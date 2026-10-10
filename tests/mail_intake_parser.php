<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Mail intake parsing, no mailbox and no database: golden-file tests over the .eml fixtures in tests/fixtures/mail.
 * Each fixture is parsed with the real Webklex parser through ITFlow\Mail\MessageNormalizer (the code the cron poller
 * runs), then classified (auto-reply / OOO / DSN), threaded (candidate Message-IDs), quote-stripped and attachment-limited.
 * The expected result of every fixture is tests/fixtures/mail/expected/<name>.json; regenerate with UPDATE_GOLDEN=1 and
 * review the diff. Plus unit checks for Message-ID, header, quote and attachment helpers.
 *
 *   php tests/mail_intake_parser.php
 */
$root = dirname(__DIR__);
require_once "$root/plugins/vendor/autoload.php";
spl_autoload_register(static function (string $c) use ($root): void {
    if (strpos($c, 'ITFlow\\Mail\\') === 0) {
        $f = "$root/src/Mail/" . substr($c, strlen('ITFlow\\Mail\\')) . '.php';
        if (is_file($f)) require $f;
    }
});

use ITFlow\Mail\{AttachmentPolicy, AutoReplyDetector, InboundPreparer, MessageId, MessageNormalizer, QuotedTextStripper, RawHeaders};

$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// ---- golden fixtures ---------------------------------------------------------------------------------------------
$limits = ['max_file_bytes' => 4096, 'max_message_bytes' => 100000, 'inline_max_bytes' => 1048576];
$update = getenv('UPDATE_GOLDEN') === '1';
$fixtures = glob("$root/tests/fixtures/mail/*.eml");
sort($fixtures);
foreach ($fixtures as $file) {
    $name = basename($file, '.eml');
    $norm = MessageNormalizer::fromWebklex(\Webklex\PHPIMAP\Message::fromString(file_get_contents($file)));
    $prep = InboundPreparer::prepare($norm, $limits);
    $cls = AutoReplyDetector::classify($norm['headers'], $norm['subject'], array_map(fn ($a) => $a['content_type'], $norm['attachments']));
    $reply = QuotedTextStripper::stripReply($norm['html'], $norm['text']);
    $summary = [
        'from' => $norm['from_email'],
        'subject' => $norm['subject'],
        'message_id' => $norm['message_id'],
        'thread_candidates' => MessageId::threadCandidates($norm['in_reply_to'], $norm['references']),
        'classification' => $cls === null ? null : ['kind' => $cls['kind'], 'rule' => $cls['rule']],
        'reply_stripped' => $reply['stripped'],
        'reply_text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</div>', '</p>'], "\n", $reply['body'])), ENT_QUOTES)),
        'attachments_kept' => array_column($prep['attachments'], 'name'),
        'attachments_rejected' => array_column($prep['rejected'], 'name'),
    ];
    $summary['reply_text'] = trim(preg_replace("/\n{3,}/", "\n\n", $summary['reply_text']));
    $expFile = "$root/tests/fixtures/mail/expected/$name.json";
    if ($update) {
        file_put_contents($expFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    $expected = is_file($expFile) ? json_decode(file_get_contents($expFile), true) : null;
    $ok($expected === $summary, "golden: $name" . ($expected === $summary ? '' : "\n  got      " . json_encode($summary) . "\n  expected " . json_encode($expected)));
}

// ---- explicit expectations on top of the goldens (so a regenerated golden cannot silently bless a regression) --------
$load = fn (string $f) => MessageNormalizer::fromWebklex(\Webklex\PHPIMAP\Message::fromString(file_get_contents("$root/tests/fixtures/mail/$f.eml")));
$cls = function (string $f) use ($load) { $m = $load($f); return AutoReplyDetector::classify($m['headers'], $m['subject'], array_map(fn ($a) => $a['content_type'], $m['attachments'])); };

$ok(($cls('autoresponder')['rule'] ?? '') === 'auto_submitted', 'autoresponder -> Auto-Submitted rule');
$ok(($cls('ooo')['rule'] ?? '') === 'ooo_subject', 'out-of-office with no auto headers -> subject rule');
$ok(($cls('dsn')['kind'] ?? '') === 'dsn', 'multipart/report -> dsn');
foreach (['threaded_reply', 'outlook_quoted_reply', 'gmail_quoted_reply', 'oversized_attachment', 'duplicate_message_id'] as $f) {
    $ok($cls($f) === null, "ordinary mail is not suppressed: $f");
}
$t = $load('threaded_reply');
$ok($t['message_id'] === 'reply-77@client.example', 'Message-ID normalised (case, brackets)');
$ok(MessageId::threadCandidates($t['in_reply_to'], $t['references']) === ['rivet.out42@msp.example', 'orig-42@client.example'], 'thread candidates: In-Reply-To first, then References newest to oldest');
$ok($load('duplicate_message_id')['message_id'] === $t['message_id'], 'duplicate fixture carries the same normalised Message-ID');

$q = QuotedTextStripper::stripReply($load('outlook_quoted_reply')['html'], $load('outlook_quoted_reply')['text']);
$ok($q['stripped'] && strpos($q['body'], 'Please reboot') === false && strpos($q['body'], 'Thanks, that fixed it.') !== false, 'Outlook reply: quoted history removed, reply kept');
$q = QuotedTextStripper::stripReply($load('gmail_quoted_reply')['html'], $load('gmail_quoted_reply')['text']);
$ok($q['stripped'] && strpos($q['body'], 'Please reboot') === false && strpos($q['body'], 'Works now') !== false, 'Gmail reply (wrapped attribution): quoted history removed');
$q = QuotedTextStripper::stripReply($load('gmail_quoted_reply')['html'], '');
$ok($q['stripped'] && strpos($q['body'], 'Please reboot') === false && strpos($q['body'], 'Works now') !== false, 'Gmail reply, HTML only: gmail_quote container removed');

$p = InboundPreparer::prepare($load('oversized_attachment'), $limits);
$ok(array_column($p['attachments'], 'name') === ['small.txt'] && array_column($p['rejected'], 'name') === ['huge.bin'], 'oversized attachment refused, small one kept');
$ok(strpos($p['body'], 'Attachments not imported') !== false && strpos($p['body'], 'huge.bin') !== false, 'the stored body tells the technician what was left out');
$p = InboundPreparer::prepare($load('oversized_attachment'), ['max_file_bytes' => 100000, 'max_message_bytes' => 5000]);
$ok(array_column($p['attachments'], 'name') === ['small.txt'] && count($p['rejected']) === 1, 'per-message total limit refuses the attachment that would exceed it');

$d = $load('dsn');
$ok(array_column($d['attachments'], 'content_type') === ['message/delivery-status', 'message/rfc822'] && $d['attachments'][0]['mime'] === 'text/plain', 'DSN parts: declared content type kept (Webklex getMimeType() would say text/plain)');
$dp = InboundPreparer::prepare($d, $limits);
$ok(strpos((string) $dp['raw_parts'][0]['content'], 'Final-Recipient: rfc822; bob@gone.example') !== false && strpos((string) $dp['raw_parts'][1]['content'], 'Subject: [TCK-0042]') !== false, 'DSN parts: delivery-status and embedded-message bodies reach the bounce parser');
$poison = $load('poison');
$ok($poison['message_id'] === '' && is_string($poison['subject']), 'poison fixture: parser survives, no Message-ID -> fallback key');

// ---- unit checks ----------------------------------------------------------------------------------------------------
$H = fn (string $raw) => RawHeaders::parse($raw);
$ok(MessageId::normalize('  <ABC.def@Host.Example> ') === 'abc.def@host.example', 'normalize strips brackets/case/space');
$ok(MessageId::normalize('') === '' && MessageId::normalize('<>') === '' && MessageId::normalize('has space@x') === '', 'normalize rejects empty / malformed ids');
$ok(strlen(MessageId::normalize('<' . str_repeat('a', 400) . '@x>')) === 255, 'normalize caps at 255');
$ok(MessageId::parseList('<a@x> <b@x>,<A@X>') === ['a@x', 'b@x'], 'parseList dedupes case-insensitively');
$ok(preg_match('/^rivet\.[0-9a-f]{24}@example\.com$/', MessageId::generate('Support@Example.com')) === 1, 'generate: rivet.<hex>@sender-domain');
$ok(substr(MessageId::generate('nonsense'), -16) === '@rivetit.invalid', 'generate: invalid sender falls back');

$cases = [
    ["Auto-Submitted: auto-generated\n", 'auto_submitted'],
    ["Auto-Submitted: auto-replied; foo=bar\n", 'auto_submitted'],
    ["Auto-Submitted: no\n", null],
    ["Precedence: bulk\n", 'precedence'], ["Precedence: JUNK\n", 'precedence'], ["Precedence: list\n", 'precedence'], ["Precedence: first-class\n", null],
    ["X-Auto-Response-Suppress: All\n", 'auto_response_suppress'], ["X-Auto-Response-Suppress: DR, OOF, AutoReply\n", 'auto_response_suppress'], ["X-Auto-Response-Suppress: DR, RN\n", null],
    ["X-Autoreply: yes\n", 'x_autoreply'], ["X-Autorespond: 1\n", 'x_autoreply'],
    ["Return-Path: <>\n", 'null_return_path'], ["Return-Path: <a@b.example>\n", null],
    ["X-RivetIT-Auto: 1\n", 'loop'],
    ["Content-Type: multipart/report; report-type=delivery-status; boundary=x\n", 'dsn'],
];
foreach ($cases as [$hdr, $rule]) {
    $c = AutoReplyDetector::classify($H($hdr . "Subject: hello\n"), 'hello printer is broken');
    $ok(($c['rule'] ?? null) === $rule, 'header rule ' . trim(str_replace("\n", ' | ', $hdr)) . ' -> ' . var_export($rule, true));
}
foreach (['Out of Office: back Monday', 'Re: Automatic reply: ticket', 'Abwesenheitsnotiz: Urlaub', 'Réponse automatique : Absence', 'Respuesta automática: fuera', 'Autosvar: Semester', 'Out of the office until 5th'] as $s) {
    $ok((AutoReplyDetector::classify([], $s)['rule'] ?? null) === 'ooo_subject', "OOO subject: $s");
}
foreach (['Printer is out of office hours coverage', 'Question about vacation policy', 'Re: [TCK-1] automatic updates broke Outlook'] as $s) {
    $ok(AutoReplyDetector::classify([], $s) === null, "not OOO: $s");
}
$ok((AutoReplyDetector::classify([], 'Undeliverable: Hello')['kind'] ?? '') === 'dsn', 'Undeliverable subject -> dsn');
$ok((AutoReplyDetector::classify([], 'x', ['message/delivery-status'])['kind'] ?? '') === 'dsn', 'delivery-status part -> dsn');

$S = fn (string $t) => QuotedTextStripper::stripText($t);
$ok($S("Fixed.\n\n-----Original Message-----\nFrom: a\nSent: b\n\nold") === 'Fixed.', 'Original Message separator');
$ok($S("Fixed.\n\nOn Mon, 5 Oct 2026 at 10:00, Bob <b@x.com> wrote:\n> old") === 'Fixed.', 'On ... wrote:');
$ok($S("Fixed.\n\nOn 5 Oct 2026, at 10:00, Bob wrote:\n\n> old") === 'Fixed.', 'On ... wrote: (Apple style)');
$ok($S("Gelöst.\n\nAm 05.10.2026 um 10:00 schrieb Bob <b@x.de>:\n> alt") === 'Gelöst.', 'German schrieb');
$ok($S("Réglé.\n\nLe 5 oct. 2026 à 10:00, Bob <b@x.fr> a écrit :\n> vieux") === 'Réglé.', 'French a écrit');
$ok($S("Resuelto.\n\nEl lun, 5 oct 2026 a las 10:00, Bob <b@x.es> escribió:\n> viejo") === 'Resuelto.', 'Spanish escribió');
$ok($S("Fixed.\n\nFrom: Bob <b@x.com>\nSent: Monday\nTo: me\nSubject: Re: x\n\nold") === 'Fixed.', 'From/Sent/To/Subject block');
$ok($S("Fixed.\n\n________________________________\nFrom: Bob\nSent: Monday\nTo: me\nSubject: x\n\nold") === 'Fixed.', 'Outlook underscore rule + header block');
$ok($S("Fixed.\n\n*From:* Bob <b@x.com>\n*Sent:* Monday\n*To:* me\n\nold") === 'Fixed.', 'Starred header block (Gmail text)');
$ok($S("Q1?\n> a\nA1\n> b\nA2") === "Q1?\nA1\nA2", 'inline replies keep the author lines, drop > lines');
$ok($S("Hello\n##- Please type your reply above this line -##\nstuff below") === 'Hello', 'existing reply-above marker still wins');
$ok($S("> only quoted\n> text") === "> only quoted\n> text", 'whole body quoted -> original kept');
$ok($S("-----Original Message-----\nFrom: a\nSent: b\n\nbody") !== '', 'forward-only reply never empties the body');
$ok($S("From: the vendor said no\nThat is all") === "From: the vendor said no\nThat is all", 'a lone "From:" line in prose is not a header block');
$ok($S("On the other hand he wrote:\nnope") === "On the other hand he wrote:\nnope", 'sentence starting with On is not an attribution');
$ok($S('') === '' && $S("   \n") === "   \n", 'blank input unchanged');
$ok(QuotedTextStripper::stripHtml('<blockquote type="cite">only</blockquote>') === '<blockquote type="cite">only</blockquote>', 'HTML that is all quote is kept');
$ok(trim(QuotedTextStripper::stripHtml('<div>Hi</div><i style="x">##- Please type your reply above this line -##</i><br>Hello old')) === '<div>Hi</div>', 'HTML reply marker wrapper stripped');

$ok(AttachmentPolicy::inlineAllowed(1000, 1024) && !AttachmentPolicy::inlineAllowed(2000, 1024) && AttachmentPolicy::inlineAllowed(10 ** 9, 0), 'inline cap: under allowed, over refused, 0 = unlimited');
$img = ['html' => '<img src="cid:logo1"><img src="cid:big1">', 'text' => '', 'attachments' => [
    ['name' => 'logo.png', 'content' => str_repeat('a', 500), 'mime' => 'image/png', 'disposition' => 'inline', 'cid' => '<logo1>'],
    ['name' => 'big.png', 'content' => str_repeat('b', 5000), 'mime' => 'image/png', 'disposition' => 'inline', 'cid' => 'big1'],
]];
$p = InboundPreparer::prepare($img, ['inline_max_bytes' => 1024, 'max_file_bytes' => 100000, 'max_message_bytes' => 100000]);
$ok(strpos($p['body'], 'data:image/png;base64,') !== false && strpos($p['body'], 'cid:logo1') === false, 'small inline image embedded as data URI');
$ok(strpos($p['body'], 'cid:big1') !== false && array_column($p['attachments'], 'name') === ['big.png'], 'large inline image kept as an attachment, not embedded');
$p = InboundPreparer::prepare(['html' => 'x', 'text' => '', 'attachments' => [['name' => 'a.txt', 'content' => 'EICAR', 'mime' => 'text/plain', 'disposition' => 'attachment', 'cid' => null], ['name' => 'b.txt', 'content' => 'fine', 'mime' => 'text/plain', 'disposition' => 'attachment', 'cid' => null]]],
    ['scan' => fn ($c) => $c === 'EICAR' ? 'Eicar-Test-Signature' : null]);
$ok(array_column($p['attachments'], 'name') === ['b.txt'] && strpos($p['rejected'][0]['reason'], 'Eicar-Test-Signature') !== false, 'virus scan hook removes the infected attachment');

echo "\n$n checks, $fails failed\n";
exit($fails ? 1 : 0);
