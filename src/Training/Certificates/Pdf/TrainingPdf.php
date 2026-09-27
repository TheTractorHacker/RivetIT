<?php

namespace ITFlow\Training\Certificates\Pdf;

// TCPDF is not a Composer package here (plugins/TCPDF, 6.11.3). Loaded once, in exception mode:
// its autoconfig would otherwise define K_TCPDF_THROW_EXCEPTION_ERROR false, and Error() would die().
if (!class_exists('TCPDF', false)) {
    if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
        define('K_TCPDF_THROW_EXCEPTION_ERROR', true);   // exceptions, never die()
    }
    require_once dirname(__DIR__, 4) . '/plugins/TCPDF/tcpdf.php';
}

/**
 * Base for the Training PDFs (Phase 5 spec §3.3, S1/S2): the single abstract class Phase 5 allows.
 *
 * Rules for every subclass (spec §3.3 "Writing into PDFs", §8 "PDF content"):
 *   - data reaches writeHTML() only through esc(); everything else is Cell/MultiCell/Text (plain text);
 *   - K_TCPDF_CALLS_IN_HTML stays false (TCPDF's default), so no <tcpdf> tags run;
 *   - images go in only as Image('@' . $bytes): no URL fetches, no author HTML;
 *   - PDFs are returned as bytes (Output('', 'S')) and never written to disk.
 */
abstract class TrainingPdf extends \TCPDF
{
    protected const INK = [22, 35, 42];        // #16232a
    protected const MUTED = [93, 111, 118];    // #5d6f76
    protected const RULE = [154, 167, 177];    // #9aa7b1
    protected const RED = [220, 38, 38];       // #dc2626

    protected string $footerLeft = '';
    protected string $pageWord = 'Page';
    protected string $ofWord = 'of';

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(...self::MUTED);
        $w = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.78, 5, $this->footerLeft, 0, 0, 'L', false, '', 1);
        $this->Cell($w * 0.22, 5, $this->pageWord . ' ' . $this->getAliasNumPage() . ' ' . $this->ofWord . ' ' . $this->getAliasNbPages(), 0, 0, 'R');
    }

    /** The only path from data into writeHTML(). */
    protected static function esc(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Printable text: valid UTF-8, no control characters (TCPDF would draw them as boxes). */
    protected static function plain(?string $s): string
    {
        $s = mb_scrub((string) $s, 'UTF-8');
        return trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', $s));
    }

    /** "September 23, 2026" / "23 de septiembre de 2026"; '' when not a Y-m-d date. */
    protected static function longDate(?string $ymd, string $lang): string
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        if ($lang === 'es') {
            $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            return (int) $m[3] . ' de ' . $months[(int) $m[2] - 1] . ' de ' . $m[1];
        }
        return date('F j, Y', mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
    }

    /** "Sep 8, 2026" for tables; '' when not a date. */
    protected static function shortDate(?string $ymd): string
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        return date('M j, Y', mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
    }

    /** '92.00' / '92' / '87.5' => '92' / '87.5'; null when not a number. */
    protected static function score(mixed $pct): ?string
    {
        if ($pct === null || $pct === '' || !is_numeric($pct)) {
            return null;
        }
        $s = rtrim(rtrim(sprintf('%.2f', (float) $pct), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /** Common document setup: no header/footer lines of TCPDF's own, subsetted fonts, metadata. */
    protected function setup(string $title, string $author): void
    {
        $this->setPrintHeader(false);
        $this->tcpdflink = false;   // no "Powered by TCPDF" line on the last page
        $this->SetCreator('ITFlow Training');
        $this->SetAuthor(self::plain($author));
        $this->SetTitle(self::plain($title));
        $this->setFontSubsetting(true);
        $this->setImageScale(1);
        $this->setJPEGQuality(90);
    }

    /** Draws image bytes fitted into a box (keeps the aspect ratio); false when the bytes are not an image. */
    protected function imageFit(?string $bytes, float $x, float $y, float $maxW, float $maxH, string $align = 'L'): bool
    {
        if ($bytes === null || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            return false;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] < 1 || $info[1] < 1) {
            return false;
        }
        $ratio = $info[0] / $info[1];
        $w = $maxW;
        $h = $w / $ratio;
        if ($h > $maxH) {
            $h = $maxH;
            $w = $h * $ratio;
        }
        $dx = match ($align) {
            'C' => ($maxW - $w) / 2,
            'R' => $maxW - $w,
            default => 0.0,
        };
        // No try/catch: TCPDF's Error() destroys the document before it throws, so a failure here
        // must end the render (the endpoint answers with its error page), never continue half-built.
        $this->Image('@' . $bytes, $x + $dx, $y + ($maxH - $h) / 2, $w, $h, $info[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG');
        return true;
    }
}
