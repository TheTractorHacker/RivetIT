<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;
use ITFlow\Training\Kiosk\Core\RevisionCache;
use ITFlow\Training\Kiosk\Learn\RunRepo;
use ITFlow\Training\Kiosk\Learn\RunService;
use ITFlow\Training\Quiz\InList;

/**
 * Agent "Locked & blocked courses" (P3 spec §4.3 run_ rows, L-10): kiosk runs locked after the
 * last failed try, or blocked because a video changed or disappeared. module_training >= 2, and
 * every run is scoped to the agent's departments (RecordsBridge::scopeClientIds, fail-closed:
 * a run outside the scope is a 404, exactly like a run that does not exist).
 */
final class RunAdminActions
{
    public const LIST_MAX = 500;

    /** GET run_locked_list [client_id] => {runs:[{run_id, contact, course, state, attempts_used, locked_at, lesson, blocked_reason}]} */
    public static function runLockedList(Ctx $c, ApiContext $a): array
    {
        self::level($c);
        $db = $c->db;
        if (!self::ready($db)) {
            return ['runs' => []];
        }
        $scope = (new RecordsBridge($c))->scopeClientIds($c);
        if ($scope === []) {
            return ['runs' => []];
        }
        $clientId = $a->int('client_id', false, 1);
        $where = 'r.trun_open_guard = 1 AND (r.trun_locked_at_utc IS NOT NULL OR r.trun_blocked_reason IS NOT NULL)';
        $types = '';
        $params = [];
        if ($scope !== null) {
            [$ph, $t, $p] = InList::ints($scope);
            $where .= " AND c.contact_client_id IN ($ph)";
            $types .= $t;
            $params = array_merge($params, $p);
        }
        if ($clientId !== null) {
            $where .= ' AND c.contact_client_id = ?';
            $types .= 'i';
            $params[] = $clientId;
        }
        $rows = Db::all($db, 'SELECT r.trun_id, r.trun_contact_id, r.trun_course_id, r.trun_revision_id, r.trun_language, r.trun_status, r.trun_locked_at_utc,
                r.trun_locked_lesson_uid, r.trun_blocked_reason, r.trun_blocked_lesson_uid, r.trun_started_at_utc, r.trun_last_activity_at_utc,
                c.contact_name, c.contact_client_id, cl.client_name, co.course_name, co.course_current_revision_id
            FROM training_runs r
            JOIN contacts c ON c.contact_id = r.trun_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            JOIN training_courses co ON co.course_id = r.trun_course_id
            WHERE ' . $where . ' ORDER BY COALESCE(r.trun_locked_at_utc, r.trun_last_activity_at_utc) DESC, r.trun_id DESC LIMIT ' . self::LIST_MAX,
            $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $lessonUid = $r['trun_locked_at_utc'] !== null ? $r['trun_locked_lesson_uid'] : $r['trun_blocked_lesson_uid'];
            $lessonTitle = null;
            $attempts = 0;
            try {
                $doc = RevisionCache::get($db, (int) $r['trun_revision_id'])['doc'];
                if ($lessonUid !== null) {
                    $l = RunRepo::lesson($doc, (string) $lessonUid);
                    $lessonTitle = $l === null ? null : (string) (RunRepo::variant($l, (string) $r['trun_language'], (string) $doc['course']['default_language'])['title'] ?? '');
                }
            } catch (\Throwable $e) {
                error_log('Training run_locked_list revision: ' . get_class($e));
            }
            if ($lessonUid !== null) {
                $attempts = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM training_attempts WHERE tattempt_run_id = ? AND tattempt_lesson_uid = ?', 'is',
                    [(int) $r['trun_id'], (string) $lessonUid])['n'] ?? 0);
            }
            $out[] = [
                'run_id' => (int) $r['trun_id'],
                'contact' => ['id' => (int) $r['trun_contact_id'], 'name' => (string) $r['contact_name'], 'dept' => (string) ($r['client_name'] ?? '')],
                'course' => ['id' => (int) $r['trun_course_id'], 'name' => (string) $r['course_name'],
                    'newer_version' => $r['course_current_revision_id'] !== null && (int) $r['course_current_revision_id'] !== (int) $r['trun_revision_id']],
                'state' => $r['trun_locked_at_utc'] !== null ? 'locked' : 'blocked',
                'attempts_used' => $attempts,
                'locked_at' => $r['trun_locked_at_utc'] === null ? null : \ITFlow\Training\Core\Clock::toIso((string) $r['trun_locked_at_utc'], true),
                'last_activity' => \ITFlow\Training\Core\Clock::toIso((string) $r['trun_last_activity_at_utc'], true),
                'lesson' => $lessonUid === null ? null : ['uid' => (string) $lessonUid, 'title' => $lessonTitle],
                'blocked_reason' => $r['trun_blocked_reason'] === null ? null : (string) $r['trun_blocked_reason'],
            ];
        }
        return ['runs' => $out];
    }

    /** POST run_unlock {run_id, mode:'extra'|'restart', extra?:1..5, reason(5..255)} => {} */
    public static function runUnlock(Ctx $c, ApiContext $a): array
    {
        self::level($c);
        $db = $c->db;
        if (!self::ready($db)) {
            throw ApiException::notFound('That course run was not found.');
        }
        $runId = (int) $a->int('run_id', true, 1);
        $mode = (string) $a->enum('mode', RunService::UNLOCK_MODES);
        $extra = $mode === 'extra' ? (int) ($a->int('extra', false, 1, 5) ?? 1) : 0;
        $reason = (string) $a->str('reason', 255);
        $run = RunRepo::load($db, $runId);
        if ($run === null) {
            throw ApiException::notFound('That course run was not found.');
        }
        (new RecordsBridge($c))->assertContactInScope($c, (int) $run['trun_contact_id']);
        $res = RunService::unlockAs($db, $runId, $mode, $extra, $reason, [
            'actor_type' => 'user', 'actor_user_id' => $c->userId, 'user_agent' => $c->userAgent,
        ]);
        $summary = ($mode === 'extra' ? "Gave $extra more " . ($extra === 1 ? 'try' : 'tries') : 'Restarted on the current version')
            . ' for kiosk course run #' . $runId . ' (contact #' . $res['contact_id'] . ', course #' . $res['course_id'] . ')';
        CourseActions::log('Modify', $summary, $runId);
        try {
            (new \ITFlow\Audit\AuditService($db))->log('training.run_unlocked', $c->userId, 'training_run', $runId, 'update', $summary,
                ['contact_id' => $res['contact_id'], 'course_id' => $res['course_id'], 'mode' => $mode, 'extra' => $extra, 'reason' => $reason]);
        } catch (\Throwable $e) {
            error_log('Training run_unlock audit: ' . get_class($e));
        }
        return [];
    }

    private static function level(Ctx $c): void
    {
        if ($c->level < 2 && !$c->isAdmin) {
            throw ApiException::forbidden('Unlocking courses needs Training edit access.');
        }
    }

    private static function ready(\mysqli $db): bool
    {
        $row = Db::one($db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training_runs'");
        return (int) ($row['n'] ?? 0) === 1;
    }
}
