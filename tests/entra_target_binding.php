<?php
/*
 * IT-9 (pentest 2026-10-08): the Entra account actions must act only on an Entra account in the same department as the contact the
 * workflow runs for, and entra_create_account must never change an account that already exists. DB-free: a stubbed Graph client, no network.
 *   php tests/entra_target_binding.php
 */
require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Integrations\ExternalUser;
use ITFlow\Integrations\Microsoft\EntraAccountService;
use ITFlow\Integrations\Microsoft\GraphClient;
use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\Actions\EntraAddToGroupsAction;
use ITFlow\Workflow\Actions\EntraDisableAccountAction;
use ITFlow\Workflow\DirectoryActionGateway;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$threw = function (callable $f, string $needle = '') { try { $f(); } catch (\Throwable $e) { return $needle === '' || str_contains($e->getMessage(), $needle); } return false; };

/** Records every write; serves users from a map keyed by lower-case email. */
class StubGraph extends GraphClient
{
    public array $users = [];
    public array $calls = [];
    public function findUserByEmail(string $email): ?ExternalUser { return $this->users[strtolower($email)] ?? null; }
    public function getUser(string $externalId): ?ExternalUser { return $this->users[strtolower($externalId)] ?? null; }
    public function setAccountEnabled(string $userId, bool $enabled): void { $this->calls[] = ['enable', $userId, $enabled]; }
    public function revokeSignInSessions(string $userId): void { $this->calls[] = ['revoke', $userId]; }
    public function addGroupMember(string $groupId, string $userId): bool { $this->calls[] = ['group', $groupId, $userId]; return true; }
    public function createUser(array $spec): array { $this->calls[] = ['create', $spec['userPrincipalName']]; return ['id' => 'new-1']; }
}
$user = fn (string $id, string $mail, ?string $dept) => new ExternalUser($id, $mail, $mail, true, ['id' => $id] + ($dept === null ? [] : ['department' => $dept]));
$graph = (new ReflectionClass(StubGraph::class))->newInstanceWithoutConstructor();
$graph->users = [
    'a@x.test' => $user('id-a', 'a@x.test', 'Sales'),
    'b@x.test' => $user('id-b', 'b@x.test', 'Finance'),
    'c@x.test' => $user('id-c', 'c@x.test', null),
];
$svc = new EntraAccountService($graph, function (): void {}, fn (string $s): bool => true);
$G = '11111111-1111-1111-1111-111111111111';

// ---- disable
$svc->disableAccount('a@x.test', true, ' sales ');
$ok($graph->calls === [['enable', 'id-a', false], ['revoke', 'id-a']], 'disable: same department (case/space-insensitive) is allowed');
$graph->calls = [];
$ok($threw(fn () => $svc->disableAccount('b@x.test', true, 'Sales'), 'not in this contact') && $graph->calls === [], 'disable: another department\'s account is refused, nothing sent');
$ok($threw(fn () => $svc->disableAccount('c@x.test', true, 'Sales'), 'not in this contact') && $graph->calls === [], 'disable: account with no department attribute is refused');
$ok($threw(fn () => $svc->disableAccount('a@x.test', true, ''), 'no department') && $graph->calls === [], 'disable: contact with no department is refused');
$ok($threw(fn () => $svc->disableAccount('a@x.test', true), 'no department') && $graph->calls === [], 'disable: omitting the department never means "unrestricted"');

// ---- add to groups
$svc->addToGroups('a@x.test', [$G], 'Sales');
$ok($graph->calls === [['group', $G, 'id-a']], 'groups: same department is allowed');
$graph->calls = [];
$ok($threw(fn () => $svc->addToGroups('b@x.test', [$G], 'Sales')) && $graph->calls === [], 'groups: another department\'s account is refused, no group change');
$ok($threw(fn () => $svc->addToGroups('a@x.test', [$G])) && $graph->calls === [], 'groups: omitted department is refused');

// ---- create never touches an existing account
$msg = $svc->createAccount(['upn' => 'b@x.test', 'display_name' => 'B', 'enabled' => false, 'groups' => [$G]]);
$ok($graph->calls === [] && str_contains($msg, 'already exists') && str_contains($msg, 'no groups changed'), 'create: an existing UPN is left alone, no groups added');

// ---- the actions hand the contact's department to the gateway
class RecGw implements ActionGateway, DirectoryActionGateway
{
    public array $seen = [];
    public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int { return 0; }
    public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void {}
    public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void {}
    public function emitEvent(string $event, array $data): void {}
    public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void {}
    public function disablePortalLogin(int $contactId): string { return ''; }
    public function entraWritesAllowed(): bool { return true; }
    public function entraDisableAccount(string $email, bool $revokeSessions, int $runTaskId, string $expectedDepartment = ''): string { $this->seen[] = ['disable', $email, $expectedDepartment]; return 'ok'; }
    public function entraCreateAccount(array $spec, int $runTaskId): string { return 'ok'; }
    public function entraAddToGroups(string $email, array $groupIds, int $runTaskId, string $expectedDepartment = ''): string { $this->seen[] = ['groups', $email, $expectedDepartment]; return 'ok'; }
}
$ctx = ['run_task_id' => 5, 'vars' => ['employee_email' => 'a@x.test', 'department' => 'Sales']];
$gw = new RecGw();
(new EntraDisableAccountAction())->execute([], $ctx, $gw);
(new EntraAddToGroupsAction())->execute(['groups' => $G], $ctx, $gw);
$ok($gw->seen === [['disable', 'a@x.test', 'Sales'], ['groups', 'a@x.test', 'Sales']], 'actions pass the contact\'s department to the gateway');

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
