<?php

namespace ITFlow\Training\Achievements;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Icons;

/**
 * Reads of training_achievement_awards (P3 spec §3.5, §4.3).
 *
 * Learner side (kiosk result screen, receipt, Learning Center; K3/K4): forContact(), since() and
 * progress() return AwardPublic rows {uid, name, description, icon, color, awarded_at} built by
 * AwardEngine::publicShape() from the award's snapshot. The optional $lang swaps in the
 * achievement's translated name and description (training_i18n) when the author wrote one.
 *
 * Agent side (award_list, the Awarded badges page, the transcript partial): listRows() and
 * counts(), filtered to the agent's departments by the caller-supplied client id list
 * (AwardScope::clientIds: null = all, [] = none).
 *
 * Every reader returns [] when the 2.6.93 table is not there yet.
 */
final class AwardRepository
{
    public const LIST_MAX = 500;

    private const PUBLIC_COLS = 'w.taward_id, w.taward_achievement_id, w.taward_achievement_uid, w.taward_snap_name, w.taward_snap_icon,
        w.taward_snap_color, w.taward_awarded_at_utc, a.achievement_description';

    /** Every award of the contact, newest first (snapshot fields). @return list<array> AwardPublic */
    public static function forContact(\mysqli $db, int $cid, ?string $lang = null): array
    {
        if ($cid < 1 || !AwardEngine::ready($db)) {
            return [];
        }
        $rows = Db::all($db, 'SELECT ' . self::PUBLIC_COLS . ' FROM training_achievement_awards w
                LEFT JOIN training_achievements a ON a.achievement_id = w.taward_achievement_id
            WHERE w.taward_contact_id = ?
            ORDER BY w.taward_awarded_at_utc DESC, w.taward_id DESC
            LIMIT ' . self::LIST_MAX, 'i', [$cid]);
        return self::publicRows($db, $rows, $lang);
    }

    /** Awards of the contact at or after $sinceUtc ('Y-m-d H:i:s[.v]' UTC), newest first - result and receipt screens. */
    public static function since(\mysqli $db, int $cid, string $sinceUtc, ?string $lang = null): array
    {
        if ($cid < 1 || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $sinceUtc) !== 1 || !AwardEngine::ready($db)) {
            return [];
        }
        $rows = Db::all($db, 'SELECT ' . self::PUBLIC_COLS . ' FROM training_achievement_awards w
                LEFT JOIN training_achievements a ON a.achievement_id = w.taward_achievement_id
            WHERE w.taward_contact_id = ? AND w.taward_awarded_at_utc >= ?
            ORDER BY w.taward_awarded_at_utc DESC, w.taward_id DESC
            LIMIT 50', 'is', [$cid, $sinceUtc]);
        return self::publicRows($db, $rows, $lang);
    }

    /**
     * [S] Progress toward badges the contact has not earned yet, for the Learning Center's
     * progress tiles ("3 of 6 months"). Only measurable rules: on_time_streak (unit 'months'),
     * courses_completed_count, category_completed and path badges (unit 'courses'). Closest to
     * done first. Empty when the records bridge is unavailable.
     *
     * @return list<array{uid:string, name:string, icon:string, color:string, have:int, need:int, unit:string}>
     */
    public static function progress(\mysqli $db, int $cid, ?string $lang = null): array
    {
        if ($cid < 1 || !AwardEngine::ready($db)) {
            return [];
        }
        $facts = AwardFacts::get($db);
        if ($facts === null) {
            return [];
        }
        $defs = Db::all($db, "SELECT achievement_id, achievement_uid, achievement_name, achievement_icon, achievement_color,
                achievement_rule_type, achievement_rule_json
            FROM training_achievements
            WHERE achievement_active = 1 AND achievement_archived_at IS NULL
              AND achievement_rule_type IN ('on_time_streak','courses_completed_count','category_completed','path_completed')
            ORDER BY achievement_sort, achievement_id");
        $catalog = AwardEngine::catalog($db);
        $pathBadges = [];
        foreach ($catalog['path_badges'] as $pid => $badge) {
            $pathBadges[$badge['id']] ??= $pid;
        }
        if ($defs === [] && $pathBadges === []) {
            return [];
        }
        $held = [];
        foreach (Db::all($db, "SELECT DISTINCT taward_achievement_id FROM training_achievement_awards WHERE taward_contact_id = ? AND taward_scope_key = ''", 'i', [$cid]) as $r) {
            $held[(int) $r['taward_achievement_id']] = true;
        }
        $v = array_fill_keys($facts->validCourseIds($cid), true);
        $months = null;
        $count = static function (array $ids) use ($v): int {
            $n = 0;
            foreach ($ids as $id) {
                if (isset($v[$id])) {
                    $n++;
                }
            }
            return $n;
        };

        $out = [];
        $seen = [];
        $add = static function (int $id, string $uid, string $name, string $icon, string $color, int $have, int $need, string $unit) use (&$out, &$seen, $held): void {
            if ($need < 1 || isset($held[$id]) || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'uid' => $uid, 'name' => $name, 'icon' => $icon, 'color' => $color,
                'have' => min($have, $need), 'need' => $need, 'unit' => $unit];
        };
        foreach ($defs as $d) {
            $id = (int) $d['achievement_id'];
            $params = json_decode((string) ($d['achievement_rule_json'] ?? ''), true);
            $params = is_array($params) ? $params : [];
            $args = [$id, (string) $d['achievement_uid'], (string) $d['achievement_name'], (string) $d['achievement_icon'], (string) $d['achievement_color']];
            switch ($d['achievement_rule_type']) {
                case 'on_time_streak':
                    $months ??= $facts->onTimeMonths($cid);
                    $add(...array_merge($args, [$months, (int) ($params['months'] ?? 0), 'months']));
                    break;
                case 'courses_completed_count':
                    $cat = isset($params['category_id']) ? (int) $params['category_id'] : null;
                    $have = 0;
                    foreach (array_keys($v) as $courseId) {
                        if ($cat === null || ($catalog['course_category'][$courseId] ?? null) === $cat) {
                            $have++;
                        }
                    }
                    $add(...array_merge($args, [$have, (int) ($params['count'] ?? 0), 'courses']));
                    break;
                case 'category_completed':
                    $ids = $catalog['categories'][(int) ($params['category_id'] ?? 0)] ?? [];
                    $add(...array_merge($args, [$count($ids), count($ids), 'courses']));
                    break;
                case 'path_completed':
                    $ids = $catalog['paths'][(int) ($params['path_id'] ?? 0)] ?? null;
                    if ($ids !== null) {
                        $add(...array_merge($args, [$count($ids), count($ids), 'courses']));
                    }
                    break;
            }
        }
        foreach ($pathBadges as $aid => $pid) {
            $b = $catalog['path_badges'][$pid];
            $ids = $catalog['paths'][$pid] ?? [];
            $add($aid, $b['uid'], $b['name'], $b['icon'], $b['color'], $count($ids), count($ids), 'courses');
        }

        $names = self::translations($db, array_column($out, 'id'), $lang);
        usort($out, static fn($x, $y) => [$y['have'] / $y['need'], $x['need'] - $x['have'], $x['id']] <=> [$x['have'] / $x['need'], $y['need'] - $y['have'], $y['id']]);
        return array_map(static function (array $p) use ($names): array {
            $name = $names[$p['id']]['name'] ?? null;
            return [
                'uid' => $p['uid'],
                'name' => ($name !== null && $name !== '') ? $name : $p['name'],
                'icon' => Icons::valid($p['icon']) ? $p['icon'] : AwardEngine::DEFAULT_ICON,
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/D', $p['color']) === 1 ? $p['color'] : AwardEngine::DEFAULT_COLOR,
                'have' => $p['have'],
                'need' => $p['need'],
                'unit' => $p['unit'],
            ];
        }, $out);
    }

    /** AwardPublic rows for the given award ids, in id order (AwardEngine's return values). */
    public static function publicByIds(\mysqli $db, array $ids, ?string $lang = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = Db::all($db, 'SELECT ' . self::PUBLIC_COLS . ' FROM training_achievement_awards w
                LEFT JOIN training_achievements a ON a.achievement_id = w.taward_achievement_id
            WHERE w.taward_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY w.taward_id', str_repeat('i', count($ids)), $ids);
        return self::publicRows($db, $rows, $lang);
    }

    // ------------------------------------------------------------------ agent side

    /**
     * Awards for the agent list, newest first. $clientIds: null = every department, [] = none.
     * Each row: {id, achievement:{id,uid,name,icon,color}, contact:{id,name,dept,archived}, source,
     * rule_type, reason, awarded_by:{kind:'user'|'contact'|'system', name}, awarded_at, kiosk_id}.
     *
     * @return array{rows:list<array>, more:bool}
     */
    public static function listRows(\mysqli $db, ?array $clientIds, ?int $achievementId = null, ?int $contactId = null, int $limit = self::LIST_MAX): array
    {
        if (!AwardEngine::ready($db) || $clientIds === []) {
            return ['rows' => [], 'more' => false];
        }
        $limit = max(1, min(self::LIST_MAX, $limit));
        $where = '1 = 1';
        $types = '';
        $params = [];
        if ($clientIds !== null) {
            $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
            $where .= ' AND c.contact_client_id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . ')';
            $types .= str_repeat('i', count($clientIds));
            $params = array_merge($params, $clientIds);
        }
        if ($achievementId !== null) {
            $where .= ' AND w.taward_achievement_id = ?';
            $types .= 'i';
            $params[] = $achievementId;
        }
        if ($contactId !== null) {
            $where .= ' AND w.taward_contact_id = ?';
            $types .= 'i';
            $params[] = $contactId;
        }
        $rows = self::detailed($db, "WHERE $where", $types, $params, $limit + 1);
        $more = count($rows) > $limit;
        return ['rows' => array_slice($rows, 0, $limit), 'more' => $more];
    }

    /** One list row per award id (awardManual's return value). @return list<array> */
    public static function listByIds(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        return self::detailed($db, 'WHERE w.taward_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids, count($ids));
    }

    /** Awards per achievement id, within the departments ($clientIds as listRows). @return array<int, int> */
    public static function counts(\mysqli $db, ?array $clientIds): array
    {
        if (!AwardEngine::ready($db) || $clientIds === []) {
            return [];
        }
        $where = '';
        $types = '';
        $params = [];
        if ($clientIds !== null) {
            $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
            $where = ' WHERE c.contact_client_id IN (' . implode(',', array_fill(0, count($clientIds), '?')) . ')';
            $types = str_repeat('i', count($clientIds));
            $params = $clientIds;
        }
        $out = [];
        foreach (Db::all($db, 'SELECT w.taward_achievement_id, COUNT(*) AS n FROM training_achievement_awards w
                JOIN contacts c ON c.contact_id = w.taward_contact_id' . $where . '
            GROUP BY w.taward_achievement_id', $types, $params) as $r) {
            $out[(int) $r['taward_achievement_id']] = (int) $r['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ internals

    private static function detailed(\mysqli $db, string $where, string $types, array $params, int $limit): array
    {
        $rows = Db::all($db, 'SELECT w.taward_id, w.taward_achievement_id, w.taward_achievement_uid, w.taward_contact_id, w.taward_rule_type,
                w.taward_source, w.taward_reason, w.taward_awarded_by_user_id, w.taward_awarded_by_contact_id, w.taward_snap_name,
                w.taward_snap_icon, w.taward_snap_color, w.taward_awarded_at_utc, w.taward_kiosk_id, a.achievement_description,
                c.contact_name, c.contact_archived_at, cl.client_name, u.user_name AS by_user_name, bc.contact_name AS by_contact_name
            FROM training_achievement_awards w
            JOIN contacts c ON c.contact_id = w.taward_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            LEFT JOIN training_achievements a ON a.achievement_id = w.taward_achievement_id
            LEFT JOIN users u ON u.user_id = w.taward_awarded_by_user_id
            LEFT JOIN contacts bc ON bc.contact_id = w.taward_awarded_by_contact_id
            ' . $where . '
            ORDER BY w.taward_awarded_at_utc DESC, w.taward_id DESC
            LIMIT ' . max(1, $limit), $types, $params);
        return array_map(static function (array $r): array {
            $pub = AwardEngine::publicShape($r);
            if ($r['taward_awarded_by_user_id'] !== null) {
                $by = ['kind' => 'user', 'name' => $r['by_user_name'] === null ? null : (string) $r['by_user_name']];
            } elseif ($r['taward_awarded_by_contact_id'] !== null) {
                $by = ['kind' => 'contact', 'name' => $r['by_contact_name'] === null ? null : (string) $r['by_contact_name']];
            } else {
                $by = ['kind' => 'system', 'name' => null];
            }
            return [
                'id' => (int) $r['taward_id'],
                'achievement' => [
                    'id' => (int) $r['taward_achievement_id'],
                    'uid' => $pub['uid'],
                    'name' => $pub['name'],
                    'icon' => $pub['icon'],
                    'color' => $pub['color'],
                ],
                'contact' => [
                    'id' => (int) $r['taward_contact_id'],
                    'name' => (string) $r['contact_name'],
                    'dept' => $r['client_name'] === null ? null : (string) $r['client_name'],
                    'archived' => $r['contact_archived_at'] !== null,
                ],
                'source' => (string) $r['taward_source'],
                'rule_type' => (string) $r['taward_rule_type'],
                'reason' => $r['taward_reason'] === null ? null : (string) $r['taward_reason'],
                'awarded_by' => $by,
                'awarded_at' => $pub['awarded_at'],
                'kiosk_id' => $r['taward_kiosk_id'] === null ? null : (int) $r['taward_kiosk_id'],
            ];
        }, $rows);
    }

    /** @param list<array> $rows PUBLIC_COLS rows */
    private static function publicRows(\mysqli $db, array $rows, ?string $lang): array
    {
        $names = self::translations($db, array_map(static fn($r) => (int) $r['taward_achievement_id'], $rows), $lang);
        return array_map(static function (array $r) use ($names): array {
            $t = $names[(int) $r['taward_achievement_id']] ?? [];
            if (isset($t['name'])) {
                $r['tr_name'] = $t['name'];
            }
            if (isset($t['description'])) {
                $r['tr_description'] = $t['description'];
            }
            return AwardEngine::publicShape($r);
        }, $rows);
    }

    /** training_i18n name/description of achievements in $lang. @return array<int, array{name?:string, description?:string}> */
    private static function translations(\mysqli $db, array $achievementIds, ?string $lang): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $achievementIds), static fn($i) => $i > 0)));
        if ($lang === null || $lang === '' || $ids === [] || preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $lang) !== 1) {
            return [];
        }
        $out = [];
        foreach (Db::all($db, "SELECT ti18n_entity_id, ti18n_field, ti18n_value FROM training_i18n
            WHERE ti18n_entity = 'achievement' AND ti18n_lang = ? AND ti18n_field IN ('name','description')
              AND ti18n_entity_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', 's' . str_repeat('i', count($ids)), array_merge([$lang], $ids)) as $r) {
            if ((string) $r['ti18n_value'] !== '') {
                $out[(int) $r['ti18n_entity_id']][(string) $r['ti18n_field']] = (string) $r['ti18n_value'];
            }
        }
        return $out;
    }
}
