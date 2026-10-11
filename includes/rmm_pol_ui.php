<?php

/*
 * RMM Phase 2: policies and custom fields (RivetCore 1.0.0-rc.10).
 *
 *   agent/rmm_policies.php   list, editor, versions, assignments, "explain a device"
 *   agent/rmm_fields.php     custom field definitions and their client / site values
 *   asset page tab           "Policy and fields" (rivetRmmPolPanelSection(), called by rivetRmmUiTabs())
 *   agent/post/rmm_automation_pol.php   the one handler behind all of those forms
 *
 * Nothing here decides anything: every read and every write is a call into RivetCore's technician API as the signed-in user (rivetRmmAutoApi), so
 * the 403/404 rules, the client scoping and the audit entries are Core's. The only direct reads are the department / site names of the pickers
 * (rivetRmmAutoScopeData, filtered by the user's visible clients) and the user names of "changed by".
 *
 * Layout of this file: (1) small helpers and the plain-language vocabulary, (2) the policy form <-> Core JSON conversion, (3) policy renderers,
 * (4) policy page view-models, (5) the effective-policy renderer shared by the policy page and the asset tab, (6) custom fields, (7) the asset tab hook.
 */

require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\RmmProtocol;

// ------------------------------------------------------------------ (1) helpers and vocabulary

/** A technician API call as the page's user. @return array{status:int,ok:bool,data:array<string,mixed>,error:string,code:string} */
function rivetRmmPolCall(array $ctx, string $method, array $segments, array $query = [], ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], (int) $ctx['uid'], (string) $ctx['name'], $method, $segments, $query, $body);
}

/**
 * The check type catalog (GET check_types), keyed by type. Cached for the request. Empty when the user may not read it.
 *
 * @return array<string,array<string,mixed>>
 */
function rivetRmmPolCatalog(array $ctx): array
{
    static $cache = [];
    $k = (int) $ctx['uid'];
    if (!isset($cache[$k])) {
        $cache[$k] = [];
        $r = rivetRmmPolCall($ctx, 'GET', ['check_types']);
        foreach ((array) ($r['data']['data'] ?? []) as $t) {
            if (is_array($t) && isset($t['type'])) {
                $t['fields'] = is_array($t['fields'] ?? null) ? $t['fields'] : [];
                $cache[$k][(string) $t['type']] = $t;
            }
        }
    }

    return $cache[$k];
}

/** @return array<int,string> user_id => name */
function rivetRmmPolUserNames(\mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn (int $i): bool => $i > 0));
    $out = [];
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['user_id']] = (string) $row['user_name'];
    }

    return $out;
}

const RIVET_RMM_POL_OPS = ['gt' => 'is above', 'gte' => 'is at or above', 'lt' => 'is below', 'lte' => 'is at or below'];
const RIVET_RMM_POL_OP_SYMBOLS = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
const RIVET_RMM_POL_FEATURES = ['software_inventory' => 'Software inventory', 'script_checks' => 'Script checks', 'checks' => 'Checks'];
const RIVET_RMM_POL_LEGACY_TYPES = ['service', 'disk', 'pending_reboot', 'script'];

/** The plain-language name of a policy setting key. */
function rivetRmmPolKeyLabel(string $key): string
{
    return match (true) {
        $key === 'interval.check_in_s' => 'Check-in interval',
        $key === 'interval.collect_s' => 'Collection interval',
        $key === 'agent.ring' => 'Update ring',
        str_starts_with($key, 'feature.') => (RIVET_RMM_POL_FEATURES[substr($key, 8)] ?? substr($key, 8)) . ' (feature)',
        str_starts_with($key, 'check.') => 'Check ' . substr($key, 6),
        default => $key,
    };
}

/** "warn: above 80" style text of a thresholds object. */
function rivetRmmPolThresholdText(array $t): string
{
    $parts = [];
    foreach (['warn' => 'warn', 'crit' => 'critical'] as $tier => $word) {
        if (isset($t[$tier]['op'], $t[$tier]['value'])) {
            $parts[] = $word . ' when the value ' . (RIVET_RMM_POL_OPS[$t[$tier]['op']] ?? $t[$tier]['op']) . ' ' . $t[$tier]['value'];
        }
    }
    foreach (['hysteresis' => 'hysteresis', 'for_samples' => 'samples in a row', 'for_minutes' => 'minutes'] as $f => $w) {
        if (isset($t[$f]) && (int) $t[$f] > (int) ($f === 'for_samples' ? 1 : 0)) {
            $parts[] = $t[$f] . ' ' . $w;
        }
    }

    return implode('; ', $parts);
}

/** One setting entry ({mode, value}) as a short sentence (plain text, escape it when printing). */
function rivetRmmPolValueText(string $key, array $entry): string
{
    $mode = (string) ($entry['mode'] ?? 'override');
    $v = $entry['value'] ?? null;
    $kind = str_starts_with($key, 'check.') ? 'check' : (str_starts_with($key, 'feature.') ? 'feature' : (str_starts_with($key, 'interval.') ? 'interval' : 'ring'));
    if ($mode === 'inherit') {
        return 'Not set';
    }
    if ($mode === 'disable') {
        return match ($kind) {
            'feature' => 'Off',
            'check' => 'Remove this check',
            default => 'Turned off (the global value applies)',
        };
    }

    return match ($kind) {
        'feature' => $v === true ? 'On' : 'Off',
        'interval' => (is_scalar($v) ? (string) $v : '?') . ' seconds',
        'ring' => is_string($v) ? $v : '?',
        default => rivetRmmPolCheckText(is_array($v) ? $v : []),
    };
}

/** A check template as one line: "cpu every 60 s; warn when the value is above 80". */
function rivetRmmPolCheckText(array $c): string
{
    $params = is_array($c['params'] ?? null) ? $c['params'] : [];
    $s = (string) ($c['type'] ?? '?') . ' every ' . (int) ($c['interval_s'] ?? 0) . ' s';
    if (is_array($params['thresholds'] ?? null)) {
        $t = rivetRmmPolThresholdText($params['thresholds']);
        $s .= $t !== '' ? '; ' . $t : '';
    }
    if (isset($params['flap'])) {
        $s .= '; flap detection on';
    }
    if (!empty($params['ignore_parent'])) {
        $s .= '; ignores the parent device';
    }

    return $s;
}

/**
 * A readable settings table (never raw JSON).
 *
 * @param array<string,mixed> $settings key => {mode, value}
 */
function rivetRmmPolSettingsTable(array $settings, string $caption): string
{
    if ($settings === []) {
        return '<p class="text-muted small mb-0">This policy sets nothing.</p>';
    }
    $o = '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">' . rmmH($caption) . '</caption>'
        . '<thead class="rmm-thead"><tr><th scope="col">Setting</th><th scope="col">What it does</th></tr></thead><tbody>';
    foreach ($settings as $key => $entry) {
        $o .= '<tr><th scope="row" class="fw-normal">' . rmmH(rivetRmmPolKeyLabel((string) $key)) . '</th><td>' . rmmH(rivetRmmPolValueText((string) $key, is_array($entry) ? $entry : [])) . '</td></tr>';
    }

    return $o . '</tbody></table></div>';
}

/** Which keys differ between two settings maps (for "changed since the previous version"). @return list<string> */
function rivetRmmPolChangedKeys(array $before, array $after): array
{
    $out = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
        if (json_encode($before[$k] ?? null) !== json_encode($after[$k] ?? null)) {
            $out[] = (string) $k;
        }
    }
    sort($out);

    return $out;
}

// ------------------------------------------------------------------ (2) the form <-> Core JSON conversion

/** Whole number from a posted string: int, or null when empty, or false when not a whole number. */
function rivetRmmPolIntOf($raw)
{
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    return preg_match('/^-?\d{1,12}$/', trim($raw)) === 1 ? (int) trim($raw) : false;
}

/**
 * Turn the policy form's fields into Core's `settings` map. Returns [settings, errors]. When errors is not empty the caller must not send it: a value that is not
 * a whole number is kept as its text so the form can show it again.
 *
 * @param array<string,mixed> $in the posted fields
 * @param array<string,array<string,mixed>> $catalog the check type catalog
 * @return array{0:array<string,array{mode:string,value:mixed}>,1:list<string>}
 */
function rivetRmmPolBuildSettings(array $in, array $catalog): array
{
    $settings = [];
    $errors = [];
    // intervals
    foreach (['ci' => ['interval.check_in_s', 'Check-in interval', 60, 3600], 'co' => ['interval.collect_s', 'Collection interval', 30, 3600]] as $p => [$key, $label, $min, $max]) {
        $mode = (string) ($in[$p . '_mode'] ?? '');
        if ($mode === 'disable') {
            $settings[$key] = ['mode' => 'disable', 'value' => null];
        } elseif ($mode === 'override') {
            $n = rivetRmmPolIntOf($in[$p . '_value'] ?? null);
            if ($n === null || $n === false) {
                $errors[] = "$label must be a whole number of seconds from $min to $max.";
                $settings[$key] = ['mode' => 'override', 'value' => (string) ($in[$p . '_value'] ?? '')];
            } elseif ($n < $min || $n > $max) {
                $errors[] = "$label must be from $min to $max seconds (you gave $n).";
                $settings[$key] = ['mode' => 'override', 'value' => $n];
            } else {
                $settings[$key] = ['mode' => 'override', 'value' => $n];
            }
        }
    }
    // features: on = override true, off = disable
    foreach (array_keys(RIVET_RMM_POL_FEATURES) as $f) {
        $v = (string) ($in['ft_' . $f] ?? '');
        if ($v === 'on') {
            $settings['feature.' . $f] = ['mode' => 'override', 'value' => true];
        } elseif ($v === 'off') {
            $settings['feature.' . $f] = ['mode' => 'disable', 'value' => null];
        }
    }
    $ring = (string) ($in['ring'] ?? '');
    if ($ring === 'stable' || $ring === 'pilot') {
        $settings['agent.ring'] = ['mode' => 'override', 'value' => $ring];
    }
    // check templates
    $rows = is_array($in['chk'] ?? null) ? $in['chk'] : [];
    ksort($rows);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row['key'] ?? ''));
        $type = (string) ($row['type'] ?? '');
        if ($key === '' && $type === '' && trim((string) ($row['interval_s'] ?? '')) === '') {
            continue;   // an untouched blank row
        }
        if (preg_match(RmmProtocol::CHECK_KEY_RE, $key) !== 1) {
            $errors[] = 'A check key is 1 to 100 letters, digits or _ . : - (' . ($key === '' ? 'one is empty' : '"' . mb_substr($key, 0, 40) . '"') . ').';
            continue;
        }
        if (isset($settings['check.' . $key])) {
            $errors[] = "Two checks use the key $key.";
            continue;
        }
        if (($row['mode'] ?? 'override') === 'disable') {
            $settings['check.' . $key] = ['mode' => 'disable', 'value' => null];
            continue;
        }
        if (!isset($catalog[$type]) && $catalog !== []) {
            $errors[] = "Check $key: choose a check type.";
            continue;
        }
        $iv = rivetRmmPolIntOf($row['interval_s'] ?? null);
        $params = [];
        $spec = $catalog[$type] ?? ['fields' => [], 'legacy' => in_array($type, RIVET_RMM_POL_LEGACY_TYPES, true), 'reports_value' => false];
        if (!empty($spec['legacy'])) {
            $json = trim((string) ($row['params_json'] ?? ''));
            if ($json !== '') {
                $dec = json_decode($json, true);
                if (!is_array($dec) || ($dec !== [] && array_is_list($dec))) {
                    $errors[] = "Check $key: the extra settings must be a JSON object such as {\"name\": \"Spooler\"}.";
                } else {
                    $params = $dec;
                }
            }
        } else {
            $own = is_array($row['p'][$type] ?? null) ? $row['p'][$type] : [];
            foreach ((array) ($spec['fields'] ?? []) as $name => $f) {
                $raw = $own[$name] ?? null;
                $kind = (string) ($f['kind'] ?? 'str');
                if ($kind === 'int') {
                    $n = rivetRmmPolIntOf($raw);
                    if ($n === false) {
                        $errors[] = "Check $key: $name must be a whole number from " . (int) $f['min'] . ' to ' . (int) $f['max'] . '.';
                        $params[$name] = (string) $raw;
                    } elseif ($n !== null) {
                        $params[$name] = $n;
                    }
                } elseif ($kind === 'bool') {
                    if ($raw === 'true' || $raw === 'false') {
                        $params[$name] = $raw === 'true';
                    }
                } elseif ($kind === 'list') {
                    $items = array_values(array_filter(array_map('trim', preg_split('/\R/', is_string($raw) ? $raw : '') ?: []), static fn (string $s): bool => $s !== ''));
                    if ($items !== []) {
                        $params[$name] = $items;
                    }
                } elseif (is_string($raw) && trim($raw) !== '') {
                    $params[$name] = trim($raw);
                }
            }
        }
        // alert behaviour (thresholds only for a check that reports a value)
        if (!empty($spec['reports_value'])) {
            $t = [];
            foreach (['warn', 'crit'] as $tier) {
                $n = rivetRmmPolIntOf($row['th'][$tier . '_val'] ?? null);
                if ($n === false) {
                    $errors[] = "Check $key: the $tier limit must be a whole number.";
                } elseif ($n !== null) {
                    $op = (string) ($row['th'][$tier . '_op'] ?? 'gt');
                    $t[$tier] = ['op' => isset(RIVET_RMM_POL_OPS[$op]) ? $op : 'gt', 'value' => $n];
                }
            }
            if ($t !== []) {
                foreach (['hysteresis' => 'Hysteresis', 'for_samples' => 'Samples in a row', 'for_minutes' => 'Minutes'] as $f => $label) {
                    $n = rivetRmmPolIntOf($row['th'][$f] ?? null);
                    if ($n === false) {
                        $errors[] = "Check $key: $label must be a whole number.";
                    } elseif ($n !== null) {
                        $t[$f] = $n;
                    }
                }
                $params['thresholds'] = $t;
            }
        }
        if (!empty($row['flap_on'])) {
            $flap = [];
            foreach (['window' => 'Flap window', 'high_pct' => 'Flap high', 'low_pct' => 'Flap low'] as $f => $label) {
                $n = rivetRmmPolIntOf($row['flap'][$f] ?? null);
                if ($n === false) {
                    $errors[] = "Check $key: $label must be a whole number.";
                } elseif ($n !== null) {
                    $flap[$f] = $n;
                }
            }
            $params['flap'] = $flap;
        }
        if (!empty($row['ignore_parent'])) {
            $params['ignore_parent'] = true;
        }
        if ($iv === null || $iv === false) {
            $errors[] = "Check $key: the interval must be a whole number of seconds from 30 to 86400.";
            $iv = (string) ($row['interval_s'] ?? '');
        }
        $settings['check.' . $key] = ['mode' => 'override', 'value' => ['type' => $type, 'params' => $params, 'interval_s' => $iv]];
    }

    return [$settings, $errors];
}

// ------------------------------------------------------------------ (3) policy form renderers

/**
 * Settings map (Core's shape) -> the values the form fields show.
 *
 * @param array<string,mixed> $settings
 * @return array<string,mixed>
 */
function rivetRmmPolFormModel(array $settings): array
{
    $m = ['ci_mode' => '', 'ci_value' => '', 'co_mode' => '', 'co_value' => '', 'ring' => '', 'ft' => [], 'checks' => []];
    foreach (['interval.check_in_s' => 'ci', 'interval.collect_s' => 'co'] as $key => $p) {
        $e = $settings[$key] ?? null;
        if (is_array($e) && ($e['mode'] ?? '') === 'override') {
            $m[$p . '_mode'] = 'override';
            $m[$p . '_value'] = (string) ($e['value'] ?? '');
        } elseif (is_array($e) && ($e['mode'] ?? '') === 'disable') {
            $m[$p . '_mode'] = 'disable';
        }
    }
    foreach (array_keys(RIVET_RMM_POL_FEATURES) as $f) {
        $e = $settings['feature.' . $f] ?? null;
        if (is_array($e) && ($e['mode'] ?? '') !== 'inherit') {
            $m['ft'][$f] = ($e['mode'] ?? '') === 'override' && ($e['value'] ?? null) === true ? 'on' : 'off';
        }
    }
    $r = $settings['agent.ring'] ?? null;
    $m['ring'] = is_array($r) && ($r['mode'] ?? '') === 'override' && is_string($r['value'] ?? null) ? $r['value'] : '';
    foreach ($settings as $key => $e) {
        if (!str_starts_with((string) $key, 'check.') || !is_array($e) || ($e['mode'] ?? '') === 'inherit') {
            continue;
        }
        $v = is_array($e['value'] ?? null) ? $e['value'] : [];
        $params = is_array($v['params'] ?? null) ? $v['params'] : [];
        $th = is_array($params['thresholds'] ?? null) ? $params['thresholds'] : [];
        $flap = is_array($params['flap'] ?? null) ? $params['flap'] : null;
        $own = $params;
        unset($own['thresholds'], $own['flap'], $own['ignore_parent']);
        $m['checks'][] = ['key' => substr((string) $key, 6), 'mode' => ($e['mode'] ?? '') === 'disable' ? 'disable' : 'override', 'type' => (string) ($v['type'] ?? ''),
            'interval_s' => (string) ($v['interval_s'] ?? ''), 'own' => $own, 'th' => $th, 'flap' => $flap, 'ignore_parent' => !empty($params['ignore_parent'])];
    }

    return $m;
}

/** One input for a check type's own param, from the catalog's schema. */
function rivetRmmPolParamInput(string $idBase, string $nameBase, string $name, array $f, $current): string
{
    $id = $idBase . '_' . $name;
    $nm = $nameBase . '[' . $name . ']';
    $kind = (string) ($f['kind'] ?? 'str');
    $req = !empty($f['required']);
    $label = '<label class="form-label small mb-1" for="' . rmmH($id) . '">' . rmmH(str_replace('_', ' ', $name)) . ($req ? ' <span class="text-danger" title="required">*</span>' : '') . '</label>';
    switch ($kind) {
        case 'int':
            $hint = 'from ' . (int) $f['min'] . ' to ' . (int) $f['max'] . ($req ? '' : ', default ' . (int) $f['default']);
            $in = '<input type="number" step="1" class="form-control form-control-sm" id="' . rmmH($id) . '" name="' . rmmH($nm) . '" min="' . (int) $f['min'] . '" max="' . (int) $f['max']
                . '" value="' . rmmH(is_scalar($current) ? (string) $current : '') . '" placeholder="' . (!$req ? (int) $f['default'] : '') . '">';
            break;
        case 'enum':
            $hint = 'default ' . (string) ($f['default'] ?? '');
            $in = '<select class="form-select form-select-sm" id="' . rmmH($id) . '" name="' . rmmH($nm) . '"><option value="">Default (' . rmmH((string) ($f['default'] ?? '')) . ')</option>';
            foreach ((array) ($f['values'] ?? []) as $v) {
                $in .= '<option value="' . rmmH((string) $v) . '"' . ((string) $current === (string) $v ? ' selected' : '') . '>' . rmmH((string) $v) . '</option>';
            }
            $in .= '</select>';
            break;
        case 'bool':
            $hint = 'default ' . (!empty($f['default']) ? 'yes' : 'no');
            $cur = $current === true ? 'true' : ($current === false ? 'false' : '');
            $in = '<select class="form-select form-select-sm" id="' . rmmH($id) . '" name="' . rmmH($nm) . '"><option value="">Default (' . (!empty($f['default']) ? 'yes' : 'no') . ')</option>'
                . '<option value="true"' . ($cur === 'true' ? ' selected' : '') . '>Yes</option><option value="false"' . ($cur === 'false' ? ' selected' : '') . '>No</option></select>';
            break;
        case 'list':
            $hint = 'one value per line, at most ' . (int) ($f['max_items'] ?? 0);
            $in = '<textarea class="form-control form-control-sm" rows="2" id="' . rmmH($id) . '" name="' . rmmH($nm) . '">' . rmmH(is_array($current) ? implode("\n", array_map('strval', $current)) : (string) $current) . '</textarea>';
            break;
        default:
            $hint = 'up to ' . (int) ($f['max_length'] ?? 0) . ' characters';
            $in = '<input type="text" class="form-control form-control-sm" id="' . rmmH($id) . '" name="' . rmmH($nm) . '" maxlength="' . (int) ($f['max_length'] ?? 255) . '" value="' . rmmH(is_scalar($current) ? (string) $current : '') . '">';
    }

    return '<div class="col-sm-6 col-lg-4">' . $label . $in . '<div class="form-text mt-0">' . rmmH($hint) . '</div></div>';
}

/**
 * One check template row (an existing one, or the blank template with $i = '__i__').
 *
 * @param array<string,array<string,mixed>> $catalog
 * @param array<string,mixed> $row from rivetRmmPolFormModel()['checks'][n] (empty for a blank)
 */
function rivetRmmPolCheckRow(string $i, array $row, array $catalog): string
{
    $id = 'pc' . $i;
    $nm = 'chk[' . $i . ']';
    $type = (string) ($row['type'] ?? '');
    $valueTypes = [];
    foreach ($catalog as $t => $spec) {
        if (!empty($spec['reports_value'])) {
            $valueTypes[] = $t;
        }
    }
    $o = '<div class="rmm-row" data-rmm-index="' . ($i === '__i__' ? '' : rmmH($i)) . '"><div class="row g-2 align-items-end">'
        . '<div class="col-sm-6 col-lg-3"><label class="form-label small mb-1" for="' . $id . '_key">Check key</label><input type="text" class="form-control form-control-sm rmm-mono" id="' . $id . '_key" name="' . $nm . '[key]" maxlength="100" value="' . rmmH($row['key'] ?? '') . '" placeholder="cpu_load"></div>'
        . '<div class="col-sm-6 col-lg-2"><label class="form-label small mb-1" for="' . $id . '_mode">This check</label><select class="form-select form-select-sm" id="' . $id . '_mode" name="' . $nm . '[mode]">'
        . '<option value="override"' . (($row['mode'] ?? 'override') === 'override' ? ' selected' : '') . '>Is set by this policy</option><option value="disable"' . (($row['mode'] ?? '') === 'disable' ? ' selected' : '') . '>Remove this check</option></select></div>'
        . '<div class="col-sm-6 col-lg-3"><label class="form-label small mb-1" for="' . $id . '_type">Check type</label><select class="form-select form-select-sm" id="' . $id . '_type" name="' . $nm . '[type]"><option value="">Choose a type</option>';
    foreach ($catalog as $t => $spec) {
        $plat = implode(' and ', array_map('ucfirst', (array) ($spec['platforms'] ?? [])));
        $o .= '<option value="' . rmmH($t) . '"' . ($t === $type ? ' selected' : '') . '>' . rmmH($t . ($plat !== '' ? ' (' . $plat . ')' : '') . (!empty($spec['legacy']) ? ', own settings' : '')) . '</option>';
    }
    $o .= '</select></div>'
        . '<div class="col-sm-4 col-lg-2"><label class="form-label small mb-1" for="' . $id . '_iv">Run every (seconds)</label><input type="number" step="1" min="30" max="86400" class="form-control form-control-sm" id="' . $id . '_iv" name="' . $nm . '[interval_s]" value="' . rmmH($row['interval_s'] ?? '') . '" placeholder="60"></div>'
        . '<div class="col-sm-2 col-lg-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger rmm-row-remove" data-rmm-remove="1" aria-label="Remove this check row" title="Remove this row"><i class="fas fa-times" aria-hidden="true"></i></button></div></div>'
        . '<p class="form-text mb-1">Choosing "Remove this check" takes the key out of the device\'s check list (also one from the global list); the type and settings are then ignored.</p>';
    // the type's own params
    $legacy = [];
    foreach ($catalog as $t => $spec) {
        if (!empty($spec['legacy'])) {
            $legacy[] = $t;
        }
    }
    if ($legacy !== []) {
        $json = '';
        if (in_array($type, $legacy, true) && is_array($row['own'] ?? null) && $row['own'] !== []) {
            $json = (string) json_encode($row['own'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $o .= '<div class="mt-2" data-rmm-when="#' . $id . '_type=' . rmmH(implode(',', $legacy)) . '"><label class="form-label small mb-1" for="' . $id . '_pj">Extra settings of this type (JSON object)</label>'
            . '<textarea class="form-control form-control-sm rmm-mono" rows="2" id="' . $id . '_pj" name="' . $nm . '[params_json]" placeholder="{&quot;name&quot;: &quot;Spooler&quot;}">' . rmmH($json) . '</textarea>'
            . '<div class="form-text">These older check types take free-form settings; RivetCore checks them when you save and shows what it refuses.</div></div>';
    }
    foreach ($catalog as $t => $spec) {
        if (!empty($spec['legacy'])) {
            continue;
        }
        $o .= '<div class="mt-2" data-rmm-when="#' . $id . '_type=' . rmmH($t) . '"><div class="small text-muted mb-1">Runs on '
            . rmmH(implode(' and ', array_map('ucfirst', (array) ($spec['platforms'] ?? [])))) . '.' . (!empty($spec['reports_value']) ? ' Reports a value in ' . rmmH((string) $spec['unit']) . '.' : ' Does not report a value, so it has no thresholds.')
            . '</div><div class="row g-2">';
        $cur = $type === $t && is_array($row['own'] ?? null) ? $row['own'] : [];
        foreach ((array) $spec['fields'] as $name => $f) {
            $o .= rivetRmmPolParamInput($id . '_' . $t, $nm . '[p][' . $t . ']', (string) $name, is_array($f) ? $f : [], $cur[$name] ?? null);
        }
        $o .= '</div></div>';
    }
    // alert behaviour
    $th = is_array($row['th'] ?? null) ? $row['th'] : [];
    $flap = is_array($row['flap'] ?? null) ? $row['flap'] : null;
    $open = $th !== [] || $flap !== null || !empty($row['ignore_parent']);
    $o .= '<details class="mt-2"' . ($open ? ' open' : '') . '><summary class="small">Alert behaviour (thresholds, flapping, parent device)</summary><div class="pt-2">';
    if ($valueTypes !== []) {
        $o .= '<div data-rmm-when="#' . $id . '_type=' . rmmH(implode(',', $valueTypes)) . '"><div class="row g-2">';
        foreach (['warn' => 'Warning', 'crit' => 'Critical'] as $tier => $word) {
            $o .= '<div class="col-sm-6 col-lg-3"><label class="form-label small mb-1" for="' . $id . '_' . $tier . 'op">' . $word . ' when the value</label><select class="form-select form-select-sm" id="' . $id . '_' . $tier . 'op" name="' . $nm . '[th][' . $tier . '_op]">';
            foreach (RIVET_RMM_POL_OPS as $op => $words) {
                $o .= '<option value="' . $op . '"' . ((string) ($th[$tier]['op'] ?? 'gt') === $op ? ' selected' : '') . '>' . $words . '</option>';
            }
            $o .= '</select></div><div class="col-sm-6 col-lg-3"><label class="form-label small mb-1" for="' . $id . '_' . $tier . 'v">' . $word . ' limit (whole number)</label><input type="number" step="1" class="form-control form-control-sm" id="' . $id . '_' . $tier . 'v" name="' . $nm . '[th][' . $tier . '_val]" value="' . rmmH($th[$tier]['value'] ?? '') . '"></div>';
        }
        foreach (['hysteresis' => ['Hysteresis (0 to 1000)', 0], 'for_samples' => ['Samples in a row (1 to 100)', 1], 'for_minutes' => ['Minutes it must last (0 to 1440)', 0]] as $f => [$label, $def]) {
            $o .= '<div class="col-sm-4 col-lg-4"><label class="form-label small mb-1" for="' . $id . '_' . $f . '">' . $label . '</label><input type="number" step="1" class="form-control form-control-sm" id="' . $id . '_' . $f . '" name="' . $nm . '[th][' . $f . ']" value="' . rmmH($th[$f] ?? '') . '" placeholder="' . $def . '"></div>';
        }
        $o .= '</div><div class="form-text mb-2">Leave both limits empty for no thresholds. The critical limit must be beyond the warning limit.</div></div>';
    }
    $o .= '<div class="row g-2 align-items-end"><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '_flapon" name="' . $nm . '[flap_on]" value="1"' . ($flap !== null ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '_flapon">Detect flapping (a check that keeps switching between good and bad)</label></div></div>';
    foreach (['window' => ['Window of samples (6 to 50)', 20], 'high_pct' => ['Flapping above (percent)', 50], 'low_pct' => ['Stable below (percent)', 25]] as $f => [$label, $def]) {
        $o .= '<div class="col-sm-4"><label class="form-label small mb-1" for="' . $id . '_fl' . $f . '">' . $label . '</label><input type="number" step="1" class="form-control form-control-sm" id="' . $id . '_fl' . $f . '" name="' . $nm . '[flap][' . $f . ']" value="' . rmmH($flap[$f] ?? '') . '" placeholder="' . $def . '"></div>';
    }
    $o .= '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '_ip" name="' . $nm . '[ignore_parent]" value="1"' . (!empty($row['ignore_parent']) ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '_ip">Alert even when the device\'s parent device is down</label></div></div></div>';

    return $o . '</div></details></div>';
}

/**
 * The policy editor form (administrators).
 *
 * @param array<string,mixed> $vm rivetRmmPolPolicyView()
 */
function rivetRmmPolEditor(array $vm, string $csrf): string
{
    $p = $vm['policy'];   // null for a new one
    $d = $vm['draft'];
    $name = $d['name'] ?? ($p['name'] ?? '');
    $desc = $d['description'] ?? ($p['description'] ?? '');
    $enabled = array_key_exists('enabled', $d ?? []) ? (bool) $d['enabled'] : ($p === null ? true : (bool) $p['enabled']);
    $settings = is_array($d['settings'] ?? null) ? $d['settings'] : (is_array($p['settings'] ?? null) ? $p['settings'] : []);
    $m = rivetRmmPolFormModel($settings);
    $catalog = $vm['catalog'];
    $ret = $p === null ? '/agent/rmm_policies.php?new=1' : '/agent/rmm_policies.php?policy_id=' . (int) $p['policy_id'];
    $o = '<form method="post" action="/agent/post/rmm_automation_pol.php" id="pol-form" data-rmm-once="1" novalidate>' . rivetRmmAutoHidden($csrf, $ret)
        . '<input type="hidden" name="action" value="save_policy"><input type="hidden" name="policy_id" value="' . (int) ($p['policy_id'] ?? 0) . '">'
        . '<div class="row g-3 mb-3"><div class="col-md-5"><label class="form-label" for="pol_name">Name</label><input type="text" class="form-control" id="pol_name" name="name" maxlength="100" required value="' . rmmH($name) . '"></div>'
        . '<div class="col-md-7"><label class="form-label" for="pol_desc">Description</label><input type="text" class="form-control" id="pol_desc" name="description" maxlength="300" value="' . rmmH($desc) . '"></div>'
        . '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="pol_enabled" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '><label class="form-check-label" for="pol_enabled">Policy is on (a policy that is off keeps its assignments but reaches no device)</label></div></div></div>';

    // intervals
    $o .= '<fieldset class="mb-3"><legend class="h6">How often the agent talks to the server</legend><div class="row g-3">';
    foreach (['ci' => ['Check-in interval', 60, 3600, 'Seconds between check-ins. Kept between 60 and 3600; the global setting is used where this is not set.'],
              'co' => ['Collection interval', 30, 3600, 'Seconds between readings. Kept between 30 and 3600; the global setting is used where this is not set.']] as $p2 => [$label, $min, $max, $help]) {
        $o .= '<div class="col-md-6"><label class="form-label" for="pol_' . $p2 . '_mode">' . $label . '</label><div class="row g-2"><div class="col-6"><select class="form-select" id="pol_' . $p2 . '_mode" name="' . $p2 . '_mode">'
            . '<option value=""' . ($m[$p2 . '_mode'] === '' ? ' selected' : '') . '>Not set</option><option value="override"' . ($m[$p2 . '_mode'] === 'override' ? ' selected' : '') . '>Set</option>'
            . '<option value="disable"' . ($m[$p2 . '_mode'] === 'disable' ? ' selected' : '') . '>Turn off (use the less specific or global value)</option></select></div>'
            . '<div class="col-6" data-rmm-when="#pol_' . $p2 . '_mode=override"><label class="visually-hidden" for="pol_' . $p2 . '_value">' . $label . ' in seconds</label><div class="input-group"><input type="number" step="1" min="' . $min . '" max="' . $max . '" class="form-control" id="pol_' . $p2 . '_value" name="' . $p2 . '_value" value="' . rmmH($m[$p2 . '_value']) . '"><span class="input-group-text">seconds</span></div></div></div>'
            . '<div class="form-text">' . rmmH($help) . '</div></div>';
    }
    $o .= '</div></fieldset>';

    // features and ring
    $o .= '<fieldset class="mb-3"><legend class="h6">Features and updates</legend><p class="form-text">A policy can only narrow what the instance allows: "On" does not switch on a feature the instance has turned off in Administration.</p><div class="row g-3">';
    foreach (RIVET_RMM_POL_FEATURES as $f => $label) {
        $cur = $m['ft'][$f] ?? '';
        $o .= '<div class="col-md-4"><label class="form-label" for="pol_ft_' . $f . '">' . $label . '</label><select class="form-select" id="pol_ft_' . $f . '" name="ft_' . $f . '"><option value=""' . ($cur === '' ? ' selected' : '') . '>Not set</option>'
            . '<option value="on"' . ($cur === 'on' ? ' selected' : '') . '>On</option><option value="off"' . ($cur === 'off' ? ' selected' : '') . '>Off</option></select></div>';
    }
    $o .= '<div class="col-md-4"><label class="form-label" for="pol_ring">Update ring</label><select class="form-select" id="pol_ring" name="ring"><option value=""' . ($m['ring'] === '' ? ' selected' : '') . '>Not set</option>'
        . '<option value="stable"' . ($m['ring'] === 'stable' ? ' selected' : '') . '>Stable</option><option value="pilot"' . ($m['ring'] === 'pilot' ? ' selected' : '') . '>Pilot (gets new releases first)</option></select></div></div></fieldset>';

    // check templates
    $o .= '<fieldset class="mb-3"><legend class="h6">Checks</legend><p class="form-text">A check the policy sets replaces a check with the same key in the global list, or adds a new one. Whole numbers only.</p>'
        . '<div id="pol-checks" data-rmm-repeat="1" data-rmm-max="50"><div data-rmm-rows="1">';
    foreach ($m['checks'] as $n => $row) {
        $o .= rivetRmmPolCheckRow((string) $n, $row, $catalog);
    }
    $o .= '</div><template data-rmm-row="1">' . rivetRmmPolCheckRow('__i__', [], $catalog) . '</template>'
        . '<button type="button" class="btn btn-sm btn-outline-primary" data-rmm-add="#pol-checks"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a check</button></div></fieldset>';
    $o .= '<div class="rmm-sticky-form"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>' . ($p === null ? 'Create policy' : 'Save policy') . '</button> '
        . '<a class="btn btn-outline-secondary" href="/agent/rmm_policies.php">Cancel</a>'
        . ($p !== null ? '<span class="text-muted small ms-2">Saving a change to the settings makes version ' . ((int) $p['version'] + 1) . '; the old version is kept.</span>' : '') . '</div></form>';

    return $o;
}

// ------------------------------------------------------------------ (4) view-models (read only through rivetRmmAutoApi)

/**
 * Names for a handful of scopes, in the shape rivetRmmAutoScopeLabel() reads: only the ids asked for are looked up (the asset tab must not build the whole picker).
 *
 * @param list<array{0:string,1:int}> $pairs [scope_type, scope_id]
 * @return array{clients:array<int,string>,sites:array<int,string>,groups:array<int,string>,tags:array<int,string>,devices:array<int,string>,policies:array<int,string>}
 */
function rivetRmmPolSparseScopeData(\mysqli $mysqli, array $pairs): array
{
    $ids = ['client' => [], 'site' => [], 'group' => [], 'tag' => [], 'device' => []];
    foreach ($pairs as [$t, $id]) {
        if (isset($ids[$t]) && (int) $id > 0) {
            $ids[$t][(int) $id] = true;
        }
    }
    $out = ['clients' => [], 'sites' => [], 'groups' => [], 'tags' => [], 'devices' => [], 'policies' => []];
    $in = static fn (string $t): string => implode(',', array_keys($ids[$t]) ?: [0]);
    $run = static function (string $sql, callable $each) use ($mysqli): void {
        $r = mysqli_query($mysqli, $sql);
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $each($row);
        }
    };
    if ($ids['client']) {
        $run('SELECT client_id, client_name FROM clients WHERE client_id IN (' . $in('client') . ')', function ($r) use (&$out) { $out['clients'][(int) $r['client_id']] = (string) $r['client_name']; });
    }
    if ($ids['site']) {
        $run('SELECT l.location_id, l.location_name, c.client_name FROM locations l JOIN clients c ON c.client_id = l.location_client_id WHERE l.location_id IN (' . $in('site') . ')',
            function ($r) use (&$out) { $out['sites'][(int) $r['location_id']] = (string) $r['client_name'] . ' / ' . (string) $r['location_name']; });
    }
    if ($ids['group']) {
        $run('SELECT group_id, name FROM rmm_groups WHERE group_id IN (' . $in('group') . ')', function ($r) use (&$out) { $out['groups'][(int) $r['group_id']] = (string) $r['name']; });
    }
    if ($ids['tag']) {
        $run('SELECT tag_id, name FROM rmm_tags WHERE tag_id IN (' . $in('tag') . ')', function ($r) use (&$out) { $out['tags'][(int) $r['tag_id']] = (string) $r['name']; });
    }
    if ($ids['device']) {
        $run('SELECT device_id, hostname FROM endpoint_agent_devices WHERE device_id IN (' . $in('device') . ')', function ($r) use (&$out) { $out['devices'][(int) $r['device_id']] = (string) $r['hostname']; });
    }

    return $out;
}

/** The draft a failed save left in the session (once), when it is for this policy. @return array<string,mixed>|null */
function rivetRmmPolTakeDraft(int $policyId): ?array
{
    $d = $_SESSION['rmm_pol_draft'] ?? null;
    unset($_SESSION['rmm_pol_draft']);

    return is_array($d) && (int) ($d['for'] ?? -1) === $policyId ? $d : null;
}

/**
 * The policies page: a list, or one policy (?policy_id=N), or the new-policy form (?new=1).
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmPolPoliciesView(array $ctx, array $get): array
{
    $mysqli = $ctx['mysqli'];
    $vm = ['mode' => 'list', 'perm' => $ctx['perm'], 'error' => '', 'policies' => [], 'assignments' => [], 'labels' => [], 'users' => [], 'catalog' => [], 'draft' => null,
        'policy' => null, 'versions' => [], 'scope_data' => null, 'explain' => null, 'explain_device' => 0, 'capped' => false];
    $policyId = isset($get['policy_id']) ? (int) $get['policy_id'] : 0;
    if ($policyId <= 0 && empty($get['new'])) {
        $r = rivetRmmPolCall($ctx, 'GET', ['policies']);
        if (!$r['ok']) {
            $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The policies could not be read.';

            return $vm;
        }
        $vm['policies'] = array_values((array) ($r['data']['data'] ?? []));
        $pairs = [];
        foreach (array_slice($vm['policies'], 0, 200) as $p) {
            $d = rivetRmmPolCall($ctx, 'GET', ['policies', (int) $p['policy_id']]);
            $vm['assignments'][(int) $p['policy_id']] = (array) ($d['data']['assignments'] ?? []);
            foreach ($vm['assignments'][(int) $p['policy_id']] as $a) {
                $pairs[] = [(string) $a['scope_type'], (int) $a['scope_id']];
            }
        }
        $vm['capped'] = count($vm['policies']) > 200;
        $vm['labels'] = rivetRmmPolSparseScopeData($mysqli, $pairs);
        $vm['users'] = rivetRmmPolUserNames($mysqli, array_column($vm['policies'], 'updated_by'));

        return $vm;
    }
    if ($policyId <= 0) {   // new
        $vm['mode'] = 'new';
        if (!$ctx['perm']['admin']) {
            $vm['error'] = 'Only administrators can create policies.';

            return $vm;
        }
        $vm['catalog'] = rivetRmmPolCatalog($ctx);
        $vm['draft'] = rivetRmmPolTakeDraft(0);

        return $vm;
    }
    $vm['mode'] = 'detail';
    $r = rivetRmmPolCall($ctx, 'GET', ['policies', $policyId]);
    if (!$r['ok'] || !is_array($r['data']['policy'] ?? null)) {
        $vm['mode'] = 'missing';
        $vm['error'] = 'That policy does not exist.';

        return $vm;
    }
    $vm['policy'] = $r['data']['policy'];
    $vm['assignments'][$policyId] = (array) ($r['data']['assignments'] ?? []);
    $v = rivetRmmPolCall($ctx, 'GET', ['policies', $policyId, 'versions'], ['body' => '1']);
    $vm['versions'] = array_values((array) ($v['data']['data'] ?? []));
    $vm['catalog'] = $ctx['perm']['admin'] ? rivetRmmPolCatalog($ctx) : [];
    $vm['draft'] = rivetRmmPolTakeDraft($policyId);
    $vm['scope_data'] = rivetRmmAutoScopeData($mysqli, (int) $ctx['uid']);   // the picker of "Assign to", the labels and the "Explain a device" select
    $vm['users'] = rivetRmmPolUserNames($mysqli, array_merge([(int) $vm['policy']['updated_by']], array_column($vm['versions'], 'changed_by')));
    $dev = isset($get['explain_device']) ? (int) $get['explain_device'] : 0;
    if ($dev > 0) {
        $vm['explain_device'] = $dev;
        $vm['explain'] = rivetRmmPolEffectiveView($ctx, $dev);
    }

    return $vm;
}

/**
 * A device's effective policy with everything the renderer needs (GET endpoint_devices/{id}/policy plus the policies behind its layers).
 *
 * @return array<string,mixed> ['ok'=>bool,'error'=>string,'eff'=>array,'layer_settings'=>[assignment_id=>settings],'assignments'=>[assignment_id=>row],'policy_names'=>[id=>name],'labels'=>sparse scope data]
 */
function rivetRmmPolEffectiveView(array $ctx, int $deviceId): array
{
    $out = ['ok' => false, 'error' => '', 'eff' => [], 'layer_settings' => [], 'assignments' => [], 'policy_names' => [], 'labels' => []];
    $r = rivetRmmPolCall($ctx, 'GET', [$deviceId, 'policy']);
    if (!$r['ok']) {
        $out['error'] = $r['status'] === 404 ? 'That device does not exist, or you cannot see it.' : ($r['error'] !== '' ? $r['error'] : 'The effective policy could not be read.');

        return $out;
    }
    $out['ok'] = true;
    $out['eff'] = $r['data'];
    $details = [];
    foreach ((array) ($r['data']['layers'] ?? []) as $l) {
        $pid = (int) $l['policy_id'];
        $out['policy_names'][$pid] = (string) ($l['policy'] ?? '');
        if (!isset($details[$pid])) {
            $d = rivetRmmPolCall($ctx, 'GET', ['policies', $pid]);
            $details[$pid] = $d['ok'] ? $d['data'] : [];
        }
    }
    $pairs = [];
    foreach ((array) ($r['data']['layers'] ?? []) as $l) {
        $pid = (int) $l['policy_id'];
        $aid = (int) $l['assignment_id'];
        $settings = (array) ($details[$pid]['policy']['settings'] ?? []);
        foreach ((array) ($details[$pid]['assignments'] ?? []) as $a) {
            if ((int) $a['assignment_id'] === $aid) {
                $out['assignments'][$aid] = $a;
                $pairs[] = [(string) $a['scope_type'], (int) $a['scope_id']];
                foreach ((array) $a['overrides'] as $k => $e) {
                    if (is_array($e) && ($e['mode'] ?? '') === 'inherit') {
                        unset($settings[$k]);
                    } else {
                        $settings[$k] = $e;
                    }
                }
            }
        }
        $out['layer_settings'][$aid] = $settings;
    }
    $out['labels'] = rivetRmmPolSparseScopeData($ctx['mysqli'], $pairs);

    return $out;
}

// ------------------------------------------------------------------ (5) policy page renderers

const RIVET_RMM_POL_RANK = ['global' => 0, 'client' => 1, 'site' => 2, 'group' => 3, 'tag' => 4, 'device' => 5];

/** The text of a scope word for a layer whose id may be hidden from this user. */
function rivetRmmPolScopeText(string $type, ?array $assignment, array $labels): string
{
    if ($assignment === null) {
        return match ($type) { 'global' => 'Everything (global)', 'client' => 'A department', default => 'A ' . $type };
    }

    return rivetRmmAutoScopeLabel($type, (int) $assignment['scope_id'], $labels);
}

/** Why one layer beat another, in words. */
function rivetRmmPolWhyBeat(array $win, array $lose): string
{
    if (!empty($win['enforce']) && empty($lose['enforce'])) {
        return 'enforced, so a more specific scope cannot change it';
    }
    if (!empty($win['enforce']) && !empty($lose['enforce'])) {
        return 'both are enforced and the outermost scope has the last word';
    }
    $rw = RIVET_RMM_POL_RANK[$win['scope_type']] ?? 0;
    $rl = RIVET_RMM_POL_RANK[$lose['scope_type']] ?? 0;
    if ($rw !== $rl) {
        return 'a more specific scope wins';
    }
    if ((int) $win['priority'] !== (int) $lose['priority']) {
        return 'a higher priority wins inside the same scope';
    }

    return 'the newer assignment wins inside the same scope';
}

/**
 * The effective policy of one device as a table of settings in force (value, policy, scope, why), the policies that reach it and the checks it really receives.
 *
 * @param array<string,mixed> $e rivetRmmPolEffectiveView()
 */
function rivetRmmPolEffectiveHtml(array $e): string
{
    if (!$e['ok']) {
        return '<div class="alert alert-warning mb-0" role="status">' . rmmH($e['error']) . '</div>';
    }
    $eff = $e['eff'];
    $labels = $e['labels'];
    $layers = [];
    foreach ((array) ($eff['layers'] ?? []) as $l) {
        $layers[(int) $l['assignment_id']] = $l;
    }
    $link = static fn (int $pid): string => '<a href="/agent/rmm_policies.php?policy_id=' . $pid . '">' . rmmH($e['policy_names'][$pid] ?? ('Policy #' . $pid)) . '</a>';
    $feat = '';
    foreach (RIVET_RMM_POL_FEATURES as $f => $label) {
        $on = !empty($eff['features'][$f]);
        $feat .= rivetRmmUiPill($on ? 'ok' : 'off', $label . ($on ? ': on' : ': off')) . ' ';
    }
    $o = rivetRmmUiDl([
        ['Policies reaching this device', $layers === [] ? '<span class="text-muted">none</span>' : (string) count($layers)],
        ['Check-in interval', rmmH((string) ($eff['check_in_interval_s'] ?? '?')) . ' seconds'],
        ['Collection interval', rmmH((string) ($eff['collect_interval_s'] ?? '?')) . ' seconds'],
        ['Features', $feat],
        ['Update ring', $eff['ring'] !== null && $eff['ring'] !== '' ? rmmH((string) $eff['ring']) : '<span class="text-muted">the instance default</span>'],
        ['Configuration version', '<span class="rmm-mono">' . rmmH(substr((string) ($eff['version'] ?? ''), 0, 12)) . '</span>'],
    ]);
    if ($layers === []) {
        return $o . '<div class="mt-3">' . rivetRmmUiEmpty('fas fa-sliders-h', 'No policy reaches this device', 'No policy reaches this device; it follows the global configuration.') . '</div>' . rivetRmmPolChecksTable($eff, $e, false);
    }
    // settings in force
    $sources = (array) ($eff['sources'] ?? []);
    $checksByKey = [];
    foreach ((array) ($eff['checks'] ?? []) as $c) {
        $checksByKey[(string) $c['key']] = $c;
    }
    $o .= '<h6 class="mt-3 mb-2">Settings in force</h6><div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Settings that a policy sets for this device, where each came from and why</caption>'
        . rivetRmmUiThead(['Setting', 'Value', 'Policy', 'Scope', 'Why']) . '<tbody>';
    foreach ($sources as $key => $src) {
        $key = (string) $key;
        $aid = (int) ($src['assignment_id'] ?? 0);
        $win = $layers[$aid] ?? ['scope_type' => (string) ($src['scope_type'] ?? ''), 'priority' => 0, 'enforce' => !empty($src['enforced']), 'policy_id' => (int) ($src['policy_id'] ?? 0)];
        $val = match (true) {
            $key === 'interval.check_in_s' => ($src['mode'] === 'override' ? $eff['check_in_interval_s'] . ' seconds' : 'Turned off: the global value applies (' . $eff['check_in_interval_s'] . ' seconds)'),
            $key === 'interval.collect_s' => ($src['mode'] === 'override' ? $eff['collect_interval_s'] . ' seconds' : 'Turned off: the global value applies (' . $eff['collect_interval_s'] . ' seconds)'),
            str_starts_with($key, 'feature.') => !empty($eff['features'][substr($key, 8)]) ? 'On' : 'Off',
            $key === 'agent.ring' => $src['mode'] === 'override' ? (string) $eff['ring'] : 'Turned off: the instance default applies',
            default => $src['mode'] === 'disable' ? 'Check removed' : (isset($checksByKey[substr($key, 6)]) ? rivetRmmPolCheckText($checksByKey[substr($key, 6)]) : 'Set, but not delivered to this device (a feature is off or the agent cannot run this check type)'),
        };
        $why = !empty($win['enforce']) ? '<i class="fas fa-lock me-1" aria-hidden="true"></i>Enforced: nothing more specific can change it.' : '';
        $others = [];
        foreach ($layers as $oaid => $ol) {
            $ent = $e['layer_settings'][$oaid][$key] ?? null;
            if ($oaid !== $aid && is_array($ent) && in_array($ent['mode'] ?? '', ['override', 'disable'], true)) {
                $others[] = '<li>Also set by ' . $link((int) $ol['policy_id']) . ' (' . rmmH(rivetRmmPolScopeText((string) $ol['scope_type'], $e['assignments'][$oaid] ?? null, $labels)) . '): not used, ' . rmmH(rivetRmmPolWhyBeat($win, $ol)) . '.</li>';
            }
        }
        if ($why === '') {
            $why = $others === [] ? 'Only one policy sets it.' : 'The most specific scope that sets it (priority ' . (int) $win['priority'] . ').';
        }
        $o .= '<tr><th scope="row" class="fw-normal">' . rmmH(rivetRmmPolKeyLabel($key)) . '</th><td>' . rmmH($val) . '</td><td>' . $link((int) ($src['policy_id'] ?? 0)) . '</td><td>'
            . rmmH(rivetRmmPolScopeText((string) ($src['scope_type'] ?? ''), $e['assignments'][$aid] ?? null, $labels)) . '</td><td>' . $why
            . ($others !== [] ? '<ul class="small text-muted mb-0 ps-3">' . implode('', $others) . '</ul>' : '') . '</td></tr>';
    }
    $o .= '</tbody></table></div>';
    // policies that reach the device
    $o .= '<h6 class="mt-3 mb-2">Policies that reach this device</h6><div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Policies that reach this device</caption>'
        . rivetRmmUiThead(['Policy', 'Through', 'Priority', 'Enforced']) . '<tbody>';
    foreach ($layers as $aid => $l) {
        $o .= '<tr><td>' . $link((int) $l['policy_id']) . '</td><td>' . rmmH(rivetRmmPolScopeText((string) $l['scope_type'], $e['assignments'][$aid] ?? null, $labels)) . '</td><td>' . (int) $l['priority'] . '</td><td>'
            . (!empty($l['enforce']) ? rivetRmmUiPill('warn', 'Enforced') : '<span class="text-muted">no</span>') . '</td></tr>';
    }

    return $o . '</tbody></table></div>' . rivetRmmPolChecksTable($eff, $e, true);
}

/** The checks the device really receives and where each came from. */
function rivetRmmPolChecksTable(array $eff, array $e, bool $policyOn): string
{
    $checks = (array) ($eff['checks'] ?? []);
    $o = '<h6 class="mt-3 mb-2">Checks this device receives</h6>';
    if ($checks === []) {
        return $o . '<p class="text-muted small mb-0">None. No check is delivered to this device.</p>';
    }
    $o .= '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Checks the device receives</caption>' . rivetRmmUiThead(['Key', 'Type', 'Every', 'Comes from']) . '<tbody>';
    foreach ($checks as $c) {
        $from = is_array($c['from'] ?? null) ? $c['from'] : null;
        $o .= '<tr><td class="rmm-mono">' . rmmH($c['key']) . '</td><td>' . rmmH($c['type']) . '</td><td>' . (int) $c['interval_s'] . ' s</td><td>'
            . ($from === null ? '<span class="text-muted">Global configuration</span>' : '<a href="/agent/rmm_policies.php?policy_id=' . (int) $from['policy_id'] . '">' . rmmH($e['policy_names'][(int) $from['policy_id']] ?? ('Policy #' . (int) $from['policy_id'])) . '</a>') . '</td></tr>';
    }

    return $o . '</tbody></table></div>';
}

/** The "Explain a device" box of the policy page. */
function rivetRmmPolExplainBox(array $vm): string
{
    $pid = (int) $vm['policy']['policy_id'];
    $o = '<form method="get" action="/agent/rmm_policies.php" class="row g-2 align-items-end mb-3"><input type="hidden" name="policy_id" value="' . $pid . '">'
        . '<div class="col-sm-8 col-md-6"><label class="form-label" for="pol_explain_dev">Device</label><select class="form-select" id="pol_explain_dev" name="explain_device"><option value="">Choose a device</option>';
    foreach ((array) ($vm['scope_data']['devices'] ?? []) as $id => $host) {
        $o .= '<option value="' . (int) $id . '"' . ((int) $id === (int) $vm['explain_device'] ? ' selected' : '') . '>' . rmmH($host) . '</option>';
    }
    $o .= '</select></div><div class="col-auto"><button type="submit" class="btn btn-outline-primary"><i class="fas fa-search me-1" aria-hidden="true"></i>Show its effective policy</button></div></form>'
        . '<p class="text-muted small">There is no list of every device a policy reaches; pick a device to see which policies reach it and what it ends up with.</p>';
    if ($vm['explain'] !== null) {
        $o .= '<div id="pol-explain" aria-live="polite">' . rivetRmmPolEffectiveHtml($vm['explain']) . '</div>';
    }

    return $o;
}

/** The assignments panel of one policy. */
function rivetRmmPolAssignments(array $vm, string $csrf): string
{
    $p = $vm['policy'];
    $pid = (int) $p['policy_id'];
    $admin = !empty($vm['perm']['admin']);
    $ret = '/agent/rmm_policies.php?policy_id=' . $pid;
    $labels = $vm['scope_data'];
    $o = '<p class="small text-muted">The more specific scope wins: global, then department, site, group, tag and device (the device is the most specific). A setting marked "enforce" wins over everything more specific; inside one scope a higher priority number wins.</p>';
    $rows = (array) ($vm['assignments'][$pid] ?? []);
    if ($rows === []) {
        $o .= '<p class="text-muted">This policy is not assigned to anything, so it reaches no device.</p>';
    } else {
        $o .= '<div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden">Where this policy is assigned</caption>' . rivetRmmUiThead(array_merge(['Applies to', 'Priority', 'Enforce', 'Changes'], $admin ? ['Actions'] : [])) . '<tbody>';
        foreach ($rows as $a) {
            $ov = is_array($a['overrides'] ?? null) ? count($a['overrides']) : 0;
            $o .= '<tr><td>' . rmmH(rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $labels)) . '</td><td>' . (int) $a['priority'] . '</td><td>'
                . (!empty($a['enforce']) ? rivetRmmUiPill('warn', 'Enforced') : '<span class="text-muted">no</span>') . '</td><td>' . ($ov > 0 ? $ov . ' setting' . ($ov === 1 ? '' : 's') . ' changed here' : '<span class="text-muted">none</span>') . '</td>'
                . ($admin ? '<td class="rmm-table-actions">' . rivetRmmAutoActionForm('rmm_automation_pol', 'unassign_policy', ['policy_id' => $pid, 'assignment_id' => (int) $a['assignment_id']], $csrf, $ret,
                    '<i class="fas fa-times me-1" aria-hidden="true"></i>Remove', 'btn btn-sm btn-outline-danger', 'Remove this assignment? Devices that only got settings through it fall back to the next policy or the global configuration.', 'Remove this assignment') . '</td>' : '') . '</tr>';
        }
        $o .= '</tbody></table></div>';
    }
    if ($admin) {
        $o .= '<form method="post" action="/agent/post/rmm_automation_pol.php" class="border-top pt-3" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $ret) . '<input type="hidden" name="action" value="assign_policy"><input type="hidden" name="policy_id" value="' . $pid . '">'
            . '<h6 class="mb-2">Assign to</h6><div class="row g-3"><div class="col-md-5">' . rivetRmmAutoScopePicker('as', ['global', 'client', 'site', 'group', 'tag', 'device'], 'client', 0, $labels, 'Assign this policy to') . '</div>'
            . '<div class="col-sm-6 col-md-3"><label class="form-label" for="as_priority">Priority (0 to 1000000)</label><input type="number" step="1" min="0" max="1000000" class="form-control" id="as_priority" name="priority" value="100"></div>'
            . '<div class="col-sm-6 col-md-4"><div class="form-check mt-md-4 pt-md-2"><input class="form-check-input" type="checkbox" id="as_enforce" name="enforce" value="1"><label class="form-check-label" for="as_enforce">Enforce (nothing more specific may change these settings)</label></div></div></div>'
            . '<div class="mt-3"><button type="submit" class="btn btn-primary"><i class="fas fa-link me-1" aria-hidden="true"></i>Assign</button></div></form>';
    }

    return $o;
}

/** The versions panel: every version with who changed it, what changed since the previous version and a disclosure with the settings of that version. */
function rivetRmmPolVersions(array $vm): string
{
    $versions = (array) $vm['versions'];
    if ($versions === []) {
        return '<p class="text-muted mb-0">No versions.</p>';
    }
    $o = '<p class="small text-muted">Changing the settings makes a new version; renaming a policy or switching it off does not. Old versions are kept for the record.</p><div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Versions of this policy</caption>'
        . rivetRmmUiThead(['Version', 'Changed by', 'When', 'Changed since the previous version', 'Settings']) . '<tbody>';
    $byNo = [];
    foreach ($versions as $v) {
        $byNo[(int) $v['version']] = (array) ($v['settings'] ?? []);
    }
    foreach ($versions as $n => $v) {
        $no = (int) $v['version'];
        $prev = $byNo[$no - 1] ?? null;
        $changed = $prev === null ? null : rivetRmmPolChangedKeys($prev, $byNo[$no]);
        $cap = $n >= 20;
        $o .= '<tr><th scope="row">' . $no . ((int) $vm['policy']['version'] === $no ? ' ' . rivetRmmUiPill('ok', 'Current') : '') . '</th><td>' . rmmH($vm['users'][(int) $v['changed_by']] ?? ('User #' . (int) $v['changed_by'])) . '</td><td>' . rivetRmmAutoUtc((string) $v['changed_at']) . '</td><td>'
            . ($prev === null ? 'First version' : ($changed === [] ? 'Nothing' : rmmH(implode(', ', array_map('rivetRmmPolKeyLabel', $changed))))) . '</td><td>'
            . ($cap ? '<span class="text-muted small">Not shown (only the 20 newest are expanded)</span>' : '<details><summary class="small">View settings of version ' . $no . '</summary><div class="pt-2">' . rivetRmmPolSettingsTable($byNo[$no], 'Settings of version ' . $no) . '</div></details>') . '</td></tr>';
    }

    return $o . '</tbody></table></div>';
}

/** The whole policies page (list, new, one policy). */
function rivetRmmPolPoliciesPage(array $vm, array $ctx): string
{
    $csrf = (string) $ctx['csrf'];
    $admin = !empty($vm['perm']['admin']);
    $o = '';
    if ($vm['mode'] === 'list') {
        $o .= rivetRmmAutoHeader('Policies', 'sliders-h', $admin ? '<a class="btn btn-primary" href="/agent/rmm_policies.php?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New policy</a>' : '',
            'A policy tells the devices it reaches how often to check in, which features to use, which update ring to follow and which checks to run. Assign it to everything, a department, a site, a group, a tag or one device.');
        if ($vm['error'] !== '') {
            return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
        }
        if ($vm['policies'] === []) {
            return $o . rivetRmmUiCard('Policies', 'sliders-h', rivetRmmUiEmpty('fas fa-sliders-h', 'No policies yet', 'Devices follow the global configuration.', $admin ? '<a class="btn btn-primary" href="/agent/rmm_policies.php?new=1">New policy</a>' : ''), '', '', false, 'pol-list') . rivetRmmAutoConfirmModal();
        }
        $t = '<div class="table-responsive"><table class="table table-vcenter card-table mb-0"><caption class="visually-hidden">Policies</caption>'
            . rivetRmmUiThead(array_merge(['Name', 'Version', 'Status', 'Assigned', 'Reaches', 'Updated'], $admin ? ['Actions'] : [])) . '<tbody>';
        foreach ($vm['policies'] as $p) {
            $pid = (int) $p['policy_id'];
            $as = (array) ($vm['assignments'][$pid] ?? []);
            $names = array_map(static fn (array $a): string => rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $vm['labels']), $as);
            $reach = $names === [] ? '<span class="text-muted">nothing</span>' : rmmH(implode(', ', array_slice($names, 0, 3))) . (count($names) > 3 ? ' <span class="text-muted">and ' . (count($names) - 3) . ' more</span>' : '');
            $t .= '<tr><td class="ps-3"><a href="/agent/rmm_policies.php?policy_id=' . $pid . '"><strong>' . rmmH($p['name']) . '</strong></a>' . ($p['description'] !== '' ? '<div class="small text-muted">' . rmmH($p['description']) . '</div>' : '') . '</td>'
                . '<td>' . (int) $p['version'] . '</td><td>' . ($p['enabled'] ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off')) . '</td><td>' . count($as) . '</td><td>' . $reach . '</td>'
                . '<td>' . rivetRmmAutoUtc((string) $p['updated_at']) . '<div class="small text-muted">' . rmmH($vm['users'][(int) $p['updated_by']] ?? '') . '</div></td>';
            if ($admin) {
                $t .= '<td class="rmm-table-actions"><a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_policies.php?policy_id=' . $pid . '"><i class="fas fa-edit me-1" aria-hidden="true"></i>Edit</a> '
                    . rivetRmmAutoActionForm('rmm_automation_pol', 'toggle_policy', ['policy_id' => $pid, 'enabled' => $p['enabled'] ? 0 : 1], $csrf, '/agent/rmm_policies.php',
                        $p['enabled'] ? '<i class="fas fa-power-off me-1" aria-hidden="true"></i>Turn off' : '<i class="fas fa-power-off me-1" aria-hidden="true"></i>Turn on') . ' '
                    . rivetRmmAutoActionForm('rmm_automation_pol', 'delete_policy', ['policy_id' => $pid], $csrf, '/agent/rmm_policies.php', '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete', 'btn btn-sm btn-outline-danger',
                        'Delete the policy "' . $p['name'] . '" with its versions and assignments? Devices fall back to their next policy or the global configuration at their next check-in.') . '</td>';
            }
            $t .= '</tr>';
        }

        return $o . rivetRmmUiCard('Policies', 'sliders-h', $t . '</tbody></table></div>' . ($vm['capped'] ? '<p class="small text-muted p-3 mb-0">Assignments are shown for the first 200 policies.</p>' : ''), '', '', true, 'pol-list') . rivetRmmAutoConfirmModal();
    }
    if ($vm['mode'] === 'missing' || ($vm['mode'] === 'new' && $vm['error'] !== '')) {
        return rivetRmmAutoHeader('Policy', 'sliders-h', '<a class="btn btn-outline-secondary" href="/agent/rmm_policies.php">All policies</a>') . '<div class="alert alert-warning" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if ($vm['mode'] === 'new') {
        return rivetRmmAutoHeader('New policy', 'sliders-h', '<a class="btn btn-outline-secondary" href="/agent/rmm_policies.php">All policies</a>', 'Choose what the policy sets, then assign it on the next page. You only fill in what the policy should say; everything left as "Not set" is decided by a less specific policy or the global configuration.')
            . rivetRmmUiCard('Settings', 'sliders-h', rivetRmmPolEditor($vm, $csrf), '', '', false, 'pol-settings') . rivetRmmAutoConfirmModal();
    }
    $p = $vm['policy'];
    $pid = (int) $p['policy_id'];
    $acts = '<a class="btn btn-outline-secondary" href="/agent/rmm_policies.php">All policies</a>';
    if ($admin) {
        $acts .= ' ' . rivetRmmAutoActionForm('rmm_automation_pol', 'toggle_policy', ['policy_id' => $pid, 'enabled' => $p['enabled'] ? 0 : 1], $csrf, '/agent/rmm_policies.php?policy_id=' . $pid,
                '<i class="fas fa-power-off me-1" aria-hidden="true"></i>' . ($p['enabled'] ? 'Turn off' : 'Turn on'), 'btn btn-outline-secondary')
            . ' ' . rivetRmmAutoActionForm('rmm_automation_pol', 'delete_policy', ['policy_id' => $pid], $csrf, '/agent/rmm_policies.php', '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete', 'btn btn-outline-danger',
                'Delete the policy "' . $p['name'] . '" with its versions and assignments? Devices fall back to their next policy or the global configuration at their next check-in.');
    }
    $o .= rivetRmmAutoHeader((string) $p['name'], 'sliders-h', $acts, ($p['description'] !== '' ? rtrim((string) $p['description'], '. ') . '. ' : '') . 'Version ' . (int) $p['version'] . '. ' . ($p['enabled'] ? 'On.' : 'Off: it reaches no device until it is turned on.'));
    $settingsBody = $admin ? rivetRmmPolEditor($vm, $csrf) : rivetRmmPolSettingsTable((array) $p['settings'], 'Settings of this policy');
    $o .= rivetRmmUiCard('Settings', 'sliders-h', $settingsBody, '', $admin ? '' : '<span class="small text-muted">Read only: only administrators change policies</span>', false, 'pol-settings')
        . rivetRmmUiCard('Assignments', 'link', rivetRmmPolAssignments($vm, $csrf), '', '', false, 'pol-assignments')
        . rivetRmmUiCard('Versions', 'history', rivetRmmPolVersions($vm), '', '', false, 'pol-versions')
        . rivetRmmUiCard('Explain a device', 'search', rivetRmmPolExplainBox($vm), '', '', false, 'pol-explain-card');

    return $o . rivetRmmAutoConfirmModal();
}

// ------------------------------------------------------------------ (6) custom fields

const RIVET_RMM_POL_FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'bool' => 'Yes or no', 'date' => 'Date', 'list' => 'One of a list', 'secret' => 'Secret (write-only)'];
const RIVET_RMM_POL_FIELD_SCOPES = ['client' => 'Department', 'site' => 'Site', 'device' => 'Device'];
const RIVET_RMM_POL_FIELD_ROWS = 100;

/**
 * The custom fields page.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @return array<string,mixed>
 */
function rivetRmmPolFieldsView(array $ctx, array $get): array
{
    $mysqli = $ctx['mysqli'];
    $vm = ['perm' => $ctx['perm'], 'error' => '', 'fields' => [], 'scope_data' => ['clients' => [], 'sites' => []], 'values' => [], 'capped' => false];
    $r = rivetRmmPolCall($ctx, 'GET', ['fields']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The custom fields could not be read.';

        return $vm;
    }
    $vm['fields'] = array_values((array) ($r['data']['data'] ?? []));
    $scopes = array_unique(array_column($vm['fields'], 'scope'));
    if (array_intersect($scopes, ['client', 'site']) !== []) {
        $vm['scope_data'] = rivetRmmAutoScopeData($mysqli, (int) $ctx['uid']);
        $svc = rivetRmmModule($mysqli)->readModel()->automation()?->fieldService();
        if ($svc !== null) {
            // Read through the module's read model for the departments and sites this user may see (the lists come from rivetRmmAutoScopeData, which applies the visible-client filter).
            foreach (['client' => 'clients', 'site' => 'sites'] as $scope => $list) {
                if (!in_array($scope, $scopes, true)) {
                    continue;
                }
                $n = 0;
                foreach ($vm['scope_data'][$list] as $sid => $label) {
                    if ($n++ >= RIVET_RMM_POL_FIELD_ROWS) {
                        $vm['capped'] = true;
                        break;
                    }
                    foreach ($svc->valuesFor($scope, (int) $sid) as $v) {
                        if (!empty($v['set'])) {
                            $vm['values'][(int) $v['field_id']][] = ['scope_id' => (int) $sid, 'label' => $label, 'value' => $v['value'], 'type' => $v['type'], 'updated_at' => $v['updated_at']];
                        }
                    }
                }
            }
        }
    }

    return $vm;
}

/** One value input for a field's type. @param array<string,mixed> $f */
function rivetRmmPolValueInput(array $f, string $id, string $name, string $current = ''): string
{
    $type = (string) $f['type'];
    if ($type === 'bool' || $type === 'list') {
        $opts = $type === 'bool' ? ['true' => 'Yes', 'false' => 'No'] : array_combine((array) $f['options'], (array) $f['options']);
        $o = '<select class="form-select form-select-sm" id="' . rmmH($id) . '" name="' . rmmH($name) . '"><option value="">Choose</option>';
        foreach ($opts as $k => $label) {
            $o .= '<option value="' . rmmH((string) $k) . '"' . ($current === (string) $k ? ' selected' : '') . '>' . rmmH((string) $label) . '</option>';
        }

        return $o . '</select>';
    }
    $itype = $type === 'date' ? 'date' : ($type === 'secret' ? 'password' : 'text');

    return '<input type="' . $itype . '" class="form-control form-control-sm" id="' . rmmH($id) . '" name="' . rmmH($name) . '" maxlength="2000" value="' . rmmH($type === 'secret' ? '' : $current) . '"'
        . ($type === 'number' ? ' inputmode="decimal"' : '') . ($type === 'secret' ? ' autocomplete="new-password" placeholder="Write-only"' : '') . '>';
}

/** The page. */
function rivetRmmPolFieldsPage(array $vm, array $ctx): string
{
    $csrf = (string) $ctx['csrf'];
    $admin = !empty($vm['perm']['admin']);
    $manage = !empty($vm['perm']['manage']);
    $ret = '/agent/rmm_fields.php';
    $o = rivetRmmAutoHeader('Custom fields', 'list-alt', '', 'Fields hold facts you want scripts to use, per department, site or device. Put {{field.name}} in a script parameter and it is filled in with the value that applies to the device the script runs on. Secrets are write-only: they are never shown again.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if ($vm['fields'] === []) {
        $table = rivetRmmUiEmpty('fas fa-list-alt', 'No custom fields yet', $admin ? 'Define the first field below.' : 'An administrator can define fields.');
    } else {
        $table = '<div class="table-responsive"><table class="table table-vcenter card-table mb-0"><caption class="visually-hidden">Custom field definitions</caption>'
            . rivetRmmUiThead(['Name', 'Applies to', 'Type', 'Default', 'Options', 'Values set']) . '<tbody>';
        foreach ($vm['fields'] as $f) {
            $table .= '<tr><td class="ps-3"><strong class="rmm-mono">' . rmmH($f['name']) . '</strong>' . ($f['label'] !== '' ? '<div class="small">' . rmmH($f['label']) . '</div>' : '') . ($f['description'] !== '' ? '<div class="small text-muted">' . rmmH($f['description']) . '</div>' : '') . '</td>'
                . '<td>' . rmmH(RIVET_RMM_POL_FIELD_SCOPES[$f['scope']] ?? $f['scope']) . '</td><td>' . rmmH(RIVET_RMM_POL_FIELD_TYPES[$f['type']] ?? $f['type']) . '</td>'
                . '<td>' . ($f['default'] !== null && $f['default'] !== '' ? rmmH($f['default']) : '<span class="text-muted">none</span>') . '</td>'
                . '<td>' . ($f['options'] ? rmmH(implode(', ', (array) $f['options'])) : '<span class="text-muted">-</span>') . '</td><td>' . (int) $f['values'] . '</td></tr>';
        }
        $table .= '</tbody></table></div>';
    }
    $o .= rivetRmmUiCard('Definitions', 'list-alt', $table, '', '', true, 'fld-defs');
    // one panel per field: values, edit, delete
    foreach ($vm['fields'] as $f) {
        $fid = (int) $f['field_id'];
        $secret = $f['type'] === 'secret';
        $body = '';
        if ($f['scope'] === 'device') {
            $body .= '<p class="small text-muted">Values of a device field are set on the device\'s own page, in the "Policy and fields" tab.</p>';
        } else {
            $list = $f['scope'] === 'client' ? 'clients' : 'sites';
            $rows = (array) ($vm['values'][$fid] ?? []);
            if ($secret) {
                $body .= '<p class="small text-muted">A secret is never shown. The table says only whether a value is set.</p>';
            }
            if ($rows === []) {
                $body .= '<p class="text-muted small">No ' . ($f['scope'] === 'client' ? 'department' : 'site') . ' has a value yet' . ($f['default'] !== null && $f['default'] !== '' ? ' (the default applies)' : '') . '.</p>';
            } else {
                $body .= '<div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden">Values of ' . rmmH($f['name']) . '</caption>' . rivetRmmUiThead([$f['scope'] === 'client' ? 'Department' : 'Site', 'Value', 'Changed']) . '<tbody>';
                foreach ($rows as $rw) {
                    $body .= '<tr><td>' . rmmH($rw['label']) . '</td><td>' . ($secret ? rivetRmmUiPill('ok', 'Set') : rmmH($rw['value'])) . '</td><td>' . rivetRmmAutoUtc($rw['updated_at']) . '</td></tr>';
                }
                $body .= '</tbody></table></div>' . ($vm['capped'] ? '<p class="small text-muted">Only the first ' . RIVET_RMM_POL_FIELD_ROWS . ' ' . ($f['scope'] === 'client' ? 'departments' : 'sites') . ' were checked; use the form below for any other.</p>' : '');
            }
            if (($secret ? $admin : $manage) && $vm['scope_data'][$list] !== []) {
                $body .= '<form method="post" action="/agent/post/rmm_automation_pol.php" class="row g-2 align-items-end border-top pt-3" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $ret . '#fld-' . $fid)
                    . '<input type="hidden" name="action" value="set_field_value"><input type="hidden" name="field_id" value="' . $fid . '">'
                    . '<div class="col-md-4"><label class="form-label small" for="fv_s_' . $fid . '">' . ($f['scope'] === 'client' ? 'Department' : 'Site') . '</label><select class="form-select form-select-sm" id="fv_s_' . $fid . '" name="scope_id">';
                foreach ($vm['scope_data'][$list] as $sid => $label) {
                    $body .= '<option value="' . (int) $sid . '">' . rmmH($label) . '</option>';
                }
                $body .= '</select></div><div class="col-md-4"><label class="form-label small" for="fv_v_' . $fid . '">Value</label>' . rivetRmmPolValueInput($f, 'fv_v_' . $fid, 'value') . '</div>'
                    . '<div class="col-md-4"><button type="submit" class="btn btn-sm btn-primary">Save value</button> <button type="submit" name="clear" value="1" class="btn btn-sm btn-outline-secondary" data-rmm-confirm="Clear the value of this field for the chosen ' . ($f['scope'] === 'client' ? 'department' : 'site') . '?">Clear value</button></div></form>';
            } elseif ($secret && !$admin) {
                $body .= '<p class="small text-muted">Only administrators set secret values.</p>';
            }
        }
        if ($admin) {
            $body .= '<details class="border-top pt-3 mt-3"><summary>Edit this field</summary><form method="post" action="/agent/post/rmm_automation_pol.php" class="row g-3 pt-3" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $ret . '#fld-' . $fid)
                . '<input type="hidden" name="action" value="save_field"><input type="hidden" name="field_id" value="' . $fid . '">'
                . '<div class="col-12"><p class="form-text mb-0">The name, where it applies and its type are fixed once a field exists (values depend on them). To change them, define a new field.</p></div>'
                . '<div class="col-md-4"><label class="form-label" for="fe_l_' . $fid . '">Label</label><input type="text" class="form-control" id="fe_l_' . $fid . '" name="label" maxlength="100" value="' . rmmH($f['label']) . '"></div>'
                . '<div class="col-md-8"><label class="form-label" for="fe_d_' . $fid . '">Description</label><input type="text" class="form-control" id="fe_d_' . $fid . '" name="description" maxlength="300" value="' . rmmH($f['description']) . '"></div>'
                . ($f['type'] === 'list' ? '<div class="col-md-6"><label class="form-label" for="fe_o_' . $fid . '">Options (one per line)</label><textarea class="form-control" rows="3" id="fe_o_' . $fid . '" name="options">' . rmmH(implode("\n", (array) $f['options'])) . '</textarea></div>' : '')
                . (!$secret ? '<div class="col-md-6"><label class="form-label" for="fe_df_' . $fid . '">Default value</label><input type="text" class="form-control" id="fe_df_' . $fid . '" name="default" maxlength="2000" value="' . rmmH((string) ($f['default'] ?? '')) . '"><div class="form-text">Used where no value is set. Leave empty for none.</div></div>' : '')
                . '<div class="col-12"><button type="submit" class="btn btn-primary">Save changes</button></div></form>'
                . '<div class="pt-3">' . rivetRmmAutoActionForm('rmm_automation_pol', 'delete_field', ['field_id' => $fid], $csrf, $ret, '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete this field', 'btn btn-sm btn-outline-danger',
                    'Delete the field "' . $f['name'] . '"? Its ' . (int) $f['values'] . ' value' . ((int) $f['values'] === 1 ? '' : 's') . ' go with it, and scripts that use {{field.' . $f['name'] . '}} will no longer be filled in.') . '</div></details>';
        }
        $o .= rivetRmmUiCard('<span class="rmm-mono">' . rmmH($f['name']) . '</span> <span class="small text-muted fw-normal">' . rmmH(RIVET_RMM_POL_FIELD_SCOPES[$f['scope']] ?? $f['scope']) . ', ' . rmmH(RIVET_RMM_POL_FIELD_TYPES[$f['type']] ?? $f['type']) . '</span>', 'tag', $body, '', '', false, 'fld-' . $fid);
    }
    if ($admin) {
        $body = '<form method="post" action="/agent/post/rmm_automation_pol.php" class="row g-3" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $ret) . '<input type="hidden" name="action" value="save_field"><input type="hidden" name="field_id" value="0">'
            . '<div class="col-md-4"><label class="form-label" for="nf_name">Name</label><input type="text" class="form-control rmm-mono" id="nf_name" name="name" maxlength="40" required pattern="[a-z][a-z0-9_]{0,39}" placeholder="vpn_gateway"><div class="form-text">Lower case letters, digits and _, starting with a letter, at most 40 characters. Used as {{field.name}}.</div></div>'
            . '<div class="col-md-4"><label class="form-label" for="nf_label">Label</label><input type="text" class="form-control" id="nf_label" name="label" maxlength="100"></div>'
            . '<div class="col-md-4"><label class="form-label" for="nf_desc">Description</label><input type="text" class="form-control" id="nf_desc" name="description" maxlength="300"></div>'
            . '<div class="col-md-4"><label class="form-label" for="nf_scope">Applies to</label><select class="form-select" id="nf_scope" name="scope">';
        foreach (RIVET_RMM_POL_FIELD_SCOPES as $k => $label) {
            $body .= '<option value="' . $k . '">' . $label . '</option>';
        }
        $body .= '</select></div><div class="col-md-4"><label class="form-label" for="nf_type">Type</label><select class="form-select" id="nf_type" name="type">';
        foreach (RIVET_RMM_POL_FIELD_TYPES as $k => $label) {
            $body .= '<option value="' . $k . '">' . $label . '</option>';
        }
        $body .= '</select></div><div class="col-md-4" data-rmm-when="#nf_type=text,number,bool,date,list"><label class="form-label" for="nf_default">Default value</label><input type="text" class="form-control" id="nf_default" name="default" maxlength="2000"><div class="form-text">Optional. A yes or no field takes true or false, a date takes YYYY-MM-DD.</div></div>'
            . '<div class="col-md-6" data-rmm-when="#nf_type=list"><label class="form-label" for="nf_options">Options (one per line)</label><textarea class="form-control" rows="3" id="nf_options" name="options"></textarea></div>'
            . '<div class="col-12"><button type="submit" class="btn btn-primary"><i class="fas fa-plus me-1" aria-hidden="true"></i>Define field</button></div></form>';
        $o .= rivetRmmUiCard('New field', 'plus', $body, '', '', false, 'fld-new');
    }

    return $o . rivetRmmAutoConfirmModal();
}

// ------------------------------------------------------------------ (7) the asset page tab

/**
 * The "Policy and fields" tab of the asset page. '' when neither the policies nor the scripts switch is on (the tab is then not drawn).
 *
 * @param array<string,mixed> $vm the panel view-model
 */
function rivetRmmPolPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    $pol = !empty($vm['features']['policies']);
    $scr = !empty($vm['features']['scripts']);
    if (!$pol && !$scr) {
        return '';
    }
    $ctx = ['uid' => (int) $vm['user_id'], 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'mysqli' => $mysqli, 'csrf' => $csrf, 'perm' => (array) $vm['perm']];
    $dev = (int) $vm['device_id'];
    $o = '';
    if ($pol) {
        $e = rivetRmmPolEffectiveView($ctx, $dev);
        $o .= rivetRmmUiCard('Effective policy', 'sliders-h', rivetRmmPolEffectiveHtml($e), '', '<a class="small" href="/agent/rmm_policies.php">All policies</a>', false, 'rmm-policy-effective');
    }
    if ($scr) {
        $o .= rivetRmmPolDeviceFields($ctx, $vm);
    }

    return $o;
}

/** The "Custom fields" card of a device. */
function rivetRmmPolDeviceFields(array $ctx, array $vm): string
{
    $dev = (int) $vm['device_id'];
    $csrf = (string) $ctx['csrf'];
    $ret = '/agent/asset_details.php?asset_id=' . (int) $vm['asset_id'] . '#rmm-policy';
    $r = rivetRmmPolCall($ctx, 'GET', [$dev, 'fields']);
    if (!$r['ok']) {
        return rivetRmmUiCard('Custom fields', 'list-alt', '<div class="alert alert-warning mb-0" role="status">' . rmmH($r['error'] !== '' ? $r['error'] : 'The custom fields could not be read.') . '</div>', '', '', false, 'rmm-policy-fields');
    }
    $rows = (array) ($r['data']['data'] ?? []);
    if ($rows === []) {
        return rivetRmmUiCard('Custom fields', 'list-alt', rivetRmmUiEmpty('fas fa-list-alt', 'No custom fields apply', 'Fields are defined on the custom fields page.', '<a class="btn btn-sm btn-outline-primary" href="/agent/rmm_fields.php">Custom fields</a>'), '', '', false, 'rmm-policy-fields');
    }
    $defs = [];
    foreach ((array) (rivetRmmPolCall($ctx, 'GET', ['fields'])['data']['data'] ?? []) as $d) {
        $defs[(int) $d['field_id']] = $d;
    }
    $canManage = !empty($vm['perm']['manage']);
    $canAdmin = !empty($vm['perm']['admin']);
    $words = ['client' => 'Department', 'site' => 'Site', 'device' => 'This device'];
    $o = '<p class="small text-muted">Scripts can use a value as {{field.name}} in a parameter. Department and site values are edited on the <a href="/agent/rmm_fields.php">custom fields page</a>; the values of this device are edited here.</p>'
        . '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Custom fields that apply to this device</caption>' . rivetRmmUiThead(['Field', 'Comes from', 'Value', 'Default', 'Edit']) . '<tbody>';
    foreach ($rows as $f) {
        $fid = (int) $f['field_id'];
        $secret = $f['type'] === 'secret';
        $def = $defs[$fid] ?? ['type' => $f['type'], 'options' => []];
        $val = $secret ? ($f['set'] ? rivetRmmUiPill('ok', 'Set') : '<span class="text-muted">not set</span>') : ($f['set'] ? rmmH($f['value']) : '<span class="text-muted">not set</span>');
        $edit = '<span class="text-muted small">Edited on the <a href="/agent/rmm_fields.php#fld-' . $fid . '">fields page</a></span>';
        if ($f['scope'] === 'device') {
            if (($secret ? $canAdmin : $canManage) && !empty($vm['usable'])) {
                $edit = '<form method="post" action="/agent/post/rmm_automation_pol.php" class="d-flex flex-wrap gap-1" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $ret)
                    . '<input type="hidden" name="action" value="set_device_field"><input type="hidden" name="device_id" value="' . $dev . '"><input type="hidden" name="field_id" value="' . $fid . '">'
                    . '<label class="visually-hidden" for="rmm-df-' . $fid . '">Value of ' . rmmH($f['name']) . '</label><div style="min-width:9rem;flex:1 1 9rem">' . rivetRmmPolValueInput($def, 'rmm-df-' . $fid, 'value', $secret ? '' : (string) ($f['value'] ?? '')) . '</div>'
                    . '<button type="submit" class="btn btn-sm btn-primary">Save</button>'
                    . ($f['set'] ? '<button type="submit" name="clear" value="1" class="btn btn-sm btn-outline-secondary" data-rmm-confirm="Clear the value of ' . rmmH($f['name']) . ' for this device?">Clear</button>' : '') . '</form>';
            } else {
                $edit = '<span class="text-muted small">' . ($secret && !$canAdmin ? 'Only administrators set secrets' : 'Your role cannot change it') . '</span>';
            }
        }
        $o .= '<tr><th scope="row" class="fw-normal"><span class="rmm-mono">' . rmmH($f['name']) . '</span>' . ($f['label'] !== '' ? '<div class="small text-muted">' . rmmH($f['label']) . '</div>' : '') . '</th><td>' . rmmH($words[$f['scope']] ?? $f['scope']) . '</td><td>' . $val . '</td>'
            . '<td>' . ($f['default'] !== null && $f['default'] !== '' ? rmmH($f['default']) : '<span class="text-muted">none</span>') . '</td><td>' . $edit . '</td></tr>';
    }

    return rivetRmmUiCard('Custom fields', 'list-alt', $o . '</tbody></table></div>', '', '', false, 'rmm-policy-fields');
}
