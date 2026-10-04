<?php

namespace ITFlow\Mcp;

/**
 * Remote MCP settings. The administrator configures them in Administration > Remote MCP (module switch,
 * issuer and audience live in `settings`). The RIVETIT_MCP_ISSUER / RIVETIT_MCP_AUDIENCE environment
 * variables still win when set, and RIVETIT_MCP_ENABLED=0 is a hard off switch that no setting can undo.
 */
final class McpConfig
{
    public static function issuerValid(string $issuer): bool
    {
        $p = parse_url($issuer);
        return strlen($issuer) <= 255 && is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
    }

    public static function audienceValid(string $audience): bool
    {
        return $audience !== '' && strlen($audience) <= 255 && !preg_match('/[\x00-\x20]/', $audience);
    }

    /** @return array{schema_ready:bool, module_on:bool, killed:bool, enabled:bool, issuer:string, audience:string, issuer_from_env:bool, audience_from_env:bool, configured:bool} */
    public static function load(\mysqli $db): array
    {
        $row = [];
        try {
            $res = $db->query('SELECT * FROM settings WHERE company_id = 1');
            $row = $res ? ($res->fetch_assoc() ?: []) : [];
        } catch (\Throwable) { /* older schema: treated as not configured */ }

        $envIssuer = trim((string) getenv('RIVETIT_MCP_ISSUER'));
        $envAudience = trim((string) getenv('RIVETIT_MCP_AUDIENCE'));
        $issuer = $envIssuer !== '' ? $envIssuer : trim((string) ($row['config_mcp_issuer'] ?? ''));
        $audience = $envAudience !== '' ? $envAudience : trim((string) ($row['config_mcp_audience'] ?? ''));
        $moduleOn = (int) ($row['config_module_enable_mcp'] ?? 0) === 1;
        $killed = getenv('RIVETIT_MCP_ENABLED') === '0';

        return [
            'schema_ready' => array_key_exists('config_mcp_issuer', $row),
            'module_on' => $moduleOn,
            'killed' => $killed,
            'enabled' => $moduleOn && !$killed,
            'issuer' => $issuer,
            'audience' => $audience,
            'issuer_from_env' => $envIssuer !== '',
            'audience_from_env' => $envAudience !== '',
            'configured' => self::issuerValid($issuer) && self::audienceValid($audience),
        ];
    }
}
