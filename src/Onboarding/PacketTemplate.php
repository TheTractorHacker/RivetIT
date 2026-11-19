<?php
declare(strict_types=1);

namespace ITFlow\Onboarding;

/**
 * The wording of the onboarding packet PDF, editable under Administration > Templates > Onboarding packet.
 * Stored as JSON in settings.config_onboarding_packet_template (NULL = the built-in defaults below). Plain text only; the
 * placeholders {first_name}, {name}, {department} and {company} are replaced when the packet is built.
 */
final class PacketTemplate
{
    /** field => [label, default, max length, rows (1 = single line)] */
    public const FIELDS = [
        'eyebrow'      => ['Small heading on the header band', 'ONBOARDING PACKET', 40, 1],
        'title'        => ['Big title', 'Welcome, {first_name}', 80, 1],
        'intro'        => ['Intro text', 'Welcome to {department}! Here are the sign-in details for your new accounts.', 400, 3],
        'private'      => ['Privacy line (bold, under the intro)', 'Keep this page private.', 160, 1],
        'help_heading' => ['Help box heading', 'Need help?', 60, 1],
        'help_text'    => ['Help box text', "If you cannot sign in, or something here doesn't work, get in touch:", 300, 3],
        'footer'       => ['Footer on every page', "Confidential \u{2014} contains sign-in details", 120, 1],
    ];

    /** @return array<string,string> */
    public static function defaults(): array
    {
        $d = [];
        foreach (self::FIELDS as $k => $f) {
            $d[$k] = $f[1];
        }
        return $d;
    }

    /** The saved template merged over the defaults; any missing or invalid field falls back to its default. @return array<string,string> */
    public static function load(\mysqli $db): array
    {
        $out = self::defaults();
        try {
            $r = $db->query('SELECT config_onboarding_packet_template AS t FROM settings WHERE company_id = 1');
            $row = $r ? $r->fetch_assoc() : null;
            $saved = $row && is_string($row['t'] ?? null) && $row['t'] !== '' ? json_decode($row['t'], true) : null;
        } catch (\Throwable $e) {
            $saved = null;   // column not there yet (pre-migration): defaults
        }
        if (is_array($saved)) {
            foreach (self::FIELDS as $k => $f) {
                if (isset($saved[$k]) && is_string($saved[$k]) && trim($saved[$k]) !== '') {
                    $out[$k] = $saved[$k];
                }
            }
        }
        return $out;
    }

    /** Validates a form post into a JSON string. A blank field means "use the default". Throws \InvalidArgumentException (user-safe). */
    public static function encode(array $post): string
    {
        $out = [];
        foreach (self::FIELDS as $k => $f) {
            $v = $post[$k] ?? '';
            if (!is_string($v)) {
                throw new \InvalidArgumentException($f[0] . ' is not valid.');
            }
            $v = trim(strip_tags(str_replace(["\r\n", "\r"], "\n", $v)));
            if ($f[3] === 1) {
                $v = trim((string) preg_replace('/\s+/u', ' ', $v));
            }
            if (mb_strlen($v, 'UTF-8') > $f[2]) {
                throw new \InvalidArgumentException($f[0] . ' is too long (maximum ' . $f[2] . ' characters).');
            }
            if ($v !== '' && $v !== $f[1]) {
                $out[$k] = $v;
            }
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** Fills the placeholders. Unknown braces are left alone. */
    public static function fill(string $text, string $personName, string $department, string $company): string
    {
        $first = preg_split('/\s+/u', trim($personName), 2)[0] ?? '';
        return strtr($text, [
            '{first_name}' => $first,
            '{name}' => trim($personName),
            '{department}' => $department,
            '{company}' => $company,
        ]);
    }
}
