<?php
declare(strict_types=1);

namespace ITFlow\Onboarding;

/**
 * Validated, normalised form input for the onboarding packet. Pure PHP, no globals.
 * Over-limit input is rejected, never silently truncated. Passwords are kept byte-for-byte
 * (only outer whitespace is trimmed): no HTML escaping, no logging.
 */
final class PacketRequest
{
    public const MAX_SOFTWARE = 25;
    public const MAX_FIELD = 200;
    public const MAX_PASSWORD = 500;

    /** @var list<array{kind:string,name:string,username:string,password:string,uri:string,note:string,change:bool,save:bool}> */
    public array $lines = [];
    public string $startDate = '';
    public string $managerName = '';

    public static function fromPost(array $post): self
    {
        $r = new self();
        $r->startDate = self::text($post['start_date'] ?? '', 'Start date');
        $r->managerName = self::cleanName(self::text($post['manager_name'] ?? '', 'Manager name'), 'Manager name');

        $computer = self::line('computer', 'Computer', is_array($post['computer'] ?? null) ? $post['computer'] : []);
        if ($computer !== null) {
            $r->lines[] = $computer;
        }

        $emailIn = is_array($post['email'] ?? null) ? $post['email'] : [];
        // The email address IS the username, so there is no separate username field.
        $emailIn['username'] = $emailIn['name'] ?? '';
        $emailSame = !empty($post['email_same']);
        if ($emailSame) {
            // The computer password wins; a posted email password is ignored.
            $emailIn['password'] = $computer['password'] ?? '';
        }
        $email = self::line('email', 'Email', $emailIn);
        if ($email !== null && $emailSame && $email['save'] && $computer !== null && $computer['save']) {
            // Email and computer share one password and both are being saved: store ONE Login (the computer's) and note the email address on it.
            $computer['also_email'] = $email['username'];
            $r->lines[0] = $computer;
            $email['save'] = false;
        }
        if ($email !== null) {
            $r->lines[] = $email;
        }

        $software = is_array($post['software'] ?? null) ? array_values($post['software']) : [];
        if (count($software) > self::MAX_SOFTWARE) {
            throw new \InvalidArgumentException('Too many software rows (maximum ' . self::MAX_SOFTWARE . ').');
        }
        foreach ($software as $i => $row) {
            $line = self::line('software', 'Software row ' . ($i + 1), is_array($row) ? $row : []);
            if ($line !== null) {
                $r->lines[] = $line;
            }
        }
        return $r;
    }

    /**
     * The new hire's name typed into the packet when there is no contact record (a ticket with no contact).
     * Plain text, required, at most MAX_FIELD characters; invalid UTF-8 and control characters are refused,
     * never repaired. Throws \InvalidArgumentException with a user-safe message.
     */
    public static function personName(mixed $v): string
    {
        $s = self::cleanName(self::text($v, "New hire's name"), "New hire's name");
        if ($s === '') {
            throw new \InvalidArgumentException("Enter the new hire's name.");
        }
        return $s;
    }

    /**
     * Display-name cleaning: invalid UTF-8 is refused; control characters (C0, DEL, C1) and line/paragraph separators
     * become a space, invisible and direction-changing characters (U+200B-200F, U+202A-202E, U+2060-2064, U+FEFF)
     * are removed, whitespace runs collapse to one space, outer whitespace is trimmed. Accented letters are kept.
     */
    public static function cleanName(string $s, string $label = 'Name'): string
    {
        if ($s === '') {
            return '';
        }
        $a = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]+/u', ' ', $s);
        $b = $a === null ? null : preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]+/u', '', $a);
        $c = $b === null ? null : preg_replace('/\s+/u', ' ', $b);
        if ($c === null) {
            throw new \InvalidArgumentException("$label is not valid.");
        }
        return trim($c);
    }

    /** Returns null for a blank line (every field empty). */
    private static function line(string $kind, string $label, array $in): ?array
    {
        $line = [
            'kind' => $kind,
            'name' => self::text($in['name'] ?? '', "$label name"),
            'username' => self::text($in['username'] ?? '', "$label username"),
            'password' => self::text($in['password'] ?? '', "$label password", self::MAX_PASSWORD),
            'uri' => self::text($in['uri'] ?? '', "$label link"),
            'note' => self::text($in['note'] ?? '', "$label note"),
            'change' => !(isset($in['change']) && is_scalar($in['change']) && (string) $in['change'] === '0'),
            'save' => isset($in['save']) && is_scalar($in['save']) && !empty($in['save']) && (string) $in['save'] !== '0',
        ];
        if ($line['name'] === '' && $line['username'] === '' && $line['password'] === '' && $line['uri'] === '' && $line['note'] === '') {
            return null;
        }
        if ($line['save'] && $line['password'] === '') {
            throw new \InvalidArgumentException("$label needs a password to be saved to Logins.");
        }
        return $line;
    }

    private static function text(mixed $v, string $label, int $max = self::MAX_FIELD): string
    {
        if (is_array($v) || is_object($v)) {
            throw new \InvalidArgumentException("$label is not valid.");
        }
        $s = trim((string) $v);
        if (mb_strlen($s, 'UTF-8') > $max) {
            throw new \InvalidArgumentException("$label is too long (maximum $max characters).");
        }
        return $s;
    }
}
