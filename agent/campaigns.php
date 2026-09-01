<?php

// Default Column Sortby/Order Filter
$sort = "campaign_created_at";
$order = "DESC";

require_once "includes/inc_all.php";

// Perms
enforceUserPermission('module_sales');

require_once __DIR__ . '/modals/crm/crm_helpers.php';

// Load an existing campaign for editing (if requested)
$editing = false;
$campaign_id = 0;
$campaign_name = '';
$campaign_segment_id = intval($_GET['segment_id'] ?? 0); // preselect when arriving from a segment
$campaign_subject = '';
$campaign_body = '';
$campaign_status = 'draft';
$campaign_recipient_count = 0;

if (isset($_GET['campaign_id'])) {
    $campaign_id = intval($_GET['campaign_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM crm_campaigns WHERE campaign_id = $campaign_id LIMIT 1"));
    if ($row) {
        $editing = true;
        $campaign_name = nullable_htmlentities($row['campaign_name']);
        $campaign_segment_id = intval($row['campaign_segment_id']);
        $campaign_subject = nullable_htmlentities($row['campaign_subject']);
        $campaign_body = $row['campaign_body']; // escaped on output into textarea
        $campaign_status = $row['campaign_status'];
        $rc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS cnt FROM crm_campaign_recipients WHERE campaign_id = $campaign_id"));
        $campaign_recipient_count = intval($rc['cnt']);
    }
}

$is_sent = ($editing && $campaign_status === 'sent');

// Segment options
$segment_options = mysqli_query($mysqli, "SELECT segment_id, segment_name FROM crm_segments ORDER BY segment_name ASC");

// Campaign list
$sql_campaigns = mysqli_query($mysqli, "SELECT crm_campaigns.*, segment_name,
        (SELECT COUNT(*) FROM crm_campaign_recipients r WHERE r.campaign_id = crm_campaigns.campaign_id) AS recipient_count
    FROM crm_campaigns
    LEFT JOIN crm_segments ON campaign_segment_id = segment_id
    ORDER BY campaign_created_at DESC");

?>

<div class="row">
    <div class="col-md-6">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2">
                    <i class="fas fa-fw fa-bullhorn me-2"></i><?= $editing ? 'Edit Campaign' : 'New Campaign' ?>
                </h3>
                <?php if ($editing) { ?>
                    <div class="card-tools">
                        <a href="campaigns.php" class="btn btn-secondary btn-sm"><i class="fas fa-plus me-1"></i>New</a>
                    </div>
                <?php } ?>
            </div>
            <div class="card-body">

                <?php if ($is_sent) { ?>
                    <div class="alert alert-success">
                        <i class="fas fa-fw fa-check-circle me-1"></i>
                        This campaign was sent to <strong><?= $campaign_recipient_count ?></strong> recipient(s). It is now read-only.
                    </div>
                <?php } ?>

                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <?php if ($editing) { ?>
                        <input type="hidden" name="edit_campaign" value="1">
                        <input type="hidden" name="campaign_id" value="<?= $campaign_id ?>">
                    <?php } else { ?>
                        <input type="hidden" name="add_campaign" value="1">
                    <?php } ?>

                    <div class="form-group">
                        <label>Campaign Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="name" maxlength="150" value="<?= $campaign_name ?>" placeholder="Internal name" <?= $is_sent ? 'readonly' : '' ?> required>
                    </div>

                    <div class="form-group">
                        <label>Target Segment <strong class="text-danger">*</strong></label>
                        <select class="form-control select2" name="segment_id" id="campaign_segment_id" <?= $is_sent ? 'disabled' : '' ?>>
                            <option value="0">- Select a segment -</option>
                            <?php while ($s = mysqli_fetch_assoc($segment_options)) {
                                $s_id = intval($s['segment_id']); ?>
                                <option value="<?= $s_id ?>" <?= ($s_id === $campaign_segment_id) ? 'selected' : '' ?>><?= nullable_htmlentities($s['segment_name']) ?></option>
                            <?php } ?>
                        </select>
                        <small class="text-muted">Recipients with a valid email: <strong id="campaign_recipient_preview">-</strong></small>
                    </div>

                    <div class="form-group">
                        <label>Subject <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="subject" maxlength="255" value="<?= $campaign_subject ?>" placeholder="Email subject line" <?= $is_sent ? 'readonly' : '' ?> required>
                    </div>

                    <div class="form-group">
                        <label>Body</label>
                        <textarea class="form-control" name="body" rows="8" placeholder="Email body (basic HTML allowed)" <?= $is_sent ? 'readonly' : '' ?>><?= htmlspecialchars($campaign_body ?? '') ?></textarea>
                        <small class="text-muted">An unsubscribe-friendly footer is appended automatically on send.</small>
                    </div>

                    <?php if (!$is_sent) { ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= $editing ? 'Update Draft' : 'Save Draft' ?></button>
                    <?php } ?>
                </form>

                <?php if ($editing && !$is_sent) { ?>
                    <hr>
                    <form action="post.php" method="post" id="campaignSendForm">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="send_campaign" value="1">
                        <input type="hidden" name="campaign_id" value="<?= $campaign_id ?>">
                        <button type="submit" class="btn btn-success btn-block"><i class="fas fa-paper-plane me-2"></i>Send Campaign</button>
                        <small class="text-muted d-block mt-1">Save your latest changes first, then send.</small>
                    </form>
                <?php } ?>

            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-fw fa-list me-2"></i>Campaigns</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-borderless">
                        <thead class="<?php if (mysqli_num_rows($sql_campaigns) == 0) { echo 'd-none'; } ?>">
                        <tr>
                            <th>Name</th>
                            <th>Segment</th>
                            <th>Status</th>
                            <th class="text-end">Sent</th>
                            <th class="text-center">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_campaigns)) {
                            $c_id = intval($row['campaign_id']);
                            $c_name = nullable_htmlentities($row['campaign_name']);
                            $c_segment = nullable_htmlentities($row['segment_name']);
                            $c_status = nullable_htmlentities($row['campaign_status']);
                            $c_recipients = intval($row['recipient_count']);
                            $status_badge = ($c_status === 'sent') ? 'success' : 'secondary';
                            ?>
                            <tr>
                                <td class="text-bold"><a href="campaigns.php?campaign_id=<?= $c_id ?>"><i class="fas fa-fw fa-bullhorn text-secondary me-1"></i><?= $c_name ?></a></td>
                                <td class="small"><?= $c_segment ? $c_segment : '<span class="text-muted">-</span>' ?></td>
                                <td><span class="badge badge-<?= $status_badge ?>"><?= ucfirst($c_status) ?></span></td>
                                <td class="text-end"><?= $c_recipients ?></td>
                                <td>
                                    <div class="dropdown dropleft text-center">
                                        <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-h"></i></button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item" href="campaigns.php?campaign_id=<?= $c_id ?>">
                                                <i class="fas fa-fw fa-edit me-2"></i>Open
                                            </a>
                                            <?php if (lookupUserPermission("module_sales") >= 3) { ?>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger confirm-link" href="post.php?delete_campaign=<?= $c_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                                </a>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    function crmRefreshCampaignRecipients() {
        var sel = document.getElementById('campaign_segment_id');
        if (!sel) { return; }
        var segId = sel.value;
        var out = document.getElementById('campaign_recipient_preview');
        if (!segId || segId === '0') { out.textContent = '-'; return; }
        jQuery.post("crm_ajax.php?segment_preview", {
            csrf_token: '<?= $_SESSION['csrf_token'] ?>',
            segment_id: segId,
            require_email: 1
        }, function (data) {
            var res = (typeof data === 'string') ? JSON.parse(data) : data;
            out.textContent = res.success ? res.count : '-';
        });
    }

    $(document).ready(function () {
        $('#campaign_segment_id').on('change', crmRefreshCampaignRecipients);
        crmRefreshCampaignRecipients();

        // Strict-CSP-safe send confirmation (no inline onsubmit)
        var sendForm = document.getElementById('campaignSendForm');
        if (sendForm) {
            sendForm.addEventListener('submit', function (e) {
                if (!confirm('Send this campaign now? Recipients will be materialized and emails queued.')) {
                    e.preventDefault();
                }
            });
        }
    });
</script>

<?php require_once "../includes/footer.php"; ?>
