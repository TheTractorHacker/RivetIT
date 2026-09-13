<?php

/*
 * Client Portal
 * Checks if the client is logged in or not
 *
 * TWO WAYS IN, and they are not equal:
 *
 *   1. A REAL PORTAL LOGIN ($_SESSION['client_logged_in']). Completely
 *      unchanged from before - same gate, same queries, same user_type / status
 *      / archived checks, same session keys. Nothing below relaxes it.
 *
 *   2. A READ-ONLY ADMIN PREVIEW. An admin agent looking at what a department's
 *      portal looks like. This is an ADDITIONAL branch that only ever runs when
 *      branch 1 has already failed, it is re-authorised against the database on
 *      every request (client/includes/portal_preview.php), and it writes NONE of
 *      'client_logged_in', 'client_id', 'contact_id' or 'user_id' into the
 *      session - so it cannot corrupt, or be mistaken for, a real portal login,
 *      and it cannot disturb the admin's own agent session which shares
 *      $_SESSION['user_id'].
 */

if (!isset($_SESSION)) {
    // HTTP Only cookies
    ini_set("session.cookie_httponly", true);
    if ($config_https_only) {
        // Tell client to only send cookie(s) over HTTPS
        ini_set("session.cookie_secure", true);
    }
    session_start();
}

require_once __DIR__ . '/portal_preview.php';

/*
 * The exit control, handled before any gate so that "leave the preview" works
 * from every portal page and, critically, so that the portal's own Sign out
 * link (/client/post.php?logout -> session_destroy()) ends the PREVIEW instead
 * of destroying the admin's agent session. A no-op when no preview is running,
 * so a genuine portal contact's Sign out is untouched.
 */
portalPreviewHandleExitRequest();

// Request-scoped preview context. $portal_preview is null on every normal
// portal request; the shell/banner lane reads these.
$portal_preview               = null;
$portal_preview_active        = false;
$portal_preview_client_id     = 0;
$portal_preview_agent_user_id = 0;
$portal_preview_agent_name    = '';

if (!isset($_SESSION['client_logged_in']) || !$_SESSION['client_logged_in']) {

    // No real portal login. The only other legitimate way to be here is an
    // admin preview, which portalPreviewResolve() re-proves against the
    // database (live agent session, user_type = 1, active, unarchived, admin
    // role) rather than trusting any session flag.
    $preview_state = portalPreviewResolve();

    if ($preview_state['ok']) {

        $portal_preview_active        = true;
        $portal_preview_client_id     = $preview_state['client_id'];
        $portal_preview_agent_user_id = $preview_state['agent_user_id'];
        $portal_preview_agent_name    = $preview_state['agent_name'];

        // Built from the resolve above rather than by calling
        // portalPreviewContext(), which would re-run the whole (deliberately
        // un-memoised) database check a second time on every preview page.
        // Same shape portalPreviewContext() returns, by contract.
        $portal_preview = [
            'client_id'     => $preview_state['client_id'],
            'client_name'   => $preview_state['client_name'],
            'agent_user_id' => $preview_state['agent_user_id'],
            'agent_name'    => $preview_state['agent_name'],
            'started_at'    => $preview_state['started_at'],
            'exit_url'      => portalPreviewExitUrl(),
        ];

    } elseif ($preview_state['present']) {

        /*
         * A preview WAS running and has just been torn down by
         * portalPreviewResolve(). Send the admin somewhere that makes sense
         * rather than rendering half a portal or bouncing them at the portal
         * login page they never used.
         */
        $drop_reason = $preview_state['drop_reason'];

        if ($drop_reason === 'client_gone') {
            flash_alert("That department is no longer available, so the portal preview was closed.", "warning");
            redirect(PORTAL_PREVIEW_AGENT_FALLBACK);
        } elseif ($drop_reason === 'expired') {
            flash_alert("The portal preview timed out and was closed.", "info");
            redirect(PORTAL_PREVIEW_AGENT_FALLBACK);
        } else {
            // not_admin / user_mismatch / malformed - the agent session backing
            // the preview is gone or was never valid, so there is nothing to go
            // back to on the agent side.
            redirect("/login.php");
        }

    } else {

        // Unchanged behaviour for everybody else.
        redirect("/login.php");

    }

} else {

    /*
     * A REAL portal login. The two states can never coexist, so any preview
     * state left in this session is stale and gets binned here.
     *
     * It is reachable: login.php's CLIENT FLOW calls session_regenerate_id(true),
     * which keeps $_SESSION contents, so an admin who previewed and then had a
     * contact log in on the same browser would leave a blob behind. It grants
     * nothing (portalPreviewResolve() refuses it with drop_reason
     * 'real_portal_login'), but it would still be sitting in a real contact's
     * session where a degraded "helpers missing" fallback could show them a
     * preview banner they have no business seeing. Clearing it costs one unset.
     */
    portalPreviewClearState();

}

// Set Timezone
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_set_timezone.php';

// User IP & UA
$session_ip = sanitizeInput(getIP());
$session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT']);


if (!$portal_preview_active) {

    // ---------------------------------------------------------------------
    // REAL PORTAL LOGIN - unchanged.
    // ---------------------------------------------------------------------

    // Get info from session
    $session_client_id = intval($_SESSION['client_id']);
    $session_contact_id = intval($_SESSION['contact_id']);
    $session_user_id = intval($_SESSION['user_id']);

    // Load user session vars
    $sql = mysqli_query($mysqli, "SELECT * FROM users WHERE users.user_id = $session_user_id");

    $row = mysqli_fetch_assoc($sql);

    $session_avatar = $row['user_avatar'];
    $session_user_type = intval($row['user_type']);
    $session_user_status = intval($row['user_status']);
    $session_user_archived_at = $row['user_archived_at'];

    // Check user type is client aka 2
    if ($session_user_type !== 2) {
        session_unset();
        session_destroy();
        redirect("/login.php");
    }

    // Check User is active
    if ($session_user_status !== 1) {
        session_unset();
        session_destroy();
        redirect("/login.php");
    }

    // Check User is archived
    if ($session_user_archived_at !== null) {
        session_unset();
        session_destroy();
        redirect("/login.php");
    }

} else {

    /*
     * ---------------------------------------------------------------------
     * READ-ONLY ADMIN PREVIEW.
     * ---------------------------------------------------------------------
     *
     * There is no portal users row to load, and there must not be: on this
     * install there are zero users with user_type = 2, so
     * "SELECT * FROM users WHERE user_id = $session_user_id" would return
     * false, $row['user_avatar'] and friends would emit undefined-key warnings,
     * $session_user_type would come out as 0 and the very next check would
     * session_destroy() the ADMIN'S OWN AGENT SESSION. So the query is skipped
     * entirely and the three variables it feeds are supplied directly, along
     * with the type/status/archived checks' outcome (which is "pass", because
     * the authorisation for this request was already proved against the agent's
     * own users row by portalPreviewResolve()).
     */

    $session_client_id = $portal_preview_client_id;

    /*
     * NO CONTACT IDENTITY IS BORROWED. contact_id 0 matches no contacts row, so
     * every "my tickets" / "my assets" style query below and throughout the
     * portal returns an empty set instead of some real employee's private
     * history. That is the honest thing for a preview to show and it is what
     * keeps this from being impersonation.
     */
    $session_contact_id = 0;

    /*
     * DELIBERATELY 0, not the admin's real user id.
     *
     * $session_user_id is interpolated straight into writes in client/post.php
     * (e.g. "UPDATE users SET user_password = ... WHERE user_id = $session_user_id",
     * "INSERT INTO tickets SET ticket_created_by = $session_user_id"). The write
     * block is what is supposed to stop those ever running, but if one path is
     * ever missed, 0 means the statement matches no row and attributes nothing.
     * Putting the admin's real id here would make a missed path able to modify
     * the admin's own account from inside the portal.
     *
     * Preview audit entries are still attributed correctly: portalPreviewLog()
     * swaps the real admin id in around each logAction() call.
     */
    $session_user_id = 0;

    // What the skipped users query would have supplied.
    $session_avatar           = '';
    $session_user_type        = 2;    // the portal's own notion of "this is a portal request"
    $session_user_status      = 1;
    $session_user_archived_at = null;

}

// Load company session vars
$sql = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1");
$row = mysqli_fetch_assoc($sql);

$session_company_name = $row['company_name'];
$session_company_country = $row['company_country'];
$session_company_locale = $row['company_locale'];
$session_company_currency = $row['company_currency'];
$currency_format = numfmt_create($session_company_locale ?: 'en_US', NumberFormatter::CURRENCY);
$session_company_logo = $row['company_logo'];

if (!$portal_preview_active) {

    // Load contact session vars
    $contact_sql = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_id = $session_contact_id AND contact_client_id = $session_client_id");
    $contact = mysqli_fetch_assoc($contact_sql);

    $session_contact_name = sanitizeInput($contact['contact_name']);
    $session_contact_initials = initials($session_contact_name);
    $session_contact_title = sanitizeInput($contact['contact_title']);
    $session_contact_email = sanitizeInput($contact['contact_email']);
    $session_contact_photo = sanitizeInput($contact['contact_photo']);
    $session_contact_pin = sanitizeInput($contact['contact_pin']);
    $session_contact_primary = intval($contact['contact_primary']);

    $session_contact_is_technical_contact = false;
    $session_contact_is_billing_contact = false;
    if ($contact['contact_technical'] == 1) {
        $session_contact_is_technical_contact = true;
    }
    if ($contact['contact_billing'] == 1) {
        $session_contact_is_billing_contact = true;
    }

} else {

    /*
     * Preview-safe contact identity.
     *
     * contact_id is 0, so the contacts query above would return false and every
     * one of these would be an undefined-key warning followed by a portal that
     * half-renders. They are supplied directly instead.
     *
     * NOTHING PERSONAL IS FABRICATED OR BORROWED: no real name, no real email,
     * and above all no real contact_pin (that PIN is a verification secret and
     * profile.php prints it on the page - a preview must never show one).
     *
     * The three role flags are all granted. That is a READ-scope decision, not
     * a privilege escalation: the portal uses them purely to decide which
     * sections of THIS ONE department render at all (assets, documents,
     * invoices, all-department tickets), the admin can already see every one of
     * those on the agent side, and every write is blocked. Granting them is
     * what makes the preview show the whole portal instead of the smallest
     * possible corner of it.
     */
    /*
     * The name in the "Welcome back, ..." heading and the navbar dropdown is the
     * PREVIEWING ADMIN'S OWN, not an invented persona. 'Portal Preview' sat here
     * first and read as a person called Portal Preview - initials "PP" in the
     * avatar and all. The admin's real name is the one identity that can go here
     * without fabricating anyone or borrowing a department contact, and it is
     * also the true answer to "who is looking at this page".
     *
     * Falls back to 'Portal Preview' only if resolve() somehow produced no name;
     * an empty string would give initials() nothing to work with.
     */
    $session_contact_name     = $portal_preview_agent_name !== '' ? $portal_preview_agent_name : 'Portal Preview';
    $session_contact_initials = initials($session_contact_name);
    $session_contact_title    = 'Read-only preview';
    $session_contact_email    = '';
    $session_contact_photo    = '';
    $session_contact_pin      = '';
    $session_contact_primary  = 1;

    $session_contact_is_technical_contact = true;
    $session_contact_is_billing_contact   = true;

}

// Load client session vars
$client_sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_id = $session_client_id");
$client = mysqli_fetch_assoc($client_sql);

$session_client_name = $client['client_name'];
