<?php
/*
 * HTML of the task pop-up's panels (checklist, subtasks, comments, files), shared by agent/modals/project/task_detail.php (first paint)
 * and agent/project_task_ajax.php (re-render of one panel after a change), so there is exactly one place that escapes and lays them out.
 * Needs includes/project_tasks.php. Every value that reaches the page goes through nullable_htmlentities() or intval().
 *
 * $caps = ['write' => bool (module_support >= 2), 'user_id' => int, 'is_admin' => bool, 'can_delete_file' => bool]
 */

function projectTaskPanelNames(): array
{
    return ['checklist', 'subtasks', 'comments', 'files'];
}

/** Which panels exist for this database: checklist/subtasks/comments from 2.6.208, files from 2.6.209. */
function projectTaskPanelsAvailable($mysqli): array
{
    $out = [];
    if (projectTaskPhase2Ready($mysqli)) {
        $out = ['checklist', 'subtasks', 'comments'];
    }
    if (projectTaskPhase3Ready($mysqli)) {
        $out[] = 'files';
    }

    return $out;
}

function projectTaskPanelTime(?string $dt): string
{
    $t = $dt ? strtotime($dt) : false;

    return $t ? date('M j, Y g:i a', $t) : '';
}

function projectTaskPanelHumanSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }

    return $bytes . ' B';
}

/** Inner HTML of one panel. */
function projectTaskRenderPanel($mysqli, string $panel, array $ctx, array $caps): string
{
    $task_id = intval($ctx['row']['task_id']);
    $write = !empty($caps['write']);
    ob_start();
    switch ($panel) {
        case 'checklist':
            $items = projectTaskChecklist($mysqli, $task_id);
            $done = count(array_filter($items, fn($i) => $i['item_done']));
            $total = count($items);
            ?>
            <div class="pt-head"><h6 class="pt-title"><i class="far fa-fw fa-check-square me-1"></i>Checklist</h6>
                <?php if ($total) { ?><span class="badge text-bg-secondary" data-pt-count><?= $done ?>/<?= $total ?></span><?php } ?></div>
            <?php if ($total) { ?>
                <div class="progress pt-progress" role="progressbar" aria-label="Checklist progress" aria-valuenow="<?= $done ?>" aria-valuemin="0" aria-valuemax="<?= $total ?>"><div class="progress-bar bg-success" style="width: <?= round($done * 100 / $total) ?>%"></div></div>
            <?php } ?>
            <ul class="pt-list list-unstyled mb-2">
                <?php foreach ($items as $idx => $it) { ?>
                    <li class="pt-item<?= $it['item_done'] ? ' is-done' : '' ?>" data-item-id="<?= $it['item_id'] ?>">
                        <input type="checkbox" class="form-check-input pt-check" data-pt-act="checklist_toggle" <?= $it['item_done'] ? 'checked' : '' ?> <?= $write ? '' : 'disabled' ?> aria-label="Done: <?= nullable_htmlentities($it['item_text']) ?>">
                        <span class="pt-text"><?= nullable_htmlentities($it['item_text']) ?></span>
                        <?php if ($write) { ?>
                            <span class="pt-tools">
                                <button type="button" class="btn btn-sm btn-link" data-pt-act="checklist_up" aria-label="Move up" <?= $idx === 0 ? 'disabled' : '' ?>><i class="fas fa-fw fa-arrow-up"></i></button>
                                <button type="button" class="btn btn-sm btn-link" data-pt-act="checklist_down" aria-label="Move down" <?= $idx === $total - 1 ? 'disabled' : '' ?>><i class="fas fa-fw fa-arrow-down"></i></button>
                                <button type="button" class="btn btn-sm btn-link" data-pt-act="checklist_edit" aria-label="Edit item"><i class="fas fa-fw fa-pen"></i></button>
                                <button type="button" class="btn btn-sm btn-link text-danger" data-pt-act="checklist_delete" aria-label="Delete item"><i class="fas fa-fw fa-trash"></i></button>
                            </span>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <?php if ($write) { ?>
                <div class="input-group pt-add"><input type="text" class="form-control" maxlength="255" placeholder="Add an item" data-pt-input="checklist_add" aria-label="New checklist item"><button type="button" class="btn btn-outline-primary" data-pt-act="checklist_add"><i class="fas fa-plus me-1"></i>Add</button></div>
            <?php }
            break;

        case 'subtasks':
            $subs = projectTaskSubtasks($mysqli, $task_id);
            $done = count(array_filter($subs, fn($s) => $s['done']));
            $is_child = $ctx['row']['task_parent_id'] !== null;
            ?>
            <div class="pt-head"><h6 class="pt-title"><i class="fas fa-fw fa-sitemap me-1"></i>Subtasks</h6>
                <?php if ($subs) { ?><span class="badge text-bg-secondary" data-pt-count><?= $done ?>/<?= count($subs) ?></span><?php } ?></div>
            <?php if ($is_child) {
                $parent = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT task_name FROM tasks WHERE task_id = ' . intval($ctx['row']['task_parent_id']) . ' LIMIT 1')); ?>
                <p class="small text-secondary mb-2">This is a subtask of <strong><?= nullable_htmlentities($parent['task_name'] ?? 'another task') ?></strong>.
                    <?php if ($write) { ?><button type="button" class="btn btn-sm btn-link p-0 align-baseline" data-pt-act="subtask_detach_self">Make it a regular task</button><?php } ?></p>
            <?php } else { ?>
                <ul class="pt-list list-unstyled mb-2">
                    <?php foreach ($subs as $s) { ?>
                        <li class="pt-item<?= $s['done'] ? ' is-done' : '' ?>" data-subtask-id="<?= $s['task_id'] ?>">
                            <input type="checkbox" class="form-check-input pt-check" data-pt-act="subtask_toggle" <?= $s['done'] ? 'checked' : '' ?> <?= $write ? '' : 'disabled' ?> aria-label="Done: <?= nullable_htmlentities($s['task_name']) ?>">
                            <span class="pt-text"><?= nullable_htmlentities($s['task_name']) ?></span>
                            <?php if ($write) { ?>
                                <span class="pt-tools"><button type="button" class="btn btn-sm btn-link" data-pt-act="subtask_detach" aria-label="Make this a regular task" title="Make this a regular task"><i class="fas fa-fw fa-unlink"></i></button></span>
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
                <?php if ($write) { ?>
                    <div class="input-group pt-add"><input type="text" class="form-control" maxlength="255" placeholder="Add a subtask" data-pt-input="subtask_add" aria-label="New subtask"><button type="button" class="btn btn-outline-primary" data-pt-act="subtask_add"><i class="fas fa-plus me-1"></i>Add</button></div>
                <?php }
            }
            break;

        case 'comments':
            $comments = projectTaskComments($mysqli, $task_id);
            ?>
            <div class="pt-head"><h6 class="pt-title"><i class="far fa-fw fa-comments me-1"></i>Comments</h6>
                <?php if ($comments) { ?><span class="badge text-bg-secondary" data-pt-count><?= count($comments) ?></span><?php } ?></div>
            <div class="pt-comments mb-2">
                <?php if (!$comments) { ?><p class="text-secondary small mb-2">No comments yet.</p><?php } ?>
                <?php foreach ($comments as $c) {
                    $own = $c['comment_user_id'] !== null && $c['comment_user_id'] === intval($caps['user_id']);
                    $can_delete = $write && ($own || !empty($caps['is_admin'])); ?>
                    <div class="pt-comment" data-comment-id="<?= $c['comment_id'] ?>">
                        <div class="pt-comment-meta"><span class="pt-comment-who"><strong><?= nullable_htmlentities($c['user_name']) ?></strong>
                            <span class="text-secondary"><?= nullable_htmlentities(projectTaskPanelTime($c['comment_created_at'])) ?><?= $c['comment_updated_at'] ? ' (edited)' : '' ?></span></span>
                            <?php if ($write && ($own || $can_delete)) { ?>
                                <span class="pt-tools">
                                    <?php if ($own) { ?><button type="button" class="btn btn-sm btn-link" data-pt-act="comment_edit" aria-label="Edit comment"><i class="fas fa-fw fa-pen"></i></button><?php } ?>
                                    <?php if ($can_delete) { ?><button type="button" class="btn btn-sm btn-link text-danger" data-pt-act="comment_delete" aria-label="Delete comment"><i class="fas fa-fw fa-trash"></i></button><?php } ?>
                                </span>
                            <?php } ?>
                        </div>
                        <div class="pt-comment-body"><?= nl2br(nullable_htmlentities($c['comment_body'])) ?></div>
                        <?php /* raw text for the inline editor (escaped; read back with .value, never as HTML) */ ?>
                        <textarea class="d-none" data-pt-raw><?= nullable_htmlentities($c['comment_body']) ?></textarea>
                    </div>
                <?php } ?>
            </div>
            <?php if ($write) { ?>
                <div class="pt-add"><textarea class="form-control mb-2" rows="2" maxlength="20000" placeholder="Write a comment" data-pt-input="comment_add" aria-label="New comment"></textarea>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-pt-act="comment_add"><i class="far fa-fw fa-paper-plane me-1"></i>Comment</button></div>
            <?php }
            break;

        case 'files':
            $files = projectTaskAttachments($mysqli, $task_id);
            ?>
            <div class="pt-head"><h6 class="pt-title"><i class="fas fa-fw fa-paperclip me-1"></i>Files</h6>
                <?php if ($files) { ?><span class="badge text-bg-secondary" data-pt-count><?= count($files) ?></span><?php } ?></div>
            <ul class="pt-list list-unstyled mb-2">
                <?php if (!$files) { ?><li class="text-secondary small">No files attached.</li><?php } ?>
                <?php foreach ($files as $f) { ?>
                    <li class="pt-item" data-attachment-id="<?= $f['attachment_id'] ?>">
                        <i class="far fa-fw fa-file text-secondary"></i>
                        <a class="pt-text" href="/agent/project_task_attachment.php?task_id=<?= $task_id ?>&amp;id=<?= $f['attachment_id'] ?>" download><?= nullable_htmlentities($f['attachment_name']) ?></a>
                        <span class="text-secondary small"><?= nullable_htmlentities(projectTaskPanelHumanSize($f['attachment_size'])) ?><?= $f['user_name'] ? ' &middot; ' . nullable_htmlentities($f['user_name']) : '' ?></span>
                        <?php if ($write && (!empty($caps['can_delete_file']) || (!empty($caps['user_id']) && $f['attachment_uploaded_by'] === intval($caps['user_id'])))) { ?>
                            <span class="pt-tools"><button type="button" class="btn btn-sm btn-link text-danger" data-pt-act="file_delete" aria-label="Delete file"><i class="fas fa-fw fa-trash"></i></button></span>
                        <?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <?php if ($write) { ?>
                <label class="btn btn-outline-primary btn-sm mb-0"><i class="fas fa-fw fa-upload me-1"></i>Attach files
                    <input type="file" class="d-none" multiple data-pt-upload></label>
                <span class="small text-secondary ms-2">Up to <?= intval(PROJECT_TASK_ATTACHMENT_MAX_BYTES / 1048576) ?> MB each. Scripts, web pages and installers are not accepted.</span>
            <?php }
            break;
    }

    return (string) ob_get_clean();
}

/** The whole panel block for the pop-up (wrapper sections plus first-paint content). Empty string when no panel applies. */
function projectTaskRenderPanels($mysqli, array $ctx, array $caps): string
{
    $panels = projectTaskPanelsAvailable($mysqli);
    if (!$panels || !projectTaskIsProjectTask($ctx)) {
        return '';
    }
    $task_id = intval($ctx['row']['task_id']);
    ob_start();
    ?>
    <style>
        .task-panels { margin-top: 1rem; border-top: 1px solid var(--if-border, #dee2e6); padding-top: .5rem; }
        .task-panels .pt-section { padding: .75rem 0; border-bottom: 1px solid var(--if-border, #dee2e6); }
        .task-panels .pt-section:last-child { border-bottom: 0; }
        .task-panels .pt-head { display: flex; align-items: center; gap: .5rem; margin-bottom: .4rem; }
        .task-panels .pt-title { margin: 0; font-weight: 600; }
        .task-panels .pt-progress { height: 6px; margin-bottom: .5rem; }
        .task-panels .pt-item { display: flex; align-items: center; gap: .5rem; padding: .25rem 0; min-height: 2.5rem; }
        .task-panels .pt-check { flex: 0 0 auto; width: 1.25rem; height: 1.25rem; margin: 0; }
        .task-panels .pt-text { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
        .task-panels .pt-item.is-done .pt-text { text-decoration: line-through; opacity: .65; }
        .task-panels .pt-tools { display: inline-flex; flex: 0 0 auto; }
        .task-panels .pt-tools .btn { min-width: 2.25rem; min-height: 2.25rem; padding: .25rem; color: var(--bs-secondary-color, #6c757d); }
        .task-panels .pt-tools .btn.text-danger { color: var(--bs-danger, #d63939); }
        .task-panels .pt-comment { padding: .5rem .65rem; margin-bottom: .5rem; border: 1px solid var(--if-border, #dee2e6); border-radius: .4rem; background: var(--if-surface, transparent); }
        .task-panels .pt-comment-meta { display: flex; align-items: flex-start; gap: .5rem; font-size: .85rem; }
        .task-panels .pt-comment-who { flex: 1 1 auto; min-width: 0; display: flex; flex-wrap: wrap; column-gap: .5rem; align-self: center; }
        .task-panels .pt-comment-body { margin-top: .25rem; overflow-wrap: anywhere; }
        .task-panels .pt-busy { opacity: .6; pointer-events: none; }
        .task-panels .pt-error { color: var(--bs-danger, #d63939); font-size: .85rem; margin: .25rem 0 0; }
        @media (max-width: 575.98px) { .task-panels .pt-tools .btn { min-width: 2.75rem; min-height: 2.75rem; } .task-panels .pt-check { width: 1.75rem; height: 1.75rem; } .task-panels .pt-item { min-height: 2.75rem; } }
    </style>
    <div class="task-panels" id="task-panels" data-task-id="<?= $task_id ?>" data-endpoint="/agent/project_task_ajax.php">
        <?php foreach ($panels as $name) { ?>
            <section class="pt-section" data-pt-panel="<?= $name ?>"><?= projectTaskRenderPanel($mysqli, $name, $ctx, $caps) ?></section>
        <?php } ?>
    </div>
    <script src="/agent/js/project_task_panel.js?v=<?= file_exists(__DIR__ . '/../agent/js/project_task_panel.js') ? filemtime(__DIR__ . '/../agent/js/project_task_panel.js') : time() ?>"></script>
    <?php

    return (string) ob_get_clean();
}
