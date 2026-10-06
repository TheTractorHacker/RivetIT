<?php
/*
 * Redis settings, stats, clearing and memory limit. Needs two THROWAWAY Redis servers (never the real one) and a
 * schema-only scratch database with migration 2.6.123 applied:
 *   redis-server --port 6391 --save "" --daemonize no   (open)
 *   redis-server --port 6392 --save "" --requirepass s3cret --daemonize no
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/redis_settings.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
use ITFlow\Redis\RedisSettings;

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$db->query("SET SESSION sql_mode=''");
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };
foreach (['HOST', 'PORT', 'PASSWORD', 'DB', 'USERNAME', 'TLS', 'TLS_VERIFY', 'TLS_CA_FILE', 'TLS_CERT_FILE', 'TLS_KEY_FILE', 'ENV_FILE'] as $k) putenv("RIVETIT_REDIS_$k");

$open = ['host' => '127.0.0.1', 'port' => 6391, 'password' => null, 'db' => 0];
$locked = ['host' => '127.0.0.1', 'port' => 6392, 'password' => 's3cret', 'db' => 0];

// ---- resolve precedence
$r = RedisSettings::resolve($db);
$ok($r['host'] === '127.0.0.1' && $r['port'] === 6380 && $r['password'] === null && $r['schema_ready'] === false, 'empty settings row: built-in default 127.0.0.1:6380');
$db->query("INSERT INTO settings (company_id) VALUES (1)");
$r = RedisSettings::resolve($db);
$ok($r['schema_ready'] && $r['port'] === 6380, 'migrated schema detected; blank/zero settings fall back to defaults');
$enc = encryptSetting('s3cret');
$db->query("UPDATE settings SET config_redis_host='redis.internal', config_redis_port=6400, config_redis_db=2, config_redis_password='$enc' WHERE company_id=1");
$r = RedisSettings::resolve($db);
$ok($r['host'] === 'redis.internal' && $r['port'] === 6400 && $r['db'] === 2 && $r['password'] === 's3cret' && $r['has_stored_password'], 'saved settings used; password decrypted');
putenv('RIVETIT_REDIS_PORT=6500'); putenv('RIVETIT_REDIS_PASSWORD=envpass');
$r = RedisSettings::resolve($db);
$ok($r['port'] === 6500 && $r['password'] === 'envpass' && $r['from_env']['port'] && $r['from_env']['password'] && !$r['from_env']['host'] && $r['host'] === 'redis.internal', 'environment overrides only the fields it sets');
putenv('RIVETIT_REDIS_PORT'); putenv('RIVETIT_REDIS_PASSWORD');
$ok(RedisSettings::resolve(null)['host'] === '127.0.0.1', 'no database at all: defaults, no error');

// ---- ACL username and TLS: stored columns (migration 2.6.143), environment wins field by field
$db->query("UPDATE settings SET config_redis_username='rivet', config_redis_tls=1, config_redis_tls_verify=0, config_redis_tls_ca_file='/etc/ssl/redis/ca.crt', config_redis_tls_cert_file=NULL, config_redis_tls_key_file='' WHERE company_id=1");
$r = RedisSettings::resolve($db);
$ok($r['username'] === 'rivet' && $r['tls'] === true && $r['tls_verify'] === false && $r['tls_ca_file'] === '/etc/ssl/redis/ca.crt' && $r['tls_cert_file'] === null && $r['tls_key_file'] === null && !$r['from_env']['tls'], 'stored username / TLS / verify / CA file used; empty cert and key are null');
putenv('RIVETIT_REDIS_USERNAME=envuser'); putenv('RIVETIT_REDIS_TLS=0'); putenv('RIVETIT_REDIS_TLS_VERIFY=1'); putenv('RIVETIT_REDIS_TLS_CA_FILE=/env/ca.pem');
$r = RedisSettings::resolve($db);
$ok($r['username'] === 'envuser' && $r['tls'] === false && $r['tls_verify'] === true && $r['tls_ca_file'] === '/env/ca.pem' && $r['from_env']['username'] && $r['from_env']['tls'] && $r['from_env']['tls_verify'] && $r['from_env']['tls_ca_file'] && !$r['from_env']['tls_cert_file'], 'environment wins for each TLS field it sets (TLS=0 beats a stored 1)');
$ok($r['stored']['username'] === 'rivet' && $r['stored']['tls'] === true, 'the stored values are still reported for the form');
putenv('RIVETIT_REDIS_TLS=yes');
$ok(RedisSettings::resolve($db)['tls'] === true, 'environment boolean "yes" reads as true');
foreach (['USERNAME', 'TLS', 'TLS_VERIFY', 'TLS_CA_FILE'] as $k) putenv("RIVETIT_REDIS_$k");
$db->query("UPDATE settings SET config_redis_username='', config_redis_tls=0, config_redis_tls_verify=1, config_redis_tls_ca_file=NULL WHERE company_id=1");
$r = RedisSettings::resolve($db);
$ok($r['username'] === null && $r['tls'] === false && $r['tls_verify'] === true && $r['tls_ca_file'] === null, 'cleared columns resolve to no username, TLS off, verify on');

// ---- the server-wide file (/etc/rivetit/redis.env): read like environment variables, real environment wins over it
$envf = tempnam(sys_get_temp_dir(), 'redisenv');
file_put_contents($envf, "# comment\nRIVETIT_REDIS_HOST=file.example\nexport RIVETIT_REDIS_PORT=6401\nRIVETIT_REDIS_PASSWORD=\"fil e-pass\"\nRIVETIT_REDIS_USERNAME='fileuser'\nRIVETIT_REDIS_TLS=1\nRIVETIT_REDIS_TLS_CA_FILE=/file/ca.crt\nOTHER_KEY=ignored\nRIVETIT_REDIS_DB=\n");
putenv('RIVETIT_REDIS_ENV_FILE=' . $envf);
$r = RedisSettings::resolve($db);
$ok($r['host'] === 'file.example' && $r['port'] === 6401 && $r['password'] === 'fil e-pass' && $r['username'] === 'fileuser' && $r['tls'] === true && $r['tls_ca_file'] === '/file/ca.crt' && $r['from_env']['host'] && $r['from_env']['tls'] && !$r['from_env']['db'], 'file values are used and marked as set by the server; blank and foreign keys ignored');
putenv('RIVETIT_REDIS_PORT=6500');
$ok(RedisSettings::resolve($db)['port'] === 6500 && RedisSettings::resolve($db)['host'] === 'file.example', 'a real environment variable beats the file, field by field');
putenv('RIVETIT_REDIS_PORT');
putenv('RIVETIT_REDIS_ENV_FILE=/nonexistent/redis.env');
$ok(RedisSettings::resolve($db)['host'] !== 'file.example' && RedisSettings::fileValues() === [], 'a missing file is simply no values');
putenv('RIVETIT_REDIS_ENV_FILE'); @unlink($envf);

// ---- full validation (RedisConnectionConfig::validate)
$base = ['host' => '127.0.0.1', 'port' => 6379, 'db' => 0, 'password' => 'x'];
$ok(RedisSettings::validateParams($base) === null, 'validateParams: plain settings accepted');
$ok(RedisSettings::validateParams(['username' => 'u', 'password' => null] + $base) !== null, 'validateParams: a username needs a password');
$ok(RedisSettings::validateParams(['username' => 'bad user!'] + $base) !== null, 'validateParams: odd characters in the username refused');
$ok(RedisSettings::validateParams(['tls_ca_file' => '/x'] + $base) !== null, 'validateParams: a CA file without TLS refused');
$ok(RedisSettings::validateParams(['tls' => true, 'tls_ca_file' => '/nonexistent/ca'] + $base, true) !== null && RedisSettings::validateParams(['tls' => true, 'tls_ca_file' => '/nonexistent/ca'] + $base, false) === null, 'validateParams: missing files refused only when files are checked');
$ok(RedisSettings::validateParams(['tls' => true, 'tls_key_file' => __FILE__] + $base, true) !== null, 'validateParams: a client key needs a certificate');
$ok(RedisSettings::validateParams(['tls' => true, 'tls_ca_file' => 'https://evil/ca'] + $base) !== null, 'validateParams: a URL is not a file path');
$t = RedisSettings::test(['username' => 'u', 'password' => 'p', 'tls' => true, 'tls_ca_file' => '/nonexistent'] + $locked);
$ok(!$t['ok'] && $t['reason'] === 'invalid' && !str_contains(json_encode($t), 'p"'), 'test: reason "invalid" for an unreadable CA file');
$t = RedisSettings::test(['tls' => true, 'tls_verify' => false] + $open);
$ok(!$t['ok'] && in_array($t['reason'], ['tls', 'unreachable'], true), 'test: TLS against a plain server fails with a tls/unreachable reason');
$t = RedisSettings::test($bad = ['host' => '127.0.0.1', 'port' => 6399, 'password' => null, 'db' => 0]);
$ok($t['reason'] === 'unreachable', 'test: reason "unreachable" when nothing listens');
$t = RedisSettings::test(['password' => null] + $locked);
$ok($t['reason'] === 'auth', 'test: reason "auth" when a password is needed');

// ---- validate
$ok(RedisSettings::validate('127.0.0.1', 6379, 0, '') === null && RedisSettings::validate('redis.internal', 6379, 3, 'x') === null && RedisSettings::validate('::1', 6379, 0, '') === null, 'valid host, ipv4, ipv6 accepted');
$ok(RedisSettings::validate('bad host;rm', 6379, 0, '') !== null && RedisSettings::validate('', 6379, 0, '') !== null, 'bad hosts rejected');
$ok(RedisSettings::validate('h', 0, 0, '') !== null && RedisSettings::validate('h', 70000, 0, '') !== null && RedisSettings::validate('h', 1, 16, '') !== null && RedisSettings::validate('h', 1, 0, str_repeat('x', 501)) !== null, 'bad port / db / password length rejected');

// ---- connection test
$ok(RedisSettings::test($open)['ok'], 'test: open server connects');
$bad = RedisSettings::test(['host' => '127.0.0.1', 'port' => 6399, 'password' => null, 'db' => 0]);
$ok(!$bad['ok'] && str_contains($bad['message'], 'host and port'), 'test: nothing listening -> plain message');
$noPass = RedisSettings::test(['password' => null] + $locked);
$ok(!$noPass['ok'] && str_contains($noPass['message'], 'password'), 'test: server wants a password -> says so');
$ok(RedisSettings::test($locked)['ok'], 'test: correct password connects');
$ok(!RedisSettings::test(['password' => 'wrong'] + $locked)['ok'], 'test: wrong password refused');
$ok(!str_contains(json_encode([$bad, $noPass]), 's3cret'), 'messages never include the password');

// ---- stats, counts, clearing (only allowlisted groups)
$c = RedisSettings::client($open);
$c->flushdb();
foreach (['rivetit:rl:a', 'rivetit:rl:b', 'api_rl:x', 'mcp_metadata:1', 'rivetit:lock:cron:j', 'precious:data', 'user:session:1'] as $k) $c->set($k, '1');
$st = RedisSettings::stats($c);
$ok($st['keys'] === 7 && $st['version'] !== '?' && $st['maxmemory'] === 0 && is_int($st['clients']), 'stats: key count, version, no memory limit');
$counts = RedisSettings::groupCounts($c);
$ok($counts['rate_limits'] === 3 && $counts['mcp_cache'] === 1 && $counts['locks'] === 1, 'group counts match what was seeded');
$ok(RedisSettings::clear($c, 'rate_limits') === 3 && !$c->exists('rivetit:rl:a') && !$c->exists('api_rl:x'), 'clear rate limits removes only those keys');
$ok($c->exists('precious:data') && $c->exists('user:session:1') && $c->exists('mcp_metadata:1') && $c->exists('rivetit:lock:cron:j'), 'everything else is untouched');
$threw = false; try { RedisSettings::clear($c, '*'); } catch (InvalidArgumentException) { $threw = true; }
$ok($threw && (int) $c->dbsize() === 4, 'unknown group refused (no wildcard flush)');
for ($i = 0; $i < 450; $i++) $c->set("rivetit:rl:bulk$i", '1');
$ok(RedisSettings::clear($c, 'rate_limits') === 450 && (int) $c->dbsize() === 4, 'clears more than one batch of keys');

// ---- memory limit
$m = RedisSettings::setMemory($c, 128, 'allkeys-lru');
$ok($m['ok'] && !$m['persisted'] && str_contains($m['message'], 'redis.conf'), 'memory limit applied; says it is not persisted without a config file');
$st = RedisSettings::stats($c);
$ok($st['maxmemory'] === 128 * 1048576 && $st['policy'] === 'allkeys-lru', 'Redis really reports the new limit and policy');
$ok(!RedisSettings::setMemory($c, 8, 'allkeys-lru')['ok'] && !RedisSettings::setMemory($c, 128, 'evil')['ok'], 'too-small limit and unknown policy refused');
$c->config('SET', 'maxmemory', '0'); $c->config('SET', 'maxmemory-policy', 'noeviction');
$c->flushdb();

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
