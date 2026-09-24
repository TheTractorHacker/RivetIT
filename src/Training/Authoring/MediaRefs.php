<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Media\ArticleSanitizer;
use ITFlow\Training\Media\MediaStore;

/**
 * Media references from authoring: kind validation (spec §3.4 table), the §6.1 `Media` shape,
 * PDF page lists and HTML purification at save.
 *
 * Reads training_media / training_media_pages with explicit columns; URLs always come from
 * MediaStore::url(), so every link goes through agent/training_media.php's authorisation.
 */
final class MediaRefs
{
    public const COLS = 'media_id, media_sha256, media_kind, media_mime, media_ext, media_bytes, media_original_name, media_width,
        media_height, media_page_count, media_duration_ms, media_video_codec, media_audio_codec, media_faststart';

    /** Allowed kinds per reference (spec §3.4); evidence is never allowed anywhere. */
    public const KINDS = [
        'document' => ['pdf'],
        'video' => ['video'],
        'image' => ['image'],
        'thumb' => ['image'],
        'cover' => ['image'],
        'resource' => ['file', 'image', 'pdf'],
    ];

    private const KIND_LABELS = [
        'pdf' => 'a PDF', 'video' => 'an MP4 video', 'image' => 'an image', 'file' => 'a file',
    ];

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> media_id => row
     */
    public static function rows(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (Db::all($db, 'SELECT ' . self::COLS . ' FROM training_media WHERE media_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk) as $r) {
                $out[(int) $r['media_id']] = $r;
            }
        }
        return $out;
    }

    /**
     * Rendered page counts per PDF media id.
     *
     * @param list<int> $pdfIds
     * @return array<int, int>
     */
    public static function pagesReady(\mysqli $db, array $pdfIds): array
    {
        $pdfIds = array_values(array_unique(array_filter(array_map('intval', $pdfIds), static fn($i) => $i > 0)));
        $out = [];
        foreach (array_chunk($pdfIds, 500) as $chunk) {
            foreach (Db::all($db, 'SELECT mpage_pdf_media_id, COUNT(*) AS n FROM training_media_pages WHERE mpage_pdf_media_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') GROUP BY mpage_pdf_media_id', str_repeat('i', count($chunk)), $chunk) as $r) {
                $out[(int) $r['mpage_pdf_media_id']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** The §6.1 Media shape. Upload-time warnings/info are not stored, so they are empty here. */
    public static function shape(array $row, ?int $pagesReady = null): array
    {
        $id = (int) $row['media_id'];
        $kind = (string) $row['media_kind'];
        return [
            'id' => $id,
            'kind' => $kind,
            'mime' => (string) $row['media_mime'],
            'ext' => (string) $row['media_ext'],
            'bytes' => (int) $row['media_bytes'],
            'sha256' => (string) $row['media_sha256'],
            'url' => MediaStore::url($id),
            'download_url' => MediaStore::url($id, true),
            'original_name' => $row['media_original_name'] === null ? null : (string) $row['media_original_name'],
            'width' => $row['media_width'] === null ? null : (int) $row['media_width'],
            'height' => $row['media_height'] === null ? null : (int) $row['media_height'],
            'page_count' => $row['media_page_count'] === null ? null : (int) $row['media_page_count'],
            'pages_ready' => $kind === 'pdf' ? (int) ($pagesReady ?? 0) : null,
            'duration_ms' => $row['media_duration_ms'] === null ? null : (int) $row['media_duration_ms'],
            'video_codec' => $row['media_video_codec'],
            'audio_codec' => $row['media_audio_codec'],
            'faststart' => $row['media_faststart'] === null ? null : ((int) $row['media_faststart'] === 1),
            'warnings' => [],
            'info' => [],
        ];
    }

    /** A media id from a request that must exist and be of an allowed kind for $ref (422 otherwise). */
    public static function require(\mysqli $db, int $mediaId, string $ref, string $field): array
    {
        $kinds = self::KINDS[$ref] ?? null;
        if ($kinds === null) {
            throw new \InvalidArgumentException("MediaRefs: unknown reference '$ref'");
        }
        $row = self::rows($db, [$mediaId])[$mediaId] ?? null;
        if ($row === null) {
            throw ApiException::validation([$field => 'That file is no longer available. Upload it again.']);
        }
        $kind = (string) $row['media_kind'];
        if ($kind === 'evidence' || !in_array($kind, $kinds, true)) {
            $want = implode(' or ', array_map(static fn($k) => self::KIND_LABELS[$k] ?? $k, $kinds));
            throw ApiException::validation([$field => "Choose $want here."]);
        }
        return $row;
    }

    /** Rendered pages of a PDF, in page order: [{n, media_id, url, w, h}]. */
    public static function pages(\mysqli $db, int $pdfMediaId): array
    {
        $rows = Db::all(
            $db,
            'SELECT p.mpage_number, p.mpage_media_id, m.media_width, m.media_height
             FROM training_media_pages p JOIN training_media m ON m.media_id = p.mpage_media_id
             WHERE p.mpage_pdf_media_id = ? ORDER BY p.mpage_number',
            'i',
            [$pdfMediaId]
        );
        return array_map(static fn($r) => [
            'n' => (int) $r['mpage_number'],
            'media_id' => (int) $r['mpage_media_id'],
            'url' => MediaStore::url((int) $r['mpage_media_id']),
            'w' => $r['media_width'] === null ? null : (int) $r['media_width'],
            'h' => $r['media_height'] === null ? null : (int) $r['media_height'],
        ], $rows);
    }

    /** Media id of page 1 of each PDF (auto thumbnails). @return array<int, int> */
    public static function firstPages(\mysqli $db, array $pdfIds): array
    {
        $pdfIds = array_values(array_unique(array_filter(array_map('intval', $pdfIds), static fn($i) => $i > 0)));
        $out = [];
        foreach (array_chunk($pdfIds, 500) as $chunk) {
            foreach (Db::all($db, 'SELECT mpage_pdf_media_id, mpage_media_id FROM training_media_pages WHERE mpage_number = 1 AND mpage_pdf_media_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', str_repeat('i', count($chunk)), $chunk) as $r) {
                $out[(int) $r['mpage_pdf_media_id']] = (int) $r['mpage_media_id'];
            }
        }
        return $out;
    }

    /** The media-kind lookup ArticleSanitizer::purify() uses for its URI pass. */
    public static function kindLookup(\mysqli $db): \Closure
    {
        $cache = [];
        return static function ($mediaId) use ($db, &$cache): ?string {
            $id = (int) $mediaId;
            if ($id <= 0) {
                return null;
            }
            if (!array_key_exists($id, $cache)) {
                $row = Db::one($db, 'SELECT media_kind FROM training_media WHERE media_id = ?', 'i', [$id]);
                $cache[$id] = $row === null ? null : (string) $row['media_kind'];
            }
            return $cache[$id];
        };
    }

    /**
     * Purifies author HTML at save (ArticleSanitizer, spec §3.3) and turns the removal counts
     * into toast-ready warnings.
     *
     * @return array{html: ?string, warnings: list<array{code:string, count:int, message:string}>}
     */
    public static function purify(\mysqli $db, ?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return ['html' => null, 'warnings' => []];
        }
        $res = ArticleSanitizer::purify($html, self::kindLookup($db));
        $clean = (string) ($res['html'] ?? '');
        $warnings = [];
        foreach ((array) ($res['removed'] ?? []) as $code => $n) {
            $n = (int) $n;
            if ($n <= 0) {
                continue;
            }
            $warnings[] = ['code' => (string) $code, 'count' => $n, 'message' => self::removedMessage((string) $code, $n)];
        }
        return ['html' => self::htmlEmpty($clean) ? null : $clean, 'warnings' => $warnings];
    }

    /** True when the HTML has no visible text and no image. */
    public static function htmlEmpty(?string $html): bool
    {
        if ($html === null || trim($html) === '') {
            return true;
        }
        if (stripos($html, '<img') !== false) {
            return false;
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    private static function removedMessage(string $code, int $n): string
    {
        return match ($code) {
            'external_image' => $n === 1
                ? '1 image from outside Training was removed. Upload images instead.'
                : "$n images from outside Training were removed. Upload images instead.",
            'link' => $n === 1
                ? '1 link was unlinked. Links must start with https: or mailto:, or point to a Training file.'
                : "$n links were unlinked. Links must start with https: or mailto:, or point to a Training file.",
            'class' => 'Some formatting that Training does not support was removed.',
            'ikb' => $n === 1
                ? '1 interactive Knowledge Base block was removed or flattened.'
                : "$n interactive Knowledge Base blocks were removed or flattened.",
            default => $n === 1 ? '1 item that is not allowed in Training content was removed.' : "$n items that are not allowed in Training content were removed.",
        };
    }
}
