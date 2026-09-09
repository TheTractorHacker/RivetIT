<?php
/**
 * Authorization primitives for agent/kb_media.php's SIGNED branch.
 *
 * WHY THESE ARE RE-IMPLEMENTATIONS RATHER THAN CALLS. Every rule below already
 * exists twice in this codebase - once for the cookie-authenticated web app in
 * functions.php, once for the token-authenticated API in
 * api/v1/includes/api_permissions.php - and neither original is callable here:
 *
 *   - The functions.php versions read $session_* GLOBALS and answer by
 *     flash_alert()+redirect(). There is no session in the signed branch, and a
 *     302 to an HTML page is the wrong answer to an <img> request; a binary
 *     endpoint needs a bool and a status code.
 *   - The api_permissions.php versions are behind `defined('FROM_API')` and
 *     read $api_user_id / $api_key_client_id globals set by api/v1/index.php.
 *     Defining FROM_API in a non-API endpoint to borrow them would be a lie
 *     about the request's provenance.
 *
 * So this is a third copy, and copies drift. Each function below names the two
 * originals it must stay in step with. If you change a rule in one of those,
 * change it here.
 */

defined('FROM_KB_MEDIA') || die("Direct file access is not allowed");

/**
 * Does this user hold an admin role?
 *
 * Same notion of "admin" as everywhere else: user_roles.role_is_admin. Mirrors
 * includes/load_user_session.php:26 (which sets $session_is_admin) and
 * api/v1/includes/api_permissions.php:16.
 *
 * LEFT JOIN, not INNER: a user with no role row at all must resolve to "not
 * admin" rather than to no row, so that the caller's fallback is deny.
 */
function kbMediaUserIsAdmin(int $user_id): bool
{
    global $mysqli;

    if ($user_id < 1) {
        return false;
    }

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT r.role_is_admin
         FROM users u
         LEFT JOIN user_roles r ON r.role_id = u.user_role_id
         WHERE u.user_id = $user_id
         LIMIT 1"
    ));

    return (bool) ($row && $row['role_is_admin']);
}

/**
 * module_kb at >= $min_level for this user.
 *
 * Byte-for-byte the same two queries as api_has_module_permission()
 * (api/v1/includes/api_permissions.php:10) - admin role bypasses, otherwise
 * user_role_permissions JOIN modules - which is itself the API mirror of
 * lookupUserPermission() (functions.php:3410).
 *
 * Level 1, not 2, at the call site: viewing an attachment or an inline image is
 * a READ, and requiring write would lock read-only KB roles out of the very
 * articles they are allowed to read.
 */
function kbMediaHasModuleKb(int $user_id, int $min_level = 1): bool
{
    global $mysqli;

    if ($user_id < 1) {
        return false;
    }

    $role = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT u.user_role_id, r.role_is_admin
         FROM users u
         LEFT JOIN user_roles r ON r.role_id = u.user_role_id
         WHERE u.user_id = $user_id
         LIMIT 1"
    ));

    if (!$role) {
        return false;
    }
    if ($role['role_is_admin']) {
        return true;
    }

    $role_id   = intval($role['user_role_id']);
    $min_level = max(1, $min_level);

    return (bool) mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT urp.user_role_permission_level
         FROM user_role_permissions urp
         JOIN modules m ON m.module_id = urp.module_id
         WHERE urp.user_role_id = $role_id
           AND m.module_name = 'module_kb'
           AND urp.user_role_permission_level >= $min_level
         LIMIT 1"
    ));
}

/**
 * Department (client) scope for one already-known client id. The bool-returning
 * twin of enforceClientAccess() (functions.php:3469) and of
 * api_client_scope_ok() (api/v1/includes/api_permissions.php:78).
 *
 * IDENTICAL RULE, stated explicitly because it is surprising:
 *   - an article with client_id 0 is not department-scoped and is readable by
 *     anyone who got past the module check. This mirrors the callers of
 *     enforceClientAccess(), which all guard it with `if ($client_id > 0)` -
 *     including agent/kb_article_attachment.php:106.
 *   - an admin role bypasses everything.
 *   - a user with ZERO user_client_permissions rows is allowed ALL departments.
 *     That is a fail-OPEN default and it looks wrong in isolation, but it is
 *     the app-wide meaning of "no restriction configured" and changing it here
 *     alone would make this endpoint stricter than the article page that links
 *     to it - i.e. would break working installs while fixing nothing.
 *   - otherwise the (user, client) row must exist.
 *
 * $key_client_id is the legacy X-Api-Key's own api_key_client_id restriction,
 * 0 for "no restriction". It is ANDed on top rather than replacing the user
 * scope - the key resolves to the fallback admin user, so without it a
 * department-scoped legacy key would mint an admin-scoped capability.
 *
 * THE RULE THIS MUST TRACK IS $kb_client_scope_clause IN api/v1/kb.php - NOT
 * api_client_scope_ok(). That distinction was got wrong once, and it broke
 * every image in every company-wide article for department-scoped legacy
 * keys, so it is written down here. api_client_scope_ok()
 * (api_permissions.php:81) applies the key restriction to EVERY client_id
 * including 0 - but the KB article endpoint does not use it. It builds
 *
 *     (kb_articles.kb_article_client_id = 0 OR api_client_scope_sql(...))
 *
 * so a company-wide article is exempt from the key restriction entirely. This
 * endpoint mints nothing; it only re-checks what kb.php already served. Being
 * stricter than the endpoint that handed out the URL is not "safer", it is a
 * guaranteed functional break - it was reproduced as a 200 on the article and
 * a 403 on 100% of its images. Hence the `$client_id > 0` conjunct below. If
 * that clause ever changes, change this with it. (Named by symbol, not by
 * line: grep -n kb_client_scope_clause api/v1/kb.php finds it in one hop and
 * cannot go stale under an unrelated edit.)
 */
function kbMediaClientAccessOk(int $user_id, int $client_id, int $key_client_id = 0): bool
{
    global $mysqli;

    if ($user_id < 1) {
        return false;
    }

    // The key's restriction binds even for an admin - it is a property of the
    // credential, not of the person - but NOT on a company-wide (client_id 0)
    // article, because api/v1/kb.php's $kb_client_scope_clause does not apply
    // it there either.
    if ($key_client_id > 0 && $client_id > 0 && $key_client_id !== $client_id) {
        return false;
    }

    if ($client_id <= 0) {
        return true;
    }

    if (kbMediaUserIsAdmin($user_id)) {
        return true;
    }

    return (bool) mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT 1 AS ok WHERE
            NOT EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $user_id LIMIT 1)
            OR EXISTS (SELECT 1 FROM user_client_permissions WHERE user_id = $user_id AND client_id = $client_id LIMIT 1)"
    ));
}

/**
 * Turn a signed URL's principal string back into a LIVE user, or null.
 *
 * This is the step that makes a capability token an authentication artifact
 * rather than an authorization grant. The token says "principal t42 asked for
 * this"; this function goes to the database and asks whether t42 still exists,
 * still resolves to an enabled non-archived agent, and - for a legacy key -
 * has not expired. Nothing is cached and nothing is trusted from the URL.
 *
 * Returns ['user_id' => int, 'key_client_id' => int] or null.
 *
 * DELIBERATELY READ-ONLY. api/v1/index.php:151 DELETEs a Bearer token it finds
 * inactive for 90 days, and :158 UPDATEs token_last_used_at on every call; this
 * does neither. A GET for an image must not mutate credential state, and refreshing
 * token_last_used_at from a media fetch would let a stream of <img> requests
 * keep a token alive that no API client is actually using.
 */
function kbMediaResolvePrincipal(string $principal): ?array
{
    global $mysqli;

    // Caller has already format-validated, but this is the function that turns a
    // string into SQL, so it validates again rather than inheriting an assumption.
    if (!\ITFlow\KB\MediaToken::isValidPrincipal($principal)) {
        return null;
    }

    $type = $principal[0];
    $id   = intval(substr($principal, 1));
    if ($id < 1) {
        return null;
    }

    if ($type === 't') {
        /* Bearer token. The three user filters are api/v1/index.php:141-143
           verbatim - a token whose owner was disabled, archived or demoted out
           of user_type 1 stops working everywhere else, so it must stop working
           here too. The COALESCE clause is the SQL form of the 90-day inactivity
           expiry at api/v1/index.php:149-150: same cut-off, minus the DELETE. */
        $row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT t.token_user_id
             FROM api_tokens t
             JOIN users u ON u.user_id = t.token_user_id
             WHERE t.token_id = $id
               AND u.user_status = 1
               AND u.user_archived_at IS NULL
               AND u.user_type = 1
               AND COALESCE(t.token_last_used_at, t.token_created_at) > (NOW() - INTERVAL 90 DAY)
             LIMIT 1"
        ));

        if (!$row) {
            return null;
        }

        return ['user_id' => intval($row['token_user_id']), 'key_client_id' => 0];
    }

    if ($type === 'k') {
        /* Legacy instance-wide X-Api-Key. Naming the KEY rather than the user
           it resolves to is the entire point: api_keys carries an expiry and a
           department restriction that the resolved user does not, so signing
           'u<admin_id>' instead would (a) keep outstanding URLs alive after the
           key was deleted or expired and (b) hand a department-scoped key an
           admin-scoped capability. */
        $key_row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT api_key_client_id
             FROM api_keys
             WHERE api_key_id = $id
               AND api_key_expire > NOW()
             LIMIT 1"
        ));

        if (!$key_row) {
            return null;
        }

        // api_keys has no user column at all, so the API resolves a legacy key
        // to "the first admin". Same query as api/v1/index.php:190-192, so the
        // two cannot pick different users.
        $admin = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT user_id FROM users
             WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
             ORDER BY user_id LIMIT 1"
        ));

        if (!$admin) {
            return null;
        }

        return [
            'user_id'       => intval($admin['user_id']),
            'key_client_id' => intval($key_row['api_key_client_id'] ?? 0),
        ];
    }

    /* 'u' - a bare user id. THE WEAK PRINCIPAL, kept only so that a legacy key
       row lacking an id has somewhere to go. It carries no credential identity
       at all: revoking the caller's token or key does not invalidate a URL
       signed this way, only disabling/archiving the user does. Nothing should
       mint one if a 't' or 'k' principal is available. */
    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT user_id FROM users
         WHERE user_id = $id
           AND user_status = 1
           AND user_archived_at IS NULL
           AND user_type = 1
         LIMIT 1"
    ));

    if (!$row) {
        return null;
    }

    return ['user_id' => intval($row['user_id']), 'key_client_id' => 0];
}
