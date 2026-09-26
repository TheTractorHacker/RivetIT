<?php

namespace ITFlow\Training\Directory;

use ITFlow\Integrations\Odoo\OdooClient;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\Text;

/**
 * The training extension of the MANUAL Odoo directory sync (M4, S12; Phase 2 spec §3.4, §1.4 #6-7).
 * Called after the main sync's finishSyncLog, outside any transaction, under the `trodoo` lock.
 *
 *   0 schema probe (contact_odoo_attributes + the 2.6.92 settings columns); absent => {schema_ready:false}
 *   1 LinkStates::apply against the current target - ALWAYS (module on or off, clean sync or not)
 *   2 links newly flagged (re-pointed / name changed) => ONE aggregated notification per run
 *   3 OdooTarget::acceptIfUnset when the main sync was clean (a fresh install's first clean sync accepts its target)
 *   4 module off, or the main sync reported errors => stop here (no attribute call)
 *   5 job / work location / create_date: one combined read, else one read per field, each in its own
 *     try/catch; hire-date fill only when config_training_hire_fill_since is set (opt-in), for local
 *     create_date >= that date and an empty contact_start_date
 *   6 PIN-presence flags (ids only, never PIN values), own try/catch
 *   7 coattr_attrs_synced_at_utc
 * Attributes are written only for links in state `ok` or `unchecked`: a re-pointed or mismatched link
 * may now be a different person, so its job and hire date are left alone until an admin resolves it.
 */
final class OdooTrainingSync
{
    public const FIELDS = ['job_id', 'work_location_id', 'create_date'];
    private const WRITABLE_STATES = ['ok', 'unchecked'];

    private \Closure $notify;

    /** @param \Closure(string):void|null $notify default: notifyUser() once to each active admin */
    public function __construct(private readonly \mysqli $db, private readonly int $integrationId, private readonly ?int $actorUserId,
                                ?\Closure $notify = null)
    {
        $this->notify = $notify ?? self::defaultNotifier($db);
    }

    /**
     * @param list<array> $employees the hr.employee list the main sync just read (listEmployees())
     * @return array{schema_ready:bool, skipped?:string, links?:array, fields?:array, attrs_updated?:int, hire_dates_set?:int, notified?:int}
     */
    public function run(OdooClient $client, array $integrationRow, array $employees, bool $mainClean): array
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('OdooTrainingSync::run must not run inside a transaction');
        }
        $s = RecordsSettings::fromDb($this->db);
        if (!$s->schemaReady || !self::tableExists($this->db, 'contact_odoo_attributes')) {
            return ['schema_ready' => false];
        }
        $sha = OdooTarget::sha($integrationRow['base_url'] ?? null, $integrationRow['database_name'] ?? null);

        // 1-2. Link bookkeeping, always.
        $links = LinkStates::apply($this->db, $this->integrationId, $sha, $employees);
        $notified = 0;
        if ($links['newly_flagged'] !== []) {
            $n = count($links['newly_flagged']);
            try {
                ($this->notify)("Training: $n Odoo employee link" . ($n === 1 ? ' needs' : 's need') . ' review (re-pointed or name changed). Admin > Training > Employee links (Odoo).');
                $notified = 1;
            } catch (\Throwable $e) {
                error_log('Training Odoo sync: notify failed: ' . $e->getMessage());
            }
        }
        $linkStats = ['ok' => $links['ok'], 'mismatch' => $links['mismatch'], 'missing' => $links['missing'], 'repointed' => $links['repointed'], 'new' => $links['new']];

        // 3. The first CLEAN sync on a fresh install accepts the target (spec §2.3).
        if ($mainClean) {
            OdooTarget::acceptIfUnset($this->db, $integrationRow);
        }

        // 4.
        $moduleOn = (int) (Db::one($this->db, 'SELECT config_module_enable_training AS m FROM settings WHERE company_id = 1')['m'] ?? 0) === 1;
        if (!$moduleOn || !$mainClean) {
            return ['schema_ready' => true, 'skipped' => !$moduleOn ? 'module_off' : 'unclean_sync', 'links' => $linkStats,
                    'fields' => ['job' => 'skipped', 'location' => 'skipped', 'create_date' => 'skipped', 'pin' => 'skipped'],
                    'attrs_updated' => 0, 'hire_dates_set' => 0, 'notified' => $notified];
        }

        // 5. Attributes (network first, then one write transaction).
        $fields = ['job' => 'skipped', 'location' => 'skipped', 'create_date' => 'skipped', 'pin' => 'skipped'];
        $data = [];
        $got = [];
        try {
            $data = $client->readEmployeeFields(self::FIELDS);
            $got = self::FIELDS;
        } catch (\Throwable $e) {
            foreach (self::FIELDS as $f) {
                try {
                    foreach ($client->readEmployeeFields([$f]) as $id => $row) {
                        $data[$id][$f] = $row[$f] ?? false;
                    }
                    $got[] = $f;
                } catch (\Throwable $e2) {
                    $fields[self::fieldKey($f)] = 'failed: ' . self::msg($e2);
                }
            }
        }
        foreach ($got as $f) {
            $fields[self::fieldKey($f)] = 'ok';
        }

        // 6. PIN presence (ids only).
        $pinIds = null;
        try {
            $pinIds = array_fill_keys($client->employeeIdsWithUsablePin(4), true);
            $fields['pin'] = 'ok';
        } catch (\Throwable $e) {
            $fields['pin'] = 'failed: ' . self::msg($e);
        }

        [$updated, $hireSet] = $this->write($data, $got, $pinIds, $s);
        return ['schema_ready' => true, 'links' => $linkStats, 'fields' => $fields, 'attrs_updated' => $updated, 'hire_dates_set' => $hireSet,
                'notified' => $notified];
    }

    /** Plain text, e.g. "links 259 ok, 0 flagged; jobs ok, locations ok, hire dates 0, PIN flags ok". */
    public static function summary(array $r): string
    {
        if (empty($r['schema_ready'])) {
            return 'not set up yet (run the database update)';
        }
        $l = $r['links'] ?? [];
        $flagged = (int) ($l['mismatch'] ?? 0) + (int) ($l['repointed'] ?? 0) + (int) ($l['missing'] ?? 0);
        $out = 'links ' . (int) ($l['ok'] ?? 0) . ' ok, ' . $flagged . ' flagged';
        if (!empty($r['skipped'])) {
            return $out . '; attributes skipped (' . ($r['skipped'] === 'module_off' ? 'Training is off' : 'the directory sync had errors') . ')';
        }
        $f = $r['fields'] ?? [];
        $w = static fn(string $k) => (string) ($f[$k] ?? 'skipped');
        return $out . '; jobs ' . $w('job') . ', locations ' . $w('location') . ', hire dates ' . (int) ($r['hire_dates_set'] ?? 0)
            . (str_starts_with($w('create_date'), 'failed') ? ' (' . $w('create_date') . ')' : '') . ', PIN flags ' . $w('pin');
    }

    // ------------------------------------------------------------------------------------------

    /** @return array{0:int, 1:int} [coattr rows updated, hire dates set] */
    private function write(array $data, array $got, ?array $pinIds, RecordsSettings $s): array
    {
        $db = $this->db;
        $now = Clock::nowUtc();
        return Db::tx($db, function () use ($db, $data, $got, $pinIds, $s, $now): array {
            $rows = Db::all($db, 'SELECT a.coattr_contact_id, a.coattr_odoo_employee_id, a.coattr_link_state, c.contact_start_date
                FROM contact_odoo_attributes a
                JOIN contact_odoo_links l ON l.contact_id = a.coattr_contact_id AND l.odoo_integration_id = ? AND l.odoo_employee_id = a.coattr_odoo_employee_id
                JOIN contacts c ON c.contact_id = a.coattr_contact_id
                ORDER BY a.coattr_contact_id FOR UPDATE', 'i', [$this->integrationId]);
            $updated = 0;
            $events = [];
            foreach ($rows as $r) {
                $cid = (int) $r['coattr_contact_id'];
                $eid = (int) $r['coattr_odoo_employee_id'];
                if (!in_array((string) $r['coattr_link_state'], self::WRITABLE_STATES, true)) {
                    continue;
                }
                $sets = [];
                $types = '';
                $params = [];
                $d = $data[$eid] ?? null;
                if ($d !== null) {
                    if (in_array('job_id', $got, true)) {
                        [$id, $name] = self::m2o($d['job_id'] ?? false);
                        array_push($sets, 'coattr_job_id = ?', 'coattr_job_name = ?');
                        $types .= 'is';
                        array_push($params, $id, $name);
                    }
                    if (in_array('work_location_id', $got, true)) {
                        [$id, $name] = self::m2o($d['work_location_id'] ?? false);
                        array_push($sets, 'coattr_work_location_id = ?', 'coattr_work_location_name = ?');
                        $types .= 'is';
                        array_push($params, $id, $name);
                    }
                    if (in_array('create_date', $got, true)) {
                        $local = self::localCreateDate($d['create_date'] ?? false);
                        $sets[] = 'coattr_odoo_create_date = ?';
                        $types .= 's';
                        $params[] = $local;
                        if ($local !== null && $s->hireFillSince !== null && $local >= $s->hireFillSince && $r['contact_start_date'] === null) {
                            $n = Db::exec($db, 'UPDATE contacts SET contact_start_date = ? WHERE contact_id = ? AND contact_start_date IS NULL', 'si', [$local, $cid]);
                            if ($n === 1) {
                                $events[] = [
                                    'type' => 'contact.hire_date_set',
                                    'actor_type' => 'system',
                                    'actor_user_id' => $this->actorUserId,
                                    'subject_contact_id' => $cid,
                                    'entity_type' => 'contact',
                                    'entity_id' => $cid,
                                    'payload' => ['old' => null, 'new' => $local, 'rehired' => false, 'reason' => 'Filled from the Odoo employee create date'],
                                ];
                            }
                        }
                    }
                }
                if ($pinIds !== null) {
                    array_push($sets, 'coattr_odoo_pin_ok = ?', 'coattr_pin_synced_at_utc = ?');
                    $types .= 'is';
                    array_push($params, isset($pinIds[$eid]) ? 1 : 0, $now);
                }
                if ($sets === []) {
                    continue;
                }
                if ($d !== null && $got !== []) {
                    $sets[] = 'coattr_attrs_synced_at_utc = ?';
                    $types .= 's';
                    $params[] = $now;
                }
                Db::exec($db, 'UPDATE contact_odoo_attributes SET ' . implode(', ', $sets) . ' WHERE coattr_contact_id = ?', $types . 'i', array_merge($params, [$cid]));
                $updated++;
            }
            foreach ($events as $e) {
                Ledger::append($db, $e);
            }
            return [$updated, count($events)];
        });
    }

    /** Odoo many2one [id, "name"] | false => [?int, ?string]. */
    private static function m2o(mixed $v): array
    {
        if (is_array($v) && isset($v[0]) && is_int($v[0]) && $v[0] > 0) {
            return [$v[0], Text::clip(is_string($v[1] ?? null) ? $v[1] : null, 200)];
        }
        return [null, null];
    }

    /** Odoo create_date ("Y-m-d H:i:s", UTC) => local 'Y-m-d', or null. */
    private static function localCreateDate(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $v) !== 1) {
            return null;
        }
        try {
            return Clock::localDate($v);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function fieldKey(string $f): string
    {
        return match ($f) {
            'job_id' => 'job',
            'work_location_id' => 'location',
            default => 'create_date',
        };
    }

    /** Error text for the plain-text summary: one line, at most 120 characters (callers escape it for HTML). */
    private static function msg(\Throwable $e): string
    {
        $m = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));
        return (string) Text::clip($m === '' ? get_class($e) : $m, 120);
    }

    public static function tableExists(\mysqli $db, string $table): bool
    {
        return Db::one($db, 'SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', [$table]) !== null;
    }

    /** notifyUser() once to each active admin (the text is plain; notifications render it escaped). */
    public static function defaultNotifier(\mysqli $db): \Closure
    {
        return static function (string $text) use ($db): void {
            if (!function_exists('notifyUser')) {
                return;
            }
            foreach (self::adminUserIds($db) as $uid) {
                notifyUser($uid, 'Training', $text, '/admin/settings_training.php#odoo');
            }
        };
    }

    /** @return list<int> */
    public static function adminUserIds(\mysqli $db): array
    {
        return array_map(static fn($r) => (int) $r['user_id'], Db::all($db, 'SELECT u.user_id FROM users u
            JOIN user_roles r ON r.role_id = u.user_role_id
            WHERE r.role_is_admin = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND u.user_type = 1 ORDER BY u.user_id'));
    }
}
