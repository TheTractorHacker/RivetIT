<?php

declare(strict_types=1);

require_once __DIR__ . '/KitSupport.php';

use PHPUnit\Framework\TestCase;

if (!KitSupport::has(\RivetCore\Testing\TicketProblemLinkConformanceTestCase::class)) {
    /** Placeholder until the pinned RivetCore carries the conformance kit. */
    final class TicketProblemLinkConformanceTest extends TestCase
    {
        public function testKitIsAvailable(): void
        {
            $this->markTestSkipped(KitSupport::MISSING);
        }
    }

    return;
}

use RivetCore\ITSM\TicketProblemLinkInterface;
use RivetCore\Testing\TicketProblemLinkConformanceTestCase;

/** Itsm\TicketsProblemLink over the tickets table. */
final class TicketProblemLinkConformanceTest extends TicketProblemLinkConformanceTestCase
{
    protected function links(): TicketProblemLinkInterface
    {
        return new \ITFlow\Core\Adapter\Itsm\TicketsProblemLink(KitSupport::db());
    }

    protected function createTicket(): int
    {
        return (int) KitSupport::db()->execute("INSERT INTO tickets (ticket_subject, ticket_prefix, ticket_number, ticket_status) VALUES ('conformance', 'TST', ?, 1)", [random_int(900000, 99999999)])->insertId;
    }

    protected function linkedProblemId(int $ticketId): ?int
    {
        $r = KitSupport::db()->fetchOne('SELECT ticket_problem_id FROM tickets WHERE ticket_id = ?', [$ticketId]);

        return $r === null || $r['ticket_problem_id'] === null ? null : (int) $r['ticket_problem_id'];
    }

    protected function deleteTicket(int $ticketId): void
    {
        KitSupport::db()->execute('DELETE FROM tickets WHERE ticket_id = ?', [$ticketId]);
    }
}
