<?php

declare(strict_types=1);

use ITFlow\Crypto\ColumnSpec;
use ITFlow\Crypto\MysqliRewrapSource;
use RivetCore\Crypto\RewrapSource;
use RivetCore\Testing\RewrapSourceConformanceTestCase;

/**
 * RivetCore's conformance kit for RewrapSource, run on RivetIT's MysqliRewrapSource over a real table (integer primary key, so the ids 2, 10,
 * 100, 9, 1 sort differently as numbers and as strings).
 */
final class CryptoRewrapSourceConformanceTest extends RewrapSourceConformanceTestCase
{
    private ?mysqli $m = null;

    private function db(): mysqli
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        if ($this->m === null) {
            $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
            $this->m->set_charset('utf8mb4');
        }

        return $this->m;
    }

    protected function source(array $rows): RewrapSource
    {
        $m = $this->db();
        $m->query('DROP TABLE IF EXISTS crypto_conf_tmp');
        $m->query('CREATE TABLE crypto_conf_tmp (id int NOT NULL, v text DEFAULT NULL, PRIMARY KEY (id))');
        foreach ($rows as $id => $row) {
            $stmt = $m->prepare('INSERT INTO crypto_conf_tmp (id, v) VALUES (?, ?)');
            $i = (int) $id;
            $stmt->bind_param('is', $i, $row['ciphertext']);
            $stmt->execute();
        }

        return new MysqliRewrapSource($m, new ColumnSpec('crypto_conf_tmp', 'id', 'v', 'custom', false, true, static fn (string $id): string => 'rec:' . $id));
    }

    protected function stored(RewrapSource $source, string $id): ?string
    {
        $stmt = $this->db()->prepare('SELECT v FROM crypto_conf_tmp WHERE id = ?');
        $i = (int) $id;
        $stmt->bind_param('i', $i);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();

        return $row ? (string) $row[0] : null;
    }

    protected function tearDown(): void
    {
        $this->m?->query('DROP TABLE IF EXISTS crypto_conf_tmp');
    }
}
