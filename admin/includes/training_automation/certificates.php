<?php

/*
 * Training settings > Certificates card (Phase 5 spec §5.4, Lane C). Included by the one Training
 * settings page (admin/settings_training.php) and its Training-level-3 twin (agent/training_settings.php)
 * when this file exists.
 *
 * Expects from the including page:
 *   $ta              AutomationSettings::load() row (tauto_* columns + 'ready')
 *   CSRF             $_SESSION['csrf_token'] (hidden input on every form)
 *   $ta_admin_page   optional bool: true on Admin > Training (default: derived from the script path)
 *   $ta_form_action  optional string: where the forms post (default 'post.php' on the admin page,
 *                    '/agent/training_settings.php' on the agent page)
 *
 * Signatory name, title and signature: administrators and Training level 3, on either page.
 * The public certificate check switch: administrators on Admin > Training only; the agent page shows it
 * read-only ("Ask an administrator"), and CertAdmin refuses it there even if it is forged into a post.
 * Every DB-sourced string is echoed through nullable_htmlentities(); the signature preview is a data: PNG
 * (the app CSP allows data: images) built from the stored, GD re-encoded bytes.
 */

defined('TRAINING_AUTOMATION_PAGE') || exit;

$tac_script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$tac_admin = isset($ta_admin_page) ? (bool) $ta_admin_page : strpos($tac_script, '/admin/') === 0;
$tac_action = isset($ta_form_action) && is_string($ta_form_action) && $ta_form_action !== '' ? $ta_form_action : ($tac_admin ? 'post.php' : '/agent/training_settings.php');
$tac_csrf = (string) ($_SESSION['csrf_token'] ?? '');
$tac = is_array($ta ?? null) ? $ta : [];
$tac_ready = !empty($tac['ready']);
$tac_version = intval($tac['tauto_version'] ?? 0);
$tac_verify_on = intval($tac['tauto_verify_enabled'] ?? 1) === 1;
$tac_is_admin = ($session_is_admin ?? false) === true;
$tac_png = null;
if (is_string($tac['tauto_cert_signer_png'] ?? null) && $tac['tauto_cert_signer_png'] !== '') {
    $tac_raw = base64_decode($tac['tauto_cert_signer_png'], true);
    if (is_string($tac_raw) && strncmp($tac_raw, "\x89PNG\r\n\x1a\n", 8) === 0) {
        $tac_png = 'data:image/png;base64,' . base64_encode($tac_raw);   // re-encoded: only base64 characters reach the attribute
    }
}
?>
<div class="card mb-3" id="certificates">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-certificate me-2" aria-hidden="true"></i>Certificates</h3>
    </div>
    <div class="card-body">
        <?php if (!$tac_ready) { ?>
            <p class="text-muted mb-0">Run the database update to set up certificate signatures and the public certificate check.</p>
        <?php } else { ?>
        <form action="<?php echo nullable_htmlentities($tac_action); ?>" method="post" autocomplete="off" data-ts-label="Certificates">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tac_csrf); ?>">
            <input type="hidden" name="version" value="<?php echo intval($tac_version); ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="taCertSignerName">Signatory name</label>
                    <input type="text" class="form-control" id="taCertSignerName" name="signer_name" maxlength="200"
                           value="<?php echo nullable_htmlentities($tac['tauto_cert_signer_name'] ?? ''); ?>" placeholder="e.g. Dale Whitaker">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="taCertSignerTitle">Signatory title</label>
                    <input type="text" class="form-control" id="taCertSignerTitle" name="signer_title" maxlength="200"
                           value="<?php echo nullable_htmlentities($tac['tauto_cert_signer_title'] ?? ''); ?>" placeholder="e.g. Safety Manager">
                </div>
            </div>
            <div class="form-text mb-3">Printed under the signature line of certificate PDFs. Leave both empty for a blank "Authorized signature" line.</div>

            <div class="mb-3">
                <?php if ($tac_admin) { ?>
                    <input type="hidden" name="verify_enabled" value="0">
                    <div class="form-check form-switch mb-1">
                        <input type="checkbox" class="form-check-input" name="verify_enabled" value="1" id="taCertVerify" <?php if ($tac_verify_on) { echo 'checked'; } ?>>
                        <label class="form-check-label" for="taCertVerify">Public certificate check (the QR code on certificates)</label>
                    </div>
                <?php } else { ?>
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="fw-semibold">Public certificate check:</span>
                        <span class="badge <?php echo $tac_verify_on ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $tac_verify_on ? 'On' : 'Off'; ?></span>
                    </div>
                    <p class="small text-muted mb-1"><i class="fas fa-fw fa-lock me-1" aria-hidden="true"></i><?php if ($tac_is_admin) { ?>Admin only. <a href="/admin/settings_training.php#certificates">Change in Admin &rsaquo; Training</a>.<?php } else { ?>Admin only. Ask an administrator to change this.<?php } ?></p>
                <?php } ?>
                <div class="form-text">
                    Anyone who scans a certificate's QR code sees the name, course, dates, number and whether it is current. When the check is off,
                    every printed QR code shows "Not available". Checks are limited to 240 a minute overall and 20 a minute per visitor.
                </div>
            </div>
            <button type="submit" name="ta_cert_save" value="1" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save certificate settings</button>
        </form>

        <hr class="my-4">

        <div class="row g-3 align-items-start">
            <div class="col-md-6">
                <div class="fw-bold mb-2">Signature image</div>
                <?php if ($tac_png !== null) { ?>
                    <div class="border rounded p-2 mb-2 bg-white d-inline-block">
                        <img src="<?php echo nullable_htmlentities($tac_png); ?>" alt="Current signature" style="max-height: 60px; max-width: 240px;">
                    </div>
                <?php } else { ?>
                    <p class="small text-muted mb-2">No signature uploaded. Certificates print a blank signature line.</p>
                <?php } ?>
                <form action="<?php echo nullable_htmlentities($tac_action); ?>" method="post" enctype="multipart/form-data" autocomplete="off" class="mb-2" data-ts-label="Signature image">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tac_csrf); ?>">
                    <input type="hidden" name="version" value="<?php echo intval($tac_version); ?>">
                    <label class="form-label visually-hidden" for="taCertSignature">Signature image</label>
                    <div class="input-group">
                        <input type="file" class="form-control" id="taCertSignature" name="cert_signature" accept="image/png,image/jpeg" required>
                        <button type="submit" name="ta_cert_signature" value="1" class="btn btn-outline-primary"><i class="fas fa-upload me-2" aria-hidden="true"></i>Upload</button>
                    </div>
                    <div class="form-text">PNG or JPEG, at most 2 MB and 2400 × 1200 pixels. A transparent PNG looks best; it is resized to fit 1200 × 400.</div>
                </form>
                <?php if ($tac_png !== null) { ?>
                <form action="<?php echo nullable_htmlentities($tac_action); ?>" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tac_csrf); ?>">
                    <input type="hidden" name="version" value="<?php echo intval($tac_version); ?>">
                    <button type="submit" name="ta_cert_signature_clear" value="1" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt me-2" aria-hidden="true"></i>Remove signature</button>
                </form>
                <?php } ?>
            </div>
            <div class="col-md-6">
                <div class="fw-bold mb-2">Preview</div>
                <p class="small text-muted mb-2">A made-up certificate with the saved signatory, marked SAMPLE and without a QR code.</p>
                <a class="btn btn-outline-secondary btn-sm me-1" href="/agent/training_pdf.php?doc=sample" target="_blank" rel="noopener"><i class="fas fa-file-pdf me-2" aria-hidden="true"></i>Download sample certificate</a>
                <a class="btn btn-outline-secondary btn-sm" href="/agent/training_pdf.php?doc=sample&amp;lang=es" target="_blank" rel="noopener" lang="es">Muestra en español</a>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php
unset($tac_script, $tac_admin, $tac_action, $tac_csrf, $tac, $tac_ready, $tac_version, $tac_verify_on, $tac_is_admin, $tac_png, $tac_raw);
