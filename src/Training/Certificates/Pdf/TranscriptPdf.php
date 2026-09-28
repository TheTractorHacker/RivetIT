<?php

namespace ITFlow\Training\Certificates\Pdf;

use ITFlow\Training\Core\Product;

/**
 * Transcript PDF (Phase 5 spec §5.3, S2): the "Download PDF" of P2's transcript page, from Upstream's
 * TranscriptDTO (P2's TranscriptService; statuses and labels are P2's, never recomputed here).
 *
 * US Letter portrait, 15 mm margins. Page 1: logo, company, "Training transcript", the person, "As of",
 * and the summary line. Sections (writeHTML tables whose <thead> repeats on every page): Qualifications,
 * Open assignments, History (voided rows struck through, followed by "Revoked {date} by {who}: {reason}"),
 * Achievements, Evidence strength legend. Footer on every page: "Generated {when} by {user} with {APP_NAME}
 * · Ledger #{seq}/{hash16}" and "Page X of Y".
 *
 * Every data value reaches writeHTML() only through esc(); the markup around it is fixed here.
 */
final class TranscriptPdf extends TrainingPdf
{
    private const STATUS_MARK = ['valid' => '✓', 'expiring' => '!', 'expired' => '×', 'revoked' => '⊘'];
    private const STATUS_COLOR = ['valid' => '#15803d', 'expiring' => '#b45309', 'expired' => '#b91c1c', 'revoked' => '#b91c1c'];
    private const OPEN_STATUS = ['overdue' => ['Overdue', '#b91c1c'], 'due_soon' => ['Due soon', '#b45309'], 'due' => ['Due', '#374151']];

    /**
     * @param array $transcript Upstream TranscriptDTO (spec §3.1)
     * @param array $brand      Certificates\Brand::load()
     * @param array $footer     {generated_by, generated_at_local, ledger_seq, ledger_hash16}
     */
    public static function render(array $transcript, array $brand, array $footer): string
    {
        $p = is_array($transcript['contact'] ?? null) ? $transcript['contact'] : [];
        $name = self::plain($p['name'] ?? '');
        $pdf = new self('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->setup('Training transcript · ' . $name, (string) ($brand['company_name'] ?? ''));
        $ledger = ($footer['ledger_seq'] ?? null) !== null
            ? ' · Ledger #' . (int) $footer['ledger_seq'] . '/' . self::plain((string) ($footer['ledger_hash16'] ?? '')) : '';
        $pdf->footerLeft = 'Generated ' . self::plain((string) ($footer['generated_at_local'] ?? '')) . ' by ' . self::plain((string) ($footer['generated_by'] ?? ''))
            . ' with ' . self::plain(Product::name()) . $ledger;
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetFooterMargin(12);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setCellHeightRatio(1.25);
        $pdf->AddPage();

        $pdf->header1($p, $transcript, $brand);

        $quals = self::rows($transcript['qualifications'] ?? []);
        $history = self::rows($transcript['history'] ?? []);
        $open = self::rows($transcript['open_assignments'] ?? []);
        $ach = self::rows($transcript['achievements'] ?? []);

        $pdf->section('Qualifications', $quals === [] ? null : self::qualTable($quals), 'No training qualifications on file.');
        $pdf->section('Open assignments', $open === [] ? null : self::openTable($open), 'No open assignments.');
        $pdf->section('History', $history === [] ? null : self::historyTable($history), 'No training records on file.');
        $pdf->section('Achievements', $ach === [] ? null : self::achTable($ach), 'No achievements.');
        $pdf->section('Evidence strength', self::legendTable(is_array($transcript['legend'] ?? null) ? $transcript['legend'] : []), '');

        return $pdf->Output('', 'S');
    }

    /** @return list<array> */
    private static function rows(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }

    private function header1(array $p, array $t, array $brand): void
    {
        $logo = null;
        $path = $brand['logo_path'] ?? null;
        if (is_string($path) && is_file($path) && filesize($path) <= 5 * 1024 * 1024) {
            $logo = (string) file_get_contents($path);
        }
        $hasLogo = $this->imageFit($logo, 15, 13, 34, 12, 'L');
        $x = $hasLogo ? 53 : 15;
        $this->SetXY($x, 13.5);
        $this->SetFont('dejavusans', 'B', 8.5);
        $this->setFontSpacing(0.4);
        $this->SetTextColor(...self::MUTED);
        $this->Cell(185.9 - $x + 15, 5, mb_strtoupper(self::plain($brand['company_name'] ?? ''), 'UTF-8'), 0, 1, 'L', false, '', 1);
        $this->setFontSpacing(0);
        $this->SetX($x);
        $this->SetFont('dejavusans', '', 9);
        $this->Cell(100, 5, 'Training transcript', 0, 1, 'L');

        $this->SetY(30);
        $this->SetTextColor(...self::INK);
        $this->SetFont('dejavuserif', '', 20);
        $this->Cell(0, 9, self::plain($p['name'] ?? ''), 0, 1, 'L', false, '', 1);
        $sub = array_values(array_filter([self::plain($p['title'] ?? null), self::plain(is_array($p['department'] ?? null) ? ($p['department']['name'] ?? '') : ($p['department'] ?? null))], static fn($x) => $x !== ''));
        $this->SetFont('dejavusans', '', 9.5);
        $this->SetTextColor(...self::MUTED);
        if ($sub !== []) {
            $this->Cell(0, 5, implode(' · ', $sub), 0, 1, 'L', false, '', 1);
        }
        $this->Cell(0, 5, 'As of ' . self::longDate(is_string($t['as_of'] ?? null) ? $t['as_of'] : null, 'en'), 0, 1, 'L');

        $quals = self::rows($t['qualifications'] ?? []);
        $valid = count(array_filter($quals, static fn($q) => in_array($q['status'] ?? '', ['valid', 'expiring'], true)));
        $open = self::rows($t['open_assignments'] ?? []);
        $overdue = count(array_filter($open, static fn($a) => ($a['status'] ?? '') === 'overdue'));
        $ach = count(self::rows($t['achievements'] ?? []));
        $this->Ln(2);
        $this->SetTextColor(...self::INK);
        $this->SetFont('dejavusans', 'B', 9.5);
        $line = 'Valid qualifications ' . $valid . ' of ' . count($quals) . '  ·  Open assignments ' . count($open) . ' (' . $overdue . ' overdue)  ·  Achievements ' . $ach;
        $this->Cell(0, 6, $line, 'B', 1, 'L', false, '', 1);
        $this->Ln(3);
    }

    private function section(string $title, ?string $tableHtml, string $empty): void
    {
        if ($this->GetY() > $this->getPageHeight() - 45) {
            $this->AddPage();
        }
        $this->SetFont('dejavusans', 'B', 11);
        $this->SetTextColor(...self::INK);
        $this->Cell(0, 7, $title, 0, 1, 'L');
        $this->SetFont('dejavusans', '', 8);
        if ($tableHtml === null) {
            $this->SetTextColor(...self::MUTED);
            $this->Cell(0, 6, $empty, 0, 1, 'L');
        } else {
            $this->writeHTML($tableHtml, true, false, true, false, '');
        }
        $this->Ln(3);
    }

    private static function th(array $cols): string
    {
        $h = '<thead><tr style="background-color:#eef2f3;color:#374151;font-weight:bold;">';
        foreach ($cols as [$label, $w]) {
            $h .= '<th width="' . $w . '%">' . self::esc($label) . '</th>';
        }
        return $h . '</tr></thead>';
    }

    private static function table(string $thead, string $rows): string
    {
        return '<table cellpadding="3" cellspacing="0" border="0" style="font-size:8pt;">' . $thead . '<tbody>' . $rows . '</tbody></table>';
    }

    private static function statusCell(array $q): string
    {
        $st = (string) ($q['status'] ?? '');
        $mark = self::STATUS_MARK[$st] ?? '';
        $label = self::plain($q['status_label'] ?? '') !== '' ? self::plain($q['status_label']) : ucfirst($st);
        return '<span style="color:' . (self::STATUS_COLOR[$st] ?? '#374151') . ';font-weight:bold;">' . self::esc(trim($mark . ' ' . $label)) . '</span>';
    }

    private static function courseCell(array $q): string
    {
        $bits = [];
        if (($q['revision_number'] ?? null) !== null) {
            $bits[] = 'Rev ' . (int) $q['revision_number'];
        }
        if (self::plain($q['validity_label'] ?? null) !== '') {
            $bits[] = self::plain($q['validity_label']);
        }
        $code = self::plain($q['course_code'] ?? null);
        return '<b>' . self::esc(self::plain($q['course_name'] ?? '')) . '</b>' . ($code !== '' ? ' <span style="color:#6b7280;">(' . self::esc($code) . ')</span>' : '')
            . ($bits !== [] ? '<br><span style="color:#6b7280;font-size:7pt;">' . self::esc(implode(' · ', $bits)) . '</span>' : '');
    }

    private static function methodCell(array $q): string
    {
        $l = self::plain($q['evidence_letter'] ?? null);
        return ($l !== '' ? '<b>' . self::esc($l) . '</b> ' : '') . self::esc(self::plain($q['method_label'] ?? ''));
    }

    private static function trainedOn(array $q): string
    {
        return self::shortDate($q['trained_on'] ?? ($q['completed_on'] ?? null)) ?: self::shortDate($q['completed_on'] ?? null);
    }

    private static function qualTable(array $quals): string
    {
        $rows = '';
        foreach ($quals as $i => $q) {
            $score = self::score($q['score_pct'] ?? null);
            $rows .= '<tr nobr="true" style="' . ($i % 2 ? 'background-color:#f8fafb;' : '') . '">'
                . '<td width="20%">' . self::courseCell($q) . '</td>'
                . '<td width="13%">' . self::statusCell($q) . '</td>'
                . '<td width="12%">' . self::methodCell($q) . '</td>'
                . '<td width="6%">' . self::esc($score !== null ? $score . '%' : '—') . '</td>'
                . '<td width="11%">' . self::esc(self::trainedOn($q)) . '</td>'
                . '<td width="11%">' . self::esc(($q['expires_on'] ?? null) !== null ? self::shortDate($q['expires_on']) : 'No expiry') . '</td>'
                . '<td width="12%">' . self::esc(self::plain($q['trainer_or_evaluator'] ?? null) ?: '—') . '</td>'
                . '<td width="15%" style="font-family:dejavusansmono;font-size:7pt;">' . self::esc(self::plain($q['cert_number'] ?? null) ?: '#' . (int) ($q['completion_id'] ?? 0)) . '</td>'
                . '</tr>';
        }
        return self::table(self::th([['Course', 20], ['Status', 13], ['Method', 12], ['Score', 6], ['Trained on', 11], ['Expires', 11], ['Trainer / evaluator', 12], ['Record #', 15]]), $rows);
    }

    private static function openTable(array $open): string
    {
        $rows = '';
        foreach ($open as $i => $a) {
            [$label, $color] = self::OPEN_STATUS[$a['status'] ?? ''] ?? [ucfirst(str_replace('_', ' ', (string) ($a['status'] ?? ''))), '#374151'];
            $why = self::plain($a['reason_label'] ?? null);
            $rows .= '<tr nobr="true" style="' . ($i % 2 ? 'background-color:#f8fafb;' : '') . '">'
                . '<td width="50%"><b>' . self::esc(self::plain($a['course_name'] ?? '')) . '</b>' . ($why !== '' ? '<br><span style="color:#6b7280;font-size:7pt;">' . self::esc($why) . '</span>' : '') . '</td>'
                . '<td width="25%">' . self::esc(self::shortDate($a['due_on'] ?? null)) . '</td>'
                . '<td width="25%"><span style="color:' . $color . ';font-weight:bold;">' . self::esc($label) . '</span></td>'
                . '</tr>';
        }
        return self::table(self::th([['Course', 50], ['Due', 25], ['Status', 25]]), $rows);
    }

    private static function historyTable(array $history): string
    {
        $rows = '';
        foreach ($history as $i => $q) {
            $v = is_array($q['voided'] ?? null) ? $q['voided'] : null;
            $strike = $v !== null ? 'text-decoration:line-through;color:#6b7280;' : '';
            $bg = $i % 2 ? 'background-color:#f8fafb;' : '';
            $score = self::score($q['score_pct'] ?? null);
            $course = self::plain($q['course_name'] ?? '');
            $rows .= '<tr nobr="true" style="' . $bg . '">'
                . '<td width="24%" style="' . $strike . '">' . self::esc($course) . '</td>'
                . '<td width="12%" style="' . $strike . '">' . self::esc(self::shortDate($q['completed_on'] ?? ($q['trained_on'] ?? null))) . '</td>'
                . '<td width="15%" style="' . $strike . '">' . self::methodCell($q) . '</td>'
                . '<td width="6%" style="' . $strike . '">' . self::esc($score !== null ? $score . '%' : '—') . '</td>'
                . '<td width="12%" style="' . $strike . '">' . self::esc(($q['expires_on'] ?? null) !== null ? self::shortDate($q['expires_on']) : 'No expiry') . '</td>'
                . '<td width="15%">' . self::statusCell($q) . '</td>'
                . '<td width="16%" style="font-family:dejavusansmono;font-size:7pt;' . $strike . '">' . self::esc(self::plain($q['cert_number'] ?? null) ?: '#' . (int) ($q['completion_id'] ?? 0)) . '</td>'
                . '</tr>';
            if ($v !== null) {
                $who = self::plain($v['by'] ?? null);
                $line = 'Revoked ' . self::shortDate($v['on'] ?? null) . ($who !== '' ? ' by ' . $who : '') . ': ' . self::plain($v['reason'] ?? '');
                $rows .= '<tr nobr="true" style="' . $bg . '"><td colspan="7" style="color:#b91c1c;font-size:7.5pt;">' . self::esc($line) . '</td></tr>';
            }
        }
        return self::table(self::th([['Course', 24], ['Completed', 12], ['Method', 15], ['Score', 6], ['Expires', 12], ['Status', 15], ['Record #', 16]]), $rows);
    }

    private static function achTable(array $ach): string
    {
        $rows = '';
        foreach ($ach as $i => $a) {
            $how = ($a['how'] ?? '') === 'manual' ? 'Awarded by hand' : 'Automatic';
            $reason = self::plain($a['reason'] ?? null);
            $rows .= '<tr nobr="true" style="' . ($i % 2 ? 'background-color:#f8fafb;' : '') . '">'
                . '<td width="45%"><b>' . self::esc(self::plain($a['name'] ?? '')) . '</b></td>'
                . '<td width="20%">' . self::esc(self::shortDate($a['awarded_on'] ?? null)) . '</td>'
                . '<td width="35%">' . self::esc($how . ($reason !== '' ? ' · ' . $reason : '')) . '</td>'
                . '</tr>';
        }
        return self::table(self::th([['Achievement', 45], ['Awarded', 20], ['How', 35]]), $rows);
    }

    /** Labels::legend(): A..E => [label, detail] (also accepts {label, detail}). */
    private static function legendTable(array $legend): string
    {
        $rows = '';
        foreach ($legend as $k => $v) {
            $label = is_array($v) ? (string) ($v[0] ?? $v['label'] ?? '') : (string) $v;
            $detail = is_array($v) ? (string) ($v[1] ?? $v['detail'] ?? '') : '';
            $rows .= '<tr nobr="true"><td width="6%"><b>' . self::esc((string) $k) . '</b></td><td width="30%">' . self::esc(self::plain($label)) . '</td>'
                . '<td width="64%" style="color:#6b7280;">' . self::esc(self::plain($detail)) . '</td></tr>';
        }
        return self::table(self::th([['', 6], ['Evidence', 30], ['Meaning', 64]]), $rows);
    }
}
