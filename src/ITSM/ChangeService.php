<?php

namespace ITFlow\ITSM;

/**
 * Change management (master plan Section 29), kept deliberately lightweight:
 * no separate approver/CAB model - a change just carries its own status,
 * risk and plans, and a problem can point at the change meant to fix it
 * (problems.change_problem_id) to complete the tickets -> problem -> change
 * chain from the plan.
 */
class ChangeService
{
    private const STATUSES = [
        'draft', 'awaiting_approval', 'approved', 'scheduled', 'in_progress',
        'successful', 'failed', 'rolled_back', 'cancelled',
    ];

    private const RISKS = ['low', 'medium', 'high'];

    private const TRANSITIONS = [
        'draft' => ['awaiting_approval', 'cancelled'],
        'awaiting_approval' => ['draft', 'approved', 'cancelled'],
        'approved' => ['scheduled', 'in_progress', 'cancelled'],
        'scheduled' => ['approved', 'in_progress', 'cancelled'],
        'in_progress' => ['successful', 'failed', 'cancelled'],
        'failed' => ['rolled_back', 'cancelled'],
        'successful' => ['rolled_back'],
        'rolled_back' => [],
        'cancelled' => [],
    ];

    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function create(
        string $title,
        ?string $reason,
        ?string $impact,
        string $risk,
        ?string $implementationPlan,
        ?string $rollbackPlan,
        ?string $scheduledAt,
        ?int $createdBy
    ): int {
        if (!in_array($risk, self::RISKS, true)) {
            throw new \InvalidArgumentException("Invalid change risk: $risk");
        }
        $scheduledAt = $scheduledAt !== '' ? $scheduledAt : null;

        $stmt = $this->mysqli->prepare(
            "INSERT INTO changes (title, reason, impact, risk, implementation_plan, rollback_plan, scheduled_at, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $stmt->bind_param('sssssssi', $title, $reason, $impact, $risk, $implementationPlan, $rollbackPlan, $scheduledAt, $createdBy);
        $stmt->execute();
        $changeId = $stmt->insert_id;
        $stmt->close();

        return $changeId;
    }

    public function setStatus(int $changeId, string $status, ?string $scheduledAt = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid change status: $status");
        }

        $current = $this->fetchOne("SELECT status, scheduled_at FROM changes WHERE change_id = ?", 'i', [$changeId]);
        if (!$current) {
            throw new \InvalidArgumentException("Change $changeId not found");
        }

        $from = $current['status'];
        if ($from === $status) {
            return;
        }

        if (!in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \InvalidArgumentException("Cannot move a change from '$from' to '$status'");
        }

        $scheduledAt = $scheduledAt !== '' ? $scheduledAt : null;
        if ($status === 'scheduled' && !$scheduledAt && !$current['scheduled_at']) {
            throw new \InvalidArgumentException('A scheduled change needs a scheduled_at time');
        }

        if ($scheduledAt) {
            $stmt = $this->mysqli->prepare("UPDATE changes SET status = ?, scheduled_at = ? WHERE change_id = ?");
            $stmt->bind_param('ssi', $status, $scheduledAt, $changeId);
        } else {
            $stmt = $this->mysqli->prepare("UPDATE changes SET status = ? WHERE change_id = ?");
            $stmt->bind_param('si', $status, $changeId);
        }
        $stmt->execute();
        $stmt->close();
    }

    public function reschedule(int $changeId, string $scheduledAt): void
    {
        $stmt = $this->mysqli->prepare("UPDATE changes SET scheduled_at = ? WHERE change_id = ?");
        $stmt->bind_param('si', $scheduledAt, $changeId);
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
