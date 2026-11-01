<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * The Security and trust page (src/Security/TrustPage.php, /trust.php, admin/security_trust.php) and the edition baseline check
 * (scripts/edition-baseline-check.php). No database. php tests/trust_page.php
 */
$root = dirname(__DIR__);
require_once "$root/vendor/autoload.php";
define('APP_NAME', 'RivetIT');
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };

$page = new \ITFlow\Security\TrustPage($root);
$html = $page->html('26.10.test', false);
$admin = $page->html('26.10.test', true);

$claims = $page->approvedClaims();
$ok(count($claims) >= 6, 'the approved claims are read from CLAIMS.md (' . count($claims) . ')');
foreach ($claims as $c) {
    $ok(str_contains($html, htmlspecialchars($c, ENT_QUOTES, 'UTF-8')), 'claim shown verbatim: ' . substr($c, 0, 50));
}
foreach (\ITFlow\Security\TrustPage::FORBIDDEN as $bad) {
    $ok(stripos($html, $bad) === false && stripos($admin, $bad) === false, "the page never says \"$bad\"");
}
$ok(stripos($html, 'Independent security review: none yet') !== false, 'states that there is no independent review');
$ok(stripos($html, 'Known gaps') !== false && stripos($html, 'security/advisories/new') !== false, 'lists known gaps and the disclosure contact');

// counts are generated from the file, not typed
$json = json_decode(file_get_contents("$root/docs/security/EDITION_BASELINE.json"), true);
$n = array_count_values(array_column($json['controls'], 'status'));
$ok(str_contains($html, '<strong>' . ($n['enforced'] ?? 0) . '</strong> enforced') && str_contains($html, '<strong>' . ($n['partial'] ?? 0) . '</strong> partial') && str_contains($html, '<strong>' . ($n['gap'] ?? 0) . '</strong> gap'), 'status counts equal the counts in EDITION_BASELINE.json');
$ok(!str_contains($html, '<img') && !str_contains($html, 'badge'), 'no badge, seal or logo');
$ok(str_contains($admin, 'Per-control detail') && !str_contains($html, 'Per-control detail'), 'per-control detail only in the administrator copy');
$ok(preg_match('~rivet-core/blob/v1\.0\.0-rc\.\d+/docs/security/BASELINE\.md~', $html) === 1, 'links the control list at the pinned Core tag');

// markup is escaped
$tmp = sys_get_temp_dir() . '/trust_' . bin2hex(random_bytes(3));
mkdir("$tmp/docs/security", 0777, true);
$j = $json;
$j['controls'][0]['note'] = '<script>alert(1)</script>';
$j['controls'][0]['status'] = 'gap';
$j['controls'][0]['tests'] = [];
file_put_contents("$tmp/docs/security/EDITION_BASELINE.json", json_encode($j));
$evil = (new \ITFlow\Security\TrustPage($tmp, "$root/vendor/rivet/rivet-core"))->html('x', true);
$ok(!str_contains($evil, '<script>alert(1)</script>') && str_contains($evil, '&lt;script&gt;'), 'a note is escaped');
shell_exec('rm -rf ' . escapeshellarg($tmp));

// the check script
exec('php ' . escapeshellarg("$root/scripts/edition-baseline-check.php") . ' 2>&1', $o, $rc);
$ok($rc === 0, 'edition-baseline-check passes on the committed file');
$tmp = sys_get_temp_dir() . '/trustchk_' . bin2hex(random_bytes(3));
mkdir("$tmp/docs/security", 0777, true); mkdir("$tmp/scripts"); mkdir("$tmp/tests");
copy("$root/scripts/edition-baseline-check.php", "$tmp/scripts/edition-baseline-check.php");
// the check reads the pinned Core baseline from vendor/rivet/rivet-core under its own root: link the package into the scratch tree
@mkdir("$tmp/vendor/rivet", 0777, true);
symlink("$root/vendor/rivet/rivet-core", "$tmp/vendor/rivet/rivet-core");
$mut2 = function (callable $f) use ($tmp, $json) {
    $j = $json; $f($j);
    file_put_contents("$tmp/docs/security/EDITION_BASELINE.json", json_encode($j));
    exec('php ' . escapeshellarg("$tmp/scripts/edition-baseline-check.php") . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
foreach ($json['controls'] as $c) { foreach ($c['files'] as $f) { @mkdir(dirname("$tmp/$f"), 0777, true); @touch("$tmp/$f"); } foreach ($c['tests'] as $t) { @mkdir(dirname("$tmp/$t"), 0777, true); @touch("$tmp/$t"); } }
[$rc] = $mut2(fn(&$j) => null);
$ok($rc === 0, 'check: the unmodified file passes in a scratch tree');
[$rc, $out] = $mut2(function (&$j) { $j['controls'][] = ['core_control' => 'NOPE-99', 'status' => 'partial', 'how' => '', 'files' => [], 'tests' => [], 'note' => '']; });
$ok($rc === 1 && str_contains($out, 'NOPE-99'), 'check: a control that is not in the pinned Core baseline fails');
[$rc, $out] = $mut2(function (&$j) { array_shift($j['controls']); });
$ok($rc === 1 && str_contains($out, 'edition-responsibility'), 'check: dropping an edition-responsibility control fails');
[$rc, $out] = $mut2(function (&$j) { $j['controls'][0]['files'][] = 'does/not/exist.php'; });
$ok($rc === 1 && str_contains($out, 'does/not/exist.php'), 'check: a missing file fails');
[$rc, $out] = $mut2(function (&$j) { $j['controls'][0]['status'] = 'enforced'; $j['controls'][0]['tests'] = []; });
$ok($rc === 1 && str_contains($out, "'enforced' needs"), "check: 'enforced' without a test fails");
[$rc, $out] = $mut2(function (&$j) { $j['controls'][0]['status'] = 'gap'; $j['controls'][0]['tests'] = ['tests/security_passwords.php']; });
$ok($rc === 1 && str_contains($out, "'gap' must not"), "check: a 'gap' that claims a test fails");
shell_exec('rm -rf ' . escapeshellarg($tmp));

echo "\n$passes passed, $fails failed\n";
exit($fails > 0 ? 1 : 0);
