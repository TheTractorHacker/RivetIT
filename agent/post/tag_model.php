<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$type = intval($_POST['type']);
$color = sanitizeInput($_POST['color']);
// Stored without the 'fa-' prefix (templates render fa-fw fa-$icon); '' = default icon.
$icon = preg_replace('/^fa-/', '', \RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', ''));
