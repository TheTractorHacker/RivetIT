<?php
declare(strict_types=1);
/* IT-INJ-11: add_category fails closed for unlisted category types. */
require __DIR__ . '/secauthz_bootstrap.php';
chdir(SECAUTHZ_ROOT . '/agent/post'); set_include_path('.');
sec_reset_fixture(); $q("DELETE FROM categories");
$GLOBALS['T_perm'] = ['module_support' => 2];   // Tickets/assets/docs editor, nothing else
$add = fn(string $type) => sec_run('agent/post/category.php', 'add_category', ['add_category' => 1, 'csrf_token' => 't', 'name' => 'N-' . $type, 'type' => $type, 'color' => '#000']);
$cnt = fn(string $type) => (int) $one("SELECT COUNT(*) FROM categories WHERE category_type = '" . mysqli_real_escape_string($mysqli, $type) . "'");

$r = $add('SomeUnlistedType');
$ok($cnt('SomeUnlistedType') === 0, "unlisted type: a module_support-only role cannot create it ($r)");
$r = $add('Ticket');
$ok($cnt('Ticket') === 1, "listed type owned by the role's module still works ($r)");
$r = $add('Expense');
$ok($cnt('Expense') === 0 && str_starts_with($r, 'denied'), "finance type still needs the finance module ($r)");
$GLOBALS['session_is_admin'] = true;
$r = $add('SomeUnlistedType');
$ok($cnt('SomeUnlistedType') === 1, "administrator can still create an unlisted type ($r)");
exit($T_fails ? 1 : 0);
