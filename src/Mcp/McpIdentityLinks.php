<?php

namespace ITFlow\Mcp;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use ITFlow\Core\Adapter\Mcp\UsersAgentDirectory;
use RivetCore\Mcp\IdentityLinker;
use RivetCore\Mcp\UnlinkedIdentityStore;

/**
 * Links an OAuth identity (issuer + immutable subject) to one RivetIT agent. A person who signs in with a
 * valid token but is not linked yet is remembered here, so an administrator can pick the agent from a list
 * instead of copying subject ids by hand. Only tokens that already passed signature, issuer, audience, scope
 * and expiry checks are ever recorded, and linking stays an explicit administrator action.
 *
 * Edition adapter (not deprecated): the bookkeeping and linking now live in RivetCore\Mcp (UnlinkedIdentityStore,
 * IdentityLinker); the `users` queries stay in RivetIT (Core\Adapter\Mcp\UsersAgentDirectory).
 */
final class McpIdentityLinks
{
    private static function parts(\mysqli $db): array
    {
        $database = new MysqliDatabaseAdapter($db);
        $store = new UnlinkedIdentityStore($database);
        $agents = new UsersAgentDirectory($database);

        return [$store, $agents, new IdentityLinker($database, $store, $agents)];
    }

    /** Remember an unlinked but valid identity. Never throws: a failure here must not change the 403. */
    public static function recordUnlinked(\mysqli $db, string $issuer, string $subject, array $claims): void
    {
        try {
            self::parts($db)[0]->record($issuer, $subject, $claims);
        } catch (\Throwable $e) {
            error_log('MCP unlinked identity not recorded: ' . $e->getMessage());
        }
    }

    public static function pending(\mysqli $db): array
    {
        return self::parts($db)[0]->pending();
    }

    /** Active agents without a link yet, for the "link to" list. */
    public static function linkableAgents(\mysqli $db): array
    {
        return self::parts($db)[1]->linkableAgents();
    }

    public static function linkedAgents(\mysqli $db): array
    {
        return self::parts($db)[1]->linkedAgents();
    }

    /** @return array{0:bool,1:string} [ok, message] */
    public static function link(\mysqli $db, int $pendingId, int $userId): array
    {
        return self::parts($db)[2]->link($pendingId, $userId);
    }

    public static function dismiss(\mysqli $db, int $pendingId): void
    {
        self::parts($db)[0]->dismiss($pendingId);
    }

    public static function unlink(\mysqli $db, int $userId): void
    {
        self::parts($db)[1]->unlink($userId);
    }
}
