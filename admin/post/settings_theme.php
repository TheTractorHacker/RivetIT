<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_theme_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $theme = preg_replace("/[^0-9a-zA-Z-]/", "", sanitizeInput($_POST['edit_theme_settings']));

    mysqli_query($mysqli,"UPDATE settings SET config_theme = '$theme' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited theme settings to $theme");

    flash_alert("Changed theme to <strong>$theme</strong>");

    redirect();

}

if (isset($_POST['edit_favicon_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    // A file must be attached and be an .ico (the page used to say "Favicon Updated" for an empty submit or a wrong file type)
    $fav_ok = false;
    if (!empty($_FILES['file']['tmp_name']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $new_file_name = checkFileUpload($_FILES['file'], array('ico'));
        if ($new_file_name === "File size exceeds the limit.") {
            flash_alert("That file is too large.", 'error');
            redirect();
        }
        if ($new_file_name) {
            $file_tmp_path = $_FILES['file']['tmp_name'];

            // Delete old file
            if(file_exists("../uploads/favicon.ico")) {
                unlink("../uploads/favicon.ico");
            }

            // directory in which the uploaded file will be moved
            $upload_file_dir = "../uploads/";
            //Force File Name
            $new_file_name = "favicon.ico";
            $dest_path = $upload_file_dir . $new_file_name;

            move_uploaded_file($file_tmp_path, $dest_path);
            $fav_ok = true;
        }
    }

    if (!$fav_ok) {
        flash_alert("Choose an .ico file to upload. Nothing was changed.", 'error');
        redirect();
    }

    logAction("Settings", "Edit", "$session_name changed the favicon");

    flash_alert("Favicon Updated");

    redirect();

}

if (isset($_GET['reset_favicon'])) {

    validateCSRFToken($_GET['csrf_token']);

    if (file_exists("../uploads/favicon.ico")) {
        unlink("../uploads/favicon.ico");
    }

    logAction("Settings", "Edit", "$session_name reset Favicon");

    flash_alert("Favicon reset", 'error');

    redirect();

}
