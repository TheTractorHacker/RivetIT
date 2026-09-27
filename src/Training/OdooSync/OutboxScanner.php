<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Upstream\LearnerGateway;
use ITFlow\Training\Upstream\RecordsGateway;

/**
 * The safety net behind the Phase 2 listener (spec §1.4 #4): every 10 minutes the worker queues
 * whatever the listener missed. The gateways apply every filter in SQL (training kind, not voided,
 * course not opted out, recorded on/after "send since", not queued yet for this target), so each
 * row they return is queued and a page can never fill with rows that will not be sent.
 *
 * Per target (Targets): every target switched on gets its own create row per record; the certification
 * target only for courses/achievements mapped to an Odoo skill on this target. A void queues one close per
 * target the record has a create for - also for a target switched off since, because a void must reach
 * every copy in Odoo.
 */
final class OutboxScanner
{
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly \mysqli $db,
        private readonly RecordsGateway $records,
        private readonly ?LearnerGateway $learner,
        private readonly OutboxRepo $repo,
        private readonly string $inst8,
    ) {
    }

    /**
     * creates: every pushCandidates row; closes: every voidCandidates row; awards: only when
     * "Send achievements" is on, and only achievements switched to "Send to Odoo".
     * $countOnly (dry run) counts what would be queued and writes nothing.
     *
     * @return array{creates:int, closes:int, awards:int}
     */
    public function scan(Target $t, array $settings, string $nowUtc, int $limit = 200, bool $countOnly = false): array
    {
        $limit = max(1, min(500, $limit));
        $out = ['creates' => 0, 'closes' => 0, 'awards' => 0];
        $since = self::sinceUtc($settings['tauto_odoo_push_since'] ?? null);
        if ($since === null) {
            return $out;
        }
        $modes = Targets::enabled($settings);
        $awardIds = (!empty($settings['tauto_odoo_push_awards']) && $this->learner !== null) ? self::sendAchievements($this->db) : [];

        if ($countOnly) {
            foreach ($modes as $mode) {
                $out['creates'] += count($this->records->pushCandidates($since, $t->key, self::MAX_ROWS, $mode));
                if ($awardIds) {
                    $out['awards'] += count($this->learner->awardCandidates($since, $t->key, $awardIds, self::MAX_ROWS, $mode));
                }
            }
            $out['closes'] = count($this->records->voidCandidates($t->key, self::MAX_ROWS));
            return $out;
        }

        foreach ($modes as $mode) {
            $n = 0;
            do {
                $rows = $this->records->pushCandidates($since, $t->key, $limit, $mode);
                foreach ($rows as $r) {
                    if ($this->repo->enqueue($t, 'completion', (int) $r['completion_id'], 'create', (int) $r['contact_id'], $mode,
                        Marker::for($this->inst8, 'completion', (int) $r['completion_id']), $nowUtc)) {
                        $n++;
                    }
                }
            } while (count($rows) === $limit && $n < self::MAX_ROWS);
            $out['creates'] += $n;
        }

        $seen = 0;
        do {
            $rows = $this->records->voidCandidates($t->key, $limit);
            foreach ($rows as $r) {
                $mode = Targets::valid((string) ($r['mode'] ?? '')) ? (string) $r['mode'] : 'resume';
                $create = $this->repo->findCreate($t->key, 'completion', (int) $r['completion_id'], $mode);
                $marker = $create !== null ? (string) $create['todoo_marker'] : Marker::for($this->inst8, 'completion', (int) $r['completion_id']);
                if ($this->repo->enqueue($t, 'completion', (int) $r['completion_id'], 'close', (int) $r['contact_id'], $mode, $marker, $nowUtc)) {
                    $out['closes']++;
                }
            }
            $seen += count($rows);
        } while (count($rows) === $limit && $seen < self::MAX_ROWS);

        if ($awardIds) {
            foreach ($modes as $mode) {
                $n = 0;
                do {
                    $rows = $this->learner->awardCandidates($since, $t->key, $awardIds, $limit, $mode);
                    foreach ($rows as $r) {
                        if ($this->repo->enqueue($t, 'award', (int) $r['award_id'], 'create', (int) $r['contact_id'], $mode,
                            Marker::for($this->inst8, 'award', (int) $r['award_id']), $nowUtc)) {
                            $n++;
                        }
                    }
                } while (count($rows) === $limit && $n < self::MAX_ROWS);
                $out['awards'] += $n;
            }
        }
        return $out;
    }

    /**
     * The Odoo skill mapped to a course or an achievement on this target (training_odoo_map.tomap_odoo_skill_id with
     * tomap_target_key = the target), or null. A mapping made against another Odoo never applies: skill ids are per Odoo.
     */
    public static function skillFor(\mysqli $db, string $entity, int $entityId, string $targetKey): ?int
    {
        $r = Db::one($db, 'SELECT tomap_odoo_skill_id, tomap_target_key FROM training_odoo_map WHERE tomap_entity = ? AND tomap_entity_id = ?',
            'si', [$entity, $entityId]);
        if ($r === null || $r['tomap_odoo_skill_id'] === null || (int) $r['tomap_odoo_skill_id'] < 1
            || !is_string($r['tomap_target_key']) || !hash_equals($r['tomap_target_key'], $targetKey)) {
            return null;
        }
        return (int) $r['tomap_odoo_skill_id'];
    }

    /** Achievements switched to "Send to Odoo" (no row = not sent; spec §2.1). @return list<int> */
    public static function sendAchievements(\mysqli $db): array
    {
        $rows = Db::all($db, "SELECT tomap_entity_id FROM training_odoo_map WHERE tomap_entity = 'achievement' AND tomap_push = 1 ORDER BY tomap_entity_id");
        return array_map(static fn($r) => (int) $r['tomap_entity_id'], $rows);
    }

    /** Is this course sent (no row = sent; spec §2.1)? */
    public static function coursePushed(\mysqli $db, int $courseId): bool
    {
        $r = Db::one($db, "SELECT tomap_push FROM training_odoo_map WHERE tomap_entity = 'course' AND tomap_entity_id = ?", 'i', [$courseId]);
        return $r === null || (int) $r['tomap_push'] === 1;
    }

    /** Is this achievement sent (no row = not sent)? */
    public static function achievementPushed(\mysqli $db, int $achievementId): bool
    {
        $r = Db::one($db, "SELECT tomap_push FROM training_odoo_map WHERE tomap_entity = 'achievement' AND tomap_entity_id = ?", 'i', [$achievementId]);
        return $r !== null && (int) $r['tomap_push'] === 1;
    }

    /** "Send records recorded on or after" (a local date) as the UTC instant of that local midnight; null when unset. */
    public static function sinceUtc(mixed $localDate): ?string
    {
        if (!is_string($localDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, new \DateTimeZone(date_default_timezone_get()));
        if ($dt === false) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
