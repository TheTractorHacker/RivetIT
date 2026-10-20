<?php

/*
 * Device clock notice (RivetCore 1.0.0-rc.23, RivetIT 26.10.65). A PC whose clock is minutes off (or whose time zone is wrong) has its check-ins refused or its
 * timestamps shifted. The read model's `clock` object ({skew_s, measured_at, agent_offset_s, last_reject {reason, at}}) says so; this file turns it into words, a
 * banner and a small badge. Pure: no database, no session. Everything that came from a device (the refusal reason) is escaped by the callers' rmmH().
 */

const RIVET_CLOCK_WARN_S = 120;      // |skew| above this shows the notice (amber)
const RIVET_CLOCK_CRIT_S = 900;      // at or above the server's window: check-ins are refused (red)

/**
 * What to tell the technician, or null when the clock is fine. A refused check-in (last_reject) or |skew_s| above two minutes needs a notice.
 *
 * @param array<string,mixed>|null $clock RmmReadModel::clockOf()
 * @return array{level:string,minutes:?int,direction:?string,text:string,reject:?string,reject_at:?string,agent_offset_s:?int}|null
 */
function rivetRmmClockNotice(?array $clock, string $platform = ''): ?array
{
    if (!is_array($clock)) {
        return null;
    }
    $skew = isset($clock['skew_s']) && is_int($clock['skew_s']) ? $clock['skew_s'] : null;
    $rej = is_array($clock['last_reject'] ?? null) && is_string($clock['last_reject']['reason'] ?? null) && $clock['last_reject']['reason'] !== '' ? $clock['last_reject'] : null;
    $off = $skew !== null && abs($skew) > RIVET_CLOCK_WARN_S;
    if ($rej === null && !$off) {
        return null;
    }
    $fix = [
        'windows' => 'set the time zone and sync the time (Windows: tzutil /s "Central Standard Time" with your own zone name, then w32tm /resync)',
        'linux' => 'set the time zone and sync the time (Linux: timedatectl set-timezone Area/City, then timedatectl set-ntp true)',
    ][$platform] ?? 'set the time zone and sync the time (Windows: tzutil /s "Central Standard Time" with your own zone name, then w32tm /resync; Linux: timedatectl set-timezone Area/City, then timedatectl set-ntp true)';
    $minutes = $skew === null ? null : max(1, (int) round(abs($skew) / 60));
    $dir = $skew === null ? null : ($skew > 0 ? 'ahead' : 'behind');
    $thing = $platform === 'linux' ? 'machine' : 'PC';
    if ($minutes !== null && $off) {
        $text = "This $thing's clock is $minutes min " . ($dir === 'ahead' ? 'ahead of' : 'behind') . " the server: $fix.";
    } else {
        $text = "The server refused this $thing's last check-in because of its time. Check the date, time and time zone on the device: $fix.";
    }
    $critical = $rej !== null || ($skew !== null && abs($skew) >= RIVET_CLOCK_CRIT_S);

    return ['level' => $critical ? 'danger' : 'warning', 'minutes' => $minutes, 'direction' => $dir, 'text' => $text, 'reject' => $rej['reason'] ?? null, 'reject_at' => is_string($rej['at'] ?? null) ? $rej['at'] : null,
        'agent_offset_s' => isset($clock['agent_offset_s']) && is_int($clock['agent_offset_s']) ? $clock['agent_offset_s'] : null];
}

/** The banner. $when renders the refusal time (callers pass rivetRmmUiTime); everything else is escaped here. */
function rivetRmmClockBanner(?array $notice, ?callable $when = null): string
{
    if ($notice === null) {
        return '';
    }
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $o = '<div class="alert alert-' . $h($notice['level']) . ' py-2 px-3 mb-0 mt-2 small" role="alert" id="rmm-clock-notice"><i class="fas fa-clock me-1" aria-hidden="true"></i><strong>Clock problem.</strong> ' . $h($notice['text']);
    if ($notice['reject'] !== null) {
        $o .= ' <span class="d-block">The server said: &ldquo;' . $h($notice['reject']) . '&rdquo;' . ($notice['reject_at'] !== null && $when !== null ? ' (' . $when($notice['reject_at']) . ')' : '') . '. This clears itself after the next accepted check-in.</span>';
    }
    if ($notice['agent_offset_s'] !== null && abs($notice['agent_offset_s']) > 60) {
        $o .= ' <span class="d-block text-muted">The agent is correcting its own timestamps by about ' . max(1, (int) round(abs($notice['agent_offset_s']) / 60)) . ' min, but the clock itself should still be fixed.</span>';
    }

    return $o . '</div>';
}

/** A short badge for list rows ("Clock 5 min ahead" / "Clock refused"), or ''. */
function rivetRmmClockBadge(?array $clock): string
{
    $n = rivetRmmClockNotice($clock);
    if ($n === null) {
        return '';
    }
    $label = $n['minutes'] !== null && $n['direction'] !== null ? "Clock {$n['minutes']} min {$n['direction']}" : 'Clock problem';
    $title = $n['reject'] !== null ? $n['text'] . ' Server said: ' . $n['reject'] : $n['text'];

    return ' <span class="badge text-bg-' . $n['level'] . ' ms-1 rmm-clock-badge" title="' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><i class="fas fa-clock me-1" aria-hidden="true"></i>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * The clock object of several devices in one query (the list summaries do not carry it), keyed by device id; devices with nothing to show are left out.
 *
 * @param list<int> $deviceIds
 * @return array<int,array<string,mixed>>
 */
function rivetRmmClockMap(\mysqli $mysqli, array $deviceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $deviceIds), static fn (int $i): bool => $i > 0)));
    if ($ids === []) {
        return [];
    }
    $out = [];
    $res = @mysqli_query($mysqli, 'SELECT device_id, clock_skew_s, clock_skew_at, clock_offset_s, last_reject_reason, last_reject_at FROM endpoint_agent_devices WHERE device_id IN (' . implode(',', $ids)
        . ') AND (clock_skew_s IS NOT NULL OR last_reject_reason IS NOT NULL)');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $c = \RivetCore\Rmm\Read\RmmReadModel::clockOf($r);
        if ($c !== null) {
            $out[(int) $r['device_id']] = $c;
        }
    }

    return $out;
}
