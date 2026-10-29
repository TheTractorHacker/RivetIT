<?php

namespace ITFlow\Oidc;

use Firebase\JWT\JWT;
use ITFlow\Integration\IntegrationAuth;

/**
 * ID token signing keys (ES256, P-256).
 *
 * The key pair is generated on first use. The private key is stored only as encryptSetting() output under its own context
 * ('oidc_signing_key'; registered in ITFlow\Crypto\ColumnSpec so a key rotation of the settings vault reaches it) and never leaves this class
 * except as an argument to the signer. The public half is published in the JWKS with a kid.
 *
 * Rotation: a new key becomes the only signing key; the previous one is marked retired and stays in the JWKS until
 * every ID token it could have signed has expired (longest allowed ID token lifetime + a clock-skew allowance), so a relying party that has
 * the old JWKS cached keeps verifying tokens that are still valid, and one that fetches fresh also finds the old key until then.
 */
final class KeyStore
{
    public const CONTEXT = 'oidc_signing_key';
    public const ALG = 'ES256';

    /** @return array{kid:string, private_pem:string} the key that signs new tokens (created on first use) */
    public static function activeSigningKey(\mysqli $db): array
    {
        $row = self::activeRow($db);
        if ($row === null) {
            // Serialise first-use creation so two simultaneous first requests do not make two keys.
            $got = (int) ($db->query("SELECT GET_LOCK('rivetit_oidc_key', 5) AS l")->fetch_assoc()['l'] ?? 0);
            try {
                $row = self::activeRow($db);
                if ($row === null) {
                    if ($got !== 1) {
                        throw new \RuntimeException('OIDC signing key unavailable');
                    }
                    self::createKey($db, 0);
                    $row = self::activeRow($db);
                }
            } finally {
                if ($got === 1) {
                    $db->query("DO RELEASE_LOCK('rivetit_oidc_key')");
                }
            }
        }
        if ($row === null) {
            throw new \RuntimeException('OIDC signing key unavailable');
        }
        $pem = decryptSetting((string) $row['okey_private_enc'], self::CONTEXT);
        if ($pem === '' || !str_contains($pem, 'PRIVATE KEY')) {
            throw new \RuntimeException('OIDC signing key cannot be read');   // key vault problem; nothing is signed with a guess
        }
        return ['kid' => (string) $row['okey_kid'], 'private_pem' => $pem];
    }

    /** @return array<string,mixed>|null */
    private static function activeRow(\mysqli $db): ?array
    {
        $res = $db->query("SELECT okey_kid, okey_private_enc FROM oidc_signing_keys WHERE okey_status = 'active' ORDER BY okey_created_at DESC, okey_kid LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        return $row ?: null;
    }

    /** Generates and stores a new active key. Returns its kid. */
    private static function createKey(\mysqli $db, int $userId): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false || !openssl_pkey_export($key, $privatePem)) {
            throw new \RuntimeException('Could not generate an OIDC signing key');
        }
        $details = openssl_pkey_get_details($key);
        $publicPem = (string) ($details['key'] ?? '');
        if ($publicPem === '') {
            throw new \RuntimeException('Could not generate an OIDC signing key');
        }
        $kid = bin2hex(random_bytes(8));
        $enc = encryptSetting($privatePem, self::CONTEXT);
        $st = $db->prepare("INSERT INTO oidc_signing_keys (okey_kid, okey_alg, okey_public_pem, okey_private_enc, okey_status, okey_created_by_user_id) VALUES (?, 'ES256', ?, ?, 'active', ?)");
        $st->bind_param('sssi', $kid, $publicPem, $enc, $userId);
        $st->execute();
        $st->close();
        return $kid;
    }

    /**
     * Admin action: new key, previous key retired but still published for the overlap window. Returns [new kid, retired kids].
     * @return array{kid:string, retired:list<string>, publish_until_seconds:int}
     */
    public static function rotate(\mysqli $db, int $userId): array
    {
        $overlap = OidcConfig::ID_TOKEN_TTL_MAX + OidcConfig::KEY_OVERLAP_SKEW;
        $got = (int) ($db->query("SELECT GET_LOCK('rivetit_oidc_key', 10) AS l")->fetch_assoc()['l'] ?? 0);
        if ($got !== 1) {
            throw new \RuntimeException('Another key operation is running');
        }
        try {
            $retired = [];
            $res = $db->query("SELECT okey_kid FROM oidc_signing_keys WHERE okey_status = 'active'");
            while ($res && ($r = $res->fetch_assoc())) {
                $retired[] = (string) $r['okey_kid'];
            }
            $kid = self::createKey($db, $userId);
            $st = $db->prepare("UPDATE oidc_signing_keys SET okey_status = 'retired', okey_retired_at = NOW(), okey_publish_until = NOW() + INTERVAL ? SECOND
                WHERE okey_status = 'active' AND okey_kid <> ?");
            $st->bind_param('is', $overlap, $kid);
            $st->execute();
            $st->close();
            // Retired keys past their overlap by 30 days are removed; they verify nothing any more.
            $db->query("DELETE FROM oidc_signing_keys WHERE okey_status = 'retired' AND okey_publish_until < NOW() - INTERVAL 30 DAY");
            return ['kid' => $kid, 'retired' => $retired, 'publish_until_seconds' => $overlap];
        } finally {
            $db->query("DO RELEASE_LOCK('rivetit_oidc_key')");
        }
    }

    /** @return array{keys:list<array<string,string>>} the JWKS: the active key and every retired key still inside its overlap window */
    public static function jwks(\mysqli $db): array
    {
        self::activeSigningKey($db);   // makes sure a key exists, so the first fetch of the JWKS is never empty
        $keys = [];
        $res = $db->query("SELECT okey_kid, okey_public_pem FROM oidc_signing_keys
            WHERE okey_status = 'active' OR (okey_status = 'retired' AND okey_publish_until > NOW()) ORDER BY okey_created_at DESC, okey_kid");
        while ($res && ($r = $res->fetch_assoc())) {
            $jwk = self::publicJwk((string) $r['okey_public_pem'], (string) $r['okey_kid']);
            if ($jwk !== null) {
                $keys[] = $jwk;
            }
        }
        return ['keys' => $keys];
    }

    /** @return array<string,string>|null */
    public static function publicJwk(string $publicPem, string $kid): ?array
    {
        $pub = openssl_pkey_get_public($publicPem);
        $d = $pub ? openssl_pkey_get_details($pub) : false;
        if (!$d || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($d['ec']['curve_name'] ?? '') !== 'prime256v1') {
            return null;
        }
        $x = str_pad((string) $d['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad((string) $d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        return ['kty' => 'EC', 'crv' => 'P-256', 'use' => 'sig', 'alg' => self::ALG, 'kid' => $kid,
            'x' => IntegrationAuth::b64uEncode($x), 'y' => IntegrationAuth::b64uEncode($y)];
    }

    /** Signs the claims with the active key (header: alg ES256, typ JWT, kid). */
    public static function sign(\mysqli $db, array $claims): string
    {
        $k = self::activeSigningKey($db);
        return JWT::encode($claims, $k['private_pem'], self::ALG, $k['kid']);
    }

    /** @return array{active_kid:?string, active_since:?string, retired:list<array{kid:string, until:string}>} for the admin page; no key material */
    public static function status(\mysqli $db): array
    {
        $out = ['active_kid' => null, 'active_since' => null, 'retired' => []];
        $res = $db->query("SELECT okey_kid, okey_status, okey_created_at, okey_publish_until FROM oidc_signing_keys ORDER BY okey_created_at DESC, okey_kid LIMIT 10");
        while ($res && ($r = $res->fetch_assoc())) {
            if ($r['okey_status'] === 'active' && $out['active_kid'] === null) {
                $out['active_kid'] = (string) $r['okey_kid'];
                $out['active_since'] = (string) $r['okey_created_at'];
            } elseif ($r['okey_status'] === 'retired') {
                $out['retired'][] = ['kid' => (string) $r['okey_kid'], 'until' => (string) $r['okey_publish_until']];
            }
        }
        return $out;
    }
}
