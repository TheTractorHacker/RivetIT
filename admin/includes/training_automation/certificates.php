<?php

/*
 * Training settings > Certificates (Phase 5 spec §5.4, Lane C): two cards inside the #certificates section
 * that Lane A's admin/includes/training_automation/sections.php draws on BOTH one-page Training settings
 * pages (admin/settings_training.php and its Training-level-3 twin agent/training_settings.php). The
 * section wrapper carries the #certificates anchor, so the cards here repeat no id of it.
 *
 *   Certificate signatory     name, title, signature image, sample PDF       admins and Training 3, both pages
 *   Public certificate check  the verify page switch (the QR code target)    admins on Admin > Training only;
 *                                                                            read-only on the agent page
 *
 * From the shell (sections.php): $ta (AutomationSettings::load(): tauto_* + 'ready'), $ta_version,
 * $ta_csrf, $ta_post_url ('post.php' on the admin page, '/agent/training_settings.php' on the agent page),
 * $ta_admin_page, $ta_is_admin, ta_admin_only_note(). Each has a fallback so the card also renders on its own.
 * Forms post ta_cert_save / ta_cert_signature / ta_cert_signature_clear (Certificates\CertAdmin, through Lane A's
 * AutomationActions, which also refuses verify_enabled from the agent page before CertAdmin does).
 * Every DB-sourced string is echoed through nullable_htmlentities(); the signature preview is a data: PNG
 * (the app CSP allows data: images) rebuilt from the stored, GD re-encoded bytes.
 */

defined('TRAINING_AUTOMATION_PAGE') || exit;

$tac_admin = isset($ta_admin_page) ? (bool) $ta_admin_page : strpos(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') === 0;
$tac_action = isset($ta_post_url) && is_string($ta_post_url) && $ta_post_url !== '' ? $ta_post_url : ($tac_admin ? 'post.php' : '/agent/training_settings.php');
$tac_csrf = isset($ta_csrf) && is_string($ta_csrf) ? $ta_csrf : (string) ($_SESSION['csrf_token'] ?? '');
$tac = is_array($ta ?? null) ? $ta : [];
$tac_ready = !empty($tac['ready']);
$tac_version = intval($ta_version ?? ($tac['tauto_version'] ?? 0));
$tac_verify_on = intval($tac['tauto_verify_enabled'] ?? 1) === 1;
$tac_is_admin = isset($ta_is_admin) ? (bool) $ta_is_admin : (($session_is_admin ?? false) === true);
$tac_png = null;
if (is_string($tac['tauto_cert_signer_png'] ?? null) && $tac['tauto_cert_signer_png'] !== '') {
    $tac_raw = base64_decode($tac['tauto_cert_signer_png'], true);
    if (is_string($tac_raw) && strncmp($tac_raw, "\x89PNG\r\n\x1a\n", 8) === 0) {
        $tac_png = 'data:image/png;base64,' . base64_encode($tac_raw);   // re-encoded: only base64 characters reach the attribute
    }
}

if (!$tac_ready) { ?>
<div class="card mb-3">
    <div class="card-body"><p class="text-muted mb-0">Run the database update to set up the certificate signatory and the public certificate check.</p></div>
</div>
<?php } else { ?>
<!-- Certificate signatory -------------------------------------------------------------------- -->
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-signature me-2" aria-hidden="true"></i>Certificate signatory</h3>
    </div>
    <div class="card-body">
        <form action="<?php echo nullable_htmlentities($tac_action); ?>" method="post" autocomplete="off" data-ts-label="Certificate signatory">
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
            <button type="submit" name="ta_cert_save" value="1" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save signatory</button>
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
    </div>
</div>

<!-- Public certificate check ------------------------------------------------------------------ -->
<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-fw fa-qrcode me-2" aria-hidden="true"></i>Public certificate check</h3>
        <?php if (!$tac_admin) { echo function_exists('ta_read_only_badge') ? ta_read_only_badge() : ''; } ?>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            The QR code on every certificate opens <span class="font-monospace">/verify/</span>. Anyone who scans it sees the name, course, dates,
            number and whether the certificate is current, nothing else. When the check is off, every printed QR code shows "Not available".
            Checks are limited to 240 a minute overall and 20 a minute per visitor.
        </p>
        <?php if ($tac_admin) { ?>
        <form action="<?php echo nullable_htmlentities($tac_action); ?>" method="post" autocomplete="off" data-ts-label="Public certificate check">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tac_csrf); ?>">
            <input type="hidden" name="version" value="<?php echo intval($tac_version); ?>">
            <input type="hidden" name="verify_enabled" value="0">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" name="verify_enabled" value="1" id="taCertVerify" <?php if ($tac_verify_on) { echo 'checked'; } ?>>
                <label class="form-check-label" for="taCertVerify">Answer certificate checks (the QR code on certificates)</label>
            </div>
            <button type="submit" name="ta_cert_save" value="1" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save public check</button>
        </form>
        <?php } else { ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="fw-semibold">Certificate checks:</span>
            <span class="badge <?php echo $tac_verify_on ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $tac_verify_on ? 'On' : 'Off'; ?></span>
        </div>
        <?php
        if (function_exists('ta_admin_only_note')) {
            echo ta_admin_only_note('certificates');
        } else { ?>
            <p class="small mb-0"><i class="fas fa-fw fa-lock me-1" aria-hidden="true"></i><?php if ($tac_is_admin) { ?>Admin only. <a href="/admin/settings_training.php#certificates">Change in Admin &rsaquo; Training</a>.<?php } else { ?>Admin only. Ask an administrator to change this.<?php } ?></p>
        <?php } ?>
        <?php } ?>
    </div>
</div>
<?php }
unset($tac_admin, $tac_action, $tac_csrf, $tac, $tac_ready, $tac_version, $tac_verify_on, $tac_is_admin, $tac_png, $tac_raw);
