<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/api_key_form_lib.php';

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\IntegrationScope;

$cid = intval($_GET['id'] ?? 0);
$c = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM integration_clients WHERE client_id = $cid LIMIT 1"));
if (!$c || $c['client_status'] !== 'active') {
    ob_end_clean();
    echo json_encode(['content' => '<div class="modal-body">That API key is not active (or no longer exists).</div>']);
    exit;
}
$scopes = IntegrationAuth::parseScopes((string) $c['client_scopes']);
$depts = IntegrationScope::allowedDepartmentIds($mysqli, $c);   // null = all departments

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-key me-2"></i>Edit API key: <strong><?php echo nullable_htmlentities($c['client_name']); ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal" aria-label="Close">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off" data-ak-form>
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
    <div class="modal-body">
        <p class="text-muted small mb-3">Changes apply to access tokens that are already issued at their next request. The name, Client ID and secrets stay as they are.</p>
        <div class="row g-3 mb-3">
            <div class="col-md-5"><label class="form-label" for="akEditExpires">Expires</label>
                <input type="date" class="form-control" id="akEditExpires" name="expires" value="<?php echo $c['client_expires_at'] ? nullable_htmlentities(substr($c['client_expires_at'], 0, 10)) : ''; ?>"></div>
            <div class="col-md-7"><label class="form-label" for="akEditIps">Allowed IP addresses / CIDR</label>
                <textarea class="form-control" id="akEditIps" name="allowed_ips" rows="2" placeholder="Blank = any address."><?php echo nullable_htmlentities((string) $c['client_allowed_ips']); ?></textarea></div>
        </div>
        <?php echo ak_dept_fields($mysqli, $depts === null, $depts ?? [], 'akEditDept'); ?>
        <?php echo ak_scope_fields($scopes, 'akEdit'); ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="update_integration_client" value="1" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
