<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Product;
use ITFlow\Training\Core\Text;

/**
 * What an Odoo resume line says (spec §1.4 #7, plan A7): course, date, certificate number, how it
 * was recorded, expiry, the marker and "Record of truth: RivetIT" (APP_NAME). Never a score, a verify
 * URL, a PDF or a PIN. Built at push time from the current record; the outbox stores exactly what was
 * sent (todoo_payload_json) for audit.
 *
 * Only the marker ([ITFLOW:<inst8>:C…], see Marker) is ever matched. The words around it are free text:
 * lines written before the RivetIT rename still say "ITFlow" in Odoo, and nothing depends on that.
 *
 * Payload = {title, date_start, date_end|null, cert_number|null, type_label, voided_on|null, marker, company}
 *
 * The same payload feeds every target (résumé line, certification skill, HR note); only the Odoo values differ.
 */
final class PayloadBuilder
{
    /** @param array $dto the Upstream CompletionPushDTO */
    public static function completion(array $dto, string $marker, string $company = ''): array
    {
        return [
            'title' => (string) $dto['course_name'],
            'date_start' => (string) $dto['completed_on'],
            'date_end' => self::date($dto['expires_on'] ?? null),
            'cert_number' => isset($dto['cert_number']) && $dto['cert_number'] !== '' ? (string) $dto['cert_number'] : null,
            'type_label' => (string) ($dto['method_label'] ?? ''),
            'voided_on' => self::date($dto['voided_on'] ?? null),
            'marker' => $marker,
            'company' => $company,
        ];
    }

    /** @param array $dto {award_id, contact_id, achievement_id, achievement_name, awarded_on} */
    public static function award(array $dto, string $marker, string $company = ''): array
    {
        return [
            'title' => 'Achievement: ' . (string) $dto['achievement_name'],
            'date_start' => (string) $dto['awarded_on'],
            'date_end' => null,
            'cert_number' => null,
            'type_label' => 'Achievement',
            'voided_on' => null,
            'marker' => $marker,
            'company' => $company,
        ];
    }

    /**
     * hr.resume.line values for a create. Never display_type (Odoo 19 has none). line_type_id only
     * when the field exists; course_type 'external' when the field exists (Odoo requires it).
     */
    public static function resumeVals(array $p, int $employeeId, ?int $lineTypeId, array $resumeFields): array
    {
        $start = (string) $p['date_start'];
        $end = $p['date_end'] ?? null;
        $vals = [
            'employee_id' => $employeeId,
            'name' => (string) Text::clip((string) $p['title'], 250),
            'date_start' => $start,
            'date_end' => $end !== null ? max($start, (string) $end) : false,
            'description' => self::descriptionHtml($p),
        ];
        if ($lineTypeId !== null && $lineTypeId > 0 && in_array('line_type_id', $resumeFields, true)) {
            $vals['line_type_id'] = $lineTypeId;
        }
        if (in_array('course_type', $resumeFields, true)) {
            $vals['course_type'] = 'external';
        }
        return $vals;
    }

    /**
     * The write for a void: end the line on the void date, but never extend an already-ended line
     * and never end it before it started; mark the name " (revoked)" once. Idempotent on retry.
     *
     * @param array $existing the Odoo line {name, date_start, date_end(false|Y-m-d)}
     */
    public static function resumeClose(array $p, array $existing): array
    {
        $voided = (string) ($p['voided_on'] ?? '');
        if ($voided === '') {
            throw new \InvalidArgumentException('resumeClose: the record is not voided');
        }
        $start = (string) ($existing['date_start'] ?? '');
        $end = self::date($existing['date_end'] ?? null) ?? $voided;
        $name = (string) ($existing['name'] ?? '');
        return [
            'date_end' => max($start, min($end, $voided)),
            'name' => str_ends_with($name, ' (revoked)') ? $name : (string) Text::clip($name . ' (revoked)', 250),
        ];
    }

    /**
     * hr.employee.skill values for a certification (Odoo 19 hr_skills): the four required relations, valid_from =
     * the completion (or award) date, valid_to = the expiry or false (no end). Never is_certification (a readonly
     * related field: it follows the skill type). valid_to never before valid_from (Odoo's _check_date).
     */
    public static function skillVals(array $p, int $employeeId, int $skillId, int $levelId, int $typeId): array
    {
        $from = (string) $p['date_start'];
        $to = self::date($p['date_end'] ?? null);
        return [
            'employee_id' => $employeeId,
            'skill_id' => $skillId,
            'skill_level_id' => $levelId,
            'skill_type_id' => $typeId,
            'valid_from' => $from,
            'valid_to' => $to !== null ? max($from, $to) : false,
        ];
    }

    /**
     * The valid_to a void writes on a certification, or null when the stored one must stay. Odoo's own archive
     * convention (hr.individual.skill.mixin._expire_individual_skills) ends a skill "yesterday": here the day
     * before the void, unless that would end it before it started (voided on its first day) - then the void date.
     * Never extends an end that is already earlier (an expired certification keeps its expiry), never before
     * valid_from. Idempotent: a retry computes the same date.
     *
     * @param array $existing the Odoo record {valid_from: Y-m-d, valid_to: Y-m-d|false}
     */
    public static function skillCloseTo(array $p, array $existing): ?string
    {
        $voided = self::date($p['voided_on'] ?? null);
        if ($voided === null) {
            throw new \InvalidArgumentException('skillCloseTo: the record is not voided');
        }
        $from = self::date($existing['valid_from'] ?? null) ?? $voided;
        $dayBefore = Clock::addDays($voided, -1);
        $want = $dayBefore >= $from ? $dayBefore : max($from, $voided);
        $current = self::date($existing['valid_to'] ?? null);
        $new = $current !== null ? min($current, $want) : $want;
        return $new === $current ? null : $new;
    }

    /**
     * The HR note (message_post body, html): what the résumé description says (plan A7), one <p> per fact,
     * every value escaped. No score, no verify URL, no PDF, no other person's name.
     */
    public static function noteHtml(array $p, bool $isAward): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [];
        $lines[] = $isAward ? $e((string) $p['title']) : 'Training record: ' . $e((string) $p['title']);
        $lines[] = ($isAward ? 'Awarded on ' : 'Completed on ') . $e((string) $p['date_start']);
        if (!empty($p['cert_number'])) {
            $lines[] = 'Certificate ' . $e((string) $p['cert_number']);
        }
        if (!empty($p['date_end'])) {
            $lines[] = 'Expires ' . $e((string) $p['date_end']);
        }
        if (!$isAward && ($p['type_label'] ?? '') !== '') {
            $lines[] = 'Recorded as: ' . $e((string) $p['type_label']);
        }
        $company = trim((string) ($p['company'] ?? ''));
        $lines[] = 'Record of truth: ' . $e(Product::name()) . ($company !== '' ? ' (' . $e($company) . ')' : '');
        $lines[] = $e(Product::name()) . ' ref: ' . $e((string) $p['marker']);
        return '<p>' . implode('</p><p>', $lines) . '</p>';
    }

    /**
     * The follow-up note on a void: "Training record LMS-… (Forklift Safety, completed 2026-09-27) was voided in RivetIT on
     * {date}." plus its own marker. The course and completion date (both already in the first note) let HR find that note.
     */
    public static function noteVoidHtml(array $p, string $voidMarker): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $voided = self::date($p['voided_on'] ?? null);
        if ($voided === null) {
            throw new \InvalidArgumentException('noteVoidHtml: the record is not voided');
        }
        $what = !empty($p['cert_number'])
            ? 'Training record ' . $e((string) $p['cert_number']) . ' (' . $e((string) $p['title']) . ', completed ' . $e((string) $p['date_start']) . ')'
            : 'Training record "' . $e((string) $p['title']) . '" (completed ' . $e((string) $p['date_start']) . ')';
        return '<p>' . $what . ' was voided in ' . $e(Product::name()) . ' on ' . $e($voided) . '.</p><p>' . $e(Product::name()) . ' ref: ' . $e($voidMarker) . '</p>';
    }

    /** One <p> per fact; every value escaped (Odoo sanitises html too). */
    public static function descriptionHtml(array $p): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = [];
        if (!empty($p['cert_number'])) {
            $lines[] = 'Certificate ' . $e((string) $p['cert_number']);
        }
        if (($p['type_label'] ?? '') !== '') {
            $lines[] = 'Recorded as: ' . $e((string) $p['type_label']);
        }
        if (!empty($p['date_end'])) {
            $lines[] = 'Expires ' . $e((string) $p['date_end']);
        }
        $company = trim((string) ($p['company'] ?? ''));
        $lines[] = 'Record of truth: ' . $e(Product::name()) . ($company !== '' ? ' (' . $e($company) . ')' : '');
        $lines[] = $e(Product::name()) . ' ref: ' . $e((string) $p['marker']);
        return '<p>' . implode('</p><p>', $lines) . '</p>';
    }

    /** A Y-m-d string, or null for null/false/'' (Odoo reports an empty date as false). */
    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }
        return $v;
    }
}
