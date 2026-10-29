<?php
/*
 * /oidc/authorize (included by oidc/index.php only).
 *
 * The person is already signed in to the department portal (or signs in there and comes back). No consent screen: this is a first-party
 * sign-in to the company's own QMS, so what RivetIT shares (a stable id and, if the registration allows, the display name) is fixed by the
 * administrator at registration. CSRF on the initiation is the OAuth `state` that the QMS generated and checks again on return.
 *
 * Browser flow, with the pending request kept SERVER side:
 *   1. validate client + exact redirect URI + PKCE/state/nonce; store the request (oidc_pending, hashed id);
 *      the id goes in a short-lived HttpOnly cookie, never in a URL.
 *   2. no portal session: 302 to /login.php (a fixed path, never a request value). After the portal login, client/includes/check_login.php
 *      sends the browser back to /oidc/authorize (no parameters) and step 3 resumes from the cookie.
 *   3. refuse (audited, plain page) unless the portal session is a real one, not a kiosk, fresh enough, and QualityAccessPolicy allows the person.
 *   4. consume the pending request exactly once, issue the code, 302 to the registered redirect URI.
 */
defined('OIDC_FRONT') || exit;

use ITFlow\Mcp\OAuth\OAuthHttp;
use ITFlow\Mcp\OAuth\OAuthService;
use ITFlow\Oidc\OidcAudit;
use ITFlow\Oidc\OidcConfig;
use ITFlow\Oidc\OidcPages;
use ITFlow\Oidc\OidcProvider;
use ITFlow\Oidc\QualityAccessPolicy;

OidcPages::headers();   // no-store, no framing, no referrer: also on the redirects below

$secure = !empty($config_https_only);
$setPendingCookie = static function (string $id) use ($secure): void {
    setcookie(OIDC_PENDING_COOKIE, $id, ['expires' => time() + OidcConfig::PENDING_TTL, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
};
$clearPendingCookie = static function () use ($secure): void {
    setcookie(OIDC_PENDING_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[OIDC_PENDING_COOKIE]);
};
$refuse = static function (int $status, string $type, string $reason, int $clientId, int $contactId, string $headline, string $message) use ($mysqli): never {
    OidcAudit::record($mysqli, $type, 'refused', $reason, $clientId, $contactId);
    OidcPages::refusal($status, $headline, $message);
};
$redirectTo = static function (string $url): never {
    session_write_close();
    http_response_code(302);
    header('Location: ' . $url);
    exit;
};

// ---- abuse limit (before anything touches the database for real work) -----------------------------------------------------------
if (!OidcAudit::allow($mysqli, 'authorize', 120)) {
    OidcPages::refusal(429, 'Too many attempts', 'Too many sign-in attempts from your network. Wait a minute and try again.');
}
if (!$cfg['enabled']) {
    $refuse(503, 'authorize', 'provider_disabled', 0, 0, 'Single sign-on is not available', 'Single sign-on to Quality is not available right now.');
}

// ---- the portal session (read-only until we know what to do) ------------------------------------------------------------------------
if (!empty($_COOKIE[session_name()])) {
    require_once __DIR__ . '/../includes/session_init.php';
}
$sessionState = 'none';
$sess = ['contact_id' => 0, 'user_id' => 0, 'auth_time' => 0, 'amr' => ''];
if (isset($_SESSION) && is_array($_SESSION)) {
    if (!empty($_SESSION['kiosk_session']) || !empty($_SESSION['kiosk_device']) || !empty($_SESSION['is_kiosk'])) {
        $sessionState = 'kiosk';   // a shared-screen session never carries a personal QMS session
    } elseif (!empty($_SESSION['client_logged_in']) && (int) ($_SESSION['user_type'] ?? 0) === 2 && (int) ($_SESSION['contact_id'] ?? 0) > 0 && (int) ($_SESSION['user_id'] ?? 0) > 0) {
        $sessionState = 'portal';
        $method_ = (string) ($_SESSION['login_method'] ?? '');
        $sess = [
            'contact_id' => (int) $_SESSION['contact_id'],
            'user_id' => (int) $_SESSION['user_id'],
            'auth_time' => (int) ($_SESSION['portal_auth_time'] ?? 0),
            // How the person signed in, as OIDC amr values. A portal login that predates this feature has no record: 'pwd' would be a guess, so say nothing.
            'amr' => (string) ($_SESSION['portal_amr'] ?? match ($method_) { 'oidc', 'odoo', 'azure' => 'fed', default => '' }),
        ];
    } elseif (!empty($_SESSION['logged']) && empty($_SESSION['client_logged_in']) && (int) ($_SESSION['user_id'] ?? 0) > 0) {
        // An agent (or an agent previewing a portal). Not a department person, but an ADMINISTRATOR may open Quality as themselves:
        // the principal is the negative of the user id (QualityAccessPolicy::administratorPrincipal), and allows() below decides whether
        // this agent really is a live administrator. Anyone else is refused at that check.
        $sessionState = 'portal';
        $sess = [
            'contact_id' => QualityAccessPolicy::administratorPrincipal((int) $_SESSION['user_id']),
            'user_id' => (int) $_SESSION['user_id'],
            'auth_time' => (int) ($_SESSION['agent_auth_time'] ?? 0),
            'amr' => (string) ($_SESSION['agent_amr'] ?? ''),
        ];
    } elseif (!empty($_SESSION['logged'])) {
        $sessionState = 'agent';
    }
}

// ---- POST: the "sign in again" button ------------------------------------------------------------------------------------------
if ($method === 'POST') {
    $posted = OAuthService::parseParams((string) (OAuthHttp::body(2048) ?? ''));
    $csrf = (string) ($posted['values']['csrf_token'] ?? '');
    $pendingId = (string) ($_COOKIE[OIDC_PENDING_COOKIE] ?? '');
    $pending = $provider->loadPending($pendingId);
    if (($posted['values']['action'] ?? '') !== 'reauth' || $sessionState !== 'portal' || $csrf === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf) || $pending === null) {
        $refuse(400, 'authorize', 'reauth_post_invalid', 0, $sess['contact_id'], 'This page expired', 'This sign-in request is no longer valid. Go back to the application and start again.');
    }
    OidcAudit::record($mysqli, 'authorize', 'info', 'reauth_requested', (int) $pending['opend_client_id'], $sess['contact_id']);
    // End the portal session (the pending request lives server-side and in its own cookie, so it survives), then the normal portal sign-in page.
    $_SESSION = [];
    session_destroy();
    $redirectTo('/login.php');
}

// ---- the request: a fresh one (client_id present) or the resume after the portal login (no parameters) ------------------------------------
$raw = (string) ($_SERVER['QUERY_STRING'] ?? '');
if (strlen($raw) > 8192) {
    OidcPages::refusal(414, 'Request too large', 'The sign-in request is too large.');
}
$params = OAuthService::parseParams($raw);
if ($params['duplicates'] !== []) {
    $refuse(400, 'authorize', 'duplicate_parameter', 0, 0, 'This sign-in request is not valid', 'The sign-in request is not valid. Go back to the application and start again.');
}
$v = $params['values'];

if (isset($v['client_id'])) {
    $checked = $provider->validateAuthorizeRequest($v);
    if (!$checked['ok'] && isset($checked['fatal'])) {
        // The client or the redirect URI cannot be trusted: a page, never a redirect, and nothing from the request is shown.
        $refuse(400, 'authorize', $checked['fatal'], 0, 0, 'This sign-in request is not valid', 'The application that sent you here is not set up for single sign-on with this system, or the request is not valid. Go back and start again.');
    }
    if (!$checked['ok']) {
        // Client and redirect URI are verified, so the error goes back to the client (RFC 6749 4.1.2.1) with state and iss.
        OidcAudit::record($mysqli, 'authorize', 'refused', $checked['reason'], (int) $checked['client']['client_id']);
        $redirectTo(OidcProvider::redirectUrl($checked['redirect_uri'], ['error' => $checked['error'], 'state' => $checked['state']]));
    }
    $client = $checked['client'];
    $req = $checked['req'];
    $pendingId = $provider->createPending($client, $req);
    $setPendingCookie($pendingId);
    $_COOKIE[OIDC_PENDING_COOKIE] = $pendingId;
    $pending = $provider->loadPending($pendingId);
    $promptNone = $req['prompt_none'];
} else {
    $pendingId = (string) ($_COOKIE[OIDC_PENDING_COOKIE] ?? '');
    $pending = $provider->loadPending($pendingId);
    $client = $pending !== null ? $provider->clientForPending($pending) : null;
    if ($pending === null || $client === null) {
        $clearPendingCookie();
        $refuse(400, 'authorize', $pending === null ? 'pending_missing' : 'client_changed', $pending !== null ? (int) $pending['opend_client_id'] : 0, $sess['contact_id'],
            'This sign-in request expired', 'This sign-in request is no longer valid. Go back to the application and start again.');
    }
    $promptNone = false;
}
if ($pending === null) {
    $refuse(500, 'authorize', 'pending_unavailable', (int) $client['client_id'], 0, 'Something went wrong', 'The sign-in could not be completed. Try again shortly.');
}
$clientId = (int) $client['client_id'];

/** prompt=none must not show any page: report login_required to the client instead (the request is not stored for later). */
$loginRequired = static function () use ($provider, $pendingId, $pending, $mysqli, $clientId, $redirectTo): never {
    $provider->consumePending($pendingId);
    OidcAudit::record($mysqli, 'authorize', 'refused', 'login_required_prompt_none', $clientId);
    $redirectTo(OidcProvider::redirectUrl((string) $pending['opend_redirect_uri'], ['error' => 'login_required', 'state' => (string) $pending['opend_state']]));
};

// ---- who is this? ----------------------------------------------------------------------------------------------------------------------------
if ($sessionState === 'kiosk') {
    $refuse(403, 'authorize', 'kiosk_session', $clientId, 0, 'Not available on this screen', 'Quality sign-in is not available on shared screens. Use your own computer.');
}
if ($sessionState === 'agent') {
    $refuse(403, 'authorize', 'not_portal_session', $clientId, 0, 'Use your department sign-in', 'Single sign-on to Quality works from the department portal. Sign out here and sign in to the department portal.');
}
if ($sessionState === 'none') {
    if ($promptNone) {
        $loginRequired();
    }
    OidcAudit::record($mysqli, 'authorize', 'info', 'redirect_to_portal_login', $clientId);
    $redirectTo('/login.php');
}

// ---- may this person use Quality? (checked on every pass, with current database state) -----------------------------------------------------------
$contactId = $sess['contact_id'];
$policy = QualityAccessPolicy::allows($contactId, $mysqli);
if (!$policy['ok']) {
    if ($policy['reason'] === 'provider_disabled') {
        $refuse(503, 'authorize', 'provider_disabled', $clientId, $contactId, 'Single sign-on is not available', 'Single sign-on to Quality is not available right now.');
    }
    $provider->consumePending($pendingId);
    $clearPendingCookie();
    $refuse(403, 'authorize', 'policy:' . $policy['reason'], $clientId, $contactId, 'You cannot use Quality yet', 'Your account is not set up to use Quality. Ask your administrator.');
}
// The session must belong to the same login that owns the contact (a tampered or mixed-up session is refused).
$subject = QualityAccessPolicy::subject($mysqli, $contactId);
if ($subject === null || $subject['user_id'] !== $sess['user_id']) {
    $provider->consumePending($pendingId);
    $clearPendingCookie();
    $refuse(403, 'authorize', 'session_contact_mismatch', $clientId, $contactId, 'You cannot use Quality yet', 'Your account is not set up to use Quality. Ask your administrator.');
}

// ---- is the portal sign-in recent enough? (the portal session itself can live for days) -----------------------------------------------------------
$maxAge = (int) $cfg['max_auth_age_minutes'] * 60;
$reqMax = $pending['opend_max_age'] === null ? null : (int) $pending['opend_max_age'];
if ($reqMax !== null) {
    $maxAge = min($maxAge, $reqMax);
}
$authTime = $sess['auth_time'];   // 0 for a session that never recorded one (signed in before this feature): treated as stale
$forceLogin = (int) $pending['opend_force_login'] === 1 || $reqMax === 0;
$stale = $authTime <= 0 || (time() - $authTime) > $maxAge || ($forceLogin && $authTime < (int) $pending['created_epoch']);
if ($stale) {
    if ($promptNone) {
        $loginRequired();
    }
    OidcAudit::record($mysqli, 'authorize', 'info', 'reauth_prompt', $clientId, $contactId);
    OidcPages::reauth((string) ($_SESSION['csrf_token'] ?? ''));
}

// ---- issue the code -----------------------------------------------------------------------------------------------------------------------------
if (!$provider->consumePending($pendingId)) {
    // Back/forward or a second tab replaying the same request: it was already used.
    $clearPendingCookie();
    $refuse(400, 'authorize', 'pending_already_used', $clientId, $contactId, 'This sign-in request was already used', 'This sign-in request was already used. Go back to the application and start again.');
}
$clearPendingCookie();
$code = $provider->issueCode($client, $pending, $contactId, $sess['user_id'], $authTime, $sess['amr']);
OidcAudit::record($mysqli, 'authorize', 'ok', 'code_issued', $clientId, $contactId);
$redirectTo(OidcProvider::redirectUrl((string) $pending['opend_redirect_uri'], ['code' => $code, 'state' => (string) $pending['opend_state']]));
