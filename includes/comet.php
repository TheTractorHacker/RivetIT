<?php
// Comet Backup Server API helper
// Ref: https://docs.cometbackup.com/latest/api/api-guide
// SDK: https://github.com/CometBackup/comet-php-sdk

// ── Job status constants (from Comet SDK Def.php) ─────────────────────────────
// Successful
define('COMET_JOB_SUCCESS',           5000);
// Running
define('COMET_JOB_RUNNING_ACTIVE',    6001);
define('COMET_JOB_RUNNING_REVIVED',   6002);
define('COMET_JOB_RUNNING_TRYAGAIN',  6003);
define('COMET_JOB_NOT_STARTED',       6004);
// Failed
define('COMET_JOB_FAILED_TIMEOUT',    7000);
define('COMET_JOB_FAILED_WARNING',    7001);
define('COMET_JOB_FAILED_ERROR',      7002);
define('COMET_JOB_FAILED_QUOTA',      7003);
define('COMET_JOB_FAILED_MISSED',     7004);
define('COMET_JOB_FAILED_CANCELLED',  7005);
define('COMET_JOB_FAILED_SKIP',       7006);
define('COMET_JOB_FAILED_ABANDONED',  7007);

// ── Webhook event types (from Comet SDK Def.php SEVT_) ────────────────────────
define('COMET_SEVT_JOB_NEW',       4200);
define('COMET_SEVT_JOB_COMPLETED', 4201);

// ── Session key cache (stored in DB for reuse across requests) ────────────────
// We cache the session key to avoid hitting TOTP on every API call.
function comet_get_session_key(): ?string {
    global $mysqli;
    // Use a dedicated settings row to store the cached session key + expiry
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT config_value, config_expires FROM comet_session_cache WHERE id = 1 LIMIT 1"
    ));
    if ($row && !empty($row['config_value']) && strtotime($row['config_expires']) > time() + 60) {
        return $row['config_value'];
    }
    return null;
}

function comet_store_session_key(string $key, int $expires_in = 3600): void {
    global $mysqli;
    $key_safe = mysqli_real_escape_string($mysqli, $key);
    $expires   = date('Y-m-d H:i:s', time() + $expires_in);
    mysqli_query($mysqli, "INSERT INTO comet_session_cache (id, config_value, config_expires) VALUES (1, '$key_safe', '$expires') ON DUPLICATE KEY UPDATE config_value='$key_safe', config_expires='$expires'");
}

// ── Connection diagnostics ──────────────────────────────────────────────────
// comet_api() has no return channel for *why* a request failed (wrong port,
// connection refused, TLS error, timeout, malformed URL, ...). It just
// returns null, so the settings page can only ever show a generic "Cannot
// reach server". Stash the last failure here so callers/UI can surface it.
function comet_set_last_error(string $msg): void {
    $GLOBALS['__comet_last_error'] = $msg;
}
function comet_get_last_error(): ?string {
    return $GLOBALS['__comet_last_error'] ?? null;
}

// Server URLs are often typed without a scheme (e.g. "127.0.0.1:8060" or
// "comet.local:8060") since the box is "local" — curl treats that as a
// relative/malformed URL and fails before ever opening a connection. Assume
// http:// when no scheme is given (Comet Server's own UI defaults to http).
function comet_normalize_url(string $url): string {
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'http://' . $url;
    }
    return $url;
}

// ── Core HTTP request ─────────────────────────────────────────────────────────
function comet_api(string $endpoint, array $extra = []): ?array {
    global $config_comet_server_url, $config_comet_admin_user,
           $config_comet_admin_pass, $config_comet_totp_secret;
    if (empty($config_comet_server_url) || empty($config_comet_admin_user)) {
        comet_set_last_error('Server URL or admin username not configured.');
        return null;
    }

    // Try cached session key first (avoids TOTP re-entry)
    $session_key = comet_get_session_key();
    if ($session_key) {
        $auth = ['Username' => $config_comet_admin_user, 'AuthType' => 'SessionKey', 'SessionKey' => $session_key];
    } elseif (!empty($config_comet_totp_secret)) {
        // Authenticate with password + TOTP code, then get and cache a session key
        require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/totp/totp.php';
        $code = TokenAuth6238::getTokenCode($config_comet_totp_secret);
        $auth = ['Username' => $config_comet_admin_user, 'AuthType' => 'PasswordTOTP',
                 'Password' => $config_comet_admin_pass, 'TOTP' => $code];
    } else {
        $auth = ['Username' => $config_comet_admin_user, 'AuthType' => 'Password',
                 'Password' => $config_comet_admin_pass];
    }

    $body = http_build_query(array_merge($auth, $extra));
    $url  = rtrim(comet_normalize_url($config_comet_server_url), '/') . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp     = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        comet_set_last_error("Could not connect to $url — $curl_err");
        return null;
    }
    if ($code !== 200 || !$resp) {
        comet_set_last_error("Comet Server responded with HTTP $code at $url" . ($resp ? ' — ' . substr($resp, 0, 200) : ''));
        return null;
    }

    $data = json_decode($resp, true);
    if (!empty($data['Error']) || !empty($data['Status']) && intval($data['Status']) >= 400) {
        comet_set_last_error('Comet Server error: ' . ($data['Message'] ?? $data['Error'] ?? 'Unknown error'));
    }

    return $data;
}

// ── Session key login (call once to populate cache) ───────────────────────────
function comet_start_session(): bool {
    global $config_comet_admin_user, $config_comet_admin_pass, $config_comet_totp_secret, $config_comet_server_url;
    if (empty($config_comet_server_url)) {
        comet_set_last_error('Server URL not configured.');
        return false;
    }

    if (!empty($config_comet_totp_secret)) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/totp/totp.php';
        $code = TokenAuth6238::getTokenCode($config_comet_totp_secret);
        $auth = ['Username' => $config_comet_admin_user, 'AuthType' => 'PasswordTOTP',
                 'Password' => $config_comet_admin_pass, 'TOTP' => $code];
    } else {
        $auth = ['Username' => $config_comet_admin_user, 'AuthType' => 'Password',
                 'Password' => $config_comet_admin_pass];
    }
    $body = http_build_query($auth);
    $url  = rtrim(comet_normalize_url($config_comet_server_url), '/') . '/api/v1/admin/account/session-start';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw      = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        comet_set_last_error("Could not connect to $url — $curl_err");
        return false;
    }
    if ($code !== 200 || !$raw) {
        comet_set_last_error("Comet Server responded with HTTP $code at $url");
        return false;
    }

    $resp = json_decode($raw, true);
    if (!empty($resp['SessionKey'])) {
        comet_store_session_key($resp['SessionKey'], 3600);
        return true;
    }

    comet_set_last_error('Login rejected: ' . ($resp['Message'] ?? 'invalid credentials or TOTP code.'));
    return false;
}

// ── Convenience wrappers ──────────────────────────────────────────────────────

function comet_test(): bool {
    $r = comet_api('/api/v1/admin/meta/version');
    return is_array($r) && (isset($r['Version']) || isset($r['ServerVersion']));
}

function comet_get_users(): ?array {
    return comet_api('/api/v1/admin/list-users-full');
}

// Single-user profile fetch (cheaper than comet_get_users() when only one
// user's Devices dictionary is needed). Cached per-request since
// comet_process_job() may be called many times in a row for the same user
// during a cron poll of recent jobs.
function comet_get_user_profile(string $username): ?array {
    static $cache = [];
    if (array_key_exists($username, $cache)) return $cache[$username];
    return $cache[$username] = comet_api('/api/v1/admin/get-user-profile', ['TargetUser' => $username]);
}

// Comet's job objects only carry DeviceID, not a display name — resolve the
// FriendlyName from the user's profile so tickets/alerts/logs are readable.
function comet_resolve_device_name(string $username, string $device_id): string {
    if ($device_id === '') return 'Unknown Device';
    $profile = comet_get_user_profile($username);
    return $profile['Devices'][$device_id]['FriendlyName'] ?? $device_id;
}

function comet_get_jobs_for_user(string $username): ?array {
    return comet_api('/api/v1/admin/get-jobs-for-user', ['TargetUser' => $username]);
}

function comet_get_jobs_recent(): ?array {
    return comet_api('/api/v1/admin/get-jobs-recent');
}

// ── Status helpers ────────────────────────────────────────────────────────────

function comet_is_failed(int $s): bool {
    return $s >= 7000 && $s <= 7999;
}
function comet_is_running(int $s): bool {
    return $s >= 6000 && $s <= 6999;
}
function comet_is_success(int $s): bool {
    return $s >= 5000 && $s < 6000;
}

function comet_status_label(int $s): string {
    return match(true) {
        $s === COMET_JOB_SUCCESS          => 'Success',
        $s === COMET_JOB_RUNNING_ACTIVE   => 'Running',
        $s === COMET_JOB_RUNNING_REVIVED  => 'Running',
        $s === COMET_JOB_RUNNING_TRYAGAIN => 'Retrying',
        $s === COMET_JOB_NOT_STARTED      => 'Pending',
        $s === COMET_JOB_FAILED_WARNING   => 'Warning',
        $s === COMET_JOB_FAILED_TIMEOUT   => 'Timeout',
        $s === COMET_JOB_FAILED_ERROR     => 'Error',
        $s === COMET_JOB_FAILED_QUOTA     => 'Quota exceeded',
        $s === COMET_JOB_FAILED_MISSED    => 'Schedule missed',
        $s === COMET_JOB_FAILED_CANCELLED => 'Cancelled',
        $s === COMET_JOB_FAILED_ABANDONED => 'Abandoned',
        comet_is_failed($s)               => 'Failed',
        comet_is_running($s)              => 'Running',
        default                           => 'Unknown',
    };
}

function comet_status_class(int $s): string {
    if (comet_is_success($s)) return 'success';
    if (comet_is_running($s)) return 'info';
    if ($s === COMET_JOB_FAILED_WARNING) return 'warning';
    if (comet_is_failed($s)) return 'danger';
    return 'secondary';
}

function comet_status_icon(int $s): string {
    if (comet_is_success($s)) return 'check-circle';
    if (comet_is_running($s)) return 'spinner fa-spin';
    if ($s === COMET_JOB_FAILED_WARNING) return 'exclamation-triangle';
    if (comet_is_failed($s)) return 'times-circle';
    return 'circle';
}

function comet_job_type(int $c): string {
    return match($c) { 4001 => 'Backup', 4002 => 'Restore', default => 'Other' };
}

function comet_fmt_bytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
}

// Sort priority for device status tables: failed first, then warning, then
// no-job-history ("unknown"), then everything else (success/running) last.
// Uses the real Comet SDK status codes via comet_is_failed()/etc — keep this
// as the single source of truth so dashboards can't drift back to fake codes.
function comet_status_sort_rank(int $status, bool $has_job): int {
    if (!$has_job) return 2;
    if ($status === COMET_JOB_FAILED_WARNING) return 1;
    if (comet_is_failed($status)) return 0;
    return 3;
}

// ── Shared failure/recovery handling (used by webhook + cron polling fallback) ─
// Creates a high-priority ticket + appNotify the first time a device's backup
// job is seen in a failed state, and auto-resolves/replies on the next
// successful job for that device. Idempotent: safe to call repeatedly with
// the same job (existing open alert short-circuits ticket creation).
function comet_process_job(array $job): array {
    global $mysqli;

    $status    = intval($job['Status'] ?? 0);
    $username  = (string) ($job['Username'] ?? '');
    $device_id = (string) ($job['DeviceID'] ?? '');
    $err_msg   = (string) ($job['ErrorString'] ?? '');
    $job_type  = intval($job['Classification'] ?? 4001);

    if ($username === '') {
        return ['action' => 'ignored', 'reason' => 'no_username'];
    }

    // Only process backup jobs (not restores)
    if ($job_type !== 4001) {
        return ['action' => 'ignored', 'reason' => 'non_backup_job'];
    }

    // Jobs only carry DeviceID — resolve a display name for the ticket/alert.
    // alert_device_name stores this resolved name (not the ID) since that's
    // also the key comet_check_missed_backups() and the dashboards use.
    $dev_name = comet_resolve_device_name($username, $device_id);

    $u_safe = mysqli_real_escape_string($mysqli, $username);
    $d_safe = mysqli_real_escape_string($mysqli, $dev_name);

    $map = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT map_client_id FROM comet_client_map WHERE map_comet_username = '$u_safe' LIMIT 1"
    ));
    $client_id = $map ? intval($map['map_client_id']) : 0;

    if (comet_is_failed($status)) {
        // Check for existing open alert for this device
        $existing = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT alert_id, alert_ticket_id FROM comet_backup_alerts
             WHERE alert_comet_username = '$u_safe'
               AND alert_device_name = '$d_safe'
               AND alert_resolved_at IS NULL
             LIMIT 1"
        ));

        if ($existing) {
            return ['action' => 'existing_alert', 'ticket_id' => intval($existing['alert_ticket_id']), 'device_name' => $dev_name];
        }

        // Get next ticket number
        $settings = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT config_ticket_prefix FROM settings WHERE company_id = 1"
        ));
        $prefix = sanitizeInput($settings['config_ticket_prefix']);

        // Atomically increment-and-fetch via LAST_INSERT_ID(), same pattern used by
        // every other ticket-creation path - a plain SELECT-then-UPDATE here raced
        // against those paths and produced duplicate ticket_number values.
        mysqli_query($mysqli, "
            UPDATE settings
            SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
            WHERE company_id = 1
        ");
        $ticket_number = mysqli_insert_id($mysqli);

        $status_label = comet_status_label($status);
        $severity     = ($status === COMET_JOB_FAILED_WARNING) ? 'warning' : 'critical';
        $subject_safe = sanitizeInput("Backup $status_label — $dev_name");
        $message      = "Comet Backup reported a failure for device $dev_name (user: $username). Status: $status_label (code: $status)." . ($err_msg ? " Error: $err_msg" : '');
        $detail_safe  = sanitizeInput(
            "Comet Backup reported a failure for device **$dev_name** (user: $username).\n\n" .
            "Status: $status_label (code: $status)\n" .
            ($err_msg ? "Error: $err_msg\n\n" : "\n") .
            "Please check the device is online, the Comet agent is running, and there are no storage issues."
        );

        $url_key = bin2hex(random_bytes(16));
        $resolved_assigned_to = resolveTicketAssignee(0);
        $ticket_status = $resolved_assigned_to > 0 ? 2 : 1;
        mysqli_query($mysqli, "INSERT INTO tickets SET
            ticket_prefix = '$prefix',
            ticket_number = $ticket_number,
            ticket_source = 'Comet Backup',
            ticket_subject = '$subject_safe',
            ticket_details = '$detail_safe',
            ticket_priority = 'High',
            ticket_status = $ticket_status,
            ticket_client_id = $client_id,
            ticket_created_by = 0,
            ticket_assigned_to = $resolved_assigned_to,
            ticket_url_key = '$url_key'
        ");
        $ticket_id = mysqli_insert_id($mysqli);

        $msg_esc = mysqli_real_escape_string($mysqli, $message);
        mysqli_query($mysqli, "INSERT INTO comet_backup_alerts SET
            alert_comet_username = '$u_safe',
            alert_device_name    = '$d_safe',
            alert_type           = 'failed',
            alert_severity       = '$severity',
            alert_message        = '$msg_esc',
            alert_client_id      = " . ($client_id ?: 'NULL') . ",
            alert_ticket_id      = $ticket_id,
            alert_status         = 'new'
        ");

        $client_suffix = $client_id ? "&client_id=$client_id" : '';
        appNotify('Comet Backup', "Backup failed — $dev_name. Ticket #$ticket_id created.", "/agent/ticket.php?ticket_id=$ticket_id$client_suffix");

        return ['action' => 'ticket_created', 'ticket_id' => $ticket_id, 'device_name' => $dev_name];

    } elseif (comet_is_success($status)) {
        $open = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT alert_id, alert_ticket_id FROM comet_backup_alerts
             WHERE alert_comet_username = '$u_safe'
               AND alert_device_name = '$d_safe'
               AND alert_resolved_at IS NULL
             LIMIT 1"
        ));

        if (!$open) {
            return ['action' => 'no_open_alert', 'device_name' => $dev_name];
        }

        $tid = intval($open['alert_ticket_id']);
        mysqli_query($mysqli, "UPDATE comet_backup_alerts SET alert_status = 'resolved', alert_resolved_at = NOW() WHERE alert_id = {$open['alert_id']}");
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET
            ticket_reply = 'Backup succeeded — device is healthy again. Ticket auto-resolved by Comet integration.',
            ticket_reply_type = 'Internal',
            ticket_reply_by = 0,
            ticket_reply_ticket_id = $tid
        ");
        // Resolved is an immediate alias for Closed everywhere else in this app -
        // match that here too instead of leaving the ticket stuck at status 4
        // relying on the slow cron-based auto-close.
        $comet_resolved_status_id = resolveTicketStatusId(4);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $comet_resolved_status_id, ticket_resolved_at = NOW(), ticket_closed_at = NOW() WHERE ticket_id = $tid");

        return ['action' => 'ticket_resolved', 'ticket_id' => $tid, 'device_name' => $dev_name];
    }

    return ['action' => 'status_ignored', 'status' => $status, 'device_name' => $dev_name];
}

// Flags devices that haven't reported ANY job (success or failure) within
// $threshold_hours as a distinct "missed" alert — explicit failures are
// already caught immediately by comet_process_job(); this catches the
// device going quiet entirely (offline, agent removed, schedule disabled),
// which never generates a job/webhook event for comet_process_job() to see.
function comet_check_missed_backups(int $threshold_hours = 48): array {
    global $mysqli;
    $stats = ['flagged' => 0];

    $comet_users = comet_get_users();
    if (!$comet_users) return $stats;

    $maps_sql   = mysqli_query($mysqli, "SELECT map_comet_username, map_client_id FROM comet_client_map");
    $client_for = [];
    while ($m = mysqli_fetch_assoc($maps_sql)) {
        $client_for[$m['map_comet_username']] = intval($m['map_client_id']);
    }

    $cutoff = time() - ($threshold_hours * 3600);

    foreach ($comet_users as $username => $userdata) {
        $last_jobs = comet_last_jobs_per_device($username);
        $devices   = $userdata['Devices'] ?? [];
        $u_safe    = mysqli_real_escape_string($mysqli, $username);

        foreach ($devices as $devid => $devdata) {
            $dev_name   = $devdata['FriendlyName'] ?? $devid;
            $registered = intval($devdata['RegistrationTime'] ?? 0);
            $job        = $last_jobs[$devid] ?? null;
            $last_start = $job ? intval($job['StartTime']) : 0;

            // Judge against whichever is more recent: last job, or
            // registration time — gives a brand-new device a full grace
            // period before being flagged for never having backed up yet.
            $reference = max($last_start, $registered);
            if ($reference === 0 || $reference > $cutoff) {
                continue;
            }

            $d_safe = mysqli_real_escape_string($mysqli, $dev_name);
            $existing = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT alert_id FROM comet_backup_alerts
                 WHERE alert_comet_username='$u_safe' AND alert_device_name='$d_safe' AND alert_resolved_at IS NULL LIMIT 1"
            ));
            if ($existing) continue;

            $client_id = $client_for[$username] ?? 0;
            $hours_ago = intval(round((time() - $reference) / 3600));

            $settings = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT config_ticket_prefix FROM settings WHERE company_id = 1"
            ));
            $prefix = sanitizeInput($settings['config_ticket_prefix']);

            // Atomically increment-and-fetch via LAST_INSERT_ID(), same pattern used by
            // every other ticket-creation path - a plain SELECT-then-UPDATE here raced
            // against those paths (and against other iterations of this same loop
            // running back to back) and produced duplicate ticket_number values.
            mysqli_query($mysqli, "
                UPDATE settings
                SET
                    config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                    config_ticket_next_number = config_ticket_next_number + 1
                WHERE company_id = 1
            ");
            $ticket_number = mysqli_insert_id($mysqli);

            $subject_safe = sanitizeInput("Backup Missed — $dev_name");
            $message      = "No backup job reported for device $dev_name (user: $username) in over $hours_ago hours.";
            $detail_safe  = sanitizeInput(
                "$message\n\nThe device may be offline, the Comet agent may not be running, or its backup schedule may be disabled. Please check the device."
            );

            $url_key = bin2hex(random_bytes(16));
            $resolved_assigned_to = resolveTicketAssignee(0);
            $ticket_status = $resolved_assigned_to > 0 ? 2 : 1;
            mysqli_query($mysqli, "INSERT INTO tickets SET
                ticket_prefix = '$prefix',
                ticket_number = $ticket_number,
                ticket_source = 'Comet Backup',
                ticket_subject = '$subject_safe',
                ticket_details = '$detail_safe',
                ticket_priority = 'High',
                ticket_status = $ticket_status,
                ticket_client_id = $client_id,
                ticket_created_by = 0,
                ticket_assigned_to = $resolved_assigned_to,
                ticket_url_key = '$url_key'
            ");
            $ticket_id = mysqli_insert_id($mysqli);

            $msg_esc = mysqli_real_escape_string($mysqli, $message);
            mysqli_query($mysqli, "INSERT INTO comet_backup_alerts SET
                alert_comet_username = '$u_safe',
                alert_device_name    = '$d_safe',
                alert_type           = 'missed',
                alert_severity       = 'warning',
                alert_message        = '$msg_esc',
                alert_client_id      = " . ($client_id ?: 'NULL') . ",
                alert_ticket_id      = $ticket_id,
                alert_status         = 'new'
            ");

            $client_suffix = $client_id ? "&client_id=$client_id" : '';
            appNotify('Comet Backup', "Backup missed — $dev_name. Ticket #$ticket_id created.", "/agent/ticket.php?ticket_id=$ticket_id$client_suffix");
            $stats['flagged']++;
        }
    }

    return $stats;
}

// Returns the most recent job per device, keyed by Comet's DeviceID (the
// same key used in a user profile's Devices dictionary) — NOT by device
// name. BackupJobDetail has no "DeviceName" field at all (only DeviceID);
// keying by a field that never existed meant every lookup by friendly name
// always missed, and every device showed as "Unknown" regardless of its
// real backup status.
function comet_last_jobs_per_device(string $username): array {
    $jobs = comet_get_jobs_for_user($username) ?: [];
    usort($jobs, fn($a, $b) => ($b['StartTime'] ?? 0) - ($a['StartTime'] ?? 0));
    $seen = [];
    foreach ($jobs as $j) {
        $dev = $j['DeviceID'] ?? '';
        if ($dev !== '' && !isset($seen[$dev])) $seen[$dev] = $j;
    }
    return $seen;
}
