<?php

namespace ITFlow\Training\Media;

/**
 * Every training image is decoded and re-encoded with GD before it is stored (spec §3.3, §4.1).
 * Re-encoding is the sanitiser: whatever the uploaded bytes carried besides pixels - EXIF with
 * GPS coordinates, a polyglot payload, a malformed chunk aimed at a browser decoder - does not
 * survive, and the stored type is always one we produced.
 *
 * ORDER MATTERS. getimagesize() reads only the header, so the size caps (16 megapixels, 8000 px
 * on any edge) are enforced BEFORE any decode: a 20 MP or 9000 px image is refused without GD
 * ever allocating its bitmap (a 16 MP truecolor bitmap is already 64 MB).
 *
 * Output: the long edge scaled down to at most 2400 px; JPEG orientation from EXIF applied to
 * the pixels (so every viewer shows it upright without reading EXIF); opaque images as JPEG
 * quality 85, images with real transparency as PNG. Deterministic for the same input, which
 * keeps content-addressed de-duplication working for re-uploads.
 */
final class ImageProcessor
{
    public const MAX_PIXELS = 16000000;
    public const MAX_EDGE = 8000;
    public const TARGET_EDGE = 2400;
    public const JPEG_QUALITY = 85;

    /** Input types accepted for decoding (uploads are narrowed further by FileValidator). */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF, IMAGETYPE_BMP];

    /**
     * @param array{force_jpeg?:bool, max_edge?:int} $opts
     * @return array{bytes:string, mime:string, ext:string, width:int, height:int, warnings:list<string>, info:list<string>}
     * @throws MediaException 413 too_large (dimensions) | 415 unsupported_type
     */
    public static function reencodeFile(string $path, array $opts = []): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw MediaException::unsupported('That image could not be read.');
        }
        return self::reencodeBytes($bytes, $opts);
    }

    /**
     * @param array{force_jpeg?:bool, max_edge?:int} $opts
     * @return array{bytes:string, mime:string, ext:string, width:int, height:int, warnings:list<string>, info:list<string>}
     */
    public static function reencodeBytes(string $bytes, array $opts = []): array
    {
        $info = self::inspectBytes($bytes);
        $type = $info['type'];

        // The type was verified from the header above; GD picks its decoder from the same magic.
        $img = @imagecreatefromstring($bytes);
        if (!($img instanceof \GdImage)) {
            throw MediaException::unsupported('That image is damaged or in a format that cannot be read. Save it again as JPEG or PNG.');
        }
        $notes = [];
        try {
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }
            imagealphablending($img, false);
            imagesavealpha($img, true);

            if ($type === IMAGETYPE_JPEG) {
                $img = self::applyOrientation($img, self::exifOrientation($bytes));
            }
            if ($type === IMAGETYPE_GIF && self::gifIsAnimated($bytes)) {
                $notes[] = 'animation_removed';
            }

            $maxEdge = max(16, min(self::TARGET_EDGE, (int) ($opts['max_edge'] ?? self::TARGET_EDGE)));
            $w = imagesx($img);
            $h = imagesy($img);
            if (max($w, $h) > $maxEdge) {
                $scale = $maxEdge / max($w, $h);
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $img = $dst;
                unset($dst);
                $w = $nw;
                $h = $nh;
            }

            $alpha = empty($opts['force_jpeg']) && $info['may_have_alpha'] && self::hasTransparency($img);
            ob_start();
            if ($alpha) {
                $ok = imagepng($img, null, 6);
            } else {
                $flat = imagecreatetruecolor($w, $h);
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagealphablending($flat, true);
                imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
                imageinterlace($flat, true);
                $ok = imagejpeg($flat, null, self::JPEG_QUALITY);
                unset($flat);
            }
            $out = (string) ob_get_clean();
            if (!$ok || $out === '') {
                throw MediaException::unsupported('That image could not be converted. Save it again as JPEG or PNG.');
            }
            return [
                'bytes' => $out,
                'mime' => $alpha ? 'image/png' : 'image/jpeg',
                'ext' => $alpha ? 'png' : 'jpg',
                'width' => $w,
                'height' => $h,
                'warnings' => [],
                'info' => $notes,
            ];
        } finally {
            // GdImage objects are freed when the last reference goes (imagedestroy() is a no-op
            // since PHP 8.0 and deprecated in 8.5); drop the bitmap before returning the bytes.
            unset($img);
        }
    }

    /**
     * Header-only check: type allowed and dimensions within the caps. Never decodes pixels.
     *
     * @return array{type:int, width:int, height:int, may_have_alpha:bool}
     * @throws MediaException
     */
    public static function inspectBytes(string $bytes): array
    {
        if ($bytes === '') {
            throw MediaException::unsupported('That image is empty.');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || !in_array($size[2] ?? null, self::TYPES, true)) {
            throw MediaException::unsupported('Choose a JPEG, PNG, WebP or GIF image.');
        }
        return self::checkDims((int) $size[0], (int) $size[1], (int) $size[2], $bytes);
    }

    /** As inspectBytes() for a file on disk (reads only the header). */
    public static function inspectFile(string $path): array
    {
        $size = @getimagesize($path);
        if ($size === false || !in_array($size[2] ?? null, self::TYPES, true)) {
            throw MediaException::unsupported('Choose a JPEG, PNG, WebP or GIF image.');
        }
        $head = (string) @file_get_contents($path, false, null, 0, 65536);
        return self::checkDims((int) $size[0], (int) $size[1], (int) $size[2], $head);
    }

    private static function checkDims(int $w, int $h, int $type, string $head): array
    {
        if ($w < 1 || $h < 1) {
            throw MediaException::unsupported('That image has no size. Save it again as JPEG or PNG.');
        }
        if ($w > self::MAX_EDGE || $h > self::MAX_EDGE) {
            throw MediaException::tooLarge("This image is $w × $h pixels; the longest side can be at most " . self::MAX_EDGE . ' pixels. Resize it and try again.');
        }
        if ($w * $h > self::MAX_PIXELS) {
            $mp = round($w * $h / 1000000, 1);
            throw MediaException::tooLarge("This image is $mp megapixels; the limit is 16. Resize it (or take the photo at a lower resolution) and try again.");
        }
        return ['type' => $type, 'width' => $w, 'height' => $h, 'may_have_alpha' => self::mayHaveAlpha($type, $head)];
    }

    /** Whether the container can carry transparency at all (decided from the header). */
    private static function mayHaveAlpha(int $type, string $head): bool
    {
        switch ($type) {
            case IMAGETYPE_PNG:
                // IHDR colour type at byte 25: 4 = grey+alpha, 6 = RGBA; palette/truecolour can use tRNS.
                $ct = strlen($head) > 25 ? ord($head[25]) : 6;
                return $ct === 4 || $ct === 6 || str_contains($head, 'tRNS');
            case IMAGETYPE_GIF:
                return true;
            case IMAGETYPE_WEBP:
                // VP8X flags byte (offset 20): bit 4 = alpha; lossless VP8L always may.
                if (substr($head, 12, 4) === 'VP8X') {
                    return strlen($head) > 20 && (ord($head[20]) & 0x10) !== 0;
                }
                return substr($head, 12, 4) === 'VP8L';
            default:
                return false;
        }
    }

    /**
     * True when any pixel is not fully opaque. Checked on a small resampled copy (at most
     * 128x128), which carries any transparent region into partially transparent pixels.
     */
    private static function hasTransparency(\GdImage $img): bool
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $sw = min(128, $w);
        $sh = min(128, $h);
        $probe = imagecreatetruecolor($sw, $sh);
        imagealphablending($probe, false);
        imagesavealpha($probe, true);
        imagefill($probe, 0, 0, imagecolorallocatealpha($probe, 0, 0, 0, 127));
        imagecopyresampled($probe, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
        try {
            for ($y = 0; $y < $sh; $y++) {
                for ($x = 0; $x < $sw; $x++) {
                    if (((imagecolorat($probe, $x, $y) >> 24) & 0x7F) > 0) {
                        return true;
                    }
                }
            }
            return false;
        } finally {
            unset($probe);
        }
    }

    /** EXIF Orientation (1..8) of JPEG bytes; 1 when absent or unreadable. */
    public static function exifOrientation(string $jpegBytes): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return 1;
        }
        try {
            fwrite($stream, $jpegBytes);
            rewind($stream);
            $exif = @exif_read_data($stream, 'IFD0');
        } catch (\Throwable) {
            $exif = false;
        } finally {
            fclose($stream);
        }
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        return ($o >= 1 && $o <= 8) ? $o : 1;
    }

    private static function applyOrientation(\GdImage $img, int $o): \GdImage
    {
        if ($o <= 1) {
            return $img;
        }
        // imagerotate() turns counter-clockwise, so -90 is a quarter turn clockwise.
        //   2 mirror | 3 180 | 4 flip vertical | 5 transpose (cw + mirror) | 6 cw | 7 transverse (ccw + mirror) | 8 ccw
        $angle = match ($o) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rot = imagerotate($img, $angle, 0);
            if (!($rot instanceof \GdImage)) {
                return $img;
            }
            imagealphablending($rot, false);
            imagesavealpha($rot, true);
            $img = $rot;
        }
        if (in_array($o, [2, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        } elseif ($o === 4) {
            imageflip($img, IMG_FLIP_VERTICAL);
        }
        return $img;
    }

    private static function gifIsAnimated(string $bytes): bool
    {
        // More than one image descriptor preceded by a graphic control extension.
        return substr_count($bytes, "\x00\x21\xF9\x04") > 1 || str_contains($bytes, 'NETSCAPE2.0');
    }
}
