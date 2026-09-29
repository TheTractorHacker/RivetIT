<?php
/*
 * Client Portal
 * Training: every contact sees their own required training; a department's Primary/Technical contacts see the
 * whole department, and anyone with direct reports (contacts.contact_manager_id, any depth) sees those people.
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

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Reports\PairSource;

$tp_client_id = intval($session_client_id);
$tp_me = intval($session_contact_id ?? 0);
$tp_dept_wide = ($session_contact_primary == 1 || $session_contact_is_technical_contact);

// Everyone below this contact in the manager chain (same department, bounded depth).
$tp_reports = [];
if ($tp_me > 0) {
    $frontier = [$tp_me];
    for ($depth = 0; $depth < 6 && $frontier; $depth++) {
        $in = implode(',', array_map('intval', $frontier));
        $frontier = [];
        $r = mysqli_query($mysqli, "SELECT contact_id FROM contacts WHERE contact_manager_id IN ($in) AND contact_client_id = $tp_client_id AND contact_archived_at IS NULL");
        while ($row = mysqli_fetch_assoc($r)) {
            $cid = intval($row['contact_id']);
            if ($cid !== $tp_me && !isset($tp_reports[$cid])) {
                $tp_reports[$cid] = $cid;
                $frontier[] = $cid;
            }
        }
    }
}

$tp_host = preg_replace('#^https?://#i', '', trim((string) ($config_base_url ?? '')));
$tp_ctx = new Ctx($mysqli, 0, false, 0, 'https://' . rtrim((string) $tp_host, '/'), TrainingSettings::fromGlobals(), null);
$tp_src = new PairSource($tp_ctx, Scope::of([$tp_client_id]), RecordsSettings::fromDb($mysqli));
$tp_pairs = $tp_src->pairs(['client_id' => $tp_client_id]);
$tp_people = [];
foreach ($tp_src->people(['client_id' => $tp_client_id]) as $p) {
    $tp_people[$p['contact_id']] = $p;
}

$tp_visible = function (int $cid) use ($tp_dept_wide, $tp_reports, $tp_me): bool {
    return $tp_dept_wide || isset($tp_reports[$cid]);
};

$tp_mine = [];
$tp_team = [];
foreach ($tp_pairs as $p) {
    if ($tp_me > 0 && $p['contact_id'] === $tp_me) {
        $tp_mine[] = $p;
    } elseif ($tp_visible($p['contact_id'])) {
        $tp_team[] = $p;
    }
}
$tp_team_people = [];
foreach ($tp_people as $cid => $p) {
    if ($cid !== $tp_me && $tp_visible($cid)) {
        $tp_team_people[$cid] = $p;
    }
}
$tp_show_team = ($tp_dept_wide || $tp_reports) && $tp_team_people;

$tp_badge = static function (array $p): array {
    $s = $p['status'];
    if ($s === 'overdue' || $s === 'expired') {
        return ['danger', $p['label'] !== '' ? $p['label'] : ucfirst($s)];
    }
    if (in_array($s, ['due_soon', 'expiring', 'retrain_due'], true)) {
        return ['warning', $p['label'] !== '' ? $p['label'] : ucfirst(str_replace('_', ' ', $s))];
    }
    if ($p['counts_current']) {
        return ['success', $p['label'] !== '' ? $p['label'] : 'Current'];
    }
    if ($s === 'waived') {
        return ['secondary', 'Waived'];
    }
    return ['secondary', $p['label'] !== '' ? $p['label'] : ucfirst(str_replace('_', ' ', $s))];
};
$tp_needs_attention = static fn(array $p): bool => in_array($p['status'], ['overdue', 'expired', 'due_soon', 'expiring', 'retrain_due'], true);
$tp_urgency = ['overdue' => 0, 'expired' => 1, 'due_soon' => 2, 'retrain_due' => 3, 'expiring' => 4, 'due' => 5];
$tp_when = static fn(array $p): string => (string) ($p['due_on'] ?? $p['expires_on'] ?? '');
?>

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-graduation-cap me-2"></i>Training</h3>
    </div>
    <div class="col-auto">
        <a href="ticket_add.php" class="btn btn-outline-primary"><i class="fas fa-life-ring me-2"></i>Report a training problem</a>
        <a href="service_catalog.php" class="btn btn-outline-secondary"><i class="fas fa-concierge-bell me-2"></i>Request something</a>
    </div>
</div>

<?php if ($tp_me > 0) { ?>
<div class="card card-outline card-primary mb-4">
    <div class="card-header"><h5 class="mb-0">My training</h5></div>
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
                        <td><?= nullable_htmlentities($p['course']['name']) ?></td>
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

<?php if ($tp_show_team) {
    $required = array_values(array_filter($tp_team, static fn($p) => $p['required'] && !$p['waived']));
    $current = count(array_filter($required, static fn($p) => $p['counts_current']));
    $overdue = count(array_filter($required, static fn($p) => $p['status'] === 'overdue'));
    $soon = count(array_filter($required, static fn($p) => in_array($p['status'], ['due_soon', 'expiring', 'retrain_due'], true)));
    $pct = $required ? (int) round(100 * $current / count($required)) : 100;
    $attention = array_values(array_filter($tp_team, $tp_needs_attention));
    usort($attention, static function ($a, $b) use ($tp_urgency, $tp_team_people, $tp_when) {
        return [$tp_urgency[$a['status']] ?? 9, $tp_when($a), $tp_team_people[$a['contact_id']]['name'] ?? ''] <=> [$tp_urgency[$b['status']] ?? 9, $tp_when($b), $tp_team_people[$b['contact_id']]['name'] ?? ''];
    });
    $per = [];
    foreach ($tp_team_people as $cid => $person) {
        $per[$cid] = ['person' => $person, 'total' => 0, 'current' => 0, 'overdue' => 0, 'soon' => 0];
    }
    foreach ($required as $p) {
        if (!isset($per[$p['contact_id']])) {
            continue;
        }
        $per[$p['contact_id']]['total']++;
        if ($p['counts_current']) { $per[$p['contact_id']]['current']++; }
        if ($p['status'] === 'overdue') { $per[$p['contact_id']]['overdue']++; }
        elseif (in_array($p['status'], ['due_soon', 'expiring', 'retrain_due'], true)) { $per[$p['contact_id']]['soon']++; }
    }
    uasort($per, static fn($a, $b) => [$b['overdue'], $b['soon'], $a['person']['name']] <=> [$a['overdue'], $a['soon'], $b['person']['name']]);
    ?>
    <div class="row mb-3">
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0"><?= count($tp_team_people) ?></div><div class="text-muted"><?= $tp_dept_wide ? 'People in department' : 'People on your team' ?></div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0 text-<?= $pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger') ?>"><?= $pct ?>%</div><div class="text-muted">Up to date</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0 text-<?= $overdue ? 'danger' : 'success' ?>"><?= $overdue ?></div><div class="text-muted">Overdue</div></div></div></div>
        <div class="col-6 col-md-3"><div class="card"><div class="card-body text-center"><div class="h2 mb-0 text-<?= $soon ? 'warning' : 'success' ?>"><?= $soon ?></div><div class="text-muted">Due soon / expiring</div></div></div></div>
    </div>

    <div class="card card-outline card-warning mb-4">
        <div class="card-header"><h5 class="mb-0">Needs attention</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>Person</th><th>Course</th><th>Status</th><th>Due / expires</th></tr></thead>
                    <tbody>
                    <?php if (!$attention) { ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nothing overdue or coming due.</td></tr>
                    <?php }
                    foreach ($attention as $p) {
                        [$cls, $txt] = $tp_badge($p); ?>
                        <tr>
                            <td><?= nullable_htmlentities($tp_team_people[$p['contact_id']]['name'] ?? '') ?></td>
                            <td><?= nullable_htmlentities($p['course']['name']) ?></td>
                            <td><span class="badge bg-<?= $cls ?>"><?= nullable_htmlentities($txt) ?></span></td>
                            <td><?= nullable_htmlentities($tp_when($p)) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-outline card-primary mb-4">
        <div class="card-header"><h5 class="mb-0">By person</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-light"><tr><th>Person</th><th>Title</th><th class="text-end">Required</th><th class="text-end">Up to date</th><th class="text-end">Due soon</th><th class="text-end">Overdue</th></tr></thead>
                    <tbody>
                    <?php foreach ($per as $row) { ?>
                        <tr>
                            <td><?= nullable_htmlentities($row['person']['name']) ?></td>
                            <td class="text-muted"><?= nullable_htmlentities($row['person']['title'] ?? '') ?></td>
                            <td class="text-end"><?= $row['total'] ?></td>
                            <td class="text-end"><?= $row['current'] ?></td>
                            <td class="text-end"><?= $row['soon'] ? '<span class="badge bg-warning">' . $row['soon'] . '</span>' : '0' ?></td>
                            <td class="text-end"><?= $row['overdue'] ? '<span class="badge bg-danger">' . $row['overdue'] . '</span>' : '0' ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php } ?>

<?php require_once "includes/footer.php";
