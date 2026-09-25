<?php

/*
 * Training › Assignments (Phase 2 spec §5.2): ?tab=assignments|rules.
 *
 * Level 1 reads; level 2 assigns by hand, extends, waives and recalculates; level 3 creates
 * and archives rules. The first page of results comes from the same route handler the JSON
 * endpoint runs (tro_action), for the GET filters in the URL; agent/js/training_assignments.js
 * renders it with DOM nodes and refetches through assignment_list / rule_list as the filters
 * change (history.replaceState keeps the URL shareable). People scope is fail-closed: with no
 * department access the page shows the banner and empty tables.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_ops.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock for the page's JSON calls
require_once __DIR__ . '/includes/training_ops/ops.php';

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = (int) lookupUserPermission('module_training');
$tr_tab = tro_get_enum('tab', ['assignments', 'rules'], 'assignments');
$tr_scope = tro_scope($mysqli, $tr_ctx);

$tr_statuses = ['open', 'overdue', 'due_soon', 'lapsed', 'waived', 'completed', 'cancelled', 'cancelled_overdue', 'all'];

$tr_filters = [
    'status' => tro_get_enum('status', $tr_statuses, 'open'),
    'course_id' => tro_get_id('course_id'),
    'client_id' => tro_get_id('client_id', true),
    'requirement_id' => tro_get_id('requirement_id'),
    'contact_id' => tro_get_id('contact_id'),
    'q' => tro_get_str('q', 100),
    'page' => tro_get_id('page') ?? 1,
];
$tr_rule_view = tro_get_enum('view', ['active', 'archived', 'manual'], 'active');

$tr_params = array_filter($tr_filters, static fn($v) => $v !== null);
$tr_list = $tr_tab === 'assignments' ? tro_action($mysqli, 'assignment_list', $tr_params) : null;
$tr_rules = $tr_tab === 'rules'
    ? tro_action($mysqli, 'rule_list', $tr_rule_view === 'manual' ? ['include_archived' => '1', 'manual' => '1'] : ['include_archived' => '1'])
    : null;

// Names for the "Rule: …" and "Person: …" filter chips, and the person for ?assign=<contact_id>.
$tr_rule_filter = $tr_filters['requirement_id'] !== null ? tro_action($mysqli, 'rule_get', ['requirement_id' => $tr_filters['requirement_id']]) : null;
$tr_assign_id = tro_get_id('assign');
$tr_assign_person = ($tr_assign_id !== null && $tr_level >= 2) ? tro_action($mysqli, 'person_status', ['contact_id' => $tr_assign_id]) : null;

$tr_data = [
    'level' => $tr_level,
    'user_id' => $tr_ctx->userId,
    'today' => tro_today(),
    'tab' => $tr_tab,
    'scope' => $tr_scope['state'],
    'filters' => $tr_filters,
    'rule_view' => $tr_rule_view,
    'list' => $tr_list,
    'rules' => $tr_rules,
    'rule_filter' => $tr_rule_filter,
    'assign' => $tr_assign_id,
    'assign_person' => $tr_assign_person,
    'courses' => tro_course_cards($mysqli),
    'departments' => tro_departments($mysqli, $tr_scope, true),
    'settings' => tro_records_settings($mysqli),
    'routes' => [
        'assign_manual' => tro_has_route('assign_manual'),
        'assignment_extend' => tro_has_route('assignment_extend'),
        'assignment_waive' => tro_has_route('assignment_waive'),
        'reconcile_now' => tro_has_route('reconcile_now'),
        'rule_archive' => tro_has_route('rule_archive'),
    ],
];

$tr_actions = '';
if ($tr_level >= 2) {
    $tr_actions .= '<button type="button" class="btn btn-outline-secondary" id="tro-recalc" title="Re-check every rule against the current roster and records"><i class="fas fa-sync-alt me-2" aria-hidden="true"></i>Recalculate now</button>';
    $tr_actions .= '<button type="button" class="btn ' . ($tr_tab === 'rules' && $tr_level >= 3 ? 'btn-outline-secondary' : 'btn-primary') . '" id="tro-assign"><i class="fas fa-user-plus me-2" aria-hidden="true"></i>Assign training</button>';
}
if ($tr_tab === 'rules' && $tr_level >= 3) {
    $tr_actions .= '<a class="btn btn-primary" href="/agent/training_rule.php"><i class="fas fa-plus me-2" aria-hidden="true"></i>New rule</a>';
}
$tr_actions = $tr_actions === '' ? '' : '<div class="d-flex flex-wrap gap-2">' . $tr_actions . '</div>';
?>

<div class="tro-page" id="tro-assignments-page">
    <?php render_page_header('Assignments', 'Who needs to complete which training, and by when.', $tr_actions, [
        ['label' => 'Training', 'url' => '/agent/training.php'],
        ['label' => 'Assignments'],
    ]); ?>

    <?php tro_scope_banner($tr_scope); ?>

    <div class="tro-card">
        <?php tro_tabs([
            'assignments' => ['label' => 'Assignments', 'url' => '/agent/training_assignments.php', 'count' => null],
            'rules' => ['label' => 'Rules', 'url' => '/agent/training_assignments.php?tab=rules', 'count' => null],
        ], $tr_tab); ?>

        <?php if ($tr_tab === 'assignments') { ?>
        <div class="tro-seg" id="tro-a-status" role="group" aria-label="Show assignments that are"></div>
        <form class="tro-toolbar" id="tro-a-filters" method="get" action="/agent/training_assignments.php" role="search">
            <input type="hidden" name="status" value="<?= nullable_htmlentities($tr_filters['status']) ?>">
            <?php if ($tr_filters['requirement_id'] !== null) { ?><input type="hidden" name="requirement_id" value="<?= intval($tr_filters['requirement_id']) ?>"><?php } ?>
            <?php if ($tr_filters['contact_id'] !== null) { ?><input type="hidden" name="contact_id" value="<?= intval($tr_filters['contact_id']) ?>"><?php } ?>
            <div class="tro-toolbar__search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="tro-a-q">Search people or courses</label>
                <input type="search" class="form-control" id="tro-a-q" name="q" value="<?= nullable_htmlentities($tr_filters['q'] ?? '') ?>" placeholder="Search people or courses" maxlength="100">
            </div>
            <label class="visually-hidden" for="tro-a-dept">Department</label>
            <select class="form-select" id="tro-a-dept" name="client_id">
                <option value="">All departments</option>
                <?php foreach ($tr_data['departments'] as $tr_d) { ?>
                <option value="<?= intval($tr_d['id']) ?>"<?= $tr_filters['client_id'] === $tr_d['id'] ? ' selected' : '' ?>><?= nullable_htmlentities($tr_d['name']) ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="tro-a-course">Course</label>
            <select class="form-select" id="tro-a-course" name="course_id">
                <option value="">All courses</option>
                <?php foreach ($tr_data['courses'] as $tr_c) { ?>
                <option value="<?= intval($tr_c['id']) ?>"<?= $tr_filters['course_id'] === $tr_c['id'] ? ' selected' : '' ?>><?= nullable_htmlentities($tr_c['name']) ?></option>
                <?php } ?>
            </select>
            <div class="tro-chips" id="tro-a-chips"></div>
            <noscript><button type="submit" class="btn btn-outline-secondary">Apply</button></noscript>
        </form>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-a-table">
                <thead>
                    <tr>
                        <th scope="col">Person</th>
                        <th scope="col">Department</th>
                        <th scope="col">Course</th>
                        <th scope="col">Why</th>
                        <th scope="col">Due</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="tro-num">Days overdue</th>
                        <th scope="col" class="tro-actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="tro-a-body"></tbody>
            </table>
        </div>
        <div id="tro-a-empty"></div>
        <div class="tro-pager" id="tro-a-pager" hidden></div>
        <?php } else { ?>
        <div class="tro-seg" id="tro-r-view" role="group" aria-label="Show rules"></div>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-r-table">
                <thead>
                    <tr>
                        <th scope="col">Rule</th>
                        <th scope="col">Course</th>
                        <th scope="col">Who</th>
                        <th scope="col">People<span class="visually-hidden"> matched, open and overdue</span></th>
                        <th scope="col">Due</th>
                        <th scope="col">Created</th>
                        <th scope="col" class="tro-actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="tro-r-body"></tbody>
            </table>
        </div>
        <div id="tro-r-empty"></div>
        <?php } ?>
    </div>
</div>

<?php
tro_page_data($tr_data);
tro_scripts('/agent/js/training_assignments.js');
require_once "../includes/footer.php";
