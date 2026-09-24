<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\SystemCtx;

/**
 * "Is this person allowed to use the kiosk?" for KioskAuth's per-request checks (personal device
 * owner, signed-in contact). The P2 roster rule is reached only through K3's
 * Kiosk\Bridge\RecordsBridge (§0.7); until that class exists and reports available(), the
 * fallback is the spec's: not archived and in a department (contact_client_id > 0).
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
        $bridge = '\\ITFlow\\Training\\Kiosk\\Bridge\\RecordsBridge';
        try {
            if (class_exists($bridge) && $bridge::available($db)) {
                $ok = (new $bridge($c ?? SystemCtx::make($db, 0, 'training_kiosk')))->isEligible($contactId);
                return self::$memo[$contactId] = (bool) $ok;
            }
            $row = Db::one($db, 'SELECT 1 AS ok FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL AND contact_client_id > 0', 'i', [$contactId]);
            return self::$memo[$contactId] = $row !== null;
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
