<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashSpecs;
use ITFlow\Training\Core\RowHasher;

/**
 * Read side of the immutable revisions ("Version N" to authors).
 *
 * Hash checks read rows through the TEXT protocol (plain buffered queries, ids cast with
 * intval), exactly like LedgerVerifier: prepared-statement results carry native PHP types and
 * must never be hashed (spec §0 "Hashing"). A revision is "verified" when its row hash
 * re-computes, sha256(revision_json) equals revision_sha256, and its revision.published event
 * carries that row hash.
 */
final class RevisionRepository
{
    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * Newest first: {id, number, kind, languages, change_note, requires_retraining, retrain_due_days,
     * published_by, published_by_name, published_at, sha256, sha12, verified, counts:{lessons, questions, media}}.
     *
     * @return list<array>
     */
    public function list(int $courseId): array
    {
        $db = $this->c->db;
        Db::ensureUtf8mb4($db);
        $cols = implode(', ', array_map(static fn($c) => 'r.' . $c, HashSpecs::selectColumns('training_revisions')));
        $res = $db->query("SELECT $cols, SHA2(r.revision_json, 256) AS json_sha,
                JSON_LENGTH(r.revision_json, '$.lessons') AS n_lessons, JSON_LENGTH(r.revision_json, '$.questions') AS n_questions,
                JSON_LENGTH(r.revision_json, '$.media') AS n_media, u.user_name
            FROM training_revisions r LEFT JOIN users u ON u.user_id = r.revision_published_by
            WHERE r.revision_course_id = " . intval($courseId) . ' ORDER BY r.revision_number DESC');
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
        $events = $this->eventShas($courseId);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['revision_id'];
            $rowOk = $this->rowHashOk($r);
            $jsonOk = hash_equals((string) $r['revision_sha256'], (string) $r['json_sha']);
            $eventOk = isset($events[$id]) && hash_equals($events[$id], (string) $r['revision_row_sha256']);
            $out[] = $this->summary($r) + [
                'verified' => $rowOk && $jsonOk && $eventOk,
                'counts' => ['lessons' => (int) $r['n_lessons'], 'questions' => (int) $r['n_questions'], 'media' => (int) $r['n_media']],
            ];
        }
        return $out;
    }

    /**
     * One revision with its decoded document: summary fields + course_id, schema, doc, json_sha_ok, verified.
     */
    public function get(int $revisionId): array
    {
        $row = $this->fetch('r.revision_id = ' . intval($revisionId));
        if ($row === null) {
            throw ApiException::notFound('That version no longer exists.');
        }
        return $this->full($row);
    }

    /** The course's current (latest published) revision, or null. Includes doc and sha. */
    public function current(int $courseId): ?array
    {
        $c = Db::one($this->c->db, 'SELECT course_current_revision_id FROM training_courses WHERE course_id = ?', 'i', [$courseId]);
        if ($c === null || $c['course_current_revision_id'] === null) {
            return null;
        }
        $row = $this->fetch('r.revision_id = ' . intval($c['course_current_revision_id']) . ' AND r.revision_course_id = ' . intval($courseId));
        return $row === null ? null : $this->full($row);
    }

    /** Head numbers only (no JSON): {id, number, sha256, published_at_utc} of the current revision, or null. */
    public function currentHead(int $courseId): ?array
    {
        $r = Db::one($this->c->db, 'SELECT r.revision_id, r.revision_number, r.revision_sha256, r.revision_published_at_utc
            FROM training_courses c JOIN training_revisions r ON r.revision_id = c.course_current_revision_id
            WHERE c.course_id = ?', 'i', [$courseId]);
        return $r === null ? null : [
            'id' => (int) $r['revision_id'],
            'number' => (int) $r['revision_number'],
            'sha256' => (string) $r['revision_sha256'],
            'published_at_utc' => (string) $r['revision_published_at_utc'],
        ];
    }

    public function nextNumber(int $courseId): int
    {
        $r = Db::one($this->c->db, 'SELECT COALESCE(MAX(revision_number), 0) + 1 AS n FROM training_revisions WHERE revision_course_id = ?', 'i', [$courseId]);
        return (int) ($r['n'] ?? 1);
    }

    /**
     * A text-protocol revision row verifies when its row hash re-computes and, when the row
     * carries revision_json, sha256(revision_json) equals revision_sha256.
     */
    public function verifyRow(array $row): bool
    {
        if (!$this->rowHashOk($row)) {
            return false;
        }
        if (array_key_exists('revision_json', $row)) {
            return hash_equals((string) $row['revision_sha256'], Canonical::sha256((string) $row['revision_json']));
        }
        return true;
    }

    // ------------------------------------------------------------------------------------------

    private function fetch(string $where): ?array
    {
        $db = $this->c->db;
        Db::ensureUtf8mb4($db);
        $cols = implode(', ', array_map(static fn($c) => 'r.' . $c, HashSpecs::selectColumns('training_revisions')));
        $res = $db->query("SELECT $cols, r.revision_json, u.user_name FROM training_revisions r
            LEFT JOIN users u ON u.user_id = r.revision_published_by WHERE $where");
        $row = $res->fetch_assoc();
        $res->free();
        return $row ?: null;
    }

    private function full(array $row): array
    {
        $id = (int) $row['revision_id'];
        $json = (string) $row['revision_json'];
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $events = $this->eventShas((int) $row['revision_course_id']);
        $verified = $this->verifyRow($row) && isset($events[$id]) && hash_equals($events[$id], (string) $row['revision_row_sha256']);
        return $this->summary($row) + [
            'course_id' => (int) $row['revision_course_id'],
            'schema' => (int) $row['revision_schema'],
            'verified' => $verified,
            'doc' => $doc,
        ];
    }

    private function summary(array $r): array
    {
        return [
            'id' => (int) $r['revision_id'],
            'number' => (int) $r['revision_number'],
            'kind' => (string) $r['revision_kind'],
            'languages' => array_values(array_filter(explode(',', (string) $r['revision_languages']))),
            'change_note' => (string) $r['revision_change_note'],
            'requires_retraining' => (int) $r['revision_requires_retraining'] === 1,
            'retrain_due_days' => $r['revision_retrain_due_days'] === null ? null : (int) $r['revision_retrain_due_days'],
            'published_by' => (int) $r['revision_published_by'],
            'published_by_name' => (string) ($r['user_name'] ?? ''),
            'published_at' => Clock::toIso((string) $r['revision_published_at_utc'], true),
            'published_at_utc' => (string) $r['revision_published_at_utc'],
            'sha256' => (string) $r['revision_sha256'],
            'sha12' => substr((string) $r['revision_sha256'], 0, 12),
            'row_sha256' => (string) $r['revision_row_sha256'],
        ];
    }

    private function rowHashOk(array $row): bool
    {
        try {
            return hash_equals((string) $row['revision_row_sha256'], RowHasher::hash('training_revisions', $row));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return array<int, string> revision id => entity_sha256 of its revision.published event */
    private function eventShas(int $courseId): array
    {
        $res = $this->c->db->query("SELECT tevent_entity_id, tevent_entity_sha256 FROM training_events
            WHERE tevent_type = 'revision.published' AND tevent_course_id = " . intval($courseId) . " AND tevent_entity_type = 'revision'");
        $out = [];
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $e) {
            $out[(int) $e['tevent_entity_id']] = (string) $e['tevent_entity_sha256'];
        }
        $res->free();
        return $out;
    }
}
