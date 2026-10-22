<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Training\Upstream\Schema;

/**
 * What the Odoo write-back sends each training record as (the owner's 2026-09-27 ask, Phase 5 LATER item L2).
 * The targets are ADDITIVE: an admin ticks any combination, and every target gets its own outbox row per
 * record and action (the 2.6.97 unique key includes todoo_mode), so each has its own retries, its own
 * idempotency and its own close on void.
 *
 *   resume  an hr.resume.line on the employee's résumé (Phase 5, the default; marker in the description)
 *   skill   an hr.employee.skill of a CERTIFICATION skill type (valid_from = completed, valid_to = expiry);
 *           only for courses/achievements mapped to an Odoo skill (training_odoo_map.tomap_odoo_skill_id)
 *   note    an INTERNAL NOTE (message_post, subtype mail.mt_note, no recipients, followers skipped) in the
 *           employee's chatter: nobody is e-mailed or notified, followers included (Pusher::noteArgs; Odoo 19+);
 *           a void posts a short follow-up note, never an edit
 *   badge   a gamification.badge.user (Odoo's own "Badges" tab on the employee), ACHIEVEMENTS ONLY (never
 *           courses) - one Odoo badge per achievement, named after it, created the first time it is earned
 *           and reused after. No validity period and no per-record mapping to set up (unlike skill, which
 *           needs an admin-chosen certification type first). Never revoked on a void: Odoo badges are a
 *           one-time award, not a certification with an expiry (2.6.190).
 *
 * Settings: tauto_odoo_send_resume / _skill / _note / _badge (2.6.97 for the first three, 2.6.190 for badge).
 * Before 2.6.97 only the résumé line exists (the Phase 5 behaviour) and the others cannot be switched on.
 */
final class Targets
{
    public const MODES = ['resume', 'skill', 'note', 'badge'];

    public const LABELS = [
        'resume' => 'Résumé line',
        'skill' => 'Certification skill',
        'note' => 'HR note',
        'badge' => 'Badge',
    ];

    /** Plural labels for counts ("2 résumé lines"). */
    public const PLURAL = [
        'resume' => 'résumé lines',
        'skill' => 'certification skills',
        'note' => 'HR notes',
        'badge' => 'badges',
    ];

    /** "sent as …" */
    public const PHRASE = [
        'resume' => 'a résumé line',
        'skill' => 'a certification skill',
        'note' => 'an HR note',
        'badge' => 'a badge',
    ];

    public const COLUMNS = [
        'resume' => 'tauto_odoo_send_resume',
        'skill' => 'tauto_odoo_send_skill',
        'note' => 'tauto_odoo_send_note',
        'badge' => 'tauto_odoo_send_badge',
    ];

    /** The Odoo model each target writes (the outbox's todoo_odoo_model once done). */
    public const MODELS = [
        'resume' => 'hr.resume.line',
        'skill' => 'hr.employee.skill',
        'note' => 'mail.message',
        'badge' => 'gamification.badge.user',
    ];

    /** Is the 2.6.97 schema (per-target switches, per-target unique key) installed? */
    public static function schemaReady(\mysqli $db): bool
    {
        return Schema::hasColumn($db, 'training_automation', 'tauto_odoo_send_skill');
    }

    /**
     * The targets switched on in these settings, in MODES order. Settings loaded before 2.6.97 carry no
     * target columns: then only the résumé line (Phase 5).
     *
     * @return list<string>
     */
    public static function enabled(array $settings): array
    {
        if (!array_key_exists('tauto_odoo_send_skill', $settings) || ($settings['targets_ready'] ?? true) === false) {
            return ['resume'];
        }
        $out = [];
        foreach (self::COLUMNS as $mode => $col) {
            if ((int) ($settings[$col] ?? 0) === 1) {
                $out[] = $mode;
            }
        }
        return $out;
    }

    public static function label(string $mode): string
    {
        return self::LABELS[$mode] ?? $mode;
    }

    public static function valid(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }
}
