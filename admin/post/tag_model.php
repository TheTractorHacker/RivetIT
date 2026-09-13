<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
// Only add_tag's form sends this - a tag's type is fixed at creation by
// design (tag_edit.php shows it read-only, since retyping a tag already
// applied to records would be confusing), so edit_tag never sends it.
$type = intval($_POST['type'] ?? 0);
$color = sanitizeInput($_POST['color']);
$icon = preg_replace("/[^0-9a-zA-Z-]/", "", sanitizeInput($_POST['icon']));
