<?php

namespace ITFlow\Training\Reminders;

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\WorkerCtx;
use ITFlow\Training\Upstream\Schema;

/**
 * The POST side of the Reminders and Video watch cards on the one Training settings page
 * (admin/settings_training.php and its Training-3 twin agent/training_settings.php). Both cards
 * are Training-3 territory: nothing here is admin-only.
 *
 * Called by Lane A's Settings\AutomationActions (both pages), which has validated the CSRF token,
 * refused anything the person may not change, checked the schema, and after this returns writes
 * logAction + the 'training.automation_saved' audit event and escapes the flash. This class reacts
 * only to its own keys (spec §4.3), clamps and allowlists every value:
 *
 *   ta_rem_save    enabled (0|1), weekdays[] (1..7, at least one when enabled),
 *                  escalate_after_days (1..180), version
 *   ta_video_save  enabled (0|1), version
 *   ta_video_run   "Check now": VideoWatch::run(20, 20) synchronously (well under Cloudflare's 100 s)
 *
 * Saves go through AutomationSettings::save() with the posted version (optimistic tauto_version; a
 * conflict says so and writes nothing). Messages are plain text with no user- or DB-derived parts.
 */
final class ReminderAdmin
{
    /** The action keys this handler owns, with the card anchor to return to. */
    public const KEYS = ['ta_rem_save' => 'reminders', 'ta_video_save' => 'video-watch', 'ta_video_run' => 'video-watch'];

    /**
     * @return array{0:string, 1:string, type:string, message:string, anchor:string}|null
     *   null when the post carries none of this handler's keys. Both the positional [type, message]
     *   (spec §9.2) and the keyed form (SettingsService style) are present.
     */
    public static function handle(\mysqli $db, array $post, int $userId): ?array
    {
        $action = null;
        foreach (self::KEYS as $k => $_) {
            if (isset($post[$k])) {
                $action = $k;
                break;
            }
        }
        if ($action === null) {
            return null;
        }
        $anchor = self::KEYS[$action];
        if (!Schema::has($db, ['training_automation', 'training_reminder_log', 'training_video_watch'])) {
            return self::out('error', 'Run the database update first: the Training automation tables are not installed yet.', $anchor);
        }
        $s = AutomationSettings::load($db);
        if (empty($s['ready'])) {
            return self::out('error', 'Run the database update first: the Training automation tables are not installed yet.', $anchor);
        }

        return match ($action) {
            'ta_rem_save' => self::saveReminders($db, $post, $userId, $s),
            'ta_video_save' => self::saveVideo($db, $post, $userId),
            default => self::runVideo($db),
        };
    }

    private static function saveReminders(\mysqli $db, array $post, int $userId, array $s): array
    {
        $enabled = !empty($post['enabled']) ? 1 : 0;
        $days = [];
        foreach ((array) ($post['weekdays'] ?? []) as $d) {
            if (is_scalar($d) && preg_match('/^[1-7]$/D', (string) $d) === 1) {
                $days[(int) $d] = (int) $d;
            }
        }
        ksort($days);
        if ($days === [] && $enabled === 1) {
            return self::out('error', 'Nothing was saved. Choose at least one weekday for the digest.', 'reminders');
        }
        $raw = $post['escalate_after_days'] ?? 14;
        if (!is_scalar($raw) || preg_match('/^\d{1,4}$/D', trim((string) $raw)) !== 1) {
            return self::out('error', 'Nothing was saved. The number of overdue days for Training managers must be a whole number from 1 to 180.', 'reminders');
        }
        $values = [
            'tauto_reminders_enabled' => $enabled,
            // No weekday ticked while switched off keeps the saved days (the column cannot be empty).
            'tauto_reminder_weekdays' => $days === [] ? (string) ($s['tauto_reminder_weekdays'] ?? '1,2,3,4,5') : implode(',', $days),
            'tauto_escalate_after_days' => ReminderService::escalateDays($raw),
        ];
        $saved = self::save($db, 'reminders', $values, $post['version'] ?? null, $userId, 'reminders');
        if (isset($saved['type'])) {
            return $saved;
        }
        $msg = $enabled === 1 ? 'Reminders saved. Digests go out on the chosen days after the daily Training worker runs.' : 'Reminders saved. Reminders are off.';
        if ((int) $values['tauto_escalate_after_days'] !== (int) $raw) {
            $msg .= ' The overdue days for Training managers were set to ' . intval($values['tauto_escalate_after_days']) . ' (allowed: 1 to 180).';
        }
        return self::out('success', $msg, 'reminders');
    }

    private static function saveVideo(\mysqli $db, array $post, int $userId): array
    {
        $values = ['tauto_video_recheck_enabled' => !empty($post['enabled']) ? 1 : 0];
        $saved = self::save($db, 'video', $values, $post['version'] ?? null, $userId, 'video-watch');
        if (isset($saved['type'])) {
            return $saved;
        }
        return self::out('success', $values['tauto_video_recheck_enabled'] === 1
            ? 'External video checks saved. Published videos are checked every day.'
            : 'External video checks saved. The daily check is off.', 'video-watch');
    }

    private static function runVideo(\mysqli $db): array
    {
        @set_time_limit(60);
        try {
            $ctx = WorkerCtx::build($db, (string) ($GLOBALS['config_base_url'] ?? ''));
            $r = (new VideoWatch($ctx, new Notify($db)))->run(20, 20);
        } catch (\Throwable $e) {
            error_log('Training video watch (Check now): ' . get_class($e) . ': ' . $e->getMessage());
            return self::out('error', 'The video check could not run. The details were written to the server error log.', 'video-watch');
        }
        return match ($r['state']) {
            'busy' => self::out('warning', 'A video check is already running. Try again in a minute.', 'video-watch'),
            'none' => self::out('info', 'No published course uses a YouTube or Vimeo video.', 'video-watch'),
            'unavailable' => self::out('error', 'Run the database update first: the Training automation tables are not installed yet.', 'video-watch'),
            default => self::out($r['errors'] > 0 && $r['checked'] === $r['errors'] ? 'warning' : 'success', self::runMessage($r), 'video-watch'),
        };
    }

    /** "Checked 5 videos: 1 needs attention, 1 could not be reached (checked again next time). 1 alert sent." */
    private static function runMessage(array $r): string
    {
        $checked = intval($r['checked']);
        $bad = count($r['bad']);
        $parts = [];
        $parts[] = $bad === 0 ? 'no problems found' : ($bad === 1 ? '1 needs attention' : $bad . ' need attention');
        if ($r['errors'] > 0) {
            $parts[] = intval($r['errors']) . ' could not be reached (checked again next time)';
        }
        $msg = 'Checked ' . $checked . ($checked === 1 ? ' video: ' : ' videos: ') . implode(', ', $parts) . '.';
        if ($r['alerts'] > 0) {
            $msg .= ' ' . intval($r['alerts']) . ($r['alerts'] === 1 ? ' alert' : ' alerts') . ' sent.';
        }
        if ($bad > 0) {
            $msg .= ' A video is reported only after two bad checks at least an hour apart.';
        }
        return $msg;
    }

    /** AutomationSettings::save(). Returns [] on success, or the flash array to return. */
    private static function save(\mysqli $db, string $group, array $values, mixed $version, int $userId, string $anchor): array
    {
        if (!is_scalar($version) || preg_match('/^\d{1,10}$/D', (string) $version) !== 1) {
            return self::out('error', 'Nothing was saved: the form was out of date. Reload the page and try again.', $anchor);
        }
        try {
            AutomationSettings::save($db, $group, $values, (int) $version, $userId);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'conflict') {
                return self::out('error', 'Nothing was saved: these settings were changed by someone else. Reload the page and try again.', $anchor);
            }
            error_log('Training automation save (' . $group . '): ' . get_class($e) . ': ' . $e->getMessage());
            return self::out('error', 'Nothing was saved. The details were written to the server error log.', $anchor);
        } catch (\Throwable $e) {
            error_log('Training automation save (' . $group . '): ' . get_class($e) . ': ' . $e->getMessage());
            return self::out('error', 'Nothing was saved. The details were written to the server error log.', $anchor);
        }
        return [];
    }

    private static function out(string $type, string $message, string $anchor): array
    {
        return [$type, $message, 'type' => $type, 'message' => $message, 'anchor' => $anchor];
    }
}
