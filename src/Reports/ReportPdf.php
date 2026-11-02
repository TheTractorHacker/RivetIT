<?php

namespace ITFlow\Reports;

/**
 * Server-side PDF for any report, built from the same rows as the CSV export (ReportExport::fromHtml() or a report's own CSV),
 * so a PDF always shows exactly the figures the CSV and the screen show. Uses the TCPDF already bundled in plugins/TCPDF; no
 * browser, no external binary, no network. Returns bytes (never writes to disk).
 *
 * Safety: every cell goes through htmlspecialchars() before writeHTML(); K_TCPDF_CALLS_IN_HTML stays at its default (false) so no
 * <tcpdf> tags run; no images or URLs are fetched. Size is bounded (MAX_ROWS, MAX_CELL).
 */
final class ReportPdf
{
    public const MAX_ROWS = 5000;
    public const MAX_CELL = 300;
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Split ReportExport-style rows into tables: a one-cell row before a table is its caption, an empty row ends a table, the first
     * row of a table is its header.
     * @return array<int, array{caption:string, header:array, rows:array}>
     */
    public static function tables(array $rows): array
    {
        $tables = [];
        $caption = '';
        $cur = null;
        foreach ($rows as $r) {
            $r = array_values((array) $r);
            if ($r === [] || (count($r) === 1 && trim((string) $r[0]) === '')) {
                if ($cur !== null) {
                    $tables[] = $cur;
                    $cur = null;
                }
                continue;
            }
            if ($cur === null) {
                if (count($r) === 1) {
                    $caption = (string) $r[0];
                    continue;
                }
                $cur = ['caption' => $caption, 'header' => $r, 'rows' => []];
                $caption = '';
                continue;
            }
            $cur['rows'][] = $r;
        }
        if ($cur !== null) {
            $tables[] = $cur;
        }
        if ($caption !== '' && $tables === []) {
            $tables[] = ['caption' => $caption, 'header' => [], 'rows' => []];
        }
        return $tables;
    }

    private static function esc(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', $s));
        if (mb_strlen($s) > self::MAX_CELL) {
            $s = mb_substr($s, 0, self::MAX_CELL - 1) . '...';
        }
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * PDF of a catalogued report from its rows. The title is the catalogue label; the sub-title lists the date range and the
     * universal filters from the (already sanitized) parameters, so a PDF says what it covers.
     * @param array<int, array> $rows @return string PDF bytes
     */
    public static function forReport(string $key, array $rows, \mysqli $db, array $params = []): string
    {
        $params = ReportCatalog::sanitizeParams($key, $params);
        $period = '';
        if (isset($params['dtf'], $params['dtt'])) {
            $period = 'Range: ' . $params['dtf'] . ' to ' . $params['dtt'];
        } elseif (isset($params['canned_date']) && $params['canned_date'] !== 'custom') {
            $period = 'Range: ' . str_replace('_', ' ', $params['canned_date']);
        } elseif (isset($params['year'])) {
            $period = 'Year: ' . $params['year'] . (isset($params['month']) ? ', month ' . $params['month'] : '');
        }
        $filters = ReportFilters::describe($db, array_intersect_key($params, ReportFilters::TICKET));
        return self::render((string) (ReportCatalog::label($key) ?? $key), $rows, ['period' => $period, 'filters' => $filters]);
    }

    /** @param array<int, array> $rows @return string PDF bytes */
    public static function render(string $title, array $rows, array $meta = []): string
    {
        $tables = self::tables($rows);
        $maxCols = 1;
        foreach ($tables as $t) {
            $maxCols = max($maxCols, count($t['header']));
        }
        $landscape = $maxCols > 6;

        $pdf = new ReportPdfDocument($landscape ? 'L' : 'P');
        $brand = (string) ($meta['brand'] ?? (defined('APP_NAME') ? APP_NAME : 'RivetIT'));
        $generated = (string) ($meta['generated'] ?? date('Y-m-d H:i'));
        $pdf->setPrintHeader(false);
        $pdf->SetCreator($brand);
        $pdf->SetAuthor($brand);
        $pdf->SetTitle(self::plainTitle($title));
        $pdf->setFontSubsetting(true);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->footerText = self::plainTitle($brand . ' - ' . $title . ' - generated ' . $generated);
        $pdf->AddPage();

        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetTextColor(22, 35, 42);
        $pdf->MultiCell(0, 8, self::plainTitle($title), 0, 'L', false, 1);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(93, 111, 118);
        $sub = 'Generated ' . $generated;
        if (!empty($meta['period'])) {
            $sub .= ' | ' . $meta['period'];
        }
        $pdf->MultiCell(0, 4, self::plainTitle($sub), 0, 'L', false, 1);
        if (!empty($meta['filters'])) {
            $pdf->MultiCell(0, 4, self::plainTitle('Filters: ' . $meta['filters']), 0, 'L', false, 1);
        }
        $pdf->Ln(3);

        if ($tables === []) {
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetTextColor(22, 35, 42);
            $pdf->MultiCell(0, 6, 'No tabular data for the selected filters.', 0, 'L', false, 1);
        }

        $budget = self::MAX_ROWS;
        foreach ($tables as $t) {
            if ($pdf->GetY() > $pdf->getPageHeight() - 50) { // keep a caption together with the start of its table
                $pdf->AddPage();
            }
            if ($t['caption'] !== '') {
                $pdf->SetFont('dejavusans', 'B', 10);
                $pdf->SetTextColor(22, 35, 42);
                $pdf->MultiCell(0, 6, self::plainTitle($t['caption']), 0, 'L', false, 1);
            }
            if ($t['header'] === []) {
                continue;
            }
            $cols = count($t['header']);
            // Column widths follow the longest text in the column (clamped), as whole percentages.
            $len = array_fill(0, $cols, 4);
            foreach (array_merge([$t['header']], array_slice($t['rows'], 0, 200)) as $r) {
                for ($i = 0; $i < $cols; $i++) {
                    $len[$i] = max($len[$i], min(40, mb_strlen((string) ($r[$i] ?? ''))));
                }
            }
            $sum = array_sum($len);
            $pct = array_map(static fn ($l) => max(4, (int) floor($l / $sum * 100)), $len);

            $html = '<table border="0.4" cellpadding="2.2" cellspacing="0" style="border-color:#9aa7b1"><thead><tr style="background-color:#e8eef0">';
            foreach ($t['header'] as $i => $h) {
                $html .= '<th width="' . $pct[$i] . '%" align="left"><b>' . self::esc((string) $h) . '</b></th>';
            }
            $html .= '</tr></thead>';
            foreach ($t['rows'] as $n => $r) {
                if ($budget-- <= 0) {
                    break;
                }
                $html .= '<tr' . ($n % 2 ? ' style="background-color:#f6f8f9"' : '') . '>';
                for ($i = 0; $i < $cols; $i++) {
                    $v = (string) ($r[$i] ?? '');
                    $html .= '<td width="' . $pct[$i] . '%" align="' . (preg_match('/^-?[\d,]+(\.\d+)?%?$/', $v) ? 'right' : 'left') . '">' . self::esc($v) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(22, 35, 42);
            $pdf->writeHTML($html, true, false, false, false, '');
            $pdf->Ln(3);
            if ($budget <= 0) {
                $pdf->SetFont('dejavusans', 'I', 8);
                $pdf->MultiCell(0, 5, 'Output stopped at ' . self::MAX_ROWS . ' rows. Narrow the filters to see the rest.', 0, 'L', false, 1);
                break;
            }
        }
        return $pdf->Output('', 'S');
    }

    private static function plainTitle(string $s): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($s, 'UTF-8')));
    }
}
