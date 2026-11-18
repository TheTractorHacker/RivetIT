<?php

/*
 * Onboarding packet PDF.
 *
 *   POST /agent/onboarding_packet_pdf.php   (csrf_token, contact_id, packet fields - see PacketRequest)
 *
 * POST only: passwords never travel in a URL. Order: method -> CSRF -> module_credential >= 2 (read-only
 * roles refused) -> strict contact id -> contact + client -> client access -> validate input -> build the
 * PDF in memory -> save ticked lines to Credentials in one transaction -> audit (counts only) -> stream.
 * The PDF is built before anything is saved, so a render failure saves nothing, and a save failure streams
 * nothing. Nothing is written to disk; passwords are never logged or put in the session.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();
// Buffer everything from here so a stray PHP warning can never land in the PDF body or break headers.
ob_start();

use ITFlow\Onboarding\PacketPdf;
use ITFlow\Onboarding\PacketRequest;
use ITFlow\Onboarding\PacketSaver;

function obpk_text(int $code, string $msg): never
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $msg . "\n";
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    obpk_text(405, 'Method not allowed.');
}

validateCSRFToken($_POST['csrf_token'] ?? null);
enforceUserPermission('module_credential', 2);
enforceUserPermission('module_client', 1);

$obpk_raw = $_POST['contact_id'] ?? null;
$contact_id = is_string($obpk_raw) && preg_match('/^[1-9][0-9]{0,9}$/D', $obpk_raw) === 1 && (int) $obpk_raw <= 2147483647 ? (int) $obpk_raw : 0;
if ($contact_id === 0) {
    obpk_text(404, 'Not found.');
}

$obpk_st = $mysqli->prepare('SELECT contact_name, contact_client_id, client_name FROM contacts LEFT JOIN clients ON client_id = contact_client_id WHERE contact_id = ? AND contact_archived_at IS NULL');
$obpk_st->bind_param('i', $contact_id);
$obpk_st->execute();
$obpk_row = $obpk_st->get_result()->fetch_assoc();
$obpk_st->close();
if (!$obpk_row) {
    obpk_text(404, 'Not found.');
}
$client_id = (int) $obpk_row['contact_client_id'];
$person = (string) $obpk_row['contact_name'];
$client_name = (string) ($obpk_row['client_name'] ?? '');

// A department-denied user gets the same plain 404 as an unknown contact (enforceClientAccess would
// redirect with a flash, which would let contact ids be probed). It stays as the backstop.
if (!($session_is_admin ?? false) && $client_id > 0) {
    $obpk_uid = (int) $session_user_id;
    $obpk_ac = $mysqli->prepare('SELECT COUNT(*) AS total, SUM(client_id = ?) AS hit FROM user_client_permissions WHERE user_id = ?');
    $obpk_ac->bind_param('ii', $client_id, $obpk_uid);
    $obpk_ac->execute();
    $obpk_acr = $obpk_ac->get_result()->fetch_assoc();
    $obpk_ac->close();
    if ((int) ($obpk_acr['total'] ?? 0) > 0 && (int) ($obpk_acr['hit'] ?? 0) === 0) {
        obpk_text(404, 'Not found.');
    }
}
enforceClientAccess($client_id);

try {
    $obpk_req = PacketRequest::fromPost($_POST);
} catch (\InvalidArgumentException $e) {
    obpk_text(422, $e->getMessage());
}
try {
    PacketSaver::preflight($obpk_req);
} catch (\InvalidArgumentException $e) {
    obpk_text(422, $e->getMessage());
}

// Company support line and logo (same companies row and filename rule the training certificates use).
$obpk_support = '';
$obpk_logo = null;
try {
    $obpk_c = $mysqli->query('SELECT company_name, company_phone, company_phone_country_code, company_logo FROM companies WHERE company_id = 1');
    $obpk_crow = $obpk_c ? $obpk_c->fetch_assoc() : null;
    if ($obpk_crow) {
        $obpk_parts = array_filter([
            trim((string) $obpk_crow['company_name']),
            trim((string) formatPhoneNumber($obpk_crow['company_phone'], $obpk_crow['company_phone_country_code'])),
            trim((string) ($config_ticket_from_email ?? '')),
        ], static fn($p) => $p !== '');
        $obpk_support = implode(' | ', $obpk_parts);
        $obpk_file = (string) $obpk_crow['company_logo'];
        $obpk_path = dirname(__DIR__) . '/uploads/settings/' . $obpk_file;
        if (preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g)$/D', $obpk_file) === 1 && is_file($obpk_path)) {
            $obpk_bytes = @file_get_contents($obpk_path);
            $obpk_logo = is_string($obpk_bytes) ? $obpk_bytes : null;
        }
    }
} catch (\Throwable $e) {
    error_log('Onboarding packet branding: ' . get_class($e));
}

if (ini_get('memory_limit') !== '-1' && (int) ini_get('memory_limit') < 256) {
    @ini_set('memory_limit', '256M');
}
@set_time_limit(60);

try {
    $obpk_pdf = PacketPdf::render($obpk_req, $person, $client_name, $obpk_support, $obpk_logo);
} catch (\Throwable $e) {
    error_log('Onboarding packet render failed: ' . get_class($e));
    obpk_text(500, 'Could not build the packet.');
}

try {
    $obpk_saved = PacketSaver::save($mysqli, $obpk_req, $client_id, $contact_id);
} catch (\Throwable $e) {
    error_log('Onboarding packet save failed: ' . get_class($e));
    obpk_text(500, 'Could not save the logins, so no packet was produced. Nothing was saved.');
}

// The transaction has committed; audit each saved Login (names only, never passwords).
foreach ($obpk_saved as $obpk_cred) {
    logAction("Credential", "Create", "$session_name created credential " . $obpk_cred['name'] . " (onboarding packet)", $obpk_cred['client_id'], $obpk_cred['id']);
}

logAction('Onboarding Packet', 'Generate', "$session_name generated an onboarding packet for $person (" . count($obpk_req->lines) . " lines, " . count($obpk_saved) . " saved)", $client_id, $contact_id);

$obpk_slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $person), '-');
$obpk_filename = 'Onboarding-' . ($obpk_slug !== '' ? $obpk_slug : 'person') . '-' . date('Y-m-d') . '.pdf';

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/pdf');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $obpk_filename . '"');
header('Content-Length: ' . strlen($obpk_pdf));
echo $obpk_pdf;
exit;
