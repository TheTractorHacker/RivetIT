<?php

/*
 * ITFlow - SSE core for real-time notifications (mobile app)
 *
 * Included by api/v1/notifications_stream.php after it has authenticated the
 * caller and resolved $api_user_id. Streams JSON notification events as they
 * are published via publishUserNotification().
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

while (ob_get_level() > 0) {
    ob_end_flush();
}

$redis = getRedisClient();

if (!$redis) {
    echo "event: error\ndata: {\"message\":\"Live notifications unavailable\"}\n\n";
    flush();
    exit;
}

$channel = "user.$api_user_id.notifications";

$pubsub = $redis->pubSubLoop();
$pubsub->subscribe($channel);

try {
    // Consume the subscribe confirmation before entering the wait loop
    $pubsub->current();
} catch (\Throwable $e) {
    exit;
}

$socket = getRedisStreamSocket($redis);

if (!$socket) {
    exit;
}

$max_runtime = 60;
$heartbeat_interval = 20;
$start_time = time();

while (time() - $start_time < $max_runtime) {
    if (connection_aborted()) {
        break;
    }

    $read = [$socket];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, $heartbeat_interval, 0);

    if ($ready === false) {
        break;
    }

    if ($ready > 0) {
        try {
            $message = $pubsub->current();
        } catch (\Throwable $e) {
            break;
        }

        if ($message->kind === 'message') {
            echo "event: notification\ndata: {$message->payload}\n\n";
        }
    } else {
        // Heartbeat comment to keep the connection alive through proxies
        echo ": heartbeat\n\n";
    }

    flush();
}

try {
    $pubsub->unsubscribe();
} catch (\Throwable $e) {
    // Connection may already be gone
}
