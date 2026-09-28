<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_GET['update'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    validateAdminRole(); // Old function

    //git fetch downloads the latest from remote without trying to merge or rebase anything. Then the git reset resets the branch to what you just fetched. The --hard option changes all the files in your working tree to match the files in origin/main

    if (isset($_GET['force_update']) == 1) {
        exec("git fetch --all");
        exec("git reset --hard origin/main");
    } else {
        exec("git pull");
    }
    //header("Location: post.php?update_db");


    // Telemetry was removed: this used to POST company name/site/city/state/country and usage
    // counts to the upstream ITFlow project on every "Update App" click. The old condition was
    // `$config_telemetry > 0 OR $config_telemetry = 2` -- a stray "=" instead of "==", so it was
    // ALWAYS true and always sent, regardless of the Telemetry setting (found while removing this
    // feature). RivetIT sends nothing anywhere.

    logAction("App", "Update", "$session_name ran updates");

    flash_alert("Update successful");

    sleep(1);

    redirect();

}

if (isset($_GET['update_db'])) {

    validateCSRFToken($_GET['csrf_token'] ?? '');

    //validateAdminRole(); // Old function

    // Get the current version
    require_once ('../includes/database_version.php');

    // Perform upgrades, if required
    require_once ('database_updates.php');

    logAction("Database", "Update", "$session_name updated the database structure");

    flash_alert("Database structure update successful");

    sleep(1);

    redirect();

}
