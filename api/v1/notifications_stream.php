<?php
// GET /api/v1/notifications/stream - real-time SSE stream of notifications
//                                     for the authenticated user
defined('FROM_API') || die();

if ($method !== 'GET') {
    api_error(405, 'Method not allowed');
}

require $DOCUMENT_ROOT . '/includes/sse_notifications_stream.php';
