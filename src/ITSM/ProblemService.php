<?php

namespace ITFlow\ITSM;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use ITFlow\Core\Adapter\Itsm\TicketsProblemLink;

/**
 * Problem management (master plan Section 29), kept deliberately lightweight: a problem is a root-cause record
 * that tickets (incidents) link to via tickets.ticket_problem_id, and that can itself link forward to the change
 * meant to fix it via problems.change_problem_id.
 *
 * Compatibility shim over RivetCore\ITSM\ProblemService. The status machine now lives in Core and is public
 * (ProblemService::TRANSITIONS); the tickets link stays in RivetIT (Core\Adapter\Itsm\TicketsProblemLink).
 *
 * @deprecated since 26.10.26 use \RivetCore\ITSM\ProblemService (construct it with a MysqliDatabaseAdapter and TicketsProblemLink). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class ProblemService
{
    private \RivetCore\ITSM\ProblemService $core;

    public function __construct(\mysqli $mysqli)
    {
        $database = new MysqliDatabaseAdapter($mysqli);
        $this->core = new \RivetCore\ITSM\ProblemService($database, new TicketsProblemLink($database));
    }

    public function create(string $title, ?string $description, ?int $createdBy): int
    {
        return $this->core->create($title, $description, $createdBy);
    }

    public function setStatus(int $problemId, string $status): void
    {
        $this->core->setStatus($problemId, $status);
    }

    public function linkChange(int $problemId, ?int $changeId): void
    {
        $this->core->linkChange($problemId, $changeId);
    }

    public function linkTicket(int $problemId, int $ticketId): void
    {
        $this->core->linkTicket($problemId, $ticketId);
    }

    public function unlinkTicket(int $problemId, int $ticketId): void
    {
        $this->core->unlinkTicket($problemId, $ticketId);
    }
}
