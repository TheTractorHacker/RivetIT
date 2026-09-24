<?php

namespace ITFlow\Training\Quiz;

/**
 * Placeholder lists for `IN (...)` in prepared statements.
 *
 *   [$ph, $types, $params] = InList::ints($ids);
 *   Db::all($db, "SELECT ... WHERE x IN ($ph)", $types, $params);
 *
 * An empty id list yields `IN (NULL)`, which matches nothing, so callers never build an
 * invalid `IN ()`.
 */
final class InList
{
    /** @return array{0:string, 1:string, 2:list<int>} */
    public static function ints(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $clean[$id] = $id;
        }
        $clean = array_values($clean);
        if ($clean === []) {
            return ['NULL', '', []];
        }
        return [implode(',', array_fill(0, count($clean), '?')), str_repeat('i', count($clean)), $clean];
    }

    /** @return array{0:string, 1:string, 2:list<string>} */
    public static function strings(array $values): array
    {
        $clean = array_values(array_unique(array_map('strval', $values)));
        if ($clean === []) {
            return ['NULL', '', []];
        }
        return [implode(',', array_fill(0, count($clean), '?')), str_repeat('s', count($clean)), $clean];
    }
}
