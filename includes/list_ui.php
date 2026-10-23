<?php

/*
 * Shared list UI helpers: sortable column headers, filter chips and the status badge.
 * Pure helpers (no queries). Include with require_once from a list page, after includes/filter_header.php
 * has set $sort / $order / $url_query_strings_sort. The JS twin of the badge colour maths is js/list_ui.js.
 */

if (!function_exists('rivet_hex_rgb')) {

    function rivet_hex_rgb($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $hex)) {
            return null;
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    function rivet_rgb_hex(array $rgb) {
        return sprintf('#%02x%02x%02x', max(0, min(255, round($rgb[0]))), max(0, min(255, round($rgb[1]))), max(0, min(255, round($rgb[2]))));
    }

    function rivet_mix(array $a, array $b, $t) {
        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t];
    }

    function rivet_luminance(array $rgb) {
        $lin = function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $lin($rgb[0]) + 0.7152 * $lin($rgb[1]) + 0.0722 * $lin($rgb[2]);
    }

    function rivet_contrast(array $a, array $b) {
        $la = rivet_luminance($a);
        $lb = rivet_luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /*
     * Soft-tint status badge colours for light and dark, with the text colour pushed toward black (light) or
     * white (dark) until it reaches 4.5:1 on its own tint. Returns a CSS custom-property string for style="".
     */
    function rivet_status_badge_vars($hex) {
        $rgb = rivet_hex_rgb($hex);
        if ($rgb === null) {
            return '';
        }
        $rgb = array_slice($rgb, 0, 3);
        $white = [255, 255, 255];
        $black = [0, 0, 0];
        $surface = [30, 38, 48];

        $bg_l = rivet_mix($rgb, $white, 0.86);
        $fg_l = $rgb;
        for ($t = 0; $t <= 1.0001 && rivet_contrast($fg_l, $bg_l) < 4.5; $t += 0.05) {
            $fg_l = rivet_mix($rgb, $black, $t);
        }
        $bg_d = rivet_mix($rgb, $surface, 0.72);
        $fg_d = $rgb;
        for ($t = 0; $t <= 1.0001 && rivet_contrast($fg_d, $bg_d) < 4.5; $t += 0.05) {
            $fg_d = rivet_mix($rgb, $white, $t);
        }
        return '--sb-dot:' . rivet_rgb_hex($rgb)
            . ';--sb-bg-l:' . rivet_rgb_hex($bg_l) . ';--sb-fg-l:' . rivet_rgb_hex($fg_l) . ';--sb-bd-l:' . rivet_rgb_hex(rivet_mix($rgb, $white, 0.7))
            . ';--sb-bg-d:' . rivet_rgb_hex($bg_d) . ';--sb-fg-d:' . rivet_rgb_hex($fg_d) . ';--sb-bd-d:' . rivet_rgb_hex(rivet_mix($rgb, $surface, 0.5));
    }

    /* One status badge for every list: dot + label, same height, AA text on a soft tint, never clipped. $label is already HTML-escaped. */
    function rivet_status_badge($label, $hex = '', $extra_class = '') {
        $label = (string) $label;
        if ($label === '') {
            return '<span class="status-badge status-badge-empty ' . $extra_class . '">Set status</span>';
        }
        $vars = rivet_status_badge_vars($hex);
        return '<span class="status-badge ' . ($vars === '' ? 'status-badge-neutral ' : '') . $extra_class . '"' . ($vars === '' ? '' : ' style="' . $vars . '"') . '>'
            . '<span class="status-badge-dot" aria-hidden="true"></span><span class="status-badge-label">' . $label . '</span></span>';
    }

    /*
     * A sortable <th>: the whole label is a link with a visible (faint) sort icon on inactive columns, an arrow on the
     * active one and aria-sort. $col must be a key the page's sort whitelist accepts.
     */
    function rivet_sortable_th($label, $col, $th_class = '') {
        global $sort, $order, $url_query_strings_sort;
        $active = (isset($sort) && $sort === $col);
        $dir = $active ? (($order ?? 'ASC') === 'DESC' ? 'descending' : 'ascending') : 'none';
        $icon = !$active ? 'fa-sort' : ($dir === 'descending' ? 'fa-sort-down' : 'fa-sort-up');
        $href = '?' . ($url_query_strings_sort ?? '') . '&sort=' . urlencode($col) . '&order=' . sortLinkOrder($col);
        return '<th class="' . htmlspecialchars($th_class) . '" aria-sort="' . $dir . '">'
            . '<a class="sort-link' . ($active ? ' is-active' : '') . '" href="' . htmlspecialchars($href) . '" title="Sort by ' . htmlspecialchars(strip_tags($label)) . '">'
            . $label . ' <i class="fas ' . $icon . ' sort-icon" aria-hidden="true"></i></a></th>';
    }

    /* Current query string minus one param (or one value of an array param). */
    function rivet_url_without($key, $value = null) {
        $q = $_GET;
        if ($value !== null && isset($q[$key]) && is_array($q[$key])) {
            $q[$key] = array_values(array_filter($q[$key], function ($v) use ($value) { return (string) $v !== (string) $value; }));
            if (!$q[$key]) { unset($q[$key]); }
        } else {
            unset($q[$key]);
        }
        unset($q['page']);
        return '?' . http_build_query($q);
    }

    function rivet_filter_chip($label, $href) {
        return '<span class="filter-chip"><span class="filter-chip-label">' . $label . '</span>'
            . '<a class="filter-chip-x" href="' . htmlspecialchars($href) . '" aria-label="Remove filter ' . htmlspecialchars(strip_tags($label)) . '" title="Remove"><i class="fas fa-times" aria-hidden="true"></i></a></span>';
    }
}
