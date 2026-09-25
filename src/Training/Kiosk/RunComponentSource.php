<?php

namespace ITFlow\Training\Kiosk;

use ITFlow\Training\Core\Ctx;

/**
 * The online part of a blended course, as Phase 2 reads it (C-P2-3; P2 spec §1.5 names this
 * exact class). Phase 2's Records\Components::online() constructs it with P2's Ctx and calls
 * latestAttested() from CompletionService::tryIssueComponents().
 *
 * NON-LOCKING plain read on the SAME connection: inside the kiosk's attest transaction it sees
 * the run's uncommitted attestation (status already moved to awaiting_session/awaiting_evaluation),
 * and it never takes a lock that could invert P2's order.
 *
 * The interface is implemented only when Phase 2 is deployed (P2 declares it); on a tree without
 * Phase 2 this class is never loaded (Components::online() is P2 code).
 */
final class RunComponentSource implements \ITFlow\Training\Records\OnlineComponentSource
{
    public function __construct(private readonly Ctx $c)
    {
    }

    /**
     * Newest attested run of (contact, course) with status awaiting_session, awaiting_evaluation or
     * completed whose LOCAL attestation date is on or after $sinceOn.
     *
     * @return array{run_id:int, revision_id:int, attested_on:string, score_pct:?string, pass_mark_pct:?int, attempts_used:?int,
     *               language:string, proof:string, kiosk_id:?int, asset_id:?int, learner_tsig_id:?int, pin_source:?string,
     *               odoo_employee_id:?int, duration_minutes:?int}|null
     */
    public function latestAttested(int $contactId, int $courseId, string $sinceOn): ?array
    {
        return \ITFlow\Training\Kiosk\Learn\AttestedRuns::find($this->c->db, $contactId, $courseId, $sinceOn);
    }
}
