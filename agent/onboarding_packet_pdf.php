<?php

/*
 * Onboarding packet PDF.
 *
 *   POST /agent/onboarding_packet_pdf.php   (csrf_token, packet fields - see PacketRequest, and ONE of:)
 *     contact mode: contact_id                    - the person is that contact; Logins are linked to them.
 *     ticket mode:  ticket_id, person_name        - a new hire with no contact yet (the ticket's contact is usually
 *                   [attach_ticket, attach_passwords]   the manager). Department = the ticket's; Logins get no contact
 *                                                  and are named "<person> - <line>". Any contact_id is ignored.
 *
 * POST only: passwords never travel in a URL. Order: method -> CSRF -> module_credential >= 2 (read-only roles
 * refused) -> mode + strict ids -> ticket permissions -> context from DB rows (department 404) -> client access ->
 * validate input -> attach checks -> build the PDF in memory (and the ticket copy, passwords masked unless
 * attach_passwords=1) -> ONE transaction: ticked Logins + the ticket attachment file and row -> audit (counts and
 * names only) -> stream. Everything is built before anything is saved, so a render failure saves nothing, and a
 * save or attach failure rolls back, removes the file and streams nothing. The download is never written to disk;
 * passwords are never logged or put in the session. The department never comes from the request.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();
// Buffer everything from here so a stray PHP warning can never land in the PDF body or break headers.
ob_start();

use ITFlow\Onboarding\PacketContext;
use ITFlow\Onboarding\PacketPdf;
use ITFlow\Onboarding\PacketRequest;
use ITFlow\Onboarding\PacketSaver;
use ITFlow\Onboarding\PacketTicketAttachment;

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

/** A strictly positive int id from POST; 0 when absent or empty; null when present but malformed. */
function obpk_id(string $key): ?int
{
    $raw = $_POST[$key] ?? null;
    if ($raw === null || $raw === '') {
        return 0;
    }
    return is_string($raw) && preg_match('/^[1-9][0-9]{0,9}$/D', $raw) === 1 && (int) $raw <= 2147483647 ? (int) $raw : null;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    obpk_text(405, 'Method not allowed.');
}

validateCSRFToken($_POST['csrf_token'] ?? null);
enforceUserPermission('module_credential', 2);
enforceUserPermission('module_client', 1);

// Exactly one mode. Ticket mode wins and ignores any posted contact_id (the ticket's contact is the manager, not the
// new hire). The department is always read from the ticket or contact row, never from the request.
$obpk_ticket_id = obpk_id('ticket_id');
$contact_id = $obpk_ticket_id === 0 ? obpk_id('contact_id') : 0;
if ($obpk_ticket_id === null || $contact_id === null || ($obpk_ticket_id === 0 && $contact_id === 0)) {
    obpk_text(404, 'Not found.');
}
$obpk_ticket_mode = $obpk_ticket_id > 0;

// Attaching only exists in ticket mode, and the unmasked copy is an explicit opt-in that means nothing without it:
// attach_passwords=1 without attach_ticket=1 is ignored.
$obpk_attach = ($_POST['attach_ticket'] ?? null) === '1';
if ($obpk_attach && !$obpk_ticket_mode) {
    obpk_text(422, 'There is no ticket to attach the packet to.');
}
// Passwords are never put in the copy attached to a ticket (portal contacts can open ticket attachments): owner decision 2026-11-19.
$obpk_attach_pw = false;

if ($obpk_ticket_mode) {
    enforceUserPermission('module_support', 1);
}
if ($obpk_attach) {
    // Same permission agent/post/ticket.php's upload_ticket_attachment requires.
    enforceUserPermission('module_support', 2);
}

// A department-denied user gets the same plain 404 as an unknown id (enforceClientAccess would redirect with a
// flash, which would let ids be probed). It stays as the backstop.
$obpk_ctx = PacketContext::resolve($mysqli, $contact_id, $obpk_ticket_id, (int) $session_user_id, (bool) ($session_is_admin ?? false));
if ($obpk_ctx === null) {
    obpk_text(404, 'Not found.');
}
$client_id = $obpk_ctx->clientId;
$contact_id = $obpk_ctx->contactId; // 0 in ticket mode: Logins are never linked to the ticket's contact
$client_name = $obpk_ctx->clientName;
$obpk_ticket = $obpk_ctx->ticket;
enforceClientAccess($client_id);

// The department can be changed in the form (the packet heading and the department the Logins are saved under). It must be a real, active,
// non-lead department the user may access; a Login saved under a different department is never linked to the original contact.
$obpk_dept = obpk_id('department_id');
if ($obpk_dept === null) {
    obpk_text(422, 'Department is not valid.');
}
if ($obpk_dept > 0 && $obpk_dept !== $client_id) {
    $obpk_dst = $mysqli->prepare('SELECT client_name FROM clients WHERE client_id = ? AND client_lead = 0 AND client_archived_at IS NULL');
    $obpk_dst->bind_param('i', $obpk_dept);
    $obpk_dst->execute();
    $obpk_drow = $obpk_dst->get_result()->fetch_assoc();
    $obpk_dst->close();
    if (!$obpk_drow || PacketContext::departmentDenied($mysqli, $obpk_dept, (int) $session_user_id, (bool) ($session_is_admin ?? false))) {
        obpk_text(422, 'Choose a department you have access to.');
    }
    enforceClientAccess($obpk_dept);
    $client_id = $obpk_dept;
    $client_name = (string) $obpk_drow['client_name'];
    $contact_id = 0;
}

if ($obpk_attach) {
    // Same-department by construction (ticket mode takes the department from this ticket); checked anyway so a
    // later change can never attach one department's packet to another department's ticket.
    if ($obpk_ticket === null || $obpk_ticket['client_id'] !== $client_id) {
        obpk_text(422, "The packet can only be attached to a ticket in the ticket's own department. Untick \"Attach a copy\" or keep the ticket's department.");
    }
    enforceClientAccess($obpk_ticket['client_id']);
    // The ticket page offers attachments only while the ticket is open.
    if ($obpk_ticket['closed']) {
        obpk_text(422, 'That ticket is closed. Reopen it to attach the packet.');
    }
}

try {
    $obpk_req = PacketRequest::fromPost($_POST);
    // Contact mode: the contact's name (a posted person_name is ignored). Ticket mode: the typed, validated name.
    $person = $obpk_ctx->personFromContact ? $obpk_ctx->personName : PacketRequest::personName($_POST['person_name'] ?? '');
} catch (\InvalidArgumentException $e) {
    obpk_text(422, $e->getMessage());
}
// Plain text for audit lines.
$obpk_person_log = trim(strip_tags($person));
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

// Company colour scheme (Administration > Settings > Appearance accent) and the editable wording (Administration > Templates > Onboarding packet).
require_once dirname(__DIR__) . '/includes/theme_accent.php';
$obpk_accent = itflow_theme_accent_hex($config_theme ?? '', $config_theme_accent_custom ?? null);
$obpk_accent = $obpk_accent !== '' ? $obpk_accent : null;
$obpk_tpl = \ITFlow\Onboarding\PacketTemplate::load($mysqli);
// The help box shows the IT department's own contact details when they are set (Administration > Settings > IT department details); otherwise the company's.
$obpk_it_line = \ITFlow\Company\ItDepartment::contactLine(\ITFlow\Company\ItDepartment::load($mysqli));
if ($obpk_it_line !== '') {
    $obpk_support = $obpk_it_line;
}

try {
    $obpk_pdf = PacketPdf::render($obpk_req, $person, $client_name, $obpk_support, $obpk_logo, true, false, $obpk_accent, $obpk_tpl);
    $obpk_att_pdf = null;
    if ($obpk_attach) {
        // The ticket copy: passwords masked unless explicitly opted in. The download always has the real passwords.
        $obpk_att_pdf = $obpk_attach_pw ? $obpk_pdf : PacketPdf::render($obpk_req, $person, $client_name, $obpk_support, $obpk_logo, true, true, $obpk_accent, $obpk_tpl);
    }
} catch (\Throwable $e) {
    error_log('Onboarding packet render failed: ' . get_class($e));
    obpk_text(500, 'Could not build the packet.');
}

// The ticket attachment is written and recorded INSIDE the Logins transaction: if anything fails (the file, its
// row, a Login, the commit) the transaction rolls back and the file is removed, so nothing is left behind.
$obpk_att_path = null;
$obpk_att_id = 0;
$obpk_in_tx = null;
if ($obpk_att_pdf !== null) {
    $obpk_att_name = PacketTicketAttachment::displayName($person);
    $obpk_att_ticket = $obpk_ticket['id'];
    $obpk_in_tx = static function (\mysqli $db) use ($obpk_att_ticket, $obpk_att_name, $obpk_att_pdf, &$obpk_att_path, &$obpk_att_id): void {
        $obpk_att_id = PacketTicketAttachment::store($db, dirname(__DIR__) . '/uploads', $obpk_att_ticket, $obpk_att_name, $obpk_att_pdf, $obpk_att_path);
    };
}

try {
    $obpk_committed = false;
    $obpk_saved = PacketSaver::save($mysqli, $obpk_req, $client_id, $contact_id, $obpk_in_tx, $obpk_ticket_mode ? $person : '', $obpk_committed);
} catch (\Throwable $e) {
    if ($obpk_committed) {
        // COMMIT already succeeded: the Logins and the attachment row are real, so keep the file and still hand over the PDF.
        error_log('Onboarding packet post-commit failure: ' . get_class($e));
        $obpk_saved = [];
    } else {
        // Rolled back: no Logins and no attachment row. Remove the attachment file if one was written.
        if ($obpk_att_path !== null && is_file($obpk_att_path)) {
            @unlink($obpk_att_path);
        }
        error_log('Onboarding packet save failed: ' . get_class($e));
        obpk_text(500, ($obpk_attach ? 'Could not save the logins or attach the packet to the ticket' : 'Could not save the logins') . ', so no packet was produced. Nothing was saved.');
    }
}

// The transaction has committed; audit each saved Login (names only, never passwords).
foreach ($obpk_saved as $obpk_cred) {
    logAction("Credential", "Create", "$session_name created credential " . $obpk_cred['name'] . " (onboarding packet)", $obpk_cred['client_id'], $obpk_cred['id']);
}

$obpk_ticket_ref = $obpk_ticket !== null ? $obpk_ticket['prefix'] . $obpk_ticket['number'] : '';
if ($obpk_att_id > 0) {
    $obpk_copy_label = $obpk_attach_pw ? 'passwords included in the attached copy' : 'passwords hidden in the attached copy';
    logAction('Ticket', 'Attachment', "$session_name attached an onboarding packet for $obpk_person_log to ticket $obpk_ticket_ref ($obpk_copy_label)", $obpk_ticket['client_id'], $obpk_ticket['id']);
}

logAction('Onboarding Packet', 'Generate', "$session_name generated an onboarding packet for $obpk_person_log" . ($obpk_ticket_ref !== '' ? " from ticket $obpk_ticket_ref" : '') . " (" . count($obpk_req->lines) . " lines, " . count($obpk_saved) . " saved)", $client_id, $contact_id);

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
