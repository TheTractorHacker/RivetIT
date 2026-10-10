#!/usr/bin/env php
<?php

// RivetIT command-line installer (the web installer is setup/index.php).
// Example
//php setup_cli.php --help
//php setup_cli.php --host=localhost --username=rivetit --password=secret --database=rivetit --base-url=rivetit.example.com --locale=en_US --timezone=UTC --currency=USD --company-name="My Company" --country="United States" --user-name="John Doe" --user-email="john@example.com" --user-password="admin123" --non-interactive

// Change to the directory of this script so that all shell commands run here
chdir(__DIR__);

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Product name and links (APP_NAME etc.); --help prints before functions.php is loaded.
require_once __DIR__ . '/../includes/branding.php';

// Define required arguments
$required_args = [
    'host'         => 'Database host',
    'username'     => 'Database username',
    'password'     => 'Database password',
    'database'     => 'Database name',
    'base-url'     => 'Base URL (without protocol, e.g. rivetit.example.com)',
    'locale'       => 'Locale (e.g. en_US)',
    'timezone'     => 'Timezone (e.g. UTC)',
    'currency'     => 'Currency code (e.g. USD)',
    'company-name' => 'Company name',
    'country'      => 'Company country (e.g. United States)',
    'user-name'    => 'Admin user full name',
    'user-email'   => 'Admin user email',
    'user-password'=> 'Admin user password (min 12 chars)'
];

// Additional optional arguments
// address, city, state, zip, phone, company-email, website
// These are optional and don't need error checks if missing.
$optional_args = [
    'address'       => 'Company address (optional)',
    'city'          => 'Company city (optional)',
    'state'         => 'Company state (optional)',
    'zip'           => 'Company postal code (optional)',
    'phone'         => 'Company phone (optional)',
    'company-email' => 'Company email (optional)',
    'website'       => 'Company website (optional)'
];

// Parse command line options
$shortopts = "";
$longopts = [
    "help",
    "host:",
    "username:",
    "password:",
    "database:",
    "base-url:",
    "locale:",
    "timezone:",
    "currency:",
    "company-name:",
    "country:",
    "address::",
    "city::",
    "state::",
    "zip::",
    "phone::",
    "company-email::",
    "website::",
    "user-name:",
    "user-email:",
    "user-password:",
    "non-interactive",
    "config-only",
    "settings-enc-key:"
];

$options = getopt($shortopts, $longopts);

// If --help is set, print usage and exit
if (isset($options['help'])) {
    echo APP_NAME . " CLI Setup Script\n\n";
    echo "Usage:\n";
    echo "  php setup_cli.php [options]\n\n";
    echo "Options:\n";
    foreach ($required_args as $arg => $desc) {
        echo "  --$arg\t$desc (required)\n";
    }
    foreach ($optional_args as $arg => $desc) {
        echo "  --$arg\t$desc\n";
    }
    echo "  --non-interactive\tRun in non-interactive mode (fail if required args missing)\n";
    echo "  --config-only\t\tWrite config.php from --host/--username/--password/--database/\n";
    echo "  \t\t\t--base-url only, then exit — skips schema import, admin user\n";
    echo "  \t\t\tcreation, and every other prompt/argument above. For a target\n";
    echo "  \t\t\tthat will have its data restored from a backup immediately\n";
    echo "  \t\t\tafterward (see deploy/install.sh --restore-from and\n";
    echo "  \t\t\tdeploy/restore.sh) rather than set up fresh.\n";
    echo "  --settings-enc-key=<hex>\tWith --config-only: use this value for config.php's\n";
    echo "  \t\t\t\tconfig_settings_enc_key instead of generating a new random\n";
    echo "  \t\t\t\tone (deploy/restore.sh passes the key recovered from a\n";
    echo "  \t\t\t\tbackup's manifest here, so restored SMTP/IMAP/RMM/webhook\n";
    echo "  \t\t\t\tsecrets keep decrypting correctly).\n";
    echo "  --help\t\tShow this help message\n\n";
    echo "If running interactively (without --non-interactive), any missing required arguments will be prompted.\n";
    echo "If running non-interactively, all required arguments must be provided.\n\n";
    echo "Secrets can come from the environment instead of argv: RIVETIT_DB_PASSWORD (--password) and\n";
    echo "RIVETIT_ADMIN_PASSWORD (--user-password). The older ITFLOW_DB_PASSWORD / ITFLOW_ADMIN_PASSWORD\n";
    echo "names still work (deprecated) when the RIVETIT_* one is not set.\n\n";
    exit(0);
}

$config_only = isset($options['config-only']);

if (file_exists("../config.php")) {
    include_once "../config.php";
}

require_once "../functions.php";
require_once "../includes/database_version.php";

if (!isset($config_enable_setup)) {
    $config_enable_setup = 1;
}

if ($config_enable_setup == 0) {
    echo "Setup is disabled. Please delete or modify config.php if you need to re-run the setup.\n";
    exit;
}

$errorLog = ini_get('error_log') ?: "/var/log/apache2/error.log";

$timezones = DateTimeZone::listIdentifiers();

function prompt($message) {
    echo $message . ": ";
    return trim(fgets(STDIN));
}

$non_interactive = isset($options['non-interactive']);

// Secrets accepted via environment variable in preference to --password/--user-password
// on argv, which is visible to any other local user via `ps` for the life of the process
// and often ends up in shell history. A deploy script can export these instead.
// Names are tried in order: RIVETIT_* first, then the pre-rebrand ITFLOW_* names, which
// existing deploy scripts and Docker setups export and which keep working (deprecated).
$secret_env_vars = [
    'password'      => ['RIVETIT_DB_PASSWORD', 'ITFLOW_DB_PASSWORD'],
    'user-password' => ['RIVETIT_ADMIN_PASSWORD', 'ITFLOW_ADMIN_PASSWORD'],
];

function getSecretFromEnv($key) {
    global $secret_env_vars;
    if (!isset($secret_env_vars[$key])) {
        return false;
    }
    foreach ($secret_env_vars[$key] as $env_name) {
        $val = getenv($env_name);
        if ($val !== false && $val !== '') {
            return $val;
        }
    }
    return false;
}

function getOptionOrPrompt($key, $promptMessage, $required = false, $default = '', $optionsGlobal = []) {
    global $options, $non_interactive;
    $env_val = getSecretFromEnv($key);
    if ($env_val !== false) {
        return $env_val;
    } elseif (isset($options[$key])) {
        return $options[$key];
    } else {
        if ($non_interactive && $required) {
            die("Missing required argument: --$key\n");
        }
        $val = prompt($promptMessage . (strlen($default) ? " [$default]" : ''));
        if (empty($val) && !empty($default)) {
            $val = $default;
        }
        if ($required && empty($val)) {
            die("Error: $promptMessage is required.\n");
        }
        return $val;
    }
}

// Start setup
echo "Welcome to the " . APP_NAME . " CLI Setup.\n";

// If config exists, abort
if (file_exists('../config.php')) {
    echo "Database is already configured in config.php.\n";
    echo "To re-run the setup, remove config.php and run this script again.\n";
    exit;
}

// If non-interactive is set, ensure all required arguments are present
// (a secret supplied via RIVETIT_DB_PASSWORD/RIVETIT_ADMIN_PASSWORD, or the older
// ITFLOW_DB_PASSWORD/ITFLOW_ADMIN_PASSWORD, counts too).
// --config-only only ever needs the DB/base-url args below, not the
// company/admin-user ones - it never prompts for or inserts any of that.
if ($non_interactive) {
    $args_to_check = $config_only
        ? ['host', 'username', 'password', 'database', 'base-url']
        : array_keys($required_args);
    foreach ($args_to_check as $arg) {
        if (!isset($options[$arg]) && getSecretFromEnv($arg) === false) {
            die("Missing required argument: --$arg\n");
        }
    }
}

// Database Setup
echo "\n=== Database Setup ===\n";
$database = getOptionOrPrompt('database', "Enter the database name", true);
$host = getOptionOrPrompt('host', "Enter the database host", true, 'localhost');
if (empty($host)) $host = "localhost";
$username = getOptionOrPrompt('username', "Enter the database username", true);
$password = getOptionOrPrompt('password', "Enter the database password", true);

// Base URL
$base_url = getOptionOrPrompt('base-url', "Enter the base URL (e.g. rivetit.example.com)", true);
$base_url = rtrim($base_url, '/');

if (!$config_only) {
    // Locale, Timezone, Currency
    echo "\n=== Localization ===\n";
    $locale = getOptionOrPrompt('locale', "Enter the locale (e.g. en_US)", true);
    $timezone = getOptionOrPrompt('timezone', "Enter the timezone (e.g. UTC or America/New_York)", true);
    $currency_code = getOptionOrPrompt('currency', "Enter the currency code (e.g. USD)", true);

    // Company Details
    echo "\n=== Company Details ===\n";
    $company_name = getOptionOrPrompt('company-name', "Company Name", true);
    $country = getOptionOrPrompt('country', "Country (e.g. United States)", true);
    $address = getOptionOrPrompt('address', "Address (optional)", false);
    $city = getOptionOrPrompt('city', "City (optional)", false);
    $state = getOptionOrPrompt('state', "State/Province (optional)", false);
    $zip = getOptionOrPrompt('zip', "Postal Code (optional)", false);
    $phone = getOptionOrPrompt('phone', "Phone (optional)", false);
    $phone = preg_replace("/[^0-9]/", '', $phone);
    $company_email = getOptionOrPrompt('company-email', "Company Email (optional)", false);
    $website = getOptionOrPrompt('website', "Website (optional)", false);

    // User Setup
    echo "\n=== Create First User ===\n";
    $user_name = getOptionOrPrompt('user-name', "Full Name", true);
    $user_email = getOptionOrPrompt('user-email', "Email Address", true);
    while (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        echo "Invalid email.\n";
        if ($non_interactive) {
            die("Invalid email address: $user_email\n");
        }
        $user_email = prompt("Email Address");
    }
    $user_password_plain = getOptionOrPrompt('user-password', "Password (at least 12 chars)", true);
    // Staff password policy (includes/security_policy.php): 12+ characters, not the name or email address.
    require_once __DIR__ . '/../includes/security_policy.php';
    $pw_error = secPasswordPolicyError($user_password_plain, ['name' => $user_name ?? '', 'email' => $user_email ?? '', 'username' => $user_email ?? '']);
    if ($pw_error !== null) {
        if ($non_interactive) {
            fwrite(STDERR, $pw_error . "\n");
            exit(1);
        }
        while ($pw_error !== null) {
            echo $pw_error . " Try again.\n";
            $user_password_plain = prompt("Password");
            $pw_error = secPasswordPolicyError($user_password_plain, ['name' => $user_name ?? '', 'email' => $user_email ?? '', 'username' => $user_email ?? '']);
        }
    }
}

if (!preg_match('/^[a-zA-Z0-9.\-\/]+$/', $host)) {
    die("Invalid host format.\n");
}

// Test Database
$conn = @mysqli_connect($host, $username, $password, $database);
if (!$conn) {
    die("Database connection failed - " . mysqli_connect_error() . "\n");
}

$installation_id = randomString(32);

// Per-installation key for encryptSetting()/decryptSetting() - see below.
// --settings-enc-key lets a caller (deploy/restore.sh, via a backup's own
// manifest) supply the ORIGINAL key instead of minting a new one, so
// restored SMTP/IMAP passwords, RMM/webhook secrets, and the wrapped
// credential-vault master key keep decrypting correctly. Only meaningful
// with --config-only - a fresh (non-restore) install has no prior key to
// preserve, and always mints its own.
$settings_enc_key = $options['settings-enc-key'] ?? bin2hex(random_bytes(32));

$new_config = "<?php\n\n";
$new_config .= "\$dbhost = " . var_export($host, true) . ";\n";
$new_config .= "\$dbusername = " . var_export($username, true) . ";\n";
$new_config .= "\$dbpassword = " . var_export($password, true) . ";\n";
$new_config .= "\$database = " . var_export($database, true) . ";\n";
$new_config .= "\$mysqli = mysqli_connect(\$dbhost, \$dbusername, \$dbpassword, \$database) or die('Database Connection Failed');\n";
// Empty = the product name (APP_NAME) at runtime, via appDisplayName(); writing the name would pin it.
$new_config .= "\$config_app_name = ''; // empty: use the product name (APP_NAME)\n";
$new_config .= "\$config_base_url = '" . addslashes($base_url) . "';\n";
$new_config .= "\$config_https_only = TRUE;\n";
$new_config .= "\$repo_branch = 'main';\n";
$new_config .= "\$installation_id = '$installation_id';\n";
// Per-installation key for encryptSetting()/decryptSetting() - SMTP and IMAP
// passwords, OAuth refresh tokens, RMM/UniFi API keys, webhook secrets and the
// canonical credential-vault master key are all wrapped with it. It MUST be
// generated here: encryptSetting() now refuses to write a secret without one,
// and before this line existed no code path ever created it, so every install
// silently stored those columns in cleartext.
$new_config .= "\$config_settings_enc_key = '$settings_enc_key';\n";

if (file_put_contents("../config.php", $new_config) === false) {
    die("Failed to write config.php. Check file permissions.\n");
}

if (!file_exists('../config.php')) {
    die("config.php does not exist after write attempt.\n");
}

require "../config.php";

if ($config_only) {
    echo "\nconfig.php written (--config-only) - schema import, admin user, and company setup were skipped.\n";
    echo "Restore data into '$database' next (see deploy/restore.sh); this instance will be ready to log in once that completes.\n";
    exit(0);
}

// Import DB Schema
echo "Importing database schema...\n";
$filename = '../db.sql';
if (!file_exists($filename)) {
    die("db.sql file not found.\n");
}
$templine = '';
$lines = file($filename);
foreach ($lines as $line) {
    if (substr($line, 0, 2) == '--' || trim($line) == '')
        continue;
    $templine .= $line;
    if (substr(trim($line), -1, 1) == ';') {
        mysqli_query($mysqli, $templine) or die("Error performing query: $templine\n" . mysqli_error($mysqli) . "\n");
        $templine = '';
    }
}
echo "Database imported successfully.\n";

// Create User
require_once __DIR__ . '/../includes/security_policy.php';
$password_hash = secPasswordHash(trim($user_password_plain));
$site_encryption_master_key = randomString();
$user_specific_encryption_ciphertext = setupFirstUserSpecificKey($user_password_plain, $site_encryption_master_key);

mysqli_query($mysqli,"INSERT INTO users SET user_name = '$user_name', user_email = '$user_email', user_password = '$password_hash', user_specific_encryption_ciphertext = '$user_specific_encryption_ciphertext', user_role_id = 3");
// db.sql carries the AUTO_INCREMENT counter of the database it was dumped from, so the first user is not necessarily id 1.
// A settings row for a user id that does not exist leaves the real admin without one, and every paginated list then divides by zero.
$first_user_id = intval(mysqli_insert_id($mysqli));
mysqli_query($mysqli,"INSERT INTO user_settings SET user_id = $first_user_id");
echo "User $user_name created successfully.\n";

// Company Details
mysqli_query($mysqli,"INSERT INTO companies SET company_name = '$company_name', company_address = '$address', company_city = '$city', company_state = '$state', company_zip = '$zip', company_country = '$country', company_phone = '$phone', company_email = '$company_email', company_website = '$website', company_locale = '$locale', company_currency = '$currency_code'");

// Insert default settings and categories
// db.sql is a schema snapshot older than LATEST_DATABASE_VERSION; record the version it really is so the
// migrations that came after it still run (install.sh runs update_cli.php --update_db next).
$latest_database_version = LATEST_DATABASE_VERSION;
if (preg_match('/^-- RIVETIT_SCHEMA_VERSION: ([0-9.]+)$/m', (string) file_get_contents(__DIR__ . '/../db.sql', false, null, 0, 2048), $schema_marker)) {
    $latest_database_version = $schema_marker[1];
}
mysqli_query($mysqli,"INSERT INTO settings SET company_id = 1, config_current_database_version = '$latest_database_version', config_invoice_prefix = 'INV-', config_invoice_next_number = 1, config_recurring_invoice_prefix = 'REC-', config_invoice_overdue_reminders = '1,3,7', config_quote_prefix = 'QUO-', config_quote_next_number = 1, config_default_net_terms = 30, config_ticket_next_number = 1, config_ticket_prefix = 'TCK-', config_module_enable_ticket_charges = 0"); // ticket charges are billing: off, as Settings > Modules saves them

// Seed the canonical copy of the site encryption master key. This is the only
// place (besides setup/index.php) a brand-new master key is ever minted - every
// other self-heal path syncs from this canonical copy instead.
setCanonicalVaultKey($mysqli, $site_encryption_master_key);

// Categories
mysqli_query($mysqli,"INSERT INTO categories SET category_name = 'Office Supplies', category_type = 'Expense', category_color = 'blue'");
mysqli_query($mysqli,"INSERT INTO categories SET category_name = 'Travel', category_type = 'Expense', category_color = 'red'");
mysqli_query($mysqli,"INSERT INTO categories SET category_name = 'Advertising', category_type = 'Expense', category_color = 'green'");
mysqli_query($mysqli,"INSERT INTO categories SET category_name = 'Service', category_type = 'Income', category_color = 'blue'");
mysqli_query($mysqli,"INSERT INTO categories SET category_name = 'Friend', category_type = 'Referral', category_color = 'blue'");
mysqli_query($mysqli,"INSERT INTO categories SET category_name = 'Search Engine', category_type = 'Referral', category_color = 'red'");

// Payment Methods
mysqli_query($mysqli,"INSERT INTO payment_methods SET payment_method_name = 'Cash'");
mysqli_query($mysqli,"INSERT INTO payment_methods SET payment_method_name = 'Check'");
mysqli_query($mysqli,"INSERT INTO payment_methods SET payment_method_name = 'ACH'");
mysqli_query($mysqli,"INSERT INTO payment_methods SET payment_method_name = 'Credit Card'");

// Calendar
mysqli_query($mysqli,"INSERT INTO calendars SET calendar_name = 'Default', calendar_color = 'blue'");

// Ticket Statuses
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'New', ticket_status_color = '#dc3545'");
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Open', ticket_status_color = '#007bff'");
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'On Hold', ticket_status_color = '#28a745'");
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Resolved', ticket_status_color = '#343a40'");
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Closed', ticket_status_color = '#343a40'");
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Unresolved', ticket_status_color = '#fd7e14'");

// Modules
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_client', module_description = 'General department & contact management'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_support', module_description = 'Access to ticketing, assets and documentation'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_credential', module_description = 'Access to department credentials - usernames, passwords and 2FA codes'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_sales', module_description = 'Access to quotes, invoices and products'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_financial', module_description = 'Access to payments, accounts, expenses and budgets'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_reporting', module_description = 'Access to all reports'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_kb', module_description = 'Access to the knowledge base'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_rmm', module_description = 'Access to RMM device monitoring and dashboards'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_rmm_alerts', module_description = 'View RMM alerts'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_rmm_alerts_ack', module_description = 'Acknowledge and resolve RMM alerts'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_rmm_scripts', module_description = 'Run RMM scripts on managed endpoints'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_rmm_sync', module_description = 'Trigger RMM integration syncs'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_rmm_remote_connect', module_description = 'Launch remote sessions to managed endpoints'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_training', module_description = 'Training: courses, content, quizzes, records and reports'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_training_kiosk', module_description = 'Training kiosks and learner PINs (grants impersonation ability)'");
mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_assets', module_description = 'Access to assets, without ticketing or documentation'"); // DB 2.6.95 (roles audit P4)

// Roles
mysqli_query($mysqli, "INSERT INTO user_roles SET role_id = 1, role_name = 'Accountant', role_description = 'Built-in - Limited access to financial-focused modules'");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 1, user_role_permission_level = 1");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 2, user_role_permission_level = 1");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 4, user_role_permission_level = 1");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 5, user_role_permission_level = 2");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 6, user_role_permission_level = 1");

mysqli_query($mysqli, "INSERT INTO user_roles SET role_id = 2, role_name = 'Technician', role_description = 'Built-in - Limited access to technical-focused modules'");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 1, user_role_permission_level = 2");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 2, user_role_permission_level = 2");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 3, user_role_permission_level = 2");
mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 4, user_role_permission_level = 2");

// Assets (module_assets, DB 2.6.95) at each built-in role's Tickets/assets/docs level, as the 2.6.95 migration grants.
mysqli_query($mysqli, "INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) SELECT 1, module_id, 1 FROM modules WHERE module_name = 'module_assets'"); // Read assets
mysqli_query($mysqli, "INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) SELECT 2, module_id, 2 FROM modules WHERE module_name = 'module_assets'"); // Modify assets

mysqli_query($mysqli, "INSERT INTO user_roles SET role_id = 3, role_name = 'Administrator', role_description = 'Built-in - Full administrative access', role_is_admin = 1");

// Custom Links: a "Docs" link only when there are published docs (APP_DOCS_URL is empty while APP_REPO_PUBLIC is 0)
if (APP_DOCS_URL !== '') {
    mysqli_query($mysqli,"INSERT INTO custom_links SET custom_link_name = 'Docs', custom_link_uri = '" . mysqli_real_escape_string($mysqli, APP_DOCS_URL) . "', custom_link_new_tab = 1, custom_link_icon = 'question-circle'");
}

// network_interfaces
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Ethernet', category_type = 'network_interface', category_order = 1"); // 1
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'SFP', category_type = 'network_interface', category_order = 2"); // 2
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'SFP+', category_type = 'network_interface', category_order = 3"); // 3
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'QSFP28', category_type = 'network_interface', category_order = 4"); // 4
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'QSFP-DD', category_type = 'network_interface', category_order = 5"); // 5
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Coaxial', category_type = 'network_interface', category_order = 6"); // 6
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Fiber', category_type = 'network_interface', category_order = 7"); // 7
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'WiFi', category_type = 'network_interface', category_order = 8"); // 8

// Asset statuses
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Ready to Deploy', category_description = 'Asset is configured and ready to be assigned', category_type = 'asset_status', category_color = '#0dcaf0', category_order = 1"); // 1
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Deployed', category_description = 'Asset is actively in use and assigned to a department or location', category_type = 'asset_status', category_color = '#198754', category_order = 2"); // 2
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Out for Repair', category_description = 'Asset has been sent out for servicing or repair', category_type = 'asset_status', category_color = '#fd7e14', category_order = 3"); // 3
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Lost', category_description = 'Asset location is unknown and cannot be accounted for', category_type = 'asset_status', category_color = '#dc3545', category_order = 4"); // 4
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Stolen', category_description = 'Asset has been reported stolen', category_type = 'asset_status', category_color = '#dc3545', category_order = 5"); // 5
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Retired', category_description = 'Asset has been decommissioned and is no longer in service', category_type = 'asset_status', category_color = '#6c757d', category_order = 6"); // 6

// Contact note types
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Call', category_description = 'Phone call with a department or contact', category_icon = 'fa-phone-alt', category_type = 'contact_note_type', category_order = 1"); // 1
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Email', category_description = 'Email correspondence with a department or contact', category_icon = 'fa-envelope', category_type = 'contact_note_type', category_order = 2"); // 2
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Meeting', category_description = 'Scheduled meeting with a department or contact', category_icon = 'fa-handshake', category_type = 'contact_note_type', category_order = 3"); // 3
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'In Person', category_description = 'In person visit or on-site interaction', category_icon = 'fa-people-arrows', category_type = 'contact_note_type', category_order = 4"); // 4
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Note', category_description = 'General note or internal comment', category_icon = 'fa-sticky-note', category_type = 'contact_note_type', category_order = 5"); // 5

// Rack Types
mysqli_query($mysqli, "INSERT INTO categories SET category_name = '2-Post Open Frame', category_description = 'Two-post open frame rack for patch panels and lightweight equipment', category_type = 'rack_type', category_order = 1"); // 1
mysqli_query($mysqli, "INSERT INTO categories SET category_name = '4-Post Open Frame', category_description = 'Four-post open frame rack for servers and heavier equipment', category_type = 'rack_type', category_order = 2"); // 2
mysqli_query($mysqli, "INSERT INTO categories SET category_name = '4-Post Enclosed Cabinet', category_description = 'Four-post enclosed cabinet with doors and sides for secure equipment housing', category_type = 'rack_type', category_order = 3"); // 3
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Wall-Mount Open', category_description = 'Open frame rack mounted directly to a wall for small deployments', category_type = 'rack_type', category_order = 4"); // 4
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Wall-Mount Enclosed', category_description = 'Enclosed cabinet rack mounted to a wall with a locking door', category_type = 'rack_type', category_order = 5"); // 5
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Other', category_description = 'Rack type does not fit any standard category', category_type = 'rack_type', category_order = 6"); // 6

// Software Types
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Software as a Service (SaaS)', category_description = 'Cloud-hosted software accessed via a web browser or API', category_type = 'software_type', category_order = 1"); // 1
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Productivity Suite', category_description = 'Bundled office and collaboration tools such as Microsoft 365 or Google Workspace', category_type = 'software_type', category_order = 2"); // 2
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Web Application', category_description = 'Application hosted on a web server and accessed through a browser', category_type = 'software_type', category_order = 3"); // 3
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Desktop Application', category_description = 'Application installed and run locally on a workstation or laptop', category_type = 'software_type', category_order = 4"); // 4
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Mobile Application', category_description = 'Application installed and run on a mobile device or tablet', category_type = 'software_type', category_order = 5"); // 5
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Security Software', category_description = 'Software providing antivirus, endpoint protection, or security monitoring', category_type = 'software_type', category_order = 6"); // 6
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'System Software', category_description = 'Low-level software managing hardware resources and system operations', category_type = 'software_type', category_order = 7"); // 7
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Operating System', category_description = 'Core software managing hardware and providing a platform for applications', category_type = 'software_type', category_order = 8"); // 8
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Other', category_description = 'Software type does not fit any standard category', category_type = 'software_type', category_order = 9"); // 9

// Finalizing
mysqli_query($mysqli,"UPDATE companies SET company_locale = '$locale', company_currency = '$currency_code' WHERE company_id = 1");
mysqli_query($mysqli,"UPDATE settings SET config_timezone = '$timezone' WHERE company_id = 1");
mysqli_query($mysqli,"INSERT INTO accounts SET account_name = 'Cash', account_currency_code = '$currency_code'");

// Telemetry was removed: this used to optionally POST installation/company details to the upstream
// ITFlow project's telemetry.itflow.org. RivetIT sends nothing anywhere; config_telemetry keeps its
// schema default (0).

// finalize config
$myfile = fopen("../config.php", "a");
$txt = "\$config_enable_setup = 0;\n\n";
fwrite($myfile, $txt);
fclose($myfile);

echo "\nSetup complete!\n";
echo "You can now log in with the user you created at: https://$base_url/login.php\n";

exit(0);
