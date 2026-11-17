<?php

/*
 * RivetIT - GET/POST request handler for categories ('category')
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'category_model.php';

    // Edit access to the module that owns this category type (roles audit P1e - this used to need no
    // permission at all). Unknown types are administrator-only (IT-INJ-11).
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
    $category_module = $category_type_modules[$_POST['type'] ?? ''] ?? null;
    if ($category_module === null) {
        // Fail closed: a type this map does not know has no owning module to check, so a Tickets-only role must not
        // be able to create e.g. a finance/sales category by naming a type. Administrators may still create any.
        if (empty($session_is_admin)) {
            flash_alert("You cannot create a category of that type", 'error');
            redirect();
        }
    } else {
        enforceUserPermission($category_module, 2);
    }

    mysqli_query($mysqli,"INSERT INTO categories SET category_name = '$name', category_type = '$type', category_color = '$color'");

    $category_id = mysqli_insert_id($mysqli);

    logAction("Category", "Create", "$session_name created category $type $name", 0, $category_id);

    flash_alert("Category $type <strong>$name</strong> created");

    redirect();

}
