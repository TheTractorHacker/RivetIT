<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Core\Db;

/**
 * Course facts for assignment and compliance, read from each course's CURRENT (published) revision
 * JSON (course.*) so what learners were shown and what compliance enforces agree (spec §3.3).
 *
 * card(): CourseCard (spec §4.1) = {id, name, code, kind, published, revision_number, est_minutes, has_exam,
 *   pass_pct, validity_months, renewal_lead_days, needs:{online,session,practical,external_only}}
 * load() rows add: archived, current_revision_id, is_qualification (internal).
 */
final class CourseCards
{
    private const REV = "JSON_VALUE(r.revision_json, '$.course.%s')";

    /** @return array<int, array> course_id => course facts (missing ids are left out) */
    public static function load(\mysqli $db, array $courseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $courseIds), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $v = static fn(string $path) => sprintf(self::REV, $path);
        $rows = Db::all($db, "SELECT c.course_id, c.course_kind, c.course_name, c.course_code, c.course_archived_at, c.course_current_revision_id,
                c.course_est_minutes, c.course_validity_months, c.course_renewal_lead_days, c.course_is_qualification,
                c.course_needs_online, c.course_needs_session, c.course_needs_practical, c.course_external_only,
                r.revision_number,
                JSON_VALUE(r.revision_json, CONCAT('$.course.text.', JSON_VALUE(r.revision_json, '$.course.default_language'), '.name')) AS rev_name,
                " . $v('code') . " AS rev_code, " . $v('validity_months') . " AS rev_validity, " . $v('renewal_lead_days') . " AS rev_lead,
                " . $v('est_minutes') . " AS rev_est, " . $v('is_qualification') . " AS rev_qual,
                " . $v('components.online') . " AS rev_online, " . $v('components.session') . " AS rev_session,
                " . $v('components.practical') . " AS rev_practical, " . $v('components.external_only') . " AS rev_external,
                JSON_EXTRACT(r.revision_json, '$.lessons[*].quiz') AS rev_quizzes
            FROM training_courses c
            LEFT JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
            WHERE c.course_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
        $out = [];
        foreach ($rows as $r) {
            $pub = $r['course_current_revision_id'] !== null && $r['revision_number'] !== null;
            $b = static fn($revVal, $liveVal) => $pub ? in_array((string) $revVal, ['true', '1'], true) : (int) $liveVal === 1;
            $int = static fn($revVal, $liveVal) => $pub ? ($revVal === null || $revVal === 'null' ? null : (int) $revVal) : ($liveVal === null ? null : (int) $liveVal);
            $hasExam = false;
            $passPct = null;
            if ($pub && $r['rev_quizzes'] !== null) {
                $quizzes = json_decode((string) $r['rev_quizzes'], true);
                foreach (is_array($quizzes) ? $quizzes : [] as $q) {
                    if (is_array($q) && ($q['role'] ?? null) === 'exam') {
                        $hasExam = true;
                        $passPct = isset($q['pass_pct']) ? (int) $q['pass_pct'] : null;
                    }
                }
            }
            $name = $pub && $r['rev_name'] !== null && $r['rev_name'] !== '' ? (string) $r['rev_name'] : (string) $r['course_name'];
            $code = $pub ? ($r['rev_code'] === null || $r['rev_code'] === 'null' ? null : (string) $r['rev_code']) : $r['course_code'];
            $id = (int) $r['course_id'];
            $out[$id] = [
                'id' => $id,
                'name' => $name,
                'code' => $code === '' ? null : $code,
                'kind' => (string) $r['course_kind'],
                'published' => $pub && $r['course_archived_at'] === null,
                'revision_number' => $pub ? (int) $r['revision_number'] : null,
                'est_minutes' => $int($r['rev_est'], $r['course_est_minutes']),
                'has_exam' => $hasExam,
                'pass_pct' => $passPct,
                'validity_months' => $int($r['rev_validity'], $r['course_validity_months']),
                'renewal_lead_days' => (int) ($int($r['rev_lead'], $r['course_renewal_lead_days']) ?? 0),
                'needs' => [
                    'online' => $b($r['rev_online'], $r['course_needs_online']),
                    'session' => $b($r['rev_session'], $r['course_needs_session']),
                    'practical' => $b($r['rev_practical'], $r['course_needs_practical']),
                    'external_only' => $b($r['rev_external'], $r['course_external_only']),
                ],
                'is_qualification' => $b($r['rev_qual'], $r['course_is_qualification']),
                'archived' => $r['course_archived_at'] !== null,
                'current_revision_id' => $pub ? (int) $r['course_current_revision_id'] : null,
            ];
        }
        return $out;
    }

    /** The public CourseCard subset. */
    public static function card(array $k): array
    {
        return array_intersect_key($k, array_flip(['id', 'name', 'code', 'kind', 'published', 'revision_number', 'est_minutes', 'has_exam',
            'pass_pct', 'validity_months', 'renewal_lead_days', 'needs']));
    }

    /** {id, name, kind, code} (+ is_qualification for PairStatus). */
    public static function brief(array $k, bool $withQualification = false): array
    {
        $b = ['id' => $k['id'], 'name' => $k['name'], 'kind' => $k['kind'], 'code' => $k['code']];
        if ($withQualification) {
            $b['is_qualification'] = $k['is_qualification'];
        }
        return $b;
    }
}
