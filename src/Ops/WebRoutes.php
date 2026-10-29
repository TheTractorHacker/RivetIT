<?php

namespace ITFlow\Ops;

/**
 * The route registry: ONE list of every nginx `location` that deploy/templates/rivetit-locations.conf owns, in the order the
 * file writes them. Everything that needs to know what the web server must provide reads this list:
 *
 *   - deploy/lib/web_routes_tool.php (behind deploy/apply-web-routes.sh) uses the (match, path) of each entry to recognise
 *     which blocks of an older, hand-grown vhost the shared routes file now owns;
 *   - WebRouteCheck (Server status, MCP settings, Update page) requests each entry's probe through the site's public URL and
 *     reports the ones the web server does not answer correctly, naming the feature each breaks;
 *   - tests/web_routes_registry.php fails when this list and the template disagree, so they cannot drift apart;
 *   - docs/WEB_ROUTES.md describes the same entries.
 *
 * ADDING A ROUTE: add the `location` to deploy/templates/rivetit-locations.conf AND one entry here, in the same position
 * (the drift test checks the order). Give it a probe when a harmless request can tell "route present" from "route missing";
 * set 'probe' => null when it cannot (say why in 'purpose'). Then run tests/web_routes_registry.php.
 *
 * Probe fields: method (GET/HEAD only, nothing here may change state), path (string, or ['dir' => 'css', 'ext' => ['css']] to use
 * the first real file of that kind under the app root), status (accepted HTTP statuses), headers (name => text the value must
 * contain), absent (headers that must NOT be present: a header only PHP sets proves nginx did not answer), requires (a condition
 * from CONDITIONS; the probe is skipped when it is false). Probes ask for files that do not exist wherever they can, so a
 * missing "deny" shows up as 404 where the rule gives 403, and no probe ever executes application code beyond what a normal
 * visitor request does.
 *
 * severity: 'security' = a missing route exposes something (shown red), 'feature' = a feature stops working (amber).
 */
final class WebRoutes
{
    /** Where deploy/apply-web-routes.sh installs the file, and where the app looks for it (read only). */
    public const INSTALLED_PATH = '/etc/nginx/snippets/rivetit-locations.conf';
    public const TEMPLATE_PATH = 'deploy/templates/rivetit-locations.conf';
    public const APPLY_SCRIPT = 'deploy/apply-web-routes.sh';

    /** Conditions a probe can require; WebRouteCheck gets their current truth from the app (see WebRouteCheck::mcpContext). */
    public const CONDITIONS = [
        'mcp_ready' => 'Remote MCP is switched on and set up',
        'mcp_builtin' => 'Remote MCP built-in sign-in is on',
        'oidc_on' => 'The single sign-on provider (OpenID Connect for the Quality system) is switched on',
    ];

    /** A request that must succeed before any other probe means anything: PHP answers through the public address. */
    public const SENTINEL = ['method' => 'GET', 'path' => '/health/live.php', 'status' => [200]];

    private const NO_PROBE = null;

    /**
     * @return list<array{id:string, match:string, path:string, purpose:string, feature:string, severity:string, probe:?array<string,mixed>}>
     */
    public static function all(): array
    {
        $deny = static fn(string $path): array => ['method' => 'GET', 'path' => $path, 'status' => [403]];
        $e = static fn(string $id, string $match, string $path, string $purpose, string $feature, string $severity, ?array $probe): array
            => ['id' => $id, 'match' => $match, 'path' => $path, 'purpose' => $purpose, 'feature' => $feature, 'severity' => $severity, 'probe' => $probe];

        return [
            $e('vcs_config', '~', '^/(\.git|\.github|config\.php)', 'Hide .git, .github and config.php (answers 404).', 'Basic site security', 'security',
                ['method' => 'GET', 'path' => '/config.php', 'status' => [404]]),
            $e('acme', '^~', '/.well-known/', 'Keep /.well-known/ reachable for certificate renewal (ACME) while dotfiles stay denied.', 'Certificate renewal (certbot)', 'feature',
                ['method' => 'GET', 'path' => '/.well-known/acme-challenge/rivetit-route-check', 'status' => [404]]),
            $e('dotfiles', '~', '/\.', 'Refuse every dotfile (.env, .gitignore, ...).', 'Basic site security', 'security', $deny('/.rivetit-route-check')),
            $e('restore_dir', '^~', '/restore/', 'Refuse the folder where the Docker flow drops an encrypted backup.', 'Basic site security', 'security', $deny('/restore/rivetit-route-check')),
            $e('tooling_dirs', '~', '^/(deploy|docker|docs)/', 'Refuse the deployment tooling and docs shipped in the checkout.', 'Basic site security', 'security', $deny('/docs/rivetit-route-check')),
            $e('private_trees', '~', '^/(tests|vendor|src|includes|odoo_addons|mcp_server|setup/setup_functions\.php)', 'Refuse non-public source trees (pentest F-02).', 'Basic site security', 'security', $deny('/src/rivetit-route-check')),
            $e('root_metadata', '~*', '^/[^/]+\.(md|lock|sql|yml|yaml)$', 'Refuse README.md, composer.lock, *.sql, *.yml at the web root.', 'Basic site security', 'security', $deny('/rivetit-route-check.md')),
            $e('composer_json', '=', '/composer.json', 'Refuse composer.json.', 'Basic site security', 'security', $deny('/composer.json')),
            $e('uploads_scripts', '~', '^/uploads/.*\.(php|phtml|phar|phps|cgi|pl|sh)$', 'Never run an uploaded file as a script.', 'Basic site security', 'security', $deny('/uploads/rivetit-route-check.php')),
            $e('uploads_kb', '^~', '/uploads/kb/', 'Knowledge-base media is only served through the authenticated endpoints, never directly.', 'Knowledge base', 'security', $deny('/uploads/kb/rivetit-route-check')),
            $e('uploads_training', '^~', '/uploads/training/', 'Training media: internal only, reached through X-Accel-Redirect after the app authorizes the request (a harmless request cannot tell it apart: both answer 404).', 'Training media', 'security', self::NO_PROBE),
            $e('kiosk_entry', '=', '/kiosk/', 'Answer the kiosk start URLs /kiosk/?d= and ?e= with a no-log redirect so device and fleet tokens never reach the access log or PHP.', 'Training kiosk (device and fleet links)', 'feature',
                ['method' => 'GET', 'path' => '/kiosk/?e=rvkf1_' . str_repeat('A', 43), 'status' => [302], 'absent' => ['Cross-Origin-Opener-Policy']]),
            $e('kiosk_includes', '^~', '/kiosk/includes/', 'Refuse the kiosk include files.', 'Training kiosk', 'security', $deny('/kiosk/includes/rivetit-route-check.txt')),
            $e('kiosk_php', '~', '^/kiosk/.*\.php$', 'Kiosk PHP pages (they send their own CSP and Referrer-Policy).', 'Training kiosk', 'feature', self::NO_PROBE),
            $e('uploads_prefix', '^~', '/uploads/', 'Force uploaded files to download instead of rendering (Content-Disposition) and keep scripts denied.', 'Basic site security', 'security',
                ['method' => 'GET', 'path' => '/uploads/rivetit-route-check.txt', 'status' => [404], 'headers' => ['Content-Disposition' => 'attachment']]),
            $e('cron_scripts', '~', '^/(cron|scripts)/.*\.php$', 'Cron and CLI scripts never run over HTTP.', 'Basic site security', 'security', $deny('/scripts/rivetit-route-check.php')),
            $e('backups_dir', '^~', '/backups/', 'Refuse the backups folder.', 'Basic site security', 'security', $deny('/backups/rivetit-route-check')),
            $e('config_dir', '^~', '/config/', 'Refuse the config folder.', 'Basic site security', 'security', $deny('/config/rivetit-route-check')),
            $e('tcpdf_tools', '^~', '/plugins/TCPDF/tools/', 'Refuse the TCPDF tools folder.', 'Basic site security', 'security', $deny('/plugins/TCPDF/tools/rivetit-route-check')),
            $e('api_v1_leaf_scripts', '~', '^/api/v1/.+/.+\.php$', 'The legacy per-resource API scripts must not be reachable; only the router is (pentest).', 'API', 'security', $deny('/api/v1/rivetit/route-check.php')),
            $e('api_v1', '', '/api/v1/', 'API v1 clean URLs go to the front controller.', 'API', 'feature', self::NO_PROBE),
            $e('api_v2', '', '/api/v2/', 'API v2 clean URLs go to the front controller.', 'API', 'feature', self::NO_PROBE),
            $e('htaccess', '~', '/\.ht', 'Never serve .htaccess and friends.', 'Basic site security', 'security', self::NO_PROBE),
            $e('login_rate_limit', '~', '^/(login|setup)\.php$', 'Rate-limit the two password entry points (needs the itflow_login zone).', 'Sign-in brute-force protection', 'feature', self::NO_PROBE),
            $e('sse_stream', '~', '/sse_ticket_stream\.php$', 'Live ticket updates: no response buffering.', 'Live ticket updates (SSE)', 'feature', self::NO_PROBE),
            $e('api_v1_index', '=', '/api/v1/index.php', 'API front controller, unbuffered (streaming endpoints).', 'API', 'feature', self::NO_PROBE),
            $e('api_docs', '=', '/api/v1/docs', 'The API documentation page, framed by the Admin page (X-Frame-Options SAMEORIGIN).', 'API documentation', 'feature',
                ['method' => 'GET', 'path' => '/api/v1/docs', 'status' => [200], 'headers' => ['X-Frame-Options' => 'SAMEORIGIN']]),
            $e('odoo_callback', '=', '/client/login_odoo.php', 'The Odoo sign-in callback carries a one-time code, so it is never logged.', 'Odoo sign-in', 'feature', self::NO_PROBE),
            $e('mcp', '=', '/mcp', 'Remote MCP endpoint (Streamable HTTP).', 'Remote MCP', 'feature',
                ['method' => 'GET', 'path' => '/mcp', 'status' => [401], 'requires' => 'mcp_ready']),
            $e('mcp_resource_metadata', '=', '/.well-known/oauth-protected-resource', 'Remote MCP: OAuth protected-resource metadata clients read first.', 'Remote MCP', 'feature',
                ['method' => 'GET', 'path' => '/.well-known/oauth-protected-resource', 'status' => [200], 'requires' => 'mcp_ready']),
            $e('mcp_authserver_metadata', '=', '/.well-known/oauth-authorization-server', 'Remote MCP built-in sign-in: authorization-server metadata (RFC 8414). Claude cannot connect without it.', 'Remote MCP built-in sign-in', 'feature',
                ['method' => 'GET', 'path' => '/.well-known/oauth-authorization-server', 'status' => [200], 'headers' => ['Content-Type' => 'json'], 'requires' => 'mcp_builtin']),
            $e('oauth_token_endpoints', '~', '^/oauth/(token|register|revoke)\.php$', 'Remote MCP built-in sign-in: token endpoints, never cached anywhere (Cache-Control no-store, Pragma no-cache).', 'Remote MCP built-in sign-in', 'feature',
                ['method' => 'GET', 'path' => '/oauth/token.php', 'status' => [400, 401, 403, 404, 405], 'headers' => ['Pragma' => 'no-cache']]),
            $e('oauth_authorize', '=', '/oauth/authorize.php', 'Remote MCP built-in sign-in: the consent screen (framing denied, never cached).', 'Remote MCP built-in sign-in', 'feature',
                ['method' => 'GET', 'path' => '/oauth/authorize.php', 'status' => [200, 302, 400, 401, 403, 404], 'headers' => ['Pragma' => 'no-cache']]),
            $e('oidc_discovery', '=', '/.well-known/openid-configuration', 'Single sign-on provider (OpenID Connect for the Quality system): the discovery document the Quality system reads first. 404 until the provider is switched on.', 'Single sign-on to Quality', 'feature',
                ['method' => 'GET', 'path' => '/.well-known/openid-configuration', 'status' => [200], 'headers' => ['Content-Type' => 'json'], 'requires' => 'oidc_on']),
            $e('oidc_endpoints', '^~', '/oidc/', 'Single sign-on provider: jwks, authorize, token and userinfo all go to one PHP front controller (no .php file under /oidc/ is reachable directly), with framing denied.', 'Single sign-on to Quality', 'feature',
                ['method' => 'GET', 'path' => '/oidc/token', 'status' => [404, 405], 'headers' => ['Pragma' => 'no-cache']]),
            $e('training_video_frame', '=', '/agent/training_video_frame.php', 'The one agent page the app frames itself (training video check).', 'Training videos', 'feature', self::NO_PROBE),
            $e('php', '~', '\.php$', 'Run PHP pages (and answer the /health endpoints).', 'The application itself', 'security',
                ['method' => 'GET', 'path' => '/health/live.php', 'status' => [200]]),
            $e('static_plugins', '^~', '/plugins/', 'Cache vendor libraries; run only the two plugin scripts that are fetched directly, refuse other plugin PHP.', 'Page speed and the portal TOTP QR code', 'feature',
                ['method' => 'GET', 'path' => ['dir' => 'plugins', 'ext' => ['css', 'js']], 'status' => [200], 'headers' => ['Cache-Control' => 'immutable']]),
            $e('static_fonts', '^~', '/fonts/', 'Cache fonts for a long time.', 'Page speed', 'feature',
                ['method' => 'GET', 'path' => ['dir' => 'fonts', 'ext' => ['woff2', 'woff', 'ttf']], 'status' => [200], 'headers' => ['Cache-Control' => 'immutable']]),
            $e('static_css', '^~', '/css/', 'Cache stylesheets for a long time (their URLs carry ?v=).', 'Page speed', 'feature',
                ['method' => 'GET', 'path' => ['dir' => 'css', 'ext' => ['css']], 'status' => [200], 'headers' => ['Cache-Control' => 'immutable']]),
            $e('static_js', '^~', '/js/', 'Cache scripts for an hour.', 'Page speed', 'feature',
                ['method' => 'GET', 'path' => ['dir' => 'js', 'ext' => ['js']], 'status' => [200], 'headers' => ['Cache-Control' => 'max-age=3600']]),
            $e('default', '', '/', 'Everything else: a file, a folder index, or 404.', 'The application itself', 'feature', self::NO_PROBE),
        ];
    }

    /** "= /mcp", "^~ /uploads/", "~ \.php$", "/" : the same canonical form NginxConf::locationKey() gives a parsed block. */
    public static function key(array $entry): string
    {
        return $entry['match'] === '' ? $entry['path'] : $entry['match'] . ' ' . $entry['path'];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map([self::class, 'key'], self::all());
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $e) {
            if ($e['id'] === $id) { return $e; }
        }
        return null;
    }

    /** Stable fingerprint of the registry, used to invalidate cached self-check results when it changes. */
    public static function fingerprint(): string
    {
        return substr(hash('sha256', json_encode(self::all())), 0, 16);
    }

    /** The exact command an administrator runs as root to bring the web server's routes up to date. */
    public static function applyCommand(string $appRoot, string $domain): string
    {
        $q = static fn(string $v) => preg_match('~^[A-Za-z0-9_./=:-]+$~', $v) ? $v : escapeshellarg($v);
        return 'sudo ' . $q(rtrim($appRoot, '/') . '/' . self::APPLY_SCRIPT) . ' --domain ' . $q($domain);
    }
}
