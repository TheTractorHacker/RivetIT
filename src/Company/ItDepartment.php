<?php
declare(strict_types=1);

namespace ITFlow\Company;

/**
 * The IT department's own contact details (Administration > Settings > IT department details), kept next to Company Details in the
 * companies row. Anything that tells people "who to call" (the onboarding packet, later emails and the portal) reads it from here.
 */
final class ItDepartment
{
    public const FIELDS = ['name' => 100, 'phone' => 40, 'email' => 200, 'hours' => 200];

    /** @return array{name:string,phone:string,email:string,hours:string} all '' when unset or the columns do not exist yet (pre-migration). */
    public static function load(\mysqli $db): array
    {
        $out = ['name' => '', 'phone' => '', 'email' => '', 'hours' => ''];
        try {
            $r = $db->query('SELECT company_it_name, company_it_phone, company_it_email, company_it_hours FROM companies WHERE company_id = 1');
            $row = $r ? $r->fetch_assoc() : null;
            if ($row) {
                $out = ['name' => trim((string) $row['company_it_name']), 'phone' => trim((string) $row['company_it_phone']),
                    'email' => trim((string) $row['company_it_email']), 'hours' => trim((string) $row['company_it_hours'])];
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** "Name | phone | email" ('' when none is set). */
    public static function contactLine(array $it): string
    {
        return implode(' | ', array_filter([$it['name'] ?? '', $it['phone'] ?? '', $it['email'] ?? ''], static fn($p): bool => trim((string) $p) !== ''));
    }

    /** Validates a form post into the four clean values. Throws \InvalidArgumentException (user-safe). @return array<string,string> */
    public static function clean(array $post): array
    {
        $out = [];
        foreach (self::FIELDS as $k => $max) {
            $v = $post[$k] ?? '';
            if (!is_string($v)) {
                throw new \InvalidArgumentException('A field is not valid.');
            }
            $v = trim((string) preg_replace('/\s+/u', ' ', strip_tags($v)));
            if (mb_strlen($v, 'UTF-8') > $max) {
                throw new \InvalidArgumentException(ucfirst($k) . " is too long (maximum $max characters).");
            }
            if ($k === 'email' && $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Enter a valid email address.');
            }
            $out[$k] = $v;
        }
        return $out;
    }
}
