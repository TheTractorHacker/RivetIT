<?php

/*
 * Ticket list filters that take MORE THAN ONE value (assigned to, priority, board, category, on-site/remote, due).
 *
 * One place turns whatever the request carries into validated sets, and one place turns the sets into SQL, so the ticket
 * list, the kanban board, saved views and the API all read the same filters the same way:
 *
 *   - OR inside a group (High OR Medium), AND across groups (High/Medium AND assigned to me/Unassigned).
 *   - Old single values keep working (assigned=me, priority=High, board=5, onsite=1, overdue=1). The canonical new form is a
 *     list: assigned[]=me&assigned[]=7, priority[]=High&priority[]=Medium, board[]=5&board[]=9. A comma list in one value
 *     (priority=High,Medium) is read the same way, which is how the API passes lists.
 *   - Nothing here puts request text in SQL: ids are integers, priorities come from a fixed list, anything else (nested
 *     arrays, objects, non-numbers, SQL metacharacters) is dropped, and each group is capped.
 *
 * Pure functions: no database, no session, so the whole thing can be tested without either.
 */

const TICKET_FILTER_GROUP_CAP = 50;
const TICKET_FILTER_PRIORITIES = ['High', 'Medium', 'Low'];

/** The request keys that mean "the person chose a filter": with none of them present the user's default view applies. */
const TICKET_FILTER_EXPLICIT_KEYS = [
    'status', 'assigned', 'assigned_to', 'priority', 'board', 'category', 'onsite', 'overdue', 'due_today', 'tags', 'project',
    'due', 'billable', 'unbilled', 'canned_date', 'dtf', 'dtt', 'datefield', 'q', 'nodefault',
];

/**
 * Flatten a request value (a scalar, a comma list or a list) into a capped list of trimmed, non-empty strings.
 * Nested arrays, objects and other non-scalars are dropped.
 *
 * @param mixed $v
 * @return string[]
 */
function ticketFilterTokens($v, int $cap = TICKET_FILTER_GROUP_CAP): array
{
    $raw = is_array($v) ? $v : [$v];
    $out = [];
    foreach ($raw as $item) {
        if (!is_scalar($item) || is_bool($item)) {
            continue;
        }
        foreach (explode(',', (string) $item) as $part) {
            $part = trim($part);
            if ($part !== '' && strlen($part) <= 64) {
                $out[] = $part;
            }
            if (count($out) >= $cap) {
                return $out;
            }
        }
    }

    return $out;
}

/**
 * Positive integers only (digits, no sign, no decimals), de-duplicated and capped.
 *
 * @param mixed $v
 * @return int[]
 */
function ticketFilterIds($v, int $cap = TICKET_FILTER_GROUP_CAP): array
{
    $ids = [];
    foreach (ticketFilterTokens($v, $cap * 4) as $t) {
        if (ctype_digit($t) && strlen($t) <= 10 && (int) $t > 0) {
            $ids[(int) $t] = true;
        }
        if (count($ids) >= $cap) {
            break;
        }
    }

    return array_keys($ids);
}

/** True when an on/off request flag (overdue, due_today) is switched on: present and not an explicit "off" value. */
function ticketFilterFlag($v): bool
{
    if (is_array($v)) {
        return false;
    }

    return $v !== null && !in_array(strtolower(trim((string) $v)), ['0', 'false', 'off', 'no'], true);
}

/**
 * The validated sets a request (or a stored view query, parsed) asks for.
 *
 *   assigned  int[]    user ids; 0 stands for "unassigned"; "me" is resolved to $me_id
 *   priority  string[] a subset of High / Medium / Low, in that order
 *   board     int[]
 *   category  int[]
 *   onsite    int[]    a subset of [1 on-site, 0 remote]; both is the same as no filter, so it comes back empty
 *   overdue   bool
 *   due_today bool
 *
 * @param array<string,mixed> $src
 * @return array{assigned:int[],priority:string[],board:int[],category:int[],onsite:int[],overdue:bool,due_today:bool}
 */
function ticketFilterSets(array $src, int $me_id): array
{
    $assigned = [];
    $raw_assigned = $src['assigned'] ?? ($src['assigned_to'] ?? null);
    foreach (ticketFilterTokens($raw_assigned) as $t) {
        $low = strtolower($t);
        if ($low === 'me') {
            if ($me_id > 0) {
                $assigned[$me_id] = true;
            }
        } elseif ($low === 'unassigned') {
            $assigned[0] = true;
        } elseif (ctype_digit($t) && strlen($t) <= 10) {
            $assigned[(int) $t] = true;
        }
    }

    $priority = [];
    foreach (ticketFilterTokens($src['priority'] ?? null) as $t) {
        foreach (TICKET_FILTER_PRIORITIES as $p) {
            if (strcasecmp($p, $t) === 0) {
                $priority[$p] = true;
            }
        }
    }
    $priority = array_values(array_filter(TICKET_FILTER_PRIORITIES, static fn ($p) => isset($priority[$p])));

    $onsite = [];
    foreach (ticketFilterTokens($src['onsite'] ?? null) as $t) {
        if ($t === '1' || $t === '0') {
            $onsite[(int) $t] = true;
        }
    }
    $onsite = count($onsite) === 2 ? [] : array_keys($onsite);

    // The filter bar's Due checkboxes send due[]=overdue&due[]=due_today; the flags (overdue=1) are the stored form.
    $due = array_map('strtolower', ticketFilterTokens($src['due'] ?? null));

    return [
        'assigned'  => array_slice(array_keys($assigned), 0, TICKET_FILTER_GROUP_CAP),
        'priority'  => $priority,
        'board'     => ticketFilterIds($src['board'] ?? null),
        'category'  => ticketFilterIds($src['category'] ?? null),
        'onsite'    => $onsite,
        'overdue'   => ticketFilterFlag($src['overdue'] ?? null) || in_array('overdue', $due, true),
        'due_today' => ticketFilterFlag($src['due_today'] ?? null) || in_array('due_today', $due, true),
    ];
}

/**
 * SQL for the sets: one "AND ..." snippet per group (empty string when the group is not filtering). Everything interpolated
 * is an integer or a value from the fixed priority list. $alias is the tickets table alias including the dot ('t.'), if any.
 *
 * @param array<string,mixed> $sets as returned by ticketFilterSets()
 * @return array{assigned:string,priority:string,board:string,category:string,onsite:string,due:string}
 */
function ticketFilterSql(array $sets, string $alias = ''): array
{
    if ($alias !== '' && !preg_match('/^[a-z_][a-z0-9_]*\.$/i', $alias)) {
        $alias = '';
    }
    $ints = static fn (array $a): string => implode(',', array_map('intval', $a));
    $out = ['assigned' => '', 'priority' => '', 'board' => '', 'category' => '', 'onsite' => '', 'due' => ''];

    if (!empty($sets['assigned'])) {
        $out['assigned'] = "AND {$alias}ticket_assigned_to IN (" . $ints($sets['assigned']) . ')';
    }
    if (!empty($sets['priority'])) {
        $vals = array_values(array_intersect(TICKET_FILTER_PRIORITIES, $sets['priority']));
        if ($vals) {
            $out['priority'] = "AND {$alias}ticket_priority IN ('" . implode("','", $vals) . "')";
        }
    }
    if (!empty($sets['board'])) {
        $in = $ints($sets['board']);
        $out['board'] = "AND {$alias}ticket_category IN (SELECT category_id FROM categories WHERE category_id IN ($in) OR category_parent IN ($in))";
    }
    if (!empty($sets['category'])) {
        $out['category'] = "AND {$alias}ticket_category IN (" . $ints($sets['category']) . ')';
    }
    if (!empty($sets['onsite'])) {
        $out['onsite'] = "AND {$alias}ticket_onsite IN (" . $ints($sets['onsite']) . ')';
    }

    $due = [];
    if (!empty($sets['overdue'])) {
        $due[] = "{$alias}ticket_due_at < NOW()";
    }
    if (!empty($sets['due_today'])) {
        $due[] = "DATE({$alias}ticket_due_at) = CURDATE()";
    }
    if ($due) {
        $out['due'] = "AND {$alias}ticket_due_at IS NOT NULL AND {$alias}ticket_resolved_at IS NULL AND (" . implode(' OR ', $due) . ')';
    }

    return $out;
}

/**
 * Write a filter query in the canonical form: lists as key[]=a&key[]=b, scalars as key=value. Keys and values are URL-encoded.
 *
 * @param array<string,mixed> $q
 */
function ticketFilterBuildQuery(array $q): string
{
    $parts = [];
    foreach ($q as $key => $val) {
        $k = rawurlencode((string) $key);
        if (is_array($val)) {
            foreach ($val as $item) {
                if (is_scalar($item)) {
                    $parts[] = $k . '[]=' . rawurlencode((string) $item);
                }
            }
        } elseif ($val !== null) {
            $parts[] = $k . '=' . rawurlencode((string) $val);
        }
    }

    return implode('&', $parts);
}

/**
 * A comparable fingerprint of the filters in a request, so the page can tell which saved view is the active one. Lists are
 * compared by content, not order, "me" and the viewer's own id are the same thing, and an absent key equals an empty one.
 *
 * @param array<string,mixed> $src
 */
function ticketFilterSignature(array $src, int $me_id): string
{
    $sets = ticketFilterSets($src, $me_id);
    $sorted = static function (array $a): string {
        $a = array_map('strval', $a);
        sort($a);

        return implode(',', $a);
    };
    $status = $src['status'] ?? '';
    $status = is_array($status) ? $sorted(ticketFilterIds($status)) : (string) $status;
    $tags = $sorted(ticketFilterIds($src['tags'] ?? null));

    return implode('|', [
        $status,
        $sorted($sets['assigned']), $sorted($sets['priority']), $sorted($sets['board']), $sorted($sets['category']),
        $sorted($sets['onsite']), $sets['overdue'] ? 1 : 0, $sets['due_today'] ? 1 : 0, $tags,
    ]);
}

/** Does this request carry any filter of its own? (Then the user's default view must not override it.) */
function ticketFilterRequestIsExplicit(array $get): bool
{
    foreach (TICKET_FILTER_EXPLICIT_KEYS as $k) {
        if (array_key_exists($k, $get)) {
            return true;
        }
    }

    return false;
}
