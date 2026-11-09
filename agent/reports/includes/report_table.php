<?php
/*
 * Small rendering helpers for the data reports added in issue #34 (sla_breach_aging, ticket_backlog, asset_lifecycle).
 * Every figure is also drawn as a real <table> with a caption heading, because CSV export, PDF export and the "tables in the
 * email" schedule all read the page's tables (ReportExport::fromHtml()).
 */

if (!function_exists('rpt_table')) {
    /**
     * A captioned table. A cell is plain text (escaped) or ['html' => '<a ...>'] for a trusted, already-escaped fragment.
     * @param string[] $headers
     * @param array<int, array> $rows
     */
    function rpt_table(string $caption, array $headers, array $rows, string $empty = 'Nothing to show for the selected filters.'): void
    {
        echo '<div class="card mb-3"><div class="card-header py-2"><h3 class="card-title mb-0">' . nullable_htmlentities($caption) . '</h3></div>';
        echo '<div class="table-responsive"><table class="table table-striped table-sm mb-0"><thead><tr>';
        foreach ($headers as $h) {
            echo '<th>' . nullable_htmlentities($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="' . count($headers) . '" class="text-muted text-center">' . nullable_htmlentities($empty) . '</td></tr>';
        }
        foreach ($rows as $r) {
            echo '<tr>';
            foreach (array_values($r) as $c) {
                if (is_array($c) && isset($c['html'])) {
                    echo '<td>' . $c['html'] . '</td>';
                } else {
                    $t = (string) ($c ?? '');
                    echo '<td' . (preg_match('/^-?[\d,]+(\.\d+)?%?$/', $t) ? ' class="text-end"' : '') . '>' . nullable_htmlentities($t) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /** Link to a ticket as an already-escaped cell. */
    function rpt_ticket_cell(array $t): array
    {
        $label = nullable_htmlentities(($t['ticket_prefix'] ?? '') . ($t['ticket_number'] ?? ''));
        return ['html' => '<a href="/agent/ticket.php?ticket_id=' . intval($t['ticket_id']) . '">' . $label . '</a>'];
    }

    /** "3d 4h" from hours. */
    function rpt_hours_text($hours): string
    {
        $h = (int) $hours;
        if ($h < 0) {
            return '';
        }
        return $h >= 24 ? intdiv($h, 24) . 'd ' . ($h % 24) . 'h' : $h . 'h';
    }

    /** Horizontal bars (inline CSS, no script) for [label => count]; tokens only so it follows dark mode. */
    function rpt_bars(array $items): void
    {
        $max = 1;
        foreach ($items as $i) {
            $max = max($max, (int) $i['count']);
        }
        echo '<div class="px-3 pb-2">';
        foreach ($items as $i) {
            $w = (int) round(((int) $i['count']) / $max * 100);
            echo '<div class="d-flex align-items-center gap-2 mb-1"><div class="text-nowrap small" style="width:7.5rem">' . nullable_htmlentities($i['label']) . '</div>'
                . '<div class="flex-grow-1" style="background:var(--if-bg);border-radius:3px;height:.9rem"><div style="width:' . $w . '%;height:100%;background:var(--if-primary);border-radius:3px"></div></div>'
                . '<div class="small text-end" style="width:3rem">' . (int) $i['count'] . '</div></div>';
        }
        echo '</div>';
    }
}
