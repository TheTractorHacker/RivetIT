<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;

/**
 * Admin › Training "purge unreferenced media": frees budget without breaking the audit trail
 * (spec §3.3). A purge deletes only the FILE. The training_media row, its hash and its
 * media.stored event stay, and the deletion itself is a `media.file_purged` ledger event that the
 * deep verifier honours. Uploading the same bytes later restores the file (media.file_restored).
 *
 * A media id is REFERENCED - and never offered or purged - when any of these name it:
 *   - a published revision's manifest (training_revision_media; kept forever)
 *   - a lesson variant (lvar_media_id, lvar_caption_media_id) or the HTML of a lesson variant (body/description),
 *     a course description, or any translated course/section/… text (training_i18n)
 *   - a lesson thumbnail, a course cover, a learning-path cover
 *   - a question image (neutral or per-language), a lesson resource, a video-check thumbnail
 *   - the page images of a referenced PDF
 * Drafts count whatever their state: an archived course, a deleted (restorable) lesson or an
 * archived question can come back, so their media stays. Evidence is never purgeable, media
 * younger than $minAgeDays is never offered (an upload whose lesson is not saved yet looks
 * unreferenced), and a file shared by another live row is never deleted.
 *
 * RACES. purge() re-checks every id under the same 'trmedia' lock MediaStore takes to store or
 * de-duplicate a file, immediately before deleting it: an id that became referenced since the
 * list was drawn (or since the admin's form was rendered) is skipped, and a concurrent upload of
 * the same bytes either completes before the check (the file is then restored by that upload
 * or seen as in use) or waits for the purge and restores the file afterwards.
 */
final class MediaPurger
{
    public const MIN_AGE_DAYS = 7;

    private MediaStore $store;

    public function __construct(private readonly Ctx $c, ?MediaStore $store = null)
    {
        $this->store = $store ?? new MediaStore($c);
    }

    /**
     * Files that can be purged now.
     *
     * @return list<array{media_id:int, media_kind:string, media_bytes:int, media_original_name:?string,
     *                    media_ext:string, media_sha256:string, media_created_at_utc:string}>
     */
    public function unreferenced(int $minAgeDays = self::MIN_AGE_DAYS): array
    {
        $this->assertAdmin();
        $cutoff = $this->cutoff($minAgeDays);
        $referenced = $this->referencedIds(null);
        $rows = Db::all($this->c->db, "SELECT m.media_id, m.media_kind, m.media_bytes, m.media_original_name, m.media_ext,
                m.media_sha256, m.media_created_at_utc
            FROM training_media m
            LEFT JOIN (
                SELECT tevent_entity_id AS mid, MAX(tevent_seq) AS last_seq FROM training_events
                WHERE tevent_entity_type = 'media' AND tevent_type IN ('media.file_purged', 'media.file_restored')
                GROUP BY tevent_entity_id
            ) f ON f.mid = m.media_id
            LEFT JOIN training_events le ON le.tevent_seq = f.last_seq
            WHERE m.media_kind <> 'evidence' AND m.media_created_at_utc < ?
              AND (le.tevent_type IS NULL OR le.tevent_type <> 'media.file_purged')
            ORDER BY m.media_id", 's', [$cutoff]);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['media_id'];
            if (isset($referenced[$id])) {
                continue;
            }
            $out[] = [
                'media_id' => $id,
                'media_kind' => (string) $r['media_kind'],
                'media_bytes' => (int) $r['media_bytes'],
                'media_original_name' => $r['media_original_name'] === null ? null : (string) $r['media_original_name'],
                'media_ext' => (string) $r['media_ext'],
                'media_sha256' => (string) $r['media_sha256'],
                'media_created_at_utc' => (string) $r['media_created_at_utc'],
            ];
        }
        return $out;
    }

    /**
     * Deletes the files of $mediaIds that are STILL unreferenced, one ledger event each.
     *
     * @param list<int> $mediaIds
     * @return array{purged:list<int>, skipped:list<array{media_id:int, reason:string}>, bytes:int}
     *   skip reasons: not_found | evidence | too_new | in_use | already_purged | shared_file
     * @throws MediaException 409 busy (storage lock), 422 validation (reason)
     */
    public function purge(array $mediaIds, string $reason, int $minAgeDays = self::MIN_AGE_DAYS): array
    {
        $this->assertAdmin();
        MediaStore::assertOutsideTx();
        $reason = trim($reason);
        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw MediaException::validation('reason', 'Type a reason (5 to 500 characters).');
        }
        $ids = [];
        foreach ($mediaIds as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $result = ['purged' => [], 'skipped' => [], 'bytes' => 0];
        if ($ids === []) {
            return $result;
        }
        $cutoff = $this->cutoff($minAgeDays);

        if (!Db::lock($this->c->db, MediaStore::LOCK, MediaStore::LOCK_TIMEOUT_S)) {
            throw MediaException::busy('Media storage is busy (an upload is being saved). Try again in a moment.');
        }
        try {
            foreach ($ids as $id) {
                // Re-check EVERYTHING for this id now, under the lock (the list may be stale).
                $row = $this->store->get($id);
                $skip = match (true) {
                    $row === null => 'not_found',
                    $row['media_kind'] === 'evidence' || !in_array($row['media_kind'], MediaStore::KINDS, true) => 'evidence',
                    (string) $row['media_created_at_utc'] >= $cutoff => 'too_new',
                    $this->store->latestFileEvent($id) === 'media.file_purged' => 'already_purged',
                    $this->referencedIds([$id]) !== [] => 'in_use',
                    $this->sharedWithLiveRow($row) => 'shared_file',
                    default => null,
                };
                if ($skip !== null) {
                    $result['skipped'][] = ['media_id' => $id, 'reason' => $skip];
                    continue;
                }
                $this->purgeOne($row, $reason);
                $result['purged'][] = $id;
                $result['bytes'] += (int) $row['media_bytes'];
            }
        } finally {
            Db::unlock($this->c->db, MediaStore::LOCK);
        }
        return $result;
    }

    /**
     * Ids referenced anywhere (see the class comment). With $only, checks just those ids.
     *
     * @param list<int>|null $only
     * @return array<int, true>
     */
    public function referencedIds(?array $only): array
    {
        $db = $this->c->db;
        if ($only !== null) {
            $only = array_values(array_filter(array_map('intval', $only), static fn(int $i): bool => $i > 0));
            if ($only === []) {
                return [];
            }
        }
        $in = static function (string $col) use ($only): array {
            if ($only === null) {
                return ["$col IS NOT NULL", '', []];
            }
            return ["$col IN (" . implode(',', array_fill(0, count($only), '?')) . ')', str_repeat('i', count($only)), $only];
        };

        $sources = [
            ['training_revision_media', 'rmedia_media_id'],
            ['training_lesson_variants', 'lvar_media_id'],
            ['training_lessons', 'lesson_thumb_media_id'],
            ['training_courses', 'course_cover_media_id'],
            ['training_paths', 'tpath_cover_media_id'],
            ['training_questions', 'question_media_id'],
            ['training_question_texts', 'qtext_media_id'],
            ['training_lesson_resources', 'lres_media_id'],
            ['training_video_checks', 'vcheck_thumb_media_id'],
        ];
        if (Captions::schemaReady($db)) {
            $sources[] = ['training_lesson_variants', 'lvar_caption_media_id'];   // a video's caption file (DB 2.6.98)
        }
        $ref = [];
        foreach ($sources as [$table, $col]) {
            [$where, $types, $params] = $in($col);
            foreach (Db::all($db, "SELECT DISTINCT $col AS id FROM $table WHERE $where", $types, $params) as $r) {
                $ref[(int) $r['id']] = true;
            }
        }

        // Media embedded in authored HTML (images, and links to files) - wherever it is stored.
        $htmlSources = [
            ['training_lesson_variants', ['lvar_body_html', 'lvar_description_html']],
            ['training_courses', ['course_description_html', 'course_attestation_text']],
            ['training_i18n', ['ti18n_value']],
        ];
        $wanted = $only === null ? null : array_fill_keys($only, true);
        foreach ($htmlSources as [$table, $cols]) {
            foreach ($cols as $col) {
                $rows = Db::all($db, "SELECT $col AS h FROM $table WHERE $col LIKE ?", 's', ['%training_media.php%']);
                foreach ($rows as $r) {
                    foreach (ArticleMediaRefs::extract((string) $r['h']) as $mid) {
                        if ($wanted === null || isset($wanted[$mid])) {
                            $ref[$mid] = true;
                        }
                    }
                }
            }
        }

        // Pages of a referenced PDF (a page may itself be referenced directly too, e.g. as a thumbnail).
        $pdfIds = [];
        if ($ref !== []) {
            foreach (array_chunk(array_keys($ref), 500) as $chunk) {
                $rows = Db::all($db, 'SELECT media_id FROM training_media WHERE media_kind = \'pdf\' AND media_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk);
                foreach ($rows as $r) {
                    $pdfIds[] = (int) $r['media_id'];
                }
            }
        }
        foreach (array_chunk($pdfIds, 500) as $chunk) {
            $sql = 'SELECT mpage_media_id FROM training_media_pages WHERE mpage_pdf_media_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')';
            foreach (Db::all($db, $sql, str_repeat('i', count($chunk)), $chunk) as $r) {
                $mid = (int) $r['mpage_media_id'];
                if ($wanted === null || isset($wanted[$mid])) {
                    $ref[$mid] = true;
                }
            }
        }
        // $only mode: a page is also referenced when ANY of its PDFs is referenced, even if that
        // PDF was not in $only - resolve its parents explicitly.
        if ($only !== null) {
            $sql = 'SELECT DISTINCT mpage_pdf_media_id AS pdf, mpage_media_id AS page FROM training_media_pages WHERE mpage_media_id IN ('
                . implode(',', array_fill(0, count($only), '?')) . ')';
            $parents = Db::all($db, $sql, str_repeat('i', count($only)), $only);
            if ($parents !== []) {
                $parentRefs = $this->referencedIds(array_values(array_unique(array_map(static fn($p) => (int) $p['pdf'], $parents))));
                foreach ($parents as $p) {
                    if (isset($parentRefs[(int) $p['pdf']])) {
                        $ref[(int) $p['page']] = true;
                    }
                }
            }
            $ref = array_intersect_key($ref, array_fill_keys($only, true));
        }
        return $ref;
    }

    // ---------------------------------------------------------------------------------------

    private function purgeOne(array $row, string $reason): void
    {
        $id = (int) $row['media_id'];
        $abs = $this->store->absolutePath($row);
        // Move the file aside first, so a failed ledger write can put it back: the file is never
        // gone without its media.file_purged event. The tombstone name matches the `.*.tmp` sweep.
        $tomb = null;
        if (is_file($abs)) {
            $tomb = dirname($abs) . '/.' . bin2hex(random_bytes(12)) . '.purge.tmp';
            if (!@rename($abs, $tomb)) {
                throw new \RuntimeException("MediaPurger: cannot remove the file of media #$id");
            }
        }
        try {
            Db::tx($this->c->db, function () use ($row, $id, $reason): void {
                Ledger::append($this->c->db, [
                    'type' => 'media.file_purged',
                    'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
                    'entity_type' => 'media',
                    'entity_id' => $id,
                    'entity_sha256' => (string) $row['media_row_sha256'],
                    'payload' => ['content_sha256' => (string) $row['media_sha256'], 'bytes' => (int) $row['media_bytes'], 'reason' => $reason],
                    'user_agent' => $this->c->userAgent,
                ]);
            });
        } catch (\Throwable $e) {
            if ($tomb !== null && !is_file($abs)) {
                @rename($tomb, $abs);
            }
            throw $e;
        }
        if ($tomb !== null) {
            @unlink($tomb);
        }
    }

    /** Another row (another kind with identical bytes) uses the same file and is still live. */
    private function sharedWithLiveRow(array $row): bool
    {
        $others = Db::all($this->c->db, 'SELECT media_id FROM training_media WHERE media_path = ? AND media_id <> ?',
            'si', [(string) $row['media_path'], (int) $row['media_id']]);
        foreach ($others as $o) {
            if ($this->store->latestFileEvent((int) $o['media_id']) !== 'media.file_purged') {
                return true;
            }
        }
        return false;
    }

    private function cutoff(int $minAgeDays): string
    {
        $days = max(0, $minAgeDays);
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-$days days")->format('Y-m-d H:i:s.v');
    }

    private function assertAdmin(): void
    {
        if (!$this->c->isAdmin) {
            throw new MediaException(403, 'forbidden', 'Only an administrator can purge training media.');
        }
    }
}
