<?php

/*
 * Training › Devices & PINs › Setup-code slips (2.6.101, owner ask "like how we add non asset
 * devices via pin do the same for iPad"; module_training_kiosk >= 3). The print page for
 * DeviceEnrollment::issueCodes(): 8 slips per Letter page, each with the device's label, the
 * setup code (XXXXX-XXXXX) and "Use by {date}" (the code's own TTL, config_training_device_code_days
 * days out - NOT minutes, unlike the single-device flow's old 15-minute code), plus bilingual
 * steps for the kiosk's "not set up" -> "Enter a setup code" screen. Structurally identical to
 * agent/training_pin_slips.php: the batch is read with Scratch::get (NOT take) and decrypted with
 * the same slip key, so a reload within its 10 minutes reprints the same slips; "Done - clear
 * these slips" POSTs kiosk_device_codes_clear, the only take(). A prefetch never gets the page.
 */

foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ', 'HTTP_X_PURPOSE'] as $tr_h) {
    if (isset($_SERVER[$tr_h]) && stripos((string) $_SERVER[$tr_h], 'prefetch') !== false) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit;
    }
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$page_extra_css = ['/css/itflow_training.css'];
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuardKiosk(3)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_token = is_string($_GET['t'] ?? null) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $_GET['t']) === 1 ? $_GET['t'] : '';
$tr_slips = null;
if ($tr_token !== '') {
    try {
        $tr_keys = \ITFlow\Training\Kiosk\Core\KioskKeys::fromSecret((string) ($config_settings_enc_key ?? ''));
        $tr_slips = \ITFlow\Training\Kiosk\Device\DeviceEnrollment::openCodes($tr_ctx, $tr_keys, $tr_token);
    } catch (\Throwable $e) {
        error_log('Training device slips: ' . get_class($e));
        $tr_slips = null;
    }
}
$tr_company = trim((string) ($session_company_name ?? ''));

render_page_header(
    'Device setup-code slips',
    'Hand each slip to whoever is setting up that device. They type the code into the kiosk\'s "Enter a setup code" screen.',
    $tr_slips ? '<button type="button" class="btn btn-primary js-print-page"><i class="fas fa-print me-2"></i>Print</button>' : '',
    [['label' => 'Training', 'url' => '/agent/training_courses.php'], ['label' => 'Devices & PINs', 'url' => '/agent/training_devices.php'], ['label' => 'Setup-code slips']]
);
?>
<div class="tr-slips-page" id="tr-device-slips" data-token="<?= nullable_htmlentities($tr_token) ?>">
<?php if (!$tr_slips) { ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="fas fa-receipt fa-2x text-secondary mb-3" aria-hidden="true"></i>
        <?php if (isset($_GET['cleared'])) { ?>
        <h2 class="h3">Slips cleared</h2>
        <p class="text-secondary mb-3">The codes are no longer stored on the server. Devices are set up with the slips you handed out.</p>
        <?php } else { ?>
        <h2 class="h3">These slips expired</h2>
        <p class="text-secondary mb-3">Slips can be printed for 10 minutes after they are issued, or until you clear them. Issue new ones.</p>
        <?php } ?>
        <a class="btn btn-primary" href="/agent/training_devices.php"><i class="fas fa-arrow-left me-2"></i>Back to Devices &amp; PINs</a>
    </div></div>
<?php } else { ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 d-print-none" role="note">
        <i class="fas fa-user-secret" aria-hidden="true"></i>
        <span class="me-auto">These codes work once each. Print, cut, and hand each slip to whoever sets up that device. Then clear them from the server.</span>
        <button type="button" class="btn btn-outline-dark" id="tr-device-slips-clear"><i class="fas fa-check me-2"></i>Done — clear these slips</button>
    </div>
    <div class="tr-slips">
    <?php foreach ($tr_slips as $tr_s) {
        $tr_code = (string) ($tr_s['code'] ?? '');
        $tr_use_by = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($tr_s['expires_on'] ?? ''));
        $tr_unlisted = !empty($tr_s['unlisted']);
        ?>
        <article class="tr-slip">
            <header class="tr-slip__head">
                <span class="tr-slip__brand"><i class="fas fa-tablet-alt" aria-hidden="true"></i> <?= nullable_htmlentities($tr_company !== '' ? $tr_company : 'Training') ?> · Device setup</span>
            </header>
            <div class="tr-slip__name"><?= nullable_htmlentities((string) ($tr_s['label'] ?? '')) ?></div>
            <div class="tr-slip__dept"><?= $tr_unlisted ? 'Not in Assets' : '&nbsp;' ?></div>
            <div class="tr-slip__code" aria-label="Setup code"><?= nullable_htmlentities($tr_code) ?></div>
            <div class="tr-slip__useby">Use by / Usar antes del: <strong><?= nullable_htmlentities($tr_use_by ? $tr_use_by->format('M j, Y') : '') ?></strong></div>
            <ol class="tr-slip__steps">
                <li>On this device, open <?= nullable_htmlentities(APP_NAME) ?> Training (<code>/kiosk/</code>) and tap “Enter a setup code”. <span lang="es">Abra Training y toque “Ingresar un código”.</span></li>
                <li>Type this code, then tap Continue. <span lang="es">Escriba este código y toque Continuar.</span></li>
                <li>The device is ready — people find their name and enter their PIN. <span lang="es">El dispositivo está listo.</span></li>
            </ol>
        </article>
    <?php } unset($tr_code, $tr_use_by, $tr_unlisted, $tr_s); ?>
    </div>
<?php } ?>
</div>
<style nonce="<?= nullable_htmlentities($csp_nonce ?? '') ?>">
.tr-slips { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.tr-slip { border: 1px dashed var(--if-border-strong, #b8c3c6); border-radius: 8px; padding: 14px 18px; background: var(--if-surface, #fff); break-inside: avoid; page-break-inside: avoid; }
.tr-slip__brand { font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--if-muted, #5d6f76); }
.tr-slip__name { margin-top: 6px; font-size: 20px; font-weight: 700; }
.tr-slip__dept { font-size: 14px; color: var(--if-muted, #5d6f76); }
.tr-slip__code { margin: 8px 0 4px; font-family: var(--if-mono, 'Plex Mono', ui-monospace, monospace); font-size: 30px; font-weight: 600; letter-spacing: .08em; }
.tr-slip__useby { font-size: 14px; }
.tr-slip__steps { margin: 8px 0 0; padding-left: 20px; font-size: 12.5px; line-height: 1.35; }
.tr-slip__steps span { color: var(--if-muted, #5d6f76); }
@media print {
  @page { size: letter; margin: 0.4in; }
  body * { visibility: hidden; }
  .tr-slips, .tr-slips * { visibility: visible; }
  .tr-slips { position: absolute; left: 0; top: 0; width: 100%; gap: 0.12in; }
  .tr-slip { height: 2.35in; overflow: hidden; background: #fff; color: #000; border-color: #777; }
  .tr-slip:nth-child(8n) { break-after: page; page-break-after: always; }
}
</style>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_device_slips.js?v=<?= filemtime(__DIR__ . '/js/training_device_slips.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
