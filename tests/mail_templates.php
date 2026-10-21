<?php
/*
 * Email Templates (includes/mail_templates.php): the ticket created/reply/resolved/closed/assigned and portal password
 * reset/confirmation emails, previously hardcoded HTML strings duplicated across several call sites. Covers default
 * rendering, placeholder escaping vs. the small trusted-HTML RAW_KEYS set, the reply-marker and portal-link requirements,
 * the enabled switch, custom edits, "Reset to default", and the shared header/footer.
 * Needs a THROWAWAY database that holds this app's schema (import db.sql and run the database updates); it never reads
 * config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mail_templates.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$mysqli->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $mysqli;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/mail_templates.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$mysqli->query("UPDATE settings SET config_mail_template_header_html = NULL, config_mail_template_footer_html = NULL WHERE company_id = 1");
$mysqli->query("DELETE FROM mail_templates");
foreach (mailTemplateDefaults() as $event => $tpl) {
    $mysqli->query("INSERT INTO mail_templates (mail_template_event, mail_template_subject, mail_template_body, mail_template_enabled, mail_template_updated_at)
        VALUES ('" . $mysqli->real_escape_string($event) . "', '" . $mysqli->real_escape_string($tpl['subject']) . "', '" . $mysqli->real_escape_string($tpl['body']) . "', 1, NOW())");
}

$vars = [
    'contact_name' => "O'Brien <script>", 'ticket_number' => 'RMM123', 'subject' => 'Printer & "scanner" broken',
    'status' => 'Open', 'portal_link' => 'https://support.example.com/guest/guest_view_ticket.php?ticket_id=9&url_key=abc',
    'company_name' => 'Acme IT', 'company_phone' => '555-1234', 'from_email' => 'support@acme.test',
    'ticket_details' => '<p>Trusted HTML the technician wrote.</p>', 'ticket_reply' => '<p>A reply, also trusted HTML.</p>',
    'csat_rating_html' => '<a href="#">Great</a>',
];

// 1. All seven events render with the sample vars, every one non-null (enabled by default)
foreach (array_keys(mailTemplateEvents()) as $event) {
    $r = renderMailTemplate($mysqli, $event, $vars);
    $ok($r !== null, "$event renders (enabled by default)");
    $ok($r !== null && $r['subject'] !== '' && $r['body'] !== '', "$event has a non-empty subject and body");
}

// 2. Reply marker: present on the three that need it, absent on ticket_closed (do-not-reply)
foreach (['ticket_created', 'ticket_reply', 'ticket_resolved'] as $event) {
    $r = renderMailTemplate($mysqli, $event, $vars);
    $ok(mailTemplateHasReplyMarker($r['body']), "$event keeps the reply marker");
}
$rClosed = renderMailTemplate($mysqli, 'ticket_closed', $vars);
$ok(!mailTemplateHasReplyMarker($rClosed['body']), 'ticket_closed has no reply marker (by design)');

// 3. Escaping: attacker-controlled fields are HTML-escaped; already-trusted HTML (RAW_KEYS) is not re-escaped
$rCreated = renderMailTemplate($mysqli, 'ticket_created', $vars);
$ok(str_contains($rCreated['body'], 'O&#039;Brien &lt;script&gt;'), 'contact_name is HTML-escaped (XSS-safe)');
$ok(str_contains($rCreated['body'], '<p>Trusted HTML the technician wrote.</p>'), 'ticket_details (a RAW key) is inserted verbatim');
$ok(!str_contains($rCreated['subject'], '<') , 'subject never carries HTML even when inputs do (text mode)');

// 4. Unknown/missing placeholders drop to empty, never render the literal {token}
$rPartial = renderMailTemplate($mysqli, 'password_reset', ['contact_name' => 'Jamie']);
$ok($rPartial !== null && !str_contains($rPartial['body'], '{company_name}') && !str_contains($rPartial['body'], '{portal_link}'), 'missing placeholders render blank, never as raw {token} text');

// 5. The enabled switch actually suppresses rendering (the caller then sends nothing for that event)
$mysqli->query("UPDATE mail_templates SET mail_template_enabled = 0 WHERE mail_template_event = 'ticket_assigned'");
$ok(renderMailTemplate($mysqli, 'ticket_assigned', $vars) === null, 'a disabled template renders null');
$mysqli->query("UPDATE mail_templates SET mail_template_enabled = 1 WHERE mail_template_event = 'ticket_assigned'");

// 6. A custom edit is used, and Reset to default restores the exact canonical text
$mysqli->query("UPDATE mail_templates SET mail_template_subject = 'CUSTOM {ticket_number}', mail_template_body = 'CUSTOM {contact_name}' WHERE mail_template_event = 'ticket_reply'");
$rCustom = renderMailTemplate($mysqli, 'ticket_reply', $vars);
$ok($rCustom['subject'] === 'CUSTOM RMM123', 'a saved custom edit is used over the default');
mailTemplateResetToDefault($mysqli, 'ticket_reply', 1);
$rReset = renderMailTemplate($mysqli, 'ticket_reply', $vars);
$ok(str_contains($rReset['body'], 'Your ticket regarding Printer &amp;'), 'Reset to default restores the exact canonical default text');

// 7. Shared header/footer wraps every template
$mysqli->query("UPDATE settings SET config_mail_template_header_html = '<div id=\"h\">H</div>', config_mail_template_footer_html = '<div id=\"f\">F</div>' WHERE company_id = 1");
$rHf = renderMailTemplate($mysqli, 'ticket_created', $vars);
$ok(str_starts_with($rHf['body'], '<div id="h">H</div>') && str_ends_with($rHf['body'], '<div id="f">F</div>'), 'shared header/footer wraps the body');
$mysqli->query("UPDATE settings SET config_mail_template_header_html = NULL, config_mail_template_footer_html = NULL WHERE company_id = 1");

// 8. Hostile input: a made-up placeholder name in an admin-edited body is never treated as raw
$mysqli->query("UPDATE mail_templates SET mail_template_body = 'Hi {contact_name} - {not_a_real_raw_key}' WHERE mail_template_event = 'ticket_created'");
$rHostile = renderMailTemplate($mysqli, 'ticket_created', ['contact_name' => '<b>x</b>', 'not_a_real_raw_key' => '<script>alert(1)</script>']);
$ok(str_contains($rHostile['body'], '&lt;b&gt;x&lt;/b&gt;') && str_contains($rHostile['body'], '&lt;script&gt;'), 'only the fixed RAW_KEYS list skips HTML-escaping, never an admin-invented name');
mailTemplateResetToDefault($mysqli, 'ticket_created', 1);

// 10. Block builder: every default is now blocks-comment-prefixed, decompiles to the real block list (not a legacy single
// 'html' fallback), and the comment never leaks into what actually gets rendered as the email.
foreach (array_keys(mailTemplateEvents()) as $event) {
    $defaultBody = mailTemplateDefaults()[$event]['body'];
    $ok(str_starts_with($defaultBody, '<!--RIVETIT_BLOCKS:'), "$event's default body carries the blocks comment");
    $decompiled = mailTemplateBlocksFromBody($defaultBody);
    $ok(count($decompiled) > 1, "$event decompiles to its real multi-block list, not a single html fallback");
    $rendered = renderMailTemplate($mysqli, $event, $vars);
    $ok(!str_contains($rendered['body'], 'RIVETIT_BLOCKS'), "$event's rendered body never leaks the blocks comment into the real email");
}

// 11. A legacy/custom body with no comment (pre-builder, or hand-typed through "Advanced HTML") decompiles to one html block - nothing lost
$ok(count(mailTemplateBlocksFromBody('Hello, plain legacy body.')) === 1 && mailTemplateBlocksFromBody('Hello, plain legacy body.')[0]['type'] === 'html', 'a body with no blocks comment falls back to a single html block');

// 12. compileBlocks/stripBlocksComment round-trip
$roundtripBlocks = [['type' => 'text', 'html' => 'Hi {contact_name}'], ['type' => 'button', 'label' => 'Go', 'link' => '{portal_link}']];
$serialized = mailTemplateSerializeBlocks($roundtripBlocks);
$ok(mailTemplateBlocksFromBody($serialized) === $roundtripBlocks, 'serialize -> decompile round-trips to the exact same block list');
$ok(mailTemplateStripBlocksComment($serialized) === mailTemplateCompileBlocks($roundtripBlocks), 'stripping the comment leaves exactly the compiled HTML, nothing more or less');

// 9. mailTemplateRequiresReplyMarker()/mailTemplateRequiresPortalLink() agree with the actual default text's shape
$ok(mailTemplateRequiresReplyMarker('ticket_reply') && !mailTemplateRequiresReplyMarker('ticket_assigned'), 'reply-marker requirement matches which events are client-replyable');
$ok(mailTemplateRequiresPortalLink('ticket_created') && !mailTemplateRequiresPortalLink('ticket_assigned'), 'portal-link requirement matches which events have a portal link at all');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
