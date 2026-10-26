<?php
/* Accent contrast (includes/theme_accent.php): the WCAG maths, the readable (AA) versions of a company accent used for text and buttons,
 * the CSS that applies them, and the notes Admin > Appearance shows. Pure, no database.   php tests/theme_accent_contrast.php */
require dirname(__DIR__) . '/includes/theme_accent.php';
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };

$ok(abs(itflow_contrast_ratio('#000000', '#FFFFFF') - 21.0) < 0.01, 'black on white is 21:1');
$ok(abs(itflow_contrast_ratio('#FFFFFF', '#FFFFFF') - 1.0) < 0.001, 'a colour on itself is 1:1');
$ok(abs(itflow_contrast_ratio('#D97706', '#FFFFFF') - itflow_contrast_ratio('#FFFFFF', '#D97706')) < 1e-9, 'the ratio is symmetric');
$ok(abs(itflow_contrast_ratio('#767676', '#FFFFFF') - 4.54) < 0.02, '#767676 on white is the well-known 4.54:1');
$ok(itflow_mix_hex('#000000', '#FFFFFF', 0.5) === '#808080' && itflow_mix_hex('#336699', '#336699', 0.3) === '#336699', 'color-mix style blending');

// the default blue: text, links and buttons are already AA in both themes, so only the dark focus outline (2.6:1 on dark cards) is lifted
$blue = itflow_accent_aa('#2563EB');
$ok(!$blue['btn_changed'] && !$blue['link_changed'] && !$blue['link_dark_changed'] && !$blue['focus_changed'], 'the default blue keeps its buttons and links as they are');
$ok($blue['focus_dark_changed'] && itflow_accent_contrast_notes('#2563EB') === [], 'the default blue only gets a lighter dark-theme focus outline, with no note to the admin');
$ok(!str_contains(itflow_accent_aa_css('#2563EB'), '.btn-primary') && !str_contains(itflow_accent_aa_css('#2563EB'), '--color-link'), 'and no button or link override is emitted for it');

// every accent we ship or might be given ends up readable in the jobs where it is ink or a fill
$lightBg = '#EEF2F2'; $darkBg = '#1F2937';
foreach (['#0D9488', '#D97706', '#65A30D', '#FFFF00', '#000000', '#1E3A8A', '#FFFFFF', '#FF00FF', '#7C3AED', '#16A34A', '#DB2777', '#475569'] as $h) {
    $a = itflow_accent_aa($h);
    $ok(itflow_contrast_ratio($a['btn'], $a['btn_text']) >= 4.5, "$h: button label is AA ({$a['btn_text']} on {$a['btn']})");
    $ok(itflow_contrast_ratio($a['btn_hover'], $a['btn_text']) >= 4.5, "$h: hovered button label is AA");
    $ok(itflow_contrast_ratio($a['link'], $lightBg) >= 4.5, "$h: link on the light page is AA");
    $ok(itflow_contrast_ratio($a['link_dark'], $darkBg) >= 4.5, "$h: link on the dark page is AA");
    $ok(itflow_contrast_ratio($a['focus'], $lightBg) >= 3.0 && itflow_contrast_ratio($a['focus_dark'], $darkBg) >= 3.0, "$h: focus outline is 3:1 in both themes");
}

$css = itflow_accent_aa_css('#FFFF00');
$ok(str_contains($css, '.btn-primary,html body.portal-ui .btn-primary{background-color:#D6D600') && str_contains($css, 'color:#111827'), 'pure yellow gets dark button text');
$ok(str_contains($css, '--color-link:#727200'), 'pure yellow gets a darker link colour on light pages');
$ok(str_contains(itflow_accent_aa_css('#000000'), 'data-bs-theme="dark"') && preg_match('/--color-link:#(?!000000)[0-9A-F]{6}/', itflow_accent_aa_css('#000000')) === 1, 'black gets a lighter link colour on the dark theme');
$ok(itflow_accent_aa_css('not-a-colour') === '' && itflow_accent_aa_css('') === '', 'invalid input emits nothing');

$amber = itflow_accent_contrast_notes('#D97706');
$ok(count($amber) >= 1 && str_contains(implode(' ', $amber), 'Buttons use'), 'the amber preset explains what happens to its buttons');
$ok(count(itflow_accent_contrast_notes('#FFFF00')) >= 2, 'pure yellow explains buttons and links');
$ok(itflow_accent_contrast_notes('not-a-colour') === [] && itflow_accent_contrast_notes('') === [], 'invalid input yields no notes');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
