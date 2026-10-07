<?php
/*
 * The edition's `send_webhook` automation action (includes/event_bus.php, rivetAutomationActionHandlers) against a hostile local
 * receiver. No database: the handler is called with a null mysqli. Covers what RivetCore's own tests cannot see because this
 * handler lives in the edition (RivetCore security review 2026-10, SR-04):
 *   - a receiver streaming 150 MB cannot grow our memory (the body is read at most 1 MiB, then the transfer is aborted),
 *   - the HTTP status still decides success/failure (200 ok, 500 and 404 throw),
 *   - the connection is made through pinnedUrl() (a trailing-dot host is rewritten to the vetted host).
 *   php tests/automation_webhook_handler.php
 */
putenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE=1'); // the receiver listens on loopback
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/event_bus.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$dir = sys_get_temp_dir() . '/itflow_hook_' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/big') { header('Content-Type: text/plain'); for ($i = 0; $i < 150; $i++) { echo str_repeat('x', 1048576); @flush(); } exit; }
if ($p === '/500') { http_response_code(500); echo 'boom'; exit; }
if ($p === '/404') { http_response_code(404); exit; }
http_response_code(200); echo 'ok';
PHP);
$port = 0;
for ($i = 0; $i < 20 && !$port; $i++) { $try = random_int(18700, 18999); $s = @stream_socket_server("tcp://127.0.0.1:$try"); if ($s) { fclose($s); $port = $try; } }
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir, $dir . '/router.php'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $e, $m, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

$handler = rivetAutomationActionHandlers(null, 'T')['send_webhook'];
$call = function (string $path, array $extra = []) use ($handler, $port): array {
    try { return ['ok', $handler(['url' => "http://127.0.0.1:$port$path"] + $extra, ['event' => 'ticket.created'])]; }
    catch (\Throwable $e) { return ['err', $e->getMessage()]; }
};

[$k, $v] = $call('/hook');
$ok($k === 'ok' && $v === 'webhook sent (HTTP 200)', "a 200 answer is success ($v)");
[$k, $v] = $call('/500');
$ok($k === 'err' && str_contains($v, 'HTTP 500'), "a 500 answer fails the action ($v)");
[$k, $v] = $call('/404');
$ok($k === 'err' && str_contains($v, 'HTTP 404'), "a 404 answer fails the action ($v)");

$before = memory_get_peak_usage(true); $t = microtime(true);
[$k, $v] = $call('/big');
$grown = memory_get_peak_usage(true) - $before;
$ok($k === 'ok' && $v === 'webhook sent (HTTP 200)', "a 150 MB answer still reports the HTTP status ($k $v)");
$ok($grown < 16 * 1048576, 'a 150 MB answer does not grow memory (peak +' . round($grown / 1048576, 1) . ' MB)');
$ok(microtime(true) - $t < 8, 'the transfer is cut short, not read to the end (' . round(microtime(true) - $t, 1) . ' s)');

$target = ['host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
$ok(\RivetCore\Webhooks\WebhookDispatcher::pinnedUrl('https://example.com./p?q=1', $target) === 'https://example.com/p?q=1', 'pinnedUrl drops the trailing dot so the pin applies');
$src = (string) file_get_contents(__DIR__ . '/../includes/event_bus.php');
$ok(str_contains($src, 'curl_init(\\RivetCore\\Webhooks\\WebhookDispatcher::pinnedUrl('), 'the send_webhook handler connects through pinnedUrl()');

proc_terminate($proc); proc_close($proc);
array_map('unlink', glob($dir . '/*')); rmdir($dir);
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
