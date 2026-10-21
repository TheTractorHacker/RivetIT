<?php
/*
 * RivetIT - Email Templates
 *
 * The seven client/technician-facing email events this app sends (ticket created, reply, resolved, closed, assigned; portal
 * password reset and its confirmation) used to be hardcoded HTML strings duplicated across several call sites in
 * agent/post/ticket.php, functions.php and client/login_reset.php, with small copy/paste drift between the duplicates of the
 * same event. This file is the single place those strings now live (mailTemplateDefaults()) and the single function
 * (renderMailTemplate()) every call site uses to turn an event + its data into a subject/body, reading an admin's edit from the
 * mail_templates table when there is one.
 *
 * Placeholder syntax matches the existing automation-rule merge fields (src/Automation/Template.php): {name}. Most placeholders
 * are HTML-escaped on substitution (a contact's name, a ticket subject - attacker-controlled data a customer can set). A small
 * fixed set is inserted RAW, unescaped, because this app already built it as trusted HTML before the template ever sees it
 * (the ticket's own details/reply body, which a technician authored in the WYSIWYG ticket editor, and the CSAT rating links).
 */

const MAIL_TEMPLATE_RAW_KEYS = ['ticket_details', 'ticket_reply', 'csat_rating_html'];

/** Event key => human label, for the admin list page. Order here is the order they're listed in. */
function mailTemplateEvents(): array {
    return [
        'ticket_created'   => 'Ticket created',
        'ticket_reply'      => 'Ticket reply',
        'ticket_resolved'   => 'Ticket resolved',
        'ticket_closed'     => 'Ticket closed',
        'ticket_assigned'   => 'Ticket assigned (sent to the technician, not the client)',
        'password_reset'    => 'Portal password reset',
        'password_changed'  => 'Portal password reset confirmation',
    ];
}

/** Events whose body must keep the exact reply-above-this-line marker (functions.php addReply() strips everything after it on a reply). */
function mailTemplateRequiresReplyMarker(string $event): bool {
    return in_array($event, ['ticket_created', 'ticket_reply', 'ticket_resolved'], true);
}

/** Events whose body should keep a working portal link (the customer's only way back into the ticket/reset flow without a login). */
function mailTemplateRequiresPortalLink(string $event): bool {
    return in_array($event, ['ticket_created', 'ticket_reply', 'ticket_resolved', 'ticket_closed', 'password_reset'], true);
}

/** The placeholders this event's sample/live context actually fills, for the admin UI's "click to insert" list. */
function mailTemplatePlaceholdersFor(string $event): array {
    $common = ['contact_name', 'ticket_number', 'subject', 'status', 'portal_link', 'company_name', 'company_phone', 'from_email'];
    return match ($event) {
        'ticket_assigned' => ['technician_name', 'ticket_number', 'subject', 'client_name', 'agent_link', 'company_name', 'assigned_by_name'],
        'password_reset', 'password_changed' => ['contact_name', 'company_name', 'company_phone', 'from_email', 'portal_link'],
        default => $common,
    };
}

/**
 * The hardcoded text every call site used to send, verbatim where the ~2-3 historical duplicates of one event agreed, and the
 * fuller/more common variant where they had drifted (e.g. "Ticket Created -" vs "Ticket Created" in the subject; this app
 * always had three near-identical copies of the "created" email with tiny wording differences - there is no single "the"
 * original to preserve byte-for-byte). This is what ships as each event's row on day one, and what "Reset to default" restores.
 */
function mailTemplateDefaults(): array {
    return [
        'ticket_created' => [
            'subject' => 'Ticket Created - [{ticket_number}] - {subject}',
            'body' => "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>Hello {contact_name},<br><br>A ticket regarding \"{subject}\" has been created for you.<br><br>--------------------------------<br>{ticket_details}--------------------------------<br><br>Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}<br>Portal: <a href='{portal_link}'>View ticket</a><br><br>--<br>{company_name} - Support<br>{from_email}<br>{company_phone}",
        ],
        'ticket_reply' => [
            'subject' => 'Ticket update - [{ticket_number}] - {subject}',
            'body' => "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>Hello {contact_name},<br><br>Your ticket regarding {subject} has been updated.<br><br>--------------------------------<br>{ticket_reply}<br>--------------------------------<br><br>Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}<br>Portal: <a href='{portal_link}'>View ticket</a><br><br>--<br>{company_name} - Support<br>{from_email}<br>{company_phone}",
        ],
        'ticket_resolved' => [
            'subject' => 'Ticket resolved - [{ticket_number}] - {subject}',
            'body' => "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>Hello {contact_name},<br><br>Your ticket regarding {subject} has been resolved and closed.<br><br>--------------------------------<br>{ticket_reply}<br>--------------------------------<br><br>We hope this resolved things to your satisfaction - how would you rate it?<br>{csat_rating_html}<br>If you need further assistance, please reply or <a href='{portal_link}'>re-open</a> to let us know! <br><br>Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}<br>Portal: <a href='{portal_link}'>View ticket</a><br><br>--<br>{company_name} - Support<br>{from_email}<br>{company_phone}",
        ],
        'ticket_closed' => [
            // Deliberately NO reply marker - "(do not reply)" is the point of this one (functions.php's addReply() would otherwise let a reply to it reopen the ticket).
            'subject' => 'Ticket closed - [{ticket_number}] - {subject} | (do not reply)',
            'body' => "Hello {contact_name},<br><br>Your ticket regarding \"{subject}\" has been closed. <br><br> We hope the request/issue was resolved to your satisfaction - how would you rate it?<br>{csat_rating_html}<a href='{portal_link}'>Or click here to leave feedback</a>. <br>If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email. <br><br>Ticket: {ticket_number}<br>Subject: {subject}<br>Portal: <a href='{portal_link}'>View ticket</a><br><br>--<br>{company_name} - Support<br>{from_email}<br>{company_phone}",
        ],
        'ticket_assigned' => [
            // Sent to the technician the ticket was assigned to, not the client contact - no reply marker, no portal link (an agent link instead).
            'subject' => 'Ticket {ticket_number} assigned to you - {subject}',
            'body' => 'Hi {technician_name}, <br><br>A ticket has been assigned to you!<br><br>Department: {client_name}<br>Ticket Number: {ticket_number}<br>Subject: {subject}<br><br>{agent_link} <br><br>Thanks, <br>{assigned_by_name}<br>{company_name}',
        ],
        'password_reset' => [
            'subject' => 'Password reset for {company_name} Department Portal',
            'body' => "Hello {contact_name},<br><br>Someone (probably you) has requested a new password for your account on {company_name}'s Department Portal. <br><br><b>Please <a href='{portal_link}'>click here</a> to reset your password.</b> <br><br>Alternatively, copy and paste this URL into your browser:<br> {portal_link}<br><br><i>If you didn't request this change, you can safely ignore this email.</i><br><br>--<br>{company_name} - Support<br>{from_email}<br>{company_phone}",
        ],
        'password_changed' => [
            'subject' => 'Password reset confirmation for {company_name} Department Portal',
            'body' => "Hello {contact_name},<br><br>Your password for your account on {company_name}'s Department Portal was successfully reset. You should be all set! <br><br><b>If you didn't reset your password, please get in touch ASAP.</b><br><br>--<br>{company_name} - Support<br>{from_email}<br>{company_phone}",
        ],
    ];
}

/** Sample data for the admin preview/test-send, matching mailTemplatePlaceholdersFor()'s keys for every event. */
function mailTemplateSampleVars(string $event): array {
    [$cn, $cp] = array_values(getCompanyNameAndPhone());
    $common = [
        'contact_name' => 'Jamie Example', 'ticket_number' => 'T1042', 'subject' => 'Printer on 3rd floor is offline',
        'status' => 'Open', 'portal_link' => 'https://support.example.com/guest/guest_view_ticket.php?ticket_id=1042&url_key=sample',
        'company_name' => $cn ?: 'Acme IT', 'company_phone' => $cp ?: '(555) 555-0100', 'from_email' => 'support@example.com',
        'ticket_details' => 'The printer on the 3rd floor shows a paper jam error, but there is no visible paper stuck.',
        'ticket_reply' => 'I cleared a scrap of paper from the rear tray and ran a test page - it printed fine. Let us know if it happens again!',
        'csat_rating_html' => '<a href="#">Great</a> | <a href="#">OK</a> | <a href="#">Poor</a>',
    ];
    return match ($event) {
        'ticket_assigned' => ['technician_name' => 'Sam Technician', 'ticket_number' => 'T1042', 'subject' => $common['subject'],
            'client_name' => 'Acme Corp', 'agent_link' => 'https://support.example.com/agent/ticket.php?ticket_id=1042', 'company_name' => $common['company_name'], 'assigned_by_name' => 'Alex Admin'],
        'password_reset', 'password_changed' => ['contact_name' => $common['contact_name'], 'company_name' => $common['company_name'],
            'company_phone' => $common['company_phone'], 'from_email' => $common['from_email'], 'portal_link' => 'https://support.example.com/client/login_reset.php?email=jamie%40example.com&token=sample&client=1'],
        default => $common,
    };
}

/** The stored row for one event, or null when it has never been customized (the caller falls back to mailTemplateDefaults()). */
function mailTemplateRow($mysqli, string $event): ?array {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mail_templates WHERE mail_template_event = '" . mysqli_real_escape_string($mysqli, $event) . "' LIMIT 1"));

    return $row ?: null;
}

/** @return array{header:string,footer:string} the optional shared header/logo and footer block, same for every template. */
function mailTemplateHeaderFooter($mysqli): array {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_template_header_html, config_mail_template_footer_html FROM settings WHERE company_id = 1"));

    return ['header' => (string) ($row['config_mail_template_header_html'] ?? ''), 'footer' => (string) ($row['config_mail_template_footer_html'] ?? '')];
}

/**
 * Render one mail event. $vars is plain strings keyed by placeholder name (see mailTemplatePlaceholdersFor()); a key this
 * event's defaults don't use is simply ignored, and a placeholder in the template with no matching key renders as '' (the
 * same "unknown placeholders are dropped, never shown raw" rule src/Automation/Template.php already uses for automation text).
 *
 * @return array{subject:string,body:string}|null null when the event is disabled - the caller must then queue nothing for it.
 */
function renderMailTemplate($mysqli, string $event, array $vars): ?array {
    $row = mailTemplateRow($mysqli, $event);
    $defaults = mailTemplateDefaults()[$event] ?? ['subject' => '', 'body' => ''];
    if ($row !== null && (int) $row['mail_template_enabled'] === 0) {
        return null;
    }
    $subjectTpl = $row['mail_template_subject'] ?? $defaults['subject'];
    $bodyTpl = $row['mail_template_body'] ?? $defaults['body'];

    $raw = array_intersect_key($vars, array_flip(MAIL_TEMPLATE_RAW_KEYS));
    $escaped = array_diff_key($vars, array_flip(MAIL_TEMPLATE_RAW_KEYS));

    $body = $bodyTpl;
    foreach ($raw as $k => $v) {
        $body = str_replace('{' . $k . '}', (string) $v, $body);
    }
    $body = \ITFlow\Automation\Template::render($body, array_map('strval', $escaped), 'html');
    $subject = \ITFlow\Automation\Template::render($subjectTpl, array_map('strval', $escaped), 'text');

    $hf = mailTemplateHeaderFooter($mysqli);
    if ($hf['header'] !== '' || $hf['footer'] !== '') {
        $body = $hf['header'] . $body . $hf['footer'];
    }

    return ['subject' => $subject, 'body' => $body];
}

/** Reset one event's stored row back to mailTemplateDefaults(). False only when the event key or the row itself is unknown. */
function mailTemplateResetToDefault($mysqli, string $event, int $userId): bool {
    $defaults = mailTemplateDefaults();
    if (!isset($defaults[$event])) {
        return false;
    }
    $e = mysqli_real_escape_string($mysqli, $event);
    $s = mysqli_real_escape_string($mysqli, $defaults[$event]['subject']);
    $b = mysqli_real_escape_string($mysqli, $defaults[$event]['body']);

    return (bool) mysqli_query($mysqli, "UPDATE mail_templates SET mail_template_subject = '$s', mail_template_body = '$b', mail_template_enabled = 1, mail_template_updated_at = NOW(), mail_template_updated_by = " . (int) $userId . " WHERE mail_template_event = '$e'");
}

/** Whether $body still carries the exact tag-wrapped marker functions.php's addReply() regex requires to strip a quoted reply. */
function mailTemplateHasReplyMarker(string $body): bool {
    return (bool) preg_match('/<i[^>]*>\s*##-\s*Please\s+type\s+your\s+reply\s+above\s+this\s+line\s*-##\s*<\/i>/i', $body);
}

/** Whether $body still contains a {portal_link} placeholder at all (the editor's other save-time warning). */
function mailTemplateHasPortalLink(string $body): bool {
    return str_contains($body, '{portal_link}');
}
