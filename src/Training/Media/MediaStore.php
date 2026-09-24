<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\MediaUsage;
use ITFlow\Training\Core\RowHasher;
use ITFlow\Training\Core\Text;

/**
 * Content-addressed storage for training media (spec §3.3, §4.2).
 *
 *   uploads/training/content/<aa>/<sha256>.<ext>      dirs 0750, files 0640 (www-data)
 *
 * The same bytes and kind always resolve to the same training_media row (unique
 * (media_sha256, media_kind)); a row is insert-only and hashed (HashSpecs v1), and every new
 * row is announced by a `media.stored` ledger event in the SAME transaction. Files are deleted
 * only by MediaPurger (a `media.file_purged` event); uploading the same bytes again brings the
 * file back with `media.file_restored`.
 *
 * INGEST NEVER RUNS INSIDE A TRANSACTION (asserts Db::depth() === 0): it does file I/O, may wait
 * up to 10 s for the storage lock, and opens its own transaction to append to the ledger. Callers
 * ingest first and then open their write transaction with the returned media_id.
 *
 * Sequence (spec §3.3):
 *   1 sha = hash of the source. A row for (sha, kind) whose file is on disk and not purged is
 *     returned as deduped - confirmed under the 'trmedia' lock, so it cannot race a purge that
 *     is deleting that very file.
 *   2 copy (hashing again) to <dir>/.<rand>.tmp, outside any lock
 *   3 Db::lock('trmedia', 10); re-check the row (another request may have stored it meanwhile);
 *     budget: live bytes (every row minus purged ones, Core\MediaUsage) + bytes <= budget, else
 *     unlink the tmp file and 413 budget_exceeded
 *   4 rename tmp -> final (unlink tmp if final exists), 0640
 *   5 Db::tx { INSERT row ; Ledger 'media.stored' }; a 1062 (only possible for a writer that
 *     bypassed the lock) re-reads the committed row with LOCK IN SHARE MODE and returns it deduped;
 *     unlock in finally.
 *
 * The root defaults to <app>/uploads/training, derived from this file's location - never from
 * DOCUMENT_ROOT, which is not set on the CLI. Tests pass a scratch directory.
 */
final class MediaStore
{
    public const PATH_RE = '#^(content|evidence)/[0-9a-f]{2}/[0-9a-f]{64}\.(pdf|jpg|png|webp|gif|mp4|docx|xlsx|pptx|txt|csv)$#D';

    /** Kinds Phase 1 stores. 'evidence' belongs to the Phase 2 evidence pipeline and is never served. */
    public const KINDS = ['pdf', 'page', 'video', 'image', 'file'];

    /**
     * Every kind ingest() accepts (validateType only): KINDS plus Phase 2 'evidence' scans
     * (Records\EvidenceStore). KINDS itself stays Phase 1's list, so MediaAccess and the purger
     * never treat evidence as servable or purgeable (Phase 2 spec §3.5).
     */
    public const STORE_KINDS = [...self::KINDS, 'evidence'];

    /** The extensions each kind may carry. */
    public const KIND_EXTS = [
        'pdf' => ['pdf'],
        'page' => ['jpg'],
        'video' => ['mp4'],
        'image' => ['jpg', 'png', 'webp', 'gif'],
        'file' => ['docx', 'xlsx', 'pptx', 'txt', 'csv'],
        'evidence' => ['pdf', 'jpg', 'png'],
    ];

    /** Stored MIME per extension (also the serving Content-Type, see agent/training_media.php). */
    public const MIME_BY_EXT = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
    ];

    public const LOCK = 'trmedia';
    public const LOCK_TIMEOUT_S = 10;

    public const COLUMNS = 'media_id, media_sha256, media_kind, media_mime, media_ext, media_bytes, media_path, media_original_name,
        media_width, media_height, media_page_count, media_duration_ms, media_video_codec, media_audio_codec, media_faststart,
        media_uploaded_by, media_created_at_utc, media_hash_v, media_row_sha256';

    private string $root;
    private static ?string $defaultRootOverride = null;

    public function __construct(private readonly Ctx $c, ?string $root = null)
    {
        $this->root = rtrim($root ?? self::$defaultRootOverride ?? dirname(__DIR__, 3) . '/uploads/training', '/');
    }

    /**
     * TEST SEAM ONLY - production code never calls this. Points every MediaStore constructed
     * without an explicit root (the endpoints and services build their own) at a scratch
     * directory, so a CLI or HTTP test never writes into the application's uploads/.
     */
    public static function useDefaultRoot(?string $root): void
    {
        self::$defaultRootOverride = $root;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function ctx(): Ctx
    {
        return $this->c;
    }

    /**
     * Stores a file already on disk (e.g. an upload's tmp name). The source is copied, never moved.
     *
     * @param array{width?:?int, height?:?int, page_count?:?int, duration_ms?:?int, video_codec?:?string,
     *              audio_codec?:?string, faststart?:?bool} $meta
     * @return array<string, mixed> the media row plus 'deduped' => bool and 'restored' => bool
     * @throws MediaException 413 budget_exceeded, 409 busy
     */
    public function ingestFile(string $path, string $kind, string $mime, string $ext, ?string $originalName, array $meta = [], string $purpose = ''): array
    {
        self::assertOutsideTx();
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('MediaStore::ingestFile: source is not a readable file');
        }
        $sha = hash_file('sha256', $path);
        $size = filesize($path);
        if ($sha === false || $size === false) {
            throw new \RuntimeException('MediaStore: cannot read the source file');
        }
        return $this->ingest($sha, (int) $size, static function ($out) use ($path, $sha): void {
            $in = fopen($path, 'rb');
            if ($in === false) {
                throw new \RuntimeException('MediaStore: cannot open the source file');
            }
            try {
                $ctx = hash_init('sha256');
                while (!feof($in)) {
                    $chunk = fread($in, 1048576);
                    if ($chunk === false) {
                        throw new \RuntimeException('MediaStore: read failed');
                    }
                    hash_update($ctx, $chunk);
                    if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                        throw new \RuntimeException('MediaStore: write failed (disk full?)');
                    }
                }
                if (!hash_equals($sha, hash_final($ctx))) {
                    throw new \RuntimeException('MediaStore: the source changed while it was being stored');
                }
            } finally {
                fclose($in);
            }
        }, $kind, $mime, $ext, $originalName, $meta, $purpose);
    }

    /** As ingestFile() for bytes in memory (re-encoded images, rendered pages, thumbnails). */
    public function ingestBytes(string $bytes, string $kind, string $mime, string $ext, ?string $originalName, array $meta = [], string $purpose = ''): array
    {
        self::assertOutsideTx();
        if ($bytes === '') {
            throw new \InvalidArgumentException('MediaStore::ingestBytes: empty content');
        }
        $sha = hash('sha256', $bytes);
        return $this->ingest($sha, strlen($bytes), static function ($out) use ($bytes): void {
            if (fwrite($out, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('MediaStore: write failed (disk full?)');
            }
        }, $kind, $mime, $ext, $originalName, $meta, $purpose);
    }

    /** @return array<string, mixed>|null the row (prepared-statement types), or null */
    public function get(int $mediaId): ?array
    {
        if ($mediaId < 1) {
            return null;
        }
        return Db::one($this->c->db, 'SELECT ' . self::COLUMNS . ' FROM training_media WHERE media_id = ?', 'i', [$mediaId]);
    }

    /** The kind of a media id, or null when it does not exist. */
    public function kindOf(int $mediaId): ?string
    {
        if ($mediaId < 1) {
            return null;
        }
        $row = Db::one($this->c->db, 'SELECT media_kind FROM training_media WHERE media_id = ?', 'i', [$mediaId]);
        return $row === null ? null : (string) $row['media_kind'];
    }

    /** A memoised kind lookup for ArticleSanitizer::purify(). */
    public function kindLookup(): \Closure
    {
        $cache = [];
        return function (int $id) use (&$cache): ?string {
            if (!array_key_exists($id, $cache)) {
                $cache[$id] = $this->kindOf($id);
            }
            return $cache[$id];
        };
    }

    /** Absolute path of a row's file. Throws unless media_path matches PATH_RE. */
    public function absolutePath(array $row): string
    {
        $path = (string) ($row['media_path'] ?? '');
        if (preg_match(self::PATH_RE, $path) !== 1) {
            throw new \UnexpectedValueException('MediaStore: invalid media_path');
        }
        return $this->root . '/' . $path;
    }

    /** True when the row's file is on disk with the recorded size. */
    public function fileOk(array $row): bool
    {
        try {
            $abs = $this->absolutePath($row);
        } catch (\UnexpectedValueException) {
            return false;
        }
        clearstatcache(true, $abs);
        return is_file($abs) && !is_link($abs) && (int) filesize($abs) === (int) $row['media_bytes'];
    }

    public static function url(int $mediaId, bool $download = false): string
    {
        return '/agent/training_media.php?m=' . $mediaId . ($download ? '&dl=1' : '');
    }

    /**
     * The ids among $mediaIds whose file is purged: their LATEST file event (media.file_purged /
     * media.file_restored) is a purge. One query for any number of ids.
     *
     * @param list<int> $mediaIds
     * @return array<int, true>
     */
    public static function purgedIds(\mysqli $db, array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn(int $i): bool => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::all($db, "SELECT e.tevent_entity_id AS mid FROM training_events e
                JOIN (SELECT tevent_entity_id AS mid, MAX(tevent_seq) AS last_seq FROM training_events
                      WHERE tevent_entity_type = 'media' AND tevent_type IN ('media.file_purged', 'media.file_restored')
                        AND tevent_entity_id IN ($in)
                      GROUP BY tevent_entity_id) f ON f.last_seq = e.tevent_seq
                WHERE e.tevent_type = 'media.file_purged'", str_repeat('i', count($chunk)), $chunk);
            foreach ($rows as $r) {
                $out[(int) $r['mid']] = true;
            }
        }
        return $out;
    }

    /** The type of the latest file event (stored / purged / restored) for a media id. */
    public function latestFileEvent(int $mediaId): ?string
    {
        $row = Db::one($this->c->db, "SELECT tevent_type FROM training_events
            WHERE tevent_entity_type = 'media' AND tevent_entity_id = ?
              AND tevent_type IN ('media.stored', 'media.file_purged', 'media.file_restored')
            ORDER BY tevent_seq DESC LIMIT 1", 'i', [$mediaId]);
        return $row === null ? null : (string) $row['tevent_type'];
    }

    /**
     * The shared `Media` JSON shape (spec §6.1) for a row.
     *
     * @param list<string> $warnings extra warning codes gathered at upload time
     * @param list<string> $info     extra info codes gathered at upload time
     */
    public function toApi(array $row, array $warnings = [], array $info = []): array
    {
        $id = (int) $row['media_id'];
        $kind = (string) $row['media_kind'];
        if ($kind === 'video') {
            if (in_array((string) $row['media_video_codec'], ['hvc1', 'hev1'], true) && !in_array('hevc', $warnings, true)) {
                $warnings[] = 'hevc';
            }
            if ($row['media_faststart'] !== null && (int) $row['media_faststart'] === 0 && !in_array('not_faststart', $info, true)) {
                $info[] = 'not_faststart';
            }
        }
        $pagesReady = null;
        if ($kind === 'pdf') {
            // Pages whose file was purged are not ready: they are rendered again (PdfRenderService).
            $pagesReady = PdfRenderService::readyCounts($this->c->db, [$id])[$id] ?? 0;
        }
        $int = static fn($v): ?int => $v === null ? null : (int) $v;
        return [
            'id' => $id,
            'kind' => $kind,
            'mime' => (string) $row['media_mime'],
            'ext' => (string) $row['media_ext'],
            'bytes' => (int) $row['media_bytes'],
            'sha256' => (string) $row['media_sha256'],
            'url' => self::url($id),
            'download_url' => self::url($id, true),
            'original_name' => $row['media_original_name'] === null ? null : (string) $row['media_original_name'],
            'width' => $int($row['media_width']),
            'height' => $int($row['media_height']),
            'page_count' => $int($row['media_page_count']),
            'pages_ready' => $pagesReady,
            'duration_ms' => $int($row['media_duration_ms']),
            'video_codec' => $row['media_video_codec'] === null ? null : (string) $row['media_video_codec'],
            'audio_codec' => $row['media_audio_codec'] === null ? null : (string) $row['media_audio_codec'],
            'faststart' => $row['media_faststart'] === null ? null : ((int) $row['media_faststart'] === 1),
            'warnings' => array_values(array_unique($warnings)),
            'info' => array_values(array_unique($info)),
        ];
    }

    public static function assertOutsideTx(): void
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('MediaStore::ingest* must not run inside Db::tx (ingest first, then open the write transaction)');
        }
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param callable(resource):void $writeTo writes the content into an open handle (and verifies its sha)
     */
    private function ingest(string $sha, int $size, callable $writeTo, string $kind, string $mime, string $ext,
                            ?string $originalName, array $meta, string $purpose): array
    {
        $this->validateType($kind, $mime, $ext);
        if (preg_match('/^[a-z0-9_]{0,40}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('MediaStore: bad purpose');
        }
        // Evidence scans live under evidence/ (nginx: deny all; streamed only by
        // agent/training_evidence.php after Records\EvidenceStore::canServe).
        $relPath = ($kind === 'evidence' ? 'evidence/' : 'content/') . substr($sha, 0, 2) . '/' . $sha . '.' . $ext;
        $final = $this->root . '/' . $relPath;
        $name = self::cleanName($originalName);
        $metaCols = self::metaColumns($meta);

        // 1 - fast path: already stored and live. Confirmed under the lock (see class comment).
        $existing = $this->find($sha, $kind);
        if ($existing !== null && $this->fileOk($existing)) {
            $this->lockOrBusy();
            try {
                if ($this->fileOk($existing) && $this->latestFileEvent((int) $existing['media_id']) !== 'media.file_purged') {
                    return $this->result($existing, true, false);
                }
            } finally {
                Db::unlock($this->c->db, self::LOCK);
            }
        }

        // 2 - copy to a tmp file next to the final location, outside any lock.
        $dir = dirname($final);
        $this->ensureDir($dir);
        $tmp = $dir . '/.' . bin2hex(random_bytes(12)) . '.tmp';
        $old = umask(0027);
        try {
            $out = @fopen($tmp, 'xb');
        } finally {
            umask($old);
        }
        if ($out === false) {
            throw new \RuntimeException('MediaStore: cannot create a temporary file in the media directory');
        }
        try {
            try {
                $writeTo($out);
                if (!fflush($out)) {
                    throw new \RuntimeException('MediaStore: write failed (disk full?)');
                }
            } finally {
                fclose($out);
            }
            @chmod($tmp, 0640);

            // 3 - storage lock: re-check, budget, rename, insert + event.
            $this->lockOrBusy();
            try {
                $existing = $this->find($sha, $kind);
                if ($existing !== null) {
                    return $this->reuseLocked($existing, $tmp, $final);
                }
                if ($kind !== 'evidence') {
                    // Evidence has its own per-file cap (config_training_evidence_max_mb) and is
                    // not counted toward the content budget (MediaUsage::liveBytes excludes it).
                    $this->checkBudget($size);
                }

                $createdFile = !is_file($final);
                if ($createdFile) {
                    if (!@rename($tmp, $final)) {
                        throw new \RuntimeException('MediaStore: cannot move the file into place');
                    }
                    @chmod($final, 0640);
                }

                try {
                    $row = Db::tx($this->c->db, function () use ($sha, $kind, $mime, $ext, $size, $relPath, $name, $metaCols, $purpose): array {
                        return $this->insertRow($sha, $kind, $mime, $ext, $size, $relPath, $name, $metaCols, $purpose);
                    });
                    return $this->result($row, false, false);
                } catch (\mysqli_sql_exception $e) {
                    if ((int) $e->getCode() === 1062) {
                        // A locking read sees the committed row the snapshot of a plain read might not.
                        $dup = Db::one($this->c->db, 'SELECT ' . self::COLUMNS . ' FROM training_media WHERE media_sha256 = ? AND media_kind = ? LOCK IN SHARE MODE',
                            'ss', [$sha, $kind]);
                        if ($dup !== null) {
                            return $this->result($dup, true, false);
                        }
                    }
                    $this->dropOrphan($createdFile, $final, $relPath);
                    throw $e;
                } catch (\Throwable $e) {
                    $this->dropOrphan($createdFile, $final, $relPath);
                    throw $e;
                }
            } finally {
                Db::unlock($this->c->db, self::LOCK);
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Existing row under the lock: return it, restoring its file (and its live status) if it was purged. */
    private function reuseLocked(array $existing, string $tmp, string $final): array
    {
        $id = (int) $existing['media_id'];
        $purged = $this->latestFileEvent($id) === 'media.file_purged';
        $fileOk = $this->fileOk($existing);
        if ($fileOk && !$purged) {
            return $this->result($existing, true, false);
        }
        if ($purged && (string) $existing['media_kind'] !== 'evidence') {
            // The file comes back into the live total (evidence is never counted, nor purged).
            $this->checkBudget((int) $existing['media_bytes']);
        }
        if (!$fileOk) {
            $target = $this->absolutePath($existing);
            if ($target !== $final) {
                $this->ensureDir(dirname($target));
            }
            if (!@rename($tmp, $target)) {
                throw new \RuntimeException('MediaStore: cannot restore the file');
            }
            @chmod($target, 0640);
        }
        if ($purged) {
            Db::tx($this->c->db, function () use ($existing, $id): void {
                Ledger::append($this->c->db, [
                    'type' => 'media.file_restored',
                    'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
                    'entity_type' => 'media',
                    'entity_id' => $id,
                    'entity_sha256' => (string) $existing['media_row_sha256'],
                    'payload' => ['content_sha256' => (string) $existing['media_sha256'], 'bytes' => (int) $existing['media_bytes'], 'reason' => 'uploaded again'],
                    'user_agent' => $this->c->userAgent,
                ]);
            });
        }
        return $this->result($existing, true, true);
    }

    private function insertRow(string $sha, string $kind, string $mime, string $ext, int $size, string $relPath,
                               ?string $name, array $metaCols, string $purpose): array
    {
        $db = $this->c->db;
        Db::ensureUtf8mb4($db);
        // Text-protocol shape: exactly what a plain SELECT hands the verifier back.
        $row = [
            'media_sha256' => $sha,
            'media_kind' => $kind,
            'media_mime' => $mime,
            'media_ext' => $ext,
            'media_bytes' => (string) $size,
            'media_path' => $relPath,
            'media_original_name' => $name,
            'media_width' => $metaCols['media_width'],
            'media_height' => $metaCols['media_height'],
            'media_page_count' => $metaCols['media_page_count'],
            'media_duration_ms' => $metaCols['media_duration_ms'],
            'media_video_codec' => $metaCols['media_video_codec'],
            'media_audio_codec' => $metaCols['media_audio_codec'],
            'media_faststart' => $metaCols['media_faststart'],
            'media_uploaded_by' => (string) max(0, $this->c->userId),
            'media_created_at_utc' => Clock::nowUtc(),
            'media_hash_v' => '1',
        ];
        $row['media_row_sha256'] = RowHasher::hash('training_media', $row);
        $cols = array_keys($row);
        $id = Db::insert($db,
            'INSERT INTO training_media (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            str_repeat('s', count($cols)), array_values($row));

        Ledger::append($db, [
            'type' => 'media.stored',
            'actor_user_id' => $this->c->userId > 0 ? $this->c->userId : null,
            'entity_type' => 'media',
            'entity_id' => $id,
            'entity_sha256' => $row['media_row_sha256'],
            'payload' => ['kind' => $kind, 'content_sha256' => $sha, 'bytes' => $size, 'ext' => $ext, 'purpose' => $purpose],
            'user_agent' => $this->c->userAgent,
        ]);
        return ['media_id' => $id] + $row;
    }

    private function result(array $row, bool $deduped, bool $restored): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = $v;
        }
        $out['media_id'] = (int) $row['media_id'];
        $out['deduped'] = $deduped;
        $out['restored'] = $restored;
        return $out;
    }

    private function find(string $sha, string $kind): ?array
    {
        return Db::one($this->c->db, 'SELECT ' . self::COLUMNS . ' FROM training_media WHERE media_sha256 = ? AND media_kind = ?', 'ss', [$sha, $kind]);
    }

    private function checkBudget(int $bytes): void
    {
        $live = MediaUsage::liveBytes($this->c->db);
        $budget = $this->c->settings->budgetBytes;
        if ($live + $bytes > $budget) {
            $mb = static fn(int $b): string => number_format($b / 1048576, 0);
            throw new MediaException(413, 'budget_exceeded',
                'Training media storage is full (' . $mb($live) . ' MB of ' . $mb($budget) . ' MB used). '
                . 'An administrator can raise the limit or purge unused files in Admin › Training.');
        }
    }

    private function lockOrBusy(): void
    {
        if (!Db::lock($this->c->db, self::LOCK, self::LOCK_TIMEOUT_S)) {
            throw MediaException::busy('Another upload is being saved. Try again in a moment.');
        }
    }

    /** Removes a file this call created when its row did not get written (and no row names it). */
    private function dropOrphan(bool $createdFile, string $final, string $relPath): void
    {
        if (!$createdFile || !is_file($final)) {
            return;
        }
        try {
            $ref = Db::one($this->c->db, 'SELECT media_id FROM training_media WHERE media_path = ? LIMIT 1', 's', [$relPath]);
        } catch (\Throwable) {
            return;   // cannot prove it is unused: leave it (content-addressed, harmless)
        }
        if ($ref === null) {
            @unlink($final);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $old = umask(0027);
        try {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('MediaStore: cannot create the media directory');
            }
        } finally {
            umask($old);
        }
    }

    private function validateType(string $kind, string $mime, string $ext): void
    {
        if (!in_array($kind, self::STORE_KINDS, true)) {
            throw new \InvalidArgumentException("MediaStore: kind '$kind' cannot be stored here");
        }
        if (!in_array($ext, self::KIND_EXTS[$kind], true)) {
            throw new \InvalidArgumentException("MediaStore: .$ext is not a valid $kind");
        }
        if ((self::MIME_BY_EXT[$ext] ?? null) !== $mime) {
            throw new \InvalidArgumentException("MediaStore: MIME $mime does not match .$ext");
        }
    }

    /** Display name only (never a path): last path segment, control characters removed, 255 chars. */
    private static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = mb_scrub($name, 'UTF-8');
        $name = preg_replace('#^.*[/\\\\]#u', '', $name) ?? '';
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);
        return $name === '' ? null : Text::clip($name, 255);
    }

    /** @return array<string, ?string> the meta columns in text-protocol shape */
    private static function metaColumns(array $meta): array
    {
        $u16 = static fn($v): ?string => (is_int($v) && $v > 0 && $v <= 65535) ? (string) $v : null;
        $u32 = static fn($v): ?string => (is_int($v) && $v >= 0 && $v <= 4294967295) ? (string) $v : null;
        $codec = static fn($v): ?string => (is_string($v) && preg_match('/^[A-Za-z0-9 ._-]{1,8}$/D', $v) === 1) ? $v : null;
        $fs = $meta['faststart'] ?? null;
        return [
            'media_width' => $u16($meta['width'] ?? null),
            'media_height' => $u16($meta['height'] ?? null),
            'media_page_count' => $u16($meta['page_count'] ?? null),
            'media_duration_ms' => $u32($meta['duration_ms'] ?? null),
            'media_video_codec' => $codec($meta['video_codec'] ?? null),
            'media_audio_codec' => $codec($meta['audio_codec'] ?? null),
            'media_faststart' => is_bool($fs) ? ($fs ? '1' : '0') : null,
        ];
    }
}
