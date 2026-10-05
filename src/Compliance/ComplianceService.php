<?php

namespace ITFlow\Compliance;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetCore\Compliance\Assessment;
use RivetCore\Compliance\AttestationStore;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\ResponsibilityStore;
use RivetCore\Compliance\SharedReport;
use RivetCore\Compliance\SnapshotStore;
use RivetCore\Support\SystemClock;

/** Wires the Core compliance engine to this installation. Pages and cron use this and nothing else. */
final class ComplianceService
{
    public static function ready(\mysqli $db): bool
    {
        if (!class_exists(ComplianceAssessor::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_snapshots'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function attestations(\mysqli $db): AttestationStore
    {
        return new AttestationStore(new MysqliDatabaseAdapter($db));
    }

    public static function snapshots(\mysqli $db): SnapshotStore
    {
        return new SnapshotStore(new MysqliDatabaseAdapter($db));
    }

    public static function sharedReady(\mysqli $db): bool
    {
        if (!class_exists(SharedReport::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_shared_report'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function shared(\mysqli $db): SharedReport
    {
        return new SharedReport(new MysqliDatabaseAdapter($db));
    }

    public static function responsibilitiesReady(\mysqli $db): bool
    {
        if (!class_exists(ResponsibilityStore::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_responsibilities'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function responsibilities(\mysqli $db): ResponsibilityStore
    {
        return new ResponsibilityStore(new MysqliDatabaseAdapter($db));
    }

    public static function catalog(\mysqli $db): ComplianceCatalog
    {
        $res = mysqli_query($db, 'SELECT * FROM settings WHERE company_id = 1');
        $settings = ($res ? mysqli_fetch_assoc($res) : null) ?: [];

        return new ComplianceCatalog($db, dirname(__DIR__, 2), $settings);
    }

    public static function assess(\mysqli $db): Assessment
    {
        $catalog = self::catalog($db);
        $responsible = [];
        try {
            $responsible = self::responsibilitiesReady($db) ? self::responsibilities($db)->names() : [];
        } catch (\Throwable $e) {
            $responsible = [];
        }

        return (new ComplianceAssessor($catalog->checks(), $catalog->manualItems(), self::attestations($db), new SystemClock(), $responsible))->assess();
    }
}
