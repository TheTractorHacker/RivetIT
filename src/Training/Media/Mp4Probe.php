<?php

namespace ITFlow\Training\Media;

/**
 * Pure-PHP ISO-BMFF / QuickTime box walker - the ffprobe this box does not have (spec §3.3).
 *
 * It reads only box headers and a handful of small leaf boxes (ftyp, mvhd, mehd, tkhd, mdhd,
 * hdlr, the first stsd entry) with fseek(); sample tables and media data are skipped, never
 * loaded, so a 95 MB upload costs a few kilobytes of reads. 64-bit box sizes (size == 1) and
 * "to end of file" boxes (size == 0) are handled; at most 10 000 boxes and 12 levels are
 * visited, and every child must fit inside its parent, so a hostile file cannot loop or
 * make us read outside it.
 *
 * ACCEPTED: brands isom iso2 iso5 iso6 mp41 mp42 avc1 'M4V ' dash 'qt  ' (as the major brand
 * or a compatible brand), a video track coded avc1/avc3 (H.264) or hvc1/hev1 (HEVC), and audio
 * that is mp4a or absent. QuickTime (.mov, brand 'qt  ') with that content is what an iPhone
 * records and plays as video/mp4 in Safari and Chrome, so it is stored as mp4 (spec §4.1).
 * Anything else - ProRes, Motion JPEG, PCM audio, DRM-protected entries - is a 415
 * unsupported_codec with "Export as MP4 (H.264)".
 *
 * NOTES FOR THE AUTHOR (not errors):
 *   warnings 'hevc'          Windows PCs without the HEVC extension may not play it.
 *   info     'not_faststart' the index (moov) is after the media data. It still plays: nginx
 *                            serves byte ranges, so the player fetches the index from the end;
 *                            the first play may just take a moment longer. We deliberately do
 *                            not rewrite the file (a remux with real corruption risk).
 */
final class Mp4Probe
{
    public const BRANDS = ['isom', 'iso2', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash', 'qt  '];
    public const VIDEO_CODECS = ['avc1', 'avc3', 'hvc1', 'hev1'];
    public const AUDIO_CODECS = ['mp4a'];

    /** Still-image (HEIF/AVIF) brands: an iPhone photo is not a video. */
    private const IMAGE_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'avif', 'avis'];

    private const CONTAINERS = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'mvex', 'edts'];
    private const LEAVES = ['mvhd', 'mehd', 'tkhd', 'mdhd', 'hdlr', 'stsd'];
    private const MAX_BOXES = 10000;
    private const MAX_DEPTH = 12;

    private const CODEC_NAMES = [
        'apcn' => 'Apple ProRes', 'apch' => 'Apple ProRes', 'apcs' => 'Apple ProRes', 'apco' => 'Apple ProRes',
        'ap4h' => 'Apple ProRes', 'ap4x' => 'Apple ProRes', 'jpeg' => 'Motion JPEG', 'mjpa' => 'Motion JPEG',
        'mp4v' => 'MPEG-4 Part 2', 'dvh1' => 'Dolby Vision', 'dvhe' => 'Dolby Vision', 'av01' => 'AV1',
        'vp09' => 'VP9', 'encv' => 'DRM-protected video', 'enca' => 'DRM-protected audio', 'lpcm' => 'PCM',
        'sowt' => 'PCM', 'twos' => 'PCM', 'ac-3' => 'Dolby Digital', 'ec-3' => 'Dolby Digital Plus',
        'alac' => 'Apple Lossless', 'Opus' => 'Opus', 'fLaC' => 'FLAC',
    ];

    /** @var resource */
    private $fh;
    private int $fileSize;
    private int $boxes = 0;

    private ?array $mvhd = null;
    private ?int $fragmentDuration = null;
    /** @var list<array{handler:?string, timescale:?int, duration:?int, codec:?string, w:?int, h:?int, rot90:bool, tw:?int, th:?int}> */
    private array $tracks = [];
    private int $cur = -1;

    /**
     * @return array{duration_ms:int, video_codec:?string, audio_codec:?string, width:?int, height:?int, faststart:bool,
     *               brand:string, container:string, warnings:list<string>, info:list<string>}
     * @throws MediaException 415 unsupported_type | unsupported_codec
     */
    public static function probe(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw MediaException::unsupported('That video could not be read.');
        }
        try {
            $p = new self($fh, (int) (fstat($fh)['size'] ?? 0));
            return $p->run();
        } finally {
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private function __construct($fh, int $size)
    {
        $this->fh = $fh;
        $this->fileSize = $size;
    }

    private function run(): array
    {
        if ($this->fileSize < 16) {
            throw self::damaged();
        }
        $ftyp = null;
        $moovAt = null;
        $mdatAt = null;
        $seenOther = false;
        $offset = 0;
        while ($offset < $this->fileSize) {
            if ($this->fileSize - $offset < 8) {
                break;   // a few stray tail bytes after the last box: ignore them
            }
            $box = $this->header($offset, $this->fileSize);
            if ($box === null) {
                throw self::damaged();
            }
            [$type, $hdr, $size] = $box;
            if ($type === 'ftyp') {
                if ($ftyp !== null || $seenOther) {
                    throw MediaException::unsupported("That file isn't an MP4 or MOV video.");
                }
                $ftyp = $this->parseFtyp($offset + $hdr, $size - $hdr);
            } elseif (in_array($type, ['free', 'skip', 'wide'], true)) {
                // QuickTime writers put padding atoms anywhere, including before ftyp.
            } else {
                if ($ftyp === null) {
                    throw MediaException::unsupported("That file isn't an MP4 or MOV video.");
                }
                $seenOther = true;
                if ($type === 'moov') {
                    if ($moovAt !== null) {
                        throw self::damaged();
                    }
                    $moovAt = $offset;
                    $this->container($offset + $hdr, $offset + $size, 1);
                } elseif ($type === 'mdat' && $mdatAt === null) {
                    $mdatAt = $offset;
                }
            }
            $offset += $size;
        }
        if ($ftyp === null) {
            throw MediaException::unsupported("That file isn't an MP4 or MOV video.");
        }
        [$major, $compat] = $ftyp;
        if (in_array($major, self::IMAGE_BRANDS, true)) {
            throw MediaException::unsupported('That is a photo, not a video.');
        }
        if (!in_array($major, self::BRANDS, true) && array_intersect($compat, self::BRANDS) === []) {
            throw new MediaException(415, 'unsupported_codec', 'This video container is not supported. Export as MP4 (H.264).');
        }
        if ($moovAt === null || $this->mvhd === null) {
            throw self::damaged();
        }

        $video = null;
        $audio = null;
        foreach ($this->tracks as $t) {
            if ($t['handler'] === 'vide' && $video === null) {
                $video = $t;
            } elseif ($t['handler'] === 'soun' && $audio === null) {
                $audio = $t;
            }
        }
        if ($video === null || $video['codec'] === null) {
            throw new MediaException(415, 'unsupported_codec', 'This file has no video track. Export as MP4 (H.264).');
        }
        if (!in_array($video['codec'], self::VIDEO_CODECS, true)) {
            $name = self::CODEC_NAMES[$video['codec']] ?? ('"' . self::printable($video['codec']) . '"');
            throw new MediaException(415, 'unsupported_codec', "This video is in the $name format, which browsers can't play. Export as MP4 (H.264).");
        }
        if ($audio !== null && $audio['codec'] !== null && !in_array($audio['codec'], self::AUDIO_CODECS, true)) {
            $name = self::CODEC_NAMES[$audio['codec']] ?? ('"' . self::printable($audio['codec']) . '"');
            throw new MediaException(415, 'unsupported_codec', "This video's sound is in the $name format, which browsers can't play. Export as MP4 (H.264 with AAC audio).");
        }

        $durationMs = $this->durationMs();
        if ($durationMs < 1) {
            throw new MediaException(415, 'unsupported_codec', 'This video has no playable length. Export it again as MP4 (H.264).');
        }

        $width = $video['w'] ?? $video['tw'];
        $height = $video['h'] ?? $video['th'];
        if ($video['rot90'] && $width !== null && $height !== null) {
            [$width, $height] = [$height, $width];
        }

        $warnings = [];
        $info = [];
        if (in_array($video['codec'], ['hvc1', 'hev1'], true)) {
            $warnings[] = 'hevc';
        }
        $faststart = $mdatAt === null || $moovAt < $mdatAt;
        if (!$faststart) {
            $info[] = 'not_faststart';
        }

        return [
            'duration_ms' => $durationMs,
            'video_codec' => $video['codec'],
            'audio_codec' => $audio['codec'] ?? null,
            'width' => ($width !== null && $width > 0 && $width <= 65535) ? $width : null,
            'height' => ($height !== null && $height > 0 && $height <= 65535) ? $height : null,
            'faststart' => $faststart,
            'brand' => $major,
            'container' => $major === 'qt  ' ? 'mov' : 'mp4',
            'warnings' => $warnings,
            'info' => $info,
        ];
    }

    /** Plain-language text for the warning/info codes above (shown by the uploader). */
    public static function messages(): array
    {
        return [
            'hevc' => 'This video is HEVC. Windows PCs may not play it. On iPhone: Settings › Camera › Formats › Most Compatible.',
            'not_faststart' => 'Plays fine: the first play may take a moment longer to start.',
        ];
    }

    // ---------------------------------------------------------------------------------------

    private function durationMs(): int
    {
        $ms = 0;
        if ($this->mvhd !== null && $this->mvhd['timescale'] > 0 && $this->mvhd['duration'] > 0) {
            $ms = self::toMs($this->mvhd['duration'], $this->mvhd['timescale']);
        }
        if ($ms < 1 && $this->fragmentDuration !== null && $this->mvhd !== null && $this->mvhd['timescale'] > 0) {
            $ms = self::toMs($this->fragmentDuration, $this->mvhd['timescale']);
        }
        if ($ms < 1) {
            foreach ($this->tracks as $t) {
                if (($t['timescale'] ?? 0) > 0 && ($t['duration'] ?? 0) > 0) {
                    $ms = max($ms, self::toMs($t['duration'], $t['timescale']));
                }
            }
        }
        return min($ms, 4294967295);
    }

    private static function toMs(int $duration, int $timescale): int
    {
        // An all-ones duration means "unknown" in both 32- and 64-bit forms.
        if ($duration === 0xFFFFFFFF || $duration < 0) {
            return 0;
        }
        return (int) round($duration * 1000 / $timescale);
    }

    private function container(int $start, int $end, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw self::damaged();
        }
        $offset = $start;
        while ($offset + 8 <= $end) {
            $box = $this->header($offset, $end);
            if ($box === null) {
                throw self::damaged();
            }
            [$type, $hdr, $size] = $box;
            $pStart = $offset + $hdr;
            $pLen = $size - $hdr;
            if ($type === 'trak') {
                $this->tracks[] = ['handler' => null, 'timescale' => null, 'duration' => null, 'codec' => null,
                                   'w' => null, 'h' => null, 'rot90' => false, 'tw' => null, 'th' => null];
                $this->cur = count($this->tracks) - 1;
                $this->container($pStart, $offset + $size, $depth + 1);
                $this->cur = -1;
            } elseif (in_array($type, self::CONTAINERS, true)) {
                $this->container($pStart, $offset + $size, $depth + 1);
            } elseif (in_array($type, self::LEAVES, true)) {
                $this->leaf($type, $pStart, $pLen);
            }
            $offset += $size;
        }
    }

    private function leaf(string $type, int $start, int $len): void
    {
        switch ($type) {
            case 'mvhd':
                if ($this->mvhd === null) {
                    [$ts, $dur] = $this->timeHeader($start, $len);
                    $this->mvhd = ['timescale' => $ts, 'duration' => $dur];
                }
                return;
            case 'mehd':
                $b = $this->read($start, min($len, 12));
                if (strlen($b) >= 8) {
                    $this->fragmentDuration = ord($b[0]) === 1 && strlen($b) >= 12 ? self::u64(substr($b, 4, 8)) : self::u32(substr($b, 4, 4));
                }
                return;
        }
        if ($this->cur < 0) {
            return;
        }
        $t = &$this->tracks[$this->cur];
        switch ($type) {
            case 'mdhd':
                [$t['timescale'], $t['duration']] = $this->timeHeader($start, $len);
                break;
            case 'hdlr':
                $b = $this->read($start, min($len, 12));
                if (strlen($b) >= 12 && $t['handler'] === null) {
                    $t['handler'] = substr($b, 8, 4);
                }
                break;
            case 'tkhd':
                $b = $this->read($start, min($len, 96));
                $v1 = strlen($b) > 0 && ord($b[0]) === 1;
                $mOff = $v1 ? 52 : 40;
                if (strlen($b) >= $mOff + 44) {
                    $a = self::s32(substr($b, $mOff, 4));
                    $bb = self::s32(substr($b, $mOff + 4, 4));
                    $c = self::s32(substr($b, $mOff + 12, 4));
                    $d = self::s32(substr($b, $mOff + 16, 4));
                    $t['rot90'] = $a === 0 && $d === 0 && abs($bb) === 0x10000 && abs($c) === 0x10000;
                    $t['tw'] = self::u32(substr($b, $mOff + 36, 4)) >> 16;
                    $t['th'] = self::u32(substr($b, $mOff + 40, 4)) >> 16;
                }
                break;
            case 'stsd':
                // full box header (4) + entry_count (4), then the first sample entry box.
                $b = $this->read($start, min($len, 8 + 36));
                if (strlen($b) >= 16 && self::u32(substr($b, 4, 4)) >= 1 && $t['codec'] === null) {
                    $entrySize = self::u32(substr($b, 8, 4));
                    if ($entrySize >= 16 && $entrySize <= $len - 8) {
                        $t['codec'] = rtrim(substr($b, 12, 4), "\0");
                        if (strlen($b) >= 8 + 36 && $entrySize >= 36) {
                            $t['w'] = self::u16(substr($b, 8 + 32, 2)) ?: null;
                            $t['h'] = self::u16(substr($b, 8 + 34, 2)) ?: null;
                        }
                    }
                }
                break;
        }
    }

    /** @return array{0:int, 1:int} timescale, duration (mvhd / mdhd layout, versions 0 and 1) */
    private function timeHeader(int $start, int $len): array
    {
        $b = $this->read($start, min($len, 32));
        if (strlen($b) >= 32 && ord($b[0]) === 1) {
            return [self::u32(substr($b, 20, 4)), self::u64(substr($b, 24, 8))];
        }
        if (strlen($b) >= 20) {
            return [self::u32(substr($b, 12, 4)), self::u32(substr($b, 16, 4))];
        }
        return [0, 0];
    }

    /** @return array{0:string, 1:list<string>} major brand, compatible brands */
    private function parseFtyp(int $start, int $len): array
    {
        if ($len < 8) {
            throw self::damaged();
        }
        $b = $this->read($start, min($len, 8 + 4 * 64));
        $major = substr($b, 0, 4);
        $compat = [];
        for ($i = 8; $i + 4 <= strlen($b); $i += 4) {
            $compat[] = substr($b, $i, 4);
        }
        return [$major, $compat];
    }

    /** @return array{0:string, 1:int, 2:int}|null type, header length, total size */
    private function header(int $offset, int $end): ?array
    {
        if (++$this->boxes > self::MAX_BOXES) {
            throw MediaException::unsupported('That video file has an unusual structure and could not be read. Export it again as MP4 (H.264).');
        }
        if ($offset + 8 > $end) {
            return null;
        }
        $b = $this->read($offset, 16);
        if (strlen($b) < 8) {
            return null;
        }
        $size = self::u32(substr($b, 0, 4));
        $type = substr($b, 4, 4);
        $hdr = 8;
        if ($size === 1) {
            if (strlen($b) < 16) {
                return null;
            }
            $size = self::u64(substr($b, 8, 8));
            $hdr = 16;
        } elseif ($size === 0) {
            $size = $end - $offset;
        }
        if ($type === 'uuid') {
            $hdr += 16;
        }
        if ($size < $hdr || $size > $end - $offset) {
            return null;
        }
        return [$type, $hdr, $size];
    }

    private function read(int $offset, int $len): string
    {
        if ($len <= 0 || $offset < 0 || $offset >= $this->fileSize) {
            return '';
        }
        if (fseek($this->fh, $offset) !== 0) {
            return '';
        }
        $b = fread($this->fh, $len);
        return is_string($b) ? $b : '';
    }

    private static function u16(string $b): int
    {
        return unpack('n', $b)[1];
    }

    private static function u32(string $b): int
    {
        return unpack('N', $b)[1];
    }

    private static function s32(string $b): int
    {
        $v = unpack('N', $b)[1];
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /** Unsigned 64-bit read; a value above PHP_INT_MAX comes back negative and every caller rejects it. */
    private static function u64(string $b): int
    {
        return unpack('J', $b)[1];
    }

    private static function printable(string $fourcc): string
    {
        return preg_replace('/[^\x20-\x7E]/', '?', $fourcc);
    }

    private static function damaged(): MediaException
    {
        return MediaException::unsupported('This video file is incomplete or damaged. Export it again as MP4 (H.264).');
    }
}
