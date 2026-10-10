<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
// Review date (optional, YYYY-MM-DD). null = the field was not on the form (keep what is stored); 'NULL' = cleared; otherwise a quoted date.
$review_at_sql = null;
if (isset($_POST['review_at'])) {
    $review_at_raw = trim((string) $_POST['review_at']);
    $review_at_ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $review_at_raw) === 1 && strtotime($review_at_raw) !== false;
    $review_at_sql = $review_at_ok ? "'" . $review_at_raw . "'" : 'NULL';
}
$folder = intval($_POST['folder']);
$description = sanitizeInput($_POST['description']);
$content = mysqli_real_escape_string($mysqli,$_POST['content']);
$content_raw = sanitizeInput($_POST['name'] . " " . str_replace("<", " <", $_POST['content']));
// Content Raw is used for FULL INDEX searching. Adding a space before HTML tags to allow spaces between newlines, bulletpoints, etc. for searching.
