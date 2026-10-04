<?php

namespace ITFlow\Mcp;

/**
 * Links an OAuth identity (issuer + immutable subject) to one RivetIT agent. A person who signs in with a
 * valid token but is not linked yet is remembered here, so an administrator can pick the agent from a list
 * instead of copying subject ids by hand. Only tokens that already passed signature, issuer, audience, scope
 * and expiry checks are ever recorded, and linking stays an explicit administrator action.
 */
final class McpIdentityLinks
{
    private const MAX_PENDING = 200;
    private const KEEP_DAYS = 30;

    private static function clean(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, 200);
    }

    /** Remember an unlinked but valid identity. Never throws: a failure here must not change the 403. */
    public static function recordUnlinked(\mysqli $db, string $issuer, string $subject, array $claims): void
    {
        try {
            $email = self::clean($claims['email'] ?? null);
            $name = self::clean($claims['name'] ?? ($claims['preferred_username'] ?? null));
            $exists = $db->prepare('SELECT 1 FROM mcp_unlinked_identities WHERE issuer = ? AND subject = ?');
            $exists->bind_param('ss', $issuer, $subject);
            $exists->execute();
            if (!$exists->get_result()->fetch_row()) {
                $count = (int) $db->query('SELECT COUNT(*) FROM mcp_unlinked_identities')->fetch_row()[0];
                if ($count >= self::MAX_PENDING) return;
            }
            $stmt = $db->prepare('INSERT INTO mcp_unlinked_identities (issuer, subject, email, display_name)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_seen_at = NOW(),
                    email = COALESCE(VALUES(email), email), display_name = COALESCE(VALUES(display_name), display_name)');
            $stmt->bind_param('ssss', $issuer, $subject, $email, $name);
            $stmt->execute();
        } catch (\Throwable $e) {
            error_log('MCP unlinked identity not recorded: ' . $e->getMessage());
        }
    }

    public static function pending(\mysqli $db): array
    {
        $days = self::KEEP_DAYS;
        $db->query("DELETE FROM mcp_unlinked_identities WHERE last_seen_at < NOW() - INTERVAL $days DAY");
        return $db->query('SELECT * FROM mcp_unlinked_identities ORDER BY last_seen_at DESC LIMIT ' . self::MAX_PENDING)->fetch_all(MYSQLI_ASSOC);
    }

    /** Active agents without a link yet, for the "link to" list. */
    public static function linkableAgents(\mysqli $db): array
    {
        return $db->query("SELECT user_id, user_name, user_email FROM users
            WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
              AND (user_oidc_subject IS NULL OR user_oidc_subject = '')
            ORDER BY user_name")->fetch_all(MYSQLI_ASSOC);
    }

    public static function linkedAgents(\mysqli $db): array
    {
        return $db->query("SELECT user_id, user_name, user_email, user_oidc_issuer, user_oidc_subject, user_status, user_archived_at
            FROM users WHERE user_type = 1 AND user_oidc_subject IS NOT NULL AND user_oidc_subject <> ''
            ORDER BY user_name")->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array{0:bool,1:string} [ok, message] */
    public static function link(\mysqli $db, int $pendingId, int $userId): array
    {
        $db->begin_transaction();
        try {
            $p = $db->prepare('SELECT issuer, subject FROM mcp_unlinked_identities WHERE mcp_unlinked_id = ? FOR UPDATE');
            $p->bind_param('i', $pendingId);
            $p->execute();
            $pending = $p->get_result()->fetch_assoc();
            if (!$pending) { $db->rollback(); return [false, 'That sign-in is no longer waiting. Ask the person to try again.']; }

            $u = $db->prepare('SELECT user_oidc_subject FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1
                AND user_archived_at IS NULL FOR UPDATE');
            $u->bind_param('i', $userId);
            $u->execute();
            $user = $u->get_result()->fetch_assoc();
            if (!$user) { $db->rollback(); return [false, 'Choose an active agent.']; }
            if (($user['user_oidc_subject'] ?? '') !== '') { $db->rollback(); return [false, 'That agent is already linked. Unlink them first.']; }

            $d = $db->prepare('SELECT 1 FROM users WHERE user_oidc_issuer = ? AND user_oidc_subject = ? LIMIT 1');
            $d->bind_param('ss', $pending['issuer'], $pending['subject']);
            $d->execute();
            if ($d->get_result()->fetch_row()) { $db->rollback(); return [false, 'This identity is already linked to another account.']; }

            $up = $db->prepare('UPDATE users SET user_oidc_issuer = ?, user_oidc_subject = ? WHERE user_id = ? AND user_type = 1');
            $up->bind_param('ssi', $pending['issuer'], $pending['subject'], $userId);
            $up->execute();
            $del = $db->prepare('DELETE FROM mcp_unlinked_identities WHERE mcp_unlinked_id = ?');
            $del->bind_param('i', $pendingId);
            $del->execute();
            $db->commit();
            return [true, 'Linked.'];
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('MCP link failed: ' . $e->getMessage());
            return [false, 'Could not link. Nothing was changed.'];
        }
    }

    public static function dismiss(\mysqli $db, int $pendingId): void
    {
        $s = $db->prepare('DELETE FROM mcp_unlinked_identities WHERE mcp_unlinked_id = ?');
        $s->bind_param('i', $pendingId);
        $s->execute();
    }

    public static function unlink(\mysqli $db, int $userId): void
    {
        $s = $db->prepare('UPDATE users SET user_oidc_issuer = NULL, user_oidc_subject = NULL WHERE user_id = ? AND user_type = 1');
        $s->bind_param('i', $userId);
        $s->execute();
    }
}
