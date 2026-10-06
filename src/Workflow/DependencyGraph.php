<?php

namespace ITFlow\Workflow;

/**
 * Task dependencies for lifecycle workflows. A task lists the ids of the tasks that must be completed (or skipped) before it
 * can be worked: stored as a comma list in `depends_on` (template task ids on a template, run task ids on a run).
 * Pure functions, no database, so the rules are unit-testable.
 */
final class DependencyGraph
{
    /** "3, 5,x,5" -> [3, 5] (positive integers, de-duplicated, order kept). @return int[] */
    public static function parse($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string) $value);
        }
        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[(int) $part] = (int) $part;
            }
        }

        return array_values($ids);
    }

    /** @param int[] $ids */
    public static function format(array $ids): ?string
    {
        $ids = self::parse($ids);

        return $ids ? implode(',', $ids) : null;
    }

    /**
     * Checks a whole template's dependency map. @param array<int, int[]> $map task id => ids it depends on
     * @return string|null a message safe to show an administrator, or null when the map is valid
     */
    public static function validate(array $map): ?string
    {
        foreach ($map as $id => $deps) {
            foreach ($deps as $dep) {
                if ($dep === $id) {
                    return 'A task cannot depend on itself.';
                }
                if (!array_key_exists($dep, $map)) {
                    return 'A dependency must be another task in the same template.';
                }
            }
        }
        // Depth-first search; 1 = on the current path, 2 = finished. A back edge to a task on the path is a cycle.
        $state = [];
        $visit = function (int $id) use (&$visit, &$state, $map): bool {
            $state[$id] = 1;
            foreach ($map[$id] as $dep) {
                if (($state[$dep] ?? 0) === 1) {
                    return true;
                }
                if (!isset($state[$dep]) && $visit($dep)) {
                    return true;
                }
            }
            $state[$id] = 2;

            return false;
        };
        foreach (array_keys($map) as $id) {
            if (!isset($state[$id]) && $visit($id)) {
                return 'These dependencies would form a loop, so no task in it could ever start.';
            }
        }

        return null;
    }

    /**
     * Validates giving one template task a new dependency list, against the other tasks already in its template.
     * @param \mysqli $mysqli
     * @param int[] $deps
     * @param int $templateTaskId 0 for a task that is not saved yet (it cannot be part of a loop)
     */
    public static function validateForTemplate(\mysqli $mysqli, int $templateId, int $templateTaskId, array $deps): ?string
    {
        $map = [];
        $res = mysqli_query($mysqli, 'SELECT template_task_id, depends_on FROM workflow_template_tasks WHERE workflow_template_id = ' . $templateId);
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $map[(int) $r['template_task_id']] = self::parse($r['depends_on']);
        }
        if ($templateTaskId > 0) {
            $map[$templateTaskId] = $deps;
        } else {
            $map[0] = $deps; // a new task: dependencies must exist, but nothing can depend on it yet
        }

        return self::validate($map);
    }
}
