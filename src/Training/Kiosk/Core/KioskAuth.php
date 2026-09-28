<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Device\DeviceLifecycle;

/**
 * Device and kiosk-session authentication (P3 spec §3.1, §8 "Device-only surface", "Learner
 * isolation"). There is NO PHP session under /kiosk/: the device is a long-lived cookie holding a
 * random token whose sha256 is training_kiosks.kiosk_token_hash, and a signed-in person is a
 * second cookie whose sha256 is training_kiosk_sessions.ksess_token_hash. Tokens are
 * base64url(32 random bytes) = 43 characters and are never stored, logged or echoed.
 *
 * Cookies are Path=/kiosk/; Secure; HttpOnly; SameSite=Strict (the __Secure- prefix enforces
 * Secure). The agent app's cookies are Path=/ and are never read here (expireAgentCookies() may
 * only expire them, P-11).
 *
 * A19: a device is valid only while it is active, its asset exists, is not archived and is of an
 * allowed type, and the asset's assignment still equals the personal-owner snapshot taken at
 * enrollment (unassigned or reassigned => locked out until re-issued), with an eligible owner.
 * An UNLISTED device (kiosk_asset_id NULL, 2.6.94) has no asset checks and is never personal.
 * A TEMPORARY device (kiosk_expires_at_utc, 2.6.94) past its time is 'expired': device() revokes
 * it there and then (DeviceLifecycle::expire - its open session ends, its token and start URL stop
 * working), exactly as if an admin had revoked it.
 */
final class KioskAuth
{
    public const DEV_COOKIE = '__Secure-MWK_DEV';
    public const SESS_COOKIE = '__Secure-MWK_S';
    public const LANG_COOKIE = '__Secure-MWK_LANG';
    public const TOKEN_RE = '/^[A-Za-z0-9_-]{43}$/D';

    public const ROLES = ['learner', 'trainer', 'checkin', 'handoff'];
    public const END_REASONS = ['done', 'idle', 'absolute', 'replaced', 'revoked', 'device', 'contact_ineligible', 'pin_locked',
                                'checkin_enter', 'checkin_exit', 'handoff_enter', 'handoff_exit', 'error'];
    /** Extra seconds past the idle limit before the server ends a session (client warns first). */
    public const IDLE_GRACE_S = 30;
    public const DEVICE_TOUCH_S = 60;
    public const SESSION_TOUCH_S = 15;
    public const DEVICE_COOKIE_MAX_AGE = 34560000;
    public const LANG_COOKIE_MAX_AGE = 31536000;
    public const AGENT_COOKIES = ['PHPSESSID', 'rememberme', 'user_encryption_session_key', 'user_extension_key'];

    private const DEVICE_SELECT = 'SELECT k.kiosk_id, k.kiosk_asset_id, k.kiosk_asset_type, k.kiosk_asset_serial, k.kiosk_personal_contact_id, k.kiosk_force_shared,
            k.kiosk_label, k.kiosk_default_client_id, k.kiosk_status, k.kiosk_enroll_method, k.kiosk_token_hash, k.kiosk_enrolled_at_utc,
            k.kiosk_last_seen_at_utc, k.kiosk_cooldown_until_utc, k.kiosk_cooldown_reason, k.kiosk_expires_at_utc,
            a.asset_id AS asset_row_id, a.asset_name, a.asset_type, a.asset_serial, a.asset_archived_at, a.asset_contact_id, a.asset_client_id
        FROM training_kiosks k LEFT JOIN assets a ON a.asset_id = k.kiosk_asset_id';

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    // ---- Device ------------------------------------------------------------------------------

    /** The end time (UTC) of a temporary device that device() found expired - and revoked - in THIS request, else null. */
    private static ?string $expiredAtUtc = null;

    /**
     * When this request's device turned out to be a temporary device whose time is up: its end time
     * (UTC). The kiosk then says "This device's training time ended at …" instead of the generic
     * "not set up" (index.php, and the ?ended= redirects of guard.php and the API router).
     */
    public static function expiredAt(): ?string
    {
        return self::$expiredAtUtc;
    }

    /**
     * The device from the cookie, or null. $reason explains a null: missing | unknown | revoked |
     * pending | expired | asset_missing | asset_archived | asset_type | assignment_changed | owner_ineligible.
     * Touches last_seen/UA at most once per 60 s unless $touch is false. An 'expired' temporary
     * device is revoked here (own transaction; never call this inside Db::tx).
     */
    public static function device(\mysqli $db, KioskSettings $ks, ?string &$reason = null, bool $touch = true): ?array
    {
        $reason = null;
        $tok = $_COOKIE[self::DEV_COOKIE] ?? null;
        if (!is_string($tok) || preg_match(self::TOKEN_RE, $tok) !== 1) {
            $reason = 'missing';
            return null;
        }
        return self::deviceByTokenHash($db, $ks, self::tokenHash($tok), $reason, $touch);
    }

    /** device() for a known token hash (adoption, tests). */
    public static function deviceByTokenHash(\mysqli $db, KioskSettings $ks, string $hash, ?string &$reason = null, bool $touch = false): ?array
    {
        $reason = null;
        $row = Db::one($db, self::DEVICE_SELECT . ' WHERE k.kiosk_token_hash = ?', 's', [$hash]);
        if ($row === null) {
            $reason = 'unknown';
            return null;
        }
        $reason = self::invalidReason($db, $row);
        if ($reason === 'expired') {
            self::$expiredAtUtc = (string) $row['kiosk_expires_at_utc'];
            if (Db::depth() === 0) {
                // A temporary device's time is up: treated exactly like a revoked one from this request on.
                DeviceLifecycle::expire($db, (int) $row['kiosk_id']);
            }
        }
        if ($reason !== null) {
            return null;
        }
        $personal = null;
        $pid = (int) ($row['kiosk_personal_contact_id'] ?? 0);
        if ($pid > 0 && $row['kiosk_asset_id'] !== null) {
            $c = Db::one($db, 'SELECT contact_name FROM contacts WHERE contact_id = ?', 'i', [$pid]);
            $name = trim((string) ($c['contact_name'] ?? ''));
            $personal = ['contact_id' => $pid, 'first' => self::firstName($name), 'name' => $name];
        }
        if ($touch) {
            $now = KTime::now();
            $before = KTime::plus(-self::DEVICE_TOUCH_S);
            if ($row['kiosk_last_seen_at_utc'] === null || (KTime::epoch($row['kiosk_last_seen_at_utc']) ?? 0) < (KTime::epoch($before) ?? 0)) {
                Db::exec($db, 'UPDATE training_kiosks SET kiosk_last_seen_at_utc = ?, kiosk_last_user_agent = ?
                    WHERE kiosk_id = ? AND (kiosk_last_seen_at_utc IS NULL OR kiosk_last_seen_at_utc < ?)',
                    'ssis', [$now, Text::clip($_SERVER['HTTP_USER_AGENT'] ?? null, 255), (int) $row['kiosk_id'], $before]);
                $row['kiosk_last_seen_at_utc'] = $now;
            }
        }
        $row['kiosk_id'] = (int) $row['kiosk_id'];
        $row['personal'] = $personal;
        return $row;
    }

    /**
     * Why a kiosk row (DEVICE_SELECT shape) is not a usable device, or null when it is.
     * Also used by the agent Devices list ('assignment_changed' is shown there).
     */
    public static function invalidReason(\mysqli $db, array $row): ?string
    {
        $status = (string) ($row['kiosk_status'] ?? '');
        if ($status !== 'active') {
            return $status === 'pending' ? 'pending' : 'revoked';
        }
        if (DeviceLifecycle::isExpired($row['kiosk_expires_at_utc'] ?? null)) {
            return 'expired';
        }
        if (($row['kiosk_asset_id'] ?? null) === null) {
            // Unlisted device (not in Assets): no asset to check, and it is never a personal device.
            return (int) ($row['kiosk_personal_contact_id'] ?? 0) > 0 ? 'assignment_changed' : null;
        }
        if ($row['asset_row_id'] === null) {
            return 'asset_missing';
        }
        if ($row['asset_archived_at'] !== null) {
            return 'asset_archived';
        }
        if (!in_array((string) $row['asset_type'], KioskSettings::ASSET_TYPES, true)) {
            return 'asset_type';
        }
        if ((int) ($row['kiosk_force_shared'] ?? 0) === 1) {
            // Explicitly forced shared (owner override, 2026-09-28): the asset's own assignment is
            // deliberately ignored, so a mismatch here is expected, not a sign the device moved to
            // someone else without being re-issued - skip the usual A19 lockout for it.
            return null;
        }
        $assigned = (int) ($row['asset_contact_id'] ?? 0);
        $snap = (int) ($row['kiosk_personal_contact_id'] ?? 0);
        if ($assigned !== $snap) {
            return 'assignment_changed';
        }
        if ($snap > 0 && !Eligibility::isEligible($db, $snap)) {
            return 'owner_ineligible';
        }
        return null;
    }

    /**
     * Pre-auth adoption of a start-URL token (POST adopt_device). A valid active token sets the
     * device cookie. A DIFFERENT valid device already on this browser and $replace false =>
     * 409 device_replace_confirm {label}. Returns ['label','kiosk_id','personal'=>bool].
     */
    public static function adopt(\mysqli $db, KioskSettings $ks, string $token, bool $replace): array
    {
        if (preg_match(self::TOKEN_RE, $token) !== 1) {
            throw new ApiException(422, 'validation', 'That setup link is not valid.', ['token' => 'Invalid.']);
        }
        $why = null;
        $new = self::deviceByTokenHash($db, $ks, self::tokenHash($token), $why, false);
        if ($new === null) {
            throw new ApiException(403, 'device_not_enrolled', 'This setup link is no longer valid. Ask an admin for a new one.');
        }
        $cur = self::device($db, $ks, $why, false);
        if ($cur !== null && (int) $cur['kiosk_id'] !== (int) $new['kiosk_id']) {
            if (!$replace) {
                throw new ApiException(409, 'device_replace_confirm', 'This device is already set up for training.', [], ['label' => (string) $cur['kiosk_label']]);
            }
        }
        if ($cur === null || (int) $cur['kiosk_id'] !== (int) $new['kiosk_id']) {
            // A session of another device must not ride along onto this one.
            self::clearSessionCookie();
        }
        self::setDeviceCookie($token);
        return ['label' => (string) $new['kiosk_label'], 'kiosk_id' => (int) $new['kiosk_id'], 'personal' => $new['personal'] !== null];
    }

    // ---- Session -----------------------------------------------------------------------------

    /**
     * Validates the kiosk-session cookie against THIS device (see the class comment in the spec
     * §3.1). Ends the ksess (own tx, guard NULL, ledger ksession.end) and throws
     * KioskAuthException(reason) on device mismatch, idle, absolute expiry, an ineligible
     * contact or a hard-locked credential; 'missing'/'ended' throw without a write. A role not in
     * $roles throws KioskRoleException and ends NOTHING. $touch refreshes last_seen (throttled
     * to 15 s, PHP literals). Returns the ksess row plus contact_name, first, initials, client_id, dept.
     */
    public static function session(KioskCtx $k, array $roles, bool $touch = true): array
    {
        $tok = $_COOKIE[self::SESS_COOKIE] ?? null;
        if (!is_string($tok) || preg_match(self::TOKEN_RE, $tok) !== 1) {
            throw new KioskAuthException('missing');
        }
        $db = $k->db();
        $row = Db::one($db, 'SELECT s.ksess_id, s.ksess_kiosk_id, s.ksess_contact_id, s.ksess_role, s.ksess_tsession_id, s.ksess_token_hash,
                s.ksess_pin_source, s.ksess_odoo_employee_id, s.ksess_language, s.ksess_idle_limit_s, s.ksess_started_at_utc,
                s.ksess_last_seen_at_utc, s.ksess_absolute_until_utc, s.ksess_ended_at_utc, s.ksess_end_reason,
                c.contact_name, c.contact_client_id, c.contact_archived_at, cl.client_name
            FROM training_kiosk_sessions s
            JOIN contacts c ON c.contact_id = s.ksess_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            WHERE s.ksess_token_hash = ?', 's', [self::tokenHash($tok)]);
        if ($row === null) {
            throw new KioskAuthException('missing');
        }
        if ($row['ksess_ended_at_utc'] !== null) {
            throw new KioskAuthException('ended');
        }
        $reason = self::sessionEndReason($k, $row);
        if ($reason !== null) {
            self::endSessionAs($db, (int) $row['ksess_id'], $reason, [
                'actor_type' => 'kiosk',
                'kiosk_id' => (int) $row['ksess_kiosk_id'],
                'ksess_id' => (int) $row['ksess_id'],
                'user_agent' => $k->core->userAgent,
            ]);
            throw new KioskAuthException($reason);
        }
        if (!in_array((string) $row['ksess_role'], $roles, true)) {
            throw new KioskRoleException((string) $row['ksess_role']);
        }
        if ($touch) {
            $now = KTime::now();
            $before = KTime::plus(-self::SESSION_TOUCH_S);
            if ((KTime::epoch($row['ksess_last_seen_at_utc']) ?? 0) < (KTime::epoch($before) ?? 0)) {
                Db::exec($db, 'UPDATE training_kiosk_sessions SET ksess_last_seen_at_utc = ?
                    WHERE ksess_id = ? AND ksess_ended_at_utc IS NULL AND ksess_last_seen_at_utc < ?', 'sis', [$now, (int) $row['ksess_id'], $before]);
                $row['ksess_last_seen_at_utc'] = $now;
            }
        }
        return self::enrich($row);
    }

    /** Null when the (open) ksess row is still valid for this request; otherwise the end reason. */
    private static function sessionEndReason(KioskCtx $k, array $row): ?string
    {
        if ($k->device === null || (int) $k->device['kiosk_id'] !== (int) $row['ksess_kiosk_id']) {
            return 'device';
        }
        $now = microtime(true);
        $seen = KTime::epoch($row['ksess_last_seen_at_utc']) ?? 0.0;
        if ($now - $seen > (int) $row['ksess_idle_limit_s'] + self::IDLE_GRACE_S) {
            return 'idle';
        }
        if ($now > (KTime::epoch($row['ksess_absolute_until_utc']) ?? 0.0)) {
            return 'absolute';
        }
        $cid = (int) $row['ksess_contact_id'];
        if ($row['contact_archived_at'] !== null || !Eligibility::isEligible($k->db(), $cid, $k->core)) {
            return 'contact_ineligible';
        }
        $cred = Db::one($k->db(), 'SELECT tcred_hard_locked FROM training_learner_credentials WHERE tcred_contact_id = ?', 'i', [$cid]);
        if ($cred !== null && (int) $cred['tcred_hard_locked'] === 1) {
            return 'pin_locked';
        }
        return null;
    }

    private static function enrich(array $row): array
    {
        $name = trim((string) ($row['contact_name'] ?? ''));
        foreach (['ksess_id', 'ksess_kiosk_id', 'ksess_contact_id', 'ksess_idle_limit_s'] as $c) {
            $row[$c] = (int) $row[$c];
        }
        $row['ksess_tsession_id'] = $row['ksess_tsession_id'] === null ? null : (int) $row['ksess_tsession_id'];
        $row['first'] = self::firstName($name);
        $row['initials'] = self::initials($name);
        $row['client_id'] = (int) ($row['contact_client_id'] ?? 0);
        $row['dept'] = (string) ($row['client_name'] ?? '');
        unset($row['contact_archived_at']);
        return $row;
    }

    /**
     * INSIDE the caller's Db::tx: kiosk row FOR UPDATE; the kiosk's open ksess (if any) is ended
     * 'replaced' (guard NULL); a new ksess is INSERTed (guard 1). Returns
     * ['row' => ksess row, 'token' => plain token, 'ended' => ?int ended ksess id]. The caller appends
     * the ksession.end/start events LAST (see startEvents()) and calls setSessionCookie() after COMMIT.
     *
     * $o: source ('local'|'odoo'), emp_id (?int), lang, idle_s, max_min, tsession_id (?int).
     * idle_s / max_min default to KioskSettings::limitsFor($role) when $o['ks'] is a KioskSettings.
     */
    public static function startSession(\mysqli $db, array $device, int $contactId, string $role, array $o): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('KioskAuth::startSession must run inside Db::tx');
        }
        if (!in_array($role, self::ROLES, true) || $contactId < 1) {
            throw new \InvalidArgumentException('KioskAuth::startSession: bad role or contact');
        }
        if ((!isset($o['idle_s']) || !isset($o['max_min'])) && ($o['ks'] ?? null) instanceof KioskSettings) {
            $o += $o['ks']->limitsFor($role);
        }
        $idle = (int) ($o['idle_s'] ?? 0);
        $maxMin = (int) ($o['max_min'] ?? 0);
        if ($idle < 1 || $maxMin < 1) {
            throw new \InvalidArgumentException('KioskAuth::startSession: idle_s and max_min are required');
        }
        $source = (string) ($o['source'] ?? 'local');
        if (!in_array($source, ['odoo', 'local'], true)) {
            throw new \InvalidArgumentException('KioskAuth::startSession: bad source');
        }
        $kioskId = (int) $device['kiosk_id'];
        $k = Db::one($db, "SELECT kiosk_id, kiosk_expires_at_utc FROM training_kiosks WHERE kiosk_id = ? AND kiosk_status = 'active' FOR UPDATE", 'i', [$kioskId]);
        if ($k === null || DeviceLifecycle::isExpired($k['kiosk_expires_at_utc'])) {
            throw new ApiException(403, 'device_not_enrolled', 'This device is not set up for training.');
        }
        $now = KTime::now();
        $ended = null;
        $open = Db::one($db, 'SELECT ksess_id FROM training_kiosk_sessions WHERE ksess_kiosk_id = ? AND ksess_open_guard = 1 FOR UPDATE', 'i', [$kioskId]);
        if ($open !== null) {
            $ended = (int) $open['ksess_id'];
            Db::exec($db, "UPDATE training_kiosk_sessions SET ksess_ended_at_utc = ?, ksess_end_reason = 'replaced', ksess_open_guard = NULL
                WHERE ksess_id = ?", 'si', [$now, $ended]);
        }
        $token = self::newToken();
        $lang = KioskStrings::lang(isset($o['lang']) ? (string) $o['lang'] : 'en');
        $empId = isset($o['emp_id']) && $o['emp_id'] !== null ? (int) $o['emp_id'] : null;
        $tsid = isset($o['tsession_id']) && $o['tsession_id'] !== null ? (int) $o['tsession_id'] : null;
        $id = Db::insert($db, 'INSERT INTO training_kiosk_sessions (ksess_kiosk_id, ksess_contact_id, ksess_role, ksess_tsession_id, ksess_token_hash,
                ksess_pin_source, ksess_odoo_employee_id, ksess_language, ksess_idle_limit_s, ksess_started_at_utc, ksess_last_seen_at_utc,
                ksess_absolute_until_utc, ksess_open_guard, ksess_user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
            'iisissisissss',
            [$kioskId, $contactId, $role, $tsid, self::tokenHash($token), $source, $empId, $lang, $idle, $now, $now,
             KTime::plus($maxMin * 60), Text::clip($_SERVER['HTTP_USER_AGENT'] ?? null, 255)]);
        $row = Db::one($db, 'SELECT ksess_id, ksess_kiosk_id, ksess_contact_id, ksess_role, ksess_tsession_id, ksess_pin_source, ksess_odoo_employee_id,
                ksess_language, ksess_idle_limit_s, ksess_started_at_utc, ksess_absolute_until_utc
            FROM training_kiosk_sessions WHERE ksess_id = ?', 'i', [$id]);
        return ['row' => $row, 'token' => $token, 'ended' => $ended];
    }

    /**
     * The ledger events for a startSession() result, to append LAST in the same transaction:
     * ksession.end {reason:'replaced'} for a replaced session, then ksession.start {role, source, tsession_id?}.
     * Actor: the signed-in contact on this kiosk (§0.9).
     *
     * @return list<array>
     */
    public static function startEvents(array $started, ?string $userAgent = null): array
    {
        $row = $started['row'];
        $cid = (int) $row['ksess_contact_id'];
        $base = ['actor_type' => 'contact', 'actor_contact_id' => $cid, 'kiosk_id' => (int) $row['ksess_kiosk_id'],
                 'subject_contact_id' => $cid, 'user_agent' => $userAgent];
        $events = [];
        if ($started['ended'] !== null) {
            $events[] = array_merge($base, ['type' => 'ksession.end', 'ksess_id' => (int) $started['ended'], 'entity_type' => 'ksess',
                                 'entity_id' => (int) $started['ended'], 'subject_contact_id' => null, 'payload' => ['reason' => 'replaced']]);
        }
        $payload = ['role' => (string) $row['ksess_role'], 'source' => (string) $row['ksess_pin_source']];
        if ($row['ksess_tsession_id'] !== null) {
            $payload['tsession_id'] = (int) $row['ksess_tsession_id'];
        }
        $events[] = array_merge($base, ['type' => 'ksession.start', 'ksess_id' => (int) $row['ksess_id'], 'entity_type' => 'ksess',
                             'entity_id' => (int) $row['ksess_id'], 'payload' => $payload]);
        return $events;
    }

    /** Own tx; guard NULL; ledger ksession.end {reason}; idempotent (an ended session is left alone). */
    public static function endSession(KioskCtx $k, int $ksessId, string $reason): void
    {
        $actor = $k->ksessId() === $ksessId
            ? $k->eventBase()
            : ['actor_type' => 'kiosk', 'kiosk_id' => $k->kioskId() > 0 ? $k->kioskId() : null, 'ksess_id' => $ksessId, 'user_agent' => $k->core->userAgent];
        self::endSessionAs($k->db(), $ksessId, $reason, $actor);
    }

    /**
     * endSession() with an explicit ledger actor (cron: ['actor_type' => 'system']). Returns true
     * when this call ended the session, false when it was already ended or does not exist.
     */
    public static function endSessionAs(\mysqli $db, int $ksessId, string $reason, array $actor): bool
    {
        if (!in_array($reason, self::END_REASONS, true)) {
            throw new \InvalidArgumentException("KioskAuth: unknown end reason '$reason'");
        }
        return Db::tx($db, static function () use ($db, $ksessId, $reason, $actor): bool {
            $row = Db::one($db, 'SELECT ksess_id, ksess_kiosk_id, ksess_contact_id, ksess_ended_at_utc FROM training_kiosk_sessions WHERE ksess_id = ? FOR UPDATE', 'i', [$ksessId]);
            if ($row === null || $row['ksess_ended_at_utc'] !== null) {
                return false;
            }
            Db::exec($db, 'UPDATE training_kiosk_sessions SET ksess_ended_at_utc = ?, ksess_end_reason = ?, ksess_open_guard = NULL WHERE ksess_id = ?',
                'ssi', [KTime::now(), $reason, $ksessId]);
            Ledger::append($db, array_merge($actor, [
                'type' => 'ksession.end',
                'kiosk_id' => (int) $row['ksess_kiosk_id'],
                'ksess_id' => $ksessId,
                'subject_contact_id' => (int) $row['ksess_contact_id'],
                'entity_type' => 'ksess',
                'entity_id' => $ksessId,
                'payload' => ['reason' => $reason],
            ]));
            return true;
        });
    }

    // ---- Cookies -----------------------------------------------------------------------------

    public static function setDeviceCookie(string $t): void
    {
        self::cookie(self::DEV_COOKIE, $t, time() + self::DEVICE_COOKIE_MAX_AGE);
    }

    public static function clearDeviceCookie(): void
    {
        if (isset($_COOKIE[self::DEV_COOKIE])) {
            self::cookie(self::DEV_COOKIE, '', 1);
        }
    }

    public static function setSessionCookie(string $t): void
    {
        self::cookie(self::SESS_COOKIE, $t, 0);
    }

    public static function clearSessionCookie(): void
    {
        if (isset($_COOKIE[self::SESS_COOKIE])) {
            self::cookie(self::SESS_COOKIE, '', 1);
        }
    }

    /** Pre-auth language choice for this device (1 year). */
    public static function setLangCookie(string $lang): void
    {
        self::cookie(self::LANG_COOKIE, KioskStrings::lang($lang), time() + self::LANG_COOKIE_MAX_AGE);
    }

    /** After a sign-out the sign-in screens go back to the default language (the next person gets their own saved one at sign-in). */
    public static function clearLangCookie(): void
    {
        if (isset($_COOKIE[self::LANG_COOKIE])) {
            self::cookie(self::LANG_COOKIE, '', 1);
        }
    }

    /**
     * [S] P-11: on an ENROLLED device (the caller checks device() first) expire stray agent
     * cookies left by the enrollment step: Set-Cookie <name>=; Max-Age=0; Path=/ for each one
     * present. Their values are never read.
     */
    public static function expireAgentCookies(): void
    {
        foreach (self::AGENT_COOKIES as $name) {
            if (isset($_COOKIE[$name]) && !headers_sent()) {
                header('Set-Cookie: ' . $name . '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/; Secure; HttpOnly', false);
            }
        }
    }

    private static function cookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        $opts = ['path' => '/kiosk/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'];
        if ($expires !== 0) {
            $opts['expires'] = $expires;
        }
        setcookie($name, $value, $opts);
        if ($value === '') {
            unset($_COOKIE[$name]);
        } else {
            $_COOKIE[$name] = $value;
        }
    }

    // ---- Names -------------------------------------------------------------------------------

    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        return (string) ($parts[0] ?? '');
    }

    public static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], static fn($p) => $p !== ''));
        if ($parts === []) {
            return '?';
        }
        $first = mb_substr($parts[0], 0, 1, 'UTF-8');
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
        return mb_strtoupper($first . $last, 'UTF-8');
    }
}
