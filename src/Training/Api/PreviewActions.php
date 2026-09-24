<?php

namespace ITFlow\Training\Api;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Uid;
use ITFlow\Training\Preview\PreviewQuizService;

/**
 * JSON handlers for preview quiz attempts (spec §6.2, lane D). The route is level 1; a draft
 * source needs level 2 (checked in LearnerView::load) and so does grading (submit's route level).
 * Both responses have already passed PayloadGuard inside the service.
 */
final class PreviewActions
{
    /**
     * POST preview_quiz_start: {source:'draft'|'revision', course_id, revision_id?, lesson_uid, lang} =>
     * {graded, attempt_token|null, quiz:{title, intro, time_limit_s, deadline_remaining_s, show_review, question_count},
     *  questions:[{uid, type, text, image_url, options:[{uid, text}]}]}
     */
    public static function start(Ctx $c, ApiContext $a): array
    {
        $lessonUid = (string) $a->str('lesson_uid', 12);
        if (!Uid::valid($lessonUid, 'l')) {
            throw ApiException::validation(['lesson_uid' => 'Not a lesson.']);
        }
        return (new PreviewQuizService($c))->start(
            (string) $a->enum('source', ['draft', 'revision']),
            (int) $a->int('course_id', true, 1),
            $a->int('revision_id', false, 1),
            $lessonUid,
            (string) $a->lang('lang')
        );
    }

    /**
     * POST preview_quiz_submit (level 2): {attempt_token, answers:{questionUid:[optionUid]}} =>
     * {score_pct, points_earned, points_possible, passed, pass_pct, critical_missed, topics_missed, feedback}
     */
    public static function submit(Ctx $c, ApiContext $a): array
    {
        $token = (string) $a->str('attempt_token', 32);
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            throw ApiException::notFound('This preview attempt has expired. Start the quiz again.');
        }
        $answers = $a->has('answers') ? $a->arr('answers') : [];
        if ($answers !== [] && array_is_list($answers)) {
            throw new ApiException(400, 'validation', 'Answers must be keyed by question.', ['answers' => 'Must be an object.']);
        }
        return (new PreviewQuizService($c))->submit($token, $answers);
    }
}
