<?php

require_once "includes/inc_all_admin.php";
require_once dirname(__DIR__) . '/vendor/autoload.php';

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Quality\InboundEvents;
use ITFlow\Quality\Outbox;
use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityLog;
use ITFlow\Quality\SyncHealth;
use ITFlow\Quality\SyncWorker;

$q_ready = QualityConfig::ready($mysqli);
$e = static fn($v): string => nullable_htmlentities((string) $v);
$csrf = $_SESSION['csrf_token'];

// A freshly created signing secret is shown once, then forgotten.
$q_new_secret = $_SESSION['quality_new_secret'] ?? null;
unset($_SESSION['quality_new_secret']);

if ($q_ready) {
    $q_row = QualityConfig::load($mysqli);
    $q_on = QualityConfig::moduleOn($mysqli);
    $q_features = QualityConfig::features($mysqli);
    $q_dept_ids = QualityConfig::departmentIds($mysqli);
    $q_health = SyncHealth::summary($mysqli);
    $q_issuer = IntegrationAuth::issuer();
    $q_depts = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
    $q_dead = Outbox::recent($mysqli, 'dead', 50);
    $q_recent = Outbox::recent($mysqli, null, 25);
    $q_inbound = InboundEvents::recent($mysqli, 25);
    $q_errors = QualityLog::recent($mysqli, 100);
    $badge = ['ok' => 'success', 'degraded' => 'warning', 'failing' => 'danger', 'off' => 'secondary', 'not_configured' => 'secondary'];
}
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clipboard-check me-2"></i>Quality (quality management system integration)</h3>
    </div>
    <div class="card-body">
        <p class="text-muted"><?= htmlspecialchars(APP_NAME) ?> does not become a quality management system. It shows each person their own items from your separate QMS (Datum) in the department portal,
            sends it training, completion and directory changes, and signs people in to it. Everything here is <strong>off until you turn it on</strong>.
            Setup steps are in <code>docs/integrations/qms-configuration.md</code>; the contract the QMS must implement is <code>docs/QUALITY_CONTRACT.md</code>.</p>

<?php if (!$q_ready) { ?>
        <div class="alert alert-warning mb-0">The database has not been updated for Quality yet (needs DB 2.6.230). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>.</div>
<?php } else { ?>

<?php if (is_array($q_new_secret)) { ?>
        <div class="alert alert-warning" role="alert" data-new-secret="1">
            <strong>New <?= $e($q_new_secret['which']) ?> signing secret</strong> (copy it now; it is not shown again):
            <div class="input-group mt-2"><input type="text" class="form-control font-monospace" readonly value="<?= $e($q_new_secret['value']) ?>" aria-label="New signing secret"></div>
            <div class="form-text">Give it to the QMS as its <?= $q_new_secret['which'] === 'outbound' ? 'verification secret for events from ' . APP_NAME : 'signing secret for events it sends to ' . APP_NAME ?>.</div>
        </div>
<?php } ?>

        <!-- Health -->
        <div id="health" class="border rounded p-3 mb-4">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h4 class="h5 mb-0">Sync health</h4>
                <span class="badge bg-<?= $badge[$q_health['state']] ?? 'secondary' ?>"><?= $e(str_replace('_', ' ', $q_health['state'])) ?></span>
                <span class="text-muted"><?= $e($q_health['headline']) ?></span>
            </div>
            <?php if ($q_health['reasons']) { ?><ul class="mb-2"><?php foreach ($q_health['reasons'] as $why) { ?><li><?= $e($why) ?></li><?php } ?></ul><?php } ?>
            <?php $f = $q_health['facts']; if (!empty($f['outbox'])) { ?>
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted small">Last QMS check</div><div><?= $e($f['last_health_ok_at'] ?? 'never') ?></div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted small">Events waiting</div><div><?= (int) $f['outbox']['pending'] ?><?= $f['outbox']['oldest_pending_seconds'] !== null ? ' (oldest ' . $e(SyncHealth::age($f['outbox']['oldest_pending_seconds'])) . ')' : '' ?></div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted small">Dead-lettered</div><div><?= (int) $f['outbox']['dead'] ?></div></div></div>
                <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="text-muted small">Errors, last 24 h</div><div><?= (int) $f['errors_24h'] ?></div></div></div>
            </div>
            <div class="table-responsive mb-3"><table class="table table-sm table-striped mb-0">
                <thead><tr><th>Stream</th><th>Position</th><th>Last ok</th><th>Events queued</th><th>Problem</th></tr></thead>
                <tbody><?php foreach ($f['streams'] as $name => $s) { ?>
                    <tr><td><?= $e($name) ?></td><td class="text-break"><?= $e($s['cursor'] ?? 'not started') ?></td><td><?= $e($s['last_ok_at'] ?? '') ?></td><td><?= (int) $s['events_total'] ?></td><td><?= $e($s['last_error'] ?? '') ?></td></tr>
                <?php } ?></tbody></table></div>
            <?php } ?>
            <form action="post.php" method="post" class="d-flex flex-wrap gap-2">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <button class="btn btn-primary" name="run_quality_sync" value="1" <?= $q_on ? '' : 'disabled' ?>><i class="fas fa-sync me-2"></i>Run sync now</button>
                <button class="btn btn-outline-secondary js-confirm-native" data-confirm-variant="primary" data-confirm-message="Queue one event for every assignment, completion, person and department? This can be a lot of events." name="quality_full_resync" value="1" <?= $q_on ? '' : 'disabled' ?>><i class="fas fa-redo me-2"></i>Queue full resync</button>
            </form>
        </div>

        <!-- Module and tuning -->
        <form action="post.php" method="post" autocomplete="off" class="border rounded p-3 mb-4" id="module">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <h4 class="h5">Module and schedule</h4>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="qModule" name="module_enabled" value="1"<?= $q_on ? ' checked' : '' ?>>
                <label class="form-check-label" for="qModule"><strong>Quality is on</strong>
                    <span class="text-muted">(off: the portal pages, the event endpoint, the scheduled sync and sign-in to the QMS all stop at once; settings and history are kept; Training is not affected)</span></label>
            </div>
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="qEvents" name="events_enabled" value="1"<?= (int) $q_row['qs_events_enabled'] === 1 ? ' checked' : '' ?>>
                <label class="form-check-label" for="qEvents">Send events to the QMS <span class="text-muted">(assignments, completions, people, departments; signed; needs the outbound secret)</span></label>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="qInbound" name="inbound_enabled" value="1"<?= (int) $q_row['qs_inbound_enabled'] === 1 ? ' checked' : '' ?>>
                <label class="form-check-label" for="qInbound">Accept events from the QMS <span class="text-muted">(<code>POST /api/v1/integrations/qms/events</code>; signed; needs the inbound secret and an API client with <code>quality:events</code>)</span></label>
            </div>
            <div class="row g-3">
                <div class="col-6 col-md-3"><label class="form-label" for="qSync">Sync every (minutes)</label><input id="qSync" type="number" class="form-control" name="sync_interval" min="1" max="1440" value="<?= (int) $q_row['qs_sync_interval_minutes'] ?>"></div>
                <div class="col-6 col-md-3"><label class="form-label" for="qRec">Reconcile every (hours)</label><input id="qRec" type="number" class="form-control" name="reconcile_interval" min="1" max="720" value="<?= (int) $q_row['qs_reconcile_interval_hours'] ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="qReauth">Re-sign-in age (min)</label><input id="qReauth" type="number" class="form-control" name="reauth_minutes" min="1" max="120" value="<?= (int) $q_row['qs_reauth_minutes'] ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="qCt">Connect timeout (s)</label><input id="qCt" type="number" class="form-control" name="connect_timeout" min="1" max="15" value="<?= (int) $q_row['qs_connect_timeout_s'] ?>"></div>
                <div class="col-6 col-md-2"><label class="form-label" for="qTt">Total timeout (s)</label><input id="qTt" type="number" class="form-control" name="total_timeout" min="2" max="60" value="<?= (int) $q_row['qs_total_timeout_s'] ?>"></div>
            </div>
            <div class="form-text mb-2">Acknowledging a document or answering an action needs a sign-in younger than "Re-sign-in age", or the person's password again.</div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="qAll" name="all_departments" value="1"<?= (int) $q_row['qs_all_departments'] === 1 ? ' checked' : '' ?>>
                <label class="form-check-label" for="qAll">All departments may use Quality <span class="text-muted">(otherwise only the departments ticked below)</span></label>
            </div>
            <button class="btn btn-primary" name="save_quality_module" value="1"><i class="fas fa-check me-2"></i>Save</button>
        </form>

        <!-- Connection -->
        <form action="post.php" method="post" autocomplete="off" class="border rounded p-3 mb-4" id="connection">
            <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
            <h4 class="h5">Connection to the QMS</h4>
            <div class="row g-3">
                <div class="col-12 col-md-6"><label class="form-label" for="qUrl">QMS address</label>
                    <input id="qUrl" class="form-control" name="qms_url" maxlength="500" placeholder="https://quality.example.com" value="<?= $e($q_row['qs_qms_url']) ?>">
                    <div class="form-text">https only. Checked when saved and again before every call. A QMS on a private network must be inside an allowed internal network (Webhooks settings).</div></div>
                <div class="col-12 col-md-6"><label class="form-label" for="qTok">Token address (optional)</label>
                    <input id="qTok" class="form-control" name="token_url" maxlength="500" placeholder="default: &lt;address&gt;/api/v1/oauth/token" value="<?= $e($q_row['qs_token_url']) ?>">
                    <div class="form-text">Must be on the same host as the QMS.</div></div>
                <div class="col-12 col-md-4"><label class="form-label" for="qCid">Client id</label><input id="qCid" class="form-control" name="client_id" maxlength="190" value="<?= $e($q_row['qs_client_id']) ?>"></div>
                <div class="col-12 col-md-4"><label class="form-label" for="qSec">Client secret</label>
                    <input id="qSec" type="password" class="form-control" name="client_secret" maxlength="512" autocomplete="new-password" placeholder="<?= (string) $q_row['qs_client_secret_enc'] !== '' ? 'saved (leave blank to keep)' : 'not set' ?>">
                    <?php if ((string) $q_row['qs_client_secret_enc'] !== '') { ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_secret" value="1" id="qClr"><label class="form-check-label" for="qClr">Remove the saved secret</label></div><?php } ?></div>
                <div class="col-12 col-md-4"><label class="form-label" for="qScope">Scope</label><input id="qScope" class="form-control" name="scope" maxlength="255" value="<?= $e($q_row['qs_scope']) ?>"></div>
            </div>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <button class="btn btn-primary" name="save_quality_connection" value="1"><i class="fas fa-check me-2"></i>Save connection</button>
                <button class="btn btn-outline-primary" name="test_quality_connection" value="1"><i class="fas fa-plug me-2"></i>Test connection</button>
            </div>
            <?php if ($q_row['qs_last_test_at']) { ?>
                <p class="mt-2 mb-0 small"><span class="badge bg-<?= (int) $q_row['qs_last_test_ok'] === 1 ? 'success' : 'danger' ?>"><?= (int) $q_row['qs_last_test_ok'] === 1 ? 'passed' : 'failed' ?></span>
                    Last test <?= $e($q_row['qs_last_test_at']) ?> (<?= (int) $q_row['qs_last_test_ms'] ?> ms): <?= $e($q_row['qs_last_test_message']) ?></p>
            <?php } ?>
        </form>

        <!-- Signing secrets -->
        <div class="border rounded p-3 mb-4" id="secrets">
            <h4 class="h5">Signing secrets</h4>
            <p class="text-muted mb-2">Events are signed with HMAC-SHA256 (<code>X-Rivet-Signature-V2: t=&lt;time&gt;,v1=&lt;hex&gt;</code>). One secret per direction. A new secret replaces the old one at once; it is shown once.</p>
            <div class="row g-3">
                <?php foreach (['outbound' => [APP_NAME . ' to QMS', 'qs_outbound_secret_enc'], 'inbound' => ['QMS to ' . APP_NAME, 'qs_inbound_secret_enc']] as $which => [$label, $col]) { ?>
                <div class="col-12 col-md-6"><form action="post.php" method="post" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="which" value="<?= $e($which) ?>">
                    <span><strong><?= $e($label) ?></strong>: <span class="badge bg-<?= (string) $q_row[$col] !== '' ? 'success' : 'secondary' ?>"><?= (string) $q_row[$col] !== '' ? 'set' : 'not set' ?></span></span>
                    <button class="btn btn-outline-secondary btn-sm" name="new_quality_secret" value="1"><?= (string) $q_row[$col] !== '' ? 'Replace' : 'Create' ?></button>
                </form></div>
                <?php } ?>
            </div>
        </div>

        <!-- Access: departments and features -->
        <div class="row g-4 mb-4" id="access">
            <div class="col-12 col-lg-6"><form action="post.php" method="post" class="border rounded p-3 h-100">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <h4 class="h5">Departments</h4>
                <p class="text-muted small">People in a ticked department may use Quality (when the module is on). A person's department is the one on their contact record.</p>
                <div class="mb-3">
                <?php while ($q_depts && ($d = mysqli_fetch_assoc($q_depts))) { ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="departments[]" value="<?= (int) $d['client_id'] ?>" id="qd<?= (int) $d['client_id'] ?>"<?= in_array((int) $d['client_id'], $q_dept_ids, true) ? ' checked' : '' ?>><label class="form-check-label" for="qd<?= (int) $d['client_id'] ?>"><?= $e($d['client_name']) ?></label></div>
                <?php } ?>
                </div>
                <button class="btn btn-primary" name="save_quality_departments" value="1"><i class="fas fa-check me-2"></i>Save departments</button>
            </form></div>
            <div class="col-12 col-lg-6"><form action="post.php" method="post" class="border rounded p-3 h-100">
                <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                <h4 class="h5">Features</h4>
                <p class="text-muted small">Each part of Quality is separately switched. Corrective-action details never appear on a shared (kiosk) screen.</p>
                <?php foreach (QualityConfig::FEATURES as $key => $label) { ?>
                    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="features[]" value="<?= $e($key) ?>" id="qf<?= $e($key) ?>"<?= !empty($q_features[$key]) ? ' checked' : '' ?>><label class="form-check-label" for="qf<?= $e($key) ?>"><?= $e($label) ?></label></div>
                <?php } ?>
                <button class="btn btn-primary mt-3" name="save_quality_features" value="1"><i class="fas fa-check me-2"></i>Save features</button>
            </form></div>
        </div>

        <!-- Events -->
        <div class="border rounded p-3 mb-4" id="events">
            <h4 class="h5">Events</h4>
            <?php if ($q_dead) { ?>
                <form action="post.php" method="post" class="mb-2"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <button class="btn btn-warning btn-sm" name="replay_quality_dead" value="1"><i class="fas fa-redo me-1"></i>Replay all <?= count($q_dead) ?> dead event(s)</button></form>
            <?php } ?>
            <h5 class="h6 mt-3">Outbound (latest 25)</h5>
            <div class="table-responsive"><table class="table table-sm table-striped">
                <thead><tr><th>Id</th><th>Type</th><th>Resource</th><th>Status</th><th>Tries</th><th>Last error</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($q_recent as $o) { ?>
                    <tr><td>#<?= (int) $o['qo_id'] ?></td><td><?= $e($o['qo_type']) ?></td><td><?= $e($o['qo_resource_type'] . ' ' . $o['qo_resource_id']) ?></td>
                        <td><span class="badge bg-<?= ['delivered' => 'success', 'pending' => 'secondary', 'dead' => 'danger'][$o['qo_status']] ?? 'secondary' ?>"><?= $e($o['qo_status']) ?></span></td>
                        <td><?= (int) $o['qo_attempts'] ?></td><td><?= $e($o['qo_last_error'] ?? '') ?></td><td><?= $e($o['qo_created_at']) ?></td>
                        <td><?php if ($o['qo_status'] === 'dead') { ?><form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="event_id" value="<?= (int) $o['qo_id'] ?>"><button class="btn btn-outline-secondary btn-sm" name="replay_quality_dead" value="1">Replay</button></form><?php } ?></td></tr>
                <?php } if (!$q_recent) { ?><tr><td colspan="8" class="text-muted text-center">No events yet.</td></tr><?php } ?>
                </tbody></table></div>
            <h5 class="h6 mt-3">Inbound (latest 25)</h5>
            <div class="table-responsive"><table class="table table-sm table-striped mb-0">
                <thead><tr><th>Received</th><th>Type</th><th>Event id</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                <?php foreach ($q_inbound as $i) { ?>
                    <tr><td><?= $e($i['qi_received_at']) ?></td><td><?= $e($i['qi_type']) ?></td><td class="text-break"><?= $e($i['qi_event_id']) ?></td><td><?= $e($i['qi_status']) ?></td><td><?= $e($i['qi_error'] ?? '') ?></td></tr>
                <?php } if (!$q_inbound) { ?><tr><td colspan="5" class="text-muted text-center">No inbound events yet.</td></tr><?php } ?>
                </tbody></table></div>
        </div>

        <!-- Error log -->
        <div class="border rounded p-3" id="errors">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h4 class="h5 mb-0">Integration error log</h4>
                <form action="post.php" method="post" class="ms-auto"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
                    <button class="btn btn-outline-danger btn-sm js-confirm-native" data-confirm-message="Clear the error log?" name="clear_quality_errors" value="1"><i class="fas fa-trash me-1"></i>Clear</button></form>
            </div>
            <p class="text-muted small">No secrets, tokens or personal data are written here. The correlation id is the same one the QMS logged. Entries are kept <?= (int) QualityLog::KEEP_DAYS ?> days.</p>
            <div class="table-responsive"><table class="table table-sm table-striped mb-0">
                <thead><tr><th>When</th><th>Area</th><th>Code</th><th>HTTP</th><th>Message</th><th>Correlation id</th></tr></thead>
                <tbody>
                <?php foreach ($q_errors as $x) { ?>
                    <tr><td><?= $e($x['qel_at']) ?></td><td><?= $e($x['qel_area']) ?></td><td><?= $e($x['qel_code']) ?></td><td><?= $e($x['qel_http_status'] ?? '') ?></td><td><?= $e($x['qel_message']) ?></td><td class="text-break"><code><?= $e($x['qel_correlation_id'] ?? '') ?></code></td></tr>
                <?php } if (!$q_errors) { ?><tr><td colspan="6" class="text-muted text-center">No errors.</td></tr><?php } ?>
                </tbody></table></div>
        </div>
<?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";
