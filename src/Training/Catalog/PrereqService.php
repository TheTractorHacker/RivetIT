<?php

namespace ITFlow\Training\Catalog;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Authoring\CourseService;
use ITFlow\Training\Authoring\Guard;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Course prerequisites ("a course can require others first", plan A15), with cycle
 * detection: a new set of requirements that would let a course (indirectly) require itself
 * is a 422 prereq_cycle naming the loop. At most 10 direct prerequisites per course.
 *
 * Writes are serialised with a database-scoped named lock, so two authors saving A→B and
 * B→A at the same moment cannot both pass the check. Prerequisites are live catalog
 * configuration: no version bump, no CourseTouch.
 */
final class PrereqService
{
    public const MAX_PREREQS = 10;
    private const LOCK = 'trprereq';

    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * Replaces the courses $courseId requires. Returns [{course_id, name}].
     *
     * @param list<int> $requiresIds
     */
    public function set(int $courseId, array $requiresIds): array
    {
        $db = $this->c->db;
        Guard::writableCourse($db, $courseId);
        $ids = array_values(array_unique(array_map('intval', $requiresIds)));
        if (count($ids) > self::MAX_PREREQS) {
            throw ApiException::validation(['requires' => 'A course can have at most ' . self::MAX_PREREQS . ' prerequisites.']);
        }
        if (in_array($courseId, $ids, true)) {
            throw new ApiException(422, 'prereq_cycle', "A course can't require itself.", ['requires' => "A course can't require itself."]);
        }
        if ($ids !== []) {
            $found = Db::all($db, 'SELECT course_id FROM training_courses WHERE course_archived_at IS NULL AND course_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
            if (count($found) !== count($ids)) {
                throw ApiException::validation(['requires' => 'A course in the list no longer exists or is archived.']);
            }
        }

        if (!Db::lock($db, self::LOCK, 5)) {
            throw ApiException::busy('Someone else is changing prerequisites; try again.');
        }
        try {
            Db::tx($db, function () use ($db, $courseId, $ids): void {
                Guard::writableCourse($db, $courseId, true);
                $graph = [];
                foreach (Db::all($db, 'SELECT prereq_course_id, prereq_requires_course_id FROM training_course_prereqs') as $e) {
                    $graph[(int) $e['prereq_course_id']][] = (int) $e['prereq_requires_course_id'];
                }
                $graph[$courseId] = $ids;
                $cycle = self::findCycle($graph, $courseId);
                if ($cycle !== null) {
                    $names = self::names($db, $cycle);
                    $label = implode(' → ', array_map(static fn($id) => $names[$id] ?? ('#' . $id), $cycle));
                    throw new ApiException(422, 'prereq_cycle', "That would make a loop: $label.", ['requires' => "That would make a loop: $label."], ['cycle' => $cycle]);
                }
                $current = array_map(static fn($r) => (int) $r['prereq_requires_course_id'], Db::all($db, 'SELECT prereq_requires_course_id FROM training_course_prereqs WHERE prereq_course_id = ?', 'i', [$courseId]));
                foreach (array_diff($current, $ids) as $gone) {
                    Db::exec($db, 'DELETE FROM training_course_prereqs WHERE prereq_course_id = ? AND prereq_requires_course_id = ?', 'ii', [$courseId, $gone]);
                }
                foreach (array_diff($ids, $current) as $new) {
                    Db::exec($db, 'INSERT INTO training_course_prereqs (prereq_course_id, prereq_requires_course_id, prereq_created_by) VALUES (?, ?, ?)', 'iii', [$courseId, $new, $this->c->userId]);
                }
            });
        } finally {
            Db::unlock($db, self::LOCK);
        }
        return CourseService::prereqs($db, $courseId);
    }

    /**
     * The prerequisite map: every course the caller may see, with what it requires and what
     * requires it. Level 1 sees published, non-archived courses only.
     */
    public function map(): array
    {
        $courses = [];
        foreach ((new CourseService($this->c))->list([])['courses'] as $cs) {
            $courses[$cs['id']] = $cs;
        }
        $requires = [];
        $requiredBy = [];
        foreach (Db::all($this->c->db, 'SELECT prereq_course_id, prereq_requires_course_id FROM training_course_prereqs ORDER BY prereq_course_id, prereq_requires_course_id') as $e) {
            $from = (int) $e['prereq_course_id'];
            $to = (int) $e['prereq_requires_course_id'];
            if (!isset($courses[$from], $courses[$to])) {
                continue;
            }
            $requires[$from][] = ['course_id' => $to, 'name' => $courses[$to]['name']];
            $requiredBy[$to][] = ['course_id' => $from, 'name' => $courses[$from]['name']];
        }
        $out = [];
        foreach ($courses as $id => $cs) {
            $out[] = [
                'course_id' => $id,
                'name' => $cs['name'],
                'code' => $cs['code'],
                'kind' => $cs['kind'],
                'status' => $cs['status'],
                'category' => $cs['category'],
                'requires' => $requires[$id] ?? [],
                'required_by' => $requiredBy[$id] ?? [],
            ];
        }
        usort($out, static fn($a, $b) => strcasecmp($a['name'], $b['name']) ?: $a['course_id'] <=> $b['course_id']);
        return $out;
    }

    /**
     * A cycle through $start in $graph (course => list of required courses), as the list of
     * course ids [start, …, start], or null. Iterative DFS, so a deep chain cannot blow the stack.
     *
     * @param array<int, list<int>> $graph
     * @return list<int>|null
     */
    public static function findCycle(array $graph, int $start): ?array
    {
        $stack = [[$start, 0]];
        $path = [$start];
        $onPath = [$start => true];
        $done = [];
        while ($stack !== []) {
            [$node, $i] = $stack[count($stack) - 1];
            $next = $graph[$node][$i] ?? null;
            if ($next === null) {
                array_pop($stack);
                array_pop($path);
                unset($onPath[$node]);
                $done[$node] = true;
                continue;
            }
            $stack[count($stack) - 1][1] = $i + 1;
            if ($next === $start) {
                return array_merge($path, [$start]);
            }
            if (isset($onPath[$next]) || isset($done[$next])) {
                continue;
            }
            $stack[] = [$next, 0];
            $path[] = $next;
            $onPath[$next] = true;
        }
        return null;
    }

    /** @return array<int, string> */
    private static function names(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $out = [];
        foreach (Db::all($db, 'SELECT course_id, course_name FROM training_courses WHERE course_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['course_id']] = (string) $r['course_name'];
        }
        return $out;
    }
}
