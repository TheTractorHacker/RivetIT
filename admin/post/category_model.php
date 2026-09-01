<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$description = sanitizeInput($_POST['description']);
$type = sanitizeInput($_POST['type']);
$color = sanitizeInput($_POST['color']);
$parent = intval($_POST['category_parent'] ?? 0);
