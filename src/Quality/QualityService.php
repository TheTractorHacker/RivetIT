<?php

namespace ITFlow\Quality;

/**
 * What the department portal (and the kiosk) shows from the QMS. Every call:
 *
 *   1. re-checks on the server that the module is on, the feature is on and this person may use Quality (QualityConfig::access, QualityAccessPolicy::eligible);
 *   2. signs a short per-user context for THIS person and THIS request (UserContext) - the QMS authorizes against it, not against the service token;
 *   3. asks the QMS live. Nothing is cached or kept: a result is shown with the time it was read ("as of"), and when the QMS cannot be reached the page says so
 *      instead of showing older data as if it were current;
 *   4. keeps only the whitelisted, length-limited fields it understands (the QMS cannot inject markup, scripts or off-site links into the page).
 *
 * The department and the manager role come from the contact's own record, never from the request, so a person can only ever ask for their own department.
 * Results are plain arrays: ['ok'=>true,'items'=>[...],'as_of'=>..] or ['ok'=>false,'error'=>code,'message'=>safe text,'reference'=>correlation id].
 */
final class QualityService
{
    /** employee tab => [QMS path, feature key] */
    public const EMPLOYEE = [
        'tasks'         => ['/me/quality/tasks', 'tasks'],
        'actions'       => ['/me/quality/actions', 'actions'],
        'documents'     => ['/me/quality/documents', 'documents'],
        'training'      => ['/me/quality/training', 'training'],
        'notifications' => ['/me/quality/notifications', 'notifications'],
        'history'       => ['/me/quality/history', 'history'],
    ];

    /** manager tab => [QMS path, query] (feature: reports) */
    public const MANAGER = [
        'summary'         => ['/quality/reports/summary', []],
        'actions'         => ['/quality/actions', []],
        'overdue'         => ['/quality/tasks', ['status' => 'overdue']],
        'compliance'      => ['/quality/training/compliance', []],
        'acknowledgments' => ['/quality/documents/acknowledgments', ['status' => 'pending']],
    ];

    private const MAX_ITEMS = 100;

    public function __construct(private readonly \mysqli $db, private ?QmsClient $client = null, private int $budgetSeconds = 8)
    {
        $this->client ??= QmsClient::fromDb($db);
    }

    /** True when the module is on and the connection is configured. */
    public function connected(): bool
    {
        return QualityConfig::moduleOn($this->db) && $this->client !== null;
    }

    /** @return array<string,mixed> */
    public function employeeView(int $contactId, string $tab): array
    {
        if (!isset(self::EMPLOYEE[$tab])) {
            return $this->fail('unknown_view', 'That page does not exist.');
        }
        [$path, $feature] = self::EMPLOYEE[$tab];
        $r = $this->fetch($contactId, $feature, $path, [], $tab, false);
        if ($tab === 'training') {
            $r['local'] = $this->localTraining($contactId);   // RivetIT's own assignments: true even when the QMS is down
        }
        return $r;
    }

    /** @return array<string,mixed> */
    public function managerView(int $contactId, string $tab): array
    {
        if (!isset(self::MANAGER[$tab])) {
            return $this->fail('unknown_view', 'That page does not exist.');
        }
        [$path, $query] = self::MANAGER[$tab];
        return $this->fetch($contactId, 'reports', $path, $query, $tab, true);
    }

    /**
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     */
    private function fetch(int $contactId, string $feature, string $path, array $query, string $tab, bool $manager): array
    {
        $gate = $this->gate($contactId, $feature, $manager);
        if ($gate !== null) {
            return $gate;
        }
        $cid = bin2hex(random_bytes(8));
        try {
            $ctx = UserContext::forContact($this->db, $contactId, $this->client->audience(), [$manager ? 'manager:read' : 'me:read'], $cid);
            if ($manager) {
                // The department is the contact's own; the QMS must authorize from the signed context and ignores this parameter if it disagrees.
                $query['department'] = (string) ($ctx->claims['dept']['rivetit_id'] ?? '');
            }
            if ($tab === 'summary') {
                $r = $this->client->request('GET', $path, ['query' => $query, 'user' => $ctx, 'budget_s' => $this->budgetSeconds, 'contact_id' => $contactId]);
                return ['ok' => true, 'summary' => $this->summaryFields((array) ($r['data']['data'] ?? $r['data'] ?? [])), 'as_of' => date('Y-m-d H:i:s')];
            }
            $r = $this->client->request('GET', $path, ['query' => $query + ['limit' => self::MAX_ITEMS], 'user' => $ctx, 'budget_s' => $this->budgetSeconds, 'contact_id' => $contactId]);
            $raw = (array) ($r['data']['data'] ?? []);
            $items = [];
            foreach (array_slice($raw, 0, self::MAX_ITEMS) as $item) {
                if (is_array($item)) {
                    $items[] = $this->shape($tab, $item);
                }
            }
            return ['ok' => true, 'items' => $items, 'as_of' => date('Y-m-d H:i:s'), 'more' => !empty($r['data']['next_cursor']) || count($raw) > self::MAX_ITEMS];
        } catch (QmsException $e) {
            return $this->unavailable($e, $contactId);
        } catch (\Throwable $e) {
            QualityLog::record($this->db, 'portal', 'unexpected', get_class($e) . ' while loading ' . $tab, $cid, null, $contactId);
            return $this->fail('unexpected', 'Quality could not be loaded.', $cid);
        }
    }

    /** Acknowledge a controlled document as the signed-in person. The caller has already re-authenticated them. @return array<string,mixed> */
    public function acknowledge(int $contactId, string $documentId, string $idempotencyKey): array
    {
        $gate = $this->gate($contactId, 'documents', false);
        if ($gate !== null) {
            return $gate;
        }
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $documentId) !== 1) {
            return $this->fail('invalid_id', 'That document is not valid.');
        }
        $cid = bin2hex(random_bytes(8));
        try {
            $ctx = UserContext::forContact($this->db, $contactId, $this->client->audience(), ['documents:acknowledge'], $cid);
            $r = $this->client->request('POST', '/me/quality/documents/' . rawurlencode($documentId) . '/acknowledgments', ['json' => ['acknowledged' => true], 'user' => $ctx,
                'idempotency_key' => $idempotencyKey, 'budget_s' => $this->budgetSeconds, 'contact_id' => $contactId]);
            return ['ok' => true, 'status' => $r['status']];
        } catch (QmsException $e) {
            return $this->unavailable($e, $contactId);
        }
    }

    /** Answer a corrective action as the signed-in person. @return array<string,mixed> */
    public function respond(int $contactId, string $actionId, string $text, string $idempotencyKey): array
    {
        $gate = $this->gate($contactId, 'actions', false);
        if ($gate !== null) {
            return $gate;
        }
        $text = trim($text);
        if (preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $actionId) !== 1 || $text === '' || mb_strlen($text) > 4000) {
            return $this->fail('invalid_input', 'Write a response of up to 4000 characters.');
        }
        $cid = bin2hex(random_bytes(8));
        try {
            $ctx = UserContext::forContact($this->db, $contactId, $this->client->audience(), ['actions:respond'], $cid);
            $r = $this->client->request('POST', '/quality/actions/' . rawurlencode($actionId) . '/responses', ['json' => ['text' => $text], 'user' => $ctx,
                'idempotency_key' => $idempotencyKey, 'budget_s' => $this->budgetSeconds, 'contact_id' => $contactId]);
            return ['ok' => true, 'status' => $r['status']];
        } catch (QmsException $e) {
            return $this->unavailable($e, $contactId);
        }
    }

    // ---- gate and results ------------------------------------------------------------------------------------------------------------------

    /** @return ?array<string,mixed> null when allowed */
    private function gate(int $contactId, string $feature, bool $manager): ?array
    {
        if (!QualityConfig::moduleOn($this->db)) {
            return $this->fail('module_disabled', 'Quality is not available.');
        }
        if (!QualityConfig::features($this->db)[$feature]) {
            return $this->fail('feature_disabled', 'This part of Quality is not switched on.');
        }
        $a = QualityConfig::access($this->db, $contactId);
        if (!$a['ok'] || ($manager && !$a['manager'])) {
            return $this->fail('not_allowed', 'You do not have access to this page.');
        }
        if ($this->client === null) {
            return ['ok' => false, 'error' => 'not_connected', 'message' => 'Quality is not connected to the quality system yet. Please tell your administrator.', 'reference' => ''];
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function fail(string $code, string $message, string $reference = ''): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message, 'reference' => $reference];
    }

    /** The QMS cannot be used right now. Nothing stale is shown; the person gets a plain message and a reference an administrator can look up. @return array<string,mixed> */
    private function unavailable(QmsException $e, int $contactId): array
    {
        $msg = match ($e->errCode) {
            QmsException::CONTEXT => 'You do not have access to this page.',
            QmsException::FORBIDDEN, QmsException::NOT_FOUND => 'The quality system did not allow this. If you think it should, ask your quality manager.',
            QmsException::REJECTED => 'The quality system could not accept this request.',
            default => 'The quality system is not available right now. Nothing is shown rather than out-of-date information. Please try again in a few minutes.',
        };
        return ['ok' => false, 'error' => $e->errCode === QmsException::CONTEXT ? 'not_allowed' : 'unavailable', 'message' => $msg, 'reference' => $e->correlationId];
    }

    // ---- field whitelists --------------------------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $i @return array<string,mixed> */
    private function shape(string $tab, array $i): array
    {
        $base = $this->client->baseUrl();
        $s = static fn($v, int $max = 200): string => mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', is_scalar($v) ? (string) $v : '') ?? ''), 0, $max);
        $d = static fn($v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1 ? substr($v, 0, 10) : null;
        $id = static fn($v): string => is_scalar($v) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', (string) $v) === 1 ? (string) $v : '';
        $out = ['id' => $id($i['id'] ?? ''), 'title' => $s($i['title'] ?? $i['name'] ?? ''), 'status' => $s($i['status'] ?? '', 40), 'url' => QmsUrl::safeLink($base, $i['url'] ?? null)];
        switch ($tab) {
            case 'tasks':
            case 'overdue':
                $out += ['due_on' => $d($i['due_on'] ?? null), 'priority' => $s($i['priority'] ?? '', 20), 'overdue' => !empty($i['overdue']) || ($i['status'] ?? '') === 'overdue', 'assignee' => $s($i['assignee'] ?? '', 120)];
                break;
            case 'actions':
                $out += ['due_on' => $d($i['due_on'] ?? null), 'overdue' => !empty($i['overdue']) || ($i['status'] ?? '') === 'overdue', 'requires_response' => !empty($i['requires_response']), 'owner' => $s($i['owner'] ?? '', 120)];
                break;
            case 'documents':
                $out += ['version' => $s($i['version'] ?? '', 30), 'due_on' => $d($i['due_on'] ?? null), 'acknowledged_on' => $d($i['acknowledged_on'] ?? null),
                    'pending' => ($i['status'] ?? '') === 'pending_acknowledgment' || !empty($i['pending'])];
                break;
            case 'training':
                $out += ['due_on' => $d($i['due_on'] ?? null), 'course_id' => $id($i['course_id'] ?? '')];
                break;
            case 'notifications':
                $out += ['at' => $s($i['created_at'] ?? '', 30), 'read' => !empty($i['read'])];
                break;
            case 'history':
                $out += ['at' => $s($i['occurred_at'] ?? '', 30), 'event' => $s($i['event'] ?? '', 60)];
                break;
            case 'compliance':
                $out += ['assigned' => (int) ($i['assigned'] ?? 0), 'completed' => (int) ($i['completed'] ?? 0), 'overdue' => (int) ($i['overdue'] ?? 0), 'pct' => isset($i['pct']) ? max(0, min(100, (int) $i['pct'])) : null];
                break;
            case 'acknowledgments':
                $out += ['version' => $s($i['version'] ?? '', 30), 'pending_count' => (int) ($i['pending_count'] ?? 0), 'due_on' => $d($i['due_on'] ?? null)];
                break;
        }
        return $out;
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function summaryFields(array $d): array
    {
        $n = static fn($v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
        return ['open_actions' => $n($d['open_actions'] ?? null), 'overdue_actions' => $n($d['overdue_actions'] ?? null), 'overdue_tasks' => $n($d['overdue_tasks'] ?? null),
            'pending_acknowledgments' => $n($d['pending_acknowledgments'] ?? null), 'training_compliance_pct' => isset($d['training_compliance_pct']) && is_numeric($d['training_compliance_pct']) ? max(0, min(100, (int) round((float) $d['training_compliance_pct']))) : null,
            'as_of' => is_string($d['as_of'] ?? null) ? mb_substr((string) $d['as_of'], 0, 30) : null];
    }

    /** RivetIT's own open training assigned for a quality requirement (external system set). Always current: it is RivetIT's data. @return list<array<string,mixed>> */
    public function localTraining(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }
        try {
            $st = $this->db->prepare("SELECT a.tassign_id, c.course_name, a.tassign_due_on, a.tassign_external_ref FROM training_assignments a JOIN training_courses c ON c.course_id = a.tassign_course_id
                WHERE a.tassign_contact_id = ? AND a.tassign_status = 'open' AND a.tassign_external_system IS NOT NULL AND a.tassign_external_system <> '' ORDER BY a.tassign_due_on LIMIT 50");
            $st->bind_param('i', $contactId);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
        } catch (\Throwable) {
            return [];
        }
        $today = date('Y-m-d');
        return array_map(static fn(array $r): array => ['id' => (int) $r['tassign_id'], 'title' => (string) $r['course_name'], 'due_on' => (string) $r['tassign_due_on'],
            'overdue' => (string) $r['tassign_due_on'] < $today, 'reference' => (string) $r['tassign_external_ref']], $rows);
    }
}
