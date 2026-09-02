<?php

namespace ITFlow\ITSM;

/**
 * Problem management (master plan Section 29), kept deliberately lightweight:
 * a problem is a root-cause record that tickets (incidents) link to via
 * tickets.ticket_problem_id, and that can itself link forward to the change
 * meant to fix it via problems.change_problem_id. No new table for
 * "incidents" - an incident is just a ticket.
 */
class ProblemService
{
    private const STATUSES = ['open', 'investigating', 'resolved', 'closed'];

    private const TRANSITIONS = [
        'open' => ['investigating', 'resolved', 'closed'],
        'investigating' => ['open', 'resolved', 'closed'],
        'resolved' => ['open', 'investigating', 'closed'],
        'closed' => ['open', 'investigating'],
    ];

    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function create(string $title, ?string $description, ?int $createdBy): int
    {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO problems (title, description, status, created_by) VALUES (?, ?, 'open', ?)"
        );
        $stmt->bind_param('ssi', $title, $description, $createdBy);
        $stmt->execute();
        $problemId = $stmt->insert_id;
        $stmt->close();

        return $problemId;
    }

    public function setStatus(int $problemId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid problem status: $status");
        }

        $current = $this->fetchOne("SELECT status FROM problems WHERE problem_id = ?", 'i', [$problemId]);
        if (!$current) {
            throw new \InvalidArgumentException("Problem $problemId not found");
        }

        $from = $current['status'];
        if ($from === $status) {
            return;
        }

        if (!in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \InvalidArgumentException("Cannot move a problem from '$from' to '$status'");
        }

        // resolved_at marks when the root cause was actually fixed - set it the
        // first time a problem reaches resolved/closed, clear it on reopen so a
        // later re-resolve gets a fresh timestamp rather than the stale one.
        if (in_array($status, ['resolved', 'closed'], true)) {
            $stmt = $this->mysqli->prepare(
                "UPDATE problems SET status = ?, resolved_at = COALESCE(resolved_at, NOW()) WHERE problem_id = ?"
            );
        } else {
            $stmt = $this->mysqli->prepare(
                "UPDATE problems SET status = ?, resolved_at = NULL WHERE problem_id = ?"
            );
        }
        $stmt->bind_param('si', $status, $problemId);
        $stmt->execute();
        $stmt->close();
    }

    public function linkChange(int $problemId, ?int $changeId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE problems SET change_problem_id = ? WHERE problem_id = ?");
        $stmt->bind_param('ii', $changeId, $problemId);
        $stmt->execute();
        $stmt->close();
    }

    public function linkTicket(int $problemId, int $ticketId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE tickets SET ticket_problem_id = ? WHERE ticket_id = ?");
        $stmt->bind_param('ii', $problemId, $ticketId);
        $stmt->execute();
        $stmt->close();
    }

    public function unlinkTicket(int $problemId, int $ticketId): void
    {
        $stmt = $this->mysqli->prepare(
            "UPDATE tickets SET ticket_problem_id = NULL WHERE ticket_id = ? AND ticket_problem_id = ?"
        );
        $stmt->bind_param('ii', $ticketId, $problemId);
        $stmt->execute();
        $stmt->close();
    }

    private function fetchOne(string $sql, string $types, array $params): ?array
    {
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}
