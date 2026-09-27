<?php

namespace ITFlow\Training\Reminders;

/**
 * Plain-text wording for the daily Training digest and the overdue escalation (Phase 5 S4,
 * spec §3.5). Pure functions over Upstream\ComplianceGateway::departmentCounts() rows; no
 * database, no clock.
 *
 * The numbers are P2's pair statuses, counted by the gateway (spec §1.4 #12): "overdue",
 * "due soon" and "renewal due" follow Training compliance settings, so the wording never names
 * a window ("within N days"). Only the escalation threshold, which is this feature's own
 * setting, is spelled out ("more than 14 days").
 *
 * The text is plain (the notifications UI escapes it) and always fits Automation\Notify's
 * 990-byte clip WITH its "…and N more" tail: departments are added only while the tail still
 * fits, so a long list is summarised rather than cut mid-sentence.
 */
final class DigestBuilder
{
    /** At most this many departments are named; the rest become "…and N more departments." */
    public const MAX_DEPARTMENTS = 6;

    /** Byte budget for the whole text (Notify clips at 990 bytes; keep a margin). */
    private const BUDGET_BYTES = 940;

    /** A department name longer than this is shortened in the text (never in the database). */
    private const NAME_CHARS = 60;

    /**
     * "Training for your departments: Fabrication — 5 overdue (2 more than 14 days), 3 due soon,
     * 2 renewals due. CNC Machining — 1 overdue." Top departments by overdue, then "…and N more
     * departments." Null when every count is 0.
     *
     * @param list<array{client_id:int, client_name:string, overdue:int, escalated:int, due_soon:int, renewal:int}> $rows
     */
    public static function digestText(array $rows, int $escalateDays): ?string
    {
        $rows = array_values(array_filter($rows, static fn($r) => self::n($r, 'overdue') + self::n($r, 'due_soon') + self::n($r, 'renewal') > 0));
        if ($rows === []) {
            return null;
        }
        usort($rows, static function ($a, $b) {
            return [self::n($b, 'overdue'), self::n($b, 'escalated'), self::n($b, 'due_soon'), self::n($b, 'renewal')]
                <=> [self::n($a, 'overdue'), self::n($a, 'escalated'), self::n($a, 'due_soon'), self::n($a, 'renewal')]
                ?: strcasecmp(self::name($a), self::name($b));
        });

        $parts = [];
        foreach ($rows as $r) {
            $bits = [];
            if (self::n($r, 'overdue') > 0) {
                $o = self::n($r, 'overdue') . ' overdue';
                if (self::n($r, 'escalated') > 0) {
                    $o .= ' (' . self::n($r, 'escalated') . ' more than ' . self::days($escalateDays) . ')';
                }
                $bits[] = $o;
            }
            if (self::n($r, 'due_soon') > 0) {
                $bits[] = self::n($r, 'due_soon') . ' due soon';
            }
            if (self::n($r, 'renewal') > 0) {
                $bits[] = self::n($r, 'renewal') . (self::n($r, 'renewal') === 1 ? ' renewal due' : ' renewals due');
            }
            $parts[] = self::name($r) . ' — ' . implode(', ', $bits) . '.';
        }
        return self::assemble('Training for your departments:', $parts, 'department');
    }

    /**
     * "Overdue more than 14 days: Fabrication 2 people (Lockout/Tagout, Forklift), Field 1 person
     * (HazCom)." Only departments with escalated > 0; most people first. Null when there are none.
     *
     * @param list<array{client_name:string, escalated:int, escalated_people?:list<array{name:string, courses:list<string>}>, top_courses?:list<string>}> $rows
     */
    public static function escalationText(array $rows, int $escalateDays): ?string
    {
        $items = [];
        foreach ($rows as $r) {
            if (self::n($r, 'escalated') <= 0) {
                continue;
            }
            $people = is_array($r['escalated_people'] ?? null) ? $r['escalated_people'] : [];
            $count = $people !== [] ? count($people) : self::n($r, 'escalated');
            $freq = [];
            foreach ($people as $p) {
                foreach ((array) ($p['courses'] ?? []) as $c) {
                    $c = self::clean((string) $c, self::NAME_CHARS);
                    if ($c !== '') {
                        $freq[$c] = ($freq[$c] ?? 0) + 1;
                    }
                }
            }
            if ($freq === [] && is_array($r['top_courses'] ?? null)) {
                foreach ($r['top_courses'] as $c) {
                    $c = self::clean((string) $c, self::NAME_CHARS);
                    if ($c !== '') {
                        $freq[$c] = 1;
                    }
                }
            }
            uksort($freq, static fn($a, $b) => ($freq[$b] <=> $freq[$a]) ?: strcasecmp($a, $b));
            $courses = array_keys($freq);
            $shown = array_slice($courses, 0, 3);
            $more = count($courses) - count($shown);
            $list = $shown === [] ? '' : ' (' . implode(', ', $shown) . ($more > 0 ? ', +' . $more . ' more' : '') . ')';
            $items[] = ['n' => $count, 'name' => self::name($r), 'text' => self::name($r) . ' ' . $count . ($count === 1 ? ' person' : ' people') . $list];
        }
        if ($items === []) {
            return null;
        }
        usort($items, static fn($a, $b) => ($b['n'] <=> $a['n']) ?: strcasecmp($a['name'], $b['name']));
        $parts = array_column($items, 'text');
        // One sentence: "A 2 people (…), B 1 person (…)." - the separator is ", " and the last part gets the full stop.
        $head = 'Overdue more than ' . self::days($escalateDays) . ':';
        return self::assemble($head, $parts, 'department', ', ', '.');
    }

    // ------------------------------------------------------------------------------------------

    /**
     * Head + as many parts as fit (at most MAX_DEPARTMENTS) + "…and N more departments.".
     *
     * @param list<string> $parts
     */
    private static function assemble(string $head, array $parts, string $noun, string $sep = ' ', string $end = ''): string
    {
        $total = count($parts);
        $taken = [];
        foreach ($parts as $i => $p) {
            if (count($taken) >= self::MAX_DEPARTMENTS) {
                break;
            }
            $rest = $total - $i - 1;
            $candidate = $head . ' ' . implode($sep, array_merge($taken, [$p])) . $end . self::tail($rest, $noun);
            if (strlen($candidate) > self::BUDGET_BYTES && $taken !== []) {
                break;
            }
            $taken[] = $p;
        }
        $text = $head . ' ' . implode($sep, $taken) . $end . self::tail($total - count($taken), $noun);
        // A single oversized part (impossible with clipped names, kept as a guard) is clipped as valid UTF-8.
        return strlen($text) > self::BUDGET_BYTES ? mb_strcut($text, 0, self::BUDGET_BYTES, 'UTF-8') : $text;
    }

    private static function tail(int $more, string $noun): string
    {
        return $more > 0 ? ' …and ' . $more . ' more ' . $noun . ($more === 1 ? '' : 's') . '.' : '';
    }

    private static function days(int $n): string
    {
        $n = max(0, $n);
        return $n === 1 ? '1 day' : $n . ' days';
    }

    private static function n(array $r, string $k): int
    {
        return max(0, (int) ($r[$k] ?? 0));
    }

    private static function name(array $r): string
    {
        $name = self::clean((string) ($r['client_name'] ?? ''), self::NAME_CHARS);
        if ($name === '') {
            return (int) ($r['client_id'] ?? 0) === 0 ? 'No department' : 'Department #' . (int) ($r['client_id'] ?? 0);
        }
        return $name;
    }

    /** Valid UTF-8, no control characters or line breaks, collapsed spaces, at most $max characters. */
    private static function clean(string $s, int $max): string
    {
        $s = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', mb_scrub($s, 'UTF-8')));
        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    }
}
