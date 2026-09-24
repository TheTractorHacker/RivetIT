<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Authoring\CourseTouch;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Publish\DriftService;
use ITFlow\Training\Publish\PublishValidator;
use ITFlow\Training\Publish\RevisionBuilder;
use ITFlow\Training\Publish\RevisionDiff;
use ITFlow\Training\Publish\RevisionPublisher;
use ITFlow\Training\Publish\RevisionRepository;
use ITFlow\Training\Quiz\Guard;

/**
 * JSON handlers for publish readiness, publishing, versions and drift (spec §6.2, lane D).
 *
 * has_changes has one definition everywhere: the strict build's sha differs from the current
 * revision's sha (or nothing is published). When publish_check computes an equal sha it settles
 * the draft timestamp, so a stale "Unpublished changes" clears itself.
 *
 * publish_check and course_drift are GETs that may write that timestamp (spec §3.5) and, with
 * network=1, call YouTube/Vimeo. A GET cannot carry the CSRF header, so they refuse a request
 * the browser marks as coming from another site (Sec-Fetch-Site, when present, must be
 * same-origin or none): a link on another page cannot make an author's browser trigger them.
 */
final class PublishActions
{
    /**
     * GET publish_check: course_id, network=0|1 =>
     * {errors, warnings, todo, summary:{lessons, questions, languages, excluded_languages, media_count},
     *  diff, next_revision_number, has_changes, kind, has_previous}
     */
    public static function publishCheck(Ctx $c, ApiContext $a): array
    {
        self::assertNotCrossSite();
        $courseId = (int) $a->int('course_id', true, 1);
        $network = (int) ($a->int('network', false, 0, 1) ?? 0) === 1;
        $course = Guard::course($c->db, $courseId);
        $build = (new RevisionBuilder($c))->build($courseId, true);
        $check = (new PublishValidator($c))->validate($build, $courseId, $network);
        $repo = new RevisionRepository($c);
        $current = $repo->current($courseId);
        $hasChanges = $current === null || !hash_equals($current['sha256'], $build['sha256']);
        if (!$hasChanges && $course['course_archived_at'] === null) {
            Db::tx($c->db, static function () use ($c, $courseId, $current, $build): void {
                CourseTouch::settleIfUnchanged($c->db, $courseId, $current['published_at_utc'], $build['draft_updated_at_utc']);
            });
        }
        return $check + [
            'summary' => [
                'lessons' => count($build['doc']['lessons']),
                'questions' => count($build['doc']['questions']),
                'languages' => $build['languages'],
                'excluded_languages' => array_keys($build['excluded']),
                'media_count' => count($build['doc']['media']),
                'bytes' => $build['bytes'],
            ],
            'diff' => RevisionDiff::diff($current['doc'] ?? null, $build['doc']),
            'next_revision_number' => $repo->nextNumber($courseId),
            'has_changes' => $hasChanges,
            'kind' => (string) $course['course_kind'],
            'has_previous' => $current !== null,
            'current_revision' => $current === null ? null : ['id' => $current['id'], 'number' => $current['number'], 'sha12' => $current['sha12']],
            'archived' => $course['course_archived_at'] !== null,
        ];
    }

    /**
     * POST publish (level 3): {course_id, change_note, requires_retraining, retrain_due_days?, acknowledge_warnings}
     * => {revision_id, number, sha256}
     */
    public static function publish(Ctx $c, ApiContext $a): array
    {
        $courseId = (int) $a->int('course_id', true, 1);
        $note = (string) $a->str('change_note', RevisionPublisher::NOTE_MAX);
        $retrain = (bool) $a->bool('requires_retraining', false);
        $days = $retrain ? $a->int('retrain_due_days', true, 1, 365) : null;
        $r = (new RevisionPublisher($c))->publish($courseId, $note, $retrain, $days, (bool) $a->bool('acknowledge_warnings', false));

        // After commit, on the same connection (spec §0): legacy log + audit trail. Best-effort:
        // the version is published (and ledger-evented) already, so a logging failure must not
        // turn it into a 500 whose retry answers no_changes.
        try {
            $name = (string) (Db::one($c->db, 'SELECT course_name FROM training_courses WHERE course_id = ?', 'i', [$courseId])['course_name'] ?? '');
        } catch (\Throwable $e) {
            $name = '#' . $courseId;
        }
        CourseActions::log('Publish', "Published \"$name\" version {$r['number']}", $courseId);
        CourseActions::audit($c, 'training.revision_published', $courseId, 'publish', "Published \"$name\" version {$r['number']}", [
            'revision_id' => $r['revision_id'], 'number' => $r['number'], 'sha256' => $r['sha256'],
            'languages' => $r['languages'], 'requires_retraining' => $retrain, 'retrain_due_days' => $days,
        ]);
        return ['revision_id' => $r['revision_id'], 'number' => $r['number'], 'sha256' => $r['sha256'],
            'sha12' => substr($r['sha256'], 0, 12), 'languages' => $r['languages'], 'published_at' => $r['published_at']];
    }

    /** GET revision_list (level 1): course_id => {revisions:[…, verified]} */
    public static function revisionList(Ctx $c, ApiContext $a): array
    {
        $courseId = (int) $a->int('course_id', true, 1);
        $course = Guard::course($c->db, $courseId);
        if ($course['course_archived_at'] !== null && $c->level < 2) {
            throw ApiException::notFound('That course no longer exists.');
        }
        $current = $course['course_current_revision_id'] === null ? null : (int) $course['course_current_revision_id'];
        $list = (new RevisionRepository($c))->list($courseId);
        foreach ($list as &$r) {
            $r['current'] = $r['id'] === $current;
        }
        unset($r);
        return ['revisions' => $list];
    }

    /** GET revision_diff: course_id, from_revision_id? (default: the current version) => diff of that version -> draft */
    public static function revisionDiff(Ctx $c, ApiContext $a): array
    {
        $courseId = (int) $a->int('course_id', true, 1);
        $fromId = $a->int('from_revision_id', false, 1);
        Guard::course($c->db, $courseId);
        $repo = new RevisionRepository($c);
        if ($fromId !== null) {
            $from = $repo->get($fromId);
            if ($from['course_id'] !== $courseId) {
                throw ApiException::notFound('That version no longer exists.');
            }
        } else {
            $from = $repo->current($courseId);
        }
        $build = (new RevisionBuilder($c))->build($courseId, true);
        return RevisionDiff::diff($from['doc'] ?? null, $build['doc']) + [
            'from_revision' => $from === null ? null : ['id' => $from['id'], 'number' => $from['number']],
        ];
    }

    /** GET course_drift: course_id => {has_changes, draft_sha, current_sha, current_number, kb, banks, videos} */
    public static function courseDrift(Ctx $c, ApiContext $a): array
    {
        self::assertNotCrossSite();
        $courseId = (int) $a->int('course_id', true, 1);
        Guard::course($c->db, $courseId);
        return (new DriftService($c))->forCourse($courseId);
    }

    /**
     * For GET actions with side effects (a settle write, outbound oEmbed requests, an export log
     * row): 403 when the browser says the request came from another site. An absent header (old
     * browser, CLI) and 'none' (typed or bookmarked URL) are allowed; fetch() from a training page
     * sends 'same-origin'.
     */
    public static function assertNotCrossSite(): void
    {
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($site) && $site !== '' && !in_array(strtolower($site), ['same-origin', 'none'], true)) {
            throw ApiException::forbidden('Open this from the Training pages.');
        }
    }
}
