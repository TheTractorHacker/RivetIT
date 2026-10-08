<?php

namespace ITFlow\Reports;

/**
 * Scheduled reports that carry data (not just the legacy headline summary).
 *
 * Delivery choice: the mail queue (email_queue / addToMailQueue) has no attachment column and the mailer cannot attach
 * files, so a CSV schedule stores the file in report_exports (database, not the web root) and emails a link with a
 * random 256-bit token. Only the SHA-256 of the token is stored; the link expires (EXPIRY_DAYS) and is served by
 * guest/report_download.php. "HTML" schedules put the report's tables straight into the email body.
 *
 * The report is rendered by scripts/report_render.php in a child process AS the schedule owner, so the owner's role
 * (module permission) and department restrictions apply exactly as they do on screen, and a deactivated owner stops
 * the schedule. Everything external (renderer, mail queue, clock) is injected so tests record instead of send.
 */
final class ReportScheduler
{
    public const EXPIRY_DAYS = 7;
    /** schedule_format: html = the legacy headline summary, csv = download link, tables = the report's tables in the email */
    public const FORMATS = ['html', 'csv', 'tables'];
    private const INTERVALS = ['daily' => '+1 day', 'weekly' => '+7 day', 'monthly' => '+1 month'];

    /** A schedule uses the data path when it targets a saved view or asks for a CSV / tables; otherwise it is the legacy headline summary. */
    public static function usesDataPath(array $s): bool
    {
        return !empty($s['schedule_saved_report_id']) || in_array($s['schedule_format'] ?? 'html', ['csv', 'tables'], true);
    }

    public static function isDue(array $s, string $now): bool
    {
        $last = $s['schedule_last_sent'] ?? null;
        if (empty($last) || $last === '0000-00-00 00:00:00') {
            return true;
        }
        $interval = self::INTERVALS[$s['schedule_frequency'] ?? 'daily'] ?? '+1 day';
        return date('Y-m-d H:i:s', strtotime($last . ' ' . $interval)) <= $now;
    }

    public static function newToken(): array
    {
        $token = bin2hex(random_bytes(32));
        return [$token, hash('sha256', $token)];
    }

    /** @return string the token (shown once, in the email) */
    public static function storeExport(\mysqli $db, ?int $scheduleId, string $filename, string $csv): string
    {
        [$token, $hash] = self::newToken();
        $days = self::EXPIRY_DAYS;
        $stmt = mysqli_prepare($db, "INSERT INTO report_exports (export_token_hash, export_schedule_id, export_filename, export_content, export_expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL $days DAY)");
        mysqli_stmt_bind_param($stmt, 'siss', $hash, $scheduleId, $filename, $csv);
        mysqli_stmt_execute($stmt);
        return $token;
    }

    /** The export for a token, or null when unknown / expired. */
    public static function fetchExport(\mysqli $db, string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = mysqli_prepare($db, "SELECT export_filename, export_content FROM report_exports WHERE export_token_hash = ? AND export_expires_at > NOW() LIMIT 1");
        mysqli_stmt_bind_param($stmt, 's', $hash);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        return $row ?: null;
    }

    public static function purgeExpired(\mysqli $db): void
    {
        mysqli_query($db, "DELETE FROM report_exports WHERE export_expires_at <= NOW()");
    }

    /**
     * Run every due, active schedule that uses the data path.
     *
     * $deps: render(array $schedule, string $reportKey, array $params, string $format): array{ok:bool, content?:string, error?:string}
     *        mail(array $messages): void        (production: addToMailQueue)
     *        from_email, from_name, base_url, brand, now (Y-m-d H:i:s)
     * @return array{processed:int, ok:int, failed:int, emails:int}
     */
    public static function runDue(\mysqli $db, array $deps): array
    {
        $now = $deps['now'] ?? date('Y-m-d H:i:s');
        $sum = ['processed' => 0, 'ok' => 0, 'failed' => 0, 'emails' => 0];
        self::purgeExpired($db);

        $res = mysqli_query($db, "SELECT * FROM report_schedules WHERE schedule_active = 1");
        while ($s = mysqli_fetch_assoc($res)) {
            if (!self::usesDataPath($s) || !self::isDue($s, $now)) {
                continue;
            }
            $sum['processed']++;
            $id = (int) $s['schedule_id'];
            $status = self::runOne($db, $s, $deps, $emails);
            $ok = $status === null;
            $stmt = $ok
                ? mysqli_prepare($db, "UPDATE report_schedules SET schedule_last_run_at = NOW(), schedule_last_sent = NOW(), schedule_last_status = ? WHERE schedule_id = ?")
                : mysqli_prepare($db, "UPDATE report_schedules SET schedule_last_run_at = NOW(), schedule_last_status = ? WHERE schedule_id = ?");
            $text = $ok ? "ok: queued $emails email(s)" : 'error: ' . $status;
            $text = mb_substr($text, 0, 250);
            mysqli_stmt_bind_param($stmt, 'si', $text, $id);
            mysqli_stmt_execute($stmt);
            $ok ? $sum['ok']++ : $sum['failed']++;
            $sum['emails'] += $ok ? $emails : 0;
        }
        return $sum;
    }

    /** @return string|null error message, or null on success ($emails = queued count) */
    private static function runOne(\mysqli $db, array $s, array $deps, &$emails): ?string
    {
        $emails = 0;
        $key = (string) $s['schedule_report'];
        $params = [];
        if (!empty($s['schedule_saved_report_id'])) {
            $view = SavedReports::get($db, (int) $s['schedule_saved_report_id']);
            if (!$view) {
                return 'saved view no longer exists';
            }
            $key = $view['saved_report_key'];
            $params = $view['params'];
        }
        if (!ReportCatalog::exists($key)) {
            return "unknown report '$key'";
        }
        if (empty($s['schedule_owner_user_id'])) {
            return 'schedule has no owner to run as';
        }
        $format = ($s['schedule_format'] ?? '') === 'csv' ? 'csv' : 'html'; // html here = tables in the email body

        $recipients = [];
        foreach (preg_split('/[,;\s]+/', (string) $s['schedule_recipients'], -1, PREG_SPLIT_NO_EMPTY) as $r) {
            if (filter_var($r, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $r;
            }
        }
        if ($recipients === []) {
            return 'no valid recipient';
        }

        $label = ReportCatalog::label($key);
        $result = ($deps['render'])($s, $key, $params, $format);
        if (empty($result['ok'])) {
            return $result['error'] ?? 'render failed';
        }

        $brand = $deps['brand'] ?? 'RivetIT';
        $subject = "$brand report: $label (" . substr($deps['now'] ?? date('Y-m-d'), 0, 10) . ')';
        if ($format === 'csv') {
            $filename = ReportExport::filename($key . '_' . substr($deps['now'] ?? date('Y-m-d'), 0, 10));
            $token = self::storeExport($db, (int) $s['schedule_id'], $filename, (string) $result['content']);
            $url = 'https://' . $deps['base_url'] . '/guest/report_download.php?t=' . $token;
            $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333"><p>Your scheduled report <b>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b> is ready.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '">Download the CSV</a></p><p style="color:#777;font-size:12px">This link expires in ' . self::EXPIRY_DAYS . ' days. Anyone with the link can download the file, so do not forward this email.</p></div>';
        } else {
            $body = (string) $result['content'];
        }

        $messages = [];
        foreach ($recipients as $to) {
            $messages[] = [
                'from' => $deps['from_email'] ?? '', 'from_name' => $deps['from_name'] ?? $brand,
                'recipient' => $to, 'recipient_name' => $to, 'subject' => $subject, 'body' => $body,
            ];
        }
        ($deps['mail'])($messages);
        $emails = count($messages);
        return null;
    }
}
