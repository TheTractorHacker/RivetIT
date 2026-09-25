<?php

namespace ITFlow\Training\Core;

/**
 * A Ctx for work nobody is logged in for: cron, CLI scripts, the directory sync runner and
 * tests (Phase 2 spec §3.1). Level 3, not an admin, settings from the database.
 *
 * $userId is recorded as the actor where a service stores one (0 = the system). Services
 * choose the ledger actor type themselves (`system` for cron, sync and system reconciles).
 *
 * The base URL comes from config.php's $config_base_url (there is no settings column for
 * it): the $baseHost argument when given, else the global that config.php defines, which every
 * cron and CLI entry point loads. It is normalised exactly like Access::ctx(): scheme and
 * trailing slash removed, then 'https://' prefixed.
 */
final class SystemCtx
{
    public static function make(\mysqli $db, int $userId = 0, string $ua = 'training_system', ?string $baseHost = null): Ctx
    {
        $host = $baseHost ?? (string) ($GLOBALS['config_base_url'] ?? '');
        $host = (string) preg_replace('#^https?://#i', '', trim($host));
        $baseUrl = 'https://' . rtrim($host, '/');

        return new Ctx(
            $db,
            max(0, $userId),
            false,
            3,
            $baseUrl,
            TrainingSettings::fromDb($db),
            Text::clip($ua, 255),
        );
    }
}
