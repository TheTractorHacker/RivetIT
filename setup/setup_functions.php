<?php
/**
 * RivetIT Restore Helpers (hardened)
 * - Safe recursive delete with optional root guard
 * - SQL import with DELIMITER and EOF handling
 * - Safe ZIP extraction (blocks traversal, symlinks, junk files)
 * - Config setters (idempotent + atomic)
 * - Uploads ZIP validator/extractor with MIME/signature scan and size caps
 * - Dangerous extension detector & executable content heuristic
 */

// ------------------------------
// deleteDir
// ------------------------------
if (!function_exists('deleteDir')) {
    /**
     * Delete a directory recursively.
     * @param string      $dir          Path to delete
     * @param string|null $mustBeUnder  Optional root path guard; if set, $dir must be within this root
     */
    function deleteDir(string $dir, ?string $mustBeUnder = null): void {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if ($dir === '' || $dir === DIRECTORY_SEPARATOR) return;

        if ($mustBeUnder !== null) {
            $root = realpath($mustBeUnder);
            $real = realpath($dir);
            if ($root === false || $real === false || strpos($real, $root) !== 0) {
                // Refuse to delete if it's not under the allowed root
                return;
            }
        }

        if (!is_dir($dir)) return;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) { @rmdir($path); }
            else { @unlink($path); }
        }
        @rmdir($dir);
    }
}

// ------------------------------
// importSqlFile
// ------------------------------
if (!function_exists('importSqlFile')) {
    /**
     * Import a SQL file via mysqli, supporting custom DELIMITER and multi statements.
     * Executes a trailing, non-delimited statement at EOF (if present).
     */
    function importSqlFile(mysqli $mysqli, string $path): void {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("SQL file not found or unreadable: $path");
        }
        $fh = fopen($path, 'r');
        if (!$fh) throw new RuntimeException("Failed to open SQL file");

        $delimiter = ';';
        $statement = '';

        while (($line = fgets($fh)) !== false) {
            $trim = trim($line);

            // skip comments and empty lines
            if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                continue;
            }

            // handle DELIMITER changes
            if (preg_match('/^DELIMITER\s+(.+)$/i', $trim, $m)) {
                $delimiter = $m[1];
                continue;
            }

            $statement .= $line;

            // end of statement?
            if (substr(rtrim($statement), -strlen($delimiter)) === $delimiter) {
                $sql = substr($statement, 0, -strlen($delimiter));
                if ($mysqli->multi_query($sql) === false) {
                    fclose($fh);
                    throw new RuntimeException("SQL error: " . $mysqli->error);
                }
                while ($mysqli->more_results() && $mysqli->next_result()) { /* flush */ }
                $statement = '';
            }
        }

        // Trailing statement at EOF (no delimiter)
        $trimStmt = trim($statement);
        if ($trimStmt !== '') {
            if ($mysqli->multi_query($trimStmt) === false) {
                fclose($fh);
                throw new RuntimeException("SQL error (EOF): " . $mysqli->error);
            }
            while ($mysqli->more_results() && $mysqli->next_result()) { /* flush */ }
        }

        fclose($fh);
    }
}

// ------------------------------
// safeExtractZip
// ------------------------------
if (!function_exists('safeExtractZip')) {
    /**
     * Extract a zip safely to $destDir.
     * - Blocks absolute paths and drive letters
     * - Normalizes ".." before writing anything
     * - Skips junk (e.g., __MACOSX/, .DS_Store, Thumbs.db)
     * - Verifies boundary after writes and blocks symlinks
     */
    function safeExtractZip(ZipArchive $zip, string $destDir): void {
        if (!is_dir($destDir) && !mkdir($destDir, 0700, true)) {
            throw new RuntimeException("Failed to create temp dir");
        }
        $rootReal = realpath($destDir);
        if ($rootReal === false) {
            throw new RuntimeException("Failed to resolve destination");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip junk/system entries
            if ($name === '' ||
                str_starts_with($name, '__MACOSX/') ||
                preg_match('#/\.DS_Store$#i', $name) ||
                preg_match('#/Thumbs\.db$#i', $name)
            ) {
                continue;
            }

            // Reject absolute paths / drive letters
            if (preg_match('#^(?:/|\\\\|[a-zA-Z]:[\\\\/])#', $name)) {
                throw new RuntimeException("Invalid absolute/drive path in zip: $name");
            }

            // Normalize path segments (handle .. and .)
            $norm = [];
            foreach (preg_split('#[\\\\/]#', $name) as $seg) {
                if ($seg === '' || $seg === '.') continue;
                if ($seg === '..') { array_pop($norm); continue; }
                $norm[] = $seg;
            }
            $safeRel = implode(DIRECTORY_SEPARATOR, $norm);
            if ($safeRel === '') continue;

            $isDir  = str_ends_with($name, '/');
            $target = $rootReal . DIRECTORY_SEPARATOR . $safeRel;
            $parent = dirname($target);

            // Create parent and verify boundary
            if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
                throw new RuntimeException("Failed to create $parent");
            }
            $parentReal = realpath($parent);
            if ($parentReal === false || strpos($parentReal, $rootReal) !== 0) {
                throw new RuntimeException("Path traversal detected (parent) for $name");
            }

            if ($isDir) {
                if (!is_dir($target) && !mkdir($target, 0700, true)) {
                    throw new RuntimeException("Failed to mkdir $target");
                }
                $dirReal = realpath($target);
                if ($dirReal === false || strpos($dirReal, $rootReal) !== 0) {
                    @rmdir($target);
                    throw new RuntimeException("Boundary check failed (dir) for $name");
                }
                continue;
            }

            // Regular file
            $fp = $zip->getStream($name);
            if ($fp === false) {
                throw new RuntimeException("Failed to read $name from zip");
            }
            $out = fopen($target, 'wb');
            if (!$out) { fclose($fp); throw new RuntimeException("Failed to create $target"); }
            stream_copy_to_stream($fp, $out);
            fclose($fp);
            fclose($out);
            @chmod($target, 0640);

            $real = realpath($target);
            if ($real === false || strpos($real, $rootReal) !== 0) {
                @unlink($target);
                throw new RuntimeException("Boundary check failed (file) for $name");
            }
            if (is_link($real)) {
                @unlink($real);
                throw new RuntimeException("Symlink detected in archive: $name");
            }
        }
    }
}

// ------------------------------
// setConfigFlag (idempotent)
// ------------------------------
if (!function_exists('setConfigFlag')) {
    /**
     * Idempotently set/append a PHP config flag like $config_enable_setup = 0;
     */
    function setConfigFlag(string $file, string $key, $value): void {
        // $key becomes a literal PHP variable name written straight into config.php, which
        // executes on every request - an unvalidated $key (e.g. containing a semicolon and more
        // PHP) would be a config injection primitive. Both callers today pass a fixed literal, but
        // this guard makes that a documented invariant instead of an unenforced assumption.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("setConfigFlag: '$key' is not a valid PHP variable name");
        }
        $cfg = @file_get_contents($file);
        if ($cfg === false) throw new RuntimeException("Cannot read $file");
        $cfg = str_replace("\r\n", "\n", $cfg);

        $pattern = '/^\s*\$' . preg_quote($key, '/') . '\s*=\s*.*?;\s*$/m';
        $line    = '$' . $key . ' = ' . (is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true)) . ';';

        if (preg_match($pattern, $cfg)) {
            $cfg = preg_replace($pattern, $line, $cfg, 1);
        } else {
            if (preg_match('/\?>\s*$/', $cfg)) {
                $cfg = preg_replace('/\?>\s*$/', "\n$line\n?>\n", $cfg, 1);
            } else {
                if ($cfg !== '' && substr($cfg, -1) !== "\n") $cfg .= "\n";
                $cfg .= $line . "\n";
            }
        }

        if (file_put_contents($file, $cfg, LOCK_EX) === false) {
            throw new RuntimeException("Failed to update $file");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}

// ------------------------------
// setConfigFlagAtomic (preferred)
// ------------------------------
if (!function_exists('setConfigFlagAtomic')) {
    /**
     * Atomic variant of setConfigFlag to avoid partial writes.
     */
    function setConfigFlagAtomic(string $file, string $key, $value): void {
        // Same config-injection guard as setConfigFlag() above - $key is written verbatim as a PHP
        // variable name into config.php.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("setConfigFlagAtomic: '$key' is not a valid PHP variable name");
        }
        clearstatcache(true, $file);
        if (!file_exists($file))  throw new RuntimeException("config.php not found: $file");
        if (!is_readable($file))  throw new RuntimeException("config.php not readable: $file");
        if (!is_writable($file))  throw new RuntimeException("config.php not writable: $file");

        $cfg = file_get_contents($file);
        if ($cfg === false) throw new RuntimeException("Failed to read config.php");
        $cfg = str_replace("\r\n", "\n", $cfg);

        $scalar = is_bool($value) ? ($value ? 'true' : 'false') : var_export($value, true);
        $line   = '$' . $key . ' = ' . $scalar . ';';

        $pattern = '/^\s*\$' . preg_quote($key, '/') . '\s*=\s*.*?;\s*$/m';
        if (preg_match($pattern, $cfg)) {
            $cfg = preg_replace($pattern, $line, $cfg, 1);
        } else {
            if (preg_match('/\?>\s*$/', $cfg)) {
                $cfg = preg_replace('/\?>\s*$/', "\n$line\n?>\n", $cfg, 1);
            } else {
                if ($cfg !== '' && substr($cfg, -1) !== "\n") $cfg .= "\n";
                $cfg .= $line . "\n";
            }
        }

        $dir  = dirname($file);
        $tmp  = tempnam($dir, 'cfg_');
        if ($tmp === false) throw new RuntimeException("Failed to create temp file in $dir");
        if (file_put_contents($tmp, $cfg, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp config");
        }
        $perms = @fileperms($file);
        if ($perms !== false) @chmod($tmp, $perms & 0777);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to atomically replace config.php");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}

// ------------------------------
// hasDangerousExtension
// ------------------------------
if (!function_exists('hasDangerousExtension')) {
    /**
     * Return true if a filename has a disallowed extension or looks like a double-extension trick.
     */
    function hasDangerousExtension(string $name, array $blockedExts): bool {
        $lower = strtolower($name);

        // Block config-like dotfiles that can affect server behavior
        if (preg_match('/(^|\/)\.(htaccess|user\.ini|env|apache2?\.conf|nginx\.conf)$/i', $lower)) return true;

        $ext = strtolower(pathinfo($lower, PATHINFO_EXTENSION));
        if ($ext === '') return false;

        // Merge user blocklist with common server-parsed types
        $blocked = array_flip($blockedExts) + array_flip([
            'shtml','stm','shtm',   // server-parsed HTML (SSI)
            'ctp',                  // CakePHP template
            'pht','phtm',          // treated as PHP on misconfigs
        ]);
        if (isset($blocked[$ext])) return true;

        // Double extension like .jpg.php or .png.sh (cap first ext to 10 chars)
        if (preg_match('/\.[a-z0-9]{1,10}\.(php[0-9]?|phtml|phar|cgi|pl|sh|exe|dll|bat|cmd|com|ps1|vb|vbs|jar|jsp|asp|aspx|s?html)$/i', $lower)) {
            return true;
        }

        return false;
    }
}

// ------------------------------
// contentLooksExecutable
// ------------------------------
if (!function_exists('contentLooksExecutable')) {
    /**
     * Heuristic content scan for executable code. Reads head/tail of file.
     * Uses finfo when available; falls back to signature scan.
     */
    function contentLooksExecutable(string $tmpPath): bool {
        $mime = '';
        if (class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $mime = $fi->file($tmpPath) ?: '';
            if (preg_match('#^(application/x-(php|elf|sharedlib|mach-o)|text/x-(php|script|shell))#i', $mime)) {
                return true;
            }
        }

        $fp = @fopen($tmpPath, 'rb');
        if (!$fp) return false;

        $head = fread($fp, 4096) ?: '';
        $tail = '';
        $stat = fstat($fp);
        if ($stat && ($stat['size'] ?? 0) > 4096) {
            fseek($fp, -4096, SEEK_END);
            $tail = fread($fp, 4096) ?: '';
        }
        fclose($fp);

        $blob = $head . $tail;

        // Execution markers (limited to reduce false positives)
        $markers = [
            '<?php', '<?=',
            '#!/usr/bin/env php', '#!/usr/bin/php',
            '#!/bin/bash', '#!/bin/sh', '#!/usr/bin/env bash',
            'shell_exec(', 'proc_open(', 'popen(', 'system(', 'passthru(',
            'eval(', 'assert(', 'base64_decode(',
        ];
        foreach ($markers as $m) {
            if (stripos($blob, $m) !== false) return true;
        }

        return false;
    }
}

// ------------------------------
// isKnownSafeUploadsPlaceholder
// ------------------------------
if (!function_exists('isKnownSafeUploadsPlaceholder')) {
    /**
     * True if $name/$content is one of this app's own uploads/ placeholders
     * that hasDangerousExtension() would otherwise reject purely by name:
     * the empty `index.php` directory-listing-denial file every uploads/*
     * subdirectory gets (deploy/install.sh's setup_upload_dirs()), or the
     * `.htaccess` that disables script execution in uploads/. Both are
     * legitimate parts of every real backup's uploads.zip - only a real
     * restore ever hits this, not a fresh install (which creates its own
     * copies directly, never through this validator).
     *
     * Checked by content, not just name, so this can never be used to
     * smuggle in an actual malicious index.php or .htaccess disguised under
     * a familiar filename: an index.php must be empty, and a .htaccess must
     * consist ENTIRELY of recognized execution-DISABLING directives - one
     * unrecognized line (e.g. a real AddHandler/SetHandler that turns
     * something ON) fails the whole file, same as any other upload.
     */
    function isKnownSafeUploadsPlaceholder(string $name, string $content): bool {
        $base = basename($name);
        $trimmed = trim($content);

        if ($base === 'index.php') {
            return $trimmed === '';
        }

        if ($base === '.htaccess') {
            if ($trimmed === '') {
                return false;
            }
            // 'x' modifier: whitespace/newlines here are formatting only,
            // ignored by PCRE - no manual stripping needed before matching.
            $safe_line = '/^(
                Options\s+-ExecCGI
                |php_flag\s+engine\s+off
                |Remove(?:Handler|Type)(?:\s+\.\w+)+
                |<FilesMatch\b[^>]*>
                |<\/FilesMatch>
                |<Files\b[^>]*>
                |<\/Files>
                |Require\s+all\s+denied
                |Deny\s+from\s+all
            )$/ix';
            foreach (preg_split('/\r\n|\r|\n/', $trimmed) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (!preg_match($safe_line, $line)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}

// ------------------------------
// extractUploadsZipWithValidationReport
// ------------------------------
if (!function_exists('extractUploadsZipWithValidationReport')) {
    /**
     * Extract uploads.zip to $destDir with validation & reporting.
     * - Validates each entry (boundary, extension, MIME/signatures, size)
     * - Collects all issues instead of failing fast
     * - If any issues are found, NOTHING is extracted and an array of problems is returned
     *
     * @return array{ok: bool, issues?: array<int, array{path: string, reason: string}>}
     */
    function extractUploadsZipWithValidationReport(ZipArchive $zip, string $destDir, array $options = []): array {
        $maxFileBytes  = $options['max_file_bytes']  ?? (200 * 1024 * 1024);  // 200MB per-file
        $maxTotalBytes = $options['max_total_bytes'] ?? (4   * 1024 * 1024 * 1024); // 4GB per-archive
        $blockedExts   = $options['blocked_exts']    ?? [
            'php','php3','php4','php5','php7','php8','phtml','phar',
            'cgi','pl','sh','bash','zsh','exe','dll','bat','cmd','com',
            'ps1','vbs','vb','jar','jsp','asp','aspx','so','dylib','bin'
        ];

        $issues   = [];
        if (!is_dir($destDir) && !mkdir($destDir, 0700, true)) {
            return ['ok' => false, 'issues' => [['path' => '(staging)', 'reason' => 'Failed to create staging directory']]];
        }
        $rootReal = realpath($destDir);
        if ($rootReal === false) {
            return ['ok' => false, 'issues' => [['path' => '(staging)', 'reason' => 'Failed to resolve staging directory']]];
        }

        $pending    = []; // list of ['tmp','target','name']
        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Skip junk/system entries
            if ($name === '' ||
                str_starts_with($name, '__MACOSX/') ||
                preg_match('#/\.DS_Store$#i', $name) ||
                preg_match('#/Thumbs\.db$#i', $name)
            ) {
                continue;
            }

            // Absolute / drive-lettered paths
            if (preg_match('#^(?:/|\\\\|[a-zA-Z]:[\\\\/])#', $name)) {
                $issues[] = ['path' => $name, 'reason' => 'Invalid absolute or drive path'];
                continue;
            }

            // Directories: defer creation to commit phase
            if (str_ends_with($name, '/')) continue;

            // The app's own uploads/ placeholders (every subdirectory's empty
            // index.php, and .htaccess disabling script execution there) are
            // a normal part of every real backup, but would otherwise be
            // rejected below purely by name - checked by content too, so
            // this can't be used to smuggle in anything actually dangerous
            // under a familiar filename (see isKnownSafeUploadsPlaceholder).
            $base_name = basename($name);
            if ($base_name === 'index.php' || $base_name === '.htaccess') {
                $placeholder_content = $zip->getFromName($name);
                if ($placeholder_content !== false && isKnownSafeUploadsPlaceholder($name, $placeholder_content)) {
                    $tmp = tempnam(sys_get_temp_dir(), 'uplscan_');
                    if ($tmp === false) { $issues[] = ['path' => $name, 'reason' => 'Failed to create temp file']; continue; }
                    if (file_put_contents($tmp, $placeholder_content) === false) {
                        @unlink($tmp);
                        $issues[] = ['path' => $name, 'reason' => 'Failed to write temp file'];
                        continue;
                    }
                    $totalBytes += strlen($placeholder_content);
                    if ($totalBytes > $maxTotalBytes) {
                        @unlink($tmp);
                        $issues[] = ['path' => $name, 'reason' => 'Archive exceeds total size limit'];
                        continue;
                    }
                    $pending[] = ['tmp' => $tmp, 'target' => $rootReal . DIRECTORY_SEPARATOR . $name, 'name' => $name];
                    continue;
                }
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                $issues[] = ['path' => $name, 'reason' => 'Unable to read entry from ZIP'];
                continue;
            }

            // Extension/double-extension checks
            if (hasDangerousExtension($name, $blockedExts)) {
                fclose($stream);
                $issues[] = ['path' => $name, 'reason' => 'Dangerous or disallowed file extension'];
                continue;
            }

            // Buffer to temp with per-file & total size caps
            $tmp = tempnam(sys_get_temp_dir(), 'uplscan_');
            if ($tmp === false) { fclose($stream); $issues[] = ['path' => $name, 'reason' => 'Failed to create temp file']; continue; }

            $out = fopen($tmp, 'wb');
            if (!$out) { fclose($stream); @unlink($tmp); $issues[] = ['path' => $name, 'reason' => 'Failed to write temp file']; continue; }

            $bytes = 0; $err = null;
            while (!feof($stream)) {
                $chunk = fread($stream, 1 << 15);
                if ($chunk === false) { $err = 'Read error while extracting'; break; }
                $len = strlen($chunk);
                $bytes      += $len;
                $totalBytes += $len;

                if ($bytes > $maxFileBytes)  { $err = 'File exceeds per-file size limit'; break; }
                if ($totalBytes > $maxTotalBytes) { $err = 'Archive exceeds total size limit'; break; }
                if (fwrite($out, $chunk) === false) { $err = 'Write error while buffering'; break; }
            }
            fclose($stream);
            fclose($out);

            if ($err !== null) {
                @unlink($tmp);
                $issues[] = ['path' => $name, 'reason' => $err];
                continue;
            }

            // MIME/signature check
            if (contentLooksExecutable($tmp)) {
                @unlink($tmp);
                $issues[] = ['path' => $name, 'reason' => 'Executable/script content detected'];
                continue;
            }

            // Record as candidate
            $target = $rootReal . DIRECTORY_SEPARATOR . $name;
            $pending[] = ['tmp' => $tmp, 'target' => $target, 'name' => $name];
        }

        // Any issues? clean up and report
        if (!empty($issues)) {
            foreach ($pending as $p) { @unlink($p['tmp']); }
            return ['ok' => false, 'issues' => $issues];
        }

        // Commit: create dirs and move files
        foreach ($pending as $p) {
            $finalDir = dirname($p['target']);
            if (!is_dir($finalDir) && !mkdir($finalDir, 0700, true)) {
                foreach ($pending as $r) { @unlink($r['tmp']); }
                return ['ok' => false, 'issues' => [['path' => $p['name'], 'reason' => 'Failed to create destination directory']]];
            }

            $realFinalDir = realpath($finalDir);
            if ($realFinalDir === false || strpos($realFinalDir, $rootReal) !== 0) {
                foreach ($pending as $r) { @unlink($r['tmp']); }
                return ['ok' => false, 'issues' => [['path' => $p['name'], 'reason' => 'Path traversal detected at commit phase']]];
            }

            if (!rename($p['tmp'], $p['target'])) {
                if (!copy($p['tmp'], $p['target'])) {
                    @unlink($p['tmp']);
                    foreach ($pending as $r) { @unlink($r['tmp']); }
                    return ['ok' => false, 'issues' => [['path' => $p['name'], 'reason' => 'Failed to place file in destination']]];
                }
                @unlink($p['tmp']);
            }

            @chmod($p['target'], 0640);
            $real = realpath($p['target']);
            if ($real === false || strpos($real, $rootReal) !== 0) {
                @unlink($p['target']);
                foreach ($pending as $r) { @unlink($r['tmp']); }
                return ['ok' => false, 'issues' => [['path' => $p['name'], 'reason' => 'Boundary check failed after write']]];
            }
            if (is_link($real)) {
                @unlink($real);
                foreach ($pending as $r) { @unlink($r['tmp']); }
                return ['ok' => false, 'issues' => [['path' => $p['name'], 'reason' => 'Symlink detected in destination']]];
            }
        }

        return ['ok' => true];
    }
}

// ------------------------------
// applyManifestSettingsEncKey
// ------------------------------
if (!function_exists('applyManifestSettingsEncKey')) {
    /**
     * Reads backup-manifest.json (or backup-manifest.json.enc, decrypted with $passphrase)
     * out of an extracted admin-zip backup, and rewrites $configPath's
     * $config_settings_enc_key line to match - the same reconciliation deploy/restore.sh
     * performs (see apply_settings_enc_key() in deploy/lib/common.sh) after a CLI restore.
     *
     * Without this, restoring this .zip onto config.php with a DIFFERENT
     * $config_settings_enc_key (any config.php that wasn't this exact backup's own source
     * instance - e.g. a fresh box provisioned by deploy/install.sh / scripts/setup_cli.php)
     * leaves every SMTP/IMAP password, RMM/webhook secret and the wrapped credential-vault
     * master key permanently undecryptable after the restore completes, with no warning -
     * db.sql's rows import fine (they're just ciphertext bytes to MySQL), but nothing that
     * decrypts them afterward will work. Restoring back onto this backup's OWN unchanged
     * config.php (the common case: same box, same install, disaster was "the database got
     * wiped") is unaffected either way, since the key would already match.
     *
     * @return array{status: string, message: string} status is one of:
     *   'applied'         - manifest found, key recovered and written to config.php
     *   'not_found'       - no backup-manifest.json[.enc] in this backup (pre-manifest backup)
     *   'decrypt_failed'  - backup-manifest.json.enc present but $passphrase didn't decrypt it
     *   'passphrase_needed' - .enc present, no $passphrase was submitted
     *   'no_key'          - manifest read fine but had no usable settings_enc_key
     *   'no_config_line'  - config.php has no $config_settings_enc_key line to replace
     */
    function applyManifestSettingsEncKey(string $tempDir, ?string $passphrase, string $configPath): array {
        $plain = $tempDir . '/backup-manifest.json';
        $enc   = $tempDir . '/backup-manifest.json.enc';
        $manifestFile = null;
        $decryptedTmp = null;

        if (is_file($plain)) {
            $manifestFile = $plain;
        } elseif (is_file($enc)) {
            if ($passphrase === null || $passphrase === '') {
                return ['status' => 'passphrase_needed', 'message' => "This backup's manifest is encrypted (a backup passphrase was set in Admin > Backup when it was taken) - enter that passphrase to recover its settings-encryption key, or SMTP/IMAP passwords, RMM/webhook secrets and the credential vault will not decrypt after this restore."];
            }
            $passFile = tempnam(sys_get_temp_dir(), 'restman_pass_');
            @chmod($passFile, 0600);
            file_put_contents($passFile, $passphrase);
            $decryptedTmp = tempnam(sys_get_temp_dir(), 'restman_dec_');
            @chmod($decryptedTmp, 0600);
            $cmd = sprintf(
                'openssl enc -d -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass file:%s 2>&1',
                escapeshellarg($enc),
                escapeshellarg($decryptedTmp),
                escapeshellarg($passFile)
            );
            exec($cmd, $out, $exitCode);
            @unlink($passFile);
            if ($exitCode !== 0 || !is_file($decryptedTmp) || filesize($decryptedTmp) === 0) {
                @unlink($decryptedTmp);
                return ['status' => 'decrypt_failed', 'message' => "This backup's manifest (backup-manifest.json.enc) could not be decrypted with the passphrase entered - it's either wrong, or the file is corrupt. Settings-encryption-key recovery was skipped; SMTP/IMAP passwords, RMM/webhook secrets and the credential vault may not decrypt correctly."];
            }
            $manifestFile = $decryptedTmp;
        } else {
            return ['status' => 'not_found', 'message' => "This backup has no backup-manifest.json (it predates settings-encryption-key capture). config_settings_enc_key was left as-is; if this isn't a restore onto its own original config.php, SMTP/IMAP passwords, RMM/webhook secrets and the credential vault will need to be re-entered by hand."];
        }

        $data = json_decode((string) file_get_contents($manifestFile), true);
        if ($decryptedTmp !== null) { @unlink($decryptedTmp); }

        $key = is_array($data) ? (string) ($data['settings_enc_key'] ?? '') : '';
        if ($key === '') {
            return ['status' => 'no_key', 'message' => "This backup's manifest has no usable settings-encryption key. config_settings_enc_key was left as-is."];
        }

        $configContents = @file_get_contents($configPath);
        if ($configContents === false || !preg_match('/^\$config_settings_enc_key = \'.*\';$/m', $configContents)) {
            return ['status' => 'no_config_line', 'message' => "config.php has no \$config_settings_enc_key line to update - this instance's config.php may predate that setting. Investigate before trusting restored SMTP/IMAP/RMM/webhook secrets."];
        }

        // setup_cli.php / deploy/restore.sh's own equivalent both rely on this same
        // guarantee: $config_settings_enc_key is always bin2hex() output through a
        // single-quoted var_export-style literal, so no escaping is needed here.
        $newContents = preg_replace(
            '/^\$config_settings_enc_key = \'.*\';$/m',
            "\$config_settings_enc_key = '" . $key . "';",
            $configContents,
            1
        );
        if ($newContents === null || @file_put_contents($configPath, $newContents, LOCK_EX) === false) {
            return ['status' => 'no_config_line', 'message' => "Found this backup's settings-encryption key but failed to write it to config.php (check file permissions). SMTP/IMAP passwords, RMM/webhook secrets and the credential vault may not decrypt correctly until this is applied by hand."];
        }

        return ['status' => 'applied', 'message' => "Settings-encryption key recovered from this backup's manifest and applied to config.php - SMTP/IMAP passwords, RMM/webhook secrets and the credential vault should decrypt normally."];
    }
}
