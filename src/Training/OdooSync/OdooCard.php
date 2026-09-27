<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Integrations\Odoo\OdooConnectorFactory;
use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Upstream\RecordsGateway;
use ITFlow\Training\Upstream\Schema;

/**
 * Everything the Odoo write-back card shows (admin/includes/training_automation/odoo.php), read
 * from the database only: a page view never calls Odoo. Values are raw; the partial escapes every
 * one of them.
 */
final class OdooCard
{
    /**
     * @param array|null  $ta       AutomationSettings::load() (loaded here when null)
     * @param bool        $readonly the Training-level-3 page: summary only, no per-person rows
     * @param bool        $preview  include the "Preview next 10" dry-run list
     */
    public static function build(\mysqli $db, ?array $ta, ?Target $t, bool $readonly, bool $preview): array
    {
        $out = ['ready' => false];
        if (!Schema::has($db, Schema::P5)) {
            return $out;
        }
        $ta ??= AutomationSettings::load($db);
        if (empty($ta['ready'])) {
            return $out;
        }
        $records = new RecordsGateway($db);
        $rs = $records->recordsSettings();
        $disc = is_string($ta['tauto_odoo_discovery_json'] ?? null) ? json_decode($ta['tauto_odoo_discovery_json'], true) : null;
        $disc = is_array($disc) ? $disc : null;
        $enabled = (int) ($ta['tauto_odoo_push_enabled'] ?? 0) === 1;
        $key = $t?->key ?? (string) ($ta['tauto_odoo_target_key'] ?? '');

        $out = [
            'ready' => true,
            'readonly' => $readonly,
            'enabled' => $enabled,
            'version' => (int) ($ta['tauto_version'] ?? 0),
            'target' => $t === null ? null : [
                'host' => $t->host(), 'database' => $t->database, 'protocol' => OdooConnectorFactory::label($t->protocol),
                'staging' => $t->looksStaging, 'https' => $t->https, 'key' => $t->key,
            ],
            'confirmed_key' => (string) ($ta['tauto_odoo_target_key'] ?? ''),
            'confirmed_at' => self::local($ta['tauto_odoo_target_confirmed_at_utc'] ?? null),
            'discovery' => $disc,
            'discovery_other_target' => $disc !== null && $t !== null && ($disc['target']['key'] ?? null) !== $t->key,
            'discovered_at' => self::local($ta['tauto_odoo_discovered_at_utc'] ?? null),
            'settings' => [
                'resume_type_id' => $ta['tauto_odoo_resume_type_id'] ?? null,
                'award_type_id' => $ta['tauto_odoo_award_type_id'] ?? null,
                'push_awards' => (int) ($ta['tauto_odoo_push_awards'] ?? 0) === 1,
                'push_since' => $ta['tauto_odoo_push_since'] ?? null,
                'key_expires_on' => $ta['tauto_odoo_key_expires_on'] ?? null,
            ],
            'would_pause' => $enabled ? PushService::pauseReason($t, $ta, $disc, $rs) : null,
            'paused_reason' => $ta['tauto_odoo_paused_reason'] ?? null,
            'last_run' => self::local($ta['tauto_odoo_last_run_at_utc'] ?? null),
            'last_result' => $ta['tauto_odoo_last_result'] ?? null,
            'links' => [
                'available' => !empty($rs['ready']),
                'checked_at' => self::local($rs['link_checked_at_utc'] ?? null),
                'after_discovery' => $disc !== null && is_string($rs['link_checked_at_utc'] ?? null)
                    && $rs['link_checked_at_utc'] >= (string) ($disc['checked_at_utc'] ?? '9999'),
            ],
            'counts' => $key !== '' ? (new OutboxRepo($db))->counts($key) : null,
            'problems' => [],
            'preview' => null,
            'courses' => [],
            'achievements' => [],
        ];
        if ($readonly || $key === '') {
            return $out;
        }

        $repo = new OutboxRepo($db);
        $rows = $repo->recent($key, ['failed', 'dead', 'held'], 20);
        $out['problems'] = self::describeRows($db, $records, $rows, $t);
        if ($preview && $t !== null) {
            $out['preview'] = self::preview($db, $records, $repo, $t, $ta);
        }
        $out['courses'] = Db::all($db, "SELECT c.course_id AS id, c.course_name AS name, c.course_code AS code, COALESCE(m.tomap_push, 1) AS push
            FROM training_courses c LEFT JOIN training_odoo_map m ON m.tomap_entity = 'course' AND m.tomap_entity_id = c.course_id
            WHERE c.course_kind = 'training' AND c.course_archived_at IS NULL ORDER BY c.course_name LIMIT 500");
        $out['achievements'] = Db::all($db, "SELECT a.achievement_id AS id, a.achievement_name AS name, COALESCE(m.tomap_push, 0) AS push
            FROM training_achievements a LEFT JOIN training_odoo_map m ON m.tomap_entity = 'achievement' AND m.tomap_entity_id = a.achievement_id
            WHERE a.achievement_archived_at IS NULL ORDER BY a.achievement_name LIMIT 500");
        return $out;
    }

    /** The dry run: the next 10 rows the worker would send, with no Odoo call and no write. */
    private static function preview(\mysqli $db, RecordsGateway $records, OutboxRepo $repo, Target $t, array $ta): array
    {
        $rows = [];
        foreach ($repo->upcoming($t->key, 10) as $r) {
            $rows[] = ['queued' => true, 'source_type' => $r['todoo_source_type'], 'source_id' => (int) $r['todoo_source_id'], 'action' => $r['todoo_action'],
                       'contact_id' => (int) $r['todoo_contact_id'], 'marker' => $r['todoo_marker'], 'error_class' => $r['todoo_error_class'], 'error' => $r['todoo_last_error']];
        }
        $since = OutboxScanner::sinceUtc($ta['tauto_odoo_push_since'] ?? null);
        if (count($rows) < 10 && $since !== null) {
            $inst8 = Marker::inst8For($db);
            foreach ($records->pushCandidates($since, $t->key, 10 - count($rows)) as $c) {
                $rows[] = ['queued' => false, 'source_type' => 'completion', 'source_id' => (int) $c['completion_id'], 'action' => 'create',
                           'contact_id' => (int) $c['contact_id'], 'marker' => Marker::for($inst8, 'completion', (int) $c['completion_id']), 'error_class' => null, 'error' => null];
            }
        }
        return self::describeRows($db, $records, $rows, $t, true);
    }

    /** Adds person, record and (for the preview) Odoo link facts to outbox-like rows. */
    private static function describeRows(\mysqli $db, RecordsGateway $records, array $rows, ?Target $t, bool $withLink = false): array
    {
        $out = [];
        $names = self::contactNames($db, array_map(static fn($r) => (int) ($r['todoo_contact_id'] ?? $r['contact_id'] ?? 0), $rows));
        foreach ($rows as $r) {
            $type = (string) ($r['todoo_source_type'] ?? $r['source_type']);
            $sid = (int) ($r['todoo_source_id'] ?? $r['source_id']);
            $cid = (int) ($r['todoo_contact_id'] ?? $r['contact_id']);
            $dto = null;
            if ($type === 'completion') {
                try {
                    $dto = $records->completionForPush($sid);
                } catch (\Throwable) {
                    $dto = null;
                }
            }
            $row = [
                'id' => isset($r['todoo_id']) ? (int) $r['todoo_id'] : null,
                'queued' => $r['queued'] ?? true,
                'status' => $r['todoo_status'] ?? null,
                'held' => ($r['todoo_error_class'] ?? $r['error_class'] ?? null) === 'hold',
                'action' => (string) ($r['todoo_action'] ?? $r['action']),
                'source' => ($type === 'award' ? 'Achievement #' : 'Record #') . $sid,
                'record_link' => $type === 'completion' ? '/agent/training_record.php?id=' . $sid : null,
                'contact_id' => $cid,
                'contact' => $names[$cid] ?? ('Contact #' . $cid),
                'course' => $dto['course_name'] ?? null,
                'cert_number' => $dto['cert_number'] ?? null,
                'completed_on' => $dto['completed_on'] ?? null,
                'expires_on' => $dto['expires_on'] ?? null,
                'voided_on' => $dto['voided_on'] ?? null,
                'marker' => (string) ($r['todoo_marker'] ?? $r['marker'] ?? ''),
                'attempts' => (int) ($r['todoo_attempts'] ?? 0),
                'error' => $r['todoo_last_error'] ?? $r['error'] ?? null,
                'employee' => null,
            ];
            if ($withLink && $t !== null) {
                try {
                    $l = $records->linkState($cid, $t->integrationId);
                    $row['employee'] = $l['odoo_employee_id'] ? ['id' => (int) $l['odoo_employee_id'], 'state' => (string) $l['state'], 'confirmed' => !empty($l['confirmed'])] : null;
                } catch (\Throwable) {
                    $row['employee'] = null;
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function contactNames(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Db::all($db, "SELECT contact_id, contact_name FROM contacts WHERE contact_id IN ($in)", str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['contact_id']] = (string) $r['contact_name'];
        }
        return $out;
    }

    /** A UTC DATETIME(3) as local "Y-m-d H:i", or null. */
    private static function local(mixed $utc): ?string
    {
        if (!is_string($utc) || $utc === '') {
            return null;
        }
        $iso = Clock::toIso($utc, true);
        return $iso ? date('Y-m-d H:i', strtotime($iso)) : null;
    }
}
