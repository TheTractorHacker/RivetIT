<?php

namespace ITFlow\Training\Catalog;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\I18nService;
use ITFlow\Training\Authoring\Patch;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Icons;
use ITFlow\Training\Core\Uid;

/**
 * Achievement (badge) definitions (plan A15). Phase 1 defines them - name, description, icon
 * (Core\Icons::ALLOWED), color, rule and active flag; the award engine and
 * training_achievement_awards arrive with the Learning Center in Phase 3.
 *
 * achievement_version guards the editor's Save and the card's active switch (409 conflict with
 * data.current). The base columns hold the first configured language; other languages are
 * training_i18n rows.
 */
final class AchievementService
{
    public const DEFAULT_ICON = 'award';
    public const DEFAULT_COLOR = '#D97706';
    /** The editor's swatches (any #RRGGBB is accepted). */
    public const SWATCHES = ['#D97706', '#DC2626', '#16A34A', '#2563EB', '#7C3AED', '#0891B2', '#DB2777', '#334155'];

    private const COLS = 'achievement_id, achievement_uid, achievement_name, achievement_description, achievement_icon, achievement_color,
        achievement_rule_type, achievement_rule_json, achievement_active, achievement_sort, achievement_version, achievement_created_at,
        achievement_updated_at, achievement_archived_at';

    public function __construct(private readonly Ctx $c)
    {
    }

    public function list(bool $includeArchived = false): array
    {
        $rows = Db::all($this->c->db, 'SELECT ' . self::COLS . ' FROM training_achievements'
            . ($includeArchived ? '' : ' WHERE achievement_archived_at IS NULL')
            . ' ORDER BY achievement_sort, achievement_name, achievement_id');
        $i18n = (new I18nService($this->c))->forEntities('achievement', array_map(static fn($r) => (int) $r['achievement_id'], $rows));
        return array_map(fn($r) => $this->shape($r, $i18n[(int) $r['achievement_id']] ?? []), $rows);
    }

    public function get(int $id): array
    {
        $row = $this->row($id);
        return $this->shape($row, (new I18nService($this->c))->forEntity('achievement', $id));
    }

    /**
     * Creates ($id null) or updates. $data keys (optional on update): name, description, icon,
     * color, rule_type, rule (params object), active, sort, i18n {lang: {name, description}}.
     */
    public function save(?int $id, ?int $version, array $data): array
    {
        $db = $this->c->db;
        $allowed = ['name', 'description', 'icon', 'color', 'rule_type', 'rule', 'active', 'sort', 'i18n'];
        foreach (array_keys($data) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw ApiException::validation([(string) $k => 'This field cannot be changed here.']);
            }
        }
        $current = $id === null ? null : $this->row($id);

        $cols = [];
        if ($id === null || array_key_exists('name', $data)) {
            $cols['achievement_name'] = Patch::text($data, 'name', 100, true);
        }
        if (array_key_exists('description', $data)) {
            $cols['achievement_description'] = Patch::text($data, 'description', 500);
        }
        if ($id === null || array_key_exists('icon', $data)) {
            $icon = $data['icon'] ?? self::DEFAULT_ICON;
            if (!is_string($icon) || !Icons::valid($icon)) {
                throw ApiException::validation(['icon' => 'Choose one of the listed icons.']);
            }
            $cols['achievement_icon'] = $icon;
        }
        if ($id === null || array_key_exists('color', $data)) {
            $cols['achievement_color'] = Patch::color(['color' => $data['color'] ?? self::DEFAULT_COLOR], 'color', false);
        }
        if ($id === null || array_key_exists('rule_type', $data) || array_key_exists('rule', $data)) {
            $type = $data['rule_type'] ?? ($current['achievement_rule_type'] ?? 'manual');
            if (!is_string($type)) {
                throw ApiException::validation(['rule_type' => 'Choose when this achievement is earned.']);
            }
            $params = $data['rule'] ?? [];
            if (!is_array($params) || ($params !== [] && array_is_list($params))) {
                throw ApiException::validation(['rule' => 'Must be an object of rule settings.']);
            }
            $params = AchievementRules::validate($type, $params, $db);
            $cols['achievement_rule_type'] = $type;
            $cols['achievement_rule_json'] = $params === [] ? null : json_encode($params, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('active', $data)) {
            $cols['achievement_active'] = Patch::bool($data, 'active') ? 1 : 0;
        }
        if (array_key_exists('sort', $data)) {
            $cols['achievement_sort'] = Patch::int($data, 'sort', 0, 65535, false);
        }
        $i18n = array_key_exists('i18n', $data) ? $this->validateI18n($data['i18n']) : [];

        $newId = Db::tx($db, function () use ($db, $id, $version, $cols, $i18n): int {
            $svc = new I18nService($this->c);
            if ($id === null) {
                if (!array_key_exists('achievement_sort', $cols)) {
                    $cols['achievement_sort'] = min(65535, (int) (Db::one($db, 'SELECT COALESCE(MAX(achievement_sort), -1) + 1 AS s FROM training_achievements')['s'] ?? 0));
                }
                $names = array_keys($cols);
                $newId = Db::insert(
                    $db,
                    'INSERT INTO training_achievements (achievement_uid, achievement_created_by, ' . implode(', ', $names) . ')
                     VALUES (?, ?' . str_repeat(', ?', count($names)) . ')',
                    'si' . str_repeat('s', count($names)),
                    array_merge([Uid::new('a'), $this->c->userId], array_values($cols))
                );
                foreach ($i18n as $lang => $fields) {
                    foreach ($fields as $f => $v) {
                        $svc->set('achievement', $newId, $lang, $f, $v);
                    }
                }
                return $newId;
            }
            $current = $this->row($id, true);
            if ($current['achievement_archived_at'] !== null) {
                throw ApiException::validation(['achievement_id' => 'This achievement is archived.']);
            }
            if ($version === null || (int) $current['achievement_version'] !== $version) {
                throw ApiException::conflict($this->shape($current, $svc->forEntity('achievement', $id)), 'This achievement was changed by someone else.');
            }
            $changes = [];
            foreach ($cols as $col => $val) {
                if (!Patch::same($current[$col], $val)) {
                    $changes[$col] = $val;
                }
            }
            $changed = $changes !== [];
            foreach ($i18n as $lang => $fields) {
                foreach ($fields as $f => $v) {
                    $changed = $svc->set('achievement', $id, $lang, $f, $v) || $changed;
                }
            }
            if ($changed) {
                $sets = array_map(static fn($c) => "$c = ?", array_keys($changes));
                $sets[] = 'achievement_version = achievement_version + 1';
                Db::exec($db, 'UPDATE training_achievements SET ' . implode(', ', $sets) . ' WHERE achievement_id = ?', str_repeat('s', count($changes)) . 'i', array_merge(array_values($changes), [$id]));
            }
            return $id;
        });
        return $this->get($newId);
    }

    public function archive(int $id): void
    {
        $this->row($id);
        Db::exec($this->c->db, 'UPDATE training_achievements SET achievement_archived_at = NOW() WHERE achievement_id = ? AND achievement_archived_at IS NULL', 'i', [$id]);
    }

    // ------------------------------------------------------------------------------------------

    private function row(int $id, bool $forUpdate = false): array
    {
        $row = $id > 0 ? Db::one($this->c->db, 'SELECT ' . self::COLS . ' FROM training_achievements WHERE achievement_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$id]) : null;
        if ($row === null) {
            throw ApiException::notFound('That achievement no longer exists.');
        }
        return $row;
    }

    private function shape(array $r, array $i18n): array
    {
        $params = [];
        if ($r['achievement_rule_json'] !== null && $r['achievement_rule_json'] !== '') {
            $decoded = json_decode((string) $r['achievement_rule_json'], true);
            $params = is_array($decoded) ? $decoded : [];
        }
        $type = (string) $r['achievement_rule_type'];
        $usedBy = array_map(static fn($p) => ['id' => (int) $p['tpath_id'], 'name' => (string) $p['tpath_name']], Db::all(
            $this->c->db,
            'SELECT tpath_id, tpath_name FROM training_paths WHERE tpath_achievement_id = ? AND tpath_archived_at IS NULL ORDER BY tpath_name',
            'i',
            [(int) $r['achievement_id']]
        ));
        return [
            'id' => (int) $r['achievement_id'],
            'uid' => (string) $r['achievement_uid'],
            'name' => (string) $r['achievement_name'],
            'description' => $r['achievement_description'],
            'icon' => (string) $r['achievement_icon'],
            'color' => (string) $r['achievement_color'],
            'rule_type' => $type,
            'rule' => $params === [] ? new \stdClass() : $params,
            'kind' => AchievementRules::kind($type),
            'summary' => AchievementRules::summary($type, $params, $this->c->db),
            'active' => (int) $r['achievement_active'] === 1,
            'sort' => (int) $r['achievement_sort'],
            'version' => (int) $r['achievement_version'],
            'archived' => $r['achievement_archived_at'] !== null,
            'paths' => $usedBy,
            'i18n' => $i18n === [] ? new \stdClass() : $i18n,
        ];
    }

    /** @return array<string, array<string, ?string>> */
    private function validateI18n(mixed $map): array
    {
        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            throw ApiException::validation(['i18n' => 'Must be an object of languages.']);
        }
        $base = $this->c->settings->languages[0];
        $out = [];
        foreach ($map as $lang => $fields) {
            $lang = (string) $lang;
            if ($lang === $base || !in_array($lang, $this->c->settings->languages, true) || !is_array($fields)) {
                throw ApiException::validation(['i18n' => 'Not a translatable language.']);
            }
            foreach (array_keys($fields) as $f) {
                if (!in_array($f, ['name', 'description'], true)) {
                    throw ApiException::validation(["i18n.$lang.$f" => 'This field cannot be translated.']);
                }
            }
            $out[$lang] = [];
            if (array_key_exists('name', $fields)) {
                $out[$lang]['name'] = Patch::text($fields, 'name', 100);
            }
            if (array_key_exists('description', $fields)) {
                $out[$lang]['description'] = Patch::text($fields, 'description', 500);
            }
        }
        return $out;
    }
}
