<?php

/*
 * RivetIT - POST handler for admin/settings_custom_fields.php
 *
 * admin/post.php loads the handler named after the page the request came from, so without this file the Create /
 * Edit / Delete buttons on the Custom fields page posted to a handler that did not exist and silently did nothing.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/custom_field.php';
