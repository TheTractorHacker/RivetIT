<?php
defined('FROM_API') || die();

/**
 * Thin prepared-statement helpers for the v1 API.
 *
 * The rest of the API is procedural mysqli with intval()/floatval()/
 * mysqli_real_escape_string(). These two wrappers give the highest-risk
 * user-supplied-string paths (ticket subject/details/reply bodies, search
 * LIKE terms) real parameter binding without touching response bytes.
 *
 * $types is the usual mysqli type string ('s','i','d','b'); when left empty
 * it defaults to all-strings, which binds every scalar safely. A PHP null in
 * $params binds as SQL NULL regardless of its type char (matching the
 * existing dbExecute() helper in functions.php).
 */

/**
 * Run a prepared SELECT and return every row as an associative array.
 *
 * @return array<int,array<string,mixed>> rows (empty on prepare failure)
 */
function api_q(string $sql, string $types = '', array $params = []): array {
    global $mysqli;
    $stmt = mysqli_prepare($mysqli, $sql);
    if ($stmt === false) {
        return [];
    }
    if (!empty($params)) {
        if ($types === '') {
            $types = str_repeat('s', count($params));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * Run a prepared write (INSERT/UPDATE/DELETE).
 *
 * @return int the new AUTO_INCREMENT id for an INSERT, otherwise the number of
 *             affected rows (0 on prepare failure).
 */
function api_exec(string $sql, string $types = '', array $params = []): int {
    global $mysqli;
    $stmt = mysqli_prepare($mysqli, $sql);
    if ($stmt === false) {
        return 0;
    }
    if (!empty($params)) {
        if ($types === '') {
            $types = str_repeat('s', count($params));
        }
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $insert_id = mysqli_insert_id($mysqli);
    $affected  = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $insert_id > 0 ? $insert_id : max(0, $affected);
}
