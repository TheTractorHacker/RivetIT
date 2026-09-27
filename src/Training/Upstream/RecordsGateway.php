<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashSpecs;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\RowHasher;
use ITFlow\Training\Directory\LinkStates;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\CertificateView;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Records\EvidenceStrength;
use ITFlow\Training\Records\PublicVerify;
use ITFlow\Training\Reports\CertificateModel;
use ITFlow\Training\Reports\TranscriptService;

/**
 * Phase 2 records for Phase 5 (spec §3.1; Lane A). Calls P2's services first and writes its own SQL
 * only where P2 has none (Odoo push candidates, link state). SQL on P2 tables lives ONLY here and in
 * the other Upstream classes. Bound to the P2 code on main (v1.14.0): PublicVerify::lookup,
 * CertificateView::build (+ CertificateModel::components for online records), TranscriptService::build,
 * CompletionService::reprintToken, LinkStates::norm.
 *
 * Every consumer (Lanes B-E) codes against the DTO shapes in the spec (§3.1); the P2 shapes are
 * mapped here. A missing P2 class or table answers null / [] / 'unavailable', never an exception.
 */
final class RecordsGateway
{
    public const VERIFY_STATES = ['valid', 'expiring', 'expired', 'revoked', 'not_found', 'integrity', 'unavailable'];

    public function __construct(private readonly \mysqli $db)
    {
    }

    // ---- M3: public verify --------------------------------------------------------------------------------

    /**
     * M3. A malformed token => not_found with NO query. P2 'verify' missing => unavailable. P2
     * PublicVerify::lookup($db, $token, $today) (any throw => unavailable); null => not_found. Then the
     * integrity check: the cert-token row, the completion row and its void row (if any) are re-read on
     * the text protocol and re-hashed with RowHasher over HashSpecs (as LedgerVerifier does); a table
     * not registered in HashSpecs is skipped. Any mismatch => 'integrity'.
     *
     * @return array{state:string, name?:string, course?:string, issued_on?:string, expires_on?:?string, cert_number?:?string, external?:bool}
     */
    public function verify(mixed $token, string $todayLocal): array
    {
        if (!CertTokens::wellFormed($token)) {
            return ['state' => 'not_found'];
        }
        if (!P2::has($this->db, 'verify')) {
            return ['state' => 'unavailable'];
        }
        try {
            $r = PublicVerify::lookup($this->db, (string) $token, $todayLocal);
        } catch (\Throwable $e) {
            error_log('Training Upstream verify: lookup failed: ' . get_class($e) . ': ' . $e->getMessage());
            return ['state' => 'unavailable'];
        }
        if ($r === null) {
            return ['state' => 'not_found'];
        }
        try {
            $intact = $this->integrity(CertTokens::sha((string) $token));
        } catch (\Throwable $e) {
            error_log('Training Upstream verify: integrity check failed to run: ' . get_class($e) . ': ' . $e->getMessage());
            return ['state' => 'unavailable'];
        }
        if ($intact !== true) {
            error_log('Training Upstream verify: row hash mismatch (' . $intact . ') for a certificate check; answering "not available"');
            return ['state' => 'integrity'];
        }
        $state = (string) ($r['status'] ?? '');
        if (!in_array($state, ['valid', 'expiring', 'expired', 'revoked'], true)) {
            error_log('Training Upstream verify: unexpected P2 status ' . $state);
            return ['state' => 'unavailable'];
        }
        return [
            'state' => $state,
            'name' => (string) $r['name'],
            'course' => (string) $r['course'],
            'issued_on' => (string) $r['issued_on'],
            'expires_on' => $r['expires_on'] === null ? null : (string) $r['expires_on'],
            'cert_number' => $r['cert_number'] === null ? null : (string) $r['cert_number'],
            'external' => (bool) $r['external'],
        ];
    }

    /**
     * true when every row re-hashes to its stored hash; otherwise the name of the first failing
     * table ('missing:<table>' when a row the lookup just read is gone).
     */
    private function integrity(string $tokenSha): true|string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $tokenSha) !== 1) {
            return 'token_sha';
        }
        $tok = $this->textRow('training_cert_tokens', "certtok_token_sha256 = '" . $tokenSha . "'");
        if ($tok === false) {
            return 'missing:training_cert_tokens';
        }
        if ($tok !== null && !self::rowIntact('training_cert_tokens', $tok)) {
            return 'training_cert_tokens';
        }
        $completionId = $tok !== null ? (int) $tok['certtok_completion_id'] : $this->completionOfTokenSha($tokenSha);
        if ($completionId < 1) {
            return 'missing:training_completions';
        }
        $c = $this->textRow('training_completions', 'completion_id = ' . $completionId);
        if ($c === false) {
            return 'missing:training_completions';
        }
        if ($c !== null && !self::rowIntact('training_completions', $c)) {
            return 'training_completions';
        }
        $v = $this->textRow('training_completion_voids', 'cvoid_completion_id = ' . $completionId);
        if ($v !== false && $v !== null && !self::rowIntact('training_completion_voids', $v)) {
            return 'training_completion_voids';
        }
        return true;
    }

    /**
     * One row of a hashed table over the text protocol (plain query, so values are the strings the
     * writer hashed). null = the table has no HashSpecs entry (skip it); false = no such row.
     * $where is built only from our own hex/int values.
     */
    private function textRow(string $table, string $where): array|false|null
    {
        try {
            $cols = HashSpecs::selectColumns($table);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $res = $this->db->query('SELECT ' . implode(', ', $cols) . " FROM $table WHERE $where LIMIT 1");
        $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
        if ($res instanceof \mysqli_result) {
            $res->free();
        }
        return is_array($row) ? $row : false;
    }

    private function completionOfTokenSha(string $tokenSha): int
    {
        $r = Db::one($this->db, 'SELECT certtok_completion_id FROM training_cert_tokens WHERE certtok_token_sha256 = ?', 's', [$tokenSha]);
        return (int) ($r['certtok_completion_id'] ?? 0);
    }

    private static function rowIntact(string $table, array $row): bool
    {
        $meta = HashSpecs::meta($table);
        $stored = (string) ($row[$meta['hash']] ?? '');
        return $stored !== '' && hash_equals($stored, RowHasher::hash($table, $row));
    }

    // ---- S1/S2: certificate and transcript DTOs ---------------------------------------------------------

    /**
     * CertificateDTO (spec §3.1) from P2 CertificateView::build, or null when P2 'cert' is unavailable
     * or the completion does not exist. The caller authorizes first (level + ScopeView::canSeeContact
     * on contactOfCompletion()).
     *
     * Beyond the frozen keys: badge ("External card recorded" | "Paper record on file" | null),
     * trained_on, evaluated_on, ledger ({seq, hash16}|null).
     */
    public function certificate(Ctx $c, int $completionId): ?array
    {
        if ($completionId < 1 || !P2::has($this->db, 'cert')) {
            return null;
        }
        try {
            $certKey = '';
            try {
                $certKey = CertSecret::fromGlobals();
            } catch (\Throwable) {
                $certKey = '';   // the view still builds; it just has no verify URL (CertTokens decides the QR)
            }
            $v = CertificateView::build($this->db, $completionId, $c->baseUrl, $certKey, RecordsSettings::fromDb($this->db));
            if ($v === null) {
                return null;
            }
            $x = Db::one($this->db, 'SELECT completion_contact_id, completion_course_id, completion_proof, completion_language,
                    completion_recorded_at_utc, completion_row_sha256
                FROM training_completions WHERE completion_id = ?', 'i', [$completionId]);
            if ($x === null) {
                return null;
            }
        } catch (\Throwable $e) {
            error_log('Training Upstream certificate: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
        $method = (string) $v['method'];
        $external = ($v['layout'] ?? '') === 'external';
        $status = is_array($v['status'] ?? null) ? $v['status'] : ['status' => 'valid', 'reason' => null, 'label' => 'Valid'];
        $void = is_array($v['void'] ?? null) ? $v['void'] : null;
        $voidOn = null;
        if ($void !== null && is_string($void['at'] ?? null)) {
            $voidOn = substr((string) $void['at'], 0, 10);   // ISO in the app's zone: its local date
        }
        $sha = (string) ($x['completion_row_sha256'] ?? '');
        return [
            'completion_id' => (int) $v['completion_id'],
            'contact_id' => (int) $x['completion_contact_id'],
            'course_id' => (int) $x['completion_course_id'],
            'kind' => (string) $v['kind'] === 'document' ? 'document' : 'training',
            'cert_number' => $v['cert_number'],
            'person_name' => (string) $v['name'],
            'course_name' => (string) $v['course'],
            'course_code' => $v['course_code'],
            'regulation_ref' => $v['regulation_ref'],
            'regulation_line' => $v['regulation_line'],
            'revision_number' => $v['revision_number'],
            'method' => $method,
            'method_label' => $external ? (string) ($v['badge'] ?? Labels::method($method)) : self::componentsLine($method, (string) $x['completion_proof'], $v),
            'evidence_letter' => self::grade($method, (string) $x['completion_proof']),
            'completed_on' => (string) $v['issued_on'],
            'expires_on' => $v['expires_on'],
            'score_pct' => $v['score_pct'] === null ? null : sprintf('%.2f', (float) $v['score_pct']),
            'is_external' => $external,
            'external_issuer' => $external ? $v['issuer'] : null,
            'external_ref' => $external ? $v['ref'] : null,
            'trainer_or_evaluator' => is_array($v['signer'] ?? null) ? (string) $v['signer']['name'] : null,
            'language' => strtolower(substr((string) $x['completion_language'], 0, 2)) === 'es' ? 'es' : 'en',
            'status' => ['code' => (string) $status['status'], 'reason' => $status['reason'] ?? null, 'label' => (string) $status['label']],
            'voided' => $void === null ? null : ['on' => $voidOn, 'reason' => (string) ($void['reason'] ?? '')],
            'recorded_at_utc' => (string) $x['completion_recorded_at_utc'],
            'row_sha12' => $sha !== '' ? substr($sha, 0, 12) : null,
            'badge' => $v['badge'] ?? null,
            'trained_on' => $v['trained_on'] ?? null,
            'evaluated_on' => $v['evaluated_on'] ?? null,
            'ledger' => $v['ledger'] ?? null,
        ];
    }

    /**
     * The "how it was done" line, worded like the on-screen certificate (P2 Reports\CertificateModel):
     * an online record says "signed attestation" only when the employee drew a signature
     * (self_pin_signature) and "PIN attestation" otherwise. P2's CertificateView says "signed" for
     * every online record, so its line is used only for the other methods.
     */
    private static function componentsLine(string $method, string $proof, array $v): string
    {
        $line = (string) ($v['components_line'] ?? '');
        if ($method === 'online' && class_exists(CertificateModel::class)) {
            $line = (string) (CertificateModel::components($method, $proof, ($v['score_pct'] ?? null) !== null) ?? $line);
        }
        return $line !== '' ? $line : Labels::method($method);
    }

    /**
     * TranscriptDTO (spec §3.1) from P2 TranscriptService::build, or null when P2 'transcript' is
     * unavailable or the build fails. The caller has authorized the person (ScopeView::canSeeContact).
     *
     * Beyond the frozen keys: summary (P2's {valid, on_file, open_assignments, open_overdue, pct, ...}),
     * ledger ("#seq/hash16"|null).
     */
    public function transcript(Ctx $c, int $contactId): ?array
    {
        if ($contactId < 1 || !P2::has($this->db, 'transcript') || !P2::has($this->db, 'scope')) {
            return null;
        }
        try {
            $t = (new TranscriptService($c, Scope::forCtx($c)))->build($contactId);
        } catch (\Throwable $e) {
            error_log('Training Upstream transcript: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
        $q = static function (array $r): array {
            return [
                'completion_id' => (int) $r['completion_id'],
                'course_name' => (string) ($r['course']['name'] ?? ''),
                'course_code' => $r['course']['code'] ?? null,
                'revision_number' => $r['revision_number'] ?? null,
                'validity_label' => $r['validity_label'] ?? null,
                'status' => (string) $r['status'],
                'status_label' => (string) $r['status_label'],
                'method_label' => $r['external'] !== null ? ($r['trainer_sub'] ?? Labels::method((string) $r['method'])) : Labels::method((string) $r['method']),
                'evidence_letter' => (string) ($r['grade'] ?? ''),
                'score_pct' => $r['score_pct'] ?? null,
                'trained_on' => $r['trained_on'] ?? null,
                'evaluated_on' => $r['evaluated_on'] ?? null,
                'expires_on' => $r['expires_on'] ?? null,
                'trainer_or_evaluator' => $r['trainer_or_evaluator'] ?? null,
                'cert_number' => $r['cert_number'] ?? null,
                'completed_on' => (string) ($r['completed_on'] ?? ''),
                'kind' => (string) ($r['course']['kind'] ?? 'training'),
            ];
        };
        $person = is_array($t['contact'] ?? null) ? $t['contact'] : [];
        $history = [];
        foreach ((array) ($t['history'] ?? []) as $r) {
            $history[] = $q($r) + ['voided' => is_array($r['voided'] ?? null)
                ? ['on' => $r['voided']['on'] ?? null, 'reason' => (string) ($r['voided']['reason'] ?? ''), 'by' => $r['voided']['by'] ?? null]
                : null];
        }
        $open = [];
        foreach ((array) ($t['open_assignments'] ?? []) as $a) {
            $st = (string) ($a['display_status'] ?? 'due');
            $open[] = [
                'course_name' => (string) ($a['course']['name'] ?? ''),
                'due_on' => (string) ($a['due_on'] ?? ''),
                'status' => in_array($st, ['overdue', 'due_soon', 'due'], true) ? $st : 'due',
                'reason_label' => (string) ($a['anchor_label'] ?? ''),
            ];
        }
        $awards = [];
        foreach ((array) ($t['achievements'] ?? []) as $w) {
            $awards[] = [
                'name' => (string) ($w['name'] ?? ''),
                'awarded_on' => (string) ($w['awarded_on'] ?? ''),
                'how' => ($w['how'] ?? '') === 'manual' ? 'manual' : 'automatic',
                'reason' => ($w['reason'] ?? null) === null || $w['reason'] === '' ? null : (string) $w['reason'],
            ];
        }
        return [
            'contact' => [
                'id' => (int) ($person['id'] ?? $contactId),
                'name' => (string) ($person['name'] ?? ''),
                'title' => ($person['title'] ?? null) ?: null,
                'department' => is_array($person['department'] ?? null) ? ((string) $person['department']['name'] ?: null) : null,
            ],
            'as_of' => (string) ($t['as_of'] ?? Clock::todayLocal()),
            'qualifications' => array_map($q, (array) ($t['qualifications'] ?? [])),
            'history' => $history,
            'open_assignments' => $open,
            'achievements' => $awards,
            'legend' => Labels::legend(),
            'summary' => is_array($t['summary'] ?? null) ? $t['summary'] : [],
            'ledger' => $t['ledger'] ?? null,
        ];
    }

    /** The person a completion belongs to (for the caller's scope check), or null. */
    public function contactOfCompletion(int $completionId): ?int
    {
        if ($completionId < 1 || !Schema::has($this->db, Schema::P2)) {
            return null;
        }
        try {
            $r = Db::one($this->db, 'SELECT completion_contact_id FROM training_completions WHERE completion_id = ?', 'i', [$completionId]);
        } catch (\Throwable $e) {
            error_log('Training Upstream contactOfCompletion: ' . $e->getMessage());
            return null;
        }
        return $r === null ? null : (int) $r['completion_contact_id'];
    }

    /**
     * The P2 settings Phase 5 reads, with the column defaults when P2 is absent.
     *
     * @return array{due_soon_days:int, reconciled_at_utc:?string, link_checked_at_utc:?string, ready:bool}
     */
    public function recordsSettings(): array
    {
        if (!class_exists(RecordsSettings::class)) {
            return ['due_soon_days' => 30, 'reconciled_at_utc' => null, 'link_checked_at_utc' => null, 'ready' => false];
        }
        try {
            $s = RecordsSettings::fromDb($this->db);
        } catch (\Throwable) {
            return ['due_soon_days' => 30, 'reconciled_at_utc' => null, 'link_checked_at_utc' => null, 'ready' => false];
        }
        return ['due_soon_days' => $s->dueSoonDays, 'reconciled_at_utc' => $s->reconciledAtUtc, 'link_checked_at_utc' => $s->linkCheckedAtUtc, 'ready' => $s->schemaReady];
    }

    // ---- S5: Odoo push (Lane B) ----------------------------------------------------------------------------

    /**
     * Every row returned is enqueued by the caller, so the window never fills with rows that will not
     * be sent: every filter is SQL. completion_course_kind = 'training'; no void; COALESCE(course
     * tomap_push, 1) = 1; completion_recorded_at_utc >= $sinceUtc; no outbox 'create' row for
     * ($targetKey, completion, id, $mode). For the certification target ($mode 'skill') only courses mapped
     * to an Odoo skill on THIS target (tomap_odoo_skill_id set, tomap_target_key = $targetKey).
     * ORDER BY completion_id. [] before the P2 or P5 migrations.
     *
     * @return list<array{completion_id:int, contact_id:int, course_id:int}>
     */
    public function pushCandidates(string $sinceUtc, string $targetKey, int $limit, string $mode = 'resume'): array
    {
        return $this->candidates($sinceUtc, $targetKey, max(1, min(5000, $limit)), null, $mode);
    }

    /** The same filters for one completion (the P2 listener path). */
    public function pushCandidate(int $completionId, string $sinceUtc, string $targetKey, string $mode = 'resume'): ?array
    {
        if ($completionId < 1) {
            return null;
        }
        return $this->candidates($sinceUtc, $targetKey, 1, $completionId, $mode)[0] ?? null;
    }

    /**
     * Training records the certification target leaves out because their course has no Odoo skill mapped on
     * this target (the queue view says so): per course, the records that would otherwise be sent.
     *
     * @return list<array{course_id:int, course_name:string, n:int}>
     */
    public function unmappedSkillCourses(string $sinceUtc, string $targetKey, int $limit = 20): array
    {
        if (!Schema::has($this->db, Schema::P2) || !Schema::has($this->db, Schema::P5)) {
            return [];
        }
        $out = [];
        foreach (Db::all($this->db, "SELECT c.completion_course_id AS id, MAX(c.completion_snap_course_name) AS name, COUNT(*) AS n
            FROM training_completions c
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            LEFT JOIN training_odoo_map m ON m.tomap_entity = 'course' AND m.tomap_entity_id = c.completion_course_id
            WHERE c.completion_course_kind = 'training' AND v.cvoid_id IS NULL AND COALESCE(m.tomap_push, 1) = 1
              AND c.completion_recorded_at_utc >= ?
              AND (m.tomap_odoo_skill_id IS NULL OR m.tomap_target_key IS NULL OR m.tomap_target_key <> ?)
              AND NOT EXISTS (SELECT 1 FROM training_odoo_outbox o WHERE o.todoo_target_key = ? AND o.todoo_source_type = 'completion'
                              AND o.todoo_source_id = c.completion_id AND o.todoo_action = 'create' AND o.todoo_mode = 'skill')
            GROUP BY c.completion_course_id ORDER BY n DESC, c.completion_course_id LIMIT ?", 'sssi', [$sinceUtc, $targetKey, $targetKey, max(1, min(200, $limit))]) as $r) {
            $out[] = ['course_id' => (int) $r['id'], 'course_name' => (string) $r['name'], 'n' => (int) $r['n']];
        }
        return $out;
    }

    private function candidates(string $sinceUtc, string $targetKey, int $limit, ?int $only, string $mode = 'resume'): array
    {
        if (!Schema::has($this->db, Schema::P2) || !Schema::has($this->db, Schema::P5) || !in_array($mode, ['resume', 'skill', 'note'], true)) {
            return [];
        }
        $sql = "SELECT c.completion_id, c.completion_contact_id, c.completion_course_id
            FROM training_completions c
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            LEFT JOIN training_odoo_map m ON m.tomap_entity = 'course' AND m.tomap_entity_id = c.completion_course_id
            WHERE c.completion_course_kind = 'training' AND v.cvoid_id IS NULL AND COALESCE(m.tomap_push, 1) = 1
              AND c.completion_recorded_at_utc >= ?"
            . ($mode === 'skill' ? ' AND m.tomap_odoo_skill_id IS NOT NULL AND m.tomap_target_key = ?' : '') . "
              AND NOT EXISTS (SELECT 1 FROM training_odoo_outbox o WHERE o.todoo_target_key = ? AND o.todoo_source_type = 'completion'
                              AND o.todoo_source_id = c.completion_id AND o.todoo_action = 'create' AND o.todoo_mode = ?)";
        $types = $mode === 'skill' ? 'ssss' : 'sss';
        $params = $mode === 'skill' ? [$sinceUtc, $targetKey, $targetKey, $mode] : [$sinceUtc, $targetKey, $mode];
        if ($only !== null) {
            $sql .= ' AND c.completion_id = ?';
            $types .= 'i';
            $params[] = $only;
        }
        $sql .= ' ORDER BY c.completion_id LIMIT ?';
        $types .= 'i';
        $params[] = $limit;
        $out = [];
        foreach (Db::all($this->db, $sql, $types, $params) as $r) {
            $out[] = ['completion_id' => (int) $r['completion_id'], 'contact_id' => (int) $r['completion_contact_id'], 'course_id' => (int) $r['completion_course_id']];
        }
        return $out;
    }

    /**
     * Voided completions that have a create row for $targetKey (status NOT IN dead, skipped) and no
     * close row yet FOR THAT TARGET (mode): one row per (completion, mode), so every target that got
     * the record gets its own close - whether or not that target is still switched on.
     *
     * @return list<array{completion_id:int, contact_id:int, mode:string}>
     */
    public function voidCandidates(string $targetKey, int $limit): array
    {
        if (!Schema::has($this->db, Schema::P2) || !Schema::has($this->db, Schema::P5)) {
            return [];
        }
        $rows = Db::all($this->db, "SELECT c.completion_id, c.completion_contact_id, o.todoo_mode
            FROM training_completion_voids v
            JOIN training_completions c ON c.completion_id = v.cvoid_completion_id
            JOIN training_odoo_outbox o ON o.todoo_target_key = ? AND o.todoo_source_type = 'completion' AND o.todoo_source_id = c.completion_id
                AND o.todoo_action = 'create' AND o.todoo_status NOT IN ('dead', 'skipped')
            WHERE NOT EXISTS (SELECT 1 FROM training_odoo_outbox x WHERE x.todoo_target_key = ? AND x.todoo_source_type = 'completion'
                              AND x.todoo_source_id = c.completion_id AND x.todoo_action = 'close' AND x.todoo_mode = o.todoo_mode)
            ORDER BY c.completion_id, o.todoo_id LIMIT ?", 'ssi', [$targetKey, $targetKey, max(1, min(5000, $limit))]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['completion_id' => (int) $r['completion_id'], 'contact_id' => (int) $r['completion_contact_id'], 'mode' => (string) $r['todoo_mode']];
        }
        return $out;
    }

    /**
     * CompletionPushDTO (spec §3.1): {completion_id, contact_id, course_id, course_kind, course_name,
     * method_label, is_external, external_issuer|null, completed_on, expires_on|null, cert_number|null,
     * voided_on|null, recorded_at_utc}. course_name is the record's snapshot (what the certificate says).
     */
    public function completionForPush(int $completionId): ?array
    {
        if ($completionId < 1 || !Schema::has($this->db, Schema::P2)) {
            return null;
        }
        $r = Db::one($this->db, 'SELECT c.completion_id, c.completion_contact_id, c.completion_course_id, c.completion_course_kind,
                c.completion_snap_course_name, c.completion_method, c.completion_external_issuer, c.completion_completed_on,
                c.completion_expires_on, c.completion_cert_number, c.completion_recorded_at_utc, v.cvoid_at_utc
            FROM training_completions c LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            WHERE c.completion_id = ?', 'i', [$completionId]);
        if ($r === null) {
            return null;
        }
        $method = (string) $r['completion_method'];
        $external = in_array($method, ['external', 'legacy_paper'], true);
        return [
            'completion_id' => (int) $r['completion_id'],
            'contact_id' => (int) $r['completion_contact_id'],
            'course_id' => (int) $r['completion_course_id'],
            'course_kind' => (string) $r['completion_course_kind'],
            'course_name' => (string) $r['completion_snap_course_name'],
            'method_label' => Labels::method($method),
            'is_external' => $external,
            'external_issuer' => $external && $r['completion_external_issuer'] !== null ? (string) $r['completion_external_issuer'] : null,
            'completed_on' => (string) $r['completion_completed_on'],
            'expires_on' => $r['completion_expires_on'] === null ? null : (string) $r['completion_expires_on'],
            'cert_number' => $r['completion_cert_number'] === null || $r['completion_cert_number'] === '' ? null : (string) $r['completion_cert_number'],
            'voided_on' => $r['cvoid_at_utc'] === null ? null : Clock::localDate((string) $r['cvoid_at_utc']),
            'recorded_at_utc' => (string) $r['completion_recorded_at_utc'],
        ];
    }

    /**
     * The contact's Odoo link for this integration: contact_odoo_links (latest id), plus P2's link
     * state from contact_odoo_attributes when that table exists.
     *   state: 'ok'|'unchecked'|'repointed'|'mismatch'|'missing' (P2), 'unchecked' when P2 has no row
     *          yet, 'unknown' before the P2 migration.
     *   confirmed: an admin confirmed THIS link in P2 (coattr_link_confirmed_at_utc set, the baseline
     *          employee is the linked one, and the state is 'ok' again) - it waives the name check.
     *   contact_name: contacts.contact_name; odoo_name: P2's last confirmed Odoo name (baseline) or null.
     *
     * @return array{odoo_employee_id:?int, state:string, confirmed:bool, contact_name:string, odoo_name:?string}
     */
    public function linkState(int $contactId, int $integrationId): array
    {
        $out = ['odoo_employee_id' => null, 'state' => 'unknown', 'confirmed' => false, 'contact_name' => '', 'odoo_name' => null];
        if ($contactId < 1) {
            return $out;
        }
        $c = Db::one($this->db, 'SELECT contact_name FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
        $out['contact_name'] = (string) ($c['contact_name'] ?? '');
        $l = Db::one($this->db, 'SELECT odoo_employee_id FROM contact_odoo_links WHERE contact_id = ? AND odoo_integration_id = ?
            ORDER BY id DESC LIMIT 1', 'ii', [$contactId, $integrationId]);
        $emp = $l === null ? null : (int) $l['odoo_employee_id'];
        $out['odoo_employee_id'] = ($emp !== null && $emp > 0) ? $emp : null;
        if (!Schema::has($this->db, Schema::P2_LINKS)) {
            return $out;
        }
        $a = Db::one($this->db, 'SELECT coattr_odoo_integration_id, coattr_odoo_employee_id, coattr_odoo_name, coattr_link_state,
                coattr_link_confirmed_by, coattr_link_confirmed_at_utc
            FROM contact_odoo_attributes WHERE coattr_contact_id = ?', 'i', [$contactId]);
        if ($a === null || (int) $a['coattr_odoo_integration_id'] !== $integrationId) {
            $out['state'] = 'unchecked';
            return $out;
        }
        $state = (string) $a['coattr_link_state'];
        $out['state'] = in_array($state, ['ok', 'unchecked', 'repointed', 'mismatch', 'missing'], true) ? $state : 'unknown';
        $out['odoo_name'] = $a['coattr_odoo_name'] === null ? null : (string) $a['coattr_odoo_name'];
        $out['confirmed'] = $a['coattr_link_confirmed_at_utc'] !== null && $out['state'] === 'ok'
            && $out['odoo_employee_id'] !== null && (int) $a['coattr_odoo_employee_id'] === $out['odoo_employee_id'];
        return $out;
    }

    /** P2's name rule for link checks (lowercase, NFD without combining marks, collapsed spaces). */
    public static function nameKey(?string $name): string
    {
        if (class_exists(LinkStates::class)) {
            return LinkStates::norm($name);
        }
        $s = mb_scrub((string) $name, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) {
                $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
            }
        }
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($s, 'UTF-8')));
    }

    /** Evidence letter A..E: P2's EvidenceStrength when present, else the same frozen table. */
    private static function grade(string $method, string $proof): string
    {
        if (class_exists(EvidenceStrength::class)) {
            return EvidenceStrength::grade($method, $proof);
        }
        return match (true) {
            $proof === 'agent_recorded' => 'E',
            $proof === 'document' => 'D',
            $proof === 'trainer_attested' => 'C',
            $method === 'online' && $proof === 'self_pin_signature' => 'A',
            default => 'B',
        };
    }
}
