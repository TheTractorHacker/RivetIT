<?php
/*
 * Seeds a THROWAWAY install for tests/browser/relationships_smoke.mjs: one department, three assets, a document, a KB article, a global vendor,
 * a service and a chain of links (service depends on asset, a second asset depends on the first), then makes user 1 an administrator with the login
 * admin@scratch.test / RMM_SMOKE_PASSWORD (default Scratch-Admin-1234). Writes the ids to the JSON file named by the first argument.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/relationships_seed.php /tmp/rel-seed.json
 *
 * WIPES users, assets, clients, documents, services, vendors, KB articles and links of that database (scratch guard of tests/endpoint_agent_lib.php).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
use ITFlow\Links\{LinkActor, LinkService};

$out = $argv[1] ?? (sys_get_temp_dir() . '/rel-seed.json');
ea_reset();
foreach (['entity_links', 'asset_vendors', 'software_vendors', 'asset_documents', 'software_assets', 'service_assets', 'documents', 'services', 'vendors', 'kb_articles', 'software', 'user_settings'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM modules"); $q("DELETE FROM user_role_permissions"); $q("DELETE FROM user_roles"); $q("DELETE FROM users");
$q("INSERT INTO user_roles SET role_id=1, role_name='Admin', role_is_admin=1, role_type=1");
$pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
$q("INSERT INTO users SET user_id=1, user_name='Scratch Admin', user_email='admin@scratch.test', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "', user_type=1, user_status=1, user_role_id=1");
$q("INSERT INTO user_settings (user_id) VALUES (1)");
$q("UPDATE settings SET config_module_enable_kb = 1 WHERE company_id = 1");
$q("INSERT INTO assets SET asset_id=101, asset_name='srv-core', asset_type='Server', asset_make='Dell', asset_client_id=1");
$q("INSERT INTO assets SET asset_id=102, asset_name='srv-app', asset_type='Server', asset_make='Dell', asset_client_id=1");
$q("INSERT INTO assets SET asset_id=103, asset_name='ws-front-desk', asset_type='Desktop', asset_make='HP', asset_client_id=1");
$q("INSERT INTO documents SET document_id=401, document_name='Core server runbook', document_content='<p>x</p>', document_content_raw='x', document_client_id=1");
$q("INSERT INTO services SET service_id=301, service_name='Payroll', service_client_id=1");
$q("INSERT INTO vendors SET vendor_id=501, vendor_name='Acme Support', vendor_client_id=0");
$q("INSERT INTO kb_articles SET kb_article_id=601, kb_article_title='Restart the core server', kb_article_content='<p>x</p>', kb_article_content_raw='x', kb_article_client_id=0");
$a = LinkActor::forUser($db, 1);
$svc = new LinkService($db);
foreach ([['service', 301, 'asset', 101, 'depends_on'], ['asset', 102, 'asset', 101, 'depends_on'], ['asset', 101, 'document', 401, 'documented_by']] as [$st, $si, $dt, $di, $lt]) {
    $r = $svc->create($a, $st, $si, $dt, $di, $lt);
    if (!$r['ok']) { fwrite(STDERR, "seed link failed: {$r['message']}\n"); exit(1); }
}
file_put_contents($out, json_encode(['asset' => ['core' => 101, 'app' => 102, 'desk' => 103], 'document' => 401, 'service' => 301, 'vendor' => 501, 'kb' => 601], JSON_PRETTY_PRINT));
echo "seeded; ids in $out\n";
