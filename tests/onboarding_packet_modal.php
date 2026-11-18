<?php
// Source-wiring checks for the onboarding packet modal (CLI only; no DB).
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
$fail = 0;
function chk(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) { $fail++; } }

$m = (string) @file_get_contents("$root/agent/modals/contact/contact_onboarding_packet.php");
$cd = (string) @file_get_contents("$root/agent/contact_details.php");
$mp = (string) @file_get_contents("$root/includes/modal_permissions.php");
$pr = (string) @file_get_contents("$root/src/Onboarding/PacketRequest.php");
chk('modal exists', $m !== '');
foreach (['csrf_token', 'name="email_same"', '<template', 'name="software[__I__][name]"', 'name="contact_id"',
          'action="onboarding_packet_pdf.php"', 'method="post"', 'autocomplete="off"', 'nullable_htmlentities',
          'enforceClientAccess', "enforceUserPermission('module_credential', 2)", 'readonly'] as $n) {
    chk("modal contains $n", str_contains($m, $n));
}
chk('change checkbox checked by default', (bool) preg_match('/name="software\[__I__\]\[change\]" value="1" checked/', $m));
chk('save checkbox unchecked by default', (bool) preg_match('/name="software\[__I__\]\[save\]" value="1">/', $m));
chk('hidden 0 precedes change checkbox', strpos($m, 'name="software[__I__][change]" value="0"') < strpos($m, 'name="software[__I__][change]" value="1"'));
chk('hidden 0 precedes save checkbox', strpos($m, 'name="software[__I__][save]" value="0"') < strpos($m, 'name="software[__I__][save]" value="1"'));
chk('no pre-rendered software rows', substr_count($m, 'name="software[') === substr_count(substr($m, (int) strpos($m, '<template'), (int) strpos($m, '</template>') - (int) strpos($m, '<template')), 'name="software['));
chk('cap of 25', str_contains($m, '$max_software = 25') && str_contains($m, 'rows.children.length >= MAX'));
chk('no inline onclick', !str_contains($m, 'onclick='));
chk('contact_details gate', str_contains($cd, "itflow_modal_allowed('contact/contact_onboarding_packet.php')") && str_contains($cd, 'modals/contact/contact_onboarding_packet.php?id=<?= $contact_id ?>'));
chk('contact_details gate comes after Credential item', strpos($cd, "itflow_modal_allowed('contact/contact_onboarding_packet.php')") > strpos($cd, "itflow_modal_allowed('credential/credential_add.php')"));
chk('modal permission row', (bool) preg_match("~'contact/contact_onboarding_packet.php'\s*=>\s*\['module_client' => 1, 'module_credential' => 2\]~", $mp));

// Field names must match the keys PacketRequest reads.
$names = [];
preg_match_all('/name="([^"]+)"/', $m, $mm);
foreach ($mm[1] as $n) { $names[$n] = true; }
$posted = array_keys($names);
foreach (['start_date', 'manager_name', 'email_same'] as $k) { chk("posts $k and PacketRequest reads it", isset($names[$k]) && str_contains($pr, "'$k'")); }
foreach (['computer', 'email'] as $grp) {
    foreach (['name', 'username', 'password', 'uri', 'note', 'change', 'save'] as $f) {
        if (in_array($grp . '.' . $f, ['computer.uri'], true)) { continue; } // computer has no link field
        chk("posts {$grp}[$f]", isset($names["{$grp}[$f]"]));
    }
}
foreach (['name', 'username', 'password', 'uri', 'note', 'change', 'save'] as $f) { chk("posts software[N][$f]", isset($names["software[__I__][$f]"])); }
foreach (['name', 'username', 'password', 'uri', 'note', 'change', 'save'] as $f) { chk("PacketRequest reads \$in['$f']", str_contains($pr, "\$in['$f']")); }
foreach (["\$post['computer']", "\$post['email']", "\$post['software']"] as $k) { chk("PacketRequest reads $k", str_contains($pr, $k)); }
// Every posted key is one the validator knows.
$known = ['csrf_token', 'contact_id', 'start_date', 'manager_name', 'email_same'];
$bad = [];
foreach ($posted as $n) {
    if (in_array($n, $known, true)) { continue; }
    if (preg_match('/^(computer|email|software\[__I__\])\[?(?:\[)?(name|username|password|uri|note|change|save)\]$/', $n)) { continue; }
    $bad[] = $n;
}
chk('no unknown posted field names: ' . implode(',', $bad), $bad === []);

exec('php -l ' . escapeshellarg("$root/agent/modals/contact/contact_onboarding_packet.php") . ' 2>&1', $o, $rc);
chk('php -l modal', $rc === 0);
exec('php -l ' . escapeshellarg("$root/agent/contact_details.php") . ' 2>&1', $o2, $rc2);
chk('php -l contact_details', $rc2 === 0);
exec('php -l ' . escapeshellarg("$root/includes/modal_permissions.php") . ' 2>&1', $o3, $rc3);
chk('php -l modal_permissions', $rc3 === 0);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
