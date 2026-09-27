<?php

namespace ITFlow\Training\Certificates\Pdf;

use ITFlow\Training\Certificates\CertQr;

/**
 * Certificate PDF (Phase 5 spec §5.2, S1; Certificate mockup): one US Letter landscape page from
 * Upstream's CertificateDTO (P2's CertificateView data, never recomputed here).
 *
 *   training records   "Certificate of Completion ... This certifies that ... has successfully completed"
 *   external / paper   "Training record" + "External card recorded" / "Paper record on file": never "certifies"
 *   documents          refused (acknowledgments have no certificate; the endpoint answers 404)
 *
 * Overlays: voided -> diagonal REVOKED + "Revoked on {date}: {reason}"; retrain -> SUPERSEDED +
 * "Retrain required"; expired -> "Expired {date}" line, no watermark; sample -> SAMPLE, no QR.
 * The QR is drawn only from $qrUrl (Upstream\CertTokens::verifyUrlForCompletion: null when the token
 * would not verify, or the public check is switched off), else "Record #id · sha12".
 * Spanish when $lang is 'es' (the endpoint passes the record's language unless ?lang= overrides).
 * Every string is drawn as plain text (Cell/MultiCell); nothing here uses writeHTML().
 */
final class CertificatePdf extends TrainingPdf
{
    private const W = 279.4;
    private const H = 215.9;

    private const STR = [
        'en' => [
            'title' => 'Certificate of Completion', 'certifies' => 'This certifies that', 'completed' => 'has successfully completed',
            'record_title' => 'Training record', 'external' => 'External card recorded', 'paper' => 'Paper record on file',
            'cert_no' => 'CERTIFICATE NO.', 'record_no_caps' => 'RECORD NO.', 'record' => 'RECORD', 'record_no' => 'Record no.',
            'issued' => 'ISSUED', 'expires' => 'EXPIRES', 'no_expiry' => 'No expiry', 'scan' => 'Scan to verify',
            'signature' => 'Authorized signature', 'score' => 'Score', 'version' => 'Version', 'issued_by' => 'Issued by',
            'card_no' => 'Card no.', 'trainer' => 'Trainer', 'evaluator' => 'Evaluator', 'reg_ref' => 'Regulation reference',
            'revoked_mark' => 'REVOKED', 'superseded_mark' => 'SUPERSEDED', 'sample_mark' => 'SAMPLE',
            'revoked_on' => 'Revoked on {date}: {reason}', 'revoked' => 'Revoked: {reason}', 'retrain' => 'Retrain required', 'expired_on' => 'Expired {date}',
            'qr_here' => 'QR code',
        ],
        'es' => [
            'title' => 'Certificado de finalización', 'certifies' => 'Se certifica que', 'completed' => 'ha completado satisfactoriamente',
            'record_title' => 'Registro de capacitación', 'external' => 'Tarjeta externa registrada', 'paper' => 'Registro en papel archivado',
            'cert_no' => 'CERTIFICADO N.º', 'record_no_caps' => 'REGISTRO N.º', 'record' => 'REGISTRO', 'record_no' => 'Registro n.º',
            'issued' => 'EMITIDO', 'expires' => 'VENCE', 'no_expiry' => 'Sin vencimiento', 'scan' => 'Escanee para verificar',
            'signature' => 'Firma autorizada', 'score' => 'Puntaje', 'version' => 'Versión', 'issued_by' => 'Emitido por',
            'card_no' => 'Tarjeta n.º', 'trainer' => 'Instructor', 'evaluator' => 'Evaluador', 'reg_ref' => 'Referencia normativa',
            'revoked_mark' => 'REVOCADO', 'superseded_mark' => 'REEMPLAZADO', 'sample_mark' => 'MUESTRA',
            'revoked_on' => 'Revocado el {date}: {reason}', 'revoked' => 'Revocado: {reason}', 'retrain' => 'Requiere volver a capacitarse', 'expired_on' => 'Venció el {date}',
            'qr_here' => 'Código QR',
        ],
    ];

    /** P2's "how it was done" lines in Spanish (unknown lines stay as P2 wrote them). */
    private const METHOD_ES = [
        'Online course + signed attestation' => 'Curso en línea + firma de conformidad',
        'Online course + PIN attestation' => 'Curso en línea + confirmación con PIN',
        'Online course' => 'Curso en línea',
        'Instructor-led session' => 'Sesión con instructor',
        'Written exam + practical evaluation' => 'Examen escrito + evaluación práctica',
        'Instructor-led session + practical evaluation' => 'Sesión con instructor + evaluación práctica',
        'Online course + instructor-led session' => 'Curso en línea + sesión con instructor',
        'Online course + practical evaluation' => 'Curso en línea + evaluación práctica',
        'Practical evaluation' => 'Evaluación práctica',
    ];

    private string $lang = 'en';

    /**
     * @param array $cert  Upstream CertificateDTO (spec §3.1)
     * @param array $brand Certificates\Brand::load()
     * @return string the PDF bytes
     */
    public static function render(array $cert, array $brand, ?string $qrUrl, string $lang = 'en', bool $sample = false): string
    {
        if (($cert['kind'] ?? 'training') !== 'training') {
            throw new \InvalidArgumentException('CertificatePdf: acknowledgment records have no certificate');
        }
        $lang = $lang === 'es' ? 'es' : 'en';
        $s = self::STR[$lang];
        $pdf = new self('L', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->lang = $lang;
        $number = self::plain($cert['cert_number'] ?? null);
        $person = self::plain($cert['person_name'] ?? '');
        $pdf->setup(($sample ? $s['sample_mark'] . ' · ' : '') . ($number !== '' ? $number . ' · ' : '') . $person, (string) ($brand['company_name'] ?? ''));
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->AddPage();

        $pdf->frame();
        $pdf->head($cert, $brand, $s, $number);
        $status = is_array($cert['status'] ?? null) ? $cert['status'] : ['code' => 'valid', 'reason' => null];
        $voided = is_array($cert['voided'] ?? null) ? $cert['voided'] : null;
        $retrain = !$voided && ($status['code'] ?? '') === 'revoked' && ($status['reason'] ?? null) === 'retrain_required';

        $statusLine = null;
        if ($voided !== null || (($status['code'] ?? '') === 'revoked' && !$retrain)) {
            $reason = self::plain($voided['reason'] ?? '');
            $on = self::longDate($voided['on'] ?? null, $lang);
            $statusLine = $on !== '' ? strtr($s['revoked_on'], ['{date}' => $on, '{reason}' => $reason]) : strtr($s['revoked'], ['{reason}' => $reason]);
            $statusLine = rtrim($statusLine, ': ');
        } elseif ($retrain) {
            $statusLine = $s['retrain'];
        } elseif (($status['code'] ?? '') === 'expired') {
            $statusLine = strtr($s['expired_on'], ['{date}' => self::longDate($cert['expires_on'] ?? null, $lang)]);
        }

        if (!empty($cert['is_external'])) {
            $pdf->externalBody($cert, $s, $number, $statusLine);
        } else {
            $pdf->trainingBody($cert, $s, $statusLine);
        }
        $pdf->bottom($cert, $brand, $s, $number, $sample ? null : $qrUrl, $sample);

        if ($sample) {
            $pdf->watermark($s['sample_mark'], [93, 111, 118]);
        } elseif ($voided !== null || (($status['code'] ?? '') === 'revoked' && !$retrain)) {
            $pdf->watermark($s['revoked_mark'], self::RED);
        } elseif ($retrain) {
            $pdf->watermark($s['superseded_mark'], self::RED);
        }
        return $pdf->Output('', 'S');
    }

    /** A made-up certificate for Training settings > Certificates > "Download sample". */
    public static function sampleCert(): array
    {
        return [
            'completion_id' => 0, 'contact_id' => 0, 'course_id' => 0, 'kind' => 'training', 'cert_number' => 'LMS-0000-000000',
            'person_name' => 'Jordan Sample', 'course_name' => 'Lockout/Tagout — Authorized Employee', 'course_code' => null,
            'regulation_ref' => '1910.147(c)(7)', 'regulation_line' => null, 'revision_number' => 3, 'method' => 'online',
            'method_label' => 'Online course + signed attestation', 'evidence_letter' => 'A', 'completed_on' => date('Y-m-d'),
            'expires_on' => date('Y-m-d', strtotime('+1 year')), 'score_pct' => '92.00', 'is_external' => false, 'external_issuer' => null,
            'external_ref' => null, 'trainer_or_evaluator' => null, 'language' => 'en',
            'status' => ['code' => 'valid', 'reason' => null, 'label' => 'Valid'], 'voided' => null, 'recorded_at_utc' => null, 'row_sha12' => null,
        ];
    }

    // ---- drawing ------------------------------------------------------------------------------

    private function frame(): void
    {
        // Guilloche rosette behind the text (24 light ellipses), then the two frame rectangles.
        for ($i = 0; $i < 24; $i++) {
            $this->Ellipse(self::W / 2, 104, 80, 25, $i * 7.5, 0, 360, 'D', ['width' => 0.12, 'color' => [226, 233, 236], 'cap' => 'round'], [], 16);
        }
        $this->Rect(7.4, 7.4, self::W - 14.8, self::H - 14.8, 'D', ['all' => ['width' => 0.4, 'color' => [63, 77, 90]]]);
        $this->Rect(9.5, 9.5, self::W - 19, self::H - 19, 'D', ['all' => ['width' => 0.2, 'color' => self::RULE]]);
        $st = ['width' => 0.8, 'color' => self::RED, 'cap' => 'square'];
        $i = 6.0;
        $L = 12.7;
        foreach ([[$i, $i, 1, 1], [self::W - $i, $i, -1, 1], [$i, self::H - $i, 1, -1], [self::W - $i, self::H - $i, -1, -1]] as [$x, $y, $dx, $dy]) {
            $this->Line($x, $y, $x + $dx * $L, $y, $st);
            $this->Line($x, $y, $x, $y + $dy * $L, $st);
        }
    }

    private function head(array $cert, array $brand, array $s, string $number): void
    {
        $logo = null;
        $path = $brand['logo_path'] ?? null;
        if (is_string($path) && is_file($path) && filesize($path) <= 5 * 1024 * 1024) {
            $logo = (string) file_get_contents($path);
        }
        $this->imageFit($logo, 20, 16, 40, 14, 'L');

        $company = mb_strtoupper(self::plain($brand['company_name'] ?? ''), 'UTF-8');
        if ($company !== '') {
            $this->SetFont('dejavusans', 'B', 9.5);
            $this->setFontSpacing(0.55);
            $this->SetTextColor(63, 77, 90);
            $this->SetXY(70, 20);
            $this->Cell(self::W - 140, 6, $company, 0, 0, 'C', false, '', 1);
            $this->setFontSpacing(0);
        }

        $external = !empty($cert['is_external']);
        $this->SetTextColor(...self::MUTED);
        $this->SetFont('dejavusans', 'B', 7);
        $this->setFontSpacing(0.3);
        $this->SetXY(self::W - 20 - 70, 18.5);
        if ($number !== '') {
            $this->Cell(70, 4, $external ? $s['record_no_caps'] : $s['cert_no'], 0, 0, 'R');
            $this->setFontSpacing(0);
            $this->SetFont('dejavusansmono', '', 9.5);
            $this->SetTextColor(...self::INK);
            $this->SetXY(self::W - 20 - 70, 23);
            $this->Cell(70, 5, $number, 0, 0, 'R');
        } else {
            $this->Cell(70, 4, $s['record'], 0, 0, 'R');
            $this->setFontSpacing(0);
            $this->SetFont('dejavusansmono', '', 9.5);
            $this->SetTextColor(...self::INK);
            $this->SetXY(self::W - 20 - 70, 23);
            $this->Cell(70, 5, '#' . (int) ($cert['completion_id'] ?? 0), 0, 0, 'R');
        }
    }

    private function trainingBody(array $cert, array $s, ?string $statusLine): void
    {
        $this->SetTextColor(...self::INK);
        $this->SetFont('dejavuserif', '', 34);
        $this->SetXY(20, 46);
        $this->Cell(self::W - 40, 16, $s['title'], 0, 0, 'C', false, '', 1);
        $this->divider(67.5);

        $this->centreText($s['certifies'], 74, 11, '', self::MUTED);
        $this->name(self::plain($cert['person_name'] ?? ''), 81, 30, 200);
        $this->Line(self::W / 2 - 60, 97, self::W / 2 + 60, 97, ['width' => 0.2, 'color' => self::RULE]);
        $this->centreText($s['completed'], 100.5, 11, '', self::MUTED);

        $y = $this->course(self::plain($cert['course_name'] ?? ''), 107, 19) + 2.5;

        $bits = [];
        $score = self::score($cert['score_pct'] ?? null);
        if ($score !== null) {
            $bits[] = $s['score'] . ' ' . $score . '%';
        }
        if (($cert['revision_number'] ?? null) !== null) {
            $bits[] = $s['version'] . ' ' . (int) $cert['revision_number'];
        }
        $method = self::plain($cert['components_line'] ?? $cert['method_label'] ?? '');
        if ($method !== '') {
            $bits[] = $this->lang === 'es' ? (self::METHOD_ES[$method] ?? $method) : $method;
        }
        if ($bits !== []) {
            $this->centreText(implode('  ·  ', $bits), $y, 10, '', [50, 64, 72]);
            $y += 6.5;
        }
        if ($statusLine !== null) {
            $this->centreText($statusLine, $y, 9.5, 'B', self::RED, 200);
            $y += 6;
        }
        $this->regulation($cert, $s, $y);
    }

    private function externalBody(array $cert, array $s, string $number, ?string $statusLine): void
    {
        $this->SetTextColor(...self::INK);
        $this->SetFont('dejavuserif', '', 30);
        $this->SetXY(20, 44);
        $this->Cell(self::W - 40, 14, $s['record_title'], 0, 0, 'C', false, '', 1);

        $badge = ($cert['method'] ?? '') === 'legacy_paper' ? $s['paper'] : $s['external'];
        $this->SetFont('dejavusans', 'B', 9);
        $bw = $this->GetStringWidth($badge) + 10;
        $this->RoundedRect((self::W - $bw) / 2, 62, $bw, 7, 3.5, '1111', 'F', [], [224, 242, 254]);
        $this->SetTextColor(7, 89, 133);
        $this->SetXY((self::W - $bw) / 2, 62);
        $this->Cell($bw, 7, $badge, 0, 0, 'C');

        $this->name(self::plain($cert['person_name'] ?? ''), 75, 26, 200);
        $this->Line(self::W / 2 - 60, 90, self::W / 2 + 60, 90, ['width' => 0.2, 'color' => self::RULE]);

        $y = $this->course(self::plain($cert['course_name'] ?? ''), 94, 17) + 4;

        $facts = [];
        $issuer = self::plain($cert['external_issuer'] ?? null);
        if ($issuer !== '') {
            $facts[] = $s['issued_by'] . ' ' . $issuer;
        }
        $ref = self::plain($cert['external_ref'] ?? null);
        if ($ref !== '') {
            $facts[] = $s['card_no'] . ' ' . $ref;
        }
        if ($number !== '') {
            $facts[] = $s['record_no'] . ' ' . $number;
        }
        foreach ($facts as $f) {
            $this->centreText($f, $y, 10.5, '', [50, 64, 72], 220);
            $y += 6;
        }
        if ($statusLine !== null) {
            $this->centreText($statusLine, $y + 1, 9.5, 'B', self::RED, 200);
            $y += 7;
        }
        $this->regulation($cert, $s, $y + 1);
    }

    /** P2's regulation line exactly (English); otherwise, and always in Spanish, the neutral reference. */
    private function regulation(array $cert, array $s, float $y): void
    {
        $line = self::plain($cert['regulation_line'] ?? null);
        $ref = self::plain($cert['regulation_ref'] ?? null);
        if ($this->lang === 'es' || $line === '') {
            $line = $ref !== '' ? $s['reg_ref'] . ': ' . $ref : '';
        }
        if ($line !== '') {
            $this->centreText($line, $y, 8.5, '', self::MUTED, 220);
        }
    }

    private function bottom(array $cert, array $brand, array $s, string $number, ?string $qrUrl, bool $sample = false): void
    {
        $lang = $this->lang;
        $this->field(22, 50, self::longDate($cert['completed_on'] ?? null, $lang), $s['issued']);
        $exp = ($cert['expires_on'] ?? null) !== null ? self::longDate($cert['expires_on'], $lang) : $s['no_expiry'];
        $this->field(80, 50, $exp, $s['expires']);

        $trainer = self::plain($cert['trainer_or_evaluator'] ?? null);
        if ($trainer !== '') {
            $label = ($cert['method'] ?? '') === 'evaluation' ? $s['evaluator'] : $s['trainer'];
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(...self::MUTED);
            $this->SetXY(22, 186);
            $this->Cell(108, 4, $label . ': ' . $trainer, 0, 0, 'L', false, '', 1);
        }

        // Signatory (Training settings > Certificates): image fitted into 60 x 15 mm, a rule, then "name, title".
        $sx = 140;
        $sw = 70;
        $drawn = $this->imageFit($brand['signer_png'] ?? null, $sx + 2, 160, 60, 15, 'L');
        $this->Line($sx, 177, $sx + $sw, 177, ['width' => 0.2, 'color' => self::RULE]);
        $name = self::plain($brand['signer_name'] ?? null);
        $title = self::plain($brand['signer_title'] ?? null);
        $this->SetXY($sx, 178.5);
        if ($name !== '') {
            $this->SetTextColor(...self::INK);
            $this->SetFont('dejavusans', 'B', 8.5);
            $nw = $this->GetStringWidth($name . ($title !== '' ? ',' : ''));
            $this->Cell(min($nw + 0.5, $sw), 4.5, $name . ($title !== '' ? ',' : ''), 0, 0, 'L', false, '', 1);
            if ($title !== '' && $nw < $sw - 8) {
                $this->SetFont('dejavusans', '', 8.5);
                $this->Cell($sw - $nw - 0.5, 4.5, ' ' . $title, 0, 0, 'L', false, '', 1);
            }
        } else {
            $this->SetTextColor(...self::MUTED);
            $this->SetFont('dejavusans', '', 7.5);
            $this->Cell($sw, 4.5, $drawn ? '' : $s['signature'], 0, 0, 'L');
        }

        // Verify block.
        $qx = 226;
        $size = 26.0;
        if ($qrUrl !== null && $qrUrl !== '') {
            CertQr::draw($this, $qrUrl, $qx + 2, 149, $size);
            $this->SetTextColor(...self::INK);
            $this->SetFont('dejavusans', 'B', 7);
            $this->SetXY($qx - 6, 176.5);
            $this->Cell($size + 16, 3.5, $s['scan'], 0, 0, 'C', false, '', 1);
            if ($number !== '') {
                $this->SetFont('dejavusansmono', '', 7);
                $this->SetTextColor(...self::MUTED);
                $this->SetXY($qx - 6, 180.5);
                $this->Cell($size + 16, 3.5, $number, 0, 0, 'C');
            }
        } elseif ($sample) {
            // Where the QR code goes: a dashed box, so the preview shows the layout without a working code.
            $this->Rect($qx + 2, 149, $size, $size, 'D', ['all' => ['width' => 0.25, 'color' => self::RULE, 'dash' => '1.5,1.2']]);
            $this->SetFont('dejavusans', '', 6.5);
            $this->SetTextColor(...self::MUTED);
            $this->SetXY($qx + 2, 149 + $size / 2 - 2);
            $this->Cell($size, 4, $s['qr_here'], 0, 0, 'C', false, '', 1);
        } else {
            $sha = self::plain($cert['row_sha12'] ?? null);
            $this->SetFont('dejavusansmono', '', 7);
            $this->SetTextColor(...self::MUTED);
            $this->SetXY($qx - 12, 178.5);
            $this->Cell($size + 22, 3.5, 'Record #' . (int) ($cert['completion_id'] ?? 0) . ($sha !== '' ? ' · ' . $sha : ''), 0, 0, 'C', false, '', 1);
        }
    }

    private function field(float $x, float $w, string $value, string $caption): void
    {
        $this->SetTextColor(...self::INK);
        $this->SetFont('dejavusans', 'B', 10.5);
        $this->SetXY($x, 170.5);
        $this->Cell($w, 5, $value, 0, 0, 'L', false, '', 1);
        $this->Line($x, 177, $x + $w, 177, ['width' => 0.2, 'color' => self::RULE]);
        $this->SetFont('dejavusans', 'B', 7);
        $this->setFontSpacing(0.45);
        $this->SetTextColor(...self::MUTED);
        $this->SetXY($x, 178.5);
        $this->Cell($w, 3.5, $caption, 0, 0, 'L');
        $this->setFontSpacing(0);
    }

    private function divider(float $y): void
    {
        $c = self::W / 2;
        $st = ['width' => 0.25, 'color' => [191, 201, 207]];
        $this->Line($c - 46, $y, $c - 5, $y, $st);
        $this->Line($c + 5, $y, $c + 46, $y, $st);
        $d = 1.7;
        $this->Polygon([$c, $y - $d, $c + $d, $y, $c, $y + $d, $c - $d, $y], 'F', [], self::RED);
    }

    /**
     * The course name in bold, at most 2 lines of 220 mm: the font shrinks from $maxPt (down to 12 pt)
     * until it fits, then a third line is cut. Returns the y below it.
     */
    private function course(string $text, float $y, float $maxPt): float
    {
        $w = 220.0;
        $pt = $maxPt;
        $this->SetTextColor(...self::INK);
        $this->SetFont('dejavusans', 'B', $pt);
        while ($pt > 12 && $this->getNumLines($text, $w, false, true, 0, 0) > 2) {
            $pt -= 0.5;
            $this->SetFont('dejavusans', 'B', $pt);
        }
        $lh = $pt * 0.47;
        $this->SetXY((self::W - $w) / 2, $y);
        $this->MultiCell($w, $lh, $text, 0, 'C', false, 1, null, null, true, 0, false, false, 2 * $lh + 0.1, 'T', false);
        return $y + $lh * min(2, max(1, $this->getNumLines($text, $w, false, true, 0, 0)));
    }

    /** The person's name, shrinking from $maxPt to 18 pt until it fits $maxW mm (then squeezed). */
    private function name(string $name, float $y, float $maxPt, float $maxW): void
    {
        $this->SetTextColor(...self::INK);
        $pt = $maxPt;
        $this->SetFont('dejavuserif', '', $pt);
        while ($pt > 18 && $this->GetStringWidth($name) > $maxW) {
            $pt -= 1;
            $this->SetFont('dejavuserif', '', $pt);
        }
        $this->SetXY((self::W - $maxW) / 2, $y);
        $this->Cell($maxW, 14, $name, 0, 0, 'C', false, '', 1, false, 'T', 'C');
    }

    private function centreText(string $text, float $y, float $pt, string $style, array $rgb, float $w = 220): void
    {
        $this->SetFont('dejavusans', $style, $pt);
        $this->SetTextColor(...$rgb);
        $this->SetXY((self::W - $w) / 2, $y);
        $this->Cell($w, $pt * 0.5, $text, 0, 0, 'C', false, '', 1);
    }

    private function watermark(string $word, array $rgb): void
    {
        $this->SetAlpha(0.15);
        $this->StartTransform();
        $this->Rotate(30, self::W / 2, self::H / 2);
        $this->SetFont('dejavusans', 'B', 72);
        $this->SetTextColor(...$rgb);
        $w = $this->GetStringWidth($word) + 10;
        $this->SetXY((self::W - $w) / 2, self::H / 2 - 16);
        $this->Cell($w, 32, $word, 0, 0, 'C');
        $this->StopTransform();
        $this->SetAlpha(1);
    }
}
