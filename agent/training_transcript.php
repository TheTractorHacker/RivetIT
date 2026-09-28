<?php

/*
 * Training › Transcript (Phase 2 spec §5.1, M10; mockup Admin-Transcript).
 *
 * GET /agent/training_transcript.php?contact_id=<id>[&print=1]
 *
 * Level 1, then People\Scope::assertContact (fail-closed; a person outside the caller's
 * departments is the same "not found" as a missing one, spec §0 #3). Server-rendered from
 * Reports\TranscriptService::build(); agent/js/training_transcript.js only toggles revoked
 * rows, keeps the chosen tab in the URL hash and opens the hire-date form (Lane E's
 * TrainingOps, when present).
 *
 * &print=1 is a standalone Letter-portrait document through the print shell (no inc_all):
 * lean bootstrap -> module on and level >= 1 -> scope -> session_write_close().
 */

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Access;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Reports\Labels;
use ITFlow\Training\Reports\Lookup;
use ITFlow\Training\Reports\TranscriptService;

$trr_contact_id = isset($_GET['contact_id']) && ctype_digit((string) $_GET['contact_id']) ? (int) $_GET['contact_id'] : 0;

// ---- Printable transcript (standalone document) --------------------------------------------------
if (isset($_GET['print'])) {
    ob_start();
    require_once "../config.php";
    require_once "../functions.php";
    require_once "../includes/check_login.php";
    ob_end_clean();
    define('TRAINING_PAGE', 1);
    require __DIR__ . '/includes/training_records/print_shell.php';
    $trr_missing = 'This person does not exist, or you do not have access to their training records.';
    if (!Access::enabled() || Access::level() < 1) {
        session_write_close();
        trr_print_not_found($trr_missing);
    }
    $trr_ctx = Access::ctx($mysqli);
    try {
        $trr_scope = Scope::forCtx($trr_ctx);
        $trr_scope->assertContact($mysqli, $trr_contact_id);
        session_write_close();
        $trr = (new TranscriptService($trr_ctx, $trr_scope))->build($trr_contact_id);
    } catch (ApiException $e) {
        session_write_close();
        trr_print_not_found($trr_missing);
    } catch (\Throwable $e) {
        error_log('Training transcript print #' . $trr_contact_id . ': ' . get_class($e) . ': ' . $e->getMessage());
        session_write_close();
        http_response_code(500);
        trr_print_begin('Transcript unavailable', 'portrait');
        echo '<main class="trr-print-missing"><h1>Something went wrong</h1><p>The transcript could not be built. Try again.</p></main>';
        trr_print_end();
        exit;
    }
    $trr_generated_by = Lookup::userName($mysqli, $trr_ctx->userId) ?? ('User #' . $trr_ctx->userId);
    require __DIR__ . '/includes/training_records/transcript_print.php';
    exit;
}

// ---- Agent page ---------------------------------------------------------------------------------
$trr_ops_js = is_file(__DIR__ . '/js/training_ops_forms.js') && is_file(__DIR__ . '/../css/itflow_training_ops.css');
$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_reports.css'];   // BEFORE inc_all
if ($trr_ops_js) {
    $page_extra_css[] = '/css/itflow_training_ops.css';
}
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
session_write_close();   // spec §0 #9: nothing below writes the session (inc_all has shown the flash); free its lock before the reports compute
require_once __DIR__ . '/includes/training_records/report_ui.php';

$trr_ctx = Access::ctx($mysqli);
$trr_scope = Scope::forCtx($trr_ctx);
$trr_level = $trr_ctx->level;
$trr = null;
$trr_not_found = false;
$trr_error = null;
try {
    $trr_scope->assertContact($mysqli, $trr_contact_id);
    $trr = (new TranscriptService($trr_ctx, $trr_scope))->build($trr_contact_id);
} catch (ApiException $e) {
    $trr_not_found = true;
} catch (\Throwable $e) {
    error_log('Training transcript #' . $trr_contact_id . ': ' . get_class($e) . ': ' . $e->getMessage());
    $trr_error = 'The transcript could not be built. Try again in a moment.';
}

$trr_crumbs = [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Records & sessions', 'url' => '/agent/training_records.php']];

if ($trr === null) {
    // The shell has already been sent by inc_all, so the refusal is the page's not-found state
    // (the JSON actions and the standalone print documents answer 404).
    ?>
<div class="trr-page trr-transcript">
    <?php render_page_header('Transcript', null, '', array_merge($trr_crumbs, [['label' => 'Transcript']])); ?>
    <?php if ($trr_scope->isNone()) { trr_scope_banner(); } ?>
    <div class="card"><div class="card-body">
        <?php if ($trr_not_found) {
            render_empty_state('fas fa-user-slash', 'Person not found', 'This person does not exist, or they are outside the departments you can see.',
                '<a class="btn btn-outline-secondary" href="/agent/training_dashboard.php">Back to the training overview</a>');
        } else {
            render_empty_state('fas fa-exclamation-triangle', 'Something went wrong', $trr_error ?? '', '');
        } ?>
    </div></div>
</div>
    <?php
    require_once "../includes/footer.php";
    exit;
}

$trr_p = $trr['contact'];
$trr_s = $trr['summary'];
$trr_quals = $trr['qualifications'];
$trr_revoked_n = count(array_filter($trr_quals, static fn($q) => $q['status'] === 'revoked'));
$trr_generated_by = Lookup::userName($mysqli, $trr_ctx->userId) ?? ('User #' . $trr_ctx->userId);
$trr_target = \ITFlow\Training\Core\RecordsSettings::fromDb($mysqli)->targetPct;

$trr_sub = array_values(array_filter([$trr_p['title'], $trr_p['department']['name'] ?? ($trr_p['archived'] ? null : Lookup::NO_DEPARTMENT), $trr_p['location']]));
$trr_actions = '<a class="btn btn-outline-secondary" href="' . trr_h(trr_url('training_transcript.php', ['contact_id' => $trr_p['id'], 'print' => 1])) . '" target="_blank" rel="noopener">'
    . '<i class="fas fa-print me-2" aria-hidden="true"></i>Print transcript</a>';
// Phase 5 (spec §7.7, S2): the transcript as a PDF file, when the PDF endpoint is deployed.
if (is_file(__DIR__ . '/training_pdf.php')) {
    $trr_actions .= '<a class="btn btn-outline-secondary" href="' . trr_h(trr_url('training_pdf.php', ['doc' => 'transcript', 'contact_id' => $trr_p['id']])) . '" target="_blank" rel="noopener">'
        . '<i class="fas fa-file-pdf me-2" aria-hidden="true"></i>Download PDF</a>';
}
if ($trr_level >= 2 && !$trr_p['archived']) {
    $trr_actions .= '<a class="btn btn-primary" href="' . trr_h(trr_url('training_assignments.php', ['assign' => $trr_p['id']])) . '"><i class="fas fa-plus me-2" aria-hidden="true"></i>Assign training</a>';
}
if ($trr_level >= 3) {
    $trr_actions .= '<div class="dropdown">'
        . '<button type="button" class="btn btn-outline-secondary btn-icon" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>'
        . '<div class="dropdown-menu dropdown-menu-end">'
        . ($trr_ops_js
            ? '<button type="button" class="dropdown-item" data-trr-hire><i class="far fa-calendar-alt me-2" aria-hidden="true"></i>Set hire date / Rehired</button>'
            : '<a class="dropdown-item" href="' . trr_h(trr_url('training_people.php', ['tab' => 'roster', 'q' => $trr_p['name']])) . '"><i class="far fa-calendar-alt me-2" aria-hidden="true"></i>Set hire date / Rehired</a>')
        . '</div></div>';
}

/** One qualification row's "Expires" cell. */
$trr_expires_cell = static function (array $q): string {
    if ($q['voided'] !== null) {
        return '<span class="trr-struck">' . trr_h(trr_date($q['expires_on'])) . '</span><span class="trr-sub trr-bad">Voided ' . trr_h(trr_date($q['voided']['on'])) . '</span>';
    }
    if ($q['status'] === 'revoked') {
        return ($q['expires_on'] !== null ? '<span class="trr-struck">' . trr_h(trr_date($q['expires_on'])) . '</span>' : '')
            . '<span class="trr-sub">Revoked' . ($q['rr'] !== null ? ' ' . trr_h(trr_date($q['rr']['revoked_on'])) : '') . '</span>';
    }
    if ($q['expires_on'] === null) {
        return '<span class="trr-muted">Does not expire</span>';
    }
    if ($q['status'] === 'expired') {
        return '<span class="trr-bad fw-semibold">' . trr_h(trr_date($q['expires_on'])) . '</span><span class="trr-sub trr-bad">Expired</span>';
    }
    if ($q['status'] === 'expiring') {
        $d = (int) $q['expires_days'];
        return '<span class="trr-warn-text fw-semibold">' . trr_h(trr_date($q['expires_on'])) . '</span><span class="trr-sub trr-warn-text">'
            . ($d <= 0 ? 'today' : 'in ' . $d . ' ' . ($d === 1 ? 'day' : 'days')) . '</span>';
    }
    return trr_h(trr_date($q['expires_on']));
};

/** "Online · Rev 4 · 12 months" */
$trr_course_sub = static function (array $q): string {
    $bits = [$q['method_label']];
    if ($q['revision_number'] !== null) {
        $bits[] = 'Rev ' . $q['revision_number'];
    }
    if ($q['validity_label'] !== null) {
        $bits[] = $q['validity_label'];
    }
    return implode(' · ', $bits);
};

$trr_trainer_cell = static function (array $q): string {
    if ($q['trainer_or_evaluator'] === null) {
        return '<span class="trr-muted">' . ($q['method'] === 'online' ? 'Self-paced' : '—') . '</span>';
    }
    return trr_h($q['trainer_or_evaluator']) . ($q['trainer_sub'] !== null ? '<span class="trr-sub">' . trr_h($q['trainer_sub']) . '</span>' : '');
};

$trr_record_link = static function (array $q): string {
    $label = $q['cert_number'] ?? ('#' . $q['completion_id']);
    return '<a class="trr-mono trr-cert-link" href="/agent/training_certificate.php?id=' . (int) $q['completion_id'] . '" target="_blank" rel="noopener" title="Open certificate"><i class="fas fa-certificate" aria-hidden="true"></i>' . trr_h($label) . '<span class="visually-hidden"> (opens the certificate in a new tab)</span></a>';
};

$trr_strength_bars = static function (string $grade): string {
    $on = ['A' => 5, 'B' => 4, 'C' => 3, 'D' => 2, 'E' => 1][$grade] ?? 0;
    $html = '<span class="trr-strength" aria-hidden="true">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<i' . ($i <= $on ? ' class="is-on"' : '') . '></i>';
    }
    return $html . '</span>';
};

$trr_assign_chip = static function (array $a): string {
    if (!empty($a['lapsed'])) {
        return '<span class="trr-chip trr-chip--err" title="Renewal due ' . trr_h(trr_date($a['due_on'])) . '"><i class="fas fa-times-circle" aria-hidden="true"></i>Expired — not qualified</span>';
    }
    return match ($a['display_status']) {
        'overdue' => '<span class="trr-chip trr-chip--err"><i class="fas fa-exclamation-circle" aria-hidden="true"></i>Overdue since ' . trr_h(trr_date($a['due_on'], true)) . '</span>',
        'due_soon' => '<span class="trr-chip trr-chip--warn"><i class="far fa-clock" aria-hidden="true"></i>Due ' . trr_h(trr_date($a['due_on'], true)) . '</span>',
        'due' => '<span class="trr-chip trr-chip--info"><i class="far fa-calendar" aria-hidden="true"></i>Due ' . trr_h(trr_date($a['due_on'], true)) . '</span>',
        'completed' => '<span class="trr-chip trr-chip--ok"><i class="fas fa-check" aria-hidden="true"></i>Completed</span>',
        'waived' => '<span class="trr-chip trr-chip--neutral"><i class="fas fa-ban" aria-hidden="true"></i>Waived' . ($a['waived_until'] !== null ? ' until ' . trr_h(trr_date($a['waived_until'], true)) : '') . '</span>',
        'cancelled' => '<span class="trr-chip trr-chip--neutral"><i class="fas fa-times" aria-hidden="true"></i>Cancelled</span>',
        default => '<span class="trr-chip trr-chip--neutral">' . trr_h(ucfirst($a['display_status'])) . '</span>',
    };
};
// Level 2+: Extend / Waive / Reset / Un-waive / History on each assignment row, the same TrainingOps forms as the
// Assignments list (training_transcript.js fills the cell; the server re-checks level and scope on every action).
$trr_row_menu = $trr_ops_js && $trr_level >= 2;
$trr_close_reason = static fn(?string $r): string => Labels::closeReason($r);
?>

<div class="trr-page trr-transcript" id="trr-transcript">
    <div class="it-page-header trr-crumbs-only">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php foreach ($trr_crumbs as $trr_cb) { ?><li class="breadcrumb-item"><a href="<?= trr_h($trr_cb['url']) ?>"><?= trr_h($trr_cb['label']) ?></a></li><?php } ?>
                <li class="breadcrumb-item active" aria-current="page"><?= trr_h($trr_p['name']) ?></li>
            </ol>
        </nav>
    </div>

    <!-- Person header -->
    <section class="card trr-card" aria-label="Person">
        <div class="card-body">
            <div class="trr-person">
                <?= trr_avatar($trr_p['initials'], 'trr-avatar--lg') ?>
                <div class="trr-person__main">
                    <h1 class="trr-person__name"><?= trr_h($trr_p['name']) ?><?php if ($trr_p['archived']) { ?> <span class="trr-chip trr-chip--neutral align-middle">Archived</span><?php } ?></h1>
                    <?php if ($trr_sub !== []) { ?><p class="trr-person__sub"><?= trr_h(implode(' · ', $trr_sub)) ?></p><?php } ?>
                    <div class="trr-person__chips">
                        <?php if ($trr_s['pct'] !== null) {
                            $trr_pc = (int) $trr_s['pct'];
                            $trr_pc_cls = $trr_pc >= $trr_target ? 'ok' : ($trr_pc >= 80 ? 'warn' : 'err');
                            ?>
                        <span class="trr-chip trr-chip--<?= $trr_pc_cls ?>"><span class="trr-ring" style="--trr-ring: <?= $trr_pc ?>%" aria-hidden="true"></span><?= $trr_pc ?>% compliant</span>
                        <?php } elseif ($trr_p['eligible'] === false) { ?>
                        <span class="trr-chip trr-chip--neutral"><i class="fas fa-user-minus" aria-hidden="true"></i>Not on the training roster</span>
                        <?php } else { ?>
                        <span class="trr-chip trr-chip--neutral">No required training</span>
                        <?php } ?>
                        <?php if ($trr_s['overdue'] > 0) { ?>
                        <span class="trr-chip trr-chip--err"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><?= (int) $trr_s['overdue'] ?> overdue</span>
                        <?php } ?>
                        <span>
                            <?php
                            $trr_meta = [];
                            if ($trr_p['employee_no'] !== null) {
                                $trr_meta[] = 'Employee <strong class="trr-mono">#' . trr_h($trr_p['employee_no']) . '</strong>';
                            }
                            $trr_meta[] = $trr_p['hire_date'] !== null ? 'Hired ' . trr_h((new DateTimeImmutable($trr_p['hire_date']))->format('M Y')) : 'Hire date not set';
                            echo implode(' · ', $trr_meta);
                            ?>
                        </span>
                    </div>
                </div>
                <div class="trr-person__actions"><?= $trr_actions ?></div>
            </div>

            <dl class="trr-strip">
                <div>
                    <dt>Valid qualifications</dt>
                    <dd><?= (int) $trr_s['valid'] ?> <small>of <?= (int) $trr_s['on_file'] ?> on file</small></dd>
                </div>
                <div>
                    <dt>Expiring in 60 days</dt>
                    <dd><?= (int) $trr_s['expiring_60'] ?><?php if ($trr_s['first_expiring'] !== null) { ?> <small class="trr-warn-text"><?= trr_h(Labels::shortCourse($trr_s['first_expiring']['course']['code'], $trr_s['first_expiring']['course']['name'])) ?>, <?= trr_h(trr_date($trr_s['first_expiring']['expires_on'], true)) ?></small><?php } ?></dd>
                </div>
                <div>
                    <dt>Open assignments</dt>
                    <dd><?= (int) $trr_s['open_assignments'] ?><?php if ($trr_s['open_overdue'] > 0) { ?> <small class="trr-bad"><?= (int) $trr_s['open_overdue'] ?> overdue</small><?php } ?></dd>
                </div>
                <div>
                    <dt>Training hours, <?= trr_h($trr_s['hours_year']) ?></dt>
                    <dd><?= trr_h(number_format((float) $trr_s['hours'], 1)) ?> <small>hrs</small></dd>
                </div>
                <div>
                    <dt>Last trained</dt>
                    <dd><?= $trr_s['last_trained_on'] !== null ? trr_h(trr_date($trr_s['last_trained_on'])) : '<small>Never</small>' ?></dd>
                </div>
                <div>
                    <dt>Supervisor</dt>
                    <dd><?php if ($trr_p['manager'] !== null) { ?><?= trr_h($trr_p['manager']['name']) ?><?php } else { ?><small>Not set</small><?php } ?></dd>
                </div>
            </dl>
        </div>
    </section>

    <!-- Tabs -->
    <section class="card trr-card" aria-label="Training records">
        <div class="trr-tabbar">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="trr-tab-quals" data-bs-toggle="tab" data-bs-target="#trr-pane-quals" type="button" role="tab" aria-controls="trr-pane-quals" aria-selected="true">Qualifications <span class="trr-count"><?= count($trr_quals) ?></span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="trr-tab-assign" data-bs-toggle="tab" data-bs-target="#trr-pane-assign" type="button" role="tab" aria-controls="trr-pane-assign" aria-selected="false">Assignments <span class="trr-count"><?= count($trr['assignments']) ?></span></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="trr-tab-history" data-bs-toggle="tab" data-bs-target="#trr-pane-history" type="button" role="tab" aria-controls="trr-pane-history" aria-selected="false">History <span class="trr-count"><?= count($trr['history']) ?></span></button>
                </li>
                <?php if ($trr['achievements'] !== null) { ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="trr-tab-ach" data-bs-toggle="tab" data-bs-target="#trr-pane-ach" type="button" role="tab" aria-controls="trr-pane-ach" aria-selected="false">Achievements <span class="trr-count"><?= count($trr['achievements']) ?></span></button>
                </li>
                <?php } ?>
            </ul>
            <div class="trr-tabbar__tools">
                <?php if ($trr_revoked_n > 0) { ?>
                <label class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="trr-show-revoked" checked>
                    <span class="form-check-label">Show revoked</span>
                </label>
                <?php } ?>
                <span>As of <?= trr_h(trr_date($trr['as_of'])) ?></span>
            </div>
        </div>

        <div class="tab-content">
            <!-- Qualifications -->
            <div class="tab-pane fade show active" id="trr-pane-quals" role="tabpanel" aria-labelledby="trr-tab-quals" tabindex="0">
                <?php if ($trr_quals === []) { ?>
                <div class="card-body"><?php render_empty_state('fas fa-id-card', 'No qualifications on file', 'Completed training courses appear here with their certificate status.', ''); ?></div>
                <?php } else { ?>
                <div class="trr-table-wrap">
                    <table class="table table-vcenter card-table trr-table">
                        <thead>
                            <tr>
                                <th scope="col">Course</th>
                                <th scope="col">Status</th>
                                <th scope="col">Method</th>
                                <th scope="col" class="text-end">Score</th>
                                <th scope="col">Trained on</th>
                                <th scope="col">Expires</th>
                                <th scope="col">Trainer / evaluator</th>
                                <th scope="col">Record #</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trr_quals as $trr_q) {
                                $trr_cls = $trr_q['status'] === 'expiring' ? 'is-flag' : ($trr_q['status'] === 'revoked' ? 'is-revoked' : '');
                                ?>
                            <tr class="<?= $trr_cls ?>"<?= $trr_q['status'] === 'revoked' ? ' data-trr-revoked' : '' ?>>
                                <td>
                                    <a class="fw-semibold trr-link-ink" href="/agent/training_record.php?id=<?= (int) $trr_q['completion_id'] ?>"><?= trr_h($trr_q['course']['name']) ?></a>
                                    <?php if ($trr_q['status'] === 'revoked' && $trr_q['status_reason'] === 'retrain_required') { ?>
                                    <span class="trr-sub"><span class="trr-chip trr-chip--outline">Rev <?= (int) $trr_q['revision_number'] ?></span> <span class="trr-bad">Revoked: retrain required<?= $trr_q['rr'] !== null ? ' (rev ' . (int) $trr_q['rr']['revision_number'] . ' published)' : '' ?></span></span>
                                    <?php } else { ?>
                                    <span class="trr-sub"><?= trr_h($trr_course_sub($trr_q)) ?></span>
                                    <?php } ?>
                                </td>
                                <td><?= trr_cert_chip($trr_q['status'], $trr_q['status_reason']) ?></td>
                                <td class="text-nowrap"><?= trr_grade($trr_q['grade'], $trr_q['strength_label']) ?></td>
                                <td class="text-end trr-num"><?= $trr_q['score_pct'] !== null ? trr_h($trr_q['score_pct']) . '%' : '<span class="trr-muted">—</span>' ?></td>
                                <td class="text-nowrap"><?= trr_h(trr_date($trr_q['trained_on'] ?? $trr_q['completed_on'])) ?></td>
                                <td class="text-nowrap"><?= $trr_expires_cell($trr_q) ?></td>
                                <td><?= $trr_trainer_cell($trr_q) ?></td>
                                <td><?= $trr_record_link($trr_q) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>

            <!-- Assignments -->
            <div class="tab-pane fade" id="trr-pane-assign" role="tabpanel" aria-labelledby="trr-tab-assign" tabindex="0">
                <?php if ($trr['assignments'] === []) { ?>
                <div class="card-body"><?php render_empty_state('fas fa-tasks', 'No assignments', 'Open assignments, and those closed in the last 12 months, appear here.', ''); ?></div>
                <?php } else { ?>
                <div class="trr-table-wrap">
                    <table class="table table-vcenter card-table trr-table">
                        <thead>
                            <tr>
                                <th scope="col">Course</th>
                                <th scope="col">Why</th>
                                <th scope="col">Due</th>
                                <th scope="col">Status</th>
                                <th scope="col">Assigned</th>
                                <th scope="col">Closed</th>
                                <?php if ($trr_row_menu) { ?><th scope="col" class="trr-row-actions"><span class="visually-hidden">Actions</span></th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trr['assignments'] as $trr_a) { ?>
                            <tr>
                                <td class="fw-semibold"><?= trr_h($trr_a['course']['name']) ?><?= !$trr_a['required'] ? ' <span class="trr-chip trr-chip--outline">Optional</span>' : '' ?></td>
                                <td><?= trr_h($trr_a['anchor_label']) ?></td>
                                <td class="text-nowrap"<?= $trr_a['original_due_on'] !== $trr_a['due_on'] ? ' title="Originally due ' . trr_h(trr_date($trr_a['original_due_on'])) . '"' : '' ?>>
                                    <?= trr_h(trr_date($trr_a['due_on'])) ?><?= $trr_a['original_due_on'] !== $trr_a['due_on'] ? '<span class="trr-sub">' . ($trr_a['due_on'] > $trr_a['original_due_on'] ? 'Extended' : 'Due date moved') . '</span>' : '' ?>
                                </td>
                                <td><?= $trr_assign_chip($trr_a) ?><?php if ($trr_a['status'] === 'completed' && $trr_a['completion_voided']) { ?><span class="trr-sub">Record voided</span><?php } ?></td>
                                <td class="text-nowrap"><?= trr_h(trr_date($trr_a['created_on'])) ?><?php if ($trr_a['created_by_name'] !== null) { ?><span class="trr-sub">by <?= trr_h($trr_a['created_by_name']) ?></span><?php } ?></td>
                                <td class="text-nowrap"><?php if ($trr_a['closed_at'] !== null) { ?><?= trr_h(trr_clock($trr_a['closed_at'])) ?><span class="trr-sub"><?= trr_h($trr_close_reason($trr_a['close_reason'])) ?></span><?php } else { ?><span class="trr-muted">—</span><?php } ?></td>
                                <?php if ($trr_row_menu) { ?><td class="trr-row-actions" data-trr-assign-menu="<?= (int) $trr_a['id'] ?>"></td><?php } ?>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>

            <!-- History -->
            <div class="tab-pane fade" id="trr-pane-history" role="tabpanel" aria-labelledby="trr-tab-history" tabindex="0">
                <?php if ($trr['history'] === []) { ?>
                <div class="card-body"><?php render_empty_state('fas fa-history', 'No training records yet', 'Every completion, acknowledgment and recorded card appears here.', ''); ?></div>
                <?php } else { ?>
                <div class="trr-table-wrap">
                    <table class="table table-vcenter card-table trr-table">
                        <thead>
                            <tr>
                                <th scope="col">Completed</th>
                                <th scope="col">Course</th>
                                <th scope="col">Method</th>
                                <th scope="col" class="text-end">Score</th>
                                <th scope="col">Expires</th>
                                <th scope="col">Status</th>
                                <th scope="col">Record #</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trr['history'] as $trr_r) {
                                $trr_doc = $trr_r['course']['kind'] === 'document';
                                ?>
                            <tr class="<?= $trr_r['voided'] !== null ? 'is-voided' : '' ?>">
                                <td class="text-nowrap"><span class="trr-strike"><?= trr_h(trr_date($trr_r['completed_on'])) ?></span></td>
                                <td>
                                    <a class="fw-semibold trr-link-ink trr-strike" href="/agent/training_record.php?id=<?= (int) $trr_r['completion_id'] ?>"><?= trr_h($trr_r['course']['name']) ?></a>
                                    <span class="trr-sub"><?= $trr_doc ? 'Acknowledgment' . ($trr_r['revision_number'] !== null ? ' · Version ' . (int) $trr_r['revision_number'] : '') : trr_h($trr_course_sub($trr_r)) ?></span>
                                    <?php if ($trr_r['voided'] !== null) { ?>
                                    <span class="trr-void-note"><i class="fas fa-ban me-1" aria-hidden="true"></i>Voided <?= trr_h(trr_date($trr_r['voided']['on'])) ?><?= $trr_r['voided']['by'] !== null ? ' by ' . trr_h($trr_r['voided']['by']) : '' ?>: <?= trr_h($trr_r['voided']['reason']) ?></span>
                                    <?php } ?>
                                </td>
                                <td class="text-nowrap"><?= trr_grade($trr_r['grade'], $trr_r['strength_label']) ?></td>
                                <td class="text-end trr-num"><?= $trr_r['score_pct'] !== null ? trr_h($trr_r['score_pct']) . '%' : '<span class="trr-muted">—</span>' ?></td>
                                <td class="text-nowrap"><?= $trr_doc ? '<span class="trr-muted">—</span>' : ($trr_r['expires_on'] !== null ? trr_h(trr_date($trr_r['expires_on'])) : '<span class="trr-muted">Does not expire</span>') ?></td>
                                <td><?= $trr_doc && $trr_r['voided'] === null ? '<span class="trr-chip trr-chip--ok"><i class="fas fa-signature" aria-hidden="true"></i>Signed</span>' : trr_cert_chip($trr_r['status'], $trr_r['status_reason']) ?></td>
                                <td><?= $trr_record_link($trr_r) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>

            <?php if ($trr['achievements'] !== null) { ?>
            <!-- Achievements (Phase 3 table) -->
            <div class="tab-pane fade" id="trr-pane-ach" role="tabpanel" aria-labelledby="trr-tab-ach" tabindex="0">
                <?php
                // Phase 3 (P3 spec §7.9 [S]): K6's partial reads the awards through AwardRepository and
                // renders the stored bare Font Awesome name ("award") checked against Core\Icons, so each
                // badge shows its own icon and colour; the person is already scope-checked above.
                $tr_awards_contact_id = (int) $trr_p['id'];
                require __DIR__ . '/includes/training/awards_partial.php';
                ?>
            </div>
            <?php } ?>
        </div>
    </section>

    <div class="trr-grid trr-grid--7-5">
        <!-- Open assignments -->
        <section class="card trr-card" aria-labelledby="trr-open-title">
            <div class="card-body">
                <div class="trr-card__head">
                    <h2 class="trr-card__title" id="trr-open-title">Open assignments <span class="trr-count"><?= count($trr['open_assignments']) ?></span></h2>
                    <a class="trr-card__link" href="<?= trr_h(trr_url('training_assignments.php', ['contact_id' => $trr_p['id']])) ?>">View all assignments <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                </div>
                <?php if ($trr['open_assignments'] === []) { ?>
                <p class="trr-empty-line"><i class="fas fa-check-circle me-2" aria-hidden="true"></i>Nothing assigned right now.</p>
                <?php } else { ?>
                <div>
                    <?php foreach ($trr['open_assignments'] as $trr_a) {
                        $trr_ic = $trr_a['display_status'] === 'overdue' ? ['err', 'fas fa-lock'] : ($trr_a['display_status'] === 'due_soon' ? ['warn', 'far fa-calendar-alt'] : ['info', 'far fa-calendar']);
                        ?>
                    <div class="trr-open">
                        <span class="trr-open__icon trr-open__icon--<?= $trr_ic[0] ?>" aria-hidden="true"><i class="<?= $trr_ic[1] ?>"></i></span>
                        <div class="trr-open__main">
                            <div class="trr-open__title"><?= trr_h($trr_a['course']['name']) ?> <span class="trr-muted fw-normal">· <?= trr_h($trr_a['anchor_label']) ?></span></div>
                            <div class="trr-open__meta">
                                <?= $trr_assign_chip($trr_a) ?>
                                <span>Assigned <?= trr_h(trr_date($trr_a['created_on'], true)) ?><?= $trr_a['created_by_name'] !== null ? ' by ' . trr_h($trr_a['created_by_name']) : ' automatically' ?></span>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
        </section>

        <!-- Evidence strength legend -->
        <section class="card trr-card" aria-labelledby="trr-legend-title">
            <div class="card-body">
                <div class="trr-card__head">
                    <h2 class="trr-card__title" id="trr-legend-title">Evidence strength <span class="trr-muted fw-normal small ms-1">How each record was proven</span></h2>
                </div>
                <ul class="trr-legend-list">
                    <?php foreach ($trr['legend'] as $trr_g => $trr_l) { ?>
                    <li><span class="trr-grade"><?= trr_h($trr_g) ?></span><?= $trr_strength_bars($trr_g) ?><strong><?= trr_h($trr_l['label']) ?></strong> <span class="trr-muted"><?= trr_h($trr_l['detail']) ?></span></li>
                    <?php } ?>
                </ul>
            </div>
        </section>
    </div>

    <p class="trr-footnote">As of <?= trr_h(trr_date($trr['as_of'])) ?> · Generated <?= trr_h(trr_clock($trr['generated_at'])) ?> by <?= trr_h($trr_generated_by) ?> with <?= trr_h(APP_NAME) ?><?= $trr['ledger'] !== null ? ' · ledger ' . trr_h($trr['ledger']) : '' ?></p>
</div>

<?php
$trr_routes = [];
if ($trr_row_menu) {
    try {
        $trr_all_routes = \ITFlow\Training\Api\Router::routes();
    } catch (\Throwable $e) {
        $trr_all_routes = [];
    }
    foreach (['assignment_extend', 'assignment_waive', 'assignment_reset', 'assignment_retake', 'assignment_unwaive'] as $trr_r) {
        $trr_routes[$trr_r] = isset($trr_all_routes[$trr_r]);
    }
}
trr_json_block('tr-page-data', [
    'user_id' => $trr_ctx->userId,
    'level' => $trr_level,
    'today' => $trr['as_of'],
    'person' => ['contact_id' => $trr_p['id'], 'name' => $trr_p['name'], 'hire_date' => $trr_p['hire_date']],
    // What the Extend / Waive / Reset / Un-waive / History forms show about each assignment (row menu, level 2+).
    'assignments' => $trr_row_menu ? array_map(static fn(array $a): array => [
        'id' => $a['id'], 'status' => $a['status'], 'display_status' => $a['display_status'],
        'course' => ['id' => $a['course']['id'], 'name' => $a['course']['name']],
        'person' => ['contact_id' => $trr_p['id'], 'name' => $trr_p['name']], 'due_on' => $a['due_on'], 'original_due_on' => $a['original_due_on'],
        'anchor_label' => $a['anchor_label'], 'archived' => (bool) $trr_p['archived'], 'waived_until' => $a['waived_until'],
        'completion_id' => $a['completion_id'], 'completion_voided' => $a['completion_voided'],
    ], $trr['assignments']) : [],
    // Row actions appear only for routes that exist (same gate as the Assignments list).
    'routes' => $trr_routes === [] ? new \stdClass() : $trr_routes,
    'settings' => ['reissue_days' => \ITFlow\Training\Core\RecordsSettings::fromDb($mysqli)->reissueDays],
]);
?>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<?php if ($trr_ops_js && $trr_level >= 2) { ?>
<script src="/agent/js/training_ops_forms.js?v=<?= filemtime(__DIR__ . '/js/training_ops_forms.js') ?>" defer></script>
<?php } ?>
<script src="/agent/js/training_transcript.js?v=<?= filemtime(__DIR__ . '/js/training_transcript.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
