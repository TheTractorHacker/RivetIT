<?php
require_once '../../../includes/modal_header.php';
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-inbox me-2"></i>Add Mailbox</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Mailbox Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span></div>
                <input type="text" class="form-control" name="mailbox_name" placeholder="e.g. Support Inbox" maxlength="200" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Email Address <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span></div>
                <input type="email" class="form-control" name="mailbox_email" placeholder="support@yourcompany.com" maxlength="200" required>
            </div>
        </div>

        <div class="form-group">
            <label>From Name</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-signature"></i></span></div>
                <input type="text" class="form-control" name="mailbox_from_name" placeholder="e.g. YourCompany Support" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Mailbox Type</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-cloud"></i></span></div>
                <select class="form-control" name="mailbox_type" id="mailbox_add_type">
                    <option value="standard_imap">Standard IMAP (Username/Password)</option>
                    <!-- google_oauth intentionally hidden from selection: there is no working Google Workspace
                         OAuth connect flow yet (cron/backend support exists, but nothing lets an admin ever
                         populate a token). Re-enable this option once a real connect flow ships. -->
                    <option value="microsoft_oauth">Microsoft 365 (OAuth)</option>
                </select>
            </div>
            <small class="text-secondary d-block mt-1" id="mailbox_add_type_hint"></small>
        </div>

        <div class="form-group">
            <label>IMAP Username <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-user"></i></span></div>
                <input type="text" class="form-control" name="mailbox_imap_username" placeholder="Mailbox address (e.g. support@yourcompany.com)" maxlength="200" required>
            </div>
        </div>

        <div id="mailbox_add_standard_fields">
            <div class="form-group">
                <label>IMAP Host</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-server"></i></span></div>
                    <input type="text" class="form-control" name="mailbox_imap_host" placeholder="Incoming Mail Server Address" maxlength="200">
                </div>
            </div>

            <div class="form-group">
                <label>IMAP Port</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-plug"></i></span></div>
                    <input type="number" min="0" class="form-control" name="mailbox_imap_port" placeholder="993">
                </div>
            </div>

            <div class="form-group">
                <label>IMAP Encryption</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span></div>
                    <select class="form-control" name="mailbox_imap_encryption">
                        <option value="">None</option>
                        <option value="tls">TLS</option>
                        <option value="ssl" selected>SSL</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>IMAP Password</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-key"></i></span></div>
                    <input type="password" class="form-control" data-toggle="password" name="mailbox_imap_password" placeholder="Password" autocomplete="new-password">
                    <div class="input-group-append"><span class="input-group-text"><i class="fa fa-fw fa-eye"></i></span></div>
                </div>
            </div>
        </div>

        <div id="mailbox_add_oauth_hint_wrap" style="display:none;">
            <div class="alert alert-info py-2 mb-3">
                <i class="fas fa-info-circle me-1"></i>
                App registration credentials (Client ID/Secret/Tenant) are shared across all mailboxes and configured once under <a href="settings_mail.php" target="_blank">Settings &gt; Mail</a>. After saving this mailbox, edit it again to click <strong>Connect</strong> and authorize access.
            </div>
        </div>

        <div class="form-group">
            <div class="input-group">
                <div class="input-group-prepend">
                    <input type="checkbox" name="mailbox_parse_unknown_senders" value="1">
                </div>
                <label class="form-check-label ms-2 mt-1">Queue unknown senders as Requests</label>
            </div>
            <small class="form-text text-muted">If checked, emails from senders that don't match an existing contact appear on the <a href="mail_requests.php" target="_blank">Requests</a> page to convert into a ticket or dismiss. If unchecked, they're left unread in the mailbox for manual review.</small>
        </div>

        <div class="form-group">
            <label>Default Client (for unmatched senders)</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-user-tag"></i></span></div>
                <select class="form-control select2" name="mailbox_default_client_id">
                    <option value="0">- None (use Guest / unassigned) -</option>
                    <?php
                    $sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                    while ($client_row = mysqli_fetch_assoc($sql_clients)) {
                        $client_id = intval($client_row['client_id']);
                        $client_name = nullable_htmlentities($client_row['client_name']);
                    ?>
                    <option value="<?= $client_id ?>"><?= $client_name ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="form-text text-muted">If set, tickets from senders that don't match a contact are routed to this client instead of a guest ticket.</small>
        </div>

        <div class="form-group">
            <div class="input-group">
                <div class="input-group-prepend">
                    <input type="checkbox" name="mailbox_active" value="1" checked>
                </div>
                <label class="form-check-label ms-2 mt-1">Active</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_mailbox" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function(){
    function setDisabled(container, disabled){
        if(!container) return;
        container.querySelectorAll('input, select, textarea').forEach(function(el){ el.disabled = !!disabled; });
    }

    var sel  = document.getElementById('mailbox_add_type');
    var std  = document.getElementById('mailbox_add_standard_fields');
    var oa   = document.getElementById('mailbox_add_oauth_hint_wrap');
    var hint = document.getElementById('mailbox_add_type_hint');

    function toggle(){
        var v = (sel && sel.value) || 'standard_imap';
        var isStd = (v === 'standard_imap');

        if (std) { std.style.display = isStd ? '' : 'none'; setDisabled(std, !isStd); }
        if (oa)  { oa.style.display  = isStd ? 'none' : ''; }

        if (hint) {
            hint.textContent = isStd
                ? 'Standard: provide host, port, encryption, username & password.'
                : 'OAuth: username should be the mailbox address. Connect after saving.';
        }
    }

    if (sel) { sel.addEventListener('change', toggle); toggle(); }
})();
</script>
<?php
require_once '../../../includes/modal_footer.php';
