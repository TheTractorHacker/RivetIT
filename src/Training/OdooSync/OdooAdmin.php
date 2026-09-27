<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Upstream\RecordsGateway;
use ITFlow\Training\Upstream\Schema;

/**
 * The Odoo write-back card's POST actions (spec §4.3, ta_odoo_* keys). ADMIN ONLY: called by
 * Settings\AutomationActions for Admin > Training (admin/post.php, administrators only) after the
 * CSRF check; the Training-level-3 page refuses every key in KEYS for everyone before this runs.
 *
 * Returns null when $post carries none of its keys, else [flash type, message] with a PLAIN-TEXT
 * message (AutomationActions escapes it and writes logAction + the training.automation_saved audit
 * event). This class adds one detailed audit event per change (training.odoo_writeback_saved,
 * training.odoo_discovered, training.odoo_outbox_changed, training.odoo_map_saved).
 *
 * Network: only ta_odoo_discover calls Odoo (read-only, 25 s budget), outside any transaction.
 */
final class OdooAdmin
{
    public const KEYS = ['ta_odoo_discover', 'ta_odoo_save', 'ta_odoo_retry', 'ta_odoo_skip', 'ta_odoo_retry_failed', 'ta_odoo_map'];

    /** The page section the actions return to. */
    public const ANCHOR = 'odoo-writeback';

    public static function handle(\mysqli $db, array $post, int $userId): ?array
    {
        $key = null;
        foreach (self::KEYS as $k) {
            if (array_key_exists($k, $post)) {
                $key = $k;
                break;
            }
        }
        if ($key === null) {
            return null;
        }
        if (!Schema::has($db, Schema::P5)) {
            return ['error', 'Run the database update first: the Training automation tables are not installed yet.'];
        }
        try {
            return match ($key) {
                'ta_odoo_discover' => self::discover($db, $userId),
                'ta_odoo_save' => self::save($db, $post, $userId),
                'ta_odoo_retry' => self::retry($db, $post, $userId),
                'ta_odoo_skip' => self::skip($db, $post, $userId),
                'ta_odoo_retry_failed' => self::retryFailed($db, $userId),
                'ta_odoo_map' => self::map($db, $post, $userId),
            };
        } catch (\RuntimeException $e) {
            if (in_array($e->getMessage(), ['conflict', 'not_ready'], true)) {
                throw $e;   // AutomationSettings::save(): the caller (AutomationActions) words these
            }
            error_log('Training Odoo write-back admin (' . $key . '): ' . get_class($e) . ': ' . $e->getMessage());
            return ['error', 'That did not work. The details were written to the server error log.'];
        } catch (\Throwable $e) {
            error_log('Training Odoo write-back admin (' . $key . '): ' . get_class($e) . ': ' . $e->getMessage());
            return ['error', 'That did not work. The details were written to the server error log.'];
        }
    }

    // -----------------------------------------------------------------------------------------

    private static function discover(\mysqli $db, int $userId): array
    {
        $t = Target::current($db);
        if ($t === null) {
            return ['error', 'No enabled Odoo integration is configured. Set one up under Integrations › Directory Sync first.'];
        }
        if (!$t->https) {
            // Spec §8 "Odoo transport": the legacy connector does not enforce https, so the key would travel in clear.
            return ['error', 'Check Odoo needs an https:// Odoo address. Change the address under Integrations › Directory Sync first.'];
        }
        $d = (new Discovery($t, $t->connector()))->run(25);
        AutomationSettings::stamp($db, [
            'tauto_odoo_discovery_json' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'tauto_odoo_discovered_at_utc' => $d['checked_at_utc'],
        ]);
        $summary = 'Checked Odoo ' . $t->host() . ' / ' . $t->database . ': resume lines ' . ($d['resume']['available'] ? 'available' : 'not available')
            . ', ' . count($d['resume']['types']) . ' line types, ' . count($d['errors']) . ' errors';
        self::log($db, $userId, 'training.odoo_discovered', 'discover', $summary, ['target' => $t->key, 'errors' => count($d['errors'])]);

        if (!$d['resume']['available']) {
            $first = $d['errors'][0] ?? 'resume lines are not offered by this Odoo';
            return ['warning', 'Checked Odoo (read-only): resume lines are not available. ' . $first];
        }
        $suggested = null;
        foreach ($d['resume']['types'] as $ty) {
            if ($ty['id'] === $d['resume']['suggested_type_id']) {
                $suggested = $ty['name'];
            }
        }
        $msg = 'Checked Odoo (read-only): resume lines are available'
            . ($suggested !== null ? '; suggested line type: ' . $suggested : '') . '.';
        if ($d['errors']) {
            return ['warning', $msg . ' Some checks failed: ' . Text::clip(implode(' · ', $d['errors']), 400)];
        }
        return ['success', $msg . ' Review the settings below and save.'];
    }

    private static function save(\mysqli $db, array $post, int $userId): array
    {
        $ta = AutomationSettings::load($db);
        $t = Target::current($db);
        if ($t === null) {
            return ['error', 'No enabled Odoo integration is configured.'];
        }
        $disc = is_string($ta['tauto_odoo_discovery_json'] ?? null) ? json_decode($ta['tauto_odoo_discovery_json'], true) : null;
        if (!is_array($disc) || ($disc['target']['key'] ?? null) !== $t->key) {
            return ['error', 'Check Odoo first: the last check was not made against the Odoo this integration points at now.'];
        }
        $mode = (string) ($post['mode'] ?? 'resume');
        if ($mode !== 'resume') {
            return ['error', 'Only the resume-line mode is available.'];
        }
        $types = [];
        foreach ((array) ($disc['resume']['types'] ?? []) as $ty) {
            if (($ty['is_course'] ?? null) !== false) {
                $types[(int) $ty['id']] = (string) $ty['name'];
            }
        }
        $resumeType = self::intOrNull($post['resume_type_id'] ?? null);
        $awardType = self::intOrNull($post['award_type_id'] ?? null);
        if ($resumeType !== null && !isset($types[$resumeType])) {
            return ['error', 'Choose a line type that the last Odoo check found (a course type).'];
        }
        if ($awardType !== null && !isset($types[$awardType])) {
            return ['error', 'Choose an achievements line type that the last Odoo check found (a course type).'];
        }
        $pushAwards = !empty($post['push_awards']) ? 1 : 0;
        $today = Clock::todayLocal();
        $since = trim((string) ($post['push_since'] ?? ''));
        if ($since === '') {
            $since = $today;
        }
        if (!self::isDate($since) || $since > $today) {
            return ['error', '"Send records recorded on or after" must be a date no later than today.'];
        }
        $expires = trim((string) ($post['key_expires_on'] ?? ''));
        if ($expires !== '' && (!self::isDate($expires) || $expires < '2000-01-01' || $expires > '2100-12-31')) {
            return ['error', '"Odoo key expires on" must be a date.'];
        }
        $enabled = !empty($post['enabled']) ? 1 : 0;
        if ($enabled === 1) {
            if (!$t->https) {
                return ['error', 'Write-back needs an https:// Odoo address.'];
            }
            if (empty($disc['resume']['available'])) {
                return ['error', 'This Odoo does not offer resume lines, so write-back cannot be switched on.'];
            }
            if ($resumeType === null) {
                return ['error', 'Choose the resume line type before switching write-back on.'];
            }
            if ($t->looksStaging && empty($post['staging_ack'])) {
                return ['error', 'This Odoo looks like a STAGING copy. Tick "I understand this writes to the STAGING Odoo" to switch write-back on.'];
            }
            if (!$t->looksStaging) {
                $rs = (new RecordsGateway($db))->recordsSettings();
                $checked = $rs['link_checked_at_utc'] ?? null;
                if (empty($rs['ready']) || !is_string($checked) || $checked < (string) ($disc['checked_at_utc'] ?? '9999')) {
                    return ['error', 'Run Check now under Employee links (Odoo) on this page first: the employee links must be checked against this Odoo after it was checked here.'];
                }
            }
        }
        $version = (int) ($post['version'] ?? -1);
        AutomationSettings::save($db, 'odoo', [
            'tauto_odoo_push_enabled' => $enabled,
            'tauto_odoo_mode' => 'resume',
            'tauto_odoo_resume_type_id' => $resumeType,
            'tauto_odoo_award_type_id' => $awardType,
            'tauto_odoo_push_awards' => $pushAwards,
            'tauto_odoo_push_since' => $since,
            'tauto_odoo_target_key' => $t->key,
            'tauto_odoo_target_confirmed_at_utc' => Clock::nowUtc(),
            'tauto_odoo_key_expires_on' => $expires === '' ? null : $expires,
        ], $version, $userId);   // a stale version throws RuntimeException('conflict')
        $summary = 'Odoo write-back settings saved for ' . $t->host() . ' / ' . $t->database . ': write-back ' . ($enabled ? 'ON' : 'OFF')
            . ', type #' . ($resumeType ?? 0) . ', achievements ' . ($pushAwards ? 'on' : 'off') . ', since ' . $since;
        self::log($db, $userId, 'training.odoo_writeback_saved', 'odoo', $summary, [
            'enabled' => $enabled, 'target' => $t->key, 'resume_type_id' => $resumeType, 'award_type_id' => $awardType,
            'push_awards' => $pushAwards, 'push_since' => $since, 'staging' => $t->looksStaging, 'key_expires_on' => $expires ?: null,
        ]);
        return ['success', 'Odoo write-back settings saved. Write-back is ' . ($enabled ? 'on' : 'off') . '.'];
    }

    private static function retry(\mysqli $db, array $post, int $userId): array
    {
        $id = (int) ($post['todoo_id'] ?? 0);
        if ($id < 1 || !(new OutboxRepo($db))->retry($id, $userId)) {
            return ['warning', 'That row cannot be retried (it was sent, is being sent, or no longer exists).'];
        }
        self::log($db, $userId, 'training.odoo_outbox_changed', 'retry', 'Odoo outbox row #' . $id . ' queued again', ['todoo_id' => $id]);
        return ['success', 'Queued again. The next worker run (every 10 minutes) sends it.'];
    }

    private static function retryFailed(\mysqli $db, int $userId): array
    {
        $t = Target::current($db);
        if ($t === null) {
            return ['error', 'No enabled Odoo integration is configured.'];
        }
        $n = (new OutboxRepo($db))->retryAll($t->key, $userId);
        self::log($db, $userId, 'training.odoo_outbox_changed', 'retry_all', 'Odoo outbox: ' . $n . ' failed rows queued again', ['count' => $n, 'target' => $t->key]);
        return ['success', $n . ' row' . ($n === 1 ? '' : 's') . ' queued again.'];
    }

    private static function skip(\mysqli $db, array $post, int $userId): array
    {
        $id = (int) ($post['todoo_id'] ?? 0);
        $reason = trim((string) ($post['reason'] ?? ''));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            return ['error', 'Give a reason for skipping (3 to 255 characters).'];
        }
        if ($id < 1 || !(new OutboxRepo($db))->skip($id, $reason, $userId)) {
            return ['warning', 'That row cannot be skipped (it was sent, is being sent, or no longer exists).'];
        }
        self::log($db, $userId, 'training.odoo_outbox_changed', 'skip', 'Odoo outbox row #' . $id . ' skipped: ' . $reason, ['todoo_id' => $id, 'reason' => $reason]);
        return ['success', 'Skipped. It will not be sent unless you retry it.'];
    }

    private static function map(\mysqli $db, array $post, int $userId): array
    {
        $entity = (string) ($post['entity'] ?? '');
        $id = (int) ($post['entity_id'] ?? 0);
        $push = (string) ($post['push'] ?? '');
        if (!in_array($entity, ['course', 'achievement'], true) || $id < 1 || !in_array($push, ['0', '1'], true)) {
            return ['error', 'Invalid request.'];
        }
        $name = $entity === 'course'
            ? Db::one($db, "SELECT course_name AS n FROM training_courses WHERE course_id = ? AND course_kind = 'training'", 'i', [$id])
            : Db::one($db, 'SELECT achievement_name AS n FROM training_achievements WHERE achievement_id = ?', 'i', [$id]);
        if ($name === null) {
            return ['error', 'That ' . $entity . ' was not found.'];
        }
        Db::exec($db, 'INSERT INTO training_odoo_map (tomap_entity, tomap_entity_id, tomap_push, tomap_updated_by) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE tomap_push = VALUES(tomap_push), tomap_updated_by = VALUES(tomap_updated_by)', 'siii', [$entity, $id, (int) $push, $userId]);
        $label = ucfirst($entity) . ' "' . $name['n'] . '"';
        self::log($db, $userId, 'training.odoo_map_saved', 'edit', $label . ($push === '1' ? ' is sent to Odoo' : ' is not sent to Odoo'),
            ['entity' => $entity, 'entity_id' => $id, 'push' => (int) $push]);
        return ['success', $label . ($push === '1' ? ' is now sent to Odoo.' : ' is no longer sent to Odoo.')];
    }

    // -----------------------------------------------------------------------------------------

    private static function log(\mysqli $db, int $userId, string $event, string $action, string $summary, array $meta): void
    {
        $summary = (string) Text::clip($summary, 480);
        try {
            (new AuditService($db))->log($event, $userId > 0 ? $userId : null, 'training_automation', 1, $action, $summary, $meta);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }

    private static function isDate(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }

    private static function intOrNull(mixed $v): ?int
    {
        if (!is_scalar($v) || !preg_match('/^\d{1,9}$/', (string) $v) || (int) $v < 1) {
            return null;
        }
        return (int) $v;
    }
}
