<?php

namespace ITFlow\Reports;

/**
 * Scheduled reports that carry data (not just the legacy headline summary).
 *
 * Delivery: "csv" stores the file in report_exports (database, not the web root) and emails a link with a random 256-bit token (only its
 * SHA-256 is stored; the link expires after EXPIRY_DAYS and is served by guest/report_download.php). "pdf" renders the report as a PDF
 * (ReportPdf, TCPDF) and attaches it: the mail queue carries attachments since DB 2.6.223 (email_queue_attachments, removed once the
 * message is sent). "html"/"tables" put the report's tables straight into the email body.
 *
 * Targets (DB 2.6.224): a schedule may have recipient-group targets, each a group plus the saved view that group receives. Each
 * target is rendered and delivered separately; a failing target is reported in the status without resending the others.
 *
 * The report is rendered by scripts/report_render.php in a child process AS the schedule owner, so the owner's role
 * (module permission) and department restrictions apply exactly as they do on screen, and a deactivated owner stops
 * the schedule. Everything external (renderer, mail queue, clock) is injected so tests record instead of send.
 */
final class ReportScheduler
{
    public const EXPIRY_DAYS = 7;
    /** schedule_format: html = the legacy headline summary, csv = download link, tables = the report's tables in the email, pdf = the report as a PDF attached to the email */
    public const FORMATS = ['html', 'csv', 'tables', 'pdf'];
    public const INTERVALS = ['daily' => '+1 day', 'weekly' => '+7 day', 'monthly' => '+1 month', 'quarterly' => '+3 month'];
    /** A PDF larger than this is not attached (mail servers refuse big messages); the run fails with a clear status instead. */
    public const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;

    /** A schedule uses the data path when it targets a saved view or asks for a CSV / tables; otherwise it is the legacy headline summary. */
    public static function usesDataPath(array $s): bool
    {
        return !empty($s['schedule_saved_report_id']) || !empty($s['has_targets']) || in_array($s['schedule_format'] ?? 'html', ['csv', 'tables', 'pdf'], true);
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

        $res = self::activeSchedules($db);
        while ($s = mysqli_fetch_assoc($res)) {
            if (!self::usesDataPath($s) || !self::isDue($s, $now)) {
                continue;
            }
            $sum['processed']++;
            $id = (int) $s['schedule_id'];
            $note = '';
            $status = self::runOne($db, $s, $deps, $emails, $note);
            $ok = $status === null;
            $stmt = $ok
                ? mysqli_prepare($db, "UPDATE report_schedules SET schedule_last_run_at = NOW(), schedule_last_sent = NOW(), schedule_last_status = ? WHERE schedule_id = ?")
                : mysqli_prepare($db, "UPDATE report_schedules SET schedule_last_run_at = NOW(), schedule_last_status = ? WHERE schedule_id = ?");
            $text = $ok ? "ok: queued $emails email(s)" . $note : 'error: ' . $status;
            $text = mb_substr($text, 0, 250);
            mysqli_stmt_bind_param($stmt, 'si', $text, $id);
            mysqli_stmt_execute($stmt);
            $ok ? $sum['ok']++ : $sum['failed']++;
            $sum['emails'] += $ok ? $emails : 0;
        }
        return $sum;
    }

    /**
     * Active schedules with a has_targets flag. Falls back to the plain query when report_schedule_targets does not exist yet (code deployed
     * before Update Database ran), so the cron keeps sending the schedules it could always send.
     */
    public static function activeSchedules(\mysqli $db): \mysqli_result
    {
        try {
            $res = mysqli_query($db, "SELECT rs.*, EXISTS(SELECT 1 FROM report_schedule_targets t WHERE t.target_schedule_id = rs.schedule_id) AS has_targets FROM report_schedules rs WHERE rs.schedule_active = 1");
        } catch (\mysqli_sql_exception $e) { // PHP 8.1+ throws by default
            $res = false;
        }
        if ($res === false) {
            $res = mysqli_query($db, "SELECT rs.*, 0 AS has_targets FROM report_schedules rs WHERE rs.schedule_active = 1");
        }
        return $res;
    }

    /** Targets of a schedule: each is a recipient group and the saved view that group receives (null = the schedule's own report/view). */
    public static function targets(\mysqli $db, int $scheduleId): array
    {
        $out = [];
        try {
            $res = mysqli_query($db, "SELECT t.target_group_id, t.target_saved_report_id, g.group_name, g.group_recipients
                FROM report_schedule_targets t JOIN report_recipient_groups g ON g.group_id = t.target_group_id
                WHERE t.target_schedule_id = " . (int) $scheduleId . " ORDER BY g.group_name");
        } catch (\mysqli_sql_exception $e) {
            return []; // tables of DB 2.6.224 not there yet
        }
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = $r;
        }
        return $out;
    }

    /** Valid addresses of a list, passed through the send-time recipient policy when the caller supplies one. */
    private static function recipients(string $list, array $deps): array
    {
        if (isset($deps['filter_recipients'])) {
            return array_values(($deps['filter_recipients'])($list));
        }
        $out = [];
        foreach (preg_split('/[,;\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $r) {
            if (filter_var($r, FILTER_VALIDATE_EMAIL)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Render one report/view for one set of recipients and queue the messages.
     * @return string|null error, or null (then $count is the number of messages queued)
     */
    private static function deliver(\mysqli $db, array $s, array $deps, string $key, array $params, array $recipients, string $format, ?string $viewName, &$count): ?string
    {
        $count = 0;
        $label = ReportCatalog::label($key);
        $result = ($deps['render'])($s, $key, $params, $format === 'pdf' ? 'pdf' : ($format === 'csv' ? 'csv' : 'html'));
        if (empty($result['ok'])) {
            return $result['error'] ?? 'render failed';
        }
        $day = substr($deps['now'] ?? date('Y-m-d'), 0, 10);
        $brand = $deps['brand'] ?? (defined('APP_NAME') ? APP_NAME : 'RivetIT');
        $subject = "$brand report: $label" . ($viewName !== null && $viewName !== '' ? " - $viewName" : '') . " ($day)";
        $attachments = [];
        if ($format === 'csv') {
            $filename = ReportExport::filename($key . '_' . $day);
            $token = self::storeExport($db, (int) $s['schedule_id'], $filename, (string) $result['content']);
            $url = 'https://' . $deps['base_url'] . '/guest/report_download.php?t=' . $token;
            $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333"><p>Your scheduled report <b>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b> is ready.</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '">Download the CSV</a></p><p style="color:#777;font-size:12px">This link expires in ' . self::EXPIRY_DAYS . ' days. Anyone with the link can download the file, so do not forward this email.</p></div>';
        } elseif ($format === 'pdf') {
            $pdf = (string) $result['content'];
            if (strncmp($pdf, '%PDF-', 5) !== 0) {
                return 'renderer did not return a PDF';
            }
            if (strlen($pdf) > self::MAX_ATTACHMENT_BYTES) {
                return 'PDF is larger than ' . (int) (self::MAX_ATTACHMENT_BYTES / 1048576) . ' MB; narrow the saved view';
            }
            $attachments[] = ['filename' => ReportExport::filename($key . '_' . $day, 'pdf'), 'mime' => 'application/pdf', 'content' => $pdf];
            $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333"><p>Your scheduled report <b>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>' . ($viewName ? ' (' . htmlspecialchars($viewName, ENT_QUOTES, 'UTF-8') . ')' : '') . ' is attached as a PDF.</p></div>';
        } else {
            $body = (string) $result['content'];
        }
        $messages = [];
        foreach ($recipients as $to) {
            $m = ['from' => $deps['from_email'] ?? '', 'from_name' => $deps['from_name'] ?? $brand,
                'recipient' => $to, 'recipient_name' => $to, 'subject' => $subject, 'body' => $body];
            if ($attachments !== []) {
                $m['attachments'] = $attachments;
            }
            $messages[] = $m;
        }
        ($deps['mail'])($messages);
        $count = count($messages);
        return null;
    }

    /** @return string|null error message, or null on success ($emails = queued count, $note = extra text for the status) */
    private static function runOne(\mysqli $db, array $s, array $deps, &$emails, &$note = ''): ?string
    {
        $emails = 0;
        $note = '';
        $key = (string) $s['schedule_report'];
        $params = [];
        $ownName = null;
        if (!empty($s['schedule_saved_report_id'])) {
            $view = SavedReports::get($db, (int) $s['schedule_saved_report_id']);
            if (!$view) {
                return 'saved view no longer exists';
            }
            $key = $view['saved_report_key'];
            $params = $view['params'];
            $ownName = $view['saved_report_name'];
        }
        if (!ReportCatalog::exists($key)) {
            return "unknown report '$key'";
        }
        if (empty($s['schedule_owner_user_id'])) {
            return 'schedule has no owner to run as';
        }
        $fmt = (string) ($s['schedule_format'] ?? '');
        $format = $fmt === 'csv' ? 'csv' : ($fmt === 'pdf' ? 'pdf' : 'html'); // html here = tables in the email body

        // Each delivery is [recipients, report key, params, view name]. Without targets there is one: the schedule's own list.
        $jobs = [];
        $own = self::recipients((string) $s['schedule_recipients'], $deps);
        if ($own !== []) {
            $jobs[] = ['recipients' => $own, 'key' => $key, 'params' => $params, 'view' => $ownName, 'group' => null];
        }
        foreach (self::targets($db, (int) $s['schedule_id']) as $t) {
            $tKey = $key;
            $tParams = $params;
            $tName = $ownName;
            if (!empty($t['target_saved_report_id'])) {
                $v = SavedReports::get($db, (int) $t['target_saved_report_id']);
                if (!$v || !ReportCatalog::exists($v['saved_report_key'])) {
                    $note .= '; group "' . $t['group_name'] . '": saved view no longer exists';
                    continue;
                }
                $tKey = $v['saved_report_key'];
                $tParams = $v['params'];
                $tName = $v['saved_report_name'];
            }
            $r = self::recipients((string) $t['group_recipients'], $deps);
            if ($r === []) {
                $note .= '; group "' . $t['group_name'] . '": no valid recipient';
                continue;
            }
            $jobs[] = ['recipients' => $r, 'key' => $tKey, 'params' => $tParams, 'view' => $tName, 'group' => $t['group_name']];
        }
        if ($jobs === []) {
            return 'no valid recipient';
        }

        $firstError = null;
        $failed = 0;
        foreach ($jobs as $j) {
            $n = 0;
            $err = self::deliver($db, $s, $deps, $j['key'], $j['params'], $j['recipients'], $format, $j['view'], $n);
            if ($err !== null) {
                $failed++;
                $firstError = $firstError ?? ($j['group'] !== null ? 'group "' . $j['group'] . '": ' : '') . $err;
                continue;
            }
            $emails += $n;
        }
        if ($emails === 0) {
            return $firstError ?? 'nothing was sent';
        }
        // Some deliveries went out: stamp the schedule as sent (a retry would send the groups that succeeded a second time) and say what failed.
        if ($failed > 0) {
            $note .= '; ' . $failed . ' delivery(ies) failed: ' . $firstError;
        }
        return null;
    }
}
