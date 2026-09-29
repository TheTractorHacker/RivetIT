<?php

// Check if the app is set up
if (file_exists("config.php")) {
    require_once "config.php";

    // Check if setup is enabled (not completed)
    if (!isset($config_enable_setup) || $config_enable_setup == 1) {
        header("Location: /setup");
        exit();
    }

    // Start the session
    require_once "functions.php";
    require_once "includes/session_init.php";

    // If user is an agent
    // (a department login also carries 'logged' so it can open its LMS role's agent pages; it still lands in the portal)
    if (isset($_SESSION['logged']) && intval($_SESSION['user_type'] ?? 1) !== 2) {
        require_once "includes/load_global_settings.php";
        // Roles audit P0: a module-only login goes to its own module; everyone else to the start page.
        $limited_home = itflow_limited_home_url_for_user(intval($_SESSION['user_id'] ?? 0));
        header("Location: " . ($limited_home ?? "/agent/$config_start_page"));
        exit();

    // If user is a client
    } elseif (isset($_SESSION['client_logged_in'])) {
        header("Location: /client/");
        exit();

    // Not logged in
    } else {
        header("Location: /login.php");
        exit();
    }

} else {
    // If config.php doesn't exist, redirect to setup
    header("Location: /setup");
    exit();
}
