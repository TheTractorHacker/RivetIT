<?php

/*
 * /kiosk/ - Home (P3 spec §5.2). THIS IS THE K1 PLATFORM STUB: lane K2 replaces this file with
 * the real Home / sign-in / PIN page (Kiosk-SignIn, Kiosk-PIN). It gives the platform release a
 * working entry point: the module gate (404 when Training is off), the "not set up" screen, the
 * device-ready screen, and the redirect of a live session to its role's home.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

$k_dev = kiosk_require_device();
if ($k_dev !== null && ($_GET['switch'] ?? null) !== '1') {
    $k_live = kiosk_peek_session();
    if ($k_live !== null) {
        kiosk_redirect(kiosk_role_home((string) $k_live['ksess_role'], $k_live));
    }
}

$k_page = [
    'title' => $k_dev === null ? 'Not set up' : 'Home',
    'data' => ['state' => $k_dev === null ? 'not_setup' : 'ready'],
    'body_class' => 'kx-home',
];
$k_h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$k_en = static fn(string $key, array $v = []): string => \ITFlow\Training\Kiosk\Core\KioskStrings::t('en', $key, $v);
$k_es = static fn(string $key, array $v = []): string => \ITFlow\Training\Kiosk\Core\KioskStrings::t('es', $key, $v);
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kx-center">
<?php if ($k_dev === null) { ?>
  <section class="kx-hero" aria-labelledby="kx-ns-title">
    <span class="kx-hero__icon" aria-hidden="true"><i class="fas fa-tablet-alt"></i></span>
    <h1 id="kx-ns-title"><?= $k_h($k_en('shell.not_setup_title')) ?></h1>
    <p class="kx-hero__alt" lang="es"><?= $k_h($k_es('shell.not_setup_title')) ?></p>
    <p class="kx-lead"><?= $k_h($k_en('shell.not_setup_body')) ?></p>
    <div class="kx-actions">
      <a class="kx-btn kx-btn--primary kx-btn--xl" href="/agent/training_device_setup.php"><i class="fas fa-cog" aria-hidden="true"></i><span><?= $k_h($k_en('shell.not_setup_action')) ?></span></a>
    </div>
  </section>
<?php } else { ?>
  <section class="kx-hero" aria-labelledby="kx-ready-title">
    <span class="kx-hero__icon" aria-hidden="true"><i class="fas fa-hard-hat"></i></span>
    <h1 id="kx-ready-title"><?= $k_h($k_t('shell.device_ready', ['label' => (string) $k_dev['kiosk_label']])) ?></h1>
    <p class="kx-lead"><?= $k_h($k_t('shell.loading')) ?></p>
  </section>
<?php } ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php';
