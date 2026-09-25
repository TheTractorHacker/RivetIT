<?php

/*
 * Training › Devices & PINs › Setup slips (P3 spec §5.8, lane K2; module_training_kiosk >= 2).
 *
 * Prints the encrypted slip batch the issuing agent just made (PinAdmin::issueSlips): 8 slips per
 * Letter page, each with the person's name, department, the 8-digit setup code, "Use by {date}"
 * and EN/ES steps. The batch is read with Scratch::get (NOT take) and decrypted with the
 * slip key, so a reload within its 10 minutes reprints the same slips; "Done - clear these slips"
 * POSTs pin_slips_clear, the only take(). A prefetch never gets the page (503 before anything).
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
if (\ITFlow\Training\Core\Access::pageGuardKiosk(2)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_token = is_string($_GET['t'] ?? null) && preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $_GET['t']) === 1 ? $_GET['t'] : '';
$tr_slips = null;
if ($tr_token !== '') {
    try {
        $tr_keys = \ITFlow\Training\Kiosk\Core\KioskKeys::fromSecret((string) ($config_settings_enc_key ?? ''));
        $tr_slips = \ITFlow\Training\Kiosk\Pin\PinAdmin::openSlips($tr_ctx, $tr_keys, $tr_token);
    } catch (\Throwable $e) {
        error_log('Training slips: ' . get_class($e));
        $tr_slips = null;
    }
}
$tr_company = trim((string) ($session_company_name ?? ''));

render_page_header(
    'PIN setup slips',
    'Hand each person their own slip. They type the code on a training device to pick their PIN.',
    $tr_slips ? '<button type="button" class="btn btn-primary js-print-page"><i class="fas fa-print me-2"></i>Print</button>' : '',
    [['label' => 'Training', 'url' => '/agent/training_courses.php'], ['label' => 'Devices & PINs', 'url' => '/agent/training_devices.php?tab=people'], ['label' => 'Setup slips']]
);
?>
<div class="tr-slips-page" id="tr-slips" data-token="<?= nullable_htmlentities($tr_token) ?>">
<?php if (!$tr_slips) { ?>
    <div class="card"><div class="card-body text-center py-5">
        <i class="fas fa-receipt fa-2x text-secondary mb-3" aria-hidden="true"></i>
        <?php if (isset($_GET['cleared'])) { ?>
        <h2 class="h3">Slips cleared</h2>
        <p class="text-secondary mb-3">The codes are no longer stored on the server. People use the slips you handed out.</p>
        <?php } else { ?>
        <h2 class="h3">These slips expired</h2>
        <p class="text-secondary mb-3">Slips can be printed for 10 minutes after they are issued, or until you clear them. Issue new ones.</p>
        <?php } ?>
        <a class="btn btn-primary" href="/agent/training_devices.php?tab=people"><i class="fas fa-arrow-left me-2"></i>Back to People &amp; PINs</a>
    </div></div>
<?php } else { ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 d-print-none" role="note">
        <i class="fas fa-user-secret" aria-hidden="true"></i>
        <span class="me-auto">These codes work once. Print, cut, and hand each slip to its person. Then clear them from the server.</span>
        <button type="button" class="btn btn-outline-dark" id="tr-slips-clear"><i class="fas fa-check me-2"></i>Done — clear these slips</button>
    </div>
    <div class="tr-slips">
    <?php foreach ($tr_slips as $tr_s) {
        $tr_code = (string) ($tr_s['code'] ?? '');
        $tr_code_fmt = strlen($tr_code) === 8 ? substr($tr_code, 0, 4) . ' ' . substr($tr_code, 4) : $tr_code;
        $tr_use_by = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($tr_s['expires_on'] ?? ''));
        ?>
        <article class="tr-slip">
            <header class="tr-slip__head">
                <span class="tr-slip__brand"><i class="fas fa-hard-hat" aria-hidden="true"></i> <?= nullable_htmlentities($tr_company !== '' ? $tr_company : 'Training') ?> · Training PIN</span>
            </header>
            <div class="tr-slip__name"><?= nullable_htmlentities((string) ($tr_s['name'] ?? '')) ?></div>
            <div class="tr-slip__dept"><?= nullable_htmlentities((string) ($tr_s['dept'] ?? '')) ?></div>
            <div class="tr-slip__code" aria-label="Setup code"><?= nullable_htmlentities($tr_code_fmt) ?></div>
            <div class="tr-slip__useby">Use by / Usar antes del: <strong><?= nullable_htmlentities($tr_use_by ? $tr_use_by->format('M j, Y') : '') ?></strong></div>
            <ol class="tr-slip__steps">
                <li>On a training iPad, find your name and tap it. <span lang="es">Busque su nombre y tóquelo.</span></li>
                <li>Tap “I have a setup code” and type this code. <span lang="es">Toque “Tengo un código” y escriba este código.</span></li>
                <li>Pick a new 6-digit PIN only you know. <span lang="es">Escoja un PIN nuevo de 6 dígitos.</span></li>
            </ol>
        </article>
    <?php } unset($tr_code, $tr_code_fmt, $tr_s); ?>
    </div>
<?php } ?>
</div>
<style nonce="<?= nullable_htmlentities($csp_nonce ?? '') ?>">
.tr-slips { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.tr-slip { border: 1px dashed var(--if-border-strong, #b8c3c6); border-radius: 8px; padding: 14px 18px; background: var(--if-surface, #fff); break-inside: avoid; page-break-inside: avoid; }
.tr-slip__brand { font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--if-muted, #5d6f76); }
.tr-slip__name { margin-top: 6px; font-size: 20px; font-weight: 700; }
.tr-slip__dept { font-size: 14px; color: var(--if-muted, #5d6f76); }
.tr-slip__code { margin: 8px 0 4px; font-family: var(--if-mono, 'Plex Mono', ui-monospace, monospace); font-size: 34px; font-weight: 600; letter-spacing: .12em; }
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
<script src="/agent/js/training_devices.js?v=<?= filemtime(__DIR__ . '/js/training_devices.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
