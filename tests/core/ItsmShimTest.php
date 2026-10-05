<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The legacy ITFlow\ITSM services keep their constructor and methods, now backed by RivetCore. Needs a scratch DB with the full RivetIT schema. */
final class ItsmShimTest extends TestCase
{
    private mysqli $m;

    protected function setUp(): void
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $this->m->query("SET SESSION sql_mode=''");
        $this->m->query('DELETE FROM problems');
        $this->m->query('DELETE FROM changes');
        $this->m->query('DELETE FROM tickets');
    }

    public function testTicketProblemChangeChain(): void
    {
        $this->m->query("INSERT INTO tickets (ticket_id, ticket_subject) VALUES (501, 'Printer down')");
        $problems = new \ITFlow\ITSM\ProblemService($this->m);
        $changes = new \ITFlow\ITSM\ChangeService($this->m);

        $p = $problems->create('Printer firmware bug', 'recurring jams', 1);
        $problems->linkTicket($p, 501);
        $this->assertEquals($p, $this->m->query('SELECT ticket_problem_id FROM tickets WHERE ticket_id = 501')->fetch_row()[0]);
        $problems->unlinkTicket($p + 1, 501);
        $this->assertEquals($p, $this->m->query('SELECT ticket_problem_id FROM tickets WHERE ticket_id = 501')->fetch_row()[0], 'a different problem id does not unlink');
        $problems->unlinkTicket($p, 501);
        $this->assertNull($this->m->query('SELECT ticket_problem_id FROM tickets WHERE ticket_id = 501')->fetch_row()[0]);

        $c = $changes->create('Flash firmware', 'fix jams', 'printers offline 10m', 'low', 'flash', 'reflash old', null, 1);
        $problems->linkChange($p, $c);
        $changes->setStatus($c, 'awaiting_approval');
        $changes->setStatus($c, 'approved');
        $changes->setStatus($c, 'scheduled', '2031-05-06 07:08:09');
        $this->assertSame('scheduled', $this->m->query("SELECT status FROM changes WHERE change_id = $c")->fetch_row()[0]);
        $problems->setStatus($p, 'resolved');
        $this->assertNotNull($this->m->query("SELECT resolved_at FROM problems WHERE problem_id = $p")->fetch_row()[0]);
    }

    public function testErrorsStillThrowInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \ITFlow\ITSM\ProblemService($this->m))->setStatus(424242, 'open');
    }

    public function testStatusTablesArePublicSoPagesCanStopMirroringThem(): void
    {
        $this->assertArrayHasKey('draft', \RivetCore\ITSM\ChangeService::TRANSITIONS);
        $this->assertArrayHasKey('open', \RivetCore\ITSM\ProblemService::TRANSITIONS);
    }
}
