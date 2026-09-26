<?php

namespace ITFlow\Training\Settings;

/**
 * Who may change which Training setting (roles audit 2026-09-26, P2 table).
 *
 *   Training 3                 course defaults (languages, pass mark, attempts, attestation text);
 *                              compliance defaults (due soon, reissue, reopen window, target %,
 *                              evidence scan size); Recalculate assignments; Capture snapshot;
 *                              records ledger Verify now
 *   Training 3 + Kiosk 3       kiosk session timeouts; setup-slip validity
 *   Admin only                 media limits and budget; the YouTube key (save, clear, test); media
 *                              purge; Odoo employee links (Check now, accept target, relink, unlink,
 *                              confirm); the nightly Odoo sync switch; the Odoo hire-date fill; the
 *                              Odoo PIN sign-in switch; PIN lockouts; device and system caps
 *
 * Admins always resolve to Training 3 / Kiosk 3 (lookupUserPermission), so they may change
 * everything. The admin-only items are saved only through Admin > Training (admin/post.php,
 * admins only); the agent page agent/training_settings.php shows them read-only and its handler
 * (AgentSettingsHandler) refuses them for everyone, admins included.
 */
final class SettingsPolicy
{
    public const DEFAULTS = 'defaults';
    public const MEDIA_LIMITS = 'media_limits';
    public const YOUTUBE_KEY = 'youtube_key';
    public const MEDIA_PURGE = 'media_purge';
    public const COMPLIANCE = 'compliance';
    public const HIRE_FILL = 'hire_fill';
    public const MAINTENANCE = 'maintenance';
    public const LEDGER_VERIFY = 'ledger_verify';
    public const ODOO_LINKS = 'odoo_links';
    public const ODOO_SYNC = 'odoo_sync';
    public const KIOSK_SESSIONS = 'kiosk_sessions';
    public const SETUP_SLIPS = 'setup_slips';
    public const PIN_LOCKOUTS = 'pin_lockouts';
    public const DEVICE_CAPS = 'device_caps';
    public const ODOO_PIN = 'odoo_pin';

    public const NEEDS_TRAINING = 'training3';
    public const NEEDS_KIOSK = 'training3_kiosk3';
    public const NEEDS_ADMIN = 'admin';

    /** item => what it needs, and a plain name for messages ("... can only be changed by an administrator"). */
    private const ITEMS = [
        self::DEFAULTS       => [self::NEEDS_TRAINING, 'Course defaults'],
        self::MEDIA_LIMITS   => [self::NEEDS_ADMIN, 'Media limits and the media budget'],
        self::YOUTUBE_KEY    => [self::NEEDS_ADMIN, 'The YouTube Data API key'],
        self::MEDIA_PURGE    => [self::NEEDS_ADMIN, 'Purging unreferenced media'],
        self::COMPLIANCE     => [self::NEEDS_TRAINING, 'Compliance defaults'],
        self::HIRE_FILL      => [self::NEEDS_ADMIN, 'The Odoo hire-date fill'],
        self::MAINTENANCE    => [self::NEEDS_TRAINING, 'Recalculating assignments and capturing snapshots'],
        self::LEDGER_VERIFY  => [self::NEEDS_TRAINING, 'Verifying the records ledger'],
        self::ODOO_LINKS     => [self::NEEDS_ADMIN, 'Odoo employee links'],
        self::ODOO_SYNC      => [self::NEEDS_ADMIN, 'The nightly Odoo directory sync'],
        self::KIOSK_SESSIONS => [self::NEEDS_KIOSK, 'Kiosk session timeouts'],
        self::SETUP_SLIPS    => [self::NEEDS_KIOSK, 'Setup-slip validity'],
        self::PIN_LOCKOUTS   => [self::NEEDS_ADMIN, 'PIN lockouts'],
        self::DEVICE_CAPS    => [self::NEEDS_ADMIN, 'Device and system caps'],
        self::ODOO_PIN       => [self::NEEDS_ADMIN, 'Odoo PIN sign-in'],
    ];

    /** The General & media form fields only an admin may send (media limits, budget, YouTube key). */
    public const GENERAL_ADMIN_FIELDS = [
        'config_training_video_max_mb', 'config_training_pdf_max_mb', 'config_training_pdf_max_pages',
        'config_training_image_max_mb', 'config_training_file_max_mb', 'config_training_media_budget_mb',
        'config_training_youtube_api_key', 'training_youtube_key_clear',
    ];

    /** Kiosk columns by item (KioskSettings::RANGES keys; the breaker counter is runtime state, never a setting). */
    public const KIOSK_COLUMNS = [
        self::KIOSK_SESSIONS => [
            'config_training_kiosk_idle_s', 'config_training_trainer_idle_s', 'config_training_checkin_idle_s',
            'config_training_learner_max_minutes', 'config_training_trainer_max_minutes',
        ],
        self::PIN_LOCKOUTS => [
            'config_training_pin_soft_failures', 'config_training_pin_lock_minutes', 'config_training_pin_hard_failures',
        ],
        self::DEVICE_CAPS => [
            'config_training_kiosk_fail_cap', 'config_training_global_fail_cap', 'config_training_kiosk_fail_cap_24h',
            'config_training_global_fail_cap_24h', 'config_training_kiosk_distinct_cap_24h', 'config_training_kiosk_search_per_min',
        ],
        self::SETUP_SLIPS => ['config_training_setup_code_days'],
    ];

    public static function needs(string $item): string
    {
        if (!isset(self::ITEMS[$item])) {
            throw new \InvalidArgumentException("SettingsPolicy: unknown item $item");
        }
        return self::ITEMS[$item][0];
    }

    public static function label(string $item): string
    {
        if (!isset(self::ITEMS[$item])) {
            throw new \InvalidArgumentException("SettingsPolicy: unknown item $item");
        }
        return self::ITEMS[$item][1];
    }

    public static function adminOnly(string $item): bool
    {
        return self::needs($item) === self::NEEDS_ADMIN;
    }

    /** May this person change $item at all (on either page)? */
    public static function can(string $item, bool $isAdmin, int $trainingLevel, int $kioskLevel): bool
    {
        if ($isAdmin) {
            return true;
        }
        return match (self::needs($item)) {
            self::NEEDS_TRAINING => $trainingLevel >= 3,
            self::NEEDS_KIOSK => $trainingLevel >= 3 && $kioskLevel >= 3,
            default => false,
        };
    }

    /** May this person change $item on the agent page (never an admin-only item)? */
    public static function canOnAgentPage(string $item, bool $isAdmin, int $trainingLevel, int $kioskLevel): bool
    {
        return !self::adminOnly($item) && self::can($item, $isAdmin, $trainingLevel, $kioskLevel);
    }

    /** The kiosk columns the agent page may write: sessions + setup slips. */
    public static function agentKioskColumns(): array
    {
        return array_merge(self::KIOSK_COLUMNS[self::KIOSK_SESSIONS], self::KIOSK_COLUMNS[self::SETUP_SLIPS]);
    }
}
