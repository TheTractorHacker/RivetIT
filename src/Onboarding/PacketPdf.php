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

/** TCPDF page with a plain footer (no "Powered by TCPDF") and a slim running head on pages after the first. */
final class PacketPdfDocument extends \TCPDF
{
    public string $footerText = '';
    public string $runningHead = '';
    /** @var array{0:int,1:int,2:int} */
    public array $accent = [27, 61, 99];

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->tcpdflink = false;
        $this->setCellPaddings(0, 0, 0, 0);
    }

    public function Header(): void
    {
        // Page 1 carries the full header band, drawn by PacketPdf itself.
        if ($this->getPage() < 2 || $this->runningHead === '') {
            return;
        }
        $pw = $this->getPageWidth();
        $this->Rect(0, 0, $pw, 2.2, 'F', [], $this->accent);
        $this->setCellPaddings(0, 0, 0, 0);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(96, 108, 122);
        $w = $pw - $this->lMargin - $this->rMargin;
        $this->SetXY($this->lMargin, 7);
        $this->Cell($w * 0.7, 4, $this->runningHead, 0, 0, 'L', false, '', 1);
        $this->Cell($w * 0.3, 4, 'Sign-in details (continued)', 0, 0, 'R');
        $this->Line($this->lMargin, 12.5, $pw - $this->rMargin, 12.5, ['width' => 0.2, 'color' => [212, 219, 228]]);
    }

    public function Footer(): void
    {
        $pw = $this->getPageWidth();
        $ph = $this->getPageHeight();
        $this->setCellPaddings(0, 0, 0, 0);
        $this->Line($this->lMargin, $ph - 14, $pw - $this->rMargin, $ph - 14, ['width' => 0.2, 'color' => [212, 219, 228]]);
        $this->SetY($ph - 12.5);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(96, 108, 122);
        $w = $pw - $this->lMargin - $this->rMargin;
        $this->Cell($w * 0.75, 4, $this->footerText, 0, 0, 'L', false, '', 1);
        // The right-shift alias makes TCPDF re-align the text once the page-number aliases are replaced.
        $this->Cell($w * 0.25, 4, $this->getAliasRightShift() . 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

/**
 * Renders the new-hire onboarding packet. All text goes in through Cell/MultiCell as plain text
 * (never writeHTML), and the PDF is returned as bytes, never written to disk.
 *
 * Layout: a header band (logo or client name, "Welcome, <first name>", start date and manager chips), a short
 * intro, one card per credential (Computer, Email, each Software item) and a "Need help?" box. Every block is
 * measured before it is drawn (on a throwaway in-memory TCPDF, so heights match TCPDF's own wrapping exactly)
 * and moved whole to the next page when it does not fit, so a card never splits across pages.
 */
final class PacketPdf
{
    /** Printed in place of every password when $maskPasswords is on. Fixed length, so it says nothing about the real one. */
    public const MASK = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";
    public const MASK_NOTE = 'Passwords and notes hidden in this copy';
    public const CHANGE_TAG = 'Change on first sign-in';
    public const CHANGE_HINT = "\u{2014} you will be asked to set a new password";
    public const FOOTER = "Confidential \u{2014} contains sign-in details";

    // Palette: restrained, and every tint stays light enough to keep dark text legible in black and white.
    private const ACCENT = [27, 61, 99];        // #1b3d63 header band, badges
    private const ACCENT_SOFT = [196, 211, 229]; // secondary text on the band
    private const CHIP = [49, 86, 128];         // chips on the band
    private const INK = [22, 32, 44];
    private const MUTED = [96, 108, 122];
    private const RULE = [208, 216, 226];
    private const TINT = [236, 241, 247];       // card title strip
    private const PW_FILL = [245, 247, 250];
    private const PW_RULE = [190, 201, 215];
    private const LINK = [29, 78, 137];
    private const AMBER_FILL = [254, 243, 205];
    private const AMBER_RULE = [214, 158, 46];
    private const AMBER_INK = [120, 72, 8];

    /** @var array<string,array{0:int,1:int,2:int}> the palette in use for the packet being built (company colours when supplied; see applyPalette) */
    private static array $p = [];
    /** @var array<string,string> the wording in use (PacketTemplate fields, placeholders already filled) */
    private static array $t = [];

    private const M = 16.0;          // page side margin
    private const TOP = 20.0;        // content top on pages after the first (below the running head)
    private const BOTTOM = 22.0;     // content stops this far above the page bottom (footer zone)
    private const GAP = 4.5;         // between cards
    private const BODY_PAD = 4.0;    // card body, top and bottom
    private const ROW_GAP = 2.7;     // between grid rows
    private const PW_PAD = 2.0;      // password box, top and bottom

    private const F_TITLE = ['dejavusans', 'B', 24];
    private const F_NAME = ['dejavusans', '', 11];
    private const F_CARD = ['dejavusans', 'B', 10.5];
    private const F_LABEL = ['dejavusans', '', 8.5];
    private const F_VALUE = ['dejavusans', '', 10];
    private const F_PW = ['dejavusansmono', '', 13];
    private const F_TAG = ['dejavusans', 'B', 7.5];
    private const F_BODY = ['dejavusans', '', 10.5];

    /**
     * $maskPasswords: the copy kept on a ticket. Every password prints as MASK (the real value never reaches TCPDF)
     * and the page says so; notes print as ******** (the label stays); usernames, links and the change-on-first-sign-in lines print as normal.
     */
    public static function render(PacketRequest $req, string $personName, string $clientName, string $supportContact, ?string $logoBytes = null, bool $compress = true, bool $maskPasswords = false, ?string $accentHex = null, array $template = []): string
    {
        self::applyPalette($accentHex);
        self::$t = $template + \ITFlow\Onboarding\PacketTemplate::defaults();
        // Only PNG/JPEG that PHP itself can parse ever reach TCPDF.
        $logo = null;
        if ($logoBytes !== null && $logoBytes !== '') {
            $info = @getimagesizefromstring($logoBytes);
            // Real pixel sizes only: a forged header can claim absurd dimensions, which would size the logo tile from garbage.
            if (is_array($info) && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
                && $info[0] > 0 && $info[1] > 0 && $info[0] <= 10000 && $info[1] <= 10000) {
                $logo = $logoBytes;
                // TCPDF silently embeds some broken PNGs (an empty logo tile). Where GD is present and the image is
                // modest, insist it actually decodes; otherwise leave it to TCPDF and the rebuild below.
                if (function_exists('imagecreatefromstring') && $info[0] * $info[1] <= 9000000 && @imagecreatefromstring($logoBytes) === false) {
                    $logo = null;
                }
            }
        }
        if ($logo !== null) {
            try {
                return self::build($req, $personName, $clientName, $supportContact, $logo, $compress, $maskPasswords);
            } catch (\Throwable $e) {
                // TCPDF destroys its document before throwing, so it cannot be continued: rebuild from scratch without the logo.
            }
        }
        return self::build($req, $personName, $clientName, $supportContact, null, $compress, $maskPasswords);
    }

    private static function build(PacketRequest $req, string $personName, string $clientName, string $supportContact, ?string $logoBytes, bool $compress, bool $mask): string
    {
        $pdf = new PacketPdfDocument();
        $pdf->SetCompression($compress);
        $pdf->SetCreator(defined('APP_NAME') ? APP_NAME : 'RivetIT');
        $pdf->SetTitle('Welcome to ' . $clientName . ', ' . $personName);
        $pdf->SetMargins(self::M, self::TOP, self::M);
        $pdf->SetAutoPageBreak(false);
        $pdf->accent = self::$p['accent'];
        $pdf->footerText = self::fillT('footer', $personName, $clientName);
        $pdf->runningHead = $personName . ($clientName !== '' ? " \u{00b7} " . $clientName : '');
        $pdf->AddPage();

        // Throwaway document used only to measure wrapped text; it is never output.
        $meter = new PacketPdfDocument();
        $meter->setPrintHeader(false);
        $meter->setPrintFooter(false);
        $meter->SetMargins(0, 0, 0);
        $meter->SetAutoPageBreak(false);
        $meter->AddPage();

        $w = $pdf->getPageWidth() - 2 * self::M;
        $y = self::header($pdf, $meter, $req, $personName, $clientName, $logoBytes) + 8;

        $intro = self::fillT('intro', $personName, $clientName);
        $private = self::fillT('private', $personName, $clientName);
        self::text($pdf, self::F_BODY, self::INK, self::M, $y, $w, $intro);
        $y += self::textH($meter, self::F_BODY, $w, $intro) + 0.6;
        self::text($pdf, ['dejavusans', 'B', 10.5], self::INK, self::M, $y, $w, $private);
        $y += self::textH($meter, ['dejavusans', 'B', 10.5], $w, $private) + 6;

        if ($mask) {
            $y += self::maskNotice($pdf, $meter, $y, $w) + 6;
        }

        $n = 0;
        foreach ($req->lines as $l) {
            $n++;
            $h = self::card(null, $meter, $l, $n, $mask, self::M, 0, $w);
            if ($y + $h > $pdf->getPageHeight() - self::BOTTOM && $y > self::TOP) {
                $pdf->AddPage();
                $y = self::TOP;
            }
            self::card($pdf, $meter, $l, $n, $mask, self::M, $y, $w);
            $y += $h + self::GAP;
        }
        if ($n === 0) {
            $none = 'No sign-in details were added to this packet.';
            self::text($pdf, ['dejavusans', 'I', 10], self::MUTED, self::M, $y, $w, $none);
            $y += self::textH($meter, ['dejavusans', 'I', 10], $w, $none) + self::GAP;
        }

        $h = self::help(null, $meter, $supportContact, self::M, 0, $w);
        if ($y + 2 + $h > $pdf->getPageHeight() - self::BOTTOM && $y > self::TOP) {
            $pdf->AddPage();
            $y = self::TOP - 2;
        }
        self::help($pdf, $meter, $supportContact, self::M, $y + 2, $w);

        unset($meter);
        return $pdf->Output('', 'S');
    }

    /** A template field with its placeholders filled. */
    private static function fillT(string $key, string $personName, string $clientName): string
    {
        $company = defined('APP_NAME') ? (string) APP_NAME : '';
        return \ITFlow\Onboarding\PacketTemplate::fill(self::$t[$key] ?? '', $personName, $clientName, $company);
    }

    /**
     * Builds the palette. With a '#RRGGBB' company accent every colour is derived from it (header band and badges are darkened until white
     * text on them stays readable; the tints are the same hue mixed toward white); otherwise the built-in navy palette is used.
     */
    private static function applyPalette(?string $hex): void
    {
        self::$p = ['accent' => self::ACCENT, 'accent_soft' => self::ACCENT_SOFT, 'chip' => self::CHIP, 'tint' => self::TINT, 'link' => self::LINK];
        if ($hex === null || preg_match('/^#[0-9A-Fa-f]{6}$/D', $hex) !== 1) {
            return;
        }
        $a = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $lum = static function (array $c): float {
            $f = static function (int $v): float { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
            return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
        };
        // White text needs contrast >= 4.5 against the band: (1.05) / (L + 0.05) >= 4.5  =>  L <= 0.1833.
        for ($i = 0; $i < 20 && $lum($a) > 0.18; $i++) {
            $a = [(int) round($a[0] * 0.9), (int) round($a[1] * 0.9), (int) round($a[2] * 0.9)];
        }
        $mix = static fn(array $c, float $t): array => [(int) round($c[0] + (255 - $c[0]) * $t), (int) round($c[1] + (255 - $c[1]) * $t), (int) round($c[2] + (255 - $c[2]) * $t)];
        self::$p = ['accent' => $a, 'accent_soft' => $mix($a, 0.78), 'chip' => $mix($a, 0.14), 'tint' => $mix($a, 0.92), 'link' => $a];
    }

    /** Header band on page 1. Returns its bottom edge. */
    private static function header(PacketPdfDocument $pdf, PacketPdfDocument $meter, PacketRequest $req, string $personName, string $clientName, ?string $logoBytes): float
    {
        $pw = $pdf->getPageWidth();
        $x = self::M;
        $rightW = 58.0;
        $leftW = $pw - 2 * self::M - $rightW - 8;

        $first = preg_split('/\s+/u', trim($personName), 2)[0] ?? '';
        $title = self::fillT('title', $personName, $clientName);
        $showFull = trim($personName) !== '' && trim($personName) !== $first;

        // Measure the left column.
        $ly = 12.0;
        $eyebrowY = $ly;
        $ly += 6;
        $titleY = $ly;
        $ly += self::textH($meter, self::F_TITLE, $leftW, $title);
        $nameY = $ly + 0.5;
        if ($showFull) {
            $ly = $nameY + self::textH($meter, self::F_NAME, $leftW, $personName);
        }
        $chips = [];
        if ($req->startDate !== '') {
            $chips[] = ['Start date', self::niceDate($req->startDate)];
        }
        if ($req->managerName !== '') {
            $chips[] = ['Manager', $req->managerName];
        }
        $chipPlan = self::chipPlan($meter, $chips, $x, $ly + 4, $leftW);
        if ($chipPlan) {
            $ly = max(array_map(static fn(array $c): float => $c['y'] + $c['h'], $chipPlan));
        }

        // Right column: the logo on a white tile, or the client name as text.
        $logo = null;
        $ry = 11.0;
        if ($logoBytes !== null) {
            $info = (array) @getimagesizefromstring($logoBytes);   // already validated by render()
            $pad = 3.0;
            $maxW = $rightW - 2 * $pad;
            $maxH = 16.0;
            $lw = $maxW;
            $lh = $lw * $info[1] / $info[0];
            if ($lh > $maxH) {
                $lh = $maxH;
                $lw = $lh * $info[0] / $info[1];
            }
            $tileW = $lw + 2 * $pad;
            $tileH = $lh + 2 * $pad;
            $logo = ['x' => $pw - self::M - $tileW, 'y' => $ry - 1, 'w' => $tileW, 'h' => $tileH, 'lw' => $lw, 'lh' => $lh, 'pad' => $pad, 'type' => $info[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG'];
            $rBottom = $logo['y'] + $tileH;
        } else {
            $rBottom = $clientName !== '' ? $ry + self::textH($meter, ['dejavusans', 'B', 13], $rightW, $clientName) : $ry;
        }

        $bandH = max($ly, $rBottom) + 9;
        $pdf->Rect(0, 0, $pw, $bandH, 'F', [], self::$p['accent']);

        $pdf->setFontSpacing(0.5);
        self::text($pdf, ['dejavusans', 'B', 7.5], self::$p['accent_soft'], $x, $eyebrowY, $leftW, self::fillT('eyebrow', $personName, $clientName));
        $pdf->setFontSpacing(0);
        self::text($pdf, self::F_TITLE, [255, 255, 255], $x, $titleY, $leftW, $title);
        if ($showFull) {
            self::text($pdf, self::F_NAME, self::$p['accent_soft'], $x, $nameY, $leftW, $personName);
        }
        foreach ($chipPlan as $c) {
            if ($c['wrap']) {
                self::text($pdf, ['dejavusans', '', 8], [255, 255, 255], $c['x'], $c['y'], $c['w'], $c['label'] . ': ' . $c['value']);
                continue;
            }
            $pdf->RoundedRect($c['x'], $c['y'], $c['w'], $c['h'], 1.6, '1111', 'F', [], self::$p['chip']);
            self::text($pdf, ['dejavusans', '', 7.5], self::$p['accent_soft'], $c['x'] + 3, $c['y'] + 1.55, $c['lw'] + 1, $c['label']);
            self::text($pdf, ['dejavusans', 'B', 8], [255, 255, 255], $c['x'] + 3 + $c['lw'] + 1.8, $c['y'] + 1.4, $c['vw'] + 1, $c['value']);
        }

        if ($logo !== null) {
            $pdf->RoundedRect($logo['x'], $logo['y'], $logo['w'], $logo['h'], 1.8, '1111', 'F', [], [255, 255, 255]);
            // TCPDF returns false (no exception) for an image it cannot decode; treat that like a throw so render()
            // rebuilds without the logo instead of leaving an empty tile.
            if ($pdf->Image('@' . $logoBytes, $logo['x'] + $logo['pad'], $logo['y'] + $logo['pad'], $logo['lw'], $logo['lh'], $logo['type']) === false) {
                throw new \RuntimeException('Logo could not be decoded.');
            }
        } elseif ($clientName !== '') {
            self::text($pdf, ['dejavusans', 'B', 13], [255, 255, 255], $pw - self::M - $rightW, $ry, $rightW, $clientName, 'R');
        }
        return $bandH;
    }

    /**
     * Lays chips out left to right, wrapping to a new row when full. A chip wider than the whole column
     * (a very long manager name) becomes plain wrapped text instead.
     * @param list<array{0:string,1:string}> $chips
     * @return list<array{label:string,value:string,x:float,y:float,w:float,h:float,lw:float,vw:float,wrap:bool}>
     */
    private static function chipPlan(PacketPdfDocument $meter, array $chips, float $x0, float $y0, float $maxW): array
    {
        $out = [];
        $x = $x0;
        $y = $y0;
        $h = 6.4;
        foreach ($chips as [$label, $value]) {
            $meter->SetFont('dejavusans', '', 7.5);
            $lw = $meter->GetStringWidth($label);
            $meter->SetFont('dejavusans', 'B', 8);
            $vw = $meter->GetStringWidth($value);
            $cw = 3 + $lw + 1.8 + $vw + 3;
            if ($cw > $maxW) {
                if ($x > $x0) {
                    $y += $h + 2;
                }
                $th = self::textH($meter, ['dejavusans', '', 8], $maxW, $label . ': ' . $value);
                $out[] = ['label' => $label, 'value' => $value, 'x' => $x0, 'y' => $y, 'w' => $maxW, 'h' => $th, 'lw' => $lw, 'vw' => $vw, 'wrap' => true];
                $y += $th + 2;
                $x = $x0;
                continue;
            }
            if ($x > $x0 && $x + $cw > $x0 + $maxW) {
                $x = $x0;
                $y += $h + 2;
            }
            $out[] = ['label' => $label, 'value' => $value, 'x' => $x, 'y' => $y, 'w' => $cw, 'h' => $h, 'lw' => $lw, 'vw' => $vw, 'wrap' => false];
            $x += $cw + 2.5;
        }
        return $out;
    }

    private static function maskNotice(PacketPdfDocument $pdf, PacketPdfDocument $meter, float $y, float $w): float
    {
        $sub = "This is the copy kept on file. The new hire's own copy shows the passwords.";
        $tw = $w - 12;
        $h1 = self::textH($meter, ['dejavusans', 'B', 9.5], $tw, self::MASK_NOTE);
        $h2 = self::textH($meter, ['dejavusans', '', 8.5], $tw, $sub);
        $h = 3.2 + $h1 + 0.6 + $h2 + 3.2;
        $pdf->RoundedRect(self::M, $y, $w, $h, 1.6, '1111', 'DF', ['width' => 0.25, 'color' => self::RULE], [243, 245, 248]);
        $pdf->Rect(self::M, $y + 1.2, 1.2, $h - 2.4, 'F', [], self::MUTED);
        self::text($pdf, ['dejavusans', 'B', 9.5], self::INK, self::M + 6, $y + 3.2, $tw, self::MASK_NOTE);
        self::text($pdf, ['dejavusans', '', 8.5], self::MUTED, self::M + 6, $y + 3.2 + $h1 + 0.6, $tw, $sub);
        return $h;
    }

    /**
     * One credential card. With $pdf null nothing is drawn and only the height is returned (the measuring pass);
     * both passes take every height from the same measurements, so they always agree.
     * @param array{kind:string,name:string,username:string,password:string,uri:string,note:string,change:bool,save:bool} $l
     */
    private static function card(?PacketPdfDocument $pdf, PacketPdfDocument $meter, array $l, int $n, bool $mask, float $x, float $y, float $w): float
    {
        $title = match ($l['kind']) {
            'computer' => 'Your computer',
            'email' => 'Your email',
            default => $l['name'] !== '' ? $l['name'] : 'Software',
        };
        $rows = [];
        if ($l['kind'] === 'computer' && $l['name'] !== '') {
            $rows[] = ['Name', $l['name'], 'text'];
        } elseif ($l['kind'] === 'email' && $l['name'] !== '') {
            $rows[] = ['Address', $l['name'], 'text'];
        }
        if ($l['username'] !== '') {
            $rows[] = ['Username', $l['username'], 'text'];
        }
        if ($l['password'] !== '') {
            $rows[] = ['Password', $mask ? self::MASK : $l['password'], 'password'];
        }
        if ($l['uri'] !== '') {
            $rows[] = ['Link', $l['uri'], 'link'];
        }
        if ($l['note'] !== '') {
            $rows[] = ['Notes', $mask ? '********' : $l['note'], 'text'];
        }
        // A change-on-first-sign-in line with no password still gets its tag (on its own row).
        if ($l['change'] && $l['password'] === '') {
            $rows[] = ['', '', 'tag'];
        }

        $pad = 5.0;
        $labelW = 24.0;
        $vx = $x + $pad + $labelW + 3;
        $vw = $x + $w - $pad - $vx;
        $lineH = static fn(array $f): float => $f[2] / 72 * 25.4 * 1.25;

        // Title strip.
        $badge = 5.6;
        $tx = $x + 4 + $badge + 3;
        $tw = $x + $w - 4 - $tx;
        $titleH = self::textH($meter, self::F_CARD, $tw, $title);
        $stripH = max(9.2, $titleH + 4.8);

        // Rows.
        $tagH = 5.2;
        $plan = [];
        foreach ($rows as [$label, $value, $kind]) {
            if ($kind === 'password') {
                $meter->SetFont(...self::F_PW);
                $inner = min($vw - 6.4, $meter->GetStringWidth($value) + 1);
                $boxW = min($vw, max(46.0, $inner + 6.4));
                $inner = $boxW - 6.4;
                $boxH = self::textH($meter, self::F_PW, $inner, $value) + 2 * self::PW_PAD;
                $h = $boxH + ($l['change'] ? 2 + $tagH : 0);
                $plan[] = compact('label', 'value', 'kind', 'h', 'boxW', 'boxH', 'inner');
            } elseif ($kind === 'tag') {
                $plan[] = ['label' => $label, 'value' => $value, 'kind' => $kind, 'h' => $tagH];
            } else {
                $plan[] = ['label' => $label, 'value' => $value, 'kind' => $kind, 'h' => max($lineH(self::F_LABEL), self::textH($meter, self::F_VALUE, $vw, $value))];
            }
        }
        $bodyH = 0.0;
        if ($plan) {
            $bodyH = self::BODY_PAD + array_sum(array_column($plan, 'h')) + self::ROW_GAP * (count($plan) - 1) + self::BODY_PAD;
        }
        $total = $stripH + $bodyH;
        if ($pdf === null) {
            return $total;
        }

        // Frame, strip and number badge.
        $pdf->RoundedRect($x, $y, $w, $total, 2, '1111', 'F', [], [255, 255, 255]);
        $pdf->RoundedRect($x, $y, $w, $stripH, 2, $bodyH > 0 ? '1100' : '1111', 'F', [], self::$p['tint']);
        if ($bodyH > 0) {
            $pdf->Line($x, $y + $stripH, $x + $w, $y + $stripH, ['width' => 0.25, 'color' => self::RULE]);
        }
        $pdf->RoundedRect($x, $y, $w, $total, 2, '1111', 'D', ['width' => 0.3, 'color' => self::RULE]);
        $firstLine = $lineH(self::F_CARD);
        $ty = $y + ($stripH - $titleH) / 2;
        $cy = $ty + $firstLine / 2;
        $pdf->Circle($x + 4 + $badge / 2, $cy, $badge / 2, 0, 360, 'F', [], self::$p['accent']);
        $pdf->SetFont('dejavusans', 'B', $n > 9 ? 7 : 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($x + 4, $cy - $badge / 2);
        $pdf->Cell($badge, $badge, (string) $n, 0, 0, 'C', false, '', 0, false, 'T', 'M');
        self::text($pdf, self::F_CARD, self::$p['accent'], $tx, $ty, $tw, $title);

        // Label / value grid.
        $ry = $y + $stripH + self::BODY_PAD;
        $labelLine = $lineH(self::F_LABEL);
        foreach ($plan as $p) {
            if ($p['kind'] === 'password') {
                $pwLine = $lineH(self::F_PW);
                self::text($pdf, self::F_LABEL, self::MUTED, $x + $pad, $ry + self::PW_PAD + ($pwLine - $labelLine) / 2, $labelW, $p['label']);
                $pdf->RoundedRect($vx, $ry, $p['boxW'], $p['boxH'], 1.4, '1111', 'DF', ['width' => 0.3, 'color' => self::PW_RULE], self::PW_FILL);
                self::text($pdf, self::F_PW, self::INK, $vx + 3.2, $ry + self::PW_PAD, $p['inner'] + 0.01, $p['value']);
                if ($l['change']) {
                    self::tag($pdf, $vx, $ry + $p['boxH'] + 2, $vw, $tagH);
                }
            } elseif ($p['kind'] === 'tag') {
                self::tag($pdf, $vx, $ry, $vw, $tagH);
            } else {
                $valueLine = $lineH(self::F_VALUE);
                self::text($pdf, self::F_LABEL, self::MUTED, $x + $pad, $ry + ($valueLine - $labelLine) / 2, $labelW, $p['label']);
                self::text($pdf, self::F_VALUE, $p['kind'] === 'link' ? self::$p['link'] : self::INK, $vx, $ry, $vw, $p['value']);
            }
            $ry += $p['h'] + self::ROW_GAP;
        }
        return $total;
    }

    /** The amber change-on-first-sign-in tag: bold phrase, lighter hint. Fixed text, so one line always fits the value column. */
    private static function tag(PacketPdfDocument $pdf, float $x, float $y, float $maxW, float $h): void
    {
        $pdf->SetFont(...self::F_TAG);
        $w1 = $pdf->GetStringWidth(self::CHANGE_TAG) + 0.4;
        $pdf->SetFont('dejavusans', '', 7.5);
        $w2 = $pdf->GetStringWidth(self::CHANGE_HINT) + 0.4;
        $w = min($maxW, 3 + $w1 + 1.4 + $w2 + 3);
        $pdf->RoundedRect($x, $y, $w, $h, 1.2, '1111', 'DF', ['width' => 0.3, 'color' => self::AMBER_RULE], self::AMBER_FILL);
        $pdf->SetTextColor(...self::AMBER_INK);
        $pdf->SetFont(...self::F_TAG);
        $pdf->SetXY($x + 3, $y);
        $pdf->Cell($w1, $h, self::CHANGE_TAG, 0, 0, 'L', false, '', 1, false, 'T', 'M');
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetXY($x + 3 + $w1 + 1.4, $y);
        $pdf->Cell(max(1.0, $w - 3 - $w1 - 1.4 - 3), $h, self::CHANGE_HINT, 0, 0, 'L', false, '', 1, false, 'T', 'M');
    }

    /** "Need help?" box with the support contact line. With $pdf null only the height is returned. */
    private static function help(?PacketPdfDocument $pdf, PacketPdfDocument $meter, string $support, float $x, float $y, float $w): float
    {
        $tx = $x + 7;
        $tw = $w - 7 - 5;
        $head = self::$t['help_heading'];
        $sub = $support !== ''
            ? self::$t['help_text']
            : "If you cannot sign in, or something here doesn't work, ask your manager or IT team.";
        $prepared = 'Packet prepared ' . date('F j, Y');
        $h1 = self::textH($meter, ['dejavusans', 'B', 11], $tw, $head);
        $h2 = self::textH($meter, ['dejavusans', '', 9.5], $tw, $sub);
        $h3 = $support !== '' ? self::textH($meter, ['dejavusans', 'B', 10], $tw, $support) : 0.0;
        $h4 = self::textH($meter, ['dejavusans', '', 7.5], $tw, $prepared);
        $total = 4.5 + $h1 + 1.2 + $h2 + ($h3 > 0 ? 1.4 + $h3 : 0) + 2.2 + $h4 + 4.5;
        if ($pdf === null) {
            return $total;
        }
        $pdf->RoundedRect($x, $y, $w, $total, 2, '1111', 'DF', ['width' => 0.3, 'color' => self::RULE], [243, 246, 250]);
        $pdf->Rect($x, $y + 1.5, 1.4, $total - 3, 'F', [], self::$p['accent']);
        $cy = $y + 4.5;
        self::text($pdf, ['dejavusans', 'B', 11], self::$p['accent'], $tx, $cy, $tw, $head);
        $cy += $h1 + 1.2;
        self::text($pdf, ['dejavusans', '', 9.5], self::INK, $tx, $cy, $tw, $sub);
        $cy += $h2;
        if ($h3 > 0) {
            $cy += 1.4;
            self::text($pdf, ['dejavusans', 'B', 10], self::INK, $tx, $cy, $tw, $support);
            $cy += $h3;
        }
        self::text($pdf, ['dejavusans', '', 7.5], self::MUTED, $tx, $cy + 2.2, $tw, $prepared);
        return $total;
    }

    /** Plain-text block at an absolute position (MultiCell, never HTML). */
    private static function text(PacketPdfDocument $pdf, array $font, array $color, float $x, float $y, float $w, string $txt, string $align = 'L'): void
    {
        $pdf->SetFont(...$font);
        $pdf->SetTextColor(...$color);
        $pdf->MultiCell($w, 0, $txt, 0, $align, false, 1, $x, $y, true, 0, false, true, 0, 'T', false);
    }

    /** Height TCPDF's own wrapping gives $txt in a $w-wide MultiCell, measured on the throwaway document. */
    private static function textH(PacketPdfDocument $meter, array $font, float $w, string $txt): float
    {
        $meter->SetFont(...$font);
        $meter->MultiCell($w, 0, $txt, 0, 'L', false, 1, 0, 10, true, 0, false, true, 0, 'T', false);
        return $meter->GetY() - 10;
    }

    /** "Tuesday, December 1, 2026" for a Y-m-d date; anything else prints as typed. */
    private static function niceDate(string $s): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return date('l, F j, Y', mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
        }
        return $s;
    }
}
