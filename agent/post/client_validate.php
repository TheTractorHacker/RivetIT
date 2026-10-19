<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Server-side checks for the department add/edit forms (the browser's required/maxlength can be bypassed, and a
// whitespace-only name passes "required"). Needs the variables client_model.php just built; a failure flashes a
// message and goes back to the form instead of saving a blank name or hitting a column-length 500.
$client_form_error = '';
if ($name === '') {
    $client_form_error = 'Department name is required.';
} elseif (mb_strlen($name) > 200) {
    $client_form_error = 'Department name must be 200 characters or fewer.';
} elseif (mb_strlen($abbreviation) > 10) {
    $client_form_error = 'Abbreviation must be 10 characters or fewer.';
} elseif (mb_strlen($website) > 200 || mb_strlen($referral) > 200 || mb_strlen($type) > 200) {
    $client_form_error = 'Website, referral and type must be 200 characters or fewer.';
} elseif (mb_strlen($tax_id_number) > 255) {
    $client_form_error = 'Tax ID must be 255 characters or fewer.';
}
if ($client_form_error !== '') {
    flash_alert($client_form_error, 'error');
    redirect();
}
