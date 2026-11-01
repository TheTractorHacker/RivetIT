<?php

namespace ITFlow\Compliance;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetCore\Compliance\Assessment;
use RivetCore\Compliance\AttestationStore;
use RivetCore\Compliance\ComplianceAssessor;
use RivetCore\Compliance\ResponsibilityStore;
use RivetCore\Compliance\SharedReport;
use RivetCore\Compliance\SnapshotStore;
use RivetCore\Compliance\Backup\RestoreTestStore;
use RivetCore\Compliance\Check\ProfileChecks;
use RivetCore\Compliance\Profile\FrameworkProfileStore;
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

    /** True when RivetCore's customizable frameworks (migration 0046) are installed in this database. */
    public static function profilesReady(\mysqli $db): bool
    {
        if (!class_exists(FrameworkProfileStore::class)) {
            return false;
        }
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'compliance_profile_selection'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    /** The framework profile store; every change is reported to the audit trail (action, profile key, version, who; no free text). */
    public static function profiles(\mysqli $db): FrameworkProfileStore
    {
        return new FrameworkProfileStore(new MysqliDatabaseAdapter($db), new SystemClock(), static function (string $action, array $details): void {
            try {
                \ITFlow\Audit\AuditService::record(
                    'compliance.framework.' . preg_replace('/[^a-z0-9_.]/', '', strtolower($action)),
                    isset($GLOBALS['session_user_id']) ? (int) $GLOBALS['session_user_id'] : null,
                    'compliance_framework',
                    (string) ($details['key'] ?? ($details['profile'] ?? '')),
                    'update',
                    'Compliance framework: ' . $action,
                    array_intersect_key($details, array_flip(['key', 'version', 'by', 'from', 'to']))
                );
            } catch (\Throwable $e) {
                error_log('compliance framework audit entry skipped: ' . get_class($e));
            }
        });
    }

    public static function restoreTests(\mysqli $db): RestoreTestStore
    {
        return new RestoreTestStore(new MysqliDatabaseAdapter($db), new SystemClock());
    }

    /**
     * The checks the selected framework profile asks for (backup coverage, recency, encryption, off-site, retention, restore-test age, data store retention,
     * log presets). Empty while no profile is selected or the tables are missing: nothing changes until an administrator selects one.
     *
     * @return list<\RivetCore\Compliance\CheckInterface>
     */
    public static function profileChecks(\mysqli $db, ?array $settings = null): array
    {
        if (!self::profilesReady($db)) {
            return [];
        }
        try {
            require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
            require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
            $module = \rivetRmmModule($db);
            $auditDays = (int) ($settings['config_audit_retention_days'] ?? 365);

            return (new ProfileChecks(
                self::profiles($db),
                new SystemClock(),
                EditionBackupFacts::checked($db, dirname(__DIR__, 2) . '/backups'),
                self::restoreTests($db),
                static fn () => $module->settings()->dataStore(),
                $module->dataStores(),
                $module->settings(),
                $module->remoteSettings(),
                $module->changeSettings(),
                static fn (): int => $auditDays,
                '/admin/compliance_framework.php'
            ))->checks();
        } catch (\Throwable $e) {
            error_log('compliance framework checks skipped: ' . get_class($e) . ': ' . $e->getMessage());

            return [];
        }
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

        // The selected framework profile (RivetCore 1.0.0-rc.44): its requirement checks join the edition's, and the profile's name and version are recorded in the
        // assessment (so in snapshots and reports). With no profile selected both are empty and the assessment is exactly what it was before.
        $profiles = self::profilesReady($db) ? self::profiles($db) : null;
        $res = mysqli_query($db, 'SELECT config_audit_retention_days FROM settings WHERE company_id = 1');
        $row = ($res ? mysqli_fetch_assoc($res) : null) ?: [];

        return (new ComplianceAssessor(array_merge($catalog->checks(), self::profileChecks($db, $row)), $catalog->manualItems(), self::attestations($db), new SystemClock(), $responsible, $profiles))->assess();
    }
}
