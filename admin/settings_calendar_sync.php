<?php
require_once "includes/inc_all_admin.php";

$callback_url = "https://$config_base_url/agent/outlook_calendar_callback.php";
$configured   = !empty($config_outlook_cal_client_id) && !empty($config_outlook_cal_client_secret) && !empty($config_outlook_cal_tenant_id);
?>

<!-- ── Credentials Card ─────────────────────────────────────── -->
<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fab fa-microsoft me-2"></i>Outlook Calendar Sync — Azure App Credentials</h3>
    </div>
    <div class="card-body">

        <?php if ($configured) { ?>
        <div class="alert alert-success py-2"><i class="fas fa-check-circle me-2"></i>Credentials saved. Users can now connect their Outlook calendars from their profile.</div>
        <?php } ?>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Tenant ID <small class="text-muted">(Directory ID)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-building"></i></span></div>
                    <input type="text" class="form-control" name="outlook_cal_tenant_id"
                           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                           value="<?= nullable_htmlentities($config_outlook_cal_tenant_id) ?>">
                </div>
                <small class="text-muted">Found in Azure Portal → App registrations → your app → Overview</small>
            </div>

            <div class="form-group">
                <label>Application (Client) ID</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-fingerprint"></i></span></div>
                    <input type="text" class="form-control" name="outlook_cal_client_id"
                           placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                           value="<?= nullable_htmlentities($config_outlook_cal_client_id) ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Client Secret</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-key"></i></span></div>
                    <input type="password" class="form-control" name="outlook_cal_client_secret"
                           placeholder="<?= $config_outlook_cal_client_secret ? '(saved — paste new value to change)' : 'Paste secret value here' ?>"
                           autocomplete="new-password">
                </div>
                <small class="text-muted">The secret <strong>value</strong> (not the secret ID). Only shown once in Azure — copy it immediately after creating.</small>
            </div>

            <div class="form-group">
                <label>Redirect URI <small class="text-muted">(copy this into Azure)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-link"></i></span></div>
                    <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($callback_url, ENT_QUOTES) ?>" readonly id="redirect_uri_field">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-outline-secondary js-copy-redirect-uri">Copy</button>
                    </div>
                </div>
            </div>

            <button type="submit" name="save_outlook_cal_settings" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Credentials</button>
            <?php if ($configured) { ?>
            <a href="post.php?clear_outlook_cal_settings=1&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger ms-2 confirm-link"><i class="fas fa-trash me-1"></i>Clear</a>
            <?php } ?>
        </form>
    </div>
</div>

<!-- ── Bulk Sync Card ───────────────────────────────────────── -->
<?php if ($configured) { ?>
<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-sync-alt me-2"></i>Sync All Appointments to Outlook</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Push all existing ticket appointments to Outlook for every technician who has connected their calendar. Useful after reconnecting a revoked token or migrating from another system. Each appointment creates or updates the corresponding Outlook event.</p>
        <form action="post.php" method="post" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group me-3">
                <label class="me-2">Scope</label>
                <select name="sync_scope" class="form-control form-control-sm">
                    <option value="future">Upcoming appointments only</option>
                    <option value="all">All appointments (past &amp; future)</option>
                </select>
            </div>
            <button type="submit" name="sync_all_outlook" class="btn btn-primary confirm-link">
                <i class="fas fa-sync-alt me-1"></i>Sync Now
            </button>
        </form>
        <small class="text-muted d-block mt-2">Only appointments where the assigned tech has connected their Outlook account will sync. Appointments with no assigned tech are skipped.</small>
    </div>
</div>
<?php } ?>

<!-- ── Setup Guide Card ────────────────────────────────────── -->
<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-book me-2"></i>Setup Guide</h3>
    </div>
    <div class="card-body">

        <p class="text-muted mb-4">Follow these steps once to register ITFlow as an app in Microsoft Azure. You need a Microsoft 365 or Azure account with admin rights.</p>

        <!-- Step 1 -->
        <div class="d-flex mb-4">
            <div class="me-3 text-center" style="min-width:32px;">
                <span class="badge text-bg-dark rounded-pill" style="font-size:1rem;width:32px;height:32px;line-height:32px;display:inline-block;">1</span>
            </div>
            <div>
                <strong>Go to Azure Portal → App Registrations</strong>
                <p class="text-muted mb-1 mt-1">Open <a href="https://portal.azure.com" target="_blank">portal.azure.com</a>, search for <strong>App registrations</strong>, and click <strong>New registration</strong>.</p>
                <ul class="text-muted ps-4">
                    <li><strong>Name:</strong> ITFlow Calendar Sync (or anything you like)</li>
                    <li><strong>Supported account types:</strong> Accounts in this organizational directory only (single tenant)</li>
                    <li><strong>Redirect URI:</strong> Web — paste the URL shown in the Redirect URI field above</li>
                </ul>
                <p class="text-muted mb-0">Click <strong>Register</strong>.</p>
            </div>
        </div>

        <!-- Step 2 -->
        <div class="d-flex mb-4">
            <div class="me-3 text-center" style="min-width:32px;">
                <span class="badge text-bg-dark rounded-pill" style="font-size:1rem;width:32px;height:32px;line-height:32px;display:inline-block;">2</span>
            </div>
            <div>
                <strong>Copy the IDs from the Overview page</strong>
                <p class="text-muted mb-0 mt-1">After registering, you land on the Overview page. Copy the <strong>Application (client) ID</strong> and <strong>Directory (tenant) ID</strong> into the fields above.</p>
            </div>
        </div>

        <!-- Step 3 -->
        <div class="d-flex mb-4">
            <div class="me-3 text-center" style="min-width:32px;">
                <span class="badge text-bg-dark rounded-pill" style="font-size:1rem;width:32px;height:32px;line-height:32px;display:inline-block;">3</span>
            </div>
            <div>
                <strong>Add API Permissions</strong>
                <p class="text-muted mb-1 mt-1">In the left menu click <strong>API permissions</strong> → <strong>Add a permission</strong> → <strong>Microsoft Graph</strong> → <strong>Delegated permissions</strong>.</p>
                <p class="text-muted mb-1">Search for and add:</p>
                <ul class="text-muted ps-4">
                    <li><code>Calendars.ReadWrite</code> — create and update calendar events</li>
                    <li><code>offline_access</code> — allows token refresh without re-login</li>
                </ul>
                <p class="text-muted mb-0">Then click <strong>Grant admin consent</strong> for your organization.</p>
            </div>
        </div>

        <!-- Step 4 -->
        <div class="d-flex mb-4">
            <div class="me-3 text-center" style="min-width:32px;">
                <span class="badge text-bg-dark rounded-pill" style="font-size:1rem;width:32px;height:32px;line-height:32px;display:inline-block;">4</span>
            </div>
            <div>
                <strong>Create a Client Secret</strong>
                <p class="text-muted mb-1 mt-1">In the left menu click <strong>Certificates &amp; secrets</strong> → <strong>New client secret</strong>.</p>
                <ul class="text-muted ps-4">
                    <li>Set a description (e.g. "ITFlow") and an expiry (24 months recommended)</li>
                    <li>Click <strong>Add</strong></li>
                    <li>Immediately copy the <strong>Value</strong> column — it is only shown once</li>
                </ul>
                <p class="text-muted mb-0">Paste it into the <strong>Client Secret</strong> field above and save.</p>
            </div>
        </div>

        <!-- Step 5 -->
        <div class="d-flex mb-4">
            <div class="me-3 text-center" style="min-width:32px;">
                <span class="badge text-bg-dark rounded-pill" style="font-size:1rem;width:32px;height:32px;line-height:32px;display:inline-block;">5</span>
            </div>
            <div>
                <strong>Save Credentials &amp; Connect Users</strong>
                <p class="text-muted mb-1 mt-1">Click <strong>Save Credentials</strong> above. Each technician then connects their Outlook account once from their <strong>Profile</strong> page (Settings → Connect Outlook Calendar).</p>
                <p class="text-muted mb-0">After connecting, scheduled tickets will automatically create and update events in their personal Outlook calendar.</p>
            </div>
        </div>

        <div class="alert alert-info py-2 mb-0">
            <i class="fas fa-info-circle me-2"></i>
            <strong>One Azure app, all users:</strong> The same app registration covers every technician. Each user does their own one-time OAuth login — ITFlow stores a refresh token per user so events stay in sync automatically.
        </div>

    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-copy-redirect-uri');
    if (!btn) return;
    var field = document.getElementById('redirect_uri_field');
    navigator.clipboard.writeText(field.value).then(function () {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(function () { btn.innerHTML = original; }, 2000);
    });
});
</script>

<?php require_once "../includes/footer.php"; ?>
