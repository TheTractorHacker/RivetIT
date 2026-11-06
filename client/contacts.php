<?php
/*
* Client Portal
* Contact management for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirect below needs output buffered - otherwise header()
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

$contacts_sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email, contact_primary, contact_technical, contact_billing FROM contacts WHERE contact_client_id = $session_client_id AND contacts.contact_archived_at IS NULL ORDER BY contact_created_at");
?>

    <div class="row mb-4">
        <div class="col">
            <h3 class="text-nowrap"><i class="fas fa-fw fa-address-book me-2"></i>Contacts</h3>
        </div>
        <div class="col-auto ms-auto text-end">
            <a href="contact_add.php" class="btn btn-primary" role="button"><i class="fas fa-plus me-2"></i>New Contact</a>
        </div>
    </div>

    <div class="row">

        <div class="col-md-12">

            <div class="card card-outline card-primary">
                <div class="card-body p-0">
                    <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Roles</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>

                <?php
                if (mysqli_num_rows($contacts_sql) == 0) { ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">No contacts found.</td>
                    </tr>
                <?php }
                while ($row = mysqli_fetch_assoc($contacts_sql)) {
                    $contact_id = intval($row['contact_id']);
                    $contact_name = nullable_htmlentities($row['contact_name']);
                    $contact_email = nullable_htmlentities($row['contact_email']);
                    $contact_primary = intval($row['contact_primary']);
                    $contact_technical = intval($row['contact_technical']);
                    $contact_billing = intval($row['contact_billing']);

                    $contact_roles_display = 'Team member';
                    if ($contact_primary) {
                        $contact_roles_display = 'Primary contact';
                    } else if ($contact_technical && $contact_billing) {
                        $contact_roles_display = 'Technical & Billing';
                    } else if ($contact_technical) {
                        $contact_roles_display = 'Technical';
                    } else if ($contact_billing) {
                        $contact_roles_display = 'Billing';
                    }

                    ?>

                    <tr>
                        <td><?php if ($contact_primary || $contact_id === intval($session_contact_id)) { echo $contact_name; } else { ?><a href="contact_edit.php?id=<?php echo $contact_id?>"><?php echo $contact_name ?></a><?php } ?><?php if ($contact_id === intval($session_contact_id)) { ?> <span class="portal-badge portal-badge--muted ms-1">You</span><?php } ?></td>
                        <td><?php echo $contact_email; ?></td>
                        <td><span class="badge <?php echo ($contact_primary || $contact_technical || $contact_billing) ? 'text-bg-primary' : 'text-bg-secondary'; ?>"><?php echo $contact_roles_display ?></span></td>
                        <td class="text-end"><?php if ($contact_primary || $contact_id === intval($session_contact_id)) { ?><span class="text-secondary small">Managed by IT</span><?php } else { ?><a href="contact_edit.php?id=<?php echo $contact_id?>" class="btn btn-sm btn-outline-secondary" aria-label="Edit <?php echo $contact_name; ?>"><i class="fas fa-pen me-1"></i>Edit</a><?php } ?></td>
                    </tr>

                <?php } ?>

                </tbody>
            </table>
                    </div>
                </div>
            </div>

        </div>

    </div>

<?php
require_once "includes/footer.php";
