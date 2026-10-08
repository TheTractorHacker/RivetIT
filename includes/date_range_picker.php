<?php

/*
 * Shared date-range picker (RivetCore\Ui\DateRange presets + a Litepicker calendar for "Custom range").
 *
 * dateRangePickerField($range) returns the markup for ONE button showing "Last 30 days  Sep 6 - Oct 5" that opens a popover
 * (grouped preset list + a two-month calendar with From/To). It writes three hidden inputs - canned_date, dtf, dtt - with the
 * exact names and meaning the list pages and includes/filter_header.php have always used, so URLs and bookmarks keep working.
 * For every preset except "custom" dtf/dtt are submitted empty-and-disabled (so a saved "Last 7 days" stays rolling); a custom
 * range carries explicit dates. The behaviour lives in js/date_range_picker.js (loaded once by includes/footer.php and bound
 * by event delegation, so AJAX-loaded modals work too); styles are at the end of css/itflow_custom.css.
 *
 * The picker is only a convenience: the server re-validates everything through dateRangeFromRequest().
 */

use RivetCore\Ui\DateRange;

if (!function_exists('dateRangeDayLabel')) {
    /** "Sep 6 - Oct 5" (years are added when the range is not entirely within the current year). */
    function dateRangeDayLabel(string $from, string $to): string
    {
        $a = new DateTimeImmutable($from);
        $b = new DateTimeImmutable($to);
        $cur = (new DateTimeImmutable('now'))->format('Y');
        $withYear = $a->format('Y') !== $b->format('Y') || $a->format('Y') !== $cur;
        $f = $withYear ? 'M j, Y' : 'M j';
        if ($from === $to) {
            return $a->format($f);
        }

        return $a->format($f) . ' – ' . $b->format($f);
    }
}

if (!function_exists('dateRangePickerField')) {
    /**
     * @param DateRange $current resolved current range (normally $date_range from filter_header.php)
     * @param string    $name    name of the hidden preset input (default 'canned_date'; dtf/dtt keep their names)
     * @param array     $opts    default (preset id the page applies when none is requested, default 'alltime'),
     *                           hide_groups (e.g. ['Upcoming']), dates ([from, to] shown for "all time" when a report bounds it),
     *                           auto_submit (default true), id, label (accessible name), class
     */
    function dateRangePickerField(DateRange $current, string $name = 'canned_date', array $opts = []): string
    {
        static $seq = 0;
        $seq++;
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['id'] ?? ('drp-' . $seq))) ?: 'drp-' . $seq;
        $default = DateRange::isPreset((string) ($opts['default'] ?? 'alltime')) ? (string) ($opts['default'] ?? 'alltime') : 'alltime';
        $hide = array_map('strval', (array) ($opts['hide_groups'] ?? []));
        $auto = ($opts['auto_submit'] ?? true) ? '1' : '0';
        $a11y = (string) ($opts['label'] ?? 'Date range');
        $extraClass = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($opts['class'] ?? '')));

        try {
            $tz = new DateTimeZone(date_default_timezone_get());
        } catch (Exception $ex) {
            $tz = new DateTimeZone('UTC');
        }

        $preset = $current->preset();
        $isCustom = $preset === 'custom';
        $shownFrom = $current->from();
        $shownTo = $current->to();
        if ($current->isAllTime() && !empty($opts['dates'][0]) && !empty($opts['dates'][1])) {
            $shownFrom = (string) $opts['dates'][0];
            $shownTo = (string) $opts['dates'][1];
        }
        $dates = $current->isAllTime() && empty($opts['dates']) ? '' : dateRangeDayLabel($shownFrom, $shownTo);
        $label = $isCustom ? 'Custom range' : $current->label();
        $nonDefault = $isCustom || $preset !== $default;

        // Group the catalogue, honouring hidden groups. The preset list shows each preset's resolved dates beside its name.
        $groups = [];
        foreach (DateRange::presets() as $p) {
            if (in_array($p['group'], $hide, true)) {
                continue;
            }
            $groups[$p['group']][] = $p;
        }

        ob_start();
        ?>
        <div class="drp <?php echo $e($extraClass); ?>" data-drp data-drp-default="<?php echo $e($default); ?>" data-drp-auto="<?php echo $auto; ?>" data-drp-name="<?php echo $e($name); ?>">
            <input type="hidden" name="<?php echo $e($name); ?>" value="<?php echo $e($preset); ?>" class="drp-canned">
            <input type="hidden" name="dtf" value="<?php echo $isCustom ? $e($current->from()) : ''; ?>" class="drp-dtf" <?php echo $isCustom ? '' : 'disabled'; ?>>
            <input type="hidden" name="dtt" value="<?php echo $isCustom ? $e($current->to()) : ''; ?>" class="drp-dtt" <?php echo $isCustom ? '' : 'disabled'; ?>>
            <div class="drp-row">
                <button type="button" class="drp-btn" id="<?php echo $e($id); ?>-btn" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?php echo $e($id); ?>-panel" aria-label="<?php echo $e($a11y); ?>: <?php echo $e($label . ($dates !== '' ? ', ' . $dates : '')); ?>">
                    <i class="far fa-calendar-alt drp-icon" aria-hidden="true"></i>
                    <span class="drp-text"><span class="drp-label"><?php echo $e($label); ?></span><span class="drp-dates"><?php echo $e($dates); ?></span></span>
                    <i class="fas fa-chevron-down drp-caret" aria-hidden="true"></i>
                </button>
                <button type="button" class="drp-clear" aria-label="Clear date range" title="Clear date range" <?php echo $nonDefault ? '' : 'hidden'; ?>><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="drp-panel" id="<?php echo $e($id); ?>-panel" role="dialog" aria-label="<?php echo $e($a11y); ?>" hidden>
                <div class="drp-presets" role="listbox" aria-label="Preset ranges">
                    <?php foreach ($groups as $gname => $items) { ?>
                        <div class="drp-group" role="presentation">
                            <div class="drp-group-title" role="presentation"><?php echo $e($gname); ?></div>
                            <?php foreach ($items as $p) {
                                $pid = $p['id'];
                                $pf = $pt = '';
                                if ($pid !== 'custom') {
                                    $r = DateRange::resolve($pid, null, null, null, $tz, 1);
                                    $pf = $r->from();
                                    $pt = $r->to();
                                }
                                $isSel = $pid === $preset;
                                ?>
                                <button type="button" role="option" class="drp-opt" data-preset="<?php echo $e($pid); ?>" data-from="<?php echo $e($pf); ?>" data-to="<?php echo $e($pt); ?>" aria-selected="<?php echo $isSel ? 'true' : 'false'; ?>" tabindex="<?php echo $isSel ? '0' : '-1'; ?>">
                                    <span class="drp-opt-name"><?php echo $e($p['label']); ?></span>
                                    <?php if ($pid !== 'custom' && $pid !== 'alltime') { ?><span class="drp-opt-dates"><?php echo $e(dateRangeDayLabel($pf, $pt)); ?></span><?php } ?>
                                </button>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
                <div class="drp-custom" <?php echo $isCustom ? '' : 'hidden'; ?>>
                    <div class="drp-inputs">
                        <label class="drp-field"><span>From</span><input type="date" class="form-control drp-from" min="1970-01-01" max="2099-12-31" value="<?php echo $isCustom ? $e($current->from()) : ''; ?>"></label>
                        <label class="drp-field"><span>To</span><input type="date" class="form-control drp-to" min="1970-01-01" max="2099-12-31" value="<?php echo $isCustom ? $e($current->to()) : ''; ?>"></label>
                    </div>
                    <div class="drp-cal" data-first-day="1"></div>
                    <div class="drp-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary drp-cancel">Cancel</button>
                        <button type="button" class="btn btn-sm btn-primary drp-apply">Apply</button>
                    </div>
                </div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
