<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_credential');

$credential_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM credentials WHERE credential_id = $credential_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$client_id               = intval($row['credential_client_id']);
$credential_name         = nullable_htmlentities($row['credential_name']);
$credential_description  = nullable_htmlentities($row['credential_description']);
$credential_uri          = nullable_htmlentities($row['credential_uri']);
$credential_uri_2        = nullable_htmlentities($row['credential_uri_2']);
$credential_uri_link     = sanitize_url($row['credential_uri']);
$credential_uri_2_link   = sanitize_url($row['credential_uri_2']);
$credential_username     = nullable_htmlentities(decryptCredentialEntry($row['credential_username']));
$credential_password_raw = decryptCredentialEntry($row['credential_password']);
$credential_password     = nullable_htmlentities($credential_password_raw);
$credential_otp_secret   = nullable_htmlentities(decryptOtpSecret($row['credential_otp_secret'] ?? ''));
$credential_note         = nullable_htmlentities($row['credential_note']);
$credential_created_at   = nullable_htmlentities($row['credential_created_at']);

if (empty($credential_otp_secret)) {
    $otp_display = '<span class="text-muted">—</span>';
} else {
    $otp_display = "<span class='otp-reveal-trigger' data-credential-id='$credential_id'><i class='far fa-clock me-1'></i><span id='otp_$credential_id'><em class='text-muted'>Hover to reveal…</em></span></span>";
}

enforceClientAccess();

ob_start();
?>

<div class="modal-header bg-dark text-white">
    <div class="d-flex align-items-center">
        <i class="fas fa-fw fa-key fa-2x me-3"></i>
        <div>
            <h5 class="modal-title mb-0"><?= $credential_name ?></h5>
            <?php if ($credential_description) { ?>
                <small class="text-muted"><?= $credential_description ?></small>
            <?php } ?>
        </div>
    </div>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<div class="modal-body">

    <table class="table table-sm table-borderless mb-0">
        <tbody>

            <tr>
                <td class="text-muted" style="width:130px;white-space:nowrap;"><i class="fas fa-fw fa-user me-1"></i>Username</td>
                <td>
                    <?php if ($credential_username) { ?>
                        <span id="cred-username-<?= $credential_id ?>"><?= $credential_username ?></span>
                        <button class="btn btn-sm clipboardjs ms-1" type="button" title="Copy username" data-clipboard-text="<?= $credential_username ?>"><i class="far fa-copy text-secondary"></i></button>
                    <?php } else { ?>
                        <span class="text-muted">—</span>
                    <?php } ?>
                </td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-lock me-1"></i>Password</td>
                <td>
                    <?php if ($credential_password_raw) { ?>
                        <span id="cred-pw-<?= $credential_id ?>"
                            data-pw="<?= htmlspecialchars($credential_password_raw, ENT_QUOTES, 'UTF-8') ?>"
                            style="font-family:monospace;letter-spacing:.1em;">••••••••</span>
                        <button id="cred-pw-toggle-<?= $credential_id ?>" class="btn btn-sm ms-1" type="button" title="Show / hide">
                            <i class="far fa-eye text-secondary"></i>
                        </button>
                        <button class="btn btn-sm clipboardjs" type="button" title="Copy password" data-clipboard-text="<?= $credential_password ?>"><i class="far fa-copy text-secondary"></i></button>
                    <?php } else { ?>
                        <span class="text-muted">—</span>
                    <?php } ?>
                </td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-shield-alt me-1"></i>TOTP</td>
                <td><?= $otp_display ?></td>
            </tr>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-link me-1"></i>URI</td>
                <td>
                    <?php if ($credential_uri) { ?>
                        <a href="<?= $credential_uri_link ?>" target="_blank" rel="noopener noreferrer"><?= $credential_uri ?></a>
                        <button class="btn btn-sm clipboardjs ms-1" type="button" title="Copy URI" data-clipboard-text="<?= $credential_uri ?>"><i class="far fa-copy text-secondary"></i></button>
                    <?php } else { ?>
                        <span class="text-muted">—</span>
                    <?php } ?>
                </td>
            </tr>

            <?php if ($credential_uri_2) { ?>
            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-link me-1"></i>URI 2</td>
                <td>
                    <a href="<?= $credential_uri_2_link ?>" target="_blank" rel="noopener noreferrer"><?= $credential_uri_2 ?></a>
                    <button class="btn btn-sm clipboardjs ms-1" type="button" title="Copy URI 2" data-clipboard-text="<?= $credential_uri_2 ?>"><i class="far fa-copy text-secondary"></i></button>
                </td>
            </tr>
            <?php } ?>

            <?php if ($credential_note) { ?>
            <tr>
                <td class="text-muted align-top"><i class="fas fa-fw fa-sticky-note me-1"></i>Notes</td>
                <td style="white-space:pre-wrap;"><?= $credential_note ?></td>
            </tr>
            <?php } ?>

            <tr>
                <td class="text-muted"><i class="fas fa-fw fa-calendar-alt me-1"></i>Created</td>
                <td class="text-muted"><?= $credential_created_at ?></td>
            </tr>

        </tbody>
    </table>

</div>

<div class="modal-footer">
    <?php if (lookupUserPermission('module_credential') >= 2) { ?>
    <a href="#" class="btn btn-outline-secondary ajax-modal"
        data-modal-url="modals/credential/credential_edit.php?id=<?= $credential_id ?>">
        <i class="fas fa-edit me-1"></i>Edit
    </a>
    <?php } ?>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Close</button>
</div>

<script src="js/credential_show_otp_via_id.js"></script>
<?php if ($credential_password_raw) { ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
(function () {
    var toggleBtn = document.getElementById('cred-pw-toggle-<?= $credential_id ?>');
    var pwSpan = document.getElementById('cred-pw-<?= $credential_id ?>');
    if (toggleBtn && pwSpan) {
        toggleBtn.addEventListener('click', function () {
            var hidden = pwSpan.textContent !== '••••••••';
            pwSpan.textContent = hidden ? '••••••••' : pwSpan.dataset.pw;
        });
    }
})();
</script>
<?php } ?>

<?php
require_once '../../../includes/modal_footer.php';
