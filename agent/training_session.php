<?php

/*
 * Training › Records & sessions › Session (Phase 2 spec §5.2, S2): ?id=<session_id> | ?new=1.
 *
 * Level 1 views; level 2 creates, edits (while open), finalizes and cancels. A session records
 * a classroom or hands-on class: course, date, trainer (a trainer or an outside instructor),
 * department, the attendance list (present/partial/absent, practical marks, proof) and the
 * signed sheet. Finalizing is attested and freezes the session (digest), then issues records
 * to the people who earned them. The page frame is server-rendered; agent/js/training_session.js
 * builds the form with DOM nodes and talks to session_get / session_save / session_finalize /
 * session_cancel. session_get checks people scope first: an unknown or out-of-scope id is the
 * not-found state, never a 403.
 */

$page_extra_css = ['/css/itflow_training.css', '/css/itflow_training_ops.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);
require_once __DIR__ . '/includes/training_ops/ops.php';

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_level = (int) lookupUserPermission('module_training');
$tr_id = tro_get_id('id');
$tr_new = $tr_id === null && isset($_GET['new']);
$tr_scope = tro_scope($mysqli, $tr_ctx);

$tr_session = $tr_id !== null ? tro_action($mysqli, 'session_get', ['session_id' => $tr_id]) : null;
$tr_s = ($tr_session !== null && $tr_session['state'] === 'ok' && is_array($tr_session['data'])) ? $tr_session['data'] : null;

$tr_data = [
    'level' => $tr_level,
    'user_id' => $tr_ctx->userId,
    'today' => tro_today(),
    'session_id' => $tr_id,
    'is_new' => $tr_new,
    'session' => $tr_session,
    'scope' => $tr_scope['state'],
    'courses' => tro_course_cards($mysqli),
    'departments' => tro_departments($mysqli, $tr_scope, false),
    'trainers' => tro_action($mysqli, 'trainer_list'),
    'settings' => tro_records_settings($mysqli),
    'routes' => [
        'session_save' => tro_has_route('session_save'),
        'session_finalize' => tro_has_route('session_finalize'),
        'session_cancel' => tro_has_route('session_cancel'),
        'people_roster' => tro_has_route('people_roster'),
        'evidence_upload' => is_file(__DIR__ . '/training_evidence_upload.php'),
    ],
];

if ($tr_s !== null) {
    $tr_title = (string) ($tr_s['course']['name'] ?? 'Session');
} else {
    $tr_title = $tr_new ? 'New session' : 'Session';
}
?>

<div class="tro-page" id="tro-session-page">
    <?php render_page_header($tr_title, $tr_new ? 'Record a classroom or hands-on class: who taught it, who attended, and the signed sheet.' : null, '<div class="d-flex flex-wrap gap-2 align-items-center" id="tro-ses-head-actions"></div>', [
        ['label' => 'Training', 'url' => '/agent/training.php'],
        ['label' => 'Records & sessions', 'url' => '/agent/training_records.php?tab=sessions'],
        ['label' => $tr_new ? 'New session' : 'Session'],
    ]); ?>

    <div id="tro-ses-alerts"></div>

    <div id="tro-session">
        <?php if ($tr_id === null && !$tr_new) { ?>
        <div class="tro-card"><?php render_empty_state('fas fa-chalkboard-teacher', 'No session chosen', 'Open a session from Records & sessions, or start a new one.'); ?></div>
        <?php } else { ?>
        <div class="tro-session" aria-busy="true">
            <div class="tro-card tro-card__body">
                <span class="tro-skel tro-skel--w60 mb-3"></span>
                <span class="tro-skel tro-skel--w80 mb-3"></span>
                <span class="tro-skel tro-skel--w40"></span>
            </div>
            <div class="tro-card tro-card__body">
                <span class="tro-skel tro-skel--w80 mb-3"></span>
                <span class="tro-skel tro-skel--w40"></span>
            </div>
        </div>
        <?php } ?>
    </div>
</div>

<?php
tro_page_data($tr_data);
tro_scripts('/agent/js/training_session.js');
require_once "../includes/footer.php";
