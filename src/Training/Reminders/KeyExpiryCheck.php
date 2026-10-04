<?php

namespace ITFlow\Training\Reminders;

use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\Recipients;
use ITFlow\Training\Core\Db;

/**
 * S9: warn admins before the Odoo API key that Training uses expires (spec §3.5). Odoo keys are
 * created with an expiry date; when it passes, the directory sync, Odoo PIN sign-in and the
 * write-back all start failing with 401s. The date is typed in by an admin on the Odoo write-back
 * card (tauto_odoo_key_expires_on); RivetIT cannot read it from Odoo.
 *
 * Within 14 days of the date, every admin gets a 'key_expiry' notification (type 'Training
 * Odoo', which is never muted with the digests): about once a week while more than 3 days are
 * left, then daily until a week after it expired, then weekly again. Notify::once dedupes per
 * admin and day, so a second worker run the same day sends nothing.
 */
final class KeyExpiryCheck
{
    public const WARN_DAYS = 14;
    public const DAILY_FROM_DAYS = 3;
    public const DAILY_UNTIL_DAYS_AFTER = 7;
    /** Where the key is replaced (Admin › Integrations › Odoo holds the Odoo connection). */
    public const ACTION = '/admin/settings_integrations.php?tab=odoo';

    /**
     * @param array $s AutomationSettings::loadWorker() row (tauto_odoo_key_expires_on)
     * @return bool true when at least one admin was notified
     */
    public static function run(\mysqli $db, Notify $n, array $s, string $todayLocal): bool
    {
        $left = self::daysLeft($s['tauto_odoo_key_expires_on'] ?? null, $todayLocal);
        if ($left === null || $left > self::WARN_DAYS) {
            return false;
        }
        $daily = $left <= self::DAILY_FROM_DAYS && $left >= -self::DAILY_UNTIL_DAYS_AFTER;
        $text = self::text((string) $s['tauto_odoo_key_expires_on'], $left);
        $any = false;
        foreach (Recipients::withLevel($db, 3) as $r) {
            if (!$r['is_admin']) {
                continue;
            }
            $uid = (int) $r['user_id'];
            try {
                if (!$daily && self::sentWithin($db, $uid, $todayLocal, 6)) {
                    continue;
                }
                if ($n->once($uid, $todayLocal, 'key_expiry', 'Training Odoo', $text, self::ACTION, ['days_left' => $left])) {
                    $any = true;
                }
            } catch (\Throwable $e) {
                error_log('Training key expiry: user ' . $uid . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        return $any;
    }

    /** Whole days from $todayLocal to the expiry date (negative once it has passed); null when unset or not a date. */
    public static function daysLeft(mixed $expiresOn, string $todayLocal): ?int
    {
        if (!is_string($expiresOn) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiresOn) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $todayLocal) !== 1) {
            return null;
        }
        $z = new \DateTimeZone('UTC');
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $todayLocal, $z);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn, $z);
        if ($a === false || $b === false || $b->format('Y-m-d') !== $expiresOn) {
            return null;
        }
        return intdiv($b->getTimestamp() - $a->getTimestamp(), 86400);
    }

    public static function text(string $expiresOn, int $left): string
    {
        $date = date('M j, Y', (int) strtotime($expiresOn));
        $when = match (true) {
            $left > 1 => "expires on $date ($left days from now)",
            $left === 1 => "expires tomorrow ($date)",
            $left === 0 => "expires today ($date)",
            default => "expired on $date",
        };
        return "The Odoo API key that Training uses $when. When it expires, the Odoo directory sync, Odoo PIN sign-in and the Odoo write-back stop working. "
            . 'Create a new key in Odoo, save it in Admin › Integrations › Directory Sync, then update "Odoo key expires on" in Admin › Training.';
    }

    private static function sentWithin(\mysqli $db, int $userId, string $todayLocal, int $days): bool
    {
        $r = Db::one($db, "SELECT 1 AS x FROM training_reminder_log WHERE trem_user_id = ? AND trem_kind = 'key_expiry'
            AND trem_date > DATE_SUB(?, INTERVAL ? DAY) AND trem_date <= ? LIMIT 1", 'isis', [$userId, $todayLocal, $days + 1, $todayLocal]);
        return $r !== null;
    }
}
