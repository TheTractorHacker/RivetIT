<?php

/*
 * Handler for admin/portal_announcements.php (the filename is load-bearing: admin/post.php picks the handler from the Referer's page name).
 * Add, edit and remove employee portal announcements. Administrators only (admin/post.php loads this inside its admin check).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../src/Portal/Announcements.php';

$announcement_departments = static function () use ($mysqli): array {
    $ids = [];
    $res = mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_archived_at IS NULL");
    while ($res && ($r = mysqli_fetch_row($res))) {
        $ids[] = (int) $r[0];
    }

    return $ids;
};

if (isset($_POST['add_announcement']) || isset($_POST['edit_announcement'])) {

    validateCSRFToken($_POST['csrf_token']);

    $is_edit = isset($_POST['edit_announcement']);
    $svc = new \ITFlow\Portal\Announcements($mysqli);
    $v = \ITFlow\Portal\Announcements::validate($_POST, $announcement_departments());

    if (!$v['ok']) {
        flash_alert(implode(' ', $v['errors']), 'danger');
        redirect($is_edit ? 'portal_announcements.php?edit=' . intval($_POST['announcement_id'] ?? 0) : 'portal_announcements.php');
    }

    if ($is_edit) {
        $id = intval($_POST['announcement_id'] ?? 0);
        if ($id <= 0 || $svc->find($id) === null) {
            flash_alert('That announcement no longer exists.', 'danger');
            redirect('portal_announcements.php');
        }
        $svc->update($id, $v['clean']);
        logAction("Portal Announcement", "Edit", "$session_name edited portal announcement \"" . $v['clean']['title'] . "\"", 0, $id);
        flash_alert("Announcement updated");
    } else {
        $id = $svc->create($v['clean'], intval($session_user_id));
        logAction("Portal Announcement", "Create", "$session_name published portal announcement \"" . $v['clean']['title'] . "\"", 0, $id);
        flash_alert("Announcement published");
    }

    redirect('portal_announcements.php');
}

if (isset($_POST['archive_announcement'])) {

    validateCSRFToken($_POST['csrf_token']);

    $id = intval($_POST['announcement_id'] ?? 0);
    $svc = new \ITFlow\Portal\Announcements($mysqli);
    $row = $id > 0 ? $svc->find($id) : null;
    if ($row === null) {
        flash_alert('That announcement no longer exists.', 'danger');
        redirect('portal_announcements.php');
    }
    $svc->archive($id);
    logAction("Portal Announcement", "Delete", "$session_name removed portal announcement \"" . $row['announcement_title'] . "\"", 0, $id);
    flash_alert("Announcement removed");

    redirect('portal_announcements.php');
}
