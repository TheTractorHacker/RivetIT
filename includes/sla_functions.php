<?php
/*
 * SLA Engine helpers (S1-S3: business-hours calendars, SLA policies, pause-on-hold).
 *
 * NON-BREAKING DESIGN:
 *   recalculateTicketSla($mysqli, int) keeps its original signature and, when no
 *   usable SLA policy target applies, falls back to the EXACT legacy behaviour:
 *   ticket_created_at + (contract per-priority hours) * 3600, NULL when there is
 *   no contract / no hours for the priority.
 *
 *   The new engine only changes a ticket's due dates once an admin configures a
 *   policy with real minute targets (and, optionally, a business-hours calendar
 *   and pause statuses). Until then every ticket resolves to the legacy path and
 *   due dates are identical to before.
 *
 * Schema (itflow_beta):
 *   sla_business_hours(calendar_id, calendar_name, calendar_timezone, calendar_is_default)
 *   sla_business_hours_periods(period_id, calendar_id, day_of_week[0=Sun..6=Sat], open_time, close_time)
 *   sla_holidays(holiday_id, calendar_id, holiday_date, holiday_name)
 *   sla_policies(policy_id, policy_name, policy_calendar_id, policy_pause_status_ids,
 *                policy_{low,medium,high}_{response,resolution} [MINUTES of business time],
 *                policy_is_default, policy_archived_at)
 *   ticket_sla_events(event_id, ticket_id, event_type, from_status, to_status, event_at)
 *   tickets.ticket_sla_policy_id / ticket_sla_paused_seconds / ticket_sla_paused_at /
 *           ticket_sla_response_met / ticket_sla_resolution_met
 */

/**
 * Does a column exist on a table? Cached per-request. Used so the policy
 * resolution chain can honour forward-compatible client/contract linkage
 * columns without failing when they are absent in the S1-S3 schema.
 */
function slaColumnExists($mysqli, string $table, string $column): bool {
    static $cache = [];
    $key = "$table.$column";
    if (isset($cache[$key])) return $cache[$key];
    $t = mysqli_real_escape_string($mysqli, $table);
    $c = mysqli_real_escape_string($mysqli, $column);
    $res = mysqli_query($mysqli, "SHOW COLUMNS FROM `$t` LIKE '$c'");
    $cache[$key] = ($res && mysqli_num_rows($res) > 0);
    return $cache[$key];
}

/**
 * Load a calendar (periods + holidays) as a plain array.
 *
 *   [] (empty)  => treat as 24/7 (raw wall-clock diff)
 *   otherwise   => ['calendar_id','calendar_name','calendar_timezone',
 *                   'periods' => [dow => [[open_sec,close_sec], ...]],
 *                   'holidays' => ['Y-m-d' => true, ...]]
 *
 * A NULL/0 calendar id returns [] (24/7). A non-existent id also returns [].
 */
function slaLoadCalendar($mysqli, ?int $calendar_id): array {
    $calendar_id = intval($calendar_id ?? 0);
    if ($calendar_id <= 0) return [];

    $cal = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT calendar_id, calendar_name, calendar_timezone FROM sla_business_hours WHERE calendar_id = $calendar_id LIMIT 1"
    ));
    if (!$cal) return [];

    $out = [
        'calendar_id'       => intval($cal['calendar_id']),
        'calendar_name'     => $cal['calendar_name'],
        'calendar_timezone' => $cal['calendar_timezone'] ?: 'UTC',
        'periods'           => [],
        'holidays'          => [],
    ];

    $pres = mysqli_query($mysqli,
        "SELECT day_of_week, open_time, close_time FROM sla_business_hours_periods WHERE calendar_id = $calendar_id ORDER BY day_of_week, open_time"
    );
    while ($p = mysqli_fetch_assoc($pres)) {
        $dow = intval($p['day_of_week']);
        $open  = slaTimeToSeconds($p['open_time']);
        $close = slaTimeToSeconds($p['close_time']);
        if ($close <= $open) continue; // ignore malformed windows
        $out['periods'][$dow][] = [$open, $close];
    }

    $hres = mysqli_query($mysqli,
        "SELECT holiday_date FROM sla_holidays WHERE calendar_id = $calendar_id"
    );
    while ($h = mysqli_fetch_assoc($hres)) {
        $out['holidays'][$h['holiday_date']] = true;
    }

    // No open windows at all => behave as 24/7 rather than "never".
    if (empty($out['periods'])) return [];

    return $out;
}

/**
 * Is the given instant (default now) inside one of the calendar's open windows?
 * Empty/NULL calendar (or one with no periods) => always open (24/7).
 * Uses server-local time to stay consistent with slaBusinessSecondsBetween /
 * slaAddBusinessSeconds, which operate on server-local DateTime objects.
 *
 * UI helper only: a "running" SLA timer whose calendar is currently closed is
 * neither ticking nor accruing business time, so the UI shows "Outside hours".
 */
function slaIsOpenNow(array $calendar, ?int $ts = null): bool {
    if (empty($calendar) || empty($calendar['periods'])) return true;
    $ts = $ts ?? time();
    $dt = (new DateTime())->setTimestamp($ts);
    $dateKey = $dt->format('Y-m-d');
    if (!empty($calendar['holidays'][$dateKey])) return false;
    $dow = (int) $dt->format('w'); // 0=Sun..6=Sat
    if (empty($calendar['periods'][$dow])) return false;
    $secs = ((int) $dt->format('G')) * 3600 + ((int) $dt->format('i')) * 60 + (int) $dt->format('s');
    foreach ($calendar['periods'][$dow] as $win) {
        if ($secs >= $win[0] && $secs < $win[1]) return true;
    }
    return false;
}

/** Convert 'HH:MM:SS' to seconds-since-midnight. */
function slaTimeToSeconds(?string $time): int {
    if (!$time) return 0;
    $parts = explode(':', $time);
    $h = intval($parts[0] ?? 0);
    $m = intval($parts[1] ?? 0);
    $s = intval($parts[2] ?? 0);
    return ($h * 3600) + ($m * 60) + $s;
}

/**
 * Business-time seconds elapsed between two instants for a given calendar.
 * Empty/NULL calendar (or one with no periods) => raw 24/7 difference.
 */
function slaBusinessSecondsBetween(DateTime $from, DateTime $to, array $calendar): int {
    if ($to <= $from) return 0;

    if (empty($calendar) || empty($calendar['periods'])) {
        return $to->getTimestamp() - $from->getTimestamp();
    }

    $periods  = $calendar['periods'];
    $holidays = $calendar['holidays'] ?? [];
    $total    = 0;

    $dayStart = (clone $from)->setTime(0, 0, 0);
    $guard = 0;
    while ($dayStart < $to && $guard < 4000) {
        $guard++;
        $dateKey = $dayStart->format('Y-m-d');
        $dow = (int) $dayStart->format('w'); // 0=Sun..6=Sat
        if (!isset($holidays[$dateKey]) && !empty($periods[$dow])) {
            foreach ($periods[$dow] as $win) {
                $winOpen  = (clone $dayStart)->modify("+{$win[0]} seconds");
                $winClose = (clone $dayStart)->modify("+{$win[1]} seconds");
                $segStart = ($from > $winOpen)  ? $from : $winOpen;
                $segEnd   = ($to   < $winClose) ? $to   : $winClose;
                if ($segEnd > $segStart) {
                    $total += $segEnd->getTimestamp() - $segStart->getTimestamp();
                }
            }
        }
        $dayStart = (clone $dayStart)->modify('+1 day');
    }

    return $total;
}

/**
 * Add N business-seconds to a start instant, returning the resulting DateTime.
 * Empty/NULL calendar => raw addition.
 */
function slaAddBusinessSeconds(DateTime $start, int $seconds, array $calendar): DateTime {
    $remaining = max(0, $seconds);

    if (empty($calendar) || empty($calendar['periods'])) {
        return (clone $start)->modify("+{$remaining} seconds");
    }
    if ($remaining === 0) return clone $start;

    $periods  = $calendar['periods'];
    $holidays = $calendar['holidays'] ?? [];

    $cursor  = clone $start;
    $dayStart = (clone $start)->setTime(0, 0, 0);
    $guard = 0;
    while ($remaining > 0 && $guard < 4000) {
        $guard++;
        $dateKey = $dayStart->format('Y-m-d');
        $dow = (int) $dayStart->format('w');
        if (!isset($holidays[$dateKey]) && !empty($periods[$dow])) {
            foreach ($periods[$dow] as $win) {
                $winOpen  = (clone $dayStart)->modify("+{$win[0]} seconds");
                $winClose = (clone $dayStart)->modify("+{$win[1]} seconds");
                $segStart = ($cursor > $winOpen) ? $cursor : $winOpen;
                if ($segStart >= $winClose) continue;
                $avail = $winClose->getTimestamp() - $segStart->getTimestamp();
                if ($avail >= $remaining) {
                    return (clone $segStart)->modify("+{$remaining} seconds");
                }
                $remaining -= $avail;
            }
        }
        $dayStart = (clone $dayStart)->modify('+1 day');
        $cursor   = clone $dayStart;
    }

    // Guard tripped (target far beyond configured windows): add the remainder raw.
    return (clone $cursor)->modify("+{$remaining} seconds");
}

/**
 * Resolve the SLA policy that governs a ticket.
 * Chain: explicit ticket_sla_policy_id -> client/contract default (forward-compatible,
 * only if optional linkage columns exist) -> global default policy -> none.
 *
 * Returns the raw sla_policies row (assoc) or NULL. When NULL, the caller falls
 * back to legacy contract-hour behaviour. A returned policy may still have NULL
 * per-priority targets; recalculateTicketSla handles that per-field.
 */
function slaGetPolicyForTicket($mysqli, int $ticket_id): ?array {
    $t = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_sla_policy_id, ticket_contract_id, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"
    ));
    if (!$t) return null;

    // 1) Explicit policy pinned on the ticket
    $explicit = intval($t['ticket_sla_policy_id'] ?? 0);
    if ($explicit > 0) {
        $p = slaLoadPolicyRow($mysqli, $explicit);
        if ($p) return $p;
    }

    // 2) Client / contract default (forward-compatible; these linkage columns are
    //    not part of the S1-S3 schema, so guarded by existence checks and simply
    //    skipped today).
    $contract_id = intval($t['ticket_contract_id'] ?? 0);
    if ($contract_id > 0 && slaColumnExists($mysqli, 'contracts', 'contract_sla_policy_id')) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT contract_sla_policy_id FROM contracts WHERE contract_id = $contract_id LIMIT 1"
        ));
        $cpid = intval($row['contract_sla_policy_id'] ?? 0);
        if ($cpid > 0) {
            $p = slaLoadPolicyRow($mysqli, $cpid);
            if ($p) return $p;
        }
    }
    $client_id = intval($t['ticket_client_id'] ?? 0);
    if ($client_id > 0 && slaColumnExists($mysqli, 'sla_policies', 'policy_client_id')) {
        $p = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM sla_policies WHERE policy_client_id = $client_id AND policy_archived_at IS NULL ORDER BY policy_is_default DESC, policy_id ASC LIMIT 1"
        ));
        if ($p) return $p;
    }

    // 3) Global default policy
    $p = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM sla_policies WHERE policy_is_default = 1 AND policy_archived_at IS NULL ORDER BY policy_id ASC LIMIT 1"
    ));
    if ($p) return $p;

    // 4) None -> legacy fallback handled by caller
    return null;
}

/** Load a single non-archived policy row by id, or NULL. */
function slaLoadPolicyRow($mysqli, int $policy_id): ?array {
    $policy_id = intval($policy_id);
    if ($policy_id <= 0) return null;
    $p = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM sla_policies WHERE policy_id = $policy_id AND policy_archived_at IS NULL LIMIT 1"
    ));
    return $p ?: null;
}

/**
 * Pull the priority-specific response/resolution MINUTE targets from a policy row.
 * Returns [response_minutes|null, resolution_minutes|null].
 */
function slaPolicyTargetsForPriority(array $policy, ?string $priority): array {
    $p = strtolower(trim((string) $priority));
    if ($p === 'low') {
        $resp = $policy['policy_low_response'];
        $reso = $policy['policy_low_resolution'];
    } elseif ($p === 'medium') {
        $resp = $policy['policy_medium_response'];
        $reso = $policy['policy_medium_resolution'];
    } else { // high / critical / anything else -> treat as high tier
        $resp = $policy['policy_high_response'];
        $reso = $policy['policy_high_resolution'];
    }
    $resp = ($resp === null || $resp === '') ? null : intval($resp);
    $reso = ($reso === null || $reso === '') ? null : intval($reso);
    return [$resp, $reso];
}

/**
 * Recalculate a ticket's SLA due dates. SAME SIGNATURE as legacy (drop-in).
 *
 * For each of response/resolution independently:
 *   - If a policy supplies a minute target for the ticket's priority, compute the
 *     due date with the policy's business-hours calendar, shifted forward by any
 *     accrued pause seconds.
 *   - Otherwise fall back to the EXACT legacy behaviour (contract hours from
 *     ticket_created_at; NULL when no contract / no hours).
 */
function recalculateTicketSla($mysqli, int $ticket_id): void {
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_contract_id, ticket_priority, ticket_created_at, ticket_sla_paused_seconds FROM tickets WHERE ticket_id = $ticket_id"
    ));
    if (!$ticket) return;

    $priority   = $ticket['ticket_priority'] ?? '';
    $created_ts = strtotime($ticket['ticket_created_at']);
    $paused_sec = intval($ticket['ticket_sla_paused_seconds'] ?? 0);

    // --- Legacy contract-hour targets (fallback for either field) ---
    $legacy_response_due   = 'NULL';
    $legacy_resolution_due = 'NULL';
    $contract_id = intval($ticket['ticket_contract_id'] ?? 0);
    if ($contract_id > 0) {
        $ct = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM contracts WHERE contract_id = $contract_id LIMIT 1"));
        if ($ct) {
            $p = strtolower($priority);
            $r_hours   = $p === 'low' ? intval($ct['contract_sla_low_response_time'])    : ($p === 'medium' ? intval($ct['contract_sla_medium_response_time'])    : intval($ct['contract_sla_high_response_time']));
            $res_hours = $p === 'low' ? intval($ct['contract_sla_low_resolution_time'])  : ($p === 'medium' ? intval($ct['contract_sla_medium_resolution_time'])  : intval($ct['contract_sla_high_resolution_time']));
            if ($r_hours > 0)   $legacy_response_due   = "'" . date('Y-m-d H:i:s', $created_ts + ($r_hours * 3600))   . "'";
            if ($res_hours > 0) $legacy_resolution_due = "'" . date('Y-m-d H:i:s', $created_ts + ($res_hours * 3600)) . "'";
        }
    }

    // --- Policy-based targets (business hours, minutes) ---
    $policy = slaGetPolicyForTicket($mysqli, $ticket_id);
    $policy_id_sql = 'NULL';
    $response_due   = $legacy_response_due;
    $resolution_due = $legacy_resolution_due;

    if ($policy) {
        $policy_id_sql = intval($policy['policy_id']);
        [$resp_min, $reso_min] = slaPolicyTargetsForPriority($policy, $priority);
        $calendar = slaLoadCalendar($mysqli, isset($policy['policy_calendar_id']) ? intval($policy['policy_calendar_id']) : null);
        $start = (new DateTime())->setTimestamp($created_ts);

        if ($resp_min !== null && $resp_min > 0) {
            $due = slaAddBusinessSeconds($start, ($resp_min * 60) + $paused_sec, $calendar);
            $response_due = "'" . $due->format('Y-m-d H:i:s') . "'";
        }
        if ($reso_min !== null && $reso_min > 0) {
            $due = slaAddBusinessSeconds($start, ($reso_min * 60) + $paused_sec, $calendar);
            $resolution_due = "'" . $due->format('Y-m-d H:i:s') . "'";
        }
    }

    mysqli_query($mysqli,
        "UPDATE tickets SET ticket_sla_response_due = $response_due, ticket_sla_resolution_due = $resolution_due, ticket_sla_policy_id = $policy_id_sql
         WHERE ticket_id = $ticket_id"
    );
}

/**
 * Accrue pause time across a status change.
 *   - Moving INTO a pause status: stamp ticket_sla_paused_at = NOW(), log 'pause'.
 *   - Moving OUT of a pause status: add the business-seconds spent paused to
 *     ticket_sla_paused_seconds, clear paused_at, log 'resume', then recalc.
 * No-op when the governing policy defines no pause statuses (non-breaking).
 */
function slaAccruePause($mysqli, int $ticket_id, int $old_status, int $new_status): void {
    if ($old_status === $new_status) return;

    $policy = slaGetPolicyForTicket($mysqli, $ticket_id);
    if (!$policy || empty($policy['policy_pause_status_ids'])) return;

    $pause_ids = array_values(array_filter(array_map('intval', explode(',', $policy['policy_pause_status_ids'])), function ($v) { return $v > 0; }));
    if (empty($pause_ids)) return;

    $was_paused = in_array($old_status, $pause_ids, true);
    $now_paused = in_array($new_status, $pause_ids, true);
    if ($was_paused === $now_paused) return; // no pause-boundary crossed

    if (!$was_paused && $now_paused) {
        // Entering pause
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_sla_paused_at FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
        if (empty($row['ticket_sla_paused_at'])) {
            mysqli_query($mysqli, "UPDATE tickets SET ticket_sla_paused_at = NOW() WHERE ticket_id = $ticket_id");
        }
        slaLogEvent($mysqli, $ticket_id, 'pause', $old_status, $new_status);
        return;
    }

    // Leaving pause
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_sla_paused_at FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    $paused_at = $row['ticket_sla_paused_at'] ?? null;
    if (!empty($paused_at)) {
        $calendar = slaLoadCalendar($mysqli, isset($policy['policy_calendar_id']) ? intval($policy['policy_calendar_id']) : null);
        $from = new DateTime($paused_at);
        $to   = new DateTime();
        $accrued = slaBusinessSecondsBetween($from, $to, $calendar);
        $accrued = max(0, intval($accrued));
        mysqli_query($mysqli, "UPDATE tickets SET ticket_sla_paused_seconds = ticket_sla_paused_seconds + $accrued, ticket_sla_paused_at = NULL WHERE ticket_id = $ticket_id");
    }
    slaLogEvent($mysqli, $ticket_id, 'resume', $old_status, $new_status);
    recalculateTicketSla($mysqli, $ticket_id);
}

/** Append a row to ticket_sla_events. */
function slaLogEvent($mysqli, int $ticket_id, string $event_type, ?int $from_status, ?int $to_status): void {
    $ticket_id = intval($ticket_id);
    $event_type = mysqli_real_escape_string($mysqli, substr($event_type, 0, 20));
    $from_sql = ($from_status === null) ? 'NULL' : intval($from_status);
    $to_sql   = ($to_status === null)   ? 'NULL' : intval($to_status);
    mysqli_query($mysqli,
        "INSERT INTO ticket_sla_events SET ticket_id = $ticket_id, event_type = '$event_type', from_status = $from_sql, to_status = $to_sql"
    );
}

/**
 * UI helper: response/resolution progress for a ticket. Returns:
 *   [
 *     'response'   => ['state'=>running|paused|met|breached|none, 'pct'=>0..100|null, 'remaining_sec'=>int|null],
 *     'resolution' => [ ... same ... ],
 *   ]
 * Due dates are already absolute business-hours deadlines, so remaining/pct use a
 * straight countdown to the stored due date; pause is reflected via the 'paused'
 * state (the due date itself is re-shifted on resume by recalculateTicketSla).
 */
function slaStatus(array $ticket, array $calendar): array {
    $now_ts     = time();
    $created_ts = !empty($ticket['ticket_created_at']) ? strtotime($ticket['ticket_created_at']) : $now_ts;
    $is_paused  = !empty($ticket['ticket_sla_paused_at']);

    $mk = function ($due, $met_at, $met_flag) use ($now_ts, $created_ts, $is_paused) {
        if (empty($due)) {
            return ['state' => 'none', 'pct' => null, 'remaining_sec' => null];
        }
        $due_ts = strtotime($due);
        $remaining = $due_ts - $now_ts;
        $total = $due_ts - $created_ts;
        $elapsed = $now_ts - $created_ts;
        $pct = ($total > 0) ? max(0, min(100, ($elapsed / $total) * 100)) : ($elapsed > 0 ? 100 : 0);

        $met = false;
        if ($met_flag !== null && $met_flag !== '') {
            $met = intval($met_flag) === 1;
        } elseif (!empty($met_at)) {
            $met = true;
        }

        if ($met) {
            $met_ts = !empty($met_at) ? strtotime($met_at) : $now_ts;
            $state = ($met_ts <= $due_ts) ? 'met' : 'breached';
            return ['state' => $state, 'pct' => 100, 'remaining_sec' => 0];
        }
        if ($remaining <= 0) {
            $state = 'breached';
        } elseif ($is_paused) {
            $state = 'paused';
        } else {
            $state = 'running';
        }
        return ['state' => $state, 'pct' => round($pct, 1), 'remaining_sec' => $remaining];
    };

    return [
        'response'   => $mk($ticket['ticket_sla_response_due']   ?? null, $ticket['ticket_first_response_at'] ?? null, $ticket['ticket_sla_response_met']   ?? null),
        'resolution' => $mk($ticket['ticket_sla_resolution_due'] ?? null, $ticket['ticket_resolved_at']       ?? null, $ticket['ticket_sla_resolution_met'] ?? null),
    ];
}
