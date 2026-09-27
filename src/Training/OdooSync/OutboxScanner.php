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

        if ($countOnly) {
            $out['creates'] = count($this->records->pushCandidates($since, $t->key, self::MAX_ROWS));
            $out['closes'] = count($this->records->voidCandidates($t->key, self::MAX_ROWS));
            $ids = !empty($settings['tauto_odoo_push_awards']) ? self::sendAchievements($this->db) : [];
            if ($ids && $this->learner !== null) {
                $out['awards'] = count($this->learner->awardCandidates($since, $t->key, $ids, self::MAX_ROWS));
            }
            return $out;
        }

        do {
            $rows = $this->records->pushCandidates($since, $t->key, $limit);
            foreach ($rows as $r) {
                if ($this->repo->enqueue($t, 'completion', (int) $r['completion_id'], 'create', (int) $r['contact_id'], 'resume',
                    Marker::for($this->inst8, 'completion', (int) $r['completion_id']), $nowUtc)) {
                    $out['creates']++;
                }
            }
        } while (count($rows) === $limit && $out['creates'] < self::MAX_ROWS);

        $seen = 0;
        do {
            $rows = $this->records->voidCandidates($t->key, $limit);
            foreach ($rows as $r) {
                $create = $this->repo->findCreate($t->key, 'completion', (int) $r['completion_id']);
                $marker = $create !== null ? (string) $create['todoo_marker'] : Marker::for($this->inst8, 'completion', (int) $r['completion_id']);
                if ($this->repo->enqueue($t, 'completion', (int) $r['completion_id'], 'close', (int) $r['contact_id'], 'resume', $marker, $nowUtc)) {
                    $out['closes']++;
                }
            }
            $seen += count($rows);
        } while (count($rows) === $limit && $seen < self::MAX_ROWS);

        if (!empty($settings['tauto_odoo_push_awards']) && $this->learner !== null) {
            $ids = self::sendAchievements($this->db);
            if ($ids) {
                do {
                    $rows = $this->learner->awardCandidates($since, $t->key, $ids, $limit);
                    foreach ($rows as $r) {
                        if ($this->repo->enqueue($t, 'award', (int) $r['award_id'], 'create', (int) $r['contact_id'], 'resume',
                            Marker::for($this->inst8, 'award', (int) $r['award_id']), $nowUtc)) {
                            $out['awards']++;
                        }
                    }
                } while (count($rows) === $limit && $out['awards'] < self::MAX_ROWS);
            }
        }
        return $out;
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
