<?php

namespace ITFlow\Training\Achievements;

use ITFlow\Training\Kiosk\Bridge\RecordsBridge;

/**
 * RecordsFacts over K3's Kiosk\Bridge\RecordsBridge (P3 spec §3.7). Only AwardFacts builds it,
 * after checking that the bridge is available(). Results are normalised to
 * plain ints so a bridge returning numeric strings cannot change a rule's outcome.
 */
final class BridgeRecordsFacts implements RecordsFacts
{
    public function __construct(private readonly RecordsBridge $bridge)
    {
    }

    public function validCourseIds(int $contactId): array
    {
        $ids = [];
        foreach ((array) $this->bridge->validCourseIds($contactId) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    public function completion(int $completionId): ?array
    {
        $r = $this->bridge->completion($completionId);
        if (!is_array($r) || !isset($r['contact_id'], $r['course_id'])) {
            return null;
        }
        return [
            'contact_id' => (int) $r['contact_id'],
            'course_id' => (int) $r['course_id'],
            'run_id' => isset($r['run_id']) ? (int) $r['run_id'] : null,
            'recorded_at_utc' => isset($r['recorded_at_utc']) ? (string) $r['recorded_at_utc'] : null,
            'voided' => !empty($r['voided']),
        ];
    }

    public function onTimeMonths(int $contactId): int
    {
        return max(0, (int) $this->bridge->onTimeMonths($contactId));
    }
}
