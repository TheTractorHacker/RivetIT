<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Clock;

/**
 * Display strings and small pure helpers shared by the report services and pages (Lane D).
 *
 * The frozen wording lives in the Phase 2 spec: evidence strength (§1.4 #5), anchor labels
 * (§4.1) and compliance bands (§5.1). Nothing here reads the database or the request.
 */
final class Labels
{
    /** Evidence strength legend (§1.4 #5; details as in the Admin-Transcript mockup). */
    public const STRENGTH = [
        'A' => ['label' => 'PIN + signature', 'detail' => 'employee signed at the kiosk'],
        'B' => ['label' => 'Trainer session', 'detail' => 'trainer and employee signed'],
        'C' => ['label' => 'Trainer attests', 'detail' => 'employee did not sign'],
        'D' => ['label' => 'Scan on file', 'detail' => 'scan of a card or certificate on file'],
        'E' => ['label' => 'Recorded by office', 'detail' => 'nothing signed'],
    ];

    public const METHOD = [
        'online' => 'Online',
        'session' => 'Instructor-led session',
        'blended' => 'Blended',
        'evaluation' => 'Practical evaluation',
        'external' => 'External card',
        'legacy_paper' => 'Paper record',
    ];

    /** Compliance band thresholds (§5.1): 0 <70, 1 70-79, 2 80-89, 3 90..target-1, 4 >= target. */
    public static function band(?int $pct, int $target): ?int
    {
        if ($pct === null) {
            return null;
        }
        if ($pct >= $target) {
            return 4;
        }
        if ($pct >= 90) {
            return 3;
        }
        if ($pct >= 80) {
            return 2;
        }
        return $pct >= 70 ? 1 : 0;
    }

    /**
     * Whole percent, rounded down like Compliance\PairRules::pct (so 99.6% shows 99%, never a
     * 100% that is not). Null when there is no denominator.
     */
    public static function pct(int $num, int $den): ?int
    {
        if ($den <= 0) {
            return null;
        }
        return (int) floor(100 * max(0, min($num, $den)) / $den);
    }

    /**
     * Evidence strength letter A..E for a (method, proof) pair. Delegates to Lane C's
     * Records\EvidenceStrength when it is present; the fallback is the same frozen §1.4 #5
     * table, so a page never breaks while the records engine is being deployed.
     */
    public static function grade(string $method, string $proof): string
    {
        if (class_exists(\ITFlow\Training\Records\EvidenceStrength::class)) {
            try {
                $g = \ITFlow\Training\Records\EvidenceStrength::grade($method, $proof);
                if (is_string($g) && isset(self::STRENGTH[$g])) {
                    return $g;
                }
            } catch (\Throwable) {
                // fall through to the table
            }
        }
        if ($proof === 'agent_recorded') {
            return 'E';
        }
        if ($proof === 'document') {
            return 'D';
        }
        if ($proof === 'trainer_attested') {
            return 'C';
        }
        if ($method === 'online' && $proof === 'self_pin_signature') {
            return 'A';
        }
        return 'B';
    }

    /** The per-record strength label; D reads "External card" for an outside card. */
    public static function strengthLabel(string $grade, string $method): string
    {
        if ($grade === 'D' && $method === 'external') {
            return 'External card';
        }
        return self::STRENGTH[$grade]['label'] ?? $grade;
    }

    public static function methodLabel(string $method): string
    {
        return self::METHOD[$method] ?? ucfirst(str_replace('_', ' ', $method));
    }

    /** "12 months", "3 years", or null for a course that does not expire. */
    public static function validity(?int $months): ?string
    {
        if ($months === null || $months <= 0) {
            return null;
        }
        if ($months % 12 === 0 && $months >= 24) {
            return ($months / 12) . ' years';
        }
        return $months . ($months === 1 ? ' month' : ' months');
    }

    /**
     * Assignment anchor label (§4.1): initial "Required by {rule}", renew "Renewal · expires
     * {date}" ("expired" once the date has passed), retrain "Retrain · Version {n}", reissue "Record voided · redo"
     * ("Take again (record {cert} voided)" when $retakeCert is set: the record was voided by "Reset (take again)"; '' = no number).
     */
    public static function anchor(?string $anchor, ?string $ruleName, ?string $expiresOn = null, ?int $revisionNumber = null, bool $manual = false,
                                  ?string $retakeCert = null): string
    {
        $anchor = (string) $anchor;
        if (str_starts_with($anchor, 'renew:')) {
            return 'Renewal' . ($expiresOn !== null ? ($expiresOn < Clock::todayLocal() ? ' · expired ' : ' · expires ') . self::shortDate($expiresOn) : '');
        }
        if (str_starts_with($anchor, 'retrain:')) {
            return 'Retrain' . ($revisionNumber !== null ? ' · Version ' . $revisionNumber : '');
        }
        if (str_starts_with($anchor, 'reissue:')) {
            return $retakeCert !== null ? 'Take again (record ' . ($retakeCert !== '' ? $retakeCert . ' ' : '') . 'voided)' : 'Record voided · redo';
        }
        if ($manual && $ruleName !== null && $ruleName !== '') {
            return $ruleName;   // a hand-made rule is named "Assigned by {user} on {date}"
        }
        return $ruleName !== null && $ruleName !== '' ? 'Required by ' . $ruleName : 'Required';
    }

    /** Why an assignment closed, in plain words ('' when open). Unknown codes read as words. */
    public static function closeReason(?string $code): string
    {
        return match ($code) {
            null, '' => '',
            'completed' => 'Completed',
            'no_longer_required' => 'No longer required',
            'contact_ineligible' => 'Not on the roster',
            'superseded' => 'Replaced by a newer assignment',
            'waived' => 'Waived',
            'unwaived' => 'Waiver ended',
            default => ucfirst(str_replace('_', ' ', $code)),
        };
    }

    /** "Sep 8, 2026" for a Y-m-d, or the input unchanged when it is not one. */
    public static function shortDate(?string $ymd): string
    {
        if ($ymd === null || !Clock::isYmd($ymd)) {
            return (string) $ymd;
        }
        return (new \DateTimeImmutable($ymd . ' 00:00:00'))->format('M j, Y');
    }

    /** "September 23, 2026". */
    public static function longDate(?string $ymd): string
    {
        if ($ymd === null || !Clock::isYmd($ymd)) {
            return (string) $ymd;
        }
        return (new \DateTimeImmutable($ymd . ' 00:00:00'))->format('F j, Y');
    }

    /** Whole days from $fromYmd to $toYmd (negative when $to is earlier). */
    public static function days(string $fromYmd, string $toYmd): int
    {
        $a = new \DateTimeImmutable($fromYmd . ' 00:00:00', new \DateTimeZone('UTC'));
        $b = new \DateTimeImmutable($toYmd . ' 00:00:00', new \DateTimeZone('UTC'));
        return (int) $a->diff($b)->format('%r%a');
    }

    /** Up to two initials from a person's name ("Marcus Reyes" => "MR"). */
    public static function initials(?string $name): string
    {
        $parts = preg_split('/[\s\-]+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return '?';
        }
        $first = mb_substr($parts[0], 0, 1, 'UTF-8');
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
        return mb_strtoupper($first . $last, 'UTF-8');
    }

    /** A short column heading for a course: its code when it has one, else its name. */
    public static function shortCourse(?string $code, string $name): string
    {
        $code = trim((string) $code);
        return $code !== '' ? $code : $name;
    }

    /** '92.00' => '92', '87.50' => '87.5', null => null. */
    public static function score(?string $pct): ?string
    {
        if ($pct === null || $pct === '' || !is_numeric($pct)) {
            return null;
        }
        $s = rtrim(rtrim(sprintf('%.2f', (float) $pct), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /** The regulation line on a certificate (§5.1). */
    public static function regulation(?string $ref): ?string
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        if (preg_match('/^19\d\d\./', $ref) === 1) {
            return 'Meets the training requirements of OSHA 29 CFR ' . $ref;
        }
        return 'Reference: ' . $ref;
    }
}
