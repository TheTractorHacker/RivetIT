<?php

namespace ITFlow\Mcp;

use RivetCore\Support\ArraySettings;

/**
 * Remote MCP settings. The administrator configures them in Administration > Remote MCP (module switch,
 * issuer and audience live in `settings`). The RIVETIT_MCP_ISSUER / RIVETIT_MCP_AUDIENCE environment
 * variables still win when set, and RIVETIT_MCP_ENABLED=0 is a hard off switch that no setting can undo.
 *
 * Edition adapter (not deprecated): the resolution logic now lives in RivetCore\Mcp\McpConfig; this reads the `settings`
 * row and maps its columns onto Core's setting keys.
 */
final class McpConfig
{
    public static function issuerValid(string $issuer): bool
    {
        return \RivetCore\Mcp\McpConfig::issuerValid($issuer);
    }

    public static function audienceValid(string $audience): bool
    {
        return \RivetCore\Mcp\McpConfig::audienceValid($audience);
    }

    /** @return array{schema_ready:bool, module_on:bool, killed:bool, enabled:bool, issuer:string, audience:string, issuer_from_env:bool, audience_from_env:bool, configured:bool} */
    public static function load(\mysqli $db): array
    {
        $row = [];
        try {
            $res = $db->query('SELECT * FROM settings WHERE company_id = 1');
            $row = $res ? ($res->fetch_assoc() ?: []) : [];
        } catch (\Throwable) { /* older schema: treated as not configured */ }

        $settings = new ArraySettings(array_filter([
            'mcp.issuer' => $row['config_mcp_issuer'] ?? null,
            'mcp.audience' => $row['config_mcp_audience'] ?? null,
            'mcp.enabled' => $row['config_module_enable_mcp'] ?? null,
        ], static fn ($v) => $v !== null));

        return \RivetCore\Mcp\McpConfig::resolve($settings, static fn (string $k) => getenv($k), 'RIVETIT_MCP_');
    }
}
