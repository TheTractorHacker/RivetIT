<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Form posts of the script library, schedules, approvals and the "Run a library script" card of the asset page (RMM Phase 2, RivetCore 1.0.0-rc.10):
 * save_script, retire_script, restore_script, resign_script, run_script (a target, or one device), save_schedule, toggle_schedule, delete_schedule,
 * decide_approval, cancel_approval.
 *
 * A direct endpoint like agent/post/rmm_agent.php. The prologue (rivetRmmAutoPostBegin) checks POST, the CSRF token, the module and the `scripts` sub-switch; every
 * action then calls RivetCore's technician API as the signed-in user, which decides again (403/404 rules, client scope, audit). The form fields are converted into
 * the exact typed JSON Core wants (ints as ints, parameters as an object). Core's messages are shown as they are: they are written for users. A secret parameter
 * value is passed on and forgotten: it is never written to a message, a log line or the session.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_bootstrap.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_scr_ui.php';

$ctx = rivetRmmAutoPostBegin($mysqli, 'scripts', (int) $session_user_id, (string) $session_name);
$api = static fn (string $method, array $seg, array $query = [], ?array $body = null): array => rivetRmmAutoApi($mysqli, $ctx['uid'], $ctx['name'], $method, $seg, $query, $body);
$ret = $ctx['return'];
$id = static fn (string $k): int => rivetRmmAutoInt($k) ?? 0;
$approvalsUrl = static fn (?int $approvalId): string => '/agent/rmm_approvals.php?state=pending_approval' . ($approvalId ? '#approval-' . $approvalId : '');

/** The parameter definition of a script version (the current one when `$version` is 0), or null when it cannot be read. */
$schemaOf = static function (int $scriptId, int $version) use ($api): ?array {
    $v = $api('GET', ['scripts', $scriptId, 'versions']);
    if (!$v['ok']) {
        return null;
    }
    $list = (array) ($v['data']['data'] ?? []);
    if ($version <= 0) {
        $s = $api('GET', ['scripts', $scriptId]);
        $version = (int) ($s['data']['script']['current_version'] ?? 0);
    }
    foreach ($list as $row) {
        if ((int) $row['version'] === $version) {
            return (array) $row['params'];
        }
    }

    return null;
};

switch (rivetRmmAutoStr('action', 40)) {
    case 'save_script':
        $sid = $id('script_id');
        $body = rivetRmmScrScriptBodyFromPost($sid === 0);
        if ($sid === 0) {
            $r = $api('POST', ['scripts'], [], $body);
        } else {
            $before = $api('GET', ['scripts', $sid]);
            $r = $api('PUT', ['scripts', $sid], [], $body);
        }
        if (!$r['ok']) {
            $_SESSION['rmm_scr_draft'] = ['script_id' => $sid, 'draft' => array_diff_key($body, ['params' => 1]) + ['current' => ['body' => $body['body'], 'params' => $body['params']]]];
            rivetRmmAutoDone($r, '', $sid === 0 ? '/agent/rmm_script_library.php?new=1' : '/agent/rmm_script_library.php?script_id=' . $sid);
        }
        $new = (array) ($r['data']['script'] ?? []);
        $nid = (int) ($new['script_id'] ?? $sid);
        $ver = (int) ($new['current_version'] ?? 0);
        if ($sid === 0) {
            $msg = 'Script published as version ' . $ver . '.';
        } elseif ($ver !== (int) ($before['data']['script']['current_version'] ?? $ver)) {
            $msg = 'Script saved as a new version (' . $ver . ').';
        } else {
            $msg = 'Settings saved. The script text and parameters are unchanged, so no new version was made.';
        }
        rivetRmmAutoDone($r, $msg, '/agent/rmm_script_library.php?script_id=' . $nid);

    case 'retire_script':
    case 'restore_script':
        $retire = $_POST['action'] === 'retire_script';
        $r = $retire ? $api('DELETE', ['scripts', $id('script_id')]) : $api('POST', ['scripts', $id('script_id'), 'restore'], [], []);
        rivetRmmAutoDone($r, $retire ? 'Script retired.' : 'Script restored.', $ret);

    case 'resign_script':
        $r = $api('POST', ['scripts', $id('script_id'), 'resign'], [], []);
        rivetRmmAutoDone($r, 'Signed ' . (int) ($r['data']['versions'] ?? 0) . ' version(s) again with the current signing key.', $ret);

    case 'run_script':
        $sid = $id('library_script_id') ?: $id('script_id');
        $ver = $id('script_version');
        $schema = $schemaOf($sid, $ver);
        if ($schema === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 404, 'error' => 'Script or version not found.'], '', $ret);
        }
        $body = ['library_script_id' => $sid, 'params' => (object) rivetRmmScrReadParams($schema), 'confirm' => !empty($_POST['confirm'])];
        if ($ver > 0) {
            $body['script_version'] = $ver;
        }
        $t = rivetRmmAutoStr('timeout_s', 8);
        if ($t !== '') {
            $body['timeout_s'] = preg_match('/^\d{1,6}$/', $t) === 1 ? (int) $t : $t;
        }
        $device = $id('device_id');
        if ($device > 0) {
            $r = $api('POST', [$device, 'jobs'], [], $body);
        } else {
            $sc = rivetRmmAutoReadScope('tgt', RIVET_RMM_SCR_TARGETS);
            $body['target'] = ['type' => $sc['type'], 'id' => $sc['id']];
            $r = $api('POST', ['runs'], [], $body);
        }
        if (!$r['ok']) {
            rivetRmmAutoDone($r, '', $ret);
        }
        if ($r['status'] === 202) {
            $n = (int) ($r['data']['device_count'] ?? 1);
            rivetRmmAutoDone($r, 'This run needs a second person to approve it before anything runs (' . $n . ' device' . ($n === 1 ? '' : 's') . '). It is waiting under Approvals.', $approvalsUrl((int) ($r['data']['approval_id'] ?? 0)));
        }
        if ($device > 0) {
            rivetRmmAutoDone($r, 'Job queued. The device runs it the next time it checks in.', $ret);
        }
        $d = (array) $r['data'];
        $msg = (int) ($d['created'] ?? 0) . ' job' . ((int) ($d['created'] ?? 0) === 1 ? '' : 's') . ' queued for ' . (int) ($d['device_count'] ?? 0) . ' device' . ((int) ($d['device_count'] ?? 0) === 1 ? '' : 's') . '.';
        $skipped = (int) ($d['skipped_denied'] ?? 0) + (int) ($d['skipped_error'] ?? 0);
        if ($skipped > 0) {
            $msg .= ' ' . $skipped . ' skipped' . (!empty($d['errors'][0]) ? ': ' . implode('; ', array_map('strval', array_slice((array) $d['errors'], 0, 3))) : '.');
        }
        rivetRmmAutoDone($r, $msg, $ret);

    case 'save_schedule':
        $sid = $id('schedule_id');
        $scriptId = $id('library_script_id');
        $ver = $id('script_version');
        $schema = $schemaOf($scriptId, $ver);
        if ($schema === null) {
            rivetRmmAutoDone(['ok' => false, 'status' => 404, 'error' => 'Script or version not found.'], '', $ret);
        }
        $sc = rivetRmmAutoReadScope('tgt', RIVET_RMM_SCR_TARGETS);
        $paramVals = rivetRmmScrReadParams($schema);
        $body = ['name' => rivetRmmAutoStr('name', 200), 'library_script_id' => $scriptId, 'params' => (object) $paramVals, 'target' => ['type' => $sc['type'], 'id' => $sc['id']],
            'overlap' => rivetRmmAutoStr('overlap', 8), 'jitter_s' => max(0, ($id('jitter_min')) * 60), 'enabled' => !empty($_POST['enabled']), 'confirm' => !empty($_POST['confirm'])];
        if ($ver > 0) {
            $body['script_version'] = $ver;
        }
        $to = rivetRmmAutoStr('timeout_s', 8);
        if ($to !== '') {
            $body['timeout_s'] = preg_match('/^\d{1,6}$/', $to) === 1 ? (int) $to : $to;
        }
        $eh = rivetRmmAutoStr('expires_h', 10);
        if ($eh !== '' && is_numeric($eh)) {
            $body['expires_s'] = (int) round((float) $eh * 3600);
        }
        $kind = rivetRmmAutoStr('kind', 10);
        $timing = ['kind' => $kind];
        if ($kind === 'interval') {
            $unit = ['minute' => 60, 'hour' => 3600, 'day' => 86400][rivetRmmAutoStr('interval_unit', 8)] ?? 60;
            $timing['interval_s'] = $id('interval_value') * $unit;
        } else {
            $timing['cron'] = rivetRmmAutoStr('cron', 100);
        }
        $startIn = rivetRmmAutoStr('start_in_min', 8);
        if ($startIn !== '' && preg_match('/^\d{1,6}$/', $startIn) === 1) {
            $timing['start_in_s'] = (int) $startIn * 60;
        }
        if ($sid > 0) {
            // Core re-times a schedule whenever a timing field is sent, so send the timing only when it really changed
            $cur = $api('GET', ['schedules', $sid]);
            $c = (array) ($cur['data']['schedule'] ?? []);
            $same = ($c['kind'] ?? '') === $kind && ($kind === 'interval' ? (int) ($c['interval_s'] ?? 0) === (int) ($timing['interval_s'] ?? -1) : (string) ($c['cron'] ?? '') === preg_replace('/\s+/', ' ', (string) ($timing['cron'] ?? '')));
            if ($same && !isset($timing['start_in_s'])) {
                $timing = [];
            }
        }
        $body += $timing;
        $r = $api($sid > 0 ? 'PUT' : 'POST', $sid > 0 ? ['schedules', $sid] : ['schedules'], [], $body);
        if (!$r['ok']) {
            // keep what was typed so the form comes back filled in; a secret parameter is never kept (not even a literal one typed by mistake)
            $keep = $paramVals;
            foreach ($schema as $def) {
                if (($def['type'] ?? '') === 'secret') {
                    unset($keep[(string) $def['name']]);
                }
            }
            $_SESSION['rmm_scr_sched_draft'] = ['schedule_id' => $sid, 'draft' => ['name' => $body['name'], 'script_id' => $scriptId, 'script_version' => $ver, 'params' => $keep, 'target' => $body['target'], 'kind' => $kind,
                'interval_s' => $timing['interval_s'] ?? null, 'cron' => $timing['cron'] ?? '', 'jitter_s' => $body['jitter_s'], 'overlap' => $body['overlap'], 'expires_s' => $body['expires_s'] ?? null,
                'timeout_s' => $body['timeout_s'] ?? null, 'enabled' => $body['enabled']]];
            rivetRmmAutoDone($r, '', $sid > 0 ? '/agent/rmm_schedules.php?schedule_id=' . $sid . '&edit=1' : '/agent/rmm_schedules.php?new=1');
        }
        $nid = (int) ($r['data']['schedule']['schedule_id'] ?? $sid);
        if (($r['data']['state'] ?? '') === 'pending_approval') {
            rivetRmmAutoDone($r, 'Schedule saved. It does not run until a second person approves it (it is waiting under Approvals).', $approvalsUrl((int) ($r['data']['approval_id'] ?? 0)));
        }
        rivetRmmAutoDone($r, 'Schedule saved.', '/agent/rmm_schedules.php?schedule_id=' . $nid);

    case 'toggle_schedule':
        $on = ($_POST['enabled'] ?? '') === '1';
        $r = $api('PATCH', ['schedules', $id('schedule_id')], [], ['enabled' => $on]);
        rivetRmmAutoDone($r, $on ? 'Schedule resumed.' : 'Schedule paused.', $ret);

    case 'delete_schedule':
        $r = $api('DELETE', ['schedules', $id('schedule_id')]);
        rivetRmmAutoDone($r, 'Schedule deleted.', '/agent/rmm_schedules.php');

    case 'decide_approval':
        $approve = ($_POST['decision'] ?? '') === 'approve';
        if (!$approve && ($_POST['decision'] ?? '') !== 'reject') {
            rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Choose Approve or Reject.'], '', $ret);
        }
        $r = $api('POST', ['approvals', $id('approval_id'), $approve ? 'approve' : 'reject'], [], ['note' => rivetRmmAutoStr('note', 300)]);
        if (!$r['ok']) {
            rivetRmmAutoDone($r, '', $ret);
        }
        $res = (array) ($r['data']['result'] ?? []);
        if (!$approve) {
            rivetRmmAutoDone($r, 'Request rejected.', $ret);
        }
        if (isset($res['created'])) {
            $msg = 'Approved. ' . (int) $res['created'] . ' job' . ((int) $res['created'] === 1 ? '' : 's') . ' queued' . (isset($res['device_count']) ? ' for ' . (int) $res['device_count'] . ' device' . ((int) $res['device_count'] === 1 ? '' : 's') : '') . '.';
            $sk = (int) ($res['skipped_denied'] ?? 0) + (int) ($res['skipped_error'] ?? 0);
            $msg .= $sk > 0 ? ' ' . $sk . ' skipped.' : '';
        } else {
            $msg = isset($res['schedule_id']) ? 'Approved. The schedule can run now.' : 'Approved.';
        }
        rivetRmmAutoDone($r, $msg, $ret);

    case 'cancel_approval':
        $r = $api('POST', ['approvals', $id('approval_id'), 'cancel'], [], []);
        rivetRmmAutoDone($r, 'Request cancelled. Nothing is run.', $ret);
}
rivetRmmAutoDone(['ok' => false, 'status' => 422, 'error' => 'Unknown action.'], '', $ret);
