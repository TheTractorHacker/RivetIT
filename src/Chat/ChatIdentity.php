<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\SlackInteractive;

/**
 * Who is this chat user? Resolves a Slack or Teams user to an agent or a portal contact.
 *
 * Order, and each step fails closed:
 *   1. An EXPLICIT link (Administration > Chat > Identity links, table chat_identities). It always wins. If the link points at an agent who is
 *      disabled / archived / not an agent, or a contact who is archived, the user is "not linked": there is no fall back to email, so an
 *      administrator who unlinks or disables someone really cuts them off.
 *   2. Only when no explicit link exists and the install opted in (Slack: config_slack_link_by_email, Teams: config_teams_link_by_email): a
 *      verified email. Slack: the address Slack says is confirmed (users.info with the bot token); Teams: the directory email the Bot Framework
 *      roster reports for that user in the pinned tenant. Exactly one active agent must own that address (agents are tried before contacts).
 * Names, display names and anything else inside a request are never used for identity.
 */
final class ChatIdentity
{
    public const SLACK = 'slack';
    public const TEAMS = 'teams';

    /** @var callable(string $token, string $slackUserId): array|null */
    private $slackLookup;
    /** @var callable(string $aadObjectId, string $conversationId, string $serviceUrl): ?string|null */
    private $teamsEmail;

    public function __construct(private \mysqli $db, private ChatSettings $settings, ?callable $slackLookup = null, ?callable $teamsEmail = null)
    {
        $this->slackLookup = $slackLookup;
        $this->teamsEmail = $teamsEmail;
    }

    /**
     * @return array{kind:string,user_id:int,contact_id:int,client_id:int,via:string,name:string}|null
     */
    public function resolve(string $platform, string $teamId, string $externalId, array $ctx = []): ?array
    {
        if (!in_array($platform, [self::SLACK, self::TEAMS], true) || $externalId === '' || strlen($externalId) > 128) {
            return null;
        }
        $link = $this->explicit($platform, $teamId, $externalId);
        if ($link !== null) {
            return $link === false ? null : $link;
        }
        $email = $platform === self::SLACK ? $this->slackEmail($externalId, $teamId) : $this->teamsEmail($externalId, $ctx);
        if ($email === null) {
            return null;
        }

        return $this->byEmail($email);
    }

    /** @return array|false|null the identity, false = a link exists but its target is unusable (deny), null = no link at all */
    private function explicit(string $platform, string $teamId, string $externalId)
    {
        $stmt = mysqli_prepare($this->db, 'SELECT identity_user_id, identity_contact_id FROM chat_identities WHERE identity_platform = ? AND identity_external_id = ? AND (identity_team_id = ? OR identity_team_id = \'\') ORDER BY (identity_team_id = \'\') ASC LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'sss', $platform, $externalId, $teamId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row) {
            return null;
        }
        if ((int) $row['identity_user_id'] > 0) {
            return $this->agent((int) $row['identity_user_id'], 'explicit') ?? false;
        }
        if ((int) $row['identity_contact_id'] > 0) {
            return $this->contact((int) $row['identity_contact_id'], 'explicit') ?? false;
        }

        return false;
    }

    private function slackEmail(string $slackUserId, string $teamId): ?string
    {
        if (!$this->settings->flag('config_slack_link_by_email')) {
            return null;
        }
        $si = new SlackInteractive($this->db, $this->slackLookup);

        return $si->verifiedEmailForSlackUser($slackUserId, $this->settings->rawSecret('config_slack_bot_token'), $teamId);
    }

    private function teamsEmail(string $aadObjectId, array $ctx): ?string
    {
        if (!$this->settings->flag('config_teams_link_by_email')) {
            return null;
        }
        $tenant = $this->settings->text('config_teams_tenant_id');
        if ($tenant === '' || !hash_equals(strtolower($tenant), strtolower((string) ($ctx['tenant_id'] ?? '')))) {
            return null; // email matching needs a pinned tenant; an unpinned install never matches by email
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', $aadObjectId) || $this->teamsEmail === null) {
            return null;
        }
        $email = ($this->teamsEmail)($aadObjectId, (string) ($ctx['conversation_id'] ?? ''), (string) ($ctx['service_url'] ?? ''));
        $email = $email === null ? '' : strtolower(trim($email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Exactly one active agent with this address, else exactly one active portal contact; otherwise null. */
    private function byEmail(string $email): ?array
    {
        $stmt = mysqli_prepare($this->db, 'SELECT user_id FROM users WHERE LOWER(user_email) = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL');
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        if (count($rows) === 1) {
            return $this->agent((int) $rows[0]['user_id'], 'email');
        }
        if (count($rows) > 1) {
            return null;
        }
        $stmt = mysqli_prepare($this->db, 'SELECT contact_id FROM contacts WHERE LOWER(contact_email) = ? AND contact_archived_at IS NULL AND contact_client_id > 0');
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        return count($rows) === 1 ? $this->contact((int) $rows[0]['contact_id'], 'email') : null;
    }

    private function agent(int $userId, string $via): ?array
    {
        $row = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT user_id, user_name FROM users WHERE user_id = $userId AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"));

        return $row ? ['kind' => 'agent', 'user_id' => (int) $row['user_id'], 'contact_id' => 0, 'client_id' => 0, 'via' => $via, 'name' => (string) $row['user_name']] : null;
    }

    private function contact(int $contactId, string $via): ?array
    {
        $row = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT contact_id, contact_name, contact_client_id FROM contacts WHERE contact_id = $contactId AND contact_archived_at IS NULL AND contact_client_id > 0"));

        return $row ? ['kind' => 'contact', 'user_id' => 0, 'contact_id' => (int) $row['contact_id'], 'client_id' => (int) $row['contact_client_id'], 'via' => $via, 'name' => (string) $row['contact_name']] : null;
    }

    // ----- explicit links: admin side --------------------------------------------------------------------------------

    /**
     * Create or replace an explicit link. Exactly one of $userId / $contactId. The target must exist and be usable.
     *
     * @return array{ok:bool,error:?string}
     */
    public function link(string $platform, string $teamId, string $externalId, int $userId, int $contactId, int $byUserId): array
    {
        $externalId = trim($externalId);
        $teamId = trim($teamId);
        if (!in_array($platform, [self::SLACK, self::TEAMS], true)) {
            return ['ok' => false, 'error' => 'Unknown platform.'];
        }
        if ($platform === self::SLACK && !preg_match('/^[UW][A-Z0-9]{4,20}$/', $externalId)) {
            return ['ok' => false, 'error' => 'A Slack member ID looks like U01ABCDE23 (Profile > three dots > Copy member ID).'];
        }
        if ($platform === self::TEAMS && !preg_match('/^[0-9a-fA-F-]{36}$/', $externalId)) {
            return ['ok' => false, 'error' => 'A Teams user is identified by their Microsoft Entra object ID (a GUID).'];
        }
        if ($teamId !== '' && !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $teamId)) {
            return ['ok' => false, 'error' => 'The workspace / tenant ID is not valid.'];
        }
        if (($userId > 0) === ($contactId > 0)) {
            return ['ok' => false, 'error' => 'Choose exactly one: an agent or a contact.'];
        }
        if ($userId > 0 && $this->agent($userId, 'explicit') === null) {
            return ['ok' => false, 'error' => 'That agent does not exist or is disabled.'];
        }
        if ($contactId > 0 && $this->contact($contactId, 'explicit') === null) {
            return ['ok' => false, 'error' => 'That contact does not exist or is archived.'];
        }
        $externalId = $platform === self::TEAMS ? strtolower($externalId) : $externalId;
        $stmt = mysqli_prepare($this->db, 'INSERT INTO chat_identities (identity_platform, identity_team_id, identity_external_id, identity_user_id, identity_contact_id, identity_created_by) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE identity_user_id = VALUES(identity_user_id), identity_contact_id = VALUES(identity_contact_id), identity_created_by = VALUES(identity_created_by), identity_created_at = NOW()');
        mysqli_stmt_bind_param($stmt, 'sssiii', $platform, $teamId, $externalId, $userId, $contactId, $byUserId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'The link could not be saved.'];
    }

    public function unlink(int $identityId): bool
    {
        $stmt = mysqli_prepare($this->db, 'DELETE FROM chat_identities WHERE identity_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $identityId);
        mysqli_stmt_execute($stmt);
        $n = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        return $n === 1;
    }

    /** @return list<array<string,mixed>> */
    public function listLinks(): array
    {
        $rows = [];
        $res = mysqli_query($this->db, 'SELECT i.*, u.user_name, c.contact_name FROM chat_identities i LEFT JOIN users u ON u.user_id = i.identity_user_id LEFT JOIN contacts c ON c.contact_id = i.identity_contact_id ORDER BY i.identity_platform, i.identity_id');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $rows[] = $r;
        }

        return $rows;
    }
}
