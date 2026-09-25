<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Publish\RevisionBuilder;

/**
 * The course settings a record is issued under (Lane C internal).
 *
 * When the course has a published revision, everything comes from that revision's JSON
 * (`course.*`: validity, renewal lead, components, window, checklist, name, code and
 * regulation reference), because that is what the person was trained on and what the
 * reconcile engine reads. Without one (an external card or paper record for a course that was
 * never published) the live course row is the fallback. Revision JSON is decoded once per
 * process per revision.
 */
final class CourseFacts
{
    private const COURSE_COLS = 'course_id, course_kind, course_name, course_code, course_regulation_ref, course_current_revision_id,
        course_archived_at, course_needs_online, course_needs_session, course_needs_practical, course_external_only,
        course_component_window_days, course_validity_months, course_renewal_lead_days, course_eval_checklist,
        course_est_minutes, course_is_qualification, course_default_language, course_allow_trainer_attest';

    /** @var array<int, array> revision id => decoded facts */
    private static array $revCache = [];

    /**
     * @return array{course_id:int, kind:string, name:string, code:?string, regulation_ref:?string, archived:bool,
     *   current_revision_id:?int, published:bool,
     *   revision:?array{id:int, number:int, sha256:string, published_at_utc:string, published_on:string, requires_retraining:bool, retrain_due_days:?int},
     *   validity_months:?int, renewal_lead_days:int, needs:array{online:bool, session:bool, practical:bool, external_only:bool},
     *   window_days:int, checklist:list<array{item:string, critical:bool}>, est_minutes:?int, is_qualification:bool,
     *   allow_trainer_attest:bool, has_exam:bool, pass_pct:?int}|null  null when the course does not exist
     * @throws ApiException 422 validation(revision_id) when $revisionId is not a revision of this course
     */
    public static function load(\mysqli $db, int $courseId, ?int $revisionId = null): ?array
    {
        if ($courseId < 1) {
            return null;
        }
        $c = Db::one($db, 'SELECT ' . self::COURSE_COLS . ' FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        if ($c === null) {
            return null;
        }
        $currentRev = $c['course_current_revision_id'] === null ? null : (int) $c['course_current_revision_id'];
        $useRev = $revisionId ?? $currentRev;
        $rev = null;
        if ($useRev !== null) {
            $rev = self::revision($db, $useRev);
            if ($rev === null || $rev['course_id'] !== $courseId) {
                if ($revisionId !== null) {
                    throw ApiException::validation(['revision_id' => 'That version does not belong to this course.']);
                }
                $rev = null;
            }
        }

        $live = [
            'validity_months' => $c['course_validity_months'] === null ? null : (int) $c['course_validity_months'],
            'renewal_lead_days' => (int) $c['course_renewal_lead_days'],
            'needs' => [
                'online' => (int) $c['course_needs_online'] === 1,
                'session' => (int) $c['course_needs_session'] === 1,
                'practical' => (int) $c['course_needs_practical'] === 1,
                'external_only' => (int) $c['course_external_only'] === 1,
            ],
            'window_days' => (int) $c['course_component_window_days'],
            'checklist' => RevisionBuilder::checklist($c['course_eval_checklist']),
            'allow_trainer_attest' => (int) $c['course_allow_trainer_attest'] === 1,
            'name' => (string) $c['course_name'],
            'code' => $c['course_code'] === null || $c['course_code'] === '' ? null : (string) $c['course_code'],
            'regulation_ref' => $c['course_regulation_ref'] === null || $c['course_regulation_ref'] === '' ? null : (string) $c['course_regulation_ref'],
            'est_minutes' => $c['course_est_minutes'] === null ? null : (int) $c['course_est_minutes'],
            'is_qualification' => (int) $c['course_is_qualification'] === 1,
            'has_exam' => false,
            'pass_pct' => null,
        ];
        $s = $rev === null ? $live : array_merge($live, $rev['settings']);

        return [
            'course_id' => (int) $c['course_id'],
            'kind' => (string) $c['course_kind'],
            'name' => $s['name'],
            'code' => $s['code'],
            'regulation_ref' => $s['regulation_ref'],
            'archived' => $c['course_archived_at'] !== null,
            'current_revision_id' => $currentRev,
            'published' => $currentRev !== null,
            'revision' => $rev === null ? null : $rev['row'],
            'validity_months' => $s['validity_months'],
            'renewal_lead_days' => $s['renewal_lead_days'],
            'needs' => $s['needs'],
            'window_days' => max(0, (int) $s['window_days']),
            'checklist' => $s['checklist'],
            'est_minutes' => $s['est_minutes'],
            'is_qualification' => $s['is_qualification'],
            'allow_trainer_attest' => $s['allow_trainer_attest'],
            'has_exam' => $s['has_exam'],
            'pass_pct' => $s['pass_pct'],
        ];
    }

    /**
     * CourseCard (spec §4.1) from load()'s facts:
     * {id, name, code, kind, published, revision_number, est_minutes, has_exam, pass_pct, validity_months,
     *  renewal_lead_days, needs:{online,session,practical,external_only}}
     */
    public static function card(array $f): array
    {
        return [
            'id' => (int) $f['course_id'],
            'name' => (string) $f['name'],
            'code' => $f['code'],
            'kind' => (string) $f['kind'],
            'published' => (bool) $f['published'],
            'revision_number' => $f['revision'] === null ? null : (int) $f['revision']['number'],
            'est_minutes' => $f['est_minutes'],
            'has_exam' => (bool) $f['has_exam'],
            'pass_pct' => $f['pass_pct'],
            'validity_months' => $f['validity_months'],
            'renewal_lead_days' => (int) $f['renewal_lead_days'],
            'needs' => $f['needs'],
        ];
    }

    /** The published revisions of a course, newest first: [{id, number}]. */
    public static function revisions(\mysqli $db, int $courseId): array
    {
        return array_map(static fn(array $r) => ['id' => (int) $r['revision_id'], 'number' => (int) $r['revision_number']],
            Db::all($db, 'SELECT revision_id, revision_number FROM training_revisions WHERE revision_course_id = ? ORDER BY revision_number DESC', 'i', [$courseId]));
    }

    /**
     * The latest requires-retraining revision per course, shaped as PairRules' RR:
     * {revision_id, revision_number, published_on (local), retrain_due_days}.
     *
     * @param list<int> $courseIds
     * @return array<int, array{revision_id:int, revision_number:int, published_on:string, retrain_due_days:int}>
     */
    public static function retrainRevisions(\mysqli $db, array $courseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $courseIds), static fn(int $i) => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::all($db, "SELECT revision_id, revision_course_id, revision_number, revision_published_at_utc, revision_retrain_due_days
                FROM training_revisions WHERE revision_requires_retraining = 1 AND revision_course_id IN ($in)
                ORDER BY revision_course_id, revision_number DESC", str_repeat('i', count($chunk)), $chunk);
            foreach ($rows as $r) {
                $cid = (int) $r['revision_course_id'];
                if (isset($out[$cid])) {
                    continue;
                }
                $out[$cid] = [
                    'revision_id' => (int) $r['revision_id'],
                    'revision_number' => (int) $r['revision_number'],
                    'published_on' => Clock::localDate((string) $r['revision_published_at_utc']),
                    'retrain_due_days' => (int) ($r['revision_retrain_due_days'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /** @return array{course_id:int, row:array, settings:array}|null */
    private static function revision(\mysqli $db, int $revisionId): ?array
    {
        if (isset(self::$revCache[$revisionId])) {
            return self::$revCache[$revisionId];
        }
        $r = Db::one($db, 'SELECT revision_id, revision_course_id, revision_number, revision_sha256, revision_json, revision_published_at_utc,
                revision_requires_retraining, revision_retrain_due_days
            FROM training_revisions WHERE revision_id = ?', 'i', [$revisionId]);
        if ($r === null) {
            return null;
        }
        $doc = json_decode((string) $r['revision_json'], true);
        $course = is_array($doc) && is_array($doc['course'] ?? null) ? $doc['course'] : [];
        $comp = is_array($course['components'] ?? null) ? $course['components'] : [];
        $default = is_string($course['default_language'] ?? null) ? $course['default_language'] : 'en';
        $text = is_array($course['text'] ?? null) ? $course['text'] : [];
        $name = $text[$default]['name'] ?? null;
        if (!is_string($name) || $name === '') {
            foreach ($text as $t) {
                if (is_array($t) && is_string($t['name'] ?? null) && $t['name'] !== '') {
                    $name = $t['name'];
                    break;
                }
            }
        }
        $checklist = [];
        foreach (is_array($course['eval_checklist'] ?? null) ? $course['eval_checklist'] : [] as $it) {
            if (is_array($it) && is_string($it['item'] ?? null)) {
                $checklist[] = ['item' => $it['item'], 'critical' => (bool) ($it['critical'] ?? false)];
            }
        }
        $hasExam = false;
        $passPct = null;
        foreach (is_array($doc['lessons'] ?? null) ? $doc['lessons'] : [] as $l) {
            if (is_array($l) && is_array($l['quiz'] ?? null) && ($l['quiz']['role'] ?? null) === 'exam') {
                $hasExam = true;
                $passPct = isset($l['quiz']['pass_pct']) ? (int) $l['quiz']['pass_pct'] : null;
            }
        }
        $settings = [
            'validity_months' => isset($course['validity_months']) ? (int) $course['validity_months'] : null,
            'renewal_lead_days' => (int) ($course['renewal_lead_days'] ?? 30),
            'needs' => [
                'online' => (bool) ($comp['online'] ?? true),
                'session' => (bool) ($comp['session'] ?? false),
                'practical' => (bool) ($comp['practical'] ?? false),
                'external_only' => (bool) ($comp['external_only'] ?? false),
            ],
            'window_days' => (int) ($comp['window_days'] ?? 90),
            'allow_trainer_attest' => (bool) ($comp['allow_trainer_attest'] ?? true),
            'checklist' => $checklist,
            'code' => is_string($course['code'] ?? null) && $course['code'] !== '' ? $course['code'] : null,
            'regulation_ref' => is_string($course['regulation_ref'] ?? null) && $course['regulation_ref'] !== '' ? $course['regulation_ref'] : null,
            'est_minutes' => isset($course['est_minutes']) ? (int) $course['est_minutes'] : null,
            'is_qualification' => (bool) ($course['is_qualification'] ?? false),
            'has_exam' => $hasExam,
            'pass_pct' => $passPct,
        ];
        if (is_string($name) && $name !== '') {
            $settings['name'] = $name;
        }
        $out = [
            'course_id' => (int) $r['revision_course_id'],
            'row' => [
                'id' => (int) $r['revision_id'],
                'number' => (int) $r['revision_number'],
                'sha256' => (string) $r['revision_sha256'],
                'published_at_utc' => (string) $r['revision_published_at_utc'],
                'published_on' => Clock::localDate((string) $r['revision_published_at_utc']),
                'requires_retraining' => (int) $r['revision_requires_retraining'] === 1,
                'retrain_due_days' => $r['revision_retrain_due_days'] === null ? null : (int) $r['revision_retrain_due_days'],
            ],
            'settings' => $settings,
        ];
        if (count(self::$revCache) > 64) {
            self::$revCache = [];
        }
        return self::$revCache[$revisionId] = $out;
    }
}
