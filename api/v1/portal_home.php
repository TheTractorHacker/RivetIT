<?php
// GET /api/v1/portal_home   (Authorization: Bearer <employee token>)
//   -> 200 {contact, announcements[], open_requests[], devices[], approvals_waiting, checklist, kb:{popular[],recent[]}, unread_notifications}
// The employee home in one call, for the mobile app. Read-only; scoped to the token's contact and department.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/portal_api.php';
require_once $DOCUMENT_ROOT . '/src/Portal/EmployeeHome.php';
require_once $DOCUMENT_ROOT . '/src/Portal/Announcements.php';
require_once $DOCUMENT_ROOT . '/src/Portal/PortalFeed.php';

portal_api_get_only();
$me = portal_api_require_employee();
$eh = new \ITFlow\Portal\EmployeeHome($mysqli);

$requests = [];
foreach ($eh->myOpenRequests($me['contact_id'], $me['client_id'], 10) as $r) {
    $requests[] = [
        'id' => intval($r['ticket_id']),
        'number' => (string) $r['ticket_prefix'] . intval($r['ticket_number']),
        'subject' => (string) $r['ticket_subject'],
        'status' => (string) ($r['ticket_status_name'] ?? ''),
        'updated_at' => $r['last_update'] ?: null,
    ];
}
$kb = intval($config_module_enable_kb ?? 0) === 1
    ? [
        'popular' => array_map(static fn($a) => ['id' => intval($a['kb_article_id']), 'title' => (string) $a['kb_article_title'], 'reads' => intval($a['read_count'])], $eh->popularArticles($me['client_id'], 5, 30)),
        'recent' => array_map(static fn($a) => ['id' => intval($a['kb_article_id']), 'title' => (string) $a['kb_article_title'], 'changed_at' => $a['changed_at']], $eh->recentArticles($me['client_id'], 5)),
    ]
    : ['popular' => [], 'recent' => []];

api_response(200, [
    'contact' => ['id' => $me['contact_id'], 'name' => $me['contact_name'], 'department_id' => $me['client_id']],
    'announcements' => array_map('portal_api_announcement', (new \ITFlow\Portal\Announcements($mysqli))->visibleFor($me['client_id'], 5)),
    'open_requests' => $requests,
    'devices' => $eh->myDeviceDetails($me['contact_id'], $me['client_id']),
    'approvals_waiting' => count($eh->waitingOnMe($me['contact_id'], $me['client_id'])),
    'checklist' => portal_api_checklist($eh->myChecklist($me['contact_id'], $me['client_id'])),
    'kb' => $kb,
    'unread_notifications' => (new \ITFlow\Portal\PortalFeed($mysqli))->unreadCount($me['contact_id'], $me['client_id']),
]);
