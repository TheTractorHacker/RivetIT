<?php

/**
 * Small renderers for the shared RMM UI kit (css/itflow_rmm_ui.css, classes .rmmx-*). Everything returned is escaped HTML.
 */

if (!function_exists('rmmxH')) {
    function rmmxH($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * A KPI card. tone: danger | warn | ok | info | neutral. A count of 0 is muted (grey) whatever the tone, so "0 overdue" never looks like an alarm.
 *
 * @param array{icon?:string,label:string,value:int|string,sub?:string,tone?:string,href?:string,active?:bool,title?:string} $k
 */
function rmmxKpi(array $k): string
{
    $zero = (string) $k['value'] === '0' || $k['value'] === 0;
    $cls = 'rmmx-kpi rmmx-tone-' . rmmxH($k['tone'] ?? 'neutral') . ($zero ? ' is-zero' : '') . (!empty($k['active']) ? ' is-active' : '');
    $inner = '<span class="rmmx-kpi-icon" aria-hidden="true"><i class="' . (in_array($k['icon'] ?? '', ['fa-windows', 'fa-linux', 'fa-apple', 'fa-android'], true) ? 'fab' : 'fas') . ' ' . rmmxH($k['icon'] ?? 'fa-circle') . '"></i></span><span class="rmmx-kpi-body"><span class="rmmx-kpi-value d-block">' . rmmxH($k['value'])
        . '</span><span class="rmmx-kpi-label d-block">' . rmmxH($k['label']) . '</span>' . (!empty($k['sub']) ? '<span class="rmmx-kpi-sub d-block">' . rmmxH($k['sub']) . '</span>' : '') . '</span>';
    $title = !empty($k['title']) ? ' title="' . rmmxH($k['title']) . '"' : '';
    if (!empty($k['href'])) {
        return '<a class="' . $cls . '" href="' . rmmxH($k['href']) . '"' . $title . (!empty($k['active']) ? ' aria-current="true"' : '') . '>' . $inner . '</a>';
    }

    return '<div class="' . $cls . '"' . $title . '>' . $inner . '</div>';
}

/** @param list<array<string,mixed>> $kpis */
function rmmxKpiGrid(array $kpis): string
{
    return '<div class="rmmx-kpis">' . implode('', array_map(static fn ($k): string => is_array($k) ? rmmxKpi($k) : (string) $k, $kpis)) . '</div>';
}

/** Page header: title, optional one-paragraph intro (max ~75ch), actions on the right. */
function rmmxHead(string $icon, string $title, string $intro = '', string $actionsHtml = ''): string
{
    return '<div class="rmmx-head"><h1><i class="fas ' . rmmxH($icon) . ' text-secondary" aria-hidden="true"></i>' . rmmxH($title) . '</h1>'
        . ($actionsHtml !== '' ? '<div class="rmmx-head-actions">' . $actionsHtml . '</div>' : '') . ($intro !== '' ? '<p class="rmmx-sub">' . rmmxH($intro) . '</p>' : '') . '</div>';
}

/** A state as a dot and a word (tone: danger | warn | ok | info | neutral). */
function rmmxState(string $text, string $tone = 'neutral'): string
{
    return '<span class="rmmx-state rmmx-tone-' . rmmxH($tone) . '">' . rmmxH($text) . '</span>';
}

/** Severity pill: critical is the one strong colour on the page. */
function rmmxSeverity(string $sev): string
{
    $map = ['critical' => ['rmmx-pill-sev-critical', 'fa-times-circle'], 'error' => ['rmmx-pill-crit', 'fa-times-circle'], 'warning' => ['rmmx-pill-warn', 'fa-exclamation-triangle'], 'info' => ['rmmx-pill-info', 'fa-info-circle']];
    [$cls, $icon] = $map[$sev] ?? ['rmmx-pill-off', 'fa-minus-circle'];

    return '<span class="rmmx-pill ' . $cls . '"><i class="fas ' . $icon . '" aria-hidden="true"></i>' . rmmxH(ucfirst($sev)) . '</span>';
}

/** "3 h ago" with the full timestamp in a tooltip. */
function rmmxWhen(?string $ts): string
{
    if (!$ts || ($t = strtotime($ts)) === false) {
        return '<span class="text-muted">-</span>';
    }
    $d = time() - $t;
    $rel = $d < 60 ? 'just now' : ($d < 3600 ? intdiv($d, 60) . ' min ago' : ($d < 86400 ? intdiv($d, 3600) . ' h ago' : ($d < 86400 * 14 ? intdiv($d, 86400) . ' d ago' : date('Y-m-d', $t))));

    return '<span class="rmmx-when" title="' . rmmxH(date('Y-m-d H:i:s', $t)) . '">' . rmmxH($rel) . '</span>';
}

/**
 * Removable filter chips: [label => removeUrl]; "Clear all" when there is more than one.
 *
 * @param array<string,string> $chips
 */
function rmmxChips(array $chips, string $clearUrl, string $countText = ''): string
{
    if ($chips === [] && $countText === '') {
        return '';
    }
    $o = '<div class="rmmx-chips" role="group" aria-label="Active filters">' . ($countText !== '' ? '<span class="rmmx-result-count">' . rmmxH($countText) . '</span>' : '');
    foreach ($chips as $label => $remove) {
        $o .= '<span class="rmmx-chip">' . rmmxH($label) . '<a href="' . rmmxH($remove) . '" aria-label="Remove filter ' . rmmxH($label) . '" title="Remove"><i class="fas fa-times" aria-hidden="true"></i></a></span>';
    }
    if (count($chips) > 1) {
        $o .= '<a class="small" href="' . rmmxH($clearUrl) . '">Clear all</a>';
    }

    return $o . '</div>';
}

function rmmxEmpty(string $icon, string $title, string $text = '', string $actionsHtml = ''): string
{
    return '<div class="rmmx-empty"><i class="fas ' . rmmxH($icon) . '" aria-hidden="true"></i><div class="rmmx-empty-title">' . rmmxH($title) . '</div>' . ($text !== '' ? '<div>' . rmmxH($text) . '</div>' : '')
        . ($actionsHtml !== '' ? '<div class="mt-3">' . $actionsHtml . '</div>' : '') . '</div>';
}

/** Platform with an OS icon and a name (never a bare glyph). $language is the script language: powershell/cmd -> Windows, bash/sh -> Linux. */
function rmmxPlatform(string $language): string
{
    $l = strtolower($language);
    if (str_contains($l, 'power') || str_contains($l, 'cmd') || str_contains($l, 'bat') || str_contains($l, 'win')) {
        return '<span class="rmmx-os"><i class="fab fa-windows" aria-hidden="true"></i><span>Windows</span></span>';
    }
    if (str_contains($l, 'bash') || str_contains($l, 'sh') || str_contains($l, 'python') || str_contains($l, 'linux')) {
        return '<span class="rmmx-os"><i class="fab fa-linux" aria-hidden="true"></i><span>Linux</span></span>';
    }

    return '<span class="rmmx-os"><i class="fas fa-desktop" aria-hidden="true"></i><span>' . rmmxH($language !== '' ? $language : 'Any') . '</span></span>';
}
