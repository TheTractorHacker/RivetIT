<?php

/*
 * /kiosk/ - Home: not set up, name search, PIN, setup code and new PIN (P3 spec §5.2, lane K2;
 * mockups Kiosk-SignIn and Kiosk-PIN).
 *
 *   - no device (none, revoked, or its asset's assignment changed - A19): the bilingual "not set up"
 *     screen with "Set up this device (admin)" and [S] "Enter a setup code". No names, no data.
 *   - /kiosk/#d=<token> (the permanent start URL): js/training_kiosk_signin.js replaces the URL at
 *     once, adopts the token by POST (asking before replacing a valid device) and reloads /kiosk/.
 *     The fragment never reaches a server.
 *   - a live learner/trainer/checkin/handoff session on this device: 302 to its home; with
 *     ?switch=1 instead "Sign out {name}?" (POST end - never a GET state change).
 *   - otherwise the sign-in screens, rendered by the page script from k-page-data; a personal
 *     device (A19/D-4) opens straight on "Hi {first}" + PIN with "Not {first}?".
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Pin\Seam;

$k_dev = kiosk_require_device();
$k_switch = null;
if ($k_dev !== null) {
    $k_live = kiosk_peek_session();
    if ($k_live !== null) {
        if (($_GET['switch'] ?? null) !== '1') {
            kiosk_redirect(kiosk_role_home((string) $k_live['ksess_role'], $k_live));
        }
        $k_switch = ['name' => (string) $k_live['contact_name'], 'home' => kiosk_role_home((string) $k_live['ksess_role'], $k_live)];
    }
}

$k_personal = null;
if ($k_dev !== null && $k_switch === null && is_array($k_dev['personal'] ?? null)) {
    $k_pid = (int) $k_dev['personal']['contact_id'];
    $k_p = \ITFlow\Training\Core\Db::one($mysqli, 'SELECT c.contact_name, cl.client_name FROM contacts c LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE c.contact_id = ?', 'i', [$k_pid]);
    $k_pname = trim((string) ($k_p['contact_name'] ?? ''));
    $k_personal = [
        'contact_id' => $k_pid,
        'sig' => $kctx->keys->pickSig($kctx->kioskId(), $k_pid),
        'name' => $k_pname,
        'first' => \ITFlow\Training\Kiosk\Core\KioskAuth::firstName($k_pname),
        'dept' => trim((string) ($k_p['client_name'] ?? '')),
        'initials' => \ITFlow\Training\Kiosk\Core\KioskAuth::initials($k_pname),
    ];
}

$k_state = $k_dev === null ? 'not_setup' : ($k_switch !== null ? 'switch' : 'signin');
// [S] T-6: the group-session check-in link shows only while a trainer has a class open (CheckinService, 12 h).
$k_checkin = false;
if ($k_dev !== null && $k_switch === null) {
    try {
        $k_checkin = (new \ITFlow\Training\Kiosk\Trainer\CheckinService($kctx))->openSessions()['sessions'] !== [];
    } catch (\Throwable $e) {
        error_log('Kiosk index checkin: ' . get_class($e));
    }
}
$k_page = [
    'title' => $k_dev === null ? 'Not set up' : 'Sign in',
    'css' => ['/css/itflow_training_kiosk_signin.css'],
    'js' => ['/js/training_kiosk_signin.js'],
    'body_class' => 'kx-home kx-signin-page',
    'data' => [
        'state' => $k_state,
        'personal' => $k_personal,
        'switch' => $k_switch,
        'lock_note' => ['n' => $kctx->ks->pinSoft, 'minutes' => $kctx->ks->pinLockMin],
        'trainers_available' => Seam::trainersAvailable($kctx->db()),
        'checkin_open' => $k_checkin,
        'setup_url' => '/agent/training_device_setup.php',
        'kiosk_idle' => false,
    ],
];
$k_h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$k_en = static fn(string $key, array $v = []): string => KioskStrings::t('en', $key, $v);
$k_es = static fn(string $key, array $v = []): string => KioskStrings::t('es', $key, $v);
require __DIR__ . '/includes/layout_top.php';
?>
<div class="kx-signin" id="kx-signin" data-state="<?= $k_h($k_state) ?>">
<?php if ($k_dev === null) { ?>
  <div class="kx-center" id="kx-ns">
    <section class="kx-hero" aria-labelledby="kx-ns-title">
      <span class="kx-hero__icon" aria-hidden="true"><i class="fas fa-tablet-alt"></i></span>
      <h1 id="kx-ns-title"><?= $k_h($k_en('shell.not_setup_title')) ?></h1>
      <p class="kx-hero__alt" lang="es"><?= $k_h($k_es('shell.not_setup_title')) ?></p>
      <p class="kx-lead"><?= $k_h($k_en('shell.not_setup_body')) ?></p>
      <div class="kx-actions kx-actions--center">
        <a class="kx-btn kx-btn--primary kx-btn--xl" href="/agent/training_device_setup.php"><i class="fas fa-cog" aria-hidden="true"></i><span><?= $k_h($k_en('shell.not_setup_action')) ?></span></a>
        <button type="button" class="kx-btn kx-btn--ghost kx-btn--xl" id="kx-code-open" hidden><i class="fas fa-keyboard" aria-hidden="true"></i><span><?= $k_h($k_en('signin.setup_code_link')) ?></span></button>
      </div>
      <div class="kx-code" id="kx-code" hidden></div>
    </section>
  </div>
<?php } else { ?>
  <div class="kx-center" id="kx-boot"><span class="kx-spin" aria-hidden="true"></span><span class="kx-sr" role="status"><?= $k_h($k_t('shell.loading')) ?></span></div>
<?php } ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php';
