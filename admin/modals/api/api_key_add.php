<?php

require_once '../../../includes/modal_header.php';

$key = randomString(32);
$decryptPW = randomString(32);

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-key me-2"></i>New Key</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <div class="modal-body">

        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-api-details">Details</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-api-keys">Keys</a>
            </li>
        </ul>
        <hr>

        <div class="tab-content">

            <div class="tab-pane fade show active" id="pills-api-details">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="key" value="<?php echo $key ?>">
                <input type="hidden" name="password" value="<?php echo $decryptPW ?>">

                <div class="form-group">
                    <label>Name <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-sticky-note"></i></span>
                        </div>
                        <input type="text" class="form-control" name="name" placeholder="Key Name" maxlength="255" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label>Expiration Date <strong class="text-danger">*</strong></label>
                    <select class="form-select mb-2" id="apiKeyExpirationPreset" aria-label="Expiration interval">
                        <option value="30">30 days (recommended)</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                        <option value="custom">Custom date</option>
                    </select>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                        </div>
                        <input type="date" class="form-control" id="apiKeyExpire" name="expire" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" max="2999-12-31" readonly required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Department Access <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                        </div>
                        <select class="form-control select2" name="client" required>
                            <option value="" selected disabled>Select a department</option>
                            <?php
                            $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                            while ($row = mysqli_fetch_assoc($sql)) {
                                $client_id = intval($row['client_id']);
                                $client_name = nullable_htmlentities($row['client_name']); ?>
                                <option value="<?php echo $client_id; ?>"><?php echo "$client_name  (Department ID: $client_id)"; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Permission <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-shield-alt"></i></span>
                        </div>
                        <select class="form-control" id="apiKeyPermission" name="permission" required>
                            <option value="read">Read only &mdash; GET requests</option>
                            <option value="write">Read &amp; write &mdash; create and update</option>
                        </select>
                    </div>
                    <div class="form-check mt-2">
                        <input type="checkbox" class="form-check-input" id="apiKeyAllowDelete" name="allow_delete" value="1" disabled>
                        <label class="form-check-label" for="apiKeyAllowDelete">Also allow deleting and archiving records</label>
                    </div>
                    <small class="text-muted">Delete access requires write permission and explicit opt-in.</small>
                </div>
                <details class="mb-3">
                    <summary class="mb-2">Security restrictions</summary>
                    <label for="apiKeyAllowedIps">Allowed IP addresses or networks</label>
                    <textarea class="form-control" id="apiKeyAllowedIps" name="allowed_ips" rows="3" maxlength="8192" placeholder="203.0.113.10&#10;2001:db8::/32"></textarea>
                    <small class="text-muted">Optional. Enter one IPv4 or IPv6 address or CIDR network per line. Leave blank to allow any source address.</small>
                </details>
            </div>

            <div class="tab-pane fade" id="pills-api-keys">
                <div class="form-group">
                    <label>API Key <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                        </div>
                        <input type="text" class="form-control" value="<?php echo $key ?>" required disabled>
                        <div class="input-group-append">
                            <button class="btn btn-default clipboardjs" type="button" data-clipboard-text="<?php echo $key; ?>"><i class="fa fa-fw fa-copy"></i></button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Login credential decryption password <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-unlock-alt"></i></span>
                        </div>
                        <input type="text" class="form-control" value="<?php echo $decryptPW ?>" required disabled>
                        <div class="input-group-append">
                            <button class="btn btn-default clipboardjs" type="button" data-clipboard-text="<?php echo $decryptPW; ?>"><i class="fa fa-fw fa-copy"></i></button>
                        </div>
                    </div>
                </div>
                <br>
                <div class="form-group">
                    <label>I have made a copy of the key(s)<strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <input type="checkbox" name="ack" value="1" required>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        </div>
    <div class="modal-footer">
        <button type="submit" name="add_api_key" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var preset = document.getElementById('apiKeyExpirationPreset');
    var expiry = document.getElementById('apiKeyExpire');
    var dates = <?= json_encode(['30' => date('Y-m-d', strtotime('+30 days')), '60' => date('Y-m-d', strtotime('+60 days')), '90' => date('Y-m-d', strtotime('+90 days'))]) ?>;
    preset.addEventListener('change', function () {
        expiry.readOnly = preset.value !== 'custom';
        if (dates[preset.value]) expiry.value = dates[preset.value];
        if (!expiry.readOnly) expiry.focus();
    });
    var permission = document.getElementById('apiKeyPermission');
    var allowDelete = document.getElementById('apiKeyAllowDelete');
    permission.addEventListener('change', function () {
        allowDelete.disabled = permission.value !== 'write';
        if (allowDelete.disabled) allowDelete.checked = false;
    });
})();
</script>

<?php
require_once '../../../includes/modal_footer.php';
