<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\CourseTouch;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashSpecs;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RowHasher;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Quiz\Guard;
use ITFlow\Training\Quiz\InList;

/**
 * Publishes a course's draft as an immutable revision (spec §3.5).
 *
 *   1  Db::lock('trpub:<course>', 5) - else 409 busy ("Someone else is publishing this course")
 *   2  build(strict) in a consistent snapshot; validate(network) - errors => 422 with the issues;
 *      unacknowledged warnings => 422 as well
 *   3  canonical JSON; equal to the current revision => settle the draft timestamp, 409 no_changes
 *   4  every manifest file exists and hash_file() matches its media_sha256 (422 media_missing /
 *      media_hash_mismatch); no evidence file is referenced (422 media_evidence_ref)
 *   5  Db::tx { SELECT course FOR UPDATE ; number = MAX+1 ; INSERT revision (row hash) ;
 *               INSERT revision_media = the manifest ; UPDATE course_current_revision_id ;
 *               CourseTouch::settle(published_at) ; Ledger 'revision.published' (last) }
 *   6  unlock
 *
 * Network I/O (step 2) and file hashing (step 4) happen before the transaction; the
 * transaction itself only writes. If the draft changed after the build was taken, the course is
 * touched instead of settled, so "Unpublished changes" keeps showing.
 * There is no UPDATE or DELETE path for revisions or revision media anywhere.
 */
final class RevisionPublisher
{
    public const LOCK_TIMEOUT_S = 5;
    public const NOTE_MIN = 5;
    public const NOTE_MAX = 1000;

    public function __construct(private readonly Ctx $c, private readonly ?MediaStore $store = null, private readonly bool $network = true)
    {
    }

    /** @return array{revision_id:int, number:int, sha256:string, languages:list<string>, published_at:?string} */
    public function publish(int $courseId, string $changeNote, bool $retrain, ?int $retrainDays, bool $ackWarnings): array
    {
        $db = $this->c->db;
        if (Db::depth() !== 0) {
            throw new \LogicException('RevisionPublisher::publish must not run inside a transaction');
        }
        $changeNote = trim($changeNote);
        $len = mb_strlen($changeNote, 'UTF-8');
        if (!mb_check_encoding($changeNote, 'UTF-8') || $len < self::NOTE_MIN || $len > self::NOTE_MAX) {
            throw ApiException::validation(['change_note' => 'Describe the change in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters.']);
        }
        if ($retrain && ($retrainDays === null || $retrainDays < 1 || $retrainDays > 365)) {
            throw ApiException::validation(['retrain_due_days' => 'Give the number of days (1 to 365).']);
        }

        $lock = 'trpub:' . $courseId;
        if (!Db::lock($db, $lock, self::LOCK_TIMEOUT_S)) {
            throw ApiException::busy('Someone else is publishing this course.');
        }
        try {
            Guard::writableCourse($db, $courseId);
            $build = (new RevisionBuilder($this->c))->build($courseId, true);
            $check = (new PublishValidator($this->c, $this->store))->validate($build, $courseId, $this->network);
            if ($check['errors'] !== []) {
                $evidence = in_array('media_evidence_ref', array_column($check['errors'], 'code'), true);
                throw new ApiException(422, $evidence ? 'media_evidence_ref' : 'validation', $evidence
                    ? 'A learner evidence file cannot be part of course content.' : 'Fix the problems below before publishing.', [],
                    ['errors' => $check['errors'], 'warnings' => $check['warnings']]);
            }
            if ($check['warnings'] !== [] && !$ackWarnings) {
                throw new ApiException(422, 'validation', 'Review the warnings, then confirm.', ['acknowledge_warnings' => 'Required.'],
                    ['errors' => [], 'warnings' => $check['warnings']]);
            }

            $repo = new RevisionRepository($this->c);
            $head = $repo->currentHead($courseId);
            if ($head !== null && hash_equals($head['sha256'], $build['sha256'])) {
                Db::tx($db, function () use ($db, $courseId, $head): void {
                    CourseTouch::settle($db, $courseId, $head['published_at_utc']);
                });
                throw new ApiException(409, 'no_changes', "Nothing has changed since Version {$head['number']}.");
            }

            $this->verifyFiles($build);

            $doc = $build['doc'];
            $retrain = $retrain && $head !== null;
            $retrainDays = $retrain ? $retrainDays : null;
            $userId = $this->c->userId;
            $result = Db::tx($db, function () use ($db, $courseId, $build, $doc, $changeNote, $retrain, $retrainDays, $userId): array {
                $course = Db::one($db, 'SELECT course_id, course_uid, course_kind, course_archived_at, course_draft_updated_at_utc
                    FROM training_courses WHERE course_id = ? FOR UPDATE', 'i', [$courseId]);
                if ($course === null) {
                    throw ApiException::notFound('That course no longer exists.');
                }
                if ($course['course_archived_at'] !== null) {
                    throw ApiException::archived();
                }
                $number = (int) (Db::one($db, 'SELECT COALESCE(MAX(revision_number), 0) + 1 AS n FROM training_revisions WHERE revision_course_id = ?',
                    'i', [$courseId])['n'] ?? 1);
                $publishedAt = Clock::nowUtc();
                // Text-protocol shape: exactly what LedgerVerifier re-reads and re-hashes.
                $row = [
                    'revision_course_id' => (string) $courseId,
                    'revision_number' => (string) $number,
                    'revision_kind' => (string) $course['course_kind'],
                    'revision_schema' => (string) RevisionBuilder::SCHEMA,
                    'revision_sha256' => $build['sha256'],
                    'revision_languages' => implode(',', $build['languages']),
                    'revision_change_note' => $changeNote,
                    'revision_requires_retraining' => $retrain ? '1' : '0',
                    'revision_retrain_due_days' => $retrainDays === null ? null : (string) $retrainDays,
                    'revision_published_by' => (string) $userId,
                    'revision_published_at_utc' => $publishedAt,
                    'revision_hash_v' => (string) HashSpecs::current('training_revisions'),
                ];
                $row['revision_row_sha256'] = RowHasher::hash('training_revisions', $row);
                $cols = array_keys($row);
                $revisionId = Db::insert($db, 'INSERT INTO training_revisions (' . implode(', ', $cols) . ', revision_json) VALUES ('
                    . implode(', ', array_fill(0, count($cols) + 1, '?')) . ')',
                    str_repeat('s', count($cols) + 1), array_merge(array_values($row), [$build['json']]));

                foreach (array_chunk($doc['media'], 200) as $chunk) {
                    $ph = [];
                    $types = '';
                    $params = [];
                    foreach ($chunk as $m) {
                        $ph[] = '(?, ?, ?, ?)';
                        $types .= 'iisi';
                        array_push($params, $revisionId, (int) $m['id'], (string) $m['sha256'], $m['dl'] ? 1 : 0);
                    }
                    Db::exec($db, 'INSERT INTO training_revision_media (rmedia_revision_id, rmedia_media_id, rmedia_media_sha256, rmedia_downloadable) VALUES '
                        . implode(', ', $ph), $types, $params);
                }

                Db::exec($db, 'UPDATE training_courses SET course_current_revision_id = ? WHERE course_id = ?', 'ii', [$revisionId, $courseId]);
                if ($course['course_draft_updated_at_utc'] === $build['draft_updated_at_utc']) {
                    CourseTouch::settle($db, $courseId, $publishedAt);
                } else {
                    // Someone saved while this version was being prepared: that edit is not in it.
                    CourseTouch::touch($db, $courseId);
                }

                Ledger::append($db, [
                    'type' => 'revision.published',
                    'actor_type' => 'user',
                    'actor_user_id' => $userId,
                    'course_id' => $courseId,
                    'entity_type' => 'revision',
                    'entity_id' => $revisionId,
                    'entity_sha256' => $row['revision_row_sha256'],
                    'user_agent' => $this->c->userAgent,
                    'payload' => [
                        'course_uid' => (string) $course['course_uid'],
                        'number' => $number,
                        'kind' => (string) $course['course_kind'],
                        'revision_sha256' => $build['sha256'],
                        'languages' => $build['languages'],
                        'lesson_count' => count($doc['lessons']),
                        'question_count' => count($doc['questions']),
                        'media_count' => count($doc['media']),
                        'requires_retraining' => $retrain,
                        'retrain_due_days' => $retrainDays,
                    ],
                ]);
                return ['revision_id' => $revisionId, 'number' => $number, 'published_at_utc' => $publishedAt];
            });
        } finally {
            Db::unlock($db, $lock);
        }

        return [
            'revision_id' => $result['revision_id'],
            'number' => $result['number'],
            'sha256' => $build['sha256'],
            'languages' => $build['languages'],
            'published_at' => Clock::toIso($result['published_at_utc'], true),
            'warnings' => $check['warnings'],
        ];
    }

    /**
     * Every manifest file must exist and still hash to its media_sha256; no evidence file may be
     * referenced. Runs before the transaction (hashing large files holds no locks).
     */
    private function verifyFiles(array $build): void
    {
        $db = $this->c->db;
        $store = $this->store ?? new MediaStore($this->c);
        $ids = array_map(static fn($m) => (int) $m['id'], $build['doc']['media']);
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            [$ph, $t, $p] = InList::ints($chunk);
            foreach (Db::all($db, "SELECT media_id, media_sha256, media_kind, media_path FROM training_media WHERE media_id IN ($ph)", $t, $p) as $r) {
                $rows[(int) $r['media_id']] = $r;
            }
        }
        foreach ($build['doc']['media'] as $m) {
            $row = $rows[(int) $m['id']] ?? null;
            if ($row === null) {
                throw new ApiException(422, 'validation', "A file used by this course no longer exists (#{$m['id']}).", [],
                    ['errors' => [['code' => 'media_missing', 'severity' => 'error', 'message' => "A file used by this course no longer exists (#{$m['id']})."]]]);
            }
            if ($row['media_kind'] === 'evidence' || $m['kind'] === 'evidence') {
                throw new ApiException(422, 'media_evidence_ref', 'A learner evidence file cannot be part of course content.');
            }
            try {
                $path = $store->absolutePath($row);
            } catch (\Throwable) {
                $path = null;
            }
            $sha = ($path !== null && is_file($path)) ? @hash_file('sha256', $path) : false;
            if ($sha === false) {
                throw new ApiException(422, 'validation', "A file used by this course is missing from storage (#{$m['id']}).", [],
                    ['errors' => [['code' => 'media_missing', 'severity' => 'error', 'message' => "A file used by this course is missing from storage (#{$m['id']})."]]]);
            }
            if (!hash_equals((string) $row['media_sha256'], $sha) || !hash_equals((string) $m['sha256'], $sha)) {
                throw new ApiException(422, 'validation', "A file used by this course has changed on disk (#{$m['id']}).", [],
                    ['errors' => [['code' => 'media_hash_mismatch', 'severity' => 'error', 'message' => "A file used by this course has changed on disk (#{$m['id']})."]]]);
            }
        }
    }
}
