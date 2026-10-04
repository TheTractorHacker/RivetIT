<?php
/* Cron Manager runner. Uses harmless temporary scripts only; never runs a real job. php tests/cron_runner.php */
require_once __DIR__ . '/../vendor/autoload.php';
use ITFlow\Cron\{JobCatalog, JobRunner};

$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };
$root = sys_get_temp_dir() . '/rivetit-runner-test-' . bin2hex(random_bytes(4));
mkdir("$root/cron", 0700, true); mkdir("$root/scripts", 0700); mkdir("$root/state", 0700);
file_put_contents("$root/scripts/tool.php", '<?php echo "from scripts dir\\n";');
file_put_contents("$root/cron/slow.php", '<?php echo "hello \\x07bell\\n"; echo "args:" . implode(",", array_slice($argv, 1)) . "\\n"; sleep(2); echo "done\\n"; exit(3);');
file_put_contents("$root/cron/quick.php", '<?php echo "ok\\n";');
$outside = sys_get_temp_dir() . '/rivetit-outside-' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($outside, '<?php echo "should never run";');
$r = new JobRunner($root, "$root/state");
$php = PHP_BINARY;

// ---- parsing
$cmd = '/usr/bin/php /v/cron/training_worker.php --task=odoo >> /var/log/itflow_mw_training_worker.log 2>&1';
$ok(JobRunner::logPathFromCommand($cmd) === '/var/log/itflow_mw_training_worker.log', 'log path read from the cron line');
$ok(JobRunner::logPathFromCommand('/usr/bin/php /v/cron/x.php >> /etc/passwd 2>&1') === null, 'log path outside /var/log ignored');
$ok(JobRunner::logPathFromCommand('/usr/bin/php /v/cron/x.php') === null, 'no redirect -> no log path');
$ok(JobRunner::argumentsFromCommand($cmd, '/v/cron/training_worker.php') === ['--task=odoo'], 'arguments extracted from the cron line');
$ok(JobRunner::argumentsFromCommand('/usr/bin/php /v/cron/a.php >> /var/log/a.log 2>&1', '/v/cron/a.php') === [], 'no arguments -> empty list');
$ok(JobRunner::argumentsFromCommand('/usr/bin/php /v/cron/a.php --task=odoo; rm -rf / >> /var/log/a.log', '/v/cron/a.php') === null, 'shell metacharacters in arguments rejected');
$ok(JobRunner::argumentsFromCommand('/usr/bin/php /v/cron/a.php $(id) >> /var/log/a.log', '/v/cron/a.php') === null, 'command substitution rejected');
$ok(JobRunner::phpBinaryFromCommand('/usr/bin/php8.4 /v/cron/a.php') === '/usr/bin/php8.4', 'php binary taken from the line');

// ---- tail hygiene
file_put_contents("$root/t.log", "a\n\x1b[31mred\x1b[0m\n\n" . str_repeat('x', 400) . "\nlast\n");
$t = JobRunner::tail("$root/t.log", 3);
$ok(count($t['lines']) === 3 && $t['lines'][2] === 'last' && mb_strlen($t['lines'][1]) === 300, 'tail: last lines, long line clipped');
$ok(!str_contains(implode('', JobRunner::tail("$root/t.log", 9)['lines']), "\x1b"), 'tail: escape characters removed');
$ok(JobRunner::tail("$root/missing.log")['lines'] === [] && JobRunner::tail(null)['mtime'] === null, 'tail: missing file is empty, not an error');

// ---- refusals
$ok(!$r->start('x1', $outside, [], $php)['ok'], 'script outside the app cron directory refused');
$ok(!$r->start('x2', "$root/cron/quick.php", [], '/bin/sh')['ok'], 'unexpected interpreter refused');
$ok(!$r->start('x3', "$root/cron/quick.php", ['--task=a;rm'], $php)['ok'], 'unsafe argument refused');
$ok(!$r->start('x4', "$root/cron/../cron/../../etc/passwd", [], $php)['ok'], 'path traversal refused');
$ok(!file_exists("$root/state/x1.pid") && !file_exists("$root/state/x2.pid"), 'refused starts leave no state behind');

// ---- scripts/ directory is allowed, other directories are not
$sx = $r->start('tool', "$root/scripts/tool.php", [], $php);
$ok($sx['ok'], 'a script in scripts/ can be started');
$ok(!$r->start('x5', "$root/state/x.php", [], $php)['ok'] && !$r->start('x6', __FILE__, [], $php)['ok'], 'scripts outside cron/ and scripts/ are still refused');
usleep(700000);

// ---- real background run
$s = $r->start('slow', "$root/cron/slow.php", ['--task=odoo', '--force'], $php);
$ok($s['ok'], 'start returns ok');
$st = $r->state('slow', realpath("$root/cron/slow.php"));
$ok($st['running'] && $st['pid'] > 0, 'reports running while it works');
$ok(!$r->start('slow', "$root/cron/slow.php", [], $php)['ok'], 'second start refused while running');
$deadline = microtime(true) + 8;
do { usleep(200000); $st = $r->state('slow', realpath("$root/cron/slow.php")); } while ($st['running'] && microtime(true) < $deadline);
$ok(!$st['running'] && $st['exit'] === 3 && $st['finished_at'] !== null, 'finishes with exit code 3 and a finish time');
$out = implode("\n", $st['lines']);
$ok(str_contains($out, 'hello') && str_contains($out, 'args:--task=odoo,--force') && str_contains($out, 'done'), 'captures output and passes arguments');
$ok(!str_contains($out, "\x07"), 'control characters stripped from captured output');
$ok($r->start('slow', "$root/cron/slow.php", [], $php)['ok'], 'can start again once finished');
sleep(3);

// ---- catalog
$all = JobCatalog::all();
$ok($all['mail_queue.php']['run_now'] === false && $all['cron.php']['run_now'] === false && $all['ticket_email_parser.php']['run_now'] === false, 'mail-sending and mailbox jobs are never startable');
$ok($all['backup_cron.php']['run_now'] === true && JobCatalog::needsArguments('training_worker.php'), 'safe jobs startable; training worker needs its schedule arguments');
$ok(JobCatalog::describe('mystery.php')['run_now'] === false, 'unknown script is not startable');
$cronFiles = array_map('basename', glob(__DIR__ . '/../cron/*.php'));
$missing = array_diff($cronFiles, array_keys($all));
$ok(JobCatalog::dir('unifi_sync_cli.php') === 'scripts' && JobCatalog::dir('backup_cron.php') === 'cron' && is_file(__DIR__ . '/../scripts/unifi_sync_cli.php'), 'catalog knows UniFi lives in scripts/');
$ok(!$missing, 'every script in cron/ is in the catalog' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

// cleanup
foreach (glob("$root/state/*") as $f) unlink($f);
foreach (glob("$root/cron/*") as $f) unlink($f);
foreach (glob("$root/scripts/*") as $f) unlink($f); rmdir("$root/scripts");
@unlink("$root/t.log"); rmdir("$root/state"); rmdir("$root/cron"); rmdir($root); unlink($outside);
echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
