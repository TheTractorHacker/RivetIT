<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Regression: admin/post/update.php must load includes/release_channel.php, in a block that encloses the call,
 * before it calls anything defined there. (The Update button once crashed with "Call to undefined function
 * releaseResetGeneratedFiles()": a require sat in a different `if` branch and a later line, so it was not loaded.)
 * Tokenizer based, no database:  php tests/update_handler_includes.php
 */
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

/** @return list<string> names of the functions an include defines at the top level */
function defined_functions(string $code): array {
    preg_match_all('/^function\s+(\w+)\s*\(/m', $code, $m);
    return $m[1];
}

/** Returns the helper calls made before the helper is loaded in an enclosing (same or outer) block. */
function unloaded_calls(string $code, array $funcs, string $needle): array {
    $tokens = token_get_all($code);
    $depth = 0;
    $loadedAt = [];   // depth => true while that block is open
    $bad = [];
    $n = count($tokens);
    $stmt = '';
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t)) {
            if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) { $depth++; continue; }
            if ($t[0] === T_STRING && in_array($t[1], $funcs, true)) {
                // a call, not a definition: next non-space token is "(" and the previous significant token is not T_FUNCTION
                $j = $i + 1; while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
                $k = $i - 1; while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k--;
                $isCall = ($tokens[$j] ?? null) === '(' && !(is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_FUNCTION);
                if ($isCall) {
                    $covered = false;
                    foreach ($loadedAt as $d => $_) { if ($d <= $depth) { $covered = true; break; } }
                    if (!$covered) $bad[] = $t[1] . '() at line ' . $t[2];
                }
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($t[1], $needle)) {
                // require_once / include of the helper: counts for this block and everything nested in it
                $loadedAt[$depth] = true;
            }
            continue;
        }
        if ($t === '{') { $depth++; }
        elseif ($t === '}') { unset($loadedAt[$depth]); $depth--; }
    }
    return $bad;
}

$helper = file_get_contents(__DIR__ . '/../includes/release_channel.php');
$funcs = defined_functions($helper);
$ok(count($funcs) > 0, 'release_channel.php defines functions');

foreach (['admin/post/update.php', 'scripts/update_cli.php'] as $file) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    $bad = unloaded_calls($src, $funcs, 'release_channel.php');
    $ok($bad === [], "$file loads release_channel.php before every helper call" . ($bad ? ' (' . implode(', ', $bad) . ')' : ''));
}

// Self-check: the detector must flag the original bug (require in a sibling branch, call before the later require)
$buggy = "<?php\nif (\$a) {\n    require_once 'x/release_channel.php';\n}\nif (\$b) {\n    releaseResetGeneratedFiles('.');\n    require_once 'x/release_channel.php';\n}\n";
$ok(unloaded_calls($buggy, ['releaseResetGeneratedFiles'], 'release_channel.php') !== [], 'detector flags a call that precedes its require');
$fixed = str_replace("    releaseResetGeneratedFiles('.');\n    require_once 'x/release_channel.php';\n", "    require_once 'x/release_channel.php';\n    releaseResetGeneratedFiles('.');\n", $buggy);
$ok(unloaded_calls($fixed, ['releaseResetGeneratedFiles'], 'release_channel.php') === [], 'detector accepts require then call');
exit($fails ? 1 : 0);
