<?php
// Calls RivetCore's server-side ChangeProtocol::redact()/alertText()/text() on fake secrets. No DB. Run: php redaction_vectors.php
require '/home/sysadmin/qa-beta-audit/vendor/autoload.php';
use RivetCore\Rmm\Change\ChangeProtocol as C;
$v = [
 // [label, input, secret substring that must NOT survive]
 ['DB_PASS bare line', "DB_PASS=Hunter2Hunter2", 'Hunter2Hunter2'],
 ['DB_PASS quoted', "DB_PASS='Hunter2 spaced'", 'Hunter2'],
 ['ENCRYPTION_KEY', "ENCRYPTION_KEY=abc123SECRETvalue", 'abc123SECRETvalue'],
 ['mysql -p attached', "mysql -u root -pSuperSecret1 db", 'SuperSecret1'],
 ['mysql -p space', "mysql -u root -p SuperSecret2 db", 'SuperSecret2'],
 ['mariadb -p', "mariadb-dump -u root -pSuperSecret3", 'SuperSecret3'],
 ['sshpass -p', "sshpass -p SuperSecret4 ssh host", 'SuperSecret4'],
 ['mongo -p', "mongosh -u admin -p SuperSecret5 --host x", 'SuperSecret5'],
 ['--password=', "tool --password=SuperSecret6", 'SuperSecret6'],
 ['--password space', "tool --password SuperSecret7 run", 'SuperSecret7'],
 ['--token', "tool --token ghp_SuperSecret8abcdef", 'SuperSecret8'],
 ['api_key json', '{"api_key":"SuperSecret9abc"}', 'SuperSecret9abc'],
 ['password json', '{"password":"SuperSecret10"}', 'SuperSecret10'],
 ['Authorization Bearer short', "Authorization: Bearer SuperSecret11abc", 'SuperSecret11abc'],
 ['Authorization Bearer jwt', "Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk", 'eyJhbGci'],
 ['URL userinfo', "https://admin:SuperSecret12@host.example/x", 'SuperSecret12'],
 ['URL userinfo encoded', "postgres://u:p%40ss%3Aword13@db/x", 'word13'],
 ['PGPASSWORD env prefix', "PGPASSWORD=SuperSecret14 pg_dump db", 'SuperSecret14'],
 ['MYSQL_PWD env prefix', "MYSQL_PWD=SuperSecret15 mysql", 'SuperSecret15'],
 ['export MYSQL_PWD', "export MYSQL_PWD=SuperSecret16", 'SuperSecret16'],
 ['Environment= systemd', "Environment=APP_SECRET=SuperSecret17 PATH=/usr/bin", 'SuperSecret17'],
 ['client_secret', "client_secret=SuperSecret18abc", 'SuperSecret18abc'],
 ['SECRET= bare', "SECRET=SuperSecret19abc", 'SuperSecret19abc'],
 ['token= bare', "token=SuperSecret20abc", 'SuperSecret20abc'],
 ['AWS secret key', "aws_secret_access_key = SuperSecret21abcdefghijklmnopqrstuvwx", 'SuperSecret21'],
 ['AWS access key id bare', "AKIAIOSFODNN7EXAMPLE", 'AKIAIOSFODNN7EXAMPLE'],
 ['bare 32 hex', "deadbeefdeadbeefdeadbeefdeadbeef", 'deadbeefdeadbeef'],
 ['long base64 40', "dGhpcyBpcyBhIHNlY3JldCB0b2tlbiB2YWx1ZSAxMjM0NTY=", 'dGhpcyBpcyBhIH'],
 ['short base64 24', "U2VjcmV0VG9rZW5WYWx1ZQ==", 'U2VjcmV0'],
 ['powershell ConvertTo-SecureString', "ConvertTo-SecureString -String 'SuperSecret22' -AsPlainText -Force", 'SuperSecret22'],
 ['net use /user', "net use Z: \\\\srv\\share /user:corp\\bob SuperSecret23", 'SuperSecret23'],
 ['schtasks /RP', "schtasks /Create /RU bob /RP SuperSecret24 /TN x", 'SuperSecret24'],
 ['curl -u', "curl -u bob:SuperSecret25 https://x", 'SuperSecret25'],
 ['curl -H X-Api-Key', 'curl -H "X-Api-Key: SuperSecret26abc" https://x', 'SuperSecret26abc'],
 ['ssh private key block', "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmU\n-----END OPENSSH PRIVATE KEY-----", 'b3BlbnNzaC1rZXkt'],
 ['connection string', "Server=db;User Id=sa;Password=SuperSecret27;", 'SuperSecret27'],
 ['smtp pass', "SMTP_PASS=SuperSecret28", 'SuperSecret28'],
 ['redis pw', "REDIS_PW=SuperSecret29", 'SuperSecret29'],
 ['sudoers NOPASSWD kept', "bob ALL=(ALL) NOPASSWD: ALL", null],
 ['ssh public key kept', "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKexamplepublickeyexamplepublickeyexamplepub bob@host", null],
];
$fail = 0;
foreach ($v as [$label, $in, $secret]) {
    $out = C::redact($in);
    $ok = $secret === null ? $out === $in || true : (stripos($out, $secret) === false);
    if ($secret === null) { $ok = $out !== '' ; }
    if (!$ok) $fail++;
    printf("%s | %-34s | %s\n", $ok ? 'PASS' : 'LEAK', $label, str_replace("\n", '\n', substr($out, 0, 110)));
}
echo "\nredact(): $fail of " . count($v) . " vectors leak the secret\n";
// alertText / text
$t = ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', "a\u{202e}b\u{200b}c\u{2066}d", "tick`cmd` \"q\" 'x'", "line1\nline2\x00\x1b[31m", '&lt;b&gt;', '<<>>'];
foreach ($t as $s) {
    $a = C::alertText($s); $x = C::text($s, 200);
    $bad = preg_match('/[<>`"\']|[\x{202a}-\x{202e}\x{2066}-\x{2069}\x{200b}-\x{200f}\x{feff}]|[\x00-\x1f]/u', $a) === 1;
    printf("%s | alertText %-40s -> %s | text -> %s\n", $bad ? 'LEAK' : 'PASS', json_encode($s), json_encode($a), json_encode($x));
}
