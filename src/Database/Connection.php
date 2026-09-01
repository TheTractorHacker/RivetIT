<?php

namespace ITFlow\Database;

/**
 * Thin accessor for the app's existing global $mysqli connection.
 *
 * The legacy app opens one mysqli connection per request in config.php and
 * passes it around via `global $mysqli`. New /src services need the same
 * connection, not a second one - this just gives them a non-global way to
 * reach it without requiring every legacy page to change how it connects.
 */
class Connection
{
    public static function get(): \mysqli
    {
        global $mysqli;

        if (!($mysqli instanceof \mysqli)) {
            throw new \RuntimeException('Database connection not initialized - Connection::get() called before config.php ran.');
        }

        return $mysqli;
    }
}
