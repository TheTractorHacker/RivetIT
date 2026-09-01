<?php

namespace ITFlow\Audit;

use ITFlow\Database\Connection;

/**
 * Central audit trail (master plan Section 37). Deliberately separate from
 * the legacy logAction()/`logs` table, which stays exactly as-is - that
 * table is client/ticket activity history for the UI's own audit tab, not a
 * structured security event trail. This is append-only: nothing else in
 * the app writes, updates, or deletes rows here except record().
 *
 * Usage from legacy procedural pages (the common case for now):
 *   \ITFlow\Audit\AuditService::record('auth.login_success', $user_id, 'user', $user_id, 'login', "User $name logged in");
 */
class AuditService
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function log(
        string $eventType,
        ?int $actorUserId,
        ?string $entityType,
        $entityId,
        string $action,
        ?string $summary = null,
        array $metadata = []
    ): void {
        $entityIdStr = $entityId === null ? null : (string) $entityId;
        $metadataJson = $metadata ? json_encode($metadata) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO audit_events
                (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sisssssss',
            $eventType,
            $actorUserId,
            $entityType,
            $entityIdStr,
            $action,
            $summary,
            $metadataJson,
            $ip,
            $ua,
            $requestId
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Static convenience wrapper so legacy pages don't need to construct the
     * service or thread $mysqli through by hand.
     */
    public static function record(
        string $eventType,
        ?int $actorUserId,
        ?string $entityType,
        $entityId,
        string $action,
        ?string $summary = null,
        array $metadata = []
    ): void {
        (new self(Connection::get()))->log($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);
    }
}
