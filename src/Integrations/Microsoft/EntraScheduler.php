<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * The scheduled part of the Entra sync, called from cron/cron.php beside the Intune device sync. Everything here is opt-in per switch on
 * microsoft_integrations (all off by default), so an existing install does nothing new until an administrator switches it on:
 *
 *   directory_sync_enabled + directory_cron_enabled   Entra directory sync every directory_cron_interval_min (>= 15)
 *   groups_sync_enabled                               Entra groups every extras_interval_min
 *   licenses_sync_enabled                             license SKUs every extras_interval_min
 *   intune_details_enabled + intune_sync_enabled      Intune compliance policy states and apps every extras_interval_min
 *   secret_expiry_source = 'graph'                    refresh the client-secret expiry (only once an administrator proved the lookup works)
 *
 * The Intune device sync itself is unchanged (it runs on every cron tick as before).
 */
final class EntraScheduler
{
    /**
     * @param array<string,mixed> $row a microsoft_integrations row (enabled, with a secret)
     * @param array<string,mixed> $clientOptions for tests (mock endpoints)
     * @return list<array{level:string,text:string}> lines for the cron log
     */
    public static function run(\mysqli $db, array $row, int $nowTs, array $clientOptions = []): array
    {
        $log = [];
        $id = (int) $row['microsoft_integration_id'];
        if (empty($row['enabled']) || empty($row['client_secret_enc'])) {
            return $log;
        }
        $opts = ['client_options' => $clientOptions];

        if (!empty($row['directory_sync_enabled']) && !empty($row['directory_cron_enabled'])
            && EntraDirectorySyncService::isDue(self::lastStart($db, 'microsoft_directory_sync_log', $id), (int) ($row['directory_cron_interval_min'] ?? 360), $nowTs)) {
            $r = EntraDirectorySyncService::run($db, $row, 0, 120, $opts);
            if (!$r['locked']) {
                $log[] = ['level' => $r['ok'] ? 'info' : 'error', 'text' => $r['ok'] ? $r['message'] : 'Entra directory sync failed' . ($r['error_code'] ? " [{$r['error_code']}]" : '') . ': ' . $r['message']];
            }
        }

        $extrasInterval = (int) ($row['extras_interval_min'] ?? 1440);
        $kinds = [];
        if (!empty($row['groups_sync_enabled'])) {
            $kinds[] = 'groups';
        }
        if (!empty($row['licenses_sync_enabled'])) {
            $kinds[] = 'licenses';
        }
        if (!empty($row['intune_details_enabled']) && !empty($row['intune_sync_enabled'])) {
            $kinds[] = 'intune_details';
        }
        foreach ($kinds as $kind) {
            if (!EntraDirectorySyncService::isDue(self::lastStart($db, 'entra_sync_log', $id, $kind), $extrasInterval, $nowTs)) {
                continue;
            }
            $r = EntraExtrasSync::run($db, $kind, $row, 0, 120, $opts);
            if (!$r['locked']) {
                $log[] = ['level' => $r['ok'] ? 'info' : 'error', 'text' => $r['ok'] ? 'Entra sync, ' . $r['message'] : "Entra $kind sync failed" . ($r['error_code'] ? " [{$r['error_code']}]" : '') . ': ' . $r['message']];
            }
        }

        if (($row['secret_expiry_source'] ?? '') === 'graph'
            && EntraDirectorySyncService::isDue(self::checkedTs($row), 1440, $nowTs)) {
            $r = self::refreshSecretExpiry($db, $row, $clientOptions);
            $log[] = ['level' => $r['ok'] ? 'info' : 'error', 'text' => 'Entra client secret expiry: ' . $r['message']];
        }

        return $log;
    }

    /**
     * Look the client secret's expiry up in Entra (needs Application.Read.All, optional) and store it. A lookup that finds nothing or is refused
     * never overwrites a date the administrator typed in. @return array{ok:bool,message:string,expires_at:?string}
     */
    public static function refreshSecretExpiry(\mysqli $db, array $row, array $clientOptions = []): array
    {
        $id = (int) $row['microsoft_integration_id'];
        try {
            $client = GraphClientFactory::forRow($db, $row, time() + 60, $clientOptions);
            $res = $client->clientSecretExpiry();
        } catch (GraphException $e) {
            mysqli_query($db, "UPDATE microsoft_integrations SET secret_expiry_checked_at = NOW() WHERE microsoft_integration_id = $id");

            return ['ok' => false, 'message' => $e->errorCode === GraphException::CONSENT_MISSING
                ? 'Entra did not let the app read its own registration (needs the optional Application.Read.All permission with admin consent). Enter the expiry date by hand instead.'
                : $e->getMessage(), 'expires_at' => null];
        }
        if ($res === null || $res['expires_at'] === null) {
            mysqli_query($db, "UPDATE microsoft_integrations SET secret_expiry_checked_at = NOW() WHERE microsoft_integration_id = $id");

            return ['ok' => false, 'message' => 'Entra returned no client secrets for this app registration.', 'expires_at' => null];
        }
        $date = gmdate('Y-m-d', (int) strtotime($res['expires_at']));
        $stmt = mysqli_prepare($db, "UPDATE microsoft_integrations SET secret_expires_at = ?, secret_expiry_source = 'graph', secret_expiry_checked_at = NOW() WHERE microsoft_integration_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $date, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return ['ok' => true, 'message' => 'expires ' . $date . ($res['matched'] ? '' : ' (the earliest of ' . count($res['secrets']) . ' secrets: none matched this one by its first 3 characters)'), 'expires_at' => $date];
    }

    private static function checkedTs(array $row): ?int
    {
        $v = $row['secret_expiry_checked_at'] ?? null;

        return $v ? (int) strtotime((string) $v) : null;
    }

    /** Start time of the newest real (not dry-run) run, in the database's clock, or null when there is none. */
    private static function lastStart(\mysqli $db, string $table, int $id, ?string $kind = null): ?int
    {
        $k = $kind === null ? '' : " AND kind='" . mysqli_real_escape_string($db, $kind) . "'";
        try {
            $r = mysqli_query($db, "SELECT UNIX_TIMESTAMP(MAX(started_at)) FROM `$table` WHERE microsoft_integration_id=$id AND dry_run=0$k");
            $v = $r ? mysqli_fetch_row($r)[0] : null;
        } catch (\Throwable) {
            return null;
        }

        return $v === null ? null : (int) $v;
    }
}
