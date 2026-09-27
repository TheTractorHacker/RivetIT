<?php

namespace ITFlow\Training\Certificates;

/**
 * Who issues the PDFs (Phase 5 spec §3.3, S1/S2): the company name and logo (companies row 1,
 * the same fields the app header and the HTML certificate use) plus the certificate signatory from
 * Training settings (AutomationSettings::loadCert()).
 *
 * Returns {company_name, logo_path:?string, signer_name:?string, signer_title:?string, signer_png:?string}.
 * logo_path is absolute and only set when the stored file name is a plain
 * ^[A-Za-z0-9._-]+\.(png|jpe?g)$ name that exists under uploads/settings/. signer_png holds the
 * decoded bytes of the stored base64 PNG (re-encoded by CertAdmin on upload), or null.
 */
final class Brand
{
    public const LOGO_RE = '/^[A-Za-z0-9._-]+\.(png|jpe?g)$/D';

    public static function load(\mysqli $db, array $certSettings): array
    {
        $name = '';
        $logo = null;
        try {
            $res = $db->query('SELECT company_name, company_logo FROM companies WHERE company_id = 1');
            $row = $res ? $res->fetch_assoc() : null;
            $name = trim((string) ($row['company_name'] ?? ''));
            $file = (string) ($row['company_logo'] ?? '');
            $path = dirname(__DIR__, 3) . '/uploads/settings/' . $file;
            if (preg_match(self::LOGO_RE, $file) === 1 && is_file($path)) {
                $logo = $path;
            }
        } catch (\Throwable $e) {
            error_log('Training PDF brand: ' . get_class($e) . ': ' . $e->getMessage());
        }
        $str = static function ($v, int $max): ?string {
            $v = trim((string) $v);
            return $v === '' ? null : mb_substr($v, 0, $max, 'UTF-8');
        };
        $png = null;
        $b64 = $certSettings['tauto_cert_signer_png'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $raw = base64_decode($b64, true);
            if (is_string($raw) && strncmp($raw, "\x89PNG\r\n\x1a\n", 8) === 0) {
                $png = $raw;
            }
        }
        return [
            'company_name' => $name,
            'logo_path' => $logo,
            'signer_name' => $str($certSettings['tauto_cert_signer_name'] ?? null, 200),
            'signer_title' => $str($certSettings['tauto_cert_signer_title'] ?? null, 200),
            'signer_png' => $png,
        ];
    }
}
