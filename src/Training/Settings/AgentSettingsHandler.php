<?php

namespace ITFlow\Training\Settings;

/**
 * The POST side of agent/training_settings.php: Training settings for Training level 3 without
 * admin (roles audit 2026-09-26, P2). It never goes through admin/post.php.
 *
 * The page validates the CSRF token (validateCSRFToken) before calling handle(). Then, in order:
 *   1  Training on, and module_training >= 3 (admins resolve to 3)
 *   2  one known action, looked up in a fixed list (the same action names as Admin > Training)
 *   3  admin-only actions are refused for everyone - admins included: those save only through
 *      Admin > Training (admin/post.php). So is a post that carries any admin-only field
 *      (media limits, budget, YouTube key, hire-date fill, PIN lockouts, device caps, Odoo PIN).
 *      A refusal writes nothing.
 *   4  kiosk items also need module_training_kiosk >= 3
 *   5  the same schema checks as the admin handlers, then SettingsService with the admin-only
 *      fields off
 * It returns where to go (this page, at the action's section) and the flash for it.
 */
final class AgentSettingsHandler
{
    public const PAGE = '/agent/training_settings.php';

    /** Action => [policy item, section anchor]. Order matters only for a post naming two actions (first wins). */
    private const ACTIONS = [
        'edit_training_settings'            => [SettingsPolicy::DEFAULTS, 'general'],
        'training_ledger_verify'            => [SettingsPolicy::LEDGER_VERIFY, 'ledger'],
        'training_youtube_key_test'         => [SettingsPolicy::YOUTUBE_KEY, 'youtube'],
        'training_media_purge'              => [SettingsPolicy::MEDIA_PURGE, 'media-storage'],
        'edit_training_compliance_settings' => [SettingsPolicy::COMPLIANCE, 'compliance'],
        'training_odoo_link_check'          => [SettingsPolicy::ODOO_LINKS, 'odoo'],
        'training_odoo_accept_target'       => [SettingsPolicy::ODOO_LINKS, 'odoo'],
        'training_odoo_link_relink'         => [SettingsPolicy::ODOO_LINKS, 'odoo'],
        'training_odoo_link_unlink'         => [SettingsPolicy::ODOO_LINKS, 'odoo'],
        'training_odoo_link_confirm'        => [SettingsPolicy::ODOO_LINKS, 'odoo'],
        'training_reconcile_now'            => [SettingsPolicy::MAINTENANCE, 'maintenance'],
        'training_snapshot_now'             => [SettingsPolicy::MAINTENANCE, 'maintenance'],
        'edit_training_kiosk_settings'      => [SettingsPolicy::KIOSK_SESSIONS, 'kiosk'],
    ];

    /**
     * @param array{user_id:int, name:string, is_admin:bool, training_level:int, kiosk_level:int, module_on:bool, schema_ready:bool} $who
     * @return array{url:string, type:string, message:string}
     */
    public static function handle(\mysqli $db, array $post, array $who): array
    {
        $isAdmin = $who['is_admin'] === true;
        $tLevel = (int) $who['training_level'];
        $kLevel = (int) $who['kiosk_level'];

        if (!$who['module_on']) {
            return self::go('', 'Training is turned off. Nothing was saved.', 'error');
        }
        if ($tLevel < 3) {
            return self::go('', "Nothing was saved. Only people with full Training access can change Training settings.", 'error');
        }

        $action = null;
        foreach (self::ACTIONS as $name => $_) {
            if (isset($post[$name])) {
                $action = $name;
                break;
            }
        }
        if ($action === null) {
            return self::go('', 'Nothing was saved: the request did not say what to change.', 'error');
        }
        [$item, $anchor] = self::ACTIONS[$action];

        // The nightly Odoo sync switch posts as a compliance-settings save with its own section.
        if ($action === 'edit_training_compliance_settings' && (($post['tc_section'] ?? '') === 'odoo_sync')) {
            $item = SettingsPolicy::ODOO_SYNC;
            $anchor = 'odoo-sync';
        }

        // Whole actions that stay in Admin > Training.
        if (SettingsPolicy::adminOnly($item)) {
            return self::refuse($item, $anchor, $isAdmin);
        }

        // Admin-only fields riding along with an allowed action.
        $adminField = self::adminOnlyFieldIn($action, $post);
        if ($adminField !== null) {
            return self::refuse($adminField, $anchor, $isAdmin);
        }

        if (!SettingsPolicy::canOnAgentPage($item, $isAdmin, $tLevel, $kLevel)) {
            // Only the kiosk items can get here (everything else needs Training 3, checked above).
            return self::go($anchor, 'Nothing was saved. ' . SettingsPolicy::label($item)
                . ' also need the Training kiosk permission at Full. Ask an administrator.', 'error');
        }

        $service = new SettingsService($db, (int) $who['user_id'], (string) $who['name'], 'training settings');

        switch ($action) {
            case 'edit_training_settings':
            case 'training_ledger_verify':
                if (!$who['schema_ready']) {
                    return self::go($anchor, 'Run the database update first: the Training tables are not installed yet.', 'error');
                }
                $out = $action === 'edit_training_settings' ? $service->saveGeneral($post, false) : $service->verifyLedger();
                break;
            case 'edit_training_compliance_settings':
            case 'training_reconcile_now':
            case 'training_snapshot_now':
                if (!$service->complianceSchemaReady((bool) $who['schema_ready'])) {
                    return self::go($anchor, 'Run the database update first: the Training compliance tables are not installed yet.', 'error');
                }
                $out = match ($action) {
                    'edit_training_compliance_settings' => $service->saveComplianceDefaults($post, false),
                    'training_reconcile_now' => $service->reconcileNow(),
                    default => $service->snapshotNow(),
                };
                break;
            case 'edit_training_kiosk_settings':
                $out = $service->saveKiosk($post, false);
                break;
            default:
                // Unreachable: every other action is admin-only and refused above.
                return self::go('', 'Nothing was saved.', 'error');
        }

        return self::go($anchor, $out['message'], $out['type']);
    }

    /** The first admin-only item whose fields appear in this post, or null. Presence alone refuses. */
    private static function adminOnlyFieldIn(string $action, array $post): ?string
    {
        if ($action === 'edit_training_settings') {
            foreach (SettingsPolicy::GENERAL_ADMIN_FIELDS as $f) {
                if (array_key_exists($f, $post)) {
                    return str_contains($f, 'youtube') ? SettingsPolicy::YOUTUBE_KEY : SettingsPolicy::MEDIA_LIMITS;
                }
            }
        } elseif ($action === 'edit_training_compliance_settings') {
            if (array_key_exists('config_training_hire_fill_since', $post)) {
                return SettingsPolicy::HIRE_FILL;
            }
            if (array_key_exists('config_training_odoo_sync_enabled', $post)) {
                return SettingsPolicy::ODOO_SYNC;
            }
        } elseif ($action === 'edit_training_kiosk_settings') {
            foreach ([SettingsPolicy::PIN_LOCKOUTS, SettingsPolicy::DEVICE_CAPS] as $item) {
                foreach (SettingsPolicy::KIOSK_COLUMNS[$item] as $f) {
                    if (array_key_exists($f, $post)) {
                        return $item;
                    }
                }
            }
            if (array_key_exists(\ITFlow\Training\Kiosk\Core\KioskSettings::SWITCH, $post)) {
                return SettingsPolicy::ODOO_PIN;
            }
        }
        return null;
    }

    private static function refuse(string $item, string $anchor, bool $isAdmin): array
    {
        $what = SettingsPolicy::label($item);
        $msg = $isAdmin
            ? "Nothing was saved. Admin only: $what. Change it in Admin › Training."
            : "Nothing was saved. Admin only: $what. Ask an administrator.";
        return self::go($anchor, $msg, 'error');
    }

    private static function go(string $anchor, string $message, string $type): array
    {
        return ['url' => self::PAGE . ($anchor !== '' ? '#' . $anchor : ''), 'type' => $type, 'message' => $message];
    }
}
