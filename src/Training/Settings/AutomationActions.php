<?php

namespace ITFlow\Training\Settings;

use ITFlow\Training\Automation\AutomationSettings;

/**
 * The POST side of the Training automation cards (LMS Phase 5, spec §4.3) on BOTH one-page Training
 * settings pages:
 *   admin/settings_training.php  -> admin/post.php -> admin/post/settings_training_automation.php (admins)
 *   agent/training_settings.php  -> AgentSettingsHandler (Training 3, not admin)
 *
 * The caller validates the CSRF token first. Then, in order:
 *   1  one known ta_* action (fixed list below; the first one present wins)
 *   2  on the agent page an admin-only action is refused for everyone, admins included (they use Admin >
 *      Training): all of Odoo write-back. So is a post that carries an admin-only FIELD (the public
 *      certificate check switch riding on "Save certificate settings"), so the handler never sees one
 *      from the agent page (an absent verify_enabled leaves the stored switch as it is).
 *   3  DB 2.6.96 installed (AutomationSettings::load()['ready'])
 *   4  the lane's handler class exists: OdooSync\OdooAdmin, Certificates\CertAdmin, Reminders\ReminderAdmin
 *      (each reacts only to its own keys, clamps/allowlists every value and saves through
 *      AutomationSettings::save() with the posted `version`)
 *   5  logAction + one audit event ('training.automation_saved', entity training_automation #1, action = the
 *      settings group) for every change - unless the handler wrote that event itself; runs (discover,
 *      Check now) are logged only.
 *
 * Handler contract (lanes B, C, D): OdooAdmin::handle($db, $post, $userId), CertAdmin::handle($db, $post, $files,
 * $userId), ReminderAdmin::handle($db, $post, $userId); each is also passed one more trailing argument, bool
 * $adminPage (true = Admin > Training), which a handler may declare as an optional last parameter
 * (`?bool $adminPage = null`) or ignore. handle(...) returns null when none of its keys is present, or
 * [type, message] / ['type' => ..., 'message' => ...] with type success|error|warning|info. The message is
 * PLAIN TEXT: this class escapes it for the flash toast (an already-escaped message is decoded first, so
 * either convention renders the same). A \RuntimeException('conflict') from AutomationSettings::save()
 * becomes "changed by someone else"; handlers need not catch it.
 */
final class AutomationActions
{
    /** action => [policy item, settings group (audit action), section anchor, changes settings?] */
    public const ACTIONS = [
        'ta_odoo_save'             => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-writeback', true],
        'ta_odoo_discover'         => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-writeback', false],
        'ta_odoo_retry'            => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-writeback', true],
        'ta_odoo_skip'             => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-writeback', true],
        'ta_odoo_retry_failed'     => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-writeback', true],
        'ta_odoo_map'              => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-writeback', true],
        'ta_odoo_skill_map'        => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-send', true],
        'ta_odoo_skill_create'     => [SettingsPolicy::ODOO_WRITEBACK, 'odoo', 'odoo-send', true],
        'ta_cert_save'             => [SettingsPolicy::CERT_SIGNATORY, 'cert', 'certificates', true],
        'ta_cert_signature'        => [SettingsPolicy::CERT_SIGNATORY, 'cert', 'certificates', true],
        'ta_cert_signature_clear'  => [SettingsPolicy::CERT_SIGNATORY, 'cert', 'certificates', true],
        'ta_rem_save'              => [SettingsPolicy::REMINDERS, 'reminders', 'reminders', true],
        'ta_video_save'            => [SettingsPolicy::VIDEO_WATCH, 'video', 'video-watch', true],
        'ta_video_run'             => [SettingsPolicy::VIDEO_WATCH, 'video', 'video-watch', false],
    ];

    /** Admin-only fields that may ride on an otherwise allowed action: field => policy item. */
    public const ADMIN_FIELDS = [
        'ta_cert_save' => ['verify_enabled' => SettingsPolicy::VERIFY_PAGE],
    ];

    /** Settings group => the lane's handler class and whether it takes $files. */
    public const HANDLERS = [
        'odoo'      => ['ITFlow\\Training\\OdooSync\\OdooAdmin', false],
        'cert'      => ['ITFlow\\Training\\Certificates\\CertAdmin', true],
        'reminders' => ['ITFlow\\Training\\Reminders\\ReminderAdmin', false],
        'video'     => ['ITFlow\\Training\\Reminders\\ReminderAdmin', false],
    ];

    /** The ta_* action this post names, or null (not an automation post). */
    public static function actionIn(array $post): ?string
    {
        foreach (self::ACTIONS as $name => $_) {
            if (array_key_exists($name, $post)) {
                return $name;
            }
        }
        return null;
    }

    /** The section anchor an action returns to ('' when unknown). */
    public static function anchorOf(string $action): string
    {
        return self::ACTIONS[$action][2] ?? '';
    }

    /**
     * @param array{user_id:int, name:string, is_admin:bool} $who
     * @param bool $adminPage true = Admin > Training (admin/post.php already required an admin)
     * @param \Closure|null $handler tests: fn(string $group, array $post, array $files, int $userId, bool $adminPage): ?array instead of the lane classes
     * @return array{anchor:string, type:string, message:string}
     */
    public static function handle(\mysqli $db, array $post, array $files, array $who, bool $adminPage, ?\Closure $handler = null): array
    {
        $action = self::actionIn($post);
        if ($action === null) {
            return self::out('', 'Nothing was saved: the request did not say what to change.', 'error');
        }
        [$item, $group, $anchor, $changes] = self::ACTIONS[$action];
        $isAdmin = ($who['is_admin'] ?? false) === true;
        $userId = (int) ($who['user_id'] ?? 0);

        if ($adminPage && !$isAdmin) {
            return self::out($anchor, 'Nothing was saved. Only administrators can use this page.', 'error');
        }
        if (!$adminPage) {
            if (SettingsPolicy::adminOnly($item)) {
                return self::refuse($item, $anchor, $isAdmin);
            }
            foreach (self::ADMIN_FIELDS[$action] ?? [] as $field => $fieldItem) {
                if (array_key_exists($field, $post)) {
                    return self::refuse($fieldItem, $anchor, $isAdmin);
                }
            }
        }

        $ta = AutomationSettings::load($db);
        if (!$ta['ready']) {
            return self::out($anchor, 'Run the database update first: the Training automation tables are not installed yet.', 'error');
        }

        [$class, $withFiles] = self::HANDLERS[$group];
        if ($handler === null && !class_exists($class)) {
            return self::out($anchor, 'This part of Training automation is not installed yet. Nothing was saved.', 'warning');
        }
        $auditMark = $changes ? self::lastAuditId($db) : null;
        try {
            if ($handler !== null) {
                $res = $handler($group, $post, $files, $userId, $adminPage);
            } else {
                // The trailing $adminPage is extra for a handler that does not declare it (PHP ignores it).
                $res = $withFiles ? $class::handle($db, $post, $files, $userId, $adminPage) : $class::handle($db, $post, $userId, $adminPage);
            }
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'conflict') {
                return self::out($anchor, 'Nothing was saved: someone else changed these settings since you opened the page. Reload the page and try again.', 'warning');
            }
            if ($e->getMessage() === 'not_ready') {
                return self::out($anchor, 'Run the database update first: the Training automation tables are not installed yet.', 'error');
            }
            error_log("Training automation ($action): " . get_class($e) . ': ' . $e->getMessage());
            return self::out($anchor, 'That could not be done. The details were written to the server error log.', 'error');
        } catch (\InvalidArgumentException $e) {
            error_log("Training automation ($action): rejected value: " . $e->getMessage());
            return self::out($anchor, 'Nothing was saved: one of the values was not accepted.', 'error');
        } catch (\Throwable $e) {
            error_log("Training automation ($action): " . get_class($e) . ': ' . $e->getMessage());
            return self::out($anchor, 'That could not be done. The details were written to the server error log.', 'error');
        }
        if ($res === null) {
            return self::out($anchor, 'Nothing was saved: the request did not say what to change.', 'error');
        }
        [$type, $message] = self::normalize($res);

        $name = (string) ($who['name'] ?? '');
        $label = SettingsPolicy::label($item);
        $page = $adminPage ? 'Admin > Training' : 'Training settings';
        if (function_exists('logAction')) {
            try {
                \logAction('Training', $changes ? 'Edit' : 'Run', "$name: Training automation $action ($label, $page): $type");
            } catch (\Throwable $e) {
                error_log('Training automation: logAction failed: ' . $e->getMessage());
            }
        }
        if ($changes && $type !== 'error' && class_exists(\ITFlow\Audit\AuditService::class) && self::lastAuditId($db) === $auditMark) {
            try {
                \ITFlow\Audit\AuditService::record('training.automation_saved', $userId > 0 ? $userId : null, 'training_automation', 1, $group,
                    "$name changed Training automation: $label", ['action' => $action, 'page' => $adminPage ? 'admin' : 'agent', 'result' => $type]);
            } catch (\Throwable $e) {
                error_log('Training automation: audit failed: ' . $e->getMessage());
            }
        }
        return self::out($anchor, $message, $type);
    }

    /** The newest training.automation_saved audit id (0 when none, null when the audit table cannot be read). */
    private static function lastAuditId(\mysqli $db): ?int
    {
        try {
            $res = $db->query("SELECT COALESCE(MAX(audit_id), 0) AS m FROM audit_events WHERE event_type = 'training.automation_saved'");
            $row = $res instanceof \mysqli_result ? $res->fetch_assoc() : null;
            if ($res instanceof \mysqli_result) {
                $res->free();
            }
            return is_array($row) ? (int) $row['m'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0:string, 1:string} [type, escaped message] */
    private static function normalize(mixed $res): array
    {
        $type = 'success';
        $message = '';
        if (is_array($res)) {
            if (array_key_exists('type', $res) || array_key_exists('message', $res)) {
                $type = (string) ($res['type'] ?? 'success');
                $message = (string) ($res['message'] ?? '');
            } else {
                $type = (string) ($res[0] ?? 'success');
                $message = (string) ($res[1] ?? '');
            }
        }
        if (!in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            $type = 'success';
        }
        if ($message === '') {
            $message = $type === 'error' ? 'Nothing was saved.' : 'Saved.';
        }
        $plain = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return [$type, htmlspecialchars(mb_scrub($plain, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
    }

    private static function refuse(string $item, string $anchor, bool $isAdmin): array
    {
        $what = SettingsPolicy::label($item);
        return self::out($anchor, $isAdmin
            ? "Nothing was saved. Admin only: $what. Change it in Admin › Training."
            : "Nothing was saved. Admin only: $what. Ask an administrator.", 'error');
    }

    /** $message must already be HTML-safe (toastr renders flash text as HTML). */
    private static function out(string $anchor, string $message, string $type): array
    {
        return ['anchor' => $anchor, 'type' => $type, 'message' => $message];
    }
}
