<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\Db;

/**
 * Closed-caption files for uploaded videos (owner ask 2026-09-27: "CC titles").
 *
 * An author attaches one caption file per language variant of a video lesson. Whatever arrives
 * (.vtt or .srt, UTF-8 / UTF-8 with BOM / UTF-16 with BOM / Windows-1252) is rebuilt here into a
 * minimal, canonical WebVTT file that holds ONLY timings and plain cue text:
 *
 *   - WebVTT header text, NOTE, STYLE and REGION blocks, cue identifiers and cue settings are
 *     dropped (a STYLE block can carry url(...) and ::cue selectors; none of it is needed);
 *   - cue text loses every tag (<b>, <v Name>, <c.x>, <font>, <script>…</script>, <img onerror>),
 *     ASS override blocks ({\an8}), scheme URLs (https://…, //…, javascript:, data:), bidi
 *     overrides and control characters; entities are decoded, then < > & are escaped again, so
 *     the stored file is inert text whatever a browser or player does with it;
 *   - timings are validated (start < end <= 24 h), cues sorted, at most MAX_CUES cues of at most
 *     MAX_LINES lines / MAX_CUE_CHARS characters each; a file with no usable cue is refused.
 *
 * The stored bytes are this canonical output (so the same captions always hash the same), kept by
 * MediaStore as kind 'caption' / text/vtt / .vtt and served by agent/training_media.php and
 * kiosk/media.php exactly like the video they belong to. The player renders cue text with
 * textContent only.
 *
 * Schema: training_media.media_kind 'caption' and training_lesson_variants.lvar_caption_media_id
 * arrive with DB 2.6.98; schemaReady() lets every caller degrade to "no captions" before the update.
 */
final class Captions
{
    public const MAX_BYTES = 1048576;           // 1 MB: a two-hour SRT is about 150 KB
    public const MAX_CUES = 5000;
    public const MAX_LINES = 4;
    public const MAX_CUE_CHARS = 400;
    public const MAX_END_MS = 86400000;         // 24 h
    public const MIME = 'text/vtt';
    public const EXT = 'vtt';

    private const TIME = '((?:\d{1,3}:)?\d{1,2}:\d{1,2}(?:[.,]\d{1,3})?)';

    private static ?bool $ready = null;

    /**
     * @return array{vtt:string, cues:int, end_ms:int, format:string, encoding:string,
     *               removed:array{markup:int, links:int, blocks:int, cues:int}}
     * @throws MediaException 413 too_large, 415 unsupported_type
     */
    public static function fromFile(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || !is_readable($path)) {
            throw MediaException::unsupported('The caption file could not be read. Try again.');
        }
        if ($size > self::MAX_BYTES) {
            throw MediaException::tooLarge(self::tooLargeMessage());
        }
        return self::fromBytes((string) file_get_contents($path));
    }

    /** @see fromFile() */
    public static function fromBytes(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw MediaException::tooLarge(self::tooLargeMessage());
        }
        if (trim($raw) === '') {
            throw MediaException::unsupported('That caption file is empty.');
        }
        [$text, $encoding] = self::decode($raw);
        $removed = ['markup' => 0, 'links' => 0, 'blocks' => 0, 'cues' => 0];
        [$format, $cues] = self::parse($text, $removed);
        if ($cues === []) {
            throw MediaException::unsupported("This file has no captions in it. Choose a WebVTT (.vtt) or SubRip (.srt) caption file.");
        }
        if (count($cues) > self::MAX_CUES) {
            throw MediaException::unsupported('This caption file has more than ' . self::MAX_CUES . ' captions. Split the video, or shorten the file.');
        }
        usort($cues, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = "WEBVTT\n";
        $end = 0;
        foreach ($cues as [$s, $e, $t]) {
            $out .= "\n" . self::stamp($s) . ' --> ' . self::stamp($e) . "\n" . $t . "\n";
            $end = max($end, $e);
        }
        return ['vtt' => $out, 'cues' => count($cues), 'end_ms' => $end, 'format' => $format, 'encoding' => $encoding, 'removed' => $removed];
    }

    /** Warnings for the uploader from fromBytes()' removal counts (plain language). */
    public static function warnings(array $res): array
    {
        $w = [];
        $r = $res['removed'] ?? [];
        if (($r['markup'] ?? 0) > 0) {
            $w[] = 'caption_markup_removed';
        }
        if (($r['links'] ?? 0) > 0) {
            $w[] = 'caption_links_removed';
        }
        if (($r['cues'] ?? 0) > 0) {
            $w[] = 'caption_cues_skipped';
        }
        return $w;
    }

    public static function tooLargeMessage(): string
    {
        return 'This caption file is larger than ' . intdiv(self::MAX_BYTES, 1048576) . ' MB. Caption files are small text files: check that you chose the .vtt or .srt file, not the video.';
    }

    /** "HH:MM:SS.mmm" */
    public static function stamp(int $ms): string
    {
        $ms = max(0, $ms);
        return sprintf('%02d:%02d:%02d.%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }

    /**
     * Whether DB 2.6.98 is in: training_media.media_kind has 'caption' and training_lesson_variants
     * has lvar_caption_media_id. One information_schema read per request.
     */
    public static function schemaReady(\mysqli $db): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        $rows = Db::all($db, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'training_lesson_variants' AND COLUMN_NAME = 'lvar_caption_media_id')
              OR (TABLE_NAME = 'training_media' AND COLUMN_NAME = 'media_kind'))");
        $col = false;
        $kind = false;
        foreach ($rows as $r) {
            if ($r['COLUMN_NAME'] === 'lvar_caption_media_id') {
                $col = true;
            } elseif (str_contains((string) $r['COLUMN_TYPE'], "'caption'")) {
                $kind = true;
            }
        }
        return self::$ready = ($col && $kind);
    }

    /** TEST SEAM: forget the memoised schemaReady() answer (after a migration in the same process). */
    public static function resetSchemaCache(): void
    {
        self::$ready = null;
    }

    // ------------------------------------------------------------------------------------------

    /** @return array{0:string, 1:string} UTF-8 text with \n line endings, and the source encoding */
    private static function decode(string $raw): array
    {
        $encoding = 'utf-8';
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
            $encoding = 'utf-8-bom';
        } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $from = str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $conv = @mb_convert_encoding(substr($raw, 2), 'UTF-8', $from);
            if (!is_string($conv) || !mb_check_encoding($conv, 'UTF-8')) {
                throw MediaException::unsupported(self::encodingMessage());
            }
            $raw = $conv;
            $encoding = strtolower($from);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            // Older subtitle tools save Spanish text as Windows-1252 ("Selección"): convert, unless it is binary.
            if (str_contains($raw, "\0")) {
                throw MediaException::unsupported(self::encodingMessage());
            }
            $conv = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
            if (!is_string($conv) || !mb_check_encoding($conv, 'UTF-8')) {
                throw MediaException::unsupported(self::encodingMessage());
            }
            $raw = $conv;
            $encoding = 'windows-1252';
        }
        if (str_contains($raw, "\0")) {
            throw MediaException::unsupported(self::encodingMessage());
        }
        // Binary files that happen to be valid UTF-8 (or CP1252) are full of control characters.
        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw);
        if ($controls > 16 && $controls * 50 > strlen($raw)) {
            throw MediaException::unsupported(self::encodingMessage());
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        return [$raw, $encoding];
    }

    private static function encodingMessage(): string
    {
        return "This isn't a caption file. Choose a WebVTT (.vtt) or SubRip (.srt) text file.";
    }

    /**
     * A line-based reader (tolerant of SRT files without blank lines between cues).
     *
     * @return array{0:string, 1:list<array{0:int, 1:int, 2:string}>} format and cues [start_ms, end_ms, escaped text]
     */
    private static function parse(string $text, array &$removed): array
    {
        $text = ltrim($text, " \t\n\u{FEFF}");
        $isVtt = preg_match('/^WEBVTT(?:[ \t\n]|$)/', $text) === 1;
        $timing = '/^\s*' . self::TIME . '\s*-->\s*' . self::TIME . '(?:[ \t].*)?$/';
        if (!$isVtt && preg_match('/^\s*' . self::TIME . '\s*-->\s*' . self::TIME . '/m', $text) !== 1) {
            throw MediaException::unsupported("This file isn't in a caption format. Choose a WebVTT (.vtt) or SubRip (.srt) caption file.");
        }
        $lines = array_map(static fn(string $l): string => rtrim($l, " \t"), explode("\n", $text));
        $cues = [];
        $cur = null;             // [start, end, lines[]] while reading a cue's text
        $skip = $isVtt;          // the WEBVTT header block, then NOTE / STYLE / REGION blocks
        $blockStart = !$isVtt;
        $finish = static function (?array $c) use (&$cues, &$removed): void {
            if ($c === null) {
                return;
            }
            if ($c[0] === null) {
                $removed['cues']++;
                return;
            }
            $t = self::cleanText($c[2], $removed);
            if ($t === '') {
                $removed['cues']++;
                return;
            }
            $cues[] = [$c[0], $c[1], $t];
        };
        foreach ($lines as $l) {
            if ($l === '') {
                $finish($cur);
                $cur = null;
                $skip = false;
                $blockStart = true;
                continue;
            }
            if ($skip) {
                continue;
            }
            if (preg_match($timing, $l, $m) === 1) {
                if ($cur !== null) {
                    // No blank line before this cue (some SRT files): the line before the timing was its index.
                    if ($cur[2] !== [] && preg_match('/^\d+$/', (string) end($cur[2])) === 1) {
                        array_pop($cur[2]);
                    }
                    $finish($cur);
                }
                $s = self::ms($m[1]);
                $e = self::ms($m[2]);
                $ok = $s !== null && $e !== null && $e > $s && $e <= self::MAX_END_MS;
                $cur = [$ok ? $s : null, $ok ? $e : null, []];
                $blockStart = false;
                continue;
            }
            if ($cur !== null) {
                $cur[2][] = $l;
                continue;
            }
            if ($isVtt && $blockStart && preg_match('/^(NOTE|STYLE|REGION)(?:[ \t]|$)/', $l) === 1) {
                $removed['blocks']++;
                $skip = true;
                continue;
            }
            // Outside a cue: a cue identifier / SRT index (ignored) or stray text (dropped).
            if (preg_match('/^\d+$/', $l) !== 1 && !$blockStart) {
                $removed['blocks']++;
            }
            $blockStart = false;
        }
        $finish($cur);
        return [$isVtt ? 'vtt' : 'srt', $cues];
    }

    /** "01:02:03.456" | "02:03,4" => ms, or null. */
    private static function ms(string $t): ?int
    {
        if (preg_match('/^(?:(\d{1,3}):)?(\d{1,2}):(\d{1,2})(?:[.,](\d{1,3}))?$/', $t, $m) !== 1) {
            return null;
        }
        $h = $m[1] === '' ? 0 : (int) $m[1];
        $min = (int) $m[2];
        $sec = (int) $m[3];
        if ($min > 59 || $sec > 59) {
            return null;
        }
        $frac = isset($m[4]) && $m[4] !== '' ? (int) str_pad($m[4], 3, '0') : 0;
        return (($h * 60 + $min) * 60 + $sec) * 1000 + $frac;
    }

    /**
     * Cue payload lines => escaped plain WebVTT cue text ('' when nothing readable is left).
     *
     * @param list<string> $lines
     */
    public static function cleanText(array $lines, array &$removed): string
    {
        $s = implode("\n", $lines);
        $orig = $s;
        // Whole elements whose content is never caption text.
        $s = preg_replace('#<\s*(script|style|iframe|object|embed|svg|math|template|noscript)\b.*?(?:<\s*/\s*\1\s*>|$)#is', '', $s) ?? '';
        $s = preg_replace('#<!--.*?(?:-->|$)#s', '', $s) ?? '';
        // ASS/SSA override blocks some SRT files carry: {\an8}, {\i1}
        $s = preg_replace('/\{\\\\[^}]*\}/', '', $s) ?? '';
        // Every remaining tag (WebVTT <b> <i> <u> <v Name> <c.x> <ruby> <00:01.000>, HTML <font> <img …>).
        $s = preg_replace('/<[^>\n]*>/', '', $s) ?? '';
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('#<\s*(script|style|iframe|object|embed|svg|math|template|noscript)\b.*?(?:<\s*/\s*\1\s*>|$)#is', '', $s) ?? '';
        // A tag that only appears after decoding (&lt;script&gt;) or never closed (<img src=x onerror=…): drop the rest of the token.
        $s = preg_replace('/<[^\s<>]*|>/', '', $s) ?? '';
        if (preg_match('/[<>]|\{\\\\|&lt;|&gt;|&#0*6[02];|&#x0*3[ce];/i', $orig) === 1) {
            $removed['markup']++;
        }
        $before = $s;
        $s = preg_replace('~(?:\b[a-z][a-z0-9+.\-]{0,20}:)?//[^\s]+~i', '', $s) ?? '';
        $s = preg_replace('~\b(?:javascript|vbscript|data|file|blob):[^\s]*~i', '', $s) ?? '';
        if ($s !== $before) {
            $removed['links']++;
        }
        // Bidi overrides, zero-width characters, control characters.
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $s) ?? '';
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{0080}-\x{009F}]/u', '', $s) ?? '';
        $out = [];
        foreach (explode("\n", $s) as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? '');
            if ($line !== '') {
                $out[] = $line;
            }
        }
        if (count($out) > self::MAX_LINES) {
            $out = array_merge(array_slice($out, 0, self::MAX_LINES - 1), [implode(' ', array_slice($out, self::MAX_LINES - 1))]);
        }
        $text = implode("\n", $out);
        if (mb_strlen($text, 'UTF-8') > self::MAX_CUE_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CUE_CHARS - 1, 'UTF-8')) . '…';
        }
        // WebVTT cue text: & < > escaped (so "-->" can never appear either).
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
