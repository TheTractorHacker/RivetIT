<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Core\Db;

/**
 * The whole question-bank forest in memory (the table is small: one row per bank).
 *
 * Children are always ordered by (qbank_sort, qbank_id), so every traversal - and therefore
 * every pool (spec §3.5 "bank DFS, question_sort, id") - is deterministic. Archived banks are
 * kept in the map (a revision may still name one) but live traversals skip them together with
 * everything below them.
 */
final class BankTree
{
    public const MAX_DEPTH = 5;

    /** @var array<int, array<string, mixed>> */
    private array $banks = [];
    /** @var array<int, list<int>> parent id (0 = root) => child ids in order */
    private array $children = [];

    public static function load(\mysqli $db): self
    {
        $t = new self();
        $rows = Db::all($db, 'SELECT ' . Guard::BANK_COLS . ' FROM training_question_banks ORDER BY qbank_sort, qbank_id');
        foreach ($rows as $r) {
            $id = (int) $r['qbank_id'];
            $t->banks[$id] = $r;
        }
        foreach ($t->banks as $id => $r) {
            $parent = $r['qbank_parent_id'] === null ? 0 : (int) $r['qbank_parent_id'];
            if ($parent !== 0 && !isset($t->banks[$parent])) {
                $parent = 0; // orphan: treat as a root rather than losing it
            }
            $t->children[$parent][] = $id;
        }
        return $t;
    }

    public function get(int $id): ?array
    {
        return $this->banks[$id] ?? null;
    }

    public function isLive(int $id): bool
    {
        return isset($this->banks[$id]) && $this->banks[$id]['qbank_archived_at'] === null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->banks;
    }

    /** @return list<int> */
    public function children(int $id, bool $liveOnly = true): array
    {
        $out = [];
        foreach ($this->children[$id] ?? [] as $c) {
            if (!$liveOnly || $this->isLive($c)) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /** @return list<int> root bank ids in order */
    public function roots(bool $liveOnly = true): array
    {
        return $this->children(0, $liveOnly);
    }

    /**
     * $id and everything below it, depth-first pre-order.
     *
     * @return list<int>
     */
    public function subtree(int $id, bool $liveOnly = true): array
    {
        if (!isset($this->banks[$id]) || ($liveOnly && !$this->isLive($id))) {
            return [];
        }
        $out = [];
        $stack = [$id];
        $seen = [];
        while ($stack !== []) {
            $cur = array_pop($stack);
            if (isset($seen[$cur])) {
                continue; // corrupted parent links can never loop us
            }
            $seen[$cur] = true;
            $out[] = $cur;
            $kids = $this->children($cur, $liveOnly);
            for ($i = count($kids) - 1; $i >= 0; $i--) {
                $stack[] = $kids[$i];
            }
        }
        return $out;
    }

    /**
     * The banks a rule draws from, in pool order: the bank itself, then (with descendants) its
     * live sub-banks depth-first. An archived or missing bank draws nothing.
     *
     * @return list<int>
     */
    public function poolBanks(int $id, bool $includeDescendants): array
    {
        if (!$this->isLive($id)) {
            return [];
        }
        return $includeDescendants ? $this->subtree($id, true) : [$id];
    }

    /**
     * Parent first, root last.
     *
     * @return list<int>
     */
    public function ancestors(int $id): array
    {
        $out = [];
        $cur = $this->banks[$id]['qbank_parent_id'] ?? null;
        $guard = 0;
        while ($cur !== null && isset($this->banks[(int) $cur]) && $guard++ < 64) {
            $cur = (int) $cur;
            if (in_array($cur, $out, true) || $cur === $id) {
                break;
            }
            $out[] = $cur;
            $cur = $this->banks[$cur]['qbank_parent_id'];
        }
        return $out;
    }

    /** Root = 1. */
    public function depth(int $id): int
    {
        return count($this->ancestors($id)) + 1;
    }

    /** Levels in the live subtree rooted at $id (a leaf = 1). */
    public function height(int $id): int
    {
        $kids = $this->children($id, true);
        if ($kids === []) {
            return 1;
        }
        $max = 0;
        foreach ($kids as $k) {
            $max = max($max, $this->height($k));
        }
        return 1 + $max;
    }

    /** "Root / Child / Bank". */
    public function path(int $id): string
    {
        if (!isset($this->banks[$id])) {
            return '';
        }
        $names = [(string) $this->banks[$id]['qbank_name']];
        foreach ($this->ancestors($id) as $a) {
            array_unshift($names, (string) $this->banks[$a]['qbank_name']);
        }
        return implode(' / ', $names);
    }

    public function isQuizBank(int $id): bool
    {
        return isset($this->banks[$id]) && $this->banks[$id]['qbank_quiz_lesson_id'] !== null;
    }

    public function isCourseRoot(int $id): bool
    {
        $b = $this->banks[$id] ?? null;
        return $b !== null && $b['qbank_course_id'] !== null && $b['qbank_parent_id'] === null && $b['qbank_quiz_lesson_id'] === null;
    }
}
