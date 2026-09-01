<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_sales', 2);

$opportunity_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT * FROM opportunities WHERE opportunity_id = $opportunity_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);

if (!$row) {
    ob_start();
    echo "<div class='modal-header bg-dark'><h5 class='modal-title'>Opportunity</h5><button type='button' class='close text-white' data-bs-dismiss='modal'><span>&times;</span></button></div>";
    echo "<div class='modal-body'><div class='alert alert-danger mb-0'>Opportunity not found.</div></div>";
    require_once '../../../includes/modal_footer.php';
    exit;
}

$opportunity_name = nullable_htmlentities($row['opportunity_name']);
$opportunity_client_id = intval($row['opportunity_client_id']);
$opportunity_contact_id = intval($row['opportunity_contact_id']);
$opportunity_stage = nullable_htmlentities($row['opportunity_stage']);
$opportunity_amount = floatval($row['opportunity_amount']);
$opportunity_probability = intval($row['opportunity_probability']);
$opportunity_close_date = nullable_htmlentities($row['opportunity_close_date']);
$opportunity_owner = intval($row['opportunity_owner']);
$opportunity_notes = nullable_htmlentities($row['opportunity_notes']);

// Enforce client access if tied to a client
if ($opportunity_client_id) {
    $client_id = $opportunity_client_id;
    enforceClientAccess();
}

$stages = getOpportunityStages();

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-funnel-dollar me-2"></i>Edit Opportunity</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="opportunity_id" value="<?php echo $opportunity_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Opportunity Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-funnel-dollar"></i></span>
                </div>
                <input type="text" class="form-control" name="name" value="<?php echo $opportunity_name; ?>" maxlength="255" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Client</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-users"></i></span>
                </div>
                <select class="form-control select2" name="client_id" id="opportunity_client_id">
                    <option value="0">- No Client -</option>
                    <?php
                    $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
                    while ($crow = mysqli_fetch_assoc($sql)) {
                        $c_id = intval($crow['client_id']);
                        $c_name = nullable_htmlentities($crow['client_name']);
                    ?>
                    <option value="<?php echo $c_id; ?>" <?php if ($c_id === $opportunity_client_id) { echo 'selected'; } ?>><?php echo $c_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Contact <small class="text-secondary">(optional)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                </div>
                <select class="form-control select2" name="contact_id" id="opportunity_contact_id">
                    <option value="0" data-client-id="0">- No Contact -</option>
                    <?php
                    $contact_sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_client_id FROM contacts WHERE contact_archived_at IS NULL ORDER BY contact_name ASC");
                    while ($crow = mysqli_fetch_assoc($contact_sql)) {
                        $ct_id = intval($crow['contact_id']);
                        $ct_name = nullable_htmlentities($crow['contact_name']);
                        $ct_client = intval($crow['contact_client_id']);
                    ?>
                    <option value="<?php echo $ct_id; ?>" data-client-id="<?php echo $ct_client; ?>" <?php if ($ct_id === $opportunity_contact_id) { echo 'selected'; } ?>><?php echo $ct_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Stage</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-stream"></i></span>
                    </div>
                    <select class="form-control select2" name="stage" id="opportunity_stage">
                        <?php foreach ($stages as $stage_name => $stage_prob) { ?>
                            <option value="<?php echo $stage_name; ?>" data-probability="<?php echo $stage_prob; ?>" <?php if ($stage_name === $opportunity_stage) { echo 'selected'; } ?>><?php echo $stage_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Probability (%)</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-percent"></i></span>
                    </div>
                    <input type="number" min="0" max="100" step="1" class="form-control" name="probability" id="opportunity_probability" value="<?php echo $opportunity_probability; ?>">
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Amount</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span>
                    </div>
                    <input type="number" step="0.01" min="0" class="form-control" name="amount" value="<?php echo $opportunity_amount; ?>">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Expected Close Date</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-check"></i></span>
                    </div>
                    <input type="date" class="form-control" name="close_date" value="<?php echo $opportunity_close_date; ?>">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Owner</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-tie"></i></span>
                </div>
                <select class="form-control select2" name="owner">
                    <option value="0">- Unassigned -</option>
                    <?php
                    $user_sql = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
                    while ($urow = mysqli_fetch_assoc($user_sql)) {
                        $u_id = intval($urow['user_id']);
                        $u_name = nullable_htmlentities($urow['user_name']);
                    ?>
                    <option value="<?php echo $u_id; ?>" <?php if ($u_id === $opportunity_owner) { echo 'selected'; } ?>><?php echo $u_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea class="form-control" rows="4" name="notes" placeholder="Notes about this opportunity"><?php echo $opportunity_notes; ?></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_opportunity" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <?php if (lookupUserPermission('module_sales') >= 3) { ?>
            <a href="post.php?delete_opportunity=<?php echo $opportunity_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>" class="btn btn-danger confirm-link"><i class="fas fa-trash me-2"></i>Delete</a>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    $('#opportunity_stage').on('change', function () {
        var prob = $(this).find(':selected').data('probability');
        if (prob !== undefined) {
            $('#opportunity_probability').val(prob);
        }
    });

    function filterContacts(clientId) {
        clientId = parseInt(clientId, 10) || 0;
        var $contact = $('#opportunity_contact_id');
        var current = parseInt($contact.val(), 10) || 0;
        $contact.find('option').each(function () {
            var optClient = parseInt($(this).data('client-id'), 10) || 0;
            var optVal = parseInt($(this).val(), 10) || 0;
            if (optClient === 0 || clientId === 0 || optClient === clientId) {
                $(this).prop('disabled', false);
            } else if (optVal !== current) {
                $(this).prop('disabled', true);
            }
        });
        $contact.trigger('change.select2');
    }

    $('#opportunity_client_id').on('change', function () {
        filterContacts($(this).val());
    });

    filterContacts($('#opportunity_client_id').val());
})();
</script>

<?php
require_once '../../../includes/modal_footer.php';
