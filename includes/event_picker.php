<?php

/*
 * Searchable event picker (the shape of the Azure "Request API permissions" list): a search box, expandable groups with counts and
 * "n selected", each event with its name, dotted id, one-line description and severity, per-group select all / none, a
 * wildcard-aware "All events", and the selection shown as removable chips with a total. The behaviour lives in js/event_picker.js
 * (loaded once by includes/footer.php, bound per picker as it appears, so AJAX-loaded modals work). The ~25 KB catalog is NOT
 * embedded: the script fetches it once per page from /modals/event_catalog.php (versioned URL, cacheable).
 *
 *   eventPickerField('webhook_events[]', $selected)                         several events or patterns ("ticket.*", "*")
 *   eventPickerField('trigger_event', [$current], ['mode' => 'single'])    exactly one event
 *
 * The chosen values are submitted as hidden inputs with the given name (one per id or pattern; single mode: one). The markup already
 * contains the current selection, so the form is correct even before the script has run. Handlers MUST re-validate every value
 * (see DestinationConfig::validateForm()): the client is a convenience, not a gate.
 */

require_once __DIR__ . '/event_catalog_ext.php';

if (!function_exists('eventPickerField')) {
    /**
     * @param list<string> $selected ids and/or patterns currently chosen
     * @param array{mode?:string,id?:string,other?:list<string>,required?:bool} $opts
     */
    function eventPickerField(string $name, array $selected, array $opts = []): void
    {
        static $seq = 0;
        $seq++;
        $mode = ($opts['mode'] ?? 'multi') === 'single' ? 'single' : 'multi';
        $id = (string) ($opts['id'] ?? 'event-picker-' . $seq);
        $other = $opts['other'] ?? null;
        if ($other === null) {
            require_once __DIR__ . '/../admin/includes/webhook_events.php';
            $other = webhook_events_seen_elsewhere();
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $version = substr(md5((string) json_encode(rivetEventCatalogData())), 0, 12);
        $url = '/modals/event_catalog.php?v=' . $version;
        $selected = array_values(array_unique(array_filter(array_map('strval', $selected), static fn (string $s): bool => $s !== '')));
        if ($mode === 'single') {
            $selected = array_slice($selected, 0, 1);
        }
        ?>
        <div class="event-picker" id="<?= $h($id) ?>" data-event-picker data-name="<?= $h($name) ?>" data-mode="<?= $mode ?>" data-catalog-url="<?= $h($url) ?>"
             data-other="<?= $h((string) json_encode(array_values($other))) ?>"<?= !empty($opts['required']) ? ' data-required="1"' : '' ?>>
            <div data-ep-values>
                <?php foreach ($selected as $v) { ?><input type="hidden" name="<?= $h($name) ?>" value="<?= $h($v) ?>"><?php } ?>
                <?php if ($mode === 'single' && !$selected) { ?><input type="hidden" name="<?= $h($name) ?>" value=""><?php } ?>
            </div>
            <div class="ep-toolbar">
                <div class="ep-search">
                    <i class="fas fa-search ep-search-icon" aria-hidden="true"></i>
                    <input type="search" class="form-control" placeholder="Search events (e.g. ticket, login, backup)" aria-label="Search events" autocomplete="off" data-ep-search>
                </div>
                <div class="ep-summary" role="status" aria-live="polite" data-ep-count></div>
                <?php if ($mode === 'multi') { ?><button type="button" class="btn btn-sm btn-link ep-clear" data-ep-clear>Clear</button><?php } ?>
            </div>
            <div class="ep-chips" role="list" aria-label="Selected events" data-ep-chips></div>
            <?php if ($mode === 'multi') { ?>
            <label class="ep-all"><input type="checkbox" class="form-check-input" data-ep-all> <span><strong>All events</strong> <code>*</code> <small class="text-secondary">including events added later</small></span></label>
            <?php } ?>
            <div class="ep-groups" data-ep-groups><div class="ep-loading"><i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>Loading events...</div></div>
            <noscript>
                <?php // Without JavaScript the picker cannot run: a plain list keeps the form usable (it is submitted under the same name). ?>
                <select class="form-select" name="<?= $h($name) ?>" <?= $mode === 'multi' ? 'multiple size="10"' : '' ?>>
                    <?php if ($mode === 'single') { ?><option value="">Choose an event...</option><?php } ?>
                    <?php $ns_groups = [];
                    foreach (rivetEventCatalogData()['events'] as $ns_e) { $ns_groups[$ns_e['groupLabel']][] = $ns_e['id']; }
                    if ($other) { $ns_groups['Other events seen on this server'] = array_values($other); }
                    foreach ($ns_groups as $ns_label => $ns_ids) { ?>
                    <optgroup label="<?= $h($ns_label) ?>">
                        <?php foreach ($ns_ids as $ns_id) { ?><option value="<?= $h($ns_id) ?>" <?= in_array($ns_id, $selected, true) ? 'selected' : '' ?>><?= $h($ns_id) ?></option><?php } ?>
                    </optgroup>
                    <?php } ?>
                </select>
            </noscript>
        </div>
        <?php
    }
}
