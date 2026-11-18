<?php
declare(strict_types=1);

namespace ITFlow\Onboarding;

// TCPDF is bundled in plugins/TCPDF, loaded in exception mode (same as ReportPdfDocument).
if (!class_exists('TCPDF', false)) {
    if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
        define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
    }
    require_once dirname(__DIR__, 2) . '/plugins/TCPDF/tcpdf.php';
}

/** TCPDF page with a plain footer (no "Powered by TCPDF"). */
final class PacketPdfDocument extends \TCPDF
{
    public string $footerText = '';

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false;
    }

    public function Header(): void
    {
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(100, 116, 120);
        $w = $this->getPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.75, 4, $this->footerText, 0, 0, 'L', false, '', 1);
        $this->Cell($w * 0.25, 4, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

/**
 * Renders the new-hire onboarding packet. All text goes in through Cell/MultiCell as plain text
 * (never writeHTML), and the PDF is returned as bytes, never written to disk.
 */
final class PacketPdf
{
    private const SECTIONS = ['computer' => '1. Your computer', 'email' => '2. Your email', 'software' => '3. Software and accounts'];

    public static function render(PacketRequest $req, string $personName, string $clientName, string $supportContact, ?string $logoBytes = null, bool $compress = true): string
    {
        // Only PNG/JPEG that PHP itself can parse ever reach TCPDF.
        $logo = null;
        if ($logoBytes !== null && $logoBytes !== '') {
            $info = @getimagesizefromstring($logoBytes);
            if (is_array($info) && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                $logo = $logoBytes;
            }
        }
        if ($logo !== null) {
            try {
                return self::build($req, $personName, $clientName, $supportContact, $logo, $compress);
            } catch (\Throwable $e) {
                // TCPDF destroys its document before throwing, so it cannot be continued: rebuild from scratch without the logo.
            }
        }
        return self::build($req, $personName, $clientName, $supportContact, null, $compress);
    }

    private static function build(PacketRequest $req, string $personName, string $clientName, string $supportContact, ?string $logoBytes, bool $compress): string
    {
        $pdf = new PacketPdfDocument();
        $pdf->SetCompression($compress);
        $pdf->SetCreator(defined('APP_NAME') ? APP_NAME : 'RivetIT');
        $pdf->SetTitle('Welcome to ' . $clientName);
        $pdf->SetMargins(18, 18, 18);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->footerText = 'Confidential - contains sign-in details. ' . ($supportContact !== '' ? 'Questions? ' . $supportContact . '. ' : '') . 'Generated ' . date('Y-m-d H:i');
        $pdf->AddPage();
        $w = $pdf->getPageWidth() - 36;

        if ($logoBytes !== null) {
            $pdf->Image('@' . $logoBytes, 18, 14, 0, 16);
            $pdf->SetY(34);
        }

        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetTextColor(20, 30, 40);
        $pdf->MultiCell($w, 9, 'Welcome to ' . $clientName . ', ' . $personName, 0, 'L', false, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(60, 70, 80);
        if ($req->startDate !== '') {
            $pdf->MultiCell($w, 6, 'Start date: ' . $req->startDate, 0, 'L', false, 1);
        }
        if ($req->managerName !== '') {
            $pdf->MultiCell($w, 6, 'Manager: ' . $req->managerName, 0, 'L', false, 1);
        }
        $pdf->Ln(4);

        foreach (self::SECTIONS as $kind => $title) {
            $lines = array_values(array_filter($req->lines, static fn(array $l): bool => $l['kind'] === $kind));
            if (!$lines) {
                continue;
            }
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
            }
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(20, 30, 40);
            $pdf->MultiCell($w, 8, $title, 0, 'L', false, 1);
            foreach ($lines as $l) {
                self::line($pdf, $l, $w);
            }
            $pdf->Ln(3);
        }

        return $pdf->Output('', 'S');
    }

    /** @param array{kind:string,name:string,username:string,password:string,uri:string,note:string,change:bool,save:bool} $l */
    private static function line(PacketPdfDocument $pdf, array $l, float $w): void
    {
        if ($pdf->GetY() > 245) {
            $pdf->AddPage();
        }
        $pdf->SetTextColor(40, 50, 60);
        if ($l['name'] !== '') {
            $pdf->SetFont('dejavusans', 'B', 10.5);
            $pdf->MultiCell($w, 6, $l['name'], 0, 'L', false, 1);
        }
        $pdf->SetFont('dejavusans', '', 9.5);
        if ($l['uri'] !== '') {
            $pdf->MultiCell($w, 5, 'Link: ' . $l['uri'], 0, 'L', false, 1);
        }
        if ($l['username'] !== '') {
            $pdf->MultiCell($w, 5, 'Username: ' . $l['username'], 0, 'L', false, 1);
        }
        if ($l['password'] !== '') {
            $pdf->MultiCell($w, 5, 'Password:', 0, 'L', false, 1);
            $pdf->SetFont('dejavusansmono', '', 10);
            $pdf->SetFillColor(240, 243, 246);
            $pdf->MultiCell($w, 7, $l['password'], 1, 'L', true, 1, '', '', true, 0, false, true, 0, 'M');
            $pdf->SetFont('dejavusans', '', 9.5);
        }
        if ($l['note'] !== '') {
            $pdf->MultiCell($w, 5, 'Note: ' . $l['note'], 0, 'L', false, 1);
        }
        if ($l['change']) {
            $pdf->SetFont('dejavusans', 'I', 9);
            $pdf->MultiCell($w, 5, 'Change on first sign-in', 0, 'L', false, 1);
        }
        $pdf->Ln(3);
    }
}
