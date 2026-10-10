<?php

/*
 * Training kiosk bootstrap (P3 spec §3.1, §0.2, §0.5). Every kiosk entry point sets
 * $KIOSK_CSP_PROFILE ('strict' default | 'external_video' | 'kb_article' | 'media' | 'api') and requires this
 * file first. In order:
 *
 *   1  §0.5 fetch-metadata guards, before auth and before any output:
 *        Sec-Purpose/Purpose "prefetch"                  -> 503, empty body (Speed Brain opt-out)
 *        HTML pages: Sec-Fetch-Dest present and not document, or Sec-Fetch-Mode present and not
 *        navigate                                        -> 403 (a same-origin fetch() can't read pages)
 *        external_video: Sec-Fetch-Mode must be PRESENT, else $kiosk_video_unsupported = true and the
 *        page falls back to the strict profile (it renders "update this device", never the player).
 *        external_video differs from strict only by frame-src (the two player hosts) and its
 *        Referrer-Policy; no third-party script is allowed on any kiosk page
 *   2  headers: XFO DENY, nosniff, no-store, COOP, Permissions-Policy, and the profile's CSP
 *   3  the app (config, functions, global settings, time zone) inside an output buffer; then assert
 *      that NO PHP session is active - /kiosk/ never starts one (§0.2)
 *   4  module gate: training off or schema < 2.6.93 -> 404; empty $config_settings_enc_key -> 503
 *   5  $kctx (Kiosk\Core\KioskCtx) with the validated device (or null) and the UI language
 *   6  [S] P-11: stray agent cookies are expired on an enrolled device
 *
 * Pages then call kiosk_require_device() / kiosk_require_session([...]) from guard.php.
 */

defined('KIOSK_BOOTSTRAP') || define('KIOSK_BOOTSTRAP', 1);
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit;   // never an entry point (nginx also denies /kiosk/includes/)
}

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskConfigException;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskKeys;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KioskStrings;

$kiosk_started_ns = hrtime(true);
$KIOSK_CSP_PROFILE = (isset($KIOSK_CSP_PROFILE) && in_array($KIOSK_CSP_PROFILE, ['strict', 'external_video', 'kb_article', 'media', 'api'], true))
    ? $KIOSK_CSP_PROFILE : 'strict';
$kiosk_csp_nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
$kiosk_video_unsupported = false;

if (!function_exists('kiosk_plain')) {
    /** A plain-text refusal with the security headers (no page chrome, no data). */
    function kiosk_plain(int $status, string $text): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            header('X-Frame-Options: DENY');
        }
        echo $text;
        exit;
    }
}

// ---- 1. fetch-metadata guards ---------------------------------------------------------------
foreach (['HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_MOZ', 'HTTP_X_PURPOSE'] as $kiosk_h) {
    if (isset($_SERVER[$kiosk_h]) && stripos((string) $_SERVER[$kiosk_h], 'prefetch') !== false) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit;
    }
}
if ($KIOSK_CSP_PROFILE === 'strict' || $KIOSK_CSP_PROFILE === 'external_video' || $KIOSK_CSP_PROFILE === 'kb_article') {
    $kiosk_dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? null;
    $kiosk_mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? null;
    if (($kiosk_dest !== null && $kiosk_dest !== 'document') || ($kiosk_mode !== null && $kiosk_mode !== 'navigate')) {
        kiosk_plain(403, 'Forbidden');
    }
    if ($KIOSK_CSP_PROFILE === 'external_video' && $kiosk_mode === null) {
        $kiosk_video_unsupported = true;
        $KIOSK_CSP_PROFILE = 'strict';
    }
}

// ---- 2. headers ------------------------------------------------------------------------------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
$kiosk_csp_strict = "default-src 'self'; script-src 'self' 'nonce-$kiosk_csp_nonce' https://static.cloudflareinsights.com; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self'; font-src 'self'; "
    . "connect-src 'self' https://cloudflareinsights.com; frame-src 'none'; object-src 'none'; base-uri 'none'; "
    . "form-action 'self'; frame-ancestors 'none'; manifest-src 'self'";
if ($KIOSK_CSP_PROFILE === 'strict') {
    header('Content-Security-Policy: ' . $kiosk_csp_strict);
    header('Referrer-Policy: no-referrer');
} elseif ($KIOSK_CSP_PROFILE === 'kb_article') {
    // A Knowledge Base article: strict, except its embedded-HTML blocks frame /kiosk/kb_embed.php (same origin). The
    // embed answers with its own `sandbox allow-scripts` policy, so its script never runs in this origin.
    header('Content-Security-Policy: ' . str_replace("frame-src 'none'", "frame-src 'self'", $kiosk_csp_strict));
    header('Referrer-Policy: no-referrer');
} elseif ($KIOSK_CSP_PROFILE === 'external_video') {
    // The YouTube/Vimeo player runs ONLY inside its cross-origin iframe: the page drives it over
    // postMessage (js/training_video_embed.js, transport 'postmessage') and loads no provider script,
    // so script-src is the same as strict. A provider script in this origin could window.open() another
    // kiosk page (CSP does not govern that) and read its full session CSRF token (security review).
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$kiosk_csp_nonce' https://static.cloudflareinsights.com; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self'; font-src 'self'; "
        . "connect-src 'self' https://cloudflareinsights.com; frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
        . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; manifest-src 'self'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
} elseif ($KIOSK_CSP_PROFILE === 'api') {
    header('Content-Type: application/json; charset=utf-8');
}

// ---- 3. the app, with no PHP session -----------------------------------------------------------
ob_start();
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/functions.php';
require_once dirname(__DIR__, 2) . '/includes/load_global_settings.php';
require_once dirname(__DIR__, 2) . '/includes/inc_set_timezone.php';
ob_end_clean();
if (session_status() === PHP_SESSION_ACTIVE) {
    error_log('Training kiosk: a PHP session is active under /kiosk/ - refusing');
    kiosk_plain(500, 'Server error');
}

// ---- 4. module gate ----------------------------------------------------------------------------
$kiosk_ks = KioskSettings::fromDb($mysqli);
if (!$kiosk_ks->moduleEnabled || !$kiosk_ks->schemaReady) {
    kiosk_plain(404, 'Not found');
}
try {
    $kiosk_keys = KioskKeys::fromSecret((string) ($config_settings_enc_key ?? ''));
} catch (KioskConfigException) {
    kiosk_plain(503, 'Training kiosk is not configured');
}

// ---- 5. context ----------------------------------------------------------------------------------
$kiosk_host = (string) preg_replace('#^https?://#i', '', trim((string) ($config_base_url ?? '')));
$kiosk_core = new Ctx($mysqli, 0, false, 0, 'https://' . rtrim($kiosk_host, '/'), TrainingSettings::fromDb($mysqli),
    Text::clip($_SERVER['HTTP_USER_AGENT'] ?? null, 255));
$kiosk_device_reason = null;
$kiosk_device = KioskAuth::device($mysqli, $kiosk_ks, $kiosk_device_reason, $KIOSK_CSP_PROFILE !== 'media');
if ($kiosk_device === null && $kiosk_device_reason !== 'missing' && $kiosk_device_reason !== 'pending' && ($KIOSK_CSP_PROFILE === 'strict' || $KIOSK_CSP_PROFILE === 'external_video' || $KIOSK_CSP_PROFILE === 'kb_article')) {
    // Revoked, re-typed, archived or re-assigned (A19): the page shows "not set up" and the stale cookie goes.
    // A fleet-link device still waiting for approval ('pending', 2.6.151) keeps its cookie: approval needs nothing more on the tablet.
    KioskAuth::clearDeviceCookie();
    KioskAuth::clearSessionCookie();
}
$kiosk_lang = KioskStrings::lang(is_string($_COOKIE[KioskAuth::LANG_COOKIE] ?? null) ? $_COOKIE[KioskAuth::LANG_COOKIE] : 'en');
$kctx = new KioskCtx($kiosk_core, $kiosk_ks, $kiosk_keys, $kiosk_device, null, $kiosk_lang, $kiosk_started_ns);

// ---- 6. [S] P-11 --------------------------------------------------------------------------------
// A portal device is minted for someone signed in to the client portal: sweeping PHPSESSID would log them out of it.
if ($kiosk_device !== null && ($kiosk_device['kiosk_enroll_method'] ?? '') !== 'portal') {
    KioskAuth::expireAgentCookies();
}
unset($kiosk_h, $kiosk_dest, $kiosk_mode, $kiosk_host);
