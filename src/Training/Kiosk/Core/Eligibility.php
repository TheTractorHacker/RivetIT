<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;

/**
 * "Is this person allowed to use the kiosk?" for KioskAuth's per-request checks (personal device
 * owner, signed-in contact). The P2 roster rule is reached only through
 * Kiosk\Bridge\RecordsBridge (§0.7), whose own fallback before 2.6.92 is the spec's: not archived
 * and in a department (contact_client_id > 0).
 * Memoised per request. A bridge failure fails CLOSED (not eligible) and logs the class only.
 */
final class Eligibility
{
    /** @var array<int, bool> */
    private static array $memo = [];

    public static function isEligible(\mysqli $db, int $contactId, ?Ctx $c = null): bool
    {
        if ($contactId < 1) {
            return false;
        }
        if (array_key_exists($contactId, self::$memo)) {
            return self::$memo[$contactId];
        }
        try {
            $ok = (new RecordsBridge($c ?? SystemCtx::make($db, 0, 'training_kiosk')))->isEligible($contactId);
            return self::$memo[$contactId] = $ok;
        } catch (\Throwable $e) {
            error_log('Kiosk eligibility: ' . get_class($e));
            return self::$memo[$contactId] = false;
        }
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$memo = [];
    }
}
