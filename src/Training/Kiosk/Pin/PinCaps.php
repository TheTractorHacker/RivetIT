<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KioskAlerts;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Anti-spray caps (plan A1, P3 spec §3.2 "Caps"), evaluated AFTER a settled failure only - never
 * at reserve, which checks only the stored cooldown/pause timestamps (an expired one lets sign-in
 * through again). Counts come from the ledger's pin.fail events; every time bound is a PHP literal.
 *
 *   kiosk 10 min      n10 >= kioskFailCap10m, no active cooldown  => cooldown 10 min 'fail_cap'
 *   global 10 min     g10 >= globalFailCap10m, not paused          => pause 10 min
 *   kiosk 24 h        n24 >= cap, (n24 - cap) % 5 == 0             => cooldown 10 x 2^(k-1) min (max 240) 'fail_cap_24h'
 *   distinct 24 h     d24 >= cap, (d24 - cap) % 5 == 0, d24 grew   => same escalation 'distinct_cap_24h'
 *   global 24 h       g24 >= cap, (g24 - cap) % 25 == 0            => pause 10 min
 * k = this kiosk's kiosk.cooldown events with a 24 h reason in the last 24 h, + 1. d24 counts only
 * contacts whose failure was not followed by a success; it "grows" only when this failure's
 * contact was not already among them, so one person retrying can't re-fire the same threshold.
 *
 * Each cap fires once per threshold crossing and re-arms after further failures. Writes are their
 * own transactions (kiosk row FOR UPDATE -> ledger); alerts go out after commit.
 */
final class PinCaps
{
    public const COOLDOWN_MIN = 10;
    public const PAUSE_MIN = 10;
    public const MAX_COOLDOWN_MIN = 240;
    public const REASONS_24H = ['fail_cap_24h', 'distinct_cap_24h'];

    /** @return list<string> what fired (tests): 'kiosk_10m','global_10m','kiosk_24h','distinct_24h','global_24h' */
    public static function afterFailure(KioskCtx $k, int $kioskId, ?int $contactId = null): array
    {
        $db = $k->db();
        $ks = $k->ks;
        $since10 = KTime::plus(-600);
        $since24 = KTime::plus(-86400);
        $fired = [];

        $g10 = self::count($db, null, $since10);
        $g24 = self::count($db, null, $since24);
        $n10 = $kioskId > 0 ? self::count($db, $kioskId, $since10) : 0;
        $n24 = $kioskId > 0 ? self::count($db, $kioskId, $since24) : 0;

        if ($kioskId > 0) {
            $kiosk = Db::one($db, 'SELECT kiosk_cooldown_until_utc FROM training_kiosks WHERE kiosk_id = ?', 'i', [$kioskId]);
            if ($n10 >= $ks->kioskFailCap10m && !KTime::isFuture($kiosk['kiosk_cooldown_until_utc'] ?? null)) {
                if (self::cooldown($k, $kioskId, self::COOLDOWN_MIN, 'fail_cap')) {
                    $fired[] = 'kiosk_10m';
                }
            }
            if ($n24 >= $ks->kioskFailCap24h && ($n24 - $ks->kioskFailCap24h) % 5 === 0) {
                if (self::cooldown($k, $kioskId, self::escalatedMinutes($db, $kioskId, $since24), 'fail_cap_24h')) {
                    $fired[] = 'kiosk_24h';
                }
            }
            $d24 = self::distinctUnresolved($db, $kioskId, $since24);
            $grew = $contactId === null || !self::hadEarlierUnresolved($db, $kioskId, $contactId, $since24);
            if ($grew && $d24 >= $ks->kioskDistinctCap24h && ($d24 - $ks->kioskDistinctCap24h) % 5 === 0) {
                if (self::cooldown($k, $kioskId, self::escalatedMinutes($db, $kioskId, $since24), 'distinct_cap_24h')) {
                    $fired[] = 'distinct_24h';
                }
            }
        }
        $settings = Db::one($db, 'SELECT config_training_pin_pause_until_utc AS p FROM settings WHERE company_id = 1');
        $paused = KTime::isFuture($settings['p'] ?? null);
        if ($g10 >= $ks->globalFailCap10m && !$paused) {
            if (self::pause($k, 'global_10m')) {
                $fired[] = 'global_10m';
                $paused = true;
            }
        }
        if ($g24 >= $ks->globalFailCap24h && ($g24 - $ks->globalFailCap24h) % 25 === 0) {
            if (self::pause($k, 'global_24h')) {
                $fired[] = 'global_24h';
            }
        }
        return $fired;
    }

    /** pin.fail events since $since (UTC literal), for one kiosk or everywhere. */
    public static function count(\mysqli $db, ?int $kioskId, string $since): int
    {
        if ($kioskId === null) {
            $r = Db::one($db, "SELECT COUNT(*) AS n FROM training_events WHERE tevent_type = 'pin.fail' AND tevent_at_utc >= ?", 's', [$since]);
        } else {
            $r = Db::one($db, "SELECT COUNT(*) AS n FROM training_events WHERE tevent_kiosk_id = ? AND tevent_type = 'pin.fail' AND tevent_at_utc >= ?", 'is', [$kioskId, $since]);
        }
        return (int) ($r['n'] ?? 0);
    }

    /** d24: distinct contacts with a failure on this kiosk not followed by a success. */
    public static function distinctUnresolved(\mysqli $db, int $kioskId, string $since): int
    {
        $r = Db::one($db, "SELECT COUNT(DISTINCT e.tevent_subject_contact_id) AS n
            FROM training_events e
            LEFT JOIN training_learner_credentials c ON c.tcred_contact_id = e.tevent_subject_contact_id
            WHERE e.tevent_kiosk_id = ? AND e.tevent_type = 'pin.fail' AND e.tevent_at_utc >= ?
              AND (c.tcred_last_success_at_utc IS NULL OR c.tcred_last_success_at_utc < e.tevent_at_utc)", 'is', [$kioskId, $since]);
        return (int) ($r['n'] ?? 0);
    }

    /** Did $contactId already have an unresolved failure here before its latest one? */
    private static function hadEarlierUnresolved(\mysqli $db, int $kioskId, int $contactId, string $since): bool
    {
        $r = Db::one($db, "SELECT COUNT(*) AS n
            FROM training_events e
            LEFT JOIN training_learner_credentials c ON c.tcred_contact_id = e.tevent_subject_contact_id
            WHERE e.tevent_kiosk_id = ? AND e.tevent_type = 'pin.fail' AND e.tevent_subject_contact_id = ? AND e.tevent_at_utc >= ?
              AND (c.tcred_last_success_at_utc IS NULL OR c.tcred_last_success_at_utc < e.tevent_at_utc)", 'iis', [$kioskId, $contactId, $since]);
        return (int) ($r['n'] ?? 0) > 1;
    }

    /** 10 x 2^(k-1) minutes, k = 24 h-reason cooldowns of this kiosk in 24 h + 1, capped at 240. */
    private static function escalatedMinutes(\mysqli $db, int $kioskId, string $since): int
    {
        $rows = Db::all($db, "SELECT tevent_payload_json FROM training_events WHERE tevent_kiosk_id = ? AND tevent_type = 'kiosk.cooldown' AND tevent_at_utc >= ?",
            'is', [$kioskId, $since]);
        $k = 1;
        foreach ($rows as $r) {
            $p = json_decode((string) $r['tevent_payload_json'], true);
            if (is_array($p) && in_array($p['reason'] ?? null, self::REASONS_24H, true)) {
                $k++;
            }
        }
        return (int) min(self::MAX_COOLDOWN_MIN, self::COOLDOWN_MIN * (2 ** min(10, $k - 1)));
    }

    /** Sets (or extends) the kiosk cooldown; kiosk.cooldown event; 'kiosk_cooldown' alert after commit. */
    private static function cooldown(KioskCtx $k, int $kioskId, int $minutes, string $reason): bool
    {
        $db = $k->db();
        $base = $k->eventBase();
        $until = KTime::plus($minutes * 60);
        $done = Db::tx($db, static function () use ($db, $kioskId, $until, $reason, $base): bool {
            $row = Db::one($db, 'SELECT kiosk_id, kiosk_cooldown_until_utc FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE', 'i', [$kioskId]);
            if ($row === null) {
                return false;
            }
            $cur = KTime::epoch($row['kiosk_cooldown_until_utc']);
            $newUntil = ($cur !== null && $cur > (KTime::epoch($until) ?? 0)) ? (string) $row['kiosk_cooldown_until_utc'] : $until;
            Db::exec($db, 'UPDATE training_kiosks SET kiosk_cooldown_until_utc = ?, kiosk_cooldown_reason = ? WHERE kiosk_id = ?', 'ssi', [$newUntil, $reason, $kioskId]);
            Ledger::append($db, array_merge($base, [
                'type' => 'kiosk.cooldown',
                'kiosk_id' => $kioskId,
                'entity_type' => 'kiosk',
                'entity_id' => $kioskId,
                'payload' => ['until_utc' => $newUntil, 'reason' => $reason],
            ]));
            return true;
        });
        if ($done) {
            KioskAlerts::notify($db, 'kiosk_cooldown');
        }
        return $done;
    }

    /** System-wide sign-in pause for 10 minutes; pin.pause event; 'pin_pause' alert after commit. */
    private static function pause(KioskCtx $k, string $cap): bool
    {
        $db = $k->db();
        $base = $k->eventBase();
        $until = KTime::plus(self::PAUSE_MIN * 60);
        Db::tx($db, static function () use ($db, $until, $cap, $base): void {
            Db::exec($db, 'UPDATE settings SET config_training_pin_pause_until_utc = ? WHERE company_id = 1', 's', [$until]);
            Ledger::append($db, array_merge($base, [
                'type' => 'pin.pause',
                'payload' => ['until_utc' => $until, 'cap' => $cap],
            ]));
        });
        KioskAlerts::notify($db, 'pin_pause');
        return true;
    }
}
