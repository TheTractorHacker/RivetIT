<?php
// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('mail_queue', 300);

require_once "../plugins/vendor/autoload.php";
require_once "../vendor/autoload.php"; // ITFlow\Mail\*

// PHP Mailer Libs
require_once "../plugins/PHPMailer/src/Exception.php";
require_once "../plugins/PHPMailer/src/PHPMailer.php";
require_once "../plugins/PHPMailer/src/SMTP.php";
require_once "../plugins/PHPMailer/src/OAuthTokenProvider.php";
require_once "../plugins/PHPMailer/src/OAuth.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuthTokenProvider;
use ITFlow\Mail\AutoReplyDetector;
use ITFlow\Mail\MailHealth;
use ITFlow\Mail\MailQueuePolicy;
use ITFlow\Mail\MailSettings;
use ITFlow\Mail\MessageId;

if (!defined('GOOGLE_OAUTH_TOKEN_URL')) {
    define('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token');
}

if (!defined('MICROSOFT_OAUTH_BASE_URL')) {
    define('MICROSOFT_OAUTH_BASE_URL', 'https://login.microsoftonline.com/');
}

/** =======================================================================
 *  XOAUTH2 Token Provider for PHPMailer (simple “static” provider)
 * ======================================================================= */
class StaticTokenProvider implements OAuthTokenProvider {
    private string $email;
    private string $accessToken;
    public function __construct(string $email, string $accessToken) {
        $this->email = $email;
        $this->accessToken = $accessToken;
    }
    public function getOauth64(): string {
        $auth = "user={$this->email}\x01auth=Bearer {$this->accessToken}\x01\x01";
        return base64_encode($auth);
    }
}

/** =======================================================================
 *  Load settings
 * ======================================================================= */
$sql_settings = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
$row = mysqli_fetch_assoc($sql_settings);

$config_enable_cron      = intval($row['config_enable_cron']);

// SMTP baseline
$config_smtp_host        = $row['config_smtp_host'];
$config_smtp_username    = $row['config_smtp_username'];
$config_smtp_password    = decryptSetting($row['config_smtp_password'] ?? '');
$config_smtp_port        = intval($row['config_smtp_port']);
$config_smtp_encryption  = $row['config_smtp_encryption'];

// SMTP provider + shared OAuth fields
$config_smtp_provider                      = $row['config_smtp_provider']; // 'standard_smtp' | 'google_oauth' | 'microsoft_oauth'
$config_mail_oauth_client_id               = $row['config_mail_oauth_client_id'] ?? '';
$config_mail_oauth_client_secret           = decryptSetting($row['config_mail_oauth_client_secret'] ?? '');
$config_mail_oauth_tenant_id               = $row['config_mail_oauth_tenant_id'] ?? '';
$config_mail_oauth_refresh_token           = decryptSetting($row['config_mail_oauth_refresh_token'] ?? '');
$config_mail_oauth_access_token            = decryptSetting($row['config_mail_oauth_access_token'] ?? '');
$config_mail_oauth_access_token_expires_at = $row['config_mail_oauth_access_token_expires_at'] ?? '';

if ($config_enable_cron == 0) {
    exit("Cron: is not enabled -- Quitting..");
}

if (empty($config_smtp_provider)) {
    logApp("Cron-Mail-Queue", "info", "SMTP sending skipped: provider not configured.");
    exit(0);
}

/** =======================================================================
 *  Lock file
 * ======================================================================= */
$temp_dir = sys_get_temp_dir();
$lock_file_path = "{$temp_dir}/itflow_mail_queue_{$installation_id}.lock";

if (file_exists($lock_file_path)) {
    $file_age = time() - filemtime($lock_file_path);
    if ($file_age > 600) {
        unlink($lock_file_path);
        logApp("Cron-Mail-Queue", "warning", "Cron Mail Queue detected a lock file was present but was over 10 minutes old so it removed it.");
    } else {
        logApp("Cron-Mail-Queue", "info", "Cron Mail Queue attempted to execute but was already executing so instead it terminated.");
        exit("Script is already running. Exiting.");
    }
}

file_put_contents($lock_file_path, "Locked");

/** =======================================================================
 *  Mail OAuth helpers + sender function
 * ======================================================================= */
function tokenIsExpired(?string $expires_at): bool {
    if (empty($expires_at)) {
        return true;
    }

    $ts = strtotime($expires_at);

    if ($ts === false) {
        return true;
    }

    return ($ts - 60) <= time();
}

function httpFormPost(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'ok' => ($raw !== false && $code >= 200 && $code < 300),
        'body' => $raw,
        'code' => $code,
        'err' => $err,
    ];
}

function persistMailOauthTokens(string $access_token, string $expires_at, ?string $refresh_token = null): void {
    global $mysqli;

    // Stored wrapped like every other path that writes these columns (admin/post/settings_mail.php). encryptSetting() refuses to
    // run without $config_settings_enc_key; in that case nothing is stored (never plaintext) and the next send refreshes again.
    try {
        $access_token_esc = mysqli_real_escape_string($mysqli, encryptSetting($access_token));
        $refresh_sql = '';
        if (!empty($refresh_token)) {
            $refresh_token_esc = mysqli_real_escape_string($mysqli, encryptSetting($refresh_token));
            $refresh_sql = ", config_mail_oauth_refresh_token = '{$refresh_token_esc}'";
        }
    } catch (RuntimeException $e) {
        error_log('mail_queue: OAuth tokens not persisted: ' . $e->getMessage());
        return;
    }
    $expires_at_esc = mysqli_real_escape_string($mysqli, $expires_at);

    mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_access_token = '{$access_token_esc}', config_mail_oauth_access_token_expires_at = '{$expires_at_esc}'{$refresh_sql} WHERE company_id = 1");
}

function refreshMailOauthAccessToken(string $provider, string $oauth_client_id, string $oauth_client_secret, string $oauth_tenant_id, string $oauth_refresh_token): ?array {
    $result = null;
    $response = null;

    if (!empty($oauth_client_id) && !empty($oauth_client_secret) && !empty($oauth_refresh_token)) {
        if ($provider === 'google_oauth') {
            $response = httpFormPost(GOOGLE_OAUTH_TOKEN_URL, [
                'client_id' => $oauth_client_id,
                'client_secret' => $oauth_client_secret,
                'refresh_token' => $oauth_refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } elseif ($provider === 'microsoft_oauth' && !empty($oauth_tenant_id)) {
            $token_url = MICROSOFT_OAUTH_BASE_URL . rawurlencode($oauth_tenant_id) . "/oauth2/v2.0/token";
            $response = httpFormPost($token_url, [
                'client_id' => $oauth_client_id,
                'client_secret' => $oauth_client_secret,
                'refresh_token' => $oauth_refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        }
    }

    if (is_array($response) && !empty($response['ok'])) {
        $json = json_decode($response['body'], true);

        if (is_array($json) && !empty($json['access_token'])) {
            $expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));
            $result = [
                'access_token' => $json['access_token'],
                'expires_at' => $expires_at,
                'refresh_token' => $json['refresh_token'] ?? null,
            ];
        }
    } elseif (is_array($response)) {
        // Surface the provider's actual reason (e.g. AADSTS7000215 invalid
        // client secret, AADSTS700082 expired refresh token) instead of
        // silently returning null - the generic "Missing OAuth access token"
        // exception this feeds into used to hide exactly what needed fixing.
        $err_json = json_decode($response['body'] ?? '', true);
        $err_detail = is_array($err_json) ? ($err_json['error_description'] ?? $err_json['error'] ?? $response['body']) : $response['body'];
        logApp("Cron-Mail-Queue", "error", "Outbound SMTP ($provider): OAuth token refresh failed (HTTP {$response['code']}): " . substr((string) $err_detail, 0, 500));
    }

    return $result;
}

function resolveMailOauthAccessToken(string $provider, string $oauth_client_id, string $oauth_client_secret, string $oauth_tenant_id, string $oauth_refresh_token, string $oauth_access_token, string $oauth_access_token_expires_at): ?string {
    if (!empty($oauth_access_token) && !tokenIsExpired($oauth_access_token_expires_at)) {
        return $oauth_access_token;
    }

    $tokens = refreshMailOauthAccessToken($provider, $oauth_client_id, $oauth_client_secret, $oauth_tenant_id, $oauth_refresh_token);

    if (!is_array($tokens) || empty($tokens['access_token']) || empty($tokens['expires_at'])) {
        return null;
    }

    persistMailOauthTokens($tokens['access_token'], $tokens['expires_at'], $tokens['refresh_token'] ?? null);

    return $tokens['access_token'];
}

function sendQueueEmail(
    string $provider,
    string $host,
    int    $port,
    string $encryption,
    string $username,
    string $password,
    string $from_email,
    string $from_name,
    string $to_email,
    string $to_name,
    string $subject,
    string $html_body,
    string $ics_str,
    string $oauth_client_id,
    string $oauth_client_secret,
    string $oauth_tenant_id,
    string $oauth_refresh_token,
    string $oauth_access_token,
    string $oauth_access_token_expires_at,
    string $message_id = '',
    bool   $auto = true
) {
    // Sensible defaults for OAuth providers if fields were left blank
    if ($provider === 'google_oauth') {
        if (!$host) $host = 'smtp.gmail.com';
        if (!$port) $port = 587;
        if (!$encryption) $encryption = 'tls';
        if (!$username) $username = $from_email;
    } elseif ($provider === 'microsoft_oauth') {
        if (!$host) $host = 'smtp.office365.com';
        if (!$port) $port = 587;
        if (!$encryption) $encryption = 'tls';
        if (!$username) $username = $from_email;
    }

    $mail = new PHPMailer(true);
    $mail->CharSet   = "UTF-8";
    $mail->SMTPDebug = 0;
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;

    $enc = strtolower($encryption);
    if ($enc === '' || $enc === 'none') {
        $mail->SMTPAutoTLS = false;
        $mail->SMTPSecure  = false;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
    } else {
        $mail->SMTPSecure = $enc; // 'tls' | 'ssl'
    }

    if ($provider === 'google_oauth' || $provider === 'microsoft_oauth') {
        // XOAUTH2
        $mail->SMTPAuth = true;
        $mail->AuthType = 'XOAUTH2';
        $mail->Username = $username;

        $access_token = resolveMailOauthAccessToken(
            $provider,
            trim($oauth_client_id),
            trim($oauth_client_secret),
            trim($oauth_tenant_id),
            trim($oauth_refresh_token),
            trim($oauth_access_token),
            trim($oauth_access_token_expires_at)
        );

        if (empty($access_token)) {
            throw new Exception("Missing OAuth access token for XOAUTH2 SMTP.");
        }

        $mail->setOAuth(new StaticTokenProvider($username, $access_token));
    } else {
        // Standard SMTP (with or without auth)
        $mail->SMTPAuth = !empty($username);
        $mail->Username = $username ?: '';
        $mail->Password = $password ?: '';
    }

    // Recipients & content
    $mail->setFrom($from_email, $from_name);
    $mail->addAddress($to_email, $to_name);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html_body;

    // Our own Message-ID (stored on the queue row), so replies thread by In-Reply-To/References.
    if ($message_id !== '') {
        $mail->MessageID = MessageId::bracket($message_id);
    }

    // Machine-generated mail must never be answered by another robot (RFC 3834). X-RivetIT-Auto lets this system recognise
    // its own mail if it comes back to a monitored mailbox and drop it instead of opening a ticket.
    if ($auto) {
        $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        $mail->addCustomHeader(AutoReplyDetector::LOOP_HEADER, '1');
    }

    if (!empty($ics_str)) {
        $mail->addStringAttachment($ics_str, 'Scheduled_ticket.ics', 'base64', 'text/calendar');
    }

    $mail->send();
    return true;
}

/** =======================================================================
 *  Reaper: rows stuck in status 1 (sending) for more than 10 minutes - a killed or crashed
 *  run - go back to status 0 so they are sent instead of sitting there forever.
 * ======================================================================= */
$reaped = MailQueuePolicy::reapStuck($mysqli);
if ($reaped > 0) {
    logApp("Cron-Mail-Queue", "warning", "Reset $reaped email(s) stuck in 'sending' for over " . MailQueuePolicy::STUCK_MINUTES . " minutes back to the queue.");
}

// Outbound rate limit (setting "outbound_rate_per_min", default 120): leftover rows wait for the next minute's run.
$send_budget = MailQueuePolicy::sendBudget($mysqli, MailSettings::int($mysqli, 'outbound_rate_per_min'));
$send_throttled = false;

/**
 * Send one queue row and record the outcome. $row['email_attempts'] is the number of attempts already made.
 * Returns true when the row consumed send budget (it was handed to the mailer, successfully or not).
 */
function deliverQueueRow(array $row): bool {
    global $mysqli, $argv, $config_smtp_provider, $config_smtp_host, $config_smtp_port, $config_smtp_encryption,
           $config_smtp_username, $config_smtp_password, $config_mail_oauth_client_id, $config_mail_oauth_client_secret,
           $config_mail_oauth_tenant_id, $config_mail_oauth_refresh_token, $config_mail_oauth_access_token,
           $config_mail_oauth_access_token_expires_at;

    $email_id             = (int)$row['email_id'];
    $email_from           = $row['email_from'];
    $email_recipient      = $row['email_recipient'];
    $email_attempts       = (int)$row['email_attempts'] + 1;
    $email_subject_logging = sanitizeInput($row['email_subject']);
    $email_to_logging      = sanitizeInput($email_recipient);

    // Check sender
    if (!filter_var($email_from, FILTER_VALIDATE_EMAIL)) {
        $email_from_logging = sanitizeInput($email_from);
        mysqli_query($mysqli, "UPDATE email_queue SET email_status = 2, email_attempts = " . MailQueuePolicy::PERMANENT . " WHERE email_id = $email_id");
        logApp("Cron-Mail-Queue", "Error", "Failed to send email #$email_id due to invalid sender address: $email_from_logging - check configuration in settings.");
        appNotify("Mail", "Failed to send email #$email_id due to invalid sender address", "/admin/logs.php");
        return false;
    }

    mysqli_query($mysqli, "UPDATE email_queue SET email_status = 1, email_started_at = NOW() WHERE email_id = $email_id");

    // Basic recipient syntax check
    if (!filter_var($email_recipient, FILTER_VALIDATE_EMAIL)) {
        mysqli_query($mysqli, "UPDATE email_queue SET email_status = 2, email_attempts = " . MailQueuePolicy::PERMANENT . " WHERE email_id = $email_id");
        logApp("Cron-Mail-Queue", "Error", "Failed to send email: $email_id to $email_to_logging due to invalid recipient address. Email subject was: $email_subject_logging");
        appNotify("Mail", "Failed to send email #$email_id to $email_to_logging due to invalid recipient address: Email subject was: $email_subject_logging", "/admin/logs.php");
        return false;
    }

    // More intelligent recipient MX check (if not disabled with --no-mx-validation)
    $domain = sanitizeInput(substr($email_recipient, strpos($email_recipient, '@') + 1));
    if (!in_array('--no-mx-validation', $argv ?? []) && !checkdnsrr($domain, 'MX')) {
        mysqli_query($mysqli, "UPDATE email_queue SET email_status = 2, email_attempts = " . MailQueuePolicy::PERMANENT . " WHERE email_id = $email_id");
        logApp("Cron-Mail-Queue", "Error", "Failed to send email: $email_id to $email_to_logging due to invalid recipient domain (no MX). Email subject was: $email_subject_logging");
        appNotify("Mail", "Failed to send email #$email_id to $email_to_logging due to invalid recipient domain (no MX): Email subject was: $email_subject_logging", "/admin/logs.php");
        return false;
    }

    // Rows queued before the Message-ID column existed (or inserted directly) get theirs now, and keep it across retries.
    $message_id = (string)($row['email_message_id'] ?? '');
    if ($message_id === '') {
        $message_id = MessageId::generate((string)$email_from);
        $mid_esc = mysqli_real_escape_string($mysqli, $message_id);
        mysqli_query($mysqli, "UPDATE email_queue SET email_message_id = '$mid_esc' WHERE email_id = $email_id");
    }

    try {
        sendQueueEmail(
            ($config_smtp_provider ?: 'standard_smtp'),
            $config_smtp_host,
            (int)$config_smtp_port,
            (string)$config_smtp_encryption,
            (string)$config_smtp_username,
            (string)$config_smtp_password,
            (string)$email_from,
            (string)$row['email_from_name'],
            (string)$email_recipient,
            (string)$row['email_recipient_name'],
            (string)$row['email_subject'],
            (string)$row['email_content'],
            (string)$row['email_cal_str'],
            (string)$config_mail_oauth_client_id,
            (string)$config_mail_oauth_client_secret,
            (string)$config_mail_oauth_tenant_id,
            (string)$config_mail_oauth_refresh_token,
            (string)$config_mail_oauth_access_token,
            (string)$config_mail_oauth_access_token_expires_at,
            $message_id,
            !empty($row['email_auto'])
        );

        mysqli_query($mysqli, "UPDATE email_queue SET email_status = 3, email_sent_at = NOW(), email_started_at = NULL, email_attempts = $email_attempts WHERE email_id = $email_id");
    } catch (\Throwable $e) {
        // email_failed_at starts the back-off clock (5, 15, 60, 240 minutes - MailQueuePolicy::BACKOFF_MINUTES).
        mysqli_query($mysqli, "UPDATE email_queue SET email_status = 2, email_failed_at = NOW(), email_started_at = NULL, email_attempts = $email_attempts WHERE email_id = $email_id");

        $err = substr("Mailer Error: " . $e->getMessage(), 0, 100) . "...";
        $next = MailQueuePolicy::backoffMinutes($email_attempts);
        $outcome = $next === null ? "no retries left (see the Outbound email gave up alert)" : "retry in $next min";
        logApp("Cron-Mail-Queue", "Error", "Failed to send email #$email_id (attempt $email_attempts) to $email_to_logging regarding $email_subject_logging. $err $outcome");
    }
    return true;
}

/** =======================================================================
 *  SEND: status = 0 (Queued)
 * ======================================================================= */
$sql_queue = mysqli_query($mysqli, "SELECT * FROM email_queue WHERE email_status = 0 AND email_queued_at <= NOW() ORDER BY email_id");

if (mysqli_num_rows($sql_queue) > 0) {
    while ($rowq = mysqli_fetch_assoc($sql_queue)) {
        if ($send_budget <= 0) {
            $send_throttled = true;
            break;
        }
        if (deliverQueueRow($rowq)) {
            $send_budget--;
        }
    }
}

/** =======================================================================
 *  RETRIES: status = 2 (Failed) with retries left. Exponential back-off by attempt count:
 *  after failure 1 wait 5 min, 2 -> 15, 3 -> 60, 4 -> 240; after the 5th failure the row is
 *  exhausted: it is not retried and MailHealth raises the "Outbound email gave up" alert.
 * =======================================================================
 */
if (!$send_throttled) {
    $sql_failed_queue = mysqli_query($mysqli, "SELECT * FROM email_queue WHERE " . MailQueuePolicy::retryDueSql() . " ORDER BY email_failed_at");
    if ($sql_failed_queue && mysqli_num_rows($sql_failed_queue) > 0) {
        while ($rowf = mysqli_fetch_assoc($sql_failed_queue)) {
            if ($send_budget <= 0) {
                $send_throttled = true;
                break;
            }
            if (deliverQueueRow($rowf)) {
                $send_budget--;
            }
        }
    }
}

if ($send_throttled) {
    logApp("Cron-Mail-Queue", "info", "Outbound rate limit (" . MailSettings::int($mysqli, 'outbound_rate_per_min') . "/min) reached; remaining email stays queued for the next run.");
}

// Standing mail checks (exhausted retries, mailbox health). Never allowed to break sending.
try {
    MailHealth::runChecks($mysqli, intval($row['config_ticket_email_parse'] ?? 0) === 1);
} catch (\Throwable $e) {
    logApp("Cron-Mail-Queue", "warning", "Mail health check failed: " . $e->getMessage());
}

/** =======================================================================
 *  Unlock
 * ======================================================================= */
unlink($lock_file_path);
