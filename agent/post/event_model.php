<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$calendar_id = intval($_POST['calendar']);
$title = sanitizeInput($_POST['title']);
$location = sanitizeInput($_POST['location']);
$description = sanitizeInput($_POST['description']);
$start = sanitizeInput($_POST['start']);
$end = sanitizeInput($_POST['end']);
// Repeat was a disabled control that nothing ever expanded; it is gone. The event_repeat column and any existing
// values are left untouched (events no longer write it).
$client_id = intval($_POST['client_id']);
$email_event = intval($_POST['email_event'] ?? 0);
