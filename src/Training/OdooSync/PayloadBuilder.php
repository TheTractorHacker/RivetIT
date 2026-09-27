<?php

namespace ITFlow\Training\OdooSync;

use ITFlow\Training\Core\Text;

/**
 * What an Odoo resume line says (spec §1.4 #7, plan A7): course, date, certificate number, how it
 * was recorded, expiry, the marker and "Record of truth: ITFlow". Never a score, a verify URL, a
 * PDF or a PIN. Built at push time from the current record; the outbox stores exactly what was
 * sent (todoo_payload_json) for audit.
 *
 * Payload = {title, date_start, date_end|null, cert_number|null, type_label, voided_on|null, marker, company}
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
        $lines[] = 'Record of truth: ITFlow' . ($company !== '' ? ' (' . $e($company) . ')' : '');
        $lines[] = 'ITFlow ref: ' . $e((string) $p['marker']);
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
