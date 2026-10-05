<?php

/*
 * Saved ticket views: a form for choosing the filters a view uses, and the matching validator that turns the posted choices
 * back into the query string stored on the view (the same parameters agent/tickets.php already understands). Nothing is
 * stored from a form value without being checked here first.
 */

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
 * Turn posted form fields into a validated view query string.
 *
 * @param array<string,mixed> $post
 */
function ticketViewQueryFromPost($mysqli, array $post): string
{
    $q = [];

    // Which tickets: Open / Closed / All, or specific statuses.
    $mode = (string) ($post['f_status_mode'] ?? 'open');
    if ($mode === 'closed') {
        $q['status'] = 'Closed';
    } elseif ($mode === 'all') {
        $q['status'] = 'All';
    } elseif ($mode === 'specific') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($post['f_status'] ?? [])), static fn ($v) => $v > 0)));
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

    // Assigned to: anyone (omitted), me, nobody, or one agent.
    $assigned = (string) ($post['f_assigned'] ?? '');
    if ($assigned === 'me' || $assigned === 'unassigned') {
        $q['assigned'] = $assigned;
    } elseif (ctype_digit($assigned) && (int) $assigned > 0 && isset(ticketViewAgents($mysqli)[(int) $assigned])) {
        $q['assigned'] = (int) $assigned;
    }

    if (in_array($post['f_priority'] ?? '', ['Low', 'Medium', 'High'], true)) {
        $q['priority'] = $post['f_priority'];
    }
    if (in_array((string) ($post['f_onsite'] ?? ''), ['0', '1'], true)) {
        $q['onsite'] = (string) $post['f_onsite'];
    }

    foreach (['board' => 'f_board', 'category' => 'f_category'] as $key => $field) {
        $id = intval($post[$field] ?? 0);
        if ($id > 0) {
            $ok = mysqli_query($mysqli, "SELECT category_id FROM categories WHERE category_id = $id AND category_type = 'Ticket' AND category_archived_at IS NULL");
            if ($ok && mysqli_num_rows($ok) === 1) {
                $q[$key] = $id;
            }
        }
    }

    $tags = array_values(array_unique(array_filter(array_map('intval', (array) ($post['f_tags'] ?? [])), static fn ($v) => $v > 0)));
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

    $due = (string) ($post['f_due'] ?? '');
    if ($due === 'overdue') {
        $q['overdue'] = 1;
    } elseif ($due === 'due_today') {
        $q['due_today'] = 1;
    }

    return http_build_query($q);
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
    if (is_array($s)) {
        $names = [];
        $in = implode(',', array_map('intval', $s));
        $res = mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id IN ($in) ORDER BY ticket_status_order");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $names[] = $r['ticket_status_name'];
        }
        $parts[] = 'Status: ' . ($names ? implode(', ', $names) : 'selected statuses');
    } elseif (ctype_digit((string) $s)) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id = " . (int) $s));
        $parts[] = 'Status: ' . ($r['ticket_status_name'] ?? 'selected status');
    } else {
        $parts[] = $s === 'Closed' ? 'Closed tickets' : ($s === 'All' ? 'All tickets' : 'Open tickets');
    }
    if (isset($p['assigned'])) {
        $a = (string) $p['assigned'];
        $parts[] = $a === 'me' ? 'Assigned to me' : ($a === 'unassigned' ? 'Unassigned' : 'Assigned to ' . (ticketViewAgents($mysqli)[(int) $a] ?? 'an agent'));
    }
    if (isset($p['priority'])) {
        $parts[] = $p['priority'] . ' priority';
    }
    if (isset($p['onsite'])) {
        $parts[] = $p['onsite'] === '1' ? 'On-site' : 'Remote';
    }
    if (isset($p['overdue'])) {
        $parts[] = 'Overdue';
    }
    if (isset($p['due_today'])) {
        $parts[] = 'Due today';
    }

    return implode(' · ', $parts);
}

/**
 * The filter form fields (no <form> tag, no buttons): drop it inside a modal body.
 *
 * @param array<string,mixed> $p current choices, as parsed from a view query
 */
function ticketViewFilterFields($mysqli, array $p, string $idp = 'tvf'): void
{
    $status = $p['status'] ?? 'Open';
    $mode = is_array($status) || ctype_digit((string) $status) ? 'specific' : ($status === 'Closed' ? 'closed' : ($status === 'All' ? 'all' : 'open'));
    $sel_status = is_array($status) ? array_map('intval', $status) : (ctype_digit((string) $status) ? [(int) $status] : []);
    $assigned = (string) ($p['assigned'] ?? '');
    $sel_tags = array_map('intval', (array) ($p['tags'] ?? []));
    $due = isset($p['overdue']) ? 'overdue' : (isset($p['due_today']) ? 'due_today' : '');
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="form-group">
        <label>Show</label>
        <div class="d-flex flex-wrap gap-3">
            <?php foreach (['open' => 'Open tickets', 'closed' => 'Closed / resolved', 'all' => 'All tickets', 'specific' => 'Specific statuses'] as $v => $l) { ?>
                <label class="form-check"><input class="form-check-input" type="radio" name="f_status_mode" value="<?= $h($v) ?>" <?= $mode === $v ? 'checked' : '' ?>> <span class="form-check-label"><?= $h($l) ?></span></label>
            <?php } ?>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-2">
            <?php
            $res = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order");
            while ($res && ($s = mysqli_fetch_assoc($res))) { ?>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="f_status[]" value="<?= (int) $s['ticket_status_id'] ?>" <?= in_array((int) $s['ticket_status_id'], $sel_status, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= $h($s['ticket_status_name']) ?></span></label>
            <?php } ?>
        </div>
        <small class="form-text text-muted">Tick statuses only when "Specific statuses" is chosen. New statuses you create appear here automatically.</small>
    </div>

    <div class="row">
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_assigned">Assigned to</label>
            <select class="form-control" id="<?= $idp ?>_assigned" name="f_assigned">
                <option value="">Anyone</option>
                <option value="me" <?= $assigned === 'me' ? 'selected' : '' ?>>Me</option>
                <option value="unassigned" <?= $assigned === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                <?php foreach (ticketViewAgents($mysqli) as $uid => $uname) { ?>
                    <option value="<?= (int) $uid ?>" <?= $assigned === (string) $uid ? 'selected' : '' ?>><?= $h($uname) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_priority">Priority</label>
            <select class="form-control" id="<?= $idp ?>_priority" name="f_priority">
                <option value="">Any</option>
                <?php foreach (['High', 'Medium', 'Low'] as $pr) { ?><option value="<?= $pr ?>" <?= ($p['priority'] ?? '') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php } ?>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_onsite">Where</label>
            <select class="form-control" id="<?= $idp ?>_onsite" name="f_onsite">
                <option value="">Any</option>
                <option value="1" <?= (string) ($p['onsite'] ?? '') === '1' ? 'selected' : '' ?>>On-site</option>
                <option value="0" <?= (string) ($p['onsite'] ?? '') === '0' ? 'selected' : '' ?>>Remote</option>
            </select>
        </div>
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_due">Due</label>
            <select class="form-control" id="<?= $idp ?>_due" name="f_due">
                <option value="">Any time</option>
                <option value="overdue" <?= $due === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                <option value="due_today" <?= $due === 'due_today' ? 'selected' : '' ?>>Due today</option>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_board">Board</label>
            <select class="form-control" id="<?= $idp ?>_board" name="f_board">
                <option value="">Any</option>
                <?php
                $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name");
                while ($res && ($c = mysqli_fetch_assoc($res))) { ?>
                    <option value="<?= (int) $c['category_id'] ?>" <?= (int) ($p['board'] ?? 0) === (int) $c['category_id'] ? 'selected' : '' ?>><?= $h($c['category_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group col-md-6">
            <label for="<?= $idp ?>_category">Category</label>
            <select class="form-control" id="<?= $idp ?>_category" name="f_category">
                <option value="">Any</option>
                <?php
                $res = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name");
                while ($res && ($c = mysqli_fetch_assoc($res))) { ?>
                    <option value="<?= (int) $c['category_id'] ?>" <?= (int) ($p['category'] ?? 0) === (int) $c['category_id'] ? 'selected' : '' ?>><?= $h($c['category_name']) ?></option>
                <?php } ?>
            </select>
        </div>
    </div>

    <?php
    $tag_res = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags WHERE tag_type = 6 ORDER BY tag_name");
    if ($tag_res && mysqli_num_rows($tag_res) > 0) { ?>
    <div class="form-group">
        <label>Tags <small class="text-muted">(a ticket with any chosen tag)</small></label>
        <div class="d-flex flex-wrap gap-3">
            <?php while ($t = mysqli_fetch_assoc($tag_res)) { ?>
                <label class="form-check"><input class="form-check-input" type="checkbox" name="f_tags[]" value="<?= (int) $t['tag_id'] ?>" <?= in_array((int) $t['tag_id'], $sel_tags, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= $h($t['tag_name']) ?></span></label>
            <?php } ?>
        </div>
    </div>
    <?php } ?>
    <input type="hidden" name="filters_present" value="1">
    <?php
}
