<?php
require_once '../../../includes/modal_header.php';

$mailbox_id = intval($_GET['id'] ?? 0);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE mailbox_id = $mailbox_id LIMIT 1"));
if (!$row) { echo '<div class="p-3 text-danger">Mailbox not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$mb_name       = nullable_htmlentities($row['mailbox_name']);
$mb_email      = nullable_htmlentities($row['mailbox_email']);
$mb_from_name  = nullable_htmlentities($row['mailbox_from_name']);
$mb_type       = $row['mailbox_type'] ?? 'standard_imap';
$mb_username   = nullable_htmlentities($row['mailbox_imap_username']);
$mb_host       = nullable_htmlentities($row['mailbox_imap_host']);
$mb_port       = intval($row['mailbox_imap_port']);
$mb_encryption = $row['mailbox_imap_encryption'] ?? '';
$mb_parse_unknown = intval($row['mailbox_parse_unknown_senders']);
$mb_default_client_id = intval($row['mailbox_default_client_id']);
$mb_active     = intval($row['mailbox_active']);

$mb_is_oauth   = in_array($mb_type, ['google_oauth', 'microsoft_oauth'], true);
$mb_has_token  = !empty($row['mailbox_oauth_refresh_token_enc']);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-edit me-2"></i>Edit Mailbox</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="mailbox_id" value="<?= $mailbox_id ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Mailbox Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span></div>
                <input type="text" class="form-control" name="mailbox_name" value="<?= $mb_name ?>" maxlength="200" required>
            </div>
        </div>

        <div class="form-group">
            <label>Email Address <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span></div>
                <input type="email" class="form-control" name="mailbox_email" value="<?= $mb_email ?>" maxlength="200" required>
            </div>
        </div>

        <div class="form-group">
            <label>From Name</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-signature"></i></span></div>
                <input type="text" class="form-control" name="mailbox_from_name" value="<?= $mb_from_name ?>" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Mailbox Type</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-cloud"></i></span></div>
                <select class="form-control" name="mailbox_type" id="mailbox_edit_type">
                    <option value="standard_imap" <?= $mb_type === 'standard_imap' ? 'selected' : '' ?>>Standard IMAP (Username/Password)</option>
                    <?php /* google_oauth intentionally hidden from selection: there is no working Google Workspace
                             OAuth connect flow yet (cron/backend support exists, but nothing lets an admin ever
                             populate a token). Re-enable this option once a real connect flow ships. If an existing
                             row already has mailbox_type = 'google_oauth', this hidden option preserves its current
                             value so the select doesn't silently change the mailbox's type on save. */ ?>
                    <?php if ($mb_type === 'google_oauth') { ?>
                    <option value="google_oauth" selected style="display:none;">Google Workspace (OAuth)</option>
                    <?php } ?>
                    <option value="microsoft_oauth" <?= $mb_type === 'microsoft_oauth' ? 'selected' : '' ?>>Microsoft 365 (OAuth)</option>
                </select>
            </div>
            <small class="text-secondary d-block mt-1" id="mailbox_edit_type_hint"></small>
        </div>

        <div class="form-group">
            <label>IMAP Username <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-user"></i></span></div>
                <input type="text" class="form-control" name="mailbox_imap_username" value="<?= $mb_username ?>" placeholder="Mailbox address (e.g. support@yourcompany.com)" maxlength="200" required>
            </div>
        </div>

        <div id="mailbox_edit_standard_fields">
            <div class="form-group">
                <label>IMAP Host</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-server"></i></span></div>
                    <input type="text" class="form-control" name="mailbox_imap_host" value="<?= $mb_host ?>" maxlength="200">
                </div>
            </div>

            <div class="form-group">
                <label>IMAP Port</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-plug"></i></span></div>
                    <input type="number" min="0" class="form-control" name="mailbox_imap_port" value="<?= $mb_port ?: '' ?>" placeholder="993">
                </div>
            </div>

            <div class="form-group">
                <label>IMAP Encryption</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span></div>
                    <select class="form-control" name="mailbox_imap_encryption">
                        <option value="" <?= $mb_encryption === '' ? 'selected' : '' ?>>None</option>
                        <option value="tls" <?= $mb_encryption === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= $mb_encryption === 'ssl' ? 'selected' : '' ?>>SSL</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>IMAP Password</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-key"></i></span></div>
                    <input type="password" class="form-control" data-toggle="password" name="mailbox_imap_password" placeholder="Leave blank to keep current password" autocomplete="new-password">
                    <div class="input-group-append"><span class="input-group-text"><i class="fa fa-fw fa-eye"></i></span></div>
                </div>
                <small class="form-text text-muted">Leave blank to keep the currently stored password.</small>
            </div>
        </div>

        <div id="mailbox_edit_oauth_wrap" style="display:none;">
            <?php if ($mb_type === 'microsoft_oauth') { ?>
                <?php if ($mb_has_token) { ?>
                    <div class="alert alert-success py-2 mb-3">
                        <i class="fas fa-check-circle me-1"></i>Connected to Microsoft 365.
                        <?php if (!empty($row['mailbox_oauth_access_token_expires_at'])) { ?>
                            <br><small>Access token expires at <?= nullable_htmlentities($row['mailbox_oauth_access_token_expires_at']) ?> (auto-refreshed as needed).</small>
                        <?php } ?>
                    </div>
                    <a href="post.php?oauth_connect_mailbox_microsoft=<?= $mailbox_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-primary btn-sm mb-3">
                        <i class="fab fa-fw fa-microsoft me-2"></i>Reconnect Microsoft 365
                    </a>
                <?php } else { ?>
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>Not connected yet. Save any changes above first, then click Connect.
                    </div>
                    <a href="post.php?oauth_connect_mailbox_microsoft=<?= $mailbox_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-primary btn-sm mb-3">
                        <i class="fab fa-fw fa-microsoft me-2"></i>Connect Microsoft 365
                    </a>
                <?php } ?>
                <small class="form-text text-muted d-block mb-3">Uses the shared app registration configured under <a href="settings_mail.php" target="_blank">Settings &gt; Mail</a>.</small>
            <?php } elseif ($mb_type === 'google_oauth') { ?>
                <?php
                // google_oauth has no working OAuth connect flow yet (no button links here from anywhere,
                // and the type is no longer selectable above), so there's nothing to click through to.
                // This branch only renders for a pre-existing row of this type; show status only, no dead-end
                // "Connect" button. Remove this comment and re-add a Connect action once a real flow ships.
                ?>
                <?php if ($mb_has_token) { ?>
                    <div class="alert alert-success py-2 mb-3">
                        <i class="fas fa-check-circle me-1"></i>Connected to Google Workspace.
                        <?php if (!empty($row['mailbox_oauth_access_token_expires_at'])) { ?>
                            <br><small>Access token expires at <?= nullable_htmlentities($row['mailbox_oauth_access_token_expires_at']) ?> (auto-refreshed as needed).</small>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>Not connected yet. Google Workspace OAuth connect is not available yet.
                    </div>
                <?php } ?>
            <?php } ?>
        </div>

        <div class="form-group">
            <div class="input-group">
                <div class="input-group-prepend">
                    <input type="checkbox" name="mailbox_parse_unknown_senders" value="1" <?= $mb_parse_unknown ? 'checked' : '' ?>>
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
                    <option value="<?= $client_id ?>" <?= $mb_default_client_id === $client_id ? 'selected' : '' ?>><?= $client_name ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="form-text text-muted">If set, tickets from senders that don't match a contact are routed to this client instead of a guest ticket.</small>
        </div>

        <div class="form-group">
            <div class="input-group">
                <div class="input-group-prepend">
                    <input type="checkbox" name="mailbox_active" value="1" <?= $mb_active ? 'checked' : '' ?>>
                </div>
                <label class="form-check-label ms-2 mt-1">Active</label>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_mailbox" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function(){
    function setDisabled(container, disabled){
        if(!container) return;
        container.querySelectorAll('input, select, textarea').forEach(function(el){ el.disabled = !!disabled; });
    }

    var sel  = document.getElementById('mailbox_edit_type');
    var std  = document.getElementById('mailbox_edit_standard_fields');
    var oa   = document.getElementById('mailbox_edit_oauth_wrap');
    var hint = document.getElementById('mailbox_edit_type_hint');

    function toggle(){
        var v = (sel && sel.value) || 'standard_imap';
        var isStd = (v === 'standard_imap');

        if (std) { std.style.display = isStd ? '' : 'none'; setDisabled(std, !isStd); }
        if (oa)  { oa.style.display  = isStd ? 'none' : ''; }

        if (hint) {
            hint.textContent = isStd
                ? 'Standard: provide host, port, encryption, username & password.'
                : 'OAuth: username should be the mailbox address.';
        }
    }

    if (sel) { sel.addEventListener('change', toggle); toggle(); }
})();
</script>
<?php
require_once '../../../includes/modal_footer.php';
