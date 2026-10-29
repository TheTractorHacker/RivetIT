<?php

require_once '../../../includes/modal_header.php';

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-key me-2"></i>API keys are now integration clients</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<div class="modal-body">
    <p>There is one machine-credential system: <strong>integration clients</strong> (Client ID + secret, short-lived access tokens, scopes and department limits).</p>
    <p class="mb-0">Create new credentials under <a href="/admin/integration_clients.php">Administration &gt; Integration clients</a>.</p>
</div>
<div class="modal-footer">
    <a href="/admin/integration_clients.php" class="btn btn-primary text-bold"><i class="fa fa-plug me-2"></i>Open Integration clients</a>
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>

<?php
require_once '../../../includes/modal_footer.php';
