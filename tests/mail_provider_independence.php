<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Guard: incoming (IMAP) and outgoing (SMTP) mail providers are independent. No single `UPDATE settings` may assign BOTH
 * config_imap_provider and config_smtp_provider, in the Microsoft callback or the Settings > Mail save handlers.
 * (A Microsoft connect on the IMAP card used to switch SMTP to Microsoft 365 too and broke sending.) No database:
 *   php tests/mail_provider_independence.php
 */
$fail = 0;
$t = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fail++; } };
foreach (['admin/oauth_microsoft_mail_callback.php', 'admin/post/settings_mail.php'] as $rel) {
    $src = (string) file_get_contents(__DIR__ . '/../' . $rel);
    $stmts = preg_split('/UPDATE\s+settings\s+SET/i', $src);
    array_shift($stmts);
    $both = 0;
    foreach ($stmts as $body) {
        $body = preg_split('/\bWHERE\b/i', $body)[0];
        if (preg_match('/config_imap_provider\s*=/', $body) && preg_match('/config_smtp_provider\s*=/', $body)) { $both++; }
    }
    $t($both === 0, "$rel: no UPDATE settings sets both the IMAP and the SMTP provider (" . count($stmts) . ' updates checked)');
}
$cb = (string) file_get_contents(__DIR__ . '/../admin/oauth_microsoft_mail_callback.php');
$t((bool) preg_match('/config_imap_provider\s*=\s*\'microsoft_oauth\'/', $cb) && !preg_match('/config_smtp_provider\s*=\s*\'microsoft_oauth\'/', $cb), 'the callback sets the IMAP provider and leaves SMTP alone');
exit($fail ? 1 : 0);
