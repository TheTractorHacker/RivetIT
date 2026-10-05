<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Itsm;

use RivetCore\Database\DatabaseInterface;
use RivetCore\ITSM\TicketProblemLinkInterface;

/** RivetIT keeps the ticket-to-problem link in tickets.ticket_problem_id; RivetCore never touches `tickets`. */
final class TicketsProblemLink implements TicketProblemLinkInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function link(int $ticketId, int $problemId): void
    {
        $this->database->execute('UPDATE tickets SET ticket_problem_id = ? WHERE ticket_id = ?', [$problemId, $ticketId]);
    }

    public function unlink(int $ticketId, int $problemId): void
    {
        $this->database->execute(
            'UPDATE tickets SET ticket_problem_id = NULL WHERE ticket_id = ? AND ticket_problem_id = ?',
            [$ticketId, $problemId]
        );
    }
}
