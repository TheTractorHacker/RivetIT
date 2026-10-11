<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (nightly MSP-2)

use PHPUnit\Framework\TestCase;
use ITFlow\Crypto\ColumnSpec;
use ITFlow\Crypto\MysqliRewrapSource;

/**
 * MysqliRewrapSource over a composite integer key (rmm_custom_field_values: field_id + scope_id; the row id is "<field>:<scope>"). RivetCore's
 * RewrapSourceConformanceTestCase fixes single-key ids, so the same contract is checked here: stable order by the pair, exclusive cursor,
 * every row exactly once at any page size, reads without side effects, replace() as compare-and-set that touches only its own row.
 */
final class CryptoRewrapCompositeSourceTest extends TestCase
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

    private function source(): MysqliRewrapSource
    {
        $m = $this->db();
        $m->query('DROP TABLE IF EXISTS crypto_conf_cmp_tmp');
        $m->query('CREATE TABLE crypto_conf_cmp_tmp (a int NOT NULL, b int NOT NULL, v text DEFAULT NULL, PRIMARY KEY (a, b))');
        foreach ([[2, 7], [10, 7], [10, 1], [100, 3], [9, 7], [1, 1]] as [$a, $b]) {
            $m->query("INSERT INTO crypto_conf_cmp_tmp (a, b, v) VALUES ($a, $b, 'ct-$a-$b')");
        }

        return new MysqliRewrapSource($m, new ColumnSpec('crypto_conf_cmp_tmp', 'a', 'v', 'custom', false, true, static fn (string $id): string => 'rec:' . $id, 'b'));
    }

    private function stored(string $id): ?string
    {
        [$a, $b] = array_map('intval', explode(':', $id));
        $r = $this->db()->query("SELECT v FROM crypto_conf_cmp_tmp WHERE a = $a AND b = $b")->fetch_row();

        return $r ? (string) $r[0] : null;
    }

    /** @return list<string> */
    private function page(MysqliRewrapSource $s, int $limit): array
    {
        $ids = [];
        $cursor = null;
        for ($i = 0; $i < 100; $i++) {
            $items = $s->next($cursor, $limit);
            $this->assertLessThanOrEqual($limit, count($items));
            if ($items === []) {
                return $ids;
            }
            foreach ($items as $it) {
                $ids[] = $it->id;
            }
            $cursor = $items[count($items) - 1]->id;
        }
        $this->fail('paging did not terminate');
    }

    public function testNameIsTheTableAndColumn(): void
    {
        $this->assertSame('crypto_conf_cmp_tmp.v', $this->source()->name());
    }

    public function testEveryRowExactlyOnceInNumericPairOrderAtAnyPageSize(): void
    {
        $want = ['1:1', '2:7', '9:7', '10:1', '10:7', '100:3'];
        foreach ([1, 2, 3, 100] as $limit) {
            $this->assertSame($want, $this->page($this->source(), $limit), "limit $limit");
        }
    }

    public function testCursorIsExclusiveAndWorksInsideOneFirstKey(): void
    {
        $s = $this->source();
        $items = $s->next('10:1', 10);
        $this->assertSame(['10:7', '100:3'], array_map(fn ($i) => $i->id, $items));
        $this->assertSame([], $s->next('100:3', 10));
    }

    public function testItemsCarryTheValueAndTheContext(): void
    {
        $items = $this->source()->next(null, 10);
        $this->assertSame('ct-1-1', $items[0]->ciphertext);
        $this->assertSame('rec:1:1', $items[0]->context);
        $this->assertEquals($items, $this->source()->next(null, 10), 'reading has no side effects');
    }

    public function testReplaceIsCompareAndSetAndTouchesOnlyItsRow(): void
    {
        $s = $this->source();
        $this->assertFalse($s->replace('10:7', 'stale', 'x'));
        $this->assertSame('ct-10-7', $this->stored('10:7'));
        $this->assertTrue($s->replace('10:7', 'ct-10-7', 'new'));
        $this->assertFalse($s->replace('10:7', 'ct-10-7', 'again'));
        $this->assertSame('new', $this->stored('10:7'));
        $this->assertSame('ct-10-1', $this->stored('10:1'), 'the other row with the same first key is untouched');
        $this->assertFalse($s->replace('999:1', 'ct-999-1', 'x'));
        $this->assertNull($this->stored('999:1'));
    }

    public function testReplacingWhilePagingNeitherSkipsNorRepeats(): void
    {
        $s = $this->source();
        $seen = [];
        $cursor = null;
        for ($guard = 0; $guard < 50 && ($items = $s->next($cursor, 2)) !== []; $guard++) {
            foreach ($items as $it) {
                $seen[] = $it->id;
                $s->replace($it->id, $it->ciphertext, 'done-' . $it->id);
            }
            $cursor = $items[count($items) - 1]->id;
        }
        $this->assertCount(6, $seen);
        $this->assertCount(6, array_unique($seen));
    }

    protected function tearDown(): void
    {
        $this->m?->query('DROP TABLE IF EXISTS crypto_conf_cmp_tmp');
    }
}
