<?php
/*
 * Client Portal - shared by training.php and training_manage.php: who this login is, whose training it may see
 * (fail-closed to its own department), and the compliance rows for them.
 * Requires includes/inc_all.php to have run and the autoloader to be loaded.
 */

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Reports\PairSource;

$tp_client_id = intval($session_client_id);
$tp_me = intval($session_contact_id ?? 0);
$tp_portal_role = 'none';
if ($tp_me > 0) {
    try {
        $r = mysqli_query($mysqli, "SELECT contact_portal_role FROM contacts WHERE contact_id = $tp_me AND contact_client_id = $tp_client_id");
        $tp_portal_role = ($r ? (mysqli_fetch_assoc($r)['contact_portal_role'] ?? 'none') : 'none');
    } catch (Throwable $e) {
        $tp_portal_role = 'none';
    }
}
$tp_dept_wide = ($session_contact_primary == 1 || $session_contact_is_technical_contact || $tp_portal_role === 'manager');

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
