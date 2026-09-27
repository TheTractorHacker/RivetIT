<?php

namespace ITFlow\Training\Records;

/**
 * The online part of a blended course (Phase 2 spec §3.5, §1.5). Phase 3 implements it as
 * \ITFlow\Training\Kiosk\RunComponentSource; until then Components::online() returns null and
 * a course that needs its online part stays pending.
 */
interface OnlineComponentSource
{
    /**
     * The latest attested (passed and signed) online run for the pair on or after $sinceOn, or null.
     * $excludeRunIds: runs that no longer count (the runs of records voided by "Reset (take again)", RetakeVoids).
     *
     * @return array{run_id:int, revision_id:?int, attested_on:string, score_pct:?string, pass_mark_pct:?int,
     *               attempts_used:?int, language:string, proof:string, kiosk_id:?int, asset_id:?int,
     *               learner_tsig_id:?int, pin_source:?string, odoo_employee_id:?int, duration_minutes:?int}|null
     */
    public function latestAttested(int $contactId, int $courseId, string $sinceOn, array $excludeRunIds = []): ?array;
}
