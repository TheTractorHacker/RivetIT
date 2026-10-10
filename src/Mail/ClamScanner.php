<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/** Optional antivirus pass over inbound attachments using clamdscan (the daemon client) when it is installed. */
final class ClamScanner
{
    /** Path to clamdscan, or null when it is not installed. */
    public static function binary(): ?string
    {
        foreach (['/usr/bin/clamdscan', '/usr/local/bin/clamdscan', '/bin/clamdscan'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * @param string|null $binary override for tests
     * @return string|null signature name when infected; null when clean or the scanner is unavailable (fail open: mail
     *                     intake must not stop because the scanner is down)
     */
    public static function scan(string $content, ?string $binary = null): ?string
    {
        $binary ??= self::binary();
        if ($binary === null) {
            return null;
        }
        $dir = sys_get_temp_dir();
        $tmp = tempnam($dir, 'rivet-av-');
        if ($tmp === false) {
            return null;
        }
        try {
            chmod($tmp, 0644); // clamd runs as another user; --fdpass hands it the descriptor anyway
            file_put_contents($tmp, $content);
            $out = [];
            $code = 0;
            exec(escapeshellarg($binary) . ' --no-summary --fdpass ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            if ($code === 1) {
                $line = implode(' ', $out);
                return preg_match('/:\s*(.+?)\s+FOUND/i', $line, $m) ? trim($m[1]) : 'malware';
            }
            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
