<?php

namespace ITFlow\Oidc;

/**
 * The seam between the department portal and the SSO provider.
 *
 * resumeUrl(): after a portal sign-in that was started by /oidc/authorize (the browser carries the pending-request cookie), the portal's
 * landing page sends the person back to /oidc/authorize. The target is a FIXED path; no value from the request or the cookie is ever put in
 * a URL, so there is nothing to turn into an open redirect. The cookie is only a lookup key: the pending request (client, redirect URI,
 * state, nonce, PKCE challenge) lives in the database, was validated when it was stored, and is re-validated when it is resumed.
 *
 * openQualityUrl(): the address the "Open Quality" button opens, or null (provider off, or no SSO client with a login URL).
 */
final class PortalResume
{
    public const COOKIE = 'rivet_oidc_pending';
    public const PATH = '/oidc/authorize';

    public static function resumeUrl(\mysqli $db, array $cookies): ?string
    {
        $id = $cookies[self::COOKIE] ?? '';
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $id) !== 1) {
            return null;
        }
        $cfg = OidcConfig::load($db);
        if (!$cfg['enabled']) {
            return null;
        }
        return (new OidcProvider($db, $cfg))->loadPending($id) !== null ? self::PATH : null;
    }

    /**
     * After an AGENT sign-in (password, passkey or company sign-in): back to /oidc/authorize when that sign-in was started by it and this
     * login is allowed to open Quality as an administrator. Anyone else goes to their normal start page - an agent who is not an
     * administrator is never detoured to a refusal page.
     */
    public static function agentResumeUrl(\mysqli $db, array $cookies, int $userId): ?string
    {
        if ($userId <= 0 || self::resumeUrl($db, $cookies) === null) {
            return null;
        }
        return QualityAccessPolicy::allows(QualityAccessPolicy::administratorPrincipal($userId), $db)['ok'] ? self::PATH : null;
    }

    public static function openQualityUrl(\mysqli $db): ?string
    {
        return OidcConfig::portalLoginUrl($db);
    }
}
