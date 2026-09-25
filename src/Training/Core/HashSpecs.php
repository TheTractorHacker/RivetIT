<?php

namespace ITFlow\Training\Core;

/**
 * Versioned column lists for every hashed (insert-only) training table - spec §2.6.
 *
 * NEVER EDIT A PUBLISHED VERSION. Rows already in the database were hashed over exactly
 * these lists; changing one would make every existing row fail verification. A later spec
 * adds `2 => [...]` beside `1`, new rows are written with the new `*_hash_v`, and each row's
 * own `*_hash_v` column selects the list used to verify it.
 *
 * Surrogate AUTO_INCREMENT ids and the hash column itself are never part of a list (natural
 * keys such as tevent_seq or the pdf/page pair are). training_revision_media
 * has no row hash (spec §1.3 #5): the complete media manifest is inside the hashed revision
 * JSON instead.
 */
final class HashSpecs
{
    public const SPECS = [
        // ---- Phase 3+4 kiosk evidence (P3 spec §2.5): insert-only, written only through Kiosk\Core\Hashed ----
        'training_lesson_completions' => [
            1 => ['lcomp_run_id', 'lcomp_contact_id', 'lcomp_course_id', 'lcomp_revision_id', 'lcomp_lesson_uid', 'lcomp_lesson_type',
                  'lcomp_language', 'lcomp_opened_at_utc', 'lcomp_completed_at_utc', 'lcomp_server_seconds', 'lcomp_required_seconds',
                  'lcomp_max_position', 'lcomp_coverage_json', 'lcomp_attempt_id', 'lcomp_tsig_id', 'lcomp_ksess_id', 'lcomp_kiosk_id',
                  'lcomp_hash_v'],
        ],
        'training_attempts' => [
            1 => ['tattempt_run_id', 'tattempt_contact_id', 'tattempt_course_id', 'tattempt_revision_id', 'tattempt_lesson_uid',
                  'tattempt_quiz_uid', 'tattempt_kind', 'tattempt_number', 'tattempt_language', 'tattempt_draw_json',
                  'tattempt_pass_mark_pct', 'tattempt_time_limit_s', 'tattempt_started_at_utc', 'tattempt_deadline_utc',
                  'tattempt_ksess_id', 'tattempt_kiosk_id', 'tattempt_hash_v'],
        ],
        'training_attempt_results' => [
            1 => ['tresult_attempt_id', 'tresult_submitted_at_utc', 'tresult_points_earned', 'tresult_points_possible', 'tresult_score_pct',
                  'tresult_pass_mark_pct', 'tresult_critical_missed', 'tresult_passed', 'tresult_timed_out', 'tresult_duration_seconds',
                  'tresult_rapid_flag', 'tresult_finalized_by', 'tresult_answers_sha256', 'tresult_log_sha256', 'tresult_log_max_id',
                  'tresult_hash_v'],
        ],
        'training_signatures' => [
            // tsig_png_base64 is covered by tsig_png_sha256 (the verifier re-derives it from the decoded PNG).
            1 => ['tsig_purpose', 'tsig_contact_id', 'tsig_signer_name', 'tsig_png_sha256', 'tsig_width', 'tsig_height', 'tsig_ink_px',
                  'tsig_statement_sha256', 'tsig_kiosk_id', 'tsig_ksess_id', 'tsig_run_id', 'tsig_tsession_id', 'tsig_captured_at_utc',
                  'tsig_hash_v'],
        ],
        'training_achievement_awards' => [
            1 => ['taward_achievement_id', 'taward_achievement_uid', 'taward_contact_id', 'taward_rule_type', 'taward_scope_key',
                  'taward_source', 'taward_evidence_json', 'taward_reason', 'taward_awarded_by_user_id', 'taward_awarded_by_contact_id',
                  'taward_snap_name', 'taward_snap_icon', 'taward_snap_color', 'taward_awarded_at_utc', 'taward_kiosk_id', 'taward_hash_v'],
        ],
        'training_media' => [
            1 => ['media_sha256', 'media_kind', 'media_mime', 'media_ext', 'media_bytes', 'media_path', 'media_original_name',
                  'media_width', 'media_height', 'media_page_count', 'media_duration_ms', 'media_video_codec', 'media_audio_codec',
                  'media_faststart', 'media_uploaded_by', 'media_created_at_utc', 'media_hash_v'],
        ],
        'training_media_pages' => [
            1 => ['mpage_pdf_media_id', 'mpage_number', 'mpage_media_id', 'mpage_created_at_utc', 'mpage_hash_v'],
        ],
        'training_revisions' => [
            // revision_json itself is covered by revision_sha256 = sha256(revision_json bytes).
            1 => ['revision_course_id', 'revision_number', 'revision_kind', 'revision_schema', 'revision_sha256', 'revision_languages',
                  'revision_change_note', 'revision_requires_retraining', 'revision_retrain_due_days', 'revision_published_by',
                  'revision_published_at_utc', 'revision_hash_v'],
        ],
        'training_cert_tokens' => [
            // Phase 2 writes these; registered now so the contract is fixed before completions exist (spec §2.5).
            1 => ['certtok_completion_id', 'certtok_nonce', 'certtok_token_sha256', 'certtok_created_at_utc', 'certtok_hash_v'],
        ],
        // ---- Phase 2 (spec §2.4): records are insert-only and written only through Core\HashedInsert ----
        'training_completions' => [
            1 => ['completion_contact_id', 'completion_course_id', 'completion_course_kind', 'completion_revision_id',
                  'completion_revision_sha256', 'completion_assignment_id', 'completion_source_key', 'completion_method',
                  'completion_proof', 'completion_completed_on', 'completion_trained_on', 'completion_evaluated_on',
                  'completion_expires_on', 'completion_language', 'completion_score_pct', 'completion_pass_mark_pct',
                  'completion_attempts_used', 'completion_duration_minutes', 'completion_run_id', 'completion_attempt_id',
                  'completion_tsession_id', 'completion_tattendee_id', 'completion_evaluation_id', 'completion_trainer_contact_id',
                  'completion_trainer_user_id', 'completion_trainer_name', 'completion_evaluator_name', 'completion_learner_tsig_id',
                  'completion_trainer_tsig_id', 'completion_kiosk_id', 'completion_asset_id', 'completion_pin_source',
                  'completion_odoo_employee_id', 'completion_external_issuer', 'completion_external_ref',
                  'completion_evidence_media_id', 'completion_recorded_by_user_id', 'completion_attestation_text',
                  'completion_notes', 'completion_cert_number', 'completion_snap_contact_name', 'completion_snap_contact_title',
                  'completion_snap_client_id', 'completion_snap_client_name', 'completion_snap_course_name',
                  'completion_snap_course_code', 'completion_snap_revision_number', 'completion_snap_regulation_ref',
                  'completion_supersedes_id', 'completion_recorded_at_utc', 'completion_hash_v'],
        ],
        'training_completion_voids' => [
            1 => ['cvoid_completion_id', 'cvoid_reason', 'cvoid_by_user_id', 'cvoid_at_utc', 'cvoid_hash_v'],
        ],
        'training_evaluations' => [
            1 => ['evaluation_source_key', 'evaluation_contact_id', 'evaluation_course_id', 'evaluation_revision_id',
                  'evaluation_run_id', 'evaluation_tsession_id', 'evaluation_channel', 'evaluation_evaluator_contact_id',
                  'evaluation_evaluator_user_id', 'evaluation_evaluator_name', 'evaluation_evaluated_on', 'evaluation_result',
                  'evaluation_equipment', 'evaluation_checklist_json', 'evaluation_notes', 'evaluation_proof',
                  'evaluation_evaluator_tsig_id', 'evaluation_evaluatee_tsig_id', 'evaluation_evidence_media_id',
                  'evaluation_kiosk_id', 'evaluation_recorded_by_user_id', 'evaluation_recorded_at_utc', 'evaluation_hash_v'],
        ],
        'training_events' => [
            // tevent_hash = sha256(tevent_prev_hash . "\n" . Canonical::row(these columns)).
            1 => ['tevent_seq', 'tevent_at_utc', 'tevent_type', 'tevent_actor_type', 'tevent_actor_user_id', 'tevent_actor_contact_id',
                  'tevent_kiosk_id', 'tevent_ksess_id', 'tevent_subject_contact_id', 'tevent_course_id', 'tevent_entity_type',
                  'tevent_entity_id', 'tevent_entity_sha256', 'tevent_payload_json', 'tevent_user_agent', 'tevent_hash_v',
                  'tevent_prev_hash'],
        ],
    ];

    /**
     * Per-table bookkeeping columns: the primary key(s), the stored hash, the version selector,
     * and (events only) the column whose value prefixes the canonical row in the hash input.
     */
    public const META = [
        'training_lesson_completions' => ['id' => ['lcomp_id'], 'hash' => 'lcomp_row_sha256', 'version' => 'lcomp_hash_v', 'chain' => null],
        'training_attempts'           => ['id' => ['tattempt_id'], 'hash' => 'tattempt_row_sha256', 'version' => 'tattempt_hash_v', 'chain' => null],
        'training_attempt_results'    => ['id' => ['tresult_attempt_id'], 'hash' => 'tresult_row_sha256', 'version' => 'tresult_hash_v', 'chain' => null],
        'training_signatures'         => ['id' => ['tsig_id'], 'hash' => 'tsig_row_sha256', 'version' => 'tsig_hash_v', 'chain' => null],
        'training_achievement_awards' => ['id' => ['taward_id'], 'hash' => 'taward_row_sha256', 'version' => 'taward_hash_v', 'chain' => null],
        'training_media'       => ['id' => ['media_id'], 'hash' => 'media_row_sha256', 'version' => 'media_hash_v', 'chain' => null],
        'training_media_pages' => ['id' => ['mpage_pdf_media_id', 'mpage_number'], 'hash' => 'mpage_row_sha256', 'version' => 'mpage_hash_v', 'chain' => null],
        'training_revisions'   => ['id' => ['revision_id'], 'hash' => 'revision_row_sha256', 'version' => 'revision_hash_v', 'chain' => null],
        'training_cert_tokens' => ['id' => ['certtok_id'], 'hash' => 'certtok_row_sha256', 'version' => 'certtok_hash_v', 'chain' => null],
        'training_completions' => ['id' => ['completion_id'], 'hash' => 'completion_row_sha256', 'version' => 'completion_hash_v', 'chain' => null],
        'training_completion_voids' => ['id' => ['cvoid_id'], 'hash' => 'cvoid_row_sha256', 'version' => 'cvoid_hash_v', 'chain' => null],
        'training_evaluations' => ['id' => ['evaluation_id'], 'hash' => 'evaluation_row_sha256', 'version' => 'evaluation_hash_v', 'chain' => null],
        'training_events'      => ['id' => ['tevent_seq'], 'hash' => 'tevent_hash', 'version' => 'tevent_hash_v', 'chain' => 'tevent_prev_hash'],
    ];

    /**
     * Event type => [table, id column] for the generic verifier path (spec §3.1): the event's
     * entity_id names a row of that table, whose row hash must re-compute and equal the event's
     * entity_sha256, and every row of the table must be named by such an event.
     */
    public const EVENT_ROWS = [
        'run.lesson_complete' => ['training_lesson_completions', 'lcomp_id'],
        'attempt.start'       => ['training_attempts', 'tattempt_id'],
        'attempt.submit'      => ['training_attempt_results', 'tresult_attempt_id'],
        'signature.captured'  => ['training_signatures', 'tsig_id'],
        'achievement.awarded' => ['training_achievement_awards', 'taward_id'],
        'completion.recorded' => ['training_completions', 'completion_id'],
        'completion.voided'   => ['training_completion_voids', 'cvoid_id'],
        'evaluation.recorded' => ['training_evaluations', 'evaluation_id'],
    ];

    /**
     * Composite digests, frozen at finalize (spec §2.4). Computed only by Core\SessionDigest:
     *   tsession_sha256 = sha256(Canonical::doc(['v' => '1', 'session' => session subset,
     *                     'attendees' => [attendee subsets sorted by (int) tattendee_contact_id]]))
     * over a text-protocol re-read. Same rule as SPECS: a published version is never edited.
     */
    public const DIGESTS = [
        'training_sessions' => [1 => [
            'session' => ['tsession_course_id', 'tsession_revision_id', 'tsession_held_on', 'tsession_start_time', 'tsession_duration_minutes',
                          'tsession_client_id', 'tsession_location', 'tsession_topic', 'tsession_notes', 'tsession_trainer_contact_id',
                          'tsession_trainer_user_id', 'tsession_trainer_name', 'tsession_channel', 'tsession_is_backfill',
                          'tsession_evidence_media_id', 'tsession_created_kiosk_id', 'tsession_started_at_utc', 'tsession_trainer_tsig_id',
                          'tsession_finalized_at_utc', 'tsession_finalized_by_user_id', 'tsession_finalized_by_contact_id',
                          'tsession_finalize_attest', 'tsession_digest_v'],
            'attendees' => ['tattendee_contact_id', 'tattendee_proof', 'tattendee_attest_reason', 'tattendee_checked_in_at_utc',
                            'tattendee_kiosk_id', 'tattendee_tsig_id', 'tattendee_attendance', 'tattendee_practical', 'tattendee_notes',
                            'tattendee_marked_by_contact_id', 'tattendee_marked_by_user_id', 'tattendee_removed_at_utc',
                            'tattendee_removed_reason'],
        ]],
    ];

    /** @return list<string> */
    public static function columns(string $t, int $v): array
    {
        if (!isset(self::SPECS[$t][$v])) {
            throw new \InvalidArgumentException("No hash spec for $t v$v");
        }
        return self::SPECS[$t][$v];
    }

    public static function current(string $t): int
    {
        if (!isset(self::SPECS[$t])) {
            throw new \InvalidArgumentException("No hash spec for $t");
        }
        return max(array_keys(self::SPECS[$t]));
    }

    /** @return array{id:list<string>, hash:string, version:string, chain:?string} */
    public static function meta(string $t): array
    {
        if (!isset(self::META[$t])) {
            throw new \InvalidArgumentException("No hash spec for $t");
        }
        return self::META[$t];
    }

    /**
     * Every column a verifier must SELECT to re-hash a row of $t under any registered version,
     * plus its id, hash and version columns. Explicit - hashed tables are never read with a star select.
     *
     * @return list<string>
     */
    public static function selectColumns(string $t): array
    {
        $m = self::meta($t);
        $cols = $m['id'];
        foreach (self::SPECS[$t] as $list) {
            foreach ($list as $c) {
                $cols[] = $c;
            }
        }
        $cols[] = $m['hash'];
        $cols[] = $m['version'];
        return array_values(array_unique($cols));
    }
}
