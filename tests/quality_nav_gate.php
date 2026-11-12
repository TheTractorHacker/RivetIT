<?php

declare(strict_types=1);

// Quality and its sign-on provider page must not appear in the admin nav or in settings search while the Quality module is off.
// Run: php tests/quality_nav_gate.php

$fails = 0;
$check = static function (string $name, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
};

$nav = (string) file_get_contents(__DIR__ . '/../admin/includes/side_nav.php');
$posGuard = strpos($nav, 'QualityConfig::moduleOn($mysqli)');
$posSso = strpos($nav, '/admin/sso_provider.php');
$posQ = strpos($nav, '/admin/quality.php');
$posEnd = $posGuard === false ? false : strpos($nav, '<?php } ?>', $posGuard);
$check('side nav: both entries sit inside one moduleOn() guard', $posGuard !== false && $posEnd !== false
    && $posGuard < $posSso && $posSso < $posEnd && $posGuard < $posQ && $posQ < $posEnd);

// Settings search: with the module off (no database here, so moduleOn cannot be true) neither page is findable.
unset($GLOBALS['mysqli']);
require_once __DIR__ . '/../includes/settings_search_index.php';
$byLabel = [];
foreach (getSettingsSearchIndex() as $e) {
    $byLabel[$e['label']] = $e['visible'] ?? true;
}
$check('search: "Quality" hidden when the module is off', ($byLabel['Quality'] ?? null) === false);
$check('search: "Single sign-on provider" hidden when the module is off', ($byLabel['Single sign-on provider'] ?? null) === false);
$check('search: unrelated entries stay visible', ($byLabel['API Keys'] ?? null) === true);

echo $fails === 0 ? "all passed\n" : "$fails failed\n";
exit($fails === 0 ? 0 : 1);
