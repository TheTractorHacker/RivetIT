<?php

namespace ITFlow\Training\Kiosk\Learn;

use ITFlow\Training\Achievements\AwardRepository;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Pin\PinService;

/**
 * The Learning Center's data (P3 spec §5.3, kiosk/me.php). Runs the lazy housekeeping first
 * (finalizeExpired for the person's runs, then settleAwaiting), then combines Phase 2's
 * LearnerSummary (through RecordsBridge) with the person's open kiosk runs.
 *
 * Shape (every text is data for textContent):
 * {person:{first,name,dept}, today, records_available,
 *  notices:[{kind:'pin_reset', on, by}|{kind:'pin_changed', on}],
 *  counts:{completed, in_progress, overdue, documents_to_sign},
 *  required:[Card], documents:[Card], optional:[Card], available:[Card],
 *  completed:[{completion_id, course_id, course_name, kind, completed_on, score_pct}], completed_total,
 *  certificates:[{completion_id, course_id, course_name, kind, cert_number, completed_on, expires_on, status}],
 *  achievements:[AwardPublic], achievement_progress:[{uid,name,icon,color,have,need}]}
 * Card = {course_id, name, kind, color, cover_url, lesson_count, minutes_left, due_on, status, assignment_id,
 *         run:null|{run_id, status, state, progress_pct, pick_up:{index, title}|null},
 *         action:'start'|'continue'|'locked'|'blocked'|'sign'|'session'|'evaluation'}
 * `status` is P2's pair status (overdue|due_soon|due|expiring|retrain_due|expired) or null for an available course.
 */
final class LearnerHome
{
    public const COMPLETED_SHOWN = 3;
    public const AVAILABLE_MAX = 12;

    public static function build(KioskCtx $k): array
    {
        $db = $k->db();
        $cid = $k->contactId();
        $lang = $k->lang;
        $actor = $k->eventBase();
        foreach (RunRepo::openRuns($db, $cid) as $r) {
            AttemptFinalizer::finalizeExpired($db, (int) $r['trun_id'], 20, $actor);
        }
        (new RunService($k))->settleAwaiting($cid);

        $bridge = new RecordsBridge($k->core);
        $summary = $bridge->learnerSummary($cid);
        $available = !empty($summary['available']);
        $runs = [];
        foreach (RunRepo::openRuns($db, $cid) as $r) {
            $runs[(int) $r['trun_course_id']] = $r;
        }
        $cards = static fn(array $items): array => array_values(array_filter(array_map(
            static fn(array $it): ?array => self::card($k, (int) $it['course_id'], $it, $runs[(int) $it['course_id']] ?? null, $lang), $items)));

        $required = $available ? $cards($summary['required'] ?? []) : [];
        $docs = [];
        $train = [];
        foreach ($required as $c) {
            if ($c['kind'] === 'document') {
                $docs[] = $c;
            } else {
                $train[] = $c;
            }
        }
        $optional = $available ? $cards($summary['optional'] ?? []) : [];

        // Open runs on courses that are not assigned (voluntary courses in progress) show as optional.
        $listed = [];
        foreach (array_merge($required, $optional) as $c) {
            $listed[$c['course_id']] = true;
        }
        foreach ($runs as $courseId => $r) {
            if (!isset($listed[$courseId])) {
                $c = self::card($k, $courseId, ['due_on' => null, 'status' => null, 'assignment_id' => null], $r, $lang);
                if ($c !== null) {
                    $optional[] = $c;
                    $listed[$courseId] = true;
                }
            }
        }

        $completed = $available ? array_values($summary['completed'] ?? []) : [];
        $inProgress = 0;
        foreach ($runs as $r) {
            if ($r['trun_status'] === 'in_progress') {
                $inProgress++;
            }
        }
        $person = [
            'first' => (string) ($k->ksess['first'] ?? ''),
            'name' => (string) ($k->ksess['contact_name'] ?? ''),
            'dept' => (string) ($k->ksess['dept'] ?? $k->ksess['client_name'] ?? ''),
        ];
        if ($person['first'] === '' && $person['name'] !== '') {
            $person['first'] = preg_split('/\s+/u', trim($person['name']))[0] ?? '';
        }
        return [
            'person' => $person,
            'today' => Clock::todayLocal(),
            'records_available' => $available,
            'notices' => self::notices($db, (int) $k->ksessId(), $cid),
            'counts' => [
                'completed' => $available ? (int) ($summary['counts']['completed'] ?? count($completed)) : 0,
                'in_progress' => $inProgress,
                'overdue' => $available ? (int) ($summary['counts']['overdue'] ?? 0) : 0,
                'documents_to_sign' => $available ? (int) ($summary['counts']['documents_to_sign'] ?? count($docs)) : 0,
            ],
            'required' => $train,
            'documents' => $docs,
            'optional' => $optional,
            'available' => self::availableCourses($k, $listed, $available ? array_map(static fn($c) => (int) $c['course_id'], $completed) : [], $lang),
            'completed' => array_map(static fn($c) => [
                'completion_id' => (int) ($c['completion_id'] ?? 0), 'course_id' => (int) ($c['course_id'] ?? 0),
                'course_name' => (string) ($c['course_name'] ?? ''), 'kind' => (string) ($c['kind'] ?? 'training'),
                'completed_on' => (string) ($c['completed_on'] ?? ''), 'score_pct' => $c['score_pct'] ?? null,
            ], array_slice($completed, 0, self::COMPLETED_SHOWN)),
            'completed_total' => count($completed),
            'certificates' => $available ? array_values(array_map(static fn($c) => [
                'completion_id' => (int) ($c['completion_id'] ?? 0), 'course_id' => (int) ($c['course_id'] ?? 0),
                'course_name' => (string) ($c['course_name'] ?? ''), 'kind' => (string) ($c['kind'] ?? 'training'),
                'cert_number' => $c['cert_number'] ?? null, 'completed_on' => (string) ($c['completed_on'] ?? ''),
                'expires_on' => $c['expires_on'] ?? null, 'status' => (string) ($c['status'] ?? ''),
            ], $summary['certificates'] ?? [])) : [],
            'achievements' => self::awards($db, $cid, $lang, 'forContact'),
            'achievement_progress' => self::awards($db, $cid, $lang, 'progress'),
        ];
    }

    /** One course card, or null when the course is not playable on the kiosk (unpublished/archived). */
    public static function card(KioskCtx $k, int $courseId, array $item, ?array $run, string $lang): ?array
    {
        $db = $k->db();
        $course = Db::one($db, 'SELECT course_id, course_current_revision_id, course_archived_at FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        if ($course === null || $course['course_archived_at'] !== null) {
            return null;
        }
        $revId = $run !== null ? (int) $run['trun_revision_id'] : (int) ($course['course_current_revision_id'] ?? 0);
        if ($revId < 1) {
            return null;
        }
        try {
            $rev = RevisionCache::get($db, $revId);
        } catch (\Throwable $e) {
            error_log('Kiosk LearnerHome revision #' . $revId . ': ' . get_class($e));
            return null;
        }
        $doc = $rev['doc'];
        $c = $doc['course'];
        $default = (string) ($c['default_language'] ?? 'en');
        $l = in_array($lang, $c['languages'] ?? [], true) ? $lang : $default;
        $done = $run === null ? [] : RunRepo::done($db, (int) $run['trun_id']);
        $secondsLeft = 0;
        foreach ($doc['lessons'] ?? [] as $lesson) {
            if (!empty($lesson['required']) && !isset($done[(string) $lesson['uid']])) {
                $secondsLeft += max(0, (int) ($lesson['duration_s'] ?? 0));
            }
        }
        $state = 'start';
        $pick = null;
        if ($run !== null) {
            $st = (string) $run['trun_status'];
            $state = match (true) {
                $run['trun_locked_at_utc'] !== null => 'locked',
                $run['trun_blocked_reason'] !== null => 'blocked',
                $st === 'awaiting_signature' => 'sign',
                $st === 'awaiting_session' => 'session',
                $st === 'awaiting_evaluation' => 'evaluation',
                default => 'continue',
            };
            if ($state === 'continue') {
                $next = RunRepo::nextUid($doc, $done, null);
                if ($next !== null) {
                    $idx = array_search($next, RunRepo::order($doc), true);
                    $nl = RunRepo::lesson($doc, $next) ?? [];
                    $pick = ['index' => $idx === false ? 1 : $idx + 1, 'title' => (string) (RunRepo::variant($nl, $l, $default)['title'] ?? '')];
                }
            }
        } elseif (empty($c['components']['online'])) {
            $state = !empty($c['components']['practical']) && empty($c['components']['session']) ? 'evaluation' : 'session';
        }
        $cover = $c['cover_media_id'] ?? null;
        return [
            'course_id' => $courseId,
            'name' => (string) ($c['text'][$l]['name'] ?? $c['text'][$default]['name'] ?? ''),
            'kind' => (string) ($c['kind'] ?? 'training'),
            'color' => $c['color'] ?? null,
            'cover_url' => $cover === null ? null : KioskLearnerView::mediaUrl($revId)((int) $cover, false),
            'lesson_count' => count($doc['lessons'] ?? []),
            'minutes_left' => (int) ceil($secondsLeft / 60),
            'validity_months' => $c['validity_months'] ?? null,
            'due_on' => $item['due_on'] ?? null,
            'status' => $item['status'] ?? null,
            'assignment_id' => isset($item['assignment_id']) ? (int) $item['assignment_id'] : null,
            'run' => $run === null ? null : [
                'run_id' => (int) $run['trun_id'],
                'status' => (string) $run['trun_status'],
                'state' => $state,
                'progress_pct' => (int) $run['trun_progress_pct'],
                'pick_up' => $pick,
            ],
            'action' => $state,
        ];
    }

    /** [S] Published courses with an online part that are not assigned, not in progress and not held. */
    private static function availableCourses(KioskCtx $k, array $listed, array $completedIds, string $lang): array
    {
        $db = $k->db();
        $skip = $listed + array_fill_keys($completedIds, true);
        $out = [];
        foreach (Db::all($db, 'SELECT course_id FROM training_courses WHERE course_archived_at IS NULL AND course_current_revision_id IS NOT NULL
                ORDER BY course_name, course_id LIMIT 200') as $row) {
            $id = (int) $row['course_id'];
            if (isset($skip[$id])) {
                continue;
            }
            $c = self::card($k, $id, ['due_on' => null, 'status' => null, 'assignment_id' => null], null, $lang);
            if ($c === null || $c['action'] !== 'start') {
                continue;
            }
            $out[] = $c;
            if (count($out) >= self::AVAILABLE_MAX) {
                break;
            }
        }
        return $out;
    }

    /** PIN notices through K2 (PinService::noticesForKsess): a reset by an agent or an Odoo PIN change, flagged at this session's sign-in. */
    private static function notices(\mysqli $db, int $ksessId, int $cid): array
    {
        $out = [];
        try {
            foreach (PinService::noticesForKsess($db, $ksessId, $cid) as $n) {
                if (!is_string($n['at_utc'] ?? null) || $n['at_utc'] === '') {
                    continue;
                }
                $on = Clock::localDate((string) $n['at_utc']);
                if (($n['type'] ?? '') === 'reset') {
                    $out[] = ['kind' => 'pin_reset', 'on' => $on, 'by' => (string) ($n['by'] ?? '')];
                } elseif (($n['type'] ?? '') === 'fp_changed') {
                    $out[] = ['kind' => 'pin_changed', 'on' => $on];
                }
            }
        } catch (\Throwable $e) {
            error_log('Kiosk LearnerHome notices: ' . get_class($e));
        }
        return $out;
    }

    private static function awards(\mysqli $db, int $cid, string $lang, string $fn): array
    {
        try {
            return array_values($fn === 'progress' ? AwardRepository::progress($db, $cid, $lang) : AwardRepository::forContact($db, $cid, $lang));
        } catch (\Throwable $e) {
            error_log('Kiosk LearnerHome awards: ' . get_class($e));
            return [];
        }
    }
}
