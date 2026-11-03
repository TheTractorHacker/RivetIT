<?php
/**
 * "Answer first" strip for report pages: one plain-English sentence that states what the report says,
 * so a manager gets the point before reading a chart or table.
 *   reportHeadline('fa-chart-line', 'October was your busiest month: 63 tickets', '+37% on September.', 'warning');
 * Tones: primary | success | warning | danger | secondary. All text is escaped here. Not rendered for CSV/print-only output.
 */
function reportHeadline($icon, $headline, $detail = '', $tone = 'primary', $stats = [])
{
    static $css_done = false;
    if (!empty($GLOBALS['report_export_csv'])) {
        return;
    }
    $tones = ['primary', 'success', 'warning', 'danger', 'secondary'];
    $tone = in_array($tone, $tones, true) ? $tone : 'primary';
    $nonce = htmlspecialchars($GLOBALS['csp_nonce'] ?? '', ENT_QUOTES);
    if (!$css_done) {
        $css_done = true;
        echo "<style nonce=\"$nonce\">
        .rpt-headline { display:flex; align-items:center; gap:1rem; padding:.9rem 1.1rem; margin-bottom:1rem; background:var(--bs-body-bg,#fff); border:1px solid var(--bs-border-color,#dee2e6); border-left-width:4px; border-radius:.5rem; }
        .rpt-headline .rpt-hl-icon { width:2.5rem; height:2.5rem; border-radius:50%; display:flex; align-items:center; justify-content:center; flex:0 0 auto; }
        .rpt-headline .rpt-hl-text { min-width:0; flex:1 1 auto; }
        .rpt-headline .rpt-hl-title { font-size:1.1rem; font-weight:600; line-height:1.3; }
        .rpt-headline .rpt-hl-detail { color:var(--bs-secondary-color,#6c757d); font-size:.875rem; }
        .rpt-headline .rpt-hl-stats { display:flex; gap:1.5rem; flex-wrap:wrap; margin-left:auto; }
        .rpt-headline .rpt-hl-stat { text-align:right; white-space:nowrap; }
        .rpt-headline .rpt-hl-stat b { display:block; font-size:1.25rem; line-height:1.2; }
        .rpt-headline .rpt-hl-stat span { font-size:.75rem; color:var(--bs-secondary-color,#6c757d); text-transform:uppercase; letter-spacing:.03em; }
        @media (max-width:768px){ .rpt-headline { flex-wrap:wrap; } .rpt-headline .rpt-hl-stats { margin-left:0; width:100%; } .rpt-headline .rpt-hl-stat { text-align:left; } }
        </style>";
    }
    $fallback = ['primary' => '#2563eb', 'success' => '#16a34a', 'warning' => '#d97706', 'danger' => '#dc2626', 'secondary' => '#64748b'][$tone];
    $c = 'var(--tblr-' . $tone . ', ' . $fallback . ')';
    echo "<div class=\"rpt-headline\" style=\"border-left-color:$c\" role=\"status\">";
    echo "<div class=\"rpt-hl-icon\" style=\"background:color-mix(in srgb, $c 14%, transparent);color:$c\"><i class=\"fas fa-fw " . htmlspecialchars($icon, ENT_QUOTES) . "\"></i></div>";
    echo "<div class=\"rpt-hl-text\"><div class=\"rpt-hl-title\">" . htmlspecialchars($headline, ENT_QUOTES) . "</div>";
    if ($detail !== '') {
        echo "<div class=\"rpt-hl-detail\">" . htmlspecialchars($detail, ENT_QUOTES) . "</div>";
    }
    echo "</div>";
    if ($stats) {
        echo "<div class=\"rpt-hl-stats\">";
        foreach ($stats as $label => $value) {
            echo "<div class=\"rpt-hl-stat\"><b>" . htmlspecialchars((string) $value, ENT_QUOTES) . "</b><span>" . htmlspecialchars((string) $label, ENT_QUOTES) . "</span></div>";
        }
        echo "</div>";
    }
    echo "</div>";
}
