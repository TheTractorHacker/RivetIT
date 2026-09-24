<?php

namespace ITFlow\Training\Media;

/**
 * Runs one external program on uploaded bytes (poppler's pdfinfo / pdftoppm). The pattern is
 * the one src/KB/PdfConverter::exec() established and documents in detail; every rule is
 * load-bearing because poppler has a CVE history and the input is hostile:
 *
 *   - NO SHELL. proc_open() gets an ARGV ARRAY, which PHP hands to execvp() directly, so
 *     quoting, `;`, `$()`, globbing and word splitting never exist.
 *   - Callers never pass a user-supplied string as an argument: paths are server-generated
 *     (PHP's upload tmp name or a content-addressed media path), always absolute, so nothing
 *     can start with '-' and be read as an option.
 *   - Reduced environment (LC_ALL=C, PATH=/usr/bin), stdin is /dev/null.
 *   - Resource limits: PDF tools are wrapped by the caller as
 *     ['/usr/bin/prlimit', '--as=536870912', '--cpu=90', '--', <tool>, ...] (see PdfPager).
 *   - A wall-clock timeout that SIGKILLs, with both pipes drained every tick (a child that
 *     fills a 64 KB pipe while we sleep would otherwise block forever) and captured output capped.
 */
final class Process
{
    private const STDOUT_CAP = 262144;
    private const STDERR_CAP = 65536;

    /**
     * @param list<string>          $argv    absolute binary path first
     * @param array<string, string> $env
     * @return array{code:int, stdout:string, stderr:string, timedout:bool}
     */
    public static function run(array $argv, string $cwd, int $timeoutMs, array $env = ['LC_ALL' => 'C', 'PATH' => '/usr/bin']): array
    {
        if ($argv === [] || !is_string($argv[0]) || !str_starts_with($argv[0], '/')) {
            throw new \InvalidArgumentException('Process::run needs an absolute binary path');
        }
        if (!is_executable($argv[0])) {
            throw new \RuntimeException('Process: ' . basename($argv[0]) . ' is not installed');
        }
        foreach ($argv as $a) {
            if (!is_string($a) || str_contains($a, "\0")) {
                throw new \InvalidArgumentException('Process::run: arguments must be strings without NUL');
            }
        }

        $desc = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(array_values($argv), $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Process: ' . basename($argv[0]) . ' could not be started');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutMs) / 1000;
        $timedout = false;

        while (true) {
            foreach ([1, 2] as $fd) {
                while (($chunk = fread($pipes[$fd], 65536)) !== false && $chunk !== '') {
                    if ($fd === 1) {
                        if (strlen($stdout) < self::STDOUT_CAP) {
                            $stdout .= $chunk;
                        }
                    } elseif (strlen($stderr) < self::STDERR_CAP) {
                        $stderr .= $chunk;
                    }
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedout = true;
                proc_terminate($proc, 9);
                break;
            }
            usleep(10000);
        }

        foreach ([1, 2] as $fd) {
            $rest = stream_get_contents($pipes[$fd]);
            if (is_string($rest) && $rest !== '') {
                if ($fd === 1 && strlen($stdout) < self::STDOUT_CAP) {
                    $stdout .= $rest;
                } elseif ($fd === 2 && strlen($stderr) < self::STDERR_CAP) {
                    $stderr .= $rest;
                }
            }
            fclose($pipes[$fd]);
        }
        $code = proc_close($proc);
        // proc_close() returns -1 when the exit status was already collected by
        // proc_get_status(); the status we saw then is the real one.
        if ($code === -1 && isset($status['exitcode']) && $status['exitcode'] >= 0 && !$timedout) {
            $code = (int) $status['exitcode'];
        }

        return [
            'code' => $timedout ? -1 : (int) $code,
            'stdout' => substr($stdout, 0, self::STDOUT_CAP),
            'stderr' => substr($stderr, 0, self::STDERR_CAP),
            'timedout' => $timedout,
        ];
    }

    /**
     * Creates a private scratch directory under the system temp dir (0700, 128-bit random name,
     * realpath-contained). The caller removes it with removeScratchDir() in a finally.
     */
    public static function makeScratchDir(string $prefix): string
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new \RuntimeException('Process: no writable temporary directory');
        }
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Process: bad scratch prefix');
        }
        $dir = $base . '/' . $prefix . '-' . bin2hex(random_bytes(16));
        $old = umask(0077);
        try {
            if (!@mkdir($dir, 0700)) {
                throw new \RuntimeException('Process: the temporary directory could not be created');
            }
        } finally {
            umask($old);
        }
        $real = realpath($dir);
        if ($real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            @rmdir($dir);
            throw new \RuntimeException('Process: the temporary directory could not be created');
        }
        return $real;
    }

    /** Removes a directory made by makeScratchDir(): one level deep, files only, contained. */
    public static function removeScratchDir(string $dir): void
    {
        $base = realpath(sys_get_temp_dir());
        $real = realpath($dir);
        if ($base === false || $real === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
            return;
        }
        foreach (@scandir($real) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $real . '/' . $e;
            if (is_file($p) || is_link($p)) {
                @unlink($p);
            }
        }
        @rmdir($real);
    }
}
