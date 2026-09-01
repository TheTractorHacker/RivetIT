<?php
require_once '../../../includes/modal_header.php';

$connected_sql = mysqli_query($mysqli, "SELECT mailbox_id, mailbox_name, mailbox_imap_username FROM mailboxes WHERE mailbox_type = 'microsoft_oauth' AND mailbox_oauth_refresh_token_enc IS NOT NULL AND mailbox_archived_at IS NULL ORDER BY mailbox_order, mailbox_id");
$connected = [];
while ($row = mysqli_fetch_assoc($connected_sql)) {
    $connected[] = $row;
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-satellite-dish me-2"></i>Check Shared Mailbox Access</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">

    <?php if (empty($connected)) { ?>

        <div class="alert alert-warning mb-0">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Connect at least one Microsoft 365 mailbox first (Add Mailbox &rarr; Microsoft 365 (OAuth) &rarr; Connect). This tool checks other addresses using that connection's access.
        </div>

    <?php } else { ?>

        <div class="alert alert-info py-2 mb-3">
            <i class="fas fa-info-circle me-1"></i>
            Microsoft Graph has no way to list which shared mailboxes you have access to, so this tests specific addresses you already believe you have <strong>Full Access</strong> to (granted in the Exchange admin center) &mdash; it's a check, not a directory.
        </div>

        <?php if (count($connected) > 1) { ?>
        <div class="form-group">
            <label>Check access as</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-user"></i></span></div>
                <select class="form-control" id="mailbox_check_access_source">
                    <?php foreach ($connected as $c) { ?>
                    <option value="<?= intval($c['mailbox_id']) ?>"><?= nullable_htmlentities($c['mailbox_name']) ?> (<?= nullable_htmlentities($c['mailbox_imap_username']) ?>)</option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <?php } else { ?>
        <input type="hidden" id="mailbox_check_access_source" value="<?= intval($connected[0]['mailbox_id']) ?>">
        <p class="text-light small mb-3">Checking as <strong><?= nullable_htmlentities($connected[0]['mailbox_imap_username']) ?></strong>.</p>
        <?php } ?>

        <div class="form-group">
            <label>Candidate addresses (one per line)</label>
            <textarea class="form-control" id="mailbox_check_access_candidates" rows="4" placeholder="support@client.com&#10;billing@client.com"></textarea>
        </div>

        <button type="button" class="btn btn-primary btn-sm mb-3" id="mailbox_check_access_btn">
            <i class="fas fa-satellite-dish me-1"></i>Check Access
        </button>

        <div id="mailbox_check_access_results"></div>

    <?php } ?>

</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
</div>

<?php if (!empty($connected)) { ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function mailboxCheckAccessRun(){
    var btn = document.getElementById('mailbox_check_access_btn');
    var resultsEl = document.getElementById('mailbox_check_access_results');
    var candidates = document.getElementById('mailbox_check_access_candidates').value;
    var sourceEl = document.getElementById('mailbox_check_access_source');
    var sourceId = sourceEl ? sourceEl.value : '';

    if (!candidates.trim()) { return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Checking...';
    resultsEl.innerHTML = '';

    fetch('post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= urlencode($_SESSION['csrf_token']) ?>'
            + '&check_mailbox_access=1'
            + '&source_mailbox_id=' + encodeURIComponent(sourceId)
            + '&candidates=' + encodeURIComponent(candidates)
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-satellite-dish me-1"></i>Check Access';

        if (!d.success) {
            resultsEl.innerHTML = '<div class="alert alert-danger py-2 mb-0">' + d.error + '</div>';
            return;
        }

        var html = '<p class="text-light small mb-2">Checked as <strong>' + escapeHtml(d.checked_as) + '</strong>'
            + (d.truncated ? ' &mdash; only the first 25 addresses were checked' : '') + '</p>';
        html += '<ul class="list-unstyled mb-0">';

        d.results.forEach(function(r){
            html += '<li class="d-flex align-items-center justify-content-between border-bottom py-2">';
            html += '<span>' + (r.accessible
                ? '<i class="fas fa-check-circle text-success me-2"></i>'
                : '<i class="fas fa-times-circle text-danger me-2"></i>') + escapeHtml(r.email);
            if (!r.accessible && r.error) {
                html += '<br><small class="text-muted">' + escapeHtml(r.error) + '</small>';
            }
            html += '</span>';
            if (r.accessible) {
                html += '<button type="button" class="btn btn-xs btn-success ms-2 mailbox-add-verified-btn" '
                    + 'data-email="' + escapeHtml(r.email) + '" data-source-mailbox-id="' + d.source_mailbox_id + '">'
                    + '<i class="fas fa-plus me-1"></i>Add as Mailbox</button>';
            }
            html += '</li>';
        });

        html += '</ul>';
        resultsEl.innerHTML = html;

        resultsEl.querySelectorAll('.mailbox-add-verified-btn').forEach(function(el){
            el.addEventListener('click', function(){
                mailboxAddVerified(el.getAttribute('data-email'), el.getAttribute('data-source-mailbox-id'));
            });
        });
    })
    .catch(function(){
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-satellite-dish me-1"></i>Check Access';
        resultsEl.innerHTML = '<div class="alert alert-danger py-2 mb-0">Request failed</div>';
    });
}

function mailboxAddVerified(email, sourceMailboxId){
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'post.php';

    var fields = {
        csrf_token: '<?= addslashes($_SESSION['csrf_token']) ?>',
        add_verified_mailbox: '1',
        mailbox_email: email,
        source_mailbox_id: sourceMailboxId
    };
    Object.keys(fields).forEach(function(key){
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

function escapeHtml(str){
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

var mailboxCheckAccessBtn = document.getElementById('mailbox_check_access_btn');
if (mailboxCheckAccessBtn) { mailboxCheckAccessBtn.addEventListener('click', mailboxCheckAccessRun); }
</script>
<?php } ?>

<?php
require_once '../../../includes/modal_footer.php';
