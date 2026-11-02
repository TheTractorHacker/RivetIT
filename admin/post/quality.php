<?php

/*
 * RivetIT - POST handler for Administration > Quality (the Quality module, issue #52).
 *
 * Reached through admin/post.php, which loads it only for administrators and only when the Referer is admin/quality.php.
 * Every action: CSRF check, administrator check, audit entry. Secrets are write-only: they are sealed with encryptSetting() on save and are never printed again
 * (a NEW signing secret is shown once, straight after it is created, from the session).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use ITFlow\Quality\Outbox;
use ITFlow\Quality\QmsClient;
use ITFlow\Quality\QmsUrl;
use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityLog;
use ITFlow\Quality\SyncWorker;

$q_back = static function (string $anchor = ''): never {
    redirect('/admin/quality.php' . ($anchor !== '' ? '#' . $anchor : ''));
};
$q_guard = static function () use ($session_is_admin, $mysqli, $q_back): void {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    if (empty($session_is_admin)) {
        flash_alert('Administrators only.', 'danger');
        $q_back();
    }
    if (!QualityConfig::ready($mysqli)) {
        flash_alert('The database has not been updated for Quality yet (DB 2.6.230). Run Administration > Update > Update Database.', 'danger');
        $q_back();
    }
};
$q_audit = static function (string $what) use ($mysqli, $session_user_id, $session_name): void {
    logAction('Quality', 'Settings', "$session_name: $what");
    \ITFlow\Audit\AuditService::record('quality.settings_changed', intval($session_user_id), 'quality_settings', 1, $what);
};

// ---- module switch, access and tuning ------------------------------------------------------------------------------------------------------
if (isset($_POST['save_quality_module'])) {
    $q_guard();
    $old = QualityConfig::moduleOn($mysqli);
    $on = !empty($_POST['module_enabled']);
    $row = QualityConfig::load($mysqli);
    $events = !empty($_POST['events_enabled']) ? 1 : 0;
    $inbound = !empty($_POST['inbound_enabled']) ? 1 : 0;
    if ($on && !QualityConfig::configured($row) && ($events || $inbound)) {
        flash_alert('Set up the QMS connection before switching on events.', 'danger');
        $q_back('connection');
    }
    if ($events && (string) $row['qs_outbound_secret_enc'] === '') {
        flash_alert('Create the outbound signing secret first (Signing secrets), then switch events on.', 'danger');
        $q_back('secrets');
    }
    if ($inbound && (string) $row['qs_inbound_secret_enc'] === '') {
        flash_alert('Create the inbound signing secret first (Signing secrets), then switch inbound events on.', 'danger');
        $q_back('secrets');
    }
    QualityConfig::set($mysqli, [
        'qs_events_enabled' => $events, 'qs_inbound_enabled' => $inbound, 'qs_all_departments' => !empty($_POST['all_departments']) ? 1 : 0,
        'qs_sync_interval_minutes' => QualityConfig::clamp(intval($_POST['sync_interval'] ?? 5), 1, 1440),
        'qs_reconcile_interval_hours' => QualityConfig::clamp(intval($_POST['reconcile_interval'] ?? 24), 1, 720),
        'qs_reauth_minutes' => QualityConfig::clamp(intval($_POST['reauth_minutes'] ?? 15), 1, 120),
        'qs_connect_timeout_s' => QualityConfig::clamp(intval($_POST['connect_timeout'] ?? 3), 1, 15),
        'qs_total_timeout_s' => QualityConfig::clamp(intval($_POST['total_timeout'] ?? 10), 2, 60),
    ], intval($session_user_id));
    QualityConfig::setModule($mysqli, $on);
    $q_audit('Quality module ' . ($on ? 'on' : 'off') . "; events " . ($events ? 'on' : 'off') . "; inbound " . ($inbound ? 'on' : 'off') . ($old !== $on ? ' (module switch changed)' : ''));
    flash_alert('Quality settings saved. The module is <strong>' . ($on ? 'on' : 'off') . '</strong>.');
    $q_back();
}

if (isset($_POST['save_quality_features'])) {
    $q_guard();
    $keys = array_values(array_intersect(array_keys(QualityConfig::FEATURES), array_map('strval', (array) ($_POST['features'] ?? []))));
    QualityConfig::setFeatures($mysqli, $keys);
    $q_audit('Quality features on: ' . ($keys ? implode(', ', $keys) : 'none'));
    flash_alert('Features saved.');
    $q_back('access');
}

if (isset($_POST['save_quality_departments'])) {
    $q_guard();
    $ids = array_map('intval', (array) ($_POST['departments'] ?? []));
    // Only real, existing departments can be enabled.
    $valid = [];
    if ($ids) {
        $in = implode(',', array_unique(array_filter($ids, static fn($i) => $i > 0)));
        $r = $in === '' ? false : mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id IN ($in)");
        while ($r && ($c = mysqli_fetch_row($r))) {
            $valid[] = (int) $c[0];
        }
    }
    QualityConfig::setDepartments($mysqli, $valid, intval($session_user_id));
    $q_audit('Quality departments enabled: ' . ($valid ? implode(',', $valid) : 'none'));
    flash_alert('Departments saved. People in other departments lose access at once.');
    $q_back('access');
}

// ---- connection --------------------------------------------------------------------------------------------------------------------------------
if (isset($_POST['save_quality_connection'])) {
    $q_guard();
    $url = trim((string) ($_POST['qms_url'] ?? ''));
    $tokenUrl = trim((string) ($_POST['token_url'] ?? ''));
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    $scope = trim(preg_replace('/\s+/', ' ', (string) ($_POST['scope'] ?? 'quality:read quality:write')) ?? '');
    if ($url !== '') {
        $v = QmsUrl::vet($mysqli, $url);
        if (!$v['ok']) {
            flash_alert(nullable_htmlentities($v['error']), 'danger');
            $q_back('connection');
        }
        $url = $v['base'];
        if ($tokenUrl !== '' && (!QmsUrl::sameOrigin($url, $tokenUrl) || !QmsUrl::vet($mysqli, $tokenUrl)['ok'])) {
            flash_alert('The token address must be on the same host as the QMS address and pass the same checks.', 'danger');
            $q_back('connection');
        }
    }
    if (strlen($clientId) > 190 || preg_match('/^[\x21-\x7e]*$/D', $clientId) !== 1 || preg_match('/^[A-Za-z0-9:_. -]{1,255}$/D', $scope) !== 1) {
        flash_alert('The client id or scope has characters that are not allowed.', 'danger');
        $q_back('connection');
    }
    $vals = ['qs_qms_url' => $url === '' ? null : $url, 'qs_token_url' => $tokenUrl === '' ? null : $tokenUrl, 'qs_client_id' => $clientId === '' ? null : $clientId, 'qs_scope' => $scope,
        'qs_token_enc' => null, 'qs_token_expires_at' => null];   // a changed address or credential must never reuse the cached token
    $newSecret = (string) ($_POST['client_secret'] ?? '');
    if (!empty($_POST['clear_secret'])) {
        $vals['qs_client_secret_enc'] = null;
    } elseif ($newSecret !== '') {
        if (strlen($newSecret) > 512) {
            flash_alert('That client secret is too long.', 'danger');
            $q_back('connection');
        }
        $vals['qs_client_secret_enc'] = QualityConfig::sealSecret($newSecret);
    }
    QualityConfig::set($mysqli, $vals, intval($session_user_id));
    $q_audit('QMS connection saved (address ' . ($url === '' ? 'none' : parse_url($url, PHP_URL_HOST)) . '; secret ' . ($newSecret !== '' ? 'replaced' : (!empty($_POST['clear_secret']) ? 'removed' : 'unchanged')) . ')');
    flash_alert('Connection saved. Use <strong>Test connection</strong> to check it.');
    $q_back('connection');
}

if (isset($_POST['test_quality_connection'])) {
    $q_guard();
    $client = QmsClient::fromDb($mysqli);
    if ($client === null) {
        flash_alert('Save the QMS address, client id and client secret first.', 'warning');
        $q_back('connection');
    }
    $t = $client->test();
    QualityConfig::set($mysqli, ['qs_last_test_at' => date('Y-m-d H:i:s'), 'qs_last_test_ok' => $t['ok'] ? 1 : 0, 'qs_last_test_ms' => $t['ms'], 'qs_last_test_message' => substr(QualityLog::scrub($t['message']), 0, 300)]);
    $q_audit('Connection test ' . ($t['ok'] ? 'passed' : 'failed'));
    flash_alert(nullable_htmlentities($t['message']) . ' (' . intval($t['ms']) . ' ms)', $t['ok'] ? 'success' : 'danger');
    $q_back('connection');
}

// ---- signing secrets ------------------------------------------------------------------------------------------------------------------------------
if (isset($_POST['new_quality_secret'])) {
    $q_guard();
    $which = (string) ($_POST['which'] ?? '');
    if (!in_array($which, ['outbound', 'inbound'], true)) {
        flash_alert('Unknown secret.', 'danger');
        $q_back('secrets');
    }
    $plain = QualityConfig::newSigningSecret();
    QualityConfig::set($mysqli, [$which === 'outbound' ? 'qs_outbound_secret_enc' : 'qs_inbound_secret_enc' => QualityConfig::sealSecret($plain)], intval($session_user_id));
    $_SESSION['quality_new_secret'] = ['which' => $which, 'value' => $plain];   // shown once on the next page view, then removed
    $q_audit("New $which signing secret created");
    flash_alert("A new $which signing secret was created. Copy it now; it is not shown again.");
    $q_back('secrets');
}

// ---- sync, events and logs --------------------------------------------------------------------------------------------------------------------
if (isset($_POST['run_quality_sync'])) {
    $q_guard();
    if (!QualityConfig::moduleOn($mysqli)) {
        flash_alert('Quality is switched off, so nothing was synchronized.', 'warning');
        $q_back('health');
    }
    $r = SyncWorker::run($mysqli, ['force' => true, 'max_pages' => 10, 'batch' => 25, 'budget_s' => 20, 'no_prune' => true]);
    $q_audit('Manual sync run: ' . $r['status']);
    flash_alert('Sync finished: ' . nullable_htmlentities($r['status']) . '. Queued ' . intval($r['produced']) . ' new event(s); sent ' . intval($r['sent']) . ', will retry ' . intval($r['retry']) . ', dead ' . intval($r['dead']) . '.', $r['status'] === 'ok' ? 'success' : 'warning');
    $q_back('health');
}

if (isset($_POST['quality_full_resync'])) {
    $q_guard();
    SyncWorker::requestFullResync($mysqli);
    $q_audit('Full resync requested');
    flash_alert('A full resync is queued. The next scheduled sync (or "Run sync now") queues one event per assignment, completion, person and department; this can be large.');
    $q_back('health');
}

if (isset($_POST['replay_quality_dead'])) {
    $q_guard();
    $id = intval($_POST['event_id'] ?? 0);
    $n = Outbox::replay($mysqli, $id > 0 ? $id : null);
    $q_audit("Replayed $n dead event(s)" . ($id > 0 ? " (#$id)" : ''));
    flash_alert("$n event(s) put back in the queue.");
    $q_back('events');
}

if (isset($_POST['clear_quality_errors'])) {
    $q_guard();
    mysqli_query($mysqli, 'DELETE FROM quality_error_log');
    $q_audit('Integration error log cleared');
    flash_alert('Error log cleared.');
    $q_back('errors');
}
