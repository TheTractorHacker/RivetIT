<?php
/*
 * DEPARTMENT HEADER STRIP
 *
 * Included by agent/includes/inc_all_client.php, which every department-scoped
 * page requires - Overview, Tickets, Assets, Contacts, Locations, Credentials,
 * and ~35 more. Anything here is seen dozens of times a day, so it is written
 * to be quiet: one title row, one three-up strip of facts, no decoration.
 *
 * It consumes the variables inc_all_client.php has already resolved
 * ($location_*, $contact_*, $client_*, $num_*) and does not re-query for them.
 * The one query it owns is the site COUNT below, which nothing else provides.
 */

/*
 * How many sites is this department actually attached to?
 *
 * Departments reach buildings through department_sites, which is many-to-many:
 * department 18 sits in two. inc_all_client.php can only surface ONE of them in
 * $location_*, picked deterministically (a location flagged primary, else the
 * lowest location_id), so without a count this card would silently imply the
 * department has exactly one address. Counting here lets the card say "showing
 * one of two" instead of hiding the other.
 *
 * $num_locations from inc_all_client.php cannot be used for this: it counts the
 * legacy per-client locations.location_client_id column, which is 0 for every
 * row in this edition - one company, shared buildings.
 */
// The RMM agent installer dialog for this department: null (nothing rendered, nothing linked) with the module off or for a user who may not issue installers.
require_once dirname(__DIR__, 2) . '/includes/rmm_installer.php';
$rmm_inst_ctx = !empty($client_id) ? rivetRmmInstallerContext($mysqli, (int) $session_user_id, (int) $client_id) : null;
if ($rmm_inst_ctx !== null) {
    $rmm_installer_scripts = true;   // includes/footer.php links js/rmm_installer.js only when this is set
}

$client_site_count = 0;
if (!empty($client_id)) {
    $sql_client_site_count = mysqli_query(
        $mysqli,
        "SELECT COUNT(*) AS num
        FROM department_sites ds
        INNER JOIN locations l ON l.location_id = ds.location_id
        WHERE ds.client_id = $client_id AND l.location_archived_at IS NULL"
    );
    if ($sql_client_site_count) {
        $client_site_count = intval(mysqli_fetch_assoc($sql_client_site_count)['num']);
    }
}

/*
 * Map link target.
 *
 * This used to be "?q=$location_address $location_zip" - raw spaces, and it
 * threw away the city and state that are printed on the very next line, so
 * "8307 Ball Rd 72908" was ambiguous to any map provider. Build the query from
 * the whole address and percent-encode it once.
 *
 * The $location_* variables arrive HTML-encoded (nullable_htmlentities ->
 * htmlspecialchars with ENT_QUOTES), so decode with the matching flags before
 * encoding, or "Ben & Jerry's Rd" would reach the provider as "Ben %26amp%3B
 * Jerry%27s Rd". rawurlencode output is attribute-safe on the way back out.
 */
$location_map_query = '';
if (!empty($location_address)) {
    $location_map_parts = array_filter(
        array_map(
            static fn($part) => trim((string)$part),
            [$location_address, $location_city, "$location_state $location_zip"]
        ),
        static fn($part) => $part !== ''
    );
    $location_map_query = rawurlencode(
        html_entity_decode(implode(', ', $location_map_parts), ENT_QUOTES, 'UTF-8')
    );
}

// The strip is open on the department landing page and closed everywhere else,
// so the toggle's initial ARIA/chevron state has to be derived from the same test.
$client_header_open = (basename($_SERVER["PHP_SELF"]) == "client_overview.php");

// Locations live behind module_support (agent/locations.php enforces it), so the
// "link a site" affordance is only offered to someone who can actually follow it.
$client_header_can_edit_sites = (lookupUserPermission("module_support") >= 1);
?>

<style nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES); ?>">
/* The collapse chevron. Bootstrap adds/removes .collapsed on the trigger itself,
   so one transform swap covers both directions with no JS. Transform only, and
   it rotates a glyph inside a fixed-size button - no layout, no moved click
   target. Durations/easing come from css/itflow_motion.css's tokens, whose
   single global prefers-reduced-motion guard neutralises this too, so this rule
   deliberately carries no media query of its own. */
.client-header-toggle .fa-chevron-up {
    display: inline-block;
    transition: transform var(--if-dur-ui, 160ms) var(--if-ease-out, ease-out);
}
.client-header-toggle.collapsed .fa-chevron-up {
    transform: rotate(180deg);
}
</style>

<div class="card d-print-none mb-3">
    <div class="card-header pb-1 pt-2 px-3">
        <div class="card-title">
            <!-- The department name used to BE the collapse trigger: an <a href="#">
                 wrapping this h4, with no chevron, no aria-expanded and no hint that
                 clicking the title would fold the strip away. The title is a plain
                 heading again; the toggle is the labelled button in .card-tools. -->
            <h4 class="mb-0" data-bs-toggle="tooltip" data-bs-placement="right" title="Department ID: <?php echo $client_id; ?>"><strong><?php echo $client_name; ?></strong><?php if ($client_archived_at) { ?> <span class="fw-normal text-secondary">(archived)</span><?php } ?></h4>
        </div>
        <?php if (!empty($client_tag_name_display_array)) { ?><div class="card-title ms-2"><?php echo $client_tags_display; ?></div> <?php } ?>
        <div class="card-tools">

            <button class="btn btn-tool client-header-toggle<?php if (!$client_header_open) { echo ' collapsed'; } ?>"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#clientHeader"
                    aria-controls="clientHeader"
                    aria-expanded="<?php echo $client_header_open ? 'true' : 'false'; ?>"
                    aria-label="Show or hide department details">
                <i class="fas fa-fw fa-chevron-up"></i>
            </button>

            <?php if (lookupUserPermission("module_client") >= 2) { ?>
            <div class="dropdown dropleft text-center">
                <!-- .btn-tool, not .btn-dark: a lone icon action in a card header
                     should read as chrome - a muted glyph with an 8% ink wash on
                     hover - not as a filled near-black chip louder than the
                     department name it sits beside. .btn-tool is this app's own
                     card-header button (50 uses; agent/calendar.php:51 is the same
                     kebab-in-a-header pattern) and css/itflow.shim-adminlte.css
                     already gives it hover, focus-visible and disabled states. -->
                <button class="btn btn-tool" type="button" data-bs-toggle="dropdown" data-boundary="window" aria-label="Department actions">
                    <i class="fas fa-fw fa-ellipsis-v"></i>
                </button>
                <div class="dropdown-menu">
                    <?php if (lookupUserPermission("module_support") >= 2) { ?>
                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add_v2.php?client_id=<?= $client_id ?>" data-modal-size="lg">
                            <i class="fas fa-fw fa-life-ring me-2"></i>New Ticket
                        </a>
                    <?php } ?>
                    <?php if ($rmm_inst_ctx !== null) { echo rivetRmmInstallerTrigger('item', (int) $client_id, 'Download agent installer'); } // the RMM "Add device" dialog, below ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item ajax-modal" href="#"
                        data-modal-url="modals/client/client_edit.php?id=<?= $client_id ?>">
                        <i class="fas fa-fw fa-edit me-2"></i>Edit Department
                    </a>
                    <?php /*
                     * "Add Credit" lived here behind $show_add_credit = 0, i.e. it has
                     * never rendered. This edition is an internal IT helpdesk - one
                     * company, departments instead of customers, and no billable
                     * anything - so a credit action on a department header is not a
                     * feature waiting to be switched on. Removed with the Billing cell
                     * below; agent/modals/client/client_credit_add.php is untouched.
                     */ ?>
                    <?php
                    /*
                     * READ-ONLY PORTAL PREVIEW.
                     *
                     * $session_is_admin specifically, NOT the surrounding
                     * lookupUserPermission("module_client") >= 2 that opens this
                     * menu: looking at a department's client portal is an
                     * administrative act, not a department-writer one, and
                     * agent/post/client.php gates the handler behind exactly the
                     * same test (a forged link therefore gets nowhere).
                     *
                     * Hidden rather than shown-and-refused in the two cases the
                     * handler cannot honour: an archived department has no live
                     * portal (portalPreviewEnter() requires client_archived_at
                     * IS NULL), and with the portal module switched off there is
                     * nothing on the other side to look at.
                     *
                     * Plain .dropdown-item with a fa-fw icon and me-2, like every
                     * sibling here. No .confirm-link - this changes nothing.
                     */
                    if (!empty($session_is_admin) && empty($client_archived_at) && !empty($config_client_portal_enable)) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="post.php?view_client_portal=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" title="Open a read-only preview of this department's client portal">
                            <i class="fas fa-fw fa-eye me-2"></i>View Department Portal
                        </a>
                    <?php } ?>

                    <?php if (lookupUserPermission("module_client") >= 3) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#exportClientPDFModal">
                            <i class="fas fa-fw fa-file-pdf me-2"></i>Export Data
                        </a>
                    <?php } ?>

                    <?php if (empty($client_archived_at)) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_client=<?php echo $client_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                            <i class="fas fa-fw fa-archive me-2"></i>Archive Department
                        </a>
                    <?php } else { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-primary confirm-link" href="post.php?restore_client=<?= $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                            <i class="fas fa-fw fa-archive me-2"></i>Restore Department
                        </a>
                    <?php } ?>

                    <?php if (lookupUserPermission("module_client") >= 3 && $client_archived_at) { ?>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger text-bold" href="#" data-bs-toggle="modal" data-bs-target="#deleteClientModal<?php echo $client_id; ?>">
                        <i class="fas fa-fw fa-trash me-2"></i>Delete Department
                    </a>
                    <?php } ?>

                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>

<div class="collapse <?php if ($client_header_open) { echo "show"; } ?>" id="clientHeader">

    <?php
    /*
     * The three-up strip.
     *
     * Every cell is built the same way on purpose: `mt-0` + no justify-content,
     * then one <h5 class="mb-2"> label, then a single .gap-1 flex column of rows.
     *
     *   mt-0  - css/itflow_design.css has a global `.card + .card { margin-top:
     *           1rem }`, which inside a flex .card-group pushed cells 2 and 3
     *           sixteen pixels down and left a visible notch beside cell 1.
     *   no justify-content-center - each cell used to vertically centre its own
     *           unequal content, so the three labels landed on three different
     *           baselines (159 / 187 / 175 measured on department 18) even though
     *           they read as one row.
     *   gap-1 - one spacing rule for every row instead of the old mixture of
     *           bare divs, .mt-1 and an <hr>.
     */
    ?>
    <div class="card-group mb-3">

        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Primary Location</h5>
            <div class="d-flex flex-column gap-1">
                <?php if (!empty($location_address)) { ?>
                    <div class="d-flex">
                        <i class="fa fa-fw fa-map-marker-alt text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <div><a href="//maps.<?php echo $session_map_source; ?>.com/?q=<?php echo $location_map_query; ?>" target="_blank" rel="noopener"><?php echo $location_address; ?></a></div>
                            <?php if (trim("$location_city $location_state $location_zip") !== '') { ?>
                                <div><?php echo trim("$location_city $location_state $location_zip"); ?></div>
                            <?php } ?>
                            <?php if (!empty($location_country)) { ?>
                                <div class="text-secondary small"><?php echo $location_country; ?></div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } else { ?>
                    <?php
                    /*
                     * Empty state. Department 19 has no site linked, and this cell used
                     * to render as a bold "Primary Location" label floating over a blank
                     * white rectangle - indistinguishable from a page that failed to
                     * load. Say what is missing, and where to fix it: locations attach
                     * to departments through department_sites, which the UI exposes as
                     * the department's own Locations page.
                     */
                    ?>
                    <div class="d-flex">
                        <i class="fa fa-fw fa-map-marker-alt text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <div class="text-secondary"><?php echo $client_site_count > 0 ? 'Linked site has no address on file' : 'No site linked to this department'; ?></div>
                            <?php if ($client_header_can_edit_sites) { ?>
                                <a class="small" href="/agent/locations.php?client_id=<?php echo $client_id; ?>"><?php echo $client_site_count > 0 ? 'Edit it on the Locations page' : 'Link one on the Locations page'; ?></a>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>

                <?php
                /*
                 * A department can occupy several buildings, and only one of them fits
                 * in a cell headed "Primary Location". Name the one being shown and
                 * count the ones that are not, rather than letting the card imply the
                 * other buildings do not exist.
                 */
                if ($client_site_count > 1) { ?>
                    <div class="d-flex small">
                        <i class="fa fa-fw fa-map-marked-alt text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <span class="text-secondary"><?php if (!empty($location_name)) { echo "Showing $location_name &middot; "; } ?><?php echo $client_site_count; ?> sites linked</span>
                            <?php if ($client_header_can_edit_sites) { ?><a class="ms-1" href="/agent/locations.php?client_id=<?php echo $client_id; ?>">View all</a><?php } ?>
                        </div>
                    </div>
                <?php } ?>

                <?php if (!empty($location_phone)) { ?>
                    <div>
                        <i class="fa fa-fw fa-phone text-secondary ms-1 me-2"></i><a href="tel:<?php echo $location_phone; ?>"><?php echo $location_phone; ?></a>
                    </div>
                <?php } ?>

                <?php if (!empty($client_website)) { ?>
                    <div>
                        <i class="fa fa-fw fa-globe text-secondary ms-1 me-2"></i><a target="_blank" rel="noopener" href="//<?php echo $client_website; ?>"><?php echo $client_website; ?></a>
                    </div>
                <?php } ?>
            </div>
        </div>

        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Primary Contact</h5>
            <div class="d-flex flex-column gap-1">
                <?php if (!empty($contact_name)) { ?>
                    <div>
                        <i class="fa fa-fw fa-user text-secondary ms-1 me-2"></i><?php echo $contact_name; ?>
                    </div>
                <?php } ?>

                <?php if (!empty($contact_email)) { ?>
                    <div>
                        <i class="fa fa-fw fa-envelope text-secondary ms-1 me-2"></i><a href="mailto:<?php echo $contact_email; ?>"><?php echo $contact_email; ?></a>
                    </div>
                <?php } ?>

                <?php if (!empty($contact_phone)) { ?>
                    <div>
                        <i class="fa fa-fw fa-phone text-secondary ms-1 me-2"></i><a href="tel:<?php echo $contact_phone; ?>"><?php echo $contact_phone; ?></a><?php
                        if (!empty($contact_extension)) {
                            echo " <small class='text-secondary'>x$contact_extension</small>";
                        }
                        ?>
                    </div>
                <?php } ?>

                <?php if (!empty($contact_mobile)) { ?>
                    <div>
                        <i class="fa fa-fw fa-mobile-alt text-secondary ms-1 me-2"></i><a href="tel:<?php echo $contact_mobile; ?>"><?php echo $contact_mobile; ?></a>
                    </div>
                <?php } ?>

                <?php if (empty($contact_name) && empty($contact_email) && empty($contact_phone) && empty($contact_mobile)) { ?>
                    <div class="d-flex">
                        <i class="fa fa-fw fa-user text-secondary ms-1 me-2 mt-1"></i>
                        <div>
                            <div class="text-secondary">No primary contact set</div>
                            <!-- No permission gate: inc_all_client.php has already
                                 enforced module_client to render this page at all,
                                 which is exactly what agent/contacts.php requires. -->
                            <a class="small" href="/agent/contacts.php?client_id=<?php echo $client_id; ?>">Choose one on the Contacts page</a>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php
        /*
         * A fourth "Billing" cell used to live here - Hourly Rate, Paid, Balance,
         * Monthly Recurring, Net Terms, Tax ID - behind
         * `lookupUserPermission("module_financial") >= 1 && $config_module_enable_accounting == 1`.
         * It has never rendered on this install, and this edition is an internal IT
         * helpdesk: departments are not customers and there is no billable anything,
         * so an accounting toggle flipped for some unrelated reason must not be able
         * to put an hourly rate on top of every department page. Removed rather than
         * left dormant. The accounting module itself is untouched and still owns that
         * data on its own pages.
         */
        ?>

        <?php if (lookupUserPermission("module_support") >= 1 && $config_module_enable_ticketing == 1) { ?>
        <div class="card card-body px-3 py-2 mt-0">
            <h5 class="mb-2">Support</h5>
            <div class="d-flex flex-column gap-1">
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Open Tickets</span>
                    <span class="fw-medium"><?php echo $num_active_tickets; ?></span>
                </div>
                <div class="d-flex justify-content-between ms-1">
                    <span class="text-secondary">Closed Tickets</span>
                    <span class="fw-medium"><?php echo $num_closed_tickets; ?></span>
                </div>
            </div>
        </div>
        <?php } ?>

    </div>
</div>

<?php
if ($rmm_inst_ctx !== null) { echo rivetRmmInstallerModal($rmm_inst_ctx); }
// require_once "modals/client/client_credit_add.php"; --Credit Not Ready 2025-08-27
require_once "modals/client/client_delete.php";
require_once "modals/client/client_download_pdf.php";
