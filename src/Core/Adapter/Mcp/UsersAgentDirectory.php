<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Mcp;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Mcp\AgentDirectoryInterface;

/**
 * RivetIT's agents: rows of `users` with user_type = 1. RivetCore links OAuth identities to agents through this
 * class and never queries `users` itself. Queries run on the same connection as the IdentityLinker's
 * transaction, so findActiveAgent() can lock the row (FOR UPDATE).
 */
final class UsersAgentDirectory implements AgentDirectoryInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function linkableAgents(): array
    {
        return $this->database->fetchAll(
            "SELECT user_id, user_name, user_email FROM users
             WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
               AND (user_oidc_subject IS NULL OR user_oidc_subject = '')
             ORDER BY user_name"
        );
    }

    public function linkedAgents(): array
    {
        return $this->database->fetchAll(
            "SELECT user_id, user_name, user_email, user_oidc_issuer, user_oidc_subject, user_status, user_archived_at
             FROM users WHERE user_type = 1 AND user_oidc_subject IS NOT NULL AND user_oidc_subject <> ''
             ORDER BY user_name"
        );
    }

    public function linkedCount(): int
    {
        return (int) ($this->database->fetchOne(
            "SELECT COUNT(*) AS c FROM users WHERE user_type = 1 AND user_oidc_subject IS NOT NULL AND user_oidc_subject <> ''"
        )['c'] ?? 0);
    }

    public function findActiveAgent(int $userId): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT user_oidc_subject FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1
               AND user_archived_at IS NULL FOR UPDATE',
            [$userId]
        );

        return $row === null ? null : ['linked' => ($row['user_oidc_subject'] ?? '') !== ''];
    }

    public function identityTaken(string $issuer, string $subject): bool
    {
        return $this->database->fetchOne(
            'SELECT 1 AS x FROM users WHERE user_oidc_issuer = ? AND user_oidc_subject = ? LIMIT 1',
            [$issuer, $subject]
        ) !== null;
    }

    public function link(int $userId, string $issuer, string $subject): void
    {
        $this->database->execute(
            'UPDATE users SET user_oidc_issuer = ?, user_oidc_subject = ? WHERE user_id = ? AND user_type = 1',
            [$issuer, $subject, $userId]
        );
    }

    public function unlink(int $userId): void
    {
        $this->database->execute(
            'UPDATE users SET user_oidc_issuer = NULL, user_oidc_subject = NULL WHERE user_id = ? AND user_type = 1',
            [$userId]
        );
    }
}
