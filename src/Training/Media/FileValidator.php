<?php

namespace ITFlow\Training\Media;

use ITFlow\Training\Core\TrainingSettings;

/**
 * Decides what an uploaded file really is, from its bytes (spec §4.1 step 10).
 *
 * The client's filename and Content-Type are never trusted: the type comes from magic bytes
 * plus a structural check (pdfinfo for PDFs, Mp4Probe for video, getimagesize for images, the
 * ZIP directory and [Content_Types].xml for Office files, UTF-8 validity for text), and the
 * stored extension is always derived from that sniffed type. The client name is used for one
 * thing only: choosing between .csv and .txt for a plain-text resource, where the bytes cannot
 * tell and both are served as inert text.
 *
 *   PDF      %PDF- magic; pdfinfo 1..max pages; user password => 415 pdf_password;
 *            owner-only restrictions => accepted with info 'pdf_owner_restricted'
 *   Video    ftyp brand + codecs via Mp4Probe; stored as mp4 / video/mp4 (MOV included)
 *   Image    getimagesize JPEG/PNG/WebP/GIF with the dimension cap BEFORE decode;
 *            HEIC/HEIF => 415 with the iPhone "Most Compatible" guidance
 *   Office   ZIP with the DocxConverter bomb caps; [Content_Types].xml + the main part;
 *            vbaProject.bin or any macroEnabled content type => 415
 *   Text     valid UTF-8, no NUL bytes (resources); CSV imports may be CP1252 (Core\Csv converts)
 */
final class FileValidator
{
    // Copied from src/KB/DocxConverter (spec §4.1: "the DocxConverter limits, copied").
    private const ZIP_MAX_ENTRIES = 1024;
    private const ZIP_MAX_TOTAL_UNCOMPRESSED = 50331648;
    private const ZIP_MAX_RATIO = 500;
    private const ZIP_RATIO_MIN_SIZE = 4194304;
    private const ZIP_RATIO_MIN_COMP = 1024;
    private const CONTENT_TYPES_MAX = 1048576;

    private const OFFICE_MAIN = [
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    ];

    private const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'avif', 'avis'];

    public const HEIC_MESSAGE = 'This photo is in HEIC format. On iPhone choose Settings › Camera › Formats › Most Compatible, or export the photo as JPEG.';

    /**
     * @return array{kind:string, mime:string, ext:string, warnings:list<string>, info:list<string>, meta:array<string, mixed>}
     *   kind is a media kind (pdf|video|image|file) for stored purposes, or 'docx' / 'csv' for the
     *   two import purposes (whose bytes are converted and never stored).
     * @throws MediaException 415 unsupported_type | unsupported_codec | pdf_password, 413 too_large, 422 validation
     */
    public static function classify(string $tmpPath, string $purpose, string $clientName, ?TrainingSettings $settings = null): array
    {
        $rule = UploadPurpose::rule($purpose);
        $settings ??= TrainingSettings::fromRow([], false);
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            throw MediaException::unsupported('The uploaded file could not be read. Try again.');
        }
        $size = (int) filesize($tmpPath);
        if ($size < 1) {
            throw MediaException::unsupported('That file is empty.');
        }
        $head = (string) file_get_contents($tmpPath, false, null, 0, 65536);
        $type = self::sniff($tmpPath, $head);

        if ($purpose === 'docx_import') {
            if ($type !== 'zip') {
                throw MediaException::unsupported(self::guidance($purpose, $type));
            }
            if (self::office($tmpPath, ['docx']) !== 'docx') {
                throw MediaException::unsupported('Choose a Word document (.docx).');
            }
            return self::result('docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx');
        }
        if ($purpose === 'csv_import') {
            if ($type !== 'text' && $type !== 'text_legacy') {
                throw MediaException::unsupported('Choose a CSV file (in Excel: File › Save As › CSV UTF-8).');
            }
            return self::result('csv', 'text/csv', 'csv');
        }

        $kinds = $rule['kinds'];
        switch ($type) {
            case 'pdf':
                if (!in_array('pdf', $kinds, true)) {
                    break;
                }
                $pdf = PdfPager::inspect($tmpPath);
                if ($purpose === 'lesson_document' && $pdf['pages'] > $settings->pdfMaxPages) {
                    throw new MediaException(422, 'validation', "This PDF has {$pdf['pages']} pages; the limit is {$settings->pdfMaxPages}. Split it into smaller documents.");
                }
                return self::result('pdf', 'application/pdf', 'pdf', [], $pdf['owner_encrypted'] ? ['pdf_owner_restricted'] : [],
                    ['page_count' => $pdf['pages'], 'owner_encrypted' => $pdf['owner_encrypted']]);

            case 'video':
                if (!in_array('video', $kinds, true)) {
                    break;
                }
                $probe = Mp4Probe::probe($tmpPath);
                return self::result('video', 'video/mp4', 'mp4', $probe['warnings'], $probe['info'], ['probe' => $probe]);

            case 'heic':
                if (in_array('image', $kinds, true)) {
                    throw MediaException::unsupported(self::HEIC_MESSAGE);
                }
                break;

            case 'image':
                if (!in_array('image', $kinds, true)) {
                    break;
                }
                $img = ImageProcessor::inspectFile($tmpPath);
                [$mime, $ext] = match ($img['type']) {
                    IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
                    IMAGETYPE_PNG => ['image/png', 'png'],
                    IMAGETYPE_WEBP => ['image/webp', 'webp'],
                    IMAGETYPE_GIF => ['image/gif', 'gif'],
                    default => throw MediaException::unsupported('Choose a JPEG, PNG, WebP or GIF image.'),
                };
                return self::result('image', $mime, $ext, [], [], ['width' => $img['width'], 'height' => $img['height']]);

            case 'zip':
                if (!in_array('file', $kinds, true)) {
                    break;
                }
                $ext = self::office($tmpPath, ['docx', 'xlsx', 'pptx']);
                return self::result('file', self::OFFICE_MAIN[$ext][1], $ext);

            case 'text':
                if (!in_array('file', $kinds, true)) {
                    break;
                }
                self::assertUtf8Text($tmpPath);
                $isCsv = self::looksCsv($tmpPath, $head, $clientName);
                return $isCsv ? self::result('file', 'text/csv', 'csv') : self::result('file', 'text/plain', 'txt');

            case 'text_legacy':
                if (in_array('file', $kinds, true)) {
                    throw MediaException::unsupported('This text file is not UTF-8. Save it again as UTF-8 (in Excel: CSV UTF-8) and try again.');
                }
                break;
        }
        throw MediaException::unsupported(self::guidance($purpose, $type));
    }

    /** One of pdf | video | heic | image | zip | ole | text | text_legacy | unknown. */
    private static function sniff(string $path, string $head): string
    {
        // Fixed-offset magic first; only then the PDF header, which may sit anywhere in the first 1 KB.
        if (str_starts_with($head, "PK\x03\x04")) {
            return 'zip';
        }
        if (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'ole';
        }
        $brand = self::isoBrand($head);
        if ($brand !== null) {
            return in_array($brand, self::HEIF_BRANDS, true) ? 'heic' : 'video';
        }
        if (str_contains(substr($head, 0, 1024), '%PDF-')) {
            return 'pdf';
        }
        $img = @getimagesize($path);
        if ($img !== false) {
            return in_array($img[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) ? 'image' : 'unknown';
        }
        if (!str_contains($head, "\0") && self::mostlyText($head)) {
            return mb_check_encoding(self::stripBom($head), 'UTF-8') || self::utf8CutAtEnd($head) ? 'text' : 'text_legacy';
        }
        return 'unknown';
    }

    /** The ftyp major brand when the file is ISO-BMFF (ftyp first, or after QuickTime padding atoms). */
    private static function isoBrand(string $head): ?string
    {
        $offset = 0;
        for ($i = 0; $i < 4 && $offset + 12 <= strlen($head); $i++) {
            $size = unpack('N', substr($head, $offset, 4))[1];
            $type = substr($head, $offset + 4, 4);
            if ($type === 'ftyp') {
                return substr($head, $offset + 8, 4);
            }
            if (!in_array($type, ['free', 'skip', 'wide'], true) || $size < 8) {
                return null;
            }
            $offset += $size;
        }
        return null;
    }

    /**
     * Validates an OOXML package and returns its type (docx|xlsx|pptx).
     *
     * @param list<string> $allowed
     */
    private static function office(string $path, array $allowed): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw MediaException::unsupported('That file is damaged: it is not a readable Office document.');
        }
        try {
            $count = $zip->numFiles;
            if ($count < 1 || $count > self::ZIP_MAX_ENTRIES) {
                throw MediaException::unsupported('That Office file has an unusual structure and was rejected.');
            }
            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $st = $zip->statIndex($i);
                if ($st === false) {
                    throw MediaException::unsupported('That Office file is damaged.');
                }
                $name = strtolower((string) $st['name']);
                $sz = (int) ($st['size'] ?? 0);
                $comp = (int) ($st['comp_size'] ?? 0);
                if ($sz < 0 || $comp < 0) {
                    throw MediaException::unsupported('That Office file is damaged.');
                }
                $total += $sz;
                if ($total > self::ZIP_MAX_TOTAL_UNCOMPRESSED) {
                    throw MediaException::unsupported('That Office file expands to more than 48 MB and was rejected. Save it as PDF instead.');
                }
                if ($sz >= self::ZIP_RATIO_MIN_SIZE && $comp >= self::ZIP_RATIO_MIN_COMP && $sz / $comp > self::ZIP_MAX_RATIO) {
                    throw MediaException::unsupported('That Office file has an abnormal compression ratio and was rejected.');
                }
                if (str_ends_with($name, 'vbaproject.bin') || str_ends_with($name, 'vbadata.xml')) {
                    throw MediaException::unsupported(self::macroMessage());
                }
            }
            $ct = $zip->getFromName('[Content_Types].xml', self::CONTENT_TYPES_MAX + 1, \ZipArchive::FL_NOCASE);
            if (!is_string($ct) || $ct === '' || strlen($ct) > self::CONTENT_TYPES_MAX) {
                throw MediaException::unsupported('That file is not a Word, Excel or PowerPoint document.');
            }
            if (stripos($ct, 'macroEnabled') !== false || stripos($ct, 'vbaProject') !== false) {
                throw MediaException::unsupported(self::macroMessage());
            }
            if (preg_match_all('/<Override\b[^>]*>/i', $ct, $m) === false) {
                throw MediaException::unsupported('That file is not a Word, Excel or PowerPoint document.');
            }
            foreach ($m[0] as $tag) {
                $ctype = preg_match('/\bContentType\s*=\s*"([^"]*)"/i', $tag, $a) === 1 ? html_entity_decode($a[1], ENT_QUOTES | ENT_XML1) : '';
                $part = preg_match('/\bPartName\s*=\s*"([^"]*)"/i', $tag, $b) === 1 ? html_entity_decode($b[1], ENT_QUOTES | ENT_XML1) : '';
                foreach (self::OFFICE_MAIN as $ext => [$mainType]) {
                    if (strcasecmp($ctype, $mainType) === 0 && in_array($ext, $allowed, true)) {
                        $entry = ltrim($part, '/');
                        if ($entry !== '' && $zip->locateName($entry, \ZipArchive::FL_NOCASE) !== false) {
                            return $ext;
                        }
                    }
                }
            }
            throw MediaException::unsupported(in_array('xlsx', $allowed, true)
                ? 'That file is not a Word, Excel or PowerPoint document (.docx, .xlsx, .pptx).'
                : 'Choose a Word document (.docx).');
        } finally {
            $zip->close();
        }
    }

    private static function macroMessage(): string
    {
        return 'This file contains macros, which cannot be uploaded. Save a copy without macros (.docx, .xlsx or .pptx) or as PDF.';
    }

    private static function assertUtf8Text(string $path): void
    {
        $bytes = (string) file_get_contents($path);
        if (str_contains($bytes, "\0")) {
            throw MediaException::unsupported('That file is not plain text.');
        }
        if (!mb_check_encoding(self::stripBom($bytes), 'UTF-8')) {
            throw MediaException::unsupported('This text file is not UTF-8. Save it again as UTF-8 (in Excel: CSV UTF-8) and try again.');
        }
    }

    private static function looksCsv(string $path, string $head, string $clientName): bool
    {
        $finfo = class_exists('finfo') ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($head) : '';
        if (in_array($finfo, ['text/csv', 'application/csv'], true)) {
            return true;
        }
        $firstLine = strtok(self::stripBom($head), "\r\n");
        $delimited = is_string($firstLine) && (str_contains($firstLine, ',') || str_contains($firstLine, ';') || str_contains($firstLine, "\t"));
        return $delimited && preg_match('/\.csv$/i', $clientName) === 1;
    }

    private static function mostlyText(string $head): bool
    {
        if ($head === '') {
            return false;
        }
        $ctrl = preg_match_all('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $head);
        return $ctrl !== false && $ctrl <= max(1, intdiv(strlen($head), 100));
    }

    /** A 64 KB head may cut a multi-byte character in half; accept that one case. */
    private static function utf8CutAtEnd(string $head): bool
    {
        for ($cut = 1; $cut <= 3; $cut++) {
            if (mb_check_encoding(self::stripBom(substr($head, 0, -$cut)), 'UTF-8')) {
                return strlen($head) >= 65536;
            }
        }
        return false;
    }

    private static function stripBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }

    private static function guidance(string $purpose, string $type): string
    {
        $office = in_array($type, ['zip', 'ole'], true);
        return match (true) {
            $purpose === 'lesson_document' && $office => 'PowerPoint, Word or Excel? Use File › Save As › PDF first, then upload the PDF.',
            $purpose === 'lesson_document' => 'Choose a PDF file.',
            $purpose === 'lesson_video' => 'Choose an MP4 or MOV video.',
            $purpose === 'docx_import' && $type === 'ole' => 'This is an older Word file (.doc). Open it in Word and save it as .docx first.',
            $purpose === 'docx_import' => 'Choose a Word document (.docx).',
            $purpose === 'resource_file' && $type === 'ole' => 'This is an older Office file. Save it as .docx, .xlsx, .pptx or PDF first.',
            $purpose === 'resource_file' => 'This file type cannot be attached. Use a PDF, Word, Excel or PowerPoint file, a picture, or a text/CSV file.',
            in_array($purpose, UploadPurpose::IMAGE_PURPOSES, true) => 'Choose a JPEG, PNG, WebP or GIF image.',
            default => 'This file type is not supported here.',
        };
    }

    private static function result(string $kind, string $mime, string $ext, array $warnings = [], array $info = [], array $meta = []): array
    {
        return ['kind' => $kind, 'mime' => $mime, 'ext' => $ext, 'warnings' => array_values($warnings), 'info' => array_values($info), 'meta' => $meta];
    }
}
