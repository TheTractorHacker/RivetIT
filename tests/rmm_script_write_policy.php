<?php
/*
 * IT-10 (pentest 2026-10-08): a level-2 technician must not be able to write, overwrite or import script bodies that run as SYSTEM.
 * DB-free: unit checks on ScriptLibraryPolicy plus source checks that every handler writing rmm_scripts bodies uses it.
 *   php tests/rmm_script_write_policy.php
 */
require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Core\Adapter\Endpoint\ScriptLibraryPolicy;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$ok(ScriptLibraryPolicy::WRITE_LEVEL === 3, 'writing script bodies needs level 3 (same as free-form scripts)');
$ok(!ScriptLibraryPolicy::canWriteBodies(0) && !ScriptLibraryPolicy::canWriteBodies(1) && !ScriptLibraryPolicy::canWriteBodies(2), 'levels 0-2 cannot write bodies');
$ok(ScriptLibraryPolicy::canWriteBodies(3), 'level 3 can');
$ok(ScriptLibraryPolicy::bodyHash("Write-Host 'x'") === hash('sha256', "Write-Host 'x'") && ScriptLibraryPolicy::bodyHash('a') !== ScriptLibraryPolicy::bodyHash('b'), 'body hash is the SHA-256 of the body');

$root = dirname(__DIR__);
$src = fn (string $f) => file_get_contents("$root/$f");

// agent/rmm_scripts.php: the save branch must be gated by the policy level, not a literal 2, before any INSERT/UPDATE of a body.
$page = $src('agent/rmm_scripts.php');
$save = substr($page, strpos($page, "isset(\$_POST['save_script'])"), 900);
$ok(str_contains($save, 'ScriptLibraryPolicy::WRITE_LEVEL') && !preg_match("/enforceUserPermission\('module_rmm_scripts', 2\)/", $save), 'script save handler requires the policy level');
$ok(strpos($save, 'enforceUserPermission') < strpos($save, 'UPDATE rmm_scripts') || strpos($save, 'UPDATE rmm_scripts') === false, 'the permission check comes before any write');
$ok(substr_count($page, 'ScriptLibraryPolicy::bodyHash') >= 2, 'create and edit log the body hash');

// agent/post/rmm_sync.php: importing scripts writes bodies, so it needs the same level (it used to need any level >= 1).
$sync = $src('agent/post/rmm_sync.php');
$blk = substr($sync, strpos($sync, "\$action === 'sync_scripts'"), 500);
$ok(str_contains($blk, 'ScriptLibraryPolicy::canWriteBodies'), 'script import requires the policy level');

// Running saved scripts stays at level 2 (only authoring moved).
$runner = $src('agent/post/rmm_script_run.php');
$ok(str_contains($runner, "enforceUserPermission('module_rmm_scripts', 2)"), 'running a saved script is still level 2');

// No other handler may write rmm_scripts.script_body.
$writers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || preg_match('#/(vendor|tests|docs|node_modules)/#', $p) || str_contains($p, 'database_updates') || str_contains($p, '/setup/')) continue;
    if (preg_match('/(INSERT INTO|UPDATE)\s+rmm_scripts\s+SET[^;]*script_body/i', (string) file_get_contents($p))) $writers[] = substr($p, strlen($root) + 1);
}
sort($writers);
$ok($writers === ['agent/post/rmm_sync.php', 'agent/rmm_scripts.php'], 'only the two gated handlers write script bodies (found: ' . implode(', ', $writers) . ')');

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
