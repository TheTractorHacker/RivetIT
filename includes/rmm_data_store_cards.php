<?php

/*
 * Administration > Endpoint agent > "RMM data store" card (RivetCore 1.0.0-rc.41, issue #89 phase 4; docs/rmm/POSTGRES.md section 13,
 * UPGRADING.md "Adopting the data store setting and migration"). Pure rendering, same shell/helpers as includes/rmm_scale_cards.php; every
 * control posts to admin/post/settings_endpoint_agent.php, which calls RivetCore\Rmm\Store\DataStoreSwitch / RmmModule::storeMigrator().
 *
 * MariaDB stays the default and this card never opens a PostgreSQL connection on its own - every number it shows (status(), connectionSettings())
 * is read from the control database. The password is never in $ctx: the form shows a dot-mask placeholder when one is stored and the field is
 * otherwise blank, exactly as DataStoreSwitch::saveConnection() requires (retype only when host/port/user/database changed).
 */

require_once __DIR__ . '/rmm_scale_cards.php';   // rivetRmmScaleCard(), rmmH(), rivetRmmUiPill(), rivetRmmUiDl(), rivetRmmScaleMsgHtml()

/**
 * @param array{status:array<string,mixed>,conn:?array<string,mixed>,msg:?array{type:string,text:string},old:array<string,mixed>,run:array<string,mixed>,run_log:list<array<string,mixed>>,module_on:bool,csrf:string,cred_meta:?array{changed_at:?string,changed_by:int}} $ctx
 */
function rivetRmmDataStoreCard(array $ctx): string
{
    $status = $ctx['status'];
    $conn = $ctx['conn'];
    $old = $ctx['old'];
    $csrf = '<input type="hidden" name="csrf_token" value="' . rmmH($ctx['csrf']) . '">';
    $kind = (string) $status['data_store'];
    $isPg = $kind === 'postgres';
    $val = static fn (string $k, $default = '') => array_key_exists($k, $old) ? (string) $old[$k] : (string) ($conn[$k] ?? $default);

    $msg = rivetRmmScaleMsgHtml($ctx['msg']);

    // ---- recommended same-server setup: no password stored at all (PostgresConfig and DataStoreSwitch both accept an empty
    // password - verified against a real server; peer authentication matches the connecting OS user to the role by name, and
    // PHP-FPM/cron/CLI all run as www-data on this box, so a role named exactly "www-data" needs no secret anywhere).
    $peerPanel = '<div class="alert alert-info py-2 mb-3"><p class="mb-2"><i class="fas fa-shield-alt me-1" aria-hidden="true"></i><strong>Recommended for a same-server PostgreSQL: no stored password.</strong> '
        . 'PostgreSQL authenticates the connecting Unix user by name (peer authentication) instead of a password, so nothing is sealed, stored or rotated for this connection at all.</p>'
        . '<p class="small mb-1">As the <code>postgres</code> OS user, for a <strong>new</strong> installation:</p>'
        . '<pre class="small bg-dark text-white p-2 rounded mb-2" style="white-space:pre-wrap">sudo -u postgres psql &lt;&lt;\'SQL\'
CREATE ROLE "www-data" LOGIN;
CREATE DATABASE rivet_rmm OWNER "www-data" ENCODING \'UTF8\' LC_COLLATE \'C.UTF-8\' LC_CTYPE \'C.UTF-8\' TEMPLATE template0;
\c rivet_rmm
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO "www-data";
SQL</pre>'
        . '<p class="small mb-1">Moving an <strong>existing</strong> password-based install off the password role (run as <code>postgres</code>; replace <code>rivet_rmm</code> with the current role name if different):</p>'
        . '<pre class="small bg-dark text-white p-2 rounded mb-2" style="white-space:pre-wrap">sudo -u postgres psql &lt;&lt;\'SQL\'
CREATE ROLE "www-data" LOGIN;
GRANT ALL ON DATABASE rivet_rmm TO "www-data";
\c rivet_rmm
ALTER DATABASE rivet_rmm OWNER TO "www-data";
REASSIGN OWNED BY rivet_rmm TO "www-data";
-- only after the card below is saved, tested and switched over to the new role:
-- DROP ROLE rivet_rmm;
SQL</pre>'
        . '<p class="small mb-1">Then, in <code>pg_hba.conf</code>, a <code>local</code> (unix socket) line for the new role instead of a password method, and reload PostgreSQL:</p>'
        . '<pre class="small bg-dark text-white p-2 rounded mb-0" style="white-space:pre-wrap">local   rivet_rmm   www-data                    peer
systemctl reload postgresql</pre>'
        . '<button type="button" class="btn btn-sm btn-outline-info mt-2" data-ds-fill-peer>Fill in the form below for this setup</button>'
        . '</div>';

    // ---- connection form
    $pwPlaceholder = ($conn['password_set'] ?? false) ? 'leave blank to keep the stored password' : 'leave blank for peer authentication (recommended, same server)';
    $form = '<form method="post" action="post.php" id="ds_conn_form" autocomplete="off">' . $csrf
        . '<div class="row g-2">'
        . '<div class="col-sm-6 col-lg-4"><label class="form-label small" for="ds_host">Host</label><input class="form-control form-control-sm" id="ds_host" name="ds_host" value="' . rmmH($val('host', '127.0.0.1')) . '" maxlength="255" placeholder="127.0.0.1 or /var/run/postgresql" required></div>'
        . '<div class="col-sm-3 col-lg-2"><label class="form-label small" for="ds_port">Port</label><input type="number" class="form-control form-control-sm" id="ds_port" name="ds_port" value="' . rmmH($val('port', '5432')) . '" min="1" max="65535" required></div>'
        . '<div class="col-sm-3 col-lg-3"><label class="form-label small" for="ds_db">Database</label><input class="form-control form-control-sm" id="ds_db" name="ds_db" value="' . rmmH($val('database')) . '" maxlength="63" required></div>'
        . '<div class="col-sm-6 col-lg-3"><label class="form-label small" for="ds_user">Role</label><input class="form-control form-control-sm" id="ds_user" name="ds_user" value="' . rmmH($val('user')) . '" maxlength="63" required></div>'
        . '<div class="col-sm-6 col-lg-4"><label class="form-label small" for="ds_pass">Password <span class="text-muted">(blank = peer auth, see above)</span></label><input type="password" class="form-control form-control-sm" id="ds_pass" name="ds_pass" autocomplete="new-password" placeholder="' . rmmH($pwPlaceholder) . '"></div>'
        . '<div class="col-sm-4 col-lg-2"><label class="form-label small" for="ds_sslmode">SSL mode</label><select class="form-select form-select-sm" id="ds_sslmode" name="ds_sslmode">'
        . implode('', array_map(static fn (string $m) => '<option value="' . $m . '"' . ($val('sslmode', 'prefer') === $m ? ' selected' : '') . '>' . $m . '</option>', ['disable', 'prefer', 'require', 'verify-ca', 'verify-full']))
        . '</select></div>'
        . '<div class="col-sm-4 col-lg-3"><label class="form-label small" for="ds_schema">Schema</label><input class="form-control form-control-sm" id="ds_schema" name="ds_schema" value="' . rmmH($val('schema', 'public')) . '" maxlength="63"></div>'
        . '<div class="col-sm-4 col-lg-3"><label class="form-label small" for="ds_rootcert">SSL root CA file (optional)</label><input class="form-control form-control-sm" id="ds_rootcert" name="ds_rootcert" value="' . rmmH($val('sslrootcert')) . '" maxlength="255"></div>'
        . '</div>'
        . '<p class="small text-muted mt-2 mb-2">Changing the host, port, role or database requires retyping the password (the stored one is only ever reused for the exact target it was saved for); a stored password reused for a host other than localhost/127.0.0.1/::1/a unix socket needs SSL mode <code>verify-full</code> or <code>verify-ca</code>. A connection with no password at all (peer authentication) is only usable over a unix socket (host starting with <code>/</code>).</p>'
        . '<button type="submit" name="save_datastore_connection" class="btn btn-outline-secondary btn-sm me-2"><i class="fas fa-save me-1" aria-hidden="true"></i>Save connection</button>'
        . '<button type="submit" name="test_datastore" class="btn btn-outline-primary btn-sm" data-ds-test><i class="fas fa-vial me-1" aria-hidden="true"></i>Test connection</button>'
        . '<div class="mt-2" data-ds-test-live aria-live="polite"></div>'
        . '</form>';

    // ---- status readout
    $connRows = [];
    $credMeta = $ctx['cred_meta'] ?? null;
    if ($conn !== null) {
        $connRows[] = ['Target', rmmH($conn['user'] . '@' . $conn['host'] . ':' . $conn['port'] . '/' . $conn['database']) . ' (schema ' . rmmH($conn['schema']) . ')'];
        $connRows[] = ['Last test', $conn['tested_at'] === null ? rivetRmmUiPill('off', 'Never tested') : ($conn['test_ok'] ? rivetRmmUiPill('ok', 'OK') . ' ' . rmmH($conn['tested_at']) . ' UTC' : rivetRmmUiPill('warn', 'Failed') . ' ' . rmmH($conn['tested_at']) . ' UTC')];
        $connRows[] = ['Storage mode', $conn['storage_mode'] === null ? '<span class="text-muted">not created yet</span>' : rmmH($conn['storage_mode'])];
        $connRows[] = ['Server version', $conn['server_version'] === null ? '<span class="text-muted">unknown</span>' : rmmH($conn['server_version'])];
        if (!empty($conn['password_set'])) {
            $changedAt = $credMeta['changed_at'] ?? null;
            $daysOld = $changedAt !== null ? (int) floor((time() - strtotime($changedAt . ' UTC')) / 86400) : null;
            $changedText = $changedAt === null ? '<span class="text-muted">unknown (saved before this was tracked)</span>' : rmmH($changedAt) . ' UTC (' . $daysOld . ' day' . ($daysOld === 1 ? '' : 's') . ' ago)';
            $connRows[] = ['Password', rivetRmmUiPill('warn', 'Stored') . ' credential last changed: ' . $changedText . ($daysOld !== null && $daysOld > 90 ? ' ' . rivetRmmUiPill('warn', 'Over 90 days - consider rotating') : '')];
        } else {
            $connRows[] = ['Password', rivetRmmUiPill('ok', 'Not stored (peer authentication)')];
        }
    } else {
        $connRows[] = ['Connection', '<span class="text-muted">none saved yet</span>'];
    }

    $switchBlock = '<div class="row"><div class="col-lg-6 mb-2"><h6 class="small text-uppercase text-muted">PostgreSQL connection</h6><div class="rmm-scale-dl">' . rivetRmmUiDl($connRows) . '</div></div>'
        . '<div class="col-lg-6 mb-2"><h6 class="small text-uppercase text-muted">Active data store</h6><div class="rmm-scale-dl">'
        . rivetRmmUiDl([['Store', rivetRmmUiPill($isPg ? 'ok' : 'off', $isPg ? 'PostgreSQL' : 'MariaDB') . ' <span class="text-muted">(default)</span>']])
        . '</div></div></div>';

    // ---- left-behind banner
    $leftBehind = '';
    if (($status['left_behind'] ?? null) !== null) {
        $lb = $status['left_behind'];
        $leftBehind = '<div class="alert alert-warning py-2 mb-3"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . rmmH($lb['message']) . '</div>';
    }

    // ---- switch buttons
    $noticeText = \RivetCore\Rmm\Store\DataStoreSwitch::NO_DATA_MOVE_NOTICE;
    $switchForms = '';
    if (!$isPg) {
        $switchForms = '<form method="post" action="post.php" class="border rounded p-3 mb-3">' . $csrf
            . '<p class="small mb-2"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>' . rmmH($noticeText) . '</p>'
            . '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="ds_ack" name="ds_ack_no_data_move" value="1" required><label class="form-check-label" for="ds_ack">I understand switching does not move existing history</label></div>'
            . '<button type="submit" name="switch_datastore_postgres" class="btn btn-primary btn-sm" ' . ($conn === null ? 'disabled' : '') . '><i class="fas fa-exchange-alt me-1" aria-hidden="true"></i>Switch to PostgreSQL</button>'
            . ($conn === null ? '<span class="small text-muted ms-2">Save and test a connection first.</span>' : '')
            . '</form>';
    } else {
        $switchForms = '<form method="post" action="post.php" class="mb-3">' . $csrf
            . '<button type="submit" name="switch_datastore_mariadb" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo me-1" aria-hidden="true"></i>Switch back to MariaDB</button>'
            . '<span class="small text-muted ms-2">Always allowed. History written to PostgreSQL meanwhile stays there until migrated.</span>'
            . '</form>';
    }

    // ---- change password (rotation): a separate action from "Save connection" - the new password is TESTED against the unchanged
    // host/port/user/database before the sealed value is replaced, never the other way round. Only shown once a password-based
    // connection is already stored; a peer-auth connection has nothing to rotate.
    $rotateBlock = '';
    if ($conn !== null && !empty($conn['password_set'])) {
        $rotateBlock = '<h6 class="mt-3">Change password</h6>'
            . '<p class="small text-muted">First change it in PostgreSQL (as the <code>postgres</code> user, or the current role itself):</p>'
            . '<pre class="small bg-dark text-white p-2 rounded mb-2" style="white-space:pre-wrap">ALTER ROLE ' . rmmH($conn['user']) . ' WITH PASSWORD \'&lt;new password&gt;\';</pre>'
            . '<form method="post" action="post.php" autocomplete="off" class="row g-2 align-items-end">' . $csrf
            . '<div class="col-sm-6 col-lg-4"><label class="form-label small" for="ds_newpass">New password</label><input type="password" class="form-control form-control-sm" id="ds_newpass" name="ds_newpass" autocomplete="new-password" required></div>'
            . '<div class="col-sm-6 col-lg-4"><button type="submit" name="change_datastore_password" class="btn btn-warning btn-sm"><i class="fas fa-key me-1" aria-hidden="true"></i>Test and change password</button></div>'
            . '</form>'
            . '<p class="small text-muted mt-1 mb-0">Tested against the stored host, port, role, database and SSL mode (unchanged) before the stored value is replaced. Nothing is saved if the test fails.</p>';
    }

    // ---- migration controls
    $migrateBlock = rivetRmmDataStoreMigrateBlock($status, $ctx['run'] ?? [], $ctx['run_log'] ?? [], $csrf, $kind);

    $body = $msg . $leftBehind . $peerPanel . '<h6>Connection</h6>' . $form . $rotateBlock . '<hr>' . $switchBlock . '<hr><h6>Switch</h6>' . $switchForms . '<hr>' . $migrateBlock;

    return rivetRmmScaleCard('datastore', 'database', 'RMM data store', $isPg, $body, $isPg ? 'PostgreSQL' : 'MariaDB (default)');
}

/** The "Migrate data / Roll back / Purge old" controls, state-aware. @param array<string,mixed> $run @param list<array<string,mixed>> $log */
function rivetRmmDataStoreMigrateBlock(array $status, array $run, array $log, string $csrf, string $kind): string
{
    $migration = $status['migration'] ?? null;
    $running = $migration !== null && in_array($migration['state'] ?? '', ['copying', 'dual_write', 'verified', 'failed'], true);
    $otherKind = $kind === 'postgres' ? 'mariadb' : 'postgres';
    $label = static fn (string $k): string => $k === 'postgres' ? 'PostgreSQL' : 'MariaDB';

    $out = '<h6>Migrate data</h6>';
    if ($migration === null) {
        $out .= '<p class="small text-muted">No migration has run yet. Moving history keeps it available after a switch; it is optional, and retention pauses on the active store for the duration.</p>';
    } else {
        $rows = [
            ['Last/current run', '#' . (int) $migration['run_id'] . ' ' . rmmH($migration['from_store'] . ' &rarr; ' . $migration['to_store'])],
            ['State', rivetRmmUiPill($migration['state'] === 'failed' ? 'warn' : ($running ? 'warn' : 'ok'), rmmH(str_replace('_', ' ', (string) $migration['state'])))],
            ['Dual-write', !empty($migration['dual_write']) ? rivetRmmUiPill('warn', 'On') . ' ' . (int) ($migration['dual_faults'] ?? 0) . ' fault(s)' : rivetRmmUiPill('off', 'Off')],
        ];
        if (!empty($migration['rollback_until'])) {
            $rows[] = ['Rollback available until', rmmH($migration['rollback_until']) . ' UTC'];
        }
        if (!empty($migration['last_error'])) {
            $rows[] = ['Last error', '<span class="text-danger">' . rmmH($migration['last_error']) . '</span>'];
        }
        $out .= '<div class="rmm-scale-dl mb-2">' . rivetRmmUiDl($rows) . '</div>';
    }

    if ($running) {
        $out .= '<form method="post" action="post.php" class="d-inline me-2">' . $csrf . '<input type="hidden" name="ds_run_id" value="' . (int) ($migration['run_id'] ?? 0) . '">'
            . '<button type="submit" name="migrate_datastore_resume" class="btn btn-outline-primary btn-sm"><i class="fas fa-play me-1" aria-hidden="true"></i>Resume</button></form>'
            . '<form method="post" action="post.php" class="d-inline me-2">' . $csrf
            . '<button type="submit" name="migrate_datastore_abort" class="btn btn-outline-danger btn-sm"><i class="fas fa-stop me-1" aria-hidden="true"></i>Abort</button></form>';
    } else {
        $out .= '<form method="post" action="post.php" class="d-inline me-2">' . $csrf
            . '<input type="hidden" name="ds_from" value="' . rmmH($kind) . '"><input type="hidden" name="ds_to" value="' . rmmH($otherKind) . '">'
            . '<button type="submit" name="migrate_datastore_dry_run" class="btn btn-outline-secondary btn-sm me-1"><i class="fas fa-eye me-1" aria-hidden="true"></i>Dry run (' . rmmH($label($kind)) . ' &rarr; ' . rmmH($label($otherKind)) . ')</button>'
            . '<button type="submit" name="migrate_datastore_start" class="btn btn-primary btn-sm"><i class="fas fa-play me-1" aria-hidden="true"></i>Migrate data</button></form>';
        if (($migration['state'] ?? null) === 'cutover' && !empty($migration['rollback_until'])) {
            $out .= '<form method="post" action="post.php" class="d-inline me-2">' . $csrf
                . '<button type="submit" name="migrate_datastore_rollback" class="btn btn-outline-warning btn-sm"><i class="fas fa-history me-1" aria-hidden="true"></i>Roll back</button></form>';
        }
    }
    $out .= '<form method="post" action="post.php" class="d-inline mt-2 d-block">' . $csrf
        . '<label class="form-label small" for="ds_purge_confirm">Purge the inactive store (type PURGE to confirm)</label>'
        . '<div class="input-group input-group-sm" style="max-width:360px"><input class="form-control" id="ds_purge_confirm" name="ds_purge_confirm" placeholder="PURGE"><button type="submit" name="migrate_datastore_purge" class="btn btn-outline-danger"><i class="fas fa-trash me-1" aria-hidden="true"></i>Purge old store</button></div>'
        . '<p class="small text-muted mb-0">Deletes the history of the store that is NOT active. Never automatic. Inside the rollback period this also needs confirmation below.</p></form>';

    if ($log !== []) {
        $out .= '<h6 class="mt-3">Progress</h6><div class="rmm-scale-scroll" style="max-height:220px;overflow:auto;font-family:monospace;font-size:.75rem" data-ds-progress-live aria-live="polite">';
        foreach ($log as $e) {
            $out .= '<div>' . rmmH(json_encode($e)) . '</div>';
        }
        $out .= '</div><form method="post" action="post.php" class="mt-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ds-progress-refresh>Refresh</button></form>';
    }

    return $out;
}
