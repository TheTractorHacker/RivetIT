<?php
declare(strict_types=1);
/*
 * Department portal Microsoft Entra sign-in: stable identity binding.
 *
 * The login endpoint is the multi-tenant "organizations" authority, so a token can come from ANY Entra tenant and a
 * userPrincipalName is only unique inside its own tenant. Matching on the UPN string alone let an attacker who
 * controls a tenant mint a UPN equal to a real contact's email and be signed in as that contact. The identity is
 * therefore (tenant id, object id): stored in users.user_oidc_issuer = https://login.microsoftonline.com/<tid>/v2.0
 * and users.user_oidc_subject = <oid> (the same columns, and unique index, the portal OpenID Connect login uses).
 *
 *   issuer + subject set   -> bound: only that tenant + object signs in (email/UPN is never consulted)
 *   issuer set, subject NULL -> tenant pinned by an administrator, awaiting first sign-in: the first sign-in from THAT
 *                              tenant whose UPN equals the login's email binds the object id (one atomic UPDATE)
 *   issuer NULL            -> not linked: refused until an administrator sets the tenant (re-link)
 */

const PORTAL_ENTRA_AUTHORITY = 'https://login.microsoftonline.com/';
const PORTAL_ENTRA_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

function portalEntraGuid(string $v): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
}

function portalEntraIssuer(string $tenant_id): string
{
    return PORTAL_ENTRA_AUTHORITY . strtolower($tenant_id) . '/v2.0';
}

/**
 * Validate the id_token Microsoft returned from the token endpoint (back-channel, over TLS, so the signature is not
 * re-verified; audience, issuer-vs-tenant, expiry and nonce are). Returns ['tid' => , 'oid' => ] or null.
 */
function portalEntraClaims(string $id_token, string $client_id, string $nonce, ?int $now = null): ?array
{
    $parts = explode('.', $id_token);
    if (count($parts) !== 3) {
        return null;
    }
    $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
    $c = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($c)) {
        return null;
    }
    $tid = (string) ($c['tid'] ?? '');
    $oid = (string) ($c['oid'] ?? '');
    if (!portalEntraGuid($tid) || !portalEntraGuid($oid) || strtolower($tid) === PORTAL_ENTRA_CONSUMER_TENANT) {
        return null;
    }
    if (($c['aud'] ?? null) !== $client_id || !hash_equals(portalEntraIssuer($tid), (string) ($c['iss'] ?? ''))) {
        return null;
    }
    if ($nonce === '' || !hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
        return null;
    }
    if ((int) ($c['exp'] ?? 0) < ($now ?? time())) {
        return null;
    }
    return ['tid' => strtolower($tid), 'oid' => strtolower($oid)];
}

/**
 * The department login this Entra identity may sign in as, or null. Binds the object id on first sign-in of a
 * tenant-pinned login. $upn is only used for that first binding, never to find an account on its own.
 */
function portalEntraResolveLogin(mysqli $mysqli, string $tid, string $oid, string $upn): ?array
{
    $issuer = portalEntraIssuer($tid);
    $oid = strtolower($oid);
    $select = "SELECT * FROM users
        LEFT JOIN contacts ON user_id = contact_user_id
        LEFT JOIN clients ON contact_client_id = client_id
        WHERE user_archived_at IS NULL AND client_archived_at IS NULL AND user_type = 2 AND user_status = 1
        AND user_auth_method = 'azure'";

    $stmt = $mysqli->prepare("$select AND user_oidc_issuer = ? AND user_oidc_subject = ? LIMIT 1");
    $stmt->bind_param('ss', $issuer, $oid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return $row;
    }

    // First sign-in of a login whose tenant an administrator pinned: bind the object id.
    $stmt = $mysqli->prepare("$select AND user_oidc_issuer = ? AND user_oidc_subject IS NULL AND user_email = ? LIMIT 1");
    $stmt->bind_param('ss', $issuer, $upn);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }
    $uid = (int) $row['user_id'];
    $bind = $mysqli->prepare("UPDATE users SET user_oidc_subject = ? WHERE user_id = ? AND user_oidc_issuer = ? AND user_oidc_subject IS NULL");
    $bind->bind_param('sis', $oid, $uid, $issuer);
    $bind->execute();
    if ($bind->affected_rows !== 1) {
        return null;
    }
    $row['user_oidc_subject'] = $oid;
    return $row;
}
