<?php

/*
 * /kiosk/ - Home: not set up, name search, PIN, setup code and new PIN (P3 spec §5.2, lane K2;
 * mockups Kiosk-SignIn and Kiosk-PIN).
 *
 *   - no device (none, revoked, or its asset's assignment changed - A19): the bilingual "not set up"
 *     screen with "Set up this device (admin)" and [S] "Enter a setup code". No names, no data.
 *     A temporary device whose time ran out on THIS request (KioskAuth::expiredAt()), or a redirect
 *     here with ?ended=<epoch> (guard.php / the runtime, within the last 24 h), says instead "This
 *     device's training time is over - it stopped working at 3:13 PM" (a local clock time; display
 *     only, the device is gone either way).
 *   - /kiosk/#d=<token> (the permanent start URL): js/training_kiosk_signin.js replaces the URL at
 *     once, adopts the token by POST (asking before replacing a valid device) and reloads /kiosk/.
 *     The fragment never reaches a server.
 *   - a live learner/trainer/checkin/handoff session on this device: 302 to its home; with
 *     ?switch=1 instead "Sign out {name}?" (POST end - never a GET state change). Check-in and
 *     hand-off ignore ?switch=1: leaving them needs the trainer PIN.
 *   - /kiosk/?d=<token> (the Windows kiosk-mode start URL): 302 to /kiosk/#d=<token> before any
 *     HTML, so the page never renders with the device token in its URL; the fragment is adopted
 *     by POST as above.
 *   - otherwise the sign-in screens, rendered by the page script from k-page-data; a personal
 *     device (A19/D-4) opens straight on "Hi {first}" + PIN with "Not {first}?".
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Pin\Seam;

// The Windows kiosk-mode start URL /kiosk/?d=<token>: answer with a bare redirect to the fragment
// form (never rendered with the token in the address bar; the page adopts it by POST). A malformed
// ?d= just goes home. nginx answers this hop itself where its kiosk rule is deployed (no access log).
if (array_key_exists('d', $_GET)) {
    $k_d = $_GET['d'];
    $k_loc = is_string($k_d) && preg_match(KioskAuth::TOKEN_RE, $k_d) === 1 ? '/kiosk/#d=' . $k_d : '/kiosk/';
    unset($k_d);
    header('Location: ' . $k_loc, true, 302);
    exit;
}

// The fleet link /kiosk/?e=<fleet token>&sn=<serial> (MDM mass deployment, 2.6.151): the same bare redirect to the fragment form
// /kiosk/#e=<token>&sn=<serial>, which the page redeems by POST (enroll_fleet). A malformed token just goes home; a serial that is not
// plain text is passed on percent-encoded and trimmed so the page can say it is not valid.
if (array_key_exists('e', $_GET)) {
    $k_e = $_GET['e'];
    $k_sn = $_GET['sn'] ?? null;
    $k_loc = '/kiosk/';
    if (is_string($k_e) && preg_match(\ITFlow\Training\Kiosk\Device\FleetLinks::TOKEN_RE, $k_e) === 1) {
        $k_loc = '/kiosk/#e=' . $k_e . (is_string($k_sn) && $k_sn !== '' ? '&sn=' . rawurlencode(substr($k_sn, 0, 100)) : '');
    }
    unset($k_e, $k_sn);
    header('Location: ' . $k_loc, true, 302);
    exit;
}

$k_dev = kiosk_require_device();
// A portal device has no sign-in screen of its own: without a live session it goes back to the portal.
if ($k_dev !== null && ($k_dev['kiosk_enroll_method'] ?? '') === 'portal' && kiosk_peek_session() === null) {
    header('Location: /client/training.php', true, 302);
    exit;
}
// A temporary device whose time is up: when it ended (this request found it, or ?ended= from a redirect).
$k_ended = null;
if ($k_dev === null) {
    $k_end_utc = KioskAuth::expiredAt();
    if ($k_end_utc === null && is_string($_GET['ended'] ?? null) && preg_match('/^\d{9,11}$/D', $_GET['ended']) === 1) {
        $k_e = (int) $_GET['ended'];
        $k_end_utc = ($k_e <= time() + 120 && $k_e >= time() - 86400) ? \ITFlow\Training\Kiosk\Core\KTime::fromEpoch((float) $k_e) : null;
    }
    if ($k_end_utc !== null) {
        $k_ended = ['en' => \ITFlow\Training\Kiosk\Core\KTime::localClock($k_end_utc, 'en')];
    }
}
$k_switch = null;
if ($k_dev !== null) {
    $k_live = kiosk_peek_session();
    if ($k_live !== null) {
        // Check-in and hand-off modes are left only with the trainer PIN (T-3, T-5): no "Sign out {name}?" for them.
        if (($_GET['switch'] ?? null) !== '1' || in_array((string) $k_live['ksess_role'], ['checkin', 'handoff'], true)) {
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

// A fleet-link device that is waiting for an admin (2.6.151): its cookie is kept and it sees only the waiting screen.
$k_pending = $k_dev === null && $k_ended === null && KioskAuth::awaitingApproval();
$k_state = $k_dev === null ? ($k_pending ? 'pending' : 'not_setup') : ($k_switch !== null ? 'switch' : 'signin');
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
    'title' => $k_dev === null ? ($k_pending ? 'Waiting for approval' : 'Not set up') : 'Sign in',
    'css' => ['/css/itflow_training_kiosk_signin.css'],
    'js' => ['/js/training_kiosk_signin.js'],
    'body_class' => 'kx-home kx-signin-page',
    'data' => [
        'state' => $k_state,
        'ended' => $k_ended !== null,
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
<?php if ($k_pending) { ?>
  <div class="kx-center" id="kx-ns">
    <section class="kx-hero" aria-labelledby="kx-ns-title">
      <span class="kx-hero__icon" aria-hidden="true"><i class="fas fa-hourglass-half"></i></span>
      <h1 id="kx-ns-title"><?= $k_h($k_en('shell.pending_title')) ?></h1>
      <p class="kx-hero__alt" lang="es"><?= $k_h($k_es('shell.pending_title')) ?></p>
      <p class="kx-lead"><?= $k_h($k_en('shell.pending_body')) ?></p>
      <p class="kx-lead" lang="es"><?= $k_h($k_es('shell.pending_body')) ?></p>
    </section>
  </div>
<?php } elseif ($k_dev === null) { ?>
  <div class="kx-center" id="kx-ns">
    <section class="kx-hero" aria-labelledby="kx-ns-title">
      <span class="kx-hero__icon" aria-hidden="true"><i class="fas <?= $k_ended !== null ? 'fa-hourglass-end' : 'fa-tablet-alt' ?>"></i></span>
<?php if ($k_ended !== null) { ?>
      <h1 id="kx-ns-title"><?= $k_h($k_en('shell.ended_title')) ?></h1>
      <p class="kx-hero__alt" lang="es"><?= $k_h($k_es('shell.ended_title')) ?></p>
      <p class="kx-lead" id="kx-ns-ended"><?= $k_h($k_en('shell.ended_body', ['time' => $k_ended['en']])) ?></p>
<?php } else { ?>
      <h1 id="kx-ns-title"><?= $k_h($k_en('shell.not_setup_title')) ?></h1>
      <p class="kx-hero__alt" lang="es"><?= $k_h($k_es('shell.not_setup_title')) ?></p>
      <p class="kx-lead"><?= $k_h($k_en('shell.not_setup_body')) ?></p>
<?php } ?>
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
