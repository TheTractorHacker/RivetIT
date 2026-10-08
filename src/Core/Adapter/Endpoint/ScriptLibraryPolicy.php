<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

/**
 * Who may change what the script library holds (pentest IT-10). A saved script runs as SYSTEM on endpoints and a level-2 technician
 * may run saved scripts, so letting level 2 also write or overwrite script bodies bypassed the level-3 gate on free-form scripts.
 * Writing, editing and importing script bodies now needs the same level as running a free-form script. Running stays at level 2.
 */
final class ScriptLibraryPolicy
{
    /** module_rmm_scripts level needed to create, edit or import a script body (the level that may run free-form scripts). */
    public const WRITE_LEVEL = 3;

    public static function canWriteBodies(int $level): bool
    {
        return $level >= self::WRITE_LEVEL;
    }

    /** SHA-256 of a script body, logged on every create/edit/import so a changed body is attributable without storing the body in the log. */
    public static function bodyHash(string $body): string
    {
        return hash('sha256', $body);
    }
}
