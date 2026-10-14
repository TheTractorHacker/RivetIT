<?php
/*
 * Fixtures and probes for tests/browser/rmm_runscript_smoke.mjs, run on the same throwaway install as tests/browser/rmm_seed.php (the guard of
 * tests/endpoint_agent_lib.php applies: the database name must contain "scratch").
 *
 *   php tests/browser/rmm_runscript_extra.php seed /tmp/rmm-seed.json       -> {"ids":{"read":..,"change":..,...}}   library scripts of every kind (administrator)
 *   php tests/browser/rmm_runscript_extra.php job <script_id>               -> the newest job of that library script: {"n":..,"type":..,"state":..,"device_id":..,"script_version":..,"params":{..}}
 *   php tests/browser/rmm_runscript_extra.php shell <device_id>             -> {"n":..,"type":..,"has_script":..} the newest free-form (non-library) script job of the device
 *   php tests/browser/rmm_runscript_extra.php approvals                     -> {"pending":N}
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_automation.php';

$mode = $argv[1] ?? '';
$api = fn (string $m, array $seg, array $qs = [], ?array $b = null) => rivetRmmAutoApi($db, 1, 'admin', $m, $seg, $qs, $b);
if ($mode === 'seed') {
    $mk = function (array $b) use ($api): int {
        $r = $api('POST', ['scripts'], [], $b + ['description' => '', 'note' => 'browser smoke', 'tags' => [], 'requires_approval' => false, 'destructive' => false, 'params' => []]);

        return (int) ($r['data']['script']['script_id'] ?? 0);
    };
    $ids = [
        'read' => $mk(['name' => 'RS Disk report (Windows)', 'language' => 'powershell', 'body' => 'Write-Output "report"', 'description' => 'Lists the biggest folders.', 'tags' => ['risk:read_only', 'disk'],
            'params' => [['name' => 'top_n', 'type' => 'int', 'label' => 'How many folders', 'default' => 5, 'min' => 1, 'max' => 20, 'required' => true, 'description' => 'Folders to list.'],
                ['name' => 'api_token', 'type' => 'secret', 'label' => 'API token'], ['name' => 'format', 'type' => 'choice', 'choices' => ['table', 'csv'], 'default' => 'table']]]),
        'change' => $mk(['name' => 'RS Clean temp (Windows)', 'language' => 'powershell', 'body' => 'if ($dry_run) { "would" } else { "did" }', 'description' => 'Deletes old temporary files.', 'tags' => ['risk:changes_system', 'cleanup'],
            'params' => [['name' => 'dry_run', 'type' => 'bool', 'default' => true, 'description' => 'Report only.'], ['name' => 'older_days', 'type' => 'int', 'default' => 30, 'min' => 1]]]),
        'disruptive' => $mk(['name' => 'RS Reset stack (Windows)', 'language' => 'powershell', 'body' => 'Restart-Service x', 'tags' => ['risk:disruptive'], 'destructive' => true, 'requires_approval' => true, 'params' => [['name' => 'dry_run', 'type' => 'bool', 'default' => true]]]),
        'approval' => $mk(['name' => 'RS Needs sign-off (Windows)', 'language' => 'powershell', 'body' => 'Get-Date', 'tags' => ['risk:changes_system'], 'requires_approval' => true, 'params' => [['name' => 'who', 'type' => 'string']]]),
        'destructive' => $mk(['name' => 'RS Wipe cache (Windows)', 'language' => 'powershell', 'body' => 'Remove-Item x', 'tags' => ['risk:changes_system'], 'destructive' => true]),
        'linux' => $mk(['name' => 'RS Uptime (Linux)', 'language' => 'bash', 'body' => 'uptime', 'tags' => ['risk:read_only']]),
    ];
    echo json_encode(['ids' => $ids]) . "\n";
} elseif ($mode === 'job') {
    $sid = (int) ($argv[2] ?? 0);
    $j = $db->query("SELECT j.type, j.state, j.device_id, j.params_json, x.script_version FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id=$sid ORDER BY j.created_at DESC, j.job_id DESC LIMIT 1")->fetch_assoc();
    $n = (int) $db->query("SELECT COUNT(*) FROM rmm_job_extra WHERE script_id=$sid")->fetch_row()[0];
    echo json_encode(['n' => $n, 'type' => $j['type'] ?? null, 'state' => $j['state'] ?? null, 'device_id' => (int) ($j['device_id'] ?? 0), 'script_version' => (int) ($j['script_version'] ?? 0), 'params' => json_decode((string) ($j['params_json'] ?? '{}'), true)]) . "\n";
} elseif ($mode === 'shell') {
    $dev = (int) ($argv[2] ?? 0);
    $r = $db->query("SELECT j.type, (j.script IS NOT NULL AND j.script <> '') AS has_script FROM endpoint_agent_jobs j LEFT JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE j.device_id=$dev AND j.type IN ('shell','powershell') AND x.job_id IS NULL ORDER BY j.created_at DESC LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $n = (int) $db->query("SELECT COUNT(*) FROM endpoint_agent_jobs j LEFT JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE j.device_id=$dev AND j.type IN ('shell','powershell') AND x.job_id IS NULL")->fetch_row()[0];
    echo json_encode(['n' => $n, 'type' => $row['type'] ?? null, 'has_script' => (bool) ($row['has_script'] ?? false)]) . "\n";
} elseif ($mode === 'approvals') {
    echo json_encode(['pending' => (int) $db->query("SELECT COUNT(*) FROM rmm_approvals WHERE state='pending_approval'")->fetch_row()[0]]) . "\n";
}
