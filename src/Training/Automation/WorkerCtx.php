<?php

namespace ITFlow\Training\Automation;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Core\TrainingSettings;

/**
 * Contexts for cron/training_worker.php (spec §3.2). The worker runs as the system: user 0, admin,
 * Training level 3, so P2's Scope::forCtx() gives it every department. forUser() builds the context a
 * digest is computed for, so P2's Scope sees that recipient's admin flag and level.
 *
 * The base URL is 'https://' + the host of config.php's $config_base_url (scheme and trailing slash
 * removed), exactly as Access::ctx() builds it - never a Host header. The YouTube key is a closure
 * over decryptSetting(), as in Access::ctx() (never stored on the object).
 */
final class WorkerCtx
{
    public const USER_AGENT = 'itflow-training-worker';

    public static function build(\mysqli $db, string $configBaseUrl): Ctx
    {
        return self::make($db, $configBaseUrl, 0, true, 3);
    }

    public static function forUser(\mysqli $db, string $configBaseUrl, int $userId, bool $isAdmin, int $level): Ctx
    {
        return self::make($db, $configBaseUrl, max(0, $userId), $isAdmin, max(0, min(3, $level)));
    }

    /** 'https://host' from a config base URL ('host', 'host/', 'https://host/'). */
    public static function baseUrl(string $configBaseUrl): string
    {
        $host = (string) preg_replace('#^https?://#i', '', trim($configBaseUrl));
        return 'https://' . rtrim($host, '/');
    }

    private static function make(\mysqli $db, string $configBaseUrl, int $userId, bool $isAdmin, int $level): Ctx
    {
        $settings = TrainingSettings::fromDb($db);
        $keyEnc = $settings->youtubeKeyEnc;
        $keyFn = $keyEnc === null ? null : static function () use ($keyEnc): ?string {
            if (!function_exists('decryptSetting')) {
                return null;
            }
            try {
                $plain = decryptSetting($keyEnc);
            } catch (\Throwable) {
                return null;
            }
            return ($plain === '' || $plain === null) ? null : (string) $plain;
        };
        return new Ctx($db, $userId, $isAdmin, $level, self::baseUrl($configBaseUrl), $settings, Text::clip(self::USER_AGENT, 255), $keyFn);
    }
}
