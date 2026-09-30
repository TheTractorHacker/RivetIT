<?php
/*
 * Demo seed 20-knowledge - Knowledge Base, Credentials, Printers and Network Drives
 * for the fictional Summit Ridge Manufacturing instance used by the user guide.
 *
 *   RIVETIT_APP_DIR=/path/to/app php docs/user-guide/tools/seed/20-knowledge.php
 *
 * Why PHP and not SQL: credential usernames, passwords and one-time-password (OTP)
 * secrets are encrypted with the vault master key, so they are written through the
 * app's own helpers (encryptCredentialEntryWithKey() and the canonical vault key from
 * getCanonicalVaultKey()) - the same functions the app uses. Nothing here does its own
 * cryptography. Knowledge Base articles go through the app's own InteractiveBlocks
 * normaliser and progress store for the same reason.
 *
 * Idempotent: every row is looked up by a natural key (title, name, ...) first and only
 * created when missing, so re-running never duplicates anything. It never prints secrets.
 * Everything is fictional: .example addresses, 555-01xx phones, 10.x / 192.168.x IPs,
 * obviously fake passwords and OTP secrets.
 *
 * Depends on 00-core (departments, employees, locations, agent users). It looks up the
 * 10-infrastructure assets by NAME and degrades gracefully when they are missing.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this seed from the command line.\n");
    exit(1);
}

$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
$app = rtrim($app, '/');
if (!is_file("$app/functions.php") || !is_file("$app/config.php")) {
    fwrite(STDERR, "RIVETIT_APP_DIR ($app) is not a RivetIT application directory.\n");
    exit(1);
}

// The app uses relative ../ requires, so run from a subdirectory of it (as scripts/setup_cli.php does).
chdir("$app/scripts");
$_SERVER['DOCUMENT_ROOT'] = $app;
require_once "$app/config.php";
require_once "$app/functions.php";
mysqli_set_charset($mysqli, 'utf8mb4');

// The interactive-KB progress store refuses to load unless a caller declares itself.
define('FROM_KB_PROGRESS', true);
require_once "$app/agent/includes/kb_progress_store.php";

$stats = ['categories' => 0, 'articles' => 0, 'versions' => 0, 'attachments' => 0, 'images' => 0,
          'tags' => 0, 'folders' => 0, 'credentials' => 0, 'printers' => 0, 'drives' => 0];

/* ------------------------------------------------------------------ small helpers */

function seed_esc($value): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $value);
}

function seed_scalar(string $sql)
{
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    $row = $result ? mysqli_fetch_row($result) : null;
    return $row ? $row[0] : null;
}

function seed_exec(string $sql): bool
{
    global $mysqli;
    return (bool) mysqli_query($mysqli, $sql);
}

function seed_user(string $email): int
{
    return (int) seed_scalar("SELECT user_id FROM users WHERE user_email = '" . seed_esc($email) . "' LIMIT 1");
}

function seed_department(string $name): int
{
    return (int) seed_scalar("SELECT client_id FROM clients WHERE client_name = '" . seed_esc($name) . "' LIMIT 1");
}

function seed_contact(?string $email): int
{
    if (!$email) {
        return 0;
    }
    return (int) seed_scalar("SELECT contact_id FROM contacts WHERE contact_email = '" . seed_esc($email) . "' LIMIT 1");
}

function seed_location(?string $name): int
{
    if (!$name) {
        return 0;
    }
    return (int) seed_scalar("SELECT location_id FROM locations WHERE location_name = '" . seed_esc($name) . "' LIMIT 1");
}

/** An infrastructure asset by name, or null when the 10-infrastructure seed has not run. */
function seed_asset(?string $name): ?array
{
    global $mysqli;
    if (!$name) {
        return null;
    }
    $result = mysqli_query($mysqli, "SELECT asset_id, asset_client_id FROM assets WHERE asset_name = '" . seed_esc($name) . "' AND asset_archived_at IS NULL LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? ['id' => (int) $row['asset_id'], 'client_id' => (int) $row['asset_client_id']] : null;
}

/** "n days ago" as a SQL expression, so seeded data always looks recent. */
function seed_ago(int $days, int $hours = 0): string
{
    return "(NOW() - INTERVAL $days DAY - INTERVAL $hours HOUR)";
}

/* ------------------------------------------------------------------ people */

$alex   = seed_user('alex.morgan@summitridge.example');
$priya  = seed_user('priya.nair@summitridge.example');
$marcus = seed_user('marcus.lee@summitridge.example');
if (!$alex || !$priya || !$marcus) {
    fwrite(STDERR, "The 00-core seed has not been applied (agent users are missing).\n");
    exit(1);
}
$editors = [
    'alex'   => $alex,
    'priya'  => $priya,
    'marcus' => $marcus,
];

/* ================================================================== KNOWLEDGE BASE */

/* ---- categories --------------------------------------------------------------- */

function seed_category(string $name): int
{
    global $mysqli, $stats;
    $existing = seed_scalar("SELECT kb_category_id FROM kb_categories WHERE kb_category_name = '" . seed_esc($name) . "' AND kb_category_archived_at IS NULL LIMIT 1");
    if ($existing) {
        return (int) $existing;
    }
    $order = (int) seed_scalar("SELECT COALESCE(MAX(kb_category_order), -1) + 1 FROM kb_categories WHERE kb_category_parent_id = 0");
    seed_exec("INSERT INTO kb_categories SET kb_category_name = '" . seed_esc($name) . "', kb_category_parent_id = 0, kb_category_client_id = 0, kb_category_order = $order");
    $stats['categories']++;
    return (int) mysqli_insert_id($mysqli);
}

$category = [];
foreach (['Getting Started', 'Accounts & Passwords', 'Printing', 'Network & Wi-Fi', 'Security', 'Hardware & Devices'] as $name) {
    $category[$name] = seed_category($name);
}

/* ---- pictures and files that belong to articles -------------------------------- */

/** A labelled floor-plan PNG drawn with GD (best effort - returns null when GD/FreeType is missing). */
function seed_floorplan_png(): ?string
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
        return null;
    }
    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $bold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    if (!is_file($font) || !is_file($bold)) {
        return null;
    }
    $w = 900;
    $h = 460;
    $img = imagecreatetruecolor($w, $h);
    $white  = imagecolorallocate($img, 255, 255, 255);
    $paper  = imagecolorallocate($img, 244, 247, 250);
    $wall   = imagecolorallocate($img, 71, 85, 105);
    $room   = imagecolorallocate($img, 226, 232, 240);
    $accent = imagecolorallocate($img, 234, 88, 12);
    $ink    = imagecolorallocate($img, 30, 41, 59);
    $muted  = imagecolorallocate($img, 100, 116, 139);
    imagefilledrectangle($img, 0, 0, $w, $h, $paper);
    imagettftext($img, 15, 0, 24, 34, $ink, $bold, 'Headquarters - Madison, 2nd floor');

    // [x1, y1, x2, y2, label, filled]
    $rooms = [
        [24, 56, 236, 250, 'Stairwell', true],
        [236, 56, 470, 250, 'Finance suite', true],
        [470, 56, 690, 250, 'Copy room', true],
        [690, 56, 876, 250, 'IT office', true],
        [24, 250, 470, 436, 'Open office', true],
        [470, 250, 690, 436, 'Meeting room 2A', true],
        [690, 250, 876, 436, 'Kitchen', true],
    ];
    foreach ($rooms as [$x1, $y1, $x2, $y2, $label]) {
        imagefilledrectangle($img, $x1, $y1, $x2, $y2, $room);
        imagerectangle($img, $x1, $y1, $x2, $y2, $wall);
        imagettftext($img, 12, 0, $x1 + 14, $y1 + 28, $muted, $font, $label);
    }
    // Highlight the copy room and mark the printer.
    imagefilledrectangle($img, 470, 56, 690, 250, imagecolorallocate($img, 255, 237, 213));
    imagerectangle($img, 470, 56, 690, 250, $accent);
    imagerectangle($img, 471, 57, 689, 249, $accent);
    imagettftext($img, 12, 0, 484, 84, $accent, $bold, 'Copy room');
    imagefilledrectangle($img, 540, 130, 620, 176, $accent);
    imagefilledrectangle($img, 552, 118, 608, 132, $accent);
    imagefilledrectangle($img, 556, 160, 604, 190, $white);
    imagettftext($img, 11, 0, 484, 216, $ink, $bold, 'Ricoh IM C3000');
    imagettftext($img, 10, 0, 484, 234, $muted, $font, 'print queue HQ-Copyroom');
    imagettftext($img, 10, 0, 24, 452, $muted, $font, 'Not to scale.');

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return $png ?: null;
}

/** A tiny one-page PDF (base-14 Helvetica, no embedded fonts) so the attachment can really be viewed. */
function seed_simple_pdf(string $title, array $lines): string
{
    $pdf_text = static fn (string $s): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $content = "BT /F1 18 Tf 56 780 Td (" . $pdf_text($title) . ") Tj ET\n";
    $y = 748;
    foreach ($lines as $line) {
        $content .= "BT /F1 12 Tf 56 $y Td (" . $pdf_text($line) . ") Tj ET\n";
        $y -= 20;
    }
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        4 => "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream",
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "$number 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

/** Store an attachment the way agent/post/kb_article.php does: name via checkFileUpload(), file under uploads/kb/<id>/. */
function seed_attachment(int $article_id, string $display_name, string $bytes, int $days_ago): void
{
    global $app, $stats;
    $exists = seed_scalar("SELECT kb_article_attachment_id FROM kb_article_attachments WHERE kb_article_attachment_kb_article_id = $article_id AND kb_article_attachment_name = '" . seed_esc($display_name) . "' LIMIT 1");
    if ($exists) {
        return;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'kbatt');
    file_put_contents($tmp, $bytes);
    $reference = checkFileUpload(['name' => $display_name, 'tmp_name' => $tmp, 'size' => strlen($bytes)],
        ['pdf', 'txt', 'png', 'docx', 'xlsx', 'zip']);
    if (!isUploadReferenceName($reference)) {
        unlink($tmp);
        fwrite(STDERR, "Could not name attachment $display_name\n");
        return;
    }
    $dir = "$app/uploads/kb/$article_id";
    if (!is_dir("$app/uploads/kb")) {
        mkdir("$app/uploads/kb", 0775, true);
    }
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    copy($tmp, "$dir/$reference");
    unlink($tmp);
    seed_exec("INSERT INTO kb_article_attachments SET kb_article_attachment_name = '" . seed_esc($display_name) . "', kb_article_attachment_reference_name = '" . seed_esc($reference) . "', kb_article_attachment_kb_article_id = $article_id, kb_article_attachment_created_at = " . seed_ago($days_ago));
    $stats['attachments']++;
}

/* ---- article text -------------------------------------------------------------- */
/*
 * Each article lists its versions oldest first. Like the app, a version row holds the text an
 * edit REPLACED: the live article is always newer than the newest version in the history.
 * Tokens in double braces are filled in once the ids are known: {{IMG_FLOORPLAN}} (inline
 * picture) and {{CRED_FIREWALL}} (a [[credential:ID]] reference).
 */

$articles = [];

/* 1 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Connecting to the Office Printer',
    'category' => 'Printing', 'department' => null, 'visible' => 1,
    'author' => 'priya', 'created' => 118, 'updated' => 9,
    'review' => [80, 'priya'],
    'versions' => [
        ['editor' => 'priya', 'days' => 62, 'html' => <<<'HTML'
<h2>Connecting to the printer</h2>
<p>Use the shared printer at <code>\\PRINTSRV\Copyroom</code>.</p>
<ol>
<li>Click Start, type <em>printers</em> and open <strong>Printers &amp; scanners</strong>.</li>
<li>Click <strong>Add a printer</strong> and choose the copy room printer.</li>
<li>Install the driver from the IT share if Windows asks for one.</li>
</ol>
HTML],
        ['editor' => 'marcus', 'days' => 31, 'html' => <<<'HTML'
<h2>Before you start</h2>
<p>You can print from any company laptop while you are in the office. Personal devices cannot use the office printers.</p>
<h2>Windows 11</h2>
<ol>
<li>Open <strong>Settings</strong> and go to <strong>Bluetooth &amp; devices</strong> &rarr; <strong>Printers &amp; scanners</strong>.</li>
<li>Select <strong>Add device</strong> and wait a few seconds for the list to fill.</li>
<li>Pick your printer from the table below. If it does not appear, choose <strong>Add manually</strong> and type the path from the table.</li>
<li>Windows installs the driver from the print server. No download is needed.</li>
</ol>
<h2>Printers by site</h2>
<table border="1" style="border-collapse: collapse; width: 100%;">
<tbody>
<tr><th>Site</th><th>Printer</th><th>Print queue</th></tr>
<tr><td>Headquarters - copy room</td><td>Ricoh IM C3000</td><td>\\SRV-PRINT-01\HQ-Copyroom</td></tr>
<tr><td>Plant 1 - quality lab</td><td>HP LaserJet Enterprise M507</td><td>\\SRV-PRINT-01\Plant1-Quality</td></tr>
</tbody>
</table>
<p>Still stuck? Open a ticket and include the printer name shown on its label.</p>
HTML],
    ],
    'html' => <<<'HTML'
<h2>Before you start</h2>
<p>You can print from any company laptop while you are on the office network. Working from home? Connect to the VPN first. Personal devices cannot use the office printers.</p>
<h2>Where to find it</h2>
<p>The Headquarters copy-room printer is on the 2nd floor, between the finance suite and the IT office.</p>
<p><img src="{{IMG_FLOORPLAN}}" alt="Floor plan of the Headquarters 2nd floor with the copy-room printer highlighted" width="640"></p>
<h2>Windows 11</h2>
<ol>
<li>Open <strong>Settings</strong> and go to <strong>Bluetooth &amp; devices</strong> &rarr; <strong>Printers &amp; scanners</strong>.</li>
<li>Select <strong>Add device</strong> and wait a few seconds for the list to fill.</li>
<li>Pick the printer for your site from the table below. If it does not appear, choose <strong>Add manually</strong>, then <strong>Select a shared printer by name</strong>, and type the path from the table.</li>
<li>Select <strong>Next</strong>. Windows installs the driver from the file server, so no download is needed.</li>
<li>Print a test page: right-click the printer, choose <strong>Printer properties</strong>, then <strong>Print Test Page</strong>.</li>
</ol>
<h2>macOS</h2>
<ol>
<li>Open <strong>System Settings</strong> &rarr; <strong>Printers &amp; Scanners</strong> and select <strong>Add Printer, Scanner or Fax</strong>.</li>
<li>Open the <strong>IP</strong> tab, enter the printer's IP address from the table and set <strong>Protocol</strong> to <strong>Line Printer Daemon - LPD</strong>.</li>
<li>Give the printer a clear name, leave <strong>Use</strong> on <strong>Generic PostScript Printer</strong> and select <strong>Add</strong>.</li>
</ol>
<h2>Printers by site</h2>
<table border="1" style="border-collapse: collapse; width: 100%;">
<tbody>
<tr><th>Site</th><th>Printer</th><th>Print queue (Windows)</th><th>IP address</th></tr>
<tr><td>Headquarters - 2nd floor copy room</td><td>Ricoh IM C3000 (color)</td><td>\\SRV-FILE-01\HQ-Copyroom</td><td>10.10.20.41</td></tr>
<tr><td>Plant 1 - quality lab</td><td>HP LaserJet Enterprise M507</td><td>\\SRV-FILE-01\Plant1-Quality</td><td>10.20.5.31</td></tr>
<tr><td>Distribution Center - shipping dock</td><td>Zebra ZT411 (labels only)</td><td>\\SRV-FILE-01\DC-Labels</td><td>10.30.5.60</td></tr>
</tbody>
</table>
<h2>If nothing prints</h2>
<ul>
<li>Check that the printer shows as <strong>Ready</strong> and is not paused.</li>
<li>Confirm you are on the office Wi-Fi, a wired port or the VPN.</li>
<li>Restart your laptop. This also restarts the Windows print service.</li>
<li>Still stuck? Use <strong>Request Something</strong> in the portal or call the service desk on extension 100, and give the printer name shown on its label.</li>
</ul>
HTML,
    'attachments' => [
        ['name' => 'Printer-Cheat-Sheet.txt', 'days' => 30, 'kind' => 'text', 'text' => "Summit Ridge Manufacturing - printer cheat sheet\n\nHQ copy room ........ \\\\SRV-FILE-01\\HQ-Copyroom (10.10.20.41)\nPlant 1 quality lab . \\\\SRV-FILE-01\\Plant1-Quality (10.20.5.31)\nDistribution Center . \\\\SRV-FILE-01\\DC-Labels (10.30.5.60, labels only)\n\nService desk: extension 100\n"],
    ],
    'image' => 'floorplan',
];

/* 2 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Setting Up Multi-Factor Authentication (MFA)',
    'category' => 'Accounts & Passwords', 'department' => null, 'visible' => 1,
    'author' => 'marcus', 'created' => 84, 'updated' => 27,
    'review' => [120, 'marcus'],
    'versions' => [
        ['editor' => 'marcus', 'days' => 27, 'html' => <<<'HTML'
<h2>Text-message codes</h2>
<p>When you sign in to email from outside the office you are sent a six-digit code by text message. Enter the code to finish signing in.</p>
<p>Changed your phone number? Call the service desk so we can update it.</p>
HTML],
    ],
    'html' => <<<'HTML'
<p>Multi-factor authentication (MFA) asks for a second proof of identity when you sign in to email and the VPN. It stops most account takeovers, even when a password has leaked. You set it up once; it takes about five minutes.</p>
<h2>What you need</h2>
<ul>
<li>Your work account and password.</li>
<li>A smartphone with a camera.</li>
<li>The <strong>Microsoft Authenticator</strong> app (free from your phone's app store).</li>
</ul>
<h2>Set it up</h2>
<ol>
<li>On your computer, open the <strong>Security info</strong> page linked from the intranet home page and sign in.</li>
<li>Select <strong>Add sign-in method</strong>, then <strong>Authenticator app</strong>, then <strong>Next</strong>.</li>
<li>On your phone open Microsoft Authenticator, choose <strong>Add account</strong>, then <strong>Work or school account</strong>, then <strong>Scan a QR code</strong>.</li>
<li>Scan the code on your computer screen and approve the test notification on your phone.</li>
<li>Add a second method (a phone number for text messages) so you are not locked out if you lose the phone.</li>
</ol>
<h2>Using it</h2>
<p>Approve the prompt on your phone when you sign in. The six-digit number the app shows is also the token code the VPN asks for.</p>
<h2>Lost or replaced phone?</h2>
<p>Call the service desk on extension 100, ideally before you wipe the old phone. We remove the old device so you can enrol the new one.</p>
HTML,
];

/* 3 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Verifying a Caller Before a Password Reset',
    'category' => 'Accounts & Passwords', 'department' => null, 'visible' => 0,
    'author' => 'alex', 'created' => 140, 'updated' => 21,
    'versions' => [
        ['editor' => 'alex', 'days' => 96, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong></p>
<p>Ask the caller for their employee ID, then reset their password.</p>
HTML],
        ['editor' => 'priya', 'days' => 48, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Use this before you reset a password or unlock an account for someone who contacts the service desk.</p>
<ol>
<li>Open the person's record and confirm their <strong>Employee ID</strong> and department with the caller.</li>
<li>Call back on the mobile number on file.</li>
<li>Reset the password and tick <strong>User must change password at next sign-in</strong>.</li>
</ol>
HTML],
    ],
    'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Use this before you reset a password, unlock an account or change a phone number for someone who contacts the service desk by phone or chat.</p>
<h2>Check who you are talking to</h2>
<ol>
<li>Find the person under <strong>People</strong> and open their record. Confirm the <strong>Employee ID</strong>, job title and department with the caller.</li>
<li>Ask one more question only the employee would know, such as their manager's name or their start date.</li>
<li>Call back on the <strong>mobile number on file</strong>, not on a number the caller gives you.</li>
</ol>
<h2>Do not reset if</h2>
<ul>
<li>The caller cannot answer both questions.</li>
<li>The request arrives by email or chat from someone who says they cannot talk.</li>
<li>The caller is in a hurry and pushes you to skip a step. Pressure to skip steps is itself a warning sign.</li>
</ul>
<h2>Do the reset</h2>
<ol>
<li>Reset the password in the directory and tick <strong>User must change password at next sign-in</strong>.</li>
<li>Read the temporary password out over the phone only. Never put it in a ticket or chat.</li>
<li>Note in the ticket how you verified the person.</li>
</ol>
HTML,
];

/* 4 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'VPN Troubleshooting for the Service Desk',
    'category' => 'Network & Wi-Fi', 'department' => null, 'visible' => 0,
    'author' => 'marcus', 'created' => 105, 'updated' => 6,
    'review' => [45, 'marcus'],
    'versions' => [
        ['editor' => 'marcus', 'days' => 70, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong></p>
<h2>VPN will not connect</h2>
<ol>
<li>Ask the user to restart the VPN client.</li>
<li>If it still fails, issue a new token.</li>
</ol>
HTML],
        ['editor' => 'marcus', 'days' => 33, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Work through these checks in order.</p>
<ol>
<li>Ask the user to restart the VPN client and try again.</li>
<li>Ask them to switch to their phone's hotspot. If that works, their home network blocks the VPN.</li>
<li>Check that the token code is current. Issue a new token if it is rejected twice.</li>
<li>Still failing? Open a ticket for the Network Administrator with the exact error message.</li>
</ol>
HTML],
    ],
    'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Use the decision tree first. It covers most calls without needing the Network Administrator.</p>
<div class="ikb" data-ikb="tree" data-ikb-key="vpntree01" data-ikb-start="n1">
<h4 class="ikb-title">User cannot connect to the VPN</h4>
<div class="ikb-node" data-ikb-node="n1"><p>Does the VPN client show an error <em>before</em> it asks for the token code?</p>
<ul><li><a href="#ikb-vpntree01-n2" data-ikb-go="n2">Yes, it fails straight away</a></li><li><a href="#ikb-vpntree01-n3" data-ikb-go="n3">No, it asks for the token code and then fails</a></li></ul></div>
<div class="ikb-node" data-ikb-node="n2"><p>Ask the user to switch to their phone's hotspot and try again. Does it connect?</p>
<ul><li><a href="#ikb-vpntree01-n4" data-ikb-go="n4">Yes</a></li><li><a href="#ikb-vpntree01-n5" data-ikb-go="n5">No</a></li></ul></div>
<div class="ikb-node" data-ikb-node="n3"><p><strong>Answer:</strong> The token code is out of step or expired. Issue a new token, ask the user to add it in the authenticator app and try again.</p></div>
<div class="ikb-node" data-ikb-node="n4"><p><strong>Answer:</strong> The user's home network or router blocks the VPN. Ask them to work from the hotspot for now and note the router make in the ticket.</p></div>
<div class="ikb-node" data-ikb-node="n5"><p><strong>Answer:</strong> Check the firewall's VPN service from the console. If it is running, open a High priority ticket for the Network Administrator with the exact error text.</p></div>
</div>
<h2>Quick reference</h2>
<table border="1" style="border-collapse: collapse; width: 100%;">
<tbody>
<tr><th>Setting</th><th>Value</th></tr>
<tr><td>Server address</td><td>vpn.summitridge.example</td></tr>
<tr><td>Sign-in</td><td>Work email address and the six-digit token code</td></tr>
<tr><td>Who can use it</td><td>Company laptops only</td></tr>
</tbody>
</table>
<p>A printable copy is attached as <strong>VPN-Quick-Reference.pdf</strong>.</p>
HTML,
    'attachments' => [
        ['name' => 'VPN-Quick-Reference.pdf', 'days' => 6, 'kind' => 'pdf', 'title' => 'Summit Ridge VPN - quick reference', 'lines' => [
            'Server address: vpn.summitridge.example',
            'Sign-in: work email address and six-digit token code',
            'Company laptops only.',
            '',
            'Cannot connect? Try the phone hotspot, then check the token code.',
            'Escalate to the Network Administrator with the exact error text.',
        ]],
    ],
];

/* 5 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Site Network Reference',
    'category' => 'Network & Wi-Fi', 'department' => null, 'visible' => 0,
    'author' => 'marcus', 'created' => 52, 'updated' => 52,
    'versions' => [],
    'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Addressing and wireless networks at each Summit Ridge site.</p>
<table border="1" style="border-collapse: collapse; width: 100%;">
<tbody>
<tr><th>Site</th><th>Address range</th><th>Gateway</th><th>Wireless networks</th></tr>
<tr><td>Headquarters - Madison</td><td>10.10.0.0/16</td><td>10.10.1.1</td><td>SummitRidge-Staff, SummitRidge-Guest</td></tr>
<tr><td>Plant 1 - Sun Prairie</td><td>10.20.0.0/16</td><td>10.20.1.1</td><td>SummitRidge-Staff, SR-Plant</td></tr>
<tr><td>Distribution Center - Milwaukee</td><td>10.30.0.0/16</td><td>10.30.1.1</td><td>SummitRidge-Staff, SR-Warehouse-Scan</td></tr>
</tbody>
</table>
<h2>Headquarters segments</h2>
<ul>
<li><strong>10.10.1.0/24</strong> servers and network equipment</li>
<li><strong>10.10.20.0/24</strong> offices, printers and copiers</li>
<li><strong>10.10.30.0/24</strong> guest Wi-Fi (internet only)</li>
</ul>
<h2>Notes</h2>
<ul>
<li>The three sites are joined by IKEv2 tunnels that terminate on the edge firewalls.</li>
<li>SR-Plant and SR-Warehouse-Scan are for tablets and handheld scanners that IT has already configured. Do not give the key to users.</li>
<li>Printers hold fixed addresses. Record any change in the Printers list.</li>
</ul>
HTML,
];

/* 6 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Handling a Reported Phishing Email',
    'category' => 'Security', 'department' => null, 'visible' => 0,
    'author' => 'priya', 'created' => 132, 'updated' => 14,
    'review' => [150, 'priya'],
    'versions' => [
        ['editor' => 'priya', 'days' => 88, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong></p>
<ol>
<li>Look at the message.</li>
<li>If it is bad, delete it from the mailbox.</li>
</ol>
HTML],
        ['editor' => 'marcus', 'days' => 40, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong></p>
<h2>Triage</h2>
<ol>
<li>Open the message headers and note the sender, the return path and any links.</li>
<li>Search the mail log for the same sender or subject.</li>
</ol>
<h2>If it is malicious</h2>
<ul>
<li>Block the sender on the mail filter.</li>
<li>Remove the message from every mailbox that received it.</li>
</ul>
HTML],
    ],
    'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Employees report suspicious email with the <strong>Report Phishing</strong> button or by opening a ticket. Work through these steps for every report.</p>
<h2>Triage</h2>
<ol>
<li>Open the message headers and note the sender address, the return path and any links.</li>
<li>Never open attachments on your own laptop. Use the sandbox workstation.</li>
<li>Search the mail log for the same sender or subject to see who else received it.</li>
</ol>
<h2>If it is malicious</h2>
<ul>
<li>Block the sender and the linked domain on the mail filter.</li>
<li>Remove the message from every mailbox that received it.</li>
<li>If anyone clicked or typed a password, reset that person's password, sign them out everywhere and open a <strong>High</strong> priority ticket.</li>
</ul>
<h2>Close the loop</h2>
<p>Reply to the employee who reported it, say thank you and tell them what you found. Add a line to the monthly security summary.</p>
HTML,
];

/* 7 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Firewall Change Procedure',
    'category' => 'Security', 'department' => null, 'visible' => 0,
    'author' => 'marcus', 'created' => 160, 'updated' => 75,
    'review' => [-12, 'marcus'],
    'versions' => [
        ['editor' => 'marcus', 'days' => 75, 'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong></p>
<ol>
<li>Tell the Network Administrator what you plan to change.</li>
<li>Save a copy of the configuration.</li>
<li>Make the change and test it.</li>
</ol>
HTML],
    ],
    'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Every change to the edge firewall follows this procedure. Emergency changes are documented afterwards, in the same change record.</p>
<h2>Before the change</h2>
<ol>
<li>Open a change record and get approval from the Network Administrator.</li>
<li>Export the running configuration and save it to <code>I:\Firewall\Backups</code>.</li>
<li>Book a maintenance window outside production shifts (06:00 to 18:00).</li>
<li>Tell the service desk which sites may notice a short interruption.</li>
</ol>
<h2>Sign in to the firewall</h2>
<p>The administrator login is kept in the credential vault. Use this button to open it: {{CRED_FIREWALL}}</p>
<p>Sign in with the one-time code from the same credential. Never save the password in a browser.</p>
<h2>Make the change</h2>
<ol>
<li>Change one rule at a time and add the change record number to the rule comment.</li>
<li>Apply the change and confirm from a laptop on the office network and from one on the VPN.</li>
<li>Watch the log for ten minutes for denied traffic you did not expect.</li>
</ol>
<h2>After the change</h2>
<ul>
<li>Save the new configuration next to the old one, with the date in the file name.</li>
<li>Close the change record and note anything that surprised you.</li>
<li>If something breaks, restore the exported configuration first and investigate second.</li>
</ul>
HTML,
];

/* 8 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Laptop Imaging and Deployment Runbook',
    'category' => 'Hardware & Devices', 'department' => null, 'visible' => 0,
    'author' => 'priya', 'created' => 70, 'updated' => 70,
    'versions' => [],
    'progress' => ['user' => 'alex', 'block' => 'imgrun01', 'parts' => ['imgstep01', 'imgstep02', 'imgstep03', 'imgstep04']],
    'html' => <<<'HTML'
<p><strong>Internal - IT staff only.</strong> Follow the checklist top to bottom for every new or rebuilt laptop. Your ticks are saved for you, so you can leave and come back.</p>
<div class="ikb" data-ikb="sequence" data-ikb-mode="checklist" data-ikb-key="imgrun01">
<h4 class="ikb-title">Image and hand over a laptop</h4>
<div class="ikb-part" data-ikb-part="imgstep01"><h5 class="ikb-label">Record the asset tag and serial number</h5><div class="ikb-body"><p>Check that the sticker on the lid and the serial number in the BIOS match the purchase record.</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep02"><h5 class="ikb-label">Boot from the deployment USB</h5><div class="ikb-body"><p>Press <strong>F12</strong> at power-on and choose the USB drive.</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep03"><h5 class="ikb-label">Apply the Summit Ridge standard image</h5><div class="ikb-body"><p>Choose the internal drive as the target. This erases the disk.</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep04"><h5 class="ikb-label">Name the laptop after its asset tag</h5><div class="ikb-body"><p>Use the pattern <code>LT-DEPT-NN</code>, for example <code>LT-FIN-02</code>.</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep05"><h5 class="ikb-label">Join the domain and run Windows Update</h5><div class="ikb-body"><p>Repeat until Windows Update reports nothing left to install.</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep06"><h5 class="ikb-label">Install department software</h5><div class="ikb-body"><p>Installers are on the <strong>IT Software Library</strong> drive (I:).</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep07"><h5 class="ikb-label">Turn on disk encryption</h5><div class="ikb-body"><p>Enable BitLocker and save the recovery key to the laptop's asset record.</p></div></div>
<div class="ikb-part" data-ikb-part="imgstep08"><h5 class="ikb-label">Update the asset record and close the ticket</h5><div class="ikb-body"><p>Set the assigned employee, add the warranty end date and attach the hand-over form.</p></div></div>
</div>
<h2>Copy and paste</h2>
<p>Run this once the laptop is on the network to pull the latest policies:</p>
<pre class="ikb-copy" data-ikb="copy"><code>gpupdate /force</code></pre>
HTML,
];

/* 9 ---------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Label Printer Quick Fixes',
    'category' => 'Printing', 'department' => 'Warehouse & Logistics', 'visible' => 1,
    'author' => 'priya', 'created' => 39, 'updated' => 39,
    'versions' => [],
    'html' => <<<'HTML'
<p>For the Zebra ZT411 label printer on the shipping dock. Try these fixes in order before you ask for help.</p>
<h2>Labels print blank or faded</h2>
<ol>
<li>Check the label roll is <strong>direct thermal</strong>, or that a ribbon is loaded for <strong>thermal transfer</strong> labels. The rolls at station 3 are thermal transfer.</li>
<li>Raise the <strong>Darkness</strong> setting by 2 in the printer's front menu.</li>
<li>Clean the print head with an alcohol wipe while the printer is off and cool.</li>
</ol>
<h2>Labels skip or print off the edge</h2>
<ol>
<li>Open the printer menu and choose <strong>Tools</strong> &rarr; <strong>Calibrate</strong>.</li>
<li>Wait for two or three blank labels to feed out.</li>
<li>Print one test label from the warehouse system before you print a full batch.</li>
</ol>
<h2>The printer shows a red light</h2>
<ul>
<li><strong>Out of ribbon or labels:</strong> reload and press the <strong>Pause</strong> button.</li>
<li><strong>Head open:</strong> close the cover until it clicks.</li>
<li>Anything else: write down the message on the screen and use <strong>Request Something</strong> in the portal.</li>
</ul>
HTML,
];

/* 10 --------------------------------------------------------------------------- */
$articles[] = [
    'title' => 'Your First Week: IT Setup Checklist',
    'category' => 'Getting Started', 'department' => null, 'visible' => 1,
    'author' => 'alex', 'created' => 96, 'updated' => 3,
    'review' => [200, 'alex'],
    'versions' => [],
    'html' => <<<'HTML'
<p>Welcome to Summit Ridge. IT sets up your laptop and accounts before you start; these are the things only you can do. Tick each one off as you go. Your ticks are saved.</p>
<div class="ikb" data-ikb="sequence" data-ikb-mode="checklist" data-ikb-key="firstweek1">
<h4 class="ikb-title">Your first-week IT checklist</h4>
<div class="ikb-part" data-ikb-part="fwstep01"><h5 class="ikb-label">Sign in to your laptop</h5><div class="ikb-body"><p>Use the temporary password on your welcome sheet, then choose a new one when asked.</p></div></div>
<div class="ikb-part" data-ikb-part="fwstep02"><h5 class="ikb-label">Set up multi-factor authentication</h5><div class="ikb-body"><p>Follow <em>Setting Up Multi-Factor Authentication (MFA)</em> and keep your phone handy.</p></div></div>
<div class="ikb-part" data-ikb-part="fwstep03"><h5 class="ikb-label">Connect to your office printer</h5><div class="ikb-body"><p>The steps are in <em>Connecting to the Office Printer</em>.</p></div></div>
<div class="ikb-part" data-ikb-part="fwstep04"><h5 class="ikb-label">Set up the VPN if you will work from home</h5><div class="ikb-body"><p>See <em>Use the VPN from home</em>.</p></div></div>
<div class="ikb-part" data-ikb-part="fwstep05"><h5 class="ikb-label">Save the service desk number</h5><div class="ikb-body"><p>The service desk is on extension <strong>100</strong>. Add the main number, 608-555-0100, to your phone as well.</p></div></div>
<div class="ikb-part" data-ikb-part="fwstep06"><h5 class="ikb-label">Read how to spot phishing emails</h5><div class="ikb-body"><p>It takes two minutes: <em>How to spot a phishing email</em>.</p></div></div>
</div>
<h2>Need help?</h2>
<p>Use <strong>Request Something</strong> in this portal or call the service desk. We answer from 7:00 to 17:00, Monday to Friday.</p>
HTML,
];

/* 11 - archived: hidden from every list, visible only under Admin > Knowledge Base > Archived ----------- */
$articles[] = [
    'title' => 'Plant Tablet Wi-Fi Setup (Retired)',
    'category' => 'Network & Wi-Fi', 'department' => 'Production', 'visible' => 0,
    'author' => 'marcus', 'created' => 200, 'updated' => 20, 'archived' => 20,
    'versions' => [],
    'html' => <<<'HTML'
<p><strong>Retired.</strong> The plant tablets used to be joined to the SR-Plant network by hand. IT now configures them with a device profile before they reach the floor, so this procedure is no longer needed.</p>
<p>Kept for reference only.</p>
HTML,
];

/* ================================================================== CREDENTIALS */

// The canonical vault master key is what every credential is encrypted with (Admin > Settings >
// Security). Without it the vault cannot be written outside a signed-in browser session.
$master_key = getCanonicalVaultKey($mysqli);
if (!$master_key) {
    fwrite(STDERR, "No canonical vault key is set, so credentials cannot be encrypted.\n");
    exit(1);
}

function seed_tag(string $name, string $color, string $icon): int
{
    global $mysqli, $stats;
    $existing = seed_scalar("SELECT tag_id FROM tags WHERE tag_name = '" . seed_esc($name) . "' AND tag_type = 4 LIMIT 1");
    if ($existing) {
        return (int) $existing;
    }
    seed_exec("INSERT INTO tags SET tag_name = '" . seed_esc($name) . "', tag_type = 4, tag_color = '" . seed_esc($color) . "', tag_icon = '" . seed_esc($icon) . "'");
    $stats['tags']++;
    return (int) mysqli_insert_id($mysqli);
}

/** A credential folder (folder_location 2) inside one department's workspace. */
function seed_folder(int $client_id, string $name): int
{
    global $mysqli, $stats;
    $existing = seed_scalar("SELECT folder_id FROM folders WHERE folder_client_id = $client_id AND folder_location = 2 AND parent_folder = 0 AND folder_name = '" . seed_esc($name) . "' LIMIT 1");
    if ($existing) {
        return (int) $existing;
    }
    seed_exec("INSERT INTO folders SET folder_name = '" . seed_esc($name) . "', parent_folder = 0, folder_location = 2, folder_client_id = $client_id");
    $stats['folders']++;
    return (int) mysqli_insert_id($mysqli);
}

$tag = [
    'Network gear'  => seed_tag('Network gear', '#17a2b8', 'network-wired'),
    'Server'        => seed_tag('Server', '#6f42c1', 'server'),
    'Cloud service' => seed_tag('Cloud service', '#007bff', 'cloud'),
    'Vendor portal' => seed_tag('Vendor portal', '#fd7e14', 'building'),
    'Shop floor'    => seed_tag('Shop floor', '#28a745', 'industry'),
];

/*
 * 'department' is where the credential lives. When it names an infrastructure asset that
 * already exists (10-infrastructure), the credential moves into that asset's department, so the
 * Relation tab's asset list can show the link. History rows are [field, old, new, editor, days ago];
 * the Password row carries no values, exactly as the app records it.
 * All passwords, keys and OTP secrets below are made up. The OTP secrets are the well-known
 * documentation examples (valid base32, so the app can show a live code).
 */
$credentials = [
    [
        'name' => 'Edge Firewall (FW-EDGE-01) - Admin', 'type' => 'Login',
        'description' => 'Administrator console for the Headquarters edge firewall',
        'username' => 'fwadmin', 'password' => 'FAKE-Fw-Admin-#01', 'otp' => 'JBSWY3DPEHPK3PXP',
        'uri' => 'https://10.10.1.1:8443', 'uri2' => '',
        'note' => "Headquarters edge firewall. Sign-in also needs the one-time code shown in this record.\nAll changes follow the Firewall Change Procedure article in the Knowledge Base.",
        'favorite' => 1, 'department' => 'Executive Office', 'asset' => 'FW-EDGE-01', 'contact' => null,
        'tags' => ['Network gear'], 'folder' => 'Network Devices',
        'created' => 150, 'rotation_due' => 38, 'rotated' => 52,
        'history' => [['Password', null, null, 'marcus', 52], ['URL', 'https://10.10.1.1', 'https://10.10.1.1:8443', 'marcus', 52]],
    ],
    [
        'name' => 'Core Switch (SW-CORE-01) - Admin', 'type' => 'Login',
        'description' => 'Management login for the core switch in the Headquarters server closet',
        'username' => 'netadmin', 'password' => 'FAKE-Sw-Core-#02', 'otp' => '',
        'uri' => 'https://10.10.1.2', 'uri2' => '',
        'note' => 'SSH is enabled on the same login. Password rotation is overdue - see the rotation report.',
        'favorite' => 0, 'department' => 'Executive Office', 'asset' => 'SW-CORE-01', 'contact' => null,
        'tags' => ['Network gear'], 'folder' => 'Network Devices',
        'created' => 140, 'rotation_due' => -12, 'rotated' => 102,
        'history' => [['Password', null, null, 'priya', 102], ['Note', '', 'SSH is enabled on the same login.', 'priya', 102]],
    ],
    [
        'name' => 'File Server (SRV-FILE-01) - Local Administrator', 'type' => 'Login',
        'description' => 'Break-glass local administrator for the Headquarters file server',
        'username' => 'SRV-FILE-01\\Administrator', 'password' => 'FAKE-Srv-Local-#03', 'otp' => '',
        'uri' => '', 'uri2' => '',
        'note' => 'Local account only, for when the domain is unreachable. Domain administrator accounts are never stored here.',
        'favorite' => 0, 'department' => 'Executive Office', 'asset' => 'SRV-FILE-01', 'contact' => null,
        'tags' => ['Server'], 'folder' => null,
        'created' => 130, 'rotation_due' => 75, 'rotated' => 33,
        'history' => [['Password', null, null, 'alex', 33]],
    ],
    [
        'name' => 'Microsoft 365 - Break-glass Admin', 'type' => 'Login',
        'description' => 'Emergency global administrator - use only if normal admin sign-in is unavailable',
        'username' => 'breakglass@summitridge.example', 'password' => 'FAKE-M365-Glass-#04', 'otp' => 'GEZDGNBVGY3TQOJQ',
        'uri' => 'https://admin.m365.example', 'uri2' => '',
        'note' => 'Excluded from conditional-access policies on purpose. Every sign-in is reviewed by the IT team the next working day.',
        'favorite' => 1, 'department' => 'Executive Office', 'asset' => null, 'contact' => null,
        'tags' => ['Cloud service'], 'folder' => null,
        'created' => 160, 'rotation_due' => 160, 'rotated' => 20,
        'history' => [['Password', null, null, 'alex', 20], ['Description', 'Emergency admin account', 'Emergency global administrator - use only if normal admin sign-in is unavailable', 'alex', 20]],
    ],
    [
        'name' => 'Treasury Portal - Company Bank', 'type' => 'Login',
        'description' => 'Company bank treasury portal used for wire approvals',
        'username' => 'summit.treasury', 'password' => 'FAKE-Bank-Portal-#05', 'otp' => 'MFRGGZDFMZTWQ2LK',
        'uri' => 'https://treasury.summitbank.example', 'uri2' => 'https://support.summitbank.example',
        'note' => 'Shared by the Finance Director and the Senior Accountant. Wire approvals need a second approver.',
        'favorite' => 0, 'department' => 'Finance & Accounting', 'asset' => null, 'contact' => 'grace.okafor@summitridge.example',
        'tags' => ['Vendor portal'], 'folder' => null,
        'created' => 110, 'rotation_due' => 20, 'rotated' => 71,
        'history' => [['Password', null, null, 'priya', 71]],
    ],
    [
        'name' => 'Corporate Card Portal', 'type' => 'Login',
        'description' => 'Card statements and limits for the company cards',
        'username' => 'finance-cards', 'password' => 'FAKE-Card-Portal-#06', 'otp' => '',
        'uri' => 'https://cards.summitbank.example', 'uri2' => '',
        'note' => '',
        'favorite' => 0, 'department' => 'Finance & Accounting', 'asset' => null, 'contact' => 'tom.kessler@summitridge.example',
        'tags' => ['Vendor portal'], 'folder' => null,
        'created' => 88, 'rotation_due' => null, 'rotated' => null,
        'history' => [],
    ],
    [
        'name' => 'Marketing Email Platform - API Key', 'type' => 'API Key',
        'description' => 'Used by the newsletter sign-up integration on the company website',
        'username' => 'summit-marketing-prod', 'password' => 'FAKE-key-7f3a91c2d8e04b6a-us1', 'otp' => '',
        'uri' => 'https://api.mailplatform.example/v3', 'uri2' => 'https://dashboard.mailplatform.example',
        'note' => 'Rotate every six months and whenever the marketing owner changes.',
        'favorite' => 0, 'department' => 'Sales & Marketing', 'asset' => null, 'contact' => 'zoe.hartman@summitridge.example',
        'tags' => ['Cloud service'], 'folder' => null,
        'created' => 75, 'rotation_due' => 9, 'rotated' => 170,
        'history' => [['Password', null, null, 'priya', 45]],
    ],
    [
        'name' => 'Inspection Station PC (PC-PLANT-07) - Local Admin', 'type' => 'Login',
        'description' => 'Local administrator on the quality inspection station',
        'username' => 'PC-PLANT-07\\localadmin', 'password' => 'FAKE-Plant07-#08', 'otp' => '',
        'uri' => '', 'uri2' => '',
        'note' => 'Runs the inspection software. Do not install updates during a production shift.',
        'favorite' => 0, 'department' => 'Production', 'asset' => 'PC-PLANT-07', 'contact' => 'aisha.rahman@summitridge.example',
        'tags' => ['Shop floor'], 'folder' => null,
        'created' => 60, 'rotation_due' => 120, 'rotated' => null,
        'history' => [],
    ],
];

$cred_ids = [];
foreach ($credentials as $c) {
    $asset = seed_asset($c['asset']);
    $client_id = ($asset && $asset['client_id'] > 0) ? $asset['client_id'] : seed_department($c['department']);
    if (!$client_id) {
        fwrite(STDERR, "Department {$c['department']} not found - was the 00-core seed applied?\n");
        exit(1);
    }
    $asset_id = $asset ? $asset['id'] : 0;
    $folder_id = $c['folder'] ? seed_folder($client_id, $c['folder']) : 0;
    $contact_id = seed_contact($c['contact']);
    if ($contact_id && (int) seed_scalar("SELECT contact_client_id FROM contacts WHERE contact_id = $contact_id") !== $client_id) {
        $contact_id = 0;   // the Relation tab only offers people from the credential's own department
    }

    $existing = (int) seed_scalar("SELECT credential_id FROM credentials WHERE credential_name = '" . seed_esc($c['name']) . "' LIMIT 1");
    if ($existing) {
        // Re-run after the infrastructure seed appeared: link the asset and move into its department.
        if ($asset_id && !(int) seed_scalar("SELECT credential_asset_id FROM credentials WHERE credential_id = $existing")) {
            seed_exec("UPDATE credentials SET credential_asset_id = $asset_id, credential_client_id = $client_id, credential_folder_id = $folder_id, credential_updated_at = credential_updated_at WHERE credential_id = $existing");
        }
        $cred_ids[$c['name']] = $existing;
        continue;
    }

    // Encrypt through the app's own helper. OTP secrets carry the app's 'enc:' prefix (see encryptOtpSecret()).
    $enc_username = encryptCredentialEntryWithKey($c['username'], $master_key);
    $enc_password = encryptCredentialEntryWithKey($c['password'], $master_key);
    $enc_otp = $c['otp'] !== '' ? 'enc:' . encryptCredentialEntryWithKey($c['otp'], $master_key) : '';

    $rotation_due = $c['rotation_due'] === null ? 'NULL' : "(CURDATE() + INTERVAL {$c['rotation_due']} DAY)";
    $last_rotated = $c['rotated'] === null ? 'NULL' : seed_ago($c['rotated']);
    $changed_at = $c['rotated'] === null ? seed_ago($c['created']) : seed_ago($c['rotated']);

    seed_exec("INSERT INTO credentials SET
        credential_type = '" . seed_esc($c['type']) . "',
        credential_name = '" . seed_esc($c['name']) . "',
        credential_description = '" . seed_esc($c['description']) . "',
        credential_uri = '" . seed_esc($c['uri']) . "',
        credential_uri_2 = '" . seed_esc($c['uri2']) . "',
        credential_username = '" . seed_esc($enc_username) . "',
        credential_password = '" . seed_esc($enc_password) . "',
        credential_otp_secret = '" . seed_esc($enc_otp) . "',
        credential_note = '" . seed_esc($c['note']) . "',
        credential_favorite = " . (int) $c['favorite'] . ",
        credential_folder_id = $folder_id,
        credential_contact_id = $contact_id,
        credential_asset_id = $asset_id,
        credential_client_id = $client_id,
        credential_rotation_due_at = $rotation_due,
        credential_last_rotated_at = $last_rotated,
        credential_password_changed_at = $changed_at,
        credential_created_at = " . seed_ago($c['created']));
    $credential_id = (int) mysqli_insert_id($mysqli);
    $cred_ids[$c['name']] = $credential_id;
    $stats['credentials']++;

    // Prove the round trip through the app's own decrypt helper (never printed).
    $stored = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_password FROM credentials WHERE credential_id = $credential_id"));
    if (decryptCredentialEntryWithKey($stored['credential_password'], $master_key) !== $c['password']) {
        fwrite(STDERR, "Encryption round-trip failed for credential {$c['name']}\n");
        exit(1);
    }

    foreach ($c['tags'] as $tag_name) {
        seed_exec("INSERT IGNORE INTO credential_tags SET credential_id = $credential_id, tag_id = " . $tag[$tag_name]);
    }
    foreach ($c['history'] as [$field, $old, $new, $editor, $days]) {
        $editor_id = $editors[$editor];
        $editor_name = seed_scalar("SELECT user_name FROM users WHERE user_id = $editor_id");
        seed_exec("INSERT INTO credential_history SET history_credential_id = $credential_id, history_user_id = $editor_id,
            history_user_name = '" . seed_esc($editor_name) . "', history_field = '" . seed_esc($field) . "',
            history_old_value = " . ($old === null ? 'NULL' : "'" . seed_esc($old) . "'") . ",
            history_new_value = " . ($new === null ? 'NULL' : "'" . seed_esc($new) . "'") . ",
            history_created_at = " . seed_ago($days));
    }
}

/* ================================================================== KNOWLEDGE BASE ARTICLES */

/** Content columns exactly as agent/post/kb_article.php builds them: HTML plus a tag-stripped search copy. */
function seed_article_columns(string $title, string $html): array
{
    $normalised = \ITFlow\KB\InteractiveBlocks::normalise($html);
    return [
        'html' => $normalised['html'],
        'sql_content' => seed_esc($normalised['html']),
        'sql_raw' => sanitizeInput($title . " " . str_replace("<", " <", $normalised['html'])),
    ];
}

foreach ($articles as $a) {
    $title = $a['title'];
    $author = $editors[$a['author']];
    $client_id = $a['department'] ? seed_department($a['department']) : 0;
    if ($a['department'] && !$client_id) {
        fwrite(STDERR, "Department {$a['department']} not found.\n");
        exit(1);
    }
    $html = str_replace('{{CRED_FIREWALL}}', '[[credential:' . ($cred_ids['Edge Firewall (FW-EDGE-01) - Admin'] ?? 0) . ']]', $a['html']);

    $article_id = (int) seed_scalar("SELECT kb_article_id FROM kb_articles WHERE kb_article_title = '" . seed_esc($title) . "' LIMIT 1");
    $is_new = !$article_id;

    if ($is_new) {
        // Draw the inline picture up front so the article is written once, with its final address.
        $png = null;
        if (($a['image'] ?? '') === 'floorplan') {
            $png = seed_floorplan_png();
            if ($png === null) {
                // No GD/FreeType here: drop the picture paragraph rather than leave a broken image.
                $html = preg_replace('#<p><img src="\{\{IMG_FLOORPLAN\}\}"[^>]*></p>\s*#', '', $html);
            }
        }

        $cols = seed_article_columns($title, $html);
        $reviewer = isset($a['review']) ? $editors[$a['review'][1]] : 0;
        $review_due = isset($a['review']) ? "(CURDATE() + INTERVAL {$a['review'][0]} DAY)" : 'NULL';
        $last_editor = $a['versions'] ? $editors[end($a['versions'])['editor']] : $author;

        seed_exec("INSERT INTO kb_articles SET
            kb_article_title = '" . seed_esc($title) . "',
            kb_article_content = '{$cols['sql_content']}',
            kb_article_content_raw = '{$cols['sql_raw']}',
            kb_article_client_id = $client_id,
            kb_article_category_id = " . (int) $category[$a['category']] . ",
            kb_article_client_visible = " . (int) $a['visible'] . ",
            kb_article_created_by = $author,
            kb_article_updated_by = $last_editor,
            kb_article_created_at = " . seed_ago($a['created']) . ",
            kb_article_updated_at = " . seed_ago($a['updated']) . ",
            kb_article_archived_at = " . (isset($a['archived']) ? seed_ago($a['archived']) : 'NULL') . ",
            kb_article_review_due_at = $review_due,
            kb_article_reviewer_user_id = " . ($reviewer ?: 'NULL'));
        $article_id = (int) mysqli_insert_id($mysqli);
        $stats['articles']++;

        if ($png !== null) {
            $dir = "$app/uploads/kb/$article_id";
            if (!is_dir("$app/uploads/kb")) {
                mkdir("$app/uploads/kb", 0775, true);
            }
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $image_name = bin2hex(random_bytes(16)) . '.png';
            file_put_contents("$dir/$image_name", $png);
            // The canonical, signature-free address the importers store (see agent/post/kb_article.php).
            $html = str_replace('{{IMG_FLOORPLAN}}', "/agent/kb_media.php?a=$article_id&amp;f=$image_name", $html);
            $cols = seed_article_columns($title, $html);
            seed_exec("UPDATE kb_articles SET kb_article_content = '{$cols['sql_content']}', kb_article_content_raw = '{$cols['sql_raw']}',
                kb_article_updated_at = " . seed_ago($a['updated']) . " WHERE kb_article_id = $article_id");
            $stats['images']++;
        }

        // Saved progress for one reader, written through the app's own progress store.
        if (!empty($a['progress'])) {
            $hashes = \ITFlow\KB\InteractiveBlocks::partHashes($cols['html']);
            $items = [];
            foreach ($a['progress']['parts'] as $part) {
                $items[] = ['b' => $a['progress']['block'], 'p' => $part, 's' => 1, 'h' => $hashes[$a['progress']['block']][$part] ?? ''];
            }
            kbProgressSave($mysqli, $article_id, 'u', $editors[$a['progress']['user']], $items);
        }
    }

    // Version history: the text each edit replaced, numbered from 1 (missing numbers only).
    $number = 0;
    foreach ($a['versions'] as $version) {
        $number++;
        if (seed_scalar("SELECT 1 FROM kb_article_versions WHERE kb_article_version_kb_article_id = $article_id AND kb_article_version_number = $number")) {
            continue;
        }
        $vcols = seed_article_columns($title, $version['html']);
        seed_exec("INSERT INTO kb_article_versions SET
            kb_article_version_kb_article_id = $article_id,
            kb_article_version_content = '{$vcols['sql_content']}',
            kb_article_version_content_raw = '{$vcols['sql_raw']}',
            kb_article_version_edited_by = " . $editors[$version['editor']] . ",
            kb_article_version_edited_at = " . seed_ago($version['days']) . ",
            kb_article_version_number = $number");
        $stats['versions']++;
    }

    foreach ($a['attachments'] ?? [] as $att) {
        $bytes = $att['kind'] === 'pdf' ? seed_simple_pdf($att['title'], $att['lines']) : $att['text'];
        seed_attachment($article_id, $att['name'], $bytes, $att['days']);
    }
}

/* ================================================================== PRINTERS */

$printers = [
    [
        'name' => 'HQ Copy Room - Ricoh IM C3000', 'department' => null, 'location' => 'Headquarters - Madison',
        'physical' => '2nd floor copy room', 'model' => 'Ricoh IM C3000 (color MFP)', 'ip' => '10.10.20.41',
        'serial' => 'RCH-C3000-A81724', 'mac' => '00:26:73:4A:1B:C2', 'by' => 'priya', 'created' => 125,
        'notes' => "Main color multifunction printer for Headquarters.\nScan-to-folder saves to the Scans drive (Z:).\nToner and service are covered by the vendor contract - call the vendor first for jams inside the fuser.",
    ],
    [
        'name' => 'Plant 1 Quality Lab - HP LaserJet Enterprise M507', 'department' => null, 'location' => 'Plant 1 - Sun Prairie',
        'physical' => 'Quality lab, next to the inspection station', 'model' => 'HP LaserJet Enterprise M507dn', 'ip' => '10.20.5.31',
        'serial' => 'PHBLN40817', 'mac' => '3C:52:82:1D:5E:A9', 'by' => 'marcus', 'created' => 98,
        'notes' => 'Prints inspection reports and production travellers. Trays are set for 20 lb paper - do not change the paper size.',
    ],
    [
        'name' => 'Distribution Center Shipping Dock - Zebra ZT411', 'department' => null, 'location' => 'Distribution Center - Milwaukee',
        'physical' => 'Shipping dock, station 3', 'model' => 'Zebra ZT411 (203 dpi)', 'ip' => '10.30.5.60',
        'serial' => 'D2J184402711', 'mac' => '00:07:4D:8A:33:F0', 'by' => 'priya', 'created' => 84,
        'notes' => 'Shipping labels only (4 x 6 in). See the Knowledge Base article "Label Printer Quick Fixes (Warehouse)" for ribbon and calibration steps.',
    ],
    [
        'name' => 'Finance Check Printer - HP LaserJet Enterprise M611', 'department' => 'Finance & Accounting', 'location' => null,
        'physical' => 'Finance suite, room 214', 'model' => 'HP LaserJet Enterprise M611dn', 'ip' => '10.10.20.55',
        'serial' => 'PHBLN52233', 'mac' => '3C:52:82:2E:11:0B', 'by' => 'marcus', 'created' => 70,
        'notes' => 'Tray 2 holds pre-printed check stock and is locked. The key is held by the Finance Director.',
    ],
];
foreach ($printers as $p) {
    $client_id = $p['department'] ? seed_department($p['department']) : 0;
    if (seed_scalar("SELECT 1 FROM printers WHERE printer_name = '" . seed_esc($p['name']) . "' AND printer_client_id = $client_id LIMIT 1")) {
        continue;
    }
    $by = $editors[$p['by']];
    seed_exec("INSERT INTO printers SET
        printer_client_id = $client_id,
        printer_location_id = " . seed_location($p['location']) . ",
        printer_name = '" . seed_esc($p['name']) . "',
        printer_ip_address = '" . seed_esc($p['ip']) . "',
        printer_physical_location = '" . seed_esc($p['physical']) . "',
        printer_model = '" . seed_esc($p['model']) . "',
        printer_serial_number = '" . seed_esc($p['serial']) . "',
        printer_mac_address = '" . seed_esc($p['mac']) . "',
        printer_notes = '" . seed_esc($p['notes']) . "',
        printer_created_by = $by, printer_updated_by = $by,
        printer_created_at = " . seed_ago($p['created']));
    $stats['printers']++;
}

/* ================================================================== NETWORK DRIVES */

$drives = [
    ['name' => 'Company Share', 'department' => null, 'letter' => 'S:', 'path' => '\\\\SRV-FILE-01\\Company',
     'purpose' => 'Company-wide policies, forms and templates', 'by' => 'priya', 'created' => 120,
     'notes' => 'Read-only for most staff. HR owns the Policies folder; IT owns the structure.'],
    ['name' => 'Scans', 'department' => null, 'letter' => 'Z:', 'path' => '\\\\SRV-FILE-01\\Scans',
     'purpose' => 'Scan-to-folder destination for the copy-room printers', 'by' => 'marcus', 'created' => 110,
     'notes' => 'Files older than 14 days are deleted automatically. Move anything you need to keep.'],
    ['name' => 'IT Software Library', 'department' => null, 'letter' => 'I:', 'path' => '\\\\SRV-FILE-01\\IT-Software',
     'purpose' => 'Installers and licence keys for IT staff', 'by' => 'alex', 'created' => 100,
     'notes' => 'Agents only. Do not map this drive for regular users.'],
    ['name' => 'Finance Share', 'department' => 'Finance & Accounting', 'letter' => 'F:', 'path' => '\\\\SRV-FILE-01\\Finance',
     'purpose' => 'Month-end close and accounts working files', 'by' => 'priya', 'created' => 90,
     'notes' => "Access is limited to the Finance-Users group.\nNightly snapshot at 02:00, kept for 30 days."],
];
foreach ($drives as $d) {
    $client_id = $d['department'] ? seed_department($d['department']) : 0;
    if (seed_scalar("SELECT 1 FROM network_drives WHERE network_drive_name = '" . seed_esc($d['name']) . "' AND network_drive_client_id = $client_id LIMIT 1")) {
        continue;
    }
    $by = $editors[$d['by']];
    seed_exec("INSERT INTO network_drives SET
        network_drive_client_id = $client_id,
        network_drive_name = '" . seed_esc($d['name']) . "',
        network_drive_letter = '" . seed_esc($d['letter']) . "',
        network_drive_path = '" . seed_esc($d['path']) . "',
        network_drive_purpose = '" . seed_esc($d['purpose']) . "',
        network_drive_notes = '" . seed_esc($d['notes']) . "',
        network_drive_created_by = $by, network_drive_updated_by = $by,
        network_drive_created_at = " . seed_ago($d['created']));
    $stats['drives']++;
}

/* ------------------------------------------------------------------ report (counts only, never secrets) */
echo "20-knowledge: added " . implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($stats), $stats)) . "\n";
$totals = [
    'kb_articles' => (int) seed_scalar("SELECT COUNT(*) FROM kb_articles WHERE kb_article_archived_at IS NULL"),
    'credentials' => (int) seed_scalar("SELECT COUNT(*) FROM credentials WHERE credential_archived_at IS NULL"),
    'printers' => (int) seed_scalar("SELECT COUNT(*) FROM printers WHERE printer_archived_at IS NULL"),
    'network_drives' => (int) seed_scalar("SELECT COUNT(*) FROM network_drives WHERE network_drive_archived_at IS NULL"),
];
echo "20-knowledge: now in the database - " . implode(', ', array_map(fn ($k, $v) => "$v $k", array_keys($totals), $totals)) . "\n";
