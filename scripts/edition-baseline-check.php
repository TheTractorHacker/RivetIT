#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fails (exit 1) when docs/security/EDITION_BASELINE.json is not trustworthy. Rules (copied from RivetCore's scripts/baseline-check.php, plus the edition's own):
 *  - schema: every entry has core_control, status (enforced|partial|gap), how, files, tests, note
 *  - core_control exists in the PINNED RivetCore baseline (vendor/rivet/rivet-core/docs/security/baseline.json); no duplicates
 *  - every Core control whose status is edition-responsibility has an entry (nothing the edition owes is silently missing)
 *  - every referenced file exists; every test exists (path or path::method)
 *  - an 'enforced' entry names at least one test; a 'gap' entry names none
 * tests/ is not tracked in git in this repository, so in a checkout without a tests/ directory a missing test is a warning, not an error.
 *
 *   php scripts/edition-baseline-check.php [--core=vendor/rivet/rivet-core]
 */

$root = dirname(__DIR__);
$o = getopt('', ['core::']);
$core = $root . '/' . trim((string) ($o['core'] ?? 'vendor/rivet/rivet-core'), '/');
$errors = [];
$warnings = [];

$edition = json_decode((string) @file_get_contents($root . '/docs/security/EDITION_BASELINE.json'), true);
if (!is_array($edition) || !is_array($edition['controls'] ?? null)) {
    fwrite(STDERR, "docs/security/EDITION_BASELINE.json is missing or not valid JSON\n");
    exit(1);
}
$coreBaseline = json_decode((string) @file_get_contents($core . '/docs/security/baseline.json'), true);
if (!is_array($coreBaseline) || !is_array($coreBaseline['controls'] ?? null)) {
    fwrite(STDERR, "The pinned RivetCore baseline ($core/docs/security/baseline.json) was not found: run composer install\n");
    exit(1);
}
$coreById = [];
foreach ($coreBaseline['controls'] as $c) {
    $coreById[$c['id']] = $c;
}
$hasTests = is_dir($root . '/tests');
$seen = [];
foreach ($edition['controls'] as $c) {
    $id = (string) ($c['core_control'] ?? '(no id)');
    foreach (['core_control', 'status', 'how', 'files', 'tests', 'note'] as $f) {
        if (!array_key_exists($f, $c)) {
            $errors[] = "$id: missing field $f";
        }
    }
    if (isset($seen[$id])) {
        $errors[] = "$id: duplicate entry";
    }
    $seen[$id] = true;
    if (!isset($coreById[$id])) {
        $errors[] = "$id: not a control of the pinned RivetCore baseline (version " . ($coreBaseline['version'] ?? '?') . ')';
    }
    $status = (string) ($c['status'] ?? '');
    if (!in_array($status, ['enforced', 'partial', 'gap'], true)) {
        $errors[] = "$id: unknown status " . json_encode($status);
        continue;
    }
    foreach ((array) ($c['files'] ?? []) as $f) {
        if (!file_exists($root . '/' . $f)) {
            $errors[] = "$id: referenced file does not exist: $f";
        }
    }
    foreach ((array) ($c['tests'] ?? []) as $t) {
        [$path, $method] = array_pad(explode('::', (string) $t, 2), 2, null);
        $full = $root . '/' . $path;
        if (!is_file($full)) {
            $msg = "$id: test does not exist: $path";
            $hasTests ? ($errors[] = $msg) : ($warnings[] = $msg . ' (no tests/ directory in this checkout)');
            continue;
        }
        if ($method !== null && !preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', (string) file_get_contents($full))) {
            $errors[] = "$id: $path has no method $method";
        }
    }
    if ($status === 'enforced' && (array) ($c['tests'] ?? []) === []) {
        $errors[] = "$id: 'enforced' needs at least one test";
    }
    if ($status === 'gap' && (array) ($c['tests'] ?? []) !== []) {
        $errors[] = "$id: a 'gap' must not claim a test that proves it";
    }
}
foreach ($coreById as $id => $c) {
    if (($c['status'] ?? '') === 'edition-responsibility' && !isset($seen[$id])) {
        $errors[] = "$id: RivetCore marks it edition-responsibility but EDITION_BASELINE.json has no entry";
    }
}

$counts = ['enforced' => 0, 'partial' => 0, 'gap' => 0];
foreach ($edition['controls'] as $c) {
    $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
}
foreach ($warnings as $w) {
    fwrite(STDERR, "warning: $w\n");
}
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "ERROR: $e\n");
    }
    fwrite(STDERR, count($errors) . " problem(s).\n");
    exit(1);
}
echo 'OK  ' . count($edition['controls']) . " edition controls against RivetCore baseline v" . ($coreBaseline['version'] ?? '?') . ": {$counts['enforced']} enforced, {$counts['partial']} partial, {$counts['gap']} gap\n";
