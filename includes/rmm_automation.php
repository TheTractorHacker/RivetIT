<?php

/*
 * RMM Phase 2 and 3 pages (RivetCore 1.0.0-rc.10): policies, script library, schedules, approvals, custom fields, agent alerts, maintenance windows and
 * escalation policies. This file is the SHARED layer those pages (agent/rmm_policies.php, rmm_script_library.php, rmm_schedules.php, rmm_approvals.php,
 * rmm_fields.php, rmm_agent_alerts.php, rmm_maintenance.php, rmm_escalations.php), their handlers (agent/post/rmm_automation_*.php) and the asset page
 * sections share: the gate every page starts with, the internal call into RivetCore's technician API, the scope picker, form and confirm helpers, and the
 * answer a form post gives.
 *
 * ONE AUTHORIZATION PATH. The pages never reimplement a permission or a client scope. Every read and every write is a call into
 * RivetCore\Rmm\Http\TechnicianApi (the very code behind the REST API, `endpoint_devices/...`) as the signed-in user, so the 403/404 rules, the client
 * scoping of lists and the audit entries are Core's. Hiding a button is cosmetic; a forged POST gets the same answer the REST API would give.
 *
 * THE SWITCHES. A page starts with rivetRmmAutoPage(): module off or sub-switch off is the standard "switched off" notice, built from the module's state file
 * (no query, no module built). With the module off nothing here asks the database anything.
 *
 * Output is escaped at the point it is printed (rmmH()); markup passed between functions is already HTML and says so in its name or docblock.
 */

require_once __DIR__ . '/rmm_ui_render.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Http\RmmRequest;

/** Which sub-switch each page needs, the plain-language name used in the "switched off" notice, and the Administration anchor. */
const RIVET_RMM_AUTO_FEATURES = [
    'policies' => 'Policies',
    'scripts' => 'Scripts, schedules and approvals',
    'alerting' => 'Alerting',
    'patching' => 'Patch management',
    'software_deploy' => 'Software deployment',
];

// ------------------------------------------------------------------ the internal call into the technician API

/**
 * Call RivetCore's technician API as a user, in process. `$segments` are the path segments after `endpoint_devices/`, for example ['policies', '3', 'assignments'].
 * A write passes `$body` (an associative array with PHP types that survive JSON: ints stay ints, which Core's validators care about).
 *
 * @param list<string|int> $segments
 * @param array<string,string> $query
 * @param array<string,mixed>|null $body
 * @return array{status:int,ok:bool,data:array<string,mixed>,error:string,code:string}
 */
function rivetRmmAutoApi(\mysqli $mysqli, int $userId, string $userName, string $method, array $segments, array $query = [], ?array $body = null): array
{
    $stream = null;
    $len = null;
    if ($body !== null) {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $json);
        rewind($stream);
        $len = strlen($json);
    }
    $req = new RmmRequest(strtoupper($method), 'endpoint_devices', array_map('strval', $segments), $query, ['content-type' => 'application/json'],
        function_exists('getIP') ? (string) getIP() : '127.0.0.1', isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null, true, $len, $stream);
    $resp = rivetRmmModule($mysqli)->technicianApi()->handle($req, rivetRmmPrincipal($userId, $userName));
    $data = json_decode((string) $resp->body, true);
    $data = is_array($data) ? $data : [];

    return ['status' => $resp->status, 'ok' => $resp->status >= 200 && $resp->status < 300, 'data' => $data, 'error' => (string) ($data['error'] ?? ''), 'code' => (string) ($data['code'] ?? '')];
}

// ------------------------------------------------------------------ the gate every page starts with

/**
 * The role-level abilities of a user (a department of 0 asks "anywhere"). Per-department abilities are decided by Core on each call.
 *
 * @return array{view:bool,admin:bool,manage:bool,run_saved:bool,run_script:bool,approve:bool,alert_manage:bool}
 */
function rivetRmmAutoPerm(\mysqli $mysqli, int $userId): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();

    return [
        'view' => $authz->allowed($userId, RmmAbility::DEVICE_VIEW, 0),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0),
        'manage' => $authz->allowed($userId, RmmAbility::DEVICE_MANAGE, 0),
        'run_saved' => $authz->allowed($userId, RmmAbility::JOB_RUN_SAVED, 0),
        'run_script' => $authz->allowed($userId, RmmAbility::JOB_RUN_SCRIPT, 0),
        'approve' => $authz->allowed($userId, RmmAbility::JOB_APPROVE, 0),
        'alert_manage' => $authz->allowed($userId, RmmAbility::ALERT_MANAGE, 0),
    ];
}

/**
 * The first thing a page does (after inc_all.php and enforceUserPermission('module_rmm')): the module and the sub-switch must be on and the user must be allowed to
 * view devices. Returns the page context, or null after rendering the denial/notice (the caller then stops: `return;`). Module off or switch off asks the
 * database nothing.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmAutoPage(\mysqli $mysqli, string $feature, int $userId, string $userName): ?array
{
    if (!rivetRmmEnabled($mysqli)) {
        itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');

        return null;
    }
    if (!rivetRmmFeatureOn($feature, $mysqli)) {
        itflow_render_denied((RIVET_RMM_AUTO_FEATURES[$feature] ?? 'This part of the endpoint agent') . ' is switched off. An administrator can turn it on under Administration > Endpoint agent > ' . ($feature === 'patching' ? 'Patch management' : ($feature === 'software_deploy' ? 'Software deployment' : 'Policies, scripts and alerting')) . '.',
            'Switched off');

        return null;
    }
    $perm = rivetRmmAutoPerm($mysqli, $userId);
    if (!$perm['view']) {
        itflow_render_denied('Your role cannot view agent devices.', 'No access');

        return null;
    }
    $GLOBALS['rmm_auto_scripts'] = true;   // includes/footer.php links js/rmm_automation.js only when this is set

    return ['uid' => $userId, 'name' => $userName, 'csrf' => (string) ($_SESSION['csrf_token'] ?? ''), 'perm' => $perm, 'mysqli' => $mysqli];
}

// ------------------------------------------------------------------ small renderers shared by the pages

/** Ask includes/footer.php to link js/rmm_automation.js on this page (returns '' so a renderer can append it). */
function rivetRmmAutoScriptsOn(): string
{
    $GLOBALS['rmm_auto_scripts'] = true;

    return '';
}

/** The page title row: title and icon on the left, the page's buttons (already HTML) on the right, then an optional one-line explanation. */
function rivetRmmAutoHeader(string $title, string $icon, string $actionsHtml = '', string $intro = ''): string
{
    return '<div class="d-flex align-items-center flex-wrap mb-3" style="gap:6px"><h4 class="mb-0 me-auto"><i class="fas fa-' . rmmH($icon) . ' me-2" aria-hidden="true"></i>' . rmmH($title) . '</h4>'
        . $actionsHtml . '</div>' . ($intro !== '' ? '<p class="text-muted small mb-3">' . rmmH($intro) . '</p>' : '');
}

/** A hidden CSRF field plus the return target (validated again by the handler). */
function rivetRmmAutoHidden(string $csrf, string $returnTo): string
{
    return '<input type="hidden" name="csrf_token" value="' . rmmH($csrf) . '"><input type="hidden" name="return" value="' . rmmH($returnTo) . '">';
}

/**
 * A small POST form with one button, for a row action. `$confirm` (plain words) makes the button ask first in an in-page dialog (js/rmm_automation.js).
 *
 * @param array<string,scalar> $fields extra hidden fields (ids)
 */
function rivetRmmAutoActionForm(string $handler, string $action, array $fields, string $csrf, string $returnTo, string $buttonHtml, string $buttonClass = 'btn btn-sm btn-outline-secondary', string $confirm = '', string $title = ''): string
{
    $hidden = '';
    foreach ($fields as $k => $v) {
        $hidden .= '<input type="hidden" name="' . rmmH($k) . '" value="' . rmmH((string) $v) . '">';
    }

    return '<form method="post" action="/agent/post/' . rmmH($handler) . '.php" class="d-inline">' . rivetRmmAutoHidden($csrf, $returnTo) . '<input type="hidden" name="action" value="' . rmmH($action) . '">' . $hidden
        . '<button type="submit" class="' . rmmH($buttonClass) . '"' . ($confirm !== '' ? ' data-rmm-confirm="' . rmmH($confirm) . '"' : '') . ($title !== '' ? ' title="' . rmmH($title) . '"' : '') . '>' . $buttonHtml . '</button></form>';
}

/** The in-page confirmation dialog js/rmm_automation.js drives (no window.confirm). Render once per page that has data-rmm-confirm buttons. */
function rivetRmmAutoConfirmModal(): string
{
    return '<div class="modal fade" id="rmmAutoConfirm" tabindex="-1" aria-labelledby="rmmAutoConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmAutoConfirmTitle"><i class="fas fa-question-circle me-2" aria-hidden="true"></i>Please confirm</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><p id="rmmAutoConfirmText" class="mb-0"></p></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="rmmAutoConfirmGo">Confirm</button></div></div></div></div>';
}

/** UTC time as text for tables ("2026-10-10 14:03 UTC"), or "never". Accepts RFC 3339 or SQL UTC. */
function rivetRmmAutoUtc(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '<span class="text-muted">never</span>';
    }
    $t = strtotime(str_contains($iso, 'T') || str_ends_with($iso, 'Z') ? $iso : $iso . ' UTC');

    return $t === false ? rmmH($iso) : '<time datetime="' . rmmH(gmdate('Y-m-d\TH:i:s\Z', $t)) . '">' . rmmH(gmdate('Y-m-d H:i', $t) . ' UTC') . '</time>';
}

/** Plain-language state pill for an approval, schedule, alert or window state. */
function rivetRmmAutoStatePill(string $state): string
{
    $map = [
        'open' => ['crit', 'Open'], 'acknowledged' => ['warn', 'Acknowledged'], 'resolved' => ['ok', 'Resolved'],
        'pending_approval' => ['warn', 'Waiting for approval'], 'approved' => ['ok', 'Approved'], 'rejected' => ['crit', 'Rejected'], 'cancelled' => ['off', 'Cancelled'], 'expired' => ['off', 'Expired'],
        'active' => ['ok', 'Active'], 'paused' => ['off', 'Paused'], 'enabled' => ['ok', 'On'], 'disabled' => ['off', 'Off'],
    ];
    [$kind, $text] = $map[$state] ?? ['off', ucfirst(str_replace('_', ' ', $state))];

    return rivetRmmUiPill($kind, $text);
}

// ------------------------------------------------------------------ scope picker (client, site, group, tag, device)

/**
 * The lists a scope picker offers, each capped (a larger fleet types an id into the "device" box). Built lazily: nothing here runs on a page without a picker.
 *
 * @return array{clients:array<int,string>,sites:array<int,string>,groups:array<int,string>,tags:array<int,string>,devices:array<int,string>,policies:array<int,string>}
 */
function rivetRmmAutoScopeData(\mysqli $mysqli, int $userId): array
{
    $rmm = rivetRmmModule($mysqli);
    $visible = $rmm->authorizer()->visibleClientIds($userId);
    $read = $rmm->readModel();
    $scope = '';
    if ($visible !== null) {
        $scope = $visible === [] ? ' AND 1 = 0' : ' AND %s IN (' . implode(',', array_map('intval', $visible)) . ')';
    }
    $out = ['clients' => [], 'sites' => [], 'groups' => [], 'tags' => [], 'devices' => [], 'policies' => []];
    $r = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL' . sprintf($scope, 'client_id') . ' ORDER BY client_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out['clients'][(int) $row['client_id']] = (string) $row['client_name'];
    }
    $r = mysqli_query($mysqli, 'SELECT l.location_id, l.location_name, c.client_name FROM locations l JOIN clients c ON c.client_id = l.location_client_id WHERE l.location_archived_at IS NULL'
        . sprintf($scope, 'l.location_client_id') . ' ORDER BY c.client_name, l.location_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out['sites'][(int) $row['location_id']] = (string) $row['client_name'] . ' / ' . (string) $row['location_name'];
    }
    foreach ($read->groups($visible) as $g) {
        $out['groups'][(int) $g['group_id']] = (string) $g['name'];
    }
    foreach ($read->tags($visible) as $t) {
        $out['tags'][(int) $t['tag_id']] = (string) $t['name'];
    }
    foreach ($read->listDevices([], $visible, 500, 0)['items'] as $d) {
        $out['devices'][(int) $d['device_id']] = (string) $d['hostname'];
    }
    foreach ($read->automation()?->policyStore()->all() ?? [] as $p) {
        $out['policies'][(int) $p['policy_id']] = (string) $p['name'];
    }

    return $out;
}

/** Text for a scope: "All devices", "Department Acme", "Tag VIP", "Device WIN1" ... Unknown ids read as "#id". */
function rivetRmmAutoScopeLabel(string $type, int $id, array $data): string
{
    $name = static fn (string $key, string $word) => $word . ' ' . ($data[$key][$id] ?? ('#' . $id));
    return match ($type) {
        'all', 'global' => $type === 'all' ? 'All devices' : 'Everything (global)',
        'client' => $name('clients', 'Department'),
        'site' => $name('sites', 'Site'),
        'group' => $name('groups', 'Group'),
        'tag' => $name('tags', 'Tag'),
        'device' => $name('devices', 'Device'),
        'policy' => $name('policies', 'Policy'),
        default => ucfirst($type) . ' #' . $id,
    };
}

/**
 * A scope picker: a "Applies to" select plus the matching list (department, site, group, tag, device, policy). Field names are `{prefix}_type` and
 * `{prefix}_id_{type}`; read it back with rivetRmmAutoReadScope(). js/rmm_automation.js shows only the list that matches the type (without JavaScript all are
 * visible, labelled).
 *
 * @param list<string> $types which scope types to offer, in order
 * @param array<string,array<int,string>> $data rivetRmmAutoScopeData()
 */
function rivetRmmAutoScopePicker(string $prefix, array $types, string $selType, int $selId, array $data, string $label = 'Applies to'): string
{
    $words = ['all' => 'All devices', 'global' => 'Everything', 'client' => 'A department', 'site' => 'A site', 'group' => 'A group', 'tag' => 'A tag', 'device' => 'One device', 'policy' => 'Devices of a policy'];
    $lists = ['client' => 'clients', 'site' => 'sites', 'group' => 'groups', 'tag' => 'tags', 'device' => 'devices', 'policy' => 'policies'];
    $o = '<div class="rmm-scope" data-rmm-scope="' . rmmH($prefix) . '"><label class="form-label" for="' . rmmH($prefix) . '_type">' . rmmH($label) . '</label>'
        . '<select class="form-select mb-2" id="' . rmmH($prefix) . '_type" name="' . rmmH($prefix) . '_type" data-rmm-scope-type="1">';
    foreach ($types as $t) {
        $o .= '<option value="' . rmmH($t) . '"' . ($t === $selType ? ' selected' : '') . '>' . rmmH($words[$t] ?? $t) . '</option>';
    }
    $o .= '</select>';
    foreach ($types as $t) {
        if (!isset($lists[$t])) {
            continue;
        }
        $opts = $data[$lists[$t]] ?? [];
        $id = $prefix . '_id_' . $t;
        $o .= '<div data-rmm-scope-for="' . rmmH($t) . '"><label class="visually-hidden" for="' . rmmH($id) . '">' . rmmH($words[$t] ?? $t) . '</label>';
        if ($opts === [] && $t !== 'device') {
            $o .= '<div class="form-text">None exist yet (or none you can see).</div>';
        } else {
            $o .= '<select class="form-select" id="' . rmmH($id) . '" name="' . rmmH($id) . '">';
            foreach ($opts as $oid => $oname) {
                $o .= '<option value="' . (int) $oid . '"' . ($t === $selType && $oid === $selId ? ' selected' : '') . '>' . rmmH($oname) . '</option>';
            }
            if ($t === $selType && $selId > 0 && !isset($opts[$selId])) {
                $o .= '<option value="' . $selId . '" selected>#' . $selId . '</option>';
            }
            $o .= '</select>';
        }
        $o .= '</div>';
    }

    return $o . '</div>';
}

/**
 * Read a scope picker back from $_POST.
 *
 * @param list<string> $types
 * @return array{type:string,id:int}
 */
function rivetRmmAutoReadScope(string $prefix, array $types): array
{
    $t = (string) ($_POST[$prefix . '_type'] ?? '');
    if (!in_array($t, $types, true)) {
        $t = $types[0] ?? 'all';
    }
    $id = in_array($t, ['all', 'global'], true) ? 0 : (int) ($_POST[$prefix . '_id_' . $t] ?? 0);

    return ['type' => $t, 'id' => $id];
}

// ------------------------------------------------------------------ form posts: the handlers' shared prologue and epilogue

/**
 * Where a form post may send the browser back to: an RMM page of this application, nothing else (no scheme, no host, no protocol-relative URL).
 */
function rivetRmmAutoSafeReturn(?string $to, string $default = '/agent/rmm_fleet.php'): string
{
    $to = (string) $to;
    if (preg_match('~^/agent/(rmm_[a-z_]+|asset_details)\.php(\?[A-Za-z0-9_=&%.\-\[\]+]*)?(#[A-Za-z0-9_-]*)?$~', $to) === 1 && !str_contains($to, '//')) {
        return $to;
    }

    return $default;
}

/** True when the client asked for JSON (the XHR helpers), not a page. */
function rivetRmmAutoWantsJson(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * Finish a form post. A browser form gets a flash message and a redirect back (to `$returnTo` after validation); a request that asks for JSON gets
 * {success, message|error, ...extra}. Never returns.
 *
 * @param array{ok:bool,status?:int,error?:string,data?:array<string,mixed>} $result an rivetRmmAutoApi() result, or ['ok'=>..,'error'=>..] of your own
 * @param array<string,mixed> $extra
 */
function rivetRmmAutoDone(array $result, string $okMessage, string $returnTo, array $extra = []): never
{
    $ok = (bool) $result['ok'];
    $message = $ok ? $okMessage : ((string) ($result['error'] ?? '') !== '' ? (string) $result['error'] : 'That did not work.');
    if (rivetRmmAutoWantsJson()) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        http_response_code($ok ? 200 : (int) ($result['status'] ?? 422));
        echo json_encode(['success' => $ok, ($ok ? 'message' : 'error') => $message] + $extra);
        exit;
    }
    flash_alert(nullable_htmlentities($message), $ok ? 'success' : 'error');
    redirect(rivetRmmAutoSafeReturn($returnTo));
}

/**
 * Prologue of a handler: CSRF (a failed token ends the request with the standard message), module on, sub-switch on, POST only. Returns the user.
 * A handler file begins like agent/post/rmm_agent.php (config, functions, check_login, load_global_settings, load_user_session, autoload, this file), then calls this.
 *
 * @return array{uid:int,name:string,return:string,perm:array<string,bool>}
 */
function rivetRmmAutoPostBegin(\mysqli $mysqli, string $feature, int $userId, string $userName): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $return = rivetRmmAutoSafeReturn($_POST['return'] ?? null);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        exit;   // validateCSRFToken() already redirected with the standard message
    }
    if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn($feature, $mysqli)) {
        rivetRmmAutoDone(['ok' => false, 'status' => 404, 'error' => (RIVET_RMM_AUTO_FEATURES[$feature] ?? 'This part of the endpoint agent') . ' is switched off.'], '', $return);
    }

    return ['uid' => $userId, 'name' => $userName, 'return' => $return, 'perm' => rivetRmmAutoPerm($mysqli, $userId)];
}

/** A whole number from $_POST, or null when empty/not numeric. */
function rivetRmmAutoInt(string $key): ?int
{
    $v = $_POST[$key] ?? null;

    return is_string($v) && preg_match('/^-?\d{1,12}$/', trim($v)) === 1 ? (int) trim($v) : null;
}

/** A trimmed string from $_POST (never an array). */
function rivetRmmAutoStr(string $key, int $max = 1000): string
{
    $v = $_POST[$key] ?? '';

    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
