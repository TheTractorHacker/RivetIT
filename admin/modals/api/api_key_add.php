<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/api_key_form_lib.php';

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-key me-2"></i>Add API key</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal" aria-label="Close">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off" data-ak-form>
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <div class="modal-body">
        <p class="text-muted small">The key's <strong>Client ID</strong> and <strong>secret</strong> are the username and password the other system uses. The secret is shown once, right after you create the key.</p>
        <div class="row g-3 mb-3">
            <div class="col-md-7"><label class="form-label" for="akNewName">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="akNewName" name="name" maxlength="150" required placeholder="e.g. QMS (production)" autofocus></div>
            <div class="col-md-5"><label class="form-label" for="akNewExpires">Expires (optional)</label>
                <input type="date" class="form-control" id="akNewExpires" name="expires" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"></div>
            <div class="col-12"><label class="form-label" for="akNewDesc">Description (optional)</label>
                <input type="text" class="form-control" id="akNewDesc" name="description" maxlength="500"></div>
        </div>
        <?php echo ak_dept_fields($mysqli, null, [], 'akNewDept'); ?>
        <?php echo ak_scope_fields([], 'akNew'); ?>
        <div class="mb-0"><label class="form-label" for="akNewIps">Allowed IP addresses / CIDR (optional)</label>
            <textarea class="form-control" id="akNewIps" name="allowed_ips" rows="2" placeholder="One per line or comma separated. Blank = any address."></textarea></div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_integration_client" value="1" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create key and show secret</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
