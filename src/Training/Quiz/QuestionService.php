<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Uid;

/**
 * Question CRUD for the builder and the Question Library (spec §3.5, §5.5, §5.6).
 *
 * A question is language-neutral (type, options, correct/pinned flags, points, critical, image)
 * with per-language texts. update() therefore takes three independent parts:
 *   $text       per-language: text, explanation, topic, media_id (an image override for $lang)
 *   $structure  neutral: type, points (1..10), critical, media_id
 *   $options    the FULL option list in display order: [{id|null, correct?, pinned?, text?, feedback?}].
 *               correct/pinned are neutral (omitted = unchanged, so a text-only update in another
 *               language can never touch the key); text/feedback apply to $lang. Options missing
 *               from the list are deleted (in every language). True/false always keeps exactly
 *               two options whose labels are fixed per language (en True/False, es Verdadero/Falso).
 *
 * question_version guards author-typed content; any real change bumps it, a no-op patch changes
 * nothing (no bump, no touch). Every change touches the courses whose quizzes draw from the
 * question's bank, because every question of a used bank is part of that course's revision.
 */
final class QuestionService
{
    public function __construct(private readonly Ctx $c)
    {
    }

    /** One question in the §6.1 Question shape. */
    public function get(int $questionId): array
    {
        $db = $this->c->db;
        $q = QuestionData::load($db, [$questionId])[$questionId] ?? null;
        if ($q === null) {
            throw ApiException::notFound('That question no longer exists.');
        }
        $bank = Db::one($db, 'SELECT ' . Guard::BANK_COLS . ' FROM training_question_banks WHERE qbank_id = ?', 'i', [$q['bank_id']]);
        $langs = $bank === null ? ['default' => 'en', 'offered' => ['en']] : Guard::bankLanguages($db, $bank, $this->c->settings);
        return $this->shape($q, $langs, MediaRefs::rows($db, self::mediaIds([$q])));
    }

    /**
     * Filters: bank_id (required), include_descendants, q (search text in any language),
     * type, critical, needs_attention.
     *
     * @return list<array> Question
     */
    public function list(array $filters): array
    {
        $db = $this->c->db;
        $bankId = (int) ($filters['bank_id'] ?? 0);
        $bank = Guard::bank($db, $bankId);
        $tree = BankTree::load($db);
        $banks = !empty($filters['include_descendants']) ? $tree->subtree($bankId, true) : [$bankId];
        $langs = Guard::bankLanguages($db, $bank, $this->c->settings);
        $questions = QuestionData::load($db, QuestionData::idsForBanks($db, $banks));

        $needle = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : '';
        $type = $filters['type'] ?? null;
        $critical = $filters['critical'] ?? null;
        $attention = !empty($filters['needs_attention']);

        $keep = [];
        foreach ($questions as $q) {
            if ($type !== null && $q['type'] !== $type) {
                continue;
            }
            if ($critical !== null && $q['critical'] !== (bool) $critical) {
                continue;
            }
            if ($needle !== '' && !self::matches($q, $needle)) {
                continue;
            }
            if ($attention && !$this->needsAttention($q, $langs)) {
                continue;
            }
            $keep[] = $q;
        }
        $media = MediaRefs::rows($db, self::mediaIds($keep));
        return array_map(fn($q) => $this->shape($q, $langs, $media), $keep);
    }

    public function create(int $bankId, string $type, string $lang, ?string $text, ?int $afterId): array
    {
        if (!in_array($type, QuestionRules::TYPES, true)) {
            throw ApiException::validation(['type' => 'Not a valid choice.']);
        }
        $text = self::cleanText($text, QuestionRules::TEXT_MAX, 'text');
        $db = $this->c->db;
        $id = Db::tx($db, function () use ($db, $bankId, $type, $lang, $text, $afterId): int {
            Guard::writableBank($db, $bankId, true);
            $sort = $this->insertPosition($db, $bankId, $afterId);
            $qid = Db::insert($db, 'INSERT INTO training_questions (question_uid, question_bank_id, question_type, question_points,
                    question_critical, question_sort, question_created_by) VALUES (?, ?, ?, 1, 0, ?, ?)',
                'sisii', [Uid::new('q'), $bankId, $type, $sort, $this->c->userId]);
            if ($text !== null && $text !== '') {
                Db::exec($db, 'INSERT INTO training_question_texts (qtext_question_id, qtext_lang, qtext_text, qtext_updated_by) VALUES (?, ?, ?, ?)',
                    'issi', [$qid, $lang, $text, $this->c->userId]);
            }
            for ($i = 0; $i < 2; $i++) {
                Db::insert($db, 'INSERT INTO training_question_options (option_uid, option_question_id, option_sort, option_is_correct, option_pinned)
                    VALUES (?, ?, ?, 0, 0)', 'sii', [Uid::new('o'), $qid, $i]);
            }
            Usage::touchBanks($db, [$bankId]);
            return $qid;
        });
        return $this->get($id);
    }

    /**
     * @param array      $text      subset of {text, explanation, topic, media_id} for $lang
     * @param array|null $structure subset of {type, points, critical, media_id}
     * @param array|null $options   full list [{id?, correct?, pinned?, text?, feedback?}] or null = unchanged
     */
    public function update(int $questionId, int $version, string $lang, array $text, ?array $structure, ?array $options): array
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $questionId, $version, $lang, $text, $structure, $options): void {
            $row = Db::one($db, 'SELECT question_id, question_bank_id, question_version, question_archived_at FROM training_questions
                WHERE question_id = ? FOR UPDATE', 'i', [$questionId]);
            if ($row === null || $row['question_archived_at'] !== null) {
                throw ApiException::notFound('That question was deleted.');
            }
            $bankId = (int) $row['question_bank_id'];
            Guard::writableBank($db, $bankId);
            if ((int) $row['question_version'] !== $version) {
                throw ApiException::conflict($this->get($questionId));
            }
            $q = QuestionData::load($db, [$questionId])[$questionId];
            $changed = false;

            // --- neutral structure -------------------------------------------------------
            $newType = $q['type'];
            if ($structure !== null) {
                $sets = [];
                $types = '';
                $params = [];
                if (array_key_exists('type', $structure)) {
                    $t = $structure['type'];
                    if (!is_string($t) || !in_array($t, QuestionRules::TYPES, true)) {
                        throw ApiException::validation(['type' => 'Not a valid choice.']);
                    }
                    if ($t !== $q['type']) {
                        $newType = $t;
                        $sets[] = 'question_type = ?';
                        $types .= 's';
                        $params[] = $t;
                    }
                }
                if (array_key_exists('points', $structure)) {
                    $p = $structure['points'];
                    if (!is_int($p) || $p < QuestionRules::MIN_POINTS || $p > QuestionRules::MAX_POINTS) {
                        throw ApiException::validation(['points' => 'Points must be between 1 and 10.']);
                    }
                    if ($p !== $q['points']) {
                        $sets[] = 'question_points = ?';
                        $types .= 'i';
                        $params[] = $p;
                    }
                }
                if (array_key_exists('critical', $structure)) {
                    $cr = (bool) $structure['critical'];
                    if ($cr !== $q['critical']) {
                        $sets[] = 'question_critical = ?';
                        $types .= 'i';
                        $params[] = $cr ? 1 : 0;
                    }
                }
                if (array_key_exists('media_id', $structure)) {
                    $m = $structure['media_id'] === null ? null : (int) $structure['media_id'];
                    MediaRefs::assertKind($db, $m, ['image'], 'media_id');
                    if ($m !== $q['media_id']) {
                        $sets[] = 'question_media_id = ?';
                        $types .= 'i';
                        $params[] = $m;
                    }
                }
                if ($sets !== []) {
                    $types .= 'i';
                    $params[] = $questionId;
                    Db::exec($db, 'UPDATE training_questions SET ' . implode(', ', $sets) . ' WHERE question_id = ?', $types, $params);
                    $changed = true;
                }
            }

            // --- options (neutral flags + $lang texts) -------------------------------------
            if ($options !== null || $newType !== $q['type']) {
                $changed = $this->applyOptions($db, $q, $newType, $lang, $options) || $changed;
            }

            // --- texts for $lang -------------------------------------------------------------
            if ($text !== []) {
                $changed = $this->applyText($db, $q, $lang, $text) || $changed;
            }

            if ($changed) {
                Db::exec($db, 'UPDATE training_questions SET question_version = question_version + 1 WHERE question_id = ?', 'i', [$questionId]);
                Usage::touchBanks($db, [$bankId]);
            }
        });
        return $this->get($questionId);
    }

    /** Archives (soft-deletes) a question; restore() brings it back. */
    public function delete(int $questionId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $questionId): void {
            $row = $this->lockRow($db, $questionId);
            if ($row['question_archived_at'] !== null) {
                return;
            }
            Guard::writableBank($db, (int) $row['question_bank_id']);
            Db::exec($db, 'UPDATE training_questions SET question_archived_at = NOW() WHERE question_id = ?', 'i', [$questionId]);
            Usage::touchBanks($db, [(int) $row['question_bank_id']]);
        });
    }

    public function restore(int $questionId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $questionId): void {
            $row = $this->lockRow($db, $questionId);
            if ($row['question_archived_at'] === null) {
                return;
            }
            Guard::writableBank($db, (int) $row['question_bank_id']);
            Db::exec($db, 'UPDATE training_questions SET question_archived_at = NULL WHERE question_id = ?', 'i', [$questionId]);
            Usage::touchBanks($db, [(int) $row['question_bank_id']]);
        });
    }

    /** A copy (new uids, every language) placed right after the original. */
    public function duplicate(int $questionId): array
    {
        $db = $this->c->db;
        $id = Db::tx($db, function () use ($db, $questionId): int {
            $row = $this->lockRow($db, $questionId);
            if ($row['question_archived_at'] !== null) {
                throw ApiException::notFound('That question was deleted.');
            }
            $bankId = (int) $row['question_bank_id'];
            Guard::writableBank($db, $bankId);
            $q = QuestionData::load($db, [$questionId])[$questionId];
            $sort = $this->insertPosition($db, $bankId, $questionId);
            $newId = self::insertCopy($db, $q, $bankId, $sort, $this->c->userId);
            Usage::touchBanks($db, [$bankId]);
            return $newId;
        });
        return $this->get($id);
    }

    /** $ids must be exactly the bank's live questions, in the new order. */
    public function reorder(int $bankId, array $ids): void
    {
        $db = $this->c->db;
        $ids = array_values(array_map('intval', $ids));
        Db::tx($db, function () use ($db, $bankId, $ids): void {
            Guard::writableBank($db, $bankId, true);
            $rows = Db::all($db, 'SELECT question_id, question_sort FROM training_questions WHERE question_bank_id = ? AND question_archived_at IS NULL
                FOR UPDATE', 'i', [$bankId]);
            $current = [];
            foreach ($rows as $r) {
                $current[(int) $r['question_id']] = (int) $r['question_sort'];
            }
            $given = $ids;
            sort($given);
            $have = array_keys($current);
            sort($have);
            if ($given !== $have || count($ids) !== count(array_unique($ids))) {
                throw ApiException::validation(['ids' => 'The list must contain every question in this bank exactly once.']);
            }
            $changed = false;
            foreach ($ids as $i => $qid) {
                if ($current[$qid] !== $i) {
                    Db::exec($db, 'UPDATE training_questions SET question_sort = ? WHERE question_id = ?', 'ii', [$i, $qid]);
                    $changed = true;
                }
            }
            if ($changed) {
                Usage::touchBanks($db, [$bankId]);
            }
        });
    }

    /**
     * Moves questions to another bank (appended in the given order). The target may not be a
     * quiz's own "written for this quiz" bank: that would silently add questions to that quiz.
     */
    public function move(array $ids, int $bankId): void
    {
        $db = $this->c->db;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ApiException::validation(['ids' => 'Choose at least one question.']);
        }
        Db::tx($db, function () use ($db, $ids, $bankId): void {
            $target = Guard::writableBank($db, $bankId, true);
            if ($target['qbank_quiz_lesson_id'] !== null) {
                throw ApiException::validation(['bank_id' => "Questions can't be moved into the questions written for a quiz. Choose a Question Library bank."]);
            }
            $sources = [];
            $rows = [];
            foreach ($ids as $qid) {
                $row = $this->lockRow($db, $qid);
                if ($row['question_archived_at'] !== null) {
                    throw ApiException::validation(['ids' => 'One of those questions was deleted.']);
                }
                $src = (int) $row['question_bank_id'];
                if (!isset($sources[$src])) {
                    Guard::writableBank($db, $src);
                    $sources[$src] = true;
                }
                $rows[] = $row;
            }
            $affectedBefore = Usage::coursesForBanks($db, array_merge(array_keys($sources), [$bankId]));
            $next = (int) (Db::one($db, 'SELECT COALESCE(MAX(question_sort), -1) + 1 AS s FROM training_questions WHERE question_bank_id = ?',
                'i', [$bankId])['s'] ?? 0);
            $moved = false;
            foreach ($rows as $row) {
                if ((int) $row['question_bank_id'] === $bankId) {
                    continue;
                }
                Db::exec($db, 'UPDATE training_questions SET question_bank_id = ?, question_sort = ? WHERE question_id = ?',
                    'iii', [$bankId, $next++, (int) $row['question_id']]);
                $moved = true;
            }
            if ($moved) {
                Usage::touch($db, $affectedBefore);
            }
        });
    }

    // ------------------------------------------------------------------------------------------

    /**
     * Inserts a full copy of a loaded question into $bankId. Returns the new question id.
     * Used by duplicate() and QuizCloner.
     */
    public static function insertCopy(\mysqli $db, array $q, int $bankId, int $sort, int $userId, bool $archived = false): int
    {
        $newId = Db::insert($db, 'INSERT INTO training_questions (question_uid, question_bank_id, question_type, question_media_id,
                question_points, question_critical, question_sort, question_created_by, question_archived_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . ($archived ? 'NOW()' : 'NULL') . ')',
            'sisiiiii', [Uid::new('q'), $bankId, $q['type'], $q['media_id'], $q['points'], $q['critical'] ? 1 : 0, $sort, $userId]);
        foreach ($q['texts'] as $lang => $t) {
            Db::exec($db, 'INSERT INTO training_question_texts (qtext_question_id, qtext_lang, qtext_text, qtext_explanation, qtext_topic,
                    qtext_media_id, qtext_updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                'issssii', [$newId, (string) $lang, $t['text'], $t['explanation'], $t['topic'], $t['media_id'], $userId]);
        }
        foreach ($q['options'] as $o) {
            $oid = Db::insert($db, 'INSERT INTO training_question_options (option_uid, option_question_id, option_sort, option_is_correct, option_pinned)
                VALUES (?, ?, ?, ?, ?)', 'siiii', [Uid::new('o'), $newId, $o['sort'], $o['correct'] ? 1 : 0, $o['pinned'] ? 1 : 0]);
            foreach ($o['texts'] as $lang => $ot) {
                Db::exec($db, 'INSERT INTO training_option_texts (otext_option_id, otext_lang, otext_text, otext_feedback) VALUES (?, ?, ?, ?)',
                    'isss', [$oid, (string) $lang, $ot['text'], $ot['feedback']]);
            }
        }
        return $newId;
    }

    /** Media ids referenced by questions (neutral image and per-language overrides). @return list<int> */
    public static function mediaIds(array $questions): array
    {
        $ids = [];
        foreach ($questions as $q) {
            if ($q['media_id'] !== null) {
                $ids[] = $q['media_id'];
            }
            foreach ($q['texts'] as $t) {
                if ($t['media_id'] !== null) {
                    $ids[] = $t['media_id'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * The §6.1 Question shape.
     *
     * @param array{default:string, offered:list<string>} $langs
     */
    public function shape(array $q, array $langs, array $media): array
    {
        $texts = [];
        foreach ($q['texts'] as $lang => $t) {
            $texts[(string) $lang] = [
                'text' => $t['text'],
                'explanation' => $t['explanation'],
                'topic' => $t['topic'],
                'media' => $t['media_id'] === null ? null : MediaRefs::api($media[$t['media_id']] ?? null),
            ];
        }
        $options = [];
        foreach ($q['options'] as $i => $o) {
            $ot = [];
            if ($q['type'] === 'truefalse') {
                foreach (array_unique(array_merge($langs['offered'], array_keys($o['texts']))) as $lang) {
                    $ot[(string) $lang] = ['text' => QuestionRules::tfLabel((string) $lang, $i), 'feedback' => $o['texts'][$lang]['feedback'] ?? null];
                }
            } else {
                foreach ($o['texts'] as $lang => $t) {
                    $ot[(string) $lang] = ['text' => $t['text'], 'feedback' => $t['feedback']];
                }
            }
            $options[] = [
                'id' => $o['id'],
                'uid' => $o['uid'],
                'correct' => $o['correct'],
                'pinned' => $o['pinned'],
                'sort' => $o['sort'],
                'texts' => $ot === [] ? new \stdClass() : $ot,
            ];
        }
        $languages = [];
        foreach ($langs['offered'] as $l) {
            $languages[$l] = ['complete' => QuestionRules::completeIn($q, $l)];
        }
        return [
            'id' => $q['id'],
            'uid' => $q['uid'],
            'bank_id' => $q['bank_id'],
            'type' => $q['type'],
            'points' => $q['points'],
            'critical' => $q['critical'],
            'media' => $q['media_id'] === null ? null : MediaRefs::api($media[$q['media_id']] ?? null),
            'sort' => $q['sort'],
            'version' => $q['version'],
            'archived' => $q['archived'],
            'texts' => $texts === [] ? new \stdClass() : $texts,
            'options' => $options,
            'languages' => $languages === [] ? new \stdClass() : $languages,
            'issues' => QuestionRules::issues($q, $langs['default']),
        ];
    }

    // ------------------------------------------------------------------------------------------

    private function lockRow(\mysqli $db, int $questionId): array
    {
        $row = Db::one($db, 'SELECT question_id, question_bank_id, question_archived_at FROM training_questions WHERE question_id = ? FOR UPDATE',
            'i', [$questionId]);
        if ($row === null) {
            throw ApiException::notFound('That question no longer exists.');
        }
        return $row;
    }

    /** Sort value for a new question: after $afterId (shifting the rest down) or at the end. */
    private function insertPosition(\mysqli $db, int $bankId, ?int $afterId): int
    {
        if ($afterId !== null) {
            $after = Db::one($db, 'SELECT question_sort FROM training_questions WHERE question_id = ? AND question_bank_id = ?', 'ii', [$afterId, $bankId]);
            if ($after === null) {
                throw ApiException::validation(['after_question_id' => 'That question is not in this bank.']);
            }
            $pos = (int) $after['question_sort'] + 1;
            Db::exec($db, 'UPDATE training_questions SET question_sort = question_sort + 1 WHERE question_bank_id = ? AND question_sort >= ?',
                'ii', [$bankId, $pos]);
            return $pos;
        }
        return (int) (Db::one($db, 'SELECT COALESCE(MAX(question_sort), -1) + 1 AS s FROM training_questions WHERE question_bank_id = ?',
            'i', [$bankId])['s'] ?? 0);
    }

    /** Applies the option list / type conversion. Returns whether anything changed. */
    private function applyOptions(\mysqli $db, array $q, string $newType, string $lang, ?array $options): bool
    {
        $current = [];
        foreach ($q['options'] as $o) {
            $current[$o['id']] = $o;
        }
        // The desired list: the given one, or (type change only) the current one.
        $desired = [];
        if ($options === null) {
            foreach ($q['options'] as $o) {
                $desired[] = ['id' => $o['id']];
            }
        } else {
            if (!array_is_list($options)) {
                throw ApiException::validation(['options' => 'Must be a list.']);
            }
            $seen = [];
            foreach ($options as $i => $o) {
                if (!is_array($o)) {
                    throw ApiException::validation(['options' => 'Each answer must be an object.']);
                }
                $entry = [];
                if (isset($o['id'])) {
                    $oid = is_int($o['id']) ? $o['id'] : (is_string($o['id']) && ctype_digit($o['id']) ? (int) $o['id'] : -1);
                    if (!isset($current[$oid]) || isset($seen[$oid])) {
                        throw ApiException::validation(['options' => 'An answer does not belong to this question.']);
                    }
                    $seen[$oid] = true;
                    $entry['id'] = $oid;
                }
                foreach (['correct', 'pinned'] as $flag) {
                    if (array_key_exists($flag, $o) && $o[$flag] !== null) {
                        if (!is_bool($o[$flag]) && !in_array($o[$flag], [0, 1], true)) {
                            throw ApiException::validation(['options' => "'$flag' must be true or false."]);
                        }
                        $entry[$flag] = (bool) $o[$flag];
                    }
                }
                if (array_key_exists('text', $o)) {
                    $entry['text'] = self::cleanText($o['text'], QuestionRules::OPTION_TEXT_MAX, "options.$i.text") ?? '';
                }
                if (array_key_exists('feedback', $o)) {
                    $entry['feedback'] = self::cleanText($o['feedback'], QuestionRules::OPTION_FEEDBACK_MAX, "options.$i.feedback");
                    if ($entry['feedback'] === '') {
                        $entry['feedback'] = null;
                    }
                }
                $desired[] = $entry;
            }
        }
        if ($newType === 'truefalse') {
            // Exactly two rows: reuse the first two, create what is missing, drop the rest.
            $desired = array_slice($desired, 0, 2);
            while (count($desired) < 2) {
                $desired[] = [];
            }
        } elseif (count($desired) > QuestionRules::MAX_OPTIONS) {
            throw ApiException::validation(['options' => 'At most ' . QuestionRules::MAX_OPTIONS . ' answers.']);
        }

        $changed = false;
        $keep = [];
        foreach ($desired as $i => $d) {
            $cur = isset($d['id']) ? $current[$d['id']] : null;
            $correct = $d['correct'] ?? ($cur['correct'] ?? false);
            $pinned = $newType === 'truefalse' ? false : ($d['pinned'] ?? ($cur['pinned'] ?? false));
            if ($cur === null) {
                $oid = Db::insert($db, 'INSERT INTO training_question_options (option_uid, option_question_id, option_sort, option_is_correct, option_pinned)
                    VALUES (?, ?, ?, ?, ?)', 'siiii', [Uid::new('o'), $q['id'], $i, $correct ? 1 : 0, $pinned ? 1 : 0]);
                $cur = ['id' => $oid, 'sort' => $i, 'correct' => $correct, 'pinned' => $pinned, 'texts' => []];
                $changed = true;
            } elseif ($cur['sort'] !== $i || $cur['correct'] !== $correct || $cur['pinned'] !== $pinned) {
                Db::exec($db, 'UPDATE training_question_options SET option_sort = ?, option_is_correct = ?, option_pinned = ? WHERE option_id = ?',
                    'iiii', [$i, $correct ? 1 : 0, $pinned ? 1 : 0, $cur['id']]);
                $changed = true;
            }
            $keep[$cur['id']] = true;

            if ($newType === 'truefalse') {
                // Fixed labels in every language that has a row; feedback per $lang.
                foreach ($cur['texts'] as $l => $t) {
                    $label = QuestionRules::tfLabel((string) $l, $i);
                    if ($t['text'] !== $label) {
                        Db::exec($db, 'UPDATE training_option_texts SET otext_text = ? WHERE otext_option_id = ? AND otext_lang = ?',
                            'sis', [$label, $cur['id'], (string) $l]);
                        $changed = true;
                    }
                }
                if (array_key_exists('feedback', $d)) {
                    $changed = $this->upsertOptionText($db, $cur, $lang, QuestionRules::tfLabel($lang, $i), $d['feedback']) || $changed;
                }
            } elseif (array_key_exists('text', $d) || array_key_exists('feedback', $d)) {
                $old = $cur['texts'][$lang] ?? null;
                $newText = array_key_exists('text', $d) ? $d['text'] : ($old['text'] ?? '');
                $newFeedback = array_key_exists('feedback', $d) ? $d['feedback'] : ($old['feedback'] ?? null);
                if ($old === null && $newText === '' && $newFeedback === null) {
                    continue;
                }
                $changed = $this->upsertOptionText($db, $cur, $lang, $newText, $newFeedback) || $changed;
            }
        }
        $drop = array_diff(array_keys($current), array_keys($keep));
        if ($drop !== []) {
            [$ph, $t, $p] = InList::ints($drop);
            Db::exec($db, "DELETE FROM training_option_texts WHERE otext_option_id IN ($ph)", $t, $p);
            Db::exec($db, "DELETE FROM training_question_options WHERE option_id IN ($ph) AND option_question_id = ?", $t . 'i', array_merge($p, [$q['id']]));
            $changed = true;
        }
        return $changed;
    }

    private function upsertOptionText(\mysqli $db, array $opt, string $lang, string $text, ?string $feedback): bool
    {
        $old = $opt['texts'][$lang] ?? null;
        if ($old !== null && $old['text'] === $text && $old['feedback'] === $feedback) {
            return false;
        }
        Db::exec($db, 'INSERT INTO training_option_texts (otext_option_id, otext_lang, otext_text, otext_feedback) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE otext_text = VALUES(otext_text), otext_feedback = VALUES(otext_feedback)',
            'isss', [$opt['id'], $lang, $text, $feedback]);
        return true;
    }

    /** Applies {text, explanation, topic, media_id} for $lang. Returns whether anything changed. */
    private function applyText(\mysqli $db, array $q, string $lang, array $text): bool
    {
        $old = $q['texts'][$lang] ?? null;
        $new = $old ?? ['text' => '', 'explanation' => null, 'topic' => null, 'media_id' => null];
        foreach ($text as $k => $v) {
            switch ($k) {
                case 'text':
                    $new['text'] = self::cleanText($v, QuestionRules::TEXT_MAX, 'text') ?? '';
                    break;
                case 'explanation':
                    $new['explanation'] = QuestionRules::nullIfBlank(self::cleanText($v, QuestionRules::EXPLANATION_MAX, 'explanation'));
                    break;
                case 'topic':
                    $new['topic'] = QuestionRules::nullIfBlank(self::cleanText($v, QuestionRules::TOPIC_MAX, 'topic'));
                    break;
                case 'media_id':
                    $m = $v === null ? null : (int) $v;
                    MediaRefs::assertKind($db, $m, ['image'], 'media_id');
                    $new['media_id'] = $m;
                    break;
                default:
                    throw ApiException::validation([$k => 'This field cannot be changed here.']);
            }
        }
        if ($old === null && $new['text'] === '' && $new['explanation'] === null && $new['topic'] === null && $new['media_id'] === null) {
            return false;
        }
        if ($old !== null && $old['text'] === $new['text'] && $old['explanation'] === $new['explanation']
            && $old['topic'] === $new['topic'] && $old['media_id'] === $new['media_id']) {
            return false;
        }
        Db::exec($db, 'INSERT INTO training_question_texts (qtext_question_id, qtext_lang, qtext_text, qtext_explanation, qtext_topic, qtext_media_id, qtext_updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qtext_text = VALUES(qtext_text), qtext_explanation = VALUES(qtext_explanation),
              qtext_topic = VALUES(qtext_topic), qtext_media_id = VALUES(qtext_media_id), qtext_updated_by = VALUES(qtext_updated_by)',
            'issssii', [$q['id'], $lang, $new['text'], $new['explanation'], $new['topic'], $new['media_id'], $this->c->userId]);
        return true;
    }

    private function needsAttention(array $q, array $langs): bool
    {
        if (QuestionRules::issues($q, $langs['default']) !== []) {
            return true;
        }
        foreach ($langs['offered'] as $l) {
            if (!QuestionRules::completeIn($q, $l)) {
                return true;
            }
        }
        return false;
    }

    private static function matches(array $q, string $needle): bool
    {
        foreach ($q['texts'] as $t) {
            foreach ([$t['text'], $t['topic'], $t['explanation']] as $s) {
                if ($s !== null && mb_stripos((string) $s, $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
        }
        foreach ($q['options'] as $o) {
            foreach ($o['texts'] as $t) {
                if (mb_stripos((string) $t['text'], $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
        }
        return $q['uid'] === $needle;
    }

    /** Valid UTF-8, trimmed, within $max characters. null stays null. */
    public static function cleanText(mixed $v, int $max, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v)) {
            throw ApiException::validation([$field => 'Must be text.']);
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            throw ApiException::validation([$field => 'Contains characters that could not be read. Retype it and try again.']);
        }
        $v = trim($v);
        if (mb_strlen($v, 'UTF-8') > $max) {
            throw ApiException::validation([$field => "Too long (at most $max characters)."]);
        }
        return $v;
    }
}
