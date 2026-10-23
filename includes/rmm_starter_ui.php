<?php

/*
 * RMM starter pack (RivetCore 1.0.0-rc.13): the "Starter pack" page (agent/rmm_starter_pack.php, administrators, behind the `scripts` sub-switch) and the small pieces the
 * script library shows for starter scripts (the "Starter" badge, the risk chip and the "update waiting for review" marker). Its handler is agent/post/rmm_starter.php.
 *
 * Everything goes through RivetCore's technician API as the signed-in user (rivetRmmAutoApi -> endpoint_devices/starter/{catalog,plan,install,uninstall}): `rmm.admin` is checked there,
 * and the pack's own safety rules (an edited script is never overwritten, a policy draft that is edited, on or assigned is left alone, uninstall removes only untouched
 * items, no signing key means nothing is installed) are Core's, not ours. This file only shows the catalog, collects a selection, shows what Core says it would do or did, and
 * never prints a script's text: the catalog does not carry it.
 *
 * View-model functions return arrays (testable); renderers return strings. Everything printed is escaped. No inline script or handler (js/rmm_starter.js does the ticking).
 */

require_once __DIR__ . '/rmm_scr_ui.php';

const RIVET_RMM_STARTER_PAGE = '/agent/rmm_starter_pack.php';
const RIVET_RMM_STARTER_HANDLER = 'rmm_starter';

// ------------------------------------------------------------------ tags, badge, chips (also used by the script library)

/**
 * The pack's own tags on a script: `starter` marks it, `risk:<class>` is the risk chip; the rest are ordinary tags.
 *
 * @param array<int,mixed> $tags
 * @return array{starter:bool,risk:?string,rest:list<string>}
 */
function rivetRmmStarterTagInfo(array $tags): array
{
    $starter = false;
    $risk = null;
    $rest = [];
    foreach ($tags as $t) {
        $t = (string) $t;
        if ($t === 'starter') {
            $starter = true;
        } elseif (str_starts_with($t, 'risk:') && in_array(substr($t, 5), ['read_only', 'changes_system', 'disruptive'], true)) {
            $risk = substr($t, 5);
        } else {
            $rest[] = $t;
        }
    }

    return ['starter' => $starter, 'risk' => $risk, 'rest' => $rest];
}

/** The risk class as a chip: a word AND an icon, never colour alone. */
function rivetRmmStarterRiskPill(string $risk): string
{
    $map = ['read_only' => ['ok', 'Read only'], 'changes_system' => ['warn', 'Changes the system'], 'disruptive' => ['crit', 'Disruptive']];
    [$kind, $text] = $map[$risk] ?? ['off', ucfirst(str_replace('_', ' ', $risk))];

    return rivetRmmUiPill($kind, $text, 'rmm-risk-chip');
}

/** "Starter" badge plus the risk chip of a script's tags, or '' for a script of the administrator's own. */
function rivetRmmStarterBadge(array $tags): string
{
    $i = rivetRmmStarterTagInfo($tags);
    if (!$i['starter']) {
        return '';
    }

    return '<span class="badge text-bg-primary rmm-starter-badge"><i class="fas fa-box-open me-1" aria-hidden="true"></i>Starter</span>' . ($i['risk'] !== null ? ' ' . rivetRmmStarterRiskPill($i['risk']) : '') . ' ';
}

/** A version note written by the pack ("starter|pack|item|version|hash" or "starter-updated|...") in plain words, or null for any other note. */
function rivetRmmStarterNoteWords(string $note): ?string
{
    $p = explode('|', $note);
    if (count($p) < 5 || !in_array($p[0], ['starter', 'starter-updated'], true)) {
        return null;
    }
    $what = $p[0] === 'starter' ? 'Installed from the starter pack' : 'Starter pack update, waiting for review';

    return $what . ' (' . mb_substr($p[1], 0, 60) . ' ' . mb_substr($p[3], 0, 20) . ')';
}

function rivetRmmStarterIsStagedNote(string $note): bool
{
    return str_starts_with($note, 'starter-updated|');
}

/**
 * Which of these library scripts have a starter update waiting for review? Administrators only (they are the ones who can act on it), and only asks Core when the
 * page holds a starter script.
 *
 * @param list<array<string,mixed>> $items library list rows
 * @return array<int,true> script id => true
 */
function rivetRmmStarterWaiting(array $ctx, array $items): array
{
    if (empty($ctx['perm']['admin'])) {
        return [];
    }
    $any = false;
    foreach ($items as $s) {
        if (rivetRmmStarterTagInfo((array) ($s['tags'] ?? []))['starter']) {
            $any = true;
            break;
        }
    }
    if (!$any) {
        return [];
    }
    $r = rivetRmmScrApi($ctx, 'GET', ['starter', 'catalog']);
    $out = [];
    if ($r['ok']) {
        foreach ((array) ($r['data']['packs'] ?? []) as $pack) {
            foreach ((array) ($pack['scripts'] ?? []) as $sc) {
                if (($sc['state'] ?? '') === 'update_staged' && (int) ($sc['script_id'] ?? 0) > 0) {
                    $out[(int) $sc['script_id']] = true;
                }
            }
        }
    }

    return $out;
}

// ------------------------------------------------------------------ the page: view-model

/** State of an item in this instance, in words an administrator reads. */
function rivetRmmStarterStatePill(string $state): string
{
    $map = [
        'not_installed' => ['off', 'Not installed'], 'current' => ['ok', 'Installed'], 'update_available' => ['info', 'Update available'], 'modified' => ['warn', 'Edited by you'],
        'update_staged' => ['warn', 'Update waiting for review'], 'retired' => ['off', 'Retired'], 'in_use' => ['warn', 'In use, update not applied'],
    ];
    [$kind, $text] = $map[$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];

    return rivetRmmUiPill($kind, $text);
}

/** What Core says an install would do / did / an uninstall would do, as a pill. */
function rivetRmmStarterActionPill(string $a): string
{
    $map = [
        'add' => ['ok', 'Add'], 'update' => ['info', 'Update'], 'stage' => ['warn', 'Park for review'], 'unchanged' => ['off', 'Already installed'], 'skip' => ['off', 'Skip'], 'blocked' => ['crit', 'Blocked'],
        'added' => ['ok', 'Added'], 'updated' => ['info', 'Updated'], 'staged' => ['warn', 'Parked for review'], 'skipped' => ['off', 'Skipped'], 'failed' => ['crit', 'Failed'],
        'would_retire' => ['warn', 'Would be retired'], 'would_delete' => ['warn', 'Would be deleted'], 'retired' => ['ok', 'Retired'], 'deleted' => ['ok', 'Deleted'],
    ];
    [$kind, $text] = $map[$a] ?? ['off', ucfirst(str_replace('_', ' ', $a))];

    return rivetRmmUiPill($kind, $text);
}

/**
 * @return array{error:string,status:int,ready:bool,blocker:string,filters:array{platform:string,risk:list<string>,tag:string,q:string},scripts:list<array<string,mixed>>,shown:list<array<string,mixed>>,
 *     policies:list<array<string,mixed>>,check_sets:list<array<string,mixed>>,params:list<array<string,mixed>>,counts:array<string,mixed>,tags:array<string,int>,pack:array<string,mixed>,result:?array<string,mixed>}
 */
function rivetRmmStarterView(array $ctx, array $get): array
{
    $str = static fn (string $k, int $max): string => is_string($get[$k] ?? null) ? mb_substr(trim($get[$k]), 0, $max) : '';
    $platform = $str('platform', 10);
    $risk = [];
    foreach ((array) ($get['risk'] ?? []) as $r) {
        if (is_string($r) && in_array($r, ['read_only', 'changes_system', 'disruptive'], true) && !in_array($r, $risk, true)) {
            $risk[] = $r;
        }
    }
    $f = ['platform' => in_array($platform, ['windows', 'linux'], true) ? $platform : '', 'risk' => $risk, 'tag' => $str('tag', 30), 'q' => mb_strtolower($str('q', 100))];
    $r = rivetRmmScrApi($ctx, 'GET', ['starter', 'catalog']);
    $vm = ['error' => $r['ok'] ? '' : $r['error'], 'status' => $r['status'], 'ready' => (bool) ($r['data']['ready'] ?? false), 'blocker' => (string) ($r['data']['blocker'] ?? ''), 'filters' => $f,
        'scripts' => [], 'shown' => [], 'policies' => [], 'check_sets' => [], 'params' => [], 'pack' => [], 'tags' => [], 'result' => null,
        'counts' => ['scripts' => 0, 'windows' => 0, 'linux' => 0, 'installed' => 0, 'waiting' => 0, 'risk' => ['read_only' => 0, 'changes_system' => 0, 'disruptive' => 0], 'shown' => 0, 'policies' => 0, 'check_sets' => 0]];
    if ($r['ok']) {
        $packs = (array) ($r['data']['packs'] ?? []);
        $vm['pack'] = (array) ($packs[0]['pack'] ?? []);
        foreach ($packs as $pack) {
            foreach ((array) $pack['scripts'] as $s) {
                $s['pack_id'] = (string) ($pack['pack']['id'] ?? '');
                $vm['scripts'][] = $s;
            }
            foreach ((array) $pack['policies'] as $p) {
                $p['pack_id'] = (string) ($pack['pack']['id'] ?? '');
                $vm['policies'][] = $p;
            }
            foreach ((array) $pack['check_sets'] as $c) {
                $vm['check_sets'][] = $c;
            }
        }
        $c = &$vm['counts'];
        foreach ($vm['scripts'] as $s) {
            ++$c['scripts'];
            ++$c[(string) $s['platform']];
            $c['risk'][(string) $s['risk']] = ($c['risk'][(string) $s['risk']] ?? 0) + 1;
            $c['installed'] += ($s['state'] ?? '') === 'not_installed' ? 0 : 1;
            $c['waiting'] += ($s['state'] ?? '') === 'update_staged' ? 1 : 0;
            foreach ((array) $s['tags'] as $t) {
                $vm['tags'][(string) $t] = ($vm['tags'][(string) $t] ?? 0) + 1;
            }
            $hay = mb_strtolower($s['name'] . ' ' . $s['description']);
            if (($f['platform'] === '' || $s['platform'] === $f['platform']) && ($f['risk'] === [] || in_array($s['risk'], $f['risk'], true))
                && ($f['tag'] === '' || in_array($f['tag'], array_map('strval', (array) $s['tags']), true)) && ($f['q'] === '' || str_contains($hay, $f['q']))) {
                $vm['shown'][] = $s;
            }
        }
        unset($c);
        $vm['counts']['shown'] = count($vm['shown']);
        $vm['counts']['policies'] = count($vm['policies']);
        $vm['counts']['check_sets'] = count($vm['check_sets']);
        ksort($vm['tags']);
        // the parameters of the policy drafts, one input each (the same name in several drafts is one input)
        $seen = [];
        foreach ($vm['policies'] as $p) {
            foreach ((array) ($p['params'] ?? []) as $d) {
                if (!isset($seen[(string) $d['name']])) {
                    $seen[(string) $d['name']] = true;
                    $vm['params'][] = $d + ['used_by' => []];
                }
            }
        }
        foreach ($vm['params'] as $i => $d) {
            foreach ($vm['policies'] as $p) {
                foreach ((array) ($p['params'] ?? []) as $pd) {
                    if ($pd['name'] === $d['name']) {
                        $vm['params'][$i]['used_by'][] = (string) $p['name'];
                    }
                }
            }
        }
    }
    if (isset($_SESSION['rmm_starter_result']) && is_array($_SESSION['rmm_starter_result'])) {   // shown once, after the redirect that followed a plan, an install or an uninstall
        $vm['result'] = $_SESSION['rmm_starter_result'];
        unset($_SESSION['rmm_starter_result']);
    }

    return $vm;
}

// ------------------------------------------------------------------ the page: renderers

function rivetRmmStarterCounts(array $counts): string
{
    $o = '';
    foreach ($counts as $k => $n) {
        $o .= '<span class="me-1">' . rivetRmmStarterActionPill((string) $k) . ' <span class="small">' . (int) $n . '</span></span>';
    }

    return $o;
}

/** @param array<string,mixed> $res the stored result: kind (plan|install|uninstall_preview|uninstall), data (Core's answer), ids, params */
function rivetRmmStarterResultCard(array $res, array $ctx, string $returnTo): string
{
    $data = (array) ($res['data'] ?? []);
    $items = (array) ($data['items'] ?? []);
    $kind = (string) ($res['kind'] ?? '');
    $titles = ['plan' => ['Plan: nothing was changed', 'clipboard-list'], 'install' => ['Install result', 'check-double'], 'uninstall_preview' => ['Uninstall preview: nothing was changed', 'eye'], 'uninstall' => ['Uninstall result', 'trash-alt']];
    [$title, $icon] = $titles[$kind] ?? ['Result', 'info-circle'];
    $rows = '';
    foreach ($items as $i) {
        $act = (string) ($i['outcome'] ?? $i['action'] ?? '');
        $rows .= '<tr><td class="ps-3">' . rmmH($i['name'] ?? $i['id'] ?? '') . '<div class="small text-muted">' . rmmH($i['id'] ?? '') . '</div></td><td>' . rmmH(($i['kind'] ?? '') === 'policy' ? 'Policy draft' : 'Script') . '</td>'
            . '<td>' . rivetRmmStarterActionPill($act) . '</td><td class="small">' . rmmH((string) ($i['message'] ?? $i['reason'] ?? '')) . '</td></tr>';
    }
    $table = $items === [] ? '<p class="text-muted mb-0">Nothing matched.</p>'
        : '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><caption class="visually-hidden">What the starter pack ' . ($kind === 'plan' ? 'would do' : 'did') . ' for each selected item</caption>'
            . rivetRmmUiThead(['Item', 'Kind', $kind === 'plan' ? 'Would do' : 'Outcome', 'Why']) . '<tbody>' . $rows . '</tbody></table></div>';
    $body = '<div class="mb-2" role="status">' . rivetRmmStarterCounts((array) ($data['counts'] ?? [])) . '</div>' . $table;
    if ($kind === 'uninstall_preview' && array_filter($items, static fn (array $i): bool => in_array($i['outcome'] ?? '', ['would_retire', 'would_delete'], true)) !== []) {
        $hidden = '';
        foreach ((array) ($res['ids'] ?? []) as $id) {
            $hidden .= '<input type="hidden" name="ids[]" value="' . rmmH($id) . '">';
        }
        $body .= '<form method="post" action="/agent/post/' . RIVET_RMM_STARTER_HANDLER . '.php" class="mt-3">' . rivetRmmAutoHidden($ctx['csrf'], $returnTo) . '<input type="hidden" name="action" value="uninstall">' . $hidden
            . '<button type="submit" class="btn btn-danger" id="starter-uninstall-go" data-rmm-confirm="Uninstall the items listed above? Scripts are retired (their history stays and they can be restored) and untouched policy drafts are deleted. Anything you edited is left alone." data-rmm-confirm-label="Uninstall" data-rmm-confirm-class="btn-danger">'
            . '<i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Uninstall these</button></form>';
    }

    return rivetRmmUiCard($title, $icon, $body, in_array($kind, ['plan', 'uninstall_preview'], true) ? 'info' : 'success', '', false, 'starter-result');
}

/** @param array<string,mixed> $d one parameter definition of a policy draft */
function rivetRmmStarterParamInput(array $d, string $value): string
{
    $name = (string) $d['name'];
    $id = 'starter_param_' . $name;
    $label = rmmH($d['label'] ?? $name);
    $help = '<div class="form-text" id="' . $id . '_help">' . rmmH($d['description'] ?? '') . (isset($d['default']) && $d['default'] !== null && $d['default'] !== [] ? ' Default: <code>' . rmmH(is_array($d['default']) ? implode(', ', $d['default']) : $d['default']) . '</code>.' : '')
        . (!empty($d['used_by']) ? ' Used by: ' . rmmH(implode(', ', $d['used_by'])) . '.' : '') . '</div>';
    if (($d['type'] ?? '') === 'list') {
        return '<div class="col-md-6"><label class="form-label" for="' . $id . '">' . $label . ' <span class="text-muted">(one per line)</span></label><textarea class="form-control font-monospace" id="' . $id . '" name="param[' . rmmH($name) . ']" rows="3" spellcheck="false" aria-describedby="' . $id . '_help" placeholder="' . rmmH($d['example'] ?? '') . '">' . rmmH($value) . '</textarea>' . $help . '</div>';
    }
    $int = ($d['type'] ?? '') === 'int';

    return '<div class="col-md-6"><label class="form-label" for="' . $id . '">' . $label . '</label><input class="form-control" id="' . $id . '" name="param[' . rmmH($name) . ']" ' . ($int ? 'type="number" inputmode="numeric"' : 'type="text" maxlength="200" autocomplete="off"')
        . ' value="' . rmmH($value) . '" aria-describedby="' . $id . '_help" placeholder="' . rmmH($d['example'] ?? '') . '">' . $help . '</div>';
}

function rivetRmmStarterPage(array $vm, array $ctx): string
{
    $self = RIVET_RMM_STARTER_PAGE;
    $csrf = $ctx['csrf'];
    $f = $vm['filters'];
    $q = array_filter(['platform' => $f['platform'], 'tag' => $f['tag'], 'q' => $f['q']], static fn ($v): bool => $v !== '');
    $queryString = http_build_query($q + ($f['risk'] === [] ? [] : ['risk' => $f['risk']]));
    $returnTo = $self . ($queryString !== '' && preg_match('~^[A-Za-z0-9_=&%.\-\[\]+]*$~', $queryString) === 1 ? '?' . $queryString : '');
    $back = '<a class="btn btn-outline-secondary" href="/agent/rmm_script_library.php"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Scripts</a>';
    $o = rivetRmmAutoHeader('Starter pack', 'box-open', $back, 'Ready-made scripts, check templates and policy drafts for a new install.');
    $o .= '<div class="rmmx-info" role="note"><i class="fas fa-shield-alt" aria-hidden="true"></i><div><strong>Safe by default.</strong><ul>'
        . '<li>Nothing is installed until you tick items and press <strong>Install selected</strong>.</li>'
        . '<li>Every script is signed with this installation\'s key.</li>'
        . '<li>A change to the system has a dry run, on by default. <strong>Plan</strong> shows what would happen without doing it.</li>'
        . '<li>A script you edit later is never overwritten by the pack.</li></ul></div></div>';
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $ready = $vm['ready'];
    if (!$ready) {
        $o .= '<div class="alert alert-danger d-flex gap-2" role="alert" id="starter-blocker"><i class="fas fa-exclamation-triangle mt-1" aria-hidden="true"></i><div><strong>Nothing can be installed yet.</strong> ' . rmmH($vm['blocker'])
            . ' <a href="/admin/settings_endpoint_agent.php#signing">Open Administration &gt; Endpoint agent &gt; Signing key</a>. You can still browse the catalog and see a plan.</div></div>';
    }
    if ($vm['result'] !== null) {
        $o .= rivetRmmStarterResultCard($vm['result'], $ctx, $returnTo);
    }
    $c = $vm['counts'];
    $link = static fn (array $over): string => $self . ($over === [] ? '' : '?' . http_build_query($over));
    $kp = static fn (string $label, int $n, string $tone, string $href, bool $active, string $tip, string $icon): string => rmmxKpi(['icon' => $icon, 'label' => $label, 'value' => $n, 'tone' => $tone, 'href' => $href, 'active' => $active, 'title' => $tip]);
    $chips = rmmxKpiGrid([
        $kp('Windows scripts', (int) $c['windows'], 'info', $link(['platform' => 'windows']), $f['platform'] === 'windows', 'Show only scripts that run on Windows', 'fa-windows'),
        $kp('Linux scripts', (int) $c['linux'], 'info', $link(['platform' => 'linux']), $f['platform'] === 'linux', 'Show only scripts that run on Linux', 'fa-linux'),
        $kp('Read only', (int) $c['risk']['read_only'], 'ok', $link(['risk' => ['read_only']]), $f['risk'] === ['read_only'], 'Scripts that only read and report: they change nothing on the device', 'fa-eye'),
        $kp('Change the system', (int) $c['risk']['changes_system'], 'warn', $link(['risk' => ['changes_system']]), $f['risk'] === ['changes_system'], 'Scripts that change settings or files (they have a dry run, on by default)', 'fa-wrench'),
        $kp('Disruptive', (int) $c['risk']['disruptive'], 'danger', $link(['risk' => ['disruptive']]), $f['risk'] === ['disruptive'], 'Scripts that can interrupt users or services (restart, stop, remove)', 'fa-bolt'),
        $kp('Installed', (int) $c['installed'], 'ok', $self . '#starter-card-scripts', false, (int) $c['scripts'] . ' scripts, ' . (int) $c['policies'] . ' policy drafts and ' . (int) $c['check_sets'] . ' check template sets in the pack', 'fa-check-circle'),
    ]) . ($c['waiting'] > 0 ? '<p class="small"><span class="rmmx-pill rmmx-pill-warn">' . (int) $c['waiting'] . ' update' . ($c['waiting'] === 1 ? '' : 's') . ' waiting for review</span></p>' : '')
        . '<p class="small text-muted">' . (int) $c['scripts'] . ' scripts &middot; ' . (int) $c['policies'] . ' policy drafts &middot; ' . (int) $c['check_sets'] . ' check template sets in this pack.</p>';
    $o .= $chips;
    // filters
    $tagOpts = '<option value="">Any tag</option>';
    foreach ($vm['tags'] as $t => $n) {
        if ($t === 'starter' || str_starts_with((string) $t, 'risk:')) {
            continue;
        }
        $tagOpts .= '<option value="' . rmmH($t) . '"' . ($f['tag'] === $t ? ' selected' : '') . '>' . rmmH($t) . ' (' . (int) $n . ')</option>';
    }
    $riskBoxes = '';
    foreach (['read_only' => 'Read only', 'changes_system' => 'Changes the system', 'disruptive' => 'Disruptive'] as $k => $l) {
        $riskBoxes .= '<input class="btn-check" type="checkbox" id="st_risk_' . $k . '" name="risk[]" value="' . $k . '"' . (in_array($k, $f['risk'], true) ? ' checked' : '') . '><label class="btn btn-outline-secondary" for="st_risk_' . $k . '">' . $l . '</label>';
    }
    $o .= '<form method="get" action="' . $self . '" class="rmmx-toolbar" id="starter-filters" role="search" aria-label="Filter the starter pack">'
        . '<div class="rmmx-field rmmx-grow"><label for="st_q">Search</label><input class="form-control" type="search" id="st_q" name="q" maxlength="100" value="' . rmmH($f['q']) . '" placeholder="Name or description"></div>'
        . '<div class="rmmx-field"><label for="st_platform">Platform</label><select class="form-select" id="st_platform" name="platform" data-searchable="0"><option value="">Any</option><option value="windows"' . ($f['platform'] === 'windows' ? ' selected' : '') . '>Windows</option><option value="linux"' . ($f['platform'] === 'linux' ? ' selected' : '') . '>Linux</option></select></div>'
        . '<div class="rmmx-field"><label for="st_tag">Tag</label><select class="form-select" id="st_tag" name="tag">' . $tagOpts . '</select></div>'
        . '<div class="rmmx-field"><span class="rmmx-label" id="st_risk_label">Risk</span><div class="btn-group" role="group" aria-labelledby="st_risk_label">' . $riskBoxes . '</div></div>'
        . '<div class="rmmx-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-filter" aria-hidden="true"></i>Apply</button><a class="btn btn-outline-secondary" href="' . $self . '">Reset</a></div></form>'
        . rmmxChips(array_filter(['Platform: ' . ucfirst($f['platform']) => $f['platform'] !== '' ? $link(array_filter(['tag' => $f['tag'], 'q' => $f['q']] + ($f['risk'] ? ['risk' => $f['risk']] : []), static fn ($v) => $v !== '' && $v !== [])) : '',
            'Tag: ' . $f['tag'] => $f['tag'] !== '' ? $link(array_filter(['platform' => $f['platform'], 'q' => $f['q']] + ($f['risk'] ? ['risk' => $f['risk']] : []), static fn ($v) => $v !== '' && $v !== [])) : '',
            'Search: ' . $f['q'] => $f['q'] !== '' ? $link(array_filter(['platform' => $f['platform'], 'tag' => $f['tag']] + ($f['risk'] ? ['risk' => $f['risk']] : []), static fn ($v) => $v !== '' && $v !== [])) : ''], static fn ($u) => $u !== ''), $self, (int) $c['shown'] . ' of ' . (int) $c['scripts'] . ' scripts shown');

    // the one form: tick items, set the policy parameters, then Plan / Install / Preview uninstall
    $ticked = array_flip(array_map('strval', (array) ($vm['result']['ids'] ?? [])));
    $paramVals = (array) ($vm['result']['params'] ?? []);
    $rows = '';
    foreach ($vm['shown'] as $s) {
        $id = (string) $s['id'];
        $sid = (int) ($s['script_id'] ?? 0);
        $name = $sid > 0 ? '<a href="/agent/rmm_script_library.php?script_id=' . $sid . '">' . rmmH($s['name']) . '</a>' : rmmH($s['name']);
        $state = (string) ($s['state'] ?? 'not_installed');
        $stateExtra = '';
        if ($state === 'modified') {
            $stateExtra = '<div class="small text-muted">You changed it, so the pack will not overwrite it.</div>';
        } elseif ($state === 'update_staged' && $sid > 0) {
            $stateExtra = '<div class="small"><a href="/agent/rmm_script_library.php?script_id=' . $sid . '#scr-versions">Review the update</a></div>';
        }
        $rows .= '<tr><td class="checkbox-column"><input class="form-check-input starter-pick" type="checkbox" name="ids[]" id="st_pick_' . rmmH($id) . '" value="' . rmmH($id) . '" data-risk="' . rmmH($s['risk']) . '"' . (isset($ticked[$id]) ? ' checked' : '')
            . ' aria-label="Select ' . rmmH($s['name']) . '"></td>'
            . '<td class="rmmx-title"><div class="fw-semibold">' . $name . '</div><div class="rmmx-desc">' . rmmH(mb_substr((string) $s['description'], 0, 160)) . '</div></td>'
            . '<td>' . rivetRmmStarterStatePill($state) . $stateExtra . '</td>'
            . '<td>' . rmmxPlatform((string) $s['language']) . '</td><td>' . rivetRmmStarterRiskPill((string) $s['risk']) . ($s['requires_approval'] ? '<div class="small text-muted mt-1">Needs approval</div>' : '') . '</td>'
            . '<td class="rmmx-tags">' . rivetRmmScrTagChips(array_slice(rivetRmmStarterTagInfo((array) $s['tags'])['rest'], 0, 4)) . '</td></tr>';
    }
    $scriptsTable = $vm['shown'] === []
        ? rivetRmmUiEmpty('fas fa-box-open', 'No script matches', 'Try another search or clear a filter.')
        : '<div class="rmmx-scroll"><table class="rmmx-table" id="starter-scripts"><caption class="visually-hidden">Starter pack scripts</caption>'
            . '<thead><tr><th scope="col" class="checkbox-column"><input class="form-check-input" type="checkbox" id="starter-all" aria-label="Select every script shown"></th><th scope="col">Script</th><th scope="col">State</th><th scope="col">Runs on</th><th scope="col">Risk</th><th scope="col">Tags</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    $pRows = '';
    foreach ($vm['policies'] as $p) {
        $id = (string) $p['id'];
        $pState = (string) ($p['state'] ?? 'not_installed');
        $pRows .= '<tr><td class="ps-3"><input class="form-check-input starter-pick" type="checkbox" name="ids[]" id="st_pick_' . rmmH($id) . '" value="' . rmmH($id) . '" data-risk="policy"' . (isset($ticked[$id]) ? ' checked' : '') . ' aria-label="Select ' . rmmH($p['name']) . '"></td>'
            . '<td>' . ((int) ($p['policy_id'] ?? 0) > 0 ? '<a href="/agent/rmm_policies.php?policy_id=' . (int) $p['policy_id'] . '">' . rmmH($p['name']) . '</a>' : rmmH($p['name'])) . '<div class="small text-muted">' . rmmH($p['description']) . '</div></td>'
            . '<td>' . rmmH(ucfirst((string) $p['platform'])) . '</td><td>' . rmmH(implode(', ', array_map('strval', (array) ($p['check_sets'] ?? [])))) . '</td>'
            . '<td>' . rivetRmmStarterStatePill($pState) . (!empty($p['enabled']) ? ' ' . rivetRmmUiPill('warn', 'On') : '') . ((int) ($p['assignments'] ?? 0) > 0 ? '<div class="small text-muted">' . (int) $p['assignments'] . ' assignment' . ((int) $p['assignments'] === 1 ? '' : 's') . '</div>' : '') . '</td></tr>';
    }
    $policiesTable = $vm['policies'] === [] ? '<p class="text-muted p-3 mb-0">No policy drafts in the pack.</p>'
        : '<div class="table-responsive"><table class="table table-hover table-sm mb-0" id="starter-policies"><caption class="visually-hidden">Starter pack policy drafts</caption><thead><tr class="rmm-thead"><th scope="col" class="ps-3" style="width:2.5rem"><span class="visually-hidden">Select</span></th><th scope="col">Policy draft</th><th scope="col">Platform</th><th scope="col">Check template set</th><th scope="col">State</th></tr></thead><tbody>' . $pRows . '</tbody></table></div>';
    $sets = '';
    foreach ($vm['check_sets'] as $cs) {
        $sets .= '<li><strong>' . rmmH($cs['name']) . '</strong> <span class="text-muted">(' . count((array) ($cs['checks'] ?? [])) . ' checks, ' . rmmH($cs['platform']) . ')</span><div class="small text-muted">' . rmmH($cs['description'] ?? '') . '</div></li>';
    }
    $params = '';
    foreach ($vm['params'] as $d) {
        $val = $paramVals[$d['name']] ?? '';
        $params .= rivetRmmStarterParamInput($d, is_array($val) ? implode("\n", array_map('strval', $val)) : (string) $val);
    }
    $csrfHidden = rivetRmmAutoHidden($csrf, $returnTo);
    $installAttrs = $ready ? '' : ' disabled aria-disabled="true" title="No signing key: nothing can be installed yet"';
    $actions = '<div class="rmmx-bar" id="starter-actions" role="region" aria-label="Selection actions"><span class="rmmx-bar-count" id="starter-selected" role="status" aria-live="polite">Nothing selected</span>'
        . '<button type="button" class="btn btn-outline-secondary" data-starter-select="read_only">Select read-only</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-starter-select="all">Select all shown</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-starter-select="none">Clear</button>'
        . '<button type="submit" name="action" value="plan" class="btn btn-outline-primary" id="starter-plan" title="Dry run: shows what installing the selection would do, and changes nothing"><i class="fas fa-clipboard-list me-1" aria-hidden="true"></i>Plan</button>'
        . '<button type="submit" name="action" value="install" class="btn btn-primary" id="starter-install"' . $installAttrs . ' data-rmm-confirm="Install the selected items? Scripts are added to the library, signed with this installation\'s key; a policy draft is created switched off and assigned to nothing."><i class="fas fa-download me-1" aria-hidden="true"></i><span id="starter-install-label">Install selected</span></button>'
        . '<span class="rmmx-bar-sep" aria-hidden="true"></span>'
        . '<button type="submit" name="action" value="uninstall_preview" class="btn btn-outline-danger" id="starter-uninstall" title="Preview first: removes only pack items nobody has edited"><i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Uninstall untouched</button></div>';
    $o .= '<form method="post" action="/agent/post/' . RIVET_RMM_STARTER_HANDLER . '.php" id="starter-form" class="rmmx-pad-bottom">' . $csrfHidden
        . rivetRmmUiCard('Scripts', 'file-code', $scriptsTable, '', '<span class="small text-muted">' . (int) $c['shown'] . ' of ' . (int) $c['scripts'] . ' shown</span>', true, 'starter-card-scripts')
        . rivetRmmUiCard('Policy drafts', 'sliders-h', '<p class="small text-muted px-3 pt-3 mb-2">A draft is created switched off and assigned to nothing. Read it, set its parameters below, switch it on and assign it on the Policies page.</p>' . $policiesTable, '', '<span class="small text-muted">' . (int) $c['policies'] . '</span>', true, 'starter-card-policies')
        . rivetRmmUiCard('Policy settings', 'cog', '<p class="small text-muted mb-3">Used when a policy draft is installed or updated; leave a field empty to keep the pack\'s default. A draft you edited yourself is not changed by these.</p><div class="row g-3">' . $params . '</div>', '', '', false, 'starter-card-params')
        . ($sets === '' ? '' : rivetRmmUiCard('Check template sets', 'clipboard-check', '<ul class="mb-0 ps-3">' . $sets . '</ul>', '', '', false, 'starter-card-sets'))
        . $actions . '</form>';
    $o .= '<p class="small text-muted mt-3">What an install does: new scripts are added; a script nobody edited gets the pack\'s newer version; a script you edited keeps running what it ran and the pack\'s newer text is parked as a further version for you to review in the script library; a script you retired stays retired. '
        . 'Uninstall retires scripts and deletes policy drafts only while you have not edited them (and a draft only while it is off and unassigned).</p>';

    return $o . rivetRmmAutoConfirmModal() . rivetRmmAutoScriptsOn();
}

// ------------------------------------------------------------------ the handler's reading of the form

/**
 * The selection Core wants from the form: the ticked ids (a valid id is 1 to 60 of a-z 0-9 -) and the policy parameters that were filled in. An empty selection is NOT
 * sent on (Core reads "nothing" as the whole pack).
 *
 * @return array{ids:list<string>,params:array<string,mixed>,raw_params:array<string,string>}
 */
function rivetRmmStarterSelectionFromPost(): array
{
    $ids = [];
    foreach ((array) ($_POST['ids'] ?? []) as $id) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{1,60}$/', $id) === 1 && !in_array($id, $ids, true) && count($ids) < 500) {
            $ids[] = $id;
        }
    }
    $params = [];
    $raw = [];
    foreach ((array) ($_POST['param'] ?? []) as $name => $val) {
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) !== 1 || !is_string($val)) {
            continue;
        }
        $val = trim($val);
        $raw[$name] = mb_substr($val, 0, 2000);
        if ($val === '') {
            continue;   // empty = the pack's default
        }
        if ($name === 'cert_paths') {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $val) ?: []), static fn (string $l): bool => $l !== ''));
            $params[$name] = array_slice($lines, 0, 20);
        } elseif (preg_match('/^-?\d{1,9}$/', $val) === 1 && !in_array($name, ['backup_service', 'ntp_server', 'ssh_service'], true)) {
            $params[$name] = (int) $val;
        } else {
            $params[$name] = mb_substr($val, 0, 200);
        }
    }

    return ['ids' => $ids, 'params' => $params, 'raw_params' => $raw];
}
