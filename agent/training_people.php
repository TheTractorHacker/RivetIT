<?php

/*
 * Training › People (Phase 2 spec §5.2): ?tab=roster|groups|trainers|links.
 *
 * Level 1 reads; level 3 includes/excludes people from the training roster, sets hire dates,
 * edits job groups and trainers. Links is read-only here (the actions live on the admin page
 * Admin › Training compliance). First data comes from the same route handler the JSON
 * endpoint runs (tro_action); agent/js/training_people.js renders it with DOM nodes and
 * refetches through people_roster / jobgroup_list / trainer_list. People scope is
 * fail-closed: with no department access the page shows the banner and empty tables.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_ops.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock for the page's JSON calls
require_once __DIR__ . '/includes/training_ops/ops.php';

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = (int) lookupUserPermission('module_training');
$tr_tab = tro_get_enum('tab', ['roster', 'groups', 'trainers', 'links'], 'roster');
$tr_scope = tro_scope($mysqli, $tr_ctx);

// Roster filter states: the people_roster `state` values (auto|include|exclude|eligible|ineligible|all).
$tr_states = ['eligible', 'ineligible', 'include', 'exclude'];
$tr_filters = [
    'q' => tro_get_str('q', 100),
    'client_id' => tro_get_id('client_id', true),
    'state' => tro_get_enum('state', $tr_states),
    'page' => tro_get_id('page') ?? 1,
];

$tr_roster = $tr_tab === 'roster' ? tro_action($mysqli, 'people_roster', array_filter($tr_filters, static fn($v) => $v !== null)) : null;
// Links: the first roster page; the page script reads the remaining pages and keeps the links that need review.
$tr_links = $tr_tab === 'links' ? tro_action($mysqli, 'people_roster', ['state' => 'all']) : null;
$tr_groups = $tr_tab === 'groups' ? tro_action($mysqli, 'jobgroup_list', ['include_archived' => '1']) : null;
$tr_trainers = $tr_tab === 'trainers' ? tro_action($mysqli, 'trainer_list') : null;

$tr_routes = [
    'roster_set' => tro_has_route('roster_set'),
    'hire_date_set' => tro_has_route('hire_date_set'),
    'jobgroup_save' => tro_has_route('jobgroup_save'),
    'jobgroup_archive' => tro_has_route('jobgroup_archive'),
    'jobgroup_titles' => tro_has_route('jobgroup_titles'),
    'trainer_save' => tro_has_route('trainer_save'),
    'assign_manual' => tro_has_route('assign_manual'),
];

$tr_data = [
    'level' => $tr_level,
    'user_id' => $tr_ctx->userId,
    'is_admin' => (bool) $tr_ctx->isAdmin,
    'today' => tro_today(),
    'tab' => $tr_tab,
    'scope' => $tr_scope['state'],
    'filters' => $tr_filters,
    'roster' => $tr_roster,
    'links' => $tr_links,
    'groups' => $tr_groups,
    'trainers' => $tr_trainers,
    'courses' => tro_course_cards($mysqli),
    'departments' => tro_departments($mysqli, $tr_scope, true),
    'settings' => tro_records_settings($mysqli),
    'routes' => $tr_routes,
];

$tr_actions = '';
if ($tr_tab === 'groups' && $tr_level >= 3) {
    $tr_actions = '<button type="button" class="btn btn-primary" id="tro-pg-new"><i class="fas fa-plus me-2" aria-hidden="true"></i>New job group</button>';
} elseif ($tr_tab === 'trainers' && $tr_level >= 3) {
    $tr_actions = '<button type="button" class="btn btn-primary" id="tro-pt-new"><i class="fas fa-user-plus me-2" aria-hidden="true"></i>Add trainer</button>';
} elseif ($tr_tab === 'links' && $tr_ctx->isAdmin) {
    $tr_actions = '<a class="btn btn-outline-secondary" href="/admin/settings_training_compliance.php"><i class="fas fa-cog me-2" aria-hidden="true"></i>Manage links</a>';
}

$tr_state_labels = [
    'eligible' => 'On the roster',
    'ineligible' => 'Not on the roster',
    'include' => 'Included by hand',
    'exclude' => 'Excluded',
];
?>

<div class="tro-page" id="tro-people-page">
    <?php render_page_header('People', 'Who is on the training roster, the job groups rules can target, and who may train or evaluate.', $tr_actions, [
        ['label' => 'Training', 'url' => '/agent/training.php'],
        ['label' => 'People'],
    ]); ?>

    <?php tro_scope_banner($tr_scope); ?>

    <div class="tro-card">
        <?php tro_tabs([
            'roster' => ['label' => 'Roster', 'url' => '/agent/training_people.php'],
            'groups' => ['label' => 'Job groups', 'url' => '/agent/training_people.php?tab=groups'],
            'trainers' => ['label' => 'Trainers', 'url' => '/agent/training_people.php?tab=trainers'],
            'links' => ['label' => 'Odoo links', 'url' => '/agent/training_people.php?tab=links'],
        ], $tr_tab); ?>

        <?php if ($tr_tab === 'roster') { ?>
        <form class="tro-toolbar" id="tro-pr-filters" method="get" action="/agent/training_people.php" role="search">
            <div class="tro-toolbar__search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="tro-pr-q">Search name or title</label>
                <input type="search" class="form-control" id="tro-pr-q" name="q" value="<?= nullable_htmlentities($tr_filters['q'] ?? '') ?>" placeholder="Search name or title" maxlength="100">
            </div>
            <label class="visually-hidden" for="tro-pr-dept">Department</label>
            <select class="form-select" id="tro-pr-dept" name="client_id">
                <option value="">All departments</option>
                <?php foreach ($tr_data['departments'] as $tr_d) { ?>
                <option value="<?= intval($tr_d['id']) ?>"<?= $tr_filters['client_id'] === $tr_d['id'] ? ' selected' : '' ?>><?= nullable_htmlentities($tr_d['name']) ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="tro-pr-state">Roster state</label>
            <select class="form-select" id="tro-pr-state" name="state">
                <option value="">Everyone</option>
                <?php foreach ($tr_state_labels as $tr_k => $tr_v) { ?>
                <option value="<?= $tr_k ?>"<?= $tr_filters['state'] === $tr_k ? ' selected' : '' ?>><?= nullable_htmlentities($tr_v) ?></option>
                <?php } ?>
            </select>
            <noscript><button type="submit" class="btn btn-outline-secondary">Apply</button></noscript>
        </form>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-pr-table">
                <thead>
                    <tr>
                        <th scope="col">Person</th>
                        <th scope="col">Department</th>
                        <th scope="col">Job and location</th>
                        <th scope="col">Hire date</th>
                        <th scope="col">Training roster</th>
                        <th scope="col">Odoo link</th>
                        <th scope="col" class="tro-actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="tro-pr-body"></tbody>
            </table>
        </div>
        <div id="tro-pr-empty"></div>
        <div class="tro-pager" id="tro-pr-pager" hidden></div>
        <?php } elseif ($tr_tab === 'groups') { ?>
        <div class="tro-card__body pb-0">
            <p class="text-muted small mb-0">A job group names people by their job title or by hand, for example "Welders" or "Forklift drivers". Rules can then target the group, and new people with a matching title join it automatically.</p>
        </div>
        <div class="tro-grid" id="tro-pg-grid"></div>
        <div id="tro-pg-empty"></div>
        <?php } elseif ($tr_tab === 'trainers') { ?>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-pt-table">
                <thead>
                    <tr>
                        <th scope="col">Trainer</th>
                        <th scope="col">May</th>
                        <th scope="col">Courses</th>
                        <th scope="col">Departments</th>
                        <th scope="col">Qualifications</th>
                        <th scope="col" class="tro-actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="tro-pt-body"></tbody>
            </table>
        </div>
        <div id="tro-pt-empty"></div>
        <?php } else { ?>
        <div class="tro-card__body tro-links-head" id="tro-pl-head"></div>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-pl-table">
                <thead>
                    <tr>
                        <th scope="col">Person</th>
                        <th scope="col">Department</th>
                        <th scope="col">Link</th>
                        <th scope="col">What we found</th>
                    </tr>
                </thead>
                <tbody id="tro-pl-body"></tbody>
            </table>
        </div>
        <div id="tro-pl-empty"></div>
        <div class="tro-card__foot" id="tro-pl-legend"></div>
        <?php } ?>
    </div>
</div>

<?php
tro_page_data($tr_data);
tro_scripts('/agent/js/training_people.js');
require_once "../includes/footer.php";
