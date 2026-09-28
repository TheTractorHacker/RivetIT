<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

/*
 * Department Portal Preview.
 *
 * A place to open a READ-ONLY preview of any department's client portal, so an
 * administrator can see exactly what that department sees without needing a portal
 * login to exist. The same action is available from the kebab menu on an individual
 * department page; this page is the deliberate route - pick a department, look at
 * its portal, come back.
 *
 * This page does NOT hold any of the logic. It renders links to the existing entry
 * point at agent/post.php?view_client_portal=<id>, which is what performs the admin
 * check, the department-access check, the CSRF check and the audit log. The preview
 * itself is governed by client/includes/portal_preview.php, which re-derives the
 * admin's authority from the database on every single request rather than trusting a
 * session flag - so a link on this page is not a capability, it is just a link.
 *
 * Why "preview" and not "log in as": there is nobody to log in as. A portal session
 * needs a users row with user_type = 2, and the agent and portal gates share
 * $_SESSION['user_id'] - so a real session switch would overwrite the admin's own
 * identity in the same browser. The preview uses a separate session namespace and
 * blocks every write instead.
 */

$portal_module_on = !empty($config_client_portal_enable);

// Non-archived departments only: portalPreviewEnter() refuses an archived one, so
// offering the link would just produce a flash error.
$sql_departments = mysqli_query($mysqli,
    "SELECT clients.client_id, clients.client_name, clients.client_abbreviation,
            (SELECT COUNT(*) FROM contacts
              WHERE contacts.contact_client_id = clients.client_id
                AND contacts.contact_archived_at IS NULL) AS contact_count,
            (SELECT COUNT(*) FROM contacts
              WHERE contacts.contact_client_id = clients.client_id
                AND contacts.contact_archived_at IS NULL
                AND contacts.contact_user_id > 0) AS portal_login_count
     FROM clients
     WHERE clients.client_archived_at IS NULL
     ORDER BY clients.client_name ASC"
);

?>

<div class="card">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto"><i class="fas fa-fw fa-eye me-2"></i>Department Portal Preview</h3>
        <?php if ($portal_module_on) { ?>
            <span class="badge text-bg-success"><i class="fas fa-check-circle me-1"></i>Portal Enabled</span>
        <?php } else { ?>
            <span class="badge text-bg-secondary"><i class="fas fa-times-circle me-1"></i>Portal Disabled</span>
        <?php } ?>
    </div>

    <div class="card-body">
        <p class="text-muted small mb-3">
            Open a <strong>read-only</strong> preview of a department's portal to see exactly what that
            department sees. You stay signed in as yourself the whole time &mdash; the preview uses a
            separate session, so your <?= htmlspecialchars(APP_NAME) ?> session is untouched and you can leave it at any point
            from the banner at the top of the portal.
        </p>

        <div class="alert alert-info">
            <div>
                <h4 class="alert-title"><i class="fas fa-fw fa-circle-info me-1"></i>Every action is blocked while previewing</h4>
                <p class="mb-0">
                    This is a preview, not a sign-in. Nothing can be created, changed or paid for while
                    it is running, and both entering and leaving are written to the audit log against
                    your account. It is a way to check how the portal looks and reads &mdash; not a way
                    to act on a department's behalf.
                </p>
            </div>
        </div>

        <?php if (!$portal_module_on) { ?>
        <div class="alert alert-warning">
            <div>
                <h4 class="alert-title"><i class="fas fa-fw fa-triangle-exclamation me-1"></i>The client portal is switched off</h4>
                <p class="mb-0">
                    Previewing is unavailable until the portal module is enabled under
                    <a href="/admin/settings_module.php">Settings &rsaquo; Modules</a>. Departments are
                    listed below for reference only.
                </p>
            </div>
        </div>
        <?php } ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th class="text-center">Contacts</th>
                        <th class="text-center">Portal logins</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($sql_departments) === 0) { ?>
                    <tr>
                        <td colspan="4">
                            <div class="text-center text-muted py-4">
                                <i class="far fa-folder-open fa-2x d-block mb-3 opacity-50"></i>
                                <p class="mb-0">No active departments to preview.</p>
                            </div>
                        </td>
                    </tr>
                <?php }

                while ($row = mysqli_fetch_assoc($sql_departments)) {
                    $dept_id      = intval($row['client_id']);
                    $dept_name    = nullable_htmlentities($row['client_name']);
                    $dept_abbr    = nullable_htmlentities($row['client_abbreviation']);
                    $contact_n    = intval($row['contact_count']);
                    $portal_login = intval($row['portal_login_count']);
                ?>
                    <tr>
                        <td>
                            <a href="/agent/client_overview.php?client_id=<?php echo $dept_id; ?>"><?php echo $dept_name; ?></a>
                            <?php if ($dept_abbr !== '') { ?>
                                <span class="text-muted small ms-1"><?php echo $dept_abbr; ?></span>
                            <?php } ?>
                        </td>
                        <td class="text-center"><?php echo $contact_n; ?></td>
                        <td class="text-center">
                            <?php if ($portal_login > 0) { ?>
                                <?php echo $portal_login; ?>
                            <?php } else { ?>
                                <span class="text-muted" title="No contact in this department can sign in to the portal yet. The preview works regardless - it does not need one.">&mdash;</span>
                            <?php } ?>
                        </td>
                        <td class="text-end">
                            <?php if ($portal_module_on) { ?>
                                <a class="btn btn-sm btn-primary"
                                   href="/agent/post.php?view_client_portal=<?php echo $dept_id; ?>&amp;csrf_token=<?php echo $_SESSION['csrf_token']; ?>"
                                   title="Open a read-only preview of this department's portal">
                                    <i class="fas fa-fw fa-eye me-1"></i>View portal
                                </a>
                            <?php } else { ?>
                                <button type="button" class="btn btn-sm btn-secondary" disabled>
                                    <i class="fas fa-fw fa-eye me-1"></i>View portal
                                </button>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once "../includes/footer.php";
