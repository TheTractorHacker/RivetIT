<?php

/*
 * Kiosk page shell, top half (P3 spec §5.1). Expects the bootstrap's $kctx / $kiosk_csp_nonce and
 * the page's $k_page:
 *   title (string), css (list of /css/… paths), js (list of /js/… paths), lang_toggle (bool, default true),
 *   data (array, goes to k-page-data.page), body_class (string), csrf (override: the video page's
 *   restricted token), video ({run_id, lesson_uid} for the X-Kiosk-Video header).
 * Every value reaching the DOM is escaped here; page scripts render with textContent.
 */

defined('KIOSK_BOOTSTRAP') || exit;

$k_page = is_array($k_page ?? null) ? $k_page : [];
$k_h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
if (!function_exists('kiosk_asset')) {
    /** "/css/x.css" -> "/css/x.css?v=<mtime>", or null when the file is not in this build. */
    function kiosk_asset(string $path): ?string
    {
        if (preg_match('#^/(css|js|plugins)/[A-Za-z0-9_./-]+\.(css|js)$#D', $path) !== 1 || str_contains($path, '..')) {
            return null;
        }
        $file = dirname(__DIR__, 2) . $path;
        return is_file($file) ? $path . '?v=' . filemtime($file) : null;
    }
}

$k_lang = $kctx->lang;
$k_session = $kctx->ksess;
$k_css = array_merge(
    ['/plugins/fontawesome-free/css/all.min.css', '/css/itflow_design.css', '/css/itflow_training_player.css',
     '/css/itflow_training_article.css', '/css/itflow_training_kiosk.css'],
    array_values(array_filter((array) ($k_page['css'] ?? []), 'is_string'))
);

// Company accent (same rule as includes/header.php) - the kiosk never hard-codes one.
$k_accent_presets = ['teal' => '#0D9488', 'blue' => '#2563EB', 'indigo' => '#4F46E5', 'purple' => '#7C3AED', 'green' => '#16A34A',
    'red' => '#DC2626', 'orange' => '#EA580C', 'pink' => '#DB2777', 'cyan' => '#0891B2', 'yellow' => '#D97706', 'lime' => '#65A30D',
    'fuchsia' => '#C026D3', 'navy' => '#1E3A8A', 'maroon' => '#9F1239', 'gray' => '#475569'];
$k_accent = '';
if (!empty($config_theme_accent_custom) && preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) $config_theme_accent_custom) === 1) {
    $k_accent = strtoupper((string) $config_theme_accent_custom);
} elseif (isset($k_accent_presets[$config_theme ?? ''])) {
    $k_accent = $k_accent_presets[$config_theme];
}
$k_dark = intval($config_theme_dark_default ?? 0) === 1;
$k_brand = $kctx->ks->brandWord();
// The company logo (Settings > Company), shown left of the brand as in the mockups; a plain file name only.
$k_logo = null;
try {
    $k_logo_row = \ITFlow\Training\Core\Db::one($kctx->db(), 'SELECT company_logo FROM companies WHERE company_id = 1');
    $k_logo_file = (string) ($k_logo_row['company_logo'] ?? '');
    if ($k_logo_file !== '' && preg_match('/^[A-Za-z0-9._-]{1,200}$/D', $k_logo_file) === 1 && !str_contains($k_logo_file, '..')
        && preg_match('/\.(png|jpe?g|gif|webp|svg)$/iD', $k_logo_file) === 1 && is_file(dirname(__DIR__, 2) . '/uploads/settings/' . $k_logo_file)) {
        $k_logo = '/uploads/settings/' . rawurlencode($k_logo_file);
    }
} catch (\Throwable $e) {
    $k_logo = null;
}
// set_language needs an enrolled device (pre-auth) or a session; the not-set-up screen is bilingual instead.
$k_toggle = ($k_page['lang_toggle'] ?? true) !== false && $kctx->device !== null;
$k_t = static fn(string $key, array $vars = []): string => \ITFlow\Training\Kiosk\Core\KioskStrings::t($k_lang, $key, $vars);
?>
<!doctype html>
<html lang="<?= $k_h($k_lang) ?>" data-bs-theme="<?= $k_dark ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= $k_h($k_t('shell.brand_suffix')) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<meta name="robots" content="noindex, nofollow">
<?php if ($k_accent !== '') { ?><meta name="theme-color" content="<?= $k_h($k_accent) ?>">
<?php } ?>
<title><?= $k_h(trim(($k_page['title'] ?? '') . ' · ' . $k_brand . ' ' . $k_t('shell.brand_suffix'), ' ·')) ?></title>
<link rel="manifest" href="/kiosk/manifest.json">
<link rel="apple-touch-icon" sizes="180x180" href="/kiosk/icons/apple-touch-icon.png">
<link rel="apple-touch-icon-precomposed" sizes="180x180" href="/kiosk/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="/kiosk/icons/icon-192.png">
<?php foreach ($k_css as $k_href) { $k_url = kiosk_asset($k_href); if ($k_url !== null) { ?>
<link rel="stylesheet" href="<?= $k_h($k_url) ?>">
<?php } } ?>
<?php if ($k_accent !== '') {
    $k_r = hexdec(substr($k_accent, 1, 2)); $k_g = hexdec(substr($k_accent, 3, 2)); $k_b = hexdec(substr($k_accent, 5, 2));
    $k_hover = sprintf('#%02X%02X%02X', (int) round($k_r * 0.82), (int) round($k_g * 0.82), (int) round($k_b * 0.82)); ?>
<style nonce="<?= $k_h($kiosk_csp_nonce) ?>">
:root { --color-accent: <?= $k_accent ?>; --color-accent-rgb: <?= "$k_r, $k_g, $k_b" ?>; --color-accent-hover: <?= $k_hover ?>; --color-accent-soft: rgba(<?= "$k_r, $k_g, $k_b" ?>, .12); }
:root[data-bs-theme="dark"] { --color-accent-soft: rgba(<?= "$k_r, $k_g, $k_b" ?>, .22); }
</style>
<?php } ?>
</head>
<body class="kx<?= isset($k_page['body_class']) && preg_match('/^[a-z0-9 _-]{1,80}$/D', (string) $k_page['body_class']) === 1 ? ' ' . $k_h($k_page['body_class']) : '' ?>">
<header class="kx-top">
  <div class="kx-brand"><?php if ($k_logo !== null) { ?><img class="kx-brand__logo" src="<?= $k_h($k_logo) ?>" alt=""><?php } ?><span class="kx-brand__word"><?= $k_h($k_brand) ?></span> <span class="kx-brand__accent"><?= $k_h($k_t('shell.brand_suffix')) ?></span></div>
  <div class="kx-top__right">
<?php if ($k_toggle) { ?>
    <div class="kx-seg" role="group" aria-label="<?= $k_h($k_t('shell.lang_group')) ?>">
      <button type="button" class="kx-seg__btn" data-kx-lang="en" lang="en" aria-label="English" aria-pressed="<?= $k_lang === 'en' ? 'true' : 'false' ?>"><span>EN</span></button>
      <button type="button" class="kx-seg__btn" data-kx-lang="es" lang="es" aria-label="Español" aria-pressed="<?= $k_lang === 'es' ? 'true' : 'false' ?>"><span>ES</span></button>
    </div>
<?php } ?>
<?php
// Check-in and hand-off hand the device to other people: the header shows no one's name (the trainer's
// would sit over "Hi Marisol") and no Done (leaving those modes needs the trainer PIN).
if ($k_session !== null && !in_array((string) ($k_session['ksess_role'] ?? ''), ['checkin', 'handoff'], true)) { ?>
    <span class="kx-top__sep" aria-hidden="true"></span>
    <div class="kx-who">
      <span class="kx-avatar" aria-hidden="true"><?= $k_h($k_session['initials'] ?? '') ?></span>
      <span class="kx-who__name"><?= $k_h($k_session['contact_name'] ?? '') ?><?php if (($k_session['dept'] ?? '') !== '') { ?> <span class="kx-who__dept">· <?= $k_h($k_session['dept']) ?></span><?php } ?></span>
    </div>
    <button type="button" class="kx-btn kx-done" aria-label="<?= $k_h($k_t('shell.done_hint')) ?>"><i class="fas fa-sign-out-alt" aria-hidden="true"></i><span><?= $k_h($k_t('shell.done')) ?></span></button>
<?php } ?>
  </div>
</header>
<main class="kx-main" id="kx-main">
