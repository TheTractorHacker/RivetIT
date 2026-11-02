<?php
// GET  /api/v1/portal_notifications               -> 200 {items:[{key,type,title,detail,link,at,sticky,unread,severity}], unread:N}
// POST /api/v1/portal_notifications {"action":"mark_read"}  -> 200 {ok:true, unread:N}   (sticky items stay unread until dealt with)
// The employee's own notification feed (announcements, replies and closures on their requests, approvals waiting, due checklist tasks,
// device warranties running out). Built from existing data; nothing is sent to anyone.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/portal_api.php';
require_once $DOCUMENT_ROOT . '/src/Portal/EmployeeHome.php';
require_once $DOCUMENT_ROOT . '/src/Portal/Announcements.php';
require_once $DOCUMENT_ROOT . '/src/Portal/PortalFeed.php';

$me = portal_api_require_employee();
$feed = new \ITFlow\Portal\PortalFeed($mysqli);

if ($method === 'GET') {
    $items = $feed->items($me['contact_id'], $me['client_id'], 60);
    $unread = 0;
    foreach ($items as $i) {
        if ($i['unread']) {
            $unread++;
        }
    }
    api_response(200, ['items' => $items, 'unread' => $unread]);
}
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || ($body['action'] ?? '') !== 'mark_read') {
        api_error(422, 'action must be "mark_read"');
    }
    $feed->markRead($me['contact_id']);
    api_response(200, ['ok' => true, 'unread' => $feed->unreadCount($me['contact_id'], $me['client_id'])]);
}
header('Allow: GET, POST');
api_error(405, 'Method not allowed');
