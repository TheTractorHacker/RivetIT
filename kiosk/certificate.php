<?php

/*
 * Kiosk › My certificates › one certificate (self-serve, mirrors agent/training_certificate.php).
 *
 * GET /kiosk/certificate.php?id=<completion_id>
 *
 * Same standalone print document as the staff version - same CertificateModel + print shell - but
 * authorized by kiosk session instead of staff login, and restricted to the signed-in learner's own
 * completion_id only (no Scope-based department access; "is this me", nothing broader). Learner
 * session only, same as kiosk/me.php that links here.
 *
 * Order of checks: kiosk session -> the record exists -> it belongs to this contact -> print.
 * Same "not found" sheet for missing and not-mine, so the page never confirms an id exists.
 */

$KIOSK_CSP_PROFILE = 'strict';
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/guard.php';

use ITFlow\Training\Reports\CertificateModel;
use ITFlow\Training\Reports\Labels;

define('TRAINING_PAGE', 1);
require __DIR__ . '/../agent/includes/training_records/print_shell.php';
require __DIR__ . '/../includes/load_company_settings.php';

$k_session = kiosk_require_session(['learner']);
$cid = $kctx->contactId();

$trr_missing = 'This record does not exist, or you do not have access to it.';
$trr_id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
try {
    $trr_cert = CertificateModel::build($kctx->core, $trr_id);
    if ($trr_cert === null || (int) $trr_cert['contact_id'] !== $cid) {
        throw new \RuntimeException('missing');
    }
} catch (\Throwable $e) {
    if (!($e instanceof \RuntimeException && $e->getMessage() === 'missing')) {
        error_log('Kiosk certificate #' . $trr_id . ': ' . get_class($e) . ': ' . $e->getMessage());
    }
    session_write_close();
    trr_print_not_found($trr_missing);
}
session_write_close();

$trr_h = static fn(?string $s): string => nullable_htmlentities((string) $s);

/** TCPDF QR as inline SVG: prolog and <desc> stripped, viewBox added so CSS can size it (S13). */
$trr_qr = null;
if ($trr_cert['verify_url'] !== null && $trr_cert['kind'] === 'training') {
    try {
        require_once __DIR__ . '/../plugins/TCPDF/tcpdf_barcodes_2d.php';
        $trr_svg = (new \TCPDF2DBarcode($trr_cert['verify_url'], 'QRCODE,M'))->getBarcodeSVGcode(3, 3, '#111');
        $trr_pos = strpos($trr_svg, '<svg');
        if ($trr_pos !== false) {
            $trr_svg = substr($trr_svg, $trr_pos);
            $trr_svg = preg_replace('#<desc>.*?</desc>\s*#s', '', $trr_svg);
            if (preg_match('/<svg width="([0-9.]+)" height="([0-9.]+)"/', $trr_svg, $trr_m) === 1) {
                $trr_svg = preg_replace('/<svg width="[0-9.]+" height="[0-9.]+"/',
                    '<svg viewBox="0 0 ' . $trr_m[1] . ' ' . $trr_m[2] . '" aria-hidden="true" focusable="false" shape-rendering="crispEdges"', $trr_svg, 1);
            }
            $trr_qr = $trr_svg;
        }
    } catch (\Throwable $e) {
        error_log('Kiosk certificate QR #' . $trr_id . ': ' . $e->getMessage());
        $trr_qr = null;
    }
}

/** Decorative guilloche rosette (pure SVG, no script) behind the text. */
$trr_rosette = '';
for ($i = 0; $i < 24; $i++) {
    $trr_rosette .= '<ellipse cx="0" cy="0" rx="250" ry="78" transform="rotate(' . sprintf('%.1f', $i * 7.5) . ')"/>';
}

$trr_kind = $trr_cert['kind'] === 'document' ? 'document' : ($trr_cert['external'] ? 'external' : 'training');
$trr_status = $trr_cert['status'];
$trr_overlay = null;
if ($trr_cert['voided'] !== null) {
    $trr_overlay = ['class' => 'void', 'mark' => 'VOID', 'text' => 'VOID — ' . $trr_cert['voided']['reason']];
} elseif ($trr_status['code'] === 'revoked' && $trr_status['reason'] === 'retrain_required') {
    $trr_overlay = ['class' => 'superseded', 'mark' => 'SUPERSEDED', 'text' => 'Superseded: retrain required'];
} elseif ($trr_status['code'] === 'expired') {
    $trr_overlay = ['class' => 'expired', 'mark' => 'EXPIRED', 'text' => 'Expired ' . Labels::longDate($trr_cert['expires_on'])];
}
$trr_company = (string) ($session_company_name ?? '');
$trr_logo = (string) ($session_company_logo ?? '');
$trr_title = match ($trr_kind) {
    'document' => 'Acknowledgment record',
    'external' => 'Training record',
    default => 'Certificate of Completion',
};

trr_print_begin($trr_title . ' · ' . $trr_cert['person_name'], 'landscape');
?>
<div class="trr-toolbar" role="toolbar" aria-label="Certificate actions">
    <a class="trr-toolbar__link" href="/kiosk/me.php">&larr; My learning</a>
    <span class="trr-toolbar__hint">US Letter, landscape. Choose “Save as PDF” in the print dialog for a file.</span>
    <button type="button" class="trr-toolbar__btn js-print">Print</button>
</div>

<div class="trr-sheet-wrap">
<main class="trr-sheet trr-cert trr-cert--<?= $trr_kind ?><?= $trr_overlay !== null ? ' trr-cert--flagged' : '' ?>" aria-label="<?= $trr_h($trr_title) ?>">
    <svg class="trr-cert__rosette" aria-hidden="true" focusable="false" viewBox="-264 -264 528 528"><g fill="none" stroke="currentColor" stroke-width=".7"><?= $trr_rosette ?></g></svg>
    <div class="trr-cert__frame" aria-hidden="true"><span></span><span></span><span></span><span></span></div>

    <div class="trr-cert__inner">
        <header class="trr-cert__head">
            <div class="trr-cert__logo">
                <?php if ($trr_logo !== '') { ?><img src="/uploads/settings/<?= $trr_h($trr_logo) ?>" alt="<?= $trr_h($trr_company) ?> logo"><?php } ?>
            </div>
            <div class="trr-cert__company"><?= $trr_h($trr_company) ?></div>
            <div class="trr-cert__number">
                <?php if ($trr_cert['cert_number'] !== null) { ?>
                <div class="trr-cert__eyebrow"><?= $trr_kind === 'training' ? 'Certificate no.' : 'Record no.' ?></div>
                <div class="trr-cert__mono"><?= $trr_h($trr_cert['cert_number']) ?></div>
                <?php } ?>
            </div>
        </header>

        <section class="trr-cert__body">
            <?php if ($trr_kind === 'training') { ?>
            <h1 class="trr-cert__title">Certificate of Completion</h1>
            <div class="trr-cert__divider" aria-hidden="true"><span></span><i></i><span></span></div>
            <p class="trr-cert__lead">This certifies that</p>
            <p class="trr-cert__name"><?= $trr_h($trr_cert['person_name']) ?></p>
            <div class="trr-cert__rule" aria-hidden="true"></div>
            <p class="trr-cert__lead">has successfully completed</p>
            <p class="trr-cert__course"><?= $trr_h($trr_cert['course_name']) ?></p>
            <p class="trr-cert__meta">
                <?php
                $trr_bits = [];
                if ($trr_cert['score_pct'] !== null) {
                    $trr_bits[] = 'Score <strong>' . $trr_h($trr_cert['score_pct']) . '%</strong>';
                }
                if ($trr_cert['revision_number'] !== null) {
                    $trr_bits[] = 'Version <strong>' . (int) $trr_cert['revision_number'] . '</strong>';
                }
                if ($trr_cert['components_line'] !== null) {
                    $trr_bits[] = $trr_h($trr_cert['components_line']);
                }
                echo implode('<span class="trr-cert__dot" aria-hidden="true"></span>', array_map(static fn($b) => '<span>' . $b . '</span>', $trr_bits));
                ?>
            </p>
            <?php if ($trr_cert['regulation_line'] !== null) { ?><p class="trr-cert__reg"><?= $trr_h($trr_cert['regulation_line']) ?></p><?php } ?>

            <?php } elseif ($trr_kind === 'external') { ?>
            <h1 class="trr-cert__title trr-cert__title--sm">Training record</h1>
            <p class="trr-cert__badge"><?= $trr_h($trr_cert['external_label']) ?></p>
            <p class="trr-cert__name trr-cert__name--sm"><?= $trr_h($trr_cert['person_name']) ?></p>
            <div class="trr-cert__rule" aria-hidden="true"></div>
            <p class="trr-cert__course"><?= $trr_h($trr_cert['course_name']) ?></p>
            <dl class="trr-cert__facts">
                <?php if ($trr_cert['issuer'] !== null) { ?><div><dt>Issued by</dt><dd><?= $trr_h($trr_cert['issuer']) ?></dd></div><?php } ?>
                <?php if ($trr_cert['external_ref'] !== null) { ?><div><dt>Card no.</dt><dd class="trr-cert__mono"><?= $trr_h($trr_cert['external_ref']) ?></dd></div><?php } ?>
                <?php if ($trr_cert['trained_on'] !== null) { ?><div><dt>Trained</dt><dd><?= $trr_h(Labels::longDate($trr_cert['trained_on'])) ?></dd></div><?php } ?>
                <?php if ($trr_cert['evaluated_on'] !== null) { ?><div><dt>Evaluated</dt><dd><?= $trr_h(Labels::longDate($trr_cert['evaluated_on'])) ?></dd></div><?php } ?>
            </dl>
            <?php if ($trr_cert['regulation_line'] !== null) { ?><p class="trr-cert__reg"><?= $trr_h($trr_cert['regulation_line']) ?></p><?php } ?>

            <?php } else { ?>
            <h1 class="trr-cert__title trr-cert__title--sm">Acknowledgment record</h1>
            <div class="trr-cert__divider" aria-hidden="true"><span></span><i></i><span></span></div>
            <p class="trr-cert__ack">
                <strong><?= $trr_h($trr_cert['person_name']) ?></strong> acknowledged
                <strong><?= $trr_h($trr_cert['course_name']) ?></strong><?php if ($trr_cert['revision_number'] !== null) { ?> (Version <?= (int) $trr_cert['revision_number'] ?>)<?php } ?>
                on <?= $trr_h(Labels::longDate($trr_cert['completed_on'])) ?>.
            </p>
            <?php if ($trr_cert['regulation_line'] !== null) { ?><p class="trr-cert__reg"><?= $trr_h($trr_cert['regulation_line']) ?></p><?php } ?>
            <?php } ?>
        </section>

        <footer class="trr-cert__foot">
            <div class="trr-cert__field">
                <div class="trr-cert__value"><?= $trr_h(Labels::longDate($trr_cert['completed_on'])) ?></div>
                <div class="trr-cert__eyebrow"><?= $trr_kind === 'document' ? 'Acknowledged' : 'Issued' ?></div>
            </div>
            <?php if ($trr_kind !== 'document') { ?>
            <div class="trr-cert__field">
                <div class="trr-cert__value"><?= $trr_cert['expires_on'] !== null ? $trr_h(Labels::longDate($trr_cert['expires_on'])) : 'Does not expire' ?></div>
                <div class="trr-cert__eyebrow">Expires</div>
            </div>
            <?php } else { ?>
            <div class="trr-cert__field trr-cert__field--empty" aria-hidden="true"></div>
            <?php } ?>
            <div class="trr-cert__sign">
                <?php if ($trr_cert['signer'] !== null) { ?>
                <div class="trr-cert__sigline" aria-hidden="true"></div>
                <div class="trr-cert__signer"><strong><?= $trr_h($trr_cert['signer']['name']) ?></strong><?= $trr_cert['signer']['title'] !== null ? ', ' . $trr_h($trr_cert['signer']['title']) : '' ?></div>
                <?php } ?>
            </div>
            <div class="trr-cert__verify">
                <?php if ($trr_qr !== null) { ?>
                <div class="trr-qr" role="img" aria-label="Verification QR code"><?= $trr_qr ?></div>
                <div class="trr-cert__verify-text"><strong>Scan to verify</strong><span class="trr-cert__mono"><?= $trr_h($trr_cert['cert_number']) ?></span></div>
                <?php } elseif ($trr_cert['verify_url'] !== null) { ?>
                <div class="trr-cert__verify-text"><strong>Verify at</strong><span class="trr-cert__mono trr-cert__url"><?= $trr_h($trr_cert['verify_url']) ?></span></div>
                <?php } ?>
            </div>
        </footer>
        <div class="trr-cert__record">Record #<?= (int) $trr_cert['completion_id'] ?><?= $trr_cert['ledger'] !== null ? ' · ledger ' . $trr_h($trr_cert['ledger']) : '' ?></div>
    </div>

    <?php if ($trr_overlay !== null) { ?>
    <?php if ($trr_overlay['mark'] !== null) { ?><div class="trr-cert__watermark trr-cert__watermark--<?= $trr_overlay['class'] ?>" aria-hidden="true"><?= $trr_h($trr_overlay['mark']) ?></div><?php } ?>
    <div class="trr-cert__status trr-cert__status--<?= $trr_overlay['class'] ?>" role="note"><?= $trr_h($trr_overlay['text']) ?></div>
    <?php } ?>
</main>
</div>
<?php
trr_print_end();
