<?php

namespace ITFlow\EndpointAgent;

/** The one-row endpoint_agent_settings table (not `settings`, which is at MariaDB's row-size limit). */
final class Config
{
    public const INTEGRATION_TYPE = 'rivetit_agent';

    private static ?array $cache = null;

    public static function get(bool $fresh = false): array
    {
        if (self::$cache === null || $fresh) {
            $row = Db::one('SELECT * FROM endpoint_agent_settings WHERE id = 1');
            if ($row === null) {
                Db::run('INSERT IGNORE INTO endpoint_agent_settings (id) VALUES (1)');
                $row = Db::one('SELECT * FROM endpoint_agent_settings WHERE id = 1') ?? [];
            }
            self::$cache = $row;
        }
        return self::$cache;
    }

    public static function enabled(): bool
    {
        return (int) (self::get()['enabled'] ?? 0) === 1;
    }

    /** Update whitelisted columns. */
    public static function set(array $values): void
    {
        $allowed = ['enabled', 'service_url', 'check_in_interval_s', 'collect_interval_s', 'offline_after_s', 'stale_after_s',
            'failure_debounce', 'recovery_debounce', 'retention_days', 'job_retention_days', 'job_output_max_bytes',
            'job_default_timeout_s', 'job_max_timeout_s', 'job_expiry_s', 'job_ack_timeout_s', 'job_max_attempts', 'enroll_max_ttl_h',
            'unmatched_policy', 'checks_json', 'mesh_enabled', 'mesh_url', 'mesh_domain', 'mesh_login_key_enc', 'mesh_account_template',
            'mesh_policy', 'mesh_token_ttl_s', 'coexistence_policy', 'integration_id', 'ca_pem'];
        $sets = [];
        $params = [];
        foreach ($values as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                throw new \InvalidArgumentException("unknown setting $k");
            }
            $sets[] = "`$k` = ?";
            $params[] = $v;
        }
        if ($sets) {
            Db::run('UPDATE endpoint_agent_settings SET ' . implode(', ', $sets) . ' WHERE id = 1', $params);
        }
        self::$cache = null;
    }

    /**
     * Turn the feature on: mint the instance signing key the first time and make sure the synthetic RMM integration row exists
     * (asset_rmm_links.integration_id points at it, so devices appear in the existing RMM views and metrics).
     */
    public static function enable(): void
    {
        $cfg = self::get(true);
        if ($cfg['signing_public_key'] === '' || empty($cfg['signing_private_key_enc'])) {
            self::generateSigningKey();
        }
        self::integrationId();
        self::set(['enabled' => 1]);
    }

    public static function generateSigningKey(): string
    {
        [$pub, $sec] = Signer::generateKeypair();
        $kid = substr(hash('sha256', $pub), 0, 16);
        Db::run('UPDATE endpoint_agent_settings SET signing_key_id = ?, signing_public_key = ?, signing_private_key_enc = ?, signing_key_created_at = ? WHERE id = 1',
            [$kid, $pub, encryptSetting($sec), Db::utcNow()]);
        self::$cache = null;
        return $kid;
    }

    /** @return array{0:string,1:string,2:string} [secret key base64, public key base64, key id] */
    public static function signingKey(): array
    {
        $cfg = self::get();
        $sec = decryptSetting((string) ($cfg['signing_private_key_enc'] ?? ''));
        if ($sec === '' || $cfg['signing_public_key'] === '') {
            throw new \RuntimeException('endpoint agent signing key is not available');
        }
        return [$sec, $cfg['signing_public_key'], $cfg['signing_key_id']];
    }

    public static function integrationId(): int
    {
        $cfg = self::get();
        $id = (int) $cfg['integration_id'];
        if ($id > 0 && Db::val('SELECT id FROM rmm_integrations WHERE id = ? AND type = ?', [$id, self::INTEGRATION_TYPE]) !== null) {
            return $id;
        }
        $existing = Db::val('SELECT id FROM rmm_integrations WHERE type = ? ORDER BY id LIMIT 1', [self::INTEGRATION_TYPE]);
        if ($existing === null) {
            $existing = Db::insert("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES ('RivetIT Endpoint Agent', ?, '', '', '', 1)",
                [self::INTEGRATION_TYPE]);
        }
        self::set(['integration_id' => (int) $existing]);
        return (int) $existing;
    }

    /** Default check schedule delivered to agents (an admin may replace it in Administration > Endpoint agent). */
    public static function defaultChecks(): array
    {
        return [
            ['key' => 'disk_c', 'type' => 'disk', 'params' => ['mount' => 'C:', 'warn_pct' => 85, 'fail_pct' => 95], 'interval_s' => 300],
            ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => new \stdClass(), 'interval_s' => 3600],
            ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expect' => 'running'], 'interval_s' => 300],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function checks(): array
    {
        $raw = (string) (self::get()['checks_json'] ?? '');
        $list = $raw === '' ? null : json_decode($raw, true);
        if (!is_array($list) || !array_is_list($list)) {
            return self::defaultChecks();
        }
        return $list;
    }

    /** Check definitions as delivered to the agent, each signed (script checks execute code on the endpoint). */
    public static function signedChecks(): array
    {
        [$sec] = self::signingKey();
        $out = [];
        foreach (self::checks() as $c) {
            $item = [
                'key' => (string) $c['key'],
                'type' => (string) $c['type'],
                'params' => $c['params'] ?? new \stdClass(),
                'interval_s' => (int) $c['interval_s'],
            ];
            if (is_array($item['params']) && !$item['params']) {
                $item['params'] = new \stdClass();
            }
            $msg = Signer::canonical(Signer::toObject(json_decode(json_encode($item), false)));
            $item['signature'] = Signer::sign($msg, $sec);
            $out[] = $item;
        }
        return $out;
    }

    private static function hasFloat($v): bool
    {
        if (is_float($v)) {
            return true;
        }
        if (is_array($v)) {
            foreach ($v as $x) {
                if (self::hasFloat($x)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Validate and normalise an admin-supplied check list (JSON text). @return array{0:?array,1:?string} */
    public static function validateChecks(string $json): array
    {
        $list = json_decode($json, true);
        if (!is_array($list) || !array_is_list($list) || count($list) > 50) {
            return [null, 'Checks must be a JSON list of at most 50 items.'];
        }
        $seen = [];
        $out = [];
        foreach ($list as $c) {
            if (!is_array($c) || !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', (string) ($c['key'] ?? ''))) {
                return [null, 'Each check needs a key of letters, digits and _ . : -'];
            }
            if (isset($seen[$c['key']])) {
                return [null, 'Duplicate check key ' . $c['key']];
            }
            $seen[$c['key']] = 1;
            if (!in_array($c['type'] ?? '', ['service', 'disk', 'pending_reboot', 'script'], true)) {
                return [null, 'Check type must be service, disk, pending_reboot or script.'];
            }
            $iv = (int) ($c['interval_s'] ?? 0);
            if ($iv < 30 || $iv > 86400) {
                return [null, 'Check interval_s must be 30 to 86400.'];
            }
            $params = $c['params'] ?? [];
            if (self::hasFloat($c)) {
                return [null, 'Check values must be whole numbers (no decimals).'];
            }
            if (!is_array($params)) {
                return [null, 'Check params must be an object.'];
            }
            if ($c['type'] === 'script') {
                $body = (string) ($params['script'] ?? '');
                if ($body === '' || strlen($body) > 8192) {
                    return [null, 'Script checks need params.script (at most 8 KiB).'];
                }
                $params['timeout_s'] = max(1, min(60, (int) ($params['timeout_s'] ?? 30)));
            }
            $out[] = ['key' => $c['key'], 'type' => $c['type'], 'params' => $params ?: new \stdClass(), 'interval_s' => $iv];
        }
        return [$out, null];
    }
}
