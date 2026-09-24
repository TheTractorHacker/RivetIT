<?php

namespace ITFlow\Training\Media;

/**
 * poppler wrappers for the PDF lesson pipeline (spec §3.3, §4.3).
 *
 *   inspect()      pdfinfo, 10 s: page count and whether the file is encrypted.
 *   renderRange()  pdftoppm -jpeg -jpegopt quality=82,optimize=y -scale-to 1600, 60 s.
 *
 * Both run through Process (argv array, no shell) under prlimit (512 MB address space, 90 s
 * CPU), so a hostile PDF cannot take the machine down with it.
 *
 * PASSWORDS. A USER password (needed to open the file at all) makes pdfinfo exit non-zero
 * with "Incorrect password" - that is a 415 pdf_password, because we cannot render what we
 * cannot open. An OWNER password only restricts print/copy/edit ("Encrypted: yes (print:no
 * copy:no ...)"); poppler opens and renders such a file normally, and learners only ever see
 * our page images, so it is accepted with an info note (spec §4.1).
 */
final class PdfPager
{
    private const PRLIMIT = ['/usr/bin/prlimit', '--as=536870912', '--cpu=90', '--'];
    private const PDFINFO = '/usr/bin/pdfinfo';
    private const PDFTOPPM = '/usr/bin/pdftoppm';
    private const INFO_TIMEOUT_MS = 10000;
    private const RENDER_TIMEOUT_MS = 60000;

    public const PASSWORD_MESSAGE = 'This PDF needs a password to open. Save an unlocked copy.';

    /**
     * @return array{pages:int, owner_encrypted:bool}
     * @throws MediaException 415 pdf_password | unsupported_type
     */
    public static function inspect(string $pdfPath): array
    {
        $pdfPath = self::checkedPath($pdfPath);
        $r = Process::run(array_merge(self::PRLIMIT, [self::PDFINFO, '-enc', 'UTF-8', $pdfPath]), dirname($pdfPath), self::INFO_TIMEOUT_MS);
        if ($r['timedout']) {
            throw MediaException::unsupported('That PDF took too long to read. Save it again from the original program and retry.');
        }
        if ($r['code'] !== 0) {
            if (stripos($r['stderr'], 'password') !== false) {
                throw new MediaException(415, 'pdf_password', self::PASSWORD_MESSAGE);
            }
            throw MediaException::unsupported('That file is not a readable PDF.');
        }
        if (preg_match('/^Pages:\s*(\d+)/mi', $r['stdout'], $m) !== 1) {
            throw MediaException::unsupported('That file is not a readable PDF.');
        }
        $pages = (int) $m[1];
        if ($pages < 1) {
            throw MediaException::unsupported('That PDF has no pages.');
        }
        return [
            'pages' => $pages,
            'owner_encrypted' => preg_match('/^Encrypted:\s*yes/mi', $r['stdout']) === 1,
        ];
    }

    /**
     * Renders pages $first..$last into $scratch (a directory from Process::makeScratchDir()).
     *
     * @return array<int, string> page number => absolute JPEG path, for every page produced
     * @throws MediaException 415 when poppler fails or times out
     */
    public static function renderRange(string $pdfPath, int $first, int $last, string $scratch): array
    {
        $pdfPath = self::checkedPath($pdfPath);
        if ($first < 1 || $last < $first) {
            throw new \InvalidArgumentException('PdfPager::renderRange: bad page range');
        }
        $scratchReal = realpath($scratch);
        if ($scratchReal === false || !is_dir($scratchReal)) {
            throw new \InvalidArgumentException('PdfPager::renderRange: scratch directory missing');
        }
        $argv = array_merge(self::PRLIMIT, [
            self::PDFTOPPM, '-jpeg', '-jpegopt', 'quality=82,optimize=y', '-scale-to', '1600',
            '-f', (string) $first, '-l', (string) $last, $pdfPath, $scratchReal . '/pg',
        ]);
        $r = Process::run($argv, $scratchReal, self::RENDER_TIMEOUT_MS);
        if ($r['timedout']) {
            throw MediaException::unsupported("Page $first of this PDF took too long to prepare. Save the PDF again (File › Save As › PDF) and retry.");
        }
        if ($r['code'] !== 0) {
            if (stripos($r['stderr'], 'password') !== false) {
                throw new MediaException(415, 'pdf_password', self::PASSWORD_MESSAGE);
            }
            error_log('Training PdfPager: pdftoppm exited ' . $r['code'] . ': ' . substr(preg_replace('/[^\x20-\x7E]+/', ' ', $r['stderr']), 0, 300));
            throw MediaException::unsupported('The pages of this PDF could not be prepared.');
        }
        $out = [];
        foreach (glob($scratchReal . '/pg-*.jpg') ?: [] as $f) {
            if (preg_match('#/pg-0*([1-9][0-9]*)\.jpg$#', $f, $m) === 1) {
                $n = (int) $m[1];
                if ($n >= $first && $n <= $last && is_file($f) && !is_link($f)) {
                    $out[$n] = $f;
                }
            }
        }
        ksort($out);
        return $out;
    }

    private static function checkedPath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw MediaException::unsupported('That file is not a readable PDF.');
        }
        return $real;
    }
}
