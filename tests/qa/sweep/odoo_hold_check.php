<?php
// Runs the Odoo directory mapper's employee sync against the SCRATCH database with canned employee records (no Odoo contact at all).
// usage: php odoo_hold_check.php <app-dir> <odoo_employee_id> <name> <email> <active 0|1>
chdir($argv[1] . '/scripts');
$_SERVER['DOCUMENT_ROOT'] = $argv[1];
require_once '../config.php';
require_once '../functions.php';
$mapper = new \ITFlow\Integrations\Odoo\OdooDirectoryMapper($mysqli, 1);
$stats = $mapper->syncEmployees([[ 'id' => (int) $argv[2], 'name' => $argv[3], 'work_email' => $argv[4], 'active' => $argv[5] === '1', 'department_id' => false ]], []);
echo json_encode(['created' => $stats['created'] ?? null, 'updated' => $stats['updated'] ?? null, 'matched' => $stats['matched'] ?? null, 'errors' => $stats['errors'] ?? []]) . "\n";
