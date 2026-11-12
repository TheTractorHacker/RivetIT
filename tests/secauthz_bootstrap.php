<?php
declare(strict_types=1);
/*
 * Shared setup for tests/secauthz_*.php (pre-launch authorization fixes). Runs the REAL handler blocks out of the
 * source files against a THROWAWAY schema-only database: the block `if (isset($_POST['x'])) {...}` is cut out with
 * the PHP tokenizer and eval'd with stubbed framework calls (CSRF, redirect, flash, permission level).
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/secauthz_inj_N.php
 */
if (PHP_SAPI !== 'cli') { exit(2); }
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "Set RIVETIT_TEST_DB=1 (scratch DB only)\n"); exit(2); }
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', (string) getenv('RIVETIT_TEST_DB_USER'), (string) getenv('RIVETIT_TEST_DB_PASS'), (string) getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "DB connect failed\n"); exit(2); }
mysqli_query($mysqli, "SET SESSION sql_mode=''");
date_default_timezone_set('UTC');
define('FROM_POST_HANDLER', true);
define('SECAUTHZ_ROOT', dirname(__DIR__));

class SecRedirect extends Exception {}
class SecDenied extends Exception {}

$T_fails = 0;
$ok = function (bool $c, string $l) use (&$T_fails): void { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $T_fails++; } };
$q = fn(string $sql) => mysqli_query($GLOBALS['mysqli'], $sql);
$one = fn(string $sql) => mysqli_fetch_row(mysqli_query($GLOBALS['mysqli'], $sql))[0] ?? null;

$GLOBALS['T_perm'] = [];      // module => level held by the fake session user
$GLOBALS['T_flash'] = [];
$GLOBALS['T_log'] = [];
$GLOBALS['T_queue'] = [];
$GLOBALS['session_is_admin'] = false;
$GLOBALS['session_name'] = 'Tester';

function validateCSRFToken($t = null) {}
function flash_alert($m, $t = 'success') { $GLOBALS['T_flash'][] = [(string) $m, $t]; }
function redirect($u = '') { throw new SecRedirect((string) $u); }
function logAction(...$a) { $GLOBALS['T_log'][] = $a; }
function customAction(...$a) {}
function enforceUserPermission($module, $level = 1) {
    if (($GLOBALS['T_perm'][$module] ?? 0) < $level) { throw new SecDenied("$module>=$level"); }
}
function enforceAssetPermission($level = 1) { enforceUserPermission('module_assets', $level); }
function addToMailQueue($data) { $GLOBALS['T_queue'] = array_merge($GLOBALS['T_queue'], $data); return true; }

/** Define named top-level functions from a source file (real code, not copies). */
function sec_fn(array $names, string $file = SECAUTHZ_ROOT . '/functions.php'): void {
    $src = (string) file_get_contents($file);
    foreach ($names as $fn) {
        if (function_exists($fn)) { continue; }
        if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?^}\n/ms', $src, $m)) { echo "FAIL  could not find $fn\n"; exit(1); }
        eval($m[0]);
    }
}
sec_fn(['sanitizeInput', 'enforceClientAccess', 'getFieldById', 'cleanInput', 'randomString']);

/** Source of the top-level `if (isset($_POST|$_GET['key'])) {...}` block. */
function sec_block(string $relFile, string $key): string {
    $src = (string) file_get_contents(SECAUTHZ_ROOT . '/' . $relFile);
    $toks = token_get_all($src);
    $n = count($toks); $out = ''; $found = false; $depth = 0; $started = false; $buf = '';
    for ($i = 0; $i < $n; $i++) {
        $t = $toks[$i];
        $s = is_array($t) ? $t[1] : $t;
        if (!$found) {
            if (is_array($t) && $t[0] === T_IF) {
                // look ahead for isset($_POST['key'] / $_GET['key'] in the condition
                $look = ''; $j = $i;
                while ($j < $n && $j < $i + 60) { $look .= is_array($toks[$j]) ? $toks[$j][1] : $toks[$j]; if ($toks[$j] === '{') break; $j++; }
                if (preg_match('/isset\(\$_(POST|GET)\[[\'"]' . preg_quote($key, '/') . '[\'"]\]/', $look)) { $found = true; $buf = ''; }
            }
        }
        if ($found) {
            $buf .= $s;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $started = true; }
            elseif ($t === '}') { $depth--; if ($started && $depth === 0) { return $buf; } }
        }
    }
    echo "FAIL  block $key not found in $relFile\n"; exit(1);
}

/**
 * Run one handler block. Returns 'done' | 'redirect:<url>' | 'denied:<what>'.
 * $post fills $_POST; $globals are made global (session_user_id etc).
 */
function sec_run(string $relFile, string $key, array $post, array $globals = []): string {
    $GLOBALS['T_flash'] = []; $GLOBALS['T_log'] = []; $GLOBALS['T_queue'] = [];
    $_POST = $post; $_GET = []; $_FILES = [];
    foreach ($globals as $k => $v) { $GLOBALS[$k] = $v; }
    $block = sec_block($relFile, $key);
    $names = ['mysqli', 'client_id', 'session_user_id', 'session_name', 'session_is_admin', 'session_client_id', 'session_contact_id', 'session_contact_primary',
        'session_contact_name', 'session_contact_is_technical_contact', 'config_mail_from_email', 'config_mail_from_name', 'config_invoice_from_email',
        'config_invoice_from_name', 'config_quote_from_email', 'config_quote_from_name', 'config_ticket_from_email', 'config_ticket_from_name'];
    $fn = function () use ($block, $names) {
        eval('global $' . implode(',$', $names) . '; ' . $block);
    };
    try { $fn(); return 'done'; }
    catch (SecRedirect $e) { return 'redirect:' . $e->getMessage(); }
    catch (SecDenied $e) { return 'denied:' . $e->getMessage(); }
}

/** Read a fresh scratch fixture: two departments, a restricted user (access to dept 1 only). */
function sec_reset_fixture(): void {
    global $mysqli;
    foreach (['user_client_permissions', 'tickets', 'contacts', 'assets', 'clients', 'users', 'projects', 'problems', 'problem_tickets', 'mailboxes', 'mail_requests', 'locations', 'vendors', 'email_queue', 'ticket_assets'] as $t) {
        mysqli_query($mysqli, "DELETE FROM `$t`");
    }
    mysqli_query($mysqli, "INSERT INTO clients SET client_id = 1, client_name = 'Dept A', client_notes = 'a'");
    mysqli_query($mysqli, "INSERT INTO clients SET client_id = 2, client_name = 'Dept B', client_notes = 'b'");
    mysqli_query($mysqli, "INSERT INTO users SET user_id = 10, user_name = 'Restricted', user_email = 'r@example.com', user_password = 'x', user_type = 1");
    mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = 10, client_id = 1");
    $GLOBALS['session_user_id'] = 10; $GLOBALS['session_is_admin'] = false; $GLOBALS['client_id'] = 0;
}
