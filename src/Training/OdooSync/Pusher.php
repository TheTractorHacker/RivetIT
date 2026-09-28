<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooAuthException;
use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Integrations\Odoo\OdooConnectorInterface;
use ITFlow\Training\Core\Product;
use ITFlow\Training\Core\Text;

/**
 * The network half of the write-back (spec §3.4): no database access. Every call goes through
 * call(), which refuses any method outside ALLOWED - Odoo is never asked to delete anything - and
 * sets the per-call timeouts (connect 5 s, total 20 s).
 *
 * Protocol differences are handled here, and the mock Odoo tests both:
 *   - JSON-2 create:  kwargs vals_list => [vals]  -> [id]
 *   - legacy create:  positional [vals]           -> id   (vals_list in kwargs would reach Odoo 19's
 *     create with args=[] and fail with IndexError after the insert, so it is never sent that way)
 *   - message_post:   kwargs ids => [employee] (the legacy connector moves ids to the first positional
 *     argument) -> [id] on JSON-2, id on legacy
 *   - everything else is named kwargs, valid on both connectors.
 *
 * Three targets (Targets):
 *   resume  hr.resume.line; idempotency = the marker in the description, lines this integration created
 *   skill   hr.employee.skill of the chosen certification type; no free-text field exists, so idempotency is
 *           the exact natural key (employee, skill, level, valid_from, valid_to) among records this integration
 *           created (create_uid; any creator when Odoo does not say who we are), and a record another RivetIT
 *           record already holds is never adopted. The values are saved on the outbox row BEFORE the create call
 *           and every later attempt searches with those saved values first, so a lost response is adopted even
 *           when the course's mapping or the level changed in between. Odoo refuses an identical certification
 *           (same skill, level and period): that is a permanent, explained error, never a retry loop. A void ends
 *           it (valid_to).
 *   note    an INTERNAL note in the employee's chatter that reaches nobody (see noteArgs): no recipient, followers
 *           not even looked up (notify_skip_followers), the integration user never subscribed, no out-of-office
 *           auto-reply. Idempotency = the marker, searched in mail.message. A void posts a follow-up note with its
 *           own marker; the first note is never edited (and nothing is ever deleted).
 */
final class Pusher
{
    public const ALLOWED = ['fields_get', 'search_read', 'search_count', 'read', 'create', 'write', 'message_post', 'context_get'];
    private const CALL = ['connect_timeout' => 5, 'timeout' => 20];

    private ?int $ownUid = null;
    private bool $ownUidRead = false;

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
     * Lines carrying $marker, optionally only those created by $createUid and/or on $employeeId
     * (filtered by Odoo, so no number of other lines can push ours past the limit). ilike is only a
     * pre-filter: each hit is kept only when the marker is contained exactly in its description.
     * Callers decide with ownLines().
     *
     * @return list<array{id:int, employee_id:int, create_uid:int}> lowest id first (create_uid 0 = unknown)
     */
    public function findByMarker(string $marker, ?int $createUid = null, ?int $employeeId = null): array
    {
        $domain = [['description', 'ilike', $marker]];
        if ($createUid !== null) {
            $domain[] = ['create_uid', '=', $createUid];
        }
        if ($employeeId !== null) {
            $domain[] = ['employee_id', '=', $employeeId];
        }
        $rows = $this->call('hr.resume.line', 'search_read', [
            'domain' => $domain,
            'fields' => ['id', 'employee_id', 'description', 'create_uid'],
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
            $h = ['id' => $r['id'], 'employee_id' => self::m2oId($r['employee_id'] ?? null), 'create_uid' => self::m2oId($r['create_uid'] ?? null)];
            if (($createUid !== null && $h['create_uid'] !== $createUid) || ($employeeId !== null && $h['employee_id'] !== $employeeId)) {
                continue;   // belt and braces: Odoo already filtered
            }
            $hits[] = $h;
        }
        usort($hits, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $hits;
    }

    /**
     * The marker lines this integration may treat as its own. The marker is predictable (inst8 is on
     * every line, completion ids are sequential) and Odoo's rule 182 lets every employee with an Odoo
     * login create lines on their own résumé, so only lines the integration's own Odoo user created
     * count; a line anyone else planted can neither block a record (employee_changed) nor be adopted.
     * When Odoo does not report our uid, only lines on $employeeId count (the spec's employee-scoped
     * search; none when $employeeId < 1).
     *
     * @return list<array{id:int, employee_id:int, create_uid:int}> lowest id first
     */
    public function ownLines(string $marker, int $employeeId): array
    {
        $uid = $this->ownUid();
        if ($uid !== null) {
            return $this->findByMarker($marker, $uid, null);
        }
        return $employeeId > 0 ? $this->findByMarker($marker, null, $employeeId) : [];
    }

    /**
     * The Odoo user this integration writes as: the legacy login uid, else the uid in
     * res.users.context_get(). Null when Odoo does not say. Read once per run; call errors propagate
     * (the row then fails like any other call).
     */
    public function ownUid(): ?int
    {
        if ($this->ownUidRead) {
            return $this->ownUid;
        }
        if (method_exists($this->odoo, 'uid')) {
            $u = $this->odoo->uid();
            if (is_int($u) && $u > 0) {
                $this->ownUidRead = true;
                return $this->ownUid = $u;
            }
        }
        $ctx = $this->call('res.users', 'context_get', []);
        $u = is_array($ctx) ? ($ctx['uid'] ?? null) : null;
        if (!(is_int($u) && $u > 0) && method_exists($this->odoo, 'uid')) {
            $u = $this->odoo->uid();   // legacy: the login made by that call
        }
        $this->ownUid = is_int($u) && $u > 0 ? $u : null;
        $this->ownUidRead = true;
        return $this->ownUid;
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
     * @throws \DomainException('employee_changed') a line this integration created with this marker is on another employee
     * @throws PushException    permanent outcomes (odoo_record_missing, odoo_record_moved, bad_create_response, write_refused)
     */
    public function push(array $row, array $payload, int $employeeId, ?array $createRow, array $opts = []): array
    {
        $close = ($row['todoo_action'] ?? '') === 'close';
        return match ((string) ($row['todoo_mode'] ?? '')) {
            'resume' => $close ? $this->closeResume($payload, $createRow) : $this->createResume($row, $payload, $employeeId),
            'skill' => $close ? $this->closeSkill($payload, $createRow) : $this->createSkill($payload, $employeeId, $opts),
            'note' => $close ? $this->closeNote($row, $payload, $createRow) : $this->createNote($row, $payload, $employeeId),
            default => throw new \LogicException('Unknown Odoo write-back target: ' . (string) ($row['todoo_mode'] ?? '')),
        };
    }

    /**
     * For a create whose record was voided before it reached Odoo: the Odoo record this integration may
     * already have made for it (a lost response), or null. Same idempotency rules as push(); for a certification
     * the values an earlier attempt saved come first (they name the employee too), the current mapping only when
     * no attempt was ever made.
     *
     * @param array $opts skill: sent, skill_id, level_id, type_id, holder (see createSkill)
     * @return array{id:int, employee_id:int, vals?:array}|null
     */
    public function existing(array $row, array $payload, int $employeeId, array $opts = []): ?array
    {
        $marker = (string) $row['todoo_marker'];
        switch ((string) ($row['todoo_mode'] ?? '')) {
            case 'resume':
                $hits = $this->ownLines($marker, $employeeId);
                return $hits ? ['id' => $hits[0]['id'], 'employee_id' => $hits[0]['employee_id']] : null;
            case 'note':
                $hits = $this->ownNotes($marker, $employeeId);
                return $hits ? ['id' => $hits[0]['id'], 'employee_id' => $hits[0]['employee_id']] : null;
            case 'skill':
                $sent = $opts['sent'] ?? null;
                if (is_array($sent)) {
                    // Every attempt saved its values before its create call: only those can have made a record.
                    foreach ($this->skillsLike($sent, $this->ownUid()) as $h) {
                        if (!self::heldByOther($h['id'], $opts)) {
                            return ['id' => $h['id'], 'employee_id' => (int) $sent['employee_id'], 'vals' => $sent];
                        }
                    }
                    return null;
                }
                if ($employeeId < 1 || empty($opts['skill_id']) || empty($opts['level_id']) || empty($opts['type_id'])) {
                    return null;
                }
                $vals = PayloadBuilder::skillVals($payload, $employeeId, (int) $opts['skill_id'], (int) $opts['level_id'], (int) $opts['type_id']);
                foreach ($this->skillsLike($vals, $this->ownUid()) as $h) {
                    if (!self::heldByOther($h['id'], $opts)) {
                        return ['id' => $h['id'], 'employee_id' => $employeeId, 'vals' => $vals];
                    }
                }
                return null;
        }
        return null;
    }

    /** An hr.skill of $typeId named exactly $name (the admin's "Create skill in Odoo" reuses it), or null. */
    public function findSkillByName(int $typeId, string $name): ?array
    {
        $rows = $this->call('hr.skill', 'search_read', [
            'domain' => [['skill_type_id', '=', $typeId], ['name', '=', $name]],
            'fields' => ['id', 'name', 'skill_type_id'],
            'limit' => 1,
            'order' => 'id asc',
        ]);
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_array($r) && is_int($r['id'] ?? null) && $r['id'] > 0) {
                return ['id' => $r['id'], 'name' => is_string($r['name'] ?? null) ? $r['name'] : $name];
            }
        }
        return null;
    }

    /** Creates hr.skill {name, skill_type_id} (an admin action, never automatic). @return int the new skill id */
    public function createSkillInType(int $typeId, string $name): int
    {
        return $this->createOne('hr.skill', ['name' => $name, 'skill_type_id' => $typeId]);
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
        // At-least-once without duplicates: a line WE created earlier (a lost create response) is adopted.
        // Our line on another employee means the contact was re-linked since: never adopted, never duplicated.
        $own = $this->ownLines($marker, $employeeId);
        foreach ($own as $h) {
            if ($h['employee_id'] === $employeeId) {
                return ['model' => 'hr.resume.line', 'res_id' => $h['id']];
            }
        }
        if ($own !== []) {
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

    // ---- certification skill (hr.employee.skill) ---------------------------------------------------------------

    /**
     * @param array $opts
     *   sent      the values an earlier attempt of THIS row saved before its create call (OutboxRepo::sentSkill), or null
     *   skill_id, level_id, type_id  the course's mapped skill and the configured level/type now (absent: not mapped)
     *   unmapped  why they are absent (the policy message when nothing was sent before either)
     *   holder    fn(int $odooId): ?array{source_type:string, source_id:int, close_status:?string} - the other RivetIT
     *             record whose done create already holds that Odoo record (PushService reads the outbox; this class has no DB)
     *   remember  fn(array $vals): void - saves the values on the row; called right BEFORE the create call
     * @return array{model:string, res_id:int, vals:array} vals = the values of the Odoo certification
     */
    private function createSkill(array $payload, int $employeeId, array $opts): array
    {
        $uid = $this->ownUid();
        $sent = $opts['sent'] ?? null;
        if (is_array($sent)) {
            // An earlier attempt sent these values and its answer was lost: the certification it made is this record's,
            // whatever the mapping or the level say now (they may have changed since). Found on another employee: the
            // contact was re-linked in between - never adopted, never duplicated.
            foreach ($this->skillsLike($sent, $uid) as $h) {
                if (self::heldByOther($h['id'], $opts)) {
                    continue;
                }
                if ((int) $sent['employee_id'] !== $employeeId) {
                    throw new \DomainException('employee_changed');
                }
                return ['model' => 'hr.employee.skill', 'res_id' => $h['id'], 'vals' => $sent];
            }
        }
        $skillId = (int) ($opts['skill_id'] ?? 0);
        $levelId = (int) ($opts['level_id'] ?? 0);
        $typeId = (int) ($opts['type_id'] ?? 0);
        if ($skillId < 1 || $levelId < 1 || $typeId < 1) {
            throw new PushException('policy', (string) ($opts['unmapped'] ?? 'not_mapped: no Odoo certification skill is mapped for this record'));
        }
        $vals = PayloadBuilder::skillVals($payload, $employeeId, $skillId, $levelId, $typeId);

        // At-least-once without duplicates: an identical certification WE created earlier (a lost create response)
        // is adopted - unless another RivetIT record already holds it (then Odoo cannot take a second identical one).
        foreach ($this->skillsLike($vals, $uid) as $h) {
            $holder = isset($opts['holder']) ? ($opts['holder'])($h['id']) : null;
            if ($holder === null) {
                return ['model' => 'hr.employee.skill', 'res_id' => $h['id'], 'vals' => $vals];
            }
            $other = ($holder['source_type'] === 'award' ? 'achievement award #' : Product::name() . ' record #') . (int) $holder['source_id'];
            if (in_array($holder['close_status'] ?? null, ['pending', 'running', 'failed'], true)) {
                throw new PushException('wait', 'waiting: ' . $other . ' holds the same certification in Odoo (#' . $h['id'] . ') and its void is still being sent there');
            }
            throw new PushException('permanent', 'skill_overlap: Odoo already has this certification (same skill, level and dates) for this employee from '
                . $other . ' (Odoo #' . $h['id'] . '); Odoo refuses a second identical one, so nothing was created');
        }
        if ($uid !== null) {
            // The same certification entered in Odoo by someone else: Odoo would refuse the create (identical skill,
            // level and period). Say so plainly instead of sending a create that must fail.
            $others = $this->skillsLike($vals, null);
            if ($others) {
                throw new PushException('permanent', 'skill_overlap: this employee already has the same certification in Odoo (same skill, level and dates, Odoo #'
                    . $others[0]['id'] . ', not created by ' . Product::name() . '); Odoo refuses a second identical one, so nothing was created');
            }
        }
        if (isset($opts['remember'])) {
            ($opts['remember'])($vals);   // before the call: a lost answer is found by exactly these values next time
        }
        try {
            $id = $this->createOne('hr.employee.skill', $vals);
        } catch (PushException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw self::explainSkillRefusal($e);
        }
        return ['model' => 'hr.employee.skill', 'res_id' => $id, 'vals' => $vals];
    }

    /** Does another RivetIT record's done create already hold Odoo certification $odooId? */
    private static function heldByOther(int $odooId, array $opts): bool
    {
        return isset($opts['holder']) && ($opts['holder'])($odooId) !== null;
    }

    /** A void ends the certification: valid_to = the day before the void (Odoo's archive convention) or the void date. */
    private function closeSkill(array $payload, ?array $createRow): array
    {
        $resId = (int) ($createRow['todoo_odoo_res_id'] ?? 0);
        $emp = (int) ($createRow['todoo_odoo_employee_id'] ?? 0);
        if ($resId < 1) {
            throw new PushException('policy', 'create_not_sent');
        }
        $rows = $this->call('hr.employee.skill', 'search_read', [
            'domain' => [['id', '=', $resId]],
            'fields' => ['id', 'employee_id', 'skill_id', 'skill_level_id', 'valid_from', 'valid_to'],
        ]);
        $rec = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
        if ($rec === null) {
            throw new PushException('permanent', 'odoo_record_missing: the Odoo certification #' . $resId . ' no longer exists (it is never re-created)');
        }
        if (self::m2oId($rec['employee_id'] ?? null) !== $emp) {
            throw new PushException('permanent', 'odoo_record_moved: the Odoo certification #' . $resId . ' now belongs to another employee');
        }
        $to = PayloadBuilder::skillCloseTo($payload, ['valid_from' => $rec['valid_from'] ?? null, 'valid_to' => $rec['valid_to'] ?? false]);
        if ($to === null) {
            return ['model' => 'hr.employee.skill', 'res_id' => $resId];   // already ended on (or before) that date
        }
        try {
            $ok = $this->call('hr.employee.skill', 'write', ['ids' => [$resId], 'vals' => ['valid_to' => $to]]);
        } catch (\RuntimeException $e) {
            throw self::explainSkillRefusal($e, true);
        }
        if ($ok !== true) {
            throw new PushException('permanent', 'write_refused: Odoo did not confirm the end date of certification #' . $resId);
        }
        return ['model' => 'hr.employee.skill', 'res_id' => $resId];
    }

    /**
     * Certifications with exactly these values (employee, skill, level, valid_from, valid_to), optionally only
     * those $createUid created. @return list<array{id:int}> lowest id first
     */
    private function skillsLike(array $vals, ?int $createUid): array
    {
        $domain = [
            ['employee_id', '=', (int) $vals['employee_id']],
            ['skill_id', '=', (int) $vals['skill_id']],
            ['skill_level_id', '=', (int) $vals['skill_level_id']],
            ['valid_from', '=', (string) $vals['valid_from']],
            ['valid_to', '=', $vals['valid_to'] === false ? false : (string) $vals['valid_to']],
        ];
        if ($createUid !== null) {
            $domain[] = ['create_uid', '=', $createUid];
        }
        $rows = $this->call('hr.employee.skill', 'search_read', [
            'domain' => $domain,
            'fields' => ['id', 'employee_id', 'skill_id', 'skill_level_id', 'valid_from', 'valid_to', 'create_uid'],
            'limit' => 5,
            'order' => 'id asc',
        ]);
        $hits = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r) || !is_int($r['id'] ?? null)) {
                continue;
            }
            // belt and braces: Odoo already filtered
            $to = is_string($r['valid_to'] ?? null) ? $r['valid_to'] : false;
            if (self::m2oId($r['employee_id'] ?? null) !== (int) $vals['employee_id'] || self::m2oId($r['skill_id'] ?? null) !== (int) $vals['skill_id']
                || self::m2oId($r['skill_level_id'] ?? null) !== (int) $vals['skill_level_id'] || ($r['valid_from'] ?? null) !== $vals['valid_from']
                || $to !== $vals['valid_to'] || ($createUid !== null && self::m2oId($r['create_uid'] ?? null) !== $createUid)) {
                continue;
            }
            $hits[] = ['id' => $r['id']];
        }
        usort($hits, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $hits;
    }

    /** Odoo's hr.employee.skill ValidationErrors in plain words (all permanent: a retry cannot change them). */
    private static function explainSkillRefusal(\RuntimeException $e, bool $onClose = false): \Throwable
    {
        if (ErrorClass::of($e) !== 'permanent') {
            return $e;   // auth, config, transient: the caller's usual handling
        }
        $msg = $e->getMessage();
        $said = ' Odoo said: ' . Text::clip(preg_replace('/\s+/', ' ', $msg) ?? $msg, 220);
        if (stripos($msg, 'overlap or exactly match existing skills') !== false) {
            return new PushException('permanent', ($onClose ? 'skill_overlap_on_close: ending this certification would make it identical to another one on this employee (same skill, level and dates), which Odoo refuses; end or remove one of them in Odoo by hand.'
                : 'skill_overlap: this employee already has the same certification in Odoo (same skill, level and dates); Odoo refuses a second identical one, so nothing was created.') . $said);
        }
        if (stripos($msg, "don't match") !== false) {
            return new PushException('permanent', 'skill_type_mismatch: the mapped Odoo skill is not in the chosen certification type; map a skill of that type.' . $said);
        }
        if (stripos($msg, 'is not valid for skill type') !== false) {
            return new PushException('permanent', 'level_type_mismatch: the chosen level does not belong to the chosen certification type; choose the level again and save.' . $said);
        }
        if (stripos($msg, 'valid stop date prior') !== false) {
            return new PushException('permanent', 'skill_dates: Odoo refused the dates (the end is before the start).' . $said);
        }
        return $e;
    }

    // ---- HR note (hr.employee chatter) --------------------------------------------------------------------------

    private function createNote(array $row, array $payload, int $employeeId): array
    {
        $marker = (string) $row['todoo_marker'];
        $own = $this->ownNotes($marker, $employeeId);
        foreach ($own as $h) {
            if ($h['employee_id'] === $employeeId) {
                return ['model' => 'mail.message', 'res_id' => $h['id']];
            }
        }
        if ($own !== []) {
            throw new \DomainException('employee_changed');
        }
        $id = $this->postNote($employeeId, PayloadBuilder::noteHtml($payload, ($row['todoo_source_type'] ?? '') === 'award'), $marker);
        return ['model' => 'mail.message', 'res_id' => $id];
    }

    /** A void: a short follow-up internal note with its own marker on the same employee. The first note stays as it is. */
    private function closeNote(array $row, array $payload, ?array $createRow): array
    {
        $emp = (int) ($createRow['todoo_odoo_employee_id'] ?? 0);
        if ((int) ($createRow['todoo_odoo_res_id'] ?? 0) < 1 || $emp < 1) {
            throw new PushException('policy', 'create_not_sent');
        }
        $voidMarker = Marker::voidOf((string) $row['todoo_marker']);
        foreach ($this->ownNotes($voidMarker, $emp) as $h) {
            if ($h['employee_id'] === $emp) {
                return ['model' => 'mail.message', 'res_id' => $h['id']];
            }
        }
        $id = $this->postNote($emp, PayloadBuilder::noteVoidHtml($payload, $voidMarker), $voidMarker);
        return ['model' => 'mail.message', 'res_id' => $id];
    }

    /**
     * Notes carrying $marker that this integration may treat as its own (the ownLines rule: only messages its own
     * Odoo user created; when Odoo does not say, only on $employeeId). @return list<array{id:int, employee_id:int}>
     */
    public function ownNotes(string $marker, int $employeeId): array
    {
        $uid = $this->ownUid();
        $domain = [['model', '=', 'hr.employee'], ['body', 'ilike', $marker]];
        if ($uid !== null) {
            $domain[] = ['create_uid', '=', $uid];
        } elseif ($employeeId > 0) {
            $domain[] = ['res_id', '=', $employeeId];
        } else {
            return [];
        }
        $rows = $this->call('mail.message', 'search_read', [
            'domain' => $domain,
            'fields' => ['id', 'model', 'res_id', 'body', 'create_uid'],
            'limit' => 5,
            'order' => 'id asc',
        ]);
        $hits = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r) || !is_int($r['id'] ?? null) || ($r['model'] ?? null) !== 'hr.employee') {
                continue;
            }
            if (!Marker::inHtml(is_string($r['body'] ?? null) ? $r['body'] : null, $marker)) {
                continue;
            }
            if ($uid !== null && self::m2oId($r['create_uid'] ?? null) !== $uid) {
                continue;
            }
            $hits[] = ['id' => $r['id'], 'employee_id' => (int) ($r['res_id'] ?? 0)];
        }
        usort($hits, static fn($a, $b) => $a['id'] <=> $b['id']);
        return $hits;
    }

    /**
     * message_post on the employee as an INTERNAL NOTE that reaches nobody (see noteArgs). body_is_html because the
     * body is our own escaped html (Odoo documents it for RPC callers; a plain str would be escaped as text).
     */
    private function postNote(int $employeeId, string $html, string $marker): int
    {
        $res = $this->call('hr.employee', 'message_post', self::noteArgs($employeeId, $html));
        if (is_int($res) && $res > 0) {
            return $res;
        }
        if (is_array($res) && count($res) === 1 && is_int($res[0] ?? null) && $res[0] > 0) {
            return $res[0];
        }
        if (is_string($res) && preg_match('/^mail\.message\((\d+),?\)$/D', $res, $m) === 1 && (int) $m[1] > 0) {
            return (int) $m[1];   // a recordset the RPC layer rendered as text
        }
        // Posted, but the answer carries no plain id (the legacy RPC layer's rendering of a recordset differs between
        // Odoo versions): the note is found by its marker - it is never posted twice.
        foreach ($this->ownNotes($marker, $employeeId) as $h) {
            if ($h['employee_id'] === $employeeId) {
                return $h['id'];
            }
        }
        throw new PushException('permanent', 'bad_post_response: Odoo did not return the new note id and it could not be found by its reference');
    }

    /**
     * The exact message_post arguments (public so the tests can assert them), checked against Odoo 19's
     * mail.thread.message_post / _notify_thread / _notify_get_recipients / mail.followers._get_recipient_data:
     *   subtype_xmlid mail.mt_note, is_internal  an internal note: never shown to portal users.
     *   partner_ids [] + notify_skip_followers   no recipient at all: the followers are not even looked up, so nobody
     *                                            is notified or e-mailed - also not an internal user who follows the
     *                                            employee with the "Note" subtype ticked (an internal subtype alone
     *                                            still reaches such followers). Odoo 19 and later only; older versions
     *                                            refuse the parameter, so Discovery offers notes only from 19 on.
     *   message_type 'notification'              what Odoo's own server-side notes use (_message_log). A 'comment'
     *                                            would trigger the out-of-office auto-reply (the employee's user or
     *                                            the last chatter author on vacation would e-mail the integration
     *                                            user), and the chatter offers only comments for editing.
     *   context mail_create_nosubscribe, mail_post_autofollow_author_skip, mail_post_autofollow false
     *                                            the integration user is never made a follower (Odoo 16-18 subscribe
     *                                            the author unless mail_create_nosubscribe; 19 unless _author_skip),
     *                                            so later chatter on the employee never e-mails it.
     */
    public static function noteArgs(int $employeeId, string $html): array
    {
        return [
            'ids' => [$employeeId],
            'body' => $html,
            'body_is_html' => true,
            'message_type' => 'notification',
            'subtype_xmlid' => 'mail.mt_note',
            'is_internal' => true,
            'partner_ids' => [],
            'notify_skip_followers' => true,
            'context' => ['mail_create_nosubscribe' => true, 'mail_post_autofollow' => false, 'mail_post_autofollow_author_skip' => true],
        ];
    }

    // -----------------------------------------------------------------------------------------------------------

    /** create on both protocols; exactly one new id back. */
    private function createOne(string $model, array $vals): int
    {
        if ($this->odoo->protocol() === OdooConnectorFactory::PROTOCOL_JSON2) {
            $res = $this->call($model, 'create', ['vals_list' => [$vals]]);
        } else {
            $res = $this->call($model, 'create', [], [$vals]);
        }
        if (is_int($res) && $res > 0) {
            return $res;
        }
        if (is_array($res) && count($res) === 1 && is_int($res[0] ?? null) && $res[0] > 0) {
            return $res[0];
        }
        throw new PushException('permanent', 'bad_create_response: Odoo did not return one new id');
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
