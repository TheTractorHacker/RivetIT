<?php

declare(strict_types=1);

namespace ITFlow\Crypto;

/**
 * DB update 2.6.160: make every column that holds an encrypted value wide enough for the v3 envelope (about 28 bytes plus the key id plus
 * base64 more than the ENC2 form, and the vault fields much more than the old 16 + base64). Idempotent: a column that is wide enough, or
 * not there, is left alone; nothing is ever narrowed. Existing data is untouched (a MODIFY to a wider type of the same kind).
 *
 * A nullable varchar becomes TEXT (the settings table is close to the row size limit, TEXT keeps the data off the row); a NOT NULL
 * varchar with a default stays a varchar, widened to 512, so an INSERT that omits it keeps working.
 */
final class SchemaWidening
{
    /** A varchar/char narrower than this is widened. */
    public const MIN_CHARS = 512;

    /** @var list<array{string,string,int}> vault columns: table, column, target bytes (varbinary) or chars (varchar) */
    private const VAULT = [
        ['credentials', 'credential_username', 1024],
        ['credentials', 'credential_password', 2048],
        ['credentials', 'credential_otp_secret', 1024],
        ['credential_restore_staging', 'credential_username', 1024],
        ['credential_restore_staging', 'credential_password', 2048],
        ['credential_versions', 'version_previous_username_enc', 2048],
        ['credential_versions', 'version_previous_password_enc', 2048],
    ];

    /**
     * @return array<string,string> "table.column" => what was done ("TEXT", "VARCHAR(512)", "VARBINARY(2048)")
     */
    public static function run(\mysqli $db): array
    {
        $done = [];
        foreach (ColumnRegistry::all() as $spec) {
            $r = self::widenText($db, $spec->table, $spec->column);
            if ($r !== null) {
                $done[$spec->table . '.' . $spec->column] = $r;
            }
        }
        // Wrapped DEK / user wraps / API key wraps (vw3: with its KDF header is longer than the V2: form).
        foreach ([['users', 'user_specific_encryption_ciphertext'], ['api_keys', 'api_key_decrypt_hash']] as [$t, $c]) {
            $r = self::widenText($db, $t, $c, 1024);
            if ($r !== null) {
                $done["$t.$c"] = $r;
            }
        }
        foreach (self::VAULT as [$t, $c, $size]) {
            $r = self::widenVault($db, $t, $c, $size);
            if ($r !== null) {
                $done["$t.$c"] = $r;
            }
        }

        return $done;
    }

    /** @return array{data_type:string, len:?int, nullable:bool, default:?string, collation:?string, charset:?string}|null */
    private static function describe(\mysqli $db, string $table, string $column): ?array
    {
        $stmt = $db->prepare('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        return [
            'data_type' => strtolower((string) $row['DATA_TYPE']),
            'len' => $row['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int) $row['CHARACTER_MAXIMUM_LENGTH'],
            'nullable' => $row['IS_NULLABLE'] === 'YES',
            'default' => $row['COLUMN_DEFAULT'] === null ? null : (string) $row['COLUMN_DEFAULT'],
            'collation' => $row['COLLATION_NAME'] === null ? null : (string) $row['COLLATION_NAME'],
            'charset' => $row['CHARACTER_SET_NAME'] === null ? null : (string) $row['CHARACTER_SET_NAME'],
        ];
    }

    private static function widenText(\mysqli $db, string $table, string $column, int $min = self::MIN_CHARS): ?string
    {
        $d = self::describe($db, $table, $column);
        if ($d === null || !in_array($d['data_type'], ['varchar', 'char'], true) || ($d['len'] ?? 0) >= $min) {
            return null;
        }
        $cs = $d['charset'] !== null ? ' CHARACTER SET ' . $d['charset'] . ($d['collation'] !== null ? ' COLLATE ' . $d['collation'] : '') : '';
        $hasDefault = $d['default'] !== null && strtoupper($d['default']) !== 'NULL';
        if ($d['nullable'] || !$hasDefault) {
            $type = 'TEXT';
            $null = $d['nullable'] ? ' DEFAULT NULL' : ' NOT NULL';
        } else {
            $type = 'VARCHAR(' . max($min, 512) . ')';
            $dv = trim($d['default'], "'");
            $null = " NOT NULL DEFAULT '" . $db->real_escape_string($dv) . "'";
        }
        $sql = 'ALTER TABLE `' . $table . '` MODIFY `' . $column . '` ' . $type . $cs . $null;
        if (!$db->query($sql)) {
            return null;
        }

        return $type;
    }

    private static function widenVault(\mysqli $db, string $table, string $column, int $target): ?string
    {
        $d = self::describe($db, $table, $column);
        if ($d === null) {
            return null;
        }
        if ($d['data_type'] === 'varbinary' && ($d['len'] ?? 0) < $target) {
            $sql = 'ALTER TABLE `' . $table . '` MODIFY `' . $column . '` VARBINARY(' . $target . ')' . ($d['nullable'] ? ' DEFAULT NULL' : ' NOT NULL');
        } elseif ($d['data_type'] === 'varchar' && ($d['len'] ?? 0) < $target) {
            $cs = $d['charset'] !== null ? ' CHARACTER SET ' . $d['charset'] . ($d['collation'] !== null ? ' COLLATE ' . $d['collation'] : '') : '';
            $sql = 'ALTER TABLE `' . $table . '` MODIFY `' . $column . '` TEXT' . $cs . ($d['nullable'] ? ' DEFAULT NULL' : ' NOT NULL');
            $target = 0;
        } else {
            return null;
        }
        if (!$db->query($sql)) {
            return null;
        }

        return $target > 0 ? 'VARBINARY(' . $target . ')' : 'TEXT';
    }
}
