<?php

namespace ITFlow\Training\Automation;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;

/**
 * The ONLY way Phase 5 notifies anyone (spec §0, §3.2). In-app notifications only - no mail, ever.
 *
 * once() dedupes per (user, local date, kind) through training_reminder_log: the log row is inserted
 * first (autocommit; a duplicate key means "already sent today"), then the sink runs; if the sink
 * throws, the log row is deleted again so the next run retries.
 *
 * The sink is fn(int $userId, string $type, string $text, ?string $action): void. The default is
 * notifyUser() (which applies includes/module_access.php's notification filter: a Training type
 * reaches only people whose role holds Training), EXCEPT when the constant
 * ITFLOW_TRAINING_TEST_NOTIFY_FILE is defined - the test harness prepend only, never config.php -
 * in which case the default sink appends one JSON line to that file. Tests therefore never reach
 * notifyUser(), Redis or Firebase.
 *
 * Callers loop over recipients with a try/catch per recipient, so one failure never stops the rest.
 */
final class Notify
{
    public const KINDS = ['digest', 'escalation', 'video', 'odoo_paused', 'odoo_auth', 'odoo_config', 'odoo_dead', 'odoo_link', 'key_expiry', 'verify_integrity'];
    /**
     * 'Training Digest' / 'Training Escalation' / 'Training Video' are the mutable 'training' push category.
     * 'Training Odoo' (write-back problems) and 'Training' (records integrity, e.g. the public certificate check's
     * alert) stay unmapped: always pushed, never muted with the digests (spec §5.8). includes/module_access.php
     * sends every one of them only to roles that hold Training.
     */
    public const TYPES = ['Training Digest', 'Training Escalation', 'Training Video', 'Training Odoo', 'Training'];

    /** notification.notification is varchar(1000) under STRICT_TRANS_TABLES; notifyUser() cuts bytes at 1000. */
    public const MAX_BYTES = 990;

    public function __construct(private readonly \mysqli $db, private readonly ?\Closure $sink = null)
    {
    }

    /**
     * @param array $counts stored as JSON in the log row (numbers only by convention; no names)
     * @return bool true when sent now; false when already sent today or the sink failed
     */
    public function once(int $userId, string $dateLocal, string $kind, string $type, string $text, ?string $action, array $counts): bool
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Notify: unknown kind '$kind'");
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Notify: unknown type '$type'");
        }
        if ($userId < 1 || !Clock::isYmd($dateLocal)) {
            throw new \InvalidArgumentException('Notify: bad user or date');
        }
        if ($action !== null && (preg_match('#^/[A-Za-z0-9_./?=&%\#-]{0,240}$#D', $action) !== 1 || str_starts_with($action, '//'))) {
            throw new \InvalidArgumentException('Notify: the action must be a site-relative path');
        }
        $text = self::clip($text);
        if ($text === '') {
            return false;
        }
        $json = json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            $json = '{}';
        }
        try {
            $logId = Db::insert($this->db, 'INSERT INTO training_reminder_log (trem_user_id, trem_date, trem_kind, trem_counts_json) VALUES (?, ?, ?, ?)',
                'isss', [$userId, $dateLocal, $kind, $json]);
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1062) {
                return false;   // already sent today
            }
            throw $e;
        }
        try {
            ($this->sink ?? self::defaultSink())($userId, $type, $text, $action);
        } catch (\Throwable $e) {
            try {
                Db::exec($this->db, 'DELETE FROM training_reminder_log WHERE trem_id = ?', 'i', [$logId]);
            } catch (\Throwable $e2) {
                error_log('Training Notify: could not remove the dedupe row after a failed send: ' . $e2->getMessage());
            }
            error_log("Training Notify: sending '$kind' to user #$userId failed: " . get_class($e) . ': ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Text::clip($t, 1000), then mb_strcut(..., 0, 990, 'UTF-8'): always valid UTF-8 and under 1000
     * bytes, so notifyUser()'s byte substr() is a no-op and a multibyte character is never cut in half.
     * Control characters (tabs, newlines) collapse to a space: a notification is one line.
     */
    public static function clip(string $text): string
    {
        $t = (string) Text::clip($text, 1000);
        $t = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t);
        $t = trim($t);
        return mb_strcut($t, 0, self::MAX_BYTES, 'UTF-8');
    }

    /** notifyUser(), or the harness JSONL file when ITFLOW_TRAINING_TEST_NOTIFY_FILE is defined. */
    private static function defaultSink(): \Closure
    {
        if (defined('ITFLOW_TRAINING_TEST_NOTIFY_FILE')) {
            $file = (string) constant('ITFLOW_TRAINING_TEST_NOTIFY_FILE');
            return static function (int $userId, string $type, string $text, ?string $action) use ($file): void {
                $line = json_encode(['user_id' => $userId, 'type' => $type, 'text' => $text, 'action' => $action, 'at' => gmdate('c')],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
                    throw new \RuntimeException('test notify file not writable');
                }
            };
        }
        return static function (int $userId, string $type, string $text, ?string $action): void {
            if (!function_exists('notifyUser')) {
                throw new \RuntimeException('notifyUser() is not loaded');
            }
            \notifyUser($userId, $type, $text, $action);
        };
    }
}
