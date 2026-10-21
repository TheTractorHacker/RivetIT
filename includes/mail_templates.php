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
 * Strips the leading "<!--RIVETIT_BLOCKS:{...}-->" comment (Settings > Email Templates' drag-and-drop builder's own state)
 * before any placeholder substitution ever sees the body - the JSON's own {"..."} braces would otherwise be mangled by the
 * {placeholder} regex, and no real email should ever carry this comment.
 */
const MAIL_TEMPLATE_BLOCKS_RE = '/^<!--RIVETIT_BLOCKS:(.*?)-->\s*/s';

function mailTemplateStripBlocksComment(string $body): string {
    return (string) preg_replace(MAIL_TEMPLATE_BLOCKS_RE, '', $body, 1);
}

/**
 * The block list behind a stored (or default) body, for the admin builder UI. Always returns at least one block: a body with
 * no recognizable comment - a legacy row saved before the builder existed, or anything hand-edited through the "Advanced
 * HTML" escape hatch in a way that does not round-trip - becomes a single 'html' block, so nothing is ever lost or hidden.
 *
 * @return list<array<string,mixed>>
 */
function mailTemplateBlocksFromBody(string $body): array {
    if (preg_match(MAIL_TEMPLATE_BLOCKS_RE, $body, $m) === 1) {
        $decoded = json_decode($m[1], true);
        if (is_array($decoded) && is_array($decoded['blocks'] ?? null)) {
            return $decoded['blocks'];
        }
    }

    return [['type' => 'html', 'html' => mailTemplateStripBlocksComment($body)]];
}

/**
 * A real, styled button, not a bare link - table-wrapped (not just CSS background on the `<a>`) because Outlook desktop
 * renders email HTML with Word's engine, which honours a table cell's background far more reliably than CSS background
 * on an anchor. Mirrored exactly in the JS compiler (admin/settings_mail_templates.php) - keep both in sync.
 *
 * The background colour is the literal token "{mt_accent}", not a baked-in hex: this runs at compile/save time (when a
 * template is saved, or when mailTemplateDefaults() builds the seeded defaults), long before a render knows the
 * company's current theme colour. renderMailTemplate() and the admin preview handler always inject the real resolved
 * hex as a placeholder named mt_accent right before substitution, so every button picks up the company's theme colour
 * at SEND time - change the theme later and every email (including ones already saved) follows, with no re-save needed.
 */
function mailTemplateButtonHtml(string $link, string $label): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0;"><tr><td style="border-radius:6px;background-color:{mt_accent};">'
        . '<a href="' . $link . '" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;border-radius:6px;font-family:inherit;">'
        . $label . '</a></td></tr></table>';
}

/**
 * One block's compiled HTML. Mirrors the JS compiler in admin/settings_mail_templates.php exactly (block type for block
 * type) - kept here only so mailTemplateDefaults() can be generated from mailTemplateDefaultBlocks() instead of two
 * hand-written copies of the same text drifting apart. A block the JS could not have produced (an unknown type, or one a
 * future version adds) degrades to its raw 'html'/'label' text rather than vanishing.
 */
function mailTemplateCompileBlock(array $b): string {
    switch ((string) ($b['type'] ?? '')) {
        case 'reply_marker':
            return "<i style='color: #808080'>##- Please type your reply above this line -##</i>";
        case 'button':
            return mailTemplateButtonHtml((string) ($b['link'] ?? ''), (string) ($b['label'] ?? 'Click here'));
        case 'divider':
            return '<hr style="border:none;border-top:1px solid #e5e7eb;margin:22px 0;">';
        case 'spacer':
            return '<div style="height:' . max(4, min(200, (int) ($b['size'] ?? 16))) . 'px;line-height:1px;font-size:1px;">&nbsp;</div>';
        case 'quote':
            // A shaded, rounded "quoted content" box (a ticket's own details, a reply) instead of plain text fenced in
            // ASCII dashes - the accent-coloured left edge is the literal token "{mt_accent}", resolved at render time
            // the same way a Button's colour is (see mailTemplateButtonHtml()).
            return '<div style="background-color:#f8f9fa;border-left:3px solid {mt_accent};border-radius:6px;padding:16px 18px;margin:18px 0;color:#374151;font-size:14px;line-height:1.6;">'
                . (string) ($b['html'] ?? '') . '</div>';
        default: // 'text', 'html', and anything unrecognized
            return (string) ($b['html'] ?? '');
    }
}

/** @param list<array<string,mixed>> $blocks */
function mailTemplateCompileBlocks(array $blocks): string {
    return implode('<br><br>', array_map('mailTemplateCompileBlock', $blocks));
}

/** Embeds the block list as a leading HTML comment ahead of its own compiled HTML, so re-opening the builder shows the same blocks instead of one opaque blob. */
function mailTemplateSerializeBlocks(array $blocks): string {
    return '<!--RIVETIT_BLOCKS:' . json_encode(['v' => 1, 'blocks' => $blocks]) . '-->' . mailTemplateCompileBlocks($blocks);
}

/**
 * The default block list per event - the single source of truth mailTemplateDefaults() compiles from. Reconstructed from
 * the hardcoded text every call site used to send, verbatim where the ~2-3 historical duplicates of one event agreed, and
 * the fuller/more common variant where they had drifted (e.g. "Ticket Created -" vs "Ticket Created" in the subject; there
 * is no single "the" original to preserve byte-for-byte). A plain inline link became its own Button block (clearer in a
 * drag-and-drop builder than text with a link buried in it) - a deliberate, cosmetic-only change from the old hardcoded
 * wording, never a behavior change: same placeholders, same destination.
 */
function mailTemplateDefaultBlocks(): array {
    $signature = ['type' => 'text', 'html' => '--<br>{company_name} - Support<br>{from_email}<br>{company_phone}'];

    return [
        'ticket_created' => [
            ['type' => 'reply_marker'],
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>A ticket regarding "{subject}" has been created for you.'],
            ['type' => 'quote', 'html' => '{ticket_details}'],
            ['type' => 'text', 'html' => 'Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}'],
            ['type' => 'button', 'label' => 'View ticket', 'link' => '{portal_link}'],
            $signature,
        ],
        'ticket_reply' => [
            ['type' => 'reply_marker'],
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>Your ticket regarding {subject} has been updated.'],
            ['type' => 'quote', 'html' => '{ticket_reply}'],
            ['type' => 'text', 'html' => 'Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}'],
            ['type' => 'button', 'label' => 'View ticket', 'link' => '{portal_link}'],
            $signature,
        ],
        'ticket_resolved' => [
            ['type' => 'reply_marker'],
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>Your ticket regarding {subject} has been resolved and closed.'],
            ['type' => 'quote', 'html' => '{ticket_reply}'],
            ['type' => 'text', 'html' => 'We hope this resolved things to your satisfaction - how would you rate it?<br>{csat_rating_html}'],
            ['type' => 'text', 'html' => "If you need further assistance, please reply or <a href='{portal_link}'>re-open</a> to let us know!"],
            ['type' => 'text', 'html' => 'Ticket: {ticket_number}<br>Subject: {subject}<br>Status: {status}'],
            ['type' => 'button', 'label' => 'View ticket', 'link' => '{portal_link}'],
            $signature,
        ],
        'ticket_closed' => [
            // Deliberately NO reply marker - "(do not reply)" is the point of this one (functions.php's addReply() would otherwise let a reply to it reopen the ticket).
            ['type' => 'text', 'html' => 'Hello {contact_name},<br><br>Your ticket regarding "{subject}" has been closed.'],
            ['type' => 'text', 'html' => 'We hope the request/issue was resolved to your satisfaction - how would you rate it?<br>{csat_rating_html}'],
            ['type' => 'button', 'label' => 'Leave feedback', 'link' => '{portal_link}'],
            ['type' => 'text', 'html' => 'If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email.'],
            ['type' => 'text', 'html' => 'Ticket: {ticket_number}<br>Subject: {subject}'],
            ['type' => 'button', 'label' => 'View ticket', 'link' => '{portal_link}'],
            $signature,
        ],
        'ticket_assigned' => [
            // Sent to the technician the ticket was assigned to, not the client contact - no reply marker, no portal link (an agent link instead).
            ['type' => 'text', 'html' => 'Hi {technician_name},<br><br>A ticket has been assigned to you!'],
            ['type' => 'text', 'html' => 'Department: {client_name}<br>Ticket Number: {ticket_number}<br>Subject: {subject}'],
            ['type' => 'button', 'label' => 'Open ticket', 'link' => '{agent_link}'],
            ['type' => 'text', 'html' => 'Thanks,<br>{assigned_by_name}<br>{company_name}'],
        ],
        'password_reset' => [
            ['type' => 'text', 'html' => "Hello {contact_name},<br><br>Someone (probably you) has requested a new password for your account on {company_name}'s Department Portal."],
            ['type' => 'button', 'label' => 'Reset your password', 'link' => '{portal_link}'],
            ['type' => 'text', 'html' => 'Alternatively, copy and paste this URL into your browser:<br>{portal_link}'],
            ['type' => 'text', 'html' => "<i>If you didn't request this change, you can safely ignore this email.</i>"],
            $signature,
        ],
        'password_changed' => [
            ['type' => 'text', 'html' => "Hello {contact_name},<br><br>Your password for your account on {company_name}'s Department Portal was successfully reset. You should be all set!"],
            ['type' => 'text', 'html' => "<b>If you didn't reset your password, please get in touch ASAP.</b>"],
            $signature,
        ],
    ];
}

/** This is what ships as each event's row on day one, and what "Reset to default" restores - compiled from mailTemplateDefaultBlocks(), one source of truth. */
function mailTemplateDefaults(): array {
    $subjects = [
        'ticket_created' => 'Ticket Created - [{ticket_number}] - {subject}',
        'ticket_reply' => 'Ticket update - [{ticket_number}] - {subject}',
        'ticket_resolved' => 'Ticket resolved - [{ticket_number}] - {subject}',
        'ticket_closed' => 'Ticket closed - [{ticket_number}] - {subject} | (do not reply)',
        'ticket_assigned' => 'Ticket {ticket_number} assigned to you - {subject}',
        'password_reset' => 'Password reset for {company_name} Department Portal',
        'password_changed' => 'Password reset confirmation for {company_name} Department Portal',
    ];
    $out = [];
    foreach (mailTemplateDefaultBlocks() as $event => $blocks) {
        $out[$event] = ['subject' => $subjects[$event], 'body' => mailTemplateSerializeBlocks($blocks)];
    }

    return $out;
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
 * The company's own branding, resolved straight from the database rather than trusted globals, because mail is sent
 * from several different entry points (an authenticated admin page, cron/ticket_email_parser.php, the public
 * client/login_reset.php) and not all of them load includes/load_global_settings.php or load_company_settings.php -
 * this must work the same from every one of them.
 *
 * @return array{accent:string,logo_url:string} accent is '#RRGGBB' (Admin > Appearance's theme, or RivetIT blue when
 *   none is configured); logo_url is the company's uploaded logo as an absolute URL (email clients cannot resolve a
 *   relative one), or '' when there is no logo.
 */
function mailTemplateBranding($mysqli): array {
    require_once __DIR__ . '/theme_accent.php';
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT settings.config_theme, settings.config_theme_accent_custom, companies.company_logo
        FROM settings, companies WHERE companies.company_id = settings.company_id AND companies.company_id = 1"));
    $accent = itflow_theme_accent_hex($row['config_theme'] ?? '', $row['config_theme_accent_custom'] ?? null);
    $logo = (string) ($row['company_logo'] ?? '');
    $base = (string) ($GLOBALS['config_base_url'] ?? '');

    return ['accent' => $accent !== '' ? $accent : '#206bc4', 'logo_url' => $logo !== '' && $base !== '' ? 'https://' . $base . '/uploads/settings/' . rawurlencode($logo) : ''];
}

/**
 * Wraps a compiled template body in a plain, widely-compatible transactional-email shell: a light outer background, a
 * white card with a thin accent stripe in the company's own theme colour, a readable system font and generous padding
 * - so every email this app sends looks like a deliberate, trustworthy piece of mail instead of unstyled text, with no
 * admin action required. Table-based (not flexbox/CSS background on the body) because that is what actually renders
 * consistently across real inboxes, Outlook desktop included. An admin's own header/footer (set under Settings > Email
 * Templates) sits inside this card, above/below the template body and wins outright; failing that, the company's
 * uploaded logo (Admin > Company); failing that, the company name alone as a simple bold header.
 */
function mailTemplateWrapShell(string $bodyHtml, string $companyName, string $customHeaderHtml, string $customFooterHtml, string $accentHex, string $logoUrl): string {
    $header = $customHeaderHtml !== '' ? $customHeaderHtml
        : ($logoUrl !== '' ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '" style="max-height:44px;max-width:220px;display:block;margin-bottom:20px;border:0;">'
        : ($companyName !== '' ? '<div style="font-size:19px;font-weight:700;color:#1f2937;margin-bottom:20px;">' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</div>' : ''));
    $accentHex = preg_match('/^#[0-9A-Fa-f]{6}$/D', $accentHex) === 1 ? $accentHex : '#206bc4';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:32px 16px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" style="max-width:580px;background-color:#ffffff;border-radius:10px;" cellpadding="0" cellspacing="0">'
        . '<tr><td style="height:4px;line-height:4px;font-size:0;background-color:' . $accentHex . ';border-radius:10px 10px 0 0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:32px 36px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;font-size:15px;line-height:1.65;">'
        . $header . $bodyHtml . $customFooterHtml
        . '</td></tr></table>'
        . '</td></tr></table>';
}

/**
 * Strips the builder's blocks comment (never meant to reach a real email), then substitutes {placeholder} tokens: the small
 * fixed MAIL_TEMPLATE_RAW_KEYS set verbatim (already-trusted HTML this app built), everything else HTML-escaped (or, in
 * 'text' mode, control-character-stripped only - a mail header, never HTML). Shared by renderMailTemplate() and the admin
 * preview/test-send handler, so both ever strip/escape/substitute exactly one way.
 *
 * @param array<string,mixed> $vars
 */
function mailTemplateApplyPlaceholders(string $template, array $vars, string $mode = 'html'): string {
    $template = mailTemplateStripBlocksComment($template);
    $escaped = array_diff_key($vars, array_flip(MAIL_TEMPLATE_RAW_KEYS));
    if ($mode === 'html') {
        foreach (array_intersect_key($vars, array_flip(MAIL_TEMPLATE_RAW_KEYS)) as $k => $v) {
            $template = str_replace('{' . $k . '}', (string) $v, $template);
        }
    }

    return \ITFlow\Automation\Template::render($template, array_map('strval', $escaped), $mode);
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

    $branding = mailTemplateBranding($mysqli);
    $vars['mt_accent'] = $branding['accent'];
    $body = mailTemplateApplyPlaceholders($bodyTpl, $vars, 'html');
    $subject = mailTemplateApplyPlaceholders($subjectTpl, $vars, 'text');

    $hf = mailTemplateHeaderFooter($mysqli);
    [$companyName] = array_values(getCompanyNameAndPhone());
    $body = mailTemplateWrapShell($body, $companyName, $hf['header'], $hf['footer'], $branding['accent'], $branding['logo_url']);

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
