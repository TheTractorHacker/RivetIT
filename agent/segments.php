<?php

// Default Column Sortby/Order Filter
$sort = "segment_created_at";
$order = "DESC";

require_once "includes/inc_all.php";

// Perms
enforceUserPermission('module_sales');

require_once __DIR__ . '/modals/crm/crm_helpers.php';

// Tag options (used by contacts)
$tags_options = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags ORDER BY tag_name ASC");

// Client options (respecting client access scope)
$clients_options = mysqli_query($mysqli, "SELECT clients.client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");

// Saved segments
$sql_segments = mysqli_query($mysqli, "SELECT * FROM crm_segments ORDER BY segment_created_at DESC");

?>

<div class="row">
    <div class="col-md-5">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-fw fa-filter me-2"></i>New Segment</h3>
            </div>
            <div class="card-body">
                <form action="post.php" method="post" autocomplete="off" id="segmentForm">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="add_segment" value="1">

                    <div class="form-group">
                        <label>Segment Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="name" maxlength="150" placeholder="e.g. Active leads with open deals" required>
                    </div>

                    <div class="form-group">
                        <label>Tag</label>
                        <select class="form-control select2" name="tag_id" id="seg_tag_id">
                            <option value="0">- Any Tag -</option>
                            <?php while ($t = mysqli_fetch_assoc($tags_options)) { ?>
                                <option value="<?= intval($t['tag_id']) ?>"><?= nullable_htmlentities($t['tag_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Client</label>
                        <select class="form-control select2" name="client_id" id="seg_client_id">
                            <option value="0">- Any Client -</option>
                            <?php while ($c = mysqli_fetch_assoc($clients_options)) { ?>
                                <option value="<?= intval($c['client_id']) ?>"><?= nullable_htmlentities($c['client_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Lead / Customer Status</label>
                        <select class="form-control" name="lead_status" id="seg_lead_status">
                            <option value="">- Any -</option>
                            <option value="lead">Leads only</option>
                            <option value="customer">Customers only</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <div class="form-check form-check">
                            <input type="checkbox" class="form-check-input" name="has_open_opportunity" id="seg_has_open_opportunity" value="1">
                            <label class="form-check-label" for="seg_has_open_opportunity">Only contacts with an open opportunity</label>
                        </div>
                    </div>

                    <hr>

                    <div class="alert alert-secondary mb-2">
                        <i class="fas fa-fw fa-users me-1"></i>
                        Matches: <strong id="seg_preview_count">0</strong> contact(s)
                    </div>
                    <ul class="list-unstyled small text-muted mb-3" id="seg_preview_list"></ul>

                    <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-save me-2"></i>Save Segment</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h3 class="card-title mt-2"><i class="fas fa-fw fa-layer-group me-2"></i>Saved Segments</h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-borderless">
                        <thead class="<?php if (mysqli_num_rows($sql_segments) == 0) { echo 'd-none'; } ?>">
                        <tr>
                            <th>Name</th>
                            <th>Criteria</th>
                            <th class="text-end">Contacts</th>
                            <th class="text-center">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_segments)) {
                            $segment_id = intval($row['segment_id']);
                            $segment_name = nullable_htmlentities($row['segment_name']);
                            $criteria = crmCriteriaFromJson($row['segment_criteria_json']);
                            $criteria_summary = crmCriteriaSummary($mysqli, $criteria);
                            $segment_count = crmContactCount($mysqli, $criteria, $client_access_string ?? '', $session_is_admin ?? false);
                            ?>
                            <tr>
                                <td class="text-bold"><i class="fas fa-fw fa-filter text-secondary me-1"></i><?= $segment_name ?></td>
                                <td class="small text-muted"><?= $criteria_summary ?></td>
                                <td class="text-end"><span class="badge text-bg-secondary p-2"><?= $segment_count ?></span></td>
                                <td>
                                    <div class="dropdown dropleft text-center">
                                        <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-h"></i></button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item" href="campaigns.php?segment_id=<?= $segment_id ?>">
                                                <i class="fas fa-fw fa-paper-plane me-2"></i>New Campaign
                                            </a>
                                            <?php if (lookupUserPermission("module_sales") >= 3) { ?>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger confirm-link" href="post.php?delete_segment=<?= $segment_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
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
    function crmRefreshSegmentPreview() {
        jQuery.post("crm_ajax.php?segment_preview", {
            csrf_token: '<?= $_SESSION['csrf_token'] ?>',
            tag_id: document.getElementById('seg_tag_id').value,
            client_id: document.getElementById('seg_client_id').value,
            lead_status: document.getElementById('seg_lead_status').value,
            has_open_opportunity: document.getElementById('seg_has_open_opportunity').checked ? 1 : 0
        }, function (data) {
            var res = (typeof data === 'string') ? JSON.parse(data) : data;
            if (!res.success) { return; }
            document.getElementById('seg_preview_count').textContent = res.count;
            var list = document.getElementById('seg_preview_list');
            list.innerHTML = '';
            res.preview.forEach(function (p) {
                var li = document.createElement('li');
                li.textContent = p.name + (p.email ? ' (' + p.email + ')' : '');
                list.appendChild(li);
            });
        });
    }

    $(document).ready(function () {
        $('#seg_tag_id, #seg_client_id, #seg_lead_status').on('change', crmRefreshSegmentPreview);
        $('#seg_has_open_opportunity').on('change', crmRefreshSegmentPreview);
        crmRefreshSegmentPreview();
    });
</script>

<?php require_once "../includes/footer.php"; ?>
