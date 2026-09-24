<?php

namespace ITFlow\Training\Quiz;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Csv;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Core\Uid;

/**
 * CSV and "Paste questions" (Aiken-style) import, in two steps (spec §3.5, §5.5, §8 "CSV and
 * paste import"):
 *
 *   preview  parse and validate every row, never writing anything; the valid rows are stored in a
 *            Core\Scratch 'import' file bound to the user (30 minutes, single use) and the
 *            preview rows and row errors go back to the author;
 *   commit   takes the Scratch file (atomic, so a double click cannot import twice) and writes
 *            the valid rows in one transaction.
 *
 * CSV: at most 2 MB and 1000 rows, UTF-8 (with or without BOM) or CP1252, comma, semicolon or
 * TAB delimited (Core\Csv::read, which also strips the export's formula-guard apostrophe).
 * Everything is plain text, stored verbatim (only surrounding spaces and line breaks are
 * trimmed) and never evaluated. A blank uid creates a question; a known uid of this bank adds or
 * updates that language's texts only - its structure (type, answers, key, points, critical) is
 * never changed by an import, and a difference is reported as a row warning.
 */
final class QuestionImporter
{
    public const MAX_ROWS = 1000;
    public const TTL_S = 1800;
    public const PASTE_MAX_CHARS = 200000;

    private const TRUE_WORDS = ['true', 'verdadero', 'cierto', 't', 'v'];
    private const FALSE_WORDS = ['false', 'falso', 'f'];

    public function __construct(private readonly Ctx $c)
    {
    }

    /** @return array{import_token:?string, rows:list<array>, errors:list<array>, summary:array} */
    public function previewCsv(string $tmpPath, int $bankId, string $defaultLang): array
    {
        $bank = Guard::writableBank($this->c->db, $bankId);
        try {
            $csv = Csv::read($tmpPath, TrainingSettings::CSV_MAX_BYTES, self::MAX_ROWS + 1);
        } catch (\LengthException $e) {
            if ($e->getMessage() === 'csv_too_large') {
                throw new ApiException(413, 'too_large', 'That file is too large (at most 2 MB).');
            }
            throw ApiException::validation(['file' => 'That file has more than ' . self::MAX_ROWS . ' questions. Split it into smaller files.']);
        } catch (\RuntimeException) {
            throw ApiException::validation(['file' => 'That file could not be read.']);
        }
        $parsed = self::parseCsv($csv['rows'], $defaultLang, $this->allowedLangs());
        foreach ($csv['warnings'] as $w) {
            if ($w === 'converted_from_cp1252') {
                $parsed['notes'][] = 'The file was not UTF-8; it was read as Windows (CP1252) text. Check accented letters.';
            }
        }
        return $this->finishPreview($parsed, $bank);
    }

    /** @return array{import_token:?string, rows:list<array>, errors:list<array>, summary:array} */
    public function previewPaste(string $text, int $bankId, string $lang): array
    {
        $bank = Guard::writableBank($this->c->db, $bankId);
        if (mb_strlen($text, 'UTF-8') > self::PASTE_MAX_CHARS) {
            throw ApiException::validation(['text' => 'That is too much text at once. Paste fewer questions.']);
        }
        return $this->finishPreview(self::parsePaste($text, $lang), $bank);
    }

    /** @return array{created:int, updated:int, bank_id:int} */
    public function commit(string $token, int $bankId): array
    {
        $data = Scratch::take('import', $token, $this->c->userId);
        if ($data === null) {
            throw ApiException::notFound('This import preview expired. Check the questions again.');
        }
        if ((int) ($data['bank_id'] ?? 0) !== $bankId) {
            throw ApiException::validation(['bank_id' => 'This preview was made for another bank.']);
        }
        $db = $this->c->db;
        [$created, $updated] = Db::tx($db, function () use ($db, $data, $bankId): array {
            Guard::writableBank($db, $bankId, true);
            $created = 0;
            $updated = 0;
            $sort = (int) (Db::one($db, 'SELECT COALESCE(MAX(question_sort), -1) + 1 AS s FROM training_questions WHERE question_bank_id = ?',
                'i', [$bankId])['s'] ?? 0);
            foreach ($data['items'] as $it) {
                if ($it['uid'] === null) {
                    $this->insertItem($db, $it, $bankId, $sort++);
                    $created++;
                } elseif ($this->updateTexts($db, $it, $bankId)) {
                    $updated++;
                }
            }
            if ($created + $updated > 0) {
                Usage::touchBanks($db, [$bankId]);
            }
            return [$created, $updated];
        });
        return ['created' => $created, 'updated' => $updated, 'bank_id' => $bankId];
    }

    // --- parsing (pure) -------------------------------------------------------------------------

    /**
     * @param list<list<string>> $rows CSV rows including the header
     * @param list<string> $allowedLangs
     * @return array{items:list<array>, errors:list<array>, notes:list<string>}
     */
    public static function parseCsv(array $rows, string $defaultLang, array $allowedLangs): array
    {
        $items = [];
        $errors = [];
        if ($rows === []) {
            return ['items' => [], 'errors' => [['row' => 0, 'message' => 'The file is empty.']], 'notes' => []];
        }
        $map = [];
        foreach ($rows[0] as $i => $name) {
            $n = strtolower(trim((string) $name));
            $n = preg_replace('/\s*\(.*\)$/', '', $n); // "type(single|multi|truefalse)" -> "type"
            if (in_array($n, QuestionExporter::HEADER, true) && !isset($map[$n])) {
                $map[$n] = $i;
            }
        }
        foreach (['question', 'a', 'b', 'correct'] as $req) {
            if (!isset($map[$req])) {
                return ['items' => [], 'errors' => [['row' => 1, 'message' =>
                    'The first row must be the column names from the template (uid, lang, type, question, a … h, correct, points, critical, topic, explanation).']],
                    'notes' => []];
            }
        }
        $seen = [];
        foreach (array_slice($rows, 1) as $idx => $cells) {
            $rowNo = $idx + 2;
            $get = static fn(string $col): string => isset($map[$col]) ? (string) ($cells[$map[$col]] ?? '') : '';
            $rowErrors = [];
            $warnings = [];

            $uid = trim($get('uid'));
            if ($uid !== '' && !Uid::valid($uid, 'q')) {
                $rowErrors[] = ['field' => 'uid', 'message' => 'The uid is not valid. Leave it empty to create a new question.'];
            }
            $lang = strtolower(trim($get('lang')));
            if ($lang === '') {
                $lang = $defaultLang;
            } elseif (!in_array($lang, $allowedLangs, true)) {
                $rowErrors[] = ['field' => 'lang', 'message' => "Language \"$lang\" is not offered."];
            }
            $text = self::cell($get('question'));
            if ($text === '') {
                $rowErrors[] = ['field' => 'question', 'message' => 'The question is empty.'];
            } elseif (mb_strlen($text, 'UTF-8') > QuestionRules::TEXT_MAX) {
                $rowErrors[] = ['field' => 'question', 'message' => 'The question is longer than ' . QuestionRules::TEXT_MAX . ' characters.'];
            }

            // Options by column letter; empty columns are skipped and letters re-mapped.
            $byLetter = [];
            foreach (QuestionExporter::LETTERS as $L) {
                $v = self::cell($get(strtolower($L)));
                if ($v !== '') {
                    if (mb_strlen($v, 'UTF-8') > QuestionRules::OPTION_TEXT_MAX) {
                        $rowErrors[] = ['field' => strtolower($L), 'message' => "Answer $L is longer than " . QuestionRules::OPTION_TEXT_MAX . ' characters.'];
                    }
                    $byLetter[$L] = $v;
                }
            }
            $letters = self::letters($get('correct'));
            if ($letters === null) {
                $rowErrors[] = ['field' => 'correct', 'message' => 'Write the correct answer as letters, e.g. B or A,C.'];
                $letters = [];
            }
            foreach ($letters as $L) {
                if (!isset($byLetter[$L])) {
                    $rowErrors[] = ['field' => 'correct', 'message' => "Answer $L is marked correct but has no text."];
                }
            }

            $type = strtolower(trim($get('type')));
            if ($type === 'true/false' || $type === 'tf' || $type === 'true_false') {
                $type = 'truefalse';
            }
            if ($type === '') {
                $type = self::isTrueFalse(array_values($byLetter)) ? 'truefalse' : (count($letters) > 1 ? 'multi' : 'single');
            }
            if (!in_array($type, QuestionRules::TYPES, true)) {
                $rowErrors[] = ['field' => 'type', 'message' => 'Type must be single, multi or truefalse.'];
                $type = 'single';
            }

            $options = array_values($byLetter);
            $correct = [];
            foreach (array_keys($byLetter) as $pos => $L) {
                if (in_array($L, $letters, true)) {
                    $correct[] = $pos;
                }
            }
            if ($type === 'truefalse') {
                $options = [QuestionRules::tfLabel($lang, 0), QuestionRules::tfLabel($lang, 1)];
                $correct = [];
                foreach ($letters as $L) {
                    if ($L === 'A' || $L === 'B') {
                        $correct[] = $L === 'A' ? 0 : 1;
                    } else {
                        $rowErrors[] = ['field' => 'correct', 'message' => 'A true/false answer is A (True) or B (False).'];
                    }
                }
                $rowErrors = array_values(array_filter($rowErrors, static fn($e) => !($e['field'] === 'correct' && str_contains($e['message'], 'has no text'))));
            }
            if ($uid === '') {
                if ($type !== 'truefalse' && count($options) < 2) {
                    $rowErrors[] = ['field' => 'a', 'message' => 'Give at least two answers.'];
                }
                if ($correct === []) {
                    $rowErrors[] = ['field' => 'correct', 'message' => 'Mark the correct answer.'];
                } elseif ($type !== 'multi' && count($correct) > 1) {
                    $rowErrors[] = ['field' => 'correct', 'message' => 'Only one answer can be correct for this type. Use type multi for several.'];
                }
            }

            $pointsRaw = trim($get('points'));
            $points = 1;
            if ($pointsRaw !== '') {
                if (preg_match('/^[0-9]{1,3}$/', $pointsRaw) !== 1 || (int) $pointsRaw < QuestionRules::MIN_POINTS || (int) $pointsRaw > QuestionRules::MAX_POINTS) {
                    $rowErrors[] = ['field' => 'points', 'message' => 'Points must be a whole number from 1 to 10.'];
                } else {
                    $points = (int) $pointsRaw;
                }
            }
            $critRaw = strtolower(trim($get('critical')));
            $critical = false;
            if (in_array($critRaw, ['y', 'yes', '1', 'true', 'x', 's', 'si', 'sí'], true)) {
                $critical = true;
            } elseif (!in_array($critRaw, ['', 'n', 'no', '0', 'false'], true)) {
                $rowErrors[] = ['field' => 'critical', 'message' => 'Critical must be y or n.'];
            }
            $topic = self::cell($get('topic'));
            if (mb_strlen($topic, 'UTF-8') > QuestionRules::TOPIC_MAX) {
                $rowErrors[] = ['field' => 'topic', 'message' => 'The topic is longer than ' . QuestionRules::TOPIC_MAX . ' characters.'];
            }
            $explanation = self::cell($get('explanation'));
            if (mb_strlen($explanation, 'UTF-8') > QuestionRules::EXPLANATION_MAX) {
                $rowErrors[] = ['field' => 'explanation', 'message' => 'The explanation is longer than ' . QuestionRules::EXPLANATION_MAX . ' characters.'];
            }
            if ($uid !== '') {
                $k = $uid . '|' . $lang;
                if (isset($seen[$k])) {
                    $rowErrors[] = ['field' => 'uid', 'message' => "This question and language already appear on row {$seen[$k]}."];
                } else {
                    $seen[$k] = $rowNo;
                }
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $e) {
                    $errors[] = ['row' => $rowNo] + $e;
                }
                continue;
            }
            $items[] = [
                'row' => $rowNo,
                'uid' => $uid === '' ? null : $uid,
                'lang' => $lang,
                'type' => $type,
                'text' => $text,
                'options' => $options,
                'correct' => $correct,
                'points' => $points,
                'critical' => $critical,
                'topic' => $topic === '' ? null : $topic,
                'explanation' => $explanation === '' ? null : $explanation,
                'warnings' => $warnings,
            ];
        }
        return ['items' => $items, 'errors' => $errors, 'notes' => []];
    }

    /**
     * Aiken-style paste: blocks separated by a blank line; the lines before the first option are
     * the question; options "A) text" or "A. text"; the answer "ANSWER: B" / "ANSWER: A,C", or a
     * leading "*" on correct options. Two options that are exactly True/False become true/false.
     *
     * @return array{items:list<array>, errors:list<array>, notes:list<string>}
     */
    public static function parsePaste(string $text, string $lang): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return ['items' => [], 'errors' => [['row' => 0, 'message' => 'The text contains characters that could not be read.']], 'notes' => []];
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n[ \t]*\n+/', trim($text)) ?: [];
        $items = [];
        $errors = [];
        foreach ($blocks as $bi => $block) {
            $no = $bi + 1;
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn($l) => $l !== ''));
            if ($lines === []) {
                continue;
            }
            if (count($blocks) > self::MAX_ROWS && $bi >= self::MAX_ROWS) {
                $errors[] = ['row' => $no, 'message' => 'At most ' . self::MAX_ROWS . ' questions at once.'];
                break;
            }
            $question = [];
            $opts = [];      // letter => text
            $starred = [];
            $answer = null;
            $bad = null;
            foreach ($lines as $line) {
                if (preg_match('/^(?:ANSWER|RESPUESTA)\s*[:：]\s*(.*)$/iu', $line, $m) === 1) {
                    $answer = self::letters($m[1]);
                    if ($answer === null || $answer === []) {
                        $bad = 'The ANSWER line must name letters, e.g. ANSWER: B.';
                    }
                    continue;
                }
                if (preg_match('/^(\*?)\s*([A-Ha-h])\s*[\).]\s*(\*?)\s*(.+)$/u', $line, $m) === 1 && ($question !== [] || $opts !== [])) {
                    $L = strtoupper($m[2]);
                    if (isset($opts[$L])) {
                        $bad = "Answer $L appears twice.";
                        continue;
                    }
                    $opts[$L] = trim($m[4]);
                    if ($m[1] === '*' || $m[3] === '*') {
                        $starred[] = $L;
                    }
                    continue;
                }
                if ($opts !== [] || $answer !== null) {
                    $bad = 'Unexpected line after the answers: "' . mb_substr($line, 0, 60) . '".';
                    continue;
                }
                $question[] = $line;
            }
            $q = implode("\n", $question);
            $letters = $answer ?? $starred;
            if ($bad === null) {
                if ($q === '') {
                    $bad = 'The question is missing.';
                } elseif (mb_strlen($q, 'UTF-8') > QuestionRules::TEXT_MAX) {
                    $bad = 'The question is longer than ' . QuestionRules::TEXT_MAX . ' characters.';
                } elseif (count($opts) < 2) {
                    $bad = 'Give at least two answers (A) … B) …).';
                } elseif ($letters === []) {
                    $bad = 'Mark the correct answer with ANSWER: B or a * before it.';
                } else {
                    foreach ($letters as $L) {
                        if (!isset($opts[$L])) {
                            $bad = "Answer $L is marked correct but does not exist.";
                        }
                    }
                    foreach ($opts as $o) {
                        if (mb_strlen($o, 'UTF-8') > QuestionRules::OPTION_TEXT_MAX) {
                            $bad = 'An answer is longer than ' . QuestionRules::OPTION_TEXT_MAX . ' characters.';
                        }
                    }
                }
            }
            if ($bad !== null) {
                $errors[] = ['row' => $no, 'message' => "Question $no: $bad"];
                continue;
            }
            $warnings = [];
            if ($answer !== null && $starred !== [] && self::sortedLetters($answer) !== self::sortedLetters($starred)) {
                $warnings[] = 'The ANSWER line and the * marks disagree; the ANSWER line was used.';
            }
            $texts = array_values($opts);
            $keys = array_keys($opts);
            if (count($texts) === 2 && self::isTrueFalse($texts)) {
                $trueIdx = in_array(mb_strtolower($texts[0], 'UTF-8'), self::TRUE_WORDS, true) ? 0 : 1;
                $correctIsTrue = $keys[$trueIdx] === $letters[0];
                $items[] = self::pasteItem($no, $lang, 'truefalse', $q,
                    [QuestionRules::tfLabel($lang, 0), QuestionRules::tfLabel($lang, 1)], [$correctIsTrue ? 0 : 1], $warnings);
                continue;
            }
            $correct = [];
            foreach ($keys as $pos => $L) {
                if (in_array($L, $letters, true)) {
                    $correct[] = $pos;
                }
            }
            $items[] = self::pasteItem($no, $lang, count($correct) > 1 ? 'multi' : 'single', $q, $texts, $correct, $warnings);
        }
        if ($items === [] && $errors === []) {
            $errors[] = ['row' => 0, 'message' => 'Paste at least one question.'];
        }
        return ['items' => $items, 'errors' => $errors, 'notes' => []];
    }

    // ------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function allowedLangs(): array
    {
        return $this->c->settings->languages !== [] ? array_values($this->c->settings->languages) : ['en'];
    }

    private function finishPreview(array $parsed, array $bank): array
    {
        $db = $this->c->db;
        $bankId = (int) $bank['qbank_id'];
        $items = [];
        $errors = $parsed['errors'];

        // Known uids must be questions of THIS bank; compare their structure with the row.
        $uids = array_values(array_unique(array_filter(array_map(static fn($i) => $i['uid'], $parsed['items']))));
        $known = [];
        if ($uids !== []) {
            [$ph, $t, $p] = InList::strings($uids);
            $rows = Db::all($db, "SELECT question_id, question_uid, question_bank_id, question_archived_at FROM training_questions
                WHERE question_uid IN ($ph)", $t, $p);
            $data = QuestionData::load($db, array_map(static fn($r) => (int) $r['question_id'], $rows));
            foreach ($rows as $r) {
                $known[(string) $r['question_uid']] = $data[(int) $r['question_id']] ?? null;
            }
        }
        foreach ($parsed['items'] as $it) {
            if ($it['uid'] !== null) {
                $q = $known[$it['uid']] ?? null;
                if ($q === null) {
                    $errors[] = ['row' => $it['row'], 'field' => 'uid', 'message' => 'No question has this uid. Leave the uid empty to create a new question.'];
                    continue;
                }
                if ($q['bank_id'] !== $bankId) {
                    $errors[] = ['row' => $it['row'], 'field' => 'uid', 'message' => 'That question belongs to another bank. Clear the uid column to import a copy.'];
                    continue;
                }
                if ($q['archived']) {
                    $errors[] = ['row' => $it['row'], 'field' => 'uid', 'message' => 'That question was deleted. Restore it first, or clear the uid column.'];
                    continue;
                }
                $it['warnings'] = array_merge($it['warnings'], self::structureWarnings($q, $it));
                $it['action'] = 'update_text';
            } else {
                $it['action'] = 'create';
            }
            $items[] = $it;
        }
        usort($errors, static fn($a, $b) => $a['row'] <=> $b['row']);

        $token = $items === [] ? null : Scratch::put('import', $this->c->userId, ['bank_id' => $bankId, 'items' => $items], self::TTL_S);
        $rows = [];
        $warningCount = 0;
        foreach ($items as $it) {
            $warningCount += count($it['warnings']);
            $letters = array_map(static fn($i) => QuestionExporter::LETTERS[$i], $it['correct']);
            $rows[] = [
                'row' => $it['row'],
                'action' => $it['action'],
                'uid' => $it['uid'],
                'lang' => $it['lang'],
                'type' => $it['type'],
                'text' => $it['text'],
                'options' => $it['options'],
                'correct' => implode(',', $letters),
                'points' => $it['points'],
                'critical' => $it['critical'],
                'topic' => $it['topic'],
                'explanation' => $it['explanation'],
                'warnings' => $it['warnings'],
            ];
        }
        $creates = count(array_filter($items, static fn($i) => $i['action'] === 'create'));
        return [
            'import_token' => $token,
            'rows' => $rows,
            'errors' => $errors,
            'summary' => [
                'valid' => count($items),
                'errors' => count(array_unique(array_map(static fn($e) => $e['row'], $errors))),
                'create' => $creates,
                'update' => count($items) - $creates,
                'warnings' => $warningCount,
                'notes' => $parsed['notes'] ?? [],
            ],
        ];
    }

    /** Row warnings for a known question whose neutral structure differs from the row. @return list<string> */
    private static function structureWarnings(array $q, array $it): array
    {
        $w = [];
        if ($q['type'] !== $it['type']) {
            $w[] = 'The type differs from the existing question; only the texts are imported.';
        }
        if ($q['type'] !== 'truefalse' && count($q['options']) !== count($it['options'])) {
            $w[] = 'The number of answers differs from the existing question; answer texts are not imported.';
        }
        $correct = [];
        foreach ($q['options'] as $i => $o) {
            if ($o['correct']) {
                $correct[] = $i;
            }
        }
        if ($correct !== $it['correct']) {
            $w[] = 'The correct answers differ from the existing question; the answer key is not changed by an import.';
        }
        if ($q['points'] !== $it['points'] || $q['critical'] !== $it['critical']) {
            $w[] = 'Points or critical differ from the existing question; they are not changed by an import.';
        }
        return $w;
    }

    private function insertItem(\mysqli $db, array $it, int $bankId, int $sort): void
    {
        $qid = Db::insert($db, 'INSERT INTO training_questions (question_uid, question_bank_id, question_type, question_points, question_critical,
                question_sort, question_created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'sisiiii', [Uid::new('q'), $bankId, $it['type'], $it['points'], $it['critical'] ? 1 : 0, $sort, $this->c->userId]);
        Db::exec($db, 'INSERT INTO training_question_texts (qtext_question_id, qtext_lang, qtext_text, qtext_explanation, qtext_topic, qtext_updated_by)
            VALUES (?, ?, ?, ?, ?, ?)', 'issssi', [$qid, $it['lang'], $it['text'], $it['explanation'], $it['topic'], $this->c->userId]);
        foreach ($it['options'] as $i => $label) {
            $oid = Db::insert($db, 'INSERT INTO training_question_options (option_uid, option_question_id, option_sort, option_is_correct, option_pinned)
                VALUES (?, ?, ?, ?, 0)', 'siii', [Uid::new('o'), $qid, $i, in_array($i, $it['correct'], true) ? 1 : 0]);
            if ($it['type'] !== 'truefalse') {
                Db::exec($db, 'INSERT INTO training_option_texts (otext_option_id, otext_lang, otext_text) VALUES (?, ?, ?)',
                    'iss', [$oid, $it['lang'], $label]);
            }
        }
    }

    /** Adds/updates one language's texts of an existing question. Returns whether anything changed. */
    private function updateTexts(\mysqli $db, array $it, int $bankId): bool
    {
        $row = Db::one($db, 'SELECT question_id FROM training_questions WHERE question_uid = ? AND question_bank_id = ? AND question_archived_at IS NULL
            FOR UPDATE', 'si', [$it['uid'], $bankId]);
        if ($row === null) {
            return false; // moved or deleted since the preview
        }
        $qid = (int) $row['question_id'];
        $q = QuestionData::load($db, [$qid])[$qid];
        $lang = $it['lang'];
        $changed = false;
        $old = $q['texts'][$lang] ?? null;
        if ($old === null || $old['text'] !== $it['text'] || $old['explanation'] !== $it['explanation'] || $old['topic'] !== $it['topic']) {
            Db::exec($db, 'INSERT INTO training_question_texts (qtext_question_id, qtext_lang, qtext_text, qtext_explanation, qtext_topic, qtext_updated_by)
                VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE qtext_text = VALUES(qtext_text), qtext_explanation = VALUES(qtext_explanation),
                qtext_topic = VALUES(qtext_topic), qtext_updated_by = VALUES(qtext_updated_by)',
                'issssi', [$qid, $lang, $it['text'], $it['explanation'], $it['topic'], $this->c->userId]);
            $changed = true;
        }
        if ($q['type'] !== 'truefalse' && count($q['options']) === count($it['options'])) {
            foreach ($q['options'] as $i => $o) {
                $label = $it['options'][$i];
                $cur = $o['texts'][$lang] ?? null;
                if ($cur !== null && $cur['text'] === $label) {
                    continue;
                }
                Db::exec($db, 'INSERT INTO training_option_texts (otext_option_id, otext_lang, otext_text) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE otext_text = VALUES(otext_text)', 'iss', [$o['id'], $lang, $label]);
                $changed = true;
            }
        }
        if ($changed) {
            Db::exec($db, 'UPDATE training_questions SET question_version = question_version + 1 WHERE question_id = ?', 'i', [$qid]);
        }
        return $changed;
    }

    private static function pasteItem(int $no, string $lang, string $type, string $q, array $options, array $correct, array $warnings): array
    {
        return [
            'row' => $no, 'uid' => null, 'lang' => $lang, 'type' => $type, 'text' => $q, 'options' => $options, 'correct' => $correct,
            'points' => 1, 'critical' => false, 'topic' => null, 'explanation' => null, 'warnings' => $warnings,
        ];
    }

    /** A cell's text: verbatim except surrounding spaces and line breaks (tabs are kept, see the class comment). */
    private static function cell(string $v): string
    {
        return trim($v, " \n\r\0\x0B");
    }

    /** "B", "A,C", "a; c", "AC" => ['A','C']; '' => []; anything else => null. @return list<string>|null */
    private static function letters(string $raw): ?array
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return [];
        }
        if (preg_match('/^[A-H](?:\s*[,;\/ ]?\s*[A-H])*$/', $raw) !== 1) {
            return null;
        }
        preg_match_all('/[A-H]/', $raw, $m);
        return array_values(array_unique($m[0]));
    }

    private static function sortedLetters(array $l): array
    {
        sort($l, SORT_STRING);
        return $l;
    }

    private static function isTrueFalse(array $texts): bool
    {
        if (count($texts) !== 2) {
            return false;
        }
        $a = mb_strtolower(trim((string) $texts[0]), 'UTF-8');
        $b = mb_strtolower(trim((string) $texts[1]), 'UTF-8');
        return (in_array($a, self::TRUE_WORDS, true) && in_array($b, self::FALSE_WORDS, true))
            || (in_array($a, self::FALSE_WORDS, true) && in_array($b, self::TRUE_WORDS, true));
    }
}
