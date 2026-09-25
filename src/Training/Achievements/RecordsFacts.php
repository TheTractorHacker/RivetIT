<?php

namespace ITFlow\Training\Achievements;

/**
 * The completion facts the award rules need (P3 spec §3.5). The only implementation in the
 * product is BridgeRecordsFacts, which reads them through K3's Kiosk\Bridge\RecordsBridge
 * (spec §0.7: Phase 2 records are reached only through the bridge). A CLI harness can hand
 * AwardFacts a fake.
 */
interface RecordsFacts
{
    /** Course ids of the contact's valid completions (not voided, not expired). @return list<int> */
    public function validCourseIds(int $contactId): array;

    /** {contact_id, course_id, run_id, recorded_at_utc, voided} or null. */
    public function completion(int $completionId): ?array;

    /** Whole months in a row with every required training on time; 0 when unknown. */
    public function onTimeMonths(int $contactId): int;
}
