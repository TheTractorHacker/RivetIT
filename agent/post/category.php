<?php

/*
 * ITFlow - GET/POST request handler for categories ('category')
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'category_model.php';

    // Edit access to the module that owns this category type (roles audit P1e - this used to need no
    // permission at all). Unknown types need Tickets/assets/docs edit access.
    $category_type_modules = [
        'Ticket'            => 'module_support',
        'asset_status'      => 'module_support',
        'network_interface' => 'module_support',
        'rack_type'         => 'module_support',
        'software_type'     => 'module_support',
        'contact_note_type' => 'module_client',
        'Referral'          => 'module_client',
        'Income'            => 'module_sales',
        'Expense'           => 'module_financial',
        'Payment Method'    => 'module_financial',
    ];
    enforceUserPermission($category_type_modules[$_POST['type'] ?? ''] ?? 'module_support', 2);

    mysqli_query($mysqli,"INSERT INTO categories SET category_name = '$name', category_type = '$type', category_color = '$color'");

    $category_id = mysqli_insert_id($mysqli);

    logAction("Category", "Create", "$session_name created category $type $name", 0, $category_id);

    flash_alert("Category $type <strong>$name</strong> created");

    redirect();

}
