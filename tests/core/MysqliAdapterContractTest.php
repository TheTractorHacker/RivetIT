<?php

declare(strict_types=1);

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Testing\DatabaseContractTestCase;

/** RivetIT's adapter must satisfy the same contract as every other DatabaseInterface. */
final class MysqliAdapterContractTest extends DatabaseContractTestCase
{
    private ?DatabaseInterface $db = null;

    protected function database(): DatabaseInterface
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $m->set_charset('utf8mb4');

        return $this->db ??= new MysqliDatabaseAdapter($m);
    }
}
