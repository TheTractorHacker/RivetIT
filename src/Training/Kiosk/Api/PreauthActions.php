<?php

namespace ITFlow\Training\Kiosk\Api;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Core\RateLimiter;
use ITFlow\Training\Kiosk\Device\CodeEnrollment;
use ITFlow\Training\Kiosk\Pin\CredentialRepo;
use ITFlow\Training\Kiosk\Pin\PinService;
use ITFlow\Training\Kiosk\Pin\Seam;

/**
 * Pre-auth kiosk actions (P3 spec §4.2 K2 rows): device adoption, name search, pick, PIN sign-in,
 * setup code and PIN create, and the [S] setup-code enrollment.
 *
 * A search result carries sig = KioskKeys::pickSig(kiosk, contact) (first 16 hex of an HMAC):
 * pick / pin_login / setup_code_verify / pin_create refuse a contact id without the sig THIS
 * device was given, so a tampered request can't walk contact ids (404 before any charge).
 * Every PIN-verifying handler pads its response to >= 800 ms in finally (§0.6) and unsets the PIN.
 */
final class PreauthActions
{
    public const SEARCH_MAX = 8;
    public const SEARCH_DAY_CAP = 2000;
    public const SEARCH_SYSTEM_PER_MIN = 300;
    public const PIN_PER_MIN = 20;
    public const ADOPT_PER_MIN = 10;

    /**
     * POST adopt_device {token, replace?} (anon).
     *
     * The token is looked up FIRST: a valid start-URL token (256 random bits, so it can't be
     * guessed) is never rate-limited, which keeps an anonymous flood from stopping real devices
     * (a Windows kiosk re-adopts after every public-browsing reset). Only malformed or unknown
     * tokens count in the shared bucket, and once it is full they get 429 instead of 403.
     */
    public static function adoptDevice(KioskCtx $k, ApiContext $a): array
    {
        $token = $a->input['token'] ?? null;
        try {
            if (!is_string($token)) {
                throw ApiException::validation(['token' => 'Invalid.']);
            }
            $r = KioskAuth::adopt($k->db(), $k->ks, $token, (bool) $a->bool('replace', false));
        } catch (ApiException $e) {
            if (in_array($e->errCode, ['validation', 'device_not_enrolled'], true)
                && !RateLimiter::hit($k->db(), 'adopt:bad', 60, self::ADOPT_PER_MIN)) {
                throw new ApiException(429, 'rate_limited', 'Too many tries. Wait a minute.');
            }
            throw $e;
        } finally {
            unset($token);
        }
        return ['label' => $r['label'], 'next' => '/kiosk/'];
    }

    /** POST enroll_code {code} (anon) [S]. */
    public static function enrollCode(KioskCtx $k, ApiContext $a): array
    {
        if (!RateLimiter::hit($k->db(), 'enroll_try:all', 60, 20)) {
            throw new ApiException(429, 'rate_limited', 'Too many tries. Wait a minute.');
        }
        try {
            return CodeEnrollment::redeem($k, $a->input['code'] ?? null);
        } finally {
            PinService::pad($k->startedNs);
        }
    }

    /**
     * POST search {q, scope?:'learner'|'trainer'|'checkin'} (device). >= 2 letters; up to 8 eligible
     * people whose every typed word starts a word of their name (accent-insensitive by collation);
     * department always, title only where two results share a name.
     */
    public static function search(KioskCtx $k, ApiContext $a): array
    {
        $db = $k->db();
        $kid = $k->kioskId();
        if (!RateLimiter::hit($db, "search:k$kid:m", 60, $k->ks->searchPerMin)
            || !RateLimiter::hit($db, "search:k$kid:d", 86400, self::SEARCH_DAY_CAP)
            || !RateLimiter::hit($db, 'search:all:m', 60, self::SEARCH_SYSTEM_PER_MIN)) {
            throw new ApiException(429, 'rate_limited', 'Too many searches. Wait a minute.');
        }
        $scope = $a->enum('scope', ['learner', 'trainer', 'checkin'], false) ?? 'learner';
        $q = is_string($a->input['q'] ?? null) ? (string) $a->input['q'] : '';
        $q = trim((string) preg_replace('/\s+/u', ' ', mb_substr($q, 0, 60, 'UTF-8')));
        $words = array_values(array_filter(explode(' ', $q), static fn($w) => $w !== ''));
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $q);
        if (mb_strlen($letters, 'UTF-8') < 2 || count($words) > 4) {
            return ['results' => [], 'more' => false];
        }
        $el = Seam::eligibleSql($k->core, 'c');
        $sql = 'SELECT c.contact_id, c.contact_name, c.contact_title, cl.client_name FROM contacts c '
            . $el['join'] . ' LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE ' . $el['where'];
        $types = $el['types'];
        $params = $el['params'];
        foreach ($words as $w) {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w);
            $sql .= ' AND (c.contact_name LIKE ? OR c.contact_name LIKE ?)';
            $types .= 'ss';
            $params[] = $like . '%';
            $params[] = '% ' . $like . '%';
        }
        $fetch = $scope === 'trainer' ? 60 : self::SEARCH_MAX + 1;
        $sql .= ' ORDER BY c.contact_name, c.contact_id LIMIT ' . $fetch;
        $rows = Db::all($db, $sql, $types, $params);
        if ($scope === 'trainer') {
            $rows = array_values(array_filter($rows, static fn($r) => Seam::isActiveTrainer($db, (int) $r['contact_id'])));
        }
        $more = count($rows) > self::SEARCH_MAX;
        $rows = array_slice($rows, 0, self::SEARCH_MAX);
        $nameCount = [];
        foreach ($rows as $r) {
            $key = mb_strtolower(trim((string) $r['contact_name']), 'UTF-8');
            $nameCount[$key] = ($nameCount[$key] ?? 0) + 1;
        }
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['contact_id'];
            $name = trim((string) $r['contact_name']);
            $dup = ($nameCount[mb_strtolower($name, 'UTF-8')] ?? 0) > 1;
            $title = trim((string) ($r['contact_title'] ?? ''));
            $out[] = [
                'contact_id' => $cid,
                'sig' => $k->keys->pickSig($kid, $cid),
                'name' => $name,
                'first' => KioskAuth::firstName($name),
                'dept' => trim((string) ($r['client_name'] ?? '')),
                'title' => $dup && $title !== '' ? $title : null,
                'initials' => KioskAuth::initials($name),
            ];
        }
        return ['results' => $out, 'more' => $more];
    }

    /**
     * POST pick {contact_id, sig, role?} (device): who is signing in and how.
     * {first, name, dept, prompt:'odoo'|'local'|'setup_needed'|'unavailable', locked:{minutes}|null, hard_locked, trainer_ok}
     */
    public static function pick(KioskCtx $k, ApiContext $a): array
    {
        $cid = self::pickedContact($k, $a);
        $person = self::person($k, $cid);
        $role = $a->enum('role', ['learner', 'trainer'], false) ?? 'learner';
        if ($role === 'trainer' && !Seam::isActiveTrainer($k->db(), $cid)) {
            throw new ApiException(403, 'not_trainer', 'Only active trainers can sign in here.');
        }
        $cred = (new CredentialRepo($k))->forPick($cid);
        if (!empty($cred['unavailable'])) {
            return $person + ['prompt' => 'unavailable', 'locked' => null, 'hard_locked' => false];
        }
        $prompt = $cred['effective_source'] === 'odoo' ? 'odoo' : ($cred['has_pin'] ? 'local' : 'setup_needed');
        $mins = KTime::minutesUntil($cred['tcred_locked_until_utc']);
        return $person + [
            'prompt' => $prompt,
            'locked' => $mins > 0 ? ['minutes' => $mins] : null,
            'hard_locked' => (int) $cred['tcred_hard_locked'] === 1,
        ];
    }

    /** POST pin_login {contact_id, sig, pin, role} (device). */
    public static function pinLogin(KioskCtx $k, ApiContext $a): array
    {
        try {
            self::pinRate($k);
            $cid = self::pickedContact($k, $a);
            $role = $a->enum('role', ['learner', 'trainer'], false) ?? 'learner';
            if ($role === 'trainer' && !Seam::isActiveTrainer($k->db(), $cid)) {
                throw new ApiException(403, 'not_trainer', 'Only active trainers can sign in here.');
            }
            $r = (new PinService($k))->login($cid, $a->input['pin'] ?? null, $role);
            unset($a->input['pin']);
            $err = $r['result']->toApi('pin');
            if ($err !== null) {
                throw $err;
            }
            KioskAuth::setSessionCookie((string) $r['token']);
            unset($r);
            return ['next' => $role === 'trainer' ? '/kiosk/trainer.php' : '/kiosk/me.php'];
        } finally {
            PinService::pad($k->startedNs);
        }
    }

    /** POST setup_code_verify {contact_id, sig, code} (device) => {setup_token}. */
    public static function setupCodeVerify(KioskCtx $k, ApiContext $a): array
    {
        try {
            self::pinRate($k);
            $cid = self::pickedContact($k, $a);
            $r = (new PinService($k))->setupCodeVerify($cid, $a->input['code'] ?? null);
            unset($a->input['code']);
            $err = $r['result']->toApi('setup');
            if ($err !== null) {
                throw $err;
            }
            return ['setup_token' => (string) $r['setup_token']];
        } finally {
            PinService::pad($k->startedNs);
        }
    }

    /** POST pin_create {contact_id, sig, setup_token, pin, pin2, role} (device) => as pin_login. */
    public static function pinCreate(KioskCtx $k, ApiContext $a): array
    {
        try {
            self::pinRate($k);
            $cid = self::pickedContact($k, $a);
            $role = $a->enum('role', ['learner', 'trainer'], false) ?? 'learner';
            if ($role === 'trainer' && !Seam::isActiveTrainer($k->db(), $cid)) {
                throw new ApiException(403, 'not_trainer', 'Only active trainers can sign in here.');
            }
            $tok = is_string($a->input['setup_token'] ?? null) ? (string) $a->input['setup_token'] : '';
            $r = (new PinService($k))->createFromSetup($cid, $tok, $a->input['pin'] ?? null, $a->input['pin2'] ?? null, $role);
            unset($a->input['pin'], $a->input['pin2'], $tok);
            KioskAuth::setSessionCookie((string) $r['token']);
            unset($r);
            return ['next' => $role === 'trainer' ? '/kiosk/trainer.php' : '/kiosk/me.php'];
        } finally {
            PinService::pad($k->startedNs);
        }
    }

    // ------------------------------------------------------------------------------------------

    /** The contact id of a request, only when its sig is the one THIS device was handed by search. */
    public static function pickedContact(KioskCtx $k, ApiContext $a): int
    {
        $raw = $a->input['contact_id'] ?? null;
        $cid = (is_int($raw) || (is_string($raw) && preg_match('/^[1-9][0-9]{0,9}$/D', $raw) === 1)) ? intval($raw) : 0;
        $sig = $a->input['sig'] ?? null;
        if ($cid < 1 || !is_string($sig) || $k->kioskId() < 1 || !hash_equals($k->keys->pickSig($k->kioskId(), $cid), $sig)) {
            throw ApiException::notFound('That person was not found.');
        }
        if (!Seam::isEligible($k->db(), $cid, $k->core)) {
            throw ApiException::notFound('That person was not found.');
        }
        return $cid;
    }

    /** {first, name, dept, initials} of an eligible contact. */
    public static function person(KioskCtx $k, int $cid): array
    {
        $r = Db::one($k->db(), 'SELECT c.contact_name, cl.client_name FROM contacts c LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE c.contact_id = ?', 'i', [$cid]);
        $name = trim((string) ($r['contact_name'] ?? ''));
        return ['first' => KioskAuth::firstName($name), 'name' => $name, 'dept' => trim((string) ($r['client_name'] ?? '')), 'initials' => KioskAuth::initials($name)];
    }

    private static function pinRate(KioskCtx $k): void
    {
        if (!RateLimiter::hit($k->db(), 'pin:k' . $k->kioskId() . ':m', 60, self::PIN_PER_MIN)) {
            throw new ApiException(429, 'rate_limited', 'Too many tries. Wait a minute.');
        }
    }
}
