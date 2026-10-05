<?php

namespace ITFlow\Audit;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use ITFlow\Core\Adapter\Http\ServerRequestContext;
use ITFlow\Database\Connection;

/**
 * Compatibility shim: the audit implementation now lives in RivetCore
 * (RivetCore\Audit\AuditService). This class keeps the legacy entry points so
 * the ~40 existing callers - static record() from procedural pages and
 * `new AuditService($mysqli)` from Training/MCP - keep working unchanged.
 *
 * Deliberately separate from the legacy logAction()/`logs` table, which stays
 * exactly as-is.
 *
 *   \ITFlow\Audit\AuditService::record('auth.login_success', $user_id, 'user', $user_id, 'login', "User $name logged in");
 */
class AuditService
{
    private ?\RivetCore\Audit\AuditService $core = null;
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        // Between `git pull` and `composer install` during an update, rivet/rivet-core may not be on disk
        // yet; fall back to the original direct insert so logins never break in that window.
        if (class_exists(\RivetCore\Audit\AuditService::class)) {
            $this->core = new \RivetCore\Audit\AuditService(
                new MysqliDatabaseAdapter($mysqli),
                new ServerRequestContext()
            );
        }
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
        if ($this->core !== null) {
            $this->core->log($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);

            return;
        }
        $this->legacyInsert($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);
    }

    private function legacyInsert(string $eventType, ?int $actorUserId, ?string $entityType, $entityId, string $action, ?string $summary, array $metadata): void
    {
        $entityIdStr = $entityId === null ? null : (string) $entityId;
        $metadataJson = $metadata ? json_encode($metadata) : null;
        $summary = $summary === null ? null : mb_substr($summary, 0, 500);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        $stmt = $this->mysqli->prepare(
            "INSERT INTO audit_events
                (event_type, actor_user_id, entity_type, entity_id, action, summary, metadata_json, ip_address, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sissssssss', $eventType, $actorUserId, $entityType, $entityIdStr, $action, $summary, $metadataJson, $ip, $ua, $requestId);
        $stmt->execute();
        $stmt->close();
    }

    /** Static convenience wrapper for legacy pages. */
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
