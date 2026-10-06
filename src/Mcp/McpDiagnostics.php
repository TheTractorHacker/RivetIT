<?php

namespace ITFlow\Mcp;

use GuzzleHttp\ClientInterface;
use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use ITFlow\Core\Adapter\Mcp\UsersAgentDirectory;

/**
 * "Is this actually going to work?" checks for Administration > Remote MCP. Each check returns
 * status ok|warn|fail|skip, a short label, and a plain-language detail with the fix. Network access goes
 * through an injected Guzzle client so the checks can be tested without a real identity provider.
 *
 * Edition adapter (not deprecated) over RivetCore\Mcp\McpDiagnostics; the linked-agent count comes from RivetIT's users.
 */
final class McpDiagnostics
{
    private \RivetCore\Mcp\McpDiagnostics $core;

    public function __construct(\mysqli $db, ClientInterface $http)
    {
        $this->core = new \RivetCore\Mcp\McpDiagnostics(
            new UsersAgentDirectory(new MysqliDatabaseAdapter($db)), $http, 'RIVETIT_MCP_ENABLED', 'RivetIT'
        );
    }

    /** @return list<array{status:string,label:string,detail:string}> */
    public function run(array $cfg, string $baseHost): array
    {
        return $this->core->run($cfg, $baseHost);
    }
}
