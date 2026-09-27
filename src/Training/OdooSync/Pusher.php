<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooAuthException;
use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;

/**
 * The network half of the write-back (spec §3.4): no database access. Every call goes through
 * call(), which refuses any method outside ALLOWED - Odoo is never asked to delete anything - and
 * sets the per-call timeouts (connect 5 s, total 20 s).
 *
 * Protocol differences are handled here, and the mock Odoo tests both:
 *   - JSON-2 create:  kwargs vals_list => [vals]  -> [id]
 *   - legacy create:  positional [vals]           -> id   (vals_list in kwargs would reach Odoo 19's
 *     create with args=[] and fail with IndexError after the insert, so it is never sent that way)
 *   - everything else is named kwargs, valid on both connectors.
 */
final class Pusher
{
    public const ALLOWED = ['fields_get', 'search_read', 'search_count', 'read', 'create', 'write', 'message_post', 'context_get'];
    private const CALL = ['connect_timeout' => 5, 'timeout' => 20];

    /**
     * @param array $discovery the stored Discovery::run() result for this target
     * @param array $settings  AutomationSettings::loadWorker() (tauto_odoo_resume_type_id / _award_type_id)
     */
    public function __construct(
        private readonly OdooConnectorInterface $odoo,
        private readonly array $discovery,
        private readonly array $settings,
    ) {
    }

    /**
     * Lines carrying $marker, on any employee. ilike is only a pre-filter: each hit is kept only when
     * the marker is contained exactly in its description.
     *
     * @return list<array{id:int, employee_id:int}> lowest id first
     */
    public function findByMarker(string $marker): array
    {
        $rows = $this->call('hr.resume.line', 'search_read', [
            'domain' => [['description', 'ilike', $marker]],
            'fields' => ['id', 'employee_id', 'description'],
            'limit' => 5,
            'order' => 'id asc',
        ]);
        $hits = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r) || !isset($r['id']) || !is_int($r['id'])) {
                continue;
            }
            if (!Marker::inHtml(is_string($r['description'] ?? null) ? $r['description'] : null, $marker)) {
                continue;
            }
            $hits[] = ['id' => $r['id'], 'employee_id' => self::m2oId($r['employee_id'] ?? null)];
        }
        usort($hits, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $hits;
    }

    /** @return array{id:int, name:string, active:bool}|null (archived employees included) */
    public function employee(int $employeeId): ?array
    {
        $rows = $this->call('hr.employee', 'search_read', [
            'domain' => [['id', '=', $employeeId]],
            'fields' => ['id', 'name', 'active'],
            'context' => ['active_test' => false],
        ]);
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_array($r) && ($r['id'] ?? null) === $employeeId) {
                return ['id' => $employeeId, 'name' => is_string($r['name'] ?? null) ? $r['name'] : '', 'active' => (bool) ($r['active'] ?? true)];
            }
        }
        return null;
    }

    /**
     * @param array      $row       the claimed outbox row
     * @param array      $payload   PayloadBuilder::completion()/award()
     * @param int        $employeeId the Odoo employee (for a close: the create row's)
     * @param array|null $createRow for a close: the done create row (res_id, employee)
     * @return array{model:string, res_id:int}
     * @throws \DomainException('employee_changed') a line with this marker exists on another employee
     * @throws PushException    permanent outcomes (odoo_record_missing, odoo_record_moved, bad_create_response, write_refused)
     */
    public function push(array $row, array $payload, int $employeeId, ?array $createRow): array
    {
        if (($row['todoo_mode'] ?? '') !== 'resume') {
            throw new \LogicException('Odoo skill and note modes are not built yet (Phase 5 L2)');
        }
        return ($row['todoo_action'] ?? '') === 'close'
            ? $this->closeResume($payload, $createRow)
            : $this->createResume($row, $payload, $employeeId);
    }

    /** true when the key works; rethrows OdooAuthException; any other failure => false. */
    public function probe(): bool
    {
        try {
            $this->call('res.users', 'context_get', []);
            return true;
        } catch (OdooAuthException $e) {
            throw $e;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------------------------

    private function createResume(array $row, array $payload, int $employeeId): array
    {
        $marker = (string) $row['todoo_marker'];
        $hits = $this->findByMarker($marker);
        if ($hits !== []) {
            foreach ($hits as $h) {
                if ($h['employee_id'] === $employeeId) {
                    return ['model' => 'hr.resume.line', 'res_id' => $h['id']];
                }
            }
            throw new \DomainException('employee_changed');
        }

        $fields = array_values(array_filter((array) ($this->discovery['resume']['fields'] ?? []), 'is_string'));
        $typeId = ($row['todoo_source_type'] ?? '') === 'award'
            ? (self::intOrNull($this->settings['tauto_odoo_award_type_id'] ?? null) ?? self::intOrNull($this->settings['tauto_odoo_resume_type_id'] ?? null))
            : self::intOrNull($this->settings['tauto_odoo_resume_type_id'] ?? null);
        $vals = PayloadBuilder::resumeVals($payload, $employeeId, $typeId, $fields);

        if ($this->odoo->protocol() === OdooConnectorFactory::PROTOCOL_JSON2) {
            $res = $this->call('hr.resume.line', 'create', ['vals_list' => [$vals]]);
        } else {
            $res = $this->call('hr.resume.line', 'create', [], [$vals]);
        }
        $id = null;
        if (is_int($res) && $res > 0) {
            $id = $res;
        } elseif (is_array($res) && count($res) === 1 && is_int($res[0] ?? null) && $res[0] > 0) {
            $id = $res[0];
        }
        if ($id === null) {
            throw new PushException('permanent', 'bad_create_response: Odoo did not return one new line id');
        }
        return ['model' => 'hr.resume.line', 'res_id' => $id];
    }

    private function closeResume(array $payload, ?array $createRow): array
    {
        $resId = (int) ($createRow['todoo_odoo_res_id'] ?? 0);
        $emp = (int) ($createRow['todoo_odoo_employee_id'] ?? 0);
        if ($resId < 1) {
            throw new PushException('policy', 'create_not_sent');
        }
        $rows = $this->call('hr.resume.line', 'search_read', [
            'domain' => [['id', '=', $resId]],
            'fields' => ['id', 'name', 'date_start', 'date_end', 'employee_id'],
        ]);
        $line = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
        if ($line === null) {
            throw new PushException('permanent', 'odoo_record_missing: the Odoo line #' . $resId . ' no longer exists (it is never re-created)');
        }
        if (self::m2oId($line['employee_id'] ?? null) !== $emp) {
            throw new PushException('permanent', 'odoo_record_moved: the Odoo line #' . $resId . ' now belongs to another employee');
        }
        $vals = PayloadBuilder::resumeClose($payload, [
            'name' => is_string($line['name'] ?? null) ? $line['name'] : '',
            'date_start' => is_string($line['date_start'] ?? null) ? $line['date_start'] : '',
            'date_end' => $line['date_end'] ?? false,
        ]);
        $ok = $this->call('hr.resume.line', 'write', ['ids' => [$resId], 'vals' => $vals]);
        if ($ok !== true) {
            throw new PushException('permanent', 'write_refused: Odoo did not confirm the update of line #' . $resId);
        }
        return ['model' => 'hr.resume.line', 'res_id' => $resId];
    }

    private function call(string $model, string $method, array $kwargs, array $args = []): mixed
    {
        if (!in_array($method, self::ALLOWED, true)) {
            throw new \LogicException("Odoo method $method is not allowed for the training write-back");
        }
        return $this->odoo->call($model, $method, $args, $kwargs, self::CALL);
    }

    private static function m2oId(mixed $v): int
    {
        if (is_array($v) && isset($v[0]) && is_int($v[0])) {
            return $v[0];
        }
        return is_int($v) ? $v : 0;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '' || (int) $v < 1) ? null : (int) $v;
    }
}
