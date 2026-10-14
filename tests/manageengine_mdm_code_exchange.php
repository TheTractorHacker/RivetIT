<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * MdmClient::exchangeAuthorizationCode() against a LOCAL mock of the Zoho token endpoint. No database, no real network:
 *   php tests/manageengine_mdm_code_exchange.php
 */
foreach (['MdmException', 'MdmTokenStore', 'InMemoryMdmTokenStore', 'DataCenters', 'ConnectionResult', 'MdmClient'] as $c) {
    foreach ([__DIR__ . "/../src/Integrations/ManageEngine/$c.php", __DIR__ . "/../src/Integrations/$c.php"] as $f) { if (is_file($f)) { require_once $f; break; } }
}
use ITFlow\Integrations\ManageEngine\MdmClient;
use ITFlow\Integrations\ManageEngine\MdmException;

$dir = sys_get_temp_dir() . '/mdm_code_' . getmypid();
mkdir($dir);
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
header('Content-Type: application/json');
parse_str(file_get_contents('php://input'), $p);
if (($p['grant_type'] ?? '') !== 'authorization_code') { echo json_encode(['error' => 'wrong_grant']); return; }
if (($p['client_secret'] ?? '') !== 'good-secret') { echo json_encode(['error' => 'invalid_client']); return; }
switch ($p['code'] ?? '') {
    case '1000.goodcode.aaaaaaaa': echo json_encode(['access_token' => 'at', 'refresh_token' => '1000.new.refresh', 'expires_in' => 3600]); break;
    case '1000.reused.bbbbbbbb':   echo json_encode(['access_token' => 'at', 'expires_in' => 3600]); break;
    default: echo json_encode(['error' => 'invalid_code']);
}
PHP);
$port = random_int(20000, 40000);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
$fail = 0;
$t = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fail++; } };
$mk = fn(string $secret) => new MdmClient('us', '1000.CLIENT', $secret, '', null, ['accounts_base' => "http://127.0.0.1:$port", 'max_retries' => 0]);
$err = function (callable $f): ?MdmException { try { $f(); } catch (MdmException $e) { return $e; } return null; };

$t($mk('good-secret')->exchangeAuthorizationCode('1000.goodcode.aaaaaaaa') === '1000.new.refresh', 'a good code returns the refresh token');
$e = $err(fn() => $mk('good-secret')->exchangeAuthorizationCode('1000.expired.cccccccc'));
$t($e !== null && $e->getCode() !== null && str_contains($e->getMessage(), 'invalid_code') && str_contains($e->getMessage(), 'generate a new one'), 'a bad or expired code gives a clear message');
$e = $err(fn() => $mk('good-secret')->exchangeAuthorizationCode('1000.reused.bbbbbbbb'));
$t($e !== null && str_contains($e->getMessage(), 'already used'), 'a reply with no refresh token says the code was probably used');
$e = $err(fn() => $mk('wrong-secret')->exchangeAuthorizationCode('1000.goodcode.aaaaaaaa'));
$t($e !== null && str_contains($e->getMessage(), 'invalid_client'), 'a wrong secret is reported');
$e = $err(fn() => $mk('good-secret')->exchangeAuthorizationCode('1000.goodcode.aaaaaaaa'));
$t($e === null, 'the code is not consumed by the mock, so a repeat in the test works (real Zoho refuses a reuse)');
$msg = $err(fn() => $mk('good-secret')->exchangeAuthorizationCode('x'));
$t($msg !== null && !str_contains($msg->getMessage(), 'good-secret'), 'the secret never appears in an error message');

proc_terminate($proc); proc_close($proc);
array_map('unlink', glob($dir . '/*')); rmdir($dir);
exit($fail ? 1 : 0);
