<?php

namespace ITFlow\Training\Api;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Achievements\AwardEngine;
use ITFlow\Training\Achievements\AwardRepository;
use ITFlow\Training\Achievements\AwardScope;
use ITFlow\Training\Core\Ctx;

/**
 * Agent award routes (P3 spec §4.3, lane K6). The Router already required module_training >= 1;
 * each handler checks its own level, and every per-contact action asserts the contact is in
 * the agent's departments (404 otherwise, fail-closed).
 *
 *   award_list    GET   level 1  achievement_id?, contact_id?  => {awards:[…], counts:{achievement_id:n}, more}
 *   award_manual  POST  level 2  {achievement_id, contact_id, reason(5..500)}  => {award}
 *
 * The legacy activity log and the audit trail are written after the award has committed, and
 * never fail the request.
 */
final class AwardActions
{
    public static function awardList(Ctx $c, ApiContext $a): array
    {
        $achievementId = $a->int('achievement_id', false, 1);
        $contactId = $a->int('contact_id', false, 1);
        if ($contactId !== null) {
            AwardScope::assertContact($c, $contactId);
        }
        $scope = AwardScope::clientIds($c);
        $list = AwardRepository::listRows($c->db, $scope, $achievementId, $contactId);
        $counts = AwardRepository::counts($c->db, $scope);
        $countMap = new \stdClass();
        foreach ($counts as $id => $n) {
            $countMap->{(string) $id} = $n;
        }
        return ['awards' => $list['rows'], 'counts' => $countMap, 'more' => $list['more']];
    }

    public static function awardManual(Ctx $c, ApiContext $a): array
    {
        if ($c->level < 2 && !$c->isAdmin) {
            throw ApiException::forbidden("You don't have permission to award achievements.");
        }
        $achievementId = (int) $a->int('achievement_id', true, 1);
        $contactId = (int) $a->int('contact_id', true, 1);
        $reason = (string) $a->str('reason', AwardEngine::REASON_MAX);
        if (mb_strlen($reason, 'UTF-8') < AwardEngine::REASON_MIN) {
            throw ApiException::validation(['reason' => 'Write at least ' . AwardEngine::REASON_MIN . ' characters.']);
        }
        $contact = AwardScope::assertContact($c, $contactId);
        if ($contact['archived']) {
            throw ApiException::notFound('That person was not found.');
        }

        $award = AwardEngine::awardManual($c->db, $achievementId, $contactId, $reason, [
            'actor_type' => 'user',
            'actor_user_id' => $c->userId,
            'user_agent' => $c->userAgent,
        ]);

        self::log('Award', 'Awarded achievement "' . $award['achievement']['name'] . '" to ' . $contact['name'], (int) $award['id']);
        try {
            (new AuditService($c->db))->log('training.award_manual', $c->userId, 'training_achievement_award', (int) $award['id'], 'create',
                'Awarded "' . $award['achievement']['name'] . '" to contact #' . $contactId,
                ['achievement_id' => $achievementId, 'contact_id' => $contactId]);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . get_class($e));
        }
        return ['award' => $award];
    }

    /** logAction('Training', …) after commit; best-effort (absent in CLI harnesses). */
    private static function log(string $action, string $description, int $entityId): void
    {
        if (!function_exists('logAction')) {
            return;
        }
        try {
            logAction('Training', $action, $description, 0, $entityId);
        } catch (\Throwable $e) {
            error_log('Training: logAction failed: ' . get_class($e));
        }
    }
}
