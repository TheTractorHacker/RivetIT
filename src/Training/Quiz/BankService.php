<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Canonical;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Core\Uid;

/**
 * The Question Library: a forest of banks (spec §3.5, §5.6).
 *
 * Three kinds of bank, told apart by columns rather than a type field:
 *   shared   qbank_course_id NULL                      reusable by any course
 *   course   qbank_course_id = C                       one root per course (ensureCourseRoot) plus sub-banks
 *   quiz     qbank_quiz_lesson_id = L (and course C)   "Written for this quiz": the auto bank of lesson L's
 *                                                      quiz, a child of C's root, hidden in the library by default
 *
 * Structure rules: depth <= 5; no cycles; a bank never moves between scopes (shared stays
 * shared, a course's banks stay in that course); course roots and quiz banks never move; quiz
 * banks have no sub-banks. Archiving refuses (422 bank_in_use) while any rule of a live course
 * draws from the bank or anything below it.
 */
final class BankService
{
    public const NAME_MAX = 150;
    public const DESCRIPTION_MAX = 500;

    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * Live banks as a nested list. $courseId limits the forest to shared banks plus that
     * course's banks; quiz-owned banks are left out unless asked for.
     *
     * @return list<array> Bank (§6.1)
     */
    public function tree(?int $courseId, bool $includeQuizOwned = false): array
    {
        $db = $this->c->db;
        $tree = BankTree::load($db);
        $visible = [];
        foreach ($tree->all() as $id => $b) {
            if ($b['qbank_archived_at'] !== null) {
                continue;
            }
            if ($courseId !== null && $b['qbank_course_id'] !== null && (int) $b['qbank_course_id'] !== $courseId) {
                continue;
            }
            if (!$includeQuizOwned && $b['qbank_quiz_lesson_id'] !== null) {
                continue;
            }
            $visible[$id] = true;
        }
        $ctx = $this->nodeContext($tree, array_keys($visible));
        $out = [];
        foreach ($tree->roots(true) as $root) {
            if (isset($visible[$root])) {
                $out[] = $this->node($tree, $root, $visible, $ctx);
            }
        }
        return $out;
    }

    /** One bank with its visible live subtree (quiz banks included). */
    public function get(int $bankId): array
    {
        $db = $this->c->db;
        Guard::bank($db, $bankId);
        $tree = BankTree::load($db);
        $visible = array_fill_keys($tree->subtree($bankId, true), true);
        return $this->node($tree, $bankId, $visible, $this->nodeContext($tree, array_keys($visible)));
    }

    public function create(?int $parentId, string $name, ?int $courseId, ?string $description = null): array
    {
        $name = $this->cleanName($name);
        $description = $this->cleanDescription($description);
        $db = $this->c->db;

        $id = Db::tx($db, function () use ($db, $parentId, $name, $courseId, $description): int {
            if ($parentId === null && $courseId !== null) {
                // A course has exactly one root bank; new course banks go below it.
                $parentId = $this->ensureCourseRoot($courseId);
            }
            $bankCourse = null;
            if ($parentId !== null) {
                $parent = Guard::writableBank($db, $parentId, true);
                if ($parent['qbank_quiz_lesson_id'] !== null) {
                    throw ApiException::validation(['parent_id' => "Questions written for a quiz can't have sub-banks."]);
                }
                $bankCourse = $parent['qbank_course_id'] === null ? null : (int) $parent['qbank_course_id'];
                if ($courseId !== null && $bankCourse !== $courseId) {
                    throw ApiException::validation(['parent_id' => 'That bank belongs to another course.']);
                }
                $tree = BankTree::load($db);
                if ($tree->depth($parentId) + 1 > BankTree::MAX_DEPTH) {
                    throw ApiException::validation(['parent_id' => 'Banks can be nested at most ' . BankTree::MAX_DEPTH . ' levels deep.']);
                }
            }
            $sort = $this->nextSort($db, $parentId);
            return Db::insert($db, 'INSERT INTO training_question_banks (qbank_uid, qbank_parent_id, qbank_name, qbank_description,
                    qbank_course_id, qbank_quiz_lesson_id, qbank_sort, qbank_created_by) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)',
                'sissiii', [Uid::new('b'), $parentId, $name, $description, $bankCourse, $sort, $this->c->userId]);
        });
        return $this->get($id);
    }

    /**
     * Allowed fields: name, description, parent_id, sort. Re-parenting keeps the bank in its
     * scope, depth <= 5, and never creates a cycle.
     */
    public function update(int $bankId, array $f): array
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $bankId, $f): void {
            $bank = Guard::writableBank($db, $bankId, true);
            $tree = BankTree::load($db);
            $sets = [];
            $types = '';
            $params = [];
            $structural = false;

            if (array_key_exists('name', $f)) {
                $name = $this->cleanName((string) $f['name']);
                if ($name !== $bank['qbank_name']) {
                    $sets[] = 'qbank_name = ?';
                    $types .= 's';
                    $params[] = $name;
                    $structural = true; // bank paths are part of revision JSON
                }
            }
            if (array_key_exists('description', $f)) {
                $desc = $this->cleanDescription($f['description'] === null ? null : (string) $f['description']);
                if ($desc !== $bank['qbank_description']) {
                    $sets[] = 'qbank_description = ?';
                    $types .= 's';
                    $params[] = $desc;
                }
            }
            $oldAncestors = $tree->ancestors($bankId);
            $newAncestors = $oldAncestors;
            if (array_key_exists('parent_id', $f)) {
                $newParent = $f['parent_id'] === null ? null : (int) $f['parent_id'];
                $curParent = $bank['qbank_parent_id'] === null ? null : (int) $bank['qbank_parent_id'];
                if ($newParent !== $curParent) {
                    $this->assertMovable($db, $tree, $bank, $newParent);
                    $sets[] = 'qbank_parent_id = ?';
                    $types .= 'i';
                    $params[] = $newParent;
                    $sets[] = 'qbank_sort = ?';
                    $types .= 'i';
                    $params[] = $this->nextSort($db, $newParent);
                    $newAncestors = $newParent === null ? [] : array_merge([$newParent], $tree->ancestors($newParent));
                    $structural = true;
                }
            }
            if (array_key_exists('sort', $f) && !array_key_exists('parent_id', $f)) {
                $sort = max(0, min(65535, (int) $f['sort']));
                if ($sort !== (int) $bank['qbank_sort']) {
                    $sets[] = 'qbank_sort = ?';
                    $types .= 'i';
                    $params[] = $sort;
                    $structural = true; // sibling order is pool (DFS) order
                }
            }
            if ($sets === []) {
                return;
            }
            // Courses drawing from the bank before the change (old ancestors) and after it.
            $affected = Usage::coursesForBanks($db, array_merge($tree->subtree($bankId, true), $oldAncestors), $tree);
            $types .= 'i';
            $params[] = $bankId;
            Db::exec($db, 'UPDATE training_question_banks SET ' . implode(', ', $sets) . ' WHERE qbank_id = ?', $types, $params);
            if ($structural) {
                $after = BankTree::load($db);
                $affected = array_merge($affected, Usage::coursesForBanks($db, array_merge($after->subtree($bankId, true), $newAncestors), $after));
                Usage::touch($db, $affected);
            }
        });
        return $this->get($bankId);
    }

    /** Archives the bank and every bank below it. Questions are kept (and restorable with the bank). */
    public function archive(int $bankId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $bankId): void {
            Guard::writableBank($db, $bankId, true);
            $tree = BankTree::load($db);
            $subtree = $tree->subtree($bankId, true);
            $uses = [];
            foreach (Usage::rulesForBanks($db, $subtree, $tree) as $r) {
                if ($r['course_archived_at'] === null && $r['lesson_archived_at'] === null) {
                    $uses[] = $this->useItem($r);
                }
            }
            if ($uses !== []) {
                throw new ApiException(422, 'bank_in_use', 'Quizzes still draw questions from this bank. Remove those sources first.', [], ['used_by' => $uses]);
            }
            [$ph, $t, $p] = InList::ints($subtree);
            Db::exec($db, "UPDATE training_question_banks SET qbank_archived_at = NOW() WHERE qbank_id IN ($ph) AND qbank_archived_at IS NULL", $t, $p);
        });
    }

    /** The course's root bank (created, or unarchived, when needed). Returns its id. */
    public function ensureCourseRoot(int $courseId): int
    {
        $db = $this->c->db;
        return Db::tx($db, function () use ($db, $courseId): int {
            $course = Guard::course($db, $courseId, true);
            if ($course['course_archived_at'] !== null) {
                throw ApiException::archived();
            }
            $row = Db::one($db, 'SELECT qbank_id, qbank_archived_at FROM training_question_banks
                WHERE qbank_course_id = ? AND qbank_parent_id IS NULL AND qbank_quiz_lesson_id IS NULL
                ORDER BY qbank_id LIMIT 1 FOR UPDATE', 'i', [$courseId]);
            if ($row !== null) {
                if ($row['qbank_archived_at'] !== null) {
                    Db::exec($db, 'UPDATE training_question_banks SET qbank_archived_at = NULL WHERE qbank_id = ?', 'i', [(int) $row['qbank_id']]);
                }
                return (int) $row['qbank_id'];
            }
            $name = Text::clip(trim((string) $course['course_name']), self::NAME_MAX) ?: 'Course questions';
            return Db::insert($db, 'INSERT INTO training_question_banks (qbank_uid, qbank_parent_id, qbank_name, qbank_course_id,
                    qbank_quiz_lesson_id, qbank_sort, qbank_created_by) VALUES (?, NULL, ?, ?, NULL, 0, ?)',
                'ssii', [Uid::new('b'), $name, $courseId, $this->c->userId]);
        });
    }

    /**
     * The "written for this quiz" bank of a lesson. An existing one (even archived by an
     * earlier detach) is reused and unarchived, which is how re-attaching a quick check brings
     * its questions back. Returns its id.
     */
    public function ensureQuizBank(int $lessonId): int
    {
        $db = $this->c->db;
        return Db::tx($db, function () use ($db, $lessonId): int {
            $lesson = Guard::lesson($db, $lessonId);
            $courseId = (int) $lesson['lesson_course_id'];
            $root = $this->ensureCourseRoot($courseId);
            $row = Db::one($db, 'SELECT qbank_id, qbank_archived_at FROM training_question_banks WHERE qbank_quiz_lesson_id = ?
                ORDER BY qbank_id LIMIT 1 FOR UPDATE', 'i', [$lessonId]);
            if ($row !== null) {
                if ($row['qbank_archived_at'] !== null) {
                    Db::exec($db, 'UPDATE training_question_banks SET qbank_archived_at = NULL WHERE qbank_id = ?', 'i', [(int) $row['qbank_id']]);
                }
                return (int) $row['qbank_id'];
            }
            $title = Db::one($db, 'SELECT v.lvar_title FROM training_lesson_variants v JOIN training_courses c ON c.course_id = ?
                WHERE v.lvar_lesson_id = ? AND v.lvar_lang = c.course_default_language', 'ii', [$courseId, $lessonId]);
            $label = trim((string) ($title['lvar_title'] ?? ''));
            $name = Text::clip($label === '' ? 'Quiz questions' : 'Quiz: ' . $label, self::NAME_MAX);
            return Db::insert($db, 'INSERT INTO training_question_banks (qbank_uid, qbank_parent_id, qbank_name, qbank_course_id,
                    qbank_quiz_lesson_id, qbank_sort, qbank_created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                'sisiiii', [Uid::new('b'), $root, $name, $courseId, $lessonId, $this->nextSort($db, $root), $this->c->userId]);
        });
    }

    /**
     * Content hash of the questions a rule on this bank draws (live questions of the bank, and
     * with $desc of its live sub-banks, in pool order, every language). Stored per rule bank in
     * revision JSON; DriftService compares it with the value now.
     */
    public function sha(int $bankId, bool $desc): string
    {
        return self::poolSha($this->c->db, BankTree::load($this->c->db), $bankId, $desc);
    }

    public static function poolSha(\mysqli $db, BankTree $tree, int $bankId, bool $desc): string
    {
        $ids = QuestionData::idsForBanks($db, $tree->poolBanks($bankId, $desc));
        $docs = [];
        foreach (QuestionData::load($db, $ids) as $q) {
            $docs[] = QuestionRules::revisionDoc($q, QuestionRules::textLanguages($q));
        }
        return Canonical::sha256(Canonical::doc($docs));
    }

    /**
     * Rules of OTHER courses that draw from any bank of $courseId (for delete-draft).
     *
     * @return list<array> used_by items
     */
    public function usedByOtherCourses(int $courseId): array
    {
        $db = $this->c->db;
        $ids = array_map(static fn($r) => (int) $r['qbank_id'],
            Db::all($db, 'SELECT qbank_id FROM training_question_banks WHERE qbank_course_id = ?', 'i', [$courseId]));
        $out = [];
        foreach (Usage::rulesForBanks($db, $ids) as $r) {
            if ((int) $r['lesson_course_id'] !== $courseId) {
                $out[] = $this->useItem($r);
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------

    private function assertMovable(\mysqli $db, BankTree $tree, array $bank, ?int $newParent): void
    {
        $id = (int) $bank['qbank_id'];
        if ($bank['qbank_quiz_lesson_id'] !== null) {
            throw ApiException::validation(['parent_id' => "Questions written for a quiz stay with their quiz."]);
        }
        if ($tree->isCourseRoot($id)) {
            throw ApiException::validation(['parent_id' => "A course's main bank can't be moved."]);
        }
        $scope = $bank['qbank_course_id'] === null ? null : (int) $bank['qbank_course_id'];
        if ($newParent === null) {
            if ($scope !== null) {
                throw ApiException::validation(['parent_id' => "A course's banks stay inside that course."]);
            }
            return;
        }
        $parent = Guard::writableBank($db, $newParent);
        if ($parent['qbank_quiz_lesson_id'] !== null) {
            throw ApiException::validation(['parent_id' => "Questions written for a quiz can't have sub-banks."]);
        }
        $parentScope = $parent['qbank_course_id'] === null ? null : (int) $parent['qbank_course_id'];
        if ($parentScope !== $scope) {
            throw ApiException::validation(['parent_id' => $scope === null
                ? 'Shared banks stay in the shared library.' : "A course's banks stay inside that course."]);
        }
        if (in_array($newParent, $tree->subtree($id, false), true)) {
            throw ApiException::validation(['parent_id' => "A bank can't be moved inside itself."]);
        }
        if ($tree->depth($newParent) + $tree->height($id) > BankTree::MAX_DEPTH) {
            throw ApiException::validation(['parent_id' => 'Banks can be nested at most ' . BankTree::MAX_DEPTH . ' levels deep.']);
        }
    }

    private function nextSort(\mysqli $db, ?int $parentId): int
    {
        $row = $parentId === null
            ? Db::one($db, 'SELECT COALESCE(MAX(qbank_sort), -1) + 1 AS s FROM training_question_banks WHERE qbank_parent_id IS NULL')
            : Db::one($db, 'SELECT COALESCE(MAX(qbank_sort), -1) + 1 AS s FROM training_question_banks WHERE qbank_parent_id = ?', 'i', [$parentId]);
        return min(65535, (int) ($row['s'] ?? 0));
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8')) {
            throw ApiException::validation(['name' => 'Required.']);
        }
        if (mb_strlen($name, 'UTF-8') > self::NAME_MAX) {
            throw ApiException::validation(['name' => 'Too long (at most ' . self::NAME_MAX . ' characters).']);
        }
        return $name;
    }

    private function cleanDescription(?string $d): ?string
    {
        if ($d === null) {
            return null;
        }
        $d = trim($d);
        if ($d === '') {
            return null;
        }
        if (!mb_check_encoding($d, 'UTF-8') || mb_strlen($d, 'UTF-8') > self::DESCRIPTION_MAX) {
            throw ApiException::validation(['description' => 'Too long (at most ' . self::DESCRIPTION_MAX . ' characters).']);
        }
        return $d;
    }

    private function useItem(array $r): array
    {
        return [
            'rule_id' => (int) $r['qrule_id'],
            'quiz_id' => (int) $r['quiz_id'],
            'lesson_id' => (int) $r['quiz_lesson_id'],
            'lesson_title' => (string) ($r['lesson_title'] ?? ''),
            'course_id' => (int) $r['lesson_course_id'],
            'course_name' => (string) $r['course_name'],
        ];
    }

    /**
     * Everything node() needs, loaded in bulk: per-bank question counts and per-language
     * translated counts, quiz-bank lesson titles, and rules with their quiz/course.
     */
    private function nodeContext(BankTree $tree, array $bankIds): array
    {
        $db = $this->c->db;
        $qids = QuestionData::idsForBanks($db, $bankIds);
        $questions = QuestionData::load($db, $qids);

        $langCache = [];
        $langsFor = function (array $bank) use (&$langCache, $db): array {
            $key = $bank['qbank_course_id'] === null ? 0 : (int) $bank['qbank_course_id'];
            return $langCache[$key] ??= Guard::bankLanguages($db, $bank, $this->c->settings)['offered'];
        };

        $counts = [];
        foreach ($bankIds as $b) {
            $translated = [];
            foreach ($langsFor($tree->get($b)) as $l) {
                $translated[$l] = 0;
            }
            $counts[$b] = ['questions' => 0, 'critical' => 0, 'translated' => $translated];
        }
        foreach ($questions as $q) {
            $b = $q['bank_id'];
            if (!isset($counts[$b])) {
                continue;
            }
            $counts[$b]['questions']++;
            if ($q['critical']) {
                $counts[$b]['critical']++;
            }
            foreach (array_keys($counts[$b]['translated']) as $l) {
                if (QuestionRules::completeIn($q, $l)) {
                    $counts[$b]['translated'][$l]++;
                }
            }
        }

        $lessonTitles = [];
        $lessonIds = [];
        foreach ($bankIds as $b) {
            $lid = $tree->get($b)['qbank_quiz_lesson_id'] ?? null;
            if ($lid !== null) {
                $lessonIds[] = (int) $lid;
            }
        }
        if ($lessonIds !== []) {
            [$ph, $t, $p] = InList::ints($lessonIds);
            foreach (Db::all($db, "SELECT l.lesson_id, v.lvar_title FROM training_lessons l
                    JOIN training_courses c ON c.course_id = l.lesson_course_id
                    LEFT JOIN training_lesson_variants v ON v.lvar_lesson_id = l.lesson_id AND v.lvar_lang = c.course_default_language
                    WHERE l.lesson_id IN ($ph)", $t, $p) as $r) {
                $lessonTitles[(int) $r['lesson_id']] = (string) ($r['lvar_title'] ?? '');
            }
        }

        $rules = [];
        foreach (Usage::rulesForBanks($db, $bankIds, $tree) as $r) {
            if ($r['course_archived_at'] === null && $r['lesson_archived_at'] === null) {
                $rules[] = $r;
            }
        }
        return ['counts' => $counts, 'lesson_titles' => $lessonTitles, 'rules' => $rules];
    }

    private function node(BankTree $tree, int $id, array $visible, array $ctx): array
    {
        $b = $tree->get($id);
        $ancestors = $tree->ancestors($id);
        $usedBy = [];
        foreach ($ctx['rules'] as $r) {
            $rb = (int) $r['qrule_bank_id'];
            if ($rb === $id || ((int) $r['qrule_include_descendants'] === 1 && in_array($rb, $ancestors, true))) {
                $usedBy[] = $this->useItem($r);
            }
        }
        $children = [];
        foreach ($tree->children($id, true) as $cid) {
            if (isset($visible[$cid])) {
                $children[] = $this->node($tree, $cid, $visible, $ctx);
            }
        }
        $quizLesson = $b['qbank_quiz_lesson_id'] === null ? null : (int) $b['qbank_quiz_lesson_id'];
        $kind = $quizLesson !== null ? 'quiz' : ($b['qbank_course_id'] === null ? 'shared' : 'course');
        $label = (string) $b['qbank_name'];
        if ($quizLesson !== null && ($ctx['lesson_titles'][$quizLesson] ?? '') !== '') {
            $label = 'Quiz: ' . $ctx['lesson_titles'][$quizLesson];
        }
        $counts = $ctx['counts'][$id] ?? ['questions' => 0, 'critical' => 0, 'translated' => []];
        $counts['translated'] = $counts['translated'] === [] ? new \stdClass() : $counts['translated'];
        return [
            'id' => $id,
            'uid' => (string) $b['qbank_uid'],
            'parent_id' => $b['qbank_parent_id'] === null ? null : (int) $b['qbank_parent_id'],
            'name' => (string) $b['qbank_name'],
            'description' => $b['qbank_description'],
            'course_id' => $b['qbank_course_id'] === null ? null : (int) $b['qbank_course_id'],
            'quiz_lesson_id' => $quizLesson,
            'kind' => $kind,
            'label' => $label,
            'path' => $tree->path($id),
            'depth' => $tree->depth($id),
            'sort' => (int) $b['qbank_sort'],
            'counts' => $counts,
            'children' => $children,
            'used_by' => $usedBy,
        ];
    }
}
