<?php
// Seeds catalog fixtures into the scratch app DB (fictional demo data only). Usage: php seed.php <worktree>
$root = $argv[1];
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER') ?: 'i30_scratch', getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME') ?: 'i30_scratch_app');
require $root . '/src/ITSM/ServiceCatalogService.php';
use ITFlow\ITSM\ServiceCatalogService as S;
$svc = new S($m);
$q = fn($s) => $m->query($s);
$one = fn($s) => ($r = $m->query($s)) ? ($r->fetch_row()[0] ?? null) : null;
$admin = (int) $one("SELECT u.user_id FROM users u JOIN user_roles r ON r.role_id=u.user_role_id WHERE r.role_is_admin=1 AND u.user_status=1 AND u.user_archived_at IS NULL ORDER BY u.user_id LIMIT 1");
$other = (int) $one("SELECT u.user_id FROM users u WHERE u.user_type=1 AND u.user_status=1 AND u.user_archived_at IS NULL AND u.user_id <> $admin ORDER BY u.user_id LIMIT 1");
$role = (int) $one("SELECT role_id FROM user_roles WHERE role_is_admin=0 AND role_archived_at IS NULL AND role_type=1 ORDER BY role_id LIMIT 1");
$q("DELETE FROM service_catalog_items WHERE name IN ('New laptop','Shared mailbox','Monitor','Folder access')");
$hw = $svc->saveCategory(0, 'Hardware', 'Laptops, monitors and peripherals', 'fa-laptop', 1, true);
$ac = $svc->saveCategory(0, 'Access', 'Mailboxes, folders and software', 'fa-key', 2, true);
$svc->saveCategory(0, 'Employee', 'Joiners, movers and leavers', 'fa-user-plus', 3, true);
$mk = function ($name, $cat, $o = []) use ($q, $m) {
    $n = $m->real_escape_string($name);
    $q("INSERT INTO service_catalog_items SET name='$n', description='" . $m->real_escape_string($o['desc'] ?? '') . "', icon='" . ($o['icon'] ?? 'fa-ticket-alt') . "', ticket_subject_template='$n request', is_active=1, catalog_category_id=" . ($cat ?: 'NULL') . ", requires_approval=" . ($o['appr'] ?? 0) . ", risk_score=" . ($o['risk'] ?? 0) . ", reminder_after_hours=4, escalate_after_hours=8, expire_after_hours=48");
    return (int) $m->insert_id;
};
$lap = $mk('New laptop', $hw, ['desc' => 'Order a laptop for a new or existing employee', 'icon' => 'fa-laptop', 'appr' => 1, 'risk' => 20]);
$svc->saveFields($lap, S::normalizeFieldRows([
    'field_label' => ['Model', 'Needs admin rights', 'Justification', 'Accessories'],
    'field_type' => ['select', 'checkbox', 'text', 'table'], 'field_order' => [1, 2, 3, 4],
    'field_options' => ["Standard\nDeveloper\nWorkstation", '', '', "Item\nQty|number"], 'field_required' => [1 => 0, 2 => 1],
    'field_showif_field' => [2 => 'needs_admin_rights'], 'field_showif_op' => [2 => 'equals'], 'field_showif_value' => [2 => 'Yes'],
    'field_showif2_field' => [2 => 'model'], 'field_showif2_op' => [2 => 'equals'], 'field_showif2_value' => [2 => 'Workstation'], 'field_showif_match' => [2 => 'any'],
]));
$svc->saveRiskRules($lap, S::normalizeRiskRuleRows(['risk_field' => ['model', 'needs_admin_rights'], 'risk_op' => ['equals', 'equals'], 'risk_value' => ['Workstation', 'Yes'], 'risk_points' => [40, 30]]));
$svc->saveSteps($lap, S::normalizeStepRows([
    'step_type' => ['user', 'role', 'user'], 'step_ref_user' => [$admin, 0, $other ?: $admin], 'step_ref_role' => [1 => $role],
    'step_order' => [1, 2, 3], 'step_mode' => ['any', 'any', 'any'], 'step_parallel' => [1 => 1], 'step_risk_min' => [2 => 50],
]));
$mk('Monitor', $hw, ['desc' => 'A second screen', 'icon' => 'fa-desktop']);
$mb = $mk('Shared mailbox', $ac, ['desc' => 'Create or get access to a shared mailbox', 'icon' => 'fa-envelope']);
$svc->saveFields($mb, S::normalizeFieldRows(['field_label' => ['Mailbox name'], 'field_type' => ['text'], 'field_order' => [1], 'field_required' => [1]]));
$mk('Folder access', 0, ['desc' => 'Ask for access to a shared folder', 'icon' => 'fa-folder-open']);
$q("UPDATE settings SET config_redis_port = 6399 WHERE company_id = 1");
echo "seeded laptop=$lap admin=$admin other=$other role=$role\n";
