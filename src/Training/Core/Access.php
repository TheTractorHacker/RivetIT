<?php

namespace ITFlow\Training\Core;

use ITFlow\Training\Api\ApiException;

/**
 * The ONLY Core class that reads the legacy request globals (spec §0 "Service boundaries").
 *
 * Levels follow the app's role editor for the `module_training` permission: 1 Read,
 * 2 Modify (author), 3 Full; admins are always 3. Every training page and endpoint first
 * requires the module toggle ($config_module_enable_training == 1). No role rows are seeded,
 * so until an admin grants the module only admins see Training.
 */
final class Access
{
    public static function enabled(): bool
    {
        return intval($GLOBALS['config_module_enable_training'] ?? 0) === 1;
    }

    /** The session user's module_training level, 0 when not granted (memoised per request). */
    public static function level(): int
    {
        static $level = null;
        if ($level === null) {
            if (!function_exists('lookupUserPermission')) {
                return 0;
            }
            $raw = lookupUserPermission('module_training');
            $level = $raw === false ? 0 : max(0, min(3, (int) $raw));
        }
        return $level;
    }

    /** Builds the request's Ctx from the logged-in session. */
    public static function ctx(\mysqli $db): Ctx
    {
        $settings = TrainingSettings::fromGlobals();
        $host = (string) ($GLOBALS['config_base_url'] ?? '');
        $host = preg_replace('#^https?://#i', '', trim($host));
        $baseUrl = 'https://' . rtrim((string) $host, '/');

        $keyEnc = $settings->youtubeKeyEnc;
        $keyFn = $keyEnc === null ? null : static function () use ($keyEnc): ?string {
            if (!function_exists('decryptSetting')) {
                return null;
            }
            $plain = decryptSetting($keyEnc);
            return $plain === '' ? null : $plain;
        };

        return new Ctx(
            $db,
            intval($GLOBALS['session_user_id'] ?? 0),
            ($GLOBALS['session_is_admin'] ?? false) === true,
            self::level(),
            $baseUrl,
            $settings,
            Text::clip($_SERVER['HTTP_USER_AGENT'] ?? null, 255),
            $keyFn,
        );
    }

    /**
     * Page guard, called at TOP-LEVEL scope after includes/inc_all.php:
     *
     *   if (\ITFlow\Training\Core\Access::pageGuard(2)) { require_once "../includes/footer.php"; exit; }
     *
     * Renders the empty state and returns 'off' (module disabled) or 'forbidden' (level too
     * low); returns null when the page may render. It never requires the footer itself: the
     * footer reads $tab_title / $page_title / $csp_nonce as bare variables, which would be out
     * of scope inside this method.
     */
    public static function pageGuard(int $min): ?string
    {
        if (!self::enabled()) {
            $actions = '';
            if (($GLOBALS['session_is_admin'] ?? false) === true) {
                $actions = '<a class="btn btn-primary" href="/admin/settings_module.php"><i class="fas fa-cube me-2"></i>Open Modules</a>';
            }
            self::renderGuard('fas fa-hard-hat', 'Training is turned off', 'An administrator can turn it on in Settings › Modules.', $actions);
            return 'off';
        }
        if (self::level() < $min) {
            self::renderGuard('fas fa-lock', "You don't have access to this part of Training.", 'Ask an administrator to grant the Training permission to your role.', '');
            return 'forbidden';
        }
        return null;
    }

    /** JSON endpoints: throws 404 module_disabled or 403 forbidden. */
    public static function api(int $min): void
    {
        if (!self::enabled()) {
            throw new ApiException(404, 'module_disabled', 'Training is turned off.');
        }
        if (self::level() < $min) {
            throw new ApiException(403, 'forbidden', "You don't have access to this part of Training.");
        }
    }

    public static function canUseKb(): bool
    {
        if (intval($GLOBALS['config_module_enable_kb'] ?? 0) !== 1 || !function_exists('lookupUserPermission')) {
            return false;
        }
        $level = lookupUserPermission('module_kb');
        return $level !== false && (int) $level >= 1;
    }

    /** The session user's module_training_kiosk level (Devices & PINs), 0 when not granted; admins resolve to 3 via lookupUserPermission. */
    public static function kioskLevel(): int
    {
        static $l = null;
        if ($l === null) {
            if (!function_exists('lookupUserPermission')) {
                return 0;
            }
            $raw = lookupUserPermission('module_training_kiosk');
            $l = $raw === false ? 0 : max(0, min(3, (int) $raw));
        }
        return $l;
    }

    /**
     * Whether the session user may see the Assets list (Assets or Tickets/assets/docs view; admins always).
     * Device setup's "It's in Assets" path lists asset names, serials, tags and who has each asset, so it
     * needs this on top of Training kiosk Full (security review 2026-09-26). "This device isn't in Assets"
     * doesn't.
     */
    public static function canAssets(): bool
    {
        if (function_exists('itflow_can_assets')) {
            return itflow_can_assets(1);
        }
        return false;
    }

    public const ASSETS_NEEDED = 'Setting up a device from Assets needs view access to Assets. Choose "This device isn\'t in Assets" instead, or ask an administrator.';

    /** JSON endpoints for training devices and PINs: 403 forbidden below $min (the Router already required module_training >= 1). */
    public static function apiKiosk(int $min): void
    {
        if (self::kioskLevel() < $min) {
            throw new ApiException(403, 'forbidden', "You don't have access to training devices and PINs.");
        }
    }

    /**
     * Pure decision behind pageGuardKiosk()/the Router's kiosk routes: null = allowed, 'off' = Training turned off,
     * 'forbidden' = the role lacks module_training_kiosk >= $min. Devices & PINs is its own permission, so a
     * kiosk-only role (module_training_kiosk without any module_training read) is allowed in - the old guard also
     * demanded Training Read, which sent such a role to a page it then could not open.
     */
    public static function kioskDecision(bool $enabled, int $kioskLevel, int $min): ?string
    {
        if (!$enabled) {
            return 'off';
        }
        return $kioskLevel < $min ? 'forbidden' : null;
    }

    /** Devices & PINs page guard: Training on + module_training_kiosk >= $min (no Training Read needed). */
    public static function pageGuardKiosk(int $min): ?string
    {
        $d = self::kioskDecision(self::enabled(), self::kioskLevel(), $min);
        if ($d === null) {
            return null;
        }
        if ($d === 'off') {
            return self::pageGuard($min); // renders the "Training is turned off" card
        }
        self::renderGuard('fas fa-lock', "You don't have access to training devices and PINs.", 'Ask an administrator for the Training kiosk permission.', '');
        return 'forbidden';
    }

    /** JSON gate for the kiosk_admin routes: Training on + module_training_kiosk >= 1; each handler then applies its own apiKiosk() level. */
    public static function apiKioskRoute(): void
    {
        if (!self::enabled()) {
            throw new ApiException(404, 'module_disabled', 'Training is turned off.');
        }
        if (self::kioskLevel() < 1) {
            throw new ApiException(403, 'forbidden', "You don't have access to training devices and PINs.");
        }
    }

    /**
     * KB article visibility for $c's user, as a WHERE fragment over kb_articles columns:
     * [sql, types, params]. Mirrors kbMediaClientAccessOk() (agent/includes/kb_media_auth.php)
     * exactly, plus the archived filter:
     *   - company-wide articles (client_id <= 0) are visible to everyone;
     *   - admins see every department;
     *   - a user with NO user_client_permissions rows sees every department - the app-wide
     *     "no rows = all departments" rule (fail-open by design, load_user_session.php);
     *   - otherwise only departments the user has a row for.
     *
     * @return array{0:string, 1:string, 2:list<int>}
     */
    public static function kbScopeSql(Ctx $c): array
    {
        $sql = "kb_article_archived_at IS NULL AND ("
             . "kb_article_client_id <= 0"
             . " OR ? = 1"
             . " OR NOT EXISTS (SELECT 1 FROM user_client_permissions ucp_any WHERE ucp_any.user_id = ?)"
             . " OR kb_article_client_id IN (SELECT ucp.client_id FROM user_client_permissions ucp WHERE ucp.user_id = ?)"
             . ")";
        return [$sql, 'iii', [$c->isAdmin ? 1 : 0, $c->userId, $c->userId]];
    }

    private static function renderGuard(string $icon, string $title, string $subtitle, string $actionsHtml): void
    {
        echo '<div class="card"><div class="card-body">';
        if (function_exists('render_empty_state')) {
            render_empty_state($icon, $title, $subtitle, $actionsHtml);
        } else {
            echo '<p class="text-muted">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '</div></div>';
    }
}
