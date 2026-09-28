<?php

/*
 * Client Portal - READ-ONLY ADMIN PREVIEW
 * =======================================
 *
 * WHAT THIS IS
 * ------------
 * An admin agent needs to see what a department's client portal looks like.
 * The obvious way - log the admin in as a portal contact - is not available on
 * this install and must not be faked:
 *
 *   * client/includes/check_login.php reads $_SESSION['user_id'] and so does
 *     includes/auth_check.php. They SHARE that key. Overwriting it to
 *     impersonate a contact would destroy the agent's own identity in the same
 *     browser session.
 *   * There are zero contacts with contact_user_id > 0 and zero users with
 *     user_type = 2 on this install, so the users row check_login.php expects
 *     for a portal login does not exist and cannot be borrowed.
 *
 * So this is a PREVIEW, not impersonation:
 *
 *   * Its state lives under ONE new session key, $_SESSION['portal_preview'],
 *     which never collides with 'logged', 'user_id', 'client_logged_in',
 *     'client_id' or 'contact_id'.
 *   * It is not a credential. It carries no authority of its own: every single
 *     call re-derives authority from the live admin agent session by hitting
 *     the database (see portalPreviewCurrentAdmin()). Delete the agent session
 *     and the preview evaporates on the next request.
 *   * Every write is blocked while it is on - see portalPreviewBlockWrites().
 *     The admin can look, and can do nothing. That is the whole point: without
 *     the write block this would be an impersonation primitive.
 *   * Entry and exit are audit-logged via logAction().
 *
 * WHERE IT IS USED
 * ----------------
 *   client/includes/check_login.php   consumes it: adds a preview branch beside
 *                                     (never instead of) the real portal login.
 *   agent side                        calls portalPreviewEnter() to start one.
 *   client/post.php etc.              call portalPreviewBlockWrites() to stop
 *                                     every state change while previewing.
 *   portal shell                      calls portalPreviewContext() to render
 *                                     the persistent banner.
 *
 * REQUIREMENTS OF THE CALLER
 * --------------------------
 * config.php ($mysqli) and functions.php (logAction, redirect, getIP,
 * sanitizeInput) must already be loaded, and a session must already be started.
 * Both the agent shell (includes/check_login.php -> session_init.php) and the
 * portal shell (client/includes/check_login.php) satisfy this before including
 * this file. Nothing here starts a session itself - starting one with the wrong
 * cookie parameters would be worse than doing nothing, so every function
 * degrades to "not previewing" when no session is active.
 */

// Include guard. This file is reachable from the portal shell, from the agent
// shell and from lane-specific entry points, potentially spelled with different
// paths; a redefinition would be a fatal, so guard the whole file once.
if (defined('PORTAL_PREVIEW_LOADED')) {
    return;
}
define('PORTAL_PREVIEW_LOADED', true);

/*
 * THE SESSION KEY NAMESPACE.
 *
 * Exactly ONE top-level key is used, and its value is an array. Nothing else in
 * $_SESSION is read for authority and nothing else is written.
 *
 *   $_SESSION['portal_preview']['client_id']      int, the department previewed
 *   $_SESSION['portal_preview']['agent_user_id']  int, the admin who opened it
 *   $_SESSION['portal_preview']['started_at']     int, unix time of entry
 *
 * agent_user_id is what stops a preview blob left behind by one login being
 * picked up by a different login in the same PHP session: the stored id must
 * still equal the id of the live, DB-verified admin on every request.
 */
define('PORTAL_PREVIEW_SESSION_KEY', 'portal_preview');

/*
 * Belt-and-braces upper bound on how long one preview may run. The real control
 * is the agent session (kill it and the preview dies with it); this just means a
 * forgotten preview in a long-lived session does not linger indefinitely. On
 * expiry the preview is dropped and the admin is returned to the agent side.
 */
define('PORTAL_PREVIEW_MAX_LIFETIME', 43200); // 12 hours

/*
 * Where an exited / dropped preview sends the admin back to on the agent side.
 */
define('PORTAL_PREVIEW_AGENT_RETURN', '/agent/client_overview.php?client_id=');
define('PORTAL_PREVIEW_AGENT_FALLBACK', '/agent/clients.php');


/**
 * Is a portal preview in force for this request?
 *
 * Cheap to call but NOT free: it re-derives authority from the database every
 * time (see portalPreviewResolve()). Call it once per page and keep the answer
 * in a local if you need it repeatedly in a tight loop.
 */
function portalPreviewActive(): bool
{
    $state = portalPreviewResolve();
    return $state['ok'];
}

/**
 * The department (client) id being previewed.
 *
 * RETURNS 0 - NEVER null - when this request is not a preview. 0 is also what
 * comes back when a preview blob existed but has just been dropped as invalid;
 * portalPreviewResolve()['drop_reason'] says why. Test it with
 * `> 0` / truthiness, never with `!== null`.
 */
function portalPreviewClientId(): int
{
    $state = portalPreviewResolve();
    return $state['ok'] ? $state['client_id'] : 0;
}

/**
 * The name of the department being previewed, or '' when this request is not a
 * preview. Raw database value - escape at the point of output.
 */
function portalPreviewClientName(): string
{
    $state = portalPreviewResolve();
    return $state['ok'] ? $state['client_name'] : '';
}

/**
 * Everything the UI needs to render the preview banner, or null when this
 * request is not a preview.
 *
 *   ['client_id' => int, 'client_name' => string,
 *    'agent_user_id' => int, 'agent_name' => string,
 *    'started_at' => int, 'exit_url' => string]
 *
 * client_name / agent_name are raw database values - escape them at the point
 * of output with nullable_htmlentities() like the rest of the app does.
 */
function portalPreviewContext(): ?array
{
    $state = portalPreviewResolve();

    if (!$state['ok']) {
        return null;
    }

    return [
        'client_id'     => $state['client_id'],
        'client_name'   => $state['client_name'],
        'agent_user_id' => $state['agent_user_id'],
        'agent_name'    => $state['agent_name'],
        'started_at'    => $state['started_at'],
        'exit_url'      => portalPreviewExitUrl(),
    ];
}

/**
 * Semantic alias of portalPreviewActive(), for the read-only question rather
 * than the "am I previewing" question. Use this to grey out / hide buttons;
 * use portalPreviewBlockWrites() to actually enforce it.
 */
function portalPreviewIsReadOnly(): bool
{
    return portalPreviewActive();
}

/**
 * The URL that ends a preview. Safe to render as a plain link.
 *
 * No CSRF token is required to exit, deliberately: exiting is a fail-safe
 * teardown that can only ever REDUCE access, so the worst a forged request can
 * achieve is ending the admin's own preview a little early. Requiring a token
 * would instead risk stranding an admin inside a preview they cannot leave.
 */
function portalPreviewExitUrl(): string
{
    return '/client/index.php?exit_portal_preview=1';
}

/**
 * Start a preview of $client_id for the current admin agent.
 *
 * Called from the AGENT side, where includes/check_login.php has already run.
 * Returns true on success. Returns false and changes nothing if the caller is
 * not a live admin agent, if the department does not resolve, or if the client
 * portal module is switched off.
 *
 * The caller is responsible for CSRF-protecting whatever action invokes this
 * (validateCSRFToken()) - entering a preview is low impact (it only affects
 * /client/* pages, never the agent side) but there is no reason to leave it
 * forgeable.
 */
function portalPreviewEnter(int $client_id): bool
{
    global $mysqli, $config_client_portal_enable;

    if (session_status() !== PHP_SESSION_ACTIVE || !isset($mysqli)) {
        return false;
    }

    $client_id = intval($client_id);
    if ($client_id < 1) {
        return false;
    }

    // Authority comes from the database, not from a session flag.
    $admin = portalPreviewCurrentAdmin();
    if ($admin === null) {
        return false;
    }

    // A real portal login is in this session - it is not an agent browser and
    // must never be turned into one.
    if (!empty($_SESSION['client_logged_in'])) {
        return false;
    }

    // Do not preview something no contact could reach. Checked at entry only:
    // the module flag is a product setting, not an authorisation, so toggling
    // it mid-preview should not yank the page out from under the admin.
    if (isset($config_client_portal_enable) && intval($config_client_portal_enable) !== 1) {
        return false;
    }

    $client = portalPreviewLoadClient($client_id);
    if ($client === null) {
        return false;
    }

    $_SESSION[PORTAL_PREVIEW_SESSION_KEY] = [
        'client_id'     => $client_id,
        'agent_user_id' => $admin['user_id'],
        'started_at'    => time(),
    ];

    portalPreviewLog(
        $admin['user_id'],
        "Entered",
        "{$admin['user_name']} (agent user {$admin['user_id']}) opened a READ-ONLY preview of the {$client['client_name']} department portal. No portal login was used; all writes are blocked for the duration.",
        $client_id
    );

    return true;
}

/**
 * End a preview.
 *
 * $reason is recorded in the audit log so "the admin clicked Exit" is
 * distinguishable from "the department was archived underneath them".
 * Known reasons: manual, logout_link, client_gone, expired, not_admin,
 * user_mismatch, malformed, real_portal_login.
 *
 * Safe to call when no preview is running - it is then a no-op that logs
 * nothing. Never touches 'logged', 'user_id', 'client_logged_in', 'client_id'
 * or 'contact_id', so it can never disturb the agent session it depends on.
 */
function portalPreviewExit(string $reason = 'manual'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $blob = $_SESSION[PORTAL_PREVIEW_SESSION_KEY] ?? null;
    if (!is_array($blob)) {
        portalPreviewClearState();
        return;
    }

    $client_id     = intval($blob['client_id'] ?? 0);
    $agent_user_id = intval($blob['agent_user_id'] ?? 0);
    $started_at    = intval($blob['started_at'] ?? 0);

    // Drop the state FIRST. If anything below fails, the preview is still gone.
    portalPreviewClearState();

    if ($client_id < 1 || $agent_user_id < 1) {
        return;
    }

    $client      = portalPreviewLoadClient($client_id);
    $client_name = $client['client_name'] ?? "department $client_id (no longer resolvable)";

    $duration = ($started_at > 0 && $started_at <= time()) ? (time() - $started_at) : 0;
    $minutes  = intdiv($duration, 60);

    $reason = preg_replace('/[^a-z0-9_]/', '', strtolower($reason));
    if ($reason === '') {
        $reason = 'unspecified';
    }

    portalPreviewLog(
        $agent_user_id,
        "Exited",
        "Agent user $agent_user_id left the READ-ONLY preview of the $client_name department portal after {$minutes}m (reason: $reason).",
        $client_id
    );
}

/**
 * "This request must not write."
 *
 * A no-op outside a preview, so it is safe to drop in unconditionally at the
 * top of any handler. Inside a preview it audit-logs the attempt, answers 403
 * and terminates the request - it never returns.
 *
 * $attempted_action is free text for the audit trail, e.g. 'add_ticket',
 * 'post.php', 'contact_edit'.
 *
 * This is the control that keeps the preview from being an impersonation
 * primitive, so it is deliberately a hard stop rather than something the
 * calling code can forget to check the return value of.
 */
function portalPreviewBlockWrites(string $attempted_action = ''): void
{
    $state = portalPreviewResolve();

    if (!$state['ok']) {
        /* FAIL CLOSED when a preview blob WAS present and resolve() has just torn it
           down (expired, admin demoted, user_mismatch). Returning silently there would
           let the very request that invalidated the preview complete its write - the
           one moment the gate matters most. 'present' is what separates that case from
           "nobody is previewing", which must carry on untouched. */
        if (!empty($state['present'])) {
            /* Self-contained denial: the rich 403 below needs $state['client_name'] and
               ['agent_user_id'], which resolve() does not populate once it has refused.
               Keep this minimal and dependency-free - it must not be able to fatal. */
            if (!headers_sent()) {
                http_response_code(403);
            }
            $inv_accept    = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
            $inv_requested = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
            if ($inv_requested === 'xmlhttprequest'
                || str_contains($inv_accept, 'application/json')
                || str_contains($inv_accept, 'text/event-stream')) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode([
                    'success' => false,
                    'error'   => 'portal_preview_expired',
                    'message' => 'That portal preview has ended. Nothing was changed.',
                ]);
                exit;
            }
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo "That portal preview has ended, so nothing was changed. Return to " . APP_NAME . " and start a new preview if you still need one.\n";
            exit;
        }
        return; // Not previewing - a real portal contact, or nobody. Carry on.
    }

    $label = trim($attempted_action);
    if ($label === '') {
        $label = ($_SERVER['REQUEST_METHOD'] ?? 'REQUEST') . ' ' . ($_SERVER['SCRIPT_NAME'] ?? 'unknown');
    }
    $label = substr(preg_replace('/[[:cntrl:]]/', '', $label), 0, 200);

    portalPreviewLog(
        $state['agent_user_id'],
        "Blocked Write",
        "Blocked a write attempt ($label) made from the READ-ONLY preview of the {$state['client_name']} department portal by agent user {$state['agent_user_id']}.",
        $state['client_id']
    );

    if (!headers_sent()) {
        http_response_code(403);
    }

    // Machine callers (the portal's fetch()/XHR endpoints) want JSON, not a page.
    $accept    = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $requested = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $wants_json = $requested === 'xmlhttprequest'
        || str_contains($accept, 'application/json')
        || str_contains($accept, 'text/event-stream');

    if ($wants_json) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'error'   => 'portal_preview_read_only',
            'message' => 'This is a read-only preview of the department portal. Writes are disabled.',
        ]);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }

    $exit_url    = htmlspecialchars(portalPreviewExitUrl(), ENT_QUOTES, 'UTF-8');
    $client_html = htmlspecialchars($state['client_name'], ENT_QUOTES, 'UTF-8');

    // No inline <style> or <script>: this page has to render correctly under
    // every CSP the portal sends (several client pages send
    // "default-src 'self'"), and a same-origin stylesheet is allowed by all of
    // them while an unnonced inline block would not be.
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Read-only preview</title>
<link rel="stylesheet" href="/plugins/tabler/css/tabler.min.css">
</head>
<body>
<div class="page page-center">
  <div class="container container-tight py-4">
    <div class="card card-md">
      <div class="card-body text-center">
        <div class="h1 mb-3">Read-only preview</div>
        <p class="text-secondary">
          You are previewing the <strong>$client_html</strong> department portal as an
          administrator. Nothing can be created, changed or deleted from here.
        </p>
        <p class="text-secondary">
          The action you tried has been blocked and recorded in the audit log.
        </p>
        <div class="mt-4 d-flex flex-wrap justify-content-center gap-2">
          <a href="/client/index.php" class="btn btn-primary">Back to the portal</a>
          <a href="$exit_url" class="btn btn-outline-secondary">Leave the preview</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
HTML;

    exit;
}

/**
 * Handles the "leave the preview" request. Called by
 * client/includes/check_login.php before any gate runs, so the exit control
 * works from every portal page.
 *
 * Two triggers:
 *
 *   ?exit_portal_preview=1   the banner's exit control.
 *
 *   ?logout                  the portal's own Sign out link
 *                            (/client/post.php?logout). That handler calls
 *                            session_unset() + session_destroy(), which in a
 *                            preview would destroy the ADMIN'S OWN AGENT
 *                            SESSION - the admin would be signed out of RivetIT
 *                            entirely by clicking a button in a preview. While
 *                            previewing, that link ends the preview instead.
 *                            When no valid preview is running this returns
 *                            without doing anything, so a genuine portal
 *                            contact's Sign out behaves exactly as it does
 *                            today.
 */
function portalPreviewHandleExitRequest(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $exit_requested   = isset($_GET['exit_portal_preview']) || isset($_POST['exit_portal_preview']);
    $logout_requested = isset($_GET['logout']);

    if (!$exit_requested && !$logout_requested) {
        return;
    }

    if (!isset($_SESSION[PORTAL_PREVIEW_SESSION_KEY])) {
        // No preview at all. A real contact signing out must not be intercepted.
        return;
    }

    $state = portalPreviewResolve();

    if (!$state['ok']) {
        // A stale blob; portalPreviewResolve() has already cleared it.
        if ($logout_requested) {
            return; // let the normal logout path run
        }
        redirect(PORTAL_PREVIEW_AGENT_FALLBACK);
        return;
    }

    $client_id = $state['client_id'];
    portalPreviewExit($logout_requested ? 'logout_link' : 'manual');
    redirect(PORTAL_PREVIEW_AGENT_RETURN . $client_id);
}

/**
 * THE ENGINE. Everything above is a thin wrapper over this.
 *
 * Returns:
 *   [
 *     'ok'            => bool,   true only if a preview is genuinely in force
 *     'present'       => bool,   a preview blob existed when the call started
 *     'client_id'     => int,
 *     'client_name'   => string,
 *     'agent_user_id' => int,
 *     'agent_name'    => string,
 *     'started_at'    => int,
 *     'drop_reason'   => string, '' when ok, otherwise why it was refused
 *   ]
 *
 * ('present' true) + ('ok' false) means "there WAS a preview and it has just
 * been torn down" - the caller can then send the admin somewhere sensible
 * instead of to the portal login page.
 *
 * NOT MEMOISED, on purpose. Every call re-runs the database check, because the
 * whole safety argument for this feature is that the preview holds no authority
 * of its own. It is two primary-key lookups on a page that already runs dozens
 * of queries; correctness is worth more here than the microseconds.
 */
function portalPreviewResolve(): array
{
    $state = [
        'ok'            => false,
        'present'       => false,
        'client_id'     => 0,
        'client_name'   => '',
        'agent_user_id' => 0,
        'agent_name'    => '',
        'started_at'    => 0,
        'drop_reason'   => 'none',
    ];

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $state['drop_reason'] = 'no_session';
        return $state;
    }

    $blob = $_SESSION[PORTAL_PREVIEW_SESSION_KEY] ?? null;

    if (!is_array($blob)) {
        if ($blob !== null) {
            // Something non-array is squatting on the key - bin it.
            portalPreviewClearState();
            $state['present']     = true;
            $state['drop_reason'] = 'malformed';
        }
        return $state;
    }

    $state['present']       = true;
    $state['client_id']     = intval($blob['client_id'] ?? 0);
    $state['agent_user_id'] = intval($blob['agent_user_id'] ?? 0);
    $state['started_at']    = intval($blob['started_at'] ?? 0);

    // 1. A REAL portal login always wins. The two can never coexist, and the
    //    real login is the one with actual credentials behind it.
    if (!empty($_SESSION['client_logged_in'])) {
        portalPreviewClearState();
        $state['drop_reason'] = 'real_portal_login';
        return $state;
    }

    // 2. Shape.
    if ($state['client_id'] < 1 || $state['agent_user_id'] < 1) {
        portalPreviewClearState();
        $state['drop_reason'] = 'malformed';
        return $state;
    }

    // 3. Lifetime, including a clock-skew / tampering guard on started_at.
    $now = time();
    if ($state['started_at'] < 1
        || $state['started_at'] > $now + 300
        || ($now - $state['started_at']) > PORTAL_PREVIEW_MAX_LIFETIME) {
        portalPreviewClearState();
        $state['drop_reason'] = 'expired';
        return $state;
    }

    // 4. THE AUTHORISATION. Not a session boolean - a database lookup proving
    //    the requester is still a live, active, unarchived, admin AGENT user.
    $admin = portalPreviewCurrentAdmin();
    if ($admin === null) {
        portalPreviewClearState();
        $state['drop_reason'] = 'not_admin';
        return $state;
    }

    // 5. It must be the SAME admin who opened it. Without this a preview blob
    //    could survive a logout/login cycle in one PHP session and be inherited
    //    by whoever logs in next.
    if ($admin['user_id'] !== $state['agent_user_id']) {
        portalPreviewClearState();
        $state['drop_reason'] = 'user_mismatch';
        return $state;
    }
    $state['agent_name'] = $admin['user_name'];

    // 6. The department must still exist and not be archived.
    $client = portalPreviewLoadClient($state['client_id']);
    if ($client === null) {
        portalPreviewClearState();
        $state['drop_reason'] = 'client_gone';
        return $state;
    }
    $state['client_name'] = $client['client_name'];

    $state['ok']          = true;
    $state['drop_reason'] = '';
    return $state;
}

/**
 * Re-derives the live admin agent identity FROM THE DATABASE, or null.
 *
 * This mirrors the agent side exactly:
 *
 *   includes/auth_check.php          requires $_SESSION['logged'] + user_id
 *   includes/load_user_session.php   then re-reads the users row and enforces
 *                                    user_type = 1, user_status = 1,
 *                                    user_archived_at IS NULL, and derives
 *                                    $session_is_admin from
 *                                    user_roles.role_is_admin == 1 via
 *                                    users.user_role_id = user_roles.role_id.
 *
 * The one thing that is NOT mirrored is trust in the session flag. Note that
 * login.php's CLIENT FLOW also sets $_SESSION['logged'] = true for a portal
 * contact, so that flag on its own does not distinguish an agent from a
 * contact - the user_type = 1 test in the query below is what does, and it is
 * done against the database on every single call.
 *
 * role_archived_at IS NULL is an addition, not a relaxation: an archived
 * administrator role should not hand out portal previews.
 */
function portalPreviewCurrentAdmin(): ?array
{
    global $mysqli;

    if (session_status() !== PHP_SESSION_ACTIVE || !isset($mysqli)) {
        return null;
    }

    if (empty($_SESSION['logged'])) {
        return null;
    }

    // A session that holds a real portal login is not an agent session.
    if (!empty($_SESSION['client_logged_in'])) {
        return null;
    }

    $user_id = intval($_SESSION['user_id'] ?? 0);
    if ($user_id < 1) {
        return null;
    }

    $sql = mysqli_query($mysqli, "
        SELECT users.user_id, users.user_name
        FROM users
        LEFT JOIN user_roles ON users.user_role_id = user_roles.role_id
        WHERE users.user_id = $user_id
          AND users.user_type = 1
          AND users.user_status = 1
          AND users.user_archived_at IS NULL
          AND user_roles.role_is_admin = 1
          AND user_roles.role_archived_at IS NULL
        LIMIT 1
    ");

    if (!$sql) {
        return null;
    }

    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        return null;
    }

    return [
        'user_id'   => intval($row['user_id']),
        'user_name' => (string) ($row['user_name'] ?? ''),
    ];
}

/**
 * A live (non-archived) department, or null. "Live" is what makes a preview
 * legitimate; an archived department has no portal to look at.
 */
function portalPreviewLoadClient(int $client_id): ?array
{
    global $mysqli;

    $client_id = intval($client_id);
    if ($client_id < 1 || !isset($mysqli)) {
        return null;
    }

    $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1");
    if (!$sql) {
        return null;
    }

    $row = mysqli_fetch_assoc($sql);
    if (!$row) {
        return null;
    }

    return [
        'client_id'   => intval($row['client_id']),
        'client_name' => (string) ($row['client_name'] ?? ''),
    ];
}

/**
 * Removes all preview state. The ONLY session key touched is
 * PORTAL_PREVIEW_SESSION_KEY - never 'logged', 'user_id', 'client_logged_in',
 * 'client_id' or 'contact_id'.
 */
function portalPreviewClearState(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset($_SESSION[PORTAL_PREVIEW_SESSION_KEY]);
}

/**
 * Audit logging for the preview.
 *
 * logAction() attributes a row to `global $session_user_id`. In preview mode
 * check_login.php deliberately sets that global to 0 (see the comment there -
 * a non-zero value would let any missed write path in client/post.php target a
 * real users row). So every log line written here temporarily swaps in the real
 * admin id, writes, and puts the global back exactly as it found it. Otherwise
 * the audit trail for a preview would say "user 0", which is useless.
 *
 * $session_ip / $session_user_agent are filled in if the caller has not set
 * them yet (portalPreviewEnter() can be reached from a POST handler that has,
 * and from one that has not).
 */
function portalPreviewLog(int $agent_user_id, string $action, string $description, int $client_id): void
{
    global $session_user_id, $session_ip, $session_user_agent;

    if (!function_exists('logAction')) {
        return;
    }

    $previous_user_id = $session_user_id ?? null;

    if (empty($session_ip)) {
        $session_ip = sanitizeInput(getIP());
    }
    if (empty($session_user_agent)) {
        $session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    $session_user_id = intval($agent_user_id);

    logAction("Portal Preview", $action, $description, intval($client_id), intval($agent_user_id));

    $session_user_id = $previous_user_id;
}
