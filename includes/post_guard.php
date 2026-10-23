<?php
/*
 * Shared guard for the agent and admin POST dispatchers (agent/post.php, admin/post.php); loaded after
 * FROM_POST_HANDLER is defined and before any handler runs.
 *
 *  1. A name/subject/title field that is present but only whitespace is rejected. HTML `required` lets "   "
 *     through, and every handler then stored an invisible, un-clickable record.
 *  2. MariaDB "Data too long" (1406) on an over-long value used to be an uncaught exception (blank HTTP 500
 *     page, whole request lost). It now sends the user back with a readable message.
 */
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

(function () {
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    $host = parse_url($back, PHP_URL_HOST);
    // only ever bounce back to our own site; otherwise fall back to the site root
    $back = ($host !== null && $host === ($_SERVER['HTTP_HOST'] ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : null)) ? $back : '/';

    foreach ($_POST as $key => $value) {
        if (is_string($value) && $value !== '' && trim($value) === '' && preg_match('/(^|_)(name|subject|title)$/', (string) $key)) {
            flash_alert('A name cannot be blank or only spaces. Nothing was saved.', 'error');
            header('Location: ' . $back);
            exit;
        }
    }

    set_exception_handler(function (Throwable $e) use ($back) {
        // The RivetCore database adapter wraps driver errors in its own exception class; look through the chain by message too.
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if (!($x instanceof mysqli_sql_exception) && preg_match('/Data too long for column|Out of range value for column|Incorrect (integer|decimal) value/i', $x->getMessage())) {
                $msg = stripos($x->getMessage(), 'Data too long') !== false
                    ? 'One of the values you entered is too long for its field. Nothing was saved - shorten it and try again.'
                    : 'One of the numbers you entered is too large, too small or not valid for its field. Nothing was saved - check the value and try again.';
                flash_alert($msg, 'error');
                if (!headers_sent()) { header('Location: ' . $back); }
                exit;
            }
        }
        if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1406) {
            flash_alert('One of the values you entered is too long for its field. Nothing was saved - shorten it and try again.', 'error');
            if (!headers_sent()) {
                header('Location: ' . $back);
            }
            exit;
        }
        // 1264 out of range / 1265 truncated / 1366 incorrect integer / 1292 incorrect value: a number typed outside the column's range
        // (e.g. 99999999999999999999 in an "Order" box) used to be the same blank HTTP 500.
        if ($e instanceof mysqli_sql_exception && in_array((int) $e->getCode(), [1264, 1265, 1366, 1292], true)) {
            flash_alert('One of the numbers you entered is too large, too small or not valid for its field. Nothing was saved - check the value and try again.', 'error');
            if (!headers_sent()) {
                header('Location: ' . $back);
            }
            exit;
        }
        throw $e;
    });
})();

/** Reject a POST where a required text field is empty or only spaces (the browser's required attribute accepts "   "). */
function requireFilledPost(array $labelsByKey): void
{
    foreach ($labelsByKey as $key => $label) {
        if (trim((string) ($_POST[$key] ?? '')) === '') {
            flash_alert("$label cannot be blank or only spaces. Nothing was saved.", 'error');
            redirect();
        }
    }
}
