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
chk('currentScript guard', str_contains($m, "|| document;") && str_contains($m, "var scope = (document.currentScript && document.currentScript.closest('.modal'))"));
chk("old closest('.modal, body') gone", !str_contains($m, "closest('.modal, body')"));
chk('null-guarded form', str_contains($m, 'if (!form) { return; }'));
chk('eye toggle on 3 password groups + template', substr_count($m, 'btn btn-default obpk-eye') === 3 && str_contains($m, "pi.type = pi.type === 'password' ? 'text' : 'password'"));
chk('generate shows value as text', str_contains($m, "input.type = 'text'"));
chk('.fail handler on generator', str_contains($m, '.fail(function') && str_contains($m, 'Could not generate a password'));
chk('save-without-password validity', str_contains($m, 'setCustomValidity'));
chk('no window-stays-open promise', !str_contains($m, 'stays open'));
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
$known = ['csrf_token', 'contact_id', 'ticket_id', 'person_name', 'attach_ticket', 'attach_passwords', 'start_date', 'manager_name', 'email_same'];
$bad = [];
foreach ($posted as $n) {
    if (in_array($n, $known, true)) { continue; }
    if (preg_match('/^(computer|email|software\[__I__\])\[?(?:\[)?(name|username|password|uri|note|change|save)\]$/', $n)) { continue; }
    $bad[] = $n;
}
chk('no unknown posted field names: ' . implode(',', $bad), $bad === []);

chk('info text updated', str_contains($m, 'passwords are not stored unless Save to Logins is ticked. Delete the PDF after printing.'));
chk('double-submit: setTimeout(0) after serialisation', str_contains($m, "addEventListener('submit'") && str_contains($m, '}, 0);') && strpos($m, "addEventListener('submit'") < strpos($m, 'setTimeout('));
chk('double-submit: submit button disabled', str_contains($m, 'submitBtn.disabled = true'));
chk('double-submit: ticked save boxes unticked and disabled', str_contains($m, 'cb.checked = false;') && str_contains($m, 'cb.disabled = true;'));
chk('double-submit: disable only inside setTimeout', strpos($m, 'submitBtn.disabled = true') > strpos($m, 'setTimeout('));
chk('double-submit: notes shown', str_contains($m, "won\\'t save again") && str_contains($m, 'Close this window to start another, to avoid saving duplicate Logins.'));
$tk = (string) @file_get_contents("$root/agent/ticket.php");
chk('ticket page: item shown whenever the ticket has a department (not only with a contact)', str_contains($tk, "if (\$client_id && itflow_modal_allowed('contact/contact_onboarding_packet.php'))") && !str_contains($tk, "if (\$contact_id && itflow_modal_allowed('contact/contact_onboarding_packet.php'))"));
chk('ticket page: link is ?ticket_id=<ticket id>', str_contains($tk, 'data-modal-url="modals/contact/contact_onboarding_packet.php?ticket_id=<?= intval($ticket_id) ?>"'));
chk('ticket page: the ticket contact (the manager) is not passed as the person', preg_match('/contact_onboarding_packet\.php\?[^"]*\bid=/', $tk) === 0);

// ---- Ticket mode in the modal.
$seg = static function (string $src, string $open, string $needle): bool {
    // $needle sits inside the template block that starts at $open (before that block's closing brace tag).
    $a = strpos($src, $open);
    if ($a === false) { return false; }
    $b = strpos($src, '<?php } ?>', $a);
    $c = strpos($src, '<?php } else { ?>', $a);
    $end = ($c !== false && ($b === false || $c < $b)) ? $c : $b;
    $n = strpos($src, $needle, $a);
    return $n !== false && $end !== false && $n < $end;
};
chk('modal: ticket_id read; contact id ignored in ticket mode', str_contains($m, "\$ticket_id = intval(\$_GET['ticket_id'] ?? 0);") && str_contains($m, "\$contact_id = \$ticket_id > 0 ? 0 : intval(\$_GET['id'] ?? 0);"));
chk('modal: ticket mode needs Support read', preg_match("/if \(\\\$ticket_id > 0\) \{\s*enforceUserPermission\('module_support', 1\);/", $m) === 1);
chk('modal: shared resolver, 404 on null, department from the context', str_contains($m, 'PacketContext::resolve($mysqli, $contact_id, $ticket_id,') && preg_match('/if \(\$ctx === null\) \{.*?http_response_code\(404\);/s', $m) === 1 && str_contains($m, '$client_id = $ctx->clientId;') && strpos($m, '$client_id = $ctx->clientId;') < strpos($m, 'enforceClientAccess($client_id);'));
chk('modal: permissions before the lookup', strpos($m, "enforceUserPermission('module_credential', 2)") < strpos($m, 'PacketContext::resolve(') && strpos($m, "enforceUserPermission('module_client', 1)") < strpos($m, 'PacketContext::resolve('));
chk('modal: hidden ticket_id only in ticket mode, contact_id only in contact mode', $seg($m, '<?php if ($ticket_mode) { ?>', 'name="ticket_id" value="<?= intval($ctx->ticket[\'id\']) ?>"') && preg_match('/<\?php \} else \{ \?>\s*<input type="hidden" name="contact_id" value="<\?= intval\(\$ctx->contactId\) \?>">/', $m) === 1);
chk("modal: ticket mode person is a required typed person_name (max 200, placeholder)", str_contains($m, '<input type="text" class="form-control" name="person_name" maxlength="200" required autocomplete="off" placeholder="New hire\'s name">'));
chk('modal: person_name only in ticket mode; read-only contact name otherwise', preg_match('/<label>Person<\/label>\s*<\?php if \(\$ticket_mode\) \{ \?>\s*<input[^>]*name="person_name"[^>]*>\s*<\?php \} else \{ \?>\s*<input type="text" class="form-control" value="<\?= \$contact_name \?>" readonly>/', $m) === 1 && substr_count($m, 'name="person_name"') === 1);
chk('modal: manager prefilled from the ticket contact (escaped), blank otherwise', str_contains($m, "\$manager_prefill = \$ticket_mode ? nullable_htmlentities(\$ctx->ticketContactName) : '';") && str_contains($m, 'name="manager_name" maxlength="200" placeholder="Manager name" value="<?= $manager_prefill ?>"'));
chk('modal: ticket-mode note about where Logins go', str_contains($m, 'Logins saved from a ticket go to the department (not to a contact), named after the new hire.'));

// ---- Attach section.
chk('attach: shown only for an open ticket with Support edit access', str_contains($m, "\$attach_ok = \$ticket_mode && !\$ctx->ticket['closed'] && lookupUserPermission('module_support') >= 2;"));
chk('attach: section absent without a ticket (inside if ($attach_ok))', $seg($m, '<?php if ($attach_ok) { ?>', 'name="attach_ticket"') && $seg($m, '<?php if ($attach_ok) { ?>', 'name="attach_passwords"') && $seg($m, '<?php if ($attach_ok) { ?>', 'Anyone who can open the ticket can read attached passwords.') && substr_count($m, 'name="attach_ticket"') === 1 && substr_count($m, 'name="attach_passwords"') === 1);
chk('attach: "Attach a copy to this ticket" ticked by default', preg_match('/name="attach_ticket" id="obpk-attach-ticket" value="1" checked>/', $m) === 1 && str_contains($m, 'Attach a copy to this ticket'));
chk('attach: "Include passwords in the attached copy" unticked by default', preg_match('/name="attach_passwords" id="obpk-attach-passwords" value="1">/', $m) === 1 && str_contains($m, 'Include passwords in the attached copy'));
chk('attach: warning text', str_contains($m, 'Anyone who can open the ticket can read attached passwords.'));
chk('attach: passwords box enabled only while attach is ticked', str_contains($m, 'attachPw.disabled = !attach.checked;') && str_contains($m, 'if (!attach.checked) { attachPw.checked = false; }') && str_contains($m, "attach.addEventListener('change', syncAttach)") && preg_match('/syncAttach\(\);\s*refresh\(\);/', $m) === 1);
chk('attach: JS tolerates the section being absent', str_contains($m, 'if (!attach || !attachPw) { return; }') && str_contains($m, 'if (attach) {'));
chk('modal: no client_id from the request', preg_match('/\$_(GET|POST|REQUEST)\s*\[\s*[\'"]client_id/', $m) === 0);
exec('php -l ' . escapeshellarg("$root/src/Onboarding/PacketContext.php") . ' 2>&1', $oc, $rcc);
chk('php -l PacketContext', $rcc === 0);
exec('php -l ' . escapeshellarg("$root/agent/ticket.php") . ' 2>&1', $ot, $rct);
chk('php -l ticket.php', $rct === 0);
exec('php -l ' . escapeshellarg("$root/agent/modals/contact/contact_onboarding_packet.php") . ' 2>&1', $o, $rc);
chk('php -l modal', $rc === 0);
exec('php -l ' . escapeshellarg("$root/agent/contact_details.php") . ' 2>&1', $o2, $rc2);
chk('php -l contact_details', $rc2 === 0);
exec('php -l ' . escapeshellarg("$root/includes/modal_permissions.php") . ' 2>&1', $o3, $rc3);
chk('php -l modal_permissions', $rc3 === 0);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
