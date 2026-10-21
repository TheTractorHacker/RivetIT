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
        if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1406) {
            flash_alert('One of the values you entered is too long for its field. Nothing was saved - shorten it and try again.', 'error');
            if (!headers_sent()) {
                header('Location: ' . $back);
            }
            exit;
        }
        throw $e;
    });
})();
