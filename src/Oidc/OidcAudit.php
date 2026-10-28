<?php

namespace ITFlow\Oidc;

use ITFlow\Integration\IntegrationAuth;

/**
 * Audit trail and throttling for the SSO provider.
 *
 * Every issue and every refusal is one row in oidc_events (the list on Administration > Single sign-on provider) and one line in the
 * general audit log. Rows carry reason CODES and ids - never a code, token, secret, client-supplied URL, state or nonce. The user agent and
 * the free-text detail are length-capped and always bound as parameters; the admin page escapes them on output.
 *
 * Rate limiting is two-layered like the integration token endpoint: the shared Redis limiter (fails open when Redis is down, it is an abuse
 * guard) AND counters read from oidc_events, which keep working without Redis, so taking Redis away does not lift the throttle on the
 * credential-guessing endpoint (token).
 */
final class OidcAudit
{
    public static function record(\mysqli $db, string $type, string $result, string $reason, int $clientId = 0, int $contactId = 0, string $detail = '', string $ip = ''): void
    {
        $type = substr($type, 0, 30);
        $result = substr($result, 0, 10);
        $reason = substr(preg_replace('/[^a-z0-9_.:-]/', '_', strtolower($reason)) ?? '', 0, 60);
        $detail = substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $detail) ?? '', 0, 200);
        $ip = substr($ip !== '' ? $ip : self::ip(), 0, 45);
        try {
            $st = $db->prepare('INSERT INTO oidc_events (oev_type, oev_result, oev_reason, oev_client_id, oev_contact_id, oev_ip, oev_detail) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $st->bind_param('sssiiss', $type, $result, $reason, $clientId, $contactId, $ip, $detail);
            $st->execute();
            $st->close();
            if (random_int(1, 200) === 1) {
                $db->query('DELETE FROM oidc_events WHERE oev_at < NOW() - INTERVAL 180 DAY');
            }
        } catch (\Throwable $e) {
            error_log('OIDC audit failed: ' . $e::class);
        }
        IntegrationAuth::audit($db, 'SSO Provider', ucfirst($result) . ': ' . $type, "$type $result ($reason)" . ($contactId > 0 ? " contact #$contactId" : '') . ($detail !== '' ? " - $detail" : ''), $clientId, $ip);
    }

    public static function ip(): string
    {
        $ip = function_exists('getIP') ? getIP() : ($_SERVER['REMOTE_ADDR'] ?? '');
        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : 'unknown';
    }

    /**
     * True when the request may proceed. $endpoint: 'authorize' | 'token' | 'userinfo' | 'public'. Redis counts every request per address
     * network (and per address+client on the token endpoint). The database layer counts, for THIS endpoint only, the outcomes already recorded
     * for the address in the last minute (all outcomes) and the refusals in the last 10 minutes - per address, and per client on the token
     * endpoint - so the guess-limiting does not depend on Redis, and a person's mistakes at authorize never lock the QMS server out of token.
     * The caps are generous for successful traffic (one office address, or the QMS server's single address, carries many people) and tight for
     * refusals, which is what a credential-guessing attacker produces.
     */
    public static function allow(\mysqli $db, string $endpoint, int $redisLimit, ?int $clientId = null): bool
    {
        $ip = self::ip();
        $net = \ITFlow\Mcp\OAuth\OAuthHttp::netKey($ip);
        if (function_exists('rivetRateLimit')) {
            if (!rivetRateLimit('oidc:' . $endpoint . ':ip:' . $net, $redisLimit, 60)['allowed']) {
                header('Retry-After: 60');
                return false;
            }
            if ($clientId !== null && !rivetRateLimit('oidc:' . $endpoint . ':cl:' . substr(hash('sha256', $clientId . '|' . $net), 0, 24), max(5, intdiv($redisLimit, 2)), 60)['allowed']) {
                header('Retry-After: 60');
                return false;
            }
        }
        $types = match ($endpoint) {
            'token' => ['token', 'code_replay'],
            'authorize' => ['authorize'],
            'userinfo' => ['userinfo'],
            default => [],
        };
        if ($types === []) {
            return true;
        }
        $in = implode(',', array_fill(0, count($types), '?'));
        $cid = $clientId ?? 0;
        // On the token endpoint only failed CLIENT AUTHENTICATION counts towards the lockout (that is what a credential guesser produces);
        // an invalid_grant (expired code, back-button replay) from the real QMS must never lock the QMS out.
        $guess = $endpoint === 'token' ? "AND oev_reason IN ('client_auth_failed','client_not_sso')" : '';
        $st = $db->prepare("SELECT
                COALESCE(SUM(oev_at > NOW() - INTERVAL 1 MINUTE), 0) AS last_minute,
                COALESCE(SUM(oev_result = 'refused' $guess), 0) AS refusals_ip,
                COALESCE(SUM(oev_result = 'refused' $guess AND oev_client_id = ? AND ? > 0), 0) AS refusals_client
            FROM oidc_events WHERE oev_ip = ? AND oev_at > NOW() - INTERVAL 10 MINUTE AND oev_type IN ($in)");
        $st->bind_param('iis' . str_repeat('s', count($types)), $cid, $cid, $ip, ...$types);
        $st->execute();
        $r = $st->get_result()->fetch_assoc() ?: [];
        $st->close();
        $caps = $endpoint === 'token' ? ['ip' => 20, 'client' => 8] : ['ip' => 60, 'client' => 60];
        if ((int) $r['last_minute'] >= $redisLimit * 2 || (int) $r['refusals_ip'] >= $caps['ip'] || (int) $r['refusals_client'] >= $caps['client']) {
            header('Retry-After: 60');
            return false;
        }
        return true;
    }

    /** Recent audit rows for the admin page. @return list<array<string,mixed>> */
    public static function recent(\mysqli $db, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $res = $db->query("SELECT e.oev_at, e.oev_type, e.oev_result, e.oev_reason, e.oev_client_id, e.oev_contact_id, e.oev_ip, e.oev_detail,
                c.contact_name, ic.client_name
            FROM oidc_events e LEFT JOIN contacts c ON c.contact_id = e.oev_contact_id AND e.oev_contact_id > 0
            LEFT JOIN integration_clients ic ON ic.client_id = e.oev_client_id AND e.oev_client_id > 0
            ORDER BY e.oev_id DESC LIMIT $limit");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}
