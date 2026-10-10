<?php
/*
 * Wave 1 security, item 5: staff password policy and hashing.
 *   - minimum length (setting, default 12), not equal to name / username / email (or the part of the email before the @)
 *   - optional Have I Been Pwned range check: off by default, fails OPEN, only the 5 character SHA-1 prefix is ever handed to the fetch,
 *     the fetch goes through RivetCore's UrlPolicy
 *   - new hashes are Argon2id (64 MiB, 3 passes); old bcrypt hashes still verify and are replaced at the next successful sign-in
 *   - the places that create or change a staff password use the policy and the new hash
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/security_passwords.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_policy.php";

$q("DELETE FROM security_settings");
secSettingsAll($db, true);
$who = ['name' => 'Alex Morgan', 'email' => 'alex.morgan@example.test', 'username' => 'alex.morgan@example.test'];

// ------------------------------------------------------------------ length and identity
$ok(secSettingInt('password_min_length') === 12, 'the default minimum length is 12');
$ok(secPasswordPolicyError('short-pass', $who) !== null && str_contains((string) secPasswordPolicyError('short-pass', $who), '12'), 'an 11 character password is refused and the message names the minimum');
$ok(secPasswordPolicyError('twelve-chars', $who) === null, 'a 12 character password is accepted');
$ok(secPasswordPolicyError('a-much-longer-passphrase-than-needed', $who) === null, 'a long passphrase is accepted');
$ok(secPasswordPolicyError('ALEX.MORGAN@EXAMPLE.TEST', $who) !== null, 'the email address (any case) is refused');
$ok(secPasswordPolicyError('alex morgan', $who) !== null, '"alex morgan" is refused (11 characters, and it is the name)');
$ok(secPasswordPolicyError('Alex Morgan Alex', $who) === null, 'a password that merely contains the name is not refused (only equality is)');
$ok(secPasswordPolicyError('alex.morganX', ['email' => 'alex.morganX@example.test']) !== null, 'the part of the email before the @ is refused');
$ok(secPasswordPolicyError('Some Long User Name', ['name' => 'Some Long User Name']) !== null, 'the user\'s name as the password is refused');
secSettingSet($db, 'password_min_length', 16);
$ok(secPasswordPolicyError('twelve-chars', $who) !== null && secPasswordPolicyError('sixteen-chars-ok', $who) === null, 'the minimum length is a setting');
secSettingSet($db, 'password_min_length', 3);
$ok(secSettingInt('password_min_length') === 8, 'the setting cannot be lowered under 8');
secSettingSet($db, 'password_min_length', 12);

// ------------------------------------------------------------------ HIBP
$sha = strtoupper(sha1('Password1234!'));
$calls = [];
$fetchHit = function (string $prefix) use (&$calls, $sha) { $calls[] = $prefix; return "0018A45C4D1DEF81644B54AB7F969B88D65:3\r\n" . substr($sha, 5) . ":9545\r\nFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:1"; };
$fetchMiss = function (string $prefix) { return "0018A45C4D1DEF81644B54AB7F969B88D65:3\r\n00D4F6E8FA6EECAD2A3AA415EEC418D38EC:2"; };
$ok(secPasswordIsPwned('Password1234!', $fetchHit) === true, 'a password found in the range data is reported');
$ok(secPasswordIsPwned('Password1234!', $fetchMiss) === false, 'a password not in the range data is clean');
$ok(secPasswordIsPwned('Password1234!', fn($p) => null) === null, 'a failed fetch is "could not check" (null)');
$ok(secPasswordIsPwned('Password1234!', function ($p) { throw new RuntimeException('timeout'); }) === null, 'an exception in the fetch is also "could not check"');
$ok(secPasswordIsPwned('Password1234!', fn($p) => '') === null, 'an empty response is "could not check"');
$ok(count($calls) === 1 && strlen($calls[0]) === 5 && $calls[0] === substr($sha, 0, 5), 'only the 5 character prefix of the SHA-1 is handed to the fetch');
$ok(secPasswordPolicyError('Password1234!', $who, null, $fetchHit) === null, 'the check is OFF by default: a breached password is not refused');
secSettingSet($db, 'password_hibp_check', 1);
$ok(secPasswordPolicyError('Password1234!', $who, null, $fetchHit) !== null, 'with the check on, a breached password is refused');
$ok(secPasswordPolicyError('Password1234!', $who, null, $fetchMiss) === null, 'with the check on, a clean password is accepted');
$ok(secPasswordPolicyError('Password1234!', $who, null, fn($p) => null) === null, 'with the check on, an unreachable service accepts the password (fails open)');
$src = file_get_contents("$root/includes/security_policy.php");
$ok(str_contains($src, 'new \RivetCore\Webhooks\UrlPolicy(false)') && str_contains($src, '->vet($url)') && str_contains($src, 'CURLOPT_RESOLVE') && str_contains($src, 'CURLOPT_FOLLOWLOCATION => false'), 'the real fetch vets the URL with RivetCore\'s UrlPolicy, pins the connection and follows no redirects');
$ok(str_contains($src, 'CURLOPT_TIMEOUT        => 3') && str_contains($src, 'CURLPROTO_HTTPS'), 'the real fetch has a short timeout and speaks only HTTPS');
$ok(secHibpFetchRange('xyz') === null && secHibpFetchRange('GGGGG') === null, 'the real fetch refuses anything that is not a 5 character hex prefix');
secSettingSet($db, 'password_hibp_check', 0);

// ------------------------------------------------------------------ hashing
$h = secPasswordHash('correct horse battery');
$info = password_get_info($h);
$ok($info['algo'] === 'argon2id', 'a new hash is Argon2id');
$ok(($info['options']['memory_cost'] ?? 0) === 65536 && ($info['options']['time_cost'] ?? 0) === 3, 'with 64 MiB memory and 3 passes');
$ok(password_verify('correct horse battery', $h) && !password_verify('wrong', $h), 'and it verifies');
$ok(secPasswordNeedsRehash($h) === false, 'a fresh hash does not need a rehash');
$bcrypt = password_hash('old-bcrypt-pass', PASSWORD_BCRYPT);
$ok(password_verify('old-bcrypt-pass', $bcrypt) && secPasswordNeedsRehash($bcrypt) === true, 'an old bcrypt hash still verifies and is flagged for rehash');
$weak = password_hash('weak-argon', PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
$ok(secPasswordNeedsRehash($weak) === true, 'an Argon2id hash with weaker parameters is flagged too');

$q("DELETE FROM users WHERE user_email='sec-pw@example.test'");
$q("INSERT INTO users SET user_name='Sec PW', user_email='sec-pw@example.test', user_password='" . $esc($bcrypt) . "', user_type=1, user_status=1, user_role_id=1");
$uid = (int) $one("SELECT user_id FROM users WHERE user_email='sec-pw@example.test'");
$ok(secPasswordRehashIfNeeded($db, $uid, 'old-bcrypt-pass', $bcrypt) === true, 'a successful sign-in replaces the old hash');
$now = $one("SELECT user_password FROM users WHERE user_id=$uid");
$ok(str_starts_with($now, '$argon2id$') && password_verify('old-bcrypt-pass', $now), 'the stored hash is now Argon2id and the same password still works');
$ok(secPasswordRehashIfNeeded($db, $uid, 'old-bcrypt-pass', $now) === false && $one("SELECT user_password FROM users WHERE user_id=$uid") === $now, 'an up-to-date hash is left alone');
$ok(secPasswordRehashIfNeeded($db, $uid, 'old-bcrypt-pass', $bcrypt) === false && $one("SELECT user_password FROM users WHERE user_id=$uid") === $now, 'a stale rehash cannot overwrite a newer hash (compare-and-set on the old value)');

// ------------------------------------------------------------------ the places that create or change a staff password
$expect = [
    'admin/post/users.php'            => ['secPasswordPolicyError(', 'secPasswordHash('],
    'agent/user/post/profile.php'     => ['secPasswordPolicyError(', 'secPasswordHash(', 'secSessionsOnPasswordChange('],
    'setup/index.php'                 => ['secPasswordPolicyError(', 'secPasswordHash('],
    'scripts/setup_cli.php'           => ['secPasswordPolicyError(', 'secPasswordHash('],
    'api/v1/me.php'                   => ['secPasswordPolicyError(', 'secPasswordHash(', 'secSessionsOnPasswordChange('],
    'login.php'                       => ['secPasswordRehashIfNeeded('],
    'api/v1/auth.php'                 => ['secPasswordRehashIfNeeded('],
];
foreach ($expect as $file => $needles) {
    $s = file_get_contents("$root/$file");
    $missing = array_filter($needles, fn($n) => !str_contains($s, $n));
    $ok($missing === [], "$file uses " . implode(', ', $needles));
}
$ok(substr_count(file_get_contents("$root/admin/post/users.php"), 'password_hash(') === 1, 'admin/post/users.php only keeps password_hash() for the random password of an archived account');

// the real setup CLI refuses a short password before it writes anything. Run it from a skeleton directory with no config.php
// (with one, setup is "disabled"), the real code symlinked in.
$skel = sys_get_temp_dir() . '/sec_pw_skel_' . bin2hex(random_bytes(4));
mkdir("$skel/scripts", 0700, true);
copy("$root/scripts/setup_cli.php", "$skel/scripts/setup_cli.php");
foreach (['functions.php', 'includes', 'vendor', 'plugins', 'src', 'db.sql'] as $f) { symlink("$root/$f", "$skel/$f"); }
$cli = fn(string $pw) => sec_sh('cd ' . escapeshellarg("$skel/scripts") . ' && php setup_cli.php --host=localhost --username=x --password=y --database=nonexistent_scratch --base-url=t.test --locale=en_US --timezone=UTC --currency=USD --company-name=T --country="United States" --user-name="A B" --user-email=a@b.test --user-password=' . escapeshellarg($pw) . ' --non-interactive < /dev/null');
[$c, $o] = $cli('short');
$ok($c !== 0 && str_contains($o, 'at least 12'), 'scripts/setup_cli.php refuses a password under 12 characters (non-zero exit)');
[$c, $o] = $cli('a@b.test');
$ok($c !== 0, 'and one equal to the email address (also under 12 here)');
sec_sh('rm -rf ' . escapeshellarg($skel));

// cleanup
$q("DELETE FROM users WHERE user_email='sec-pw@example.test'");
$q("DELETE FROM security_settings");
