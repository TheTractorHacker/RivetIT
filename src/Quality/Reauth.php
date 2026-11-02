<?php

namespace ITFlow\Quality;

/**
 * Re-authentication for sensitive Quality actions (acknowledging a controlled document, answering a corrective action).
 *
 * A portal sign-in younger than the configured age (default 15 minutes) counts as fresh, as does a password confirmation inside that age. Otherwise the person must
 * type their portal password again. A login that has no local password (signed in through Microsoft, Odoo or OpenID Connect) cannot do that, so for them the answer is
 * "sign in again": the sign-in time is set when they do. Wrong passwords are counted per session and per person; five misses block the action for the rest of the
 * sign-in. The password is never logged or stored.
 */
final class Reauth
{
    public const MAX_MISSES = 5;

    /** @param array<string,mixed> $session $_SESSION */
    public static function fresh(array $session, int $minutes): bool
    {
        $at = max((int) ($session['portal_auth_time'] ?? 0), (int) ($session['portal_quality_reauth_at'] ?? 0));
        return $at > 0 && $at >= time() - max(1, $minutes) * 60;
    }

    /** True when this login has a local password to confirm with. */
    public static function hasLocalPassword(\mysqli $db, int $userId): bool
    {
        $st = $db->prepare('SELECT user_password FROM users WHERE user_id = ? AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL');
        $st->bind_param('i', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return $r && (string) $r['user_password'] !== '' && password_get_info((string) $r['user_password'])['algo'] !== null;
    }

    /**
     * Confirms the password for this user. Counts misses in $session and returns one of: ok | wrong | locked | no_password.
     *
     * @param array<string,mixed> $session by reference: portal_quality_reauth_at / portal_quality_misses are written
     */
    public static function confirm(\mysqli $db, array &$session, int $userId, string $password): string
    {
        if ((int) ($session['portal_quality_misses'] ?? 0) >= self::MAX_MISSES) {
            return 'locked';
        }
        $st = $db->prepare('SELECT user_password FROM users WHERE user_id = ? AND user_type = 2 AND user_status = 1 AND user_archived_at IS NULL');
        $st->bind_param('i', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        $hash = $r ? (string) $r['user_password'] : '';
        if ($hash === '' || password_get_info($hash)['algo'] === null) {
            return 'no_password';
        }
        if ($password !== '' && strlen($password) <= 1024 && password_verify($password, $hash)) {
            $session['portal_quality_reauth_at'] = time();
            $session['portal_quality_misses'] = 0;
            return 'ok';
        }
        $session['portal_quality_misses'] = (int) ($session['portal_quality_misses'] ?? 0) + 1;
        return 'wrong';
    }
}
