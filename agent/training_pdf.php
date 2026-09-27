<?php

/*
 * Training PDFs (Phase 5 spec §4.2, §5.2, §5.3; S1/S2):
 *
 *   GET /agent/training_pdf.php?doc=certificate&completion_id=N[&dl=1][&lang=en|es]
 *   GET /agent/training_pdf.php?doc=transcript&contact_id=N[&dl=1]
 *   GET /agent/training_pdf.php?doc=sample[&lang=es]            (Training settings > Certificates)
 *
 * Order of checks (spec §8 "PDF endpoint authz"): lean bootstrap (config, functions, check_login -
 * which also keeps a module-only login on its own modules) -> session_write_close() -> Training on
 * and module_training >= 1 -> strict ids -> the person is in the caller's fail-closed People scope
 * (P2's Scope, through Upstream\PeopleScope). Every refusal - unknown id, out of scope, a document
 * acknowledgment (no certificate), P2 or Phase 5 Upstream not deployed - is the same plain 404, so the
 * endpoint never confirms that a record exists. The sample needs an administrator or Training level 3
 * (who may edit the signatory); anyone else gets the same 404.
 *
 * Voided and superseded certificates print only with their REVOKED / SUPERSEDED watermark. PDFs are
 * built in memory and streamed with nosniff and private, no-store; nothing is written to disk.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Certificates\Brand;
use ITFlow\Training\Certificates\Pdf\CertificatePdf;
use ITFlow\Training\Certificates\Pdf\TranscriptPdf;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Upstream\CertTokens;
use ITFlow\Training\Upstream\P2;
use ITFlow\Training\Upstream\PeopleScope;
use ITFlow\Training\Upstream\RecordsGateway;

session_write_close();

/** The one refusal: plain 404, no detail. */
function trpdf_not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo "Not found.\n";
    exit;
}

/** A strict positive id from the query string (1-10 digits, no sign or spaces), or 0. */
function trpdf_id(string $key): int
{
    $v = $_GET[$key] ?? null;
    return is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/D', $v) === 1 && (int) $v <= 2147483647 ? (int) $v : 0;
}

/** Streams the PDF bytes (HEAD: headers only). */
function trpdf_send(string $bytes, string $filename, bool $download, string $method): never
{
    header('Content-Type: application/pdf');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    if ($method !== 'HEAD') {
        echo $bytes;
    }
    exit;
}

$trpdf_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($trpdf_method !== 'GET' && $trpdf_method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method not allowed.\n";
    exit;
}

if (!Access::enabled() || Access::level() < 1) {
    trpdf_not_found();
}
foreach ([RecordsGateway::class, PeopleScope::class, CertTokens::class, P2::class, CertificatePdf::class, TranscriptPdf::class, Brand::class] as $trpdf_class) {
    if (!class_exists($trpdf_class)) {
        error_log('Training PDF: ' . $trpdf_class . ' is missing');
        trpdf_not_found();
    }
}

$trpdf_doc = is_string($_GET['doc'] ?? null) ? $_GET['doc'] : '';
$trpdf_dl = ($_GET['dl'] ?? '') === '1';
$trpdf_lang = in_array($_GET['lang'] ?? null, ['en', 'es'], true) ? (string) $_GET['lang'] : null;
$trpdf_ctx = Access::ctx($mysqli);
$trpdf_gw = new RecordsGateway($mysqli);

$trpdf_cert_settings = class_exists(AutomationSettings::class) ? AutomationSettings::loadCert($mysqli) : [];
$trpdf_verify_on = !class_exists(AutomationSettings::class) || AutomationSettings::loadVerify($mysqli);

if (ini_get('memory_limit') !== '-1' && (int) ini_get('memory_limit') < 256) {
    @ini_set('memory_limit', '256M');
}
@set_time_limit(60);

try {
    switch ($trpdf_doc) {
        case 'certificate':
            $trpdf_id = trpdf_id('completion_id');
            if ($trpdf_id === 0 || !P2::has($mysqli, 'cert')) {
                trpdf_not_found();
            }
            $trpdf_contact = $trpdf_gw->contactOfCompletion($trpdf_id);
            if ($trpdf_contact === null || !PeopleScope::forCtx($trpdf_ctx)->canSeeContact($mysqli, $trpdf_contact)) {
                trpdf_not_found();
            }
            $trpdf_cert = $trpdf_gw->certificate($trpdf_ctx, $trpdf_id);
            if ($trpdf_cert === null || ($trpdf_cert['kind'] ?? '') !== 'training' || (int) ($trpdf_cert['contact_id'] ?? 0) !== $trpdf_contact) {
                trpdf_not_found();   // acknowledgment records have no certificate
            }
            // The QR only when the token re-derives to the stored one and the public check is on.
            $trpdf_qr = $trpdf_verify_on ? CertTokens::verifyUrlForCompletion($trpdf_ctx, $trpdf_id) : null;
            $trpdf_l = $trpdf_lang ?? (($trpdf_cert['language'] ?? 'en') === 'es' ? 'es' : 'en');
            $trpdf_bytes = CertificatePdf::render($trpdf_cert, Brand::load($mysqli, $trpdf_cert_settings), $trpdf_qr, $trpdf_l);
            $trpdf_num = (string) ($trpdf_cert['cert_number'] ?? '');
            $trpdf_file = preg_match('/^LMS-\d{4}-\d{6}$/D', $trpdf_num) === 1 ? $trpdf_num . '.pdf' : 'certificate-' . $trpdf_id . '.pdf';
            if ($trpdf_method === 'GET') {
                logAction('Training', 'Export', 'Downloaded training certificate PDF ' . ($trpdf_num !== '' ? $trpdf_num : '#' . $trpdf_id)
                    . ' for contact #' . $trpdf_contact, 0, $trpdf_id);
            }
            trpdf_send($trpdf_bytes, $trpdf_file, $trpdf_dl, $trpdf_method);

        case 'transcript':
            $trpdf_id = trpdf_id('contact_id');
            if ($trpdf_id === 0 || !P2::has($mysqli, 'transcript') || !PeopleScope::forCtx($trpdf_ctx)->canSeeContact($mysqli, $trpdf_id)) {
                trpdf_not_found();
            }
            $trpdf_t = $trpdf_gw->transcript($trpdf_ctx, $trpdf_id);
            if ($trpdf_t === null) {
                trpdf_not_found();
            }
            if (($trpdf_t['achievements'] ?? null) === null && class_exists(\ITFlow\Training\Upstream\LearnerGateway::class)) {
                $trpdf_t['achievements'] = (new \ITFlow\Training\Upstream\LearnerGateway($mysqli))->awardsForContact($trpdf_id);
            }
            // Who generated it: read fresh from users (prepared), never from the session name.
            $trpdf_by = null;
            $trpdf_st = $mysqli->prepare('SELECT user_name FROM users WHERE user_id = ?');
            $trpdf_uid = (int) $trpdf_ctx->userId;
            $trpdf_st->bind_param('i', $trpdf_uid);
            $trpdf_st->execute();
            $trpdf_by = $trpdf_st->get_result()->fetch_assoc()['user_name'] ?? null;
            $trpdf_st->close();
            $trpdf_seq = null;
            $trpdf_hash = null;
            try {
                $trpdf_head = \ITFlow\Training\Core\Ledger::head($mysqli);
                $trpdf_seq = (int) $trpdf_head['seq'];
                $trpdf_hash = substr((string) $trpdf_head['hash'], 0, 16);
            } catch (\Throwable $e) {
                error_log('Training transcript PDF: no ledger head: ' . get_class($e));
            }
            $trpdf_bytes = TranscriptPdf::render($trpdf_t, Brand::load($mysqli, $trpdf_cert_settings), [
                'generated_by' => $trpdf_by !== null ? (string) $trpdf_by : 'User #' . $trpdf_uid,
                'generated_at_local' => date('Y-m-d H:i T'),
                'ledger_seq' => $trpdf_seq,
                'ledger_hash16' => $trpdf_hash,
            ]);
            if ($trpdf_method === 'GET') {
                logAction('Training', 'Export', 'Downloaded training transcript PDF for contact #' . $trpdf_id, 0, $trpdf_id);
            }
            trpdf_send($trpdf_bytes, 'transcript-' . $trpdf_id . '-' . date('Ymd') . '.pdf', $trpdf_dl, $trpdf_method);

        case 'sample':
            if (!(($session_is_admin ?? false) === true || Access::level() >= 3)) {
                trpdf_not_found();
            }
            $trpdf_bytes = CertificatePdf::render(CertificatePdf::sampleCert(), Brand::load($mysqli, $trpdf_cert_settings), null, $trpdf_lang ?? 'en', true);
            trpdf_send($trpdf_bytes, 'certificate-sample.pdf', $trpdf_dl, $trpdf_method);

        default:
            trpdf_not_found();
    }
} catch (\Throwable $e) {
    error_log('Training PDF (' . $trpdf_doc . '): ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
    }
    echo "The PDF could not be built. Try again.\n";
    exit;
}
