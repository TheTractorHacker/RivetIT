<?php

declare(strict_types=1);

namespace ITFlow\Audit;

use ITFlow\Platform\PlatformSettings;

/**
 * Optional copy of every sealed audit event to a JSON-lines file and/or syslog, for a SIEM or a log shipper. Off by default
 * (platform settings audit_sink_path, audit_sink_syslog). Never throws: a full disk must not stop the action being audited.
 */
final class AuditSink
{
    /** A file path the sink may write: absolute, no traversal, not a symlink, not inside the web root, in a directory that exists. @return string|null why not, or null when it is fine */
    public static function pathProblem(string $path, string $appRoot): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if ($path[0] !== '/' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return 'Use an absolute path without "..".';
        }
        $dir = dirname($path);
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            return 'The directory does not exist.';
        }
        $realRoot = realpath($appRoot) ?: $appRoot;
        if ($realDir === $realRoot || str_starts_with($realDir . '/', rtrim($realRoot, '/') . '/')) {
            return 'Choose a directory outside the application folder (for example /var/log/rivetit/).';
        }
        if (is_link($path)) {
            return 'The path is a symbolic link.';
        }
        if (is_dir($path)) {
            return 'The path is a directory.';
        }
        if (!is_writable(file_exists($path) ? $path : $realDir)) {
            return 'The web server user cannot write there.';
        }

        return null;
    }

    /** @param list<array<string,mixed>> $rows sealed audit rows */
    public static function write(\mysqli $db, array $rows, string $appRoot): void
    {
        if ($rows === []) {
            return;
        }
        try {
            $path = PlatformSettings::get($db, 'audit_sink_path');
            $syslog = PlatformSettings::bool($db, 'audit_sink_syslog');
            if ($path === '' && !$syslog) {
                return;
            }
            $lines = [];
            foreach ($rows as $r) {
                $meta = $r['metadata_json'] !== null ? json_decode((string) $r['metadata_json'], true) : null;
                $lines[] = json_encode([
                    'ts' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $r['created_at'] . ' UTC') ?: time()),
                    'app' => 'rivetit', 'audit_id' => (int) $r['audit_id'], 'event' => $r['event_type'], 'actor_user_id' => $r['actor_user_id'] === null ? null : (int) $r['actor_user_id'],
                    'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'action' => $r['action'], 'summary' => $r['summary'], 'metadata' => $meta,
                    'ip' => $r['ip_address'], 'request_id' => $r['request_id'], 'prev_hash' => $r['prev_hash'], 'row_hash' => $r['row_hash'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            if ($path !== '' && self::pathProblem($path, $appRoot) === null) {
                @file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
            }
            if ($syslog && function_exists('openlog')) {
                @openlog('rivetit-audit', LOG_PID, LOG_AUTH);
                foreach ($lines as $l) {
                    @syslog(LOG_INFO, $l);
                }
                @closelog();
            }
        } catch (\Throwable $e) {
            // never break the audited action
        }
    }
}
