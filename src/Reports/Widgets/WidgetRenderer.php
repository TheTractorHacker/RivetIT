<?php

namespace ITFlow\Reports\Widgets;

/** Turns WidgetRegistry::data() output into escaped HTML. Everything user-controlled goes through esc(). */
final class WidgetRenderer
{
    private static function esc($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function render(?array $d): string
    {
        if ($d === null) {
            return '<div class="text-muted">Not available.</div>';
        }
        switch ($d['type']) {
            case 'stat':
                return '<div class="display-6 fw-bold">' . self::esc($d['value']) . '</div><div class="text-muted">' . self::esc($d['caption'] ?? '') . '</div>';
            case 'bars':
                if (empty($d['items'])) {
                    return '<div class="text-muted">' . self::esc($d['empty'] ?? 'Nothing to show.') . '</div>';
                }
                $max = max(1, max(array_column($d['items'], 'value')));
                $h = '';
                foreach ($d['items'] as $i) {
                    $w = (int) round($i['value'] / $max * 100);
                    $h .= '<div class="d-flex align-items-center mb-1"><div class="text-truncate" style="width:38%" title="' . self::esc($i['label']) . '">' . self::esc($i['label']) . '</div>'
                        . '<div class="flex-fill px-2"><div class="progress" style="height:.6rem"><div class="progress-bar" role="progressbar" style="width:' . $w . '%"></div></div></div>'
                        . '<div class="text-end fw-bold" style="min-width:2.5rem">' . (int) $i['value'] . '</div></div>';
                }
                return $h;
            case 'table':
                if (empty($d['rows'])) {
                    return '<div class="text-muted">' . self::esc($d['empty'] ?? 'Nothing to show.') . '</div>';
                }
                $h = '<div class="table-responsive-sm"><table class="table table-sm mb-0"><thead><tr>';
                foreach ($d['columns'] as $c) {
                    $h .= '<th>' . self::esc($c) . '</th>';
                }
                $h .= '</tr></thead><tbody>';
                foreach ($d['rows'] as $r) {
                    $h .= '<tr>';
                    foreach ($r['cells'] as $n => $cell) {
                        $txt = self::esc($cell);
                        // Only same-site relative links are ever emitted.
                        if ($n === 0 && !empty($r['href']) && strpos($r['href'], '/agent/') === 0) {
                            $txt = '<a href="' . self::esc($r['href']) . '">' . $txt . '</a>';
                        }
                        $h .= '<td>' . $txt . '</td>';
                    }
                    $h .= '</tr>';
                }
                return $h . '</tbody></table></div>';
            case 'series':
                $max = max(1, max(array_merge($d['a'], $d['b'])));
                $n = count($d['labels']);
                $w = 600; $hgt = 120; $bw = $w / max(1, $n);
                $svg = '<svg viewBox="0 0 ' . $w . ' ' . ($hgt + 16) . '" class="w-100" role="img" aria-label="' . self::esc($d['a_label'] . ' versus ' . $d['b_label']) . '">';
                for ($i = 0; $i < $n; $i++) {
                    $ah = $d['a'][$i] / $max * $hgt;
                    $bh = $d['b'][$i] / $max * $hgt;
                    $x = $i * $bw;
                    $svg .= '<rect x="' . round($x + 1, 1) . '" y="' . round($hgt - $ah, 1) . '" width="' . round($bw / 2 - 1.5, 1) . '" height="' . round($ah, 1) . '" fill="#0d6efd"><title>' . self::esc($d['labels'][$i] . ': ' . $d['a_label'] . ' ' . $d['a'][$i]) . '</title></rect>';
                    $svg .= '<rect x="' . round($x + $bw / 2, 1) . '" y="' . round($hgt - $bh, 1) . '" width="' . round($bw / 2 - 1.5, 1) . '" height="' . round($bh, 1) . '" fill="#198754"><title>' . self::esc($d['labels'][$i] . ': ' . $d['b_label'] . ' ' . $d['b'][$i]) . '</title></rect>';
                }
                $svg .= '<line x1="0" y1="' . $hgt . '" x2="' . $w . '" y2="' . $hgt . '" stroke="#adb5bd" stroke-width="1"/>';
                $svg .= '</svg>';
                return $svg . '<div class="d-flex justify-content-between small text-muted"><span>' . self::esc($d['labels'][0] ?? '') . '</span><span>' . self::esc($d['labels'][$n - 1] ?? '') . '</span></div><div class="small text-muted"><span style="color:#0d6efd">&#9632;</span> ' . self::esc($d['a_label']) . ' (' . array_sum($d['a']) . ') &nbsp; <span style="color:#198754">&#9632;</span> ' . self::esc($d['b_label']) . ' (' . array_sum($d['b']) . ')</div>';
        }
        return '';
    }
}
