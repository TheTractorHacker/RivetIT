<?php

namespace ITFlow\Recovery;

/**
 * The restore drill's verification rules as pure functions (no database, no files), so every rule is unit-tested and the
 * orchestrator (RestoreDrill) only gathers facts and hands them here. A check is
 * ['id' => string, 'label' => string, 'status' => pass|warn|fail|skip, 'detail' => string].
 */
final class DrillVerifier
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    /** Absolute row slack so a 3-row table that gained one row is not a "33%" failure. */
    public const MIN_SLACK_ROWS = 2;

    public static function check(string $id, string $label, string $status, string $detail): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /**
     * Reads version.txt as written by build_backup().
     *
     * @return array{generated:?string,version:?string,db_version:?string,sha_db:?string,sha_uploads:?string,ledger_seq:?int,ledger_hash:?string}
     */
    public static function parseVersionTxt(string $txt): array
    {
        $out = ['generated' => null, 'version' => null, 'db_version' => null, 'sha_db' => null, 'sha_uploads' => null, 'ledger_seq' => null, 'ledger_hash' => null];
        $m = [];
        if (preg_match('/^Generated:\s*(.+)$/mi', $txt, $m)) {
            $out['generated'] = trim($m[1]);
        }
        if (preg_match('/^DB Version:\s*(\S+)/mi', $txt, $m) && $m[1] !== 'N/A') {
            $out['db_version'] = $m[1];
        }
        if (preg_match('/^[^\n:]*Version:\s*(\S+)/mi', $txt, $m) && !preg_match('/^DB\b/i', $m[0])) {
            $out['version'] = $m[1];
        }
        if (preg_match('/^SHA256 db\.sql:\s*([0-9a-f]{64})/mi', $txt, $m)) {
            $out['sha_db'] = strtolower($m[1]);
        }
        if (preg_match('/^SHA256 uploads\.zip:\s*([0-9a-f]{64})/mi', $txt, $m)) {
            $out['sha_uploads'] = strtolower($m[1]);
        }
        if (preg_match('/^Training ledger head:\s*#(\d+)\s+([0-9a-f]{64})/mi', $txt, $m)) {
            $out['ledger_seq'] = (int) $m[1];
            $out['ledger_hash'] = strtolower($m[2]);
        }

        return $out;
    }

    /** SHA-256 of db.sql and uploads.zip against version.txt. A null actual means the file was not available. */
    public static function checkChecksums(array $parsed, ?string $actualDb, ?string $actualUploads): array
    {
        $out = [];
        if ($parsed['sha_db'] === null) {
            $out[] = self::check('sha_db', 'db.sql checksum', self::SKIP, 'this archive carries no db.sql checksum');
        } elseif ($actualDb === null) {
            $out[] = self::check('sha_db', 'db.sql checksum', self::FAIL, 'db.sql could not be read from the archive');
        } elseif (hash_equals($parsed['sha_db'], strtolower($actualDb))) {
            $out[] = self::check('sha_db', 'db.sql checksum', self::PASS, 'matches version.txt (' . substr($actualDb, 0, 12) . ')');
        } else {
            $out[] = self::check('sha_db', 'db.sql checksum', self::FAIL, 'db.sql hashes to ' . substr($actualDb, 0, 12) . ' but version.txt says ' . substr($parsed['sha_db'], 0, 12));
        }
        if ($parsed['sha_uploads'] === null) {
            $out[] = self::check('sha_uploads', 'uploads.zip checksum', self::SKIP, 'this archive carries no uploads.zip checksum');
        } elseif ($actualUploads === null) {
            $out[] = self::check('sha_uploads', 'uploads.zip checksum', self::FAIL, 'uploads.zip could not be read from the archive');
        } elseif (hash_equals($parsed['sha_uploads'], strtolower($actualUploads))) {
            $out[] = self::check('sha_uploads', 'uploads.zip checksum', self::PASS, 'matches version.txt (' . substr($actualUploads, 0, 12) . ')');
        } else {
            $out[] = self::check('sha_uploads', 'uploads.zip checksum', self::FAIL, 'uploads.zip hashes to ' . substr($actualUploads, 0, 12) . ' but version.txt says ' . substr($parsed['sha_uploads'], 0, 12));
        }

        return $out;
    }

    /** The restored settings row must be at the schema version version.txt recorded. $live is informational (an older backup is normal). */
    public static function checkSchemaVersion(?string $backupVersion, ?string $restoredVersion, ?string $liveVersion): array
    {
        if ($restoredVersion === null || $restoredVersion === '') {
            return self::check('schema_version', 'Schema version', self::FAIL, 'the restored database has no settings row / schema version');
        }
        if ($backupVersion === null) {
            // deploy/backup.sh archives carry no version.txt: judge the restored settings row against the live database instead.
            if ($liveVersion === null || $liveVersion === '') {
                return self::check('schema_version', 'Schema version', self::SKIP, "restored schema is $restoredVersion; this archive records no version to compare");
            }
            $cmp = version_compare($restoredVersion, $liveVersion);

            return $cmp === 0
                ? self::check('schema_version', 'Schema version', self::PASS, "restored database is at $restoredVersion, the same as live")
                : ($cmp < 0
                    ? self::check('schema_version', 'Schema version', self::PASS, "restored database is at $restoredVersion (live is $liveVersion; update_db brings a restore forward)")
                    : self::check('schema_version', 'Schema version', self::WARN, "restored database is at $restoredVersion, newer than live $liveVersion"));
        }
        if ($backupVersion !== $restoredVersion) {
            return self::check('schema_version', 'Schema version', self::FAIL, "version.txt says $backupVersion but the restored settings say $restoredVersion");
        }
        $note = $liveVersion !== null && $liveVersion !== '' && $liveVersion !== $restoredVersion ? " (live is $liveVersion; update_db brings a restore forward)" : '';

        return self::check('schema_version', 'Schema version', self::PASS, "restored database is at $restoredVersion$note");
    }

    /**
     * Table list restored vs the live database. A table the live database has but the restore lacks is a failure when the schema
     * versions match (nothing could legitimately differ) and a warning when the backup is older than live.
     *
     * @param list<string> $restored
     * @param list<string> $live
     */
    public static function checkTables(array $restored, array $live, bool $sameSchema): array
    {
        $missing = array_values(array_diff($live, $restored));
        $extra = array_values(array_diff($restored, $live));
        if ($restored === []) {
            return self::check('tables', 'Table list', self::FAIL, 'no tables were restored');
        }
        $detail = count($restored) . ' tables restored, ' . count($live) . ' in the live database';
        if ($missing === []) {
            return self::check('tables', 'Table list', self::PASS, $detail . ($extra ? '; ' . count($extra) . ' in the backup only (' . self::sample($extra) . ')' : ''));
        }

        return self::check('tables', 'Table list', $sameSchema ? self::FAIL : self::WARN,
            $detail . '; missing from the restore: ' . self::sample($missing) . ($sameSchema ? '' : ' (backup is from an older schema)'));
    }

    public static function withinTolerance(int $expected, int $actual, float $tolerancePct, int $slack = self::MIN_SLACK_ROWS): bool
    {
        if ($expected > $slack && $actual === 0) {
            return false;   // an emptied table is never "close enough"
        }
        $allowed = max($slack, (int) ceil($expected * $tolerancePct / 100));

        return abs($actual - $expected) <= $allowed;
    }

    /**
     * Row counts of the restored core tables against the exact counts stored in the backup.
     *
     * @param array<string,int> $expected core_counts from table-snapshot.json
     * @param array<string,int> $actual   COUNT(*) from the scratch database (a table missing from the restore is absent here)
     */
    public static function checkRowCounts(array $expected, array $actual, float $tolerancePct = 5.0): array
    {
        if ($expected === []) {
            return self::check('row_counts', 'Row counts', self::SKIP, 'this backup has no table snapshot to compare against');
        }
        $bad = [];
        foreach ($expected as $table => $e) {
            if (!array_key_exists($table, $actual)) {
                $bad[] = "$table missing";
            } elseif (!self::withinTolerance((int) $e, (int) $actual[$table], $tolerancePct)) {
                $bad[] = "$table has {$actual[$table]} rows, backup recorded $e";
            }
        }
        $n = count($expected);
        if ($bad === []) {
            return self::check('row_counts', 'Row counts', self::PASS, "$n core tables within {$tolerancePct}% of the snapshot");
        }

        return self::check('row_counts', 'Row counts', self::FAIL, count($bad) . " of $n core tables off: " . implode('; ', array_slice($bad, 0, 6)));
    }

    /**
     * @param array{seq:?int,hash:?string}|null $anchor  ledger head recorded in version.txt (outside the database)
     * @param array{seq:int,hash:string}|null   $head    head row in the restored database
     * @param array<string,mixed>|null          $walk    LedgerVerifier::verify() result run against the scratch database
     */
    public static function checkLedger(?array $anchor, ?array $head, ?array $walk): array
    {
        if ($head === null) {
            return self::check('ledger', 'Training ledger chain', self::SKIP, 'no training ledger in this database');
        }
        if ($walk !== null && empty($walk['ok'])) {
            if (!empty($walk['breaks'])) {
                $b = $walk['breaks'][0];

                return self::check('ledger', 'Training ledger chain', self::FAIL, 'chain break (' . ($b['kind'] ?? '?') . ') at #' . ($b['seq'] ?? '?') . ': ' . ($b['detail'] ?? ''));
            }

            return self::check('ledger', 'Training ledger chain', self::WARN, 'chain walk did not finish inside its time budget (' . (int) ($walk['checked'] ?? 0) . ' events checked, no break found)');
        }
        if ($anchor !== null && $anchor['seq'] !== null) {
            if ($head['seq'] !== $anchor['seq'] || strtolower($head['hash']) !== strtolower((string) $anchor['hash'])) {
                return self::check('ledger', 'Training ledger chain', self::FAIL, "restored head is #{$head['seq']}/" . substr($head['hash'], 0, 12) . " but the backup recorded #{$anchor['seq']}/" . substr((string) $anchor['hash'], 0, 12));
            }

            return self::check('ledger', 'Training ledger chain', self::PASS, "chain verified to head #{$head['seq']}, equal to the head recorded outside the database");
        }

        return self::check('ledger', 'Training ledger chain', self::PASS, "chain verified to head #{$head['seq']} (this archive records no outside anchor)");
    }

    /** $found: was an encrypted value present to test; $plain: what decrypting it returned. */
    public static function checkSecret(bool $found, ?string $plain, ?string $column = null): array
    {
        if (!$found) {
            return self::check('secret', 'Sample secret decrypts', self::SKIP, 'no encrypted setting is stored yet, nothing to test');
        }
        if ($plain === null || $plain === '') {
            return self::check('secret', 'Sample secret decrypts', self::FAIL, 'a stored secret' . ($column ? " ($column)" : '') . ' did not decrypt with the current key - a restore would lose the stored credentials; check the settings key / key escrow');
        }

        return self::check('secret', 'Sample secret decrypts', self::PASS, 'a stored secret' . ($column ? " ($column)" : '') . ' decrypted with the current key');
    }

    /**
     * The settings-key manifest of the backup against the key that is available to this run.
     *
     * $m: ['state' => 'none'|'undecryptable'|'ok', 'fingerprint' => ?string, 'key' => ?string] read from the archive's manifest
     *     (state "undecryptable" = the manifest is encrypted and the passphrase available to this run did not open it).
     * $suppliedKey: key from a deploy/backup.sh .settings-key file (null when none was supplied). $liveKey: this installation's key.
     * $fp: fingerprint function (backup_settings_key_fingerprint).
     */
    public static function checkKey(array $m, ?string $suppliedKey, string $liveKey, callable $fp): array
    {
        $label = 'Settings key for this backup';
        $state = $m['state'] ?? 'none';
        if ($state === 'none') {
            return self::check('key', $label, self::SKIP, 'this archive carries no key manifest');
        }
        if ($state === 'undecryptable') {
            return self::check('key', $label, self::FAIL, 'the key manifest could not be opened with the backup passphrase available to this run - a restore needs the right passphrase; check it against your escrow copy');
        }
        $backupFp = (string) ($m['fingerprint'] ?? '');
        if (($m['key'] ?? '') !== '') {
            $backupFp = $fp((string) $m['key']);
        }
        if ($backupFp === '') {
            return self::check('key', $label, self::SKIP, 'the backup was taken without a settings key');
        }
        if ($suppliedKey !== null && $suppliedKey !== '') {
            return hash_equals($backupFp, $fp($suppliedKey))
                ? self::check('key', $label, self::PASS, 'the supplied settings-key file matches this archive (fingerprint ' . $backupFp . ')')
                : self::check('key', $label, self::FAIL, 'the supplied settings-key file does NOT match this archive (fingerprint ' . $backupFp . ') - this backup could not be restored with it');
        }
        if ($liveKey === '') {
            return self::check('key', $label, self::WARN, 'no settings key available to compare (fingerprint ' . $backupFp . ')');
        }
        if (hash_equals($backupFp, $fp($liveKey))) {
            return self::check('key', $label, self::PASS, ($m['key'] ?? '') !== '' ? 'the key stored in the encrypted manifest matches the current settings key' : 'the current settings key matches the archive fingerprint');
        }

        return self::check('key', $label, self::WARN, 'the archive was made with a different settings key than this installation uses now (key rotated or restored elsewhere); keep the matching key');
    }

    public static function checkUploads(?int $entries, int $sampled, int $badSamples, bool $liveHasFiles): array
    {
        if ($entries === null) {
            return self::check('uploads', 'Uploads archive', self::FAIL, 'uploads.zip could not be opened as a zip archive');
        }
        if ($badSamples > 0) {
            return self::check('uploads', 'Uploads archive', self::FAIL, "$badSamples of $sampled sampled files failed their CRC check");
        }
        if ($entries === 0) {
            return $liveHasFiles
                ? self::check('uploads', 'Uploads archive', self::WARN, 'uploads.zip is empty but the live uploads folder has files')
                : self::check('uploads', 'Uploads archive', self::PASS, 'uploads.zip is empty (the live uploads folder has no files either)');
        }

        return self::check('uploads', 'Uploads archive', self::PASS, "$entries files; $sampled sampled and CRC-verified");
    }

    /** fail beats warn beats pass; skip counts for nothing. An empty list is a fail (nothing was proven). */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if ($statuses === [] || in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }
        if (!in_array(self::PASS, $statuses, true)) {
            return self::FAIL;
        }

        return in_array(self::WARN, $statuses, true) ? self::WARN : self::PASS;
    }

    public static function summary(array $checks): string
    {
        $n = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($checks as $c) {
            $n[$c['status']] = ($n[$c['status']] ?? 0) + 1;
        }
        $failed = array_map(fn ($c) => $c['label'], array_filter($checks, fn ($c) => $c['status'] === self::FAIL));
        $s = "{$n['pass']} passed, {$n['warn']} warnings, {$n['fail']} failed, {$n['skip']} skipped";

        return $failed ? $s . ' (failed: ' . implode(', ', $failed) . ')' : $s;
    }

    /**
     * Compliance rule "restore tested in the last N days". A drill that passed (or passed with warnings) inside the window proves it;
     * everything else (none, only failures, too old) does not.
     *
     * @return array{state:string,summary:string,detail:?string} state: pass | fail
     */
    public static function restoreTestedState(?float $ageDaysOfLastGood, ?string $lastStatus, ?float $lastRestoreSeconds, int $maxDays, bool $drillEnabled): array
    {
        if ($ageDaysOfLastGood !== null && $ageDaysOfLastGood <= $maxDays) {
            $rto = $lastRestoreSeconds !== null ? ' Measured restore time ' . self::duration($lastRestoreSeconds) . '.' : '';

            return ['state' => 'pass', 'summary' => 'A restore was proven ' . self::days($ageDaysOfLastGood) . ' ago.', 'detail' => trim("The newest backup was restored into a scratch database and verified.$rto")];
        }
        if ($ageDaysOfLastGood !== null) {
            return ['state' => 'fail', 'summary' => 'The last proven restore was ' . self::days($ageDaysOfLastGood) . " ago (limit $maxDays days).",
                'detail' => $drillEnabled ? 'The drill is enabled but has not passed recently: open Admin > Backup > Restore drill.' : 'Enable the nightly restore drill on Admin > Backup.'];
        }
        if ($lastStatus !== null && $lastStatus !== 'not_configured') {
            return ['state' => 'fail', 'summary' => "No restore drill has passed (latest result: $lastStatus).", 'detail' => 'Open Admin > Backup > Restore drill for the failed checks.'];
        }

        return ['state' => 'fail', 'summary' => 'No restore has ever been tested.', 'detail' => 'Set up the scoped drill account and enable the nightly restore drill on Admin > Backup (see docs/RECOVERY_RUNBOOK.md).'];
    }

    private static function days(float $d): string
    {
        return $d < 1 ? 'less than a day' : (int) round($d) . ' day' . ((int) round($d) === 1 ? '' : 's');
    }

    public static function duration(float $seconds): string
    {
        if ($seconds < 90) {
            return round($seconds, 1) . ' s';
        }

        return $seconds < 5400 ? round($seconds / 60, 1) . ' min' : round($seconds / 3600, 1) . ' h';
    }

    /** @param list<string> $names */
    private static function sample(array $names): string
    {
        return implode(', ', array_slice($names, 0, 5)) . (count($names) > 5 ? ', +' . (count($names) - 5) . ' more' : '');
    }
}
