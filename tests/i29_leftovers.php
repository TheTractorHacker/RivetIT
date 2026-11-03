<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Issue #29 leftovers (docs/user-guide/tools/app-findings.md), logic that does not need a browser:
 *   - rivetRmmRemovePolicyChecks(): deleting an RMM check policy takes the pushed checks off the agents; a check the RMM cannot remove is KEPT
 *     (status remove_failed) instead of silently orphaned, and nothing is deleted from a device whose removal failed;
 *   - source guards for the smaller changes (vendors permission model, users CSV, restore passphrase, software seat links, department move).
 * The DB part needs a SCRATCH database seeded by tests/browser/i29_leftovers_seed.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/i29_leftovers.php
 */
$root = dirname(__DIR__);
$fail = 0;
$ok = function ($c, $m) use (&$fail) { echo ($c ? 'PASS  ' : 'FAIL  ') . $m . "\n"; if (!$c) { $fail++; } };
$src = fn ($p) => (string) file_get_contents("$root/$p");

// ---- source guards ----
$fn = $src('functions.php');
$ok(str_contains($fn, 'function enforceVendorPermission') && str_contains($fn, 'function vendorCanSeeAccountNumber'), 'vendor permission helpers exist');
$v = $src('agent/post/vendor.php');
$ok(substr_count($v, 'enforceVendorPermission($client_id') >= 8 && substr_count($v, "enforceUserPermission('module_financial'") === 1, 'vendor handlers use the shared permission rule (only the CSV export still needs Finance)');
$ok(preg_match('/enforceVendorPermission\(\$client_id, 3\)/', $v) === 1 && !preg_match('/delete_vendor.*?enforceVendorPermission\(\$client_id, 2\)/s', substr($v, strpos($v, "delete_vendor'"), 600)), 'vendor delete stays at level 3 (Finance)');
$ok(str_contains($v, 'vendorCanSeeAccountNumber($client_id)'), 'account number is Finance-only for company-wide vendors');
$u = $src('admin/post/users.php');
$ok(str_contains($u, '$include_archived') && str_contains($u, 'user_type = 1 $archived_filter'), 'users CSV: agents only, archived only when asked');
$ok(str_contains($src('deploy/restore.sh'), 'read_manifest_dir "${EXTRACT_DIR}" "${PASSPHRASE_FILE}"'), 'restore.sh decrypts an encrypted inner manifest with --passphrase-file');
$sw = $src('agent/post/software.php');
$ok(substr_count($sw, 'INSERT IGNORE INTO software_assets') === 2 && substr_count($sw, 'INSERT IGNORE INTO software_contacts') === 2, 'licence seat links are limited to the licence department');
$c = $src('agent/post/contact.php');
$ok(str_contains($c, "move_contact_department") && str_contains($c, 'LifecycleEvents::afterChange($mysqli, $contact_id, $lifecycle_before)') && str_contains($c, '"Contact", "Move"'), 'Move department uses the lifecycle events and is audited');
$ok(preg_match("/action === 'delete_policy'.*?rivetRmmRemovePolicyChecks/s", $src('agent/post/rmm_check.php')) === 1, 'delete_policy removes the pushed checks first');

// ---- the RMM cleanup against a scratch DB ----
if (getenv('RIVETIT_TEST_DB') !== '1') { echo "SKIP  database part (RIVETIT_TEST_DB not set)\n"; exit($fail ? 1 : 0); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$q = fn (string $s) => $db->query($s);
$one = fn (string $s) => ($r = $db->query($s)) ? ($r->fetch_row()[0] ?? null) : null;
require_once "$root/includes/rmm_policy_cleanup.php";

$seed = sys_get_temp_dir() . '/i29-seed-' . getmypid() . '.json';
passthru('php ' . escapeshellarg("$root/tests/browser/i29_leftovers_seed.php") . ' ' . escapeshellarg($seed), $rc);
$ok($rc === 0, 'seed ran'); @unlink($seed);

/** A stand-in for the RMM client: records the check ids it was asked to delete and answers per id. */
$stub = new class { public array $asked = []; public array $answers = []; public function deleteCheck(int $id): bool { $this->asked[] = $id; $a = $this->answers[$id] ?? true; if ($a instanceof Throwable) { throw $a; } return (bool) $a; } };
$factoryCalls = 0;
$factory = function (int $integration) use ($stub, &$factoryCalls) { $factoryCalls++; return $stub; };

// 1. every removal confirmed: deployments gone, both checks asked for, one client built for the integration
$res = rivetRmmRemovePolicyChecks($db, 94, $factory);
$ok($res['removed'] === 2 && $res['failed'] === [], 'all pushed checks removed from the RMM');
$ok($stub->asked === [501, 502], 'the RMM was asked to delete exactly the pushed checks');
$ok($factoryCalls === 1, 'one RMM client for the integration');
$ok((int) $one("SELECT COUNT(*) FROM rmm_check_deployments WHERE policy_id = 94") === 0, 'deployment rows removed');

// 2. one fails (returns false), one throws: both kept, marked remove_failed, nothing hidden
$q("INSERT INTO rmm_check_deployments SET policy_id = 94, link_id = 92, tactical_check_id = '501', status = 'active'");
$q("INSERT INTO rmm_check_deployments SET policy_id = 94, link_id = 93, tactical_check_id = '502', status = 'active'");
$stub->asked = []; $stub->answers = [501 => false, 502 => new RuntimeException('RMM unreachable')];
$res = rivetRmmRemovePolicyChecks($db, 94, $factory);
$ok($res['removed'] === 0 && count($res['failed']) === 2, 'both failures reported');
$ok($res['failed'][1]['error'] === 'RMM unreachable' && $res['failed'][0]['hostname'] === 'alpha-laptop-1', 'failure names the device and the reason');
$ok((int) $one("SELECT COUNT(*) FROM rmm_check_deployments WHERE policy_id = 94 AND status = 'remove_failed'") === 2, 'failed deployments are kept and marked remove_failed');

// 3. retry: one now succeeds, the other still fails
$stub->answers = [501 => true, 502 => false];
$res = rivetRmmRemovePolicyChecks($db, 94, $factory);
$ok($res['removed'] === 1 && count($res['failed']) === 1 && $res['failed'][0]['check_id'] === '502', 'a retry removes what the RMM now accepts');
$ok((int) $one("SELECT COUNT(*) FROM rmm_check_deployments WHERE policy_id = 94") === 1, 'only the still-failing deployment remains');

// 4. a deployment with no remote check id, and one whose device link is gone
$q("DELETE FROM rmm_check_deployments WHERE policy_id = 94");
$q("INSERT INTO rmm_check_deployments SET policy_id = 94, link_id = 92, tactical_check_id = NULL, status = 'active'");
$q("INSERT INTO rmm_check_deployments SET policy_id = 94, link_id = 9999, tactical_check_id = '777', status = 'active'");
$stub->asked = []; $stub->answers = [];
$res = rivetRmmRemovePolicyChecks($db, 94, $factory);
$ok($res['removed'] === 1 && count($res['failed']) === 1 && $res['failed'][0]['check_id'] === '777', 'no check id: nothing to remove; link gone: reported, not silently dropped');
$ok($stub->asked === [], 'the RMM was not asked about either');

// 5. another policy is untouched
$q("INSERT INTO rmm_check_policies SET id = 95, name = 'Scratch other', platform = 'windows', check_type = 'cpuload', enabled = 1");
$q("INSERT INTO rmm_check_deployments SET policy_id = 95, link_id = 93, tactical_check_id = '900', status = 'active'");
rivetRmmRemovePolicyChecks($db, 94, $factory);
$ok((int) $one("SELECT COUNT(*) FROM rmm_check_deployments WHERE policy_id = 95") === 1, 'only the named policy is touched');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
