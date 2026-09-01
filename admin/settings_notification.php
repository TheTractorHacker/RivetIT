<?php

require_once "includes/inc_all_admin.php";

?>

<style>
.notif-section {
    border: 1px solid var(--color-border, #dee2e6);
    border-radius: .5rem;
    margin-bottom: 1.25rem;
    background: var(--color-surface, #fff);
    overflow: hidden;
}
.notif-section-header {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .75rem 1.25rem;
    background: rgba(0,0,0,.03);
    border-bottom: 1px solid var(--color-border, #dee2e6);
    font-weight: 600;
    font-size: .95rem;
}
.notif-section-header .section-icon {
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: .85rem;
}
.notif-section-body {
    padding: 1rem 1.25rem;
}
.notif-row {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: .65rem 0;
    border-bottom: 1px solid var(--color-border, #eee);
}
.notif-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.notif-row-meta {
    flex: 1;
}
.notif-row-meta .notif-label {
    font-weight: 500;
    font-size: .9rem;
    margin-bottom: .15rem;
}
.notif-row-meta .notif-desc {
    font-size: .8rem;
    color: var(--color-text-muted, #6c757d);
}
.notif-row-control {
    flex-shrink: 0;
    padding-top: .15rem;
}
.notif-email-input {
    max-width: 340px;
    margin-top: .5rem;
}
</style>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-bell me-2"></i>Notification Settings</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <!-- Cron / Scheduler -->
            <div class="notif-section">
                <div class="notif-section-header">
                    <span class="section-icon bg-secondary text-white"><i class="fas fa-clock"></i></span>
                    Scheduler
                </div>
                <div class="notif-section-body">
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Enable Cron Job</div>
                            <div class="notif-desc">Required for email reminders, expiration alerts, and other scheduled tasks. Several cron entries must also be configured on your server — <a href="https://docs.itflow.org/cron" target="_blank">see docs</a>.</div>
                        </div>
                        <div class="notif-row-control">
                            <div class="form-check form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="config_enable_cron" <?php if ($config_enable_cron) echo "checked"; ?> value="1" id="enableCronSwitch">
                                <label class="form-check-label" for="enableCronSwitch"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ticket Notifications -->
            <div class="notif-section">
                <div class="notif-section-header">
                    <span class="section-icon bg-primary text-white"><i class="fas fa-ticket-alt"></i></span>
                    Ticket Notifications
                </div>
                <div class="notif-section-body">
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">New Ticket Alert Email</div>
                            <div class="notif-desc">Send an email whenever a new ticket is created. Leave blank to disable.</div>
                            <div class="notif-email-input">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                                    <input type="email" class="form-control" name="config_ticket_new_ticket_notification_email"
                                           value="<?= nullable_htmlentities($config_ticket_new_ticket_notification_email) ?>"
                                           placeholder="alerts@example.com">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Client Portal Notifications</div>
                            <div class="notif-desc">Email clients automatically when their tickets are opened or closed.</div>
                        </div>
                        <div class="notif-row-control">
                            <div class="form-check form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="config_ticket_client_general_notifications"
                                       <?php if ($config_ticket_client_general_notifications) echo "checked"; ?> value="1" id="clientNotifSwitch">
                                <label class="form-check-label" for="clientNotifSwitch"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Notifications -->
            <div class="notif-section">
                <div class="notif-section-header">
                    <span class="section-icon bg-success text-white"><i class="fas fa-file-invoice-dollar"></i></span>
                    Invoice Notifications
                </div>
                <div class="notif-section-body">
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Client Overdue Reminders</div>
                            <div class="notif-desc">Automatically email clients about overdue invoices every 30 days.</div>
                        </div>
                        <div class="notif-row-control">
                            <div class="form-check form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="config_send_invoice_reminders"
                                       <?php if ($config_send_invoice_reminders) echo "checked"; ?> value="1" id="sendRemindersSwitch">
                                <label class="form-check-label" for="sendRemindersSwitch"></label>
                            </div>
                        </div>
                    </div>
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Internal Overdue Alerts</div>
                            <div class="notif-desc">Notify staff when invoices become overdue.</div>
                        </div>
                        <div class="notif-row-control">
                            <div class="form-check form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="config_invoice_overdue_reminders"
                                       <?php if (!empty($config_invoice_overdue_reminders)) echo "checked"; ?> value="1" id="overdueSwitch">
                                <label class="form-check-label" for="overdueSwitch"></label>
                            </div>
                        </div>
                    </div>
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Invoice Paid Notification</div>
                            <div class="notif-desc">Send an email when a client pays an invoice. Leave blank to disable.</div>
                            <div class="notif-email-input">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                                    <input type="email" class="form-control" name="config_invoice_paid_notification_email"
                                           value="<?= nullable_htmlentities($config_invoice_paid_notification_email) ?>"
                                           placeholder="billing@example.com">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Recurring Invoice Emails</div>
                            <div class="notif-desc">Email clients when their recurring invoices are automatically generated.</div>
                        </div>
                        <div class="notif-row-control">
                            <div class="form-check form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="config_recurring_auto_send_invoice"
                                       <?php if ($config_recurring_auto_send_invoice) echo "checked"; ?> value="1" id="sendRecurringSwitch">
                                <label class="form-check-label" for="sendRecurringSwitch"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quote Notifications -->
            <div class="notif-section">
                <div class="notif-section-header">
                    <span class="section-icon bg-info text-white"><i class="fas fa-file-contract"></i></span>
                    Quote Notifications
                </div>
                <div class="notif-section-body">
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Quote Accepted Notification</div>
                            <div class="notif-desc">Send an email when a client accepts a quote. Leave blank to disable.</div>
                            <div class="notif-email-input">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-envelope"></i></span></div>
                                    <input type="email" class="form-control" name="config_quote_notification_email"
                                           value="<?= nullable_htmlentities($config_quote_notification_email) ?>"
                                           placeholder="sales@example.com">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Expiration Alerts -->
            <div class="notif-section">
                <div class="notif-section-header">
                    <span class="section-icon bg-warning text-white"><i class="fas fa-globe"></i></span>
                    Expiration Alerts
                </div>
                <div class="notif-section-body">
                    <div class="notif-row">
                        <div class="notif-row-meta">
                            <div class="notif-label">Domain &amp; Certificate Expiry</div>
                            <div class="notif-desc">Show in-app alerts at 1, 7, and 45 days before domain and SSL certificate expiration.</div>
                        </div>
                        <div class="notif-row-control">
                            <div class="form-check form-check form-switch">
                                <input type="checkbox" class="form-check-input" name="config_enable_alert_domain_expire"
                                       <?php if ($config_enable_alert_domain_expire) echo "checked"; ?> value="1" id="domainExpireSwitch">
                                <label class="form-check-label" for="domainExpireSwitch"></label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" name="edit_notification_settings" class="btn btn-primary">
                <i class="fa fa-check me-2"></i>Save Settings
            </button>

        </form>

        <?php
        $firebase_configured = file_exists(__DIR__ . '/../config/firebase_service_account.json');
        $firebase_project_id   = null;
        $firebase_client_email = null;
        if ($firebase_configured) {
            $sa_raw = @file_get_contents(__DIR__ . '/../config/firebase_service_account.json');
            if ($sa_raw) {
                $sa = json_decode($sa_raw, true) ?: [];
                $firebase_project_id   = $sa['project_id']   ?? null;
                $firebase_client_email = $sa['client_email']  ?? null;
            }
        }
        $sql_push_total = mysqli_query($mysqli, "SELECT COUNT(*) AS cnt FROM api_tokens WHERE token_fcm_token IS NOT NULL AND token_fcm_token != ''");
        $push_total_devices = intval(mysqli_fetch_assoc($sql_push_total)['cnt']);
        $sql_push_users = mysqli_query($mysqli, "SELECT COUNT(DISTINCT token_user_id) AS cnt FROM api_tokens WHERE token_fcm_token IS NOT NULL AND token_fcm_token != ''");
        $push_user_count = intval(mysqli_fetch_assoc($sql_push_users)['cnt']);
        $sql_push_mine = mysqli_query($mysqli, "SELECT COUNT(*) AS cnt FROM api_tokens WHERE token_user_id = $session_user_id AND token_fcm_token IS NOT NULL AND token_fcm_token != ''");
        $push_my_devices = intval(mysqli_fetch_assoc($sql_push_mine)['cnt']);
        $sql_push_devices = mysqli_query($mysqli, "
            SELECT t.token_id, t.token_name, t.token_fcm_token, t.token_last_used_at, t.token_created_at, u.user_name
            FROM api_tokens t
            JOIN users u ON u.user_id = t.token_user_id
            ORDER BY t.token_last_used_at IS NULL, t.token_last_used_at DESC, t.token_created_at DESC
        ");
        ?>

        <!-- Mobile App Push Notifications -->
        <div class="notif-section mt-3">
            <div class="notif-section-header">
                <span class="section-icon text-white" style="background:#6f42c1;"><i class="fas fa-mobile-alt"></i></span>
                Mobile App Push Notifications
            </div>
            <div class="notif-section-body">

            <?php if ($firebase_configured) { ?>

                <!-- ── CONFIGURED STATE ── -->

                <!-- Status bar -->
                <div class="d-flex align-items-center justify-content-between flex-wrap mb-3 p-3 rounded"
                     style="background:rgba(40,167,69,.08);border:1px solid rgba(40,167,69,.25);gap:.75rem;">
                    <div>
                        <div class="font-weight-semibold text-success mb-1">
                            <i class="fas fa-check-circle me-1"></i>Firebase Connected
                        </div>
                        <?php if ($firebase_project_id) { ?>
                        <div class="small text-muted">
                            <i class="fas fa-cube me-1"></i>Project: <code><?= nullable_htmlentities($firebase_project_id) ?></code>
                        </div>
                        <?php } ?>
                        <?php if ($firebase_client_email) { ?>
                        <div class="small text-muted mt-1" style="word-break:break-all;">
                            <i class="fas fa-key me-1"></i><?= nullable_htmlentities($firebase_client_email) ?>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="d-flex" style="gap:.4rem;flex-shrink:0;">
                        <?php if ($push_my_devices > 0) { ?>
                        <form method="post" action="post.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <button type="submit" name="test_push_notification" class="btn btn-sm btn-primary" title="Sends to your own registered device(s) only">
                                <i class="fas fa-paper-plane me-1"></i>Send Test Push (mine)
                            </button>
                        </form>
                        <?php } else if ($push_total_devices > 0) { ?>
                        <span class="small text-muted" title="Other staff have registered devices, but your account doesn't">
                            <i class="fas fa-info-circle me-1"></i>No device registered to your account
                        </span>
                        <?php } ?>
                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                data-bs-toggle="collapse" data-bs-target="#firebaseJsonEditor">
                            <i class="fas fa-edit me-1"></i>Update Key
                        </button>
                        <form method="post" action="post.php">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <button type="submit" name="remove_firebase_config" class="btn btn-sm btn-outline-danger confirm-link">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Update key collapse -->
                <div class="collapse mb-3" id="firebaseJsonEditor">
                    <div class="p-3 rounded" style="background:rgba(0,0,0,.03);border:1px solid var(--color-border,#dee2e6);">
                        <div class="small fw-bold mb-2"><i class="fas fa-file-code me-1"></i>Paste new service account JSON</div>
                        <form method="post" action="post.php" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <textarea name="firebase_service_account_json" rows="8"
                                class="form-control form-control-sm mb-2"
                                style="font-family:monospace;font-size:.75rem;resize:vertical;"
                                placeholder='{ "type": "service_account", "project_id": "…", … }'></textarea>
                            <button type="submit" name="save_firebase_config" class="btn btn-sm btn-primary">
                                <i class="fas fa-save me-1"></i>Update Configuration
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Device stats -->
                <div class="d-flex align-items-center mb-3" style="gap:2rem;">
                    <div class="text-center">
                        <div style="font-size:1.6rem;font-weight:700;line-height:1;color:#6f42c1;"><?= $push_total_devices ?></div>
                        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;">Device<?= $push_total_devices !== 1 ? 's' : '' ?> with push</div>
                    </div>
                    <div class="text-center">
                        <div style="font-size:1.6rem;font-weight:700;line-height:1;color:#6f42c1;"><?= $push_user_count ?></div>
                        <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;">Staff covered</div>
                    </div>
                </div>

                <!-- Device management table -->
                <div class="mb-1">
                    <div class="small text-muted fw-bold mb-2 text-uppercase" style="letter-spacing:.05em;font-size:.7rem;">
                        <i class="fas fa-tablet-alt me-1"></i>Manage devices
                    </div>
                    <?php if (mysqli_num_rows($sql_push_devices) > 0) { ?>
                    <div class="table-responsive" style="border:1px solid var(--color-border,#eee);border-radius:.4rem;">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Owner</th>
                                    <th>Push</th>
                                    <th>Last Active</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($dev = mysqli_fetch_assoc($sql_push_devices)) {
                                    $last_used   = $dev['token_last_used_at'];
                                    $is_active   = $last_used && (time() - strtotime($last_used)) < 7 * 86400;
                                    $has_push    = !empty($dev['token_fcm_token']);
                                ?>
                                <tr id="push-dev-<?= $dev['token_id'] ?>">
                                    <td>
                                        <i class="fas fa-fw fa-mobile-alt text-secondary me-1"></i>
                                        <?= nullable_htmlentities($dev['token_name']) ?>
                                    </td>
                                    <td><i class="fas fa-fw fa-user text-muted me-1"></i><?= nullable_htmlentities($dev['user_name']) ?></td>
                                    <td>
                                        <?php if ($has_push) { ?>
                                        <i class="fas fa-bell text-success" title="Push notifications active"></i>
                                        <?php } else { ?>
                                        <i class="fas fa-bell-slash text-muted" title="No push token registered"></i>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php if ($last_used) { ?>
                                        <span class="badge <?= $is_active ? 'text-bg-success' : 'text-bg-secondary' ?>" style="font-size:.7rem;">
                                            <?= $is_active ? 'Active' : 'Idle' ?>
                                        </span>
                                        <span class="text-muted small ms-1"><?= $last_used ?></span>
                                        <?php } else { ?>
                                        <span class="badge text-bg-light border" style="font-size:.7rem;">Never used</span>
                                        <?php } ?>
                                    </td>
                                    <td class="text-end">
                                        <button class="btn btn-xs btn-outline-danger js-revoke-push-device"
                                                data-token-id="<?= $dev['token_id'] ?>"
                                                data-csrf="<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-times me-1"></i>Revoke
                                        </button>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <?php } else { ?>
                    <div class="p-3 text-muted text-center small" style="border:1px solid var(--color-border,#eee);border-radius:.4rem;">
                        <i class="fas fa-mobile-alt fa-2x mb-2 d-block text-secondary"></i>
                        No devices have logged into the ITFlow mobile app yet.
                    </div>
                    <?php } ?>
                </div>

                <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
                async function revokePushDevice(id, btn) {
                    if (!confirm('Revoke this device? It will be logged out of the mobile app.')) return;
                    btn.disabled = true;
                    try {
                        const r = await fetch('post.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'revoke_push_device=1&token_id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(btn.dataset.csrf)
                        });
                        const j = await r.json();
                        if (j.ok) {
                            document.getElementById('push-dev-' + id).remove();
                        } else {
                            alert('Could not revoke device.');
                            btn.disabled = false;
                        }
                    } catch (e) {
                        alert('Network error revoking device.');
                        btn.disabled = false;
                    }
                }
                // Was a bare onclick=; silently blocked by this app's CSP.
                document.addEventListener('click', function (e) {
                    var btn = e.target.closest('.js-revoke-push-device');
                    if (btn) { revokePushDevice(btn.dataset.tokenId, btn); }
                });
                </script>

                <!-- Globally allowed push categories -->
                <div class="mt-3 pt-3" style="border-top:1px solid var(--color-border,#eee);">
                    <div class="small text-muted fw-bold mb-1 text-uppercase" style="letter-spacing:.05em;font-size:.7rem;">
                        <i class="fas fa-list-check me-1"></i>Notification categories allowed to push
                    </div>
                    <div class="small text-muted mb-2">Staff can only enable push for categories switched on here. Turning one off disables it for everyone.</div>
                    <form method="post" action="post.php">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="rounded" style="border:1px solid var(--color-border,#eee);overflow:hidden;">
                            <?php
                            $push_global_categories = push_globally_enabled_categories();
                            $cat_total = count(push_notification_categories());
                            $cat_i = 0;
                            foreach (push_notification_categories() as $cat_key => $cat) {
                                $cat_i++;
                            ?>
                            <div class="d-flex align-items-center justify-content-between p-2 px-3"
                                 style="<?= $cat_i < $cat_total ? 'border-bottom:1px solid var(--color-border,#eee);' : '' ?>">
                                <span><i class="<?= $cat['icon'] ?> me-2 text-muted" style="width:1.1rem;text-align:center;"></i><?= nullable_htmlentities($cat['label']) ?></span>
                                <div class="form-check form-check form-switch mb-0">
                                    <input type="checkbox" class="form-check-input" id="push_cat_<?= $cat_key ?>"
                                           name="push_categories[]" value="<?= $cat_key ?>"
                                           <?= in_array($cat_key, $push_global_categories, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="push_cat_<?= $cat_key ?>"></label>
                                </div>
                            </div>
                            <?php } ?>
                        </div>
                        <button type="submit" name="save_push_categories" class="btn btn-sm btn-primary mt-3">
                            <i class="fas fa-save me-1"></i>Save Categories
                        </button>
                    </form>
                </div>

            <?php } else { ?>

                <!-- ── NOT CONFIGURED STATE ── -->

                <!-- Warning callout -->
                <div class="d-flex align-items-start p-3 rounded mb-3"
                     style="background:rgba(255,193,7,.08);border:1px solid rgba(255,193,7,.35);gap:.75rem;">
                    <i class="fas fa-exclamation-triangle text-warning mt-1" style="font-size:1.1rem;flex-shrink:0;"></i>
                    <div>
                        <div class="font-weight-semibold mb-1">Push notifications are not configured</div>
                        <div class="small text-muted">
                            Connect a Firebase project to send real-time push notifications to staff
                            on the ITFlow mobile app. Firebase Cloud Messaging (FCM) is free with no per-message cost.
                        </div>
                    </div>
                </div>

                <!-- Inline setup guide -->
                <div class="mb-3">
                    <div class="small fw-bold text-uppercase text-muted mb-2" style="letter-spacing:.05em;font-size:.7rem;">
                        <i class="fas fa-list-ol me-1"></i>Setup Steps
                    </div>
                    <div class="push-steps">
                        <div class="push-step">
                            <div class="push-step-num">1</div>
                            <div class="push-step-body">
                                Go to the <a href="https://console.firebase.google.com/" target="_blank">Firebase Console</a>
                                and sign in with a Google account.
                            </div>
                        </div>
                        <div class="push-step">
                            <div class="push-step-num">2</div>
                            <div class="push-step-body">
                                Click <strong>Add project</strong>, give it a name (e.g. <em>ITFlow</em>), and complete the wizard.
                                Google Analytics is not required — you can disable it.
                            </div>
                        </div>
                        <div class="push-step">
                            <div class="push-step-num">3</div>
                            <div class="push-step-body">
                                Inside your project, click the <strong><i class="fab fa-android"></i> Android</strong> icon to register an app.
                                Set the Android package name to <code>com.foleyit.itflow</code> and skip the
                                <code>google-services.json</code> and SHA steps.
                            </div>
                        </div>
                        <div class="push-step">
                            <div class="push-step-num">4</div>
                            <div class="push-step-body">
                                Open <strong>Project Settings</strong> (gear icon in the left sidebar) and click the
                                <strong>Service accounts</strong> tab.
                            </div>
                        </div>
                        <div class="push-step">
                            <div class="push-step-num">5</div>
                            <div class="push-step-body">
                                Click <strong>Generate new private key</strong> and confirm. A <code>.json</code> file
                                will download — open it, copy all the text, and paste it below.
                            </div>
                        </div>
                        <div class="push-step" style="border-bottom:none;">
                            <div class="push-step-num">6</div>
                            <div class="push-step-body">
                                Staff log into the <strong>ITFlow mobile app</strong> and their devices register automatically.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- JSON paste form -->
                <div class="p-3 rounded" style="background:rgba(0,0,0,.03);border:1px solid var(--color-border,#dee2e6);">
                    <div class="small fw-bold mb-2"><i class="fas fa-file-code me-1"></i>Paste service account JSON</div>
                    <form method="post" action="post.php" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <textarea name="firebase_service_account_json" rows="9"
                            class="form-control form-control-sm mb-2"
                            style="font-family:monospace;font-size:.75rem;resize:vertical;"
                            placeholder='{ "type": "service_account", "project_id": "…", … }'></textarea>
                        <button type="submit" name="save_firebase_config" class="btn btn-sm btn-primary">
                            <i class="fas fa-save me-1"></i>Save Configuration
                        </button>
                    </form>
                </div>

            <?php } ?>

            </div>
        </div>

        <style>
        .push-steps { border:1px solid var(--color-border,#dee2e6); border-radius:.375rem; overflow:hidden; }
        .push-step { display:flex; align-items:flex-start; gap:.75rem; padding:.65rem .9rem; border-bottom:1px solid var(--color-border,#eee); }
        .push-step-num {
            flex-shrink:0; width:1.5rem; height:1.5rem; border-radius:50%;
            background:#6f42c1; color:#fff; font-size:.7rem; font-weight:700;
            display:flex; align-items:center; justify-content:center; margin-top:.05rem;
        }
        .push-step-body { font-size:.85rem; line-height:1.55; }
        .font-weight-semibold { font-weight:600; }
        </style>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
