<?php
/**
 * Project health: one answer to "is this project OK?" from its due date and task progress.
 * Returns [key, label, bootstrap-colour, icon, days-to-due (null when no due date)].
 */
function projectHealth($due, $done, $total, $completed_at = null)
{
    if ($completed_at) {
        return ['done', 'Completed', 'secondary', 'fa-check', null];
    }
    $due_ts = $due ? strtotime($due . ' 23:59:59') : false;
    if (!$due_ts) {
        return ['nodue', 'No due date', 'secondary', 'fa-calendar-minus', null];
    }
    $days = (int) floor(($due_ts - time()) / 86400);
    $pct = $total > 0 ? ($done / $total) * 100 : 0;
    if ($days < 0) {
        $n = abs($days);
        return ['overdue', 'Overdue ' . $n . 'd', 'danger', 'fa-exclamation-circle', $days];
    }
    if ($days <= 7 && $pct < 80) {
        return ['risk', $days === 0 ? 'At risk, due today' : "At risk, due in {$days}d", 'warning', 'fa-exclamation-triangle', $days];
    }
    return ['ok', $days <= 7 ? "On track, due in {$days}d" : 'On track', 'success', 'fa-check-circle', $days];
}

function projectHealthBadge($due, $done, $total, $completed_at = null)
{
    [$k, $label, $c, $icon] = projectHealth($due, $done, $total, $completed_at);
    $tc = $c === 'warning' ? 'text-bg-warning' : 'text-bg-' . $c;
    return "<span class='badge $tc text-nowrap' data-health='$k'><i class='fas fa-fw $icon me-1'></i>" . htmlspecialchars($label, ENT_QUOTES) . "</span>";
}
