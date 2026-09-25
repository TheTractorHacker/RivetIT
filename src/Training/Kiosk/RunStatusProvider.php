<?php

namespace ITFlow\Training\Kiosk;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Quiz\InList;

/**
 * Open kiosk runs as Phase 2 shows them (C-P2-11, optional for P2): v0 §8.4 "Locked" and
 * "Pending" states on the compliance screens. P2 calls it guarded by class_exists().
 *
 * forContacts() returns [contact_id][course_id] => {status, progress_pct, started_at_utc, locked_at_utc}
 * for every OPEN run (trun_open_guard = 1) of the given people (optionally only some courses):
 *   status 'locked' when attempts are exhausted (trun_locked_at_utc set), 'blocked' when a video
 *   changed or became unavailable, else the run status (in_progress, awaiting_signature,
 *   awaiting_session, awaiting_evaluation).
 * [] when the table does not exist yet (before 2.6.93) or no ids are given.
 */
final class RunStatusProvider
{
    public const CHUNK = 500;

    /**
     * @param list<int>      $cids
     * @param list<int>|null $courseIds
     * @return array<int, array<int, array{status:string, progress_pct:int, started_at_utc:string, locked_at_utc:?string}>>
     */
    public static function forContacts(\mysqli $db, array $cids, ?array $courseIds = null): array
    {
        $cids = array_values(array_unique(array_filter(array_map('intval', $cids), static fn(int $i) => $i > 0)));
        if ($cids === [] || ($courseIds !== null && $courseIds === [])) {
            return [];
        }
        $courseFilter = '';
        $ct = '';
        $cp = [];
        if ($courseIds !== null) {
            [$ph, $ct, $cp] = InList::ints(array_values(array_unique(array_map('intval', $courseIds))));
            $courseFilter = " AND trun_course_id IN ($ph)";
        }
        $out = [];
        try {
            foreach (array_chunk($cids, self::CHUNK) as $chunk) {
                [$ph, $t, $p] = InList::ints($chunk);
                $rows = Db::all($db, "SELECT trun_contact_id, trun_course_id, trun_status, trun_progress_pct, trun_started_at_utc,
                        trun_locked_at_utc, trun_blocked_reason
                    FROM training_runs WHERE trun_open_guard = 1 AND trun_contact_id IN ($ph)$courseFilter", $t . $ct, array_merge($p, $cp));
                foreach ($rows as $r) {
                    $status = (string) $r['trun_status'];
                    if ($r['trun_locked_at_utc'] !== null) {
                        $status = 'locked';
                    } elseif ($r['trun_blocked_reason'] !== null) {
                        $status = 'blocked';
                    }
                    $out[(int) $r['trun_contact_id']][(int) $r['trun_course_id']] = [
                        'status' => $status,
                        'progress_pct' => (int) $r['trun_progress_pct'],
                        'started_at_utc' => (string) $r['trun_started_at_utc'],
                        'locked_at_utc' => $r['trun_locked_at_utc'] === null ? null : (string) $r['trun_locked_at_utc'],
                    ];
                }
            }
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {
                return [];
            }
            throw $e;
        }
        return $out;
    }
}
