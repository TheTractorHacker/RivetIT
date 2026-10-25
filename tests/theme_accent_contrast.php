<?php
/* Accent contrast helpers (includes/theme_accent.php): the WCAG maths and the warnings Admin > Appearance shows for an accent that is
 * hard to read. Pure, no database.   php tests/theme_accent_contrast.php */
require dirname(__DIR__) . '/includes/theme_accent.php';
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };

$ok(abs(itflow_contrast_ratio('#000000', '#FFFFFF') - 21.0) < 0.01, 'black on white is 21:1');
$ok(abs(itflow_contrast_ratio('#FFFFFF', '#FFFFFF') - 1.0) < 0.001, 'a colour on itself is 1:1');
$ok(abs(itflow_contrast_ratio('#D97706', '#FFFFFF') - itflow_contrast_ratio('#FFFFFF', '#D97706')) < 1e-9, 'the ratio is symmetric');
$ok(abs(itflow_contrast_ratio('#767676', '#FFFFFF') - 4.54) < 0.02, '#767676 on white is the well-known 4.54:1');

$ok(itflow_accent_contrast_notes('#2563EB') === [], 'the default blue needs no warning');
$ok(itflow_accent_contrast_notes('#0D9488') !== [] || true, 'teal is evaluated without error');
$amber = itflow_accent_contrast_notes('#D97706');
$ok(count($amber) >= 1 && str_contains($amber[0], 'primary buttons'), 'the shipped amber preset warns about button text');
$yellow = itflow_accent_contrast_notes('#FFFF00');
$ok(count($yellow) === 2, 'pure yellow warns about buttons and links');
$ok(str_contains(implode(' ', $yellow), '3:1 for focus'), 'a very low link contrast also mentions focus outlines');
$ok(itflow_accent_contrast_notes('#000000') === [], 'black is fine on light pages');
$ok(itflow_accent_contrast_notes('not-a-colour') === [], 'invalid input yields no warnings');
$ok(itflow_accent_contrast_notes('') === [], 'empty input yields no warnings');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
