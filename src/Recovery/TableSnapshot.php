<?php

namespace ITFlow\Recovery;

/**
 * The table snapshot stored inside every backup (zip entry table-snapshot.json; tar member for deploy/backup.sh archives):
 * for every table the SHOW TABLE STATUS row estimate and size, and an exact COUNT(*) for the core tables. The restore drill
 * compares the scratch copy against it (tolerance 5%), which is what proves rows actually came back, not just tables.
 *
 * It holds table names and counts only - no row data and no secrets - so it is stored in clear text next to version.txt, never
 * inside the (possibly passphrase-encrypted) manifest, where the drill would need the passphrase just to read a number.
 * The builder is deliberately a separate small function: build_backup_manifest() belongs to the security work.
 */
final class TableSnapshot
{
    public const VERSION = 1;
    public const ENTRY = 'table-snapshot.json';

    /** Core tables whose exact row count is recorded and checked (a table missing from an install is skipped). */
    public const CORE_TABLES = [
        'clients', 'contacts', 'users', 'user_roles', 'tickets', 'ticket_replies', 'assets', 'invoices', 'payments', 'expenses',
        'quotes', 'products', 'documents', 'files', 'credentials', 'domains', 'certificates', 'software', 'locations', 'networks',
        'vendors', 'training_completions', 'companies', 'settings',
    ];

    /** @return array<string,mixed> */
    public static function build(\mysqli $db, ?array $coreTables = null): array
    {
        $tables = [];
        $res = $db->query('SHOW TABLE STATUS');
        while ($res && ($row = $res->fetch_assoc())) {
            if (($row['Engine'] ?? null) === null) {
                continue;   // a view
            }
            $tables[(string) $row['Name']] = ['rows_est' => (int) ($row['Rows'] ?? 0), 'data_bytes' => (int) ($row['Data_length'] ?? 0)];
        }
        if ($res) {
            $res->close();
        }
        $core = [];
        foreach ($coreTables ?? self::CORE_TABLES as $t) {
            if (!isset($tables[$t]) || !preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                continue;
            }
            $c = $db->query("SELECT COUNT(*) AS c FROM `$t`");
            $row = $c ? $c->fetch_assoc() : null;
            if ($row) {
                $core[$t] = (int) $row['c'];
            }
        }
        $dbRow = $db->query('SELECT DATABASE() AS d')?->fetch_assoc();

        return ['snapshot_version' => self::VERSION, 'taken_at' => gmdate('Y-m-d\TH:i:s\Z'), 'database' => $dbRow['d'] ?? null,
            'tables' => $tables, 'core_counts' => $core];
    }

    /** Writes the snapshot to a private temp file and returns its path, or null if it cannot be built (the backup then simply has none). */
    public static function toTempFile(\mysqli $db, string $prefix): ?string
    {
        try {
            $json = json_encode(self::build($db), JSON_UNESCAPED_SLASHES);
            $file = tempnam(sys_get_temp_dir(), $prefix . '_snap_');
            if ($json === false || $file === false) {
                return null;
            }
            @chmod($file, 0600);
            file_put_contents($file, $json);

            return $file;
        } catch (\Throwable $e) {
            error_log('TableSnapshot: ' . $e->getMessage());

            return null;
        }
    }
}
