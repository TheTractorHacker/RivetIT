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
        'training_media'       => ['id' => ['media_id'], 'hash' => 'media_row_sha256', 'version' => 'media_hash_v', 'chain' => null],
        'training_media_pages' => ['id' => ['mpage_pdf_media_id', 'mpage_number'], 'hash' => 'mpage_row_sha256', 'version' => 'mpage_hash_v', 'chain' => null],
        'training_revisions'   => ['id' => ['revision_id'], 'hash' => 'revision_row_sha256', 'version' => 'revision_hash_v', 'chain' => null],
        'training_cert_tokens' => ['id' => ['certtok_id'], 'hash' => 'certtok_row_sha256', 'version' => 'certtok_hash_v', 'chain' => null],
        'training_events'      => ['id' => ['tevent_seq'], 'hash' => 'tevent_hash', 'version' => 'tevent_hash_v', 'chain' => 'tevent_prev_hash'],
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
