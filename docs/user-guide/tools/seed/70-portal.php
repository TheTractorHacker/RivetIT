<?php
/*
 * Demo data for the Department Portal chapter of the user guide (group "portal").
 *
 *   RIVETIT_APP_DIR=<app dir> php docs/user-guide/tools/seed/70-portal.php
 *
 * WHAT IT ADDS (everything is fictional; addresses use the reserved .example domain)
 *   - Two employee portal logins, created the way the app's own "Department Portal" setting does it
 *     (agent/post/contact.php): a users row with user_type = 2 and user_auth_method = 'local', linked to the
 *     person through contacts.contact_user_id.
 *         sophie.tran@summitridge.example   HR Coordinator, ordinary employee (Human Resources)
 *         grace.okafor@summitridge.example  Finance Director, department primary contact (Finance & Accounting)
 *     Both use the shared demo password DemoPass#2026.
 *     These are the ONLY users rows this seed inserts.
 *   - The shared ticket categories (only if missing), tickets with replies for those two departments, one task
 *     that needs the department's approval, and a couple of live-chat lines.
 *   - "Request Something" catalog items, portal-visible Knowledge Base articles, and for the two departments:
 *     laptops, and for Finance & Accounting also documents, a printer, a domain and certificates (they feed the
 *     "Needs Attention" list on a department lead's home page).
 *   - One "Department Portal Nav" custom link, and one share link (a document) for the guest-link screenshots.
 *
 * IDEMPOTENT: every row is guarded by a natural key (subject / name / title / email), so re-running adds nothing.
 * Parents are looked up by name; no numeric id is hard-coded. Nothing secret is printed.
 *
 * It bumps settings.config_ticket_next_number exactly like the portal's own "Raise ticket" does, so the demo
 * tickets take real ticket numbers and can never collide with tickets seeded by other chapters.
 *
 * FIXED KEYS the capture script (capture/portal.cjs) relies on - keep the two files in step:
 *   ticket link key  ......  PORTAL_GUEST_TICKET_KEY   (the HR printer ticket)
 *   shared document key  ..  PORTAL_SHARE_DOC_KEY      (the Finance quick-reference document)
 */

if (php_sapi_name() !== 'cli') {
    exit("Run this from the command line.\n");
}

// Where the app lives. The rebuild script exports RIVETIT_APP_DIR; the fallback is the demo app this chapter was written against.
$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
if (!is_file($app . '/config.php')) {
    fwrite(STDERR, "70-portal: cannot find the app (no config.php in '$app'). Set RIVETIT_APP_DIR.\n");
    exit(1);
}
chdir($app . '/scripts');            // the app uses relative ../ requires, like scripts/setup_cli.php
require_once '../config.php';        // $mysqli and the config_* values
require_once '../functions.php';     // randomString() and friends

const PORTAL_PASSWORD         = 'DemoPass#2026';
const PORTAL_GUEST_TICKET_KEY = 'ugportalprinterticketkey00000001';   // 32 characters, like the app's own keys
const PORTAL_SHARE_DOC_KEY    = 'ugportalsharedocumentkey000000001';  // 33 chars is fine: item_key is a varchar(255)

// ---- tiny helpers -------------------------------------------------------------------------------------------------

function fail(string $msg): void
{
    fwrite(STDERR, "70-portal: $msg\n");
    exit(1);
}

function run(string $sql)
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    if ($r === false) {
        fail('SQL failed: ' . mysqli_error($mysqli) . ' -- ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 200));
    }
    return $r;
}

function esc($v): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $v);
}

/** First column of the first row, or null. */
function val(string $sql)
{
    $row = mysqli_fetch_row(run($sql));
    return $row ? $row[0] : null;
}

function row(string $sql): ?array
{
    return mysqli_fetch_assoc(run($sql)) ?: null;
}

function newId(): int
{
    global $mysqli;
    return (int) mysqli_insert_id($mysqli);
}

/** id of a parent row looked up by name; stops loudly when a core row is missing. */
function need(string $sql, string $what): int
{
    $v = val($sql);
    if ($v === null) {
        fail("$what not found - has 00-core.sql been applied?");
    }
    return (int) $v;
}

function say(string $line): void
{
    echo "  $line\n";
}

/** Plain text of an HTML snippet, for the *_raw search columns. */
function plain(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace('<', ' <', $html))));
}

// ---- who and where ------------------------------------------------------------------------------------------------

$finance = need("SELECT client_id FROM clients WHERE client_name = 'Finance & Accounting'", 'Department Finance & Accounting');
$hr      = need("SELECT client_id FROM clients WHERE client_name = 'Human Resources'", 'Department Human Resources');

$priya  = need("SELECT user_id FROM users WHERE user_email = 'priya.nair@summitridge.example'", 'Agent Priya Nair');
$marcus = need("SELECT user_id FROM users WHERE user_email = 'marcus.lee@summitridge.example'", 'Agent Marcus Lee');

function contactId(string $email): int
{
    return need("SELECT contact_id FROM contacts WHERE contact_email = '" . esc($email) . "' AND contact_archived_at IS NULL LIMIT 1", "Person $email");
}

echo "70-portal: seeding the Department Portal demo data\n";

// ---- 1. portal logins ---------------------------------------------------------------------------------------------

/**
 * Gives an existing person (contact) a Department Portal login, the same way agent/post/contact.php does:
 * INSERT INTO users (... user_auth_method = 'local', user_type = 2), then contacts.contact_user_id = that user.
 * Returns the user id.
 */
function portalLogin(string $email, string $password): int
{
    $c = row("SELECT contact_id, contact_name, contact_user_id FROM contacts WHERE contact_email = '" . esc($email) . "' AND contact_archived_at IS NULL LIMIT 1");
    if (!$c) {
        fail("person $email not found - has 00-core.sql been applied?");
    }
    if ((int) $c['contact_user_id'] > 0) {
        return (int) $c['contact_user_id'];               // already has a login
    }
    $existing = val("SELECT user_id FROM users WHERE user_email = '" . esc($email) . "' AND user_type = 2 LIMIT 1");
    if ($existing !== null) {
        $user_id = (int) $existing;                       // a portal user with this email exists: just link it
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        run("INSERT INTO users SET user_name = '" . esc($c['contact_name']) . "', user_email = '" . esc($email) . "', user_password = '" . esc($hash) . "', user_auth_method = 'local', user_type = 2");
        $user_id = newId();
        say("portal login created for $email");
    }
    run("UPDATE contacts SET contact_user_id = $user_id WHERE contact_id = " . (int) $c['contact_id']);
    return $user_id;
}

$sophie_user = portalLogin('sophie.tran@summitridge.example', PORTAL_PASSWORD);
$grace_user  = portalLogin('grace.okafor@summitridge.example', PORTAL_PASSWORD);

// ---- 2. shared lookups (only if missing - see the shared lookup contract) ------------------------------------------

foreach ([['Hardware', '#6f42c1'], ['Software', '#0d6efd'], ['Network', '#20c997'], ['Access & Accounts', '#fd7e14'], ['Other', '#6c757d']] as [$name, $color]) {
    run("INSERT INTO categories (category_name, category_type, category_color)
         SELECT '" . esc($name) . "', 'Ticket', '$color' FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = '" . esc($name) . "' AND category_type = 'Ticket')");
}

function categoryId(string $name): int
{
    return need("SELECT category_id FROM categories WHERE category_name = '" . esc($name) . "' AND category_type = 'Ticket' LIMIT 1", "Ticket category $name");
}

// ---- 3. assets (a laptop each for the two employees; the Finance ones also feed the lead's pages) -----------------

/**
 * @param string|null $assignee_email person the asset is assigned to
 * @param int         $warranty_days  days from today until the warranty ends (null = not recorded)
 */
function seedAsset(int $client_id, string $name, string $type, string $make, string $model, string $serial, string $description, ?string $assignee_email, ?int $warranty_days, int $purchased_days_ago, string $uri_client = ''): void
{
    if (val("SELECT asset_id FROM assets WHERE asset_name = '" . esc($name) . "' AND asset_client_id = $client_id LIMIT 1") !== null) {
        return;
    }
    $contact = $assignee_email ? contactId($assignee_email) : 0;
    $warranty = $warranty_days === null ? 'NULL' : "CURDATE() + INTERVAL $warranty_days DAY";
    $status_id = val("SELECT category_id FROM categories WHERE category_type = 'asset_status' AND category_name = 'Deployed' LIMIT 1");
    $status = $status_id !== null ? 'Deployed' : '';
    run("INSERT INTO assets SET asset_type = '" . esc($type) . "', asset_name = '" . esc($name) . "', asset_description = '" . esc($description) . "',
            asset_make = '" . esc($make) . "', asset_model = '" . esc($model) . "', asset_serial = '" . esc($serial) . "',
            asset_status = '$status', asset_purchase_date = CURDATE() - INTERVAL $purchased_days_ago DAY,
            asset_warranty_expire = $warranty, asset_install_date = CURDATE() - INTERVAL " . max(1, $purchased_days_ago - 10) . " DAY,
            asset_uri_client = '" . esc($uri_client) . "', asset_contact_id = $contact, asset_client_id = $client_id");
    say("asset $name");
}

seedAsset($hr, 'LT-HR-06', 'Laptop', 'Dell', 'Latitude 5440', 'HR06-4N8K2Z', 'HR Coordinator laptop', 'sophie.tran@summitridge.example', 610, 480);
seedAsset($finance, 'LT-FIN-08', 'Laptop', 'Dell', 'Latitude 7440', 'FIN08-7Q2K9X', 'Finance Director laptop', 'grace.okafor@summitridge.example', 25, 1070, 'https://intranet.summitridge.example/devices/lt-fin-08');
seedAsset($finance, 'DT-FIN-04', 'Desktop', 'HP', 'EliteDesk 800 G9', 'FIN04-2M6D1B', 'Accounts payable workstation', 'lena.fischer@summitridge.example', 300, 540);
seedAsset($finance, 'PRN-FIN-02', 'Printer', 'HP', 'LaserJet Pro M404dn', 'FIN02-9R5T3C', 'Finance office printer', null, 420, 600);

// ---- 4. Finance: documents, a domain and certificates ---------------------------------------------------------------

function seedFolder(int $client_id, string $name): int
{
    $id = val("SELECT folder_id FROM folders WHERE folder_name = '" . esc($name) . "' AND folder_client_id = $client_id LIMIT 1");
    if ($id !== null) {
        return (int) $id;
    }
    run("INSERT INTO folders SET folder_name = '" . esc($name) . "', folder_client_id = $client_id");
    return newId();
}

function seedDocument(int $client_id, string $name, string $description, string $html, int $visible, int $folder_id, int $created_by, int $days_ago): int
{
    $id = val("SELECT document_id FROM documents WHERE document_name = '" . esc($name) . "' AND document_client_id = $client_id LIMIT 1");
    if ($id !== null) {
        return (int) $id;
    }
    run("INSERT INTO documents SET document_name = '" . esc($name) . "', document_description = '" . esc($description) . "',
            document_content = '" . esc($html) . "', document_content_raw = '" . esc($name . ' ' . plain($html)) . "',
            document_client_visible = $visible, document_folder_id = $folder_id, document_created_by = $created_by,
            document_client_id = $client_id, document_created_at = NOW() - INTERVAL $days_ago DAY");
    say("document $name");
    return newId();
}

$month_end_folder = seedFolder($finance, 'Month-End');

$doc_quickref = seedDocument($finance, 'Finance systems quick reference',
    'Where to sign in for the finance tools, and who to call for each one.',
    '<p>This page lists the systems the Finance team uses every day and how to get help with each.</p>
<table>
<thead><tr><th>System</th><th>Used for</th><th>Sign in</th><th>Help</th></tr></thead>
<tbody>
<tr><td>Finance ERP</td><td>Ledger, payables and receivables</td><td>Work email and password</td><td>IT service desk, ext. 100</td></tr>
<tr><td>Bank portal</td><td>Payments and statements</td><td>Bank token plus your own login</td><td>Finance Director</td></tr>
<tr><td>Expense tool</td><td>Staff expense reports</td><td>Work email (single sign-on)</td><td>IT service desk, ext. 100</td></tr>
<tr><td>Finance file share</td><td>Month-end workbooks and audit files</td><td>Connect from the office network or VPN</td><td>IT service desk, ext. 100</td></tr>
</tbody></table>
<p>If a system is unavailable during month-end close, raise a <strong>High</strong> priority ticket so it is picked up first.</p>',
    1, 0, $priya, 40);

seedDocument($finance, 'Month-end close - system access checklist',
    'Check these before the first day of close.',
    '<p>Complete this checklist two working days before month-end close starts.</p>
<ol>
<li>Everyone on the close calendar can sign in to the Finance ERP.</li>
<li>The finance file share opens from the office and over the VPN.</li>
<li>The bank token for the payments approver is charged and paired.</li>
<li>The finance printer has paper and toner for the close reports.</li>
<li>Anyone joining the close team this month has a ticket open for access.</li>
</ol>
<p>Raise a ticket from the portal for anything on this list that is not ready.</p>',
    1, $month_end_folder, $priya, 22);

seedDocument($finance, 'Finance file server - maintenance notes',
    'IT reference. Not shared with the department.',
    '<p>Internal notes for the IT team about the finance file server: patch windows, backup checks and who to call.</p>',
    0, 0, $marcus, 60);

function seedDomain(int $client_id, string $name, string $registrar, int $expires_in_days): void
{
    if (val("SELECT domain_id FROM domains WHERE domain_name = '" . esc($name) . "' AND domain_client_id = $client_id LIMIT 1") !== null) {
        return;
    }
    run("INSERT INTO domains SET domain_name = '" . esc($name) . "', domain_registrar_name = '" . esc($registrar) . "',
            domain_expire = CURDATE() + INTERVAL $expires_in_days DAY, domain_registered_at = CURDATE() + INTERVAL $expires_in_days DAY - INTERVAL 2 YEAR,
            domain_client_id = $client_id");
    say("domain $name");
}

function seedCertificate(int $client_id, string $name, string $fqdn, string $issuer, int $expires_in_days): void
{
    if (val("SELECT certificate_id FROM certificates WHERE certificate_name = '" . esc($name) . "' AND certificate_client_id = $client_id LIMIT 1") !== null) {
        return;
    }
    run("INSERT INTO certificates SET certificate_name = '" . esc($name) . "', certificate_domain = '" . esc($fqdn) . "',
            certificate_issued_by = '" . esc($issuer) . "', certificate_expire = CURDATE() + INTERVAL $expires_in_days DAY,
            certificate_client_id = $client_id");
    say("certificate $name");
}

seedDomain($finance, 'ap-summitridge.example', 'Example Registrar', 21);
seedDomain($finance, 'summitridge-finance.example', 'Example Registrar', 300);
seedCertificate($finance, 'AP portal', 'ap.summitridge.example', 'Summit Ridge Internal CA', 12);
seedCertificate($finance, 'Legacy reports server', 'reports-old.summitridge.example', 'Summit Ridge Internal CA', -9);
seedCertificate($finance, 'Finance file share', 'files.summitridge.example', 'Summit Ridge Internal CA', 240);

// ---- 5. tickets ---------------------------------------------------------------------------------------------------

$prefix = (string) val("SELECT config_ticket_prefix FROM settings WHERE company_id = 1");

function statusId(string $name): int
{
    return need("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = '" . esc($name) . "' LIMIT 1", "Ticket status $name");
}

/** Next ticket number, taken exactly the way client/post.php does it (atomic, so other seeds never collide). */
function nextTicketNumber(): int
{
    run("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    return newId();
}

/**
 * One ticket with its conversation.
 *
 * 'age'      minutes since it was raised          'touched'  minutes since the last activity (null = never touched)
 * 'resolved' / 'closed'  minutes ago (null = not)  'csat'     [rating, comment] on a closed ticket
 * 'replies'  [[who, type, html, minutes ago]]      who = 'agent' | 'contact'; type = Public | Client | System
 */
function seedTicket(array $t): void
{
    global $prefix;
    $client_id = $t['client'];
    if (val("SELECT ticket_id FROM tickets WHERE ticket_subject = '" . esc($t['subject']) . "' AND ticket_client_id = $client_id LIMIT 1") !== null) {
        return;
    }
    $contact_id = contactId($t['contact']);
    $agent      = $t['agent'] ?? 0;
    $status     = statusId($t['status']);
    $number     = nextTicketNumber();
    $key        = $t['url_key'] ?? randomString(32);
    $ago        = fn($min) => $min === null ? 'NULL' : "NOW() - INTERVAL " . (int) $min . " MINUTE";

    $first_reply = null;
    foreach ($t['replies'] ?? [] as $r) {
        if ($r[0] === 'agent' && $r[1] === 'Public') {
            $first_reply = $first_reply === null ? $r[3] : min($first_reply, $r[3]);
        }
    }
    $csat_sql = '';
    if (!empty($t['csat'])) {
        $csat_sql = ", ticket_csat_rating = " . (int) $t['csat'][0] . ", ticket_csat_comment = '" . esc($t['csat'][1]) . "', ticket_csat_rated_at = " . $ago($t['csat'][2] ?? $t['closed']);
    }

    run("INSERT INTO tickets SET ticket_prefix = '" . esc($prefix) . "', ticket_number = $number, ticket_source = '" . esc($t['source']) . "',
            ticket_category = '" . categoryId($t['category']) . "', ticket_subject = '" . esc($t['subject']) . "',
            ticket_details = '" . esc($t['details']) . "', ticket_priority = '" . esc($t['priority']) . "', ticket_status = $status,
            ticket_billable = 0, ticket_url_key = '" . esc($key) . "',
            ticket_created_at = " . $ago($t['age']) . ",
            ticket_updated_at = " . $ago($t['touched'] ?? null) . ",
            ticket_first_response_at = " . $ago($first_reply) . ",
            ticket_resolved_at = " . $ago($t['resolved'] ?? null) . ",
            ticket_closed_at = " . $ago($t['closed'] ?? null) . ",
            ticket_created_by = " . (int) ($t['created_by'] ?? 0) . ", ticket_assigned_to = $agent,
            ticket_closed_by = " . (!empty($t['closed']) ? $agent : 0) . ",
            ticket_client_id = $client_id, ticket_contact_id = $contact_id$csat_sql");
    $ticket_id = newId();

    foreach ($t['replies'] ?? [] as [$who, $type, $html, $minutes]) {
        $by = $who === 'agent' ? $agent : $contact_id;
        run("INSERT INTO ticket_replies SET ticket_reply = '" . esc($html) . "', ticket_reply_type = '" . esc($type) . "',
                ticket_reply_by = $by, ticket_reply_ticket_id = $ticket_id, ticket_reply_emailed = 1,
                ticket_reply_created_at = NOW() - INTERVAL " . (int) $minutes . " MINUTE");
    }
    foreach ($t['chat'] ?? [] as [$who, $text, $minutes]) {
        $sender = $who === 'agent' ? $agent : $contact_id;
        run("INSERT INTO ticket_chat_messages SET ticket_id = $ticket_id, sender_type = '" . ($who === 'agent' ? 'agent' : 'contact') . "',
                sender_id = $sender, message = '" . esc($text) . "', created_at = NOW() - INTERVAL " . (int) $minutes . " MINUTE");
    }
    foreach ($t['tasks'] ?? [] as $i => $task) {
        run("INSERT INTO tasks SET task_name = '" . esc($task['name']) . "', task_ticket_id = $ticket_id, task_order = " . ($i + 1) . ",
                task_completed_at = " . (!empty($task['done']) ? 'NOW() - INTERVAL 1 DAY' : 'NULL') . ",
                task_completed_by = " . (!empty($task['done']) ? $agent : 'NULL') . ", task_progress = " . (!empty($task['done']) ? 100 : 0));
        $task_id = newId();
        if (!empty($task['approval'])) {
            run("INSERT INTO task_approvals SET approval_scope = 'client', approval_type = '" . esc($task['approval']) . "', approval_status = 'pending',
                    approval_created_by = $agent, approval_url_key = '" . esc(randomString(32)) . "', approval_task_id = $task_id");
        }
    }
    say("ticket $prefix$number  " . $t['subject']);
}

$sophie = 'sophie.tran@summitridge.example';
$grace  = 'grace.okafor@summitridge.example';
$tom    = 'tom.kessler@summitridge.example';
$lena   = 'lena.fischer@summitridge.example';

$day = 1440;

// ---- Sophie Tran, HR Coordinator (an ordinary employee): oldest first, so ticket numbers run in time order --------------

seedTicket(['client' => $hr, 'contact' => $sophie, 'source' => 'Portal', 'created_by' => $sophie_user, 'agent' => $marcus,
    'subject' => 'Reset my VPN token', 'category' => 'Access & Accounts', 'priority' => 'Medium', 'status' => 'Closed',
    'details' => '<p>My VPN token stopped working after I changed phones. I am working from home on Friday and need to reach the HR file share.</p>',
    'age' => 27 * $day, 'touched' => 26 * $day, 'resolved' => 26 * $day, 'closed' => 26 * $day,
    'csat' => [5, 'Quick fix and very friendly - thank you!', 26 * $day - 90],
    'replies' => [
        ['agent', 'Public', '<p>Hi Sophie, I have issued a new VPN token. The set-up steps are in the Knowledge Base under Security. Let me know if the sign-in still fails.</p>', 27 * $day - 60],
        ['contact', 'Client', '<p>That worked, thanks!</p>', 27 * $day - 200],
        ['agent', 'System', '<p>Ticket closed.</p>', 26 * $day],
    ]]);

seedTicket(['client' => $hr, 'contact' => $sophie, 'source' => 'Portal', 'created_by' => $sophie_user, 'agent' => $priya,
    'subject' => 'Monitor at the reception desk flickers', 'category' => 'Hardware', 'priority' => 'Low', 'status' => 'Closed',
    'details' => '<p>The monitor at the HR reception desk flickers every few minutes and goes black for a second. It is worse in the afternoon.</p>',
    'age' => 6 * $day, 'touched' => 4 * $day, 'resolved' => 4 * $day, 'closed' => 4 * $day,
    'replies' => [
        ['agent', 'Public', '<p>Hi Sophie, I swapped the HDMI cable at the reception desk and the flicker is gone. I will close this ticket - please raise a new one if it comes back.</p>', 4 * $day + 30],
        ['agent', 'System', '<p>Ticket closed.</p>', 4 * $day],
    ]]);

seedTicket(['client' => $hr, 'contact' => $sophie, 'source' => 'Portal', 'created_by' => $sophie_user, 'agent' => $marcus,
    'subject' => 'New hire laptop setup for next Monday', 'category' => 'Hardware', 'priority' => 'Medium', 'status' => 'On Hold',
    'details' => '<p>We have a new HR assistant starting next Monday. Please set up a laptop, a work email account and access to the HR file share before they arrive.</p>',
    'age' => 5 * $day, 'touched' => 4 * $day + 300,
    'replies' => [
        ['agent', 'Public', '<p>The laptop is on order and should arrive on Thursday. I will set it up and create the accounts as soon as it lands. This ticket stays on hold until then.</p>', 4 * $day + 300],
    ]]);

seedTicket(['client' => $hr, 'contact' => $sophie, 'source' => 'Portal', 'created_by' => $sophie_user, 'agent' => $priya,
    'subject' => 'HR office printer prints blank pages', 'category' => 'Hardware', 'priority' => 'Medium', 'status' => 'Open',
    'url_key' => PORTAL_GUEST_TICKET_KEY,
    'details' => '<p>Since Monday the HR office printer (near the copy room) prints a blank page after every job. I have tried turning it off and on again.</p><p>Offer letters are waiting to be printed, so this is getting urgent.</p>',
    'age' => 3 * $day, 'touched' => 300,
    'replies' => [
        ['agent', 'Public', '<p>Thanks Sophie. The printer shows as online from here. Does it print blank pages from every computer, or only from yours?</p>', 3 * $day - 45],
        ['contact', 'Client', '<p>It does the same from Miguel\'s computer, so it looks like the printer itself.</p>', 2 * $day + 600],
        ['agent', 'Public', '<p>Thanks, that helps. I have booked the printer vendor for tomorrow morning. Please use the second-floor printer until then.</p>', 300],
    ],
    'chat' => [
        ['agent', 'Hi Sophie - the vendor confirmed a visit tomorrow at 9am.', 190],
        ['contact', 'Great, thank you! I will use the second-floor printer today.', 185],
    ]]);

seedTicket(['client' => $hr, 'contact' => $sophie, 'source' => 'Portal', 'created_by' => $sophie_user, 'agent' => $priya,
    'subject' => 'Outlook keeps asking for my password', 'category' => 'Software', 'priority' => 'Medium', 'status' => 'Resolved',
    'details' => '<p>Outlook on my laptop asks me to sign in again every few minutes. Email still works, but the pop-up is very disruptive.</p>',
    'age' => $day + 60, 'touched' => 360, 'resolved' => 360,
    'replies' => [
        ['agent', 'Public', '<p>I cleared the saved credentials on your laptop and signed you back in. Please let us know if the prompt comes back.</p>', 360],
    ]]);

seedTicket(['client' => $hr, 'contact' => $sophie, 'source' => 'Portal', 'created_by' => $sophie_user, 'agent' => 0,
    'subject' => 'Request access to the shared onboarding folder', 'category' => 'Access & Accounts', 'priority' => 'Low', 'status' => 'New',
    'details' => '<p>Could I please have access to the shared <strong>HR Onboarding</strong> folder? I need it to prepare next week\'s new-hire packs.</p>',
    'age' => 45]);

// ---- Finance & Accounting: Grace Okafor (department lead) and two colleagues -------------------------------------------

seedTicket(['client' => $finance, 'contact' => $lena, 'source' => 'Email', 'created_by' => 0, 'agent' => $priya,
    'subject' => 'Add me to the AP approvals mailbox', 'category' => 'Access & Accounts', 'priority' => 'Low', 'status' => 'Closed',
    'details' => '<p>Please add me to the shared accounts-payable approvals mailbox so I can cover for Tom when he is away.</p>',
    'age' => 15 * $day, 'touched' => 14 * $day, 'resolved' => 14 * $day, 'closed' => 14 * $day,
    'replies' => [
        ['agent', 'Public', '<p>Done - the mailbox will appear in Outlook within about an hour. Restart Outlook if it does not.</p>', 14 * $day + 60],
        ['agent', 'System', '<p>Ticket closed.</p>', 14 * $day],
    ]]);

seedTicket(['client' => $finance, 'contact' => $tom, 'source' => 'Email', 'created_by' => 0, 'agent' => $priya,
    'subject' => 'Second monitor not detected on the docking station', 'category' => 'Hardware', 'priority' => 'Medium', 'status' => 'Closed',
    'details' => '<p>My second monitor is not detected when I dock my laptop at my desk. The first monitor works fine.</p>',
    'age' => 9 * $day, 'touched' => 7 * $day, 'resolved' => 7 * $day, 'closed' => 7 * $day,
    'csat' => [4, 'Cable was the problem - thanks.', 7 * $day - 120],
    'replies' => [
        ['agent', 'Public', '<p>The DisplayPort cable was faulty. I have replaced it and both monitors are detected now.</p>', 7 * $day + 40],
        ['agent', 'System', '<p>Ticket closed.</p>', 7 * $day],
    ]]);

seedTicket(['client' => $finance, 'contact' => $grace, 'source' => 'Portal', 'created_by' => $grace_user, 'agent' => $marcus,
    'subject' => 'Renew the accounts-payable web certificate', 'category' => 'Network', 'priority' => 'High', 'status' => 'Open',
    'details' => '<p>The web certificate for the accounts-payable portal is due to expire soon. Please renew it before it lapses so suppliers can keep using the portal.</p>',
    'age' => 6 * $day, 'touched' => 2 * $day,
    'replies' => [
        ['agent', 'Public', '<p>Hi Grace, the certificate expires in 12 days. I have prepared the renewal request. Please approve the renewal date (the task on this ticket) so I can order it.</p>', 2 * $day],
    ],
    'tasks' => [
        ['name' => 'Prepare the certificate signing request', 'done' => true],
        ['name' => 'Approve certificate renewal and install date', 'approval' => 'any'],
    ]]);

seedTicket(['client' => $finance, 'contact' => $tom, 'source' => 'Email', 'created_by' => 0, 'agent' => $priya,
    'subject' => 'Excel freezes when opening the bank reconciliation workbook', 'category' => 'Software', 'priority' => 'Medium', 'status' => 'Open',
    'details' => '<p>Excel freezes for about a minute whenever I open the monthly bank reconciliation workbook. Other workbooks open normally.</p>',
    'age' => 2 * $day, 'touched' => 20 * 60,
    'replies' => [
        ['agent', 'Public', '<p>Thanks Tom. I have seen this with the add-in that ships with the bank export. I will test with add-ins switched off on your laptop this afternoon.</p>', 20 * 60],
    ]]);

seedTicket(['client' => $finance, 'contact' => $grace, 'source' => 'Portal', 'created_by' => $grace_user, 'agent' => 0,
    'subject' => 'Laptop battery drains in under two hours', 'category' => 'Hardware', 'priority' => 'Medium', 'status' => 'New',
    'details' => '<p>My laptop battery now lasts less than two hours away from the charger. The warranty ends next month, so I would like it checked before then.</p>',
    'age' => 180]);

seedTicket(['client' => $finance, 'contact' => $lena, 'source' => 'Email', 'created_by' => 0, 'agent' => 0,
    'subject' => 'Scanner sends scans to the wrong folder', 'category' => 'Hardware', 'priority' => 'Low', 'status' => 'New',
    'details' => '<p>Scans from the finance office scanner are going to a shared folder that only the warehouse team uses. They should go to the Finance scans folder.</p>',
    'age' => 300]);

// ---- 6. "Request Something" catalog -------------------------------------------------------------------------------

function seedCatalogItem(string $name, string $description, string $icon, string $subject, string $category, string $priority, int $order): void
{
    if (val("SELECT catalog_item_id FROM service_catalog_items WHERE name = '" . esc($name) . "' LIMIT 1") !== null) {
        return;
    }
    run("INSERT INTO service_catalog_items SET name = '" . esc($name) . "', description = '" . esc($description) . "', icon = '" . esc($icon) . "',
            ticket_subject_template = '" . esc($subject) . "', ticket_category_id = " . categoryId($category) . ",
            default_priority = '" . esc($priority) . "', is_active = 1, sort_order = $order");
    say("catalog item $name");
}

seedCatalogItem('New laptop or desktop', 'Ask for a new or replacement computer for yourself or a new team member.', 'fa-laptop', 'New computer request', 'Hardware', 'Medium', 10);
seedCatalogItem('Install or update software', 'Need a program installed, or an existing one updated?', 'fa-cube', 'Software installation request', 'Software', 'Low', 20);
seedCatalogItem('Password reset or locked account', 'Locked out, or forgot your password? Start here.', 'fa-key', 'Password reset or account unlock', 'Access & Accounts', 'High', 30);
seedCatalogItem('New starter IT setup', 'Get accounts, a computer and access ready for someone joining your team.', 'fa-user-plus', 'New starter IT setup', 'Access & Accounts', 'Medium', 40);
seedCatalogItem('Shared folder or mailbox access', 'Ask for access to a shared folder, mailbox or distribution list.', 'fa-folder-open', 'Shared folder or mailbox access request', 'Access & Accounts', 'Low', 50);
seedCatalogItem('Report a lost or stolen device', 'Tell us straight away if a laptop, phone or badge is missing.', 'fa-exclamation-triangle', 'Lost or stolen device', 'Hardware', 'High', 60);

// ---- 7. Knowledge Base: articles a department can read ---------------------------------------------------------------

function kbCategoryId(string $name): int
{
    $id = val("SELECT kb_category_id FROM kb_categories WHERE kb_category_name = '" . esc($name) . "' AND kb_category_client_id = 0 LIMIT 1");
    if ($id !== null) {
        return (int) $id;
    }
    run("INSERT INTO kb_categories SET kb_category_name = '" . esc($name) . "', kb_category_client_id = 0");
    return newId();
}

/** $client_id 0 = a Central (company-wide) article, otherwise it is only shown to that department. */
function seedKbArticle(string $title, string $category, int $client_id, string $html, int $created_by, int $days_ago): void
{
    if (val("SELECT kb_article_id FROM kb_articles WHERE kb_article_title = '" . esc($title) . "' AND kb_article_client_id = $client_id LIMIT 1") !== null) {
        return;
    }
    run("INSERT INTO kb_articles SET kb_article_title = '" . esc($title) . "', kb_article_content = '" . esc($html) . "',
            kb_article_content_raw = '" . esc($title . ' ' . plain($html)) . "', kb_article_client_id = $client_id,
            kb_article_client_visible = 1, kb_article_category_id = " . kbCategoryId($category) . ",
            kb_article_created_by = $created_by, kb_article_updated_by = $created_by,
            kb_article_created_at = NOW() - INTERVAL $days_ago DAY, kb_article_updated_at = NOW() - INTERVAL " . max(1, intdiv($days_ago, 2)) . " DAY");
    say("KB article $title");
}

seedKbArticle('Connect to the staff Wi-Fi', 'Network & Wi-Fi', 0,
    '<p>Use the <strong>SummitRidge-Staff</strong> network on company laptops and phones. The <strong>SummitRidge-Guest</strong> network is for visitors and cannot reach internal systems.</p>
<h4>On a Windows laptop</h4>
<ol>
<li>Select the Wi-Fi icon at the right of the taskbar.</li>
<li>Choose <strong>SummitRidge-Staff</strong> and select <strong>Connect</strong>.</li>
<li>Sign in with your work email address and password when asked.</li>
</ol>
<h4>On a phone or tablet</h4>
<p>Open <strong>Settings</strong>, then <strong>Wi-Fi</strong>, pick <strong>SummitRidge-Staff</strong> and sign in with your work email and password. Accept the certificate if your phone asks.</p>
<p>Still not connecting? Raise a ticket from the <strong>Tickets</strong> page and tell us which room or area it fails in.</p>', $priya, 90);

seedKbArticle('Set up work email on your phone', 'Email & Calendar', 0,
    '<p>You can read work email and see your calendar on your own phone. Company policy asks you to keep a screen lock on.</p>
<ol>
<li>Install the Outlook app from your phone\'s app store.</li>
<li>Open it and enter your work email address.</li>
<li>Sign in with your work password and approve the sign-in request.</li>
<li>Choose a screen lock if the app asks you to.</li>
</ol>
<p>If you change phones, remove the old phone from your account by raising a ticket, so it stops receiving email.</p>', $priya, 75);

seedKbArticle('What to do if your laptop or phone is lost or stolen', 'Security', 0,
    '<p>Act quickly: the sooner IT knows, the sooner we can lock the device and protect company data.</p>
<ol>
<li>Use <strong>Request Something</strong> and choose <strong>Report a lost or stolen device</strong>, or call the service desk on extension 100.</li>
<li>Tell us what is missing, when and where you last had it, and whether it was locked.</li>
<li>Do not try to find it in unsafe places. Report a theft to the police if it happened outside work.</li>
</ol>
<p>IT will lock the device, sign it out of your accounts and, where possible, erase it remotely.</p>', $marcus, 60);

seedKbArticle('How to spot a phishing email', 'Security', 0,
    '<p>Phishing emails try to trick you into sharing a password or opening a harmful file. Check these five things before you click.</p>
<ol>
<li><strong>The sender.</strong> Does the address match the company it claims to be from?</li>
<li><strong>The urgency.</strong> Threats such as "your account will be closed today" are a warning sign.</li>
<li><strong>The link.</strong> Hover over it. If the address looks odd, do not click.</li>
<li><strong>The attachment.</strong> Do not open files you were not expecting.</li>
<li><strong>The request.</strong> IT will never ask for your password by email.</li>
</ol>
<p>Not sure? Forward it to the service desk or raise a ticket. Do not reply to it.</p>', $marcus, 45);

seedKbArticle('Change your Department Portal password', 'Accounts & Passwords', 0,
    '<p>If you sign in to this portal with a password, you can change it at any time.</p>
<ol>
<li>Select your name at the top right, then <strong>Account</strong>.</li>
<li>Type a new password of at least eight characters under <strong>Password</strong>.</li>
<li>Select <strong>Save password</strong>.</li>
</ol>
<p>Forgotten your password? Ask the service desk to set a new one for you.</p>', $priya, 30);

seedKbArticle('Use the VPN from home', 'Network & Wi-Fi', 0,
    '<p>The VPN gives you a secure connection to the company network when you work away from the office.</p>
<ol>
<li>Start the VPN client from the Start menu.</li>
<li>Sign in with your work email address and your VPN token code.</li>
<li>Wait for the status to change to <strong>Connected</strong>, then open your files as usual.</li>
</ol>
<p>Disconnect when you finish. If the token code is rejected, raise a ticket or ask the service desk for a new token.</p>', $marcus, 70);

seedKbArticle('Fix a slow or dropping internet connection', 'Network & Wi-Fi', 0,
    '<p>Try these steps before you raise a ticket. They solve most home and office connection problems.</p>
<ol>
<li>Move closer to the Wi-Fi access point, or plug in a network cable.</li>
<li>Turn Wi-Fi off and on again.</li>
<li>Restart your computer.</li>
<li>Check whether a colleague nearby has the same problem.</li>
</ol>
<p>If it keeps happening, raise a ticket and tell us the room, the time and whether others are affected.</p>', $marcus, 55);

seedKbArticle('Share your calendar with a colleague', 'Email & Calendar', 0,
    '<p>Sharing your calendar lets a colleague see when you are free, so meetings are easier to arrange.</p>
<ol>
<li>Open your calendar in Outlook.</li>
<li>Select <strong>Share Calendar</strong> on the toolbar.</li>
<li>Type your colleague\'s name and choose how much detail they can see.</li>
<li>Select <strong>Send</strong>.</li>
</ol>', $priya, 50);

seedKbArticle('Lock your screen and choose a strong password', 'Security', 0,
    '<p>A locked screen and a strong password protect company data when you step away.</p>
<ul>
<li>Press <strong>Windows key + L</strong> every time you leave your desk.</li>
<li>Use at least twelve characters. Three or four random words work well.</li>
<li>Never reuse your work password on another website.</li>
<li>Never share your password, even with IT.</li>
</ul>', $marcus, 35);

seedKbArticle('Unlock your account after too many wrong passwords', 'Accounts & Passwords', 0,
    '<p>After several wrong passwords your account locks itself to keep it safe.</p>
<ol>
<li>Wait fifteen minutes. The account unlocks by itself.</li>
<li>Or use <strong>Request Something</strong> and choose <strong>Password reset or locked account</strong> for a faster fix.</li>
</ol>
<p>Have your employee number ready when you contact the service desk.</p>', $priya, 28);

seedKbArticle('Month-end close: reach the finance file share from home', 'Finance Systems', $finance,
    '<p>The finance file share is only reachable from the office network or the VPN.</p>
<ol>
<li>Start the VPN client and sign in with your work email and token.</li>
<li>Open <strong>File Explorer</strong> and select <strong>Finance</strong> under Network locations.</li>
<li>Open the <strong>Month-End</strong> folder for the current period.</li>
</ol>
<p>If the share does not open, raise a <strong>High</strong> priority ticket during close so it is picked up first.</p>', $priya, 20);

seedKbArticle('Send documents securely to candidates', 'HR Systems', $hr,
    '<p>Use the secure send option when an offer letter or form contains personal details.</p>
<ol>
<li>In Outlook, create a new message and attach the document.</li>
<li>Select <strong>Options</strong>, then <strong>Encrypt</strong>, and choose <strong>Encrypt-Only</strong>.</li>
<li>Send the message. The candidate will be asked to verify their email address before opening it.</li>
</ol>
<p>Never send offer letters as normal attachments.</p>', $marcus, 25);

// ---- 8. a Department Portal Nav link -----------------------------------------------------------------------------------

run("INSERT INTO custom_links (custom_link_name, custom_link_uri, custom_link_new_tab, custom_link_location, custom_link_order)
     SELECT 'Employee Handbook', 'https://intranet.summitridge.example/handbook', 1, 3, 1 FROM DUAL
     WHERE NOT EXISTS (SELECT 1 FROM custom_links WHERE custom_link_name = 'Employee Handbook' AND custom_link_location = 3)");

// ---- 9. a share link (a document), for the recipient-side screenshot ------------------------------------------------------

if ($doc_quickref && val("SELECT item_id FROM shared_items WHERE item_key = '" . PORTAL_SHARE_DOC_KEY . "'") === null) {
    run("INSERT INTO shared_items SET item_active = 1, item_key = '" . PORTAL_SHARE_DOC_KEY . "', item_type = 'Document',
            item_related_id = $doc_quickref, item_note = 'Department visible note: the bank token steps are on page 2.',
            item_recipient = '$grace', item_views = 0, item_view_limit = 0,
            item_expire_at = NOW() + INTERVAL 45 DAY, item_client_id = $finance");
    say('share link for the Finance quick reference document');
}

echo "70-portal: done\n";
