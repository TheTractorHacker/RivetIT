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
 * Network: ta_odoo_discover calls Odoo (read-only, 25 s budget), and ta_odoo_skill_create - the admin's explicit
 * "Create skill in Odoo" button - creates one hr.skill in the chosen certification type (or reuses one with exactly
 * that name). Both outside any transaction; nothing else here talks to Odoo, and nothing is ever deleted there.
 */
final class OdooAdmin
{
    public const KEYS = ['ta_odoo_discover', 'ta_odoo_save', 'ta_odoo_retry', 'ta_odoo_skip', 'ta_odoo_retry_failed', 'ta_odoo_map',
                         'ta_odoo_skill_map', 'ta_odoo_skill_create'];

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
                'ta_odoo_skill_map' => self::skillMap($db, $post, $userId),
                'ta_odoo_skill_create' => self::skillCreate($db, $post, $userId),
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
            . ', ' . count($d['resume']['types']) . ' line types, ' . count($d['skill']['cert_types']) . ' certification types, '
            . count($d['skill']['skills']) . ' certification skills, notes ' . ($d['note']['available'] ? 'available' : 'not available')
            . ', ' . count($d['errors']) . ' errors';
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
            . ($suggested !== null ? '; suggested line type: ' . $suggested : '')
            . '; certification types: ' . (count($d['skill']['cert_types']) ?: 'none')
            . '; HR notes: ' . ($d['note']['available'] ? 'available' : 'not available') . '.';
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
        // Targets (2.6.97): any combination. Before that update only the résumé line exists (Phase 5).
        $targetsReady = Targets::schemaReady($db);
        $send = [];
        if (array_key_exists('targets_form', $post)) {
            foreach (Targets::MODES as $m) {
                $send[$m] = !empty($post['send_' . $m]) ? 1 : 0;
            }
        } else {
            // A page rendered before the targets existed (its "Mode" select only offered the résumé line): it keeps
            // meaning "résumé line"; the other targets keep their stored switches.
            if ((string) ($post['mode'] ?? 'resume') !== 'resume') {
                return ['error', 'Reload the page and choose how records are sent with the checkboxes.'];
            }
            $send = ['resume' => 1, 'skill' => (int) ($ta['tauto_odoo_send_skill'] ?? 0), 'note' => (int) ($ta['tauto_odoo_send_note'] ?? 0)];
        }
        if (!$targetsReady) {
            if ($send['skill'] || $send['note']) {
                return ['error', 'Run the database update first (Admin › Update): certification skills and HR notes need it.'];
            }
            $send['resume'] = 1;
        }
        // Certification type + level: one "type:level" choice, both from the last Odoo check; the level belongs to the type.
        // (An old form has no such field: the stored choice stays.)
        [$skillType, $skillLevel] = array_key_exists('targets_form', $post) ? [null, null]
            : [self::intOrNull($ta['tauto_odoo_skill_type_id'] ?? null), self::intOrNull($ta['tauto_odoo_skill_level_id'] ?? null)];
        $pair = trim((string) ($post['skill_type_level'] ?? ''));
        if ($pair !== '') {
            if (preg_match('/^(\d{1,9}):(\d{1,9})$/D', $pair, $pm) !== 1) {
                return ['error', 'Choose a certification type and level that the last Odoo check found.'];
            }
            $skillType = (int) $pm[1];
            $skillLevel = (int) $pm[2];
            if (PushService::skillConfig(['tauto_odoo_skill_type_id' => $skillType, 'tauto_odoo_skill_level_id' => $skillLevel], $disc) === null) {
                return ['error', 'Choose a certification type and level that the last Odoo check found (the level must belong to the type).'];
            }
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
            if (!in_array(1, $send, true)) {
                return ['error', 'Choose at least one way to send records to Odoo (résumé line, certification skill or HR note) before switching write-back on.'];
            }
            if ($send['resume'] && empty($disc['resume']['available'])) {
                return ['error', 'This Odoo does not offer resume lines. Untick "Résumé line" or check Odoo again.'];
            }
            if ($send['resume'] && $resumeType === null) {
                return ['error', 'Choose the resume line type before switching write-back on.'];
            }
            if ($send['skill']) {
                if (empty($disc['skill']['available'])) {
                    return ['error', 'This Odoo does not offer employee skills (the Skills app). Untick "Certification skill".'];
                }
                if (empty($disc['skill']['cert_types'])) {
                    return ['error', 'This Odoo has no certification skill type yet. Create one in Odoo (Employees › Configuration › Skill Types: tick "Certification", add a level such as "Certified" and at least one skill), then Check Odoo again.'];
                }
                if ($skillType === null) {
                    return ['error', 'Choose the certification type and level before sending certification skills.'];
                }
            }
            if ($send['note'] && empty($disc['note']['available'])) {
                return ['error', 'This Odoo does not let the integration read employee chatter, so HR notes cannot be sent. Untick "HR note" or give the integration user the Employees: Officer role and check Odoo again.'];
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
        $values = [
            'tauto_odoo_push_enabled' => $enabled,
            'tauto_odoo_mode' => 'resume',   // unchanged meaning ('resume'); since 2.6.97 the target switches decide what is sent
            'tauto_odoo_resume_type_id' => $resumeType,
            'tauto_odoo_award_type_id' => $awardType,
            'tauto_odoo_skill_type_id' => $skillType,
            'tauto_odoo_skill_level_id' => $skillLevel,
            'tauto_odoo_push_awards' => $pushAwards,
            'tauto_odoo_push_since' => $since,
            'tauto_odoo_target_key' => $t->key,
            'tauto_odoo_target_confirmed_at_utc' => Clock::nowUtc(),
            'tauto_odoo_key_expires_on' => $expires === '' ? null : $expires,
        ];
        if ($targetsReady) {
            foreach (Targets::COLUMNS as $m => $col) {
                $values[$col] = $send[$m];
            }
        }
        AutomationSettings::save($db, 'odoo', $values, $version, $userId);   // a stale version throws RuntimeException('conflict')
        $on = array_keys(array_filter($send));
        $labels = implode(', ', array_map([Targets::class, 'label'], $on)) ?: 'nothing';
        $summary = 'Odoo write-back settings saved for ' . $t->host() . ' / ' . $t->database . ': write-back ' . ($enabled ? 'ON' : 'OFF')
            . ', sends ' . $labels . ', type #' . ($resumeType ?? 0) . ', certification #' . ($skillType ?? 0) . '/' . ($skillLevel ?? 0)
            . ', achievements ' . ($pushAwards ? 'on' : 'off') . ', since ' . $since;
        self::log($db, $userId, 'training.odoo_writeback_saved', 'odoo', $summary, [
            'enabled' => $enabled, 'target' => $t->key, 'targets' => $on, 'resume_type_id' => $resumeType, 'award_type_id' => $awardType,
            'skill_type_id' => $skillType, 'skill_level_id' => $skillLevel,
            'push_awards' => $pushAwards, 'push_since' => $since, 'staging' => $t->looksStaging, 'key_expires_on' => $expires ?: null,
        ]);
        return ['success', 'Odoo write-back settings saved. Write-back is ' . ($enabled ? 'on' : 'off') . '; records are sent as: ' . $labels . '.'];
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

    /**
     * ta_odoo_skill_map: the Odoo certification skill of a course or an achievement (skill_id; empty = none). Only a
     * skill of the SAVED certification type from the last Check Odoo of THIS Odoo; stored with the target key, so a
     * mapping never applies to another Odoo. A course without a row keeps "sent" (tomap_push 1), an achievement
     * "not sent" (0) - mapping a skill does not change whether it is sent.
     */
    private static function skillMap(\mysqli $db, array $post, int $userId): array
    {
        [$entity, $id, $name, $err] = self::entity($db, $post);
        if ($err !== null) {
            return $err;
        }
        $raw = trim((string) ($post['skill_id'] ?? ''));
        $skillId = ($raw === '' || $raw === '0') ? null : self::intOrNull($raw);
        if ($raw !== '' && $raw !== '0' && $skillId === null) {
            return ['error', 'Invalid request.'];
        }
        $t = Target::current($db);
        if ($t === null) {
            return ['error', 'No enabled Odoo integration is configured.'];
        }
        $label = ucfirst($entity) . ' "' . $name . '"';
        if ($skillId === null) {
            Db::exec($db, 'UPDATE training_odoo_map SET tomap_odoo_skill_id = NULL, tomap_target_key = NULL, tomap_updated_by = ?
                WHERE tomap_entity = ? AND tomap_entity_id = ?', 'isi', [$userId, $entity, $id]);
            self::log($db, $userId, 'training.odoo_map_saved', 'skill', $label . ': no Odoo certification skill', ['entity' => $entity, 'entity_id' => $id, 'skill_id' => null]);
            return ['success', $label . ' has no Odoo certification skill now; it is not sent as a certification.'];
        }
        $ta = AutomationSettings::load($db);
        $disc = self::discoveryFor($ta, $t);
        $cfg = $disc === null ? null : PushService::skillConfig($ta, $disc);
        if ($cfg === null) {
            return ['error', 'Save the certification type and level first (after Check Odoo), then map skills.'];
        }
        if (!isset($cfg['skills'][$skillId])) {
            return ['error', 'Choose a skill of the saved certification type (from the last Check Odoo).'];
        }
        self::upsertSkill($db, $entity, $id, $skillId, $t->key, $userId);
        self::log($db, $userId, 'training.odoo_map_saved', 'skill', $label . ' is sent as the Odoo certification skill "' . $cfg['skills'][$skillId] . '" (#' . $skillId . ')',
            ['entity' => $entity, 'entity_id' => $id, 'skill_id' => $skillId, 'target' => $t->key]);
        return ['success', $label . ' is sent as the Odoo certification "' . $cfg['skills'][$skillId] . '".'];
    }

    /**
     * ta_odoo_skill_create: the admin's "Create skill in Odoo" - one hr.skill named after the course (achievement) in the
     * saved certification type; an existing skill with exactly that name in that type is reused instead. Then mapped.
     * An Odoo write the owner triggers, never automatic; nothing else is created and nothing is deleted.
     */
    private static function skillCreate(\mysqli $db, array $post, int $userId): array
    {
        [$entity, $id, $name, $err] = self::entity($db, $post);
        if ($err !== null) {
            return $err;
        }
        $t = Target::current($db);
        if ($t === null) {
            return ['error', 'No enabled Odoo integration is configured.'];
        }
        if (!$t->https) {
            return ['error', 'Creating a skill in Odoo needs an https:// Odoo address.'];
        }
        $ta = AutomationSettings::load($db);
        $disc = self::discoveryFor($ta, $t);
        $cfg = $disc === null ? null : PushService::skillConfig($ta, $disc);
        if ($cfg === null) {
            return ['error', 'Save the certification type and level first (after Check Odoo), then create skills.'];
        }
        $skillName = trim((string) Text::clip(preg_replace('/\s+/u', ' ', $name) ?? $name, 200));
        if ($skillName === '') {
            return ['error', 'This ' . $entity . ' has no name to give the skill.'];
        }
        $pusher = new Pusher($t->connector(), $disc, $ta);
        try {
            $found = $pusher->findSkillByName($cfg['type_id'], $skillName);
            $created = $found === null;
            $skillId = $found['id'] ?? $pusher->createSkillInType($cfg['type_id'], $skillName);
        } catch (\ITFlow\Integrations\Odoo\OdooAuthException $e) {
            error_log('Training Odoo skill create: ' . $e->getMessage());
            return ['error', 'Odoo refused the request (the key, or the integration user may not create skills: it needs Employees: Officer). Nothing was created.'];
        } catch (\Throwable $e) {
            error_log('Training Odoo skill create: ' . get_class($e) . ': ' . $e->getMessage());
            return ['error', 'Odoo did not create the skill: ' . Text::clip($e->getMessage(), 240)];
        }
        // Keep the stored check in step, so the new skill can be chosen at once (the next Check Odoo reads it anyway).
        $known = false;
        foreach ((array) ($disc['skill']['skills'] ?? []) as $k) {
            $known = $known || (int) ($k['id'] ?? 0) === $skillId;
        }
        if (!$known) {
            $disc['skill']['skills'][] = ['id' => $skillId, 'name' => $skillName, 'type_id' => $cfg['type_id']];
            AutomationSettings::stamp($db, ['tauto_odoo_discovery_json' => json_encode($disc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
        }
        self::upsertSkill($db, $entity, $id, $skillId, $t->key, $userId);
        $label = ucfirst($entity) . ' "' . $name . '"';
        self::log($db, $userId, $created ? 'training.odoo_skill_created' : 'training.odoo_map_saved', 'skill',
            ($created ? 'Created the Odoo certification skill "' : 'Reused the Odoo certification skill "') . $skillName . '" (#' . $skillId . ') in ' . $t->host() . ' / ' . $t->database . ' for ' . $label,
            ['entity' => $entity, 'entity_id' => $id, 'skill_id' => $skillId, 'type_id' => $cfg['type_id'], 'created' => $created, 'target' => $t->key]);
        return ['success', ($created ? 'Created the skill "' : 'Odoo already had the skill "') . $skillName . '" in Odoo' . ($created ? '' : '; it is used')
            . '. ' . $label . ' is sent as that certification.'];
    }

    /** @return array{0:string, 1:int, 2:string, 3:?array} [entity, id, name, error] */
    private static function entity(\mysqli $db, array $post): array
    {
        $entity = (string) ($post['entity'] ?? '');
        $id = (int) ($post['entity_id'] ?? 0);
        if (!in_array($entity, ['course', 'achievement'], true) || $id < 1) {
            return ['', 0, '', ['error', 'Invalid request.']];
        }
        $row = $entity === 'course'
            ? Db::one($db, "SELECT course_name AS n FROM training_courses WHERE course_id = ? AND course_kind = 'training'", 'i', [$id])
            : Db::one($db, 'SELECT achievement_name AS n FROM training_achievements WHERE achievement_id = ?', 'i', [$id]);
        if ($row === null) {
            return ['', 0, '', ['error', 'That ' . $entity . ' was not found.']];
        }
        return [$entity, $id, (string) $row['n'], null];
    }

    private static function upsertSkill(\mysqli $db, string $entity, int $id, int $skillId, string $targetKey, int $userId): void
    {
        Db::exec($db, 'INSERT INTO training_odoo_map (tomap_entity, tomap_entity_id, tomap_push, tomap_target_key, tomap_odoo_skill_id, tomap_updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE tomap_odoo_skill_id = VALUES(tomap_odoo_skill_id), tomap_target_key = VALUES(tomap_target_key), tomap_updated_by = VALUES(tomap_updated_by)',
            'siisii', [$entity, $id, $entity === 'course' ? 1 : 0, $targetKey, $skillId, $userId]);
    }

    /** The stored discovery when it belongs to $t, else null. */
    private static function discoveryFor(array $ta, Target $t): ?array
    {
        $disc = is_string($ta['tauto_odoo_discovery_json'] ?? null) ? json_decode($ta['tauto_odoo_discovery_json'], true) : null;
        return is_array($disc) && ($disc['target']['key'] ?? null) === $t->key ? $disc : null;
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
