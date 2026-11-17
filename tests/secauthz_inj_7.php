<?php
declare(strict_types=1);
/* IT-INJ-7: legacy vendor_contact CSV export/import must enforce department access. */
require __DIR__ . '/secauthz_bootstrap.php';
sec_fn(['formatPhoneNumber', 'strtoAZaz09']);
sec_reset_fixture();
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'A1', contact_email = 'a1@a.example', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'B1', contact_email = 'b1@b.example', contact_client_id = 2");
$GLOBALS['T_perm'] = ['module_client' => 2];
$F = 'agent/post/vendor_contact.php';

// export: foreign department -> refused, nothing printed
ob_start(); $r = sec_run($F, 'export_vendor_contacts_csv', ['export_vendor_contacts_csv' => 1, 'csrf_token' => 't', 'client_id' => 2]); $out = ob_get_clean();
$ok(str_starts_with($r, 'redirect:') && !str_contains($out, 'b1@b.example'), "export: other department's contacts are not exported ($r)");

// import: write a CSV and point $_FILES at it
$csv = tempnam(sys_get_temp_dir(), 'sa'); file_put_contents($csv, "Name,Title,Department,Email,Phone,Ext,Mobile,Location\nInjected,,,inj@evil.example,,,,\n");
$GLOBALS['T_files'] = ['file' => ['tmp_name' => $csv, 'name' => 'c.csv', 'size' => filesize($csv)]];
$r = sec_run($F, 'import_vendor_contacts_csv', ['import_vendor_contacts_csv' => 1, 'csrf_token' => 't', 'client_id' => 2]);
$ok((int) $one("SELECT COUNT(*) FROM contacts WHERE contact_email = 'inj@evil.example'") === 0, "import: cannot add contacts to another department ($r)");
$r = sec_run($F, 'import_vendor_contacts_csv', ['import_vendor_contacts_csv' => 1, 'csrf_token' => 't', 'client_id' => 1]);
$ok((int) $one("SELECT COUNT(*) FROM contacts WHERE contact_email = 'inj@evil.example' AND contact_client_id = 1") === 1, "import: own department still works ($r)");
// (own-department export ends in exit; after streaming the CSV, so it is not driven here)
exit($T_fails ? 1 : 0);
