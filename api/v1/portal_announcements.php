<?php
// GET /api/v1/portal_announcements   -> 200 {announcements:[{id,title,body,severity,pinned,all_departments,published_at,expires_at}]}
// What is published right now for all departments or the employee's own department (plain text body). Read-only.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/portal_api.php';
require_once $DOCUMENT_ROOT . '/src/Portal/Announcements.php';

portal_api_get_only();
$me = portal_api_require_employee();
api_response(200, ['announcements' => array_map('portal_api_announcement', (new \ITFlow\Portal\Announcements($mysqli))->visibleFor($me['client_id'], 50))]);
