<?php

namespace ITFlow\EndpointAgent;

/**
 * MeshCentral remote access.
 *
 * AUTH MODEL. MeshCentral supports "login tokens" for embedding it in another application (docs.meshcentral.com/meshcentral/tokens):
 * the server holds a single loginTokenKey (`node node_modules/meshcentral --loginTokenKey`, regenerate with `--loginTokenGen`); an
 * integrator builds a token for a MeshCentral user from that key and opens `https://<server>/?login=<token>`. The token is
 * AES-256-GCM over {"u": <userid>, "a": 3, "time": <unix seconds>} using the first 32 bytes of the key (hex-decoded), packed as
 * IV(12) || GCM tag(16) || ciphertext, base64 with "+" -> "@" and "/" -> "$". MeshCentral accepts it for its own configured
 * lifetime (default about an hour), so we mint it only at the moment of the click and never store or log it.
 *
 * Because that key can sign in as ANY MeshCentral user it is a high-value secret: it is stored encrypted (encryptSetting) and is
 * used only to impersonate ONE limited MeshCentral account chosen by the admin (default "rivetit-support"), which should only be a
 * member of the device group(s) the technicians may control. No shared administrator credential is ever used or shown.
 *
 * This implementation could not be exercised against a real MeshCentral server (see docs/ENDPOINT_AGENT.md, "What is verified").
 */
final class Mesh
{
    public static function validNodeId(string $id): bool
    {
        return (bool) preg_match('#^node//[A-Za-z0-9@$_-]{16,100}$#', $id);
    }

    /** Validates and normalises the MeshCentral base URL: https (http only when the test constant allows), no userinfo/query. */
    public static function normalizeUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $p = parse_url($url);
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme !== 'https' && !($scheme === 'http' && defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true)) {
            return null;
        }
        if (strlen($url) > 500 || preg_match('/[\x00-\x20"<>\\\\^`{|}]/', $url)) {
            return null;
        }
        return $url;
    }

    public static function newLoginKey(): string
    {
        return bin2hex(random_bytes(80));
    }

    /** MeshCentral's own cookie format. $keyHex is the loginTokenKey as hex. */
    public static function encodeCookie(array $payload, string $keyHex, ?string $iv = null): ?string
    {
        $key = hex2bin($keyHex);
        if ($key === false || strlen($key) < 32) {
            return null;
        }
        $key = substr($key, 0, 32);
        $iv = $iv ?? random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt(json_encode($payload), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            return null;
        }
        return strtr(base64_encode($iv . $tag . $ct), '+/', '@$');
    }

    /** Test helper and sanity check: the inverse of encodeCookie(). */
    public static function decodeCookie(string $cookie, string $keyHex): ?array
    {
        $key = hex2bin($keyHex);
        $raw = base64_decode(strtr($cookie, '@$', '+/'), true);
        if ($key === false || $raw === false || strlen($raw) < 29) {
            return null;
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', substr($key, 0, 32), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? null : json_decode($pt, true);
    }

    /**
     * Build a launch for one device. All preconditions are enforced HERE (not in the callers) so the web handler and the API cannot
     * diverge.
     *
     * @return array{ok:bool,code:string,message:string,url?:string,session_id?:string}
     */
    public static function launch(array $dev, string $username, int $userId, bool $force = false): array
    {
        $cfg = Config::get();
        if (!(int) $cfg['mesh_enabled'] || $cfg['mesh_url'] === '' || empty($cfg['mesh_login_key_enc'])) {
            return self::fail('not_configured', 'Remote access through MeshCentral is not configured.');
        }
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            return self::fail('device_retired', 'This device is retired or revoked.');
        }
        $node = Db::one('SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$dev['device_id']]);
        if (!$node) {
            return self::fail('unmapped', 'This device is not mapped to a MeshCentral node yet. An administrator can map it on the device page.');
        }
        $st = Devices::status($dev, $cfg);
        if ($st['state'] !== 'online' && !$force) {
            return self::fail('device_offline', 'The device has not checked in since ' . ($st['last_checkin_at'] ?? 'it was enrolled') . '. It looks offline; try again later or launch anyway.');
        }
        $base = self::normalizeUrl((string) $cfg['mesh_url']);
        if ($base === null) {
            return self::fail('not_configured', 'The MeshCentral address is not valid.');
        }
        $health = self::probe($base);
        if ($health !== null) {
            return self::fail('mesh_unavailable', $health);
        }
        $keyHex = decryptSetting((string) $cfg['mesh_login_key_enc']);
        $domain = (string) $cfg['mesh_domain'];
        $account = str_replace(['{user_id}', '{username}'], [(string) $userId, preg_replace('/[^A-Za-z0-9._-]/', '', $username)], (string) $cfg['mesh_account_template']);
        $account = preg_replace('/[^A-Za-z0-9._-]/', '', $account);
        if ($keyHex === '' || $account === '') {
            return self::fail('not_configured', 'The MeshCentral login key or account is missing.');
        }
        $uid = 'user/' . $domain . '/' . $account;
        $cookie = self::encodeCookie(['u' => $uid, 'a' => 3, 'time' => time() - 120], $keyHex);
        if ($cookie === null) {
            return self::fail('not_configured', 'The MeshCentral login key is not usable.');
        }
        // viewmode 11 = Desktop, hide 31 = only the desktop (attended policy keeps the user-consent prompt on the endpoint side).
        $path = ($domain !== '' ? '/' . rawurlencode($domain) : '') . '/';
        $url = $base . $path . '?login=' . rawurlencode($cookie) . '&gotonode=' . rawurlencode($node['mesh_node_id']) . '&viewmode=11';
        return ['ok' => true, 'code' => 'ok', 'message' => 'Opening the remote session.', 'url' => $url, 'session_id' => bin2hex(random_bytes(8))];
    }

    private static function fail(string $code, string $msg): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $msg];
    }

    /** Reachability probe: GET <base>/health.ashx with pinned DNS (SSRF policy), short timeout, no redirects. Returns an error text or null. */
    public static function probe(string $base): ?string
    {
        $target = null;
        $isHttpTest = defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true;
        require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
        try {
            $target = \rivetWebhookUrlPolicy()->vet($base);
        } catch (\Throwable $e) {
            $target = null;
        }
        if ($target === null) {
            return 'The MeshCentral address is not allowed by the network policy (see Administration > Webhooks > Internal network access).';
        }
        $ch = curl_init($base . '/health.ashx');
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $isHttpTest ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if (!empty($target['ips'])) {
            $opts[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $target['ips'][0]];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 400) {
            return null;
        }
        return 'MeshCentral did not answer (timeout or error). Try again in a moment; if it keeps failing, check the MeshCentral server.';
    }
}
