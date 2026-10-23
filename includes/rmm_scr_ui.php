<?php

/*
 * RMM Phase 2 (RivetCore 1.0.0-rc.10) pages: the script library (agent/rmm_script_library.php), schedules (agent/rmm_schedules.php), approvals
 * (agent/rmm_approvals.php) and the "Run a library script" card on the Jobs tab of the asset page (rivetRmmScrDeviceSection). Their handlers are in
 * agent/post/rmm_automation_scr.php. All of them need the `scripts` sub-switch.
 *
 * Every read and every write goes through RivetCore\Rmm\Http\TechnicianApi as the signed-in user (rivetRmmAutoApi): its 403/404 rules, client scoping and
 * audit apply. The only things read straight from Core are named where they happen (the frozen request of an approval, to show what will run).
 * Script text is shown only when the API gives it (rmm.job.run_saved); it is escaped, never executed, and never put in a flash message or an attribute.
 * A secret parameter value is never rendered back, never put in a flash message, and a request that holds one is not stored by Core in plain text.
 *
 * View-model functions return arrays and read only through the API (testable); renderers return strings.
 */

require_once __DIR__ . '/rivet_time.php';
require_once __DIR__ . '/rmm_ui_render.php';
require_once __DIR__ . '/rmm_starter_ui.php';   // rc.13: the Starter badge, the risk chip and the update-waiting marker (rmm_starter_ui.php needs this file too; both are require_once)

use RivetCore\Rmm\Scripts\CronSchedule;
use RivetCore\Rmm\Scripts\ParamSchema;
use RivetCore\Rmm\Scripts\ScriptLanguage;

const RIVET_RMM_SCR_HANDLER = 'rmm_automation_scr';
const RIVET_RMM_SCR_TARGETS = ['device', 'tag', 'group', 'client', 'site', 'policy', 'all'];
const RIVET_RMM_SCR_PAGE = 25;

// ------------------------------------------------------------------ small helpers

/** @param array<string,string> $query @param array<string,mixed>|null $body */
function rivetRmmScrApi(array $ctx, string $method, array $segments, array $query = [], ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
}

/** @return array<int,string> user id => name (one query, none for an empty list) */
function rivetRmmScrUserNames(\mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    if ($ids === []) {
        return [];
    }
    $out = [];
    $r = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['user_id']] = (string) $row['user_name'];
    }

    return $out;
}

function rivetRmmScrLanguage(string $language): string
{
    return ['powershell' => 'PowerShell', 'bash' => 'Bash', 'python' => 'Python'][$language] ?? ucfirst($language);
}

/** "Windows" / "Linux" for the platforms a language runs on (ScriptLanguage::platforms). */
function rivetRmmScrPlatformWords(string $language): string
{
    $words = array_map(static fn (string $p): string => ['windows' => 'Windows', 'linux' => 'Linux'][$p] ?? ucfirst($p), ScriptLanguage::platforms($language));

    return $words === [] ? 'unknown' : implode(' and ', $words);
}

/** Relative time in the future or past: "in 5 min" / "5 min ago"; empty input is an empty string. */
function rivetRmmScrWhen(?string $iso, ?int $now = null): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    $t = strtotime(str_contains($iso, 'T') || str_ends_with($iso, 'Z') ? $iso : $iso . ' UTC');
    if ($t === false) {
        return '';
    }
    $d = $t - ($now ?? time());

    return $d >= 0 ? 'in ' . rivetRmmUiDuration($d) : rivetRmmUiDuration(-$d) . ' ago';
}

/** An absolute time in the company zone plus the relative text, as markup. */
function rivetRmmScrTimeBoth(?string $iso, ?int $now = null): string
{
    if ($iso === null || $iso === '') {
        return '<span class="text-muted">none</span>';
    }

    return rivetRmmAutoUtc($iso) . '<div class="small text-muted">' . rmmH(rivetRmmScrWhen($iso, $now)) . '</div>';
}

/** Seconds in the largest whole unit: 21600 -> "6 hours", 90 -> "90 seconds". */
function rivetRmmScrSecondsWords(int $s): string
{
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$n, $w]) {
        if ($s >= $n && $s % $n === 0) {
            $c = intdiv($s, $n);

            return $c . ' ' . $w . ($c === 1 ? '' : 's');
        }
    }

    return $s . ' second' . ($s === 1 ? '' : 's');
}

/** A five-field UTC cron expression in words as far as the common shapes go; anything else is returned as the expression itself. */
function rivetRmmScrCronWords(string $expr): string
{
    $p = preg_split('/\s+/', trim($expr)) ?: [];
    if (count($p) !== 5) {
        return $expr;
    }
    [$mi, $h, $dom, $mon, $dow] = $p;
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $num = static fn (string $v): bool => preg_match('/^\d{1,2}$/', $v) === 1;
    $step = static fn (string $v): ?int => preg_match('~^\*/(\d{1,2})$~', $v, $m) === 1 && (int) $m[1] > 0 ? (int) $m[1] : null;
    $at = static fn (string $hh, string $mm): string => sprintf('%02d:%02d UTC', (int) $hh, (int) $mm);
    $restStar = $dom === '*' && $mon === '*' && $dow === '*';
    if ($mi === '*' && $h === '*' && $restStar) {
        return 'Every minute';
    }
    if ($step($mi) !== null && $h === '*' && $restStar) {
        return 'Every ' . $step($mi) . ' minutes';
    }
    if ($num($mi) && $h === '*' && $restStar) {
        return 'Every hour at minute ' . (int) $mi;
    }
    if ($num($mi) && $step($h) !== null && $restStar) {
        return 'Every ' . $step($h) . ' hours at minute ' . (int) $mi;
    }
    if ($num($mi) && $num($h) && $restStar) {
        return 'Every day at ' . $at($h, $mi);
    }
    if ($num($mi) && $num($h) && $dom === '*' && $mon === '*') {
        if ($num($dow) && (int) $dow <= 7) {
            return 'Every ' . $days[(int) $dow] . ' at ' . $at($h, $mi);
        }
        if (preg_match('/^([0-7])-([0-7])$/', $dow, $m) === 1) {
            return 'Every ' . $days[(int) $m[1]] . ' to ' . $days[(int) $m[2]] . ' at ' . $at($h, $mi);
        }
        if (preg_match('/^[0-7](,[0-7])+$/', $dow) === 1) {
            return 'Every ' . implode(', ', array_map(static fn (string $d): string => $days[(int) $d], explode(',', $dow))) . ' at ' . $at($h, $mi);
        }
    }
    if ($num($mi) && $num($h) && $num($dom) && $mon === '*' && $dow === '*') {
        return 'On day ' . (int) $dom . ' of every month at ' . $at($h, $mi);
    }

    return $expr;
}

/** The cadence of a schedule row in words. */
function rivetRmmScrCadence(array $s): string
{
    if (($s['kind'] ?? '') === 'interval') {
        return 'Every ' . rivetRmmScrSecondsWords((int) ($s['interval_s'] ?? 0));
    }
    $w = rivetRmmScrCronWords((string) ($s['cron'] ?? ''));

    return $w === (string) ($s['cron'] ?? '') ? 'Cron ' . $w . ' (UTC)' : $w . ' (cron ' . (string) $s['cron'] . ')';
}

/** @return array{0:?string,1:bool} [UTC SQL time of the next run, whether it was computed here ("about")] */
function rivetRmmScrNextRun(array $s, ?int $now = null): array
{
    if (!empty($s['next_run_at'])) {
        return [(string) $s['next_run_at'], false];
    }
    $now ??= time();
    try {
        if (($s['kind'] ?? '') === 'cron' && (string) ($s['cron'] ?? '') !== '') {
            $n = CronSchedule::parse((string) $s['cron'])->next(new \DateTimeImmutable('@' . $now));

            return [$n === null ? null : $n->format('Y-m-d\TH:i:s\Z'), true];
        }
        if (($s['kind'] ?? '') === 'interval' && (int) ($s['interval_s'] ?? 0) > 0) {
            $base = !empty($s['last_run_at']) ? (int) strtotime((string) $s['last_run_at']) : $now;

            return [gmdate('Y-m-d\TH:i:s\Z', $base + (int) $s['interval_s']), true];
        }
    } catch (\InvalidArgumentException) {
        return [null, false];
    }

    return [null, false];
}

/** A badge for a job or run state, icon and word. */
function rivetRmmScrJobPill(string $state): string
{
    $m = ['succeeded' => ['ok', 'Succeeded'], 'failed' => ['crit', 'Failed'], 'timed_out' => ['warn', 'Timed out'], 'cancelled' => ['off', 'Cancelled'], 'expired' => ['off', 'Expired'],
        'running' => ['info', 'Running'], 'queued' => ['info', 'Queued'], 'done' => ['ok', 'Done'], 'missed' => ['warn', 'Missed'], 'deferred' => ['warn', 'Waiting for a maintenance window']][$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];

    return rivetRmmUiPill($m[0], $m[1]);
}

/** A small pager (previous / next) for ?page=N lists; `$url` is a callable page => href. */
function rivetRmmScrPager(int $page, int $total, int $per, callable $url): string
{
    $pages = max(1, (int) ceil($total / $per));
    if ($pages <= 1) {
        return '';
    }
    $o = '<nav class="d-flex align-items-center justify-content-between mt-2 small" aria-label="Pages"><span class="text-muted">Page ' . $page . ' of ' . $pages . ' (' . (int) $total . ' in all)</span><span>';
    $o .= $page > 1 ? '<a class="btn btn-sm btn-outline-secondary me-1" href="' . rmmH($url($page - 1)) . '">Previous</a>' : '';
    $o .= $page < $pages ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($page + 1)) . '">Next</a>' : '';

    return $o . '</span></nav>';
}

/** Copy button for a hash: the first 16 characters shown, the whole value copied. */
function rivetRmmScrHash(string $sha): string
{
    if ($sha === '') {
        return '<span class="text-muted">none</span>';
    }

    return '<code class="rmm-mono">' . rmmH(substr($sha, 0, 16)) . '</code> <button type="button" class="btn btn-xs btn-outline-secondary" data-rmm-copy="' . rmmH($sha) . '" aria-label="Copy the full SHA-256 hash">Copy</button>';
}

// ------------------------------------------------------------------ the line diff (LCS)

/**
 * A line diff of two texts, written here (longest common subsequence) so nothing is shelled out. Common first and last lines are peeled off first, the
 * differing middle is compared up to `$cap` lines on each side; beyond that the rest is reported as truncated. Lines are returned in order as
 * ['t' => ' '|'+'|'-', 'a' => old line number|null, 'b' => new line number|null, 'x' => text].
 *
 * @return array{rows:list<array{t:string,a:?int,b:?int,x:string}>,added:int,removed:int,truncated:bool}
 */
function rivetRmmScrDiff(string $old, string $new, int $cap = 1200): array
{
    $A = $old === '' ? [] : explode("\n", str_replace("\r\n", "\n", $old));
    $B = $new === '' ? [] : explode("\n", str_replace("\r\n", "\n", $new));
    $na = count($A);
    $nb = count($B);
    $pre = 0;
    while ($pre < $na && $pre < $nb && $A[$pre] === $B[$pre]) {
        ++$pre;
    }
    $suf = 0;
    while ($suf < $na - $pre && $suf < $nb - $pre && $A[$na - 1 - $suf] === $B[$nb - 1 - $suf]) {
        ++$suf;
    }
    $rows = [];
    for ($i = 0; $i < $pre; ++$i) {
        $rows[] = ['t' => ' ', 'a' => $i + 1, 'b' => $i + 1, 'x' => $A[$i]];
    }
    $ma = array_slice($A, $pre, $na - $pre - $suf);
    $mb = array_slice($B, $pre, $nb - $pre - $suf);
    $truncated = false;
    if (count($ma) > $cap || count($mb) > $cap) {
        $truncated = true;
        $ma = array_slice($ma, 0, $cap);
        $mb = array_slice($mb, 0, $cap);
    }
    $n = count($ma);
    $m = count($mb);
    // lengths of the longest common suffixes, row by row (two rows kept would lose the path, so keep all rows as packed strings of ints)
    $L = [];
    $L[$n] = array_fill(0, $m + 1, 0);
    for ($i = $n - 1; $i >= 0; --$i) {
        $row = array_fill(0, $m + 1, 0);
        $next = $L[$i + 1];
        for ($j = $m - 1; $j >= 0; --$j) {
            $row[$j] = $ma[$i] === $mb[$j] ? $next[$j + 1] + 1 : max($next[$j], $row[$j + 1]);
        }
        $L[$i] = $row;
    }
    $i = $j = 0;
    while ($i < $n || $j < $m) {
        if ($i < $n && $j < $m && $ma[$i] === $mb[$j]) {
            $rows[] = ['t' => ' ', 'a' => $pre + $i + 1, 'b' => $pre + $j + 1, 'x' => $ma[$i]];
            ++$i;
            ++$j;
        } elseif ($j >= $m || ($i < $n && $L[$i + 1][$j] >= $L[$i][$j + 1])) {
            $rows[] = ['t' => '-', 'a' => $pre + $i + 1, 'b' => null, 'x' => $ma[$i]];
            ++$i;
        } else {
            $rows[] = ['t' => '+', 'a' => null, 'b' => $pre + $j + 1, 'x' => $mb[$j]];
            ++$j;
        }
    }
    if (!$truncated) {
        for ($k = 0; $k < $suf; ++$k) {
            $rows[] = ['t' => ' ', 'a' => $na - $suf + $k + 1, 'b' => $nb - $suf + $k + 1, 'x' => $A[$na - $suf + $k]];
        }
    }
    $add = count(array_filter($rows, static fn (array $r): bool => $r['t'] === '+'));
    $rem = count(array_filter($rows, static fn (array $r): bool => $r['t'] === '-'));

    return ['rows' => $rows, 'added' => $add, 'removed' => $rem, 'truncated' => $truncated];
}

/** The diff as a table: changed lines carry a + / - sign AND a background (css/itflow_rmm_automation.css); unchanged runs far from a change are folded. */
function rivetRmmScrDiffTable(array $d, int $context = 3, int $maxRows = 1500): string
{
    $rows = $d['rows'];
    $keep = array_fill(0, count($rows), false);
    foreach ($rows as $i => $r) {
        if ($r['t'] !== ' ') {
            for ($k = max(0, $i - $context); $k <= min(count($rows) - 1, $i + $context); ++$k) {
                $keep[$k] = true;
            }
        }
    }
    $o = '';
    $shown = 0;
    $folded = false;
    $capped = false;
    foreach ($rows as $i => $r) {
        if (!$keep[$i]) {
            if (!$folded) {
                $o .= '<tr class="rmm-diff-fold"><td colspan="4" class="text-muted small ps-3">unchanged lines not shown</td></tr>';
                $folded = true;
            }
            continue;
        }
        $folded = false;
        if (++$shown > $maxRows) {
            $capped = true;
            break;
        }
        $cls = $r['t'] === '+' ? 'rmm-diff-add' : ($r['t'] === '-' ? 'rmm-diff-del' : '');
        $sign = $r['t'] === ' ' ? '' : $r['t'];
        $word = $r['t'] === '+' ? 'added' : ($r['t'] === '-' ? 'removed' : '');
        $o .= '<tr class="' . $cls . '"><td class="rmm-diff-no" aria-hidden="true">' . ($r['a'] ?? '') . '</td><td class="rmm-diff-no" aria-hidden="true">' . ($r['b'] ?? '') . '</td>'
            . '<td class="rmm-diff-sign">' . ($word !== '' ? '<span aria-hidden="true">' . $sign . '</span><span class="visually-hidden">' . $word . ' line: </span>' : '') . '</td>'
            . '<td class="rmm-diff-text">' . rmmH($r['x']) . '</td></tr>';
    }
    $note = ($d['truncated'] || $capped) ? '<div class="alert alert-warning small py-2 mt-2 mb-0" role="note">The comparison is cut short: only the first lines of a very long difference are compared and shown.</div>' : '';

    return '<div class="table-responsive rmm-diff-wrap"><table class="table table-sm mb-0 rmm-diff"><caption class="visually-hidden">Line by line difference between two versions; lines marked + were added, lines marked - were removed</caption>'
        . '<thead class="visually-hidden"><tr><th scope="col">Old line</th><th scope="col">New line</th><th scope="col">Change</th><th scope="col">Text</th></tr></thead><tbody>' . $o . '</tbody></table></div>' . $note;
}

// ------------------------------------------------------------------ parameters: inputs for a run, definition rows for the editor

/**
 * The inputs for a run of a script: one per parameter definition. Names are `param[<name>]`. A secret is a password field and is never pre-filled.
 *
 * @param list<array<string,mixed>> $schema
 * @param array<string,mixed> $values previously posted non-secret values
 */
function rivetRmmScrParamInputs(array $schema, string $idp, array $values = [], bool $placeholderHint = true, bool $scheduleMode = false): string
{
    if ($schema === []) {
        return '<p class="text-muted small mb-0">This version takes no parameters.</p>';
    }
    $o = '';
    foreach ($schema as $p) {
        $name = (string) $p['name'];
        $id = $idp . '_' . $name;
        $label = (string) ($p['label'] ?? '') !== '' ? (string) $p['label'] : $name;
        $type = (string) ($p['type'] ?? 'string');
        $req = !empty($p['required']);
        $desc = (string) ($p['description'] ?? '');
        $val = array_key_exists($name, $values) && ($type !== 'secret' || $scheduleMode) ? (is_bool($values[$name]) ? ($values[$name] ? 'true' : 'false') : (string) $values[$name]) : '';
        $o .= '<div class="mb-2"><label class="form-label" for="' . rmmH($id) . '">' . rmmH($label) . ' <span class="text-muted small">(' . rmmH($name) . ($req ? ', required' : '') . ')</span></label>';
        $f = 'name="param[' . rmmH($name) . ']" id="' . rmmH($id) . '"';
        if ($type === 'int') {
            $o .= '<input class="form-control" type="number" step="1" ' . $f . (isset($p['min']) ? ' min="' . (int) $p['min'] . '"' : '') . (isset($p['max']) ? ' max="' . (int) $p['max'] . '"' : '')
                . ' value="' . rmmH($val) . '"' . (array_key_exists('default', $p) ? ' placeholder="' . rmmH((string) $p['default']) . '"' : '') . '>';
        } elseif ($type === 'bool') {
            $o .= '<select class="form-select" ' . $f . '><option value="">' . (array_key_exists('default', $p) ? 'Default (' . (!empty($p['default']) ? 'yes' : 'no') . ')' : 'Not set') . '</option>'
                . '<option value="true"' . ($val === 'true' ? ' selected' : '') . '>Yes</option><option value="false"' . ($val === 'false' ? ' selected' : '') . '>No</option></select>';
        } elseif ($type === 'choice') {
            $o .= '<select class="form-select" ' . $f . '><option value="">' . (array_key_exists('default', $p) ? 'Default (' . rmmH((string) $p['default']) . ')' : 'Choose...') . '</option>';
            foreach ((array) ($p['choices'] ?? []) as $c) {
                $o .= '<option value="' . rmmH((string) $c) . '"' . ($val === (string) $c ? ' selected' : '') . '>' . rmmH((string) $c) . '</option>';
            }
            $o .= '</select>';
        } elseif ($type === 'secret' && $scheduleMode) {
            $o .= '<input class="form-control" type="text" autocomplete="off" ' . $f . ' maxlength="' . (int) ($p['max_length'] ?? 256) . '" value="' . rmmH($val) . '" placeholder="{{field.name}}">'
                . '<div class="form-text">A secret in a schedule must be a placeholder for a secret custom field, written {{field.name}}; the secret itself is never stored here.</div>';
        } elseif ($type === 'secret') {
            $o .= '<input class="form-control" type="password" autocomplete="new-password" ' . $f . ' maxlength="' . (int) ($p['max_length'] ?? 256) . '">'
                . '<div class="form-text">A secret is never stored in the job or shown in its output.' . ($placeholderHint ? ' In a schedule or a run that needs approval, type a placeholder such as {{field.name}} instead of the secret itself.' : '') . '</div>';
        } else {
            $o .= '<input class="form-control" type="text" ' . $f . ' maxlength="' . (int) ($p['max_length'] ?? 256) . '" value="' . rmmH($val) . '"' . (array_key_exists('default', $p) ? ' placeholder="' . rmmH((string) $p['default']) . '"' : '') . '>';
        }
        if ($desc !== '') {
            $o .= '<div class="form-text">' . rmmH($desc) . '</div>';
        }
        $o .= '</div>';
    }

    return $o;
}

/**
 * Read `param[<name>]` from $_POST against a definition into the typed object Core wants (ints as ints, bools as bools, secrets as strings). Empty
 * values are left out so a default applies. `$only` limits to the schema in use; unknown names are dropped (Core would refuse them).
 *
 * @param list<array<string,mixed>> $schema
 * @return array<string,string|int|bool>
 */
function rivetRmmScrReadParams(array $schema): array
{
    $in = $_POST['param'] ?? [];
    $in = is_array($in) ? $in : [];
    $out = [];
    foreach ($schema as $p) {
        $name = (string) $p['name'];
        $v = $in[$name] ?? null;
        if (!is_string($v) || trim($v) === '') {
            continue;
        }
        $type = (string) ($p['type'] ?? 'string');
        if ($type === 'int') {
            $out[$name] = preg_match('/^-?\d{1,15}$/', trim($v)) === 1 ? (int) trim($v) : $v;
        } elseif ($type === 'bool') {
            $out[$name] = $v === 'true' ? true : ($v === 'false' ? false : $v);
        } elseif ($type === 'secret') {
            $out[$name] = $v;   // as typed: no trimming of a secret
        } else {
            $out[$name] = $v;
        }
    }

    return $out;
}

/** One editable parameter-definition row of the script editor. `$i` may be '__i__' (the blank template row). */
function rivetRmmScrParamDefRow($i, array $d = []): string
{
    $k = 'pdef[' . $i . ']';
    $id = 'pd_' . $i;
    $type = (string) ($d['type'] ?? 'string');
    $types = ['string' => 'Text', 'int' => 'Whole number', 'bool' => 'Yes / no', 'choice' => 'Choice', 'secret' => 'Secret'];
    $opts = '';
    foreach ($types as $t => $w) {
        $opts .= '<option value="' . $t . '"' . ($t === $type ? ' selected' : '') . '>' . $w . '</option>';
    }
    $choices = implode("\n", array_map('strval', (array) ($d['choices'] ?? [])));
    $def = array_key_exists('default', $d) ? (is_bool($d['default']) ? ($d['default'] ? 'true' : 'false') : (string) $d['default']) : '';

    return '<div class="rmm-row" data-rmm-index="' . rmmH((string) $i) . '"><div class="row g-2">'
        . '<div class="col-md-3"><label class="form-label" for="' . $id . '_name">Name</label><input class="form-control" type="text" id="' . $id . '_name" name="' . $k . '[name]" maxlength="32" pattern="[a-z][a-z0-9_]{0,31}" data-rmm-param-name="1" value="' . rmmH((string) ($d['name'] ?? '')) . '" autocomplete="off" aria-describedby="' . $id . '_name_fb"><div class="invalid-feedback" id="' . $id . '_name_fb">Lower case letters, digits and _, starting with a letter, at most 32 characters. Names must be unique.</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="' . $id . '_type">Type</label><select class="form-select" id="' . $id . '_type" name="' . $k . '[type]">' . $opts . '</select></div>'
        . '<div class="col-md-4"><label class="form-label" for="' . $id . '_label">Label</label><input class="form-control" type="text" id="' . $id . '_label" name="' . $k . '[label]" maxlength="80" value="' . rmmH((string) ($d['label'] ?? '')) . '"></div>'
        . '<div class="col-md-2 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="' . $id . '_req" name="' . $k . '[required]" value="1"' . (!empty($d['required']) ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '_req">Required</label></div></div>'
        . '<div class="col-md-6"><label class="form-label" for="' . $id . '_desc">Description</label><input class="form-control" type="text" id="' . $id . '_desc" name="' . $k . '[description]" maxlength="200" value="' . rmmH((string) ($d['description'] ?? '')) . '"></div>'
        . '<div class="col-md-4" data-rmm-when="#' . $id . '_type=string,int,bool,choice"><label class="form-label" for="' . $id . '_def">Default value</label><input class="form-control" type="text" id="' . $id . '_def" name="' . $k . '[default]" value="' . rmmH($def) . '"></div>'
        . '<div class="col-md-2" data-rmm-when="#' . $id . '_type=string,secret"><label class="form-label" for="' . $id . '_max">Longest, bytes</label><input class="form-control" type="number" min="1" max="1024" id="' . $id . '_max" name="' . $k . '[max_length]" value="' . rmmH((string) ($d['max_length'] ?? '')) . '"></div>'
        . '<div class="col-md-3" data-rmm-when="#' . $id . '_type=int"><label class="form-label" for="' . $id . '_min">Smallest</label><input class="form-control" type="number" step="1" id="' . $id . '_min" name="' . $k . '[min]" value="' . rmmH((string) ($d['min'] ?? '')) . '"></div>'
        . '<div class="col-md-3" data-rmm-when="#' . $id . '_type=int"><label class="form-label" for="' . $id . '_maxv">Largest</label><input class="form-control" type="number" step="1" id="' . $id . '_maxv" name="' . $k . '[max]" value="' . rmmH((string) ($d['max'] ?? '')) . '"></div>'
        . '<div class="col-12" data-rmm-when="#' . $id . '_type=choice"><label class="form-label" for="' . $id . '_choices">Choices, one per line</label><textarea class="form-control" rows="3" id="' . $id . '_choices" name="' . $k . '[choices]">' . rmmH($choices) . '</textarea></div>'
        . '<div class="col-12 text-end"><button type="button" class="btn btn-outline-secondary rmm-row-remove" data-rmm-remove="1" aria-label="Remove this parameter"><i class="fas fa-trash me-1" aria-hidden="true"></i>Remove</button></div>'
        . '</div></div>';
}

/**
 * Read the editor's parameter rows (`pdef[i][...]`) into Core's definition list (typed). Rows without a name are dropped.
 *
 * @return list<array<string,mixed>>
 */
function rivetRmmScrReadParamDefs(): array
{
    $rows = $_POST['pdef'] ?? [];
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
        if (!is_array($r)) {
            continue;
        }
        $s = static fn (string $k, int $max = 200): string => isset($r[$k]) && is_string($r[$k]) ? mb_substr(trim($r[$k]), 0, $max) : '';
        $name = $s('name', 40);
        if ($name === '') {
            continue;
        }
        $type = $s('type', 10) !== '' ? $s('type', 10) : 'string';
        $d = ['name' => $name, 'type' => $type, 'required' => !empty($r['required'])];
        foreach (['label', 'description'] as $k) {
            if ($s($k) !== '') {
                $d[$k] = $s($k);
            }
        }
        $int = static fn (string $v): ?int => preg_match('/^-?\d{1,15}$/', $v) === 1 ? (int) $v : null;
        if ($type === 'string' || $type === 'secret') {
            $m = $int($s('max_length', 6));
            if ($m !== null) {
                $d['max_length'] = $m;
            }
        } elseif ($type === 'int') {
            foreach (['min', 'max'] as $k) {
                if ($s($k, 18) !== '') {
                    $d[$k] = $int($s($k, 18)) ?? $s($k, 18);   // a non-number is passed on so Core says so
                }
            }
        } elseif ($type === 'choice') {
            $d['choices'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($r['choices'] ?? '')) ?: []), static fn (string $c): bool => $c !== ''));
        }
        if ($type !== 'secret' && $s('default', 1024) !== '') {
            $dv = $s('default', 1024);
            $d['default'] = $type === 'int' ? ($int($dv) ?? $dv) : ($type === 'bool' ? (in_array(strtolower($dv), ['true', '1', 'yes', 'on'], true) ? true : (in_array(strtolower($dv), ['false', '0', 'no', 'off'], true) ? false : $dv)) : $dv);
        }
        $out[] = $d;
    }

    return $out;
}

/** The confirmation sentence for a run (built here; js/rmm_scr.js rebuilds it with the same words when the version or target changes). */
function rivetRmmScrRunSentence(string $script, string $version, string $target, bool $destructive): string
{
    return 'Run the script "' . $script . '", version ' . $version . ', on ' . $target . '. One job is queued for each matching device that is linked and able to run it; a device that is offline runs it when it returns, until the job expires.'
        . ($destructive ? ' This script is marked destructive: it changes or removes things on the devices.' : '');
}

// ------------------------------------------------------------------ the script library: list

/** @return array{filters:array<string,mixed>,items:list<array<string,mixed>>,total:int,error:string,status:int} */
function rivetRmmScrLibraryView(array $ctx, array $get): array
{
    $str = static fn (string $k, int $max): string => is_string($get[$k] ?? null) ? mb_substr(trim($get[$k]), 0, $max) : '';
    $lang = $str('language', 12);
    $f = ['q' => $str('q', 100), 'language' => ScriptLanguage::valid($lang) ? $lang : '', 'tag' => $str('tag', 30), 'retired' => ($get['retired'] ?? '') === '1', 'page' => max(1, (int) ($get['page'] ?? 1))];
    $q = ['limit' => (string) RIVET_RMM_SCR_PAGE, 'offset' => (string) (($f['page'] - 1) * RIVET_RMM_SCR_PAGE)];
    foreach (['q', 'language', 'tag'] as $k) {
        if ($f[$k] !== '') {
            $q[$k] = $f[$k];
        }
    }
    if ($f['retired']) {
        $q['include_retired'] = '1';
    }
    $r = rivetRmmScrApi($ctx, 'GET', ['scripts'], $q);

    return ['filters' => $f, 'items' => $r['ok'] ? (array) ($r['data']['data'] ?? []) : [], 'total' => (int) ($r['data']['total'] ?? 0), 'error' => $r['ok'] ? '' : $r['error'], 'status' => $r['status']];
}

function rivetRmmScrFlags(array $s): string
{
    return rivetRmmStarterBadge((array) ($s['tags'] ?? [])) . ($s['requires_approval'] ? rivetRmmUiPill('warn', 'Needs approval') . ' ' : '') . ($s['destructive'] ? rivetRmmUiPill('crit', 'Destructive') . ' ' : '')
        . (!empty($s['retired']) ? rivetRmmUiPill('off', 'Retired') : '');
}

function rivetRmmScrTagChips(array $tags): string
{
    $o = '';
    // the pack's own tags (`starter`, `risk:*`) are shown as the Starter badge and the risk chip in the Flags column, not as plain tags
    foreach (rivetRmmStarterTagInfo($tags)['rest'] as $t) {
        $o .= '<span class="badge text-bg-light border me-1">' . rmmH((string) $t) . '</span>';
    }

    return $o === '' ? '<span class="text-muted small">none</span>' : $o;
}

/** The "update waiting for review" marker of a starter script in the library list. */
function rivetRmmStarterWaitingMarker(int $scriptId): string
{
    return ' <a class="text-decoration-none" href="/agent/rmm_script_library.php?script_id=' . $scriptId . '#scr-versions" title="The starter pack has a newer version of this script that was not applied because you edited it">'
        . rivetRmmUiPill('warn', 'Update waiting for review', 'rmm-starter-waiting') . '</a>';
}

/** A version's note: the pack's own notes in plain words (with the marker and a link to the differences for a parked update), any other note as written. */
function rivetRmmScrNoteHtml(string $note, int $scriptId, int $current, int $version, bool $canRead): string
{
    $words = rivetRmmStarterNoteWords($note);
    if ($words === null) {
        return rmmH($note);
    }
    if (!rivetRmmStarterIsStagedNote($note)) {
        return rmmH($words);
    }

    return rivetRmmUiPill('warn', 'Update waiting for review', 'rmm-starter-waiting') . '<div class="small text-muted">' . rmmH($words) . '</div>'
        . ($canRead && $version !== $current ? '<a class="small" href="/agent/rmm_script_library.php?script_id=' . $scriptId . '&amp;from=' . $current . '&amp;to=' . $version . '#scr-diff">Compare with the current version</a>' : '');
}

function rivetRmmScrLibraryPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $f = $vm['filters'];
    $self = '/agent/rmm_script_library.php';
    $url = static fn (int $p) => $self . '?' . http_build_query(array_filter(['q' => $f['q'], 'language' => $f['language'], 'tag' => $f['tag'], 'retired' => $f['retired'] ? '1' : '', 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== ''));
    $new = $perm['run_script'] ? '<a class="btn btn-primary btn-sm" href="' . $self . '?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New script</a>' : '';
    if ($perm['admin']) {   // rc.13: the starter pack is administrators only (the page and Core check again)
        $new = '<a class="btn btn-outline-primary btn-sm" href="' . RIVET_RMM_STARTER_PAGE . '" id="scr-starter-link"><i class="fas fa-box-open me-1" aria-hidden="true"></i>Starter pack</a> ' . $new;
    }
    $o = rivetRmmAutoHeader('Scripts', 'file-code', $new,
        'Scripts you can run on your devices, or schedule. Every change to the text makes a new, signed version: the older ones stay readable and a schedule or a pending approval keeps the version it was set up with.');
    $o .= rivetRmmScrTabs('library', $ctx['mysqli']);
    $o .= '<form method="get" action="' . $self . '" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-4"><label class="form-label" for="scr_q">Search</label><input class="form-control" type="search" id="scr_q" name="q" maxlength="100" value="' . rmmH($f['q']) . '" placeholder="Name or description"></div>'
        . '<div class="col-md-2"><label class="form-label" for="scr_lang">Language</label><select class="form-select" id="scr_lang" name="language"><option value="">Any</option>';
    foreach (ScriptLanguage::all() as $l) {
        $o .= '<option value="' . $l . '"' . ($f['language'] === $l ? ' selected' : '') . '>' . rmmH(rivetRmmScrLanguage($l)) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="scr_tag">Tag</label><input class="form-control" type="text" id="scr_tag" name="tag" maxlength="30" value="' . rmmH($f['tag']) . '"></div>'
        . '<div class="col-md-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="scr_ret" name="retired" value="1"' . ($f['retired'] ? ' checked' : '') . '><label class="form-check-label" for="scr_ret">Include retired</label></div></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="fas fa-search me-1" aria-hidden="true"></i>Filter</button></div></div></div></form>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if ($vm['items'] === []) {
        $filtered = $f['q'] !== '' || $f['language'] !== '' || $f['tag'] !== '';

        return $o . rivetRmmUiCard('Scripts', 'file-code', rivetRmmUiEmpty('fas fa-file-code', $filtered ? 'No script matches' : 'No scripts yet',
            $filtered ? 'Try another search, or include retired scripts.' : ($perm['run_script'] ? 'Publish the first script to run it on devices.' : 'An administrator or a technician with script rights can publish scripts here.'),
            $perm['run_script'] && !$filtered ? '<a class="btn btn-primary" href="' . $self . '?new=1">New script</a>' : ''), '', '', true);
    }
    $rows = '';
    $waiting = rivetRmmStarterWaiting($ctx, $vm['items']);   // administrators only, and only when the page holds a starter script
    foreach ($vm['items'] as $s) {
        $id = (int) $s['script_id'];
        $acts = '';
        if ($perm['run_saved'] && empty($s['retired'])) {
            $acts .= '<a class="btn btn-sm btn-outline-primary" href="' . $self . '?script_id=' . $id . '#scr-run"><i class="fas fa-play me-1" aria-hidden="true"></i>Run</a> ';
        }
        if ($perm['run_script']) {
            if (empty($s['retired'])) {
                $acts .= '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?script_id=' . $id . '#scr-edit">Edit</a> '
                    . rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'retire_script', ['script_id' => $id], $csrf, $url($vm['filters']['page']), 'Retire', 'btn btn-sm btn-outline-danger',
                        'Retire the script "' . $s['name'] . '"? It can no longer be run or scheduled, its history stays, and you can restore it later.');
            } else {
                $acts .= rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'restore_script', ['script_id' => $id], $csrf, $url($vm['filters']['page']), 'Restore', 'btn btn-sm btn-outline-secondary');
            }
        }
        $rows .= '<tr><td class="ps-3"><a href="' . $self . '?script_id=' . $id . '">' . rmmH($s['name']) . '</a>' . ($s['description'] !== '' ? '<div class="small text-muted">' . rmmH(mb_substr((string) $s['description'], 0, 120)) . '</div>' : '') . '</td>'
            . '<td>' . rmmH(rivetRmmScrLanguage((string) $s['language'])) . '</td><td>' . rmmH(rivetRmmScrPlatformWords((string) $s['language'])) . '</td>'
            . '<td>v' . (int) $s['current_version'] . '</td><td>' . rivetRmmScrTagChips((array) $s['tags']) . '</td><td>' . ((rivetRmmScrFlags($s) . (isset($waiting[$id]) ? rivetRmmStarterWaitingMarker($id) : '')) ?: '<span class="text-muted small">none</span>') . '</td>'
            . '<td>' . rivetRmmAutoUtc((string) $s['updated_at']) . '</td><td class="text-end pe-3 rmm-table-actions">' . $acts . '</td></tr>';
    }
    $table = '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Library scripts</caption>'
        . rivetRmmUiThead(['Script', 'Language', 'Runs on', 'Version', 'Tags', 'Flags', 'Last updated', 'Actions']) . '<tbody>' . $rows . '</tbody></table></div>';
    $o .= rivetRmmUiCard('Scripts', 'file-code', $table . '<div class="px-3 pb-3">' . rivetRmmScrPager($f['page'], $vm['total'], RIVET_RMM_SCR_PAGE, $url) . '</div>', '', '<span class="small text-muted">' . (int) $vm['total'] . ' in all</span>', true);
    if (!$perm['run_saved']) {
        $o .= '<p class="small text-muted">Your role sees what each script is and does, but not its text.</p>';
    }

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

// ------------------------------------------------------------------ one script: view-model

/**
 * @return array<string,mixed> status/error, script (with current + body when allowed), versions (newest first), runs, view (one version with body), diff
 */
function rivetRmmScrScriptView(array $ctx, array $get): array
{
    $id = (int) ($get['script_id'] ?? 0);
    $vm = ['id' => $id, 'new' => $id === 0, 'status' => 200, 'error' => '', 'script' => null, 'versions' => [], 'runs' => null, 'runs_error' => '', 'view' => null, 'view_error' => '', 'diff' => null, 'diff_error' => '',
        'from' => 0, 'to' => 0, 'names' => [], 'body_visible' => false, 'draft' => null];
    // the form values of a save that was refused (put in the session by the handler), shown once
    $d = $_SESSION['rmm_scr_draft'] ?? null;
    if (is_array($d) && (int) ($d['script_id'] ?? -1) === $id) {
        $vm['draft'] = $d['draft'];
    }
    unset($_SESSION['rmm_scr_draft']);
    if ($id === 0) {
        return $vm;
    }
    $r = rivetRmmScrApi($ctx, 'GET', ['scripts', $id], ['body' => '1']);
    if (!$r['ok']) {
        $vm['status'] = $r['status'];
        $vm['error'] = $r['error'];

        return $vm;
    }
    $vm['script'] = $r['data']['script'];
    $vm['body_visible'] = isset($vm['script']['current']['body']);
    $v = rivetRmmScrApi($ctx, 'GET', ['scripts', $id, 'versions']);
    $vm['versions'] = $v['ok'] ? (array) ($v['data']['data'] ?? []) : [];
    $have = array_column($vm['versions'], null, 'version');
    if ($ctx['perm']['run_saved']) {
        $runs = rivetRmmScrApi($ctx, 'GET', ['scripts', $id, 'runs'], ['limit' => '15']);
        $vm['runs'] = $runs['ok'] ? (array) ($runs['data']['data'] ?? []) : null;
        $vm['runs_error'] = $runs['ok'] ? '' : $runs['error'];
    }
    $view = (int) ($get['view'] ?? 0);
    if ($view > 0 && isset($have[$view])) {
        $one = rivetRmmScrApi($ctx, 'GET', ['scripts', $id, 'versions', $view]);
        $vm['view'] = $one['ok'] ? $one['data']['version'] : null;
        $vm['view_error'] = $one['ok'] ? '' : $one['error'];
    }
    $from = (int) ($get['from'] ?? 0);
    $to = (int) ($get['to'] ?? 0);
    if ($from > 0 && $to > 0) {
        $vm['from'] = $from;
        $vm['to'] = $to;
        if (!isset($have[$from], $have[$to])) {
            $vm['diff_error'] = 'Pick two versions of this script.';
        } else {
            $a = rivetRmmScrApi($ctx, 'GET', ['scripts', $id, 'versions', $from]);
            $b = rivetRmmScrApi($ctx, 'GET', ['scripts', $id, 'versions', $to]);
            if (!$a['ok'] || !$b['ok']) {
                $vm['diff_error'] = $a['ok'] ? $b['error'] : $a['error'];
            } else {
                $vm['diff'] = rivetRmmScrDiff((string) $a['data']['version']['body'], (string) $b['data']['version']['body']);
                $vm['diff']['params_changed'] = json_encode($a['data']['version']['params']) !== json_encode($b['data']['version']['params']);
            }
        }
    }
    $vm['names'] = rivetRmmScrUserNames($ctx['mysqli'], array_column($vm['versions'], 'created_by'));

    return $vm;
}

// ------------------------------------------------------------------ one script: renderers

/** The editor form (create when `$script` is null). */
function rivetRmmScrEditor(?array $script, string $csrf, array $ctx, ?array $draft = null): string
{
    $new = $script === null;
    $cur = $script['current'] ?? [];
    if ($draft !== null) {   // the values of a save that Core refused, so nothing typed is lost
        $script = array_merge($script ?? [], $draft);
        $cur = (array) $draft['current'];
    }
    $maxBytes = \RivetCore\Rmm\RmmProtocol::JOB_MAX_SCRIPT_BYTES;
    $self = '/agent/rmm_script_library.php';
    $return = $new ? $self : $self . '?script_id=' . (int) $script['script_id'];
    $lang = (string) ($script['language'] ?? 'bash');
    $o = '<form method="post" action="/agent/post/' . RIVET_RMM_SCR_HANDLER . '.php" data-rmm-once="1" data-rmm-guard="1" id="scr-editor" class="rmmx-pad-bottom" novalidate>' . rivetRmmAutoHidden($csrf, $return)
        . '<input type="hidden" name="action" value="save_script"><input type="hidden" name="script_id" value="' . (int) ($script['script_id'] ?? 0) . '"><div class="row g-3">'
        . '<div class="col-md-6"><label class="form-label" for="scr_name">Name</label><input class="form-control" type="text" id="scr_name" name="name" maxlength="100" required value="' . rmmH((string) ($script['name'] ?? '')) . '"></div>';
    if ($new) {
        $o .= '<div class="col-md-6"><label class="form-label" for="scr_language">Language</label><select class="form-select" id="scr_language" name="language">';
        foreach (ScriptLanguage::all() as $l) {
            $o .= '<option value="' . $l . '"' . ($l === $lang ? ' selected' : '') . '>' . rmmH(rivetRmmScrLanguage($l) . ' (runs on ' . rivetRmmScrPlatformWords($l) . ')') . '</option>';
        }
        $o .= '</select></div>';
    } else {
        $o .= '<div class="col-md-6"><span class="form-label d-block">Language</span><div class="form-control-plaintext">' . rmmH(rivetRmmScrLanguage($lang) . ', runs on ' . rivetRmmScrPlatformWords($lang)) . '</div><div class="form-text">The language of a script cannot change; publish a new script instead.</div></div>';
    }
    $o .= '<div class="col-12"><label class="form-label" for="scr_desc">Description</label><input class="form-control" type="text" id="scr_desc" name="description" maxlength="500" value="' . rmmH((string) ($script['description'] ?? '')) . '"></div>'
        . '<div class="col-12"><label class="form-label" for="scr_body">Script text</label>'
        . '<textarea class="form-control rmm-code" rows="16" id="scr_body" name="body" spellcheck="false" autocomplete="off" autocapitalize="off" wrap="off" required>' . "\n" . rmmH((string) ($cur['body'] ?? '')) . '</textarea>'
        . '<div class="form-text" id="scr_counter" aria-live="polite" data-scr-counter="#scr_body" data-max-bytes="' . $maxBytes . '" data-ps-chars="' . ScriptLanguage::POWERSHELL_MAX_CHARS . '"'
        . ($new ? ' data-lang-source="#scr_language"' : ' data-lang="' . rmmH($lang) . '"') . '>Limits: ' . number_format($maxBytes) . ' bytes; a PowerShell script is at most ' . number_format(ScriptLanguage::POWERSHELL_MAX_CHARS) . ' characters (it travels in an encoded command line).</div></div>'
        . '<div class="col-md-8"><label class="form-label" for="scr_note">Version note</label><input class="form-control" type="text" id="scr_note" name="note" maxlength="200" placeholder="What changed (kept with the new version)">'
        . '<div class="form-text">Saving with a changed text or changed parameters makes a new, immutable, signed version. Saving only the settings below does not.</div></div>'
        . '<div class="col-md-4"><label class="form-label" for="scr_timeout">Default timeout, seconds</label><input class="form-control" type="number" min="1" id="scr_timeout" name="timeout_s" value="' . rmmH((string) ($script['timeout_s'] ?? '')) . '" placeholder="instance default"></div>'
        . '<div class="col-md-12"><label class="form-label" for="scr_tags">Tags</label><input class="form-control" type="text" id="scr_tags" name="tags" maxlength="400" value="' . rmmH(implode(', ', (array) ($script['tags'] ?? []))) . '"><div class="form-text">Comma separated, up to 10 (letters, digits, spaces and _ . : -).</div></div>'
        . '<div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="scr_appr" name="requires_approval" value="1"' . (!empty($script['requires_approval']) ? ' checked' : '') . '><label class="form-check-label" for="scr_appr">Needs approval: every run waits until a second person approves it</label></div></div>'
        . '<div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="scr_destr" name="destructive" value="1"' . (!empty($script['destructive']) ? ' checked' : '') . '><label class="form-check-label" for="scr_destr">Destructive: it changes or removes things, so every run asks for an explicit confirmation</label></div></div>'
        . '</div>';
    $rows = '';
    foreach (array_values((array) ($cur['params'] ?? [])) as $i => $p) {
        $rows .= rivetRmmScrParamDefRow($i, $p);
    }
    $o .= '<section class="rmmx-section" aria-labelledby="scr-params-h"><h6 id="scr-params-h" class="rmmx-section-title">Parameters</h6>'
        . '<p class="rmmx-section-help">A parameter reaches the script as an environment variable named <code>RIVETIT_PARAM_&lt;name&gt;</code> and as a generated variable. It is never spliced into the code, so a value cannot change what the script does. A secret parameter is never stored in the job record or shown in the output.</p>'
        . '<ul class="rmmx-rules"><li>Names use lower case letters, digits and <code>_</code>, start with a letter, and are at most 32 characters.</li><li>Names that would shadow something the shell needs (for example <code>path</code>, <code>home</code>, <code>user</code>, <code>error</code>, <code>if</code>) are refused.</li><li>Up to 20 parameters.</li></ul>'
        . '<div data-rmm-repeat="1" data-rmm-max="20" id="scr-params"><div data-rmm-rows="1">' . $rows . '</div>'
        . '<div class="rmmx-param-empty">No parameters yet. Add one to pass values into the script.</div>'
        . '<template data-rmm-row="1">' . rivetRmmScrParamDefRow('__i__') . '</template>'
        . '<button type="button" class="btn btn-outline-secondary rmmx-add-row" data-rmm-add="#scr-params"><i class="fas fa-plus" aria-hidden="true"></i>Add a parameter</button></div></section>'
        . '<div class="rmmx-bar rmmx-form-bar" role="region" aria-label="Save"><span class="rmmx-bar-count" data-rmm-dirty-msg hidden>You have unsaved changes</span>'
        . '<a class="btn btn-outline-secondary" href="' . $self . '">Cancel</a>'
        . '<button type="submit" class="btn btn-primary rmmx-primary-cta"><i class="fas fa-save me-1" aria-hidden="true"></i>' . ($new ? 'Publish script' : 'Save') . '</button></div></form>';

    return $o;
}

/** The "Run this script" panel on the script page. @param list<array<string,mixed>> $versions */
function rivetRmmScrRunPanel(array $script, array $versions, array $scope, string $csrf): string
{
    $id = (int) $script['script_id'];
    $self = '/agent/rmm_script_library.php?script_id=' . $id;
    $cur = (int) $script['current_version'];
    $vOpts = '';
    $params = '';
    foreach (array_slice($versions, 0, 20) as $v) {
        $n = (int) $v['version'];
        $vOpts .= '<option value="' . $n . '"' . ($n === $cur ? ' selected' : '') . '>Version ' . $n . ($n === $cur ? ' (current)' : '') . '</option>';
        $params .= '<div data-rmm-when="#scr_run_version=' . $n . '">' . rivetRmmScrParamInputs((array) $v['params'], 'scr_run_v' . $n) . '</div>';
    }
    $destr = !empty($script['destructive']);
    $firstTarget = 'the target you choose';
    $sentence = rivetRmmScrRunSentence((string) $script['name'], (string) $cur, $firstTarget, $destr);
    $o = '<form method="post" action="/agent/post/' . RIVET_RMM_SCR_HANDLER . '.php" data-rmm-once="1" data-scr-run="1" data-script-name="' . rmmH($script['name']) . '" data-destructive="' . ($destr ? '1' : '0') . '" id="scr-run-form">'
        . rivetRmmAutoHidden($csrf, $self) . '<input type="hidden" name="action" value="run_script"><input type="hidden" name="script_id" value="' . $id . '">'
        . '<div class="row g-3"><div class="col-md-4"><label class="form-label" for="scr_run_version">Version</label><select class="form-select" id="scr_run_version" name="script_version">' . $vOpts . '</select></div>'
        . '<div class="col-md-4"><label class="form-label" for="scr_run_timeout">Timeout, seconds</label><input class="form-control" type="number" min="1" id="scr_run_timeout" name="timeout_s" value="' . (int) $script['timeout_s'] . '"></div>'
        . '<div class="col-md-12">' . rivetRmmAutoScopePicker('tgt', RIVET_RMM_SCR_TARGETS, 'device', 0, $scope, 'Run on') . '<div class="form-text">A device only receives the script when it can run it: ' . rmmH(rivetRmmScrLanguage((string) $script['language'])) . ' runs on ' . rmmH(rivetRmmScrPlatformWords((string) $script['language'])) . ' devices.</div></div>'
        . '<div class="col-12"><h6 class="mb-2">Parameters</h6>' . $params . '</div>';
    if ($destr) {
        // the explicit confirmation is the extra checkbox of the in-page dialog (data-rmm-confirm-check); js/rmm_scr.js puts it into this field when the person confirms
        $o .= '<input type="hidden" name="confirm" value="">';
    }
    if (!empty($script['requires_approval'])) {
        $o .= '<div class="col-12"><div class="alert alert-info small py-2 mb-0" role="note"><i class="fas fa-user-check me-1" aria-hidden="true"></i>This script needs approval: the run waits until a second person approves it, and a secret parameter must then be a {{field.name}} placeholder.</div></div>';
    }
    $o .= '<div class="col-12"><button type="submit" class="btn btn-primary" data-scr-go="1" data-rmm-confirm="' . rmmH($sentence) . '" data-rmm-confirm-label="Run script"' . ($destr ? ' data-rmm-confirm-check="I confirm this destructive script may run now."' : '') . '>'
        . '<i class="fas fa-play me-1" aria-hidden="true"></i>Run script</button> <span class="small text-muted ms-2">A run on several devices, on a whole department or on all devices asks you to confirm first. A larger run may need a second person to approve it.</span></div>'
        . '</div></form>';

    return $o;
}

function rivetRmmScrVersionsCard(array $vm, array $ctx): string
{
    $id = (int) $vm['id'];
    $self = '/agent/rmm_script_library.php?script_id=' . $id;
    $cur = (int) $vm['script']['current_version'];
    $rows = '';
    foreach ($vm['versions'] as $v) {
        $n = (int) $v['version'];
        $rows .= '<tr><td class="ps-3">v' . $n . ($n === $cur ? ' ' . rivetRmmUiPill('ok', 'Current') : '') . '</td><td>' . rmmH($vm['names'][(int) $v['created_by']] ?? ('user #' . (int) $v['created_by'])) . '</td><td>' . rivetRmmAutoUtc((string) $v['created_at']) . '</td>'
            . '<td>' . rivetRmmScrHash((string) $v['body_sha256']) . '</td><td>' . rivetRmmScrNoteHtml((string) $v['note'], $id, $cur, $n, (bool) $ctx['perm']['run_saved']) . '</td><td class="text-end pe-3 rmm-table-actions">'
            . ($ctx['perm']['run_saved'] ? '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '&amp;view=' . $n . '#scr-version">View</a>' : '') . '</td></tr>';
    }
    $o = '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><caption class="visually-hidden">Versions of this script</caption>'
        . rivetRmmUiThead(['Version', 'Author', 'When', 'SHA-256 (first 16)', 'Note', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    if ($ctx['perm']['run_saved'] && count($vm['versions']) > 1) {
        $opt = static function (string $name, int $sel) use ($vm): string {
            $s = '';
            foreach ($vm['versions'] as $v) {
                $s .= '<option value="' . (int) $v['version'] . '"' . ((int) $v['version'] === $sel ? ' selected' : '') . '>Version ' . (int) $v['version'] . '</option>';
            }
            return $s;
        };
        $o .= '<form method="get" action="/agent/rmm_script_library.php" class="p-3 border-top"><input type="hidden" name="script_id" value="' . $id . '"><div class="row g-2 align-items-end">'
            . '<div class="col-6 col-md-3"><label class="form-label" for="scr_from">Compare</label><select class="form-select" id="scr_from" name="from">' . $opt('from', $vm['from'] ?: (int) $vm['versions'][min(1, count($vm['versions']) - 1)]['version']) . '</select></div>'
            . '<div class="col-6 col-md-3"><label class="form-label" for="scr_to">With</label><select class="form-select" id="scr_to" name="to">' . $opt('to', $vm['to'] ?: $cur) . '</select></div>'
            . '<div class="col-md-3"><button class="btn btn-outline-primary" type="submit"><i class="fas fa-columns me-1" aria-hidden="true"></i>Show the differences</button></div></div></form>';
    }

    return rivetRmmUiCard('Versions', 'history', $o, '', '<span class="small text-muted">' . count($vm['versions']) . ' kept</span>', true, 'scr-versions');
}

function rivetRmmScrScriptPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_script_library.php';
    $back = '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>All scripts</a>';
    if ($vm['new']) {
        if (!$perm['run_script']) {
            return rivetRmmAutoHeader('New script', 'file-code', $back) . '<div class="alert alert-warning" role="alert">Your role cannot publish scripts. Publishing needs the scripts permission at level 3 (the same as running free-form script text).</div>';
        }

        return rivetRmmAutoHeader('New script', 'file-code', $back, 'The first version is signed with this installation\'s key when you publish it.')
            . rivetRmmUiCard('Script', 'edit', rivetRmmScrEditor(null, $csrf, $ctx, $vm['draft']), '', '', false, 'scr-edit') . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
    }
    if ($vm['script'] === null) {
        return rivetRmmAutoHeader('Script', 'file-code', $back) . rivetRmmUiEmpty('fas fa-file-code', 'Script not found', $vm['error'] !== '' ? $vm['error'] : 'It may have been removed.');
    }
    $s = $vm['script'];
    $retired = !empty($s['retired']);
    $acts = $back;
    if ($perm['run_script']) {
        $acts .= ' ' . ($retired
            ? rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'restore_script', ['script_id' => $s['script_id']], $csrf, $self . '?script_id=' . (int) $s['script_id'], 'Restore', 'btn btn-sm btn-outline-secondary')
            : rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'retire_script', ['script_id' => $s['script_id']], $csrf, $self . '?script_id=' . (int) $s['script_id'], 'Retire', 'btn btn-sm btn-outline-danger',
                'Retire the script "' . $s['name'] . '"? It can no longer be run or scheduled, its history stays, and you can restore it later.'));
    }
    $o = rivetRmmAutoHeader((string) $s['name'], 'file-code', $acts, (string) $s['description']);
    if ($retired) {
        $o .= '<div class="alert alert-secondary" role="status"><i class="fas fa-archive me-1" aria-hidden="true"></i>This script is retired. It cannot be run, scheduled or edited until it is restored.</div>';
    }
    $cur = (array) ($s['current'] ?? []);
    $staged = [];
    foreach ($vm['versions'] as $sv) {
        if (rivetRmmStarterIsStagedNote((string) $sv['note'])) {
            $staged[] = (int) $sv['version'];
        }
    }
    if ($staged !== [] && ($perm['admin'] || $perm['run_script'])) {
        $o .= '<div class="alert alert-warning d-flex gap-2" role="status" id="scr-starter-waiting"><i class="fas fa-exclamation-triangle mt-1" aria-hidden="true"></i><div><strong>The starter pack has an update for this script, waiting for your review.</strong> '
            . 'You edited the script, so the pack did not overwrite it: its newer text was added as version ' . implode(', ', $staged) . ' and the script keeps running version ' . (int) $s['current_version'] . '. '
            . ($perm['run_saved'] ? '<a href="' . $self . '?script_id=' . (int) $s['script_id'] . '&amp;from=' . (int) $s['current_version'] . '&amp;to=' . max($staged) . '#scr-diff">See the differences</a>, then save the text you want as a new version.' : '') . '</div></div>';
    }
    $o .= rivetRmmUiCard('About this script', 'info-circle', rivetRmmUiDl([
        ['Language', rmmH(rivetRmmScrLanguage((string) $s['language']))], ['Runs on', rmmH(rivetRmmScrPlatformWords((string) $s['language'])) . ' devices'],
        ['Current version', 'v' . (int) $s['current_version'] . ' <span class="text-muted small">signed ' . rivetRmmAutoUtc((string) ($cur['created_at'] ?? '')) . '</span>'],
        ['SHA-256 (first 16)', rivetRmmScrHash((string) ($cur['body_sha256'] ?? ''))], ['Tags', rivetRmmScrTagChips((array) $s['tags'])],
        ['Flags', rivetRmmScrFlags($s) ?: '<span class="text-muted">none</span>'], ['Default timeout', rmmH(rivetRmmScrSecondsWords((int) $s['timeout_s']))], ['Last updated', rivetRmmAutoUtc((string) $s['updated_at'])],
    ]));
    if ($perm['run_saved'] && !$retired) {
        $scope = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
        $o .= rivetRmmUiCard('Run this script', 'play', rivetRmmScrRunPanel($s, $vm['versions'], $scope, $csrf), 'primary', '', false, 'scr-run');
    }
    if ($perm['run_script'] && !$retired) {
        $o .= rivetRmmUiCard('Edit script', 'edit', rivetRmmScrEditor($s, $csrf, $ctx, $vm['draft']), '', '', false, 'scr-edit');
    } elseif ($vm['body_visible']) {
        $o .= rivetRmmUiCard('Script text (version ' . (int) $s['current_version'] . ')', 'code', '<pre class="rmm-code rmm-pre mb-0" tabindex="0">' . rmmH((string) $cur['body']) . '</pre>');
        if ((array) ($cur['params'] ?? []) !== []) {
            $o .= rivetRmmUiCard('Parameters', 'sliders-h', rivetRmmScrParamTable((array) $cur['params']), '', '', true);
        }
    } else {
        $o .= '<div class="alert alert-secondary small" role="note"><i class="fas fa-eye-slash me-1" aria-hidden="true"></i>The script text is hidden: your role may not read library scripts.</div>';
        if ((array) ($cur['params'] ?? []) !== []) {
            $o .= rivetRmmUiCard('Parameters', 'sliders-h', rivetRmmScrParamTable((array) $cur['params']), '', '', true);
        }
    }
    $o .= rivetRmmScrVersionsCard($vm, $ctx);
    if ($vm['view'] !== null) {
        $v = $vm['view'];
        $o .= rivetRmmUiCard('Version ' . (int) $v['version'], 'file-alt', '<p class="small text-muted mb-2">Written ' . rivetRmmAutoUtc((string) $v['created_at']) . ' by ' . rmmH($vm['names'][(int) $v['created_by']] ?? 'a user') . '. SHA-256 ' . rivetRmmScrHash((string) $v['body_sha256']) . '</p>'
            . '<pre class="rmm-code rmm-pre mb-2" tabindex="0">' . rmmH((string) ($v['body'] ?? '')) . '</pre>' . rivetRmmScrParamTable((array) $v['params']), '', '', false, 'scr-version');
    } elseif ($vm['view_error'] !== '') {
        $o .= '<div class="alert alert-warning" role="alert">' . rmmH($vm['view_error']) . '</div>';
    }
    if ($vm['diff_error'] !== '') {
        $o .= '<div class="alert alert-warning" role="alert">' . rmmH($vm['diff_error']) . '</div>';
    } elseif ($vm['diff'] !== null) {
        $d = $vm['diff'];
        $sum = $d['added'] === 0 && $d['removed'] === 0 ? 'The text of the two versions is identical' . (!empty($d['params_changed']) ? ', only the parameters differ.' : '.') : $d['added'] . ' line' . ($d['added'] === 1 ? '' : 's') . ' added, ' . $d['removed'] . ' line' . ($d['removed'] === 1 ? '' : 's') . ' removed.' . (!empty($d['params_changed']) ? ' The parameters changed too.' : '');
        $o .= rivetRmmUiCard('Differences: version ' . (int) $vm['from'] . ' to version ' . (int) $vm['to'], 'columns', '<p class="small mb-2" role="status">' . rmmH($sum) . '</p>' . rivetRmmScrDiffTable($d), '', '', false, 'scr-diff');
    }
    if ($perm['run_saved']) {
        $o .= rivetRmmUiCard('Recent runs', 'tasks', rivetRmmScrRunsTable($vm['runs'], $vm['runs_error']), '', '', true, 'scr-runs');
    }
    if ($perm['admin']) {
        $o .= rivetRmmUiCard('Signature', 'key', '<p class="small mb-2">Each version is signed with this installation\'s signing key and checked before it runs. After the signing key has been rotated, the older versions no longer verify and refuse to run until an administrator signs them again with the new key. '
            . 'Re-signing vouches for the stored text.</p>' . rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'resign_script', ['script_id' => $s['script_id']], $csrf, $self . '?script_id=' . (int) $s['script_id'], '<i class="fas fa-key me-1" aria-hidden="true"></i>Re-sign all versions',
                'btn btn-sm btn-outline-secondary', 'Sign every version of "' . $s['name'] . '" again with the current signing key? Only do this when you trust the stored script text.'));
    }

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

/** @param list<array<string,mixed>> $schema */
function rivetRmmScrParamTable(array $schema): string
{
    if ($schema === []) {
        return '<p class="small text-muted mb-0">No parameters.</p>';
    }
    $rows = '';
    foreach ($schema as $p) {
        $extra = [];
        if (isset($p['choices'])) {
            $extra[] = 'one of ' . implode(', ', array_map('strval', (array) $p['choices']));
        }
        foreach (['min' => 'at least', 'max' => 'at most', 'max_length' => 'up to'] as $k => $w) {
            if (isset($p[$k])) {
                $extra[] = $w . ' ' . $p[$k] . ($k === 'max_length' ? ' bytes' : '');
            }
        }
        if (array_key_exists('default', $p)) {
            $extra[] = 'default ' . (is_bool($p['default']) ? ($p['default'] ? 'true' : 'false') : (string) $p['default']);
        }
        $rows .= '<tr><td class="ps-3"><code>' . rmmH((string) $p['name']) . '</code></td><td>' . rmmH((string) $p['type']) . '</td><td>' . (!empty($p['required']) ? 'yes' : 'no') . '</td><td>' . rmmH((string) ($p['label'] ?? '')) . '</td><td class="small">' . rmmH(implode('; ', $extra)) . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Parameters of this version</caption>' . rivetRmmUiThead(['Name', 'Type', 'Required', 'Label', 'Rules']) . '<tbody>' . $rows . '</tbody></table></div>';
}

function rivetRmmScrRunsTable(?array $runs, string $error): string
{
    if ($runs === null) {
        return '<div class="p-3 text-muted small">' . rmmH($error !== '' ? $error : 'Runs are not shown to your role.') . '</div>';
    }
    if ($runs === []) {
        return '<div class="p-3 text-muted small">This script has not run yet.</div>';
    }
    $rows = '';
    foreach ($runs as $r) {
        $rows .= '<tr><td class="ps-3">' . rmmH((string) ($r['hostname'] !== '' ? $r['hostname'] : 'device #' . $r['device_id'])) . '</td><td>' . ($r['script_version'] === null ? '' : 'v' . (int) $r['script_version']) . '</td><td>' . rivetRmmScrJobPill((string) $r['state']) . '</td>'
            . '<td>' . ($r['exit_code'] === null ? '<span class="text-muted">none</span>' : (int) $r['exit_code']) . '</td><td>' . rivetRmmAutoUtc((string) $r['queued_at']) . '</td><td>' . rivetRmmAutoUtc($r['finished_at']) . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><caption class="visually-hidden">Recent runs of this script</caption>'
        . rivetRmmUiThead(['Device', 'Version', 'State', 'Exit code', 'Queued', 'Finished']) . '<tbody>' . $rows . '</tbody></table></div>';
}

// ------------------------------------------------------------------ schedules: view-models

/** Is this schedule's target inside what the user may see? (Core lists every schedule; a restricted user sees only those whose target the scope data knows.) */
function rivetRmmScrScheduleVisible(array $s, array $scope, bool $restricted): bool
{
    if (!$restricted) {
        return true;
    }
    $t = (string) $s['target']['type'];
    $lists = ['device' => 'devices', 'client' => 'clients', 'site' => 'sites', 'group' => 'groups', 'tag' => 'tags'];

    return isset($lists[$t]) && isset($scope[$lists[$t]][(int) $s['target']['id']]);
}

/** @return array<string,mixed> */
function rivetRmmScrSchedulesView(array $ctx, array $get): array
{
    $vm = ['items' => [], 'error' => '', 'hidden' => 0, 'scope' => [], 'scripts' => [], 'restricted' => false, 'pending' => 0];
    $r = rivetRmmScrApi($ctx, 'GET', ['schedules']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'];

        return $vm;
    }
    $vm['restricted'] = rivetRmmModule($ctx['mysqli'])->authorizer()->visibleClientIds($ctx['uid']) !== null;
    $vm['scope'] = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
    $sc = rivetRmmScrApi($ctx, 'GET', ['scripts'], ['limit' => '500', 'include_retired' => '1']);
    foreach ($sc['ok'] ? (array) ($sc['data']['data'] ?? []) : [] as $x) {
        $vm['scripts'][(int) $x['script_id']] = $x;
    }
    foreach ((array) ($r['data']['data'] ?? []) as $s) {
        if (!rivetRmmScrScheduleVisible($s, $vm['scope'], $vm['restricted'])) {
            ++$vm['hidden'];
            continue;
        }
        $s['target_label'] = rivetRmmAutoScopeLabel((string) $s['target']['type'], (int) $s['target']['id'], $vm['scope']);
        $s['script_current'] = (int) ($vm['scripts'][(int) $s['script_id']]['current_version'] ?? 0);
        $s['script_retired'] = !empty($vm['scripts'][(int) $s['script_id']]['retired']);
        $vm['items'][] = $s;
    }
    foreach ($vm['items'] as $i => $s) {
        if ($i < 40 && !empty($s['last_run_at'])) {
            $d = rivetRmmScrApi($ctx, 'GET', ['schedules', (int) $s['schedule_id']]);
            $vm['items'][$i]['last_run'] = $d['ok'] ? ((array) ($d['data']['runs'] ?? []))[0] ?? null : null;
        }
    }

    return $vm;
}

/** @return array<string,mixed> */
function rivetRmmScrScheduleDetail(array $ctx, array $get): array
{
    $id = (int) ($get['schedule_id'] ?? 0);
    $vm = ['id' => $id, 'schedule' => null, 'runs' => [], 'history' => [], 'error' => '', 'status' => 200, 'scope' => [], 'device' => (int) ($get['device_id'] ?? 0), 'page' => max(1, (int) ($get['page'] ?? 1)),
        'script' => null, 'versions' => [], 'edit' => !empty($get['edit']), 'new' => !empty($get['new']) && $id === 0, 'scripts' => []];
    $vm['scope'] = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
    // the form values of a save that was refused (put in the session by the handler), shown once
    $vm['draft'] = null;
    $d = $_SESSION['rmm_scr_sched_draft'] ?? null;
    if (is_array($d) && (int) ($d['schedule_id'] ?? -1) === $id && ($vm['new'] || $vm['edit'])) {
        $vm['draft'] = $d['draft'];
    }
    unset($_SESSION['rmm_scr_sched_draft']);
    if ($vm['new']) {
        $vm['scripts'] = rivetRmmScrScriptChoices($ctx);

        return $vm;
    }
    $r = rivetRmmScrApi($ctx, 'GET', ['schedules', $id]);
    if (!$r['ok']) {
        $vm['status'] = $r['status'];
        $vm['error'] = $r['error'];

        return $vm;
    }
    $restricted = rivetRmmModule($ctx['mysqli'])->authorizer()->visibleClientIds($ctx['uid']) !== null;
    if (!rivetRmmScrScheduleVisible($r['data']['schedule'], $vm['scope'], $restricted)) {
        $vm['status'] = 404;
        $vm['error'] = 'Schedule not found.';

        return $vm;
    }
    $vm['schedule'] = $r['data']['schedule'];
    $vm['runs'] = (array) ($r['data']['runs'] ?? []);
    $q = ['limit' => (string) RIVET_RMM_SCR_PAGE, 'offset' => (string) (($vm['page'] - 1) * RIVET_RMM_SCR_PAGE)];
    if ($vm['device'] > 0) {
        $q['device_id'] = (string) $vm['device'];
    }
    $h = rivetRmmScrApi($ctx, 'GET', ['schedules', $id, 'history'], $q);
    $vm['history'] = $h['ok'] ? (array) ($h['data']['data'] ?? []) : [];
    $vm['history_error'] = $h['ok'] ? '' : $h['error'];
    $sc = rivetRmmScrApi($ctx, 'GET', ['scripts', (int) $vm['schedule']['script_id']]);
    $vm['script'] = $sc['ok'] ? $sc['data']['script'] : null;
    $vs = rivetRmmScrApi($ctx, 'GET', ['scripts', (int) $vm['schedule']['script_id'], 'versions']);
    $vm['versions'] = $vs['ok'] ? (array) ($vs['data']['data'] ?? []) : [];
    if ($vm['edit']) {
        $vm['scripts'] = rivetRmmScrScriptChoices($ctx);
    }

    return $vm;
}

/**
 * The scripts a schedule can use (not retired), each with its versions and their parameter definitions: one list call plus one versions call per script (at most 40).
 *
 * @return list<array{script:array<string,mixed>,versions:list<array<string,mixed>>}>
 */
function rivetRmmScrScriptChoices(array $ctx, ?string $platform = null, int $max = 40): array
{
    $r = rivetRmmScrApi($ctx, 'GET', ['scripts'], ['limit' => '200']);
    $out = [];
    foreach ($r['ok'] ? (array) ($r['data']['data'] ?? []) : [] as $s) {
        if ($platform !== null && $s['platform'] !== $platform) {
            continue;
        }
        if (count($out) >= $max) {
            break;
        }
        $v = rivetRmmScrApi($ctx, 'GET', ['scripts', (int) $s['script_id'], 'versions']);
        $out[] = ['script' => $s, 'versions' => $v['ok'] ? (array) ($v['data']['data'] ?? []) : []];
    }

    return $out;
}

// ------------------------------------------------------------------ schedules: renderers

function rivetRmmScrSchedulePill(array $s): string
{
    if (!$s['approved']) {
        return rivetRmmUiPill('warn', 'Waiting for approval');
    }

    return $s['enabled'] ? rivetRmmUiPill('ok', 'Active') : rivetRmmUiPill('off', 'Paused');
}

function rivetRmmScrNextRunCell(array $s): string
{
    if (!$s['approved']) {
        return '<span class="text-muted">after it is approved</span>';
    }
    if (!$s['enabled']) {
        return '<span class="text-muted">not while paused</span>';
    }
    [$when, $computed] = rivetRmmScrNextRun($s);
    if ($when === null) {
        return '<span class="text-muted">none</span>';
    }

    return ($computed ? '<span class="small text-muted">about </span>' : '') . rivetRmmScrTimeBoth($when);
}

function rivetRmmScrSchedulesPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_schedules.php';
    $o = rivetRmmAutoHeader('Script schedules', 'clock', $perm['admin'] ? '<a class="btn btn-primary btn-sm" href="' . $self . '?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New schedule</a>' : '',
        'A schedule runs one version of a library script again and again on a target. Times are shown in the company time zone; a cron expression is always written in UTC. Administrators manage schedules; everyone with access can see them.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if ($vm['hidden'] > 0) {
        $o .= '<p class="small text-muted">' . (int) $vm['hidden'] . ' schedule' . ($vm['hidden'] === 1 ? '' : 's') . ' that reach other departments are not shown to you.</p>';
    }
    if ($vm['items'] === []) {
        return $o . rivetRmmUiCard('Schedules', 'clock', rivetRmmUiEmpty('fas fa-clock', 'No schedules yet', $perm['admin'] ? 'Create one to run a library script on a timetable.' : 'An administrator can create schedules.',
            $perm['admin'] ? '<a class="btn btn-primary" href="' . $self . '?new=1">New schedule</a>' : ''), '', '', true);
    }
    $rows = '';
    foreach ($vm['items'] as $s) {
        $id = (int) $s['schedule_id'];
        $last = '<span class="text-muted">never</span>';
        if (!empty($s['last_run_at'])) {
            $lr = $s['last_run'] ?? null;
            $last = rivetRmmUiTime((string) $s['last_run_at']) . ($lr ? '<div class="small text-muted">' . (int) $lr['jobs_created'] . ' job' . ((int) $lr['jobs_created'] === 1 ? '' : 's')
                . (($lr['skipped_overlap'] + $lr['skipped_gate'] + $lr['skipped_other']) > 0 ? ', ' . (int) ($lr['skipped_overlap'] + $lr['skipped_gate'] + $lr['skipped_other']) . ' skipped' : '') . '</div>' : '');
        }
        $newer = $s['script_current'] > (int) $s['script_version'] ? '<div class="small text-warning-emphasis"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>version ' . (int) $s['script_current'] . ' exists</div>' : '';
        $acts = '';
        if ($perm['admin']) {
            $acts = '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?schedule_id=' . $id . '&amp;edit=1">Edit</a> ';
            if ($s['approved']) {
                $acts .= rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'toggle_schedule', ['schedule_id' => $id, 'enabled' => $s['enabled'] ? '0' : '1'], $csrf, $self, $s['enabled'] ? 'Pause' : 'Resume', 'btn btn-sm btn-outline-secondary') . ' ';
            }
            $acts .= rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'delete_schedule', ['schedule_id' => $id], $csrf, $self, 'Delete', 'btn btn-sm btn-outline-danger',
                'Delete the schedule "' . $s['name'] . '" and its run history? Jobs already queued are not cancelled.');
        }
        $rows .= '<tr><td class="ps-3"><a href="' . $self . '?schedule_id=' . $id . '">' . rmmH($s['name']) . '</a></td>'
            . '<td><a href="/agent/rmm_script_library.php?script_id=' . (int) $s['script_id'] . '">' . rmmH($s['script_name'] !== '' ? $s['script_name'] : 'script #' . $s['script_id']) . '</a> v' . (int) $s['script_version'] . $newer . ($s['script_retired'] ? '<div>' . rivetRmmUiPill('off', 'Script retired') . '</div>' : '') . '</td>'
            . '<td>' . rmmH($s['target_label']) . '</td><td>' . rmmH(rivetRmmScrCadence($s)) . '</td><td>' . rivetRmmScrNextRunCell($s) . '</td>'
            . '<td>' . rivetRmmScrSchedulePill($s) . (!$s['approved'] && $s['approval_id'] ? '<div class="small"><a href="/agent/rmm_approvals.php?state=pending_approval#approval-' . (int) $s['approval_id'] . '">See the approval request</a></div>' : '') . '</td>'
            . '<td>' . $last . '</td><td class="text-end pe-3 rmm-table-actions">' . $acts . '</td></tr>';
    }
    $table = '<div class="table-responsive"><table class="table table-hover table-sm mb-0"><caption class="visually-hidden">Script schedules</caption>'
        . rivetRmmUiThead(['Schedule', 'Script', 'Runs on', 'Cadence', 'Next run', 'State', 'Last run', $perm['admin'] ? 'Actions' : '']) . '<tbody>' . $rows . '</tbody></table></div>';

    return $o . rivetRmmUiCard('Schedules', 'clock', $table, '', '<span class="small text-muted">' . count($vm['items']) . ' shown</span>', true) . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

/** The schedule form (create when `$s` is null). @param list<array{script:array<string,mixed>,versions:list<array<string,mixed>>}> $choices */
function rivetRmmScrScheduleForm(?array $s, array $choices, array $scope, string $csrf, ?array $draft = null): string
{
    $self = '/agent/rmm_schedules.php';
    $new = $s === null;
    if ($draft !== null) {
        $s = array_merge($s ?? [], $draft, ['params' => (array) ($draft['params'] ?? [])]);
        $s['schedule_id'] = (int) ($s['schedule_id'] ?? 0);
    }
    $selScript = (int) ($s['script_id'] ?? ($choices[0]['script']['script_id'] ?? 0));
    $o = '<form method="post" action="/agent/post/' . RIVET_RMM_SCR_HANDLER . '.php" data-rmm-once="1" id="sch-form">' . rivetRmmAutoHidden($csrf, $new ? $self : $self . '?schedule_id=' . (int) $s['schedule_id'])
        . '<input type="hidden" name="action" value="save_schedule"><input type="hidden" name="schedule_id" value="' . (int) ($s['schedule_id'] ?? 0) . '"><div class="row g-3">'
        . '<div class="col-md-6"><label class="form-label" for="sch_name">Name</label><input class="form-control" type="text" id="sch_name" name="name" maxlength="100" required value="' . rmmH((string) ($s['name'] ?? '')) . '"></div>';
    $sopts = '';
    foreach ($choices as $c) {
        $sid = (int) $c['script']['script_id'];
        $sopts .= '<option value="' . $sid . '"' . ($sid === $selScript ? ' selected' : '') . '>' . rmmH($c['script']['name'] . ' (' . rivetRmmScrLanguage((string) $c['script']['language']) . ')') . '</option>';
    }
    $o .= '<div class="col-md-6"><label class="form-label" for="sch_script">Script</label><select class="form-select" id="sch_script" name="library_script_id">' . $sopts . '</select></div>';
    $vers = '';
    $params = '';
    foreach ($choices as $c) {
        $sid = (int) $c['script']['script_id'];
        $cur = (int) $c['script']['current_version'];
        $isSel = $sid === (int) ($s['script_id'] ?? 0);
        $pinned = $isSel ? (int) $s['script_version'] : $cur;
        $vo = '';
        foreach (array_slice($c['versions'], 0, 30) as $v) {
            $vo .= '<option value="' . (int) $v['version'] . '"' . ((int) $v['version'] === $pinned ? ' selected' : '') . '>Version ' . (int) $v['version'] . ((int) $v['version'] === $cur ? ' (current)' : '') . '</option>';
        }
        $warn = $isSel && $pinned < $cur ? '<div class="form-text text-warning-emphasis"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>A newer version (' . $cur . ') exists. This schedule keeps running version ' . $pinned . ' until you choose the newer one.</div>' : '';
        $vers .= '<div class="col-md-6" data-rmm-when="#sch_script=' . $sid . '"><label class="form-label" for="sch_ver_' . $sid . '">Version to run (pinned)</label><select class="form-select" id="sch_ver_' . $sid . '" name="script_version">' . $vo . '</select>'
            . '<div class="form-text">The current version is ' . $cur . '. A later edit of the script never changes what this schedule runs.</div>' . $warn . '</div>';
        $pv = [];
        foreach ($c['versions'] as $v) {
            if ((int) $v['version'] === $pinned) {
                $pv = (array) $v['params'];
            }
        }
        $params .= '<div data-rmm-when="#sch_script=' . $sid . '"><h6 class="mt-2">Parameters</h6>' . rivetRmmScrParamInputs($pv, 'sch_p' . $sid, $isSel ? (array) $s['params'] : [], true, true)
            . '<div class="form-text">Parameters shown are those of version ' . $pinned . '.</div></div>';
    }
    $o .= $vers . '<div class="col-12">' . $params . '</div>';
    $o .= '<div class="col-md-6">' . rivetRmmAutoScopePicker('tgt', RIVET_RMM_SCR_TARGETS, (string) ($s['target']['type'] ?? 'tag'), (int) ($s['target']['id'] ?? 0), $scope, 'Runs on') . '</div>';
    $kind = (string) ($s['kind'] ?? 'interval');
    $iv = (int) ($s['interval_s'] ?? 21600);
    [$ivVal, $ivUnit] = $iv % 86400 === 0 ? [intdiv($iv, 86400), 'day'] : ($iv % 3600 === 0 ? [intdiv($iv, 3600), 'hour'] : [intdiv(max(60, $iv), 60), 'minute']);
    $o .= '<div class="col-md-6"><label class="form-label" for="sch_kind">How often</label><select class="form-select" id="sch_kind" name="kind"><option value="interval"' . ($kind === 'interval' ? ' selected' : '') . '>At a fixed interval</option>'
        . '<option value="cron"' . ($kind === 'cron' ? ' selected' : '') . '>On a cron timetable (UTC)</option></select></div>'
        . '<div class="col-md-6" data-rmm-when="#sch_kind=interval"><label class="form-label" for="sch_iv">Every</label><div class="input-group"><input class="form-control" type="number" min="1" id="sch_iv" name="interval_value" value="' . $ivVal . '">'
        . '<label class="visually-hidden" for="sch_ivu">Unit</label><select class="form-select" id="sch_ivu" name="interval_unit">';
    foreach (['minute' => 'minutes', 'hour' => 'hours', 'day' => 'days'] as $u => $w) {
        $o .= '<option value="' . $u . '"' . ($u === $ivUnit ? ' selected' : '') . '>' . $w . '</option>';
    }
    $o .= '</select></div><div class="form-text">At least 1 minute, at most 30 days.</div></div>'
        . '<div class="col-md-6" data-rmm-when="#sch_kind=cron"><label class="form-label" for="sch_cron">Cron expression, five fields, UTC</label><input class="form-control rmm-mono" type="text" id="sch_cron" name="cron" maxlength="100" value="' . rmmH((string) ($s['cron'] ?? '')) . '" placeholder="30 2 * * *">'
        . '<div class="form-text">Minute, hour, day of month, month, day of week (0 to 7, Sunday is 0 or 7). Each field takes <code>*</code>, a number, a range <code>1-5</code>, a list <code>1,3</code> or a step <code>*/15</code>. Examples: '
        . '<code>*/15 * * * *</code> every 15 minutes; <code>30 2 * * *</code> every day at 02:30 UTC; <code>0 6 * * 1-5</code> Monday to Friday at 06:00 UTC. Names such as MON and shortcuts such as @daily are not supported.</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="sch_start">' . ($new ? 'First run in, minutes' : 'Restart the clock: first run in, minutes') . '</label><input class="form-control" type="number" min="0" id="sch_start" name="start_in_min" placeholder="' . ($new ? 'one interval' : 'leave empty to keep') . '">'
        . '<div class="form-text">' . ($new ? 'For an interval schedule. Empty means after one interval.' : 'Only fill this in to change when it runs next. For cron, the timetable decides.') . '</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="sch_jit">Spread over, minutes</label><input class="form-control" type="number" min="0" max="60" id="sch_jit" name="jitter_min" value="' . (int) round(((int) ($s['jitter_s'] ?? 0)) / 60) . '"><div class="form-text">Devices start at random moments within this time (jitter), 0 to 60.</div></div>'
        . '<div class="col-md-6"><label class="form-label" for="sch_overlap">If the last run on a device is not finished</label><select class="form-select" id="sch_overlap" name="overlap">'
        . '<option value="skip"' . (($s['overlap'] ?? 'skip') === 'skip' ? ' selected' : '') . '>Skip that device this time</option><option value="queue"' . (($s['overlap'] ?? '') === 'queue' ? ' selected' : '') . '>Queue behind it</option></select></div>'
        . '<div class="col-md-3"><label class="form-label" for="sch_exp">Offline devices wait up to, hours</label><input class="form-control" type="number" min="0.02" max="168" step="any" id="sch_exp" name="expires_h" value="' . (isset($s['expires_s']) ? rmmH((string) round($s['expires_s'] / 3600, 2)) : '') . '" placeholder="automatic">'
        . '<div class="form-text">A device that is offline gets the job when it returns, until this window closes.</div></div>'
        . '<div class="col-md-3"><label class="form-label" for="sch_to">Timeout, seconds</label><input class="form-control" type="number" min="1" id="sch_to" name="timeout_s" value="' . rmmH((string) ($s['timeout_s'] ?? '')) . '" placeholder="script default"></div>'
        . '<div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="sch_en" name="enabled" value="1"' . (($s['enabled'] ?? true) ? ' checked' : '') . '><label class="form-check-label" for="sch_en">Enabled (untick to save it paused)</label></div></div>'
        . '<div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="sch_conf" name="confirm" value="1"><label class="form-check-label" for="sch_conf">If the script is destructive: I confirm it may run unattended on this schedule</label></div></div>'
        . '<div class="col-12"><div class="alert alert-info small py-2 mb-0" role="note"><i class="fas fa-user-check me-1" aria-hidden="true"></i>A schedule whose script needs approval, or whose target is larger than the approval limit, is saved but does not run until a second person approves it.</div></div>'
        . '<div class="col-12"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>' . ($new ? 'Create schedule' : 'Save schedule') . '</button> <a class="btn btn-outline-secondary" href="' . $self . '">Cancel</a></div>'
        . '</div></form>';

    return $o;
}

function rivetRmmScrScheduleDetailPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_schedules.php';
    $back = '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>All schedules</a>';
    if ($vm['new'] || $vm['edit']) {
        if (!$perm['admin']) {
            return rivetRmmAutoHeader($vm['new'] ? 'New schedule' : 'Edit schedule', 'clock', $back) . '<div class="alert alert-warning" role="alert">Only administrators can create or change schedules.</div>';
        }
        if ($vm['scripts'] === []) {
            return rivetRmmAutoHeader('New schedule', 'clock', $back) . '<div class="alert alert-warning" role="alert">There is no library script to schedule yet. <a href="/agent/rmm_script_library.php">Publish one first.</a></div>';
        }
        $o = rivetRmmAutoHeader($vm['new'] ? 'New schedule' : 'Edit schedule', 'clock', $back, 'Times are shown in the company time zone; a cron expression is always written in UTC.');
        $o .= rivetRmmUiCard('Schedule', 'edit', rivetRmmScrScheduleForm($vm['schedule'], $vm['scripts'], $vm['scope'], $csrf, $vm['draft']));
        if (count($vm['scripts']) >= 40) {
            $o .= '<p class="small text-muted">Only the first 40 scripts are offered here.</p>';
        }

        return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
    }
    if ($vm['schedule'] === null) {
        return rivetRmmAutoHeader('Schedule', 'clock', $back) . rivetRmmUiEmpty('fas fa-clock', 'Schedule not found', $vm['error'] !== '' ? $vm['error'] : 'It may have been deleted.');
    }
    $s = $vm['schedule'];
    $id = (int) $s['schedule_id'];
    $acts = $back;
    if ($perm['admin']) {
        $acts .= ' <a class="btn btn-sm btn-outline-secondary" href="' . $self . '?schedule_id=' . $id . '&amp;edit=1">Edit</a>';
        if ($s['approved']) {
            $acts .= ' ' . rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'toggle_schedule', ['schedule_id' => $id, 'enabled' => $s['enabled'] ? '0' : '1'], $csrf, $self . '?schedule_id=' . $id, $s['enabled'] ? 'Pause' : 'Resume', 'btn btn-sm btn-outline-secondary');
        }
        $acts .= ' ' . rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'delete_schedule', ['schedule_id' => $id], $csrf, $self, 'Delete', 'btn btn-sm btn-outline-danger', 'Delete the schedule "' . $s['name'] . '" and its run history? Jobs already queued are not cancelled.');
    }
    $o = rivetRmmAutoHeader((string) $s['name'], 'clock', $acts);
    $cur = (int) ($vm['script']['current_version'] ?? 0);
    $params = (array) $s['params'];
    $pv = [];
    foreach ($vm['versions'] as $v) {
        if ((int) $v['version'] === (int) $s['script_version']) {
            $pv = array_column((array) $v['params'], null, 'name');
        }
    }
    $plist = '';
    foreach ($params as $k => $v) {
        $plist .= '<code>' . rmmH((string) $k) . '</code> = ' . (($pv[$k]['type'] ?? '') === 'secret' ? '<span class="text-muted">hidden (secret)</span>' : '<code>' . rmmH(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) . '</code>') . '<br>';
    }
    $o .= rivetRmmUiCard('Definition', 'info-circle', rivetRmmUiDl([
        ['State', rivetRmmScrSchedulePill($s) . (!$s['approved'] && $s['approval_id'] ? ' <a href="/agent/rmm_approvals.php?state=pending_approval#approval-' . (int) $s['approval_id'] . '">See the approval request</a>' : '')],
        ['Script', '<a href="/agent/rmm_script_library.php?script_id=' . (int) $s['script_id'] . '">' . rmmH($s['script_name']) . '</a>, pinned to version ' . (int) $s['script_version'] . ' (SHA-256 ' . rmmH(substr((string) $s['body_sha256'], 0, 16)) . ')'
            . ($cur > (int) $s['script_version'] ? '<div class="small text-warning-emphasis"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>A newer version (' . $cur . ') exists. This schedule keeps running version ' . (int) $s['script_version'] . '.</div>' : '')],
        ['Runs on', rmmH(rivetRmmAutoScopeLabel((string) $s['target']['type'], (int) $s['target']['id'], $vm['scope']))], ['Cadence', rmmH(rivetRmmScrCadence($s)) . ' <span class="text-muted small">(UTC)</span>'],
        ['Next run', rivetRmmScrNextRunCell($s)], ['Last run', rivetRmmUiTime($s['last_run_at'])],
        ['Spread over', rmmH(rivetRmmScrSecondsWords(max(0, (int) $s['jitter_s'])) . ((int) $s['jitter_s'] === 0 ? ' (all at once)' : ''))],
        ['If still running', $s['overlap'] === 'skip' ? 'Skip that device this time' : 'Queue behind the unfinished run'],
        ['Offline devices wait', rmmH(rivetRmmScrSecondsWords((int) $s['expires_s']))], ['Timeout', rmmH(rivetRmmScrSecondsWords((int) $s['timeout_s']))],
        ['Parameters', $plist !== '' ? $plist : '<span class="text-muted">none</span>'],
    ]));
    // last 20 runs (one row per time the schedule came due)
    $rr = '';
    foreach ($vm['runs'] as $r) {
        $rr .= '<tr><td class="ps-3">' . rivetRmmAutoUtc((string) $r['slot_at']) . '</td><td>' . rivetRmmScrJobPill((string) $r['state']) . '</td><td>' . (int) $r['jobs_created'] . '</td><td>' . (int) $r['skipped_overlap'] . '</td><td>' . (int) $r['skipped_gate'] . '</td><td>' . (int) $r['skipped_other'] . '</td><td>' . rivetRmmAutoUtc($r['finished_at']) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Last runs', 'history', $vm['runs'] === [] ? '<div class="p-3 text-muted small">It has not run yet.</div>' : '<p class="small text-muted px-3 pt-2 mb-0">A run stays Running while some devices are held back (by a maintenance window, or by the spread time). They start when the hold ends, until the offline window closes.</p><div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">The last runs of this schedule</caption>'
        . rivetRmmUiThead(['Due at', 'State', 'Jobs queued', 'Skipped: still running', 'Skipped: maintenance window', 'Skipped: other', 'Finished']) . '<tbody>' . $rr . '</tbody></table></div>', '', '', true);
    // per-device history
    $devs = '<option value="0">All devices</option>';
    foreach ($vm['scope']['devices'] as $did => $dn) {
        $devs .= '<option value="' . (int) $did . '"' . ($vm['device'] === (int) $did ? ' selected' : '') . '>' . rmmH($dn) . '</option>';
    }
    $hf = '<form method="get" action="' . $self . '" class="p-3 border-bottom"><input type="hidden" name="schedule_id" value="' . $id . '"><div class="row g-2 align-items-end"><div class="col-md-4"><label class="form-label" for="sch_hdev">Device</label><select class="form-select" id="sch_hdev" name="device_id">' . $devs . '</select></div>'
        . '<div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Filter</button></div></div></form>';
    $hr = '';
    foreach ($vm['history'] as $h) {
        $hr .= '<tr><td class="ps-3">' . rmmH($h['hostname'] !== '' ? $h['hostname'] : 'device #' . $h['device_id']) . '</td><td>' . rivetRmmAutoUtc((string) $h['queued_at']) . '</td><td>' . rivetRmmScrJobPill((string) $h['state']) . '</td>'
            . '<td>' . ($h['exit_code'] === null ? '<span class="text-muted">none</span>' : (int) $h['exit_code']) . '</td><td>' . rivetRmmAutoUtc($h['finished_at']) . '</td></tr>';
    }
    $url = static fn (int $p): string => $self . '?' . http_build_query(array_filter(['schedule_id' => $id, 'device_id' => $vm['device'] ?: '', 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== ''));
    $pager = ($vm['page'] > 1 ? '<a class="btn btn-sm btn-outline-secondary me-1" href="' . rmmH($url($vm['page'] - 1)) . '">Previous</a>' : '')
        . (count($vm['history']) >= RIVET_RMM_SCR_PAGE ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($vm['page'] + 1)) . '">Next</a>' : '');
    $o .= rivetRmmUiCard('Result history per device', 'tasks', $hf . ($vm['history'] === [] ? '<div class="p-3 text-muted small">No device has run it yet.</div>'
        : '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><caption class="visually-hidden">One row per job this schedule created</caption>' . rivetRmmUiThead(['Device', 'Queued', 'State', 'Exit code', 'Finished']) . '<tbody>' . $hr . '</tbody></table></div>')
        . ($pager !== '' ? '<div class="p-3">' . $pager . '</div>' : ''), '', '', true);

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

// ------------------------------------------------------------------ approvals

const RIVET_RMM_SCR_APPROVAL_STATES = ['pending_approval', 'approved', 'rejected', 'cancelled', 'expired', 'all'];

/** @return array<string,mixed> */
function rivetRmmScrApprovalsView(array $ctx, array $get): array
{
    $state = is_string($get['state'] ?? null) && in_array($get['state'], RIVET_RMM_SCR_APPROVAL_STATES, true) ? $get['state'] : 'pending_approval';
    $kind = is_string($get['kind'] ?? null) && in_array($get['kind'], ['run', 'schedule', 'patch', 'software'], true) ? $get['kind'] : '';
    $vm = ['state' => $state, 'kind' => $kind, 'page' => max(1, (int) ($get['page'] ?? 1)), 'items' => [], 'total' => 0, 'error' => '', 'names' => [], 'pending' => 0, 'detail' => []];
    $q = ['limit' => (string) RIVET_RMM_SCR_PAGE, 'offset' => (string) (($vm['page'] - 1) * RIVET_RMM_SCR_PAGE)];
    if ($state !== 'all') {
        $q['state'] = $state;
    }
    if ($kind !== '') {
        $q['kind'] = $kind;
    }
    $r = rivetRmmScrApi($ctx, 'GET', ['approvals'], $q);
    if (!$r['ok']) {
        $vm['error'] = $r['error'];

        return $vm;
    }
    $vm['items'] = (array) ($r['data']['data'] ?? []);
    $vm['total'] = (int) ($r['data']['total'] ?? 0);
    $vm['pending'] = $state === 'pending_approval' && $kind === '' ? $vm['total'] : (int) ((rivetRmmScrApi($ctx, 'GET', ['approvals'], ['state' => 'pending_approval', 'limit' => '1'])['data']['total'] ?? 0));
    $ids = [];
    foreach ($vm['items'] as $a) {
        $ids[] = (int) $a['requested_by'];
        $ids[] = (int) ($a['decided_by'] ?? 0);
    }
    $vm['names'] = rivetRmmScrUserNames($ctx['mysqli'], $ids);
    $pending = array_filter($vm['items'], static fn (array $a): bool => $a['state'] === 'pending_approval');
    if ($pending !== []) {
        $scope = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
        $svc = rivetRmmModule($ctx['mysqli'])->approvals();
        $scripts = [];
        foreach ($pending as $a) {
            // The frozen request (target, parameters, hash) is read from Core's approval service by the id of a row the API has already scoped for this user.
            $req = $svc->requestOf((int) $a['approval_id']) ?? [];
            $sid = (int) ($a['script_id'] ?? $req['script_id'] ?? 0);
            $ver = (int) ($a['script_version'] ?? $req['script_version'] ?? 0);
            if ($sid > 0 && !isset($scripts[$sid])) {
                $sr = rivetRmmScrApi($ctx, 'GET', ['scripts', $sid]);
                $vr = rivetRmmScrApi($ctx, 'GET', ['scripts', $sid, 'versions']);
                $scripts[$sid] = ['script' => $sr['ok'] ? $sr['data']['script'] : null, 'versions' => $vr['ok'] ? array_column((array) ($vr['data']['data'] ?? []), null, 'version') : []];
            }
            $script = $scripts[$sid]['script'] ?? null;
            $vrow = $scripts[$sid]['versions'][$ver] ?? null;
            $body = null;
            $bodyNote = '';
            if ($ctx['perm']['run_saved'] && $vrow !== null) {
                $b = rivetRmmScrApi($ctx, 'GET', ['scripts', $sid, 'versions', $ver]);
                $body = $b['ok'] ? (string) ($b['data']['version']['body'] ?? '') : null;
                $bodyNote = $b['ok'] ? '' : $b['error'];
            } elseif (!$ctx['perm']['run_saved']) {
                $bodyNote = 'Your role may not read library scripts, so the text is not shown here.';
            }
            $secrets = [];
            foreach ((array) ($vrow['params'] ?? []) as $p) {
                if (($p['type'] ?? '') === 'secret') {
                    $secrets[(string) $p['name']] = true;
                }
            }
            $target = is_array($req['target'] ?? null) ? $req['target'] : null;
            $vm['detail'][(int) $a['approval_id']] = ['req' => $req, 'script_name' => (string) ($script['name'] ?? ''), 'version' => $ver, 'sha' => (string) ($vrow['body_sha256'] ?? $req['body_sha256'] ?? ''),
                'params' => (array) ($req['params'] ?? []), 'secrets' => $secrets, 'target' => $target === null ? 'unknown' : rivetRmmAutoScopeLabel((string) $target['type'], (int) $target['id'], $scope),
                'body' => $body === null ? null : (mb_strlen($body) > 6000 ? mb_substr($body, 0, 6000) : $body), 'body_cut' => $body !== null && mb_strlen($body) > 6000, 'body_note' => $bodyNote, 'destructive' => !empty($script['destructive'])];
        }
    }

    return $vm;
}

function rivetRmmScrApprovalsPage(array $vm, array $ctx): string
{
    $perm = $ctx['perm'];
    $csrf = $ctx['csrf'];
    $self = '/agent/rmm_approvals.php';
    $badge = $vm['pending'] > 0 ? ' <span class="badge text-bg-warning ms-2" id="approvals-pending-count"><span class="visually-hidden">Pending requests: </span>' . (int) $vm['pending'] . '</span>' : '';
    $o = '<div class="d-flex align-items-center flex-wrap mb-3" style="gap:6px"><h4 class="mb-0 me-auto"><i class="fas fa-user-check me-2" aria-hidden="true"></i>Approvals' . $badge . '</h4></div>'
        . '<p class="text-muted small mb-3">A run on many devices, or of a script that needs approval, waits here until a second person approves it. Nobody can approve their own request. A request that nobody decides expires on its own.</p>';
    $words = ['pending_approval' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'all' => 'All'];
    $o .= '<form method="get" action="' . $self . '" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label" for="apr_state">State</label><select class="form-select" id="apr_state" name="state">';
    foreach ($words as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($vm['state'] === $k ? ' selected' : '') . '>' . $w . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label" for="apr_kind">Kind</label><select class="form-select" id="apr_kind" name="kind"><option value="">Any kind</option><option value="run"' . ($vm['kind'] === 'run' ? ' selected' : '')
        . '>A run</option><option value="schedule"' . ($vm['kind'] === 'schedule' ? ' selected' : '') . '>A schedule</option><option value="patch"' . ($vm['kind'] === 'patch' ? ' selected' : '') . '>Patch updates</option><option value="software"' . ($vm['kind'] === 'software' ? ' selected' : '') . '>Software</option></select></div><div class="col-md-2"><button class="btn btn-outline-primary" type="submit">Filter</button></div></div></div></form>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if ($vm['items'] === []) {
        return $o . rivetRmmUiCard('Requests', 'user-check', rivetRmmUiEmpty('fas fa-user-check', $vm['state'] === 'pending_approval' ? 'Nothing is waiting for approval' : 'No requests here', 'Requests appear when a script that needs approval is run or a large run is started.'), '', '', true);
    }
    $rows = '';
    foreach ($vm['items'] as $a) {
        $id = (int) $a['approval_id'];
        $pending = $a['state'] === 'pending_approval';
        $by = $vm['names'][(int) $a['requested_by']] ?? ('user #' . (int) $a['requested_by']);
        $mine = (int) $a['requested_by'] === $ctx['uid'];
        $decided = $a['decided_by'] ? ($vm['names'][(int) $a['decided_by']] ?? 'user #' . (int) $a['decided_by']) : '';
        $rows .= '<tr id="approval-' . $id . '"><td class="ps-3">' . rmmH($a['summary']) . '</td><td>' . ($a['kind'] === 'schedule' ? 'Schedule' : ($a['kind'] === 'patch' ? 'Patch updates' : ($a['kind'] === 'software' ? 'Software' : 'Run'))) . '</td><td>' . rmmH($by) . '<div class="small text-muted">' . rivetRmmUiTime((string) $a['requested_at']) . '</div></td>'
            . '<td>' . (int) $a['device_count'] . '</td><td>' . ($pending ? rivetRmmScrTimeBoth((string) $a['expires_at']) : '<span class="text-muted">' . rivetRmmAutoUtc((string) $a['expires_at']) . '</span>') . '</td><td>' . rivetRmmAutoStatePill((string) $a['state']) . '</td>'
            . '<td>' . ($decided !== '' ? rmmH($decided) . '<div class="small text-muted">' . rivetRmmAutoUtc($a['decided_at']) . '</div>' : '<span class="text-muted">-</span>') . (!empty($a['decision_note']) ? '<div class="small">' . rmmH((string) $a['decision_note']) . '</div>' : '') . '</td></tr>';
        if ($pending) {
            $d = $vm['detail'][$id] ?? null;
            $rows .= '<tr class="rmm-approval-detail"><td colspan="7" class="ps-3 pe-3 py-2"><details><summary class="fw-semibold">Details and decision</summary><div class="pt-2">' . ($d === null ? '' : rivetRmmScrApprovalDetail($a, $d)) . rivetRmmScrApprovalActions($a, $mine, $perm, $csrf, $d, $by) . '</div></details></td></tr>';
        } elseif (isset($a['result']['created'])) {
            $rows .= '<tr><td colspan="7" class="ps-3 small text-muted">Result: ' . (int) $a['result']['created'] . ' job(s) queued' . (isset($a['result']['device_count']) ? ' for ' . (int) $a['result']['device_count'] . ' device(s)' : '') . '.</td></tr>';
        }
    }
    $url = static fn (int $p): string => $self . '?' . http_build_query(array_filter(['state' => $vm['state'], 'kind' => $vm['kind'], 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== ''));
    $table = '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Approval requests</caption>' . rivetRmmUiThead(['Request', 'Kind', 'Requested by', 'Devices', 'Expires', 'State', 'Decided by']) . '<tbody>' . $rows . '</tbody></table></div>'
        . '<div class="px-3 pb-3">' . rivetRmmScrPager($vm['page'], $vm['total'], RIVET_RMM_SCR_PAGE, $url) . '</div>';

    return $o . rivetRmmUiCard('Requests', 'user-check', $table, '', '<span class="small text-muted">' . (int) $vm['total'] . ' in all</span>', true) . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

/** What the request will do: script, version, hash, parameters (secrets masked), target, device count, and the script text when the viewer may read it. */
function rivetRmmScrApprovalDetail(array $a, array $d): string
{
    if (($a['kind'] ?? '') === 'software') {
        $req = (array) ($d['req'] ?? []);
        $op = (string) ($req['op'] ?? '');

        return rivetRmmUiDl([
            ['Operation', $op === 'assign' ? 'Assign a package to a scope (goes live when approved)' : ($op === 'run' ? 'Install or remove a package on one device (queued when approved)' : '<span class="text-muted">unknown</span>')],
            ['What', rmmH((string) $a['summary'])], ['Action', rmmH((string) ($req['action'] ?? ''))],
            ['Devices', (int) $a['device_count']], ['Installer', 'Pinned in the catalog by its SHA-256; the agent runs it only if the hash matches.'],
        ]);
    }
    $params = '';
    foreach ($d['params'] as $k => $v) {
        $params .= '<code>' . rmmH((string) $k) . '</code> = ' . (isset($d['secrets'][(string) $k]) ? '<span class="text-muted">hidden (secret)</span>' : '<code>' . rmmH(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) . '</code>') . '<br>';
    }
    $o = rivetRmmUiDl([
        ['Script', $d['script_name'] !== '' ? rmmH($d['script_name']) : '<span class="text-muted">unknown</span>'], ['Version', $d['version'] > 0 ? 'v' . (int) $d['version'] : '<span class="text-muted">unknown</span>'],
        ['SHA-256 (first 16)', rivetRmmScrHash($d['sha'])], ['Parameters', $params !== '' ? $params : '<span class="text-muted">none</span>'],
        ['Runs on', rmmH($d['target']) . ' <span class="text-muted small">(' . (int) $a['device_count'] . ' device' . ((int) $a['device_count'] === 1 ? '' : 's') . ')</span>'],
        ['Destructive', $d['destructive'] ? rivetRmmUiPill('crit', 'Yes') : 'No'],
    ]);
    if ($d['body'] !== null) {
        $o .= '<div class="small text-muted mt-2">Script text' . ($d['body_cut'] ? ' (the first part)' : '') . ':</div><pre class="rmm-code rmm-pre rmm-preview" tabindex="0">' . rmmH($d['body']) . '</pre>';
    } elseif ($d['body_note'] !== '') {
        $o .= '<p class="small text-muted mt-2 mb-0"><i class="fas fa-eye-slash me-1" aria-hidden="true"></i>' . rmmH($d['body_note']) . '</p>';
    }

    return $o;
}

function rivetRmmScrApprovalActions(array $a, bool $mine, array $perm, string $csrf, ?array $d, string $by): string
{
    $id = (int) $a['approval_id'];
    $self = '/agent/rmm_approvals.php';
    $o = '<div class="mt-3 d-flex flex-wrap" style="gap:8px">';
    if ($perm['approve']) {
        if ($mine) {
            $o .= '<span class="text-muted small align-self-center"><i class="fas fa-ban me-1" aria-hidden="true"></i>You cannot approve your own request</span><button type="button" class="btn btn-sm btn-success" disabled>Approve</button>';
        } else {
            $sentence = $a['kind'] === 'software' ? 'Approve this request? ' . $a['summary'] . '. ' . (($d['req']['op'] ?? '') === 'assign' ? 'The assignment goes live: devices in its scope are installed or cleaned up by the scheduler later, inside the limits you set.' : 'A software install or removal is queued right away on the device, as ' . $by . '.')
                : ($a['kind'] === 'patch' ? 'Approve this request? ' . $a['summary'] . '. It approves updates on ' . (int) $a['device_count'] . ' device' . ((int) $a['device_count'] === 1 ? '' : 's') . '; nothing is installed by approving, installation follows the patch policy.'
                : 'Approve this request? ' . $a['summary'] . '. It reaches ' . (int) $a['device_count'] . ' device' . ((int) $a['device_count'] === 1 ? '' : 's') . ($d !== null && $d['script_name'] !== '' ? ', running "' . $d['script_name'] . '" version ' . $d['version'] : '')
                . '. A run is queued right away, as ' . $by . '.');
            $o = '<form method="post" action="/agent/post/' . RIVET_RMM_SCR_HANDLER . '.php" class="mt-3" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $self . '?state=pending_approval') . '<input type="hidden" name="action" value="decide_approval"><input type="hidden" name="approval_id" value="' . $id . '">'
                . '<div class="row g-2 align-items-end"><div class="col-md-6"><label class="form-label" for="apr_note_' . $id . '">Note (optional, kept with the decision)</label><input class="form-control" type="text" id="apr_note_' . $id . '" name="note" maxlength="300"></div>'
                . '<div class="col-md-6"><button type="submit" name="decision" value="approve" class="btn btn-success" data-rmm-confirm="' . rmmH($sentence) . '" data-rmm-confirm-label="Approve"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve</button> '
                . '<button type="submit" name="decision" value="reject" class="btn btn-outline-danger"><i class="fas fa-times me-1" aria-hidden="true"></i>Reject</button></div></div></form><div class="d-flex flex-wrap mt-2" style="gap:8px">';
        }
    } elseif (!$mine) {
        $o .= '<span class="text-muted small align-self-center">Your role cannot approve or reject requests.</span>';
    }
    if ($mine || $perm['admin']) {
        $o .= rivetRmmAutoActionForm(RIVET_RMM_SCR_HANDLER, 'cancel_approval', ['approval_id' => $id], $csrf, $self . '?state=pending_approval', 'Cancel this request', 'btn btn-sm btn-outline-secondary', 'Cancel this request? Nothing is run.');
    }

    return $o . '</div>';
}

// ------------------------------------------------------------------ the asset page: "Run a library script on this device"

/**
 * The card at the top of the Jobs tab. Empty unless the `scripts` switch is on, the device can receive jobs and the viewer may run library scripts. The
 * script list is offered only for scripts whose platform matches the device (a bash script is never offered for a Windows device). Core decides again
 * when the form is posted: this is cosmetic gating.
 */
function rivetRmmScrDeviceSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (empty($vm['features']['scripts']) || empty($vm['usable']) || empty($vm['perm']['run_saved']) || ($vm['state'] ?? '') === 'never' || !rivetRmmFeatureOn('scripts', $mysqli)) {
        return '';   // (the last test is the state file: with the module or the switch off nothing below asks the database anything)
    }
    $ctx = ['uid' => (int) $vm['user_id'], 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'mysqli' => $mysqli, 'perm' => $vm['perm'], 'csrf' => $csrf];
    $platform = (string) $vm['platform'];
    $r = rivetRmmScrApi($ctx, 'GET', ['scripts'], ['limit' => '200']);
    $fits = [];
    foreach ($r['ok'] ? (array) ($r['data']['data'] ?? []) : [] as $s) {
        if ($s['platform'] === $platform && count($fits) < 25) {
            $fits[] = $s;
        }
    }
    $platformWord = ['windows' => 'Windows', 'linux' => 'Linux'][$platform] ?? 'this kind of';
    $title = 'Run a library script on this device';
    $self = '/agent/rmm_script_library.php';
    if ($fits === []) {
        return rivetRmmUiCard($title, 'file-code', '<p class="small text-muted mb-0">No library script runs on a ' . rmmH($platformWord) . ' device yet. <a href="' . $self . '">Open the script library</a>.</p>', '', '', false, 'rmm-library-run');
    }
    $opts = '';
    $blocks = '';
    foreach ($fits as $i => $s) {
        $id = (int) $s['script_id'];
        $d = rivetRmmScrDetailCached($ctx, $id);
        $cur = $d['ok'] ? (array) ($d['data']['script']['current'] ?? []) : [];
        $opts .= '<option value="' . $id . '" data-destructive="' . ($s['destructive'] ? '1' : '0') . '"' . ($i === 0 ? ' selected' : '') . '>' . rmmH($s['name'] . ' (' . rivetRmmScrLanguage((string) $s['language']) . ', v' . (int) $s['current_version'] . ')') . '</option>';
        $blocks .= '<div class="col-12" data-rmm-when="#rmm-ds-script=' . $id . '"><input type="hidden" name="script_version" value="' . (int) $s['current_version'] . '">'
            . ($s['description'] !== '' ? '<p class="small text-muted">' . rmmH($s['description']) . '</p>' : '')
            . rivetRmmScrParamInputs((array) ($cur['params'] ?? []), 'rmm_ds' . $id)
            . ($s['requires_approval'] ? '<div class="alert alert-info small py-2" role="note"><i class="fas fa-user-check me-1" aria-hidden="true"></i>This script needs approval: it waits until a second person approves it.</div>' : '')
            . ($s['destructive'] ? '<input type="hidden" name="confirm" value=""><p class="small text-warning-emphasis mb-0"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>This script is destructive: it changes or removes things on the device. You are asked to confirm before it runs.</p>' : '')
            . '</div>';
    }
    $ret = '/agent/asset_details.php?asset_id=' . (int) $vm['asset_id'] . '#rmm-jobs';
    $first = $fits[0];
    $body = '<form method="post" action="/agent/post/' . RIVET_RMM_SCR_HANDLER . '.php" data-rmm-once="1" data-scr-device="' . rmmH((string) $vm['hostname']) . '" id="rmm-library-run-form">' . rivetRmmAutoHidden($csrf, $ret)
        . '<input type="hidden" name="action" value="run_script"><input type="hidden" name="device_id" value="' . (int) $vm['device_id'] . '"><div class="row g-3">'
        . '<div class="col-md-8"><label class="form-label" for="rmm-ds-script">Script</label><select class="form-select" id="rmm-ds-script" name="library_script_id">' . $opts . '</select>'
        . '<div class="form-text">Only scripts that run on a ' . rmmH($platformWord) . ' device are listed. <a href="' . $self . '">Open the script library</a></div></div>'
        . '<div class="col-md-4"><label class="form-label" for="rmm-ds-timeout">Timeout, seconds</label><input class="form-control" type="number" min="1" id="rmm-ds-timeout" name="timeout_s" placeholder="script default"></div>'
        . $blocks . '<div class="col-12"><button type="submit" class="btn btn-primary" data-scr-go="1" id="rmm-ds-go"'
        . ($first['destructive'] ? ' data-rmm-confirm="' . rmmH(rivetRmmScrRunSentence((string) $first['name'], (string) $first['current_version'], 'this device (' . $vm['hostname'] . ')', true)) . '" data-rmm-confirm-label="Run script" data-rmm-confirm-check="I confirm this destructive script may run now."' : '')
        . '><i class="fas fa-play me-1" aria-hidden="true"></i>Run on this device</button>' . ($vm['offline'] ? ' <span class="small text-muted ms-2">The device is offline: the job waits until it returns.</span>' : '') . '</div></div></form>';

    $js = is_file(dirname(__DIR__) . '/js/rmm_scr.js') ? filemtime(dirname(__DIR__) . '/js/rmm_scr.js') : time();

    return rivetRmmUiCard($title, 'file-code', $body, 'primary', '', false, 'rmm-library-run') . '<script src="/js/rmm_scr.js?v=' . (int) $js . '" defer></script>';
}

// ------------------------------------------------------------------ the asset page: the "Run script" dialog (library scripts, one place to run anything)

/** One script's detail from the technician API, memoised for the request (the Run script dialog and the Jobs-tab card both need it). @return array{ok:bool,data:array<string,mixed>} */
function rivetRmmScrDetailCached(array $ctx, int $id, bool $forget = false): array
{
    static $memo = [];
    if ($forget) {
        $memo = [];

        return ['ok' => false, 'data' => []];
    }
    $k = (int) $ctx['uid'] . ':' . $id;
    if (!isset($memo[$k])) {
        $r = rivetRmmScrApi($ctx, 'GET', ['scripts', $id]);
        $memo[$k] = ['ok' => $r['ok'], 'data' => $r['data']];
    }

    return $memo[$k];
}

/** Drop the request's memoised script details (after a write). */
function rivetRmmScrDetailForget(int $uid): void
{
    rivetRmmScrDetailCached(['uid' => $uid], 0, true);
}

/**
 * The library scripts the Run script dialog offers for one device platform: the not-retired scripts whose platform matches, each with its current version's
 * parameter definitions. Reads only through the technician API as the viewer (Core's client scoping and ability checks apply). Cosmetic gating only: Core
 * decides again when the form is posted. Empty (and no query) unless the `scripts` sub-switch is on and the viewer may run saved scripts.
 *
 * @return array{on:bool,scripts:list<array<string,mixed>>,error:string,truncated:bool}
 */
function rivetRmmScrDeviceLibrary(\mysqli $mysqli, int $userId, string $userName, string $platform, array $perm, int $max = 60): array
{
    $out = ['on' => false, 'scripts' => [], 'error' => '', 'truncated' => false];
    if (empty($perm['run_saved']) || !in_array($platform, ['windows', 'linux'], true) || !rivetRmmFeatureOn('scripts', $mysqli)) {
        return $out;
    }
    $out['on'] = true;
    $ctx = ['uid' => $userId, 'name' => $userName, 'mysqli' => $mysqli, 'perm' => $perm, 'csrf' => ''];
    $r = rivetRmmScrApi($ctx, 'GET', ['scripts'], ['limit' => '200']);
    if (!$r['ok']) {
        $out['error'] = $r['error'] !== '' ? $r['error'] : 'The script library could not be read.';

        return $out;
    }
    foreach ((array) ($r['data']['data'] ?? []) as $s) {
        if (($s['platform'] ?? '') !== $platform || !empty($s['retired'])) {
            continue;
        }
        if (count($out['scripts']) >= $max) {
            $out['truncated'] = true;
            break;
        }
        $d = rivetRmmScrDetailCached($ctx, (int) $s['script_id']);
        $cur = $d['ok'] ? (array) ($d['data']['script']['current'] ?? []) : [];
        $info = rivetRmmStarterTagInfo((array) ($s['tags'] ?? []));
        $out['scripts'][] = ['id' => (int) $s['script_id'], 'name' => (string) $s['name'], 'description' => (string) ($s['description'] ?? ''), 'language' => (string) $s['language'],
            'version' => (int) $s['current_version'], 'starter' => $info['starter'], 'risk' => $info['risk'], 'tags' => $info['rest'], 'destructive' => !empty($s['destructive']),
            'requires_approval' => !empty($s['requires_approval']), 'params' => (array) ($cur['params'] ?? []), 'timeout_s' => (int) ($s['timeout_s'] ?? 0)];
    }
    usort($out['scripts'], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $out;
}

/** Is this parameter the "dry run" switch the starter pack's changing scripts carry? */
function rivetRmmScrIsDryRun(array $p): bool
{
    return (string) ($p['type'] ?? '') === 'bool' && in_array((string) $p['name'], ['dry_run', 'dryrun'], true);
}

/**
 * The typed parameter form of one script in the Run script dialog. Unlike rivetRmmScrParamInputs (the schedule and card form) a bool is a checkbox that always
 * sends an explicit true or false, the dry run switch is labelled in plain words and defaults ON, and every field carries its validation hint.
 * Names are `param[<name>]`, the shape rivetRmmScrReadParams reads.
 *
 * @param list<array<string,mixed>> $schema
 */
function rivetRmmRunParamInputs(array $schema, string $idp): string
{
    if ($schema === []) {
        return '<p class="text-muted small mb-0">This script takes no parameters.</p>';
    }
    $bools = $rest = '';
    foreach ($schema as $p) {
        $name = (string) $p['name'];
        $id = $idp . '_' . $name;
        $label = (string) ($p['label'] ?? '') !== '' ? (string) $p['label'] : $name;
        $type = (string) ($p['type'] ?? 'string');
        $req = !empty($p['required']);
        $desc = (string) ($p['description'] ?? '');
        $hintId = $id . '_h';
        $hints = [];
        $f = 'name="param[' . rmmH($name) . ']" id="' . rmmH($id) . '"' . ($desc !== '' || $type !== 'bool' ? ' aria-describedby="' . rmmH($hintId) . '"' : '');
        if ($type === 'bool') {
            $dry = rivetRmmScrIsDryRun($p);
            $on = $dry ? ($p['default'] ?? true) !== false : !empty($p['default']);
            $bools .= '<div class="form-check mb-2"><input type="hidden" name="param[' . rmmH($name) . ']" value="false"><input class="form-check-input" type="checkbox" value="true" ' . $f . ($on ? ' checked' : '') . '>'
                . '<label class="form-check-label' . ($dry ? ' fw-semibold' : '') . '" for="' . rmmH($id) . '">' . ($dry ? 'Dry run (nothing is changed)' : rmmH($label)) . '</label>'
                . ($dry ? '<div class="form-text" id="' . rmmH($hintId) . '">Report what the script would do without changing anything. Untick it to make the change for real.' . ($desc !== '' ? ' ' . rmmH($desc) : '') . '</div>'
                    : ($desc !== '' ? '<div class="form-text" id="' . rmmH($hintId) . '">' . rmmH($desc) . '</div>' : '')) . '</div>';
            continue;
        }
        $o = '<div class="mb-2"><label class="form-label" for="' . rmmH($id) . '">' . rmmH($label) . ($req ? ' <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(required)</span>' : ' <span class="text-muted small">(optional)</span>') . '</label>';
        $reqAttr = $req ? ' required' : '';
        if ($type === 'int') {
            $o .= '<input class="form-control" type="number" step="1" ' . $f . (isset($p['min']) ? ' min="' . (int) $p['min'] . '"' : '') . (isset($p['max']) ? ' max="' . (int) $p['max'] . '"' : '') . $reqAttr
                . (array_key_exists('default', $p) ? ' placeholder="' . rmmH((string) $p['default']) . '"' : '') . '>';
            $hints[] = 'Whole number' . (isset($p['min'], $p['max']) ? ', ' . (int) $p['min'] . ' to ' . (int) $p['max'] : (isset($p['min']) ? ', at least ' . (int) $p['min'] : (isset($p['max']) ? ', at most ' . (int) $p['max'] : '')));
        } elseif ($type === 'choice') {
            $o .= '<select class="form-select" ' . $f . $reqAttr . '><option value="">' . (array_key_exists('default', $p) ? 'Default (' . rmmH((string) $p['default']) . ')' : 'Choose...') . '</option>';
            foreach ((array) ($p['choices'] ?? []) as $c) {
                $o .= '<option value="' . rmmH((string) $c) . '">' . rmmH((string) $c) . '</option>';
            }
            $o .= '</select>';
        } elseif ($type === 'secret') {
            $o .= '<input class="form-control" type="password" autocomplete="new-password" ' . $f . ' maxlength="' . (int) ($p['max_length'] ?? 256) . '"' . $reqAttr . '>';
            $hints[] = 'A secret is never stored in the job or shown in its output. If this script needs approval, type a placeholder such as {{field.name}} instead of the secret itself';
        } else {
            $o .= '<input class="form-control" type="text" ' . $f . ' maxlength="' . (int) ($p['max_length'] ?? 256) . '"' . $reqAttr . (array_key_exists('default', $p) ? ' placeholder="' . rmmH((string) $p['default']) . '"' : '') . '>';
            if (isset($p['max_length'])) {
                $hints[] = 'Up to ' . (int) $p['max_length'] . ' characters';
            }
        }
        if (array_key_exists('default', $p) && $type !== 'choice') {
            $hints[] = 'Default: ' . (string) $p['default'];
        }
        $o .= '<div class="form-text" id="' . rmmH($hintId) . '">' . ($desc !== '' ? rmmH($desc) . ($hints !== [] ? '. ' : '') : '') . rmmH(implode('. ', $hints)) . ($desc !== '' || $hints !== [] ? '.' : '') . '</div></div>';
        $rest .= $o;
    }

    return ($bools !== '' ? '<fieldset class="mb-2"><legend class="form-label small text-muted mb-1">Options</legend>' . $bools . '</fieldset>' : '') . $rest;
}

/**
 * The "Script library" part of the Run script dialog: a search box, a select of the scripts that run on this device (grouped by risk class), and one parameter form
 * per script (only the chosen one is enabled, so only its values are posted). Submits to the same handler as the Jobs-tab card (agent/post/rmm_automation_scr.php,
 * action run_script), which keeps the CSRF, client scoping and ability checks; js/rmm_panel.js only switches blocks and filters the list.
 *
 * @param array{on:bool,scripts:list<array<string,mixed>>,error:string,truncated:bool} $lib
 */
function rivetRmmScrRunDialogLibrary(array $vm, array $lib, string $csrf): string
{
    $ret = '/agent/asset_details.php?asset_id=' . (int) $vm['asset_id'] . '#rmm-jobs';
    $groups = ['read_only' => 'Read only (nothing is changed)', 'changes_system' => 'Changes the system', 'disruptive' => 'Disruptive', '' => 'Your own scripts (no risk class)'];
    $by = [];
    foreach ($lib['scripts'] as $s) {
        $by[(string) ($s['risk'] ?? '')][] = $s;
    }
    $first = 0;
    foreach (array_keys($groups) as $risk) {
        if (!empty($by[(string) $risk])) {
            $first = (int) $by[(string) $risk][0]['id'];
            break;
        }
    }
    $opts = '';
    foreach ($groups as $risk => $label) {
        if (empty($by[$risk])) {
            continue;
        }
        $opts .= '<optgroup label="' . rmmH($label) . '" data-risk="' . rmmH((string) $risk) . '">';
        foreach ($by[$risk] as $s) {
            $search = mb_strtolower($s['name'] . ' ' . $s['description'] . ' ' . implode(' ', $s['tags']));
            $opts .= '<option value="' . (int) $s['id'] . '" data-search="' . rmmH($search) . '"' . ((int) $s['id'] === $first ? ' selected' : '') . '>' . rmmH($s['name'] . ' (' . rivetRmmScrLanguage($s['language']) . ', v' . $s['version'] . ')') . '</option>';
        }
        $opts .= '</optgroup>';
    }
    $blocks = '';
    foreach ($lib['scripts'] as $s) {
        $id = (int) $s['id'];
        $chips = ($s['starter'] ? '<span class="badge text-bg-primary rmm-starter-badge me-1"><i class="fas fa-box-open me-1" aria-hidden="true"></i>Starter</span>' : '')
            . ($s['risk'] !== null ? rivetRmmStarterRiskPill((string) $s['risk']) . ' ' : '') . ($s['requires_approval'] ? rivetRmmUiPill('warn', 'Needs approval') . ' ' : '') . ($s['destructive'] ? rivetRmmUiPill('crit', 'Destructive') . ' ' : '');
        $tags = '';
        foreach ($s['tags'] as $t) {
            $tags .= '<span class="badge text-bg-light border me-1">' . rmmH($t) . '</span>';
        }
        $inner = '<input type="hidden" name="script_version" value="' . (int) $s['version'] . '">'
            . '<div class="mb-2">' . $chips . $tags . '</div>' . ($s['description'] !== '' ? '<p class="small text-muted mb-2">' . rmmH($s['description']) . '</p>' : '')
            . '<div class="rmm-run-lib-params">' . rivetRmmRunParamInputs((array) $s['params'], 'rmm_rl' . $id) . '</div>'
            . ($s['requires_approval'] ? '<div class="alert alert-info small py-2 mb-2" role="note"><i class="fas fa-user-check me-1" aria-hidden="true"></i>This script needs approval. Nothing runs yet: the request goes to the approvals queue and a second person approves it.</div>' : '')
            . ($s['destructive'] ? '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="rmm_rl' . $id . '_confirm" required><label class="form-check-label" for="rmm_rl' . $id . '_confirm">'
                . 'I confirm this destructive script may run now on ' . rmmH($vm['hostname']) . '.</label></div>' : '');
        if ($id !== $first) {
            // every block but the chosen one starts disabled: its fields are neither validated nor posted; js/rmm_panel.js swaps them when another script is picked
            $inner = (string) preg_replace('~<(input|select|textarea)\b~', '<$1 disabled', $inner);
        }
        $blocks .= '<div class="rmm-run-lib-block" data-script-id="' . $id . '"' . ($id === $first ? '' : ' hidden') . '>' . $inner . '</div>';
    }

    return '<form id="rmm-run-lib-form" method="post" action="/agent/post/' . RIVET_RMM_SCR_HANDLER . '.php" class="rmm-run-pane" data-rmm-run-pane="library">' . rivetRmmAutoHidden($csrf, $ret)
        . '<input type="hidden" name="action" value="run_script"><input type="hidden" name="device_id" value="' . (int) $vm['device_id'] . '">'
        . '<div class="modal-body"><div class="row g-2">'
        . '<div class="col-12"><label class="form-label" for="rmm-run-lib-q">Find a script</label><input class="form-control" type="search" id="rmm-run-lib-q" autocomplete="off" placeholder="Name, tag or description" aria-controls="rmm-run-lib-script">'
        . '<div class="visually-hidden" id="rmm-run-lib-count" role="status" aria-live="polite"></div></div>'
        . '<div class="col-12"><label class="form-label" for="rmm-run-lib-script">Script</label><select class="form-select" id="rmm-run-lib-script" name="library_script_id" size="' . min(8, max(3, count($lib['scripts']) + count($by))) . '" aria-describedby="rmm-run-lib-help">' . $opts . '</select>'
        . '<div class="form-text" id="rmm-run-lib-help">' . count($lib['scripts']) . ' script' . (count($lib['scripts']) === 1 ? '' : 's') . ' for ' . ($vm['platform'] === 'linux' ? 'Linux' : 'Windows') . ' devices, grouped by what they can do to the device.'
        . ($lib['truncated'] ? ' The list is cut short: use Scripts to find the rest.' : '') . '</div></div>'
        . '<div class="col-12" id="rmm-run-lib-detail" aria-live="polite">' . $blocks . '</div>'
        . '<div class="col-md-4"><label class="form-label" for="rmm-run-lib-timeout">Timeout, seconds</label><input class="form-control" type="number" min="1" id="rmm-run-lib-timeout" name="timeout_s" placeholder="script default"></div>'
        . '</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="submit" class="btn btn-primary" id="rmm-run-lib-go"><i class="fas fa-play me-1" aria-hidden="true"></i>Queue job</button></div></form>';
}

// ------------------------------------------------------------------ one Scripts area: the library tab, the "Synced from <provider>" tab and the legacy import

const RIVET_RMM_SCR_LIBRARY_PAGE = '/agent/rmm_script_library.php';
const RIVET_RMM_SCR_LEGACY_PAGE = '/agent/rmm_scripts.php';
const RIVET_RMM_SCR_IMPORT_NOTE = 'imported from legacy Scripts';

/** The default RMM integration's name for labels ("Synced from Tactical RMM"); "RMM" when none is configured. */
function rivetRmmScrLegacyProviderName(\mysqli $mysqli): string
{
    $name = 'RMM';
    $iid = (int) ($GLOBALS['config_rmm_default_integration_id'] ?? 0);
    if ($iid > 0) {
        $r = mysqli_query($mysqli, 'SELECT name, type FROM rmm_integrations WHERE id = ' . $iid . ' LIMIT 1');
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if ($row) {
            $name = $row['name'] ?: (['tactical_rmm' => 'Tactical RMM', 'level' => 'Level.io', 'action1' => 'Action1', 'sophos_central' => 'Sophos Central'][$row['type']] ?? 'RMM');
        }
    }

    return $name;
}

/** The legacy list (agent/rmm_scripts.php, table rmm_scripts) has a tab only when it holds rows or a vendor RMM integration is configured to sync scripts into it. */
function rivetRmmScrLegacyVisible(\mysqli $mysqli): bool
{
    if ((int) ($GLOBALS['config_rmm_default_integration_id'] ?? 0) > 0) {
        return true;
    }
    $r = mysqli_query($mysqli, 'SELECT 1 FROM rmm_scripts LIMIT 1');

    return $r !== false && mysqli_fetch_row($r) !== null;
}

/** The tab strip of the one Scripts area. '' unless BOTH tabs exist (the library switch is on and the legacy list is visible): one source needs no tabs. */
function rivetRmmScrTabs(string $active, \mysqli $mysqli): string
{
    if (!rivetRmmFeatureOn('scripts', $mysqli) || !rivetRmmScrLegacyVisible($mysqli)) {
        return '';
    }
    $tab = static fn (string $key, string $href, string $icon, string $label): string => '<li class="nav-item"><a class="nav-link' . ($active === $key ? ' active' : '') . '" href="' . $href . '"'
        . ($active === $key ? ' aria-current="page"' : '') . '><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . rmmH($label) . '</a></li>';

    return '<nav aria-label="Scripts"><ul class="nav nav-tabs mb-3" id="rmm-scripts-tabs">' . $tab('library', RIVET_RMM_SCR_LIBRARY_PAGE, 'file-code', 'Library')
        . $tab('legacy', RIVET_RMM_SCR_LEGACY_PAGE, 'cloud-download-alt', 'Synced from ' . rivetRmmScrLegacyProviderName($mysqli)) . '</ul></nav>';
}

/** The Core language of a legacy script_type, or null when the library cannot hold it (CMD). */
function rivetRmmScrLegacyLanguage(string $type): ?string
{
    return ['powershell' => 'powershell', 'ps1' => 'powershell', 'bash' => 'bash', 'shell' => 'bash', 'sh' => 'bash', 'python' => 'python'][strtolower(trim($type))] ?? null;
}

/** Body hash as the library stores it (line endings normalised the way the editor posts them). */
function rivetRmmScrBodyHash(string $body): string
{
    return hash('sha256', str_replace("\r\n", "\n", $body));
}

/** A legacy category as a library tag (lower case words joined by -, at most 30 characters), or ''. */
function rivetRmmScrCategoryTag(string $category): string
{
    return substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($category)), '-'), 0, 30);
}

/**
 * The scripts created by hand on the legacy list (no vendor script id, a body) and whether each is already in the library (same name and same text, retired or not).
 *
 * @return list<array{id:int,name:string,category:string,description:string,language:?string,body:string,imported_as:int}>
 */
function rivetRmmScrLegacyManual(array $ctx, \mysqli $mysqli): array
{
    $rows = [];
    $q = mysqli_query($mysqli, "SELECT id, name, category, description, script_type, script_body FROM rmm_scripts WHERE (tactical_script_id IS NULL OR tactical_script_id = 0) AND script_body IS NOT NULL AND script_body <> '' ORDER BY name, id LIMIT 500");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $rows[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'category' => (string) $r['category'], 'description' => (string) $r['description'],
            'language' => rivetRmmScrLegacyLanguage((string) $r['script_type']), 'body' => (string) $r['script_body'], 'imported_as' => 0];
    }
    if ($rows === []) {
        return [];
    }
    $byName = [];
    $l = rivetRmmScrApi($ctx, 'GET', ['scripts'], ['limit' => '200', 'include_retired' => '1']);
    foreach ($l['ok'] ? (array) ($l['data']['data'] ?? []) : [] as $s) {
        $byName[(string) $s['name']][] = (int) $s['script_id'];
    }
    foreach ($rows as &$r) {
        $hash = rivetRmmScrBodyHash($r['body']);
        foreach ($byName[$r['name']] ?? [] as $sid) {
            $d = rivetRmmScrDetailCached($ctx, $sid);
            if ($d['ok'] && (string) ($d['data']['script']['current']['body_sha256'] ?? '') === $hash) {
                $r['imported_as'] = $sid;
                break;
            }
        }
    }
    unset($r);

    return $rows;
}

/**
 * One-time import of hand-made legacy scripts into the library, through the technician API (so Core signs every version, applies its size and parameter rules,
 * and audits). Idempotent: a script whose name and text are already in the library is skipped. Tags come from the old category; the risk class tag is the one
 * picked (default changes_system); `requires_approval` and `destructive` follow the starter pack's defaults for that class (a disruptive script needs approval).
 *
 * @param list<int> $ids legacy row ids to import (empty = every one not yet imported)
 * @return array{created:int,skipped:int,failed:list<string>}
 */
function rivetRmmScrImportLegacy(array $ctx, \mysqli $mysqli, array $ids, string $risk): array
{
    $risk = in_array($risk, ['read_only', 'changes_system', 'disruptive'], true) ? $risk : 'changes_system';
    $out = ['created' => 0, 'skipped' => 0, 'failed' => []];
    foreach (rivetRmmScrLegacyManual($ctx, $mysqli) as $row) {
        if ($ids !== [] && !in_array($row['id'], $ids, true)) {
            continue;
        }
        if ($row['imported_as'] > 0) {
            $out['skipped']++;
            continue;
        }
        if ($row['language'] === null) {
            $out['failed'][] = $row['name'] . ': the library has no CMD language.';
            continue;
        }
        $cat = rivetRmmScrCategoryTag($row['category']);
        $body = ['name' => mb_substr($row['name'], 0, 90), 'description' => mb_substr($row['description'], 0, 300), 'language' => $row['language'], 'body' => str_replace("\r\n", "\n", $row['body']),
            'note' => RIVET_RMM_SCR_IMPORT_NOTE . ' (#' . $row['id'] . ')', 'tags' => array_values(array_filter([$cat, 'imported', 'risk:' . $risk])),
            'requires_approval' => $risk === 'disruptive', 'destructive' => $risk === 'disruptive', 'params' => []];
        $r = rivetRmmScrApi($ctx, 'POST', ['scripts'], [], $body);
        if ($r['ok']) {
            $out['created']++;
            rivetRmmScrDetailForget((int) $ctx['uid']);
        } else {
            $out['failed'][] = $row['name'] . ': ' . ($r['error'] !== '' ? $r['error'] : 'the library refused it.');
        }
    }

    return $out;
}

/**
 * The script editor's post as the typed body Core takes (`$update` false adds the language). Used by the handler; the same values come back as a draft.
 *
 * @return array<string,mixed>
 */
function rivetRmmScrScriptBodyFromPost(bool $new): array
{
    $tags = array_values(array_filter(array_map('trim', explode(',', rivetRmmAutoStr('tags', 400))), static fn (string $t): bool => $t !== ''));
    $b = ['name' => rivetRmmAutoStr('name', 200), 'description' => rivetRmmAutoStr('description', 600), 'body' => str_replace("\r\n", "\n", is_string($_POST['body'] ?? null) ? $_POST['body'] : ''),
        'note' => rivetRmmAutoStr('note', 300), 'tags' => $tags, 'requires_approval' => !empty($_POST['requires_approval']), 'destructive' => !empty($_POST['destructive']), 'params' => rivetRmmScrReadParamDefs()];
    $t = rivetRmmAutoStr('timeout_s', 12);
    if ($t !== '') {
        $b['timeout_s'] = preg_match('/^\d{1,9}$/', $t) === 1 ? (int) $t : $t;
    }
    if ($new) {
        $b['language'] = rivetRmmAutoStr('language', 12);
    }

    return $b;
}
