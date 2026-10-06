<?php
/*
 * Client Portal - employee home sections (included by index.php).
 *
 * Everything is read-only and scoped to the logged-in contact ($session_contact_id) and their own department
 * ($session_client_id) by src/Portal/EmployeeHome.php. $eh_skip lists sections the page already shows another way
 * (department administrators keep the original "Recent tickets" and "Assigned assets" cards).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/EmployeeHome.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';

$eh = new \ITFlow\Portal\EmployeeHome($mysqli);
$eh_cid = intval($session_contact_id);
$eh_client = intval($session_client_id);
$eh_on = \ITFlow\Portal\EmployeeHome::parseSections($config_portal_home_sections ?? null);
$eh_skip = $eh_skip ?? [];
$eh_show = static fn(string $k): bool => in_array($k, $GLOBALS['eh_on'], true) && !in_array($k, $GLOBALS['eh_skip'], true);

// Request something: search + Popular + Recent
if ($eh_show('catalog')) {
    $eh_catalog = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
    $eh_popular = $eh_catalog->trending(5, 30);
    $eh_recent = $eh_catalog->recentForContact($eh_cid, $eh_client, 5);
    ?>
<div class="card portal-card mb-4">
    <div class="card-body">
        <h3 class="h5 mb-3"><span class="portal-card-chip"><i class="fas fa-concierge-bell" aria-hidden="true"></i></span>What do you need?</h3>
        <form action="service_catalog.php" method="get" class="portal-filterbar mb-2">
            <div class="portal-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" class="form-control form-control-lg" name="q" maxlength="100" placeholder="Request something: new laptop, software, access..." aria-label="Search the service catalog" autocomplete="off">
            </div>
        </form>
        <?php foreach ([['Popular', 'fa-fire', $eh_popular], ['Recent', 'fa-history', $eh_recent]] as [$eh_shelf_title, $eh_shelf_icon, $eh_shelf_rows]) {
            if (!$eh_shelf_rows) { continue; } ?>
            <div class="mt-2">
                <span class="small text-secondary me-2"><i class="fas <?= $eh_shelf_icon ?> me-1" aria-hidden="true"></i><?= $eh_shelf_title ?></span>
                <?php foreach ($eh_shelf_rows as $eh_item) { ?>
                    <a href="ticket_add.php?catalog_item_id=<?= intval($eh_item['catalog_item_id']) ?>" class="portal-badge portal-badge--muted me-1"><?= nullable_htmlentities($eh_item['name']) ?></a>
                <?php } ?>
            </div>
        <?php } ?>
        <div class="mt-3"><a href="service_catalog.php" class="btn btn-sm btn-outline-primary">Browse all requests</a> <a href="ticket_add.php" class="btn btn-sm btn-link text-secondary">Something else</a></div>
    </div>
</div>
<?php } ?>

<?php
$eh_approvals = $eh_show('approvals') ? $eh->waitingOnMe($eh_cid, $eh_client) : [];
$eh_requests = $eh_show('requests') ? $eh->myOpenRequests($eh_cid, $eh_client, 5) : [];
$eh_devices = $eh_show('devices') ? $eh->myDevices($eh_cid, $eh_client) : [];
?>

<div class="row g-3 mb-4">

    <?php if ($eh_show('requests')) { ?>
    <div class="col-lg-7 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-ticket-alt" aria-hidden="true"></i></span>My open requests</h3>
                <a href="tickets.php" class="btn btn-sm btn-outline-primary">View all</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>#</th><th>Subject</th><th>Status</th><th>Last update</th></tr></thead>
                        <tbody>
                        <?php if (!$eh_requests) { ?>
                            <tr><td colspan="4"><div class="portal-empty"><i class="far fa-life-ring" aria-hidden="true"></i>You have no open requests. <a href="service_catalog.php">Request something</a>.</div></td></tr>
                        <?php }
                        foreach ($eh_requests as $r) { ?>
                            <tr>
                                <td class="text-nowrap"><a href="ticket.php?id=<?= intval($r['ticket_id']) ?>">#<?= nullable_htmlentities($r['ticket_prefix']) . intval($r['ticket_number']) ?></a></td>
                                <td><a href="ticket.php?id=<?= intval($r['ticket_id']) ?>"><?= nullable_htmlentities($r['ticket_subject']) ?></a></td>
                                <td><span class="badge text-bg-secondary"><?= nullable_htmlentities($r['ticket_status_name']) ?></span></td>
                                <td class="text-secondary"><?= $r['last_update'] ? nullable_htmlentities(timeAgo($r['last_update'])) : '-' ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if ($eh_show('devices')) { ?>
    <div class="col-lg-5 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-laptop" aria-hidden="true"></i></span>My devices</h3>
                <a href="assets.php" class="btn btn-sm btn-outline-primary">Details</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (!$eh_devices) { ?>
                        <li class="list-group-item"><div class="portal-empty"><i class="fas fa-laptop" aria-hidden="true"></i>No devices are assigned to you.</div></li>
                    <?php }
                    foreach ($eh_devices as $d) { ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-fw fa-desktop text-secondary me-2" aria-hidden="true"></i><?= nullable_htmlentities($d['asset_name']) ?> <span class="text-secondary">(<?= nullable_htmlentities($d['asset_type']) ?>)</span></span>
                            <a class="btn btn-sm btn-outline-secondary" href="ticket_add.php?asset_id=<?= intval($d['asset_id']) ?>">Report a problem</a>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if ($eh_show('approvals') && ($eh_approvals || $eh->isManager($eh_cid, $eh_client))) { ?>
    <div class="col-lg-6 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-user-check" aria-hidden="true"></i></span>Waiting on me</h3>
                <a href="my_approvals.php" class="btn btn-sm btn-outline-primary">Open</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (!$eh_approvals) { ?>
                        <li class="list-group-item"><div class="portal-empty"><i class="fas fa-check-circle" aria-hidden="true"></i>Nothing is waiting for your approval.</div></li>
                    <?php }
                    foreach (array_slice($eh_approvals, 0, 5) as $a) { ?>
                        <li class="list-group-item">
                            <a href="my_approvals.php"><?= nullable_htmlentities($a['item_name']) ?></a>
                            <span class="text-secondary small">from <?= nullable_htmlentities($a['requester_name']) ?>, <?= nullable_htmlentities(timeAgo($a['created_at'])) ?></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php
    if ($eh_show('onboarding')) {
        $eh_check = $eh->myChecklist($eh_cid, $eh_client);
        if ($eh_check) { ?>
    <div class="col-lg-6 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-tasks" aria-hidden="true"></i></span>My <?= $eh_check['run']['type'] === 'offboarding' ? 'offboarding' : 'onboarding' ?> checklist</h3>
                <span class="portal-badge portal-badge--muted"><?= intval($eh_check['done']) ?> of <?= intval($eh_check['total']) ?> done</span>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($eh_check['tasks'] as $t) {
                        $eh_done = in_array($t['status'], ['completed', 'skipped'], true);
                        $eh_label = $eh_done ? 'Done' : ($t['task_type'] === 'approval' && $t['approval_status'] === 'pending' ? 'Awaiting approval' : ($t['status'] === 'blocked' ? 'Not started' : 'To do'));
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-fw <?= $eh_done ? 'fa-check-circle text-success' : 'fa-circle text-secondary' ?> me-2" aria-hidden="true"></i><?= nullable_htmlentities($t['title']) ?>
                                <?php if ($t['default_owner']) { ?><span class="text-secondary small">(<?= nullable_htmlentities($t['default_owner']) ?>)</span><?php } ?>
                            </span>
                            <span class="small text-secondary text-nowrap"><?= !$eh_done && $t['due_at'] ? 'due ' . nullable_htmlentities(substr($t['due_at'], 0, 10)) . ' &middot; ' : '' ?><?= $eh_label ?></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
    <?php } } ?>

    <?php
    if ($eh_show('training') && intval($config_module_enable_training ?? 0) === 1 && !empty($config_training_schema_ready)) {
        $eh_due = [];
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
            require_once __DIR__ . '/training_common.php'; // same compliance engine as training.php, own rows only ($tp_mine)
            foreach ($tp_mine as $p) {
                if ($tp_needs_attention($p)) { $eh_due[] = $p; }
            }
            usort($eh_due, static fn($a, $b) => [$tp_urgency[$a['status']] ?? 9, $a['course']['name']] <=> [$tp_urgency[$b['status']] ?? 9, $b['course']['name']]);
        } catch (\Throwable $e) {
            $eh_due = [];
        }
        ?>
    <div class="col-lg-6 col-md-12">
        <div class="card portal-card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-graduation-cap" aria-hidden="true"></i></span>My training due</h3>
                <a href="training.php" class="btn btn-sm btn-outline-primary">Open</a>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (!$eh_due) { ?>
                        <li class="list-group-item"><div class="portal-empty"><i class="fas fa-check-circle" aria-hidden="true"></i>Your training is up to date.</div></li>
                    <?php }
                    foreach (array_slice($eh_due, 0, 5) as $p) { [$eh_cls, $eh_txt] = $tp_badge($p); ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?= nullable_htmlentities($p['course']['name']) ?></span>
                            <span><?php if ($tp_when($p) !== '') { ?><span class="small text-secondary me-2"><?= nullable_htmlentities($tp_when($p)) ?></span><?php } ?><span class="badge text-bg-<?= nullable_htmlentities($eh_cls) ?>"><?= nullable_htmlentities($eh_txt) ?></span></span>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        </div>
    </div>
    <?php } ?>

</div>
