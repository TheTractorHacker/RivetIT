<?php

namespace ITFlow\Training\Directory;

use ITFlow\Integrations\Odoo\OdooClient;
use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooDirectoryMapper;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\People\DepartmentGroups;

/**
 * The Odoo directory sync for the nightly cron (S5; Phase 2 spec §3.4, §6.2). It mirrors the admin
 * handler's sync_odoo_directory step for step (the handler is not rewritten to call this; §1.3):
 *   latest integration row (must be enabled, with credentials) -> OdooTarget::guard -> `trodoo`
 *   (0 s) -> the 60 s `running` guard -> clientFromRow -> startSyncLog -> departments -> employees ->
 *   finishSyncLog -> DepartmentGroups::safeSync -> OdooTrainingSync::run -> (module on + schema ready) reconcile(null,'directory_sync').
 * Never inside a transaction; the lock is released at the end.
 */
final class DirectorySyncRunner
{
    /**
     * @param \Closure(array):OdooClient|null $clientFactory tests inject a fake-connector client; default clientFromRow
     * @param \Closure(string):void|null $notify passed to OdooTrainingSync (tests inject a recorder)
     * @return array{ok:bool, protocol:?string, dept:?array, emp:?array, training:?array, reconcile?:?array, error?:string, message?:string}
     */
    public static function runOdoo(\mysqli $db, int $triggeredBy, ?\Closure $clientFactory = null, ?\Closure $notify = null): array
    {
        $fail = static fn(string $code, string $message, ?string $protocol = null) => ['ok' => false, 'protocol' => $protocol, 'dept' => null, 'emp' => null,
            'training' => null, 'error' => $code, 'message' => $message];
        if (Db::depth() !== 0) {
            throw new \LogicException('DirectorySyncRunner::runOdoo must not run inside a transaction');
        }
        $row = OdooLinkChecker::latestIntegration($db);
        if ($row === null) {
            return $fail('not_configured', 'No Odoo integration is configured yet.');
        }
        if (empty($row['enabled'])) {
            return $fail('disabled', 'The Odoo integration is disabled.');
        }
        if (empty($row['base_url']) || empty($row['database_name']) || empty($row['username']) || empty($row['api_key_enc'])) {
            return $fail('credentials', 'The Odoo integration has no base URL, database, username or API key.');
        }
        $guard = OdooTarget::guard($db, $row);
        if (!$guard['ok']) {
            return $fail('odoo_target_changed', $guard['message']);
        }
        if (!Db::lock($db, 'trodoo', 0)) {
            return $fail('busy', 'Another Odoo job is running.');
        }
        try {
            $id = (int) $row['odoo_integration_id'];
            $recent = Db::one($db, "SELECT id FROM odoo_sync_log WHERE odoo_integration_id = ? AND started_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                AND status = 'running' LIMIT 1", 'i', [$id]);
            if ($recent !== null) {
                return $fail('running', 'A sync is already running.');
            }
            $client = $clientFactory !== null ? $clientFactory($row) : OdooConnectorFactory::clientFromRow($row);
            $protocol = $client->protocol();
            $mapper = new OdooDirectoryMapper($db, $id, $triggeredBy);
            $logId = $mapper->startSyncLog();
            try {
                $departments = $client->listDepartments();
                $deptStats = $mapper->syncDepartments($departments);
                $employees = $client->listEmployees();
                $empStats = $mapper->syncEmployees($employees, $deptStats['idMap']);
                $mapper->finishSyncLog($logId, $deptStats, $empStats);
            } catch (\RuntimeException $e) {
                Db::exec($db, "UPDATE odoo_sync_log SET finished_at = NOW(), status = 'failed', errors = ? WHERE id = ?", 'si',
                    [mb_substr($e->getMessage(), 0, 60000), $logId]);
                return $fail('sync_failed', 'Odoo sync failed via ' . OdooConnectorFactory::label($protocol) . ': ' . $e->getMessage(), $protocol);
            }
            unset($deptStats['idMap']);
            $out = ['ok' => true, 'protocol' => $protocol, 'dept' => $deptStats, 'emp' => $empStats, 'training' => null, 'reconcile' => null];
            // Department job groups follow the departments this sync created, renamed or archived (module on +
            // schema ready; never throws). Before the reconcile below, so a rule on a new department's group assigns.
            $out['dept_groups'] = DepartmentGroups::safeSync($db, 'directory_sync');
            try {
                $clean = empty($deptStats['errors']) && empty($empStats['errors']);
                $out['training'] = (new OdooTrainingSync($db, $id, $triggeredBy > 0 ? $triggeredBy : null, $notify))->run($client, $row, $employees, $clean);
                $moduleOn = (int) (Db::one($db, 'SELECT config_module_enable_training AS m FROM settings WHERE company_id = 1')['m'] ?? 0) === 1;
                if ($moduleOn && !empty($out['training']['schema_ready'])) {
                    $out['reconcile'] = AssignmentService::safeReconcile(SystemCtx::make($db, 0, 'odoo_sync_cron'), null, 'directory_sync', 30);
                }
            } catch (\Throwable $e) {
                error_log('Training Odoo extension (cron): ' . get_class($e) . ': ' . $e->getMessage());
                $out['training_error'] = 'Training attributes were not updated (see the error log).';
            }
            return $out;
        } finally {
            Db::unlock($db, 'trodoo');
        }
    }

    /** One plain-text line for the cron log and settings.config_training_odoo_sync_last_result (≤ 255). */
    public static function line(array $r): string
    {
        if (empty($r['ok'])) {
            return mb_substr('FAILED ' . ($r['error'] ?? 'error') . ': ' . ($r['message'] ?? ''), 0, 255);
        }
        $d = $r['dept'] ?? [];
        $e = $r['emp'] ?? [];
        $line = 'ok via ' . OdooConnectorFactory::label((string) $r['protocol'])
            . ': depts ' . (int) ($d['created'] ?? 0) . ' created/' . (int) ($d['updated'] ?? 0) . ' updated'
            . '; employees ' . (int) ($e['created'] ?? 0) . ' created/' . (int) ($e['updated'] ?? 0) . ' updated'
            . (!empty($d['errors']) || !empty($e['errors']) ? ' (' . (count($d['errors'] ?? []) + count($e['errors'] ?? [])) . ' errors)' : '')
            . '; training ' . ($r['training'] !== null ? OdooTrainingSync::summary($r['training']) : ($r['training_error'] ?? 'skipped'));
        $rec = $r['reconcile'] ?? null;
        if (is_array($rec)) {
            $line .= isset($rec['error']) ? '; reconcile ' . $rec['error']
                : '; reconcile +' . (int) $rec['created'] . ' ~' . (int) $rec['reopened'] . ' -' . (int) $rec['cancelled'] . ' done' . (int) $rec['completed'];
        }
        return mb_substr($line, 0, 255);
    }
}
