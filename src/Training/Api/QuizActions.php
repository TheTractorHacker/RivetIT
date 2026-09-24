<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Csv;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Quiz\BankService;
use ITFlow\Training\Quiz\Guard;
use ITFlow\Training\Quiz\QuestionExporter;
use ITFlow\Training\Quiz\QuestionImporter;
use ITFlow\Training\Quiz\QuestionRules;
use ITFlow\Training\Quiz\QuestionService;
use ITFlow\Training\Quiz\QuizService;

/**
 * JSON handlers for the Question Library, questions, quizzes and draw rules (spec §6.2, lane D).
 * Every handler validates its input through ApiContext and delegates to a Quiz service; the
 * Router has already enforced module, method, CSRF and level, and closed the session.
 */
final class QuizActions
{
    private const QUIZ_FIELDS = ['role', 'pass_pct', 'max_attempts', 'time_limit_s', 'shuffle_questions', 'shuffle_options',
        'feedback_mode', 'show_review', 'must_pass', 'intro'];

    // --- banks ----------------------------------------------------------------------------------

    /** GET bank_tree: course_id?, include_quiz_banks? => {banks:[Bank]} */
    public static function bankTree(Ctx $c, ApiContext $a): array
    {
        $courseId = $a->int('course_id', false, 1);
        return ['banks' => (new BankService($c))->tree($courseId, (bool) $a->bool('include_quiz_banks', false))];
    }

    /** POST bank_create: {parent_id?, name, course_id?, description?} => Bank */
    public static function bankCreate(Ctx $c, ApiContext $a): array
    {
        return (new BankService($c))->create(
            $a->int('parent_id', false, 1),
            (string) $a->str('name', BankService::NAME_MAX),
            $a->int('course_id', false, 1),
            $a->str('description', BankService::DESCRIPTION_MAX, false, true)
        );
    }

    /** POST bank_update: {bank_id, fields:{name?, description?, parent_id?, sort?}} => Bank */
    public static function bankUpdate(Ctx $c, ApiContext $a): array
    {
        $bankId = (int) $a->int('bank_id', true, 1);
        $f = $a->fields('fields', ['name', 'description', 'parent_id', 'sort']);
        $sub = new ApiContext('POST', $f, $c->settings);
        $clean = [];
        if (array_key_exists('name', $f)) {
            $clean['name'] = $sub->str('name', BankService::NAME_MAX);
        }
        if (array_key_exists('description', $f)) {
            $clean['description'] = $sub->str('description', BankService::DESCRIPTION_MAX, false, true);
        }
        if (array_key_exists('parent_id', $f)) {
            $clean['parent_id'] = $sub->int('parent_id', false, 1);
        }
        if (array_key_exists('sort', $f)) {
            $clean['sort'] = $sub->int('sort', true, 0, 65535);
        }
        return (new BankService($c))->update($bankId, $clean);
    }

    /** POST bank_archive: {bank_id} => {} */
    public static function bankArchive(Ctx $c, ApiContext $a): array
    {
        $bankId = (int) $a->int('bank_id', true, 1);
        (new BankService($c))->archive($bankId);
        self::log('Archive', "Archived question bank #$bankId", $bankId);
        return [];
    }

    // --- questions --------------------------------------------------------------------------------

    /** GET question_list: bank_id, include_descendants?, q?, type?, critical?, needs_attention? => {questions} */
    public static function questionList(Ctx $c, ApiContext $a): array
    {
        return ['questions' => (new QuestionService($c))->list([
            'bank_id' => (int) $a->int('bank_id', true, 1),
            'include_descendants' => (bool) $a->bool('include_descendants', false),
            'q' => $a->str('q', 200, false),
            'type' => $a->enum('type', QuestionRules::TYPES, false),
            'critical' => $a->bool('critical'),
            'needs_attention' => (bool) $a->bool('needs_attention', false),
        ])];
    }

    /** POST question_create: {bank_id, type, lang, text?, after_question_id?} => Question */
    public static function questionCreate(Ctx $c, ApiContext $a): array
    {
        return (new QuestionService($c))->create(
            (int) $a->int('bank_id', true, 1),
            (string) $a->enum('type', QuestionRules::TYPES),
            (string) $a->lang('lang'),
            $a->str('text', QuestionRules::TEXT_MAX, false, true),
            $a->int('after_question_id', false, 1)
        );
    }

    /**
     * POST question_update: {question_id, version, lang, text?:{text, explanation, topic, media_id},
     * structure?:{type, points, critical, media_id}, options?:[{id?, correct?, pinned?, text?, feedback?}]} => Question
     */
    public static function questionUpdate(Ctx $c, ApiContext $a): array
    {
        $text = $a->has('text') ? $a->fields('text', ['text', 'explanation', 'topic', 'media_id']) : [];
        if (array_key_exists('media_id', $text) && $text['media_id'] !== null) {
            $text['media_id'] = (new ApiContext('POST', $text, $c->settings))->int('media_id', false, 1);
        }
        $structure = null;
        if ($a->has('structure')) {
            $structure = $a->fields('structure', ['type', 'points', 'critical', 'media_id']);
            $sub = new ApiContext('POST', $structure, $c->settings);
            if (array_key_exists('points', $structure)) {
                $structure['points'] = $sub->int('points', true, QuestionRules::MIN_POINTS, QuestionRules::MAX_POINTS);
            }
            if (array_key_exists('critical', $structure)) {
                $structure['critical'] = (bool) $sub->bool('critical', false);
            }
            if (array_key_exists('type', $structure)) {
                $structure['type'] = $sub->enum('type', QuestionRules::TYPES);
            }
            if (array_key_exists('media_id', $structure) && $structure['media_id'] !== null) {
                $structure['media_id'] = $sub->int('media_id', false, 1);
            }
        }
        $options = null;
        if ($a->has('options')) {
            $options = $a->arr('options');
            if ($options !== [] && !array_is_list($options)) {
                throw ApiException::validation(['options' => 'Must be a list.']);
            }
        }
        return (new QuestionService($c))->update(
            (int) $a->int('question_id', true, 1),
            (int) $a->int('version', true, 0),
            (string) $a->lang('lang'),
            $text,
            $structure,
            $options
        );
    }

    /** POST question_delete: {question_id} => {} */
    public static function questionDelete(Ctx $c, ApiContext $a): array
    {
        (new QuestionService($c))->delete((int) $a->int('question_id', true, 1));
        return [];
    }

    /** POST question_restore: {question_id} => Question */
    public static function questionRestore(Ctx $c, ApiContext $a): array
    {
        $id = (int) $a->int('question_id', true, 1);
        $svc = new QuestionService($c);
        $svc->restore($id);
        return $svc->get($id);
    }

    /** POST question_duplicate: {question_id} => Question */
    public static function questionDuplicate(Ctx $c, ApiContext $a): array
    {
        return (new QuestionService($c))->duplicate((int) $a->int('question_id', true, 1));
    }

    /** POST questions_reorder: {bank_id, ids} => {} */
    public static function questionsReorder(Ctx $c, ApiContext $a): array
    {
        (new QuestionService($c))->reorder((int) $a->int('bank_id', true, 1), $a->ints('ids'));
        return [];
    }

    /** POST questions_move: {ids, bank_id} => {} */
    public static function questionsMove(Ctx $c, ApiContext $a): array
    {
        (new QuestionService($c))->move($a->ints('ids'), (int) $a->int('bank_id', true, 1));
        return [];
    }

    // --- import / export ------------------------------------------------------------------------

    /** POST question_paste_preview: {bank_id, lang, text} => {import_token, rows, errors, summary} */
    public static function questionPastePreview(Ctx $c, ApiContext $a): array
    {
        return (new QuestionImporter($c))->previewPaste(
            (string) $a->str('text', QuestionImporter::PASTE_MAX_CHARS),
            (int) $a->int('bank_id', true, 1),
            (string) $a->lang('lang')
        );
    }

    /** POST import_commit: {import_token, bank_id} => {created, updated, bank_id} */
    public static function importCommit(Ctx $c, ApiContext $a): array
    {
        $token = (string) $a->str('import_token', 32);
        if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) {
            throw ApiException::validation(['import_token' => 'This import preview expired. Check the questions again.']);
        }
        $bankId = (int) $a->int('bank_id', true, 1);
        $r = (new QuestionImporter($c))->commit($token, $bankId);
        self::log('Create', "Imported {$r['created']} new and {$r['updated']} updated questions into bank #$bankId", $bankId);
        return $r;
    }

    /** GET (raw) question_csv_template => CSV download */
    public static function questionCsvTemplate(Ctx $c, ApiContext $a): never
    {
        Csv::send('question-import-template.csv', QuestionExporter::HEADER, QuestionExporter::templateRows());
    }

    /** GET (raw, level 3) bank_export_csv: bank_id => CSV download with answer keys */
    public static function bankExportCsv(Ctx $c, ApiContext $a): never
    {
        PublishActions::assertNotCrossSite();   // answer keys + an export log row: never from another site's link
        $bankId = (int) $a->int('bank_id', true, 1);
        $bank = Guard::bank($c->db, $bankId);
        $rows = QuestionExporter::bankRows($c->db, $bankId);
        self::log('Export', 'Exported question bank "' . $bank['qbank_name'] . '" (' . count($rows) . ' rows, with answer keys)', $bankId);
        Csv::send('questions-' . $bank['qbank_uid'] . '.csv', QuestionExporter::HEADER, $rows);
    }

    // --- quizzes ----------------------------------------------------------------------------------

    /** GET quiz_get: lesson_id => Quiz */
    public static function quizGet(Ctx $c, ApiContext $a): array
    {
        $lessonId = (int) $a->int('lesson_id', true, 1);
        Guard::lesson($c->db, $lessonId);
        return (new QuizService($c))->get($lessonId) ?? throw ApiException::notFound('This lesson has no quiz.');
    }

    /** POST quiz_attach: {lesson_id} => Quiz (a quick check on content lessons; the quiz of a Quiz lesson) */
    public static function quizAttach(Ctx $c, ApiContext $a): array
    {
        $lessonId = (int) $a->int('lesson_id', true, 1);
        $lesson = Guard::lesson($c->db, $lessonId);
        $role = $lesson['lesson_type'] === 'quiz' ? 'standalone' : 'check';
        $svc = new QuizService($c);
        $existing = $svc->get($lessonId);
        return $existing ?? $svc->ensureForLesson($lessonId, $role);
    }

    /** POST quiz_detach: {lesson_id} => {} */
    public static function quizDetach(Ctx $c, ApiContext $a): array
    {
        (new QuizService($c))->detachFromLesson((int) $a->int('lesson_id', true, 1));
        return [];
    }

    /** POST quiz_update: {quiz_id, version, lang?, fields, replace_exam?} => Quiz */
    public static function quizUpdate(Ctx $c, ApiContext $a): array
    {
        $fields = $a->fields('fields', self::QUIZ_FIELDS);
        if (array_key_exists('intro', $fields) && $fields['intro'] !== null && !is_string($fields['intro'])) {
            throw ApiException::validation(['intro' => 'Must be text.']);
        }
        if ($a->bool('replace_exam', false)) {
            $fields['replace_exam'] = true;
        }
        return (new QuizService($c))->update(
            (int) $a->int('quiz_id', true, 1),
            (int) $a->int('version', true, 0),
            $fields,
            $a->lang('lang', false)
        );
    }

    /** POST quiz_rule_add: {quiz_id, bank_id, include_descendants?, count?} => Rule */
    public static function quizRuleAdd(Ctx $c, ApiContext $a): array
    {
        return (new QuizService($c))->ruleAdd(
            (int) $a->int('quiz_id', true, 1),
            (int) $a->int('bank_id', true, 1),
            (bool) $a->bool('include_descendants', true),
            (int) ($a->int('count', false, 0, QuizService::RULE_COUNT_MAX) ?? 0)
        );
    }

    /** POST quiz_rule_update: {rule_id, fields:{bank_id?, include_descendants?, count?}} => Rule */
    public static function quizRuleUpdate(Ctx $c, ApiContext $a): array
    {
        $f = $a->fields('fields', ['bank_id', 'include_descendants', 'count']);
        $sub = new ApiContext('POST', $f, $c->settings);
        $clean = [];
        if (array_key_exists('bank_id', $f)) {
            $clean['bank_id'] = $sub->int('bank_id', true, 1);
        }
        if (array_key_exists('include_descendants', $f)) {
            $clean['include_descendants'] = (bool) $sub->bool('include_descendants', false);
        }
        if (array_key_exists('count', $f)) {
            $clean['count'] = $sub->int('count', true, 0, QuizService::RULE_COUNT_MAX);
        }
        return (new QuizService($c))->ruleUpdate((int) $a->int('rule_id', true, 1), $clean);
    }

    /** POST quiz_rule_delete: {rule_id} => {} */
    public static function quizRuleDelete(Ctx $c, ApiContext $a): array
    {
        (new QuizService($c))->ruleDelete((int) $a->int('rule_id', true, 1));
        return [];
    }

    /** POST quiz_rules_reorder: {quiz_id, ids} => {} */
    public static function quizRulesReorder(Ctx $c, ApiContext $a): array
    {
        (new QuizService($c))->rulesReorder((int) $a->int('quiz_id', true, 1), $a->ints('ids'));
        return [];
    }

    /** GET quiz_pool_stats: quiz_id => PoolStats */
    public static function quizPoolStats(Ctx $c, ApiContext $a): array
    {
        return (new QuizService($c))->poolStats((int) $a->int('quiz_id', true, 1));
    }

    // ------------------------------------------------------------------------------------------

    /** logAction() after the service committed (legacy helper; absent in CLI harnesses). Best-effort. */
    private static function log(string $action, string $description, int $entityId): void
    {
        CourseActions::log($action, $description, $entityId);
    }
}
