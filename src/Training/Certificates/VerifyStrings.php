<?php

namespace ITFlow\Training\Certificates;

/**
 * Every word on the public certificate check (/verify/), in English and Spanish (Phase 5 spec
 * §5.1). The page maps P2's certificate status to these strings; P2's status_label is never shown.
 * Sentences with a date carry a '{date}' placeholder, filled with date() output (escaped by the page).
 */
final class VerifyStrings
{
    public const STATES = ['valid', 'expiring', 'expired', 'revoked', 'not_found', 'throttled', 'unavailable'];

    private const EN = [
        'lang' => 'en',
        'title' => 'Certificate check',
        'states' => [
            'valid' => ['Valid', 'This certificate is current.'],
            'expiring' => ['Expiring soon', 'This certificate is current and expires on {date}.'],
            'expired' => ['Expired', 'This certificate expired on {date}.'],
            'revoked' => ['Revoked', 'This certificate has been revoked.'],
            'not_found' => ['Not found', "We couldn't find a certificate for this code. Check that the whole QR code was scanned."],
            'throttled' => ['Try again shortly', 'Too many checks right now. Try again in a minute.'],
            'unavailable' => ['Not available', 'Certificate checks are not available right now.'],
        ],
        'checked' => 'Checked {date} at {time}',
        'name' => 'Name',
        'course' => 'Course',
        'issued' => 'Issued',
        'expires' => 'Expires',
        'no_expiry' => 'No expiry',
        'certificate' => 'Certificate',
        'record_no' => 'Record no.',
        'external' => 'External card recorded',
        'note' => 'Only these details are shown. Records are kept in ITFlow by {company}.',
        'note_plain' => 'Only these details are shown.',
        'logo_alt' => '{company} logo',
        'switch' => 'Español',
        'switch_lang' => 'es',
    ];

    private const ES = [
        'lang' => 'es',
        'title' => 'Verificación de certificado',
        'states' => [
            'valid' => ['Vigente', 'Este certificado está vigente.'],
            'expiring' => ['Vence pronto', 'Este certificado está vigente y vence el {date}.'],
            'expired' => ['Vencido', 'Este certificado venció el {date}.'],
            'revoked' => ['Revocado', 'Este certificado fue revocado.'],
            'not_found' => ['No encontrado', 'No encontramos un certificado para este código. Verifique que se escaneó todo el código QR.'],
            'throttled' => ['Intente de nuevo', 'Demasiadas consultas en este momento. Intente de nuevo en un minuto.'],
            'unavailable' => ['No disponible', 'La verificación de certificados no está disponible en este momento.'],
        ],
        'checked' => 'Consultado el {date} a las {time}',
        'name' => 'Nombre',
        'course' => 'Curso',
        'issued' => 'Emitido',
        'expires' => 'Vence',
        'no_expiry' => 'Sin vencimiento',
        'certificate' => 'Certificado',
        'record_no' => 'Registro n.º',
        'external' => 'Tarjeta externa registrada',
        'note' => 'Solo se muestran estos datos. {company} guarda los registros en ITFlow.',
        'note_plain' => 'Solo se muestran estos datos.',
        'logo_alt' => 'Logotipo de {company}',
        'switch' => 'English',
        'switch_lang' => 'en',
    ];

    private const ES_MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    /** @return array<string, mixed> */
    public static function for(string $lang): array
    {
        return $lang === 'es' ? self::ES : self::EN;
    }

    /** 'es' when the query asks for it or the browser prefers Spanish; 'en' otherwise. */
    public static function pick(mixed $param, ?string $acceptLanguage): string
    {
        if ($param === 'es' || $param === 'en') {
            return $param;
        }
        return preg_match('/^\s*es\b/i', (string) $acceptLanguage) === 1 ? 'es' : 'en';
    }

    /** A 'Y-m-d' as "Sep 23, 2026" / "23 sept 2026"; '' when it is not a date. */
    public static function date(?string $ymd, string $lang): string
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        if ($lang === 'es') {
            return (int) $m[3] . ' ' . self::ES_MONTHS[(int) $m[2] - 1] . ' ' . $m[1];
        }
        return date('M j, Y', mktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]));
    }

    /** "Checked Sep 23, 2026 at 2:14 PM" / "Consultado el 23 sept 2026 a las 14:14" for a Unix time (local zone). */
    public static function checked(int $ts, string $lang): string
    {
        $s = self::for($lang);
        $time = $lang === 'es' ? date('G:i', $ts) : date('g:i A', $ts);
        return strtr($s['checked'], ['{date}' => self::date(date('Y-m-d', $ts), $lang), '{time}' => $time]);
    }
}
