<?php

// Project Gantt / Timeline view
// Mirrors project_details.php's include + permission pattern.

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=" . intval($_GET['client_id']) . "&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
}

enforceUserPermission('module_support');

$project_permission_snippet = '';
if (!empty($client_access_string)) {
    $project_permission_snippet = "AND (project_client_id IN ($client_access_string) OR project_client_id = 0)";
}

$project_id = intval($_GET['project_id'] ?? 0);

$sql_project = mysqli_query(
    $mysqli,
    "SELECT * FROM projects
     WHERE project_id = $project_id
     $project_permission_snippet
     LIMIT 1"
);

// Fallback title vars so footer.php always has something to render
$tab_title = "Project";
$page_title = "Gantt";

if (mysqli_num_rows($sql_project) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='projects.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit;
}

$row = mysqli_fetch_assoc($sql_project);

$project_id = intval($row['project_id']);
$project_prefix = nullable_htmlentities($row['project_prefix']);
$project_number = intval($row['project_number']);
$project_name = nullable_htmlentities($row['project_name']);
$project_completed_at = nullable_htmlentities($row['project_completed_at']);

$tab_title = "{$row['project_prefix']}{$row['project_number']}";
$page_title = $row['project_name'];

?>

<link rel="stylesheet" href="/css/frappe-gantt.css">

<!-- Breadcrumbs-->
<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item"><a href="projects.php">Projects</a></li>
    <li class="breadcrumb-item"><a href="project_details.php?<?= $client_url ?>project_id=<?= $project_id ?>"><?= "$project_prefix$project_number" ?></a></li>
    <li class="breadcrumb-item active">Gantt</li>
</ol>

<!-- Project view tabs -->
<ul class="nav nav-pills mb-3 d-print-none">
    <li class="nav-item">
        <a class="nav-link" href="project_details.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-info-circle me-2"></i>Details</a>
    </li>
    <li class="nav-item">
        <a class="nav-link active" href="project_gantt.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-stream me-2"></i>Gantt</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="project_kanban.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-columns me-2"></i>Kanban</a>
    </li>
</ul>

<div class="card">
    <div class="card-header py-2">
        <h5 class="card-title mt-2 mb-2"><i class="fas fa-fw fa-stream me-2"></i><?= $project_name ?> &mdash; Timeline</h5>
        <div class="card-tools">
            <div class="btn-group btn-group-sm gantt-toolbar" role="group" aria-label="Gantt view mode">
                <button type="button" class="btn btn-outline-secondary" data-gantt-view="Day">Day</button>
                <button type="button" class="btn btn-outline-secondary active" data-gantt-view="Week">Week</button>
                <button type="button" class="btn btn-outline-secondary" data-gantt-view="Month">Month</button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div id="project-gantt"></div>
        <div id="gantt-empty" class="text-center text-secondary py-5" style="display:none;">
            <i class="fas fa-fw fa-stream fa-2x mb-3 d-block"></i>
            No scheduled tasks yet. Add a <strong>start</strong> and <strong>due</strong> date to a task to see it on the timeline.
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>

<script src="/js/frappe-gantt.js?v=<?= file_exists(__DIR__ . '/../js/frappe-gantt.js') ? filemtime(__DIR__ . '/../js/frappe-gantt.js') : time() ?>"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
    var PROJECT_ID = <?= $project_id ?>;
    var target = document.getElementById('project-gantt');
    var empty = document.getElementById('gantt-empty');
    var gantt = null;
    var currentView = 'Week';

    function persistSchedule(task) {
        var body = new URLSearchParams();
        body.append('update_task_schedule', '1');
        body.append('csrf_token', CSRF);
        body.append('task_id', task.id);
        body.append('start', task.start);
        body.append('end', task.end);
        body.append('progress', task.progress);
        fetch('ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); })
          .then(function () { if (window.toastr) { toastr.success('Task schedule updated'); } })
          .catch(function (err) { if (window.toastr) { toastr.error('Could not save schedule'); } console.error(err); });
    }

    fetch('ajax.php?project_gantt=' + PROJECT_ID)
        .then(function (r) { return r.json(); })
        .then(function (tasks) {
            if (!tasks || !tasks.length) {
                target.style.display = 'none';
                empty.style.display = '';
                return;
            }
            gantt = new Gantt(target, tasks, {
                view_mode: currentView,
                on_date_change: function (task) { persistSchedule(task); },
                on_progress_change: function (task) { persistSchedule(task); }
            });
        })
        .catch(function (err) {
            console.error(err);
            empty.textContent = 'Failed to load Gantt data.';
            empty.style.display = '';
        });

    document.querySelectorAll('[data-gantt-view]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            currentView = btn.getAttribute('data-gantt-view');
            document.querySelectorAll('[data-gantt-view]').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            if (gantt) { gantt.change_view_mode(currentView); }
        });
    });
})();
</script>
