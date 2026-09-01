<?php
require_once '../../../includes/modal_header.php';

$outtake_id = intval($_GET['outtake_id']);
$ticket_id  = intval($_GET['ticket_id']);

$sql = mysqli_query($mysqli, "SELECT ot.outtake_signed_at, t.ticket_prefix, t.ticket_number, t.ticket_details, t.ticket_client_id, c.client_name, co.contact_name
    FROM ticket_outtake_forms ot
    JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id
    LEFT JOIN clients c ON t.ticket_client_id = c.client_id
    LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
    WHERE ot.outtake_id = $outtake_id AND ot.outtake_ticket_id = $ticket_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);

// Same client-scoping check used by the sibling ticket_schedule_edit.php modal
// - without it, any logged-in agent could load ANY client's outtake form by
// guessing/incrementing the outtake_id, regardless of their own client access.
if ($row) {
    $client_id = intval($row['ticket_client_id']);
    if ($client_id) enforceClientAccess();
}

ob_start();

if (!$row) {
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-signature me-2"></i>Sign Outtake Form</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">
    <div class="alert alert-danger mb-0">Outtake form not found.</div>
</div>
<?php
} elseif (!empty($row['outtake_signed_at'])) {
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-signature me-2"></i>Sign Outtake Form</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">
    <div class="alert alert-success mb-0"><i class="fas fa-check-circle me-2"></i>This outtake form has already been signed.</div>
</div>
<?php
} else {
    $ticket_num    = nullable_htmlentities($row['ticket_prefix']) . intval($row['ticket_number']);
    $ticket_detail = $row['ticket_details'] ? trim(strip_tags($row['ticket_details'])) : '';
    $default_name  = nullable_htmlentities($row['contact_name'] ?: $row['client_name']);
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-signature me-2"></i>Sign Outtake Form &mdash; <?= $ticket_num ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<div class="modal-body">

    <?php if ($ticket_detail) { ?>
    <div class="form-group">
        <label class="text-uppercase text-secondary fw-bold" style="font-size:11px;">Ticket Problem</label>
        <div class="border rounded p-2 bg-light" style="max-height:120px;overflow-y:auto;white-space:pre-wrap;font-size:13px;"><?= htmlspecialchars($ticket_detail) ?></div>
    </div>
    <?php } ?>

    <div class="form-group">
        <label class="fw-bold">Full Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="outtake_signed_name" value="<?= $default_name ?>" placeholder="Type full name">
    </div>

    <div class="form-group mb-2">
        <label class="fw-bold">Signature <span class="text-danger">*</span></label>
        <canvas id="outtake_sig_canvas" style="border:2px solid #ccc;border-radius:4px;background:#fff;display:block;width:100%;height:140px;touch-action:none;cursor:crosshair;"></canvas>
        <input type="hidden" id="outtake_sig_data">
        <div class="mt-2 d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary me-3" id="outtake_sig_clear">Clear</button>
            <small class="text-secondary">Draw signature in the box above</small>
        </div>
    </div>

    <div class="form-check mt-1 mb-2">
        <input class="form-check-input" type="checkbox" id="outtake_terms_agree">
        <label class="form-check-label" style="font-size:13px;" for="outtake_terms_agree">
            I have read and agree to the <a href="https://foleyit.com/ticket-terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>
        </label>
    </div>

    <div id="outtake_sign_error" class="alert alert-danger d-none mb-0"></div>

</div>
<div class="modal-footer">
    <button type="button" class="btn btn-primary" id="outtake_sign_submit"><i class="fas fa-pen-nib me-2"></i>Sign &amp; Save</button>
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var pad = null;
    var $modal = $('#outtake_sig_canvas').closest('.modal');

    // The canvas has zero size while the modal is still display:none, so
    // wait until Bootstrap has actually shown it before sizing the drawing buffer.
    function setupPad() { pad = initSignaturePad('outtake_sig_canvas', 'outtake_sig_data'); }
    if ($modal.hasClass('show')) {
        setupPad();
    } else {
        $modal.one('shown.bs.modal', setupPad);
    }

    document.getElementById('outtake_sig_clear').addEventListener('click', function () {
        if (pad) pad.clear();
    });

    document.getElementById('outtake_sign_submit').addEventListener('click', function () {
        var btn = this;
        var name   = document.getElementById('outtake_signed_name').value.trim();
        var sig    = document.getElementById('outtake_sig_data').value;
        var agreed = document.getElementById('outtake_terms_agree').checked;
        var errBox = document.getElementById('outtake_sign_error');
        errBox.classList.add('d-none');

        if (!name)   { errBox.textContent = 'Please enter a full name.'; errBox.classList.remove('d-none'); return; }
        if (!sig)    { errBox.textContent = 'Please draw a signature.'; errBox.classList.remove('d-none'); return; }
        if (!agreed) { errBox.textContent = 'Please agree to the Terms & Conditions.'; errBox.classList.remove('d-none'); return; }

        btn.disabled = true;
        fetch('post.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({
                sign_outtake_in_person: 1,
                csrf_token: <?= json_encode($_SESSION['csrf_token']) ?>,
                outtake_id: <?= json_encode($outtake_id) ?>,
                signed_name: name,
                signature: sig
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                $(btn).closest('.modal').modal('hide');
            } else {
                errBox.textContent = data.error || 'Failed to save signature.';
                errBox.classList.remove('d-none');
                btn.disabled = false;
            }
        })
        .catch(function () {
            errBox.textContent = 'Failed to save signature.';
            errBox.classList.remove('d-none');
            btn.disabled = false;
        });
    });
})();
</script>
<?php
}

require_once '../../../includes/modal_footer.php';
