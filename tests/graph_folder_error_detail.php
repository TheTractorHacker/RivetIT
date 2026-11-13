<?php

declare(strict_types=1);

// graphFindOrCreateItflowFolder() must say why it failed (HTTP status + Graph error code), without leaking tokens or bodies.
// Run: php tests/graph_folder_error_detail.php

$src = (string) file_get_contents(__DIR__ . '/../cron/ticket_email_parser.php');
if (!preg_match('/^function graphFindOrCreateItflowFolder\(.*?^\}/ms', $src, $m)) {
    fwrite(STDERR, "FAIL: function not found\n");
    exit(1);
}
$responses = [];
function microsoftGraphRequest(string $method, string $url, string $token, ?array $body = null): array
{
    global $responses;
    return array_shift($responses);
}
eval($m[0]);

$fails = 0;
$check = static function (string $n, bool $ok, string $d = '') use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $n . ($ok ? '' : "  $d") . "\n";
    $fails += $ok ? 0 : 1;
};

$responses = [['ok' => true, 'code' => 200, 'json' => ['value' => [['id' => 'AAA']]], 'raw' => '', 'error' => '']];
$check('found folder returns its id', graphFindOrCreateItflowFolder('b', 'SECRET-TOKEN') === 'AAA');

$responses = [
    ['ok' => false, 'code' => 403, 'json' => ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied SECRET-BODY']], 'raw' => 'SECRET-BODY', 'error' => ''],
    ['ok' => false, 'code' => 403, 'json' => ['error' => ['code' => 'ErrorAccessDenied']], 'raw' => '', 'error' => ''],
];
try {
    graphFindOrCreateItflowFolder('b', 'SECRET-TOKEN');
    $check('failure throws', false);
} catch (\RuntimeException $e) {
    $msg = $e->getMessage();
    $check('message keeps the original wording', str_contains($msg, 'could not find or create the ITFlow mail folder'), $msg);
    $check('message names both HTTP statuses and the Graph error code', str_contains($msg, 'lookup: HTTP 403 ErrorAccessDenied') && str_contains($msg, 'create: HTTP 403 ErrorAccessDenied'), $msg);
    $check('message leaks no token or response body', !str_contains($msg, 'SECRET'), $msg);
}

$responses = [
    ['ok' => false, 'code' => 0, 'json' => null, 'raw' => false, 'error' => 'Could not resolve host'],
    ['ok' => false, 'code' => 0, 'json' => null, 'raw' => false, 'error' => 'Could not resolve host'],
];
try {
    graphFindOrCreateItflowFolder('b', 't');
} catch (\RuntimeException $e) {
    $check('network failure is named', str_contains($e->getMessage(), 'network: Could not resolve host'), $e->getMessage());
}

echo $fails === 0 ? "all passed\n" : "$fails failed\n";
exit($fails === 0 ? 0 : 1);
