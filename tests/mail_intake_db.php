<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Mail intake reliability against the REAL code on a scratch database (no mailbox needed):
 *   migration 2.6.152 (idempotent), MailSettings, IntakeStore (dedupe, threading by inbound AND outbound Message-ID, poison counter),
 *   InboundRouter, the real processInboundMessage()/addTicket()/addReply() glue fed with the .eml fixtures, addToMailQueue(),
 *   MailQueuePolicy (reaper, back-off, send budget), MailHealth alerts (de-duplicated), and the real cron/mail_queue.php run
 *   against a local SMTP sink (outbound headers) and against a dead port (failure -> attempts/back-off).
 *
 * SCRATCH DATABASE ONLY (same refusal rules as tests/kiosk_fleet_links.php); config.php must point at the same database:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch php tests/mail_intake_db.php
 * Needs python3 for the SMTP sink (tests/fixtures/mail/smtp_sink.py).
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
$cfgText = @file_get_contents("$root/config.php");
if (!$cfgText || !preg_match('/\$database\s*=\s*[\'"]' . preg_quote(getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText)) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'mail-intake-test';
chdir("$root/cron"); // the cron scripts use ../relative paths
require_once "$root/plugins/vendor/autoload.php";
require_once "$root/config.php";
require_once "$root/includes/inc_set_timezone.php";
require_once "$root/functions.php";
require_once "$root/includes/load_global_settings.php";
$db = $mysqli;
if (!preg_match('/scratch/i', (string) $db->query('SELECT DATABASE()')->fetch_row()[0])) { fwrite(STDERR, "Refusing: connected database is not a scratch one\n"); exit(2); }

$db->query("SET SESSION sql_mode=''");
use ITFlow\Mail\{AutoReplyDetector, InboundRouter, InboundPreparer, IntakeStore, MailHealth, MailQueuePolicy, MailSettings, MessageId, MessageNormalizer, QuotedTextStripper};

$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn (string $sql) => $db->query($sql);
$one = fn (string $sql) => $db->query($sql)->fetch_row()[0] ?? null;
$load = fn (string $f) => MessageNormalizer::fromWebklex(\Webklex\PHPIMAP\Message::fromString(file_get_contents("$root/tests/fixtures/mail/$f.eml")));

// ---- clean slate + fixtures -------------------------------------------------------------------------------------------
foreach (['tickets', 'ticket_replies', 'ticket_attachments', 'ticket_watchers', 'mail_requests', 'mail_request_attachments', 'mail_log', 'mail_intake_state',
          'mail_intake_settings', 'mail_alerts', 'email_queue', 'mailboxes', 'contacts', 'clients', 'domains', 'notifications', 'users', 'user_roles', 'ticket_statuses', 'companies', 'app_logs'] as $t) {
    $q("DELETE FROM `$t`");
}
$q("INSERT INTO companies SET company_id = 1, company_name = 'Scratch MSP', company_phone = '555', company_locale = 'en_US', company_currency = 'USD'");
$q("INSERT INTO clients SET client_id = 1, client_name = 'Acme'");
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'Jane Roe', contact_email = 'jane@client.example', contact_client_id = 1");
$q("INSERT INTO user_roles SET role_id = 1, role_name = 'Admin', role_is_admin = 1");
$q("INSERT INTO users SET user_id = 1, user_name = 'Boss', user_email = 'boss@msp.example', user_role_id = 1, user_type = 1, user_status = 1, user_password = 'x'");
foreach ([[1, 'New'], [2, 'Open'], [3, 'On Hold'], [4, 'Resolved'], [5, 'Closed']] as [$id, $name]) {
    $q("INSERT INTO ticket_statuses SET ticket_status_id = $id, ticket_status_name = '$name', ticket_status_color = '#000'");
}
$q("INSERT INTO mailboxes SET mailbox_id = 1, mailbox_name = 'Support', mailbox_email = 'support@msp.example', mailbox_type = 'standard_imap', mailbox_active = 1, mailbox_parse_unknown_senders = 1, mailbox_created_at = NOW() - INTERVAL 2 DAY");
$q("UPDATE settings SET config_ticket_prefix = 'TCK-', config_ticket_next_number = 100, config_ticket_from_email = 'support@msp.example', config_ticket_from_name = 'MSP Support',
    config_ticket_client_general_notifications = 1, config_ticket_new_ticket_notification_email = '', config_mail_from_email = 'support@msp.example', config_mail_from_name = 'MSP', config_enable_cron = 1,
    config_ticket_email_parse = 1 WHERE company_id = 1");
$q("INSERT INTO tickets SET ticket_id = 42, ticket_prefix = 'TCK-', ticket_number = 42, ticket_subject = 'Printer offline', ticket_details = 'x', ticket_status = 2, ticket_client_id = 1, ticket_contact_id = 1, ticket_priority = 'Low', ticket_url_key = 'k', ticket_source = 'Email', ticket_created_at = NOW()");
$GLOBALS['config_ticket_prefix'] = 'TCK-';
$config_ticket_prefix = 'TCK-'; $config_ticket_from_email = 'support@msp.example'; $config_ticket_from_name = 'MSP Support';
$config_ticket_client_general_notifications = 1; $config_ticket_new_ticket_notification_email = ''; $config_ticket_default_billable = 0; $config_base_url = 'scratch.invalid'; $config_app_name = 'RivetIT';
$session_name = 'Test'; $session_user_id = 1; $session_ip = '127.0.0.1'; $session_user_agent = 'test';
MailSettings::flush();

$store = new IntakeStore($db);

// ---- migration ---------------------------------------------------------------------------------------------------------
$col = fn (string $t, string $c) => (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c'");
$idx = fn (string $t, string $i) => (int) $one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '$t' AND index_name = '$i'");
$ok($col('tickets', 'ticket_mail_message_id') && $idx('tickets', 'idx_ticket_mail_message_id') && $col('ticket_replies', 'ticket_reply_mail_message_id') && $idx('ticket_replies', 'idx_ticket_reply_mail_message_id'), 'schema: tickets / ticket_replies message-id columns + indexes');
$ok($col('mailboxes', 'mailbox_last_success_at') && $col('mailboxes', 'mailbox_consecutive_failures') && $col('mailboxes', 'mailbox_last_error') && $col('email_queue', 'email_message_id') && $col('email_queue', 'email_started_at') && $idx('email_queue', 'idx_email_message_id'), 'schema: mailbox health + email_queue columns');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('mail_intake_state','mail_intake_settings','mail_alerts')") === 3, 'schema: intake tables exist');

$src = file_get_contents("$root/admin/database_updates.php");
$block = substr($src, strpos($src, "if (\$rivetit_db_version() == '2.6.151')"));
$ok(strpos($block, "== '2.6.151'") !== false && strpos($block, "'2.6.152'") !== false, 'migration block is gated on 2.6.151 and sets 2.6.152');
$q("UPDATE settings SET config_current_database_version = '2.6.151' WHERE company_id = 1");
$rivetit_db_version = static function () use ($mysqli): string { return (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings WHERE company_id=1"))[0]; };
$before = (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()");
eval($block); // second run over an already-migrated schema must be a no-op
$ok($rivetit_db_version() === '2.6.152' && (int) $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()") === $before, 'migration is idempotent (re-run changes nothing, ends at 2.6.152)');
$ok(trim(substr(file_get_contents("$root/includes/database_version.php"), -40)) !== '' && strpos(file_get_contents("$root/includes/database_version.php"), '"2.6.152"') !== false, 'LATEST_DATABASE_VERSION is 2.6.152');

// ---- MailSettings ----------------------------------------------------------------------------------------------------
$ok(MailSettings::int($db, 'rate_cap_per_hour') === 20 && MailSettings::int($db, 'poison_max_attempts') === 3 && MailSettings::int($db, 'max_attachment_mb') === 25
    && MailSettings::int($db, 'max_message_attachments_mb') === 50 && MailSettings::int($db, 'inline_cid_max_kb') === 1024 && MailSettings::int($db, 'outbound_rate_per_min') === 120, 'settings: documented defaults (20/h, 3 attempts, 25/50 MB, 1 MB inline, 120/min)');
MailSettings::set($db, 'rate_cap_per_hour', '5');
$ok(MailSettings::int($db, 'rate_cap_per_hour') === 5, 'settings: stored value wins over default');
$ok(MailSettings::set($db, 'poison_max_attempts', '9999') === '50', 'settings: out-of-range number is clamped');
$threw = false; try { MailSettings::set($db, 'rate_cap_per_hour', 'abc'); } catch (\InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'settings: non-numeric value rejected');
$threw = false; try { MailSettings::set($db, 'nonsense', '1'); } catch (\InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'settings: unknown key rejected');
$threw = false; try { MailSettings::set($db, 'alert_email', 'not-an-email'); } catch (\InvalidArgumentException $e) { $threw = true; }
$ok($threw, 'settings: bad alert email rejected');
MailSettings::set($db, 'rate_cap_per_hour', '20'); MailSettings::set($db, 'poison_max_attempts', '3');

// ---- IntakeStore: dedupe + threading ---------------------------------------------------------------------------------
$q("INSERT INTO ticket_replies SET ticket_reply = 'r', ticket_reply_type = 'Client', ticket_reply_by = 1, ticket_reply_ticket_id = 42, ticket_reply_mail_message_id = 'reply-77@client.example'");
$reply_row_id = (int) $db->insert_id;
$ok($store->isImported('<Reply-77@Client.Example>') && !$store->isImported('<other@x>') && !$store->isImported(''), 'dedupe: stored reply Message-ID found (case/brackets ignored)');
$q("INSERT INTO tickets SET ticket_id = 43, ticket_prefix = 'TCK-', ticket_number = 43, ticket_subject = 's', ticket_details = 'x', ticket_status = 1, ticket_client_id = 1, ticket_contact_id = 1, ticket_priority = 'Low', ticket_url_key = 'k2', ticket_mail_message_id = 'orig-43@client.example'");
$ok($store->isImported('orig-43@client.example'), 'dedupe: stored ticket Message-ID found');
$q("INSERT INTO mail_requests SET mail_request_mailbox_id = 1, mail_request_from_email = 'a@b.example', mail_request_received_at = NOW(), mail_request_message_id = 'req-1@b.example', mail_request_archived_at = NOW()");
$ok($store->isImported('req-1@b.example'), 'dedupe: dismissed mail request still blocks a re-import');

$q("INSERT INTO email_queue SET email_recipient = 'jane@client.example', email_from = 'support@msp.example', email_from_name = 'x', email_subject = 's', email_content = 'c', email_message_id = 'rivet.out42@msp.example', email_ticket_id = 42, email_status = 3");
$hit = $store->resolveThread(['unknown@x', 'rivet.out42@msp.example']);
$ok($hit && $hit['ticket_id'] === 42 && $hit['via'] === 'outbound mail', 'thread: our own outbound Message-ID resolves to its ticket');
$hit = $store->resolveThread(['reply-77@client.example']);
$ok($hit && $hit['ticket_id'] === 42 && $hit['via'] === 'inbound reply', 'thread: inbound reply Message-ID resolves to its ticket');
$hit = $store->resolveThread(['orig-43@client.example', 'rivet.out42@msp.example']);
$ok($hit && $hit['ticket_id'] === 43, 'thread: first candidate that resolves wins (candidate order respected)');
$ok($store->resolveThread(['nothing@x', '']) === null && $store->resolveThread([]) === null, 'thread: no match -> null');

// ---- IntakeStore: poison counter -------------------------------------------------------------------------------------
$key = IntakeStore::messageKey(1, '<poison-1@x>', 'uid:9');
$ok($key === IntakeStore::messageKey(1, 'POISON-1@x', 'uid:other') && $key !== IntakeStore::messageKey(2, 'poison-1@x', ''), 'poison key: Message-ID based, per mailbox, stable across fallbacks');
$r1 = $store->recordFailure(1, $key, 'poison-1@x', 'bad@x', 'broken', 'boom 1', 3);
$r2 = $store->recordFailure(1, $key, 'poison-1@x', 'bad@x', 'broken', 'boom 2', 3);
$ok($r1 === ['attempts' => 1, 'quarantined' => false] && $r2 === ['attempts' => 2, 'quarantined' => false] && !$store->isQuarantined(1, $key), 'poison: attempts counted, not yet quarantined');
$r3 = $store->recordFailure(1, $key, 'poison-1@x', 'bad@x', 'broken', 'boom 3', 3);
$ok($r3['attempts'] === 3 && $r3['quarantined'] === true && $store->isQuarantined(1, $key) && $store->quarantinedCount() === 1, 'poison: third failure tips it into quarantine');
$r4 = $store->recordFailure(1, $key, 'poison-1@x', 'bad@x', 'broken', 'boom 4', 3);
$ok($r4['quarantined'] === false, 'poison: quarantine tips exactly once (no repeat move/alert)');
$ok($one("SELECT intake_last_error FROM mail_intake_state WHERE intake_key = '$key'") === 'boom 4', 'poison: last error stored');
$intake_id = (int) $one("SELECT intake_id FROM mail_intake_state WHERE intake_key = '$key'");
$ok($store->release($intake_id) && !$store->isQuarantined(1, $key) && (int) $one("SELECT intake_attempts FROM mail_intake_state WHERE intake_id = $intake_id") === 0, 'poison: reset gives a fresh set of attempts');
$store->recordFailure(1, $key, null, null, null, 'e', 1);
$ok($store->discard($intake_id) && $store->quarantinedCount() === 0, 'poison: dismiss removes the entry');
$store->recordFailure(1, $key, null, null, null, 'e', 3); $store->clear(1, $key);
$ok((int) $one("SELECT COUNT(*) FROM mail_intake_state WHERE intake_key = '$key'") === 0, 'poison: a handled message forgets its failure history');

// ---- InboundRouter on the fixtures ------------------------------------------------------------------------------------
$route = fn (string $f, array $o = ['rate_cap' => 20]) => InboundRouter::preRoute($store, $load($f), $o);
$ok($route('duplicate_message_id')['action'] === 'duplicate', 'router: redelivered Message-ID -> duplicate');
$ok($route('autoresponder')['action'] === 'suppressed' && $route('ooo')['action'] === 'suppressed', 'router: autoresponder + OOO -> suppressed');
$ok($route('dsn')['action'] === 'dsn', 'router: bounce -> dsn');
$t = $route('gmail_quoted_reply');
$ok($t['action'] === 'thread' && $t['ticket_id'] === 42, 'router: In-Reply-To to our outbound id -> thread to ticket 42');
$ok($route('oversized_attachment')['action'] === 'continue', 'router: ordinary mail continues to normal matching');
for ($i = 0; $i < 20; $i++) { logMailEvent(1, 'jane@client.example', 'Jane', 'x', 'ticket_created'); }
$ok($route('oversized_attachment')['action'] === 'rate_limited', 'router: 20 handled messages in an hour -> rate limited');
logMailEvent(1, 'robot@x.example', 'r', 'x', 'suppressed'); for ($i = 0; $i < 30; $i++) logMailEvent(1, 'robot@x.example', 'r', 'x', 'suppressed');
$ok($store->senderMessageCount('robot@x.example') === 0, 'router: suppressed mail does not count toward the cap');
$ok(InboundRouter::preRoute($store, $load('oversized_attachment'), ['rate_cap' => 0])['action'] === 'continue', 'router: cap 0 disables the limit');
$q("DELETE FROM mail_log");

// ---- processInboundMessage glue (extracted from the cron script, run for real against the fixtures) -----------------------
$cron = file_get_contents("$root/cron/ticket_email_parser.php");
$a = strpos($cron, 'function processInboundMessage(');
$b = strpos($cron, "/** ------------------------------------------------------------------\n * Intake limits");
eval("use ITFlow\\Mail\\{IntakeStore, InboundRouter, MailHealth, MailSettings, QuotedTextStripper};\n" . substr($cron, $a, $b - $a));
$handle = function (array $norm, string $eml = '') use ($db) {
    $prep = InboundPreparer::prepare($norm, ['max_file_bytes' => 4096, 'max_message_bytes' => 1000000, 'inline_max_bytes' => 1048576]);
    if ($norm['message_id'] === '') { $norm['message_id'] = MessageId::synthetic($norm['from_email'], $norm['date'], $norm['subject'], $norm['text'] . $norm['html']); }
    return processInboundMessage(1, 0, 1, $norm['from_email'], $norm['from_name'], $norm['subject'], $norm['ccs'], $norm['date'], $prep['body'], $prep['body_text'], $prep['attachments'], $prep['raw_parts'], $eml, ['norm' => $norm, 'prep' => $prep]);
};
$lastLog = fn () => $db->query('SELECT * FROM mail_log ORDER BY mail_log_id DESC LIMIT 1')->fetch_assoc();
$replies = fn () => (int) $one('SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = 42 AND ticket_reply_type = \'Client\'');
$tickets = fn () => (int) $one('SELECT COUNT(*) FROM tickets');
$requests = fn () => (int) $one('SELECT COUNT(*) FROM mail_requests');

$q("DELETE FROM ticket_replies WHERE ticket_reply_id = $reply_row_id"); $q("DELETE FROM mail_requests");
$base_tickets = $tickets();

$r = $handle($load('gmail_quoted_reply'));
$row = $db->query("SELECT * FROM ticket_replies WHERE ticket_reply_mail_message_id = 'caf+gmail-1@mail.gmail.com'")->fetch_assoc();
$ok($r === true && $row && $row['ticket_reply_ticket_id'] == 42 && strpos($row['ticket_reply'], 'Works now') !== false && strpos($row['ticket_reply'], 'Please reboot') === false, 'glue: threaded Gmail reply added to ticket 42 with the quoted history stripped and its Message-ID stored');
$lg = $lastLog();
$ok($lg['mail_log_outcome'] === 'reply_added' && strpos($lg['mail_log_detail'], 'outbound mail') !== false && (int) $lg['mail_log_ticket_id'] === 42, 'glue: mail log says threaded by outbound Message-ID');
$n_replies = $replies();
$r = $handle($load('gmail_quoted_reply'));
$ok($r === true && $replies() === $n_replies && $lastLog()['mail_log_outcome'] === 'duplicate', 'glue: the same Message-ID again is a skipped duplicate (no second reply)');

$r = $handle($load('outlook_quoted_reply'));
$row = $db->query("SELECT * FROM ticket_replies WHERE ticket_reply_mail_message_id = 'outlook-1@client.example'")->fetch_assoc();
$ok($r === true && $row && strpos($row['ticket_reply'], 'Thanks, that fixed it') !== false && strpos($row['ticket_reply'], 'reboot the printer') === false && $lastLog()['mail_log_detail'] === 'Matched ticket #42 by subject tag', 'glue: Outlook reply matched by subject token (fallback) and stripped');

$t0 = $tickets(); $q0 = (int) $one('SELECT COUNT(*) FROM email_queue');
$r = $handle($load('autoresponder'));
$ok($r === true && $tickets() === $t0 && $replies() === $replies() && $lastLog()['mail_log_outcome'] === 'suppressed' && (int) $one('SELECT COUNT(*) FROM email_queue') === $q0, 'glue: auto-responder -> suppressed log only; no ticket, no reply, no outbound mail');
$r = $handle($load('ooo'));
$ok($r === true && $tickets() === $t0 && $lastLog()['mail_log_outcome'] === 'suppressed' && (int) $one('SELECT COUNT(*) FROM email_queue') === $q0, 'glue: out-of-office (threaded to a ticket by subject token) -> suppressed, nothing created or sent');
$sys_before = (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_type = 'System' AND ticket_reply_ticket_id = 42");
$r = $handle($load('dsn'));
$ok($r === true && $tickets() === $t0 && $lastLog()['mail_log_outcome'] === 'ndr' && (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_type = 'System' AND ticket_reply_ticket_id = 42") === $sys_before + 1 && (int) $one('SELECT COUNT(*) FROM email_queue') === $q0, 'glue: DSN -> ndr log + system note on the original ticket, no ticket and no reply email');
$ok(strpos($lastLog()['mail_log_detail'], 'bob@gone.example') !== false && strpos($lastLog()['mail_log_detail'], '5.1.1') !== false, 'glue: DSN detail has recipient and status');

// sender mismatch: same message twice -> exactly one mail request, handled both times
$mm = $load('gmail_quoted_reply'); $mm['from_email'] = 'stranger@evil.example'; $mm['message_id'] = 'mismatch-1@evil.example'; $mm['in_reply_to'] = null; $mm['references'] = null; $mm['subject'] = 'Re: [TCK-42] Printer offline';
$reqs0 = $requests();
$r1 = $handle($mm); $reqs1 = $requests();
$r2 = $handle($mm);
$ok($r1 === true && $r2 === true && $reqs1 === $reqs0 + 1 && $requests() === $reqs1, 'glue: sender-mismatch reply queues ONE mail request, reported handled, no new row on re-poll (dedupe by Message-ID)');
$ok($one("SELECT mail_request_reason FROM mail_requests WHERE mail_request_message_id = 'mismatch-1@evil.example'") === 'sender_mismatch', 'glue: mismatch request carries its reason');
$mm2 = $mm; $mm2['message_id'] = ''; $mm2['subject'] = 'Re: [TCK-42] other'; $mm2['text'] = 'no id mail'; $mm2['html'] = '';
$handle($mm2); $c1 = $requests(); $handle($mm2);
$ok($requests() === $c1, 'glue: even mail without a Message-ID dedupes (stand-in id)');

// new ticket from a known contact: Message-ID stored, oversize attachment refused and noted
$r = $handle($load('oversized_attachment'));
$trow = $db->query("SELECT * FROM tickets WHERE ticket_mail_message_id = 'big-1@client.example'")->fetch_assoc();
$ok($r === true && $trow && (int) $trow['ticket_contact_id'] === 1 && strpos($trow['ticket_details'], 'Attachments not imported') !== false && strpos($trow['ticket_details'], 'huge.bin') !== false, 'glue: new ticket stores the Message-ID; oversize attachment listed as not imported');
$ok((int) $one("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = {$trow['ticket_id']} AND ticket_attachment_name = 'small.txt'") === 1 && (int) $one("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id = {$trow['ticket_id']} AND ticket_attachment_name = 'huge.bin'") === 0, 'glue: small attachment stored, huge one not');
$tn = (int) $one('SELECT COUNT(*) FROM tickets');
$handle($load('oversized_attachment'));
$ok((int) $one('SELECT COUNT(*) FROM tickets') === $tn, 'glue: redelivered new-ticket mail does not create a second ticket');

// the created-ticket notification is an auto mail, threaded to the ticket, with its own Message-ID
$qrow = $db->query("SELECT * FROM email_queue WHERE email_ticket_id = {$trow['ticket_id']} ORDER BY email_id DESC LIMIT 1")->fetch_assoc();
$ok($qrow && $qrow['email_auto'] == 1 && preg_match('/^rivet\.[0-9a-f]{24}@msp\.example$/', (string) $qrow['email_message_id']) && strpos($qrow['email_subject'], 'Ticket created') === 0, 'queue: ticket-created email stamped with its own Message-ID, ticket id, auto flag');

// reply to that notification threads back to the new ticket (outbound Message-ID -> ticket)
$rep = $load('threaded_reply'); $rep['message_id'] = 'reply-to-created@client.example'; $rep['in_reply_to'] = '<' . $qrow['email_message_id'] . '>'; $rep['references'] = '<' . $qrow['email_message_id'] . '>'; $rep['subject'] = 'Re: whatever the client typed';
$handle($rep);
$ok((int) $one("SELECT ticket_reply_ticket_id FROM ticket_replies WHERE ticket_reply_mail_message_id = 'reply-to-created@client.example'") === (int) $trow['ticket_id'], 'glue: reply to the ticket-created email threads to the new ticket even though the subject was changed');

// rate cap through the glue
MailSettings::set($db, 'rate_cap_per_hour', '3');
for ($i = 0; $i < 3; $i++) { logMailEvent(1, 'flood@x.example', 'f', 's', 'mail_request'); }
$fl = $load('oversized_attachment'); $fl['from_email'] = 'flood@x.example'; $fl['message_id'] = 'flood-1@x.example'; $fl['subject'] = 'flooding';
$reqs0 = $requests();
$r = $handle($fl);
$ok($r === true && $requests() === $reqs0 + 1 && $one("SELECT mail_request_reason FROM mail_requests WHERE mail_request_message_id = 'flood-1@x.example'") === 'rate_limited' && $lastLog()['mail_log_outcome'] === 'rate_limited', 'glue: over the cap -> quarantined as a mail request (rate_limited), logged');
$ok((int) $one("SELECT COUNT(*) FROM mail_alerts WHERE alert_key LIKE 'ratelimit:%'") === 1, 'glue: one de-duplicated rate-limit alert');
MailSettings::set($db, 'rate_cap_per_hour', '20');
$q("DELETE FROM mail_log");

// ---- MailQueuePolicy ----------------------------------------------------------------------------------------------------
$ok(MailQueuePolicy::backoffMinutes(1) === 5 && MailQueuePolicy::backoffMinutes(2) === 15 && MailQueuePolicy::backoffMinutes(3) === 60 && MailQueuePolicy::backoffMinutes(4) === 240 && MailQueuePolicy::backoffMinutes(5) === null, 'queue: back-off schedule 5, 15, 60, 240 minutes then none');
$ok(MailQueuePolicy::isExhausted(5) && !MailQueuePolicy::isExhausted(4) && !MailQueuePolicy::isExhausted(99), 'queue: exhausted at 5 attempts; 99 (permanent) is not "exhausted"');
$q("DELETE FROM email_queue");
$mk = function (string $set) use ($db) {
    $defaults = ['email_recipient' => 'a@b.example', 'email_from' => 'support@msp.example', 'email_from_name' => 'x', 'email_subject' => 's', 'email_content' => 'c'];
    $sql = '';
    foreach ($defaults as $col => $val) { if (strpos($set, $col) === false) { $sql .= "$col = '$val', "; } }
    $db->query("INSERT INTO email_queue SET $sql $set");
    return (int) $db->insert_id;
};
$stuck = $mk('email_status = 1, email_started_at = NOW() - INTERVAL 20 MINUTE');
$fresh = $mk('email_status = 1, email_started_at = NOW() - INTERVAL 2 MINUTE');
$legacy = $mk('email_status = 1, email_queued_at = NOW() - INTERVAL 30 MINUTE');
$sent = $mk('email_status = 3, email_sent_at = NOW() - INTERVAL 20 MINUTE');
$ok(MailQueuePolicy::reapStuck($db) === 2, 'reaper: resets exactly the rows stuck > 10 minutes (incl. legacy rows with no start time)');
$st = fn (int $id) => (int) $one("SELECT email_status FROM email_queue WHERE email_id = $id");
$ok($st($stuck) === 0 && $st($legacy) === 0 && $st($fresh) === 1 && $st($sent) === 3, 'reaper: stuck -> 0, in-flight and sent untouched');
$q("DELETE FROM email_queue");
$due = [];
foreach ([[1, 4, false], [1, 6, true], [1, 2, false], [2, 14, false], [2, 16, true], [3, 59, false], [3, 61, true], [4, 239, false], [4, 241, true], [5, 9999, false], [99, 9999, false]] as [$att, $ageMin, $expect]) {
    $id = $mk("email_status = 2, email_attempts = $att, email_failed_at = NOW() - INTERVAL $ageMin MINUTE");
    $due[$id] = [$expect, "$att/$ageMin"];
}
$got = array_map('intval', array_column($db->query('SELECT email_id FROM email_queue WHERE ' . MailQueuePolicy::retryDueSql())->fetch_all(MYSQLI_ASSOC), 'email_id'));
$wrong = [];
foreach ($due as $id => [$expect, $label]) { if (in_array($id, $got, true) !== $expect) $wrong[] = $label; }
$ok($wrong === [], 'back-off: retry selection honours 5/15/60/240 min per attempt count; exhausted (5) and permanent (99) never selected' . ($wrong ? ' wrong cases attempts/minutes: ' . implode(', ', $wrong) : ''));
$q("DELETE FROM email_queue");
for ($i = 0; $i < 7; $i++) { $mk('email_status = 3, email_sent_at = NOW()'); }
$mk('email_status = 3, email_sent_at = NOW() - INTERVAL 5 MINUTE');
$ok(MailQueuePolicy::sendBudget($db, 10) === 3 && MailQueuePolicy::sendBudget($db, 5) === 0 && MailQueuePolicy::sendBudget($db, 120) === 113, 'rate limit: budget = per-minute cap minus what was sent in the last 60 s');

// ---- MailHealth ----------------------------------------------------------------------------------------------------------
$notes = []; $mails = [];
MailHealth::$notifier = function ($type, $msg, $action) use (&$notes) { $notes[] = $msg; };
MailHealth::$mailer = function ($emails) use (&$mails) { foreach ($emails as $e) $mails[] = $e; };
$q("DELETE FROM mail_alerts"); $q("DELETE FROM email_queue");
$ok(MailHealth::alert($db, 'k1', 'T', 'first') === true && MailHealth::alert($db, 'k1', 'T', 'second') === false && MailHealth::alert($db, 'k2', 'T', 'other') === true, 'alerts: same key is de-duplicated, a different key is not');
$ok(count($notes) === 2 && count($mails) === 2 && $mails[0]['recipient'] === 'boss@msp.example', 'alerts: in-app notification + email to the admin user');
$q("UPDATE mail_alerts SET alert_last_sent_at = NOW() - INTERVAL 7 HOUR WHERE alert_key = 'k1'");
$ok(MailHealth::alert($db, 'k1', 'T', 'third') === true, 'alerts: repeats once the 6 h window has passed');
MailSettings::set($db, 'alert_email', 'ops@msp.example, oncall@msp.example');
$mails = []; MailHealth::alert($db, 'k3', 'T', 'x');
$ok(array_column($mails, 'recipient') === ['ops@msp.example', 'oncall@msp.example'], 'alerts: configured alert addresses override the admin list');
MailSettings::set($db, 'alert_email', '');
$q("DELETE FROM mail_alerts"); $notes = []; $mails = [];
$ok(MailHealth::oauthFailure($db, 1, 'Google Workspace', 'HTTP 400 invalid_grant Token has been expired or revoked') && strpos($notes[0], 'invalid_grant') !== false && strpos($notes[0], 'support@msp.example') !== false, 'alerts: OAuth refresh failure names the mailbox and the provider reason');
$ok(!MailHealth::oauthFailure($db, 1, 'Google Workspace', 'again'), 'alerts: OAuth failure alert is de-duplicated');

$q("DELETE FROM mail_alerts"); $notes = [];
$q("UPDATE mailboxes SET mailbox_last_polled_at = NOW(), mailbox_consecutive_failures = 0");
$ok(MailHealth::recordPollFailure($db, 1, 'connection refused') === 1 && MailHealth::recordPollFailure($db, 1, 'connection refused') === 2, 'health: consecutive failures counted');
$ok(MailHealth::runChecks($db, true) === [], 'checks: 2 failures < threshold 3 -> no alert');
MailHealth::recordPollFailure($db, 1, 'connection refused');
$sent = MailHealth::runChecks($db, true);
$ok($sent === ['unreachable:1'], 'checks: 3 failed polls in a row -> "Mailbox unreachable" alert');
$ok(MailHealth::runChecks($db, true) === [], 'checks: not repeated inside the de-dupe window');
MailHealth::recordPollSuccess($db, 1);
$row = $db->query('SELECT * FROM mailboxes WHERE mailbox_id = 1')->fetch_assoc();
$ok((int) $row['mailbox_consecutive_failures'] === 0 && $row['mailbox_last_success_at'] !== null && $row['mailbox_last_error'] === null, 'health: a successful poll resets failures and records last success');
$q("DELETE FROM mail_alerts");
$q("UPDATE mailboxes SET mailbox_last_polled_at = NOW() - INTERVAL 20 MINUTE");
$ok(MailHealth::runChecks($db, true) === ['silent:1'], 'checks: enabled mailbox not polled for > 15 min -> "poller silent"');
$q("DELETE FROM mail_alerts");
$ok(MailHealth::runChecks($db, false) === [], 'checks: no silent-poller alert when email parsing is switched off');
$q("UPDATE mailboxes SET mailbox_last_polled_at = NOW()");
$q("DELETE FROM mail_alerts");
$e1 = $mk('email_status = 2, email_attempts = 5, email_failed_at = NOW()'); $e2 = $mk('email_status = 2, email_attempts = 5, email_failed_at = NOW()'); $mk('email_status = 2, email_attempts = 99, email_failed_at = NOW()');
$ok(MailHealth::runChecks($db, true) === ['queue_exhausted'], 'checks: rows that exhausted their retries -> one alert (permanent failures excluded)');
$ok((int) $one('SELECT COUNT(*) FROM email_queue WHERE email_alerted_at IS NOT NULL') === 2, 'checks: exhausted rows are marked alerted');
$s = MailHealth::summary($db);
$ok($s['exhausted'] === 2 && $s['quarantined'] === 0 && $s['unhealthy'] === 0 && $s['attention'] === 1, 'summary: admin counter reflects exhausted outbound mail');
MailHealth::$notifier = null; MailHealth::$mailer = null;

// ---- real cron/mail_queue.php --------------------------------------------------------------------------------------------
$q("DELETE FROM email_queue"); $q("DELETE FROM mail_alerts"); $q("DELETE FROM notifications");
$sink_out = sys_get_temp_dir() . '/rivet-sink-' . getmypid() . '.txt'; @unlink($sink_out);
$port = 20000 + (getmypid() % 20000);
$sink = proc_open(['python3', "$root/tests/fixtures/mail/smtp_sink.py", (string) $port, $sink_out], [], $pipes);
usleep(600000);
$q("UPDATE settings SET config_smtp_provider = 'standard_smtp', config_smtp_host = '127.0.0.1', config_smtp_port = $port, config_smtp_encryption = '', config_smtp_username = '', config_enable_cron = 1 WHERE company_id = 1");
$auto_id = $mk("email_recipient = 'jane@client.example', email_subject = 'Ticket update - [TCK-42] Printer offline', email_message_id = 'rivet.test1@msp.example', email_auto = 1, email_ticket_id = 42");
$human_id = $mk("email_recipient = 'jane@client.example', email_subject = 'Hello from a human', email_message_id = 'rivet.test2@msp.example', email_auto = 0");
$stuck_id = $mk("email_status = 1, email_started_at = NOW() - INTERVAL 30 MINUTE, email_subject = 'was stuck'");
$run = function () use ($root) { $cmd = 'php ' . escapeshellarg("$root/cron/mail_queue.php") . ' --no-mx-validation 2>&1'; exec($cmd, $out, $code); return [$code, implode("\n", $out)]; };
[$code, $out] = $run();
usleep(300000);
$sunk = (string) @file_get_contents($sink_out);
$ok($code === 0 && (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_status = 3") === 3, "cron: queue run sends all three rows incl. the reaped one (exit $code)" . ($code ? " out: $out" : ''));
$msgs = array_values(array_filter(explode(str_repeat('=', 60) . "\n", $sunk)));
$auto_msg = ''; $human_msg = '';
foreach ($msgs as $m) { if (strpos($m, 'Ticket update') !== false) $auto_msg = $m; if (strpos($m, 'Hello from a human') !== false) $human_msg = $m; }
$ok(preg_match('/^Message-ID: <rivet\.test1@msp\.example>$/mi', $auto_msg) === 1, 'outbound: stored Message-ID is sent as the Message-ID header');
$ok(preg_match('/^Auto-Submitted: auto-generated$/mi', $auto_msg) && preg_match('/^X-Auto-Response-Suppress: All$/mi', $auto_msg) && preg_match('/^X-RivetIT-Auto: 1$/mi', $auto_msg), 'outbound: automated mail carries Auto-Submitted + X-Auto-Response-Suppress + loop header');
$ok($human_msg !== '' && stripos($human_msg, 'Auto-Submitted') === false && preg_match('/^Message-ID: <rivet\.test2@msp\.example>$/mi', $human_msg), 'outbound: a non-auto row gets a Message-ID but no Auto-Submitted header');
$sent_headers = \ITFlow\Mail\RawHeaders::parse($auto_msg);
$ok((AutoReplyDetector::classify($sent_headers, 'x')['rule'] ?? '') === 'loop', 'loop: our own outbound mail would be recognised and dropped if it came back to a mailbox');
$ok((int) $one("SELECT email_attempts FROM email_queue WHERE email_id = $stuck_id") === 1, 'cron: reaped row counted one attempt');
proc_terminate($sink); proc_close($sink);

// failure path against a dead port: attempts/back-off/alert
$q("DELETE FROM email_queue"); $q("DELETE FROM mail_alerts");
$q("UPDATE settings SET config_smtp_port = 1 WHERE company_id = 1");
$f1 = $mk("email_recipient = 'jane@client.example', email_subject = 'will fail', email_message_id = 'rivet.fail1@msp.example'");
$run();
$fr = $db->query("SELECT * FROM email_queue WHERE email_id = $f1")->fetch_assoc();
$ok((int) $fr['email_status'] === 2 && (int) $fr['email_attempts'] === 1 && $fr['email_failed_at'] !== null && $fr['email_message_id'] === 'rivet.fail1@msp.example', 'cron: SMTP failure -> status 2, attempts 1, message id kept');
$run();
$ok((int) $one("SELECT email_attempts FROM email_queue WHERE email_id = $f1") === 1, 'cron: not retried before the 5-minute back-off elapses');
$q("UPDATE email_queue SET email_failed_at = NOW() - INTERVAL 6 MINUTE WHERE email_id = $f1");
$run();
$ok((int) $one("SELECT email_attempts FROM email_queue WHERE email_id = $f1") === 2, 'cron: retried after 5 min (attempt 2)');
$q("UPDATE email_queue SET email_failed_at = NOW() - INTERVAL 10 MINUTE WHERE email_id = $f1"); $run();
$ok((int) $one("SELECT email_attempts FROM email_queue WHERE email_id = $f1") === 2, 'cron: attempt 3 waits 15 min, not 10');
$q("UPDATE email_queue SET email_failed_at = NOW() - INTERVAL 16 MINUTE WHERE email_id = $f1"); $run();
$q("UPDATE email_queue SET email_failed_at = NOW() - INTERVAL 61 MINUTE WHERE email_id = $f1"); $run();
$q("UPDATE email_queue SET email_failed_at = NOW() - INTERVAL 241 MINUTE WHERE email_id = $f1"); $run();
$fr = $db->query("SELECT * FROM email_queue WHERE email_id = $f1")->fetch_assoc();
$ok((int) $fr['email_attempts'] === 5 && (int) $fr['email_status'] === 2, 'cron: walks 5/15/60/240 minute back-off to five attempts');
$q("UPDATE email_queue SET email_failed_at = NOW() - INTERVAL 1000 MINUTE WHERE email_id = $f1"); $run();
$ok((int) $one("SELECT email_attempts FROM email_queue WHERE email_id = $f1") === 5, 'cron: exhausted row is never retried again');
$ok((int) $one("SELECT COUNT(*) FROM mail_alerts WHERE alert_key = 'queue_exhausted'") === 1 && (int) $one("SELECT COUNT(*) FROM notifications WHERE notification LIKE '%failed all 5 send attempts%'") >= 1, 'cron: exhaustion raises the (single) admin alert + notification');
// rate limit: budget 2/min with 5 rows queued and an unreachable server -> only 2 are attempted
$q("DELETE FROM email_queue");
MailSettings::set($db, 'outbound_rate_per_min', '2');
for ($i = 0; $i < 5; $i++) { $mk("email_recipient = 'jane@client.example', email_subject = 'rl $i'"); }
// the budget counts SENT mail, so seed 2 sends in the last minute and expect nothing to go
$mk('email_status = 3, email_sent_at = NOW()'); $mk('email_status = 3, email_sent_at = NOW()');
$run();
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_status = 0") === 5, 'cron: outbound budget exhausted -> remaining rows stay queued for the next run');
MailSettings::set($db, 'outbound_rate_per_min', '120');

// ---- clean up --------------------------------------------------------------------------------------------------------
@unlink($sink_out);
$ok(is_file("$root/uploads/tmp/index.php"), 'an empty .eml name never moves the uploads/tmp directory (createMailRequestFromInbound guard)');
foreach (glob("$root/uploads/mail_requests/*") ?: [] as $d) { exec('rm -rf ' . escapeshellarg($d)); }
@rmdir("$root/uploads/mail_requests");
foreach ([42, 43, 44, 45, 46, 47, 48] as $tid) { if (is_dir("$root/uploads/tickets/$tid")) { exec('rm -rf ' . escapeshellarg("$root/uploads/tickets/$tid")); } }
$q("UPDATE settings SET config_smtp_provider = NULL, config_smtp_host = NULL, config_smtp_port = 0 WHERE company_id = 1");
echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
