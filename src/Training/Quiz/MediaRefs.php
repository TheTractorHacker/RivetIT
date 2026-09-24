<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\MediaStore;

/**
 * Read-side helpers for training_media rows referenced by quiz, publish and preview data:
 * batch loading with an explicit column list, the §6.1 `Media` response shape, and the
 * reference-by-kind check (spec §3.4: a question image must be kind `image`, and nothing may
 * ever reference `evidence`).
 */
final class MediaRefs
{
    public const COLS = 'media_id, media_sha256, media_kind, media_mime, media_ext, media_bytes, media_path, media_original_name,
        media_width, media_height, media_page_count, media_duration_ms, media_video_codec, media_audio_codec, media_faststart';

    /** @return array<int, array<string, mixed>> media id => row */
    public static function rows(\mysqli $db, array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0))), 500) as $chunk) {
            [$ph, $t, $p] = InList::ints($chunk);
            foreach (Db::all($db, 'SELECT ' . self::COLS . " FROM training_media WHERE media_id IN ($ph)", $t, $p) as $r) {
                $out[(int) $r['media_id']] = $r;
            }
        }
        return $out;
    }

    /** The §6.1 Media shape for one row (null stays null). */
    public static function api(?array $row, ?int $pagesReady = null): ?array
    {
        if ($row === null) {
            return null;
        }
        $id = (int) $row['media_id'];
        $int = static fn($v) => $v === null ? null : (int) $v;
        return [
            'id' => $id,
            'kind' => (string) $row['media_kind'],
            'mime' => (string) $row['media_mime'],
            'ext' => (string) $row['media_ext'],
            'bytes' => (int) $row['media_bytes'],
            'sha256' => (string) $row['media_sha256'],
            'url' => MediaStore::url($id),
            'download_url' => MediaStore::url($id, true),
            'original_name' => $row['media_original_name'],
            'width' => $int($row['media_width']),
            'height' => $int($row['media_height']),
            'page_count' => $int($row['media_page_count']),
            'pages_ready' => $row['media_kind'] === 'pdf' ? $pagesReady : null,
            'duration_ms' => $int($row['media_duration_ms']),
            'video_codec' => $row['media_video_codec'],
            'audio_codec' => $row['media_audio_codec'],
            'faststart' => $row['media_faststart'] === null ? null : ((int) $row['media_faststart'] === 1),
            'warnings' => [],
            'info' => [],
        ];
    }

    /**
     * 422 unless $mediaId is null or an existing media row of one of $kinds. `evidence` is never
     * accepted, whatever $kinds says.
     */
    public static function assertKind(\mysqli $db, ?int $mediaId, array $kinds, string $field): void
    {
        if ($mediaId === null) {
            return;
        }
        $row = Db::one($db, 'SELECT media_kind FROM training_media WHERE media_id = ?', 'i', [$mediaId]);
        if ($row === null) {
            throw ApiException::validation([$field => 'That file no longer exists. Upload it again.']);
        }
        $kind = (string) $row['media_kind'];
        if ($kind === 'evidence' || !in_array($kind, $kinds, true)) {
            throw ApiException::validation([$field => 'That file is the wrong type here.']);
        }
    }
}
