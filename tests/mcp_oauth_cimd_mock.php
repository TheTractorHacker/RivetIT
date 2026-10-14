<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Test helper for tests/mcp_oauth_cimd.php: a tiny HTTPS server (self-signed certificate, one connection at a time) that plays
 * "somebody's client metadata document host". It answers whatever the test wrote into the control file, so the same URL can be
 * valid, then broken, then valid again. Never started by anything except that test, and it only listens on 127.0.0.1.
 *
 *   php tests/mcp_oauth_cimd_mock.php <port> <pem (key + certificate)> <control.json> <hits.log>
 *
 * control.json maps a request path to {status, headers:[...], body, delay (seconds), repeat (bytes of padding to append)}.
 * "{{SELF}}" in a body is replaced by this server's own URL of that path (so a document can carry the right client_id).
 * Every request path is appended to hits.log, which is how the test proves a cached document was not fetched again.
 */
[, $port, $pem, $controlFile, $hitsFile] = $argv + [null, null, null, null, null];
if (!$port || !is_file((string) $pem)) { fwrite(STDERR, "usage: mcp_oauth_cimd_mock.php port pem control hits\n"); exit(2); }

$ctx = stream_context_create(['ssl' => ['local_cert' => $pem, 'allow_self_signed' => true, 'verify_peer' => false]]);
$server = stream_socket_server("ssl://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) { fwrite(STDERR, "listen failed: $errstr\n"); exit(2); }
pcntl_signal(SIGTERM, static fn () => exit(0));

while (true) {
    pcntl_signal_dispatch();
    $conn = @stream_socket_accept($server, 1);
    if (!$conn) { continue; }
    stream_set_timeout($conn, 3);
    $line = fgets($conn);
    while (($h = fgets($conn)) !== false && trim($h) !== '') { /* drain request headers */ }
    $path = '/';
    if (is_string($line) && preg_match('#^GET (\S+) HTTP#', $line, $m)) { $path = $m[1]; }
    file_put_contents($hitsFile, $path . "\n", FILE_APPEND | LOCK_EX);
    $control = json_decode((string) @file_get_contents($controlFile), true) ?: [];
    $spec = $control[$path] ?? ['status' => 404, 'headers' => ['Content-Type: text/plain'], 'body' => 'not found'];
    if (!empty($spec['delay'])) { sleep((int) $spec['delay']); }
    $body = str_replace('{{SELF}}', "https://127.0.0.1:$port$path", (string) ($spec['body'] ?? ''));
    if (!empty($spec['repeat'])) { $body .= str_repeat(' ', (int) $spec['repeat']); }
    $status = (int) ($spec['status'] ?? 200);
    $out = "HTTP/1.1 $status X\r\n";
    foreach ($spec['headers'] ?? ['Content-Type: application/json'] as $hdr) { $out .= $hdr . "\r\n"; }
    $out .= 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    @fwrite($conn, $out);
    @fclose($conn);
}
