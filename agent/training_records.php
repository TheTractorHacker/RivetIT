<?php

/*
 * Training › Records & sessions (Phase 2 spec §5.2): ?tab=log|sessions.
 *
 * The records log lists every completion (voided rows struck through) with GET filters that
 * stay in the URL; level 2 records external cards / paper records, practical evaluations and
 * sessions from here. First data comes from the same route handler as the JSON endpoint
 * (tro_action); agent/js/training_records.js renders it with DOM nodes and refetches through
 * completion_list / session_list.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_ops.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock for the page's JSON calls
require_once __DIR__ . '/includes/training_ops/ops.php';

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = (int) lookupUserPermission('module_training');
$tr_tab = tro_get_enum('tab', ['log', 'sessions'], 'log');
$tr_scope = tro_scope($mysqli, $tr_ctx);

$tr_filters = [
    'q' => tro_get_str('q', 100),
    'course_id' => tro_get_id('course_id'),
    'client_id' => tro_get_id('client_id', true),
    'method' => tro_get_enum('method', ['online', 'session', 'blended', 'evaluation', 'external', 'legacy_paper']),
    'strength' => tro_get_enum('strength', ['A', 'B', 'C', 'D', 'E']),
    'voided' => tro_get_enum('voided', ['include', 'exclude', 'only'], 'include'),
    'from' => tro_get_date('from'),
    'to' => tro_get_date('to'),
    'page' => tro_get_id('page') ?? 1,
];
$tr_sfilters = [
    'status' => tro_get_enum('status', ['open', 'finalized', 'cancelled']),
    'course_id' => tro_get_id('course_id'),
    'from' => tro_get_date('from'),
    'to' => tro_get_date('to'),
    'page' => tro_get_id('page') ?? 1,
];

$tr_list = $tr_tab === 'log' ? tro_action($mysqli, 'completion_list', array_filter($tr_filters, static fn($v) => $v !== null)) : null;
$tr_sessions = $tr_tab === 'sessions' ? tro_action($mysqli, 'session_list', array_filter($tr_sfilters, static fn($v) => $v !== null)) : null;

$tr_routes = [
    'completion_record' => tro_has_route('completion_record'),
    'evaluation_record' => tro_has_route('evaluation_record'),
    'session_save' => tro_has_route('session_save'),
    'report_csv' => tro_has_route('report_csv'),
];

$tr_data = [
    'level' => $tr_level,
    'user_id' => $tr_ctx->userId,
    'today' => tro_today(),
    'tab' => $tr_tab,
    'scope' => $tr_scope['state'],
    'filters' => $tr_filters,
    'sfilters' => $tr_sfilters,
    'list' => $tr_list,
    'sessions' => $tr_sessions,
    'courses' => tro_course_cards($mysqli),
    'departments' => tro_departments($mysqli, $tr_scope, true),
    'settings' => tro_records_settings($mysqli),
    'routes' => $tr_routes,
];

$tr_actions = '';
if ($tr_level >= 2) {
    // Every item is rendered; training_records.js hides the ones whose action is not installed yet (routes).
    $tr_actions .= '<div class="btn-group" id="tro-rec-actions">'
        . '<button type="button" class="btn btn-primary" id="tro-rec-external"><i class="fas fa-id-card me-2" aria-hidden="true"></i>Record external card or paper record</button>'
        . '<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" id="tro-rec-more" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More ways to record training"></button>'
        . '<ul class="dropdown-menu dropdown-menu-end">'
        . '<li><button type="button" class="dropdown-item" id="tro-rec-eval"><i class="fas fa-clipboard-check fa-fw me-2 text-muted" aria-hidden="true"></i>Record practical evaluation</button></li>'
        . '<li><a class="dropdown-item" id="tro-rec-session" href="/agent/training_session.php?new=1"><i class="fas fa-chalkboard-teacher fa-fw me-2 text-muted" aria-hidden="true"></i>New session</a></li>'
        . '</ul></div>';
}
if ($tr_tab === 'log') {
    $tr_actions = '<a class="btn btn-outline-secondary" id="tro-rec-csv" href="#"><i class="fas fa-file-csv me-2" aria-hidden="true"></i>CSV</a>' . $tr_actions;
}
$tr_actions = $tr_actions === '' ? '' : '<div class="d-flex flex-wrap gap-2">' . $tr_actions . '</div>';
?>

<div class="tro-page" id="tro-records-page">
    <?php render_page_header('Records & sessions', 'Every training record on file, how it was proven, and the classroom sessions behind them.', $tr_actions, [
        ['label' => 'Training', 'url' => '/agent/training.php'],
        ['label' => 'Records & sessions'],
    ]); ?>

    <?php tro_scope_banner($tr_scope); ?>

    <div class="tro-card">
        <?php tro_tabs([
            'log' => ['label' => 'Records', 'url' => '/agent/training_records.php'],
            'sessions' => ['label' => 'Sessions', 'url' => '/agent/training_records.php?tab=sessions'],
        ], $tr_tab); ?>

        <?php if ($tr_tab === 'log') { ?>
        <form class="tro-toolbar" id="tro-rec-filters" method="get" action="/agent/training_records.php" role="search">
            <div class="tro-toolbar__search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="tro-rec-q">Search name or record number</label>
                <input type="search" class="form-control" id="tro-rec-q" name="q" value="<?= nullable_htmlentities($tr_filters['q'] ?? '') ?>" placeholder="Name or record number" maxlength="100">
            </div>
            <label class="visually-hidden" for="tro-rec-course">Course</label>
            <select class="form-select" id="tro-rec-course" name="course_id">
                <option value="">All courses</option>
                <?php foreach ($tr_data['courses'] as $tr_c) { ?>
                <option value="<?= intval($tr_c['id']) ?>"<?= $tr_filters['course_id'] === $tr_c['id'] ? ' selected' : '' ?>><?= nullable_htmlentities($tr_c['name']) ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="tro-rec-dept">Department</label>
            <select class="form-select" id="tro-rec-dept" name="client_id">
                <option value="">All departments</option>
                <?php foreach ($tr_data['departments'] as $tr_d) { ?>
                <option value="<?= intval($tr_d['id']) ?>"<?= $tr_filters['client_id'] === $tr_d['id'] ? ' selected' : '' ?>><?= nullable_htmlentities($tr_d['name']) ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="tro-rec-method">How it was done</label>
            <select class="form-select" id="tro-rec-method" name="method">
                <option value="">Any method</option>
                <?php foreach (['online' => 'Online course', 'session' => 'Instructor-led session', 'blended' => 'Blended', 'evaluation' => 'Practical evaluation', 'external' => 'External card', 'legacy_paper' => 'Paper record'] as $tr_k => $tr_v) { ?>
                <option value="<?= $tr_k ?>"<?= $tr_filters['method'] === $tr_k ? ' selected' : '' ?>><?= nullable_htmlentities($tr_v) ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="tro-rec-strength">Evidence strength</label>
            <select class="form-select" id="tro-rec-strength" name="strength">
                <option value="">Any evidence</option>
                <?php foreach (['A' => 'A · PIN + signature', 'B' => 'B · Trainer session', 'C' => 'C · Trainer attests', 'D' => 'D · Scan or external card', 'E' => 'E · Recorded by office'] as $tr_k => $tr_v) { ?>
                <option value="<?= $tr_k ?>"<?= $tr_filters['strength'] === $tr_k ? ' selected' : '' ?>><?= nullable_htmlentities($tr_v) ?></option>
                <?php } ?>
            </select>
            <label class="visually-hidden" for="tro-rec-voided">Voided records</label>
            <select class="form-select" id="tro-rec-voided" name="voided">
                <option value="include"<?= $tr_filters['voided'] === 'include' ? ' selected' : '' ?>>Include voided</option>
                <option value="exclude"<?= $tr_filters['voided'] === 'exclude' ? ' selected' : '' ?>>Hide voided</option>
                <option value="only"<?= $tr_filters['voided'] === 'only' ? ' selected' : '' ?>>Only voided</option>
            </select>
            <div class="d-flex align-items-center gap-2">
                <label class="small text-muted" for="tro-rec-from">From</label>
                <input type="date" class="form-control tro-date-sm" id="tro-rec-from" name="from" value="<?= nullable_htmlentities($tr_filters['from'] ?? '') ?>">
                <label class="small text-muted" for="tro-rec-to">to</label>
                <input type="date" class="form-control tro-date-sm" id="tro-rec-to" name="to" value="<?= nullable_htmlentities($tr_filters['to'] ?? '') ?>">
            </div>
            <noscript><button type="submit" class="btn btn-outline-secondary">Apply</button></noscript>
        </form>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-rec-table">
                <thead>
                    <tr>
                        <th scope="col">Record #</th>
                        <th scope="col">Person</th>
                        <th scope="col">Course</th>
                        <th scope="col">How it was proven</th>
                        <th scope="col">Completed</th>
                        <th scope="col">Expires</th>
                        <th scope="col">Certificate</th>
                        <th scope="col">Recorded by</th>
                    </tr>
                </thead>
                <tbody id="tro-rec-body"></tbody>
            </table>
        </div>
        <div id="tro-rec-empty"></div>
        <div class="tro-pager" id="tro-rec-pager" hidden></div>
        <?php } else { ?>
        <div class="tro-seg" id="tro-ses-status" role="group" aria-label="Show sessions that are"></div>
        <div class="tro-table-wrap">
            <table class="table table-vcenter card-table tro-table" id="tro-ses-table">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Course</th>
                        <th scope="col">Topic</th>
                        <th scope="col">Trainer</th>
                        <th scope="col">Department</th>
                        <th scope="col" class="tro-num">Present</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody id="tro-ses-body"></tbody>
            </table>
        </div>
        <div id="tro-ses-empty"></div>
        <div class="tro-pager" id="tro-ses-pager" hidden></div>
        <?php } ?>
    </div>

    <div class="tro-card mt-3">
        <div class="tro-card__head">
            <h2 class="tro-card__title">Evidence strength</h2>
            <span class="tro-card__sub">How each record was proven</span>
        </div>
        <div class="tro-card__body" id="tro-rec-legend"></div>
    </div>
</div>

<?php
tro_page_data($tr_data);
tro_scripts('/agent/js/training_records.js');
require_once "../includes/footer.php";
