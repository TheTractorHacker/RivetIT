<?php

/*
 * Training JSON endpoint: /agent/training_ajax.php?action=<name> (spec §3.2, §6).
 *
 * Deliberately lean: no page chrome. The output buffer swallows anything the bootstrap might
 * print, so the only thing that ever reaches the client is the Router's JSON envelope. An
 * expired session makes check_login.php answer with a 302 to login.php; the client sees a
 * non-JSON response and reports it as code 'session'.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();

\ITFlow\Training\Api\Router::handle($mysqli);
