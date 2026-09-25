<?php
defined('TRAINING_PAGE') || exit;

/*
 * Small server-side render helpers for the Lane D report pages (dashboard, reports,
 * transcript). Every string goes out through nullable_htmlentities(); nothing here echoes
 * data unescaped. Functions are prefixed trr_ and defined once.
 */

if (!function_exists('trr_h')) {
    function trr_h(mixed $s): string
    {
        return nullable_htmlentities($s === null ? '' : (string) $s);
    }

    /** "Sep 8, 2026" (or "Sep 8" in the current year when $short). */
    function trr_date(?string $ymd, bool $short = false): string
    {
        if ($ymd === null || $ymd === '' || !\ITFlow\Training\Core\Clock::isYmd($ymd)) {
            return '';
        }
        $d = new \DateTimeImmutable($ymd . ' 00:00:00');
        if ($short && $d->format('Y') === date('Y')) {
            return $d->format('M j');
        }
        return $d->format('M j, Y');
    }

    /** "12 min ago", "3 h ago", "Yesterday", "4 days ago", else the date. From an ISO string. */
    function trr_rel_time(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            $t = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return '';
        }
        $s = time() - $t->getTimestamp();
        if ($s < 60) {
            return 'Just now';
        }
        if ($s < 3600) {
            return intdiv($s, 60) . ' min ago';
        }
        if ($s < 86400 && $t->format('Y-m-d') === date('Y-m-d')) {
            return intdiv($s, 3600) . ' h ago';
        }
        $days = (int) (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable($t->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d')))->format('%a');
        if ($days <= 1) {
            return 'Yesterday';
        }
        if ($days < 7) {
            return $days . ' days ago';
        }
        return $t->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j');
    }

    /** "9:42 AM" today, else "Sep 8, 9:42 AM". */
    function trr_clock(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            $t = (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return '';
        }
        return $t->format('Y-m-d') === date('Y-m-d') ? $t->format('g:i A') : $t->format('M j, g:i A');
    }

    /** The heat band's icon (spec §5.1: an icon and text, never colour alone). */
    function trr_band_icon(?int $band): string
    {
        return match ($band) {
            4 => 'fas fa-check',
            3 => 'fas fa-minus',
            2 => 'fas fa-arrow-down',
            1 => 'fas fa-exclamation',
            0 => 'fas fa-exclamation-triangle',
            default => '',
        };
    }

    function trr_band_label(?int $band, int $target): string
    {
        return match ($band) {
            4 => 'at target (' . $target . '% or more)',
            3 => 'close to target',
            2 => 'below target',
            1 => 'well below target',
            0 => 'under 70%',
            default => 'not required',
        };
    }

    function trr_avatar(string $initials, string $extra = ''): string
    {
        return '<span class="trr-avatar' . ($extra !== '' ? ' ' . trr_h($extra) : '') . '" aria-hidden="true">' . trr_h($initials) . '</span>';
    }

    /**
     * Pair status chip (PairRules statuses, §3.3). Labels are short for tables; the frozen
     * label string rides along in title.
     */
    function trr_pair_chip(string $status, ?string $label = null, ?string $date = null): string
    {
        [$cls, $icon, $text] = match ($status) {
            'current' => ['ok', 'fas fa-check', 'Current'],
            'expiring' => ['warn', 'far fa-clock', 'Expiring'],
            'retrain_due' => ['warn', 'fas fa-redo', 'Retrain due'],
            'overdue' => ['err', 'fas fa-exclamation-circle', 'Overdue'],
            'due_soon' => ['warn', 'far fa-clock', 'Due soon'],
            'due' => ['info', 'far fa-calendar', 'Assigned'],
            'waived' => ['neutral', 'fas fa-ban', 'Waived'],
            'expired' => ['err', 'fas fa-times-circle', 'Expired'],
            'not_started' => ['neutral', 'far fa-circle', 'Not assigned yet'],
            'completed' => ['ok', 'fas fa-check', 'Completed'],
            'cancelled' => ['neutral', 'fas fa-times', 'Cancelled'],
            default => ['neutral', 'far fa-circle', ucfirst(str_replace('_', ' ', $status))],
        };
        if ($status === 'expired' && $label !== null && str_starts_with($label, 'Revoked')) {
            [$cls, $icon, $text] = ['err', 'fas fa-ban', 'Revoked'];
        }
        if ($date !== null && $date !== '' && in_array($status, ['expiring', 'due_soon', 'due', 'overdue', 'retrain_due'], true)) {
            $text .= ' · ' . trr_date($date, true);
        }
        return '<span class="trr-chip trr-chip--' . $cls . '"' . ($label !== null && $label !== '' ? ' title="' . trr_h($label) . '"' : '') . '>'
            . '<i class="' . $icon . '" aria-hidden="true"></i>' . trr_h($text) . '</span>';
    }

    /** Certificate status chip (PairRules::certStatus). */
    function trr_cert_chip(string $status, ?string $reason = null): string
    {
        [$cls, $icon, $text] = match ($status) {
            'valid' => ['ok', 'fas fa-check', 'Valid'],
            'expiring' => ['warn', 'far fa-clock', 'Expiring'],
            'expired' => ['err', 'fas fa-times-circle', 'Expired'],
            'revoked' => ['neutral', 'fas fa-ban', $reason === 'voided' ? 'Voided' : 'Revoked'],
            default => ['neutral', 'far fa-circle', ucfirst($status)],
        };
        return '<span class="trr-chip trr-chip--' . $cls . '"><i class="' . $icon . '" aria-hidden="true"></i>' . trr_h($text) . '</span>';
    }

    /** Evidence strength letter badge + label. */
    function trr_grade(string $grade, string $label): string
    {
        return '<span class="trr-grade" title="Evidence strength ' . trr_h($grade) . '">' . trr_h($grade) . '</span><span class="trr-grade-label">' . trr_h($label) . '</span>';
    }

    /** The fail-closed scope banner (spec §5 common rules). */
    function trr_scope_banner(): void
    {
        echo '<div class="tr-banner alert alert-info d-flex align-items-center gap-2" role="status">'
            . '<i class="fas fa-user-lock" aria-hidden="true"></i>'
            . '<span>Ask an administrator to grant department access to see people.</span></div>';
    }

    /**
     * A GET <select> for the filter bar: "Label: Value ▾" pill style (mockup).
     *
     * @param list<array{0:string|int|null, 1:string}> $options value => text
     */
    function trr_filter_select(string $name, string $label, array $options, string|int|null $selected, string $icon = ''): string
    {
        $html = '<label class="trr-filter">';
        if ($icon !== '') {
            $html .= '<i class="' . trr_h($icon) . '" aria-hidden="true"></i>';
        }
        $html .= '<span class="trr-filter__label">' . trr_h($label) . ':</span>'
            . '<select name="' . trr_h($name) . '" class="trr-filter__select" data-trr-autosubmit aria-label="' . trr_h($label) . '">';
        foreach ($options as [$value, $text]) {
            $v = $value === null ? '' : (string) $value;
            $sel = ((string) ($selected ?? '')) === $v ? ' selected' : '';
            $html .= '<option value="' . trr_h($v) . '"' . $sel . '>' . trr_h($text) . '</option>';
        }
        return $html . '</select></label>';
    }

    /** A course name short enough for a column header or a list line: parentheses dropped ("Lockout/Tagout (LOTO) Awareness" -> "Lockout/Tagout Awareness"); CSS ellipsis does the rest. */
    function trr_course_short_name(string $name): string
    {
        $short = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\s*\([^)]*\)/u', '', $name)));
        return $short !== '' ? $short : $name;
    }

    /** Builds a same-page URL with the given query parameters (null values dropped). */
    function trr_url(string $path, array $params): string
    {
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
        return $path . ($params === [] ? '' : '?' . http_build_query($params));
    }

    /** A JSON data block for the page script (spec §0: JSON_HEX_* flags). */
    function trr_json_block(string $id, array $data): void
    {
        echo '<script type="application/json" id="' . trr_h($id) . '">'
            . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            . '</script>';
    }

    /** Tiny sparkline as inline SVG (no script). */
    function trr_sparkline(array $values, int $w = 96, int $h = 28): string
    {
        $vals = array_values(array_filter($values, static fn($v) => $v !== null));
        if (count($vals) < 2) {
            return '';
        }
        $min = min($vals);
        $max = max($vals);
        $span = max(1, $max - $min);
        $pts = [];
        $n = count($vals);
        foreach ($vals as $i => $v) {
            $x = round($i * ($w - 4) / ($n - 1) + 2, 1);
            $y = round($h - 3 - ($v - $min) * ($h - 6) / $span, 1);
            $pts[] = $x . ',' . $y;
        }
        $last = explode(',', end($pts));
        return '<svg class="trr-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true" focusable="false">'
            . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round" stroke-linecap="round"/>'
            . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.5" fill="currentColor"/></svg>';
    }
}
