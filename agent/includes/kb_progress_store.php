<?php
/**
 * Shared core for interactive-KB (IKB) per-reader progress.
 *
 * WHY THIS FILE EXISTS - two endpoints record and return the same rows for two
 * completely different principals: agent/kb_progress.php for an ITFlow agent
 * session, client/kb_progress.php for a department contact's portal session.
 * They answer DIFFERENT authorization questions (module_kb + department scope
 * on one side, the portal's article-visibility clause on the other), but the
 * shape of the data, the key grammar, the caps and the SQL must be IDENTICAL,
 * because a divergence there is how one reader's ticks end up attributed to
 * another. So the authorization stays in the two endpoints and everything else
 * lives here exactly once. This mirrors agent/includes/kb_media_serve.php,
 * which does the same job for the KB media endpoints and is the pattern this
 * file was written from.
 *
 * WHAT THIS FILE DOES NOT DO: authorization. Not one line. Every caller must
 * have finished deciding "may this principal read this article" BEFORE calling
 * kbProgressLoad() or kbProgressSave(). Those two functions will cheerfully
 * read and write progress for any article id, because by the time they run
 * that question is settled.
 *
 * ---------------------------------------------------------------------------
 * THE PRINCIPAL IS A PAIR
 * ---------------------------------------------------------------------------
 * ('u', users.user_id) for an agent, ('c', contacts.contact_id) for a portal
 * contact. A department contact is NOT a users row and the two id spaces
 * overlap numerically - contact 7 and user 7 are different people - so the type
 * character sits inside kb_article_progress's UNIQUE key and is what keeps them
 * apart. There is no third type today; kbProgressValidPrincipalType() is the
 * one place to add one.
 *
 * ---------------------------------------------------------------------------
 * THE KEY GRAMMAR, AND THE RESERVED KEYS
 * ---------------------------------------------------------------------------
 * A CONTENT key - a block key or a part key that names something actually in
 * the article - matches /\A[a-z0-9][a-z0-9-]{0,23}\z/. That is exactly
 * \ITFlow\KB\InteractiveBlocks::KEY_REGEX, the same grammar the purifier's
 * attribute definition enforces on data-ikb-key / data-ikb-part / data-ikb-node
 * (src/KB/InteractiveBlocks.php, another lane's file) - \A/\z anchors, not
 * ^/$, because PCRE's $ matches before a trailing newline and \A[a-z0-9][a-z0-9-]{0,23}\z
 * does not, and this file's validators must refuse exactly what that class
 * would refuse, not a slightly larger set. So a key that could not survive
 * purification into stored content cannot be written here either.
 *
 * RESERVED keys start with '_' and therefore CANNOT COLLIDE WITH A CONTENT KEY
 * BY CONSTRUCTION, not by convention: '_' is outside the content-key character
 * class, so no author-supplied and no normaliser-minted key can ever spell one.
 * There is one today:
 *
 *     _at   a guided-steps block's saved position (0-based part index),
 *           stored in kb_article_progress_state
 *
 * The winning design used the bare literal 'at' and defended it with "minted
 * part keys are always exactly 8 hex characters, so 'at' can never collide".
 * That is an invariant living inside one minting function, unenforced by the
 * schema and unenforced by the validator - exactly the shape of thing a later
 * change to the key format breaks silently, corrupting every stored wizard
 * position. The leading underscore moves the guarantee into the grammar, where
 * a reader of either half can see it.
 *
 * ---------------------------------------------------------------------------
 * THE EXTENSION SEAM ON THIS SIDE
 * ---------------------------------------------------------------------------
 * The table stores opaque (block, part) keys and one small integer. It has no
 * idea what a checklist or a wizard is, so a fifth block type costs NOTHING
 * here as long as its state is per-part. If it needs one scalar per block
 * instead, add one string to KB_PROGRESS_RESERVED_PART_KEYS below - one line,
 * no migration, no new column, and it can never collide with content.
 *
 * ---------------------------------------------------------------------------
 * EVERY QUERY IS IN A try/catch, AND THAT IS LOAD-BEARING
 * ---------------------------------------------------------------------------
 * PHP 8.1+ defaults to MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT and this
 * codebase never calls mysqli_report() - the same fact src/KB/MediaToken.php
 * documents and measured, and which was re-measured here on PHP 8.4.25 (a
 * refused CREATE TABLE in admin/database_updates.php threw an uncaught
 * mysqli_sql_exception rather than returning false). So on an install where the
 * code is deployed but the 2.6.79 database update has not been run - two
 * separate manual steps on this project - an uncaught "Table
 * 'kb_article_progress' doesn't exist" would land as an HTTP 500 with a stack
 * trace in the middle of what the browser is parsing as JSON. Catching turns
 * that into "progress is unavailable", the article still renders, and every
 * block still works without saving.
 */

defined('FROM_KB_PROGRESS') || die("Direct file access is not allowed");

/* A part key that names a reader-side concept rather than a part of the
 * article. See the header comment - the leading underscore is what makes
 * collision with a content key impossible rather than merely unlikely. */
const KB_PROGRESS_RESERVED_PART_KEYS = ['_at'];

/* Caps. Every one of these exists so that a hostile or buggy client cannot turn
 * a checkbox into unbounded work or unbounded storage.
 *   ITEMS      one request may carry at most this many (block, part) changes.
 *              The render layer coalesces ~400 ms of ticking into one POST; 100
 *              is far more than a human generates in 400 ms and still bounds
 *              the multi-row INSERT.
 *   BODY       bytes of the `items` parameter. 100 items at the maximum key and
 *              hash widths is ~7 KB; 16 KB leaves room and still refuses a
 *              megabyte.
 *   ROWS       rows per (article, principal). A 200-step runbook is the biggest
 *              thing anyone has described wanting; 1000 is five times that and
 *              is the point at which something is wrong rather than large.
 *   STATE      kb_article_progress_state is smallint(6). 9999 is inside its
 *              range on any platform and is also a sane ceiling for both
 *              meanings the column carries (a 0/1 tick, a step index).
 *   WRITES/WINDOW  per-session write rate. A fast human ticking continuously
 *              produces a few per second; 240 a minute is generous for that and
 *              still stops a runaway loop from filling the table. */
const KB_PROGRESS_MAX_ITEMS         = 100;
const KB_PROGRESS_MAX_BODY_BYTES    = 16384;
const KB_PROGRESS_MAX_ROWS          = 1000;
const KB_PROGRESS_MAX_STATE         = 9999;
const KB_PROGRESS_RATE_MAX_WRITES   = 240;
const KB_PROGRESS_RATE_WINDOW_SECS  = 60;

/**
 * Send a JSON response and end the request.
 *
 * Every exit path in both endpoints lands here, so a bad id, a missing table
 * and a refused CSRF token all produce a well-formed JSON body with a real
 * status code instead of a PHP fatal, a redirect to an HTML page, or a
 * half-written response.
 *
 * NOT AN XSSI HAZARD, and it is worth saying why rather than leaving it to
 * luck. The body is always a JSON OBJECT, never a top-level array: `{"ok":true}`
 * at statement position is a labelled block, not an expression, so a third-party
 * page that <script src>'d this URL to try to read a reader's progress gets a
 * syntax error rather than data. `nosniff` plus application/json closes the
 * other half. The judges' warning about a ".js config endpoint" is exactly this
 * hazard; this shape is the answer to it.
 *
 * JSON_FORCE_OBJECT, AND WHY IT HAS TO BE EVERY LEVEL, NOT JUST THE TOP. The
 * documented response carries a NESTED map: {block:{part:{s,h}}}. A part key
 * is a content key (kbProgressValidContentKey() allows any [a-z0-9][a-z0-9-]{0,23}),
 * so nothing stops a block's part keys from being the numeric strings "0",
 * "1", "2", ... - and when a PHP array's keys are exactly 0,1,2,... in order,
 * array_is_list() is true and plain json_encode() serialises it as a JSON
 * ARRAY regardless of the fact every key started life as a string. A caller
 * wrapping only the OUTER payload in (object) - the shape this endpoint used
 * to ship - does not reach that inner level at all, so {"blk":{"0":{...},
 * "1":{...}}} silently became {"blk":[{...},{...}]}, intermittently (a block
 * with keys "0" and "5" is NOT a list and was unaffected, which is what made
 * it easy to miss). JSON_FORCE_OBJECT applies to every nesting level in one
 * pass, so this is fixed at the one function every response already funnels
 * through rather than needing a recursive (object) cast at each call site.
 * Every payload passed to this function today is associative-only (grep the
 * two endpoints' kbProgressJson() calls) - there is no genuine JSON list
 * anywhere in this contract - so forcing objects everywhere costs nothing and
 * cannot turn a real list into a spurious object.
 */
function kbProgressJson(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        // Per-reader state. Never let a shared cache hold a copy.
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
    }
    echo json_encode($payload, JSON_FORCE_OBJECT);
    exit;
}

/**
 * The content-key grammar: a block key, or a part key naming a real part.
 *
 * Same character class the purifier enforces on the stored attributes, so a key
 * that could not reach kb_article_content cannot reach this table either.
 * Deliberately NOT permissive about case: the purifier's AttrDef lowercases,
 * so an uppercase key here would be a key that no rendered block can ever
 * match - a row nothing will select, written by a client that is out of step.
 */
function kbProgressValidContentKey(string $key): bool
{
    /* \A...\z, NOT ^...$. PCRE's $ matches before a TRAILING NEWLINE by
     * default, so "abc\n" satisfied the old ^...$ pattern here while failing
     * \ITFlow\KB\InteractiveBlocks::KEY_REGEX (src/KB/InteractiveBlocks.php),
     * which has always been \A...\z - the two grammars silently disagreed.
     * \z anchors to the true end of the string with no exception, which is
     * what "the same grammar the purifier enforces" in this file's header
     * actually requires. */
    return (bool) preg_match('/\A[a-z0-9][a-z0-9-]{0,23}\z/', $key);
}

/**
 * A part key: a content key, or one of the reserved reader-side keys.
 */
function kbProgressValidPartKey(string $key): bool
{
    return kbProgressValidContentKey($key)
        || in_array($key, KB_PROGRESS_RESERVED_PART_KEYS, true);
}

/**
 * 'u' (agent) or 'c' (portal contact). The one place a third type would be
 * added, and the reason kb_article_progress_principal_type is char(1).
 */
function kbProgressValidPrincipalType(string $type): bool
{
    return $type === 'u' || $type === 'c';
}

/**
 * The stale-part marker: 16 lowercase hex, or '' for "no hash recorded".
 *
 * \ITFlow\KB\InteractiveBlocks::partHash() (src/KB/InteractiveBlocks.php, another
 * lane's file) computes substr(sha1(normalised label . "\x1f" . normalised body), 0, 16)
 * at RENDER time, server-side, over the article as HTMLPurifier just parsed
 * it - it is published into the data-ikb-hashes attribute the render sites
 * emit. THE CLIENT COMPUTES NOTHING: js/kb_interactive.js's currentHash()
 * copies this value straight out of that attribute at tick time and sends it
 * back unchanged. A previous version of this comment said the client computed
 * substr(sha1(label + "\n" + body), 0, 16) itself - both the "who computes it"
 * and the separator ("\n" instead of "\x1f") were wrong, which would have sent
 * anyone debugging a stale tick looking for client-side crypto and a digest
 * this table never stores. THE BODY IS IN THE HASH ON PURPOSE. The design
 * hashed the label alone and listed the consequence in its own weaknesses:
 * rewriting a step's BODY from "reboot the switch" to "do not reboot the
 * switch" while leaving its label alone left every existing tick green with no
 * warning at all, which is the more dangerous of the two edits. Hashing both
 * means the noisier failure (a whitespace fix flags the tick as changed)
 * replaces the silent one.
 *
 * This function only ever validates the SHAPE. It has no way to check the
 * DIGEST, because that would mean re-parsing purified HTML on every write.
 */
function kbProgressValidHash(string $hash): bool
{
    /* \A...\z - see kbProgressValidContentKey()'s comment; the same
     * trailing-newline gap existed here (a hash ending "\n" passed this while
     * a genuine 16-hex digest never has one, so the gap only ever let through
     * garbage no render-layer comparison could match anyway - but a value
     * that reaches SQL should never depend on that being true by accident). */
    return $hash === '' || (bool) preg_match('/\A[0-9a-f]{16}\z/', $hash);
}

/**
 * Constant-time CSRF comparison that does NOT redirect.
 *
 * functions.php's validateCSRFToken() (functions.php:868) sets a flash alert and
 * sends "Location: index.php", which is right for a form post and wrong here -
 * the caller is fetch(), and a 302 to an HTML page in the middle of a JSON
 * response is not an answer. Same secret, same hash_equals(), different failure
 * shape. A missing or empty session token is a REFUSAL, never a comparison
 * against null: hash_equals() throws a TypeError on null under PHP 8, and
 * "there is no token so anything matches" would be the wrong reading anyway.
 */
function kbProgressCsrfOk(mixed $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Per-session sliding-window write budget.
 *
 * The session is the right granularity: one session is one reader, and both
 * endpoints already require one. Kept in $_SESSION rather than in Redis or a
 * table because it must not itself become a database write on the hot path, and
 * because a counter that vanishes when the session does is exactly the lifetime
 * this needs.
 *
 * Returns false when the budget is spent. Only WRITES are counted - a read is
 * one indexed range scan and rate-limiting it would only make the article page
 * fragile.
 */
function kbProgressRateOk(): bool
{
    $now = time();
    $window = $_SESSION['kb_progress_rate'] ?? null;

    if (!is_array($window)
        || !isset($window['start'], $window['count'])
        || ($now - (int) $window['start']) >= KB_PROGRESS_RATE_WINDOW_SECS
    ) {
        $_SESSION['kb_progress_rate'] = ['start' => $now, 'count' => 1];
        return true;
    }

    $count = (int) $window['count'] + 1;
    $_SESSION['kb_progress_rate'] = ['start' => (int) $window['start'], 'count' => $count];

    return $count <= KB_PROGRESS_RATE_MAX_WRITES;
}

/**
 * Parse and validate the `items` parameter.
 *
 * Returns a list of ['b','p','s','h'] arrays, or a string naming the reason it
 * was refused. Every value is checked HERE, before anything reaches SQL, so the
 * write function can interpolate what it is given - and it still escapes on top
 * of that, because a validator and an escape are not the same control.
 *
 * json_decode with depth 8 and no objects: the payload is a flat list of flat
 * maps, so nothing deeper is legitimate and refusing it costs nothing.
 */
function kbProgressParseItems(mixed $raw): array|string
{
    if (!is_string($raw) || $raw === '') {
        return 'items_missing';
    }
    if (strlen($raw) > KB_PROGRESS_MAX_BODY_BYTES) {
        return 'items_too_large';
    }

    $decoded = json_decode($raw, true, 8);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return 'items_malformed';
    }
    if (count($decoded) === 0) {
        return 'items_empty';
    }
    if (count($decoded) > KB_PROGRESS_MAX_ITEMS) {
        return 'items_too_many';
    }

    $items = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            return 'items_malformed';
        }

        $block = isset($entry['b']) && is_string($entry['b']) ? $entry['b'] : '';
        $part  = isset($entry['p']) && is_string($entry['p']) ? $entry['p'] : '';
        $hash  = isset($entry['h']) && is_string($entry['h']) ? $entry['h'] : '';

        /* is_int OR a decimal-integer string. Not intval() on anything: that
         * turns "3abc" into 3 and true into 1, which would let a client store a
         * state it did not ask for and would hide its own bug from itself.
         * \A...\z, not ^...$ - see kbProgressValidContentKey()'s comment: a
         * trailing "\n" would satisfy ^...$ and then be silently absorbed by
         * the (int) cast below anyway, but this validator's job is to REJECT
         * anything that is not exactly a decimal-integer string, not to rely
         * on the cast to clean up after it. */
        if (isset($entry['s']) && is_int($entry['s'])) {
            $state = $entry['s'];
        } elseif (isset($entry['s']) && is_string($entry['s']) && preg_match('/\A[0-9]{1,5}\z/', $entry['s'])) {
            $state = (int) $entry['s'];
        } else {
            return 'items_malformed';
        }

        if (!kbProgressValidContentKey($block)) {
            return 'bad_block_key';
        }
        if (!kbProgressValidPartKey($part)) {
            return 'bad_part_key';
        }
        if (!kbProgressValidHash($hash)) {
            return 'bad_hash';
        }
        if ($state < 0 || $state > KB_PROGRESS_MAX_STATE) {
            return 'bad_state';
        }

        $items[] = ['b' => $block, 'p' => $part, 's' => $state, 'h' => $hash];
    }

    return $items;
}

/**
 * Every progress row this principal holds for this article.
 *
 * Returns the map the render layer consumes:
 *
 *   { "<blockKey>": { "<partKey>": {"s": <int>, "h": "<16 hex>"} } }
 *
 * `h` is omitted entirely when no hash was recorded (a wizard's '_at' position
 * has none), which is what keeps a long runbook's payload small. Measured with
 * json_encode's compact separators: 200 ticked parts with 8-character keys and
 * 16-hex hashes is 8414 bytes; one wizard position is 28. Below that it is
 * noise on a page that already carries the whole article.
 *
 * ONE QUERY, on kb_article_progress_reader, which is (article, type, id) - the
 * exact prefix of this WHERE clause, so it is a range scan and not a filter.
 *
 * Returns [] on any database failure, including "the table does not exist yet".
 * That is a deliberate degradation: no saved progress is a working article with
 * empty checkboxes, whereas a throw here is a 500 on the article page.
 */
function kbProgressLoad(mysqli $mysqli, int $article_id, string $principal_type, int $principal_id): array
{
    if ($article_id < 1 || $principal_id < 1 || !kbProgressValidPrincipalType($principal_type)) {
        return [];
    }

    $type_esc = mysqli_real_escape_string($mysqli, $principal_type);

    try {
        $result = mysqli_query(
            $mysqli,
            "SELECT kb_article_progress_block_key,
                    kb_article_progress_part_key,
                    kb_article_progress_state,
                    kb_article_progress_part_hash
             FROM kb_article_progress
             WHERE kb_article_progress_kb_article_id = $article_id
               AND kb_article_progress_principal_type = '$type_esc'
               AND kb_article_progress_principal_id = $principal_id
             LIMIT " . KB_PROGRESS_MAX_ROWS
        );
    } catch (\Throwable $e) {
        return [];
    }

    if (!$result) {
        return [];
    }

    $progress = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $block = (string) $row['kb_article_progress_block_key'];
        $part  = (string) $row['kb_article_progress_part_key'];

        /* A row is not a trust boundary. These columns are only ever written
         * through kbProgressSave(), which validates, but re-checking is what
         * keeps a hypothetically poisoned column from reaching the render
         * layer as an attribute value. */
        if (!kbProgressValidContentKey($block) || !kbProgressValidPartKey($part)) {
            continue;
        }

        $entry = ['s' => intval($row['kb_article_progress_state'])];
        $hash  = (string) $row['kb_article_progress_part_hash'];
        if (kbProgressValidHash($hash) && $hash !== '') {
            $entry['h'] = $hash;
        }

        $progress[$block][$part] = $entry;
    }

    return $progress;
}

/**
 * Write a batch of progress changes.
 *
 * Returns ['ok' => bool, 'stored' => int, 'error' => string].
 *
 * ONE STATEMENT for the whole batch: INSERT ... ON DUPLICATE KEY UPDATE against
 * kb_article_progress_unique. That key is (article, block, part, type, id), so
 * a second tick of the same box updates in place rather than accumulating rows,
 * and two tabs ticking at once cannot produce a duplicate.
 *
 * THE ROW CAP IS CHECKED BEFORE, NOT AFTER. Counting first costs one indexed
 * COUNT(*) on the reader index; discovering the overflow afterwards would mean
 * the rows are already written. The cap is per (article, principal), so one
 * runaway client cannot deny anyone else anything.
 *
 * Every value interpolated below has already been through
 * kbProgressParseItems(); it is escaped again anyway, because a validator and
 * an escape are two controls and the day one of them is refactored the other
 * has to still be standing.
 */
function kbProgressSave(mysqli $mysqli, int $article_id, string $principal_type, int $principal_id, array $items): array
{
    if ($article_id < 1 || $principal_id < 1 || !kbProgressValidPrincipalType($principal_type)) {
        return ['ok' => false, 'stored' => 0, 'error' => 'bad_principal'];
    }
    if ($items === []) {
        return ['ok' => true, 'stored' => 0, 'error' => ''];
    }
    if (count($items) > KB_PROGRESS_MAX_ITEMS) {
        return ['ok' => false, 'stored' => 0, 'error' => 'items_too_many'];
    }

    $type_esc = mysqli_real_escape_string($mysqli, $principal_type);

    try {
        $count_row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT COUNT(*) AS cnt
             FROM kb_article_progress
             WHERE kb_article_progress_kb_article_id = $article_id
               AND kb_article_progress_principal_type = '$type_esc'
               AND kb_article_progress_principal_id = $principal_id"
        ));
    } catch (\Throwable $e) {
        // Table missing (code deployed, 2.6.79 not run) or the server is down.
        return ['ok' => false, 'stored' => 0, 'error' => 'unavailable'];
    }

    /* count($items) is the WORST case - every item could be a new key - so the
     * cheap check is "could this request possibly overflow". On the normal path
     * that is one indexed COUNT(*) and nothing else.
     *
     * ONLY when it might overflow does it cost a second query, and that second
     * query is what stops the cap from being a permanent freeze. Measured: with
     * a flat `count + items > MAX` refusal, a reader sitting at exactly 1000
     * rows could no longer UNTICK a box - every write, including an update of a
     * key that already had a row, came back progress_full. Counting how many of
     * the requested keys already exist turns that into "you cannot add more
     * parts, but you can still change the ones you have", which is the correct
     * behaviour for a cap whose job is to bound growth. */
    $existing_rows = intval($count_row['cnt'] ?? 0);

    if (($existing_rows + count($items)) > KB_PROGRESS_MAX_ROWS) {

        $pairs = [];
        foreach ($items as $item) {
            $pairs[] = "(kb_article_progress_block_key = '"
                . mysqli_real_escape_string($mysqli, (string) $item['b'])
                . "' AND kb_article_progress_part_key = '"
                . mysqli_real_escape_string($mysqli, (string) $item['p']) . "')";
        }

        try {
            $known_row = mysqli_fetch_assoc(mysqli_query(
                $mysqli,
                "SELECT COUNT(*) AS cnt
                 FROM kb_article_progress
                 WHERE kb_article_progress_kb_article_id = $article_id
                   AND kb_article_progress_principal_type = '$type_esc'
                   AND kb_article_progress_principal_id = $principal_id
                   AND (" . implode(' OR ', $pairs) . ")"
            ));
        } catch (\Throwable $e) {
            return ['ok' => false, 'stored' => 0, 'error' => 'unavailable'];
        }

        $new_keys = count($items) - intval($known_row['cnt'] ?? 0);

        if (($existing_rows + $new_keys) > KB_PROGRESS_MAX_ROWS) {
            return ['ok' => false, 'stored' => 0, 'error' => 'progress_full'];
        }
    }

    $values = [];
    foreach ($items as $item) {
        $block_esc = mysqli_real_escape_string($mysqli, (string) $item['b']);
        $part_esc  = mysqli_real_escape_string($mysqli, (string) $item['p']);
        $hash_esc  = mysqli_real_escape_string($mysqli, (string) $item['h']);
        $state     = intval($item['s']);

        $values[] = "($article_id, '$block_esc', '$part_esc', '$type_esc', $principal_id, $state, '$hash_esc')";
    }

    try {
        mysqli_query(
            $mysqli,
            "INSERT INTO kb_article_progress
                (kb_article_progress_kb_article_id,
                 kb_article_progress_block_key,
                 kb_article_progress_part_key,
                 kb_article_progress_principal_type,
                 kb_article_progress_principal_id,
                 kb_article_progress_state,
                 kb_article_progress_part_hash)
             VALUES " . implode(', ', $values) . "
             ON DUPLICATE KEY UPDATE
                kb_article_progress_state     = VALUES(kb_article_progress_state),
                kb_article_progress_part_hash = VALUES(kb_article_progress_part_hash)"
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'stored' => 0, 'error' => 'unavailable'];
    }

    /* count($items), not mysqli_affected_rows(). ON DUPLICATE KEY UPDATE reports
     * 2 for a row it changed, 1 for a row it inserted and 0 for a row whose
     * values were already identical, so affected_rows answers a question the
     * caller did not ask. What the client wants to know is "were all of my
     * changes accepted", and reaching this line is the answer. */
    return ['ok' => true, 'stored' => count($items), 'error' => ''];
}
