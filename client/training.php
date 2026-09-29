<?php
/*
 * Client Portal
 * Training: every contact sees their own required training; a department's Primary/Technical contacts see the
 * whole department (as do contacts an admin made a department Manager), and anyone with direct reports (contacts.contact_manager_id, any depth) sees those people.
 * Read-only. Numbers come from the same compliance engine as Admin > Training > Reports, limited to this
 * contact's own department (fail-closed: a contact is only ever shown people from contact_client_id).
 */

header("Content-Security-Policy: default-src 'self'");

ob_start();
require_once "includes/inc_all.php";

if (intval($config_module_enable_training ?? 0) !== 1 || empty($config_training_schema_ready)) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

require_once __DIR__ . '/includes/training_common.php';
?>

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-graduation-cap me-2"></i>Training</h3>
    </div>
    <div class="col-auto">
        <form action="training_start.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-play me-2"></i>Start training</button>
        </form>
        <?php if ($tp_show_team) { ?>
        <a href="training_manage.php" class="btn btn-outline-primary"><i class="fas fa-chart-bar me-2"></i>Manage training</a>
        <?php } ?>
        <a href="ticket_add.php" class="btn btn-outline-primary"><i class="fas fa-life-ring me-2"></i>Report a training problem</a>
        <a href="service_catalog.php" class="btn btn-outline-secondary"><i class="fas fa-concierge-bell me-2"></i>Request something</a>
    </div>
</div>

<?php if ($tp_me > 0) { ?>
<div class="card card-outline card-primary mb-4">
    <div class="card-header"><h5 class="mb-0">My training</h5><div class="text-muted small ms-auto">Select a course to open it in the training module. You are already signed in; you only enter your training PIN when you sign.</div></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light"><tr><th>Course</th><th>Status</th><th>Due / expires</th></tr></thead>
                <tbody>
                <?php if (!$tp_mine) { ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No required training right now.</td></tr>
                <?php }
                usort($tp_mine, static fn($a, $b) => [$tp_urgency[$a['status']] ?? 9, $a['course']['name']] <=> [$tp_urgency[$b['status']] ?? 9, $b['course']['name']]);
                foreach ($tp_mine as $p) {
                    [$cls, $txt] = $tp_badge($p); ?>
                    <tr>
                        <td><?php if (intval($p['course']['id']) > 0) { ?><form action="training_start.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="course" value="<?= intval($p['course']['id']) ?>"><button type="submit" class="btn btn-link p-0 text-start"><?= nullable_htmlentities($p['course']['name']) ?><i class="fas fa-external-link-alt fa-xs ms-2 text-muted"></i></button></form><?php } else { echo nullable_htmlentities($p['course']['name']); } ?></td>
                        <td><span class="badge bg-<?= $cls ?>"><?= nullable_htmlentities($txt) ?></span></td>
                        <td><?= nullable_htmlentities($tp_when($p)) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php } ?>

<?php require_once "includes/footer.php";
