<?php

namespace ITFlow\Training\Compliance;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\RecordsSettings;

/**
 * The single source of pair, certificate and satisfaction logic (Phase 2 spec §1.4 #4, §3.3).
 * Static and pure: no database, no clock. Every caller passes `today` (a local 'Y-m-d' from
 * Clock::todayLocal()) and the facts RecordFacts loaded, so reconcile, CompletionService::issue(),
 * the compliance reads, the certificate and (Phase 5) public verify all agree by construction.
 *
 * Shapes
 *   completion c : {completion_id:?int, completed_on:'Y-m-d', expires_on:?'Y-m-d', revision_number:?int,
 *                   supersedes_id:?int, voided_at_utc:?string}      (completion_id null = the row being inserted)
 *   RR           : {revision_id:int, revision_number:int, published_on:'Y-m-d' (local), retrain_due_days:int}
 *                   the latest requires_retraining revision of the course, or null
 *   desired d    : {due_on, requirement_id, required:bool, one_time:bool, onboarding_since:?'Y-m-d'} (RuleMatcher)
 *   facts f      : {latest:?c, latestVoided:?c, waiver:?array, rr:?RR, recentCancelled:list, anchorRefs:{c:{id:c}, r:{id:RR}}}
 *   open O       : {id, anchor, due_on, onboarding_from_on:?'Y-m-d', …} (AssignmentStore::normalize)
 *   course       : {validity_months:?int, renewal_lead_days:int} from the current revision JSON (course.*)
 *
 * Anchors: 'initial' | 'renew:c<completion_id>' | 'retrain:r<revision_id>' | 'reissue:c<completion_id>'.
 */
final class PairRules
{
    /** Pair statuses that count as current (the compliance numerator). `waived` is out of the denominator. */
    public const CURRENT_STATUSES = ['current', 'expiring', 'retrain_due'];

    /** Fixed look-ahead for "Expiring" on a pair without an open assignment (spec §3.3 table). */
    public const EXPIRING_LOOKAHEAD_DAYS = 30;

    /** Does completion c predate the retrain revision RR? */
    public static function predates(array $c, ?array $rr): bool
    {
        if ($rr === null) {
            return false;
        }
        $num = $c['revision_number'] ?? null;
        if ($num !== null) {
            return (int) $num < (int) $rr['revision_number'];
        }
        return (string) $c['completed_on'] < (string) $rr['published_on'];
    }

    /** Revoked by retraining only once the grace (published_on + retrain_due_days) has run out. */
    public static function revokedByRetrain(array $c, ?array $rr, string $today): bool
    {
        if ($rr === null || !self::predates($c, $rr)) {
            return false;
        }
        return $today > Clock::addDays((string) $rr['published_on'], (int) ($rr['retrain_due_days'] ?? 0));
    }

    public static function isValid(array $c, ?array $rr, string $today): bool
    {
        if (($c['voided_at_utc'] ?? null) !== null) {
            return false;
        }
        $exp = $c['expires_on'] ?? null;
        if ($exp !== null && (string) $exp < $today) {
            return false;
        }
        return !self::revokedByRetrain($c, $rr, $today);
    }

    /**
     * What should be open for this pair right now: null, or {anchor, reason, due_on}.
     * $d null = not desired (no matching rule, or the person is not eligible).
     */
    public static function want(?array $d, array $f, array $course, string $today, RecordsSettings $s): ?array
    {
        if ($d === null || !empty($f['waiver'])) {
            return null;
        }
        $L = $f['latest'] ?? null;
        $V = $f['latestVoided'] ?? null;
        $RR = $f['rr'] ?? null;

        if ($V !== null && !($L !== null && self::isValid($L, $RR, $today))) {
            $due = Clock::addDays(Clock::localDate((string) $V['voided_at_utc']), $s->reissueDays);
            return ['anchor' => 'reissue:c' . (int) $V['completion_id'], 'reason' => 'reissue', 'due_on' => max($due, $today)];
        }
        if ($L === null) {
            return ['anchor' => 'initial', 'reason' => 'requirement', 'due_on' => (string) $d['due_on']];
        }
        if ($RR !== null && self::predates($L, $RR)) {
            $from = max((string) $RR['published_on'], $today);
            return ['anchor' => 'retrain:r' . (int) $RR['revision_id'], 'reason' => 'retrain',
                    'due_on' => Clock::addDays($from, (int) ($RR['retrain_due_days'] ?? 0))];
        }
        $exp = $L['expires_on'] ?? null;
        $lead = (int) ($course['renewal_lead_days'] ?? 0);
        if ($exp !== null && empty($d['one_time']) && $today >= Clock::addDays((string) $exp, -$lead)) {
            return ['anchor' => 'renew:c' . (int) $L['completion_id'], 'reason' => 'renewal',
                    'due_on' => max((string) $exp, Clock::addDays($today, $lead))];
        }
        return null;
    }

    /** Does completion c close the open assignment O? (Also used on the row being inserted, completion_id null.) */
    public static function satisfies(array $o, array $c, array $f, string $today): bool
    {
        if (!self::isValid($c, $f['rr'] ?? null, $today)) {
            return false;
        }
        $floor = $o['onboarding_from_on'] ?? null;
        if ($floor !== null && (string) $c['completed_on'] < (string) $floor) {
            return false;
        }
        $anchor = (string) ($o['anchor'] ?? '');
        if ($anchor === 'initial') {
            return true;
        }
        $cid = isset($c['completion_id']) ? (int) $c['completion_id'] : null;
        if (preg_match('/^renew:c(\d+)$/D', $anchor, $m) === 1) {
            $x = (int) $m[1];
            if ($cid === $x) {
                return false;
            }
            $ref = $f['anchorRefs']['c'][$x] ?? null;
            return $ref === null ? true : (string) $c['completed_on'] > (string) $ref['completed_on'];
        }
        if (preg_match('/^retrain:r(\d+)$/D', $anchor, $m) === 1) {
            $r = (int) $m[1];
            $rr = $f['anchorRefs']['r'][$r] ?? ((isset($f['rr']) && (int) $f['rr']['revision_id'] === $r) ? $f['rr'] : null);
            return $rr === null ? true : !self::predates($c, $rr);
        }
        if (preg_match('/^reissue:c(\d+)$/D', $anchor, $m) === 1) {
            $v = (int) $m[1];
            if (isset($c['supersedes_id']) && (int) $c['supersedes_id'] === $v) {
                return true;
            }
            $ref = $f['anchorRefs']['c'][$v] ?? null;
            if ($ref === null || ($ref['voided_at_utc'] ?? null) === null) {
                return false;
            }
            return (string) $c['completed_on'] >= Clock::localDate((string) $ref['voided_at_utc']);
        }
        return false;
    }

    /**
     * Pair status (labels frozen, spec §3.3):
     * {status, label, counts_current:bool, in_denominator:bool, due_on:?string, expires_on:?string, days_overdue:int, anchor:?string, lapsed:bool}
     * An open renewal whose certificate already expired keeps its status key (due_soon / due) but is labelled
     * "Expired — not qualified" with lapsed:true, the same label an overdue lapsed renewal has.
     */
    public static function pairStatus(?array $d, ?array $o, array $f, string $today, RecordsSettings $s): array
    {
        $L = $f['latest'] ?? null;
        $RR = $f['rr'] ?? null;
        $valid = $L !== null && self::isValid($L, $RR, $today);
        $exp = $L['expires_on'] ?? null;
        $out = static function (string $status, string $label, ?string $due, ?string $anchor, bool $lapsed = false) use ($exp, $today): array {
            $daysOver = ($due !== null && $due < $today) ? self::daysBetween($due, $today) : 0;
            return [
                'status' => $status,
                'label' => $label,
                'counts_current' => in_array($status, self::CURRENT_STATUSES, true),
                'in_denominator' => $status !== 'waived',
                'due_on' => $due,
                'expires_on' => $exp,
                'days_overdue' => $status === 'overdue' ? $daysOver : 0,
                'anchor' => $anchor,
                // The certificate behind an open renewal has already expired: not qualified now, even while the renewal
                // is not yet due (its due date is max(expiry, today + lead), so a card that lapsed before it was entered
                // is never overdue on day one). Display only: the status key and the counts are unchanged.
                'lapsed' => $lapsed,
            ];
        };

        if (!empty($f['waiver'])) {
            return $out('waived', 'Waived', null, null);
        }
        if ($o !== null) {
            $anchor = (string) $o['anchor'];
            $due = (string) $o['due_on'];
            if (str_starts_with($anchor, 'renew:') && $valid) {
                return $out('expiring', 'Expiring ' . $exp, $due, $anchor);
            }
            if (str_starts_with($anchor, 'retrain:') && $valid) {
                return $out('retrain_due', 'Retrain due ' . $due, $due, $anchor);
            }
            $lapsed = str_starts_with($anchor, 'renew:') && $exp !== null && $exp < $today;
            if ($due < $today) {
                return $out('overdue', $lapsed ? 'Expired — not qualified' : 'Overdue', $due, $anchor, $lapsed);
            }
            if ($due <= Clock::addDays($today, $s->dueSoonDays)) {
                return $out('due_soon', $lapsed ? 'Expired — not qualified' : 'Due soon', $due, $anchor, $lapsed);
            }
            return $out('due', $lapsed ? 'Expired — not qualified' : 'Assigned', $due, $anchor, $lapsed);
        }
        if ($valid) {
            if ($exp !== null && $exp <= Clock::addDays($today, self::EXPIRING_LOOKAHEAD_DAYS)) {
                return $out('expiring', 'Expiring ' . $exp, null, null);
            }
            return $out('current', 'Current', null, null);
        }
        if ($L !== null) {
            return $out('expired', self::revokedByRetrain($L, $RR, $today) ? 'Revoked — retrain required' : 'Expired', null, null);
        }
        return $out('not_started', 'Not assigned yet', null, null);
    }

    /**
     * Certificate status of one completion: {status:'valid'|'expiring'|'expired'|'revoked', reason:?'voided'|'retrain_required', label}.
     */
    public static function certStatus(array $c, ?array $rr, string $today, int $dueSoonDays): array
    {
        if (($c['voided_at_utc'] ?? null) !== null) {
            return ['status' => 'revoked', 'reason' => 'voided', 'label' => 'Revoked: record voided'];
        }
        if (self::revokedByRetrain($c, $rr, $today)) {
            return ['status' => 'revoked', 'reason' => 'retrain_required', 'label' => 'Revoked: retrain required'];
        }
        $exp = $c['expires_on'] ?? null;
        if ($exp !== null && (string) $exp < $today) {
            return ['status' => 'expired', 'reason' => null, 'label' => 'Expired ' . $exp];
        }
        if ($exp !== null && (string) $exp <= Clock::addDays($today, $dueSoonDays)) {
            return ['status' => 'expiring', 'reason' => null, 'label' => 'Expiring ' . $exp];
        }
        return ['status' => 'valid', 'reason' => null, 'label' => 'Valid'];
    }

    /** Compliance %: pairs counting as current / required pairs not waived; null when the denominator is 0. */
    public static function pct(int $current, int $denominator): ?int
    {
        return $denominator > 0 ? (int) floor($current * 100 / $denominator) : null;
    }

    /** Whole calendar days from $from to $to ('Y-m-d'); negative when $to is earlier. */
    public static function daysBetween(string $from, string $to): int
    {
        $a = new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC'));
        return (int) (($b->getTimestamp() - $a->getTimestamp()) / 86400);
    }

    /** The anchor kind ('initial'|'renew'|'retrain'|'reissue') and its id (null for initial). */
    public static function parseAnchor(string $anchor): array
    {
        if (preg_match('/^(renew|reissue):c(\d+)$/D', $anchor, $m) === 1) {
            return ['kind' => $m[1], 'id' => (int) $m[2]];
        }
        if (preg_match('/^retrain:r(\d+)$/D', $anchor, $m) === 1) {
            return ['kind' => 'retrain', 'id' => (int) $m[1]];
        }
        return ['kind' => 'initial', 'id' => null];
    }
}
