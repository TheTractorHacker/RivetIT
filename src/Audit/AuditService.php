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
 *
 * @deprecated since 26.10.26 use \RivetCore\Audit\AuditService. Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
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
                new ServerRequestContext(),
                // Every audit event also goes to the event bus: webhooks subscribed to it, and event automation rules.
                static function (string $eventType, ?int $actor, ?string $entityType, ?string $entityId, string $action, ?string $summary, array $metadata) {
                    if (function_exists('queueWebhookEvent') || is_file(__DIR__ . '/../../includes/event_bus.php')) {
                        require_once __DIR__ . '/../../includes/event_bus.php';
                        rivetEmitEvent($eventType, ['actor_user_id' => $actor, 'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => $action, 'summary' => $summary, 'metadata' => $metadata]);
                    }
                }
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
        // Hash chain (src/Audit/AuditChain.php): the insert and the sealing of the new row happen under one lock so rows are sealed in id order.
        $chain = self::chainFor($this->mysqli);
        $locked = false;
        if ($chain !== null) {
            try {
                $locked = $chain->lock();
            } catch (\Throwable $e) {
                self::$chainBroken = true;
                $chain = null;
            }
        }
        try {
            if ($this->core !== null) {
                $this->core->log($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);
            } else {
                $this->legacyInsert($eventType, $actorUserId, $entityType, $entityId, $action, $summary, $metadata);
            }
            if ($chain !== null) {
                try {
                    \ITFlow\Audit\AuditSink::write($this->mysqli, $chain->seal(), dirname(__DIR__, 2));
                } catch (\Throwable $e) {
                    self::$chainBroken = true;   // columns missing (database not updated yet) or similar: stop trying for this process; the cron seals later
                }
            }
        } finally {
            if ($locked) {
                try { $chain?->unlock(); } catch (\Throwable $e) { /* the lock ends with the connection */ }
            }
        }
    }

    private static bool $chainBroken = false;
    private static ?bool $chainOn = null;

    private static function chainFor(\mysqli $mysqli): ?AuditChain
    {
        if (self::$chainBroken) {
            return null;
        }
        self::$chainOn ??= \ITFlow\Platform\PlatformSettings::bool($mysqli, 'audit_chain_seal_enabled');

        return self::$chainOn ? new AuditChain($mysqli) : null;
    }

    private function legacyInsert(string $eventType, ?int $actorUserId, ?string $entityType, $entityId, string $action, ?string $summary, array $metadata): void
    {
        $entityIdStr = $entityId === null ? null : (string) $entityId;
        $metadataJson = $metadata ? json_encode($metadata) : null;
        $summary = $summary === null ? null : mb_substr($summary, 0, 500);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $requestId = $_SERVER['RIVET_REQUEST_ID'] ?? null; // never the client's X-Request-ID header
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
