<?php

namespace ITFlow\Training\Preview;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Quiz\FeedbackBuilder;
use ITFlow\Training\Quiz\Grader;
use ITFlow\Training\Quiz\QuizDraw;

/**
 * Preview quiz attempts (spec §3.5 PreviewQuizService, §6.2 preview_quiz_*).
 *
 * The draw happens on the server from the frozen pools of the revision (or the draft build).
 * For level 2+ the attempt - the draw, the answer key captured NOW, and the deadline - is kept in
 * a Core\Scratch 'preview' file for 2 hours (never in $_SESSION); grading uses that captured key,
 * so an edit to the draft mid-attempt cannot change the result. Level 1 gets the questions with
 * graded:false and no token ("Grading is available to course authors").
 *
 * The key holds only what grading and feedback need, per question uid: type, correct option
 * uids, points, critical, and the prompt, topic, explanation and per-option feedback in the
 * attempt's language. Nothing from it reaches the client before submit, and after submit only
 * what FeedbackBuilder releases for the quiz's feedback mode.
 */
final class PreviewQuizService
{
    public const TTL_S = 7200;

    public function __construct(private readonly Ctx $c)
    {
    }

    public function start(string $source, int $courseId, ?int $revisionId, string $lessonUid, string $lang): array
    {
        $src = LearnerView::load($this->c, $source, $courseId, $revisionId);
        $doc = $src['doc'];
        $lesson = null;
        foreach ($doc['lessons'] as $l) {
            if ($l['uid'] === $lessonUid) {
                $lesson = $l;
                break;
            }
        }
        if ($lesson === null || ($lesson['quiz'] ?? null) === null) {
            throw ApiException::notFound('That quiz is not part of this course.');
        }
        $default = (string) $doc['course']['default_language'];
        if (!in_array($lang, $doc['course']['languages'], true)) {
            throw ApiException::validation(['lang' => 'This version is not available in that language.']);
        }
        $quiz = $lesson['quiz'];
        $questions = $doc['questions'] ?: [];
        $draw = QuizDraw::draw($quiz, $questions);
        if ($draw === []) {
            throw ApiException::validation(['lesson_uid' => 'This quiz has no questions yet.']);
        }

        $tr = static function (?array $map, string $field) use ($lang, $default): ?string {
            if ($map === null) {
                return null;
            }
            $v = $map[$lang][$field] ?? $map[$default][$field] ?? null;
            return $v === null ? null : (string) $v;
        };

        $presented = [];
        $key = [];
        foreach ($draw as $d) {
            $q = $questions[$d['q']];
            $optByUid = [];
            foreach ($q['options'] as $o) {
                $optByUid[$o['uid']] = $o;
            }
            $media = $q['text'][$lang]['media_id'] ?? $q['text'][$default]['media_id'] ?? $q['media_id'] ?? null;
            $opts = [];
            $feedback = [];
            $correct = [];
            foreach ($d['o'] as $ou) {
                $o = $optByUid[$ou];
                $opts[] = ['uid' => $ou, 'text' => (string) ($tr($o['text'], 'label') ?? '')];
                $fb = $tr($o['text'], 'feedback');
                if ($fb !== null && trim($fb) !== '') {
                    $feedback[$ou] = $fb;
                }
                if ($o['correct']) {
                    $correct[] = $ou;
                }
            }
            $presented[] = [
                'uid' => $d['q'],
                'type' => (string) $q['type'],
                'text' => (string) ($tr($q['text'], 'q') ?? ''),
                'image_url' => $media === null ? null : MediaStore::url((int) $media),
                'options' => $opts,
            ];
            $key[$d['q']] = [
                'type' => (string) $q['type'],
                'correct' => $correct,
                'points' => (int) $q['points'],
                'critical' => (bool) $q['critical'],
                'text' => (string) ($tr($q['text'], 'q') ?? ''),
                'topic' => $tr($q['text'], 'topic'),
                'explanation' => $tr($q['text'], 'explanation'),
                'feedback' => $feedback,
            ];
        }

        $limit = $quiz['time_limit_s'] === null ? null : (int) $quiz['time_limit_s'];
        $graded = $this->c->level >= 2;
        $token = null;
        if ($graded) {
            $token = Scratch::put('preview', $this->c->userId, [
                'v' => 1,
                'course_id' => $courseId,
                'source' => $src['source'],
                'revision_id' => $src['revision_id'],
                'lesson_uid' => $lessonUid,
                'lang' => $lang,
                'draw' => $draw,
                'key' => $key,
                'pass_pct' => (int) $quiz['pass_pct'],
                'feedback_mode' => (string) $quiz['feedback_mode'],
                'deadline' => $limit === null ? null : time() + $limit,
                'result' => null,
            ], self::TTL_S);
        }

        $v = $lesson['variants'][$lang] ?? $lesson['variants'][$default] ?? [];
        $payload = [
            'graded' => $graded,
            'attempt_token' => $token,
            'quiz' => [
                'title' => (string) ($v['title'] ?? ''),
                'intro' => $quiz['intro'] === null ? null : ($quiz['intro'][$lang] ?? $quiz['intro'][$default] ?? null),
                'time_limit_s' => $limit,
                'deadline_remaining_s' => $limit,
                'show_review' => (bool) $quiz['show_review'],
                'question_count' => count($draw),
            ],
            'questions' => $presented,
        ];
        PayloadGuard::assert($payload, 'quiz_start');
        return $payload;
    }

    /**
     * Grades against the key captured at start. A second submit of the same attempt returns the
     * stored result unchanged.
     */
    public function submit(string $token, array $answers): array
    {
        if ($this->c->level < 2) {
            throw ApiException::forbidden('Grading is available to course authors.');
        }
        $rec = Scratch::get('preview', $token, $this->c->userId);
        if ($rec === null) {
            throw ApiException::notFound('This preview attempt has expired. Start the quiz again.');
        }
        if (is_array($rec['result'] ?? null)) {
            return $rec['result'];
        }
        try {
            $graded = Grader::grade($rec['draw'], $rec['key'], $answers, (int) $rec['pass_pct']);
        } catch (\DomainException $e) {
            throw new ApiException(400, 'validation', $e->getMessage(), ['answers' => $e->getMessage()]);
        }
        $mode = (string) $rec['feedback_mode'];
        $lang = (string) $rec['lang'];
        $result = [
            'score_pct' => $graded['score_pct'],
            'points_earned' => $graded['points_earned'],
            'points_possible' => $graded['points_possible'],
            'passed' => $graded['passed'],
            'pass_pct' => $graded['pass_pct'],
            'critical_missed' => $graded['critical_missed'],
            'topics_missed' => FeedbackBuilder::topicsMissed($mode, $graded, $rec['key'], $lang),
            'feedback' => FeedbackBuilder::build($mode, $graded['passed'], $graded, $rec['key'], $lang),
        ];
        PayloadGuard::assert($result, 'quiz_submit');
        $rec['result'] = $result;
        try {
            Scratch::replace('preview', $token, $this->c->userId, $rec);
        } catch (\RuntimeException) {
            // Expired between read and write: the result is still returned; a retry starts over.
        }
        return $result;
    }
}
