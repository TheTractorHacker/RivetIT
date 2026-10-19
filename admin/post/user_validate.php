<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/**
 * Server-side checks for the staff user add/edit forms. $name and $email are already sanitizeInput()ed by user_model.php.
 * $exclude_user_id is the user being edited (0 when adding). Returns a message, or null when the input is fine.
 */
function userFormError($mysqli, $name, $email, $exclude_user_id = 0)
{
    if (trim($name) === '') {
        return 'Name is required.';
    }
    if (mb_strlen($name) > 200 || mb_strlen($email) > 200) {
        return 'Name and e-mail must be 200 characters or fewer.';
    }
    if (!filter_var(html_entity_decode($email), FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid e-mail address.';
    }
    $exclude_user_id = intval($exclude_user_id);
    $stmt = mysqli_prepare($mysqli, "SELECT user_id FROM users WHERE user_type = 1 AND LOWER(user_email) = LOWER(?) AND user_id <> ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'si', $email, $exclude_user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $taken = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    if ($taken) {
        return 'A user with that e-mail address already exists.';
    }
    return null;
}
