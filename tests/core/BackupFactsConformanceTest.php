<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use ITFlow\Compliance\EditionBackupFacts;
use RivetCore\Compliance\Backup\BackupFacts;
use RivetCore\Compliance\Backup\BackupFactsProviderInterface;
use RivetCore\Testing\BackupFactsProviderConformanceTestCase;

/**
 * EditionBackupFacts (run record + file evidence) against the Core kit, then the edition's own rules on backup_runs rows:
 * no run, a failed run, a good local run, an off-site run, a run that contained the PostgreSQL dump, an encrypted run, a plain zip.
 */
final class BackupFactsConformanceTest extends BackupFactsProviderConformanceTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rit-bf-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        EndpointKit::mysqli()->query('DELETE FROM backup_runs');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        EndpointKit::mysqli()->query('DELETE FROM backup_runs');
    }

    protected function provider(): BackupFactsProviderInterface
    {
        return EditionBackupFacts::checked(EndpointKit::mysqli(), $this->dir);
    }

    /** @param array<string,mixed> $o */
    private function addRun(array $o): void
    {
        $o += ['ok' => 1, 'file' => null, 'offsite' => null, 'stores' => null, 'encrypted' => null, 'retention' => null, 'finished' => 'NOW() - INTERVAL 1 HOUR'];
        $q = static fn ($v): string => $v === null ? 'NULL' : (is_int($v) ? (string) $v : "'" . addslashes((string) $v) . "'");
        EndpointKit::mysqli()->query('INSERT INTO backup_runs (run_kind, run_started_at, run_finished_at, run_file, run_ok, run_offsite_result, run_stores, run_encrypted, run_retention_days)
            VALUES (' . $q('script') . ', NOW() - INTERVAL 2 HOUR, ' . $o['finished'] . ', ' . $q($o['file']) . ', ' . (int) $o['ok'] . ', ' . $q($o['offsite']) . ', ' . $q($o['stores']) . ', ' . $q($o['encrypted']) . ', ' . $q($o['retention']) . ')');
    }

    public function testNoRunClaimsNothing(): void
    {
        $f = $this->provider()->backupFacts();
        $this->assertTrue($f->available);
        $this->assertNull($f->lastSuccessAt);
        $this->assertSame([], $f->includedStores);
        $this->assertSame([], $f->destinations);
        $this->assertNull($f->encrypted);
    }

    public function testAFailedRunIsNotASuccess(): void
    {
        $this->addRun(['ok' => 0, 'stores' => 'control_db,rmm_postgres']);
        $f = $this->provider()->backupFacts();
        $this->assertNull($f->lastSuccessAt);
        $this->assertSame([], $f->includedStores);
    }

    public function testAGoodLocalRunOnlyClaimsTheControlDatabase(): void
    {
        $zip = new ZipArchive();
        $zip->open($this->dir . '/a.zip', ZipArchive::CREATE);
        $zip->addFromString('db.sql', "-- plain dump\nCREATE TABLE t (id int);\n");
        $zip->close();
        $this->addRun(['file' => 'a.zip']);
        $f = $this->provider()->backupFacts();
        $this->assertNotNull($f->lastSuccessAt);
        $this->assertSame(['control_db'], $f->includedStores);
        $this->assertSame([BackupFacts::DEST_LOCAL], $f->destinations);
        $this->assertFalse($f->encrypted, 'a plain zip is reported as not encrypted (the file header is the evidence)');
        $this->assertNull($f->lastOffsiteSuccessAt);
    }

    public function testOffsiteOnlyAfterAVerifiedCopy(): void
    {
        $this->addRun(['file' => 'x.enc', 'offsite' => 'failed: timeout']);
        $this->assertNull($this->provider()->backupFacts()->lastOffsiteSuccessAt, 'a failed upload is not an arrival');
        EndpointKit::mysqli()->query('DELETE FROM backup_runs');
        $this->addRun(['file' => 'x.enc', 'offsite' => 'ok: s3://bucket/x.enc']);
        $f = $this->provider()->backupFacts();
        $this->assertNotNull($f->lastOffsiteSuccessAt);
        $this->assertContains(BackupFacts::DEST_OFFSITE, $f->destinations);
        $this->assertStringNotContainsString('bucket', json_encode($f->toArray()), 'no destination name or path is carried in the facts');
    }

    public function testThePostgresStoreIsClaimedOnlyWhenTheRunRecordedIt(): void
    {
        $this->addRun(['file' => 'y.enc', 'stores' => 'control_db']);
        $this->assertSame(['control_db'], $this->provider()->backupFacts()->includedStores);
        EndpointKit::mysqli()->query('DELETE FROM backup_runs');
        $this->addRun(['file' => 'y.enc', 'stores' => 'control_db,rmm_postgres', 'encrypted' => 1, 'retention' => 14]);
        $f = $this->provider()->backupFacts();
        $this->assertSame(['control_db', 'rmm_postgres'], $f->includedStores);
        $this->assertTrue($f->encrypted);
        $this->assertSame(14, $f->retentionDays);
    }

    public function testAnUnknownStoreIdInTheRecordIsIgnored(): void
    {
        $this->addRun(['file' => 'z.enc', 'stores' => 'control_db,../etc/passwd,bogus']);
        $this->assertSame(['control_db'], $this->provider()->backupFacts()->includedStores);
    }

    public function testTheNewestSuccessfulRunWins(): void
    {
        $this->addRun(['file' => 'old.enc', 'stores' => 'control_db', 'finished' => 'NOW() - INTERVAL 3 DAY']);
        $this->addRun(['file' => 'new.enc', 'stores' => 'control_db,rmm_postgres', 'finished' => 'NOW() - INTERVAL 1 HOUR']);
        $this->addRun(['file' => 'bad.enc', 'ok' => 0, 'stores' => 'control_db,rmm_postgres', 'finished' => 'NOW() - INTERVAL 10 MINUTE']);
        $this->assertSame(['control_db', 'rmm_postgres'], $this->provider()->backupFacts()->includedStores);
    }
}
