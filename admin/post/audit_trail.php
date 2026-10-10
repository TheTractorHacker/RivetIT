<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Audit trail: verify the hash chain now, and the optional copy of every entry to a JSON-lines file / syslog.
 * See src/Audit/AuditChain.php and src/Audit/AuditSink.php.
 */

if (isset($_POST['verify_audit_chain'])) {
    validateCSRFToken($_POST['csrf_token']);

    $verify = \ITFlow\Platform\Nightly::verifyAuditChain($mysqli);
    logAction('Settings', 'Edit', "$session_name ran the audit trail hash chain check: " . $verify['status']);
    if ($verify['status'] === 'ok' || $verify['status'] === 'empty') {
        flash_alert('Audit trail checked: ' . ($verify['status'] === 'ok' ? number_format($verify['checked']) . ' entries, the chain is intact' : 'nothing sealed yet'));
    } else {
        flash_alert('Audit trail check: ' . htmlspecialchars((string) $verify['status'], ENT_QUOTES, 'UTF-8') . '. See the Integrity panel.', 'error');
    }

    redirect();
}

if (isset($_POST['save_audit_sink'])) {
    validateCSRFToken($_POST['csrf_token']);

    $sink_path = trim((string) ($_POST['audit_sink_path'] ?? ''));
    $sink_syslog = isset($_POST['audit_sink_syslog']) ? '1' : '0';
    $problem = \ITFlow\Audit\AuditSink::pathProblem($sink_path, dirname(__DIR__, 2));

    if ($problem !== null) {
        flash_alert('The audit copy file was not saved: ' . htmlspecialchars($problem, ENT_QUOTES, 'UTF-8'), 'error');
    } else {
        \ITFlow\Platform\PlatformSettings::set($mysqli, 'audit_sink_path', $sink_path);
        \ITFlow\Platform\PlatformSettings::set($mysqli, 'audit_sink_syslog', $sink_syslog);
        logAction('Settings', 'Edit', "$session_name set the audit trail copy: file " . ($sink_path === '' ? 'off' : $sink_path) . ', syslog ' . ($sink_syslog === '1' ? 'on' : 'off'));
        flash_alert('Audit trail copy settings saved');
    }

    redirect();
}
