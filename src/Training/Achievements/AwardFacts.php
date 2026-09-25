<?php

namespace ITFlow\Training\Achievements;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;

/**
 * Where the award engine gets its RecordsFacts (P3 spec §3.5, §3.7, §0.7).
 *
 * The facts come from K3's \ITFlow\Training\Kiosk\Bridge\RecordsBridge when it reports
 * available() (the Phase 2 record tables exist). Otherwise get() returns null and the
 * engine skips every completion-based rule: exam rules and manual awards still work, and the
 * nightly backfill awards whatever was missed once the records arrive (the unique key absorbs
 * repeats).
 *
 * override() swaps in another implementation for CLI harnesses (scratch only); reset() restores
 * the bridge lookup.
 */
final class AwardFacts
{
    private static ?RecordsFacts $override = null;
    private static bool $overridden = false;

    public static function get(\mysqli $db, ?Ctx $c = null): ?RecordsFacts
    {
        if (self::$overridden) {
            return self::$override;
        }
        if (!RecordsBridge::available($db)) {
            return null;
        }
        return new BridgeRecordsFacts(new RecordsBridge($c ?? SystemCtx::make($db, 0, 'training_awards')));
    }

    /** Tests only: every later get() returns $facts (null = "records unavailable"). */
    public static function override(?RecordsFacts $facts): void
    {
        self::$override = $facts;
        self::$overridden = true;
    }

    /** Tests only: back to the RecordsBridge lookup. */
    public static function reset(): void
    {
        self::$override = null;
        self::$overridden = false;
    }
}
