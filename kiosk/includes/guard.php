<?php

/*
 * Page guards for kiosk/*.php (P3 spec §3.1). Call AFTER bootstrap.php, before any output:
 *
 *   $dev = kiosk_require_device();                 // soft: null when not set up (the page shows "not set up")
 *   $dev = kiosk_require_device(false);            // hard: 302 /kiosk/ when not set up
 *   $s   = kiosk_require_session(['learner']);     // 302 /kiosk/ (cookie cleared) when missing/ended/invalid;
 *                                                  // 302 to the role's own home on a role mismatch (nothing ends)
 *   $s   = kiosk_peek_session();                   // the live session of this device or null, never redirects
 *
 * A successful session check replaces the global $kctx with $kctx->withKsess($s).
 * When this request found the device's temporary time up (KioskAuth::expiredAt()), a redirect home
 * goes to /kiosk/?ended=<epoch> so Home can say when its training time ended (display only).
 */

defined('KIOSK_BOOTSTRAP') || exit;

use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskAuthException;
use ITFlow\Training\Kiosk\Core\KioskRoleException;

function kiosk_redirect(string $path): never
{
    // Relative to this origin only: callers pass fixed /kiosk/… paths.
    if (preg_match('#^/kiosk/[A-Za-z0-9_./?=&-]*$#D', $path) !== 1) {
        $path = '/kiosk/';
    }
    $ended = KioskAuth::expiredAt();
    if ($path === '/kiosk/' && $ended !== null) {
        $path = '/kiosk/?ended=' . (int) floor(\ITFlow\Training\Kiosk\Core\KTime::epoch($ended) ?? 0);
    }
    header('Location: ' . $path, true, 302);
    exit;
}

/** The home page of a session role (§3.1). */
function kiosk_role_home(string $role, ?array $ksess = null): string
{
    return match ($role) {
        'learner' => '/kiosk/me.php',
        'trainer' => '/kiosk/trainer.php',
        'checkin' => '/kiosk/session.php' . (isset($ksess['ksess_tsession_id']) && $ksess['ksess_tsession_id'] !== null
            ? '?s=' . (int) $ksess['ksess_tsession_id'] : ''),
        'handoff' => '/kiosk/evaluate.php',
        default => '/kiosk/',
    };
}

function kiosk_require_device(bool $soft = true): ?array
{
    global $kctx;
    if ($kctx->device === null && !$soft) {
        kiosk_redirect('/kiosk/');
    }
    return $kctx->device;
}

/** @param list<string> $roles */
function kiosk_require_session(array $roles): array
{
    global $kctx;
    try {
        $s = KioskAuth::session($kctx, $roles, true);
    } catch (KioskAuthException) {
        KioskAuth::clearSessionCookie();
        kiosk_redirect('/kiosk/');
    } catch (KioskRoleException $e) {
        $home = '/kiosk/';
        try {
            $home = kiosk_role_home($e->role, KioskAuth::session($kctx, KioskAuth::ROLES, false));
        } catch (\RuntimeException) {
            // fall back to /kiosk/
        }
        kiosk_redirect($home);
    }
    $kctx = $kctx->withKsess($s);
    return $s;
}

/** This device's live session (any role) or null; an invalid one is ended/cleared as usual. Never redirects. */
function kiosk_peek_session(bool $touch = false): ?array
{
    global $kctx;
    if (!isset($_COOKIE[KioskAuth::SESS_COOKIE]) || $kctx->device === null) {
        return null;
    }
    try {
        $s = KioskAuth::session($kctx, KioskAuth::ROLES, $touch);
    } catch (KioskAuthException) {
        KioskAuth::clearSessionCookie();
        return null;
    }
    $kctx = $kctx->withKsess($s);
    return $s;
}
