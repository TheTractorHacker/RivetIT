<?php
/*
 * Proof that a queued PDF is really attached when the mailer sends: the REAL sendQueueEmail() from cron/mail_queue.php is extracted and
 * pointed at a throwaway SMTP listener on 127.0.0.1 (a few lines of Python started by this test, nothing leaves the machine). The captured
 * message is parsed and the attachment compared byte for byte. No scratch DB needed beyond the bootstrap.
 */
require __DIR__ . '/i34_bootstrap.php';
require_once __DIR__ . '/../plugins/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../plugins/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../plugins/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../plugins/PHPMailer/src/OAuthTokenProvider.php';
require_once __DIR__ . '/../plugins/PHPMailer/src/OAuth.php';
$src = file_get_contents(__DIR__ . '/../cron/mail_queue.php');
if (!preg_match('/^class StaticTokenProvider.*?^}\n/ms', $src, $c) || !preg_match('/^function sendQueueEmail\(.*?^}\n/ms', $src, $f)) { echo "FAIL  could not extract the mailer\n"; exit(1); }
eval('use PHPMailer\PHPMailer\PHPMailer; use PHPMailer\PHPMailer\Exception; use PHPMailer\PHPMailer\OAuthTokenProvider; use ITFlow\Mail\AutoReplyDetector; use ITFlow\Mail\MessageId; ' . $c[0] . $f[0]);

$port = random_int(20000, 40000);
$out = sys_get_temp_dir() . '/i34_smtp_' . bin2hex(random_bytes(4)) . '.eml';
$py = <<<'PY'
import socket, sys
port, out = int(sys.argv[1]), sys.argv[2]
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('127.0.0.1', port)); s.listen(1); s.settimeout(30)
c, _ = s.accept(); f = c.makefile('rwb', buffering=0)
def say(t): f.write((t + '\r\n').encode())
say('220 test ESMTP')
data = False; buf = b''
while True:
    l = f.readline()
    if not l: break
    if data:
        if l == b'.\r\n':
            data = False; open(out, 'wb').write(buf); say('250 queued'); continue
        buf += l; continue
    u = l.decode(errors='replace').strip().upper()
    if u.startswith('EHLO') or u.startswith('HELO'): say('250 test')
    elif u.startswith('DATA'): data = True; say('354 go')
    elif u.startswith('QUIT'): say('221 bye'); break
    else: say('250 ok')
PY;
$pyFile = sys_get_temp_dir() . '/i34_smtp_' . bin2hex(random_bytes(4)) . '.py';
file_put_contents($pyFile, $py);
$proc = proc_open(['python3', $pyFile, (string) $port, $out], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(600000);

$pdf = \ITFlow\Reports\ReportPdf::render('Mail proof', [['T'], ['a', 'b'], ['1', '2']], []);
$err = null;
try {
    $r = sendQueueEmail('standard_smtp', '127.0.0.1', $port, 'none', '', '', 'noreply@example.com', 'RivetIT', 'to@example.com', 'To', 'Report: Mail proof', '<p>see attached</p>', '', '', '', '', '', '', '', 'abc123@example.com', true,
        [['attachment_filename' => 'mail_proof.pdf', 'attachment_mime' => 'application/pdf', 'attachment_content' => $pdf]]);
} catch (\Throwable $e) { $err = $e->getMessage(); $r = false; }
proc_close($proc);
$ok($r === true && $err === null, 'sendQueueEmail() with an attachment completes against the local listener' . ($err ? " ($err)" : ''));
$raw = is_file($out) ? file_get_contents($out) : '';
$ok(strlen($raw) > $pdf ? true : strlen($raw) > 1000, 'a message was captured by the listener (' . strlen($raw) . ' bytes)');
$parsed = null;
if ($raw !== '') {
    // minimal MIME walk without extra libraries: find the attachment part and decode its base64 body
    if (preg_match('/Content-Type: application\/pdf;[^\r\n]*\r\n(?:[^\r\n]+\r\n)*?\r\n([A-Za-z0-9+\/=\r\n]+)\r\n--/s', $raw, $m)) {
        $parsed = base64_decode(preg_replace('/\s+/', '', $m[1]));
    }
}
$ok($parsed !== null && $parsed === $pdf, 'the attachment in the delivered message equals the PDF byte for byte');
$ok(strpos($raw, 'filename=mail_proof.pdf') !== false || strpos($raw, 'filename="mail_proof.pdf"') !== false, 'attachment keeps its file name');
$ok(strpos($raw, 'Auto-Submitted: auto-generated') !== false && strpos($raw, 'multipart/mixed') !== false, 'message is multipart/mixed and still marked auto-submitted');
// and without attachments nothing changes
$out2 = sys_get_temp_dir() . '/i34_smtp_' . bin2hex(random_bytes(4)) . '.eml';
$port2 = $port + 1;
file_put_contents($pyFile, $py);
$proc = proc_open(['python3', $pyFile, (string) $port2, $out2], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(600000);
$r2 = sendQueueEmail('standard_smtp', '127.0.0.1', $port2, 'none', '', '', 'noreply@example.com', 'RivetIT', 'to@example.com', 'To', 'Plain', '<p>x</p>', '', '', '', '', '', '', '', 'def456@example.com', true);
proc_close($proc);
$raw2 = is_file($out2) ? file_get_contents($out2) : '';
$ok($r2 === true && strpos($raw2, 'application/pdf') === false && strpos($raw2, 'multipart/mixed') === false, 'a message without attachments is unchanged (single part)');
@unlink($out); @unlink($out2); @unlink($pyFile);
echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
