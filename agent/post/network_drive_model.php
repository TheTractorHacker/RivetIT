<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$letter = sanitizeInput($_POST['letter']);
$path = sanitizeInput($_POST['path']);
$purpose = sanitizeInput($_POST['purpose']);
$notes = sanitizeInput($_POST['notes']);
