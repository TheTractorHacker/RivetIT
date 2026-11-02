<?php

namespace ITFlow\Integrations\Microsoft;

use ITFlow\Recovery\RecoveryAlerts;
use ITFlow\Recovery\SyncWatch;

/**
 * Health of the Entra / Intune connection for the places that watch it: the client-secret expiry (typed in by an administrator or looked up
 * from Entra) and the sync logs. Used by
 *   - RecoveryWatch (cron): alerts through RecoveryAlerts (in-app notification, mail to the administrators, event bus) at 30 days, 7 days
 *     and on expiry, and clears them when the secret is renewed;
 *   - Admin > Update > Checks and Admin > Server status: one line each, so a connection that is about to break shows up where the
 *     administrator already looks. Nothing here talks to Microsoft or changes data except the alert bookkeeping.
 * Every read is defensive: before DB 2.6.236 there is no expiry column and nothing throws.
 */
final class EntraHealth
{
    public const WARN_DAYS = 30;
    public const CRIT_DAYS = 7;

    /**
     * Pure.
     *
     * @return array{state:string,days:?int} state: none (no date known) | ok | warn (<= 30 days) | crit (<= 7 days) | expired
     */
    public static function secretState(?string $expiresDate, int $nowTs): array
    {
        if ($expiresDate === null || trim($expiresDate) === '') {
            return ['state' => 'none', 'days' => null];
        }
        $end = strtotime(substr(trim($expiresDate), 0, 10) . ' 23:59:59 UTC');
        if ($end === false) {
            return ['state' => 'none', 'days' => null];
        }
        // Whole days left, counting the expiry day itself as still valid.
        $days = (int) floor(($end - $nowTs) / 86400);
        if ($end < $nowTs) {
            return ['state' => 'expired', 'days' => $days];
        }

        return ['state' => $days <= self::CRIT_DAYS ? 'crit' : ($days <= self::WARN_DAYS ? 'warn' : 'ok'), 'days' => $days];
    }

    /** The integration row everything watches: the newest enabled one with a saved secret, or null. @return ?array<string,mixed> */
    public static function row(\mysqli $db): ?array
    {
        try {
            $r = mysqli_query($db, "SELECT * FROM microsoft_integrations WHERE enabled = 1 AND client_secret_enc IS NOT NULL AND client_secret_enc <> '' ORDER BY microsoft_integration_id DESC LIMIT 1");

            return $r ? (mysqli_fetch_assoc($r) ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{state:string,days:?int,date:?string,source:?string}|null null when no integration is configured */
    public static function secret(\mysqli $db, ?int $nowTs = null): ?array
    {
        $row = self::row($db);
        if ($row === null) {
            return null;
        }
        $date = isset($row['secret_expires_at']) ? (string) $row['secret_expires_at'] : null;

        return self::secretState($date, $nowTs ?? time()) + ['date' => $date, 'source' => $row['secret_expiry_source'] ?? null];
    }

    /**
     * Lines for Admin > Update > Checks and Admin > Server status. Empty when there is no enabled Entra / Intune connection.
     *
     * @return list<array{id:string,title:string,status:string,detail:string,fix:string}> status: ok | warn | fail | info
     */
    public static function checks(\mysqli $db): array
    {
        $row = self::row($db);
        if ($row === null) {
            return [];
        }
        $out = [];
        $s = self::secret($db);
        $fixSecret = 'Create a new client secret in Entra admin center > App registrations > (your app) > Certificates & secrets, then paste it under Administration > Integrations > Directory Sync and enter its new expiry date.';
        if ($s === null || $s['state'] === 'none') {
            $out[] = ['id' => 'entra_secret', 'title' => 'Entra client secret expiry', 'status' => 'info',
                'detail' => 'No expiry date is recorded for the Entra / Intune client secret, so nobody will be warned before it stops working.',
                'fix' => 'Enter the expiry date under Administration > Integrations > Directory Sync (or press "Look up expiry" if the app may read its own registration).'];
        } elseif ($s['state'] === 'expired') {
            $out[] = ['id' => 'entra_secret', 'title' => 'Entra client secret expiry', 'status' => 'fail', 'detail' => 'The client secret expired on ' . $s['date'] . '. Entra / Intune sync cannot sign in.', 'fix' => $fixSecret];
        } elseif ($s['state'] === 'crit' || $s['state'] === 'warn') {
            $out[] = ['id' => 'entra_secret', 'title' => 'Entra client secret expiry', 'status' => $s['state'] === 'crit' ? 'fail' : 'warn',
                'detail' => 'The client secret expires on ' . $s['date'] . ' (' . max(0, (int) $s['days']) . ' days left).', 'fix' => $fixSecret];
        } else {
            $out[] = ['id' => 'entra_secret', 'title' => 'Entra client secret expiry', 'status' => 'ok', 'detail' => 'The client secret is valid until ' . $s['date'] . ' (' . (int) $s['days'] . ' days).', 'fix' => ''];
        }

        // Sync state from the same logic that sends the alerts.
        $problems = [];
        $watched = 0;
        try {
            foreach (SyncWatch::inspect($db, false) as $w) {
                if (!in_array($w['source'], ['intune', 'entra', 'entrax'], true)) {
                    continue;
                }
                $watched++;
                if (in_array($w['state'], ['failing', 'stale'], true)) {
                    $problems[] = ['fail' => $w['state'] === 'failing', 'text' => $w['label'] . ' sync ' . ($w['state'] === 'failing' ? 'is failing' : 'has stopped') . ': ' . $w['detail']];
                }
            }
        } catch (\Throwable) {
        }
        if (isset($row['last_test_success']) && $row['last_test_success'] !== null && (int) $row['last_test_success'] === 0) {
            $problems[] = ['fail' => false, 'text' => 'The last Test Connection failed' . (!empty($row['last_test_error']) ? ': ' . substr((string) $row['last_test_error'], 0, 160) : '')];
        }
        if ($problems) {
            $worst = in_array(true, array_column($problems, 'fail'), true) ? 'fail' : 'warn';
            $out[] = ['id' => 'entra_sync', 'title' => 'Entra / Intune syncs are running', 'status' => $worst, 'detail' => implode(' ', array_column($problems, 'text')),
                'fix' => 'Open Administration > Integrations > Directory Sync (or Device Sync) and read the last error; Admin > Backup > Integration sync watch shows the history.'];
        } elseif ($watched > 0) {
            $out[] = ['id' => 'entra_sync', 'title' => 'Entra / Intune syncs are running', 'status' => 'ok', 'detail' => $watched . ' sync(s) watched, none failing or stopped.', 'fix' => ''];
        }

        return $out;
    }

    /**
     * Cron: alert about the secret expiry. De-duplicated by RecoveryAlerts (30 days: weekly; 7 days and expired: daily); cleared once the secret is
     * renewed so the next expiry alerts immediately. @return string what happened, for the cron log
     */
    public static function watch(\mysqli $db): string
    {
        $s = self::secret($db);
        if ($s === null || $s['state'] === 'none' || $s['state'] === 'ok') {
            foreach (['warn', 'crit', 'expired'] as $k) {
                RecoveryAlerts::clear($db, 'entra.secret.' . $k);
            }

            return $s === null ? 'off' : $s['state'];
        }
        $link = '/admin/settings_integrations.php?tab=directorysync';
        $app = defined('APP_NAME') ? APP_NAME : 'RivetIT';
        if ($s['state'] === 'expired') {
            $subject = 'The Entra / Intune client secret has expired (' . $s['date'] . ')';
            $body = $subject . ".\n\nEntra and Intune sync cannot sign in until a new client secret is created in the Entra admin center (App registrations > Certificates & secrets) and saved under Administration > Integrations > Directory Sync in $app.";
            RecoveryAlerts::raise($db, 'entra.secret.expired', $subject, $body, 'integration.secret_expiring', ['state' => 'expired', 'expires' => $s['date']], $link, 24);
        } else {
            $crit = $s['state'] === 'crit';
            $subject = 'The Entra / Intune client secret expires in ' . max(0, (int) $s['days']) . ' days (' . $s['date'] . ')';
            $body = $subject . ".\n\nCreate a new client secret in the Entra admin center (App registrations > Certificates & secrets), save it under Administration > Integrations > Directory Sync in $app, and enter its new expiry date. Syncs stop working when it expires.";
            RecoveryAlerts::raise($db, 'entra.secret.' . ($crit ? 'crit' : 'warn'), $subject, $body, 'integration.secret_expiring', ['state' => $s['state'], 'days' => $s['days'], 'expires' => $s['date']], $link, $crit ? 24 : 168);
        }

        return $s['state'];
    }
}
