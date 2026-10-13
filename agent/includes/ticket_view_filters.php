<?php

/*
 * Saved ticket views: a form for choosing the filters a view uses, the matching validator that turns the posted choices back
 * into the query string stored on the view (the same parameters agent/tickets.php understands), the plain-words description
 * of a stored query, and the per-user / shared DEFAULT view (table ticket_view_defaults).
 *
 * Several filters take more than one value (assigned to, priority, board, category, on-site/remote, due, statuses, tags):
 * the form shows them as checkbox groups and the query stores them as lists (assigned[]=me&assigned[]=7). Queries saved
 * before that, with a single value (assigned=me, priority=High, board=5), are still read, shown and applied. Nothing is
 * stored from a form value without being checked here first.
 */

require_once __DIR__ . '/../../includes/date_range.php';
require_once __DIR__ . '/../../includes/ticket_filter_sets.php';

use RivetCore\Ui\DateRange;

/** @return array<string,mixed> the filter choices found in a stored view query (or the current page's query) */
function ticketViewParseQuery(string $query): array
{
    parse_str($query, $p);

    return is_array($p) ? $p : [];
}

/** Active agents, for the "Assigned to" choice. @return array<int,string> */
function ticketViewAgents($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['user_id']] = $r['user_name'];
    }

    return $out;
}

/**
 * Ticket categories (boards are the top-level ones) by id: id => name. Only ids that exist, are Ticket categories and are
 * not archived come back, so it doubles as the validator for posted ids.
 *
 * @param int[] $ids
 * @return array<int,string>
 */
function ticketViewCategoriesById($mysqli, array $ids, bool $boards_only = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_slice($ids, 0, TICKET_FILTER_GROUP_CAP));
    $top = $boards_only ? ' AND category_parent = 0' : '';
    $out = [];
    $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_id IN ($in) AND category_type = 'Ticket' AND category_archived_at IS NULL$top ORDER BY category_name");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[(int) $r['category_id']] = $r['category_name'];
    }

    return $out;
}

/**
 * Turn posted form fields into a validated view query string. Multi-value groups accept a list (f_priority[]) or, from older
 * callers, a single value (f_priority).
 *
 * @param array<string,mixed> $post
 */
function ticketViewQueryFromPost($mysqli, array $post): string
{
    $q = [];

    // Which tickets: Open / Closed / All, or specific statuses.
    $mode = is_scalar($post['f_status_mode'] ?? null) ? (string) $post['f_status_mode'] : 'open';
    if ($mode === 'closed') {
        $q['status'] = 'Closed';
    } elseif ($mode === 'all') {
        $q['status'] = 'All';
    } elseif ($mode === 'specific') {
        $ids = ticketFilterIds($post['f_status'] ?? null);
        if ($ids) {
            $in = implode(',', $ids);
            $valid = [];
            $res = mysqli_query($mysqli, "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_id IN ($in)");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $valid[] = (int) $r['ticket_status_id'];
            }
            sort($valid);
            if ($valid) {
                $q['status'] = $valid;
            }
        }
    }
    if (!isset($q['status'])) {
        $q['status'] = 'Open';
    }

    // Assigned to: any mix of me, nobody and specific agents (omitted = anyone). "me" is kept as the word so a shared view
    // means "whoever opens it".
    $agents = null;
    $assigned = [];
    foreach (ticketFilterTokens($post['f_assigned'] ?? null) as $t) {
        $low = strtolower($t);
        if ($low === 'me' || $low === 'unassigned') {
            $assigned[$low] = true;
        } elseif (ctype_digit($t) && (int) $t > 0) {
            $agents ??= ticketViewAgents($mysqli);
            if (isset($agents[(int) $t])) {
                $assigned[(int) $t] = true;
            }
        }
    }
    if ($assigned) {
        $words = array_values(array_filter(['me', 'unassigned'], static fn ($w) => isset($assigned[$w])));
        $nums = array_values(array_filter(array_keys($assigned), 'is_int'));
        sort($nums);
        $q['assigned'] = array_merge($words, $nums);
    }

    // Priority: any of High / Medium / Low; all three is the same as no filter.
    $priority = ticketFilterSets(['priority' => $post['f_priority'] ?? null], 0)['priority'];
    if ($priority && count($priority) < count(TICKET_FILTER_PRIORITIES)) {
        $q['priority'] = $priority;
    }

    // On-site / Remote: both is the same as no filter.
    $onsite = ticketFilterSets(['onsite' => $post['f_onsite'] ?? null], 0)['onsite'];
    if ($onsite) {
        $q['onsite'] = $onsite;
    }

    foreach (['board' => 'f_board', 'category' => 'f_category'] as $key => $field) {
        $valid = array_keys(ticketViewCategoriesById($mysqli, ticketFilterIds($post[$field] ?? null), $key === 'board'));
        sort($valid);
        if ($valid) {
            $q[$key] = $valid;
        }
    }

    $tags = ticketFilterIds($post['f_tags'] ?? null);
    if ($tags) {
        $in = implode(',', $tags);
        $valid = [];
        $res = mysqli_query($mysqli, "SELECT tag_id FROM tags WHERE tag_type = 6 AND tag_id IN ($in)");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $valid[] = (int) $r['tag_id'];
        }
        sort($valid);
        if ($valid) {
            $q['tags'] = $valid;
        }
    }

    // Due: Overdue and Due today are independent (both = either one).
    $due = array_map('strtolower', ticketFilterTokens($post['f_due'] ?? null));
    if (in_array('overdue', $due, true)) {
        $q['overdue'] = 1;
    }
    if (in_array('due_today', $due, true)) {
        $q['due_today'] = 1;
    }

    // Date range: a PRESET is stored as canned_date only (no dtf/dtt), so "Last 7 days" keeps rolling every time the view is
    // opened; only a fixed custom range stores its dates. The date field says which ticket date the range applies to.
    $preset = strtolower(trim((string) (is_scalar($post['f_date'] ?? null) ? $post['f_date'] : '')));
    if ($preset === 'custom') {
        $r = DateRange::resolve('custom', (string) (is_scalar($post['f_dtf'] ?? null) ? $post['f_dtf'] : ''), (string) (is_scalar($post['f_dtt'] ?? null) ? $post['f_dtt'] : ''));
        if (!$r->isAllTime()) {
            $q += $r->toQuery();
        }
    } elseif ($preset !== '' && $preset !== 'alltime' && DateRange::isPreset($preset)) {
        $q += DateRange::resolve($preset)->toQuery();
    }
    $dateField = ticketDateFieldFromRequest(['datefield' => is_scalar($post['f_datefield'] ?? null) ? $post['f_datefield'] : '']);
    if ($dateField !== 'created') {
        $q['datefield'] = $dateField;
    }

    return ticketFilterBuildQuery($q);
}

/** Human summary of a stored query, for showing what a view does. */
function ticketViewDescribe($mysqli, string $query): string
{
    $p = ticketViewParseQuery($query);
    if (!$p) {
        return 'Open tickets (no extra filters)';
    }
    $parts = [];
    $s = $p['status'] ?? 'Open';
    if (is_array($s) || ctype_digit((string) $s)) {
        $ids = ticketFilterIds($s);
        $names = [];
        if ($ids) {
            $in = implode(',', $ids);
            $res = mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id IN ($in) ORDER BY ticket_status_order");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $names[] = $r['ticket_status_name'];
            }
        }
        $parts[] = 'Status: ' . ($names ? implode(', ', $names) : 'selected statuses');
    } else {
        $s = (string) $s;
        $parts[] = $s === 'Closed' ? 'Closed tickets' : ($s === 'All' ? 'All tickets' : 'Open tickets');
    }

    // The sets as written (so "me" stays "me"); ticketFilterSets() would turn it into the viewer's id.
    $sets = ticketFilterSets($p, 0);
    if ($sets['priority']) {
        $parts[] = 'Priority: ' . implode(', ', $sets['priority']);
    }

    $assigned = [];
    $agents = null;
    foreach (ticketFilterTokens($p['assigned'] ?? ($p['assigned_to'] ?? null)) as $t) {
        $low = strtolower($t);
        if ($low === 'me') {
            $assigned['me'] = 'me';
        } elseif ($low === 'unassigned' || $t === '0') {
            $assigned['unassigned'] = 'Unassigned';
        } elseif (ctype_digit($t)) {
            $agents ??= ticketViewAgents($mysqli);
            $assigned['u' . $t] = $agents[(int) $t] ?? 'an agent';
        }
    }
    if ($assigned) {
        $parts[] = 'Assigned to: ' . implode(', ', $assigned);
    }

    if ($sets['board']) {
        $names = ticketViewCategoriesById($mysqli, $sets['board']);
        $parts[] = 'Board: ' . ($names ? implode(', ', $names) : 'selected boards');
    }
    if ($sets['category']) {
        $names = ticketViewCategoriesById($mysqli, $sets['category']);
        $parts[] = 'Category: ' . ($names ? implode(', ', $names) : 'selected categories');
    }
    if ($sets['onsite']) {
        $parts[] = 'Where: ' . implode(', ', array_map(static fn ($v) => $v === 1 ? 'On-site' : 'Remote', $sets['onsite']));
    }
    $due = array_filter([$sets['overdue'] ? 'Overdue' : '', $sets['due_today'] ? 'Due today' : '']);
    if ($due) {
        $parts[] = 'Due: ' . implode(', ', $due);
    }
    $tags = ticketFilterIds($p['tags'] ?? null);
    if ($tags) {
        $names = [];
        $res = mysqli_query($mysqli, 'SELECT tag_name FROM tags WHERE tag_type = 6 AND tag_id IN (' . implode(',', $tags) . ') ORDER BY tag_name');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $names[] = $r['tag_name'];
        }
        $parts[] = 'Tags: ' . ($names ? implode(', ', $names) : 'selected tags');
    }

    $range = dateRangeFromRequest($p);
    if (!$range->isAllTime()) {
        $fields = ticketDateFields();
        $df = $fields[ticketDateFieldFromRequest($p)]['label'];
        $parts[] = $df . ' date: ' . ($range->preset() === 'custom' ? dateRangeDayLabel($range->from(), $range->to()) . ' (fixed)' : $range->label() . ' (rolling)');
    }

    return implode(' · ', $parts);
}

/* ------------------------------------------------------------------------------------------------------------------ *
 * Default view (table ticket_view_defaults, DB 2.6.166)
 *
 * One row per personal default (default_key "u:<user id>") and at most one shared default (default_key "shared"). The
 * UNIQUE key on default_key is what keeps it to one of each; setting a new one replaces the row in a single statement.
 * ------------------------------------------------------------------------------------------------------------------ */

/** Has the update that creates ticket_view_defaults been run? (Before it, the page works exactly as it did.) */
function ticketViewDefaultsAvailable($mysqli): bool
{
    static $cache = [];
    $key = spl_object_id($mysqli);
    if (!isset($cache[$key])) {
        try {
            $r = mysqli_query($mysqli, "SHOW TABLES LIKE 'ticket_view_defaults'");
            $cache[$key] = $r && mysqli_num_rows($r) > 0;
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
    }

    return $cache[$key];
}

/** A saved view this user may open (their own or a shared one, not deleted), or null. @return array<string,mixed>|null */
function ticketViewVisible($mysqli, int $view_id, int $user_id): ?array
{
    if ($view_id <= 0) {
        return null;
    }
    $res = mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $view_id AND (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $user_id) AND ticket_saved_view_archived_at IS NULL LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;

    return $row ?: null;
}

/**
 * The default view ids set for a user: ['personal' => id|null, 'shared' => id|null]. Rows pointing at a view that is gone,
 * deleted or no longer visible are ignored.
 *
 * @return array{personal:?int,shared:?int}
 */
function ticketViewDefaultIds($mysqli, int $user_id): array
{
    $out = ['personal' => null, 'shared' => null];
    if (!ticketViewDefaultsAvailable($mysqli)) {
        return $out;
    }
    $key = mysqli_real_escape_string($mysqli, 'u:' . $user_id);
    $res = mysqli_query(
        $mysqli,
        "SELECT d.default_scope, d.default_view_id FROM ticket_view_defaults d
         JOIN ticket_saved_views v ON v.ticket_saved_view_id = d.default_view_id
         WHERE d.default_key IN ('$key', 'shared')
         AND v.ticket_saved_view_archived_at IS NULL
         AND (v.ticket_saved_view_user_id = 0 OR v.ticket_saved_view_user_id = $user_id)
         AND (d.default_scope = 'personal' OR v.ticket_saved_view_user_id = 0)"
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $out[$r['default_scope'] === 'shared' ? 'shared' : 'personal'] = (int) $r['default_view_id'];
    }

    return $out;
}

/**
 * The view that opens when the user opens Tickets without choosing one: their personal default, else the shared default,
 * else null (the built-in "open tickets"). The row gets a 'default_source' of 'personal' or 'shared'.
 *
 * @return array<string,mixed>|null
 */
function ticketViewDefaultFor($mysqli, int $user_id): ?array
{
    $ids = ticketViewDefaultIds($mysqli, $user_id);
    foreach (['personal', 'shared'] as $scope) {
        if ($ids[$scope]) {
            $row = ticketViewVisible($mysqli, (int) $ids[$scope], $user_id);
            if ($row) {
                $row['default_source'] = $scope;

                return $row;
            }
        }
    }

    return null;
}

/**
 * Make a view the user's personal default, or the shared default (only for a shared view, and only when $can_share).
 * Replaces any earlier one of the same kind. @return array{ok:bool,error:string}
 */
function ticketViewSetDefault($mysqli, int $user_id, int $view_id, string $scope, bool $can_share): array
{
    if (!ticketViewDefaultsAvailable($mysqli)) {
        return ['ok' => false, 'error' => 'Run the database update first (default views need it).'];
    }
    $view = ticketViewVisible($mysqli, $view_id, $user_id);
    if (!$view) {
        return ['ok' => false, 'error' => 'That view no longer exists.'];
    }
    if ($scope === 'shared') {
        if (!$can_share) {
            return ['ok' => false, 'error' => 'Only an administrator can set the default for everyone.'];
        }
        if ((int) $view['ticket_saved_view_user_id'] !== 0) {
            return ['ok' => false, 'error' => 'Only a view shared with all agents can be the default for everyone.'];
        }
        $key = 'shared';
        $owner = 0;
    } else {
        $scope = 'personal';
        $key = 'u:' . $user_id;
        $owner = $user_id;
    }
    $key = mysqli_real_escape_string($mysqli, $key);
    mysqli_query(
        $mysqli,
        "INSERT INTO ticket_view_defaults SET default_key = '$key', default_scope = '$scope', default_user_id = $owner,
            default_view_id = $view_id, default_set_by = $user_id, default_set_at = NOW()
         ON DUPLICATE KEY UPDATE default_scope = VALUES(default_scope), default_user_id = VALUES(default_user_id),
            default_view_id = VALUES(default_view_id), default_set_by = VALUES(default_set_by), default_set_at = NOW()"
    );

    return ['ok' => true, 'error' => ''];
}

/** Remove the user's personal default, or the shared default (administrators only). Returns whether a row was removed. */
function ticketViewClearDefault($mysqli, int $user_id, string $scope, bool $can_share): bool
{
    if (!ticketViewDefaultsAvailable($mysqli)) {
        return false;
    }
    if ($scope === 'shared') {
        if (!$can_share) {
            return false;
        }
        $key = 'shared';
    } else {
        $key = 'u:' . $user_id;
    }
    $key = mysqli_real_escape_string($mysqli, $key);
    mysqli_query($mysqli, "DELETE FROM ticket_view_defaults WHERE default_key = '$key'");

    return mysqli_affected_rows($mysqli) > 0;
}

/** Forget every default that points at a view (the view was deleted). */
function ticketViewDropDefaultsFor($mysqli, int $view_id): void
{
    if (ticketViewDefaultsAvailable($mysqli) && $view_id > 0) {
        mysqli_query($mysqli, "DELETE FROM ticket_view_defaults WHERE default_view_id = $view_id");
    }
}

/**
 * Open Tickets with the user's default view when the request chose nothing itself. Call before anything reads $_GET. An
 * explicit filter in the URL (or ?nodefault=1) always wins. Only filter keys are taken from the stored query. Returns the
 * view that was applied, or null.
 *
 * @param array<string,mixed> $get the request query (by reference: the default's filters are added to it)
 * @return array<string,mixed>|null
 */
function ticketViewApplyDefault($mysqli, int $user_id, array &$get): ?array
{
    if ($user_id <= 0 || ticketFilterRequestIsExplicit($get)) {
        return null;
    }
    $view = ticketViewDefaultFor($mysqli, $user_id);
    if (!$view) {
        return null;
    }
    $params = ticketViewParseQuery((string) $view['ticket_saved_view_query']);
    foreach (TICKET_FILTER_EXPLICIT_KEYS as $k) {
        if ($k !== 'nodefault' && array_key_exists($k, $params) && !array_key_exists($k, $get)) {
            $get[$k] = $params[$k];
        }
    }

    return $view;
}

/* ------------------------------------------------------------------------------------------------------------------ *
 * Forms
 * ------------------------------------------------------------------------------------------------------------------ */

/**
 * One multi-choice filter as a labelled group of checkboxes, with "Select all", "Clear" and a count of what is ticked
 * (behaviour: js/ticket_view_filters.js, keyed on the data-tv-* attributes). 'inline' is a fieldset for forms; 'dropdown'
 * is a button that opens the same list, for the filter bar (it submits the form when it closes, if data-tv-autosubmit).
 *
 * @param array<string,string> $options value => label
 * @param string[]             $selected values that start ticked
 * @param array{mode?:string,dots?:array<string,string>,cls?:string,radio?:string,autosubmit?:bool,empty?:string} $opt
 */
function ticketFilterCheckGroup(string $id, string $label, string $name, array $options, array $selected, array $opt = []): void
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $mode = ($opt['mode'] ?? 'inline') === 'dropdown' ? 'dropdown' : 'inline';
    $selected = array_map('strval', $selected);
    $count = 0;
    foreach ($options as $v => $l) {
        if (in_array((string) $v, $selected, true)) {
            $count++;
        }
    }
    $attrs = 'data-tv-group' . (!empty($opt['radio']) ? ' data-tv-radio="' . $h($opt['radio']) . '"' : '') . (!empty($opt['autosubmit']) ? ' data-tv-autosubmit' : '');
    $list = static function () use ($h, $id, $name, $options, $selected, $opt) {
        $i = 0;
        foreach ($options as $v => $l) {
            $i++;
            $cid = $id . '_' . $i;
            $dot = isset($opt['dots'][$v]) ? '<span class="priority-dot priority-dot-' . $h($opt['dots'][$v]) . '"></span>' : '';
            echo '<label class="form-check tv-option" for="' . $h($cid) . '"><input class="form-check-input" type="checkbox" id="' . $h($cid) . '" name="' . $h($name) . '" value="' . $h($v) . '"' . (in_array((string) $v, $selected, true) ? ' checked' : '') . '> <span class="form-check-label">' . $dot . $h($l) . '</span></label>';
        }
    };
    $tools = '<span class="tv-tools"><button type="button" class="btn btn-link btn-sm p-0" data-tv-all>Select all</button><span class="text-muted" aria-hidden="true"> · </span><button type="button" class="btn btn-link btn-sm p-0" data-tv-clear>Clear</button></span>';
    $badge = '<span class="badge bg-primary rounded-pill tv-count" data-tv-count' . ($count ? '' : ' hidden') . '>' . $count . '</span>';

    if ($mode === 'dropdown') { ?>
        <div class="dropdown tv-ms <?= $h($opt['cls'] ?? '') ?>" <?= $attrs ?>>
            <button class="btn btn-outline-secondary dropdown-toggle tv-ms-btn<?= $count ? ' tv-active' : '' ?>" type="button" id="<?= $h($id) ?>_btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"><?= $h($label) ?> <?= $badge ?></button>
            <div class="dropdown-menu tv-ms-menu p-2" aria-labelledby="<?= $h($id) ?>_btn">
                <fieldset class="tv-fieldset">
                    <legend class="visually-hidden"><?= $h($label) ?></legend>
                    <?= $tools ?>
                    <div class="tv-options"><?php $list(); if (!$options) { echo '<span class="text-muted small">' . $h($opt['empty'] ?? 'Nothing to choose from') . '</span>'; } ?></div>
                </fieldset>
            </div>
        </div>
    <?php } else { ?>
        <fieldset class="tv-fieldset form-group <?= $h($opt['cls'] ?? '') ?>" <?= $attrs ?>>
            <legend class="tv-legend"><?= $h($label) ?> <?= $badge ?> <?= $tools ?></legend>
            <div class="tv-options"><?php $list(); if (!$options) { echo '<span class="text-muted small">' . $h($opt['empty'] ?? 'Nothing to choose from') . '</span>'; } ?></div>
        </fieldset>
    <?php }
}

/**
 * The filter form fields (no <form> tag, no buttons): drop it inside a modal body.
 *
 * @param array<string,mixed> $p current choices, as parsed from a view query
 */
function ticketViewFilterFields($mysqli, array $p, string $idp = 'tvf'): void
{
    $status = $p['status'] ?? 'Open';
    $is_list = is_array($status) || ctype_digit((string) $status);
    $mode = $is_list ? 'specific' : ($status === 'Closed' ? 'closed' : ($status === 'All' ? 'all' : 'open'));
    $sel_status = $is_list ? array_map('strval', ticketFilterIds($status)) : [];
    $sets = ticketFilterSets($p, 0);
    $sel_tags = array_map('strval', ticketFilterIds($p['tags'] ?? null));
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // Assigned to, as written: "me", "unassigned" and agent ids stay separate choices.
    $sel_assigned = [];
    foreach (ticketFilterTokens($p['assigned'] ?? ($p['assigned_to'] ?? null)) as $t) {
        $low = strtolower($t);
        $sel_assigned[] = $low === 'me' ? 'me' : ($low === 'unassigned' || $t === '0' ? 'unassigned' : $t);
    }
    ?>
    <div class="form-group">
        <label id="<?= $h($idp) ?>_show_label">Show</label>
        <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-labelledby="<?= $h($idp) ?>_show_label">
            <?php foreach (['open' => 'Open tickets', 'closed' => 'Closed / resolved', 'all' => 'All tickets', 'specific' => 'Specific statuses'] as $v => $l) { ?>
                <label class="form-check"><input class="form-check-input" type="radio" name="f_status_mode" value="<?= $h($v) ?>" <?= $mode === $v ? 'checked' : '' ?>> <span class="form-check-label"><?= $h($l) ?></span></label>
            <?php } ?>
        </div>
    </div>
    <?php
    $status_options = [];
    $res = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order");
    while ($res && ($s = mysqli_fetch_assoc($res))) {
        $status_options[(string) (int) $s['ticket_status_id']] = $s['ticket_status_name'];
    }
    ticketFilterCheckGroup($idp . '_status', 'Statuses', 'f_status[]', $status_options, $sel_status, ['radio' => 'f_status_mode=specific']);
    ?>
    <small class="form-text text-muted mb-3 d-block">Tick statuses only when "Specific statuses" is chosen (ticking one chooses it for you). New statuses you create appear here automatically.</small>

    <div class="row">
        <div class="col-md-6">
            <?php
            $assigned_options = ['me' => 'Me', 'unassigned' => 'Unassigned'];
            foreach (ticketViewAgents($mysqli) as $uid => $uname) {
                $assigned_options[(string) $uid] = $uname;
            }
            ticketFilterCheckGroup($idp . '_assigned', 'Assigned to', 'f_assigned[]', $assigned_options, $sel_assigned);
            ?>
            <small class="form-text text-muted mb-3 d-block">Nothing ticked means anyone. "Me" is whoever opens the view.</small>
        </div>
        <div class="col-md-6">
            <?php ticketFilterCheckGroup($idp . '_priority', 'Priority', 'f_priority[]', ['High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low'], $sets['priority'], ['dots' => ['High' => 'high', 'Medium' => 'medium', 'Low' => 'low']]); ?>
            <?php ticketFilterCheckGroup($idp . '_onsite', 'Where', 'f_onsite[]', ['1' => 'On-site', '0' => 'Remote'], array_map('strval', $sets['onsite'])); ?>
            <?php ticketFilterCheckGroup($idp . '_due', 'Due', 'f_due[]', ['overdue' => 'Overdue', 'due_today' => 'Due today'], array_values(array_filter([$sets['overdue'] ? 'overdue' : '', $sets['due_today'] ? 'due_today' : '']))); ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <?php
            $board_options = [];
            $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name");
            while ($res && ($c = mysqli_fetch_assoc($res))) {
                $board_options[(string) (int) $c['category_id']] = $c['category_name'];
            }
            ticketFilterCheckGroup($idp . '_board', 'Board', 'f_board[]', $board_options, array_map('strval', $sets['board']), ['empty' => 'No boards yet']);
            ?>
        </div>
        <div class="col-md-6">
            <?php
            $category_options = [];
            $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name");
            while ($res && ($c = mysqli_fetch_assoc($res))) {
                $category_options[(string) (int) $c['category_id']] = $c['category_name'];
            }
            ticketFilterCheckGroup($idp . '_category', 'Category', 'f_category[]', $category_options, array_map('strval', $sets['category']), ['empty' => 'No categories yet']);
            ?>
        </div>
    </div>

    <?php
    $range = dateRangeFromRequest($p);
    $sel_date = $range->preset();
    $sel_field = ticketDateFieldFromRequest($p);
    ?>
    <div class="row">
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_date">Date range</label>
            <select class="form-control" id="<?= $idp ?>_date" name="f_date">
                <option value="alltime" <?= $sel_date === 'alltime' ? 'selected' : '' ?>>Any time</option>
                <?php if ($sel_date === 'custom') { ?>
                    <option value="custom" selected>Fixed: <?= $h(dateRangeDayLabel($range->from(), $range->to())) ?></option>
                <?php } ?>
                <?php foreach (DateRange::presets() as $dp) {
                    if (in_array($dp['id'], ['alltime', 'custom'], true)) { continue; } ?>
                    <option value="<?= $h($dp['id']) ?>" <?= $sel_date === $dp['id'] ? 'selected' : '' ?>><?= $h($dp['label']) ?> (rolling)</option>
                <?php } ?>
            </select>
            <?php if ($sel_date === 'custom') { ?>
                <input type="hidden" name="f_dtf" value="<?= $h($range->from()) ?>"><input type="hidden" name="f_dtt" value="<?= $h($range->to()) ?>">
            <?php } ?>
            <small class="form-text text-muted">
                <?php if ($sel_date === 'custom') { ?>Date range: <?= $h(dateRangeDayLabel($range->from(), $range->to())) ?> (fixed dates). Choose a rolling range to keep it current.
                <?php } elseif ($sel_date !== 'alltime') { ?>Date range: <?= $h($range->label()) ?> (rolling)
                <?php } else { ?>Rolling ranges are re-worked out each time the view is opened.<?php } ?>
            </small>
        </div>
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_datefield">Date field</label>
            <select class="form-control" id="<?= $idp ?>_datefield" name="f_datefield">
                <?php foreach (ticketDateFields() as $dk => $df) { ?>
                    <option value="<?= $h($dk) ?>" <?= $sel_field === $dk ? 'selected' : '' ?>><?= $h($df['label']) ?></option>
                <?php } ?>
            </select>
        </div>
    </div>

    <?php
    $tag_options = [];
    $tag_res = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags WHERE tag_type = 6 ORDER BY tag_name");
    while ($tag_res && ($t = mysqli_fetch_assoc($tag_res))) {
        $tag_options[(string) (int) $t['tag_id']] = $t['tag_name'];
    }
    if ($tag_options) {
        ticketFilterCheckGroup($idp . '_tags', 'Tags (a ticket with any chosen tag)', 'f_tags[]', $tag_options, $sel_tags);
    }
    ?>
    <input type="hidden" name="filters_present" value="1">
    <?php
}
