<?php

namespace ITFlow\Training\OdooPush;

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\OdooSync\Marker;
use ITFlow\Training\OdooSync\OutboxRepo;
use ITFlow\Training\OdooSync\OutboxScanner;
use ITFlow\Training\OdooSync\Target;
use ITFlow\Training\Upstream\RecordsGateway;
use ITFlow\Training\Upstream\Schema;

/**
 * The Phase 2 after-commit listener (P2 spec §1.5; Records\AfterCommit calls exactly these two
 * methods once a record or a void has committed). The fast path of the Odoo write-back: it only
 * queues a row - no network call - and never throws (any failure is error_logged, and the
 * worker's 10-minute scan queues the record anyway).
 *
 * Gates: the 2.6.96 tables exist, write-back is switched on, the current Odoo is the confirmed
 * target, and the record is a candidate (training kind, not voided, course not opted out,
 * recorded on/after "send since", not queued yet). A void queues a close only when a create for
 * this target exists and is not dead or skipped.
 */
final class Outbox
{
    public static function onCompletionRecorded(Ctx $c, int $completionId): void
    {
        try {
            $g = self::gate($c);
            if ($g === null) {
                return;
            }
            [$t, $since] = $g;
            $cand = (new RecordsGateway($c->db))->pushCandidate($completionId, $since, $t->key);
            if ($cand === null) {
                return;
            }
            (new OutboxRepo($c->db))->enqueue($t, 'completion', $completionId, 'create', (int) $cand['contact_id'], 'resume',
                Marker::for(Marker::inst8For($c->db), 'completion', $completionId), Clock::nowUtc());
        } catch (\Throwable $e) {
            error_log('Training Odoo write-back: listener failed for record #' . $completionId . ': ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    public static function onCompletionVoided(Ctx $c, int $completionId): void
    {
        try {
            $g = self::gate($c);
            if ($g === null) {
                return;
            }
            [$t] = $g;
            $repo = new OutboxRepo($c->db);
            $create = $repo->findCreate($t->key, 'completion', $completionId);
            if ($create === null || in_array($create['todoo_status'], ['dead', 'skipped'], true)) {
                return;
            }
            $repo->enqueue($t, 'completion', $completionId, 'close', (int) $create['todoo_contact_id'], (string) $create['todoo_mode'],
                (string) $create['todoo_marker'], Clock::nowUtc());
        } catch (\Throwable $e) {
            error_log('Training Odoo write-back: void listener failed for record #' . $completionId . ': ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /** @return array{0:Target, 1:string}|null [target, since UTC] when the write-back is on for the confirmed target */
    private static function gate(Ctx $c): ?array
    {
        if (!Schema::has($c->db, Schema::P5)) {
            return null;
        }
        $s = AutomationSettings::loadWorker($c->db);
        if (empty($s['ready']) || (int) ($s['tauto_odoo_push_enabled'] ?? 0) !== 1 || ($s['tauto_odoo_mode'] ?? 'resume') !== 'resume') {
            return null;
        }
        $t = Target::current($c->db);
        if ($t === null || (string) ($s['tauto_odoo_target_key'] ?? '') === '' || !hash_equals((string) $s['tauto_odoo_target_key'], $t->key)) {
            return null;
        }
        $since = OutboxScanner::sinceUtc($s['tauto_odoo_push_since'] ?? null);
        return $since === null ? null : [$t, $since];
    }
}
