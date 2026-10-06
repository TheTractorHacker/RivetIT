<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Audit\AuditService;
use ITFlow\Redis\RedisSettings;

/** Connection values from the form, falling back to what is currently in effect for fields the server controls or the form left blank. */
$redis_form_params = static function (array $current): array {
    $host = $current['from_env']['host'] ? $current['host'] : trim((string) ($_POST['redis_host'] ?? ''));
    $port = $current['from_env']['port'] ? $current['port'] : (int) ($_POST['redis_port'] ?? 0);
    $db = $current['from_env']['db'] ? $current['db'] : (int) ($_POST['redis_db'] ?? 0);
    $typed = (string) ($_POST['redis_password'] ?? '');
    $clear = isset($_POST['redis_clear_password']);
    $password = $current['from_env']['password'] ? $current['password'] : ($typed !== '' ? $typed : ($clear ? null : $current['password']));
    $text = static fn (string $k, string $post) => $current['from_env'][$k] ? $current[$k] : (trim((string) ($_POST[$post] ?? '')) ?: null);
    $flag = static fn (string $k, string $post) => $current['from_env'][$k] ? (bool) $current[$k] : isset($_POST[$post]);
    $tls = $flag('tls', 'redis_tls');
    // The server's environment says TLS is off: the certificate paths are not in use, so they neither apply nor get validated.
    $paths_idle = $current['from_env']['tls'] && !$tls;
    return [
        'paths_idle' => $paths_idle,
        'host' => $host, 'port' => $port, 'db' => $db, 'password' => $password, 'typed' => $typed, 'clear' => $clear,
        'username' => $text('username', 'redis_username'),
        'tls' => $tls,
        'tls_verify' => $flag('tls_verify', 'redis_tls_verify'),
        'tls_ca_file' => $paths_idle ? null : $text('tls_ca_file', 'redis_tls_ca_file'),
        'tls_cert_file' => $paths_idle ? null : $text('tls_cert_file', 'redis_tls_cert_file'),
        'tls_key_file' => $paths_idle ? null : $text('tls_key_file', 'redis_tls_key_file'),
    ];
};

/** The test result as a sentence for the admin: what kind of problem it was, then the fix. Never contains the password. */
$redis_test_message = static function (array $test): string {
    $label = ['auth' => 'Sign-in problem', 'tls' => 'TLS problem', 'unreachable' => 'Cannot reach Redis', 'invalid' => 'Check the settings', 'unexpected' => 'Unexpected reply'][$test['reason'] ?? ''] ?? '';
    return ($label !== '' ? $label . ': ' : '') . $test['message'];
};

if (isset($_POST['test_redis_connection']) || isset($_POST['save_redis_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $current = RedisSettings::resolve($mysqli);
    $p = $redis_form_params($current);
    if ($error = RedisSettings::validateParams($p, true)) {
        flash_alert($error, 'error');
        redirect();
    }
    $test = RedisSettings::test($p);

    if (isset($_POST['test_redis_connection'])) {
        flash_alert($test['ok'] ? 'Connected to Redis. Nothing was saved.' : $redis_test_message($test), $test['ok'] ? 'success' : 'error');
        redirect();
    }
    if (!$current['schema_ready']) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    if (!$test['ok'] && !isset($_POST['redis_force'])) {
        flash_alert($redis_test_message($test) . ' Nothing was saved. Tick "Save even if it cannot connect right now" to save anyway.', 'error');
        redirect();
    }

    $host = $current['from_env']['host'] ? $current['stored_host'] : $p['host'];
    $port = $current['from_env']['port'] ? $current['stored_port'] : $p['port'];
    $db = $current['from_env']['db'] ? $current['stored_db'] : $p['db'];
    $stmt = $mysqli->prepare('UPDATE settings SET config_redis_host = ?, config_redis_port = ?, config_redis_db = ? WHERE company_id = 1');
    $stmt->bind_param('sii', $host, $port, $db);
    $stmt->execute();

    // ACL username, TLS and certificate paths: a value the server's environment controls is left as saved.
    $st = $current['stored'];
    $pick = static fn (string $k) => $current['from_env'][$k] || ($p['paths_idle'] && str_ends_with($k, '_file')) ? $st[$k] : $p[$k];
    $username = (string) $pick('username');
    $tls = (int) (bool) $pick('tls');
    $verify = (int) (bool) $pick('tls_verify');
    $ca = (string) $pick('tls_ca_file');
    $cert = (string) $pick('tls_cert_file');
    $key = (string) $pick('tls_key_file');
    $stmt = $mysqli->prepare('UPDATE settings SET config_redis_username = ?, config_redis_tls = ?, config_redis_tls_verify = ?, config_redis_tls_ca_file = ?, config_redis_tls_cert_file = ?, config_redis_tls_key_file = ? WHERE company_id = 1');
    $stmt->bind_param('siisss', $username, $tls, $verify, $ca, $cert, $key);
    $stmt->execute();

    if (!$current['from_env']['password'] && ($p['typed'] !== '' || $p['clear'])) {
        $enc = $p['typed'] !== '' ? encryptSetting($p['typed']) : '';
        $stmt = $mysqli->prepare('UPDATE settings SET config_redis_password = ? WHERE company_id = 1');
        $stmt->bind_param('s', $enc);
        $stmt->execute();
    }
    logAction('Settings', 'Edit', "$session_name edited Redis connection settings");
    AuditService::record('redis.settings_changed', (int) $session_user_id, 'settings', 'redis', 'update', 'Redis connection settings changed', ['host' => $host, 'port' => $port, 'db' => $db, 'username' => $username, 'tls' => (bool) $tls, 'tls_verify' => (bool) $verify, 'connected' => $test['ok']]);
    flash_alert($test['ok'] ? 'Redis settings saved. Connected.' : 'Redis settings saved, but it could not connect.', $test['ok'] ? 'success' : 'warning');
    redirect();
}

if (isset($_POST['save_api_rate_limit'])) {
    validateCSRFToken($_POST['csrf_token']);
    $limit = (int) ($_POST['api_rate_limit'] ?? 0);
    if ($limit < 10 || $limit > 100000) {
        flash_alert('Enter a limit between 10 and 100000 requests per minute.', 'error');
        redirect();
    }
    $stmt = $mysqli->prepare('UPDATE settings SET config_api_rate_limit = ? WHERE company_id = 1');
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    logAction('Settings', 'Edit', "$session_name set the REST API rate limit to $limit per minute");
    AuditService::record('api.rate_limit_changed', (int) $session_user_id, 'settings', 'api_rate_limit', 'update', 'REST API rate limit changed', ['per_minute' => $limit]);
    flash_alert("REST API rate limit set to $limit requests per minute.");
    redirect();
}

if (isset($_POST['set_redis_memory'])) {
    validateCSRFToken($_POST['csrf_token']);
    try {
        $client = RedisSettings::client(RedisSettings::resolve($mysqli), 1.0);
        $client->connect();
        $result = RedisSettings::setMemory($client, (int) ($_POST['redis_mb'] ?? 0), (string) ($_POST['redis_policy'] ?? ''));
    } catch (Throwable $e) {
        $result = ['ok' => false, 'message' => 'Could not connect to Redis.'];
    }
    if ($result['ok']) {
        logAction('Settings', 'Edit', "$session_name changed the Redis memory limit");
        AuditService::record('redis.memory_changed', (int) $session_user_id, 'redis', 'memory', 'update', 'Redis memory limit changed', ['mb' => (int) $_POST['redis_mb'], 'policy' => (string) $_POST['redis_policy'], 'persisted' => $result['persisted']]);
    }
    flash_alert($result['message'], $result['ok'] ? ($result['persisted'] ? 'success' : 'warning') : 'error');
    redirect();
}

if (isset($_POST['clear_redis_group'])) {
    validateCSRFToken($_POST['csrf_token']);
    $group = (string) ($_POST['redis_group'] ?? '');
    if (!isset(RedisSettings::CLEARABLE[$group])) {
        flash_alert('Unknown group.', 'error');
        redirect();
    }
    try {
        $client = RedisSettings::client(RedisSettings::resolve($mysqli), 1.0);
        $client->connect();
        $removed = RedisSettings::clear($client, $group);
        logAction('Settings', 'Edit', "$session_name cleared Redis " . RedisSettings::CLEARABLE[$group]['label']);
        AuditService::record('redis.cleared', (int) $session_user_id, 'redis', $group, 'clear', 'Cleared ' . RedisSettings::CLEARABLE[$group]['label'], ['removed' => $removed]);
        flash_alert("Cleared $removed item(s): " . RedisSettings::CLEARABLE[$group]['label'] . '.');
    } catch (Throwable $e) {
        flash_alert('Could not connect to Redis.', 'error');
    }
    redirect();
}
