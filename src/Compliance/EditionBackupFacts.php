<?php

declare(strict_types=1);

namespace ITFlow\Compliance;

use RivetCore\Compliance\Backup\ArtifactCheckedBackupFactsProvider;
use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Compliance\Backup\BackupStore;

/**
 * What the newest SUCCESSFUL backup did, read from the run record (backup_runs, written by the in-app builder and by deploy/backup.sh) and nothing
 * else: not from configuration. Core judges these facts (RivetCore 1.0.0-rc.53 BackupFactsProviderInterface).
 *
 * Encryption is deliberately reported as unknown (null) here: the run record does not say, and the file is the evidence. {@see self::checked()} wraps
 * this in Core's ArtifactCheckedBackupFactsProvider, which reads the first bytes of the newest backup's file and turns "unknown" into encrypted or
 * not encrypted. Never throws, never carries a secret or a path.
 */
final class EditionBackupFacts implements BackupFactsProviderInterface
{
    public function __construct(private \mysqli $db, private string $backupDir)
    {
    }

    /** The provider an edition check should use: run record + file evidence. */
    public static function checked(\mysqli $db, string $backupDir): BackupFactsProviderInterface
    {
        $inner = new self($db, $backupDir);

        return new ArtifactCheckedBackupFactsProvider($inner, static fn (): array => $inner->newestFiles());
    }

    public function backupFacts(): BackupFacts
    {
        try {
            if (!\ITFlow\Recovery\BackupStatus::available($this->db)) {
                return BackupFacts::unavailable('The backup run record needs the latest database update.');
            }
            $row = $this->newestGoodRun();
            if ($row === null) {
                return new BackupFacts(true, source: 'backup_runs');
            }
            $destinations = [];
            if ($this->localFile($row) !== null) {
                $destinations[] = BackupFacts::DEST_LOCAL;
            }
            $offsite = (string) ($row['run_offsite_result'] ?? '');
            $offsiteOk = str_starts_with($offsite, 'ok');
            if ($offsiteOk) {
                $destinations[] = BackupFacts::DEST_OFFSITE;
            }
            $finished = new \DateTimeImmutable('@' . (int) $row['finished_ts']);

            return new BackupFacts(true, $finished, [BackupStore::CONTROL_DB], $destinations, null, null, $offsiteOk ? $finished : null, null, null, [], 'backup_runs');
        } catch (\Throwable) {
            return BackupFacts::unavailable('The backup run record could not be read.');
        }
    }

    /** @return list<string> local path of the newest successful backup's file (empty when it is not in the backups folder) */
    public function newestFiles(): array
    {
        try {
            $row = $this->newestGoodRun();
            $file = $row === null ? null : $this->localFile($row);

            return $file === null ? [] : [$file];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    private function newestGoodRun(): ?array
    {
        $r = @mysqli_query($this->db, 'SELECT run_file, run_offsite_result, UNIX_TIMESTAMP(run_finished_at) AS finished_ts FROM backup_runs
            WHERE run_ok = 1 AND run_finished_at IS NOT NULL ORDER BY run_id DESC LIMIT 1');
        $row = $r ? mysqli_fetch_assoc($r) : null;

        return $row && $row['finished_ts'] !== null ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function localFile(array $row): ?string
    {
        $name = basename((string) ($row['run_file'] ?? ''));
        $path = $this->backupDir . '/' . $name;

        return $name !== '' && is_file($path) ? $path : null;
    }
}
