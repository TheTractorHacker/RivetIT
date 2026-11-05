<?php
/*
 * Client Portal
 * Contact management for PTC / technical contacts
 */

header("Content-Security-Policy: default-src 'self'");

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirects below need output buffered - otherwise header()
// silently fails ("headers already sent") and the page just dies with no
// content and no redirect.
ob_start();
require_once "includes/inc_all.php";

if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    ob_end_clean();
    // a contact without this role used to be signed out of the whole portal; send them home with a reason instead
    flash_alert(portal_no_access_message(__FILE__), 'warning');
    header("Location: index.php");
    exit();
}

// Check for a contact ID
if (!isset($_GET['id']) || !intval($_GET['id'])) {
    ob_end_clean();
    header("Location: contacts.php");
    exit();
}

$contact_id = intval($_GET['id']);

$sql_contact = mysqli_query(
    $mysqli, "SELECT contact_id, contact_name, contact_email, contact_primary, contact_technical, contact_billing, user_auth_method
    FROM contacts
    LEFT JOIN users ON user_id = contact_user_id
    WHERE contact_id = $contact_id AND contact_client_id = $session_client_id AND contacts.contact_archived_at IS NULL LIMIT 1"
);

$row = mysqli_fetch_assoc($sql_contact);

if ($row) {
    $contact_id = intval($row['contact_id']);
    $contact_name = nullable_htmlentities($row['contact_name']);
    $contact_email = nullable_htmlentities($row['contact_email']);
    $contact_primary = intval($row['contact_primary']);
    $contact_technical = intval($row['contact_technical']);
    $contact_billing = intval($row['contact_billing']);
    $contact_auth_method = nullable_htmlentities($row['user_auth_method']);
} else {
    // an unknown, archived or other-department id is just a dead link, not a reason to end the whole portal session
    ob_end_clean();
    flash_alert('That contact could not be found', 'warning');
    header("Location: contacts.php");
    exit();
}

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item">
            <a href="contacts.php">Contacts</a>
        </li>
        <li class="breadcrumb-item active">Edit Contact</li>
    </ol>

    <div class="col-md-6">
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="contact_id" value="<?php echo $contact_id; ?>">

            <div class="form-group">
                <label>Name <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    </div>
                    <input type="text" class="form-control" name="contact_name" value="<?php echo $contact_name ?>" required maxlength="200">
                </div>
            </div>

            <div class="form-group">
                <label>Email <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                    </div>
                    <input type="email" class="form-control" name="contact_email" value="<?php echo $contact_email ?>" required maxlength="200">
                </div>
            </div>

            <label class="form-label">Roles</label>
            <div class="form-row">
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="form-check form-check">
                            <input type="checkbox" class="form-check-input" id="contactBillingCheckbox" name="contact_billing" value="1" <?php if ($contact_billing == 1) { echo "checked"; } ?>>
                            <label class="form-check-label" for="contactBillingCheckbox">Billing</label>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <div class="form-check form-check">
                            <input type="checkbox" class="form-check-input" id="contactTechnicalCheckbox" name="contact_technical" value="1" <?php if ($contact_technical == 1) { echo "checked"; } ?>>
                            <label class="form-check-label" for="contactTechnicalCheckbox">Technical</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Portal authentication</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user-circle"></i></span>
                    </div>
                    <select class="form-select pe-5 authMethod" name="contact_auth_method">
                        <option value="">No portal access</option>
                        <option value="local" <?php if ($contact_auth_method == "local") { echo "selected"; } ?>>Local (Email and password)</option>
                        <?php if (!empty($config_azure_client_id)) { ?>
                            <option value="azure" <?php if ($contact_auth_method == "azure") { echo "selected"; } ?>>Azure (Microsoft 365)</option>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-text small text-secondary">With "Local", they can sign in once they choose a password with "Forgot password" on the sign-in page (needs outgoing email to be set up). "No portal access" keeps them as a contact only.</div>
            </div>

            <?php /* ?? 0: an admin portal preview deliberately never writes contact_id, so reading it raw
   warned on every view. 0 matches no contact, which is the correct outcome here. */ ?>
<?php if ($contact_primary || $contact_id == ($_SESSION['contact_id'] ?? 0)) { ?>
                <div class="text-secondary small mb-2"><i class="fas fa-lock me-1" aria-hidden="true"></i>This contact is managed by IT and cannot be changed here.</div>
                <a href="contacts.php" class="btn btn-outline-secondary">Back to contacts</a>
            <?php } else { ?>
                <button class="btn btn-primary" name="edit_contact">Save changes</button>
                <a href="contacts.php" class="btn btn-link text-secondary">Cancel</a>
            <?php } ?>
        </form>
    </div>


<?php
require_once "includes/footer.php";
