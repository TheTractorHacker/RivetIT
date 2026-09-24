<?php

namespace ITFlow\Training\Catalog;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;

/**
 * The achievement rule editor's model (plan A15): each rule type, its parameters, their
 * validation, and a human summary built from parts (rendered with textContent, never HTML).
 *
 * Phase 1 stores definitions only; the award engine (Phase 3) reads achievement_rule_type and
 * the normalised parameters in achievement_rule_json.
 */
final class AchievementRules
{
    /** type => [label, kind, params => [name => spec]] ; spec: [kind, required, min?, max?] */
    public const TYPES = [
        'manual' => ['label' => 'Awarded by a trainer or admin', 'kind' => 'manual', 'params' => []],
        'course_completed' => ['label' => 'Completes a course', 'kind' => 'automatic', 'params' => ['course_id' => ['course', true]]],
        'category_completed' => ['label' => 'Completes every course in a category', 'kind' => 'automatic', 'params' => ['category_id' => ['category', true]]],
        'path_completed' => ['label' => 'Completes a learning path', 'kind' => 'automatic', 'params' => ['path_id' => ['path', true]]],
        'perfect_score' => ['label' => 'Scores 100% on a final exam', 'kind' => 'automatic', 'params' => ['course_id' => ['course', false]]],
        'first_attempt_pass' => ['label' => 'Passes a final exam on the first try', 'kind' => 'automatic', 'params' => ['course_id' => ['course', false]]],
        'on_time_streak' => ['label' => 'All required training on time for a number of months', 'kind' => 'automatic', 'params' => ['months' => ['int', true, 1, 60]]],
        'courses_completed_count' => ['label' => 'Completes a number of courses', 'kind' => 'automatic', 'params' => ['count' => ['int', true, 1, 500], 'category_id' => ['category', false]]],
    ];

    /**
     * Validates and normalises a rule's parameters: only the type's keys, typed ints,
     * referenced course/category/path must exist and be live. 422 with `rule.<param>` fields.
     *
     * @return array<string, int> normalised parameters (optional ones omitted when empty)
     */
    public static function validate(string $type, array $p, \mysqli $db): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            throw ApiException::validation(['rule_type' => 'Choose when this achievement is earned.']);
        }
        foreach (array_keys($p) as $k) {
            if (!isset($def['params'][$k])) {
                throw ApiException::validation(['rule.' . $k => 'Not used by this rule.']);
            }
        }
        $out = [];
        foreach ($def['params'] as $name => $spec) {
            [$kind, $required] = $spec;
            $v = $p[$name] ?? null;
            if ($v === null || $v === '') {
                if ($required) {
                    throw ApiException::validation(['rule.' . $name => self::requiredMessage($kind)]);
                }
                continue;
            }
            if (is_string($v) && preg_match('/^[0-9]{1,10}$/', $v) === 1) {
                $v = (int) $v;
            }
            if (!is_int($v)) {
                throw ApiException::validation(['rule.' . $name => 'Must be a whole number.']);
            }
            if ($kind === 'int') {
                [, , $min, $max] = $spec;
                if ($v < $min || $v > $max) {
                    throw ApiException::validation(['rule.' . $name => "Must be between $min and $max."]);
                }
            } elseif (self::nameOf($db, $kind, $v, true) === null) {
                throw ApiException::validation(['rule.' . $name => self::requiredMessage($kind)]);
            }
            $out[$name] = $v;
        }
        ksort($out);
        return $out;
    }

    /**
     * Human summary as parts, e.g. [{text:'Completes the ', strong:false}, {text:'Crane Operator', strong:true},
     * {text:' learning path', strong:false}]. References that no longer exist read "(deleted …)".
     *
     * @return list<array{text:string, strong:bool}>
     */
    public static function summary(string $type, array $p, \mysqli $db): array
    {
        $t = static fn(string $s, bool $strong = false) => ['text' => $s, 'strong' => $strong];
        $course = static fn(?int $id) => $id === null ? null : (self::nameOf($db, 'course', $id, false) ?? '(deleted course)');
        $category = static fn(?int $id) => $id === null ? null : (self::nameOf($db, 'category', $id, false) ?? '(deleted category)');
        $path = static fn(?int $id) => $id === null ? null : (self::nameOf($db, 'path', $id, false) ?? '(deleted path)');
        $int = static fn(string $k) => isset($p[$k]) ? (int) $p[$k] : null;

        switch ($type) {
            case 'course_completed':
                return [$t('Completes '), $t($course($int('course_id')) ?? 'a course', true)];
            case 'category_completed':
                return [$t('Completes every course in '), $t($category($int('category_id')) ?? 'a category', true)];
            case 'path_completed':
                return [$t('Completes the '), $t($path($int('path_id')) ?? 'chosen', true), $t(' learning path')];
            case 'perfect_score':
                $c = $course($int('course_id'));
                return $c === null ? [$t('Scores '), $t('100%', true), $t(' on any final exam')]
                    : [$t('Scores '), $t('100%', true), $t(' on the final exam of '), $t($c, true)];
            case 'first_attempt_pass':
                $c = $course($int('course_id'));
                return $c === null ? [$t('Passes any final exam on the '), $t('first try', true)]
                    : [$t('Passes the final exam of '), $t($c, true), $t(' on the first try')];
            case 'on_time_streak':
                $m = $int('months') ?? 0;
                return [$t('All required training on time for '), $t($m === 1 ? '1 month' : "$m months", true)];
            case 'courses_completed_count':
                $n = $int('count') ?? 0;
                $parts = [$t('Completes '), $t($n === 1 ? '1 course' : "$n courses", true)];
                $cat = $category($int('category_id'));
                if ($cat !== null) {
                    $parts[] = $t(' in ');
                    $parts[] = $t($cat, true);
                }
                return $parts;
            default:
                return [$t('Awarded by a trainer or admin')];
        }
    }

    /** 'manual' or 'automatic'. */
    public static function kind(string $type): string
    {
        return self::TYPES[$type]['kind'] ?? 'manual';
    }

    /** The editor's rule-type list: [{key, label, kind, params:[{name, kind, required, min?, max?}]}]. */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::TYPES as $key => $def) {
            $params = [];
            foreach ($def['params'] as $name => $spec) {
                $params[] = ['name' => $name, 'kind' => $spec[0], 'required' => $spec[1]]
                    + ($spec[0] === 'int' ? ['min' => $spec[2], 'max' => $spec[3]] : []);
            }
            $out[] = ['key' => $key, 'label' => $def['label'], 'kind' => $def['kind'], 'params' => $params];
        }
        return $out;
    }

    private static function nameOf(\mysqli $db, string $kind, int $id, bool $liveOnly): ?string
    {
        $row = match ($kind) {
            'course' => Db::one($db, 'SELECT course_name AS n FROM training_courses WHERE course_id = ?' . ($liveOnly ? ' AND course_archived_at IS NULL' : ''), 'i', [$id]),
            'category' => Db::one($db, 'SELECT tcat_name AS n FROM training_categories WHERE tcat_id = ?' . ($liveOnly ? ' AND tcat_archived_at IS NULL' : ''), 'i', [$id]),
            'path' => Db::one($db, 'SELECT tpath_name AS n FROM training_paths WHERE tpath_id = ?' . ($liveOnly ? ' AND tpath_archived_at IS NULL' : ''), 'i', [$id]),
            default => null,
        };
        return $row === null ? null : (string) $row['n'];
    }

    private static function requiredMessage(string $kind): string
    {
        return match ($kind) {
            'course' => 'Choose a course.',
            'category' => 'Choose a category.',
            'path' => 'Choose a learning path.',
            default => 'Required.',
        };
    }
}
