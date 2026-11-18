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
        $r->managerName = self::text($post['manager_name'] ?? '', 'Manager name');

        $computer = self::line('computer', 'Computer', is_array($post['computer'] ?? null) ? $post['computer'] : []);
        if ($computer !== null) {
            $r->lines[] = $computer;
        }

        $emailIn = is_array($post['email'] ?? null) ? $post['email'] : [];
        if (!empty($post['email_same'])) {
            // The computer value wins; a posted email password is ignored.
            $emailIn['username'] = $computer['username'] ?? '';
            $emailIn['password'] = $computer['password'] ?? '';
        }
        $email = self::line('email', 'Email', $emailIn);
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
            'change' => !(isset($in['change']) && (string) $in['change'] === '0'),
            'save' => !empty($in['save']) && (string) $in['save'] !== '0',
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
