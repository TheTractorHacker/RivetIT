<?php
declare(strict_types=1);
/* IT-INJ-4: mail-request view modal and convert handler are scoped to the mailbox's department. */
require __DIR__ . '/secauthz_bootstrap.php';
sec_fn(['mailRequestOwnerClientId']);
$GLOBALS['T_converted'] = [];
function convertMailRequestToTicket(int $id, int $client_id): ?int { $GLOBALS['T_converted'][] = [$id, $client_id]; return 99; }
sec_reset_fixture();
$q("INSERT INTO mailboxes SET mailbox_id = 1, mailbox_name = 'A', mailbox_default_client_id = 1");
$q("INSERT INTO mailboxes SET mailbox_id = 2, mailbox_name = 'B', mailbox_default_client_id = 2");
$q("INSERT INTO mailboxes SET mailbox_id = 3, mailbox_name = 'Shared', mailbox_default_client_id = NULL");
foreach ([[1, 1], [2, 2], [3, 3]] as [$id, $mb]) { $q("INSERT INTO mail_requests SET mail_request_id = $id, mail_request_mailbox_id = $mb, mail_request_from_email = 'x@y.example', mail_request_subject = 's'"); }
$GLOBALS['T_perm'] = ['module_support' => 2];
$F = 'agent/post/mail_requests.php';
$conv = fn(int $id, int $dest) => sec_run($F, 'convert_mail_request', ['convert_mail_request' => 1, 'csrf_token' => 't', 'mail_request_id' => $id, 'client_id' => $dest]);

$ok(mailRequestOwnerClientId(2) === 2 && mailRequestOwnerClientId(3) === 0 && mailRequestOwnerClientId(99) === 0, "owner helper: mailbox default client, 0 for shared inbox / unknown");
$r = $conv(2, 1);
$ok(!$GLOBALS['T_converted'] && str_starts_with($r, 'redirect:clients.php'), "convert: restricted agent cannot convert a request from another department's mailbox into their own ($r)");
$r = $conv(1, 1);
$ok($GLOBALS['T_converted'] === [[1, 1]], "convert: own mailbox request still converts ($r)");
$GLOBALS['T_converted'] = []; $conv(3, 1);
$ok($GLOBALS['T_converted'] === [[3, 1]], "convert: shared (no default client) mailbox request still converts");
$GLOBALS['T_converted'] = []; $GLOBALS['session_is_admin'] = true; $conv(2, 2);
$ok($GLOBALS['T_converted'] === [[2, 2]], "convert: administrator unaffected");
$GLOBALS['session_is_admin'] = false;

// The view modal is a page, not a handler block: check its guard is wired to the same helper before anything is rendered.
$m = (string) file_get_contents(SECAUTHZ_ROOT . '/agent/modals/mail_request/mail_request_view.php');
$posGuard = strpos($m, 'enforceClientAccess($mr_owner_client_id)'); $posRender = strpos($m, 'purifier->purify');
$ok($posGuard !== false && str_contains($m, 'mailRequestOwnerClientId($mail_request_id)') && $posGuard < $posRender, "view modal: department guard runs before the body is rendered");
exit($T_fails ? 1 : 0);
