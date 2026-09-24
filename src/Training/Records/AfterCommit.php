<?php

namespace ITFlow\Training\Records;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Core\Ctx;

/**
 * Best-effort work after a records transaction has committed (Phase 2 spec §1.5, §3.7):
 * the listeners of later phases, the legacy activity log and the audit trail.
 *
 * Listener classes are called only if they exist, with exactly these names (§1.5):
 *   \ITFlow\Training\Achievements\AwardEngine::onCompletionRecorded(Ctx, int)       Phase 3
 *   \ITFlow\Training\OdooPush\Outbox::onCompletionRecorded(Ctx, int)                Phase 5
 *   \ITFlow\Training\OdooPush\Outbox::onCompletionVoided(Ctx, int)                  Phase 5
 * Every step is wrapped: a failing listener or log write never undoes or fails a record that
 * is already committed.
 */
final class AfterCommit
{
    private const RECORDED = [
        ['ITFlow\\Training\\Achievements\\AwardEngine', 'onCompletionRecorded'],
        ['ITFlow\\Training\\OdooPush\\Outbox', 'onCompletionRecorded'],
    ];
    private const VOIDED = [
        ['ITFlow\\Training\\OdooPush\\Outbox', 'onCompletionVoided'],
    ];

    public static function completionRecorded(Ctx $c, int $id): void
    {
        self::dispatch(self::RECORDED, $c, $id);
    }

    public static function completionVoided(Ctx $c, int $id): void
    {
        self::dispatch(self::VOIDED, $c, $id);
    }

    /** logAction('Training', …) on the request's legacy log; skipped on the CLI (no global connection). */
    public static function log(string $action, string $description, int $entityId): void
    {
        if (!function_exists('logAction') || !(($GLOBALS['mysqli'] ?? null) instanceof \mysqli)) {
            return;
        }
        try {
            logAction('Training', $action, $description, 0, $entityId);
        } catch (\Throwable $e) {
            error_log('Training: logAction failed: ' . $e->getMessage());
        }
    }

    /** AuditService on the context's connection, after commit; best-effort. */
    public static function audit(Ctx $c, string $event, string $entityType, int $entityId, string $action, string $summary, array $meta = []): void
    {
        try {
            (new AuditService($c->db))->log($event, $c->userId > 0 ? $c->userId : null, $entityType, $entityId, $action, $summary, $meta);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }

    /** @param list<array{0:string,1:string}> $listeners */
    private static function dispatch(array $listeners, Ctx $c, int $id): void
    {
        foreach ($listeners as [$class, $method]) {
            try {
                if (class_exists($class) && method_exists($class, $method)) {
                    $class::$method($c, $id);
                }
            } catch (\Throwable $e) {
                error_log("Training: listener $class::$method failed for record #$id: " . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }
}
