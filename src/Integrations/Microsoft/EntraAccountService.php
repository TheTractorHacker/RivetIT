<?php

namespace ITFlow\Integrations\Microsoft;

use ITFlow\Integrations\ExternalUser;

/**
 * The account changes lifecycle workflows can make in Entra ID. A thin, testable layer over GraphClient's write methods:
 * idempotent (create looks the UPN up first; disable and group changes tolerate "already done"), audited (every Graph call that
 * changes something writes an audit event with the target and the outcome, never a password or token), and gated (GraphClient
 * itself refuses every write unless 'Allow RivetIT to change Entra accounts' is on).
 *
 * The temporary password for a new account is generated here from random_bytes, passed to $storeSecret (which encrypts it for the
 * assigned technician) and to Graph, and appears nowhere else: not in return values, exceptions, logs or audit events.
 */
final class EntraAccountService
{
    /** @var callable(string $event, string $action, string $summary, array $meta): void */
    private $audit;
    /** @var callable(string $secret): bool */
    private $storeSecret;

    /**
     * @param callable $audit        (event, action, summary, metadata) => void
     * @param callable $storeSecret  (secret) => bool; true when it was saved for the technician
     */
    public function __construct(private GraphClient $graph, callable $audit, callable $storeSecret)
    {
        $this->audit = $audit;
        $this->storeSecret = $storeSecret;
    }

    /** 20 characters from all four classes, so it meets any default Entra password policy. */
    public static function generatePassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+-=?@'];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < 20) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** Cleans a group object id list; only GUIDs are accepted. @return string[] */
    public static function cleanGroupIds($raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
        $out = [];
        foreach ($items as $g) {
            $g = strtolower(trim((string) $g));
            if ($g !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $g)) {
                $out[$g] = true;
            }
        }

        return array_slice(array_keys($out), 0, 25);
    }

    /**
     * The Entra account an action found by email must belong to the same department as the RivetIT contact the workflow runs for
     * (pentest IT-9): otherwise anyone who can edit a contact's email could aim a disable or a group change at another department's
     * account. Fails closed: an empty department on either side refuses the change. Nothing was sent when this throws.
     */
    public static function assertSameDepartment(ExternalUser $user, string $expectedDepartment, string $email): void
    {
        $want = mb_strtolower(trim($expectedDepartment));
        $have = mb_strtolower(trim((string) ($user->raw['department'] ?? '')));
        if ($want === '') {
            throw new \RuntimeException("the contact has no department, so the Entra account for $email cannot be tied to it; nothing was changed");
        }
        if ($have === '' || $have !== $want) {
            throw new \RuntimeException("the Entra account for $email is not in this contact's department (the Entra department attribute must match); nothing was changed");
        }
    }

    public function disableAccount(string $email, bool $revokeSessions, string $expectedDepartment = ''): string
    {
        $user = $this->graph->findUserByEmail($email);
        if ($user === null) {
            // A security step must not look done when nothing was found.
            throw new \RuntimeException("no Entra account found for $email; nothing was disabled");
        }
        self::assertSameDepartment($user, $expectedDepartment, $email);
        $parts = [];
        if ($user->enabled) {
            $this->graph->setAccountEnabled($user->externalId, false);
            ($this->audit)('entra.account_disabled', 'disabled', "Entra sign-in disabled for $email", ['target' => $email]);
            $parts[] = 'sign-in disabled';
        } else {
            $parts[] = 'sign-in was already disabled';
        }
        if ($revokeSessions) {
            $this->graph->revokeSignInSessions($user->externalId);
            ($this->audit)('entra.sessions_revoked', 'revoked', "Entra sign-in sessions revoked for $email", ['target' => $email]);
            $parts[] = 'sessions revoked';
        }

        return "$email: " . implode(', ', $parts) . ' (account not deleted)';
    }

    /** @param array{upn:string,display_name:string,enabled:bool,groups:string[]} $spec */
    public function createAccount(array $spec): string
    {
        $upn = $spec['upn'];
        $existing = $this->graph->getUser($upn);
        if ($existing !== null) {
            // An existing account is never touched: adding groups here would let anyone who can pick the email grant another person's account
            // group access (pentest IT-9). Do the group step by hand or through an add-to-groups task on an account in the contact's department.
            return "$upn already exists in Entra; nothing created, no password set and no groups changed";
        }

        $password = self::generatePassword();
        try {
            $created = $this->graph->createUser([
                'userPrincipalName' => $upn,
                'displayName' => $spec['display_name'],
                'mailNickname' => self::mailNickname($upn),
                'accountEnabled' => (bool) $spec['enabled'],
                'password' => $password,
            ]);
            try {
                $stored = (bool) ($this->storeSecret)($password);
            } catch (\Throwable) {
                $stored = false; // the account exists now; a retry finds it and sets nothing
            }
        } finally {
            $password = str_repeat("\0", strlen($password));
        }
        ($this->audit)('entra.account_created', 'created', "Entra account $upn created (" . ($spec['enabled'] ? 'enabled' : 'disabled') . '), password change required at first sign-in', ['target' => $upn, 'enabled' => (bool) $spec['enabled']]);

        $msg = "$upn created (" . ($spec['enabled'] ? 'enabled' : 'disabled') . '), must change the password at first sign-in; '
            . ($stored ? 'the temporary password is shown once to the assigned technician on this page' : 'the temporary password could NOT be saved: reset it in Entra');
        $groups = $this->addGroups($created['id'], $upn, (array) $spec['groups']);

        return $msg . ($groups !== '' ? '; ' . $groups : '');
    }

    public function addToGroups(string $email, array $groupIds, string $expectedDepartment = ''): string
    {
        $user = $this->graph->findUserByEmail($email);
        if ($user === null) {
            throw new \RuntimeException("no Entra account found for $email; nothing was added to groups");
        }
        self::assertSameDepartment($user, $expectedDepartment, $email);

        return "$email: " . $this->addGroups($user->externalId, $email, $groupIds);
    }

    private function addGroups(string $userId, string $label, array $groupIds): string
    {
        if (!$groupIds) {
            return '';
        }
        $added = 0;
        $already = 0;
        foreach ($groupIds as $gid) {
            if ($this->graph->addGroupMember($gid, $userId)) {
                $added++;
                ($this->audit)('entra.group_member_added', 'added', "$label added to Entra group $gid", ['target' => $label, 'group_id' => $gid]);
            } else {
                $already++;
            }
        }

        return "groups: $added added, $already already a member";
    }

    public static function mailNickname(string $upn): string
    {
        $n = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $upn)[0]);

        return substr($n !== '' ? $n : 'user' . bin2hex(random_bytes(3)), 0, 64);
    }
}
