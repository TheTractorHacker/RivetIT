<?php
/*
 * ITFlow
 * Redis pub/sub helpers for live ticket updates and chat (Syncro-Beta)
 *
 * Redis runs locally on a non-default port (6380) because the standard
 * 6379 is already bound by an unrelated Docker stack on this host.
 */

require_once __DIR__ . '/../vendor/autoload.php';

define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6380);

/**
 * Lazily create (or reuse) a Predis client connected to the local Redis instance.
 *
 * Returns null if the connection fails, so live-update features degrade
 * gracefully - ticket replies, status changes, and chat messages still save
 * to the database even if Redis is unavailable; only the live push is skipped.
 *
 * @return \Predis\Client|null
 */
function getRedisClient(): ?\Predis\Client {
    static $client = null;
    static $failed = false;

    if ($failed) {
        return null;
    }

    if ($client === null) {
        try {
            $client = new \Predis\Client([
                'scheme'  => 'tcp',
                'host'    => REDIS_HOST,
                'port'    => REDIS_PORT,
                'timeout' => 0.5,
            ]);
            $client->connect();
        } catch (\Throwable $e) {
            $failed = true;
            $client = null;
        }
    }

    return $client;
}

/**
 * Publish a ticket event (new reply, status change, or chat message) so that
 * any open agent/client ticket views receive it live via SSE.
 *
 * @param int    $ticket_id
 * @param string $type      'reply' | 'status' | 'chat'
 * @param array  $payload   Event-specific data merged into the JSON message
 * @return bool  true if published, false if Redis is unavailable
 */
function publishTicketEvent(int $ticket_id, string $type, array $payload = []): bool {
    $redis = getRedisClient();
    if (!$redis) {
        return false;
    }

    $message = json_encode(array_merge($payload, [
        'type'      => $type,
        'ticket_id' => $ticket_id,
        'time'      => time(),
    ]));

    try {
        $redis->publish("ticket.$ticket_id", $message);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Publish a notification event to a user's personal channel so that an open
 * SSE connection (api/v1/notifications_stream.php) can deliver it instantly.
 *
 * @param int   $user_id
 * @param array $payload  e.g. ['id' => ..., 'type' => ..., 'title' => ..., 'body' => ..., 'action' => ..., 'client_id' => ..., 'entity_id' => ...]
 * @return bool true if published, false if Redis is unavailable
 */
function publishUserNotification(int $user_id, array $payload): bool {
    $redis = getRedisClient();
    if (!$redis || !$user_id) {
        return false;
    }

    $message = json_encode(array_merge($payload, [
        'time' => time(),
    ]));

    try {
        $redis->publish("user.$user_id.notifications", $message);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Get the raw PHP stream socket behind a connected Predis client, for use with
 * stream_select() so an SSE loop can wait on a pub/sub subscription with a
 * timeout (for heartbeats) instead of blocking forever.
 *
 * Predis\Connection\StreamConnection::getResource() returns a PSR-7 Stream
 * wrapper, not the raw resource - and its only public way to get the raw
 * resource, detach(), would sever the connection Predis itself is using. So
 * the underlying resource is pulled via reflection on the wrapper's private
 * $stream property, without disturbing the connection.
 *
 * @param \Predis\Client $redis
 * @return resource|null
 */
function getRedisStreamSocket(\Predis\Client $redis) {
    try {
        $streamWrapper = $redis->getConnection()->getResource();
        $prop = new \ReflectionProperty($streamWrapper, 'stream');

        return $prop->getValue($streamWrapper);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Look up a ticket status's display name and color, for embedding in
 * publishTicketEvent() payloads so live views can update status badges
 * without a follow-up query.
 *
 * @param \mysqli $mysqli
 * @param int     $status_id
 * @return array{id:int,name:string,color:string}
 */
function getTicketStatusInfo($mysqli, int $status_id): array {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name, ticket_status_color FROM ticket_statuses WHERE ticket_status_id = $status_id LIMIT 1"));

    if (!$row) {
        return ['id' => $status_id, 'name' => '', 'color' => 'secondary'];
    }

    return [
        'id'    => intval($row['ticket_status_id']),
        'name'  => $row['ticket_status_name'],
        'color' => $row['ticket_status_color'],
    ];
}
