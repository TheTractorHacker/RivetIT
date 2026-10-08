<?php
use ITFlow\Automation\RuleAdmin;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleSummary;

/* The Event rules list (included by admin/event_rules.php, which provides $mysqli $h $csrf $engine $event_info $group_style $group_of $group_label $action_meta $rule_actions $slookups $recipes). */

$filters = [
    'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
    'group' => (string) ($_GET['group'] ?? ''),
    'action' => isset($rule_actions[$_GET['action'] ?? '']) ? (string) $_GET['action'] : '',
    'state' => in_array($_GET['state'] ?? '', ['on', 'off', 'failing'], true) ? (string) $_GET['state'] : '',
    'sort' => 'priority',
    'dir' => 'asc',
];
$sort_raw = (string) ($_GET['sort'] ?? 'priority');
$sort_desc = str_ends_with($sort_raw, '_desc') || ($_GET['dir'] ?? '') === 'desc';
$sort_base = preg_replace('/_desc$/', '', $sort_raw);
if (in_array($sort_base, ['name', 'trigger', 'last_run', 'priority', 'runs'], true)) {
    $filters['sort'] = $sort_base;
    $filters['dir'] = $sort_desc ? 'desc' : 'asc';
}
$sort_key = $filters['sort'] . ($filters['dir'] === 'desc' ? '_desc' : '');
$all_rules = RuleAdmin::rules($mysqli);
$summary_of = static fn (array $r): string => RuleSummary::describe($r, $slookups);
$rules = RuleAdmin::filter($all_rules, $filters, $group_of, $summary_of);
$total = count($all_rules);
$enabled = count(array_filter($all_rules, static fn ($r) => (int) $r['is_enabled'] === 1));
$failed24 = array_sum(array_column($all_rules, 'failed_24h'));
$failing = count(array_filter($all_rules, static fn ($r) => $r['last_status'] === 'failed'));
$used_groups = [];
foreach ($all_rules as $r) {
    $used_groups[$group_of((string) $r['trigger_event'])] = true;
}
$engine_on = RuleEngine::switchOn($mysqli);
$filtered = $filters['q'] !== '' || $filters['group'] !== '' || $filters['action'] !== '' || $filters['state'] !== '';
$reorderable = $filters['sort'] === 'priority' && $filters['dir'] === 'asc' && !$filtered; // a filtered list would renumber only part of an event's rules
$spark = static function (array $r) use ($h): string {
    $max = 1;
    foreach ($r['spark'] as $b) {
        $max = max($max, $b['ok'] + $b['failed'] + $b['other']);
    }
    $svg = '<svg class="er-spark" viewBox="0 0 49 20" width="49" height="20" role="img" aria-label="' . $h('Last 7 days: ' . $r['ok_7d'] . ' ok, ' . $r['failed_7d'] . ' failed' . ($r['other_7d'] ? ', ' . $r['other_7d'] . ' skipped or throttled' : '')) . '">';
    foreach ($r['spark'] as $i => $b) {
        $y = 20;
        foreach (['ok' => 'er-s-ok', 'other' => 'er-s-other', 'failed' => 'er-s-bad'] as $k => $cls) {
            if ($b[$k] > 0) {
                $hh = max(2, (int) round(18 * $b[$k] / $max));
                $y -= $hh;
                $svg .= '<rect class="' . $cls . '" x="' . ($i * 7) . '" y="' . $y . '" width="5" height="' . $hh . '" rx="1"/>';
            }
        }
        if ($b['ok'] + $b['failed'] + $b['other'] === 0) {
            $svg .= '<rect class="er-s-none" x="' . ($i * 7) . '" y="18" width="5" height="2" rx="1"/>';
        }
    }

    return $svg . '</svg>';
};
?>

<div class="er-head">
    <div>
        <h3 class="er-title"><i class="fas fa-fw fa-bolt me-2" aria-hidden="true"></i>Event rules</h3>
        <p class="er-sub">When something happens in <?= $h(APP_NAME) ?>, a rule can do something about it. Each rule reads <strong>When</strong> an event happens, <strong>If</strong> it matches your conditions, <strong>Then</strong> do an action. Rules run in the background through the <a href="job_queue.php">Job queue</a>, so a slow or failing action never slows what a person is doing.</p>
    </div>
    <div class="er-head-actions">
        <a class="btn btn-primary" href="event_rules.php?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New rule</a>
        <a class="btn btn-outline-secondary" href="automation.php"><i class="fas fa-cogs me-1" aria-hidden="true"></i>Overview, run log &amp; on/off switch</a>
    </div>
</div>

<?php if (!$engine_on) { ?>
<div class="alert alert-warning"><i class="fas fa-power-off me-1" aria-hidden="true"></i>Event rules are switched <strong>off</strong> for the whole installation, so no rule runs. Turn them back on at <a href="automation.php">Automation</a>.</div>
<?php } ?>

<div class="er-strip" role="group" aria-label="Rule totals">
    <a class="er-tile" href="event_rules.php"><span class="er-tile-n" id="er-n-total"><?= (int) $total ?></span><span class="er-tile-l">rules</span></a>
    <a class="er-tile" href="event_rules.php?state=on"><span class="er-tile-n er-ok" id="er-n-on"><?= (int) $enabled ?></span><span class="er-tile-l">turned on</span></a>
    <a class="er-tile" href="event_rules.php?state=off"><span class="er-tile-n" id="er-n-off"><?= (int) ($total - $enabled) ?></span><span class="er-tile-l">turned off</span></a>
    <a class="er-tile" href="event_rules.php?state=failing"><span class="er-tile-n <?= $failed24 ? 'er-bad' : '' ?>"><?= (int) $failed24 ?></span><span class="er-tile-l">failed runs in the last 24 h<?= $failing ? ' (' . (int) $failing . ' rule' . ($failing === 1 ? '' : 's') . ' failing now)' : '' ?></span></a>
</div>

<?php if ($total === 0) { ?>
<div class="er-empty">
    <i class="fas fa-bolt er-empty-icon" aria-hidden="true"></i>
    <h4>No rules yet</h4>
    <p>Pick a recipe to start from, or <a href="event_rules.php?new=1">build a rule from scratch</a>.</p>
</div>
<?php require __DIR__ . '/event_rules_recipes.php'; ?>
<?php } else { ?>

<form method="get" class="er-filters" id="er-filters" role="search" aria-label="Filter rules">
    <div class="er-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" class="form-control" name="q" value="<?= $h($filters['q']) ?>" placeholder="Search rules, events or what they do" aria-label="Search rules" autocomplete="off"></div>
    <select class="form-select" name="group" aria-label="Filter by event group">
        <option value="">All events</option>
        <?php foreach ($used_groups as $g => $_) { ?><option value="<?= $h($g) ?>" <?= $filters['group'] === $g ? 'selected' : '' ?>><?= $h($group_label($g)) ?></option><?php } ?>
    </select>
    <select class="form-select" name="action" aria-label="Filter by action">
        <option value="">Any action</option>
        <?php foreach ($rule_actions as $k => $l) { ?><option value="<?= $h($k) ?>" <?= $filters['action'] === $k ? 'selected' : '' ?>><?= $h($l) ?></option><?php } ?>
    </select>
    <select class="form-select" name="state" aria-label="Filter by state">
        <option value="">On or off</option>
        <option value="on" <?= $filters['state'] === 'on' ? 'selected' : '' ?>>Turned on</option>
        <option value="off" <?= $filters['state'] === 'off' ? 'selected' : '' ?>>Turned off</option>
        <option value="failing" <?= $filters['state'] === 'failing' ? 'selected' : '' ?>>Failing (last run failed)</option>
    </select>
    <select class="form-select" name="sort" aria-label="Sort by">
        <?php foreach (['priority' => 'Order (per event)', 'name' => 'Name A-Z', 'name_desc' => 'Name Z-A', 'trigger' => 'Event', 'last_run' => 'Last run: oldest first', 'last_run_desc' => 'Last run: newest first', 'runs_desc' => 'Most runs', 'runs' => 'Fewest runs'] as $k => $l) { ?><option value="<?= $h($k) ?>" <?= $sort_key === $k ? 'selected' : '' ?>>Sort: <?= $h($l) ?></option><?php } ?>
    </select>
    <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-filter me-1" aria-hidden="true"></i>Apply</button>
    <?php if ($filtered || $sort_key !== 'priority') { ?><a class="btn btn-link" href="event_rules.php">Reset</a><?php } ?>
</form>

<p class="er-count text-muted small" id="er-count"><?= count($rules) ?> of <?= (int) $total ?> rule<?= $total === 1 ? '' : 's' ?> shown<?= $reorderable ? '. Drag a rule by its handle (or use the arrows) to change the order in which rules for the same event run.' : '' ?></p>

<?php if (!$rules) { ?>
<div class="er-empty er-empty-small"><i class="fas fa-search er-empty-icon" aria-hidden="true"></i><h4>No rule matches these filters</h4><p><a href="event_rules.php">Show all rules</a></p></div>
<?php } ?>

<ol class="er-list" id="er-list" data-reorder="<?= $reorderable ? '1' : '0' ?>">
<?php foreach ($rules as $r) {
    $g = $group_of((string) $r['trigger_event']);
    [$gicon, $gcolor] = $group_style[$g] ?? $group_style['other'];
    $ev = $event_info[$r['trigger_event']] ?? null;
    $ameta = $action_meta[$r['action_type']] ?? ['fa-bolt', ''];
    $sentence = $summary_of($r);
    $ls = $r['last_status'];
    $lcls = $ls === 'ok' ? 'success' : ($ls === 'failed' ? 'danger' : 'warning');
    $rid = (int) $r['rule_id'];
    $name = (string) $r['name']; ?>
    <li class="er-rule <?= $r['is_enabled'] ? '' : 'is-off' ?> <?= $ls === 'failed' ? 'is-failing' : '' ?>" data-rule="<?= $rid ?>" data-trigger="<?= $h($r['trigger_event']) ?>" data-enabled="<?= (int) $r['is_enabled'] ?>" data-name="<?= $h($name) ?>" data-action="<?= $h($r['action_type']) ?>"<?= $reorderable ? ' draggable="true"' : '' ?>>
        <?php if ($reorderable) { ?>
        <div class="er-order">
            <button type="button" class="er-move" data-er-move="-1" aria-label="<?= $h('Move "' . $name . '" earlier among rules for ' . $r['trigger_event']) ?>"><i class="fas fa-chevron-up" aria-hidden="true"></i></button>
            <span class="er-grip" aria-hidden="true" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
            <button type="button" class="er-move" data-er-move="1" aria-label="<?= $h('Move "' . $name . '" later among rules for ' . $r['trigger_event']) ?>"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
        </div>
        <?php } ?>
        <div class="er-switch-cell">
            <button type="button" role="switch" class="er-switch" aria-checked="<?= $r['is_enabled'] ? 'true' : 'false' ?>" data-er-toggle aria-label="<?= $h('Rule "' . $name . '" is ' . ($r['is_enabled'] ? 'on' : 'off')) ?>"><span class="er-knob"></span></button>
        </div>
        <div class="er-main">
            <div class="er-name-line">
                <a class="er-name" href="event_rules.php?edit=<?= $rid ?>"><?= $h($name) ?></a>
                <span class="badge text-bg-light er-prio" title="Order among rules for the same event: lower numbers run first">#<?= (int) $r['priority'] ?></span>
                <?php if (!empty($r['stop_on_match'])) { ?><span class="badge text-bg-warning" title="Later rules for this event are skipped when this one matches">stops later rules</span><?php } ?>
                <span class="badge text-bg-secondary er-offbadge" <?= $r['is_enabled'] ? 'hidden' : '' ?>>Off</span>
            </div>
            <p class="er-sentence"><?= $h($sentence) ?></p>
            <div class="er-meta">
                <span class="er-badge" style="--er-c: <?= $h($gcolor) ?>" title="<?= $h($group_label($g)) ?>"><i class="fas <?= $h($gicon) ?>" aria-hidden="true"></i><?= $h($ev['label'] ?? $r['trigger_event']) ?> <code><?= $h($r['trigger_event']) ?></code></span>
                <span class="er-badge er-badge-action" title="<?= $h($rule_actions[$r['action_type']] ?? $r['action_type']) ?>"><i class="fas <?= $h($ameta[0]) ?>" aria-hidden="true"></i><?= $h($rule_actions[$r['action_type']] ?? $r['action_type']) ?></span>
            </div>
        </div>
        <div class="er-stats">
            <div class="er-last">
                <?php if ($ls) { ?><span class="badge text-bg-<?= $lcls ?>"><?= $h($ls) ?></span> <span class="text-muted small"><?= $h(RuleAdmin::ago($r['last_age'])) ?></span><?php } else { ?><span class="text-muted small">Never run</span><?php } ?>
            </div>
            <div class="er-spark-line"><?= $spark($r) ?><span class="small text-muted"><?= (int) $r['ok_7d'] ?> ok<?= $r['failed_7d'] ? ', <span class="er-bad">' . (int) $r['failed_7d'] . ' failed</span>' : '' ?> <span class="d-none d-xl-inline">in 7 days</span></span></div>
            <div class="small text-muted"><?= (int) $r['runs_total'] ?> run<?= $r['runs_total'] === 1 ? '' : 's' ?> in total</div>
        </div>
        <div class="er-actions-cell">
            <a class="btn btn-sm btn-outline-primary" href="event_rules.php?edit=<?= $rid ?>"><i class="fas fa-edit me-1" aria-hidden="true"></i>Edit</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-er-test><i class="fas fa-vial me-1" aria-hidden="true"></i>Test</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-er-history><i class="fas fa-history me-1" aria-hidden="true"></i>History</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-er-duplicate><i class="fas fa-copy me-1" aria-hidden="true"></i>Duplicate</button>
            <a class="btn btn-sm btn-outline-danger confirm-link" href="post.php?delete_event_rule=<?= $rid ?>&amp;csrf_token=<?= $h($csrf) ?>" data-confirm-message="<?= $h('Delete the rule "' . $name . '"? Its run history stays in the run log.') ?>"><i class="fas fa-trash me-1" aria-hidden="true"></i>Delete</a>
        </div>
    </li>
<?php } ?>
</ol>

<details class="er-recipes-wrap">
    <summary><i class="fas fa-magic me-1" aria-hidden="true"></i>Start from a recipe</summary>
    <?php require __DIR__ . '/event_rules_recipes.php'; ?>
</details>
<?php } ?>

<form method="post" action="post.php" class="d-none" aria-hidden="true"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"></form>
