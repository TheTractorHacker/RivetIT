<?php
/*
 * A MOCK quality management system for tests/quality_module.php. It stands in for Datum and knows only what docs/QUALITY_CONTRACT.md says:
 *
 *   php -S 127.0.0.1:8766 tests/support/mock_qms.php        (QMS_STATE=<dir> in the environment)
 *
 * It verifies the machine token, the per-user context JWT (typ, aud, exp, signature against the JWKS file RivetIT published) and the HMAC of events, and it
 * records everything it receives in <QMS_STATE>/log.jsonl so the test can assert on what RivetIT sent. Behaviour is steered by <QMS_STATE>/ctl.json:
 *   {"fail_events": N}      answer the next N event posts with 503
 *   {"reject_events": true} answer every event post with 400
 *   {"down": true}          answer every API call with 503
 *   {"slow": seconds}       sleep before answering an API call
 *   {"bad_json": true}      answer API calls with a body that is not JSON
 *   {"big": true}           answer API calls with 2 MiB
 *   {"redirect": true}      answer API calls with a 302 to another host
 *   {"flaky": N}            answer the first N attempts of each GET with 503 (tests the bounded retry)
 * No real credentials, no network beyond loopback.
 */

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
$dir = (string) getenv('QMS_STATE');
if ($dir === '' || !is_dir($dir)) {
    http_response_code(500);
    echo 'QMS_STATE missing';
    return true;
}
$ctlFile = $dir . '/ctl.json';
$ctl = is_file($ctlFile) ? (json_decode((string) file_get_contents($ctlFile), true) ?: []) : [];
$saveCtl = static function (array $c) use ($ctlFile): void {
    file_put_contents($ctlFile, json_encode($c), LOCK_EX);
};
$cfg = json_decode((string) @file_get_contents($dir . '/cfg.json'), true) ?: [];
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$h = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
$log = static function (array $row) use ($dir): void {
    file_put_contents($dir . '/log.jsonl', json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};
$out = static function (int $status, $json, array $headers = []) use ($h): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Correlation-Id: ' . ($h['x-correlation-id'] ?? 'none'));
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    echo is_string($json) ? $json : json_encode($json);
    return true;
};

if (!str_starts_with($path, '/api/v1/')) {
    return $out(404, ['error' => ['code' => 'not_found']]);
}
$sub = substr($path, 7);   // /api/v1 removed
$base = ['method' => $method, 'path' => $sub, 'cid' => $h['x-correlation-id'] ?? null, 'auth' => isset($h['authorization']), 'ctx' => isset($h['x-rivet-user-context']), 'idem' => $h['idempotency-key'] ?? null];

// ---- token endpoint ------------------------------------------------------------------------------------------------
if ($sub === '/oauth/token' && $method === 'POST') {
    $basic = $h['authorization'] ?? '';
    $ok = false;
    if (preg_match('/^Basic (.+)$/', $basic, $m)) {
        [$u, $p] = array_pad(explode(':', (string) base64_decode($m[1]), 2), 2, '');
        $ok = hash_equals((string) ($cfg['client_id'] ?? ''), rawurldecode($u)) && hash_equals((string) ($cfg['client_secret'] ?? ''), rawurldecode($p));
    }
    $log($base + ['event' => 'token', 'ok' => $ok, 'form' => $body]);
    if (!$ok) {
        return $out(401, ['error' => 'invalid_client']);
    }
    $tok = 'qms_tok_' . bin2hex(random_bytes(8));
    file_put_contents($dir . '/token.txt', $tok);
    return $out(200, ['access_token' => $tok, 'token_type' => 'Bearer', 'expires_in' => (int) ($ctl['token_ttl'] ?? 300)]);
}

// ---- events from RivetIT (HMAC, no bearer) -------------------------------------------------------------------------------
if ($sub === '/integrations/rivetit/events' && $method === 'POST') {
    $sig = $h['x-rivet-signature-v2'] ?? '';
    $okSig = false;
    if (preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $sig, $m)) {
        $okSig = abs(time() - (int) $m[1]) <= 300 && hash_equals(hash_hmac('sha256', $m[1] . '.' . $body, (string) ($cfg['outbound_secret'] ?? '')), $m[2]);
    }
    $log($base + ['event' => 'rivetit_event', 'sig_ok' => $okSig, 'event_type' => $h['x-rivet-event-type'] ?? null, 'event_id' => $h['x-rivet-event-id'] ?? null, 'body' => $body]);
    if (!empty($ctl['reject_events'])) {
        return $out(400, ['error' => ['code' => 'bad_event']]);
    }
    if (!empty($ctl['fail_events']) && (int) $ctl['fail_events'] > 0) {
        $ctl['fail_events']--;
        $saveCtl($ctl);
        return $out(503, ['error' => ['code' => 'busy']]);
    }
    if (!$okSig) {
        return $out(401, ['error' => ['code' => 'invalid_signature']]);
    }
    return $out(202, ['status' => 'accepted']);
}

// ---- everything else needs the machine token --------------------------------------------------------------------------------
if (!empty($ctl['slow'])) {
    sleep((int) $ctl['slow']);
}
$tok = (string) @file_get_contents($dir . '/token.txt');
$bearerOk = preg_match('/^Bearer (\S+)$/', $h['authorization'] ?? '', $m) === 1 && $tok !== '' && hash_equals($tok, $m[1]);
if (!empty($ctl['down'])) {
    $log($base + ['event' => 'down']);
    return $out(503, ['error' => ['code' => 'maintenance']]);
}
if (!empty($ctl['redirect'])) {
    header('Location: http://169.254.169.254/latest/meta-data/');
    http_response_code(302);
    return true;
}
if (!empty($ctl['bad_json'])) {
    return $out(200, 'not json at all');
}
if (!empty($ctl['big'])) {
    return $out(200, str_repeat('x', 2 * 1024 * 1024));
}
if (!empty($ctl['flaky']) && $method === 'GET') {
    $n = (int) @file_get_contents($dir . '/flaky.count');
    file_put_contents($dir . '/flaky.count', (string) ($n + 1));
    if ($n < (int) $ctl['flaky']) {
        $log($base + ['event' => 'flaky_503']);
        return $out(503, ['error' => ['code' => 'busy']], ['Retry-After' => '0']);
    }
}
if (!$bearerOk) {
    $log($base + ['event' => 'unauthorized']);
    return $out(401, ['error' => ['code' => 'invalid_token']]);
}

// ---- system endpoints ------------------------------------------------------------------------------------------------------
if ($sub === '/integrations/health' && $method === 'GET') {
    $log($base + ['event' => 'health']);
    if (!empty($ctl['health_status'])) {
        return $out(200, ['status' => $ctl['health_status']]);
    }
    return $out(200, ['status' => 'ok', 'version' => '1']);
}

// ---- per-user endpoints: the user context is mandatory and verified -----------------------------------------------------------------
$ctxJwt = $h['x-rivet-user-context'] ?? '';
$claims = null;
$why = 'missing';
if ($ctxJwt !== '') {
    try {
        $keys = JWK::parseKeySet(json_decode((string) file_get_contents($dir . '/jwks.json'), true), 'ES256');
        $hdr = json_decode((string) base64_decode(strtr(explode('.', $ctxJwt)[0], '-_', '+/')), true);
        if (($hdr['typ'] ?? '') !== 'rivetit-user-context+jwt') {
            throw new \RuntimeException('typ');
        }
        $claims = (array) JWT::decode($ctxJwt, $keys);
        if (($claims['aud'] ?? '') !== ($cfg['audience'] ?? '') || ($claims['iss'] ?? '') !== ($cfg['issuer'] ?? '')) {
            throw new \RuntimeException('aud/iss');
        }
        $why = 'ok';
    } catch (\Throwable $e) {
        $claims = null;
        $why = 'invalid:' . $e->getMessage();
    }
}
$log($base + ['event' => 'user_call', 'ctx_ok' => $claims !== null, 'ctx_why' => $why, 'sub' => $claims['sub'] ?? null, 'roles' => $claims['roles'] ?? null, 'dept' => isset($claims['dept']) ? (array) $claims['dept'] : null,
    'scp' => $claims['scp'] ?? null, 'query' => $_SERVER['QUERY_STRING'] ?? '']);
$personal = str_starts_with($sub, '/me/') || str_starts_with($sub, '/quality/');
if (!$personal) {
    return $out(404, ['error' => ['code' => 'not_found']]);
}
if ($claims === null) {
    return $out(403, ['error' => ['code' => 'user_context_required']]);   // a service token alone never reads a person's items
}
$sub_ = (string) $claims['sub'];
$roles = (array) ($claims['roles'] ?? []);
$web = ($cfg['web'] ?? 'http://127.0.0.1:8766');
$list = static fn(array $d): array => ['data' => $d, 'next_cursor' => null];
$people = [
    // the mock keeps data per person (keyed by the sub claim) to prove the per-user context decides what is returned
    '1842' => [
        'tasks' => [['id' => 't1', 'title' => 'Calibrate gauge <script>alert(1)</script>', 'status' => 'open', 'due_on' => '2026-11-20', 'url' => $web . '/tasks/t1'], ['id' => 't2', 'title' => 'Late task', 'status' => 'overdue', 'overdue' => true, 'due_on' => '2026-10-01', 'url' => 'https://evil.example/phish']],
        'actions' => [['id' => 'CAPA-12', 'title' => 'Fix packaging', 'status' => 'open', 'due_on' => '2026-11-30', 'requires_response' => true, 'owner' => 'Sophie', 'url' => 'javascript:alert(1)']],
        'documents' => [['id' => 'DOC-7', 'title' => 'Forklift SOP', 'version' => '3', 'status' => 'pending_acknowledgment', 'due_on' => '2026-11-15'], ['id' => 'DOC-8', 'title' => 'Done SOP', 'version' => '1', 'status' => 'acknowledged', 'acknowledged_on' => '2026-10-01']],
        'training' => [['id' => 'TR-1', 'title' => 'GMP basics', 'status' => 'open', 'due_on' => '2026-12-01']],
        'notifications' => [['id' => 'n1', 'title' => 'New SOP published', 'created_at' => '2026-10-30 09:00', 'read' => false]],
        'history' => [['id' => 'h1', 'title' => 'Acknowledged SOP 2', 'event' => 'acknowledged', 'occurred_at' => '2026-09-01 08:00']],
    ],
];
$mine = $people[$sub_] ?? [];
if (preg_match('~^/me/quality/(tasks|actions|documents|training|notifications|history)$~', $sub, $m) && $method === 'GET') {
    return $out(200, $list($mine[$m[1]] ?? []));
}
if (preg_match('~^/me/quality/documents/([A-Za-z0-9._:-]+)/acknowledgments$~', $sub, $m) && $method === 'POST') {
    if (($h['idempotency-key'] ?? '') === '') {
        return $out(400, ['error' => ['code' => 'idempotency_key_required']]);
    }
    $seen = $dir . '/ack_' . md5($h['idempotency-key']);
    if (is_file($seen)) {
        return $out(200, ['status' => 'acknowledged', 'replayed' => true], ['Idempotent-Replay' => 'true']);
    }
    file_put_contents($seen, '1');
    $log(['event' => 'ack_recorded', 'sub' => $sub_, 'doc' => $m[1], 'idem' => $h['idempotency-key']]);
    return $out(201, ['status' => 'acknowledged']);
}
if (preg_match('~^/quality/actions/([A-Za-z0-9._:-]+)/responses$~', $sub, $m) && $method === 'POST') {
    $log(['event' => 'response_recorded', 'sub' => $sub_, 'action' => $m[1], 'idem' => $h['idempotency-key'] ?? null, 'text' => json_decode($body, true)['text'] ?? null]);
    return $out(201, ['status' => 'recorded']);
}
// manager endpoints: the role comes from the SIGNED context, not from the query
if (str_starts_with($sub, '/quality/')) {
    if (!in_array('manager', $roles, true)) {
        return $out(403, ['error' => ['code' => 'not_a_manager']]);
    }
    $deptId = (string) ((array) $claims['dept'])['rivetit_id'];
    if (isset($_GET['department']) && (string) $_GET['department'] !== $deptId) {
        return $out(403, ['error' => ['code' => 'other_department']]);   // never trusted; the QMS compares with the signed claim
    }
    if ($sub === '/quality/reports/summary') {
        return $out(200, ['data' => ['open_actions' => 4, 'overdue_actions' => 1, 'overdue_tasks' => 2, 'pending_acknowledgments' => 7, 'training_compliance_pct' => 91.6, 'as_of' => '2026-11-01T10:00:00Z']]);
    }
    if ($sub === '/quality/actions' && $method === 'GET') {
        return $out(200, $list([['id' => 'CAPA-12', 'title' => 'Fix packaging', 'status' => 'open', 'due_on' => '2026-11-30', 'owner' => 'Sophie Tran'], ['id' => 'CAPA-13', 'title' => 'Late one', 'status' => 'overdue', 'overdue' => true, 'due_on' => '2026-10-01']]));
    }
    if ($sub === '/quality/tasks' && $method === 'GET') {
        return $out(200, $list([['id' => 't2', 'title' => 'Late task', 'status' => 'overdue', 'overdue' => true, 'due_on' => '2026-10-01']]));
    }
    if ($sub === '/quality/training/compliance') {
        return $out(200, $list([['id' => 'c1', 'title' => 'GMP basics', 'assigned' => 12, 'completed' => 10, 'overdue' => 1, 'pct' => 83]]));
    }
    if ($sub === '/quality/documents/acknowledgments') {
        return $out(200, $list([['id' => 'DOC-7', 'title' => 'Forklift SOP', 'version' => '3', 'pending_count' => 5, 'due_on' => '2026-11-15']]));
    }
}
return $out(404, ['error' => ['code' => 'not_found']]);
