<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Audit\AuditService;

require_once dirname(__DIR__, 2) . '/includes/rivetdocs.php';

if (isset($_POST['save_rivetdocs_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $enabled = isset($_POST['rivetdocs_enabled']) ? 1 : 0;
    $raw = trim((string) ($_POST['rivetdocs_url'] ?? ''));
    $url = rivetdocsNormalizeUrl($raw);

    if ($raw !== '' && $url === '') {
        flash_alert('Enter a full http or https address without a login, query or fragment.', 'error');
        redirect();
    }
    if ($enabled && $url === '') {
        flash_alert('Enter the RivetDocs address before turning the link on.', 'error');
        redirect();
    }

    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_rivetdocs_enabled = ?, config_rivetdocs_url = ? WHERE company_id = 1");
    mysqli_stmt_bind_param($stmt, 'is', $enabled, $url);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    logAction('Settings', 'Edit', "$session_name changed RivetDocs settings");
    AuditService::record('rivetdocs.settings_changed', (int) $session_user_id, 'settings', 'rivetdocs', 'update', 'RivetDocs settings changed', [
        'after' => ['enabled' => $enabled, 'url' => $url],
    ]);

    flash_alert('RivetDocs settings saved.');
    redirect();
}
