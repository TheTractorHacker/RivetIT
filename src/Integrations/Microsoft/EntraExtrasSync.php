<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * The read-only syncs that sit beside the directory (people) and Intune (devices) syncs, one run each, logged in entra_sync_log:
 *
 *   groups          Entra groups and their USER members (not nested groups, not devices)   -> entra_groups, entra_group_members
 *   licenses        license SKUs with purchased/consumed counts, and who holds which SKU    -> entra_licenses, entra_user_licenses
 *   intune_details  per Intune device: compliance policy states and detected apps           -> intune_device_compliance, intune_device_apps
 *
 * Every run locks (no overlapping runs of the same kind), takes a time limit, classifies Graph errors and supports a DRY RUN: the work is done
 * inside a transaction that is rolled back, so the counts are what a real run would do and nothing is stored; the log row is written outside
 * it, flagged dry_run, and ignored by the sync watch. Nothing here writes to Microsoft. Tested only against tests/mock/graph_mock.php:
 * UNVERIFIED LIVE (docs/ENTRA_INTUNE_SETUP.md).
 */
final class EntraExtrasSync
{
    public const KINDS = ['groups', 'licenses', 'intune_details'];
    public const LOCK_SECONDS = 300;
    /** Groups whose member list is read per run (oldest first); the rest wait for the next run. Keeps a tenant with thousands of groups inside the time limit. */
    public const MAX_GROUP_MEMBER_READS = 300;
    /** Devices whose policy states / apps are read per run (never-read first, then oldest). */
    public const MAX_DEVICE_DETAIL_READS = 200;

    /** A few common license SKU part numbers in words. Anything else is shown by its part number. */
    private const SKU_NAMES = [
        'O365_BUSINESS_ESSENTIALS' => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM' => 'Microsoft 365 Business Standard',
        'SPB' => 'Microsoft 365 Business Premium',
        'SMB_BUSINESS' => 'Microsoft 365 Apps for business',
        'SPE_E3' => 'Microsoft 365 E3',
        'SPE_E5' => 'Microsoft 365 E5',
        'ENTERPRISEPACK' => 'Office 365 E3',
        'ENTERPRISEPREMIUM' => 'Office 365 E5',
        'STANDARDPACK' => 'Office 365 E1',
        'EXCHANGESTANDARD' => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE' => 'Exchange Online (Plan 2)',
        'AAD_PREMIUM' => 'Entra ID P1',
        'AAD_PREMIUM_P2' => 'Entra ID P2',
        'EMS' => 'Enterprise Mobility + Security E3',
        'EMSPREMIUM' => 'Enterprise Mobility + Security E5',
        'INTUNE_A' => 'Intune Plan 1',
        'POWER_BI_PRO' => 'Power BI Pro',
        'POWER_BI_STANDARD' => 'Power BI (free)',
        'FLOW_FREE' => 'Power Automate Free',
        'TEAMS_EXPLORATORY' => 'Teams Exploratory',
        'WINDOWS_STORE' => 'Windows Store for Business',
        'PROJECTPREMIUM' => 'Project Plan 5',
        'VISIOCLIENT' => 'Visio Plan 2',
    ];

    public static function friendlySku(string $partNumber): string
    {
        return self::SKU_NAMES[$partNumber] ?? $partNumber;
    }

    /**
     * @param array<string,mixed> $row a microsoft_integrations row
     * @param array{dry_run?:bool,client_options?:array<string,mixed>} $opts
     * @return array{ok:bool,message:string,error_code:?string,kind:string,stats:?array,log_id:int,locked:bool,dry_run:bool}
     */
    public static function run(\mysqli $db, string $kind, array $row, int $triggeredBy = 0, int $timeLimitSeconds = 120, array $opts = []): array
    {
        $dry = !empty($opts['dry_run']);
        $id = (int) $row['microsoft_integration_id'];
        $fail = static fn (string $m, ?string $code = null, int $log = 0, bool $locked = false) =>
            ['ok' => false, 'message' => $m, 'error_code' => $code, 'kind' => $kind, 'stats' => null, 'log_id' => $log, 'locked' => $locked, 'dry_run' => $dry];

        if (!in_array($kind, self::KINDS, true)) {
            return $fail('Unknown sync kind.');
        }
        if (empty($row['client_secret_enc']) || empty($row['tenant_id']) || empty($row['client_id'])) {
            return $fail('Save a tenant ID, client ID, and client secret before syncing.');
        }
        $k = mysqli_real_escape_string($db, $kind);
        $recent = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT id FROM entra_sync_log WHERE microsoft_integration_id=$id AND kind='$k' AND status='running'
             AND started_at > DATE_SUB(NOW(), INTERVAL " . self::LOCK_SECONDS . " SECOND) LIMIT 1"));
        if ($recent) {
            return $fail('A sync of this kind is already running. Please wait for it to finish.', null, 0, true);
        }

        $timeLimitSeconds = max(10, min(600, $timeLimitSeconds));
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($timeLimitSeconds + 30);
        }
        $deadline = time() + $timeLimitSeconds;
        $client = GraphClientFactory::forRow($db, $row, $deadline, $opts['client_options'] ?? []);
        mysqli_query($db, "INSERT INTO entra_sync_log SET microsoft_integration_id=$id, kind='$k', triggered_by=" . (int) $triggeredBy . ', dry_run=' . ($dry ? 1 : 0));
        $logId = (int) mysqli_insert_id($db);

        $inTx = false;
        try {
            if ($dry) {
                // The first Graph call happens inside the callee; the transaction only needs to wrap the writes, and an empty one is harmless.
                mysqli_begin_transaction($db);
                $inTx = true;
            }
            $stats = match ($kind) {
                'groups' => self::groups($db, $client, $id),
                'licenses' => self::licenses($db, $client, $id),
                'intune_details' => self::intuneDetails($db, $client, $id, $deadline),
            };
            if ($inTx) {
                mysqli_rollback($db);
                $inTx = false;
            }
            self::finish($db, $logId, 'success', $stats, null, null);

            return ['ok' => true, 'message' => ($dry ? 'Preview (nothing was changed): ' : '') . self::describe($kind, $stats), 'error_code' => null, 'kind' => $kind, 'stats' => $stats, 'log_id' => $logId, 'locked' => false, 'dry_run' => $dry];
        } catch (GraphException $e) {
            $code = $e->errorCode;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $code = GraphException::OTHER;
            $message = 'Sync failed: ' . $e->getMessage();
        }
        if ($inTx) {
            @mysqli_rollback($db);
        }
        self::finish($db, $logId, 'failed', null, $message, $code);

        return $fail($message, $code, $logId);
    }

    /** @param array{seen:int,created:int,updated:int,removed:int,skipped:int} $s */
    public static function describe(string $kind, array $s): string
    {
        $what = ['groups' => 'Entra groups', 'licenses' => 'license SKUs', 'intune_details' => 'Intune device details'][$kind] ?? $kind;
        $text = "$what: {$s['seen']} read, {$s['created']} new, {$s['updated']} updated, {$s['removed']} removed";
        if ($s['skipped'] > 0) {
            $text .= ", {$s['skipped']} left for the next run";
        }

        return $text;
    }

    private static function finish(\mysqli $db, int $logId, string $status, ?array $stats, ?string $error, ?string $code): void
    {
        $s = $stats ?? ['seen' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
        $stmt = mysqli_prepare($db, 'UPDATE entra_sync_log SET finished_at=NOW(), status=?, items_seen=?, items_created=?, items_updated=?, items_removed=?, items_skipped=?, errors=?, error_code=? WHERE id=?');
        $error = $error === null ? null : substr($error, 0, 4000);
        mysqli_stmt_bind_param($stmt, 'siiiiissi', $status, $s['seen'], $s['created'], $s['updated'], $s['removed'], $s['skipped'], $error, $code, $logId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // ----- groups ------------------------------------------------------------------------------------------------------

    /** Pure: the kind of an Entra group from Graph's flags. */
    public static function groupKind(array $g): string
    {
        $unified = in_array('Unified', (array) ($g['groupTypes'] ?? []), true);
        if ($unified) {
            return 'microsoft365';
        }
        $security = !empty($g['securityEnabled']);
        $mail = !empty($g['mailEnabled']);

        return $security && $mail ? 'mail_security' : ($mail ? 'distribution' : 'security');
    }

    private static function groups(\mysqli $db, GraphClient $client, int $id): array
    {
        $groups = $client->listGroups();
        $stats = ['seen' => count($groups), 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
        $seenIds = [];
        $up = mysqli_prepare($db,
            'INSERT INTO entra_groups (microsoft_integration_id, entra_id, display_name, description, mail, group_kind, synced_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), description = VALUES(description), mail = VALUES(mail), group_kind = VALUES(group_kind), synced_at = NOW()');
        foreach ($groups as $g) {
            $eid = trim((string) ($g['id'] ?? ''));
            if ($eid === '') {
                continue;
            }
            $seenIds[] = $eid;
            $name = substr((string) ($g['displayName'] ?? ''), 0, 255);
            $desc = isset($g['description']) ? substr((string) $g['description'], 0, 500) : null;
            $mail = isset($g['mail']) ? substr((string) $g['mail'], 0, 255) : null;
            $kind = self::groupKind($g);
            mysqli_stmt_bind_param($up, 'isssss', $id, $eid, $name, $desc, $mail, $kind);
            mysqli_stmt_execute($up);
            // 1 = inserted, 2 = existing row changed, 0 = unchanged (synced_at differs every time, so MariaDB reports 2 for updates)
            $aff = mysqli_stmt_affected_rows($up);
            if ($aff === 1) {
                $stats['created']++;
            } elseif ($aff >= 2) {
                $stats['updated']++;
            }
        }
        mysqli_stmt_close($up);

        // Groups deleted in Entra disappear here too (their members go with them via the foreign key).
        $removed = 0;
        $have = mysqli_query($db, "SELECT id, entra_id FROM entra_groups WHERE microsoft_integration_id=$id");
        $flip = array_flip($seenIds);
        while ($have && ($r = mysqli_fetch_assoc($have))) {
            if (!isset($flip[$r['entra_id']])) {
                mysqli_query($db, 'DELETE FROM entra_groups WHERE id=' . (int) $r['id']);
                $removed++;
            }
        }
        $stats['removed'] = $removed;

        // Members: oldest-read groups first, capped per run.
        $todo = mysqli_query($db, "SELECT id, entra_id FROM entra_groups WHERE microsoft_integration_id=$id ORDER BY members_synced_at IS NULL DESC, members_synced_at ASC, id ASC LIMIT " . self::MAX_GROUP_MEMBER_READS);
        $read = 0;
        $rows = [];
        while ($todo && ($r = mysqli_fetch_assoc($todo))) {
            $rows[] = $r;
        }
        foreach ($rows as $r) {
            $gid = (int) $r['id'];
            try {
                $userIds = array_values(array_unique($client->listGroupUserIds($r['entra_id'])));
            } catch (GraphException $ge) {
                if ($ge->errorCode === GraphException::TIME_LIMIT) {
                    break;   // out of time: the groups not reached are first in line next run (not an error)
                }
                throw $ge;
            }
            mysqli_query($db, "DELETE FROM entra_group_members WHERE group_id=$gid");
            if ($userIds) {
                $ins = mysqli_prepare($db, 'INSERT IGNORE INTO entra_group_members (group_id, entra_user_id) VALUES (?, ?)');
                foreach ($userIds as $uid) {
                    mysqli_stmt_bind_param($ins, 'is', $gid, $uid);
                    mysqli_stmt_execute($ins);
                }
                mysqli_stmt_close($ins);
            }
            mysqli_query($db, 'UPDATE entra_groups SET member_count=' . count($userIds) . ", members_synced_at=NOW() WHERE id=$gid");
            $read++;
        }
        $total = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM entra_groups WHERE microsoft_integration_id=$id"))[0] ?? 0);
        $stats['skipped'] = max(0, $total - $read);

        return $stats;
    }

    // ----- licenses ----------------------------------------------------------------------------------------------------

    private static function licenses(\mysqli $db, GraphClient $client, int $id): array
    {
        $skus = $client->listSubscribedSkus();
        $assign = $client->listUserLicenseAssignments();   // read first: a failure here leaves the SKU table untouched too (one transaction below)
        $stats = ['seen' => count($skus), 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
        $seen = [];
        $up = mysqli_prepare($db,
            'INSERT INTO entra_licenses (microsoft_integration_id, sku_id, sku_part_number, friendly_name, capability_status, units_enabled, units_suspended, units_warning, units_consumed, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE sku_part_number = VALUES(sku_part_number), friendly_name = VALUES(friendly_name), capability_status = VALUES(capability_status),
                units_enabled = VALUES(units_enabled), units_suspended = VALUES(units_suspended), units_warning = VALUES(units_warning), units_consumed = VALUES(units_consumed), synced_at = NOW()');
        foreach ($skus as $s) {
            $sku = trim((string) ($s['skuId'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $seen[] = $sku;
            $part = substr((string) ($s['skuPartNumber'] ?? ''), 0, 100);
            $friendly = substr(self::friendlySku($part), 0, 150);
            $cap = isset($s['capabilityStatus']) ? substr((string) $s['capabilityStatus'], 0, 20) : null;
            $pp = (array) ($s['prepaidUnits'] ?? []);
            $en = (int) ($pp['enabled'] ?? 0);
            $su = (int) ($pp['suspended'] ?? 0);
            $wa = (int) ($pp['warning'] ?? 0);
            $co = (int) ($s['consumedUnits'] ?? 0);
            mysqli_stmt_bind_param($up, 'issssiiii', $id, $sku, $part, $friendly, $cap, $en, $su, $wa, $co);
            mysqli_stmt_execute($up);
            $aff = mysqli_stmt_affected_rows($up);
            if ($aff === 1) {
                $stats['created']++;
            } elseif ($aff >= 2) {
                $stats['updated']++;
            }
        }
        mysqli_stmt_close($up);

        $flip = array_flip($seen);
        $have = mysqli_query($db, "SELECT id, sku_id FROM entra_licenses WHERE microsoft_integration_id=$id");
        while ($have && ($r = mysqli_fetch_assoc($have))) {
            if (!isset($flip[$r['sku_id']])) {
                mysqli_query($db, 'DELETE FROM entra_licenses WHERE id=' . (int) $r['id']);
                $stats['removed']++;
            }
        }

        // Who holds what: replaced as a whole (the list is complete at this point).
        mysqli_query($db, "DELETE FROM entra_user_licenses WHERE microsoft_integration_id=$id");
        $ins = mysqli_prepare($db, 'INSERT IGNORE INTO entra_user_licenses (microsoft_integration_id, entra_user_id, sku_id) VALUES (?, ?, ?)');
        foreach ($assign as $uid => $skuIds) {
            foreach ($skuIds as $sku) {
                $u = (string) $uid;
                mysqli_stmt_bind_param($ins, 'iss', $id, $u, $sku);
                mysqli_stmt_execute($ins);
            }
        }
        mysqli_stmt_close($ins);

        return $stats;
    }

    // ----- Intune details ----------------------------------------------------------------------------------------------

    private static function intuneDetails(\mysqli $db, GraphClient $client, int $id, int $deadline): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0];
        $total = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM asset_intune_links WHERE microsoft_integration_id=$id"))[0] ?? 0);
        $q = mysqli_query($db, "SELECT id, asset_id, intune_device_id FROM asset_intune_links WHERE microsoft_integration_id=$id
                                ORDER BY details_synced_at IS NULL DESC, details_synced_at ASC, id ASC LIMIT " . self::MAX_DEVICE_DETAIL_READS);
        $devices = [];
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $devices[] = $r;
        }
        $done = 0;
        foreach ($devices as $d) {
            if (time() >= $deadline - 2) {
                break;   // out of time: the devices not reached are first in line next run (not an error)
            }
            $dev = (string) $d['intune_device_id'];
            $asset = (int) $d['asset_id'];
            try {
                $policies = $client->listDeviceCompliancePolicyStates($dev);
                $apps = $client->listDeviceDetectedApps($dev);
            } catch (GraphException $e) {
                if ($e->errorCode === GraphException::TIME_LIMIT) {
                    break;
                }
                if ($e->httpStatus === 404) {   // the device left Intune since the device sync: nothing to read, do not retry it first next time
                    mysqli_query($db, 'UPDATE asset_intune_links SET details_synced_at=NOW() WHERE id=' . (int) $d['id']);
                    $stats['seen']++;
                    $done++;
                    continue;
                }
                throw $e;
            }
            $esc = mysqli_real_escape_string($db, $dev);
            $had = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT (SELECT COUNT(*) FROM intune_device_compliance WHERE microsoft_integration_id=$id AND intune_device_id='$esc') + (SELECT COUNT(*) FROM intune_device_apps WHERE microsoft_integration_id=$id AND intune_device_id='$esc')"))[0] ?? 0);
            mysqli_query($db, "DELETE FROM intune_device_compliance WHERE microsoft_integration_id=$id AND intune_device_id='$esc'");
            mysqli_query($db, "DELETE FROM intune_device_apps WHERE microsoft_integration_id=$id AND intune_device_id='$esc'");
            $pi = mysqli_prepare($db, 'INSERT INTO intune_device_compliance (microsoft_integration_id, intune_device_id, asset_id, policy_name, policy_state, setting_count, user_principal_name) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($policies as $p) {
                $name = substr((string) ($p['displayName'] ?? $p['settingName'] ?? 'Policy'), 0, 255);
                $state = substr((string) ($p['state'] ?? 'unknown'), 0, 30);
                $errs = max(0, (int) ($p['settingCount'] ?? 0));
                $upn = isset($p['userPrincipalName']) ? substr((string) $p['userPrincipalName'], 0, 255) : null;
                mysqli_stmt_bind_param($pi, 'isissis', $id, $dev, $asset, $name, $state, $errs, $upn);
                mysqli_stmt_execute($pi);
            }
            mysqli_stmt_close($pi);
            $ai = mysqli_prepare($db, 'INSERT INTO intune_device_apps (microsoft_integration_id, intune_device_id, asset_id, app_name, app_version, publisher) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($apps as $a) {
                $name = substr((string) ($a['displayName'] ?? ''), 0, 255);
                if ($name === '') {
                    continue;
                }
                $ver = isset($a['version']) ? substr((string) $a['version'], 0, 100) : null;
                $pub = isset($a['publisher']) ? substr((string) $a['publisher'], 0, 255) : null;
                mysqli_stmt_bind_param($ai, 'isisss', $id, $dev, $asset, $name, $ver, $pub);
                mysqli_stmt_execute($ai);
            }
            mysqli_stmt_close($ai);
            mysqli_query($db, 'UPDATE asset_intune_links SET details_synced_at=NOW() WHERE id=' . (int) $d['id']);
            $stats[$had > 0 ? 'updated' : 'created']++;
            $stats['seen']++;
            $done++;
        }
        $stats['skipped'] = max(0, $total - $done);

        return $stats;
    }
}
