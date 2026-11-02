<?php

namespace ITFlow\Reports;

// TCPDF is bundled in plugins/TCPDF (6.11.3), not a Composer package. Loaded once, in exception mode (its autoconfig would
// otherwise make Error() die()), the same way the Training PDFs load it.
if (!class_exists('TCPDF', false)) {
    if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
        define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
    }
    require_once dirname(__DIR__, 2) . '/plugins/TCPDF/tcpdf.php';
}

/** TCPDF page furniture for report PDFs: no header, a footer with the brand, the generated time and "Page n of m". */
final class ReportPdfDocument extends \TCPDF
{
    public string $footerText = '';

    public function __construct(string $orientation = 'P')
    {
        parent::__construct($orientation, 'mm', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false; // no "Powered by TCPDF" line
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(100, 116, 120);
        $w = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.75, 4, $this->footerText, 0, 0, 'L', false, '', 1);
        $this->Cell($w * 0.25, 4, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}
