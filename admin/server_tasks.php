<?php
require_once "includes/inc_all_admin.php";

use ITFlow\Ops\ServerStatus;

$status_service = new ServerStatus($mysqli, realpath(__DIR__ . '/..'), function_exists('getRedisClient') ? getRedisClient() : null);
$groups = $status_service->run();
$sum = ServerStatus::summarize($groups);
$tasks = $status_service->rootTasks();
$badge = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
$overall = $sum['fail'] ? ['Needs attention', 'danger'] : ($sum['warn'] ? ['Mostly healthy', 'warning'] : ['All healthy', 'success']);
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-server me-2"></i>Server status &amp; tasks</h3>
        <span class="badge bg-<?= $overall[1] ?> fs-6"><?= $overall[0] ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-2">Everything that used to need a terminal to check, in one place. <?= (int) $sum['ok'] ?> OK, <?= (int) $sum['warn'] ?> to review, <?= (int) $sum['fail'] ?> failing. Reload the page to check again.</p>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="/admin/update.php"><i class="fas fa-download me-1"></i>Update</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/backup.php"><i class="fas fa-cloud-upload-alt me-1"></i>Backups</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/cron.php"><i class="fas fa-clock me-1"></i>Scheduled jobs</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/settings_redis.php"><i class="fas fa-bolt me-1"></i>Redis</a>
            <a class="btn btn-sm btn-outline-secondary" href="/admin/settings_mcp.php"><i class="fas fa-plug me-1"></i>Remote MCP</a>
        </div>
    </div>
</div>

<?php foreach ($groups as $g) { ?>
<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><?= nullable_htmlentities($g['title']) ?></h4></div>
    <div class="card-body pt-2">
        <ul class="list-group list-group-flush">
        <?php foreach ($g['checks'] as $c) { ?>
            <li class="list-group-item px-0 d-flex gap-3">
                <span class="badge bg-<?= $badge[$c['status']] ?? 'secondary' ?> align-self-start" style="min-width:3.2rem"><?= nullable_htmlentities(strtoupper($c['status'])) ?></span>
                <div><strong><?= nullable_htmlentities($c['label']) ?></strong><div class="text-muted small"><?= nullable_htmlentities($c['detail']) ?></div></div>
            </li>
        <?php } ?>
        </ul>
    </div>
</div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-terminal me-2"></i>Tasks that need the server itself</h4></div>
    <div class="card-body">
        <p class="text-muted">These change the operating system, other users' files, or replace data, so a web page is the wrong place to run them. Each has the exact command for this install, ready to copy into a terminal on the server.</p>
        <?php foreach ($tasks as $i => $t) { ?>
            <details class="mb-3"<?= $i === 0 ? ' open' : '' ?>>
                <summary class="fw-semibold"><?= nullable_htmlentities($t['title']) ?></summary>
                <p class="text-muted small mt-2 mb-2"><?= nullable_htmlentities($t['why']) ?></p>
                <?php if ($t['caution'] !== '') { ?><div class="alert alert-danger py-2 small"><?= nullable_htmlentities($t['caution']) ?></div><?php } ?>
                <div class="position-relative">
                    <pre class="border rounded p-3 small mb-0 pe-5" id="task-cmd-<?= $i ?>" style="overflow-x:auto;white-space:pre-wrap"><?= nullable_htmlentities($t['command']) ?></pre>
                    <button type="button" class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-2" onclick="var el=document.getElementById('task-cmd-<?= $i ?>');var b=this;navigator.clipboard.writeText(el.textContent).then(function(){b.textContent='Copied'},function(){var r=document.createRange();r.selectNodeContents(el);var s=getSelection();s.removeAllRanges();s.addRange(r);b.textContent='Selected'});">Copy</button>
                </div>
            </details>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";
