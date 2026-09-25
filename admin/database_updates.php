<?php
/*
 * ITFlow
 * This file defines the SQL queries required to update the database to the "latest" database version
 * It is used in conjunction with database_version.php
 */

// Check if our database versions are defined
// If undefined, the file is probably being accessed directly rather than called via post.php?update_db
if (!defined("LATEST_DATABASE_VERSION") || !defined("CURRENT_DATABASE_VERSION") || !isset($mysqli)) {
    echo "Cannot access this file directly.";
    exit();
}

// Check if we need an update
if (version_compare(LATEST_DATABASE_VERSION, CURRENT_DATABASE_VERSION, '>')) {

    // We need updates!

    if (CURRENT_DATABASE_VERSION == '0.2.0') {
        //Insert queries here required to update to DB version 0.2.1

        mysqli_query($mysqli, "ALTER TABLE `vendors`
        ADD `vendor_hours` VARCHAR(200) NULL DEFAULT NULL AFTER `vendor_website`,
        ADD `vendor_sla` VARCHAR(200) NULL DEFAULT NULL AFTER `vendor_hours`,
        ADD `vendor_code` VARCHAR(200) NULL DEFAULT NULL AFTER `vendor_sla`,
        ADD `vendor_template_id` INT(11) DEFAULT 0 AFTER `vendor_archived_at`
        ");

        mysqli_query($mysqli, "ALTER TABLE `vendors`
        DROP `vendor_country`,
        DROP `vendor_address`,
        DROP `vendor_city`,
        DROP `vendor_state`,
        DROP `vendor_zip`,
        DROP `vendor_global`
        ");

        //Create New Vendor Templates Table
        mysqli_query($mysqli, "CREATE TABLE `vendor_templates` (`vendor_template_id` int(11) AUTO_INCREMENT PRIMARY KEY,
        `vendor_template_name` varchar(200) NOT NULL,
        `vendor_template_description` varchar(200) NULL DEFAULT NULL,
        `vendor_template_phone` varchar(200) NULL DEFAULT NULL,
        `vendor_template_email` varchar(200) NULL DEFAULT NULL,
        `vendor_template_website` varchar(200) NULL DEFAULT NULL,
        `vendor_template_hours` varchar(200) NULL DEFAULT NULL,
        `vendor_template_created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `vendor_template_updated_at` datetime NULL ON UPDATE CURRENT_TIMESTAMP,
        `vendor_template_archived_at` datetime NULL DEFAULT NULL,
        `company_id` int(11) NOT NULL
        )");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.1') {
        // Insert queries here required to update to DB version 0.2.2
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_email_parse` INT(1) NOT NULL DEFAULT '0' AFTER `config_ticket_from_email`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_imap_host` VARCHAR(200) NULL DEFAULT NULL AFTER `config_mail_from_name`, ADD `config_imap_port` INT(5) NULL DEFAULT NULL AFTER `config_imap_host`, ADD `config_imap_encryption` VARCHAR(200) NULL DEFAULT NULL AFTER `config_imap_port`;");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.2') {
        // Insert queries here required to update to DB version 0.2.3

        // Add contact_important field to those who don't have it (installed before March 2022)
        try {
            mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_important` tinyint(1) NOT NULL DEFAULT 0 AFTER contact_password_reset_token;");
        } catch (Exception $e) {
            // Field already exists - that's fine
        }

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.3') {
        //Create New interfaces Table
        mysqli_query($mysqli, "CREATE TABLE `interfaces` (`interface_id` int(11) AUTO_INCREMENT PRIMARY KEY,
        `interface_number` int(11) NULL DEFAULT NULL,
        `interface_description` varchar(200) NULL DEFAULT NULL,
        `interface_connected_asset` varchar(200) NULL DEFAULT NULL,
        `interface_ip` varchar(200) NULL DEFAULT NULL,
        `interface_created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        `interface_updated_at` datetime NULL ON UPDATE CURRENT_TIMESTAMP,
        `interface_archived_at` datetime NULL DEFAULT NULL,
        `interface_connected_asset_id` int(11) NOT NULL DEFAULT 0,
        `interface_network_id` int(11) NOT NULL DEFAULT 0,
        `interface_asset_id` int(11) NOT NULL,
        `company_id` int(11) NOT NULL
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.4'");

    }

    if (CURRENT_DATABASE_VERSION == '0.2.4') {
        mysqli_query($mysqli, "CREATE TABLE `contact_assets` (`contact_id` int(11) NOT NULL,`asset_id` int(11) NOT NULL, PRIMARY KEY (`contact_id`,`asset_id`))");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.5') {
        mysqli_query($mysqli, "ALTER TABLE `users` ADD `user_status` TINYINT(1) DEFAULT 1 AFTER `user_password`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.6') {
        // Insert queries here required to update to DB version 0.2.7
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_token_expire` DATETIME NULL DEFAULT NULL AFTER `contact_password_reset_token`");

        // Update config.php var with new version var for use with docker
        file_put_contents("config.php", "\$repo_branch = 'master';" . PHP_EOL, FILE_APPEND);


        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.7') {

        mysqli_query($mysqli, "ALTER TABLE `vendors` ADD `vendor_template` TINYINT(1) DEFAULT 0 AFTER `vendor_notes`");
        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_template` TINYINT(1) DEFAULT 0 AFTER `software_notes`");
        mysqli_query($mysqli, "ALTER TABLE `vendors` DROP `vendor_template_id`");
        mysqli_query($mysqli, "DROP TABLE vendor_templates");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.8') {

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_theme` VARCHAR(200) DEFAULT 'blue' AFTER `config_module_enable_ticketing`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.2.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.2.9') {

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_client_general_notifications` INT(1) NOT NULL DEFAULT '1' AFTER `config_ticket_email_parse`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.0'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.0') {
        mysqli_query($mysqli, "ALTER TABLE `notifications` ADD `notification_user_id` TINYINT(1) DEFAULT 0 AFTER `notification_client_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.1') {

        // Assets

        mysqli_query($mysqli, "UPDATE `assets` SET `asset_login_id` = 0 WHERE `asset_login_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `assets` CHANGE `asset_login_id` `asset_login_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `assets` SET `asset_vendor_id` = 0 WHERE `asset_vendor_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `assets` CHANGE `asset_vendor_id` `asset_vendor_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `assets` SET `asset_location_id` = 0 WHERE `asset_location_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `assets` CHANGE `asset_location_id` `asset_location_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `assets` SET `asset_network_id` = 0 WHERE `asset_network_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `assets` CHANGE `asset_network_id` `asset_network_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `assets` SET `asset_client_id` = 0 WHERE `asset_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `assets` CHANGE `asset_client_id` `asset_client_id` INT(11) NOT NULL DEFAULT 0");

        // Certificates

        mysqli_query($mysqli, "UPDATE `certificates` SET `certificate_domain_id` = 0 WHERE `certificate_domain_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `certificates` CHANGE `certificate_domain_id` `certificate_domain_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `certificates` CHANGE `certificate_client_id` `certificate_client_id` INT(11) NOT NULL DEFAULT 0");

        // Clients

        mysqli_query($mysqli, "UPDATE `clients` SET `primary_location` = 0 WHERE `primary_location` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `clients` CHANGE `primary_location` `primary_location` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `clients` SET `primary_contact` = 0 WHERE `primary_contact` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `clients` CHANGE `primary_contact` `primary_contact` INT(11) NOT NULL DEFAULT 0");

        // Contacts

        mysqli_query($mysqli, "UPDATE `contacts` SET `contact_location_id` = 0 WHERE `contact_location_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `contacts` CHANGE `contact_location_id` `contact_location_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `contacts` CHANGE `contact_client_id` `contact_client_id` INT(11) NOT NULL DEFAULT 0");

        // Documents

        mysqli_query($mysqli, "ALTER TABLE `documents` CHANGE `document_template` `document_template` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `documents` SET `document_folder_id` = 0 WHERE `document_folder_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `documents` CHANGE `document_folder_id` `document_folder_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `documents` CHANGE `document_client_id` `document_client_id` INT(11) NOT NULL DEFAULT 0");

        // Domains

        mysqli_query($mysqli, "UPDATE `domains` SET `domain_registrar` = 0 WHERE `domain_registrar` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `domains` CHANGE `domain_registrar` `domain_registrar` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `domains` SET `domain_webhost` = 0 WHERE `domain_webhost` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `domains` CHANGE `domain_webhost` `domain_webhost` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `domains` CHANGE `domain_client_id` `domain_client_id` INT(11) NOT NULL DEFAULT 0");

        // Events

        mysqli_query($mysqli, "UPDATE `events` SET `event_client_id` = 0 WHERE `event_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `events` CHANGE `event_client_id` `event_client_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `events` SET `event_location_id` = 0 WHERE `event_location_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `events` CHANGE `event_location_id` `event_location_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `events` CHANGE `event_calendar_id` `event_calendar_id` INT(11) NOT NULL DEFAULT 0");

        // Expenses

        mysqli_query($mysqli, "UPDATE `expenses` SET `expense_vendor_id` = 0 WHERE `expense_vendor_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `expenses` CHANGE `expense_vendor_id` `expense_vendor_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `expenses` SET `expense_client_id` = 0 WHERE `expense_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `expenses` CHANGE `expense_client_id` `expense_client_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `expenses` SET `expense_category_id` = 0 WHERE `expense_category_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `expenses` CHANGE `expense_category_id` `expense_category_id` INT(11) NOT NULL DEFAULT 0");

        // Files

        mysqli_query($mysqli, "ALTER TABLE `files` CHANGE `file_client_id` `file_client_id` INT(11) NOT NULL DEFAULT 0");

        // Folders

        mysqli_query($mysqli, "UPDATE `folders` SET `parent_folder` = 0 WHERE `parent_folder` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `folders` CHANGE `parent_folder` `parent_folder` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `folders` CHANGE `folder_client_id` `folder_client_id` INT(11) NOT NULL DEFAULT 0");

        // History

        mysqli_query($mysqli, "UPDATE `history` SET `history_invoice_id` = 0 WHERE `history_invoice_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `history` CHANGE `history_invoice_id` `history_invoice_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `history` SET `history_recurring_id` = 0 WHERE `history_recurring_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `history` CHANGE `history_recurring_id` `history_recurring_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `history` SET `history_quote_id` = 0 WHERE `history_quote_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `history` CHANGE `history_quote_id` `history_quote_id` INT(11) NOT NULL DEFAULT 0");

        // Invoices

        mysqli_query($mysqli, "UPDATE `invoices` SET `invoice_amount` = 0.00 WHERE `invoice_amount` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `invoices` CHANGE `invoice_amount` `invoice_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        // Invoice Items

        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_quantity` `item_quantity` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_price` `item_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_subtotal` `item_subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        mysqli_query($mysqli, "UPDATE `invoice_items` SET `item_tax` = 0.00 WHERE `item_tax` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_tax` `item_tax` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_total` `item_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        mysqli_query($mysqli, "UPDATE `invoice_items` SET `item_tax_id` = 0 WHERE `item_tax_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_tax_id` `item_tax_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `invoice_items` SET `item_quote_id` = 0 WHERE `item_quote_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_quote_id` `item_quote_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `invoice_items` SET `item_recurring_id` = 0 WHERE `item_recurring_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_recurring_id` `item_recurring_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `invoice_items` SET `item_invoice_id` = 0 WHERE `item_invoice_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` CHANGE `item_invoice_id` `item_invoice_id` INT(11) NOT NULL DEFAULT 0");

        // Locations

        mysqli_query($mysqli, "UPDATE `locations` SET `location_contact_id` = 0 WHERE `location_contact_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `locations` CHANGE `location_contact_id` `location_contact_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `locations` SET `location_client_id` = 0 WHERE `location_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `locations` CHANGE `location_client_id` `location_client_id` INT(11) NOT NULL DEFAULT 0");

        // Logins

        mysqli_query($mysqli, "UPDATE `logins` SET `login_vendor_id` = 0 WHERE `login_vendor_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `logins` CHANGE `login_vendor_id` `login_vendor_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `logins` SET `login_asset_id` = 0 WHERE `login_asset_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `logins` CHANGE `login_asset_id` `login_asset_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `logins` SET `login_software_id` = 0 WHERE `login_software_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `logins` CHANGE `login_software_id` `login_software_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `logins` SET `login_client_id` = 0 WHERE `login_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `logins` CHANGE `login_client_id` `login_client_id` INT(11) NOT NULL DEFAULT 0");

        // Logs

        mysqli_query($mysqli, "UPDATE `logs` SET `log_client_id` = 0 WHERE `log_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `logs` CHANGE `log_client_id` `log_client_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `logs` DROP `log_invoice_id`");
        mysqli_query($mysqli, "ALTER TABLE `logs` DROP `log_quote_id`");
        mysqli_query($mysqli, "ALTER TABLE `logs` DROP `log_recurring_id`");
        mysqli_query($mysqli, "ALTER TABLE `logs` DROP `log_entity_id`");

        mysqli_query($mysqli, "UPDATE `logs` SET `log_user_id` = 0 WHERE `log_user_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `logs` CHANGE `log_user_id` `log_user_id` INT(11) NOT NULL DEFAULT 0");

        // Networks

        mysqli_query($mysqli, "UPDATE `networks` SET `network_location_id` = 0 WHERE `network_location_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `networks` CHANGE `network_location_id` `network_location_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `networks` CHANGE `network_client_id` `network_client_id` INT(11) NOT NULL DEFAULT 0");

        // Notifications

        mysqli_query($mysqli, "UPDATE `notifications` SET `notification_client_id` = 0 WHERE `notification_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `notifications` CHANGE `notification_client_id` `notification_client_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `notifications` CHANGE `notification_user_id` `notification_user_id` INT(11) NOT NULL DEFAULT 0");

        // Payments

        mysqli_query($mysqli, "UPDATE `payments` SET `payment_invoice_id` = 0 WHERE `payment_invoice_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `payments` CHANGE `payment_invoice_id` `payment_invoice_id` INT(11) NOT NULL DEFAULT 0");

        // Products

        mysqli_query($mysqli, "UPDATE `products` SET `product_tax_id` = 0 WHERE `product_tax_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `products` CHANGE `product_tax_id` `product_tax_id` INT(11) NOT NULL DEFAULT 0");

        // Quotes

        mysqli_query($mysqli, "UPDATE `quotes` SET `quote_amount` = 0.00 WHERE `quote_amount` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `quotes` CHANGE `quote_amount` `quote_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        // Recurring

        mysqli_query($mysqli, "UPDATE `recurring` SET `recurring_amount` = 0.00 WHERE `recurring_amount` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `recurring` CHANGE `recurring_amount` `recurring_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        // Revenues

        mysqli_query($mysqli, "UPDATE `revenues` SET `revenue_amount` = 0.00 WHERE `revenue_amount` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `revenues` CHANGE `revenue_amount` `revenue_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00");

        mysqli_query($mysqli, "UPDATE `revenues` SET `revenue_category_id` = 0 WHERE `revenue_category_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `revenues` CHANGE `revenue_category_id` `revenue_category_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `revenues` SET `revenue_client_id` = 0 WHERE `revenue_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `revenues` CHANGE `revenue_client_id` `revenue_client_id` INT(11) NOT NULL DEFAULT 0");

        // Scheduled Tickets

        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` CHANGE `scheduled_ticket_created_by` `scheduled_ticket_created_by` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `scheduled_tickets` SET `scheduled_ticket_client_id` = 0 WHERE `scheduled_ticket_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` CHANGE `scheduled_ticket_client_id` `scheduled_ticket_client_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `scheduled_tickets` SET `scheduled_ticket_contact_id` = 0 WHERE `scheduled_ticket_contact_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` CHANGE `scheduled_ticket_contact_id` `scheduled_ticket_contact_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `scheduled_tickets` SET `scheduled_ticket_asset_id` = 0 WHERE `scheduled_ticket_asset_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` CHANGE `scheduled_ticket_asset_id` `scheduled_ticket_asset_id` INT(11) NOT NULL DEFAULT 0");

        // Settings

        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_ticket_email_parse` `config_ticket_email_parse` TINYINT(1) NOT NULL DEFAULT 0");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_ticket_client_general_notifications` `config_ticket_client_general_notifications` TINYINT(1) NOT NULL DEFAULT 1");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_enable_cron` `config_enable_cron` TINYINT(1) NOT NULL DEFAULT 0");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_recurring_auto_send_invoice` `config_recurring_auto_send_invoice` TINYINT(1) NOT NULL DEFAULT 1");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_enable_alert_domain_expire` = 1 WHERE `config_enable_alert_domain_expire` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_enable_alert_domain_expire` `config_enable_alert_domain_expire` TINYINT(1) NOT NULL DEFAULT 1");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_send_invoice_reminders` = 1 WHERE `config_send_invoice_reminders` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_send_invoice_reminders` `config_send_invoice_reminders` TINYINT(1) NOT NULL DEFAULT 1");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_stripe_enable` = 0 WHERE `config_stripe_enable` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_stripe_enable` `config_stripe_enable` TINYINT(1) NOT NULL DEFAULT 0");

        // Software

        mysqli_query($mysqli, "UPDATE `software` SET `software_template` = 0 WHERE `software_template` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `software` CHANGE `software_template` `software_template` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `software` SET `software_login_id` = 0 WHERE `software_login_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `software` CHANGE `software_login_id` `software_login_id` INT(11) NOT NULL DEFAULT 0");

        // Tags

        mysqli_query($mysqli, "ALTER TABLE `tags` ADD `tag_archived_at` DATETIME NULL DEFAULT NULL AFTER `tag_updated_at`");

        // Tickets

        mysqli_query($mysqli, "UPDATE `tickets` SET `ticket_closed_by` = 0 WHERE `ticket_closed_by` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `tickets` CHANGE `ticket_closed_by` `ticket_closed_by` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `tickets` SET `ticket_vendor_id` = 0 WHERE `ticket_vendor_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `tickets` CHANGE `ticket_vendor_id` `ticket_vendor_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `tickets` SET `ticket_client_id` = 0 WHERE `ticket_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `tickets` CHANGE `ticket_client_id` `ticket_client_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `tickets` SET `ticket_contact_id` = 0 WHERE `ticket_contact_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `tickets` CHANGE `ticket_contact_id` `ticket_contact_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `tickets` SET `ticket_location_id` = 0 WHERE `ticket_location_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `tickets` CHANGE `ticket_location_id` `ticket_location_id` INT(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `tickets` SET `ticket_asset_id` = 0 WHERE `ticket_asset_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `tickets` CHANGE `ticket_asset_id` `ticket_asset_id` INT(11) NOT NULL DEFAULT 0");

        //Trips

        mysqli_query($mysqli, "UPDATE `trips` SET `trip_client_id` = 0 WHERE `trip_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `trips` CHANGE `trip_client_id` `trip_client_id` INT(11) NOT NULL DEFAULT 0");

        // Users

        mysqli_query($mysqli, "ALTER TABLE `users` CHANGE `user_status` `user_status` TINYINT(1) NOT NULL DEFAULT 1");

        // Vendors

        mysqli_query($mysqli, "ALTER TABLE `vendors` CHANGE `vendor_template` `vendor_template` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `vendors` SET `vendor_client_id` = 0 WHERE `vendor_client_id` IS NULL");
        mysqli_query($mysqli, "ALTER TABLE `vendors` CHANGE `vendor_client_id` `vendor_client_id` INT(11) NOT NULL DEFAULT 0");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.2') {
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_billing` TINYINT(1) DEFAULT 0 AFTER `contact_important`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_technical` TINYINT(1) DEFAULT 0 AFTER `contact_billing`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.3') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_telemetry` TINYINT(1) DEFAULT 0 AFTER `config_theme`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.4'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.4') {
        // Insert queries here required to update to DB version 0.3.5

        //Get & upgrade user login encryption
        $sql_logins = mysqli_query($mysqli, "SELECT login_id, login_username FROM logins WHERE login_username IS NOT NULL");
        foreach ($sql_logins as $row) {
            $login_id = $row['login_id'];
            $login_username = $row['login_username'];
            $login_encrypted_username = encryptLoginEntry($row['login_username']);
            mysqli_query($mysqli, "UPDATE logins SET login_username = '$login_encrypted_username' WHERE login_id = '$login_id'");
        }

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.5') {
        $installation_id = randomString(32);

        // Update config.php var with new version var for use with docker
        file_put_contents("config.php", "\n\$installation_id = '$installation_id';" . PHP_EOL, FILE_APPEND);


        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.6') {
        // Insert queries here required to update to DB version 0.3.7
        mysqli_query($mysqli, "ALTER TABLE `shared_items` ADD `item_encrypted_username` VARCHAR(255) NULL DEFAULT NULL AFTER `item_related_id`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.7') {

        mysqli_query($mysqli, "ALTER TABLE `logins` ADD `login_important` TINYINT(1) NOT NULL DEFAULT 0 AFTER `login_note`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.8') {
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_accessed_at` DATETIME NULL DEFAULT NULL AFTER `contact_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_accessed_at` DATETIME NULL DEFAULT NULL AFTER `location_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_accessed_at` DATETIME NULL DEFAULT NULL AFTER `asset_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_accessed_at` DATETIME NULL DEFAULT NULL AFTER `software_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `logins` ADD `login_accessed_at` DATETIME NULL DEFAULT NULL AFTER `login_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `networks` ADD `network_accessed_at` DATETIME NULL DEFAULT NULL AFTER `network_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `certificates` ADD `certificate_accessed_at` DATETIME NULL DEFAULT NULL AFTER `certificate_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `domains` ADD `domain_accessed_at` DATETIME NULL DEFAULT NULL AFTER `domain_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `services` ADD `service_accessed_at` DATETIME NULL DEFAULT NULL AFTER `service_updated_at`");
        mysqli_query($mysqli, "ALTER TABLE `vendors` ADD `vendor_accessed_at` DATETIME NULL DEFAULT NULL AFTER `vendor_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_accessed_at` DATETIME NULL DEFAULT NULL AFTER `file_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_accessed_at` DATETIME NULL DEFAULT NULL AFTER `document_archived_at`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.3.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.3.9') {

        mysqli_query($mysqli, "ALTER TABLE `vendors` ADD `vendor_template_id` INT(11) NOT NULL DEFAULT 0 AFTER `vendor_client_id`");
        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_template_id` INT(11) NOT NULL DEFAULT 0 AFTER `software_client_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.0'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.0') {
        mysqli_query($mysqli, "ALTER TABLE `logs` ADD `log_entity_id` INT NOT NULL DEFAULT '0' AFTER `log_user_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.1') {
        mysqli_query($mysqli, "ALTER TABLE settings ADD `config_stripe_account` TINYINT(1) NOT NULL DEFAULT '0' AFTER config_stripe_secret");
        //Insert queries here required to update to DB version 0.4.2

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.2') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_timezone` VARCHAR(200) NOT NULL DEFAULT 'America/New_York' AFTER `config_telemetry`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.3') {
        // Insert queries here required to update to DB version 0.4.4
        mysqli_query($mysqli, "ALTER TABLE `client_tags` CHANGE `client_id` `client_tags_client_id` INT NOT NULL");
        mysqli_query($mysqli, "ALTER TABLE `client_tags` CHANGE `tag_id` `client_tags_tag_id` INT NOT NULL");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.4'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.4') {
        // Insert queries here required to update to DB version 0.4.5
        mysqli_query($mysqli, "ALTER TABLE `client_tags` CHANGE `client_tags_client_id` `client_tag_client_id` INT NOT NULL");
        mysqli_query($mysqli, "ALTER TABLE `client_tags` CHANGE `client_tags_tag_id` `client_tag_tag_id` INT NOT NULL");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.5') {
        // Insert queries here required to update to DB version 0.4.6
        mysqli_query($mysqli, "ALTER TABLE `contacts` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `locations` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `software` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `logins` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `networks` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `certificates` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `domains` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `tickets` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `ticket_replies` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `services` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `vendors` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `calendars` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `events` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `files` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `documents` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `folders` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `invoices` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `recurring` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `quotes` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `history` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `payments` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `trips` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `clients` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `expenses` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `transfers` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `revenues` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `api_keys` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `taxes` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `categories` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `tags` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `accounts` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `interfaces` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `records` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `logs` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `notifications` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `products` DROP `company_id`");
        mysqli_query($mysqli, "ALTER TABLE `companies` DROP `company_archived_at`");
        mysqli_query($mysqli, "ALTER TABLE `user_settings` DROP `user_default_company`");
        mysqli_query($mysqli, "DROP TABLE `user_companies`");
        mysqli_query($mysqli, "DROP TABLE `user_keys`"); //Unused Table

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.6') {

        mysqli_query($mysqli, "ALTER TABLE `notifications` ADD `notification_entity_id` INT(11) DEFAULT 0 AFTER `notification_user_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.7') {

        mysqli_query($mysqli, "ALTER TABLE `clients` ADD `client_rate` DECIMAL(15,2) NULL DEFAULT NULL AFTER `client_referral`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.8') {
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_source` VARCHAR(255) NULL DEFAULT NULL AFTER `ticket_number`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.4.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.4.9') {
        // Insert queries here required to update to DB version 0.5.0
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD `client_tax_id_number` VARCHAR(255) NULL DEFAULT NULL AFTER `client_net_terms`");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.0'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.0') {
        // Insert queries here required to update to DB version 0.5.1
        mysqli_query($mysqli, "CREATE TABLE `ticket_attachments` (
		  `ticket_attachment_id` int(11) NOT NULL AUTO_INCREMENT,
		  `ticket_attachment_name` varchar(255) NOT NULL,
		  `ticket_attachment_reference_name` varchar(255) NOT NULL,
		  `ticket_attachment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
		  `ticket_attachment_ticket_id` int(11) NOT NULL,
		  `ticket_attachment_reply_id` int(11) DEFAULT NULL,
		  PRIMARY KEY (`ticket_attachment_id`)
		)");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.1') {
        //Insert queries here required to update to DB version 0.5.2
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_autoclose` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_ticket_client_general_notifications`");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_cron_key` VARCHAR(255) NULL DEFAULT NULL AFTER `config_enable_cron`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.2') {
        //Insert queries here required to update to DB version 0.5.3
        //Custom Fields and Values

        mysqli_query($mysqli, "CREATE TABLE `custom_fields` (
			`custom_field_id` int(11) NOT NULL AUTO_INCREMENT,
			`custom_field_table` varchar(255) NOT NULL,
			`custom_field_label` varchar(255) NOT NULL,
			`custom_field_type` varchar(255) NOT NULL DEFAULT 'text',
			`custom_field_location` int(11) NOT NULL DEFAULT 0,
			`custom_field_order` int(11) NOT NULL DEFAULT 999,
			PRIMARY KEY (`custom_field_id`)
		)");

        mysqli_query($mysqli, "CREATE TABLE `custom_values` (
			`custom_value_id` int(11) NOT NULL AUTO_INCREMENT,
			`custom_value_value` text NOT NULL,
			`custom_value_field` int(11) NOT NULL,
			PRIMARY KEY (`custom_value_id`)
		)");

        mysqli_query($mysqli, "CREATE TABLE `asset_custom` (
			`asset_custom_id` int(11) NOT NULL AUTO_INCREMENT,
			`asset_custom_field_value` int(11) NOT NULL,
			`asset_custom_field_id` int(11) NOT NULL,
			`asset_custom_asset_id` int(11) NOT NULL,
			PRIMARY KEY (`asset_custom_id`)
		)");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.3') {
        //Insert queries here required to update to DB version 0.5.4
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_autoclose_hours` INT(5) NOT NULL DEFAULT 72 AFTER `config_ticket_autoclose`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.4'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.4') {
        //Insert queries here required to update to DB version 0.5.5
        mysqli_query($mysqli, "CREATE TABLE `projects` (
			`project_id` int(11) NOT NULL AUTO_INCREMENT,
			`project_template` tinyint(1) NOT NULL DEFAULT 0,
			`project_name` varchar(255) NOT NULL,
			`project_description` text NULL DEFAULT NULL,
			`project_created_at` datetime NOT NULL DEFAULT current_timestamp(),
			`project_updated_at` datetime NULL DEFAULT NULL on update CURRENT_TIMESTAMP,
			`project_archived_at` datetime NULL DEFAULT NULL,
			`project_client_id` int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (`project_id`)
		)");

        mysqli_query($mysqli, "CREATE TABLE `tasks` (
			`task_id` int(11) NOT NULL AUTO_INCREMENT,
			`task_template` tinyint(1) NOT NULL DEFAULT 0,
			`task_name` varchar(255) NOT NULL,
			`task_description` text NULL DEFAULT NULL,
			`task_finish_date` date NULL DEFAULT NULL,
			`task_status` varchar(255) NULL DEFAULT NULL,
			`task_completed_at` datetime NULL DEFAULT NULL,
			`task_completed_by` int(11) NULL DEFAULT NULL,
			`task_created_at` datetime NOT NULL DEFAULT current_timestamp(),
			`task_updated_at` datetime NULL DEFAULT NULL on update CURRENT_TIMESTAMP,
			`task_ticket_id` int(11) NULL DEFAULT NULL,
			`task_project_id` int(11) NULL DEFAULT NULL,
			PRIMARY KEY (`task_id`)
		)");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.5') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_login_key_required` TINYINT(1) NOT NULL DEFAULT '0' AFTER `config_module_enable_accounting`, ADD `config_login_key_secret` VARCHAR(255) NULL DEFAULT NULL AFTER `config_login_key_required`; ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.6') {

        mysqli_query($mysqli, "CREATE TABLE `email_queue` (
			`email_id` int(11) NOT NULL AUTO_INCREMENT,
			`email_recipient` varchar(255) NOT NULL,
			`email_from` varchar(255) NOT NULL,
			`email_from_name` varchar(255) NOT NULL,
			`email_subject` varchar(255) NOT NULL,
			`email_content` longtext NOT NULL,
			`email_queued_at` datetime NOT NULL DEFAULT current_timestamp(),
			`email_sent_at` datetime NULL DEFAULT NULL,
			PRIMARY KEY (`email_id`)
		)");

        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_description` VARCHAR(255) NULL DEFAULT NULL AFTER `asset_name`");

        mysqli_query($mysqli, "ALTER TABLE `logins` ADD `login_description` VARCHAR(255) NULL DEFAULT NULL AFTER `login_name`");

        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_pin` VARCHAR(255) NULL DEFAULT NULL AFTER `contact_photo`");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_client_portal_enable` TINYINT(1) NOT NULL DEFAULT '1' AFTER `config_module_enable_accounting`");

        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_vendor_ticket_number` VARCHAR(255) NULL DEFAULT NULL AFTER `ticket_status`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.7') {
        mysqli_query($mysqli, "ALTER TABLE `email_queue` ADD `email_status` TINYINT(1) NOT NULL DEFAULT '0' AFTER `email_id`");
        mysqli_query($mysqli, "ALTER TABLE `email_queue` ADD `email_recipient_name` VARCHAR(255) NULL DEFAULT NULL AFTER `email_recipient`");
        mysqli_query($mysqli, "ALTER TABLE `email_queue` ADD `email_failed_at` DATETIME NULL DEFAULT NULL AFTER `email_queued_at`");
        mysqli_query($mysqli, "ALTER TABLE `email_queue` ADD `email_attempts` TINYINT(1) NOT NULL DEFAULT '0' AFTER `email_failed_at`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.8') {
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_primary` TINYINT(1) NOT NULL DEFAULT 0 AFTER `contact_token_expire`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_primary` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location_photo`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.5.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.5.9') {

        // Copy primary_location and primary_contact to their new vars in their own respecting tables
        $sql = mysqli_query($mysqli, "SELECT * FROM clients");
        while($row = mysqli_fetch_assoc($sql)) {
            $primary_contact = $row['primary_contact'];
            $primary_location = $row['primary_location'];

            if($primary_contact > 0){
                mysqli_query($mysqli, "UPDATE contacts SET contact_primary = 1, contact_important = 1 WHERE contact_id = $primary_contact");
            }
            if($primary_location > 0){
                mysqli_query($mysqli, "UPDATE locations SET location_primary = 1 WHERE location_id = $primary_location");
            }
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.0'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.0') {
        mysqli_query($mysqli, "ALTER TABLE `clients` DROP `primary_contact`");
        mysqli_query($mysqli, "ALTER TABLE `clients` DROP `primary_location`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.1') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN `config_imap_username` VARCHAR(200) NULL DEFAULT NULL AFTER `config_imap_encryption`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN `config_imap_password` VARCHAR(200) NULL DEFAULT NULL AFTER `config_imap_username`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.2') {
        //Insert queries here required to update to DB version 0.6.3

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_invoice_late_fee_enable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_invoice_from_email`");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_invoice_late_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `config_invoice_late_fee_enable`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.3') {
        mysqli_query($mysqli, "ALTER TABLE `quotes` ADD COLUMN `quote_expire` DATE NULL DEFAULT NULL AFTER `quote_date`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.4'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.4') {
        //Insert queries here required to update to DB version 0.6.5

        mysqli_query($mysqli, "CREATE TABLE `ticket_watchers` (
			`watcher_id` int(11) NOT NULL AUTO_INCREMENT,
			`watcher_name` varchar(255) NULL DEFAULT NULL,
			`watcher_email` varchar(255) NOT NULL,
			`watcher_created_at` datetime NOT NULL DEFAULT current_timestamp(),
			`watcher_ticket_id` int(11) NOT NULL,
			PRIMARY KEY (`watcher_id`)
		)");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.5') {
        //Insert queries here required to update to DB version 0.6.6
        mysqli_query($mysqli, "ALTER TABLE `ticket_watchers` DROP `watcher_created_at`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.6') {

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_start_page` VARCHAR(200) DEFAULT 'clients.php' AFTER `config_current_database_version`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.7') {

        mysqli_query($mysqli, "CREATE TABLE `recurring_expenses` (
			`recurring_expense_id` INT(11) NOT NULL AUTO_INCREMENT,
			`recurring_expense_frequency` TINYINT(1) NOT NULL,
			`recurring_expense_day` TINYINT DEFAULT NULL,
			`recurring_expense_month` TINYINT DEFAULT NULL,
			`recurring_expense_last_sent` DATE NULL DEFAULT NULL,
			`recurring_expense_next_date` DATE NOT NULL,
			`recurring_expense_status` TINYINT(1) NOT NULL DEFAULT 1,
			`recurring_expense_description` TEXT DEFAULT NULL,
			`recurring_expense_amount` DECIMAL(15,2) NOT NULL,
			`recurring_expense_payment_method` VARCHAR(200) DEFAULT NULL,
			`recurring_expense_payment_reference` VARCHAR(200) DEFAULT NULL,
			`recurring_expense_currency_code` VARCHAR(200) NOT NULL,
			`recurring_expense_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
			`recurring_expense_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			`recurring_expense_archived_at` DATETIME DEFAULT NULL,
			`recurring_expense_vendor_id` INT(11) NOT NULL,
			`recurring_expense_client_id` INT(11) NOT NULL DEFAULT 0,
			`recurring_expense_category_id` INT(11) NOT NULL,
			`recurring_expense_account_id` INT(11) NOT NULL,
			PRIMARY KEY (`recurring_expense_id`)
		)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.8') {
        //Insert queries here required to update to DB version 0.6.9
        mysqli_query($mysqli, "ALTER TABLE `recurring_expenses` CHANGE `recurring_expense_payment_reference` `recurring_expense_reference` VARCHAR(255) DEFAULT NULL");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.6.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.6.9') {

        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_records_per_page` INT(11) NOT NULL DEFAULT 10 AFTER `user_role`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.0'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.0') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_login_message` TEXT DEFAULT NULL AFTER `config_client_portal_enable`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.1') {
        mysqli_query($mysqli, "CREATE TABLE `budget` (
			`budget_id` INT(11) NOT NULL AUTO_INCREMENT,
			`budget_month` TINYINT NOT NULL,
			`budget_year` TINYINT NOT NULL,
			`budget_amount` DECIMAL(15,2) NOT NULL,
			`budget_description` VARCHAR(255) DEFAULT NULL,
			`budget_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
			`budget_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			`budget_category_id` INT(11) NOT NULL,
			PRIMARY KEY (`budget_id`)
		)");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.2') {
        mysqli_query($mysqli, "ALTER TABLE `budget` CHANGE `budget_year` `budget_year` INT NOT NULL");
        mysqli_query($mysqli, "ALTER TABLE `budget` CHANGE `budget_amount` `budget_amount` DECIMAL(15,2) DEFAULT 0.00");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.3') {
        //Insert queries here required to update to DB version 0.7.4
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_folder_id` INT(11) NOT NULL DEFAULT 0 AFTER `file_accessed_at`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.4'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.4') {
        //Insert queries here required to update to DB version 0.7.5
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_hash` VARCHAR(200) DEFAULT NULL AFTER `file_ext`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.5') {
        //Insert queries here required to update to DB version 0.7.6
        mysqli_query($mysqli, "ALTER TABLE `folders` ADD `folder_location` INT DEFAULT 0 AFTER `parent_folder`");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.6') {
        //Insert queries here required to update to DB version 0.7.7
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_new_ticket_notification_email` VARCHAR(200) DEFAULT NULL AFTER `config_ticket_autoclose_hours`");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.7') {
        //Insert queries here required to update to DB version 0.7.8
        mysqli_query($mysqli, "ALTER TABLE `notifications` ADD `notification_action` VARCHAR(250) DEFAULT NULL AFTER `notification`");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.8') {
        //Insert queries here required to update to DB version 0.7.9
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_force_mfa` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_role`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.7.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.7.9') {
        //Insert queries here required to update to DB version 0.8.0
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_uri` VARCHAR(250) DEFAULT NULL AFTER `asset_mac`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.0'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.0') {
        //Insert queries here required to update to DB version 0.8.1
        mysqli_query($mysqli, "ALTER TABLE `categories` ADD `category_icon` VARCHAR(200) DEFAULT NULL AFTER `category_color`");
        mysqli_query($mysqli, "ALTER TABLE `categories` ADD `category_parent` INT(11) DEFAULT 0 AFTER `category_icon`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.1') {
        //Insert queries here required to update to DB version 0.8.2
        mysqli_query($mysqli, "CREATE TABLE `document_files` (`document_id` int(11) NOT NULL,`file_id` int(11) NOT NULL, PRIMARY KEY (`document_id`,`file_id`))");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.2'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.2') {
        //Insert queries here required to update to DB version 0.8.3
        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_parent` INT(11) NOT NULL DEFAULT 0 AFTER `document_content_raw`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.3'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.3') {
        //Insert queries here required to update to DB version 0.8.4

        mysqli_query($mysqli, "UPDATE `documents` SET `document_parent` = `document_id`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.4'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.4') {
        //Insert queries here required to update to DB version 0.8.5
        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_description` TEXT DEFAULT NULL AFTER `document_name`");
        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_created_by` INT(11) NOT NULL DEFAULT 0 AFTER `document_folder_id`");
        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_updated_by` INT(11) NOT NULL DEFAULT 0 AFTER `document_created_by`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.5') {
        // Insert queries here required to update to DB version 0.8.6    (Adding login entry password change tracking)
        mysqli_query($mysqli, "ALTER TABLE `logins` ADD  `login_password_changed_at` datetime DEFAULT current_timestamp() AFTER `login_accessed_at`");

        // For the safest initial value, set login_password_changed_at to when the login entry was created (as there is no guarantee the password was changed just because the record was updated)
        $sql_logins = mysqli_query($mysqli, "SELECT login_id, login_created_at FROM logins WHERE login_password IS NOT NULL AND login_archived_at IS NULL");
        foreach ($sql_logins as $row) {
            $login_id = $row['login_id'];
            $login_password_changed_at = $row['login_created_at'];
            mysqli_query($mysqli, "UPDATE logins SET login_password_changed_at = '$login_password_changed_at' WHERE login_id = '$login_id'");
        }

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.6') {
        // Insert queries here required to update to DB version 0.8.7
        mysqli_query($mysqli, "ALTER TABLE `accounts` ADD `account_type` int(6) DEFAULT NULL AFTER `account_notes`");
        mysqli_query($mysqli, "CREATE TABLE `account_types` (`account_type_id` int(11) NOT NULL AUTO_INCREMENT,`account_type_name` varchar(255) NOT NULL,`account_type_description` text DEFAULT NULL,`account_type_created_at` datetime NOT NULL DEFAULT current_timestamp(),`account_type_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),`account_type_archived_at` datetime DEFAULT NULL,PRIMARY KEY (`account_type_id`))");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.8.7') {
        //Create Main Account Types
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Asset', account_type_id= '10', account_type_description = 'Assets are economic resources which are expected to benefit the business in the future.'");
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Liability', account_type_id= '20', account_type_description = 'Liabilities are obligations of the business entity. They are usually classified as current liabilities (due within one year or less) and long-term liabilities (due after one year).'");
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Equity', account_type_id= '30', account_type_description = 'Equity represents the owners stake in the business after liabilities have been deducted.'");
        //Create Secondary Account Types
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Current Asset', account_type_id= '11', account_type_description = 'Current assets are expected to be consumed within one year or less.'");
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Fixed Asset', account_type_id= '12', account_type_description = 'Fixed assets are expected to benefit the business for more than one year.'");
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Other Asset', account_type_id= '19', account_type_description = 'Other assets are assets that do not fit into any of the other asset categories.'");

        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Current Liability', account_type_id= '21', account_type_description = 'Current liabilities are expected to be paid within one year or less.'");
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Long Term Liability', account_type_id= '22', account_type_description = 'Long term liabilities are expected to be paid after one year.'");
        mysqli_query($mysqli,"INSERT INTO account_types SET account_type_name = 'Other Liability', account_type_id= '29', account_type_description = 'Other liabilities are liabilities that do not fit into any of the other liability categories.'");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.8'");
    }


    if (CURRENT_DATABASE_VERSION == '0.8.8') {
        // Insert queries here required to update to DB version 0.8.9
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` ADD `item_order` INT(11) NOT NULL DEFAULT 0 AFTER `item_total`");
        // Update existing invoices so that item_order is set to item_id
        $sql_invoices = mysqli_query($mysqli, "SELECT invoice_id FROM invoices WHERE invoice_id IS NOT NULL");
        foreach ($sql_invoices as $row) {
            $invoice_id = $row['invoice_id'];
            $sql_invoice_items = mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = '$invoice_id' ORDER BY item_id ASC");
            $item_order = 1;
            foreach ($sql_invoice_items as $row) {
                $item_id = $row['item_id'];
                mysqli_query($mysqli, "UPDATE invoice_items SET item_order = '$item_order' WHERE item_id = '$item_id'");
                $item_order++;
                //Log changes made to invoice
                mysqli_query($mysqli,"INSERT INTO logs SET log_type = 'Invoice', log_action = 'Modify', log_description = 'Updated item_order to item_id: $item_order'");

            }
        }

        //
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.8.9'");
    }


    if (CURRENT_DATABASE_VERSION == '0.8.9') {
        // Insert queries here required to update to DB version 0.9.0
        // Update existing quotes and recurrings so that item_order is set to item_id
        $sql_quotes = mysqli_query($mysqli, "SELECT quote_id FROM quotes WHERE quote_id IS NOT NULL");
        $sql_recurrings = mysqli_query($mysqli, "SELECT recurring_id FROM recurring WHERE recurring_id IS NOT NULL");

        foreach ($sql_quotes as $row) {
            $quote_id = $row['quote_id'];
            $sql_quote_items = mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_quote_id = '$quote_id' ORDER BY item_id ASC");
            $item_order = 1;
            foreach ($sql_quote_items as $row) {
                $item_id = $row['item_id'];
                mysqli_query($mysqli, "UPDATE invoice_items SET item_order = '$item_order' WHERE item_id = '$item_id'");
                $item_order++;
                //Log changes made to quote
                mysqli_query($mysqli,"INSERT INTO logs SET log_type = 'Quote', log_action = 'Modify', log_description = 'Updated item_order to item_id: $item_order'");
            }
        }

        foreach ($sql_recurrings as $row) {
            $recurring_id = $row['recurring_id'];
            $sql_recurring_items = mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_recurring_id = '$recurring_id' ORDER BY item_id ASC");
            $item_order = 1;
            foreach ($sql_recurring_items as $row) {
                $item_id = $row['item_id'];
                mysqli_query($mysqli, "UPDATE invoice_items SET item_order = '$item_order' WHERE item_id = '$item_id'");
                $item_order++;
                //Log changes made to recurring
                mysqli_query($mysqli,"INSERT INTO logs SET log_type = 'Recurring', log_action = 'Modify', log_description = 'Updated item_order to item_id: $item_order'");
            }
        }


        //
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.0'");
    }


    if (CURRENT_DATABASE_VERSION == '0.9.0') {
        //add leads column to clients table
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD `client_lead` TINYINT(1) NOT NULL DEFAULT 0 AFTER `client_id`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.1'");
    }

    if (CURRENT_DATABASE_VERSION == '0.9.1') {
        // Insert queries here required to update to DB version 0.9.2
        mysqli_query($mysqli, "ALTER TABLE `invoices` ADD `invoice_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `invoice_due`");
        mysqli_query($mysqli, "ALTER TABLE `recurring` ADD `recurring_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `recurring_status`");
        mysqli_query($mysqli, "ALTER TABLE `quotes` ADD `quote_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `quote_status`");

        // Then update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.2'");

    }

    if (CURRENT_DATABASE_VERSION == '0.9.2') {
        mysqli_query($mysqli, "ALTER TABLE `account_types` ADD `account_type_parent` INT(11) NOT NULL DEFAULT 1 AFTER `account_type_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.3'");

    }

    if (CURRENT_DATABASE_VERSION == '0.9.3') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_default_hourly_rate` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `config_default_net_terms`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.4'");

    }

    if (CURRENT_DATABASE_VERSION == '0.9.4') {
        // Insert queries here required to update to DB version 0.9.5
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_stripe_client_pays_fees` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_stripe_account`");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.5'");
    }

    if (CURRENT_DATABASE_VERSION == '0.9.5') {
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_remember_me_token` VARCHAR(255) NULL DEFAULT NULL AFTER `user_role`");
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.6'");
    }

    if (CURRENT_DATABASE_VERSION == '0.9.6') {
        // Insert queries here required to update to DB version 0.9.7
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_invoice_id` INT(11) NOT NULL DEFAULT 0 AFTER `ticket_asset_id`");
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_billable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ticket_status`");
        //set all invoice id
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.7'");
    }

    if (CURRENT_DATABASE_VERSION == '0.9.7') {
        // Insert queries here required to update to DB version 0.9.8
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_dashboard_financial_enable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_config_records_per_page`");
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_dashboard_technical_enable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_config_dashboard_financial_enable`");
        //set all invoice id
        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.8'");
    }

    if (CURRENT_DATABASE_VERSION == '0.9.8') {
        //Insert queries here required to update to DB version 0.9.9
        mysqli_query($mysqli, "ALTER TABLE `domains` ADD `domain_notes` TEXT NULL DEFAULT NULL AFTER `domain_raw_whois`");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '0.9.9'");
    }

    if (CURRENT_DATABASE_VERSION == '0.9.9') {
        //Insert queries here required to update to DB version 1.0.0
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_destructive_deletes_enable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_timezone`");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.0') {
        //Insert queries here required to update to DB version 1.0.1
        mysqli_query($mysqli, "ALTER TABLE `assets` MODIFY `asset_uri` VARCHAR(500) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_uri_2` VARCHAR(500) DEFAULT NULL AFTER `asset_uri`");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.1') {
        //Insert queries here required to update to DB version 1.0.2
        mysqli_query($mysqli, "ALTER TABLE `logins` MODIFY `login_uri` VARCHAR(500) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `logins` ADD `login_uri_2` VARCHAR(500) DEFAULT NULL AFTER `login_uri`");
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_nat_ip` VARCHAR(200) DEFAULT NULL AFTER `asset_ip`");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.2'");
    }



    if (CURRENT_DATABASE_VERSION == '1.0.2') {
        //Insert queries here required to update to DB version 1.0.3
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_stripe_expense_vendor` INT(11) NOT NULL DEFAULT 0 AFTER `config_stripe_account`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_stripe_expense_category` INT(11) NOT NULL DEFAULT 0 AFTER `config_stripe_expense_vendor`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_stripe_percentage_fee` DECIMAL(4,4) NOT NULL DEFAULT 0.029 AFTER `config_stripe_expense_category`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_stripe_flat_fee` DECIMAL(15,2) NOT NULL DEFAULT 0.30 AFTER `config_stripe_percentage_fee`");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_stripe_account` `config_stripe_account` INT(11) NOT NULL DEFAULT 0");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.3') {
        //Insert queries here required to update to DB version 1.0.4
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ai_enable` TINYINT(1) DEFAULT 0 AFTER `config_stripe_percentage_fee`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ai_provider` VARCHAR(250) DEFAULT NULL AFTER `config_ai_enable`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ai_url` VARCHAR(250) DEFAULT NULL AFTER `config_ai_provider`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ai_api_key` VARCHAR(250) DEFAULT NULL AFTER `config_ai_url`");

        //Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.4'");
    }

    // Be sure to change database_version.php to reflect the version you are updating to here
    // Please add this same comment block to the bottom of this file, and update the version number.
    // Uncomment Below Lines, to add additional database updates
    //

    if (CURRENT_DATABASE_VERSION == '1.0.4') {
        //Insert queries here required to update to DB version 1.0.5
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_schedule` DATETIME DEFAULT NULL AFTER `ticket_billable`");
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_onsite` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ticket_schedule`");
        mysqli_query($mysqli, "ALTER TABLE `email_queue` ADD `email_cal_str` VARCHAR(1024) DEFAULT NULL AFTER `email_content`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.5') {
        //Insert queries here required to update to DB version 1.0.6
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ai_model` VARCHAR(250) DEFAULT NULL AFTER `config_ai_provider`");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.6') {
        // Insert queries here required to update to DB version 1.0.7
        mysqli_query($mysqli, "CREATE TABLE `remember_tokens` (`remember_token_id` int(11) NOT NULL AUTO_INCREMENT,`remember_token_token` varchar(255) NOT NULL,`remember_token_user_id` int(11) NOT NULL,`remember_token_created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`remember_token_id`))");

        // Then, update the database to the next sequential version
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.7') {
        mysqli_query($mysqli, "ALTER TABLE `user_settings` DROP `user_config_remember_me_token`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.8') {
        // Removed this as login_asset_id is present in the logins table and allow 1 asset to have many logins.
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_login_id`");
        // Dropped this unused Table as we don't need many to many relationship between assets and logins
        mysqli_query($mysqli, "DROP TABLE asset_logins");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.0.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.0.9') {
        mysqli_query($mysqli, "ALTER TABLE `transfers` ADD `transfer_method` VARCHAR(200) DEFAULT NULL AFTER `transfer_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.0') {
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_description` TEXT DEFAULT NULL AFTER `file_name`");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_important` TINYINT(1) NOT NULL DEFAULT '0' AFTER `file_hash`");

        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_important` TINYINT(1) NOT NULL DEFAULT '0' AFTER `document_content_raw`");

        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_important` TINYINT(1) NOT NULL DEFAULT '0' AFTER `asset_notes`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.1') {
        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` ADD `scheduled_ticket_assigned_to` INT(11) NOT NULL DEFAULT '0' AFTER `scheduled_ticket_created_by`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.2'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.2') {
        // Add DB support for multiple contacts under a vendor
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_vendor_id` INT(11) NOT NULL DEFAULT '0' AFTER `contact_location_id`");

        // Add DB Support to Associate files to an asset example pictures, config backups etc
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_asset_id` INT(11) NOT NULL DEFAULT '0' AFTER `file_folder_id`");

        // Add DB Support for missing Short Description fields
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_description` TEXT DEFAULT NULL AFTER `location_name`");
        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_description` TEXT DEFAULT NULL AFTER `software_name`");
        mysqli_query($mysqli, "ALTER TABLE `networks` ADD `network_description` TEXT DEFAULT NULL AFTER `network_name`");
        mysqli_query($mysqli, "ALTER TABLE `certificates` ADD `certificate_description` TEXT DEFAULT NULL AFTER `certificate_name`");
        mysqli_query($mysqli, "ALTER TABLE `domains` ADD `domain_description` TEXT DEFAULT NULL AFTER `domain_name`");

        // Add DB Support for Location for Events
        mysqli_query($mysqli, "ALTER TABLE `events` ADD `event_location` TEXT DEFAULT NULL AFTER `event_title`");

        // Add Event Attendees Table to allow multiple Attendees per event
        mysqli_query($mysqli, "CREATE TABLE `event_attendees` (
            `attendee_id` INT(11) NOT NULL AUTO_INCREMENT,
            `attendee_name` VARCHAR(200) DEFAULT NULL,
            `attendee_email` VARCHAR(200) DEFAULT NULL,
            `attendee_invitation_status` TINYINT(1) NOT NULL DEFAULT 0,
            `attendee_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `attendee_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `attendee_archived_at` DATETIME DEFAULT NULL,
            `attendee_contact_id` INT(11) NOT NULL DEFAULT 0,
            `attendee_event_id` INT(11) NOT NULL,
            PRIMARY KEY (`attendee_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.3') {
        mysqli_query($mysqli, "ALTER TABLE `networks` ADD `network_subnet` VARCHAR(200) DEFAULT NULL AFTER `network`");
        mysqli_query($mysqli, "ALTER TABLE `networks` ADD `network_primary_dns` VARCHAR(200) DEFAULT NULL AFTER `network_gateway`");
        mysqli_query($mysqli, "ALTER TABLE `networks` ADD `network_secondary_dns` VARCHAR(200) DEFAULT NULL AFTER `network_primary_dns`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.4') {

        // Add Project Templates
        mysqli_query($mysqli, "CREATE TABLE `project_templates` (
            `project_template_id` INT(11) NOT NULL AUTO_INCREMENT,
            `project_template_name` VARCHAR(200) NOT NULL,
            `project_template_description` TEXT DEFAULT NULL,
            `project_template_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `project_template_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `project_template_archived_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`project_template_id`)
        )");

        // Add Ticket Templates
        mysqli_query($mysqli, "CREATE TABLE `ticket_templates` (
            `ticket_template_id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_template_name` VARCHAR(200) NOT NULL,
            `ticket_template_description` TEXT DEFAULT NULL,
            `ticket_template_subject` VARCHAR(200) DEFAULT NULL,
            `ticket_template_details` LONGTEXT DEFAULT NULL,
            `ticket_template_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `ticket_template_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `ticket_template_archived_at` DATETIME DEFAULT NULL,
            `ticket_template_project_template_id` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`ticket_template_id`)
        )");

        // Add Task Templates
        mysqli_query($mysqli, "CREATE TABLE `task_templates` (
            `task_template_id` INT(11) NOT NULL AUTO_INCREMENT,
            `task_template_name` VARCHAR(200) NOT NULL,
            `task_template_description` TEXT DEFAULT NULL,
            `task_template_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `task_template_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `task_template_archived_at` DATETIME DEFAULT NULL,
            `task_template_ticket_template_id` INT(11) NOT NULL,
            PRIMARY KEY (`task_template_id`)
        )");

        mysqli_query($mysqli, "ALTER TABLE `projects` ADD `project_completed_at` DATETIME DEFAULT NULL AFTER `project_updated_at`");

        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_project_id` INT(11) NOT NULL DEFAULT 0 AFTER `ticket_invoice_id`");

        mysqli_query($mysqli, "ALTER TABLE `tasks` DROP `task_template`");
        mysqli_query($mysqli, "ALTER TABLE `tasks` DROP `task_finish_date`");
        mysqli_query($mysqli, "ALTER TABLE `tasks` DROP `task_project_id`");

        mysqli_query($mysqli, "ALTER TABLE `projects` DROP `project_template`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.5') {

        // Add new ticket_statuses table
        mysqli_query($mysqli,
            "CREATE TABLE `ticket_statuses` (
            `ticket_status_id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_status_name` VARCHAR(200) NOT NULL,
            `ticket_status_color` VARCHAR(200) NOT NULL,
            `ticket_status_active` TINYINT(1) NOT NULL DEFAULT '1',
            PRIMARY KEY (`ticket_status_id`)
        )");

        // Pre-seed default system/built-in ticket statuses
        mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'New', ticket_status_color = 'danger'"); // Default ID for new tickets is 1
        mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Open', ticket_status_color = 'primary'"); // 2
        mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'On Hold', ticket_status_color = 'success'"); // 3
        mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Auto Close', ticket_status_color = 'dark'"); // 4
        mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'Closed', ticket_status_color = 'dark'"); // 5

        // Update existing tickets to use new values
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 1 WHERE ticket_status = 'New'"); // New
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2 WHERE ticket_status = 'Open'"); // Open
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 3 WHERE ticket_status = 'On Hold'"); // On Hold
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 4 WHERE ticket_status = 'Auto Close'"); // Auto Close
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5 WHERE ticket_closed_at IS NOT NULL"); // Closed

        // Fix Bulk Ticket Closure not having a closed_at Time
        mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NOW(), ticket_status = 5 WHERE ticket_status = 'Closed' AND ticket_closed_at IS NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.6') {

        // Update existing tickets that did not use the defined statuses to Open
        //mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2 WHERE ticket_status NOT IN ('New', 'Open', 'On Hold', 'Auto Close') AND ticket_closed_at IS NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.7') {

        mysqli_query($mysqli, "ALTER TABLE `projects` ADD `project_due` DATE DEFAULT NULL AFTER `project_description`");
        mysqli_query($mysqli, "ALTER TABLE `tasks` ADD `task_order` INT(11) NOT NULL DEFAULT 0 AFTER `task_status`");
        mysqli_query($mysqli, "ALTER TABLE `task_templates` ADD `task_template_order` INT(11) NOT NULL DEFAULT 0 AFTER `task_template_description`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.8') {
        // Update Ticket Status color to use colors to allow more predefined colors
        mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_color = '#dc3545' WHERE ticket_status_id = 1"); // New
        mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_color = '#007bff' WHERE ticket_status_id = 2"); // Open
        mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_color = '#28a745' WHERE ticket_status_id = 3"); // On Hold
        mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_color = '#343a40' WHERE ticket_status_id = 4"); // Auto Close
        mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_color = '#343a40' WHERE ticket_status_id = 5"); // Closed

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.1.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.1.9') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_login_remember_me_expire` INT(11) NOT NULL DEFAULT 3 AFTER `config_login_key_secret`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.0') {
        mysqli_query($mysqli, "ALTER TABLE `ticket_templates` ADD `ticket_template_order` INT(11) NOT NULL DEFAULT 0 AFTER `ticket_template_details`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.1') {

        // Ticket Templates can have many project templates and Project Template can have have many ticket template, so instead create a many to many table relationship
        mysqli_query($mysqli, "ALTER TABLE `ticket_templates` DROP `ticket_template_order`");
        mysqli_query($mysqli, "ALTER TABLE `ticket_templates` DROP `ticket_template_project_template_id`");

        mysqli_query($mysqli,
            "CREATE TABLE `project_template_ticket_templates` (
            `ticket_template_id` INT(11) NOT NULL,
            `project_template_id` INT(11) NOT NULL,
            `ticket_template_order` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`ticket_template_id`,`project_template_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.2'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.2') {

        mysqli_query($mysqli, "ALTER TABLE `tasks` DROP `task_description`");
        mysqli_query($mysqli, "ALTER TABLE `task_templates` DROP `task_template_description`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.3') {

        mysqli_query($mysqli, "ALTER TABLE `projects` ADD `project_manager` INT(11) NOT NULL DEFAULT 0 AFTER `project_due`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.4') {

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_project_prefix` VARCHAR(200) NOT NULL DEFAULT 'PRJ-' AFTER `config_default_hourly_rate`");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_project_next_number` INT(11) NOT NULL DEFAULT 1 AFTER `config_project_prefix`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.5') {

        mysqli_query($mysqli, "ALTER TABLE `projects` ADD `project_prefix` VARCHAR(200) DEFAULT NULL AFTER `project_id`");
        mysqli_query($mysqli, "ALTER TABLE `projects` ADD `project_number` INT(11) NOT NULL DEFAULT 1 AFTER `project_prefix`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.6') {

        mysqli_query($mysqli, "ALTER TABLE `domains` ADD `domain_dnshost` INT(11) NOT NULL DEFAULT 0 AFTER `domain_webhost`");
        mysqli_query($mysqli, "ALTER TABLE `domains` ADD `domain_mailhost` INT(11) NOT NULL DEFAULT 0 AFTER `domain_dnshost`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.7') {

        mysqli_query($mysqli, "ALTER TABLE `recurring` ADD `recurring_invoice_email_notify` TINYINT(1) NOT NULL DEFAULT 1 AFTER `recurring_note`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.8') {

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_phone_mask` TINYINT(1) NOT NULL DEFAULT 1 AFTER `config_destructive_deletes_enable`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.2.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.2.9') {

        mysqli_query($mysqli, "CREATE TABLE `user_permissions` (`user_id` int(11) NOT NULL,`client_id` int(11) NOT NULL, PRIMARY KEY (`user_id`,`client_id`))");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.0'");
    }

     if (CURRENT_DATABASE_VERSION == '1.3.0') {

         mysqli_query($mysqli, "CREATE TABLE `user_roles` (
            `user_role_id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_role_name` VARCHAR(200) NOT NULL,
            `user_role_description` VARCHAR(200) NULL DEFAULT NULL,
            `user_role_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `user_role_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `user_role_archived_at` DATETIME NULL,
            PRIMARY KEY (`user_role_id`)
        )");

         mysqli_query($mysqli, "INSERT INTO `user_roles` SET user_role_id = 1, user_role_name = 'Accountant', user_role_description = 'Built-in - Limited access to financial-focused modules'");
         mysqli_query($mysqli, "INSERT INTO `user_roles` SET user_role_id = 2, user_role_name = 'Technician', user_role_description = 'Built-in - Limited access to technical-focused modules'");
         mysqli_query($mysqli, "INSERT INTO `user_roles` SET user_role_id = 3, user_role_name = 'Administrator', user_role_description = 'Built-in - Full administrative access to all modules (including user management)'");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.1'");
     }

     if (CURRENT_DATABASE_VERSION == '1.3.1') {
         mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_calendar_first_day` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_config_dashboard_technical_enable`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.2'");
     }

    if (CURRENT_DATABASE_VERSION == '1.3.2') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_default_billable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_ticket_new_ticket_notification_email`");
        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` ADD `scheduled_ticket_billable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `scheduled_ticket_frequency`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.3.3') {
    //     // Insert queries here required to update to DB version 1.3.3
    //     // Then, update the database to the next sequential version
        mysqli_query($mysqli, "CREATE TABLE `location_tags` (`location_id` int(11) NOT NULL,`tag_id` int(11) NOT NULL, PRIMARY KEY (`location_id`,`tag_id`))");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.3.4') {
        mysqli_query($mysqli, "ALTER TABLE `client_tags` CHANGE `client_tag_client_id` `client_id` INT(11) NOT NULL");
        mysqli_query($mysqli, "ALTER TABLE `client_tags` CHANGE `client_tag_tag_id` `tag_id` INT(11) NOT NULL");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.3.5') {
        mysqli_query($mysqli, "CREATE TABLE `contact_tags` (`contact_id` int(11) NOT NULL,`tag_id` int(11) NOT NULL, PRIMARY KEY (`contact_id`,`tag_id`))");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.3.6') {
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD `client_abbreviation` VARCHAR(10) DEFAULT NULL AFTER `client_tax_id_number`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.7'");
     }

    if (CURRENT_DATABASE_VERSION == '1.3.7') {
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_ipv6` VARCHAR(200) DEFAULT NULL AFTER `asset_ip`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.3.8') {
        mysqli_query($mysqli, "DROP TABLE `interfaces`");

        mysqli_query($mysqli, "CREATE TABLE `asset_interfaces` (
            `interface_id` INT(11) NOT NULL AUTO_INCREMENT,
            `interface_name` VARCHAR(200) NOT NULL,
            `interface_mac` VARCHAR(200) DEFAULT NULL,
            `interface_ip` VARCHAR(200) DEFAULT NULL,
            `interface_nat_ip` VARCHAR(200) DEFAULT NULL,
            `interface_ipv6` VARCHAR(200) DEFAULT NULL,
            `interface_port` VARCHAR(200) DEFAULT NULL,
            `interface_notes` TEXT DEFAULT NULL,
            `interface_primary` TINYINT(1) DEFAULT 0,
            `interface_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `interface_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `interface_archived_at` DATETIME NULL,
            `interface_network_id` INT(11) DEFAULT NULL,
            `interface_asset_id` INT(11) NOT NULL,
            PRIMARY KEY (`interface_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.3.9'");

    }

    if (CURRENT_DATABASE_VERSION == '1.3.9') {
        // Migrate all Network Info from Assets to Interface Table and make it primary interface
        $sql = mysqli_query($mysqli, "SELECT * FROM assets");
        while ($row = mysqli_fetch_assoc($sql)) {
            $asset_id = intval($row['asset_id']);
            $mac = sanitizeInput($row['asset_mac']);
            $ip = sanitizeInput($row['asset_ip']);
            $nat_ip = sanitizeInput($row['asset_nat_ip']);
            $ipv6 = sanitizeInput($row['asset_ipv6']);
            $network = intval($row['asset_network_id']);

            mysqli_query($mysqli, "INSERT INTO `asset_interfaces` SET interface_name = 'Primary', interface_mac = '$mac', interface_ip = '$ip', interface_nat_ip = '$nat_ip', interface_ipv6 = '$ipv6', interface_port = 'eth0', interface_primary = 1, interface_network_id = $network, interface_asset_id = $asset_id");
        }

        // Drop Fields from assets as they moved to asset_interfaces
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_ip`");
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_ipv6`");
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_nat_ip`");
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_mac`");
        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_network_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.0'");

    }

    if (CURRENT_DATABASE_VERSION == '1.4.0') {

        mysqli_query($mysqli, "CREATE TABLE `racks` (
            `rack_id` INT(11) NOT NULL AUTO_INCREMENT,
            `rack_name` VARCHAR(200) NOT NULL,
            `rack_description` TEXT DEFAULT NULL,
            `rack_model` VARCHAR(200) DEFAULT NULL,
            `rack_depth` VARCHAR(50) DEFAULT NULL,
            `rack_type` VARCHAR(50) DEFAULT NULL,
            `rack_units` INT(11) NOT NULL,
            `rack_photo` VARCHAR(200) DEFAULT NULL,
            `rack_physical_location` VARCHAR(200) DEFAULT NULL,
            `rack_notes` TEXT DEFAULT NULL,
            `rack_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `rack_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `rack_archived_at` DATETIME NULL,
            `rack_location_id` INT(11) DEFAULT NULL,
            `rack_client_id` INT(11) NOT NULL,
            PRIMARY KEY (`rack_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `rack_units` (
            `unit_id` INT(11) NOT NULL AUTO_INCREMENT,
            `unit_start_number` INT(11) NOT NULL,
            `unit_end_number` INT(11) NOT NULL,
            `unit_device` VARCHAR(200) DEFAULT NULL,
            `unit_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `unit_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `unit_archived_at` DATETIME NULL,
            `unit_asset_id` INT(11) DEFAULT NULL,
            `unit_rack_id` INT(11) NOT NULL,
            PRIMARY KEY (`unit_id`),
            FOREIGN KEY (`unit_rack_id`) REFERENCES `racks`(`rack_id`) ON DELETE CASCADE
        )");

        mysqli_query($mysqli, "CREATE TABLE `patch_panels` (
            `patch_panel_id` INT(11) NOT NULL AUTO_INCREMENT,
            `patch_panel_name` VARCHAR(200) NOT NULL,
            `patch_panel_description` TEXT DEFAULT NULL,
            `patch_panel_type` VARCHAR(200) DEFAULT NULL,
            `patch_panel_ports` INT(11) NOT NULL,
            `patch_panel_physical_location` VARCHAR(200) DEFAULT NULL,
            `patch_panel_notes` TEXT DEFAULT NULL,
            `patch_panel_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `patch_panel_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `patch_panel_archived_at` DATETIME NULL,
            `patch_panel_location_id` INT(11) DEFAULT NULL,
            `patch_panel_rack_id` INT(11) DEFAULT NULL,
            `patch_panel_client_id` INT(11) NOT NULL,
            PRIMARY KEY (`patch_panel_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `patch_panel_ports` (
            `port_id` INT(11) NOT NULL AUTO_INCREMENT,
            `port_number` INT(11) NOT NULL,
            `port_name` VARCHAR(200) DEFAULT NULL,
            `port_description` TEXT DEFAULT NULL,
            `port_type` VARCHAR(200) DEFAULT NULL,
            `port_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `port_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `port_archived_at` DATETIME NULL,
            `port_asset_id` INT(11) DEFAULT NULL,
            `port_patch_panel_id` INT(11) NOT NULL,
            PRIMARY KEY (`port_id`),
            FOREIGN KEY (`port_patch_panel_id`) REFERENCES `patch_panels`(`patch_panel_id`) ON DELETE CASCADE
        )");

        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_photo` VARCHAR(200) DEFAULT NULL AFTER `asset_install_date`");

        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_physical_location` VARCHAR(200) DEFAULT NULL AFTER `asset_photo`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.4.1') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_log_retention` INT(11) NOT NULL DEFAULT '90' AFTER `config_login_remember_me_expire`;");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_log_retention` = '2555' WHERE company_id = 1;"); // Set to 7 years for existing installs

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.2'");
    }

    if (CURRENT_DATABASE_VERSION == '1.4.2') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_email_parse_unknown_senders` INT(1) NOT NULL DEFAULT '0' AFTER `config_ticket_email_parse`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.3'");
    }

     if (CURRENT_DATABASE_VERSION == '1.4.3') {

         // Add ticket URL key column
         mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_url_key` VARCHAR(200) DEFAULT NULL AFTER `ticket_feedback`");
         // Populate pre-existing columns for open tickets
         $sql_tickets_1 = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE tickets.ticket_closed_at IS NULL");
         foreach ($sql_tickets_1 as $row) {
             $ticket_id = intval($row['ticket_id']);
             $url_key = randomString(156);
             mysqli_query($mysqli, "UPDATE tickets SET ticket_url_key = '$url_key' WHERE ticket_id = '$ticket_id'");
         }

         // Add ticket resolved at column
         mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_resolved_at` DATETIME DEFAULT NULL AFTER `ticket_updated_at`");
         // Populate pre-existing columns for closed tickets
         $sql_tickets_2 = mysqli_query($mysqli, "SELECT ticket_id, ticket_updated_at, ticket_closed_at FROM tickets WHERE tickets.ticket_closed_at IS NOT NULL");
         foreach ($sql_tickets_2 as $row) {
             $ticket_id = intval($row['ticket_id']);
             $ticket_updated_at = sanitizeInput($row['ticket_updated_at']); // To keep old updated_at time
             $ticket_closed_at = sanitizeInput($row['ticket_closed_at']);
             mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = '$ticket_closed_at', ticket_updated_at = '$ticket_updated_at' WHERE ticket_id = '$ticket_id'");
         }

         // Change ticket status 'Auto close' to 'Resolved'
         mysqli_query($mysqli, "UPDATE `ticket_statuses` SET `ticket_status_name` = 'Resolved' WHERE `ticket_statuses`.`ticket_status_id` = 4");

         // Auto-close is no longer optional
         mysqli_query($mysqli, "ALTER TABLE `settings` DROP `config_ticket_autoclose`");
         mysqli_query($mysqli, "UPDATE `settings` SET `config_ticket_autoclose_hours` = '72'");

         // DB Version
         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.4'");

     }

     if (CURRENT_DATABASE_VERSION == '1.4.4') {
         mysqli_query($mysqli, "ALTER TABLE `api_keys` ADD `api_key_decrypt_hash` VARCHAR(200) NOT NULL AFTER `api_key_secret`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.5'");
     }

     if (CURRENT_DATABASE_VERSION == '1.4.5') {
         mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_whitelabel_enabled` INT(11) NOT NULL DEFAULT '0' AFTER `config_phone_mask`");
         mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_whitelabel_key` TEXT NULL DEFAULT NULL AFTER `config_whitelabel_enabled`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.6'");
     }

    if (CURRENT_DATABASE_VERSION == '1.4.6') {
        mysqli_query($mysqli, "CREATE TABLE `custom_links` (
            `custom_link_id` INT(11) NOT NULL AUTO_INCREMENT,
            `custom_link_name` VARCHAR(200) NOT NULL,
            `custom_link_description` TEXT DEFAULT NULL,
            `custom_link_uri` VARCHAR(500) NOT NULL,
            `custom_link_icon` VARCHAR(200) DEFAULT NULL,
            `custom_link_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `custom_link_updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP NULL,
            `custom_link_archived_at` DATETIME NULL,
            PRIMARY KEY (`custom_link_id`)
        )");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.7'");
    }

     if (CURRENT_DATABASE_VERSION == '1.4.7') {
         mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_client_visible` INT(11) NOT NULL DEFAULT '1' AFTER `document_parent`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.8'");
     }

     if (CURRENT_DATABASE_VERSION == '1.4.8') {
         mysqli_query($mysqli, "ALTER TABLE `settings` DROP `config_stripe_client_pays_fees`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.4.9'");
     }

     if (CURRENT_DATABASE_VERSION == '1.4.9') {

         // Add new "is admin" identifier on user roles
         mysqli_query($mysqli, "ALTER TABLE `user_roles` ADD `user_role_is_admin` INT(11) NOT NULL DEFAULT '0' AFTER `user_role_description`");
         mysqli_query($mysqli, "UPDATE `user_roles` SET `user_role_is_admin` = '1' WHERE `user_role_id` = 3");

         // Add modules
         mysqli_query($mysqli, "CREATE TABLE `modules` (
            `module_id` INT(11) NOT NULL AUTO_INCREMENT,
            `module_name` VARCHAR(200) NOT NULL,
            `module_description` VARCHAR(200) NULL,
            PRIMARY KEY (`module_id`)
         )");

         mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_client', module_description = 'General client & contact management'");
         mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_support', module_description = 'Access to ticketing, assets and documentation'");
         mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_credential', module_description = 'Access to client credentials - usernames, passwords and 2FA codes'");
         mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_sales', module_description = 'Access to quotes, invoices and products'");
         mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_financial', module_description = 'Access to payments, accounts, expenses and budgets'");
         mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_reporting', module_description = 'Access to all reports'");

         // Add table for storing role<->module permissions
         mysqli_query($mysqli, "CREATE TABLE `user_role_permissions` (
            `user_role_id` INT(11) NOT NULL,
            `module_id` INT(11) NOT NULL,
            `user_role_permission_level` INT(11) NOT NULL
         )");

         // Add default permissions for accountant role
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 1, user_role_permission_level = 1"); // Read clients
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 2, user_role_permission_level = 1"); // Read support
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 4, user_role_permission_level = 1"); // Read sales
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 5, user_role_permission_level = 2"); // Modify financial
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 1, module_id = 6, user_role_permission_level = 1"); // Read reports

         // Add default permissions for tech role
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 1, user_role_permission_level = 2"); // Modify clients
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 2, user_role_permission_level = 2"); // Modify support
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 3, user_role_permission_level = 2"); // Modify credentials
         mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = 2, module_id = 4, user_role_permission_level = 2"); // Modify sales

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.0'");
     }

    if (CURRENT_DATABASE_VERSION == '1.5.0') {

        mysqli_query($mysqli, "DROP TABLE `account_types`");

        mysqli_query($mysqli, "ALTER TABLE `accounts` ADD `account_description` VARCHAR(250) DEFAULT NULL AFTER `account_name`");

        mysqli_query($mysqli, "ALTER TABLE `user_roles` MODIFY `user_role_is_admin` TINYINT(1) NOT NULL DEFAULT '0'");

        mysqli_query($mysqli, "ALTER TABLE `shared_items` ADD `item_recipient` VARCHAR(250) DEFAULT NULL AFTER `item_note`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.1') {

        mysqli_query($mysqli, "ALTER TABLE `custom_links` ADD `custom_link_location` INT(11) NOT NULL DEFAULT 1 AFTER `custom_link_icon`");
        mysqli_query($mysqli, "ALTER TABLE `custom_links` ADD `custom_link_new_tab` TINYINT(1) NOT NULL DEFAULT 0 AFTER `custom_link_uri`");
        mysqli_query($mysqli, "ALTER TABLE `custom_links` ADD `custom_link_order` INT(11) NOT NULL DEFAULT 0 AFTER `custom_link_location`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.2'");
    }

     if (CURRENT_DATABASE_VERSION == '1.5.2') {
         mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_invoice_paid_notification_email` VARCHAR(200) DEFAULT NULL AFTER `config_invoice_late_fee_percent`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.3'");
     }

    if (CURRENT_DATABASE_VERSION == '1.5.3') {
        mysqli_query($mysqli, "ALTER TABLE `users` ADD `user_type` TINYINT(1) NOT NULL DEFAULT 1 AFTER `user_password`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.4') {
        mysqli_query($mysqli, "ALTER TABLE `user_roles` ADD `user_role_type` TINYINT(1) NOT NULL DEFAULT 1 AFTER `user_role_description`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.5') {
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_user_id` INT(11) NOT NULL DEFAULT 0 AFTER `contact_vendor_id`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.6') {
        mysqli_query($mysqli, "ALTER TABLE `users` ADD `user_auth_method` VARCHAR(200) NOT NULL DEFAULT 'local' AFTER `user_password`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.7') {
        // Create Users for contacts that have logins enabled and that are not archived
        $contacts_sql = mysqli_query($mysqli, "SELECT * FROM `contacts` WHERE contact_archived_at IS NULL AND (contact_auth_method = 'local' OR contact_auth_method = 'azure')");
        while($row = mysqli_fetch_assoc($contacts_sql)) {
            $contact_id = intval($row['contact_id']);
            $contact_name = mysqli_real_escape_string($mysqli, $row['contact_name']);
            $contact_email = mysqli_real_escape_string($mysqli, $row['contact_email']);
            $contact_password_hash = mysqli_real_escape_string($mysqli, $row['contact_password_hash']);
            $contact_auth_method = mysqli_real_escape_string($mysqli, $row['contact_auth_method']);

            mysqli_query($mysqli, "INSERT INTO users SET user_name = '$contact_name', user_email = '$contact_email', user_password = '$contact_password_hash', user_auth_method = '$contact_auth_method', user_type = 2");

            $user_id = mysqli_insert_id($mysqli);

            mysqli_query($mysqli, "UPDATE `contacts` SET `contact_user_id` = $user_id WHERE contact_id = $contact_id");
        }

        // Drop Login Related fields from contacts tables as everyone who has a login has been moved over
        mysqli_query($mysqli, "ALTER TABLE `contacts` DROP `contact_auth_method`, DROP `contact_password_hash`, DROP `contact_password_reset_token`, DROP `contact_token_expire`");

        // Add Password Reset Tokens to users tables
        mysqli_query($mysqli, "ALTER TABLE `users` ADD `user_password_reset_token` VARCHAR(200) NULL DEFAULT NULL AFTER `user_token`");
        mysqli_query($mysqli, "ALTER TABLE `users` ADD `user_password_reset_token_expire` DATETIME NULL DEFAULT NULL AFTER `user_password_reset_token`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.8') {
        // Add task completetion estimate time to tasks and task templates
        mysqli_query($mysqli, "ALTER TABLE `tasks` ADD `task_completion_estimate` INT(11) NOT NULL DEFAULT 0 AFTER `task_order`");
        mysqli_query($mysqli, "ALTER TABLE `task_templates` ADD `task_template_completion_estimate` INT(11) NOT NULL DEFAULT 0 AFTER `task_template_order`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.5.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.5.9') {

        // Check if the column already exists
        $result = mysqli_query($mysqli, "SHOW COLUMNS FROM `logins` LIKE 'login_folder_id'");
        if (mysqli_num_rows($result) == 0) {
            mysqli_query($mysqli, "ALTER TABLE `logins` ADD `login_folder_id` INT(11) NOT NULL DEFAULT 0 AFTER `login_password_changed_at`");
        } else {
            // The column already exists
            echo "Column 'login_folder_id' already exists in the 'logins' table.";
        }

        mysqli_query($mysqli, "ALTER TABLE `logins` MODIFY `login_username` VARCHAR(500) DEFAULT NULL");

        mysqli_query($mysqli, "ALTER TABLE `logins` MODIFY `login_description` VARCHAR(500) DEFAULT NULL");

        mysqli_query($mysqli, "ALTER TABLE `tickets` MODIFY `ticket_subject` VARCHAR(500) NOT NULL");

        // Fix some some staggering ticket statuses that were still using a string and not a number
        // forum.itflow.org/d/1248-bug-unable-to-update-database
        // Update existing tickets to use new values
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 1 WHERE ticket_status = 'New'"); // New
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 2 WHERE ticket_status = 'Open'"); // Open
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 3 WHERE ticket_status = 'On Hold'"); // On Hold
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 4 WHERE ticket_status = 'Auto Close'"); // Auto Close
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5 WHERE ticket_status = 'Closed'"); // Closed

        mysqli_query($mysqli, "ALTER TABLE `tickets` MODIFY `ticket_status` INT(11) NOT NULL");

        mysqli_query($mysqli, "ALTER TABLE `ticket_templates` MODIFY `ticket_template_subject` VARCHAR(500) DEFAULT NULL");

        mysqli_query($mysqli, "ALTER TABLE `scheduled_tickets` MODIFY `scheduled_ticket_subject` VARCHAR(500) NOT NULL");

        mysqli_query($mysqli, "ALTER TABLE `logs` MODIFY `log_description` VARCHAR(1000) NOT NULL");

        mysqli_query($mysqli, "ALTER TABLE `notifications` MODIFY `notification` VARCHAR(1000) NOT NULL");


        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.0') {

        mysqli_query($mysqli, "CREATE TABLE `asset_history` (
            `asset_history_id` INT(11) NOT NULL AUTO_INCREMENT,
            `asset_history_status` VARCHAR(200) NOT NULL,
            `asset_history_description` VARCHAR(255) NOT NULL,
            `asset_history_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `asset_history_asset_id` INT(11) NOT NULL,
            PRIMARY KEY (`asset_history_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.1') {

        mysqli_query($mysqli, "CREATE TABLE `login_tags` (`login_id` int(11) NOT NULL,`tag_id` int(11) NOT NULL, PRIMARY KEY (`login_id`,`tag_id`))");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.2'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.2') {

        mysqli_query($mysqli, "ALTER TABLE `files` MODIFY `file_description` VARCHAR(250) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `files` MODIFY `file_ext` VARCHAR(10) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_created_by` INT(11) NOT NULL DEFAULT 0 AFTER `file_accessed_at`");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `file_ext`");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_mime_type` VARCHAR(100) DEFAULT NULL AFTER `file_hash`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.3') {

        // Find Files and update the Mime Type and File Size

        function scanDirectory($dir, $mysqli) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $file_path = $file->getPathname();
                    $file_name = $file->getFilename();
                    // Process the file
                    processFile($file_path, $file_name, $mysqli);
                }
            }
        }

        function processFile($file_path, $file_name, $mysqli) {
            // Get the file size
            $file_size = filesize($file_path);
            // Get the MIME type
            $file_mime_type = mime_content_type($file_path);

            // Prepare a statement to check if the file exists in the database
            $stmt_select = mysqli_prepare($mysqli, "SELECT file_id FROM files WHERE file_reference_name = ?");
            mysqli_stmt_bind_param($stmt_select, 's', $file_name);
            mysqli_stmt_execute($stmt_select);
            mysqli_stmt_store_result($stmt_select);

            if (mysqli_stmt_num_rows($stmt_select) > 0) {
                // File exists in the database, proceed to update
                $stmt_update = mysqli_prepare($mysqli, "UPDATE files SET file_mime_type = ?, file_size = ? WHERE file_reference_name = ?");
                mysqli_stmt_bind_param($stmt_update, 'sis', $file_mime_type, $file_size, $file_name);

                if (mysqli_stmt_execute($stmt_update)) {
                    echo "Updated: $file_name\n";
                } else {
                    echo "Error updating $file_name: " . mysqli_stmt_error($stmt_update) . "\n";
                }
                mysqli_stmt_close($stmt_update);
            } else {
                echo "No database entry found for: $file_name\n";
            }
            mysqli_stmt_close($stmt_select);
        }

        // Define the uploads directory (modify the path if necessary)
        $uploads_dir = __DIR__ . '/uploads';

        // Start scanning from the uploads directory
        scanDirectory($uploads_dir, $mysqli);

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.4') {

        mysqli_query($mysqli, "CREATE TABLE `ticket_history` (
            `ticket_history_id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_history_status` VARCHAR(200) NOT NULL,
            `ticket_history_description` VARCHAR(255) NOT NULL,
            `ticket_history_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ticket_history_ticket_id` INT(11) NOT NULL,
            PRIMARY KEY (`ticket_history_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.5') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_quote_notification_email` VARCHAR(200) DEFAULT NULL AFTER `config_quote_from_email`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.6') {

        mysqli_query($mysqli, "CREATE TABLE `contact_notes` (
            `contact_note_id` INT(11) NOT NULL AUTO_INCREMENT,
            `contact_note_type` VARCHAR(200) NOT NULL,
            `contact_note` TEXT NULL DEFAULT NULL,
            `contact_note_created_by` INT(11) NOT NULL,
            `contact_note_created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
            `contact_note_updated_at` DATETIME NULL DEFAULT NULL on update CURRENT_TIMESTAMP,
            `contact_note_archived_at` DATETIME NULL DEFAULT NULL,
            `contact_note_contact_id` INT(11) NOT NULL,
            PRIMARY KEY (`contact_note_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `client_notes` (
            `client_note_id` INT(11) NOT NULL AUTO_INCREMENT,
            `client_note_type` VARCHAR(200) NOT NULL,
            `client_note` TEXT NULL DEFAULT NULL,
            `client_note_created_by` INT(11) NOT NULL,
            `client_note_created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
            `client_note_updated_at` DATETIME NULL DEFAULT NULL on update CURRENT_TIMESTAMP,
            `client_note_archived_at` DATETIME NULL DEFAULT NULL,
            `client_note_client_id` INT(11) NOT NULL,
            PRIMARY KEY (`client_note_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `asset_notes` (
            `asset_note_id` INT(11) NOT NULL AUTO_INCREMENT,
            `asset_note_type` VARCHAR(200) NOT NULL,
            `asset_note` TEXT NULL DEFAULT NULL,
            `asset_note_created_by` INT(11) NOT NULL,
            `asset_note_created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
            `asset_note_updated_at` DATETIME NULL DEFAULT NULL on update CURRENT_TIMESTAMP,
            `asset_note_archived_at` DATETIME NULL DEFAULT NULL,
            `asset_note_asset_id` INT(11) NOT NULL,
            PRIMARY KEY (`asset_note_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.7') {

        mysqli_query($mysqli, "CREATE TABLE `error_logs` (
            `error_log_id` INT(11) NOT NULL AUTO_INCREMENT,
            `error_log_type` VARCHAR(200) NOT NULL,
            `error_log_details` VARCHAR(1000) NULL DEFAULT NULL,
            `error_log_created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`error_log_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `auth_logs` (
            `auth_log_id` INT(11) NOT NULL AUTO_INCREMENT,
            `auth_log_status` TINYINT(1) NOT NULL,
            `auth_log_details` VARCHAR(200) NULL DEFAULT NULL,
            `auth_log_ip` VARCHAR(200) NULL DEFAULT NULL,
            `auth_log_user_agent` VARCHAR(250) NULL DEFAULT NULL,
            `auth_log_user_id` INT(11) NOT NULL DEFAULT 0,
            `auth_log_created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`auth_log_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.8') {

        // Create New Vendor Templates Table this eventual be used to seperate templates out of the vendors table
        mysqli_query($mysqli, "CREATE TABLE `vendor_templates` (`vendor_template_id` int(11) AUTO_INCREMENT PRIMARY KEY,
            `vendor_template_name` varchar(200) NOT NULL,
            `vendor_template_description` varchar(200) NULL DEFAULT NULL,
            `vendor_template_phone` varchar(200) NULL DEFAULT NULL,
            `vendor_template_email` varchar(200) NULL DEFAULT NULL,
            `vendor_template_website` varchar(200) NULL DEFAULT NULL,
            `vendor_template_hours` varchar(200) NULL DEFAULT NULL,
            `vendor_template_created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `vendor_template_updated_at` datetime NULL ON UPDATE CURRENT_TIMESTAMP,
            `vendor_template_archived_at` datetime NULL DEFAULT NULL
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.6.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.6.9') {

        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_has_thumbnail` TINYINT(1) NOT NULL DEFAULT 0 AFTER `file_mime_type`");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_has_preview` TINYINT(1) NOT NULL DEFAULT 0 AFTER `file_has_thumbnail`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.0') {

        mysqli_query($mysqli, "DROP TABLE `vendor_templates`");

        mysqli_query($mysqli, "CREATE TABLE `vendor_contacts` (
            `vendor_contact_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `vendor_contact_name` VARCHAR(200) NOT NULL,
            `vendor_contact_title` VARCHAR(200) DEFAULT NULL,
            `vendor_contact_department` VARCHAR(200) DEFAULT NULL,
            `vendor_contact_email` VARCHAR(200) DEFAULT NULL,
            `vendor_contact_phone` VARCHAR(200) DEFAULT NULL,
            `vendor_contact_extension` VARCHAR(200) DEFAULT NULL,
            `vendor_contact_mobile` VARCHAR(200) DEFAULT NULL,
            `vendor_contact_notes` TEXT DEFAULT NULL,
            `vendor_contact_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `vendor_contact_updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
            `vendor_contact_archived_at` DATETIME DEFAULT NULL,
            `vendor_contact_vendor_id` INT(11) NOT NULL DEFAULT 0
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.1') {

        mysqli_query($mysqli, "DROP TABLE `error_logs`");

        mysqli_query($mysqli, "CREATE TABLE `app_logs` (
            `app_log_id` INT(11) NOT NULL AUTO_INCREMENT,
            `app_log_category` VARCHAR(200) NULL DEFAULT NULL,
            `app_log_type` ENUM('info', 'warning', 'error', 'debug') NOT NULL DEFAULT 'info',
            `app_log_details` VARCHAR(1000) NULL DEFAULT NULL,
            `app_log_created_at` DATETIME NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`app_log_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.2'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.2') {
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_fax` VARCHAR(200) DEFAULT NULL AFTER `location_phone`");

        mysqli_query($mysqli, "DROP TABLE `vendor_contacts`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.3') {

        // Add Recurring Payments
        mysqli_query($mysqli, "CREATE TABLE `recurring_payments` (
            `recurring_payment_id` INT(11) NOT NULL AUTO_INCREMENT,
            `recurring_payment_amount` DECIMAL(15,2) NOT NULL,
            `recurring_payment_currency_code` VARCHAR(10) NOT NULL,
            `recurring_payment_method` VARCHAR(200) NOT NULL,
            `recurring_payment_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `recurring_payment_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `recurring_payment_archived_at` DATETIME DEFAULT NULL,
            `recurring_payment_account_id` INT(11) NOT NULL,
            `recurring_payment_recurring_expense_id` INT(11) NOT NULL DEFAULT 0,
            `recurring_payment_recurring_invoice_id` INT(11) NOT NULL,
            PRIMARY KEY (`recurring_payment_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.4') {

        // Remove Recurring Payment Amount as it will use the Recurring Invoice Amount and is unessessary
        mysqli_query($mysqli, "ALTER TABLE `recurring_payments` DROP `recurring_payment_amount`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.5') {
        mysqli_query($mysqli, "CREATE TABLE `client_stripe` (`client_id` INT(11) NOT NULL, `stripe_id` VARCHAR(255) NOT NULL, `stripe_pm` varchar(255) NULL) ENGINE = InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci; ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.6') {
        // Create a field to show connected interface of a foreign asset
        mysqli_query($mysqli, "ALTER TABLE `asset_interfaces` ADD `interface_connected_asset_interface` INT(11) NOT NULL DEFAULT 0 AFTER `interface_network_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.7') {
        // Domain history
        mysqli_query($mysqli, "CREATE TABLE `domain_history` (`domain_history_id` INT(11) NOT NULL AUTO_INCREMENT , `domain_history_column` VARCHAR(200) NOT NULL , `domain_history_old_value` TEXT NOT NULL , `domain_history_new_value` TEXT NOT NULL , `domain_history_domain_id` INT(11) NOT NULL , `domain_history_modified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`domain_history_id`)) ENGINE = InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.8') {

        // Use a seperate table for Interface connections / links. This will make it easier to manage.
        $createInterfaceLinksTable = "
            CREATE TABLE IF NOT EXISTS `asset_interface_links` (
                `interface_link_id` INT AUTO_INCREMENT PRIMARY KEY,
                `interface_a_id` INT NOT NULL,
                `interface_b_id` INT NOT NULL,
                `interface_link_type` VARCHAR(100) NULL,
                `interface_link_status` VARCHAR(50) NULL,
                `interface_link_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `interface_link_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT `fk_interface_a`
                    FOREIGN KEY (`interface_a_id`)
                    REFERENCES `asset_interfaces` (`interface_id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE,

                CONSTRAINT `fk_interface_b`
                    FOREIGN KEY (`interface_b_id`)
                    REFERENCES `asset_interfaces` (`interface_id`)
                    ON DELETE CASCADE
                    ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        mysqli_query($mysqli, $createInterfaceLinksTable) or die(mysqli_error($mysqli));

        // Drop the old column from asset_interfaces if it exists
        $dropConnectedColumn = "
            ALTER TABLE `asset_interfaces`
            DROP COLUMN IF EXISTS `interface_connected_asset_interface`
        ";
        mysqli_query($mysqli, $dropConnectedColumn) or die(mysqli_error($mysqli));

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.7.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.7.9') {

        mysqli_query($mysqli, "ALTER TABLE `settings` DROP `config_cron_key`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.8.0') {

        mysqli_query($mysqli, "ALTER TABLE `ticket_statuses` ADD `ticket_status_order` int(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_order` int(11) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_default_view` tinyint(1) NOT NULL DEFAULT 0");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_ordering` tinyint(1) NOT NULL DEFAULT 0");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_moving_columns` tinyint(1) NOT NULL DEFAULT 1");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.8.1') {
        mysqli_query($mysqli, "ALTER TABLE `asset_interfaces` CHANGE `interface_port` `interface_description` VARCHAR(200) DEFAULT NULL AFTER `interface_name`");

        mysqli_query($mysqli, "ALTER TABLE `asset_interfaces` ADD `interface_type` VARCHAR(50) DEFAULT NULL AFTER `interface_description`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.2'");
    }

     if (CURRENT_DATABASE_VERSION == '1.8.2') {
        mysqli_query($mysqli, "CREATE TABLE `quote_files` (
            `quote_id` INT(11) NOT NULL,
            `file_id` INT(11) NOT NULL,
            PRIMARY KEY (`quote_id`, `file_id`)
        )");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.3'");
     }

    if (CURRENT_DATABASE_VERSION == '1.8.3') {
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_purchase_reference` VARCHAR(200) DEFAULT NULL AFTER `asset_status`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.8.4') {
        mysqli_query($mysqli, "ALTER TABLE `logins` DROP `login_software_id`");
        mysqli_query($mysqli, "ALTER TABLE `logins` DROP `login_vendor_id`");
        mysqli_query($mysqli, "ALTER TABLE `software` DROP `software_login_id`");
        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_vendor_id` INT(11) DEFAULT 0 AFTER `software_accessed_at`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.8.5') {
        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_purchase_reference` VARCHAR(200) DEFAULT NULL AFTER `software_seats`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.8.6') {
        mysqli_query($mysqli, "
            CREATE TABLE `certificate_history` (`certificate_history_id` INT(11) NOT NULL AUTO_INCREMENT,
            `certificate_history_column` VARCHAR(200) NOT NULL,
            `certificate_history_old_value` TEXT NOT NULL,
            `certificate_history_new_value` TEXT NOT NULL,
            `certificate_history_certificate_id` INT(11) NOT NULL,
            `certificate_history_modified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`certificate_history_id`)) ENGINE = InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.7'");
    }

     if (CURRENT_DATABASE_VERSION == '1.8.7') {
         mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_first_response_at` DATETIME NULL DEFAULT NULL AFTER `ticket_archived_at`");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.8'");
     }

    if (CURRENT_DATABASE_VERSION == '1.8.8') {
        mysqli_query($mysqli, "ALTER TABLE `invoices` ADD `invoice_recurring_invoice_id` INT(11) NOT NULL DEFAULT 0 AFTER `invoice_category_id`");
        mysqli_query($mysqli, "ALTER TABLE `invoice_items` ADD `item_product_id` INT(11) NOT NULL DEFAULT 0 AFTER `item_tax_id`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.8.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.8.9') {
        mysqli_query($mysqli, "ALTER TABLE `users` ADD `user_role_id` INT(11) DEFAULT 0 AFTER `user_archived_at`");

        // Copy user role from user settings table to the users table
        mysqli_query($mysqli,"
            UPDATE `users`
            JOIN `user_settings` ON users.user_id = user_settings.user_id
            SET users.user_role_id = user_settings.user_role
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.0'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.0') {
        mysqli_query($mysqli, "ALTER TABLE `user_settings` DROP `user_role`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.1'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.1') {

        mysqli_query($mysqli,
            "ALTER TABLE `user_roles`
            CHANGE COLUMN `user_role_id` `role_id` INT(11) NOT NULL AUTO_INCREMENT,
            CHANGE COLUMN `user_role_name` `role_name` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `user_role_description` `role_description` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `user_role_type` `role_type` TINYINT(1) NOT NULL DEFAULT 1,
            CHANGE COLUMN `user_role_is_admin` `role_is_admin` TINYINT(1) NOT NULL DEFAULT 0,
            CHANGE COLUMN `user_role_created_at` `role_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            CHANGE COLUMN `user_role_updated_at` `role_updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
            CHANGE COLUMN `user_role_archived_at` `role_archived_at` DATETIME NULL DEFAULT NULL
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.2'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.2') {

        mysqli_query($mysqli, "RENAME TABLE `user_permissions` TO `user_client_permissions`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.3'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.3') {

        // Now create the table with foreign keys
        mysqli_query($mysqli, "
            CREATE TABLE `ticket_assets` (
                `ticket_id` INT(11) NOT NULL,
                `asset_id` INT(11) NOT NULL,
                PRIMARY KEY (`ticket_id`, `asset_id`),
                FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE,
                FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`ticket_id`) ON DELETE CASCADE
            )
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.4'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.4') {
        mysqli_query($mysqli, "RENAME TABLE `scheduled_tickets` TO `recurring_tickets`");

        mysqli_query($mysqli,
            "ALTER TABLE `recurring_tickets`
            CHANGE COLUMN `scheduled_ticket_id` `recurring_ticket_id` INT(11) NOT NULL AUTO_INCREMENT,
            CHANGE COLUMN `scheduled_ticket_category` `recurring_ticket_category` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `scheduled_ticket_subject` `recurring_ticket_subject` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `scheduled_ticket_details` `recurring_ticket_details` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `scheduled_ticket_priority` `recurring_ticket_priority` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `scheduled_ticket_frequency` `recurring_ticket_frequency` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `scheduled_ticket_billable` `recurring_ticket_billable` TINYINT(1) NOT NULL DEFAULT 0,
            CHANGE COLUMN `scheduled_ticket_start_date` `recurring_ticket_start_date` DATE NOT NULL,
            CHANGE COLUMN `scheduled_ticket_next_run` `recurring_ticket_next_run` DATE NOT NULL,
            CHANGE COLUMN `scheduled_ticket_created_at` `recurring_ticket_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            CHANGE COLUMN `scheduled_ticket_updated_at` `recurring_ticket_updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
            CHANGE COLUMN `scheduled_ticket_created_by` `recurring_ticket_created_by` INT(11) NOT NULL DEFAULT 0,
            CHANGE COLUMN `scheduled_ticket_assigned_to` `recurring_ticket_assigned_to` INT(11) NOT NULL DEFAULT 0,
            CHANGE COLUMN `scheduled_ticket_client_id` `recurring_ticket_client_id` INT(11) NOT NULL DEFAULT 0,
            CHANGE COLUMN `scheduled_ticket_contact_id` `recurring_ticket_contact_id` INT(11) NOT NULL DEFAULT 0,
            CHANGE COLUMN `scheduled_ticket_asset_id` `recurring_ticket_asset_id` INT(11) NOT NULL DEFAULT 0
            "
        );

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.5'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.5') {

        // create the table with foreign keys
        mysqli_query($mysqli, "
            CREATE TABLE `recurring_ticket_assets` (
                `recurring_ticket_id` INT(11) NOT NULL,
                `asset_id` INT(11) NOT NULL,
                PRIMARY KEY (`recurring_ticket_id`, `asset_id`),
                FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE,
                FOREIGN KEY (`recurring_ticket_id`) REFERENCES `recurring_tickets`(`recurring_ticket_id`) ON DELETE CASCADE
            )
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.6'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.6') {
        mysqli_query($mysqli, "RENAME TABLE `recurring` TO `recurring_invoices`");

        mysqli_query($mysqli, "
            ALTER TABLE `recurring_invoices`
            CHANGE COLUMN `recurring_id` `recurring_invoice_id` INT(11) NOT NULL AUTO_INCREMENT,
            CHANGE COLUMN `recurring_prefix` `recurring_invoice_prefix` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `recurring_number` `recurring_invoice_number` INT(11) NOT NULL,
            CHANGE COLUMN `recurring_scope` `recurring_invoice_scope` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `recurring_frequency` `recurring_invoice_frequency` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `recurring_last_sent` `recurring_invoice_last_sent` DATE NULL DEFAULT NULL,
            CHANGE COLUMN `recurring_next_date` `recurring_invoice_next_date` DATE NOT NULL,
            CHANGE COLUMN `recurring_status` `recurring_invoice_status` INT(1) NOT NULL,
            CHANGE COLUMN `recurring_discount_amount` `recurring_invoice_discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            CHANGE COLUMN `recurring_amount` `recurring_invoice_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            CHANGE COLUMN `recurring_currency_code` `recurring_invoice_currency_code` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `recurring_note` `recurring_invoice_note` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `recurring_created_at` `recurring_invoice_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            CHANGE COLUMN `recurring_updated_at` `recurring_invoice_updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
            CHANGE COLUMN `recurring_archived_at` `recurring_invoice_archived_at` DATETIME NULL DEFAULT NULL,
            CHANGE COLUMN `recurring_category_id` `recurring_invoice_category_id` INT(11) NOT NULL,
            CHANGE COLUMN `recurring_client_id` `recurring_invoice_client_id` INT(11) NOT NULL
        ");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.7'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.7') {

        mysqli_query($mysqli, "
            ALTER TABLE `settings`
            CHANGE COLUMN `config_recurring_prefix` `config_recurring_invoice_prefix` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `config_recurring_next_number` `config_recurring_invoice_next_number` INT(11) NOT NULL DEFAULT 1
        ");

        mysqli_query($mysqli, "
            ALTER TABLE `history`
            CHANGE COLUMN `history_recurring_id` `history_recurring_invoice_id` INT(11) NOT NULL DEFAULT 0
        ");

        mysqli_query($mysqli, "
            ALTER TABLE `invoice_items`
            CHANGE COLUMN `item_recurring_id` `item_recurring_invoice_id` INT(11) NOT NULL DEFAULT 0
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.8'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.8') {
        // Reference a Recurring Ticket that generated ticket
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_recurring_ticket_id` INT(11) DEFAULT 0 AFTER `ticket_project_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '1.9.9'");
    }

    if (CURRENT_DATABASE_VERSION == '1.9.9') {
        mysqli_query($mysqli, "RENAME TABLE `logins` TO `credentials`");
        mysqli_query($mysqli, "
            ALTER TABLE `credentials`
            CHANGE COLUMN `login_id` `credential_id` INT(11) NOT NULL AUTO_INCREMENT,
            CHANGE COLUMN `login_name` `credential_name` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            CHANGE COLUMN `login_description` `credential_description` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_category` `credential_category` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_uri` `credential_uri` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_uri_2` `credential_uri_2` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_username` `credential_username` VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_password` `credential_password` VARBINARY(200) NULL DEFAULT NULL,
            CHANGE COLUMN `login_otp_secret` `credential_otp_secret` VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_note` `credential_note` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
            CHANGE COLUMN `login_important` `credential_important` TINYINT(1) NOT NULL DEFAULT '0',
            CHANGE COLUMN `login_created_at` `credential_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            CHANGE COLUMN `login_updated_at` `credential_updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(),
            CHANGE COLUMN `login_archived_at` `credential_archived_at` DATETIME NULL DEFAULT NULL,
            CHANGE COLUMN `login_accessed_at` `credential_accessed_at` DATETIME NULL DEFAULT NULL,
            CHANGE COLUMN `login_password_changed_at` `credential_password_changed_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP(),
            CHANGE COLUMN `login_folder_id` `credential_folder_id` INT(11) NOT NULL DEFAULT '0',
            CHANGE COLUMN `login_contact_id` `credential_contact_id` INT(11) NOT NULL DEFAULT '0',
            CHANGE COLUMN `login_asset_id` `credential_asset_id` INT(11) NOT NULL DEFAULT '0',
            CHANGE COLUMN `login_client_id` `credential_client_id` INT(11) NOT NULL DEFAULT '0'
        ");

        // Rename table contact_logins to contact_credentials
        mysqli_query($mysqli, "RENAME TABLE `contact_logins` TO `contact_credentials`");

        // Alter contact_credentials table and change login_id to credential_id
        mysqli_query($mysqli, "
            ALTER TABLE `contact_credentials`
            CHANGE COLUMN `login_id` `credential_id` INT(11) NOT NULL
        ");

        // Clean up orphaned contact_id rows in contact_credentials
        mysqli_query($mysqli, "
            DELETE FROM `contact_credentials`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        // Clean up orphaned credential_id rows in contact_credentials
        mysqli_query($mysqli, "
            DELETE FROM `contact_credentials`
            WHERE `credential_id` NOT IN (SELECT `credential_id` FROM `credentials`);
        ");

        // Add foreign keys to contact_credentials
        mysqli_query($mysqli, "
            ALTER TABLE `contact_credentials`
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`credential_id`) REFERENCES `credentials`(`credential_id`) ON DELETE CASCADE
        ");

        // Rename table service_logins to service_credentials
        mysqli_query($mysqli, "RENAME TABLE `service_logins` TO `service_credentials`");

        // Alter service_credentials table and change login_id to credential_id
        mysqli_query($mysqli, "
            ALTER TABLE `service_credentials`
            CHANGE COLUMN `login_id` `credential_id` INT(11) NOT NULL
        ");

        // Clean up orphaned service_id rows in service_credentials
        mysqli_query($mysqli, "
            DELETE FROM `service_credentials`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");

        // Clean up orphaned credential_id rows in service_credentials
        mysqli_query($mysqli, "
            DELETE FROM `service_credentials`
            WHERE `credential_id` NOT IN (SELECT `credential_id` FROM `credentials`);
        ");

        // Add foreign keys to service_credentials
        mysqli_query($mysqli, "
            ALTER TABLE `service_credentials`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`credential_id`) REFERENCES `credentials`(`credential_id`) ON DELETE CASCADE
        ");

        // Rename table software_logins to software_credentials
        mysqli_query($mysqli, "RENAME TABLE `software_logins` TO `software_credentials`");

        // Alter software_credentials table and change login_id to credential_id
        mysqli_query($mysqli, "
            ALTER TABLE `software_credentials`
            CHANGE COLUMN `login_id` `credential_id` INT(11) NOT NULL
        ");

        // Clean up orphaned software_id rows in software_credentials
        mysqli_query($mysqli, "
            DELETE FROM `software_credentials`
            WHERE `software_id` NOT IN (SELECT `software_id` FROM `software`);
        ");

        // Clean up orphaned credential_id rows in software_credentials
        mysqli_query($mysqli, "
            DELETE FROM `software_credentials`
            WHERE `credential_id` NOT IN (SELECT `credential_id` FROM `credentials`);
        ");

        // Add foreign keys to software_credentials
        mysqli_query($mysqli, "
            ALTER TABLE `software_credentials`
            ADD FOREIGN KEY (`software_id`) REFERENCES `software`(`software_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`credential_id`) REFERENCES `credentials`(`credential_id`) ON DELETE CASCADE
        ");

        // Rename table vendor_logins to vendor_credentials
        mysqli_query($mysqli, "RENAME TABLE `vendor_logins` TO `vendor_credentials`");

        // Alter vendor_credentials table and change login_id to credential_id
        mysqli_query($mysqli, "
            ALTER TABLE `vendor_credentials`
            CHANGE COLUMN `login_id` `credential_id` INT(11) NOT NULL
        ");

        // Clean up orphaned vendor_id rows in vendor_credentials
        mysqli_query($mysqli, "
            DELETE FROM `vendor_credentials`
            WHERE `vendor_id` NOT IN (SELECT `vendor_id` FROM `vendors`);
        ");

        // Clean up orphaned credential_id rows in vendor_credentials
        mysqli_query($mysqli, "
            DELETE FROM `vendor_credentials`
            WHERE `credential_id` NOT IN (SELECT `credential_id` FROM `credentials`);
        ");

        // Add foreign keys to vendor_credentials
        mysqli_query($mysqli, "
            ALTER TABLE `vendor_credentials`
            ADD FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`vendor_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`credential_id`) REFERENCES `credentials`(`credential_id`) ON DELETE CASCADE
        ");

        // Rename table login_tags to credential_tags
        mysqli_query($mysqli, "RENAME TABLE `login_tags` TO `credential_tags`");

        // Alter credential_tags table and change login_id to credential_id
        mysqli_query($mysqli, "
            ALTER TABLE `credential_tags`
            CHANGE COLUMN `login_id` `credential_id` INT(11) NOT NULL
        ");

        // Clean up orphaned tag_id rows in credential_tags
        mysqli_query($mysqli, "
            DELETE FROM `credential_tags`
            WHERE `tag_id` NOT IN (SELECT `tag_id` FROM `tags`);
        ");

        // Clean up orphaned credential_id rows in credential_tags
        mysqli_query($mysqli, "
            DELETE FROM `credential_tags`
            WHERE `credential_id` NOT IN (SELECT `credential_id` FROM `credentials`);
        ");

        // Add foreign keys to credential_tags
        mysqli_query($mysqli, "
            ALTER TABLE `credential_tags`
            ADD FOREIGN KEY (`tag_id`) REFERENCES `tags`(`tag_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`credential_id`) REFERENCES `credentials`(`credential_id`) ON DELETE CASCADE
        ");

        // Create asset_credentials table with foreign keys
        mysqli_query($mysqli, "
            CREATE TABLE `asset_credentials` (
                `credential_id` INT(11) NOT NULL,
                `asset_id` INT(11) NOT NULL,
                PRIMARY KEY (`credential_id`, `asset_id`),
                FOREIGN KEY (`credential_id`) REFERENCES `credentials`(`credential_id`) ON DELETE CASCADE,
                FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
            )
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.0') {

        //Dropping patch panel as a patch panel can be documented as an asset with interfaces.
        mysqli_query($mysqli, "DROP TABLE `patch_panel_ports`");
        mysqli_query($mysqli, "DROP TABLE `patch_panels`");

        mysqli_query($mysqli, "RENAME TABLE `events` TO `calendar_events`");
        mysqli_query($mysqli, "RENAME TABLE `event_attendees` TO `calendar_event_attendees`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.1') {

        // Clean up orphaned data before adding foreign keys

        // Clean up orphaned asset_custom_asset_id rows in asset_custom
        mysqli_query($mysqli, "
            DELETE FROM `asset_custom`
            WHERE `asset_custom_asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign key to asset_custom
        mysqli_query($mysqli, "
            ALTER TABLE `asset_custom`
            ADD FOREIGN KEY (`asset_custom_asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned asset_id rows in asset_documents
        mysqli_query($mysqli, "
            DELETE FROM `asset_documents`
            WHERE `asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Clean up orphaned document_id rows in asset_documents
        mysqli_query($mysqli, "
            DELETE FROM `asset_documents`
            WHERE `document_id` NOT IN (SELECT `document_id` FROM `documents`);
        ");

        // Add foreign keys to asset_documents
        mysqli_query($mysqli, "
            ALTER TABLE `asset_documents`
            ADD FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`document_id`) REFERENCES `documents`(`document_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned asset_id rows in asset_files
        mysqli_query($mysqli, "
            DELETE FROM `asset_files`
            WHERE `asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Clean up orphaned file_id rows in asset_files
        mysqli_query($mysqli, "
            DELETE FROM `asset_files`
            WHERE `file_id` NOT IN (SELECT `file_id` FROM `files`);
        ");

        // Add foreign keys to asset_files
        mysqli_query($mysqli, "
            ALTER TABLE `asset_files`
            ADD FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`file_id`) REFERENCES `files`(`file_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned asset_history_asset_id rows in asset_history
        mysqli_query($mysqli, "
            DELETE FROM `asset_history`
            WHERE `asset_history_asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign key to asset_history
        mysqli_query($mysqli, "
            ALTER TABLE `asset_history`
            ADD FOREIGN KEY (`asset_history_asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned interface_asset_id rows in asset_interfaces
        mysqli_query($mysqli, "
            DELETE FROM `asset_interfaces`
            WHERE `interface_asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign key to asset_interfaces
        mysqli_query($mysqli, "
            ALTER TABLE `asset_interfaces`
            ADD FOREIGN KEY (`interface_asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned asset_note_asset_id rows in asset_notes
        mysqli_query($mysqli, "
            DELETE FROM `asset_notes`
            WHERE `asset_note_asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign key to asset_notes
        mysqli_query($mysqli, "
            ALTER TABLE `asset_notes`
            ADD FOREIGN KEY (`asset_note_asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned contact_id rows in contact_assets
        mysqli_query($mysqli, "
            DELETE FROM `contact_assets`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        // Clean up orphaned asset_id rows in contact_assets
        mysqli_query($mysqli, "
            DELETE FROM `contact_assets`
            WHERE `asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign keys to contact_assets
        mysqli_query($mysqli, "
            ALTER TABLE `contact_assets`
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned service_id rows in service_assets
        mysqli_query($mysqli, "
            DELETE FROM `service_assets`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");

        // Clean up orphaned asset_id rows in service_assets
        mysqli_query($mysqli, "
            DELETE FROM `service_assets`
            WHERE `asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign keys to service_assets
        mysqli_query($mysqli, "
            ALTER TABLE `service_assets`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned software_id rows in software_assets
        mysqli_query($mysqli, "
            DELETE FROM `software_assets`
            WHERE `software_id` NOT IN (SELECT `software_id` FROM `software`);
        ");

        // Clean up orphaned asset_id rows in software_assets
        mysqli_query($mysqli, "
            DELETE FROM `software_assets`
            WHERE `asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign keys to software_assets
        mysqli_query($mysqli, "
            ALTER TABLE `software_assets`
            ADD FOREIGN KEY (`software_id`) REFERENCES `software`(`software_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.2'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.2') {

        // Clean up orphans
        mysqli_query($mysqli, "
            DELETE FROM `calendar_event_attendees`
            WHERE `attendee_event_id` NOT IN (SELECT `event_id` FROM `calendar_events`);
        ");

        mysqli_query($mysqli, "
            DELETE FROM `calendar_events`
            WHERE `event_calendar_id` NOT IN (SELECT `calendar_id` FROM `calendars`);
        ");

        // Add foreign key to calendar_event_attendees
        mysqli_query($mysqli, "
            ALTER TABLE `calendar_event_attendees`
            ADD FOREIGN KEY (`attendee_event_id`) REFERENCES `calendar_events`(`event_id`) ON DELETE CASCADE
        ");

        // Add foreign key to calendar_events
        mysqli_query($mysqli, "
            ALTER TABLE `calendar_events`
            ADD FOREIGN KEY (`event_calendar_id`) REFERENCES `calendars`(`calendar_id`) ON DELETE CASCADE
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.3') {

        // Clean up orphaned history
        mysqli_query($mysqli, "
            DELETE FROM `certificate_history`
            WHERE `certificate_history_certificate_id` NOT IN (SELECT `certificate_id` FROM `certificates`);
        ");

        // Add foreign key certificate history
        mysqli_query($mysqli, "
            ALTER TABLE `certificate_history`
            ADD FOREIGN KEY (`certificate_history_certificate_id`) REFERENCES `certificates`(`certificate_id`) ON DELETE CASCADE
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.4'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.4') {

        // Clean up orphaned history
        mysqli_query($mysqli, "
            DELETE FROM `client_notes`
            WHERE `client_note_client_id` NOT IN (SELECT `client_id` FROM `clients`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `client_notes`
            ADD FOREIGN KEY (`client_note_client_id`) REFERENCES `clients`(`client_id`) ON DELETE CASCADE
        ");

        // Clean up orphaned history
        mysqli_query($mysqli, "
            DELETE FROM `client_tags`
            WHERE `client_id` NOT IN (SELECT `client_id` FROM `clients`);
        ");

        // Clean up orphaned history
        mysqli_query($mysqli, "
            DELETE FROM `client_tags`
            WHERE `tag_id` NOT IN (SELECT `tag_id` FROM `tags`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `client_tags`
            ADD FOREIGN KEY (`client_id`) REFERENCES `clients`(`client_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`tag_id`) REFERENCES `tags`(`tag_id`) ON DELETE CASCADE
        ");

        //Contact Assets
        // Clean up orphaned history
        mysqli_query($mysqli, "
            DELETE FROM `contact_assets`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        mysqli_query($mysqli, "
            DELETE FROM `contact_assets`
            WHERE `asset_id` NOT IN (SELECT `asset_id` FROM `assets`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `contact_assets`
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        ");

        // Contact Documents
        // Clean up orphaned history
        mysqli_query($mysqli, "
            DELETE FROM `contact_documents`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        mysqli_query($mysqli, "
            DELETE FROM `contact_documents`
            WHERE `document_id` NOT IN (SELECT `document_id` FROM `documents`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `contact_documents`
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`document_id`) REFERENCES `documents`(`document_id`) ON DELETE CASCADE
        ");

        // contact_files
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `contact_files`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        mysqli_query($mysqli, "
            DELETE FROM `contact_files`
            WHERE `file_id` NOT IN (SELECT `file_id` FROM `files`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `contact_files`
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`file_id`) REFERENCES `files`(`file_id`) ON DELETE CASCADE
        ");

        // contact_notes
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `contact_notes`
            WHERE `contact_note_contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `contact_notes`
            ADD FOREIGN KEY (`contact_note_contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE
        ");

        // contact_tags
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `contact_tags`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");

        mysqli_query($mysqli, "
            DELETE FROM `contact_tags`
            WHERE `tag_id` NOT IN (SELECT `tag_id` FROM `tags`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `contact_tags`
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`tag_id`) REFERENCES `tags`(`tag_id`) ON DELETE CASCADE
        ");

        // document_files
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `document_files`
            WHERE `document_id` NOT IN (SELECT `document_id` FROM `documents`);
        ");

        mysqli_query($mysqli, "
            DELETE FROM `document_files`
            WHERE `file_id` NOT IN (SELECT `file_id` FROM `files`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `document_files`
            ADD FOREIGN KEY (`document_id`) REFERENCES `documents`(`document_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`file_id`) REFERENCES `files`(`file_id`) ON DELETE CASCADE
        ");

        // domain_history
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `domain_history`
            WHERE `domain_history_domain_id` NOT IN (SELECT `domain_id` FROM `domains`);
        ");

        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `domain_history`
            ADD FOREIGN KEY (`domain_history_domain_id`) REFERENCES `domains`(`domain_id`) ON DELETE CASCADE
        ");

        // location_tags
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `location_tags`
            WHERE `location_id` NOT IN (SELECT `location_id` FROM `locations`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `location_tags`
            WHERE `tag_id` NOT IN (SELECT `tag_id` FROM `tags`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `location_tags`
            ADD FOREIGN KEY (`location_id`) REFERENCES `locations`(`location_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`tag_id`) REFERENCES `tags`(`tag_id`) ON DELETE CASCADE
        ");

        // quote_files
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `quote_files`
            WHERE `quote_id` NOT IN (SELECT `quote_id` FROM `quotes`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `quote_files`
            WHERE `file_id` NOT IN (SELECT `file_id` FROM `files`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `quote_files`
            ADD FOREIGN KEY (`quote_id`) REFERENCES `quotes`(`quote_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`file_id`) REFERENCES `files`(`file_id`) ON DELETE CASCADE
        ");

        // service_certificates
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `service_certificates`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `service_certificates`
            WHERE `certificate_id` NOT IN (SELECT `certificate_id` FROM `certificates`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `service_certificates`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`certificate_id`) REFERENCES `certificates`(`certificate_id`) ON DELETE CASCADE
        ");

        // service_contacts
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `service_contacts`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `service_contacts`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `service_contacts`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE
        ");

        // service_documents
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `service_documents`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `service_documents`
            WHERE `document_id` NOT IN (SELECT `document_id` FROM `documents`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `service_documents`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`document_id`) REFERENCES `documents`(`document_id`) ON DELETE CASCADE
        ");

        // service_domains
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `service_domains`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `service_domains`
            WHERE `domain_id` NOT IN (SELECT `domain_id` FROM `domains`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `service_domains`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`domain_id`) REFERENCES `domains`(`domain_id`) ON DELETE CASCADE
        ");

        // service_vendors
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `service_vendors`
            WHERE `service_id` NOT IN (SELECT `service_id` FROM `services`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `service_vendors`
            WHERE `vendor_id` NOT IN (SELECT `vendor_id` FROM `vendors`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `service_vendors`
            ADD FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`vendor_id`) ON DELETE CASCADE
        ");

        // software_contacts
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `software_contacts`
            WHERE `software_id` NOT IN (SELECT `software_id` FROM `software`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `software_contacts`
            WHERE `contact_id` NOT IN (SELECT `contact_id` FROM `contacts`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `software_contacts`
            ADD FOREIGN KEY (`software_id`) REFERENCES `software`(`software_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE
        ");

        // software_documents
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `software_documents`
            WHERE `software_id` NOT IN (SELECT `software_id` FROM `software`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `software_documents`
            WHERE `document_id` NOT IN (SELECT `document_id` FROM `documents`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `software_documents`
            ADD FOREIGN KEY (`software_id`) REFERENCES `software`(`software_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`document_id`) REFERENCES `documents`(`document_id`) ON DELETE CASCADE
        ");

        // software_files
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `software_files`
            WHERE `software_id` NOT IN (SELECT `software_id` FROM `software`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `software_files`
            WHERE `file_id` NOT IN (SELECT `file_id` FROM `files`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `software_files`
            ADD FOREIGN KEY (`software_id`) REFERENCES `software`(`software_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`file_id`) REFERENCES `files`(`file_id`) ON DELETE CASCADE
        ");

        // vendor_documents
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `vendor_documents`
            WHERE `vendor_id` NOT IN (SELECT `vendor_id` FROM `vendors`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `vendor_documents`
            WHERE `document_id` NOT IN (SELECT `document_id` FROM `documents`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `vendor_documents`
            ADD FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`vendor_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`document_id`) REFERENCES `documents`(`document_id`) ON DELETE CASCADE
        ");

        // vendor_files
        // Clean up orphaned rows
        mysqli_query($mysqli, "
            DELETE FROM `vendor_files`
            WHERE `vendor_id` NOT IN (SELECT `vendor_id` FROM `vendors`);
        ");
        mysqli_query($mysqli, "
            DELETE FROM `vendor_files`
            WHERE `file_id` NOT IN (SELECT `file_id` FROM `files`);
        ");
        // Add foreign key
        mysqli_query($mysqli, "
            ALTER TABLE `vendor_files`
            ADD FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`vendor_id`) ON DELETE CASCADE,
            ADD FOREIGN KEY (`file_id`) REFERENCES `files`(`file_id`) ON DELETE CASCADE
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.5') {

        // CONVERT All tables TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci

        $tables = [
            'accounts', 'api_keys', 'app_logs', 'asset_credentials', 'asset_custom', 'asset_documents',
            'asset_files', 'asset_history', 'asset_interface_links', 'asset_interfaces', 'asset_notes', 'assets',
            'auth_logs', 'budget', 'calendar_event_attendees', 'calendar_events', 'calendars', 'categories',
            'certificate_history', 'certificates', 'client_notes', 'client_stripe', 'client_tags', 'clients',
            'companies', 'contact_assets', 'contact_credentials', 'contact_documents', 'contact_files', 'contact_notes',
            'contact_tags', 'contacts', 'credential_tags', 'credentials', 'custom_fields', 'custom_links',
            'custom_values', 'document_files', 'documents', 'domain_history', 'domains', 'email_queue', 'expenses',
            'files', 'folders', 'history', 'invoice_items', 'invoices', 'location_tags', 'locations', 'logs',
            'modules', 'networks', 'notifications', 'payments', 'products', 'project_template_ticket_templates',
            'project_templates', 'projects', 'quote_files', 'quotes', 'rack_units', 'racks', 'records',
            'recurring_expenses', 'recurring_invoices', 'recurring_payments', 'recurring_ticket_assets', 'recurring_tickets',
            'remember_tokens', 'revenues', 'service_assets', 'service_certificates', 'service_contacts', 'service_credentials',
            'service_documents', 'service_domains', 'service_vendors', 'services', 'settings', 'shared_items',
            'software', 'software_assets', 'software_contacts', 'software_credentials', 'software_documents', 'software_files',
            'tags', 'task_templates', 'tasks', 'taxes', 'ticket_assets', 'ticket_attachments', 'ticket_history', 'ticket_replies',
            'ticket_statuses', 'ticket_templates', 'ticket_views', 'ticket_watchers', 'tickets', 'transfers', 'trips',
            'user_client_permissions', 'user_role_permissions', 'user_roles', 'user_settings', 'users', 'vendor_credentials',
            'vendor_documents', 'vendor_files', 'vendors'
        ];

        foreach ($tables as $table) {
            $sql = "ALTER TABLE `$table` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;";
            mysqli_query($mysqli, $sql);
        }


        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.6') {
        // Fix service_domains to yse InnoDB instead of MyISAM
        mysqli_query($mysqli, "ALTER TABLE service_domains ENGINE = InnoDB;");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.7') {

        mysqli_query($mysqli, "ALTER TABLE `files` DROP `file_hash`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.8'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.8') {

        mysqli_query($mysqli, "ALTER TABLE `files` DROP `file_has_thumbnail`");
        mysqli_query($mysqli, "ALTER TABLE `files` DROP `file_has_preview`");
        mysqli_query($mysqli, "ALTER TABLE `files` DROP `file_asset_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.0.9'");
    }

    if (CURRENT_DATABASE_VERSION == '2.0.9') {

        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_phone_country_code` VARCHAR(10) DEFAULT 1 AFTER `contact_email`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD `contact_mobile_country_code` VARCHAR(10) DEFAULT 1 AFTER `contact_extension`");

        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_phone_country_code` VARCHAR(10) DEFAULT 1 AFTER `location_zip`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_phone_extension` VARCHAR(10) DEFAULT NULL AFTER `location_phone`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_fax_country_code` VARCHAR(10) DEFAULT 1 AFTER `location_phone_extension`");

        mysqli_query($mysqli, "ALTER TABLE `vendors` ADD `vendor_phone_country_code` VARCHAR(10) DEFAULT 1 AFTER `vendor_contact_name`");

        mysqli_query($mysqli, "ALTER TABLE `companies` ADD `company_phone_country_code` VARCHAR(10) DEFAULT 1 AFTER `company_country`");


        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.0') {
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_signature` TEXT DEFAULT NULL AFTER `user_config_calendar_first_day`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.1') {
        mysqli_query($mysqli, "ALTER TABLE `settings` DROP `config_phone_mask`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.2'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.2') {

        // Update country_code to NULL for `contacts` table
        mysqli_query($mysqli, "ALTER TABLE `contacts` MODIFY `contact_phone_country_code` VARCHAR(10) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `contacts` MODIFY `contact_mobile_country_code` VARCHAR(10) DEFAULT NULL");

        // Update country_code to NULL for `locations` table
        mysqli_query($mysqli, "ALTER TABLE `locations` MODIFY `location_phone_country_code` VARCHAR(10) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `locations` MODIFY `location_fax_country_code` VARCHAR(10) DEFAULT NULL");

        // Update country_code to NULL for `vendors` table
        mysqli_query($mysqli, "ALTER TABLE `vendors` MODIFY `vendor_phone_country_code` VARCHAR(10) DEFAULT NULL");

        // Update country_code to NULL for `companies` table
        mysqli_query($mysqli, "ALTER TABLE `companies` MODIFY `company_phone_country_code` VARCHAR(10) DEFAULT NULL");

        // Set country_code to NULL for `contacts` table
        mysqli_query($mysqli, "UPDATE `contacts` SET `contact_phone_country_code` = NULL");
        mysqli_query($mysqli, "UPDATE `contacts` SET `contact_mobile_country_code` = NULL");

        // Set country_code to NULL for `locations` table
        mysqli_query($mysqli, "UPDATE `locations` SET `location_phone_country_code` = NULL");
        mysqli_query($mysqli, "UPDATE `locations` SET `location_fax_country_code` = NULL");

        // Set country_code to NULL for `vendors` table
        mysqli_query($mysqli, "UPDATE `vendors` SET `vendor_phone_country_code` = NULL");

        // Set country_code to NULL for `companies` table
        mysqli_query($mysqli, "UPDATE `companies` SET `company_phone_country_code` = NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.3') {
        mysqli_query($mysqli, "ALTER TABLE `client_stripe` ADD `stripe_pm_details` VARCHAR(200) DEFAULT NULL AFTER `stripe_pm`");
        mysqli_query($mysqli, "ALTER TABLE `client_stripe` ADD `stripe_pm_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `stripe_pm_details`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.4'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.4') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_ticket_timer_autostart` TINYINT(1) NOT NULL DEFAULT '0' AFTER `config_ticket_default_billable`");
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_due_at` DATETIME DEFAULT NULL AFTER `ticket_updated_at`");
        mysqli_query($mysqli, "ALTER TABLE `companies` ADD `company_tax_id` VARCHAR(200) DEFAULT NULL AFTER `company_currency`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_invoice_show_tax_id` TINYINT(1) NOT NULL DEFAULT '0' AFTER `config_invoice_paid_notification_email`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.5') {

        mysqli_query($mysqli, "CREATE TABLE `document_versions` (
            `document_version_id` INT(11) NOT NULL AUTO_INCREMENT,
            `document_version_name` VARCHAR(200) NOT NULL,
            `document_version_description` TEXT DEFAULT NULL,
            `document_version_content` LONGTEXT NOT NULL,
            `document_version_created_by` INT(11) DEFAULT 0,
            `document_version_created_at` DATETIME NOT NULL,
            `document_version_document_id` INT(11) NOT NULL,
            PRIMARY KEY (`document_version_id`)
        )");

        // Delete all Current Document Versions
        mysqli_query($mysqli, "
            DELETE FROM `documents`
            WHERE `document_parent` > 0 AND `document_parent` != `document_id`
        ");

        mysqli_query($mysqli, "ALTER TABLE `documents` DROP `document_parent`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.6') {
        mysqli_query($mysqli, "CREATE TABLE `document_templates` (
            `document_template_id` INT(11) NOT NULL AUTO_INCREMENT,
            `document_template_name` VARCHAR(200) NOT NULL,
            `document_template_description` TEXT DEFAULT NULL,
            `document_template_content` LONGTEXT NOT NULL,
            `document_template_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `document_template_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `document_template_archived_at` DATETIME NULL DEFAULT NULL,
            `document_template_created_by` INT(11) NOT NULL DEFAULT 0,
            `document_template_updated_by` INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`document_template_id`)
        )");

        // Copy Document Templates over to new document templates table
        mysqli_query($mysqli, "
            INSERT INTO document_templates (
                document_template_name,
                document_template_description,
                document_template_content,
                document_template_created_at,
                document_template_updated_at,
                document_template_archived_at,
                document_template_created_by,
                document_template_updated_by
            )
            SELECT
                document_name,
                document_description,
                document_content,
                document_created_at,
                document_updated_at,
                document_archived_at,
                document_created_by,
                document_updated_by
            FROM
                documents
            WHERE
                document_template = 1
        ");

        mysqli_query($mysqli, "DELETE FROM documents WHERE document_template = 1");

        mysqli_query($mysqli, "ALTER TABLE `documents` DROP `document_template`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.7') {
        mysqli_query($mysqli, "CREATE TABLE `software_templates` (
            `software_template_id` INT(11) NOT NULL AUTO_INCREMENT,
            `software_template_name` VARCHAR(200) NOT NULL,
            `software_template_description` TEXT DEFAULT NULL,
            `software_template_version` VARCHAR(200) DEFAULT NULL,
            `software_template_type` VARCHAR(200) NOT NULL,
            `software_template_license_type` VARCHAR(200) DEFAULT NULL,
            `software_template_notes` TEXT DEFAULT NULL,
            `software_template_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `software_template_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `software_template_archived_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`software_template_id`)
        )");

        // Copy software Templates over to new software templates table
        mysqli_query($mysqli, "
            INSERT INTO software_templates (
                software_template_name,
                software_template_description,
                software_template_version,
                software_template_type,
                software_template_license_type,
                software_template_notes,
                software_template_created_at,
                software_template_updated_at,
                software_template_archived_at
            )
            SELECT
                software_name,
                software_description,
                software_version,
                software_type,
                software_license_type,
                software_notes,
                software_created_at,
                software_updated_at,
                software_archived_at
            FROM
                software
            WHERE
                software_template = 1
        ");

        mysqli_query($mysqli, "DELETE FROM software WHERE software_template = 1");

        mysqli_query($mysqli, "ALTER TABLE `software` DROP `software_template`");

        mysqli_query($mysqli, "ALTER TABLE `software` DROP `software_template_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.8'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.8') {
        mysqli_query($mysqli, "CREATE TABLE `vendor_templates` (
            `vendor_template_id` INT(11) NOT NULL AUTO_INCREMENT,
            `vendor_template_name` VARCHAR(200) NOT NULL,
            `vendor_template_description` VARCHAR(200) DEFAULT NULL,
            `vendor_template_contact_name` VARCHAR(200) DEFAULT NULL,
            `vendor_template_phone_country_code` VARCHAR(10) DEFAULT NULL,
            `vendor_template_phone` VARCHAR(200) DEFAULT NULL,
            `vendor_template_extension` VARCHAR(200) DEFAULT NULL,
            `vendor_template_email` VARCHAR(200) DEFAULT NULL,
            `vendor_template_website` VARCHAR(200) DEFAULT NULL,
            `vendor_template_hours` VARCHAR(200) DEFAULT NULL,
            `vendor_template_sla` VARCHAR(200) DEFAULT NULL,
            `vendor_template_code` VARCHAR(200) DEFAULT NULL,
            `vendor_template_account_number` VARCHAR(200) DEFAULT NULL,
            `vendor_template_notes` TEXT DEFAULT NULL,
            `vendor_template_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `vendor_template_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `vendor_template_archived_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`vendor_template_id`)
        )");

        // Copy Vendor Templates over to new vendor templates table
        mysqli_query($mysqli, "
            INSERT INTO vendor_templates (
                vendor_template_name,
                vendor_template_description,
                vendor_template_contact_name,
                vendor_template_phone_country_code,
                vendor_template_phone,
                vendor_template_extension,
                vendor_template_email,
                vendor_template_website,
                vendor_template_hours,
                vendor_template_sla,
                vendor_template_code,
                vendor_template_account_number,
                vendor_template_notes,
                vendor_template_created_at,
                vendor_template_updated_at,
                vendor_template_archived_at
            )
            SELECT
                vendor_name,
                vendor_description,
                vendor_contact_name,
                vendor_phone_country_code,
                vendor_phone,
                vendor_extension,
                vendor_email,
                vendor_website,
                vendor_hours,
                vendor_sla,
                vendor_code,
                vendor_account_number,
                vendor_notes,
                vendor_created_at,
                vendor_updated_at,
                vendor_archived_at
            FROM
                vendors
            WHERE
                vendor_template = 1
        ");

        mysqli_query($mysqli, "DELETE FROM vendors WHERE vendor_template = 1");

        mysqli_query($mysqli, "ALTER TABLE `vendors` DROP `vendor_template`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.1.9'");
    }

    if (CURRENT_DATABASE_VERSION == '2.1.9') {
        mysqli_query($mysqli, "ALTER TABLE `companies` MODIFY `company_currency` VARCHAR(200) DEFAULT 'USD'");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.0') {
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD `ticket_quote_id` INT(11) NOT NULL DEFAULT 0 AFTER `ticket_asset_id`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.1') {
        mysqli_query($mysqli, "CREATE TABLE `ai_providers` (
            `ai_provider_id` INT(11) NOT NULL AUTO_INCREMENT,
            `ai_provider_name` VARCHAR(200) NOT NULL,
            `ai_provider_api_url` VARCHAR(200) NOT NULL,
            `ai_provider_api_key` VARCHAR(200) DEFAULT NULL,
            `ai_provider_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ai_provider_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ai_provider_id`)
        )");

        mysqli_query($mysqli, "
            CREATE TABLE `ai_models` (
                `ai_model_id` INT(11) NOT NULL AUTO_INCREMENT,
                `ai_model_name` VARCHAR(200) NOT NULL,
                `ai_model_prompt` TEXT DEFAULT NULL,
                `ai_model_use_case` VARCHAR(200) DEFAULT NULL,
                `ai_model_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ai_model_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                `ai_model_ai_provider_id` INT(11) NOT NULL,
                PRIMARY KEY (`ai_model_id`),
                FOREIGN KEY (`ai_model_ai_provider_id`)
                    REFERENCES `ai_providers`(`ai_provider_id`)
                    ON DELETE CASCADE
            )
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.2'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.2') {
        mysqli_query($mysqli, "CREATE TABLE `payment_methods` (
            `payment_method_id` INT(11) NOT NULL AUTO_INCREMENT,
            `payment_method_name` VARCHAR(200) NOT NULL,
            `payment_method_description` VARCHAR(250) DEFAULT NULL,
            `payment_method_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `payment_method_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`payment_method_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `payment_providers` (
            `payment_provider_id` INT(11) NOT NULL AUTO_INCREMENT,
            `payment_provider_name` VARCHAR(200) NOT NULL,
            `payment_provider_description` VARCHAR(250) DEFAULT NULL,
            `payment_provider_public_key` VARCHAR(250) DEFAULT NULL,
            `payment_provider_private_key` VARCHAR(250) DEFAULT NULL,
            `payment_provider_threshold` DECIMAL(15,2) DEFAULT NULL,
            `payment_provider_active` TINYINT(1) NOT NULL DEFAULT 1,
            `payment_provider_account` INT(11) NOT NULL,
            `payment_provider_expense_vendor` INT(11) NOT NULL DEFAULT 0,
            `payment_provider_expense_category` INT(11) NOT NULL DEFAULT 0,
            `payment_provider_expense_percentage_fee` DECIMAL(4,4) DEFAULT NULL,
            `payment_provider_expense_flat_fee` DECIMAL(15,2) DEFAULT NULL,
            `payment_provider_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `payment_provider_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`payment_provider_id`)
        )");

        mysqli_query($mysqli, "CREATE TABLE `client_saved_payment_methods` (
            `saved_payment_id` INT(11) NOT NULL AUTO_INCREMENT,
            `saved_payment_provider_method` VARCHAR(200) NOT NULL,
            `saved_payment_description` VARCHAR(200) DEFAULT NULL,
            `saved_payment_client_id` INT(11) NOT NULL,
            `saved_payment_provider_id` INT(11) NOT NULL,
            `saved_payment_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `saved_payment_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`saved_payment_id`),
            FOREIGN KEY (`saved_payment_client_id`) REFERENCES clients(`client_id`) ON DELETE CASCADE,
            FOREIGN KEY (`saved_payment_provider_id`) REFERENCES payment_providers(`payment_provider_id`) ON DELETE CASCADE
        )");

        mysqli_query($mysqli, "CREATE TABLE `client_payment_provider` (
            `client_id` INT(11) NOT NULL,
            `payment_provider_id` INT(11) NOT NULL,
            `payment_provider_client` VARCHAR(200) NOT NULL,
            `client_payment_provider_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`client_id`, `payment_provider_id`),
            FOREIGN KEY (`client_id`) REFERENCES clients(`client_id`) ON DELETE CASCADE,
            FOREIGN KEY (`payment_provider_id`) REFERENCES payment_providers(`payment_provider_id`) ON DELETE CASCADE
        )");

        mysqli_query($mysqli, "ALTER TABLE `recurring_payments` ADD `recurring_payment_saved_payment_id` INT(11) DEFAULT NULL AFTER `recurring_payment_recurring_invoice_id`");

        mysqli_query($mysqli, "ALTER TABLE `recurring_payments` ADD CONSTRAINT `fk_recurring_saved_payment` FOREIGN KEY (`recurring_payment_saved_payment_id`) REFERENCES `client_saved_payment_methods`(`saved_payment_id`) ON DELETE CASCADE");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.3') {

        mysqli_query($mysqli, "CREATE TABLE `credits` (
            `credit_id` INT(11) NOT NULL AUTO_INCREMENT,
            `credit_amount` DECIMAL(15,2) NOT NULL,
            `credit_reference` VARCHAR(250) DEFAULT NULL,
            `credit_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `credit_created_by` INT(11) NOT NULL,
            `credit_expire_at` DATE DEFAULT NULL,
            `credit_client_id` INT(11) NOT NULL,
            PRIMARY KEY (`credit_id`)
        )");

        mysqli_query($mysqli, "ALTER TABLE `invoices` ADD `invoice_credit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `invoice_discount_amount`");

        mysqli_query($mysqli, "CREATE TABLE `discount_codes` (
            `discount_code_id` INT(11) NOT NULL AUTO_INCREMENT,
            `discount_code_description` VARCHAR(250) DEFAULT NULL,
            `discount_code_amount` DECIMAL(15,2) NOT NULL,
            `discount_code` VARCHAR(200) NOT NULL,
            `discount_code_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `discount_code_created_by` INT(11) NOT NULL,
            `discount_code_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `discount_code_archived_at` DATETIME NULL DEFAULT NULL,
            `discount_code_expire_at` DATE DEFAULT NULL,
            PRIMARY KEY (`discount_code_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.4'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.4') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_theme_dark` TINYINT(1) NOT NULL DEFAULT 0 AFTER `config_theme`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.5') {
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_uri_client` VARCHAR(500) NULL DEFAULT NULL AFTER `asset_uri_2`");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.6') {
        mysqli_query($mysqli, "ALTER TABLE `credits` DROP `credit_reference`");
        mysqli_query($mysqli, "ALTER TABLE `credits` ADD `credit_type` ENUM('prepaid', 'manual', 'refund', 'promotion', 'usage') NOT NULL DEFAULT 'manual' AFTER `credit_amount`");
        mysqli_query($mysqli, "ALTER TABLE `credits` ADD `credit_note` TEXT NULL DEFAULT NULL AFTER `credit_type`");
        mysqli_query($mysqli, "ALTER TABLE `credits` ADD `credit_invoice_id` INT(11) NULL DEFAULT NULL AFTER `credit_expire_at`");
        mysqli_query($mysqli, "ALTER TABLE `credits` ADD INDEX (`credit_client_id`)");
        mysqli_query($mysqli, "ALTER TABLE `credits` ADD INDEX (`credit_invoice_id`)");
        mysqli_query($mysqli, "ALTER TABLE `credits` ADD INDEX (`credit_created_at`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.7') {
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD `user_config_theme_dark` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_config_signature`");
        mysqli_query($mysqli, "ALTER TABLE `settings` DROP `config_theme_dark`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.8'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.8') {

        mysqli_query($mysqli, "ALTER TABLE `products` ADD `product_type` ENUM('service', 'product') NOT NULL DEFAULT 'service' AFTER `product_name`");
        mysqli_query($mysqli, "ALTER TABLE `products` ADD `product_code` VARCHAR(200) DEFAULT NULL AFTER `product_description`");
        mysqli_query($mysqli, "ALTER TABLE `products` ADD `product_location` VARCHAR(250) DEFAULT NULL AFTER `product_code`");

        mysqli_query($mysqli, "CREATE TABLE `product_stock` (
            `stock_id` INT(11) NOT NULL AUTO_INCREMENT,
            `stock_qty` INT(11) NOT NULL,
            `stock_note` TEXT DEFAULT NULL,
            `stock_created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP(),
            `stock_expense_id` INT(11) DEFAULT NULL,
            `stock_item_id` INT(11) DEFAULT NULL,
            `stock_product_id` INT(11) NOT NULL,
            PRIMARY KEY (`stock_id`)
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.2.9'");
    }

    if (CURRENT_DATABASE_VERSION == '2.2.9') {
        // Migrate Stripe Settings over to new Tables

        // Get Current Stripe Settings
        $sql_stripe_settings = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
        $row = mysqli_fetch_assoc($sql_stripe_settings);
        $config_stripe_enable = intval($row['config_stripe_enable']);
        if ($config_stripe_enable === 1) {
            $config_stripe_publishable = mysqli_real_escape_string($mysqli, $row['config_stripe_publishable']);
            $config_stripe_secret      = mysqli_real_escape_string($mysqli, $row['config_stripe_secret']);
            $config_stripe_account     = intval($row['config_stripe_account']);
            $config_stripe_expense_vendor   = intval($row['config_stripe_expense_vendor']);
            $config_stripe_expense_category = intval($row['config_stripe_expense_category']);
            $config_stripe_percentage_fee   = floatval($row['config_stripe_percentage_fee']);
            $config_stripe_flat_fee         = floatval($row['config_stripe_flat_fee']);

            mysqli_query($mysqli,"INSERT INTO payment_providers SET
                payment_provider_name = 'Stripe',
                payment_provider_public_key = '$config_stripe_publishable',
                payment_provider_private_key = '$config_stripe_secret',
                payment_provider_account = $config_stripe_account,
                payment_provider_expense_vendor = $config_stripe_expense_vendor,
                payment_provider_expense_category = $config_stripe_expense_category,
                payment_provider_expense_percentage_fee = $config_stripe_percentage_fee,
                payment_provider_expense_flat_fee = $config_stripe_flat_fee"
            );

            $provider_id = mysqli_insert_id($mysqli);

            // Migrate Clients and Payment Method over
            $sql_stripe_clients = mysqli_query($mysqli, "SELECT * FROM client_stripe WHERE stripe_pm IS NOT NULL AND stripe_pm != ''");
            while ($row = mysqli_fetch_assoc($sql_stripe_clients)) {
                $client_id = intval($row['client_id']);
                $stripe_id = mysqli_real_escape_string($mysqli, $row['stripe_id']);
                $stripe_pm = mysqli_real_escape_string($mysqli, $row['stripe_pm']);
                $stripe_pm_details = mysqli_real_escape_string($mysqli, $row['stripe_pm_details'] ?? 'Saved Card');

                mysqli_query($mysqli,"INSERT INTO client_payment_provider SET
                    client_id = $client_id,
                    payment_provider_id = $provider_id,
                    payment_provider_client = '$stripe_id'"
                );

                mysqli_query($mysqli,"INSERT INTO client_saved_payment_methods SET
                    saved_payment_provider_method = '$stripe_pm',
                    saved_payment_description = '$stripe_pm_details',
                    saved_payment_client_id = $client_id,
                    saved_payment_provider_id = $provider_id"
                );
            }
        }

        // Get Stripe provider id
        $res = mysqli_query($mysqli, "
            SELECT payment_provider_id
            FROM payment_providers
            WHERE payment_provider_name = 'Stripe'
            ORDER BY payment_provider_id DESC
            LIMIT 1
        ");
        $stripe = mysqli_fetch_assoc($res);
        $stripe_provider_id = intval($stripe['payment_provider_id']);

        // Correct mapping: RP -> Recurring Invoice -> Client -> Client's Stripe saved method
        mysqli_query($mysqli, "
            UPDATE recurring_payments rp
            INNER JOIN recurring_invoices ri
                ON ri.recurring_invoice_id = rp.recurring_payment_recurring_invoice_id
            INNER JOIN client_saved_payment_methods spm
                ON spm.saved_payment_client_id = ri.recurring_invoice_client_id
               AND spm.saved_payment_provider_id = $stripe_provider_id
            SET
                rp.recurring_payment_method = 'Credit Card',
                rp.recurring_payment_saved_payment_id = spm.saved_payment_id
            WHERE rp.recurring_payment_method = 'Stripe'
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.0') {
        // Migrate Payment Methods from Categories Table to new payment_methods table
        $sql_categories = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Payment Method' AND category_name != 'Stripe' AND category_archived_at IS NULL");

        while ($row = mysqli_fetch_assoc($sql_categories)) {
            $category_name = sanitizeInput($row['category_name']);

            mysqli_query($mysqli,"INSERT INTO payment_methods SET payment_method_name = '$category_name'");
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.1') {

        // Delete all Recurring Payments that are Stripe
        mysqli_query($mysqli, "DELETE FROM recurring_payments WHERE recurring_payment_method = 'Stripe'");

        // Delete Stripe Specific ITFlow Client Stripe Client Relationship Table
        mysqli_query($mysqli, "DROP TABLE client_stripe");

        // Delete Unused Stripe and AI Settings now in their own tables
        mysqli_query($mysqli, "ALTER TABLE `settings`
            DROP `config_stripe_enable`,
            DROP `config_stripe_publishable`,
            DROP `config_stripe_secret`,
            DROP `config_stripe_account`,
            DROP `config_stripe_expense_vendor`,
            DROP `config_stripe_expense_category`,
            DROP `config_stripe_percentage_fee`,
            DROP `config_stripe_flat_fee`,
            DROP `config_ai_enable`,
            DROP `config_ai_provider`,
            DROP `config_ai_model`,
            DROP `config_ai_url`,
            DROP `config_ai_api_key`
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.2'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.2') {

        mysqli_query($mysqli, "ALTER TABLE settings
            ADD `config_imap_provider` ENUM('standard_imap','google_oauth','microsoft_oauth') NULL DEFAULT NULL AFTER `config_mail_from_name`,
            ADD `config_mail_oauth_client_id` VARCHAR(255) NULL AFTER `config_imap_provider`,
            ADD `config_mail_oauth_client_secret` VARCHAR(255) NULL AFTER `config_mail_oauth_client_id`,
            ADD `config_mail_oauth_tenant_id` VARCHAR(255) NULL AFTER `config_mail_oauth_client_secret`,
            ADD `config_mail_oauth_refresh_token` TEXT NULL AFTER `config_mail_oauth_tenant_id`,
            ADD `config_mail_oauth_access_token` TEXT NULL AFTER `config_mail_oauth_refresh_token`,
            ADD `config_mail_oauth_access_token_expires_at` DATETIME NULL AFTER `config_mail_oauth_access_token`
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.3') {

        mysqli_query($mysqli, "ALTER TABLE settings
            ADD `config_smtp_provider` ENUM('standard_smtp','google_oauth','microsoft_oauth') NULL DEFAULT NULL AFTER `config_start_page`
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.4'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.4') {

        // Add Software Keys
        mysqli_query($mysqli, "CREATE TABLE `software_keys` (
            `software_key_id` INT(11) NOT NULL AUTO_INCREMENT,
            `software_key` VARCHAR(400) NOT NULL,
            `software_key_software_id` INT(11) NOT NULL,
            PRIMARY KEY (`software_key_id`),
            FOREIGN KEY (`software_key_software_id`) REFERENCES `software`(`software_id`) ON DELETE CASCADE
        )");

        // Software Key Assignments to Contacts
        mysqli_query($mysqli, "CREATE TABLE `software_key_contact_assignments` (
            `software_key_id` INT(11) NOT NULL,
            `contact_id` INT(11) NOT NULL,
            `software_key_assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`software_key_id`, `contact_id`),
            FOREIGN KEY (`software_key_id`) REFERENCES `software_keys`(`software_key_id`) ON DELETE CASCADE,
            FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`contact_id`) ON DELETE CASCADE
        )");

        // Software Key Assignments to Assets
        mysqli_query($mysqli, "CREATE TABLE `software_key_asset_assignments` (
            `software_key_id` INT(11) NOT NULL,
            `asset_id` INT(11) NOT NULL,
            `software_key_assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`software_key_id`, `asset_id`),
            FOREIGN KEY (`software_key_id`) REFERENCES `software_keys`(`software_key_id`) ON DELETE CASCADE,
            FOREIGN KEY (`asset_id`) REFERENCES `assets`(`asset_id`) ON DELETE CASCADE
        )");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.5') {
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_smtp_provider` `config_smtp_provider` VARCHAR(200) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `settings` CHANGE `config_imap_provider` `config_imap_provider` VARCHAR(200) DEFAULT NULL");
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.6') {
        // Create New Contract Templates Table
        mysqli_query($mysqli, "CREATE TABLE `contract_templates` (
          `contract_template_id` INT(11) AUTO_INCREMENT PRIMARY KEY,
          `contract_template_name` VARCHAR(255) NOT NULL,
          `contract_template_description` TEXT NULL DEFAULT NULL,
          `contract_template_type` VARCHAR(50) NULL DEFAULT NULL,

          `contract_template_sla_low_response_time` INT(11) NULL DEFAULT NULL,
          `contract_template_sla_low_resolution_time` INT(11) NULL DEFAULT NULL,
          `contract_template_sla_medium_response_time` INT(11) NULL DEFAULT NULL,
          `contract_template_sla_medium_resolution_time` INT(11) NULL DEFAULT NULL,
          `contract_template_sla_high_response_time` INT(11) NULL DEFAULT NULL,
          `contract_template_sla_high_resolution_time` INT(11) NULL DEFAULT NULL,

          `contract_template_rate_standard` DECIMAL(10,2) NULL DEFAULT NULL,
          `contract_template_rate_after_hours` DECIMAL(10,2) NULL DEFAULT NULL,

          `contract_template_net_terms` VARCHAR(50) NULL DEFAULT NULL,
          `contract_template_support_hours` VARCHAR(100) NULL DEFAULT NULL,
          `contract_template_renewal_frequency` VARCHAR(50) NULL DEFAULT NULL,

          `contract_template_details` TEXT NULL DEFAULT NULL,

          `contract_template_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `contract_template_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
          `contract_template_archived_at` DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");


        // Create New Contracts Table
        mysqli_query($mysqli, "CREATE TABLE `contracts` (
            `contract_id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `contract_name` VARCHAR(255) NOT NULL,
            `contract_status` VARCHAR(50) NOT NULL,
            `contract_type` VARCHAR(50) NOT NULL,

            `contract_sla_low_response_time` INT(11) NULL DEFAULT NULL,
            `contract_sla_low_resolution_time` INT(11) NULL DEFAULT NULL,
            `contract_sla_medium_response_time` INT(11) NULL DEFAULT NULL,
            `contract_sla_medium_resolution_time` INT(11) NULL DEFAULT NULL,
            `contract_sla_high_response_time` INT(11) NULL DEFAULT NULL,
            `contract_sla_high_resolution_time` INT(11) NULL DEFAULT NULL,

            `contract_details` TEXT NULL DEFAULT NULL,

            `contract_client_id` INT(11) NULL DEFAULT NULL,
            `contract_client_name` VARCHAR(255) NULL DEFAULT NULL,
            `contract_client_address` TEXT NULL DEFAULT NULL,
            `contract_client_email` VARCHAR(255) NULL DEFAULT NULL,
            `contract_client_phone` VARCHAR(100) NULL DEFAULT NULL,

            `contract_contact_name` VARCHAR(255) NULL DEFAULT NULL,
            `contract_contact_signature` TEXT NULL DEFAULT NULL,
            `contract_contact_signature_date` DATETIME NULL DEFAULT NULL,

            `contract_agent_name` VARCHAR(255) NULL DEFAULT NULL,
            `contract_agent_signature` TEXT NULL DEFAULT NULL,
            `contract_agent_signature_date` DATETIME NULL DEFAULT NULL,

            `contract_rate_standard` DECIMAL(10,2) NULL DEFAULT NULL,
            `contract_rate_after_hours` DECIMAL(10,2) NULL DEFAULT NULL,

            `contract_net_terms` VARCHAR(50) NULL DEFAULT NULL,
            `contract_support_hours` VARCHAR(100) NULL DEFAULT NULL,

            `contract_start_date` DATE NULL DEFAULT NULL,
            `contract_end_date` DATE NULL DEFAULT NULL,
            `contract_renewal_frequency` VARCHAR(50) NULL DEFAULT NULL,

            `contract_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `contract_updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            `contract_archived_at` DATETIME NULL DEFAULT NULL,

            FOREIGN KEY (`contract_client_id`) REFERENCES `clients`(`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.3.7') {

        mysqli_query($mysqli, "
            CREATE TABLE `asset_tags` (
                `asset_tag_asset_id` INT(11) NOT NULL,
                `asset_tag_tag_id` INT(11) NOT NULL,
                PRIMARY KEY (`asset_tag_asset_id`, `asset_tag_tag_id`),
                CONSTRAINT `fk_asset`
                    FOREIGN KEY (`asset_tag_asset_id`)
                    REFERENCES `assets`(`asset_id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_tag`
                    FOREIGN KEY (`asset_tag_tag_id`)
                    REFERENCES `tags`(`tag_id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.8'");
    }

     if (CURRENT_DATABASE_VERSION == '2.3.8') {

         mysqli_query($mysqli, "
            CREATE TABLE `task_approvals` (
              `approval_id` int(11) NOT NULL AUTO_INCREMENT,
              `approval_scope` enum('client','internal') NOT NULL,
              `approval_type` enum('any','technical','billing','specific') NOT NULL,
              `approval_required_user_id` int(11) DEFAULT NULL,
              `approval_status` enum('pending','approved','declined') NOT NULL,
              `approval_created_by` int(11) NOT NULL,
              `approval_approved_by` varchar(255) DEFAULT NULL,
              `approval_url_key` varchar(200) NOT NULL,
              `approval_task_id` int(11) NOT NULL,
              PRIMARY KEY (`approval_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

         mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.3.9'");
     }

    if (CURRENT_DATABASE_VERSION == '2.3.9') {
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD `client_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `client_notes`");

        mysqli_query($mysqli, "ALTER TABLE `locations` ADD `location_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `location_notes`");

        mysqli_query($mysqli, "ALTER TABLE `vendors` ADD `vendor_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `vendor_notes`");

        mysqli_query($mysqli, "ALTER TABLE `software` ADD `software_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `software_notes`");

        mysqli_query(
            $mysqli,
            "ALTER TABLE `credentials`
             CHANGE `credential_important` `credential_favorite`
             TINYINT(1) NOT NULL DEFAULT 0
             AFTER `credential_note`"
        );

        mysqli_query($mysqli, "ALTER TABLE `assets` DROP `asset_important`");
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `asset_notes`");

        mysqli_query($mysqli, "ALTER TABLE `documents` DROP `document_important`");
        mysqli_query($mysqli, "ALTER TABLE `documents` ADD `document_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `document_client_visible`");

        mysqli_query($mysqli, "ALTER TABLE `racks` ADD `rack_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `rack_notes`");

        mysqli_query($mysqli, "ALTER TABLE `files` DROP `file_important`");
        mysqli_query($mysqli, "ALTER TABLE `files` ADD `file_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `file_mime_type`");

        mysqli_query($mysqli, "ALTER TABLE `networks` ADD `network_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `network_notes`");

        mysqli_query($mysqli, "ALTER TABLE `domains` ADD `domain_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `domain_notes`");

        mysqli_query($mysqli, "ALTER TABLE `certificates` ADD `certificate_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `certificate_notes`");

        mysqli_query($mysqli, "ALTER TABLE `services` ADD `service_favorite` TINYINT(1) NOT NULL DEFAULT '0' AFTER `service_notes`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.0') {

        mysqli_query($mysqli, "
            CREATE TABLE `quote_items` (
              `item_id` int(11) NOT NULL AUTO_INCREMENT,
              `item_name` varchar(200) NOT NULL,
              `item_description` text DEFAULT NULL,
              `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_order` int(11) NOT NULL DEFAULT 0,
              `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              `item_archived_at` datetime DEFAULT NULL,
              `item_tax_id` int(11) NOT NULL DEFAULT 0,
              `item_product_id` int(11) NOT NULL DEFAULT 0,
              `item_quote_id` int(11) NOT NULL,
              PRIMARY KEY (`item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        mysqli_query($mysqli, "
            CREATE TABLE `recurring_invoice_items` (
              `item_id` int(11) NOT NULL AUTO_INCREMENT,
              `item_name` varchar(200) NOT NULL,
              `item_description` text DEFAULT NULL,
              `item_quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_price` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_tax` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_total` decimal(15,2) NOT NULL DEFAULT 0.00,
              `item_order` int(11) NOT NULL DEFAULT 0,
              `item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              `item_archived_at` datetime DEFAULT NULL,
              `item_tax_id` int(11) NOT NULL DEFAULT 0,
              `item_product_id` int(11) NOT NULL DEFAULT 0,
              `item_recurring_invoice_id` int(11) NOT NULL,
              PRIMARY KEY (`item_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.1') {

        // Migrate Items
        mysqli_query($mysqli, "
            INSERT INTO `recurring_invoice_items` (
              `item_name`,
              `item_description`,
              `item_quantity`,
              `item_price`,
              `item_subtotal`,
              `item_tax`,
              `item_total`,
              `item_order`,
              `item_created_at`,
              `item_updated_at`,
              `item_archived_at`,
              `item_tax_id`,
              `item_product_id`,
              `item_recurring_invoice_id`
            )
            SELECT
              `item_name`,
              `item_description`,
              `item_quantity`,
              `item_price`,
              `item_subtotal`,
              `item_tax`,
              `item_total`,
              `item_order`,
              `item_created_at`,
              `item_updated_at`,
              `item_archived_at`,
              `item_tax_id`,
              `item_product_id`,
              `item_recurring_invoice_id`
            FROM `invoice_items`
            WHERE `item_recurring_invoice_id` != 0
        ");

        mysqli_query($mysqli, "
            INSERT INTO `quote_items` (
              `item_name`,
              `item_description`,
              `item_quantity`,
              `item_price`,
              `item_subtotal`,
              `item_tax`,
              `item_total`,
              `item_order`,
              `item_created_at`,
              `item_updated_at`,
              `item_archived_at`,
              `item_tax_id`,
              `item_product_id`,
              `item_quote_id`
            )
            SELECT
              `item_name`,
              `item_description`,
              `item_quantity`,
              `item_price`,
              `item_subtotal`,
              `item_tax`,
              `item_total`,
              `item_order`,
              `item_created_at`,
              `item_updated_at`,
              `item_archived_at`,
              `item_tax_id`,
              `item_product_id`,
              `item_quote_id`
            FROM `invoice_items`
            WHERE `item_quote_id` != 0
        ");

        mysqli_query($mysqli, "
            DELETE FROM `invoice_items`
            WHERE `item_recurring_invoice_id` != 0
        ");

        mysqli_query($mysqli, "
            DELETE FROM `invoice_items`
            WHERE `item_quote_id` != 0
        ");

        mysqli_query($mysqli, "
            ALTER TABLE `invoice_items`
            DROP COLUMN `item_quote_id`,
            DROP COLUMN `item_recurring_invoice_id`
        ");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.2'");

    }

    if (CURRENT_DATABASE_VERSION == '2.4.2') {

        mysqli_query($mysqli, "ALTER TABLE `categories` ADD `category_description` VARCHAR(255) DEFAULT NULL AFTER `category_name`");
        mysqli_query($mysqli, "ALTER TABLE `categories` ADD `category_order` INT(11) NOT NULL DEFAULT 0 AFTER `category_icon`");

        // Create network_interfaces
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Ethernet', category_type = 'network_interface', category_order = 1"); // 1
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'SFP', category_type = 'network_interface', category_order = 2"); // 2
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'SFP+', category_type = 'network_interface', category_order = 3"); // 3
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'QSFP28', category_type = 'network_interface', category_order = 4"); // 4
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'QSFP-DD', category_type = 'network_interface', category_order = 5"); // 5
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Coaxial', category_type = 'network_interface', category_order = 6"); // 6
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Fiber', category_type = 'network_interface', category_order = 7"); // 7
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'WiFi', category_type = 'network_interface', category_order = 8"); // 8



        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.3') {
        // Asset Status
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Ready to Deploy', category_description = 'Asset is configured and ready to be assigned', category_type = 'asset_status', category_order = 1"); // 1
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Deployed', category_description = 'Asset is actively in use and assigned to a client or location', category_type = 'asset_status', category_order = 2"); // 2
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Out for Repair', category_description = 'Asset has been sent out for servicing or repair', category_type = 'asset_status', category_order = 3"); // 3
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Lost', category_description = 'Asset location is unknown and cannot be accounted for', category_type = 'asset_status', category_order = 4"); // 4
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Stolen', category_description = 'Asset has been reported stolen', category_type = 'asset_status', category_order = 5"); // 5
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Retired', category_description = 'Asset has been decommissioned and is no longer in service', category_type = 'asset_status', category_order = 6"); // 6

        // Contact note types
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Call', category_description = 'Phone call with a client or contact', category_icon = 'fa-phone-alt', category_type = 'contact_note_type', category_order = 1"); // 1
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Email', category_description = 'Email correspondence with a client or contact', category_icon = 'fa-envelope', category_type = 'contact_note_type', category_order = 2"); // 2
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Meeting', category_description = 'Scheduled meeting with a client or contact', category_icon = 'fa-handshake', category_type = 'contact_note_type', category_order = 3"); // 3
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'In Person', category_description = 'In person visit or on-site interaction', category_icon = 'fa-people-arrows', category_type = 'contact_note_type', category_order = 4"); // 4
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Note', category_description = 'General note or internal comment', category_icon = 'fa-sticky-note', category_type = 'contact_note_type', category_order = 5"); // 5

        // Rack Types
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = '2-Post Open Frame', category_description = 'Two-post open frame rack for patch panels and lightweight equipment', category_type = 'rack_type', category_order = 1"); // 1
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = '4-Post Open Frame', category_description = 'Four-post open frame rack for servers and heavier equipment', category_type = 'rack_type', category_order = 2"); // 2
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = '4-Post Enclosed Cabinet', category_description = 'Four-post enclosed cabinet with doors and sides for secure equipment housing', category_type = 'rack_type', category_order = 3"); // 3
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Wall-Mount Open', category_description = 'Open frame rack mounted directly to a wall for small deployments', category_type = 'rack_type', category_order = 4"); // 4
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Wall-Mount Enclosed', category_description = 'Enclosed cabinet rack mounted to a wall with a locking door', category_type = 'rack_type', category_order = 5"); // 5
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Other', category_description = 'Rack type does not fit any standard category', category_type = 'rack_type', category_order = 6"); // 6

        // Software Types
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Software as a Service (SaaS)', category_description = 'Cloud-hosted software accessed via a web browser or API', category_type = 'software_type', category_order = 1"); // 1
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Productivity Suite', category_description = 'Bundled office and collaboration tools such as Microsoft 365 or Google Workspace', category_type = 'software_type', category_order = 2"); // 2
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Web Application', category_description = 'Application hosted on a web server and accessed through a browser', category_type = 'software_type', category_order = 3"); // 3
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Desktop Application', category_description = 'Application installed and run locally on a workstation or laptop', category_type = 'software_type', category_order = 4"); // 4
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Mobile Application', category_description = 'Application installed and run on a mobile device or tablet', category_type = 'software_type', category_order = 5"); // 5
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Security Software', category_description = 'Software providing antivirus, endpoint protection, or security monitoring', category_type = 'software_type', category_order = 6"); // 6
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'System Software', category_description = 'Low-level software managing hardware resources and system operations', category_type = 'software_type', category_order = 7"); // 7
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Operating System', category_description = 'Core software managing hardware and providing a platform for applications', category_type = 'software_type', category_order = 8"); // 8
        mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'Other', category_description = 'Software type does not fit any standard category', category_type = 'software_type', category_order = 9"); // 9

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.4'");

    }

    if (CURRENT_DATABASE_VERSION == '2.4.4') {
        // Session lifetime setting
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_login_session_lifetime` INT(11) NOT NULL DEFAULT 480 AFTER `config_login_remember_me_expire`");

        // Credential history table
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `credential_history` (
          `history_id` int(11) NOT NULL AUTO_INCREMENT,
          `history_credential_id` int(11) NOT NULL,
          `history_user_id` int(11) NOT NULL DEFAULT 0,
          `history_user_name` varchar(200) NOT NULL DEFAULT '',
          `history_field` varchar(100) NOT NULL,
          `history_old_value` text DEFAULT NULL,
          `history_new_value` text DEFAULT NULL,
          `history_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`history_id`),
          KEY `history_credential_id` (`history_credential_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.5') {
        // Outbound Webhooks
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `webhooks` (
          `webhook_id` int(11) NOT NULL AUTO_INCREMENT,
          `webhook_name` varchar(200) NOT NULL DEFAULT '',
          `webhook_url` varchar(2048) NOT NULL,
          `webhook_secret` varchar(255) NOT NULL DEFAULT '',
          `webhook_events` varchar(500) NOT NULL DEFAULT '',
          `webhook_enabled` tinyint(1) NOT NULL DEFAULT 1,
          `webhook_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`webhook_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `webhook_queue` (
          `queue_id` int(11) NOT NULL AUTO_INCREMENT,
          `queue_webhook_id` int(11) NOT NULL,
          `queue_event` varchar(100) NOT NULL,
          `queue_payload` longtext NOT NULL,
          `queue_status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
          `queue_attempts` tinyint(3) NOT NULL DEFAULT 0,
          `queue_response_code` smallint(6) DEFAULT NULL,
          `queue_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `queue_next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
          `queue_delivered_at` datetime DEFAULT NULL,
          PRIMARY KEY (`queue_id`),
          KEY `queue_status_next` (`queue_status`,`queue_next_attempt_at`),
          KEY `queue_webhook_id` (`queue_webhook_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.6') {
        // Passkeys (WebAuthn / FIDO2)
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `user_passkeys` (
          `passkey_id` int(11) NOT NULL AUTO_INCREMENT,
          `passkey_user_id` int(11) NOT NULL,
          `passkey_name` varchar(200) NOT NULL DEFAULT 'Passkey',
          `passkey_credential_id` varchar(1024) NOT NULL,
          `passkey_public_key` text NOT NULL,
          `passkey_sign_count` int(11) NOT NULL DEFAULT 0,
          `passkey_aaguid` varchar(36) NOT NULL DEFAULT '',
          `passkey_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `passkey_last_used_at` datetime DEFAULT NULL,
          PRIMARY KEY (`passkey_id`),
          KEY `passkey_user_id` (`passkey_user_id`),
          KEY `passkey_credential_id` (`passkey_credential_id`(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.7') {
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_backup_auto_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_log_retention`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_backup_frequency` varchar(20) NOT NULL DEFAULT 'daily' AFTER `config_backup_auto_enabled`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_backup_retain_count` int(11) NOT NULL DEFAULT 7 AFTER `config_backup_frequency`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.8'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.8') {
        // Comet Backup integration
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_comet_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_backup_retain_count`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_comet_server_url` varchar(500) NOT NULL DEFAULT 'http://10.1.0.35:8060' AFTER `config_comet_enabled`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_comet_admin_user` varchar(200) NOT NULL DEFAULT '' AFTER `config_comet_server_url`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_comet_admin_pass` varchar(200) NOT NULL DEFAULT '' AFTER `config_comet_admin_user`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_comet_auto_ticket` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_comet_admin_pass`");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `comet_client_map` (
          `map_id` int(11) NOT NULL AUTO_INCREMENT,
          `map_client_id` int(11) NOT NULL,
          `map_comet_username` varchar(200) NOT NULL,
          `map_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`map_id`),
          UNIQUE KEY `map_client_id` (`map_client_id`),
          KEY `map_comet_username` (`map_comet_username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `comet_backup_alerts` (
          `alert_id` int(11) NOT NULL AUTO_INCREMENT,
          `alert_comet_username` varchar(200) NOT NULL,
          `alert_device_name` varchar(200) NOT NULL,
          `alert_ticket_id` int(11) NOT NULL,
          `alert_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `alert_resolved_at` datetime DEFAULT NULL,
          PRIMARY KEY (`alert_id`),
          KEY `alert_lookup` (`alert_comet_username`,`alert_device_name`,`alert_resolved_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.9'");
    }

} else {
    // Up-to-date
}

    if (CURRENT_DATABASE_VERSION == '2.4.9') {
        // 2.4.9/2.4.10 never had schema changes defined for them - this block only
        // exists to close the gap so an install parked at exactly 2.4.9 isn't stuck
        // forever (the next real migration block below starts at 2.4.11).
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.11'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.11') {

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `api_tokens` (
          `token_enc_master_key` varchar(300) DEFAULT NULL,
          `token_enc_master_iv` char(32) DEFAULT NULL,
          `token_id` int(11) NOT NULL AUTO_INCREMENT,
          `token_user_id` int(11) NOT NULL,
          `token_name` varchar(100) NOT NULL DEFAULT 'Mobile App',
          `token_hash` char(64) NOT NULL,
          `token_fcm_token` text DEFAULT NULL,
          `token_last_used_at` datetime DEFAULT NULL,
          `token_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`token_id`),
          UNIQUE KEY `token_hash` (`token_hash`),
          KEY `token_user_id` (`token_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.4.12'");
    }

    if (CURRENT_DATABASE_VERSION == '2.4.12') {

        // RMM Integration tables (Syncro-Beta)

        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_module_enable_rmm` tinyint(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS `config_rmm_default_integration_id` int(11) DEFAULT NULL
        ");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `rmm_integrations` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(200) NOT NULL,
          `type` varchar(50) NOT NULL DEFAULT 'tactical_rmm',
          `api_url` varchar(500) NOT NULL,
          `web_url` varchar(500) DEFAULT NULL,
          `api_key_enc` text NOT NULL,
          `enabled` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime DEFAULT current_timestamp(),
          `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          `created_by` int(11) DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `asset_rmm_links` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `asset_id` int(11) NOT NULL,
          `integration_id` int(11) NOT NULL,
          `tactical_agent_id` varchar(200) DEFAULT NULL,
          `mesh_node_id` varchar(200) DEFAULT NULL,
          `hostname` varchar(200) DEFAULT NULL,
          `rmm_status` varchar(20) DEFAULT 'unknown',
          `last_seen` datetime DEFAULT NULL,
          `os_name` varchar(200) DEFAULT NULL,
          `os_version` varchar(200) DEFAULT NULL,
          `manufacturer` varchar(200) DEFAULT NULL,
          `model` varchar(200) DEFAULT NULL,
          `cpu` varchar(300) DEFAULT NULL,
          `ram_gb` varchar(50) DEFAULT NULL,
          `logged_in_user` varchar(200) DEFAULT NULL,
          `last_sync` datetime DEFAULT NULL,
          `raw_data_json` longtext DEFAULT NULL,
          `created_at` datetime DEFAULT current_timestamp(),
          `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `asset_integration` (`asset_id`,`integration_id`),
          KEY `tactical_agent_id` (`tactical_agent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `rmm_sync_log` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `integration_id` int(11) NOT NULL,
          `started_at` datetime DEFAULT current_timestamp(),
          `finished_at` datetime DEFAULT NULL,
          `status` varchar(20) DEFAULT 'running',
          `assets_created` int(11) DEFAULT 0,
          `assets_updated` int(11) DEFAULT 0,
          `assets_matched` int(11) DEFAULT 0,
          `assets_skipped` int(11) DEFAULT 0,
          `errors` text DEFAULT NULL,
          `triggered_by` int(11) DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `rmm_alerts` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `asset_id` int(11) DEFAULT NULL,
          `client_id` int(11) DEFAULT NULL,
          `integration_id` int(11) NOT NULL,
          `tactical_alert_id` varchar(200) DEFAULT NULL,
          `severity` varchar(50) DEFAULT NULL,
          `status` varchar(20) DEFAULT 'new',
          `message` text DEFAULT NULL,
          `acknowledged_by` int(11) DEFAULT NULL,
          `acknowledged_at` datetime DEFAULT NULL,
          `resolved_at` datetime DEFAULT NULL,
          `raw_data_json` longtext DEFAULT NULL,
          `created_at` datetime DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `asset_id` (`asset_id`),
          KEY `client_id` (`client_id`),
          KEY `tactical_alert_id` (`tactical_alert_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `rmm_scripts` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(200) NOT NULL,
          `category` varchar(100) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `script_type` varchar(20) DEFAULT 'powershell',
          `script_body` longtext DEFAULT NULL,
          `tactical_script_id` int(11) DEFAULT NULL,
          `enabled` tinyint(1) DEFAULT 1,
          `created_by` int(11) DEFAULT 0,
          `created_at` datetime DEFAULT current_timestamp(),
          `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `rmm_script_runs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `script_id` int(11) DEFAULT NULL,
          `asset_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `status` varchar(20) DEFAULT 'pending',
          `tactical_job_id` varchar(200) DEFAULT NULL,
          `output` longtext DEFAULT NULL,
          `error_message` text DEFAULT NULL,
          `started_at` datetime DEFAULT current_timestamp(),
          `finished_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `asset_id` (`asset_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `rmm_remote_sessions` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `asset_id` int(11) NOT NULL,
          `client_id` int(11) DEFAULT NULL,
          `user_id` int(11) NOT NULL,
          `connection_type` varchar(50) DEFAULT NULL,
          `connection_url` varchar(1000) DEFAULT NULL,
          `source_ip` varchar(100) DEFAULT NULL,
          `user_agent` varchar(300) DEFAULT NULL,
          `created_at` datetime DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `asset_id` (`asset_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.0') {

        mysqli_query($mysqli, "ALTER TABLE `assets` ADD `asset_tag` VARCHAR(100) NULL DEFAULT NULL AFTER `asset_name`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.1') {

        mysqli_query($mysqli, "ALTER TABLE `rmm_alerts` ADD `ticket_id` INT(11) NULL DEFAULT NULL, ADD KEY `ticket_id` (`ticket_id`)");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD `config_rmm_auto_ticket_severities` VARCHAR(100) NOT NULL DEFAULT ''");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.2'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.2') {

        mysqli_query($mysqli, "ALTER TABLE `ticket_replies` MODIFY `ticket_reply_type` VARCHAR(20) NOT NULL");

        mysqli_query($mysqli, "UPDATE `ticket_replies` SET `ticket_reply_type` = 'Automation' WHERE `ticket_reply_type` = 'note'");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.3') {

        mysqli_query($mysqli, "ALTER TABLE `ticket_automation_rules`
            ADD `rule_conditions_json` LONGTEXT NULL AFTER `rule_cond_value`,
            ADD `rule_actions_json` LONGTEXT NULL AFTER `rule_action_value`");

        mysqli_query($mysqli, "ALTER TABLE `asset_rmm_links`
            ADD `rmm_status_changed_at` DATETIME NULL,
            ADD `automation_processed_at` DATETIME NULL");

        mysqli_query($mysqli, "ALTER TABLE `rmm_alerts`
            ADD `automation_processed_at` DATETIME NULL");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_automation_runs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `rule_id` INT(11) NOT NULL,
            `rule_name` VARCHAR(100) DEFAULT NULL,
            `trigger_type` VARCHAR(30) NOT NULL,
            `ticket_id` INT(11) DEFAULT NULL,
            `asset_id` INT(11) DEFAULT NULL,
            `alert_id` INT(11) DEFAULT NULL,
            `client_id` INT(11) DEFAULT NULL,
            `summary` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `rule_id` (`rule_id`),
            KEY `ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.4'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.4') {

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_tags` (
            `ticket_tag_ticket_id` INT(11) NOT NULL,
            `ticket_tag_tag_id` INT(11) NOT NULL,
            PRIMARY KEY (`ticket_tag_ticket_id`, `ticket_tag_tag_id`),
            KEY `fk_ticket_tag_tag` (`ticket_tag_tag_id`),
            CONSTRAINT `fk_ticket_tag_ticket` FOREIGN KEY (`ticket_tag_ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ticket_tag_tag` FOREIGN KEY (`ticket_tag_tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `canned_responses` (
            `canned_response_id` INT(11) NOT NULL AUTO_INCREMENT,
            `canned_response_name` VARCHAR(255) NOT NULL,
            `canned_response_message` MEDIUMTEXT NOT NULL,
            `canned_response_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `canned_response_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `canned_response_archived_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`canned_response_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.5') {

        mysqli_query($mysqli, "ALTER TABLE `project_templates`
            ADD COLUMN `project_template_default_contract_template_id` INT(11) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.6') {

        mysqli_query($mysqli, "ALTER TABLE `project_templates`
            ADD COLUMN `project_template_is_onboarding` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.7') {

        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN `config_module_enable_ticket_charges` TINYINT(1) NOT NULL DEFAULT 1");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.8'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.8') {

        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN `config_module_enable_kb` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `kb_articles` (
            `kb_article_id` INT(11) NOT NULL AUTO_INCREMENT,
            `kb_article_title` VARCHAR(255) NOT NULL,
            `kb_article_content` MEDIUMTEXT,
            `kb_article_content_raw` MEDIUMTEXT,
            `kb_article_client_id` INT(11) NOT NULL DEFAULT 0,
            `kb_article_client_visible` TINYINT(1) NOT NULL DEFAULT 1,
            `kb_article_favorite` TINYINT(1) NOT NULL DEFAULT 0,
            `kb_article_created_by` INT(11) NOT NULL DEFAULT 0,
            `kb_article_updated_by` INT(11) NOT NULL DEFAULT 0,
            `kb_article_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `kb_article_updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `kb_article_archived_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`kb_article_id`),
            KEY `kb_article_client_id` (`kb_article_client_id`),
            FULLTEXT KEY `kb_article_content_raw` (`kb_article_content_raw`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "INSERT INTO `modules` SET module_name = 'module_kb', module_description = 'Access to the knowledge base'");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.5.9'");
    }

    if (CURRENT_DATABASE_VERSION == '2.5.9') {

        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN `config_module_enable_live_chat` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_chat_messages` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_id` INT(11) NOT NULL,
            `sender_type` ENUM('agent','contact') NOT NULL,
            `sender_id` INT(11) NOT NULL DEFAULT 0,
            `message` TEXT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.0'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.0') {

        mysqli_query($mysqli, "ALTER TABLE `tickets`
            ADD COLUMN `ticket_initial_issue_reply_id` INT(11) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.1'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.1') {

        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN `config_vault_canonical_key` VARCHAR(255) DEFAULT NULL,
            ADD COLUMN `config_vault_canonical_key_set_at` DATETIME DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.2'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.2') {

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_saved_views` (
            `ticket_saved_view_id` INT(11) NOT NULL AUTO_INCREMENT,
            `ticket_saved_view_name` VARCHAR(100) NOT NULL,
            `ticket_saved_view_icon` VARCHAR(50) NOT NULL DEFAULT 'fa-filter',
            `ticket_saved_view_query` TEXT NOT NULL,
            `ticket_saved_view_user_id` INT(11) NOT NULL DEFAULT 0,
            `ticket_saved_view_order` INT(11) NOT NULL DEFAULT 0,
            `ticket_saved_view_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `ticket_saved_view_archived_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`ticket_saved_view_id`),
            KEY `ticket_saved_view_user_id` (`ticket_saved_view_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "INSERT INTO `ticket_saved_views`
            (`ticket_saved_view_name`, `ticket_saved_view_icon`, `ticket_saved_view_query`, `ticket_saved_view_order`) VALUES
            ('Default', 'fa-list', '', 0),
            ('On-Site', 'fa-truck', 'onsite=1&status=Open', 1),
            ('Assigned to me', 'fa-user', 'assigned=me&status=Open', 2),
            ('All Unresolved', 'fa-folder-open', 'status=Open', 3),
            ('My Queue', 'fa-inbox', 'assigned=me&status=Open', 4),
            ('Remote', 'fa-headset', 'onsite=0&status=Open', 5)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.3'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.3') {

        mysqli_query($mysqli, "ALTER TABLE `ticket_chat_messages`
            ADD KEY `ticket_id_id` (`ticket_id`, `id`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.4'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.4') {

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `kb_categories` (
            `kb_category_id` INT(11) NOT NULL AUTO_INCREMENT,
            `kb_category_name` VARCHAR(100) NOT NULL,
            `kb_category_parent_id` INT(11) NOT NULL DEFAULT 0,
            `kb_category_client_id` INT(11) NOT NULL DEFAULT 0,
            `kb_category_order` INT(11) NOT NULL DEFAULT 0,
            `kb_category_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `kb_category_archived_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`kb_category_id`),
            KEY `kb_category_client_id` (`kb_category_client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `kb_article_attachments` (
            `kb_article_attachment_id` INT(11) NOT NULL AUTO_INCREMENT,
            `kb_article_attachment_name` VARCHAR(255) NOT NULL,
            `kb_article_attachment_reference_name` VARCHAR(255) NOT NULL,
            `kb_article_attachment_kb_article_id` INT(11) NOT NULL,
            `kb_article_attachment_created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`kb_article_attachment_id`),
            KEY `kb_article_attachment_kb_article_id` (`kb_article_attachment_kb_article_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "ALTER TABLE `kb_articles`
            ADD COLUMN `kb_article_category_id` INT(11) NOT NULL DEFAULT 0,
            ADD KEY `kb_article_category_id` (`kb_article_category_id`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.5'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.5') {

        // UniFi Integration tables

        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_module_enable_unifi` tinyint(1) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS `config_unifi_default_integration_id` int(11) DEFAULT NULL
        ");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `unifi_integrations` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `name` varchar(200) NOT NULL,
          `host` varchar(255) NOT NULL,
          `port` int(11) NOT NULL DEFAULT 443,
          `api_key_enc` text NOT NULL,
          `verify_ssl` tinyint(1) NOT NULL DEFAULT 1,
          `enabled` tinyint(1) NOT NULL DEFAULT 1,
          `created_at` datetime DEFAULT current_timestamp(),
          `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          `created_by` int(11) DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `unifi_sync_log` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `integration_id` int(11) NOT NULL,
          `started_at` datetime DEFAULT current_timestamp(),
          `finished_at` datetime DEFAULT NULL,
          `status` varchar(20) DEFAULT 'running',
          `devices_created` int(11) DEFAULT 0,
          `devices_updated` int(11) DEFAULT 0,
          `devices_matched` int(11) DEFAULT 0,
          `devices_skipped` int(11) DEFAULT 0,
          `wifi_created` int(11) DEFAULT 0,
          `wifi_updated` int(11) DEFAULT 0,
          `wifi_skipped` int(11) DEFAULT 0,
          `networks_created` int(11) DEFAULT 0,
          `networks_updated` int(11) DEFAULT 0,
          `networks_skipped` int(11) DEFAULT 0,
          `errors` text DEFAULT NULL,
          `triggered_by` int(11) DEFAULT 0,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.6'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.6') {

        // "My Queue" was seeded as an exact duplicate of "Assigned to me"
        // (same query), which always highlighted both views together
        mysqli_query($mysqli, "DELETE FROM `ticket_saved_views` WHERE `ticket_saved_view_name` = 'My Queue' AND `ticket_saved_view_query` = 'assigned=me&status=Open' AND `ticket_saved_view_user_id` = 0");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.7'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.7') {

        // UniFi site -> client mapping (lets an admin override the
        // automatic site-name-to-client-name match)

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `unifi_site_mappings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `integration_id` int(11) NOT NULL,
          `unifi_site_id` varchar(100) NOT NULL,
          `unifi_site_name` varchar(200) NOT NULL,
          `client_id` int(11) DEFAULT NULL,
          `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `integration_site` (`integration_id`,`unifi_site_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.8'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.8') {

        // UnifiedPush endpoint registrations for mobile app push notifications
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `push_endpoints` (
          `push_endpoint_id` int(11) NOT NULL AUTO_INCREMENT,
          `push_endpoint_token_id` int(11) NOT NULL,
          `push_endpoint_url` varchar(1024) NOT NULL,
          `push_endpoint_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `push_endpoint_last_failed_at` datetime DEFAULT NULL,
          PRIMARY KEY (`push_endpoint_id`),
          KEY `push_endpoint_token_id` (`push_endpoint_token_id`),
          CONSTRAINT `push_endpoints_ibfk_1` FOREIGN KEY (`push_endpoint_token_id`) REFERENCES `api_tokens` (`token_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.9'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.9') {

        // Replaced by the real-time notifications SSE stream
        // (api/v1/notifications/stream) - no per-device endpoint registration needed
        mysqli_query($mysqli, "DROP TABLE IF EXISTS `push_endpoints`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.10'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.10') {

        // Per-category mobile push notification preferences (admin-level allow list + per-user override)
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN `config_push_enabled_types` TEXT DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `user_settings` ADD COLUMN `user_config_push_types` TEXT DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.11'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.11') {

        // Bring comet_backup_alerts to parity with rmm_alerts (type/severity/
        // message/client/status/acknowledge) so both can feed one unified
        // alerts view, and so "missed backup" (device gone quiet) can be
        // tracked distinctly from "failed backup" (explicit job failure).
        mysqli_query($mysqli, "ALTER TABLE `comet_backup_alerts`
            ADD COLUMN `alert_type` ENUM('failed','missed') NOT NULL DEFAULT 'failed' AFTER `alert_device_name`,
            ADD COLUMN `alert_severity` VARCHAR(20) NOT NULL DEFAULT 'critical' AFTER `alert_type`,
            ADD COLUMN `alert_message` TEXT DEFAULT NULL AFTER `alert_severity`,
            ADD COLUMN `alert_client_id` INT(11) DEFAULT NULL AFTER `alert_message`,
            ADD COLUMN `alert_status` VARCHAR(20) NOT NULL DEFAULT 'new' AFTER `alert_ticket_id`,
            ADD COLUMN `alert_acknowledged_by` INT(11) DEFAULT NULL AFTER `alert_status`,
            ADD COLUMN `alert_acknowledged_at` DATETIME DEFAULT NULL AFTER `alert_acknowledged_by`");

        // Backfill status for any alerts created before this column existed
        mysqli_query($mysqli, "UPDATE `comet_backup_alerts` SET `alert_status` = 'resolved' WHERE `alert_resolved_at` IS NOT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.12'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.12') {

        // Single-tenant integrations (e.g. Sophos Central without a Partner/
        // Organization credential) have no per-device "client name" field to
        // match against — every device belongs to the one tenant. Lets an
        // admin pick a fallback client so newly-discovered devices aren't
        // silently skipped for having no resolvable client.
        mysqli_query($mysqli, "ALTER TABLE `rmm_integrations`
            ADD COLUMN `default_client_id` INT(11) DEFAULT NULL AFTER `web_url`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.13'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.13') {

        // Add type column to unifi_integrations so a single table can hold
        // both self-hosted local controllers and UniFi Site Manager (api.ui.com)
        // cloud connections. Existing rows default to 'local'.
        mysqli_query($mysqli, "ALTER TABLE `unifi_integrations`
            ADD COLUMN `type` VARCHAR(20) NOT NULL DEFAULT 'local' AFTER `name`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.14'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.14') {

        // Track which ticket a merged ticket was merged into, so they can be
        // displayed with a "Merged → TCK-X" badge in the ticket list.
        mysqli_query($mysqli, "ALTER TABLE `tickets`
            ADD COLUMN `ticket_merged_into_id` INT DEFAULT NULL AFTER `ticket_closed_by`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.15'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.15') {

        // Store the Outlook calendar event ID per ticket_schedules row so that
        // editing an appointment updates the existing event rather than duplicating it.
        mysqli_query($mysqli, "ALTER TABLE `ticket_schedules`
            ADD COLUMN `schedule_outlook_event_id` VARCHAR(500) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.16'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.16') {

        // Track per-reply whether the work was remote or onsite, and which
        // labor type was selected, so the Time Entry Log can show this info.
        mysqli_query($mysqli, "ALTER TABLE `ticket_replies`
            ADD COLUMN IF NOT EXISTS `ticket_reply_onsite` TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN `ticket_reply_labor_type_id` INT DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.17'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.17') {

        // Add schedule_outlook_event_id to ticket_schedules so that editing an
        // appointment PATCHes the existing Outlook calendar event rather than
        // creating a duplicate. (Migration 2.6.15→2.6.16 was skipped on installs
        // that were already at 2.6.16 when that commit landed.)
        mysqli_query($mysqli, "ALTER TABLE `ticket_schedules`
            ADD COLUMN IF NOT EXISTS `schedule_outlook_event_id` VARCHAR(500) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.18'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.18') {

        // Distinguishes a "Public" reply that was actually emailed to the
        // client (a real reply) from one that wasn't (a public-visible note).
        // Both were previously stored identically as ticket_reply_type =
        // 'Public', so the reply list couldn't tell them apart and always
        // labeled them "Client Reply".
        mysqli_query($mysqli, "ALTER TABLE `ticket_replies`
            ADD COLUMN IF NOT EXISTS `ticket_reply_emailed` TINYINT(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.19'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.19') {

        // TEXT (64KB) is too small for a real-world signature pasted from Outlook —
        // those commonly include a table-based layout with embedded base64 images
        // and easily exceed that limit, which throws a "Data too long for column"
        // error (mysqli exceptions are on by default as of PHP 8.1) and 500s the
        // whole "Save" request. Match the LONGTEXT used for every other rich-HTML
        // column (ticket_replies.ticket_reply, tickets.ticket_details, etc.).
        mysqli_query($mysqli, "ALTER TABLE `user_settings` MODIFY `user_config_signature` LONGTEXT DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.20'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.20') {

        // Multiple independent mailboxes (e.g. support@, sales@, billing@ — typically
        // Microsoft 365 shared mailboxes), each independently IMAP-polled to create
        // tickets and each usable as its own "From" identity when replying. Replaces
        // the single global config_imap_*/config_mail_oauth_* mailbox.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mailboxes` (
            `mailbox_id` int(11) NOT NULL AUTO_INCREMENT,
            `mailbox_name` varchar(200) NOT NULL,
            `mailbox_email` varchar(200) NOT NULL,
            `mailbox_from_name` varchar(200) DEFAULT NULL,
            `mailbox_type` varchar(20) NOT NULL DEFAULT 'standard_imap',
            `mailbox_imap_host` varchar(200) DEFAULT NULL,
            `mailbox_imap_port` int(5) DEFAULT NULL,
            `mailbox_imap_encryption` varchar(20) DEFAULT NULL,
            `mailbox_imap_username` varchar(200) DEFAULT NULL,
            `mailbox_imap_password_enc` text DEFAULT NULL,
            `mailbox_oauth_refresh_token_enc` text DEFAULT NULL,
            `mailbox_oauth_access_token_enc` text DEFAULT NULL,
            `mailbox_oauth_access_token_expires_at` datetime DEFAULT NULL,
            `mailbox_parse_unknown_senders` tinyint(1) NOT NULL DEFAULT 0,
            `mailbox_default_client_id` int(11) DEFAULT NULL,
            `mailbox_active` tinyint(1) NOT NULL DEFAULT 1,
            `mailbox_last_polled_at` datetime DEFAULT NULL,
            `mailbox_order` int(11) NOT NULL DEFAULT 0,
            `mailbox_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `mailbox_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`mailbox_id`),
            KEY `idx_mailbox_active` (`mailbox_active`, `mailbox_archived_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "ALTER TABLE `tickets`
            ADD COLUMN IF NOT EXISTS `ticket_mailbox_id` int(11) DEFAULT NULL AFTER `ticket_source`");

        // Auto-migrate today's single global mailbox (if configured) into one row,
        // so existing installs keep polling/sending exactly as before. Ciphertext
        // columns are copied verbatim (both use encryptSetting() with the same
        // per-installation key) — no decrypt/re-encrypt needed.
        //
        // Exception: microsoft_oauth tokens are NOT copied. At the time this legacy
        // config was created, Microsoft mailboxes were polled over raw IMAP using an
        // Office 365 Exchange Online-scoped token (IMAP.AccessAsUser.All/SMTP.Send).
        // Mailbox polling now reads via Microsoft Graph (Mail.ReadWrite scope) instead
        // - see cron/ticket_email_parser.php - and a token consented for one resource
        // cannot be used to call the other, so copying it here would silently produce
        // a mailbox row that *looks* Connected but fails on every poll. Leaving the
        // token columns NULL means it correctly shows "Needs reconnect" and the admin
        // clicks Connect once to get a real Graph-scoped token.
        mysqli_query($mysqli, "INSERT INTO mailboxes
            (mailbox_name, mailbox_email, mailbox_from_name, mailbox_type, mailbox_imap_host, mailbox_imap_port,
             mailbox_imap_encryption, mailbox_imap_username, mailbox_imap_password_enc, mailbox_oauth_refresh_token_enc,
             mailbox_oauth_access_token_enc, mailbox_oauth_access_token_expires_at, mailbox_parse_unknown_senders, mailbox_active)
            SELECT 'Tickets (migrated)', COALESCE(NULLIF(config_imap_username,''), config_ticket_from_email), config_ticket_from_name,
                   COALESCE(NULLIF(config_imap_provider,''), 'standard_imap'), config_imap_host, config_imap_port, config_imap_encryption,
                   config_imap_username, config_imap_password,
                   CASE WHEN config_imap_provider = 'microsoft_oauth' THEN NULL ELSE config_mail_oauth_refresh_token END,
                   CASE WHEN config_imap_provider = 'microsoft_oauth' THEN NULL ELSE config_mail_oauth_access_token END,
                   CASE WHEN config_imap_provider = 'microsoft_oauth' THEN NULL ELSE config_mail_oauth_access_token_expires_at END,
                   config_ticket_email_parse_unknown_senders, config_ticket_email_parse
            FROM settings
            WHERE company_id = 1 AND config_imap_provider IS NOT NULL AND config_imap_provider <> ''
              AND NOT EXISTS (SELECT 1 FROM mailboxes WHERE mailbox_name = 'Tickets (migrated)')");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.21'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.21') {

        // Fixes installs that already ran the 2.6.20 migration above before this
        // exception existed: any auto-migrated "Tickets (migrated)" mailbox row of
        // type microsoft_oauth is carrying a stale Office 365 Exchange Online-scoped
        // token that Microsoft Graph will reject on every poll (wrong resource/audience
        // - see the 2.6.20 block's comment above). Clear those columns so the mailbox
        // correctly shows "Needs reconnect" instead of silently failing forever.
        mysqli_query($mysqli, "UPDATE `mailboxes` SET
            `mailbox_oauth_refresh_token_enc` = NULL,
            `mailbox_oauth_access_token_enc` = NULL,
            `mailbox_oauth_access_token_expires_at` = NULL
            WHERE `mailbox_name` = 'Tickets (migrated)' AND `mailbox_type` = 'microsoft_oauth'");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.22'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.22') {

        // Unknown-sender emails (mailbox_parse_unknown_senders) no longer create a
        // ticket immediately - they land here for an admin to review, then either
        // convert into a ticket (picking the client) or dismiss. See admin/mail_requests.php.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mail_requests` (
            `mail_request_id` int(11) NOT NULL AUTO_INCREMENT,
            `mail_request_mailbox_id` int(11) NOT NULL,
            `mail_request_from_email` varchar(200) NOT NULL,
            `mail_request_from_name` varchar(200) DEFAULT NULL,
            `mail_request_subject` varchar(500) NOT NULL DEFAULT '',
            `mail_request_body` longtext DEFAULT NULL,
            `mail_request_ccs` text DEFAULT NULL,
            `mail_request_received_at` datetime NOT NULL,
            `mail_request_eml_reference_name` varchar(255) DEFAULT NULL,
            `mail_request_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `mail_request_converted_ticket_id` int(11) DEFAULT NULL,
            `mail_request_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`mail_request_id`),
            KEY `idx_mail_request_pending` (`mail_request_archived_at`, `mail_request_mailbox_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mail_request_attachments` (
            `mail_request_attachment_id` int(11) NOT NULL AUTO_INCREMENT,
            `mail_request_attachment_name` varchar(255) NOT NULL,
            `mail_request_attachment_reference_name` varchar(255) NOT NULL,
            `mail_request_attachment_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `mail_request_attachment_mail_request_id` int(11) NOT NULL,
            PRIMARY KEY (`mail_request_attachment_id`),
            KEY `idx_mail_request_attachment_request` (`mail_request_attachment_mail_request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.23'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.23') {

        // One row per inbound email the mailbox poller touches - reply matched, ticket
        // created, queued as a Request, NDR/bounce, or left alone (unmatched / queueing
        // disabled). See logMailEvent() and admin/email_log.php.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mail_log` (
            `mail_log_id` int(11) NOT NULL AUTO_INCREMENT,
            `mail_log_mailbox_id` int(11) DEFAULT NULL,
            `mail_log_from_email` varchar(200) DEFAULT NULL,
            `mail_log_from_name` varchar(200) DEFAULT NULL,
            `mail_log_subject` varchar(500) DEFAULT NULL,
            `mail_log_outcome` varchar(30) NOT NULL,
            `mail_log_detail` varchar(500) DEFAULT NULL,
            `mail_log_ticket_id` int(11) DEFAULT NULL,
            `mail_log_mail_request_id` int(11) DEFAULT NULL,
            `mail_log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`mail_log_id`),
            KEY `idx_mail_log_created` (`mail_log_created_at`),
            KEY `idx_mail_log_mailbox` (`mail_log_mailbox_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.24'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.24') {

        // Job title and direct phone, used to build a proper email signature template
        // (Admin/Agent > My Settings > Email Signature > Use Template).
        mysqli_query($mysqli, "ALTER TABLE `users`
            ADD COLUMN IF NOT EXISTS `user_title` varchar(200) DEFAULT NULL AFTER `user_name`,
            ADD COLUMN IF NOT EXISTS `user_phone` varchar(50) DEFAULT NULL AFTER `user_title`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.25'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.25') {

        // One row per user's mobile-app device: an Ed25519/EC public key backing
        // the credential-viewing step-up biometric check (api/v1/credentials.php).
        // Replaces the old hardcoded "X-Biometric: 1" header, which the server
        // had no way to verify - the key here requires a real device-side
        // biometric unlock to sign a fresh, single-use server challenge with.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `api_biometric_keys` (
            `user_id` int(11) NOT NULL,
            `device_public_key_pem` text NOT NULL,
            `key_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `key_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.26'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.26') {

        // Per-user Outlook/Microsoft Graph OAuth tokens for the Outlook Calendar
        // integration - stores the encrypted refresh/access token pair and their
        // expiry so getOutlookAccessToken() (functions.php) can silently refresh
        // instead of re-prompting the user to sign in.
        mysqli_query($mysqli, "ALTER TABLE `users`
            ADD COLUMN IF NOT EXISTS `user_outlook_refresh_token` text DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `user_outlook_access_token` text DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `user_outlook_token_expires` datetime DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.27'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.27') {

        // Shared Azure AD app-registration credentials backing the Outlook Calendar
        // integration (client ID/secret + tenant ID) - used alongside the per-user
        // tokens above by getOutlookAccessToken() (functions.php) to talk to
        // Microsoft Graph.
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_outlook_cal_client_id` varchar(200) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `config_outlook_cal_client_secret` varchar(500) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `config_outlook_cal_tenant_id` varchar(200) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.28'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.28') {

        // Tracks the Microsoft Graph calendar event created for a scheduled ticket,
        // so the legacy-path deleteOutlookCalendarEvent() (functions.php) can find
        // and remove it from the technician's Outlook calendar when the ticket
        // schedule changes or the ticket is closed.
        mysqli_query($mysqli, "ALTER TABLE `tickets`
            ADD COLUMN IF NOT EXISTS `ticket_outlook_event_id` varchar(255) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.29'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.29') {

        // AI feature config (re-introduced after v2.3.2 dropped the old config_ai_*
        // provider/url/key columns - those now live in ai_providers / ai_models).
        // config_ai_enable   - company-wide on/off switch checked by aiEnabled()
        // config_ai_max_input_chars / config_ai_timeout_seconds - safety caps used
        //   by includes/ai_functions.php aiChat() (input truncation + cURL timeout).
        // Defaults enable=1 to preserve the pre-existing "always try" behaviour of
        // the agent/ajax.php AI handlers.
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_ai_enable` tinyint(1) NOT NULL DEFAULT 1,
            ADD COLUMN IF NOT EXISTS `config_ai_max_input_chars` int(11) NOT NULL DEFAULT 12000,
            ADD COLUMN IF NOT EXISTS `config_ai_timeout_seconds` int(11) NOT NULL DEFAULT 25");

        // Per-client opt-out from AI processing (e.g. ticket summaries).
        mysqli_query($mysqli, "ALTER TABLE `clients`
            ADD COLUMN IF NOT EXISTS `client_ai_opt_out` tinyint(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.30'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.30') {

        // ------------------------------------------------------------------
        // Consolidated migration for schema that had been applied directly to
        // the beta database during feature development but was never captured
        // in a version block. Groups: Theme, RMM health, Billing/webhooks,
        // Projects/tasks, SLA engine (+ default calendar/policy seed),
        // QuickBooks Online sync, Analytics/reporting, CRM/leads, and the
        // AI ticket-summary cache table. Every statement is idempotent
        // (CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS + guarded
        // seed inserts) so it is safe to re-run and safe on installs that
        // already carry some of these objects.
        // NOTE: config_ai_* and clients.client_ai_opt_out are intentionally
        // NOT repeated here - they are handled by the 2.6.29 block above.
        // ------------------------------------------------------------------

        // --- Theme customisation (settings) -------------------------------
        // Custom accent colour, card corner radius and a company-wide
        // "default to dark mode" toggle, read by the theme/CSS layer.
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_theme_accent_custom` varchar(7) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `config_theme_card_radius` varchar(8) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `config_theme_dark_default` tinyint(1) NOT NULL DEFAULT 0");

        // --- RMM device health (asset_rmm_links) --------------------------
        // Live health telemetry pulled from the linked RMM agent, shown on
        // the asset page and used to raise/auto-clear health alerts.
        mysqli_query($mysqli, "ALTER TABLE `asset_rmm_links`
            ADD COLUMN IF NOT EXISTS `rmm_cpu_percent` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `rmm_ram_percent` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `rmm_disk_percent` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `rmm_needs_reboot` tinyint(1) DEFAULT 0,
            ADD COLUMN IF NOT EXISTS `rmm_last_boot` datetime DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `rmm_maintenance_mode` tinyint(1) DEFAULT 0,
            ADD COLUMN IF NOT EXISTS `rmm_health_updated_at` datetime DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `rmm_patches_pending` tinyint(1) DEFAULT NULL");

        // Auto-close RMM health alerts once the device reports healthy again.
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_rmm_auto_close_on_clear` tinyint(1) NOT NULL DEFAULT 1");

        // --- Billing / payment webhooks -----------------------------------
        // Provider webhook signing secret, saved-method type (card/bank), and
        // an idempotency log of inbound payment-provider webhook events.
        mysqli_query($mysqli, "ALTER TABLE `payment_providers`
            ADD COLUMN IF NOT EXISTS `payment_provider_webhook_secret` varchar(250) DEFAULT NULL");

        mysqli_query($mysqli, "ALTER TABLE `client_saved_payment_methods`
            ADD COLUMN IF NOT EXISTS `saved_payment_type` varchar(20) DEFAULT 'card'");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
            `event_id` int(11) NOT NULL AUTO_INCREMENT,
            `event_provider_id` int(11) NOT NULL,
            `event_provider_ref` varchar(255) NOT NULL,
            `event_type` varchar(100) DEFAULT NULL,
            `event_status` enum('received','processed','ignored','error') NOT NULL DEFAULT 'received',
            `event_payload` longtext DEFAULT NULL,
            `event_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`event_id`),
            UNIQUE KEY `uq_provider_ref` (`event_provider_id`,`event_provider_ref`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // --- Projects / tasks (planning + Gantt) --------------------------
        mysqli_query($mysqli, "ALTER TABLE `projects`
            ADD COLUMN IF NOT EXISTS `project_start` date DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `project_estimated_hours` decimal(10,2) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `project_budget_amount` decimal(12,2) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `project_hourly_rate` decimal(10,2) DEFAULT NULL");

        mysqli_query($mysqli, "ALTER TABLE `tasks`
            ADD COLUMN IF NOT EXISTS `task_project_id` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `task_milestone_id` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `task_assigned_to` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `task_start` date DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `task_due` date DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `task_progress` tinyint(4) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `project_milestones` (
            `milestone_id` int(11) NOT NULL AUTO_INCREMENT,
            `milestone_project_id` int(11) NOT NULL,
            `milestone_name` varchar(255) NOT NULL,
            `milestone_description` text DEFAULT NULL,
            `milestone_due` date DEFAULT NULL,
            `milestone_order` int(11) NOT NULL DEFAULT 0,
            `milestone_status` varchar(30) NOT NULL DEFAULT 'open',
            `milestone_completed_at` datetime DEFAULT NULL,
            `milestone_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`milestone_id`),
            KEY `milestone_project_id` (`milestone_project_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `project_task_dependencies` (
            `dependency_id` int(11) NOT NULL AUTO_INCREMENT,
            `task_id` int(11) NOT NULL,
            `predecessor_id` int(11) NOT NULL,
            `dependency_type` varchar(10) NOT NULL DEFAULT 'FS',
            PRIMARY KEY (`dependency_id`),
            UNIQUE KEY `task_id` (`task_id`,`predecessor_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // --- SLA engine ---------------------------------------------------
        // Business-hours calendars + weekly periods + holidays, SLA policies
        // (response/resolution targets per priority), per-ticket SLA state,
        // and an event log used to compute paused/elapsed SLA time.
        mysqli_query($mysqli, "ALTER TABLE `tickets`
            ADD COLUMN IF NOT EXISTS `ticket_sla_policy_id` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `ticket_sla_paused_seconds` int(11) NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS `ticket_sla_paused_at` datetime DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `ticket_sla_response_met` tinyint(4) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `ticket_sla_resolution_met` tinyint(4) DEFAULT NULL");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `sla_business_hours` (
            `calendar_id` int(11) NOT NULL AUTO_INCREMENT,
            `calendar_name` varchar(150) NOT NULL,
            `calendar_timezone` varchar(64) NOT NULL DEFAULT 'UTC',
            `calendar_is_default` tinyint(4) NOT NULL DEFAULT 0,
            PRIMARY KEY (`calendar_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `sla_business_hours_periods` (
            `period_id` int(11) NOT NULL AUTO_INCREMENT,
            `calendar_id` int(11) NOT NULL,
            `day_of_week` tinyint(4) NOT NULL,
            `open_time` time NOT NULL,
            `close_time` time NOT NULL,
            PRIMARY KEY (`period_id`),
            KEY `calendar_id` (`calendar_id`,`day_of_week`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `sla_holidays` (
            `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
            `calendar_id` int(11) NOT NULL,
            `holiday_date` date NOT NULL,
            `holiday_name` varchar(150) DEFAULT NULL,
            PRIMARY KEY (`holiday_id`),
            KEY `calendar_id` (`calendar_id`,`holiday_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `sla_policies` (
            `policy_id` int(11) NOT NULL AUTO_INCREMENT,
            `policy_name` varchar(150) NOT NULL,
            `policy_calendar_id` int(11) DEFAULT NULL,
            `policy_pause_status_ids` varchar(255) DEFAULT NULL,
            `policy_low_response` int(11) DEFAULT NULL,
            `policy_low_resolution` int(11) DEFAULT NULL,
            `policy_medium_response` int(11) DEFAULT NULL,
            `policy_medium_resolution` int(11) DEFAULT NULL,
            `policy_high_response` int(11) DEFAULT NULL,
            `policy_high_resolution` int(11) DEFAULT NULL,
            `policy_is_default` tinyint(4) NOT NULL DEFAULT 0,
            `policy_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`policy_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_sla_events` (
            `event_id` int(11) NOT NULL AUTO_INCREMENT,
            `ticket_id` int(11) NOT NULL,
            `event_type` varchar(20) NOT NULL,
            `from_status` int(11) DEFAULT NULL,
            `to_status` int(11) DEFAULT NULL,
            `event_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`event_id`),
            KEY `ticket_id` (`ticket_id`,`event_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Seed a default Mon-Fri 09:00-17:00 business-hours calendar (id 1)
        // and a default SLA policy (id 1) referencing it, only if the SLA
        // tables are still empty. Ticket status 3 (on-hold/waiting) pauses
        // the SLA clock by default. Guarded so re-runs never duplicate.
        $sla_cal_check = mysqli_query($mysqli, "SELECT `calendar_id` FROM `sla_business_hours` LIMIT 1");
        if ($sla_cal_check && mysqli_num_rows($sla_cal_check) === 0) {
            mysqli_query($mysqli, "INSERT INTO `sla_business_hours`
                (`calendar_id`,`calendar_name`,`calendar_timezone`,`calendar_is_default`)
                VALUES (1,'Default (Mon-Fri 9-5)','UTC',1)");
            mysqli_query($mysqli, "INSERT INTO `sla_business_hours_periods`
                (`calendar_id`,`day_of_week`,`open_time`,`close_time`) VALUES
                (1,1,'09:00:00','17:00:00'),
                (1,2,'09:00:00','17:00:00'),
                (1,3,'09:00:00','17:00:00'),
                (1,4,'09:00:00','17:00:00'),
                (1,5,'09:00:00','17:00:00')");
        }
        $sla_pol_check = mysqli_query($mysqli, "SELECT `policy_id` FROM `sla_policies` LIMIT 1");
        if ($sla_pol_check && mysqli_num_rows($sla_pol_check) === 0) {
            mysqli_query($mysqli, "INSERT INTO `sla_policies`
                (`policy_id`,`policy_name`,`policy_calendar_id`,`policy_pause_status_ids`,`policy_is_default`)
                VALUES (1,'Default SLA Policy',1,'3',1)");
        }

        // --- QuickBooks Online / accounting sync --------------------------
        // Integration credentials, local<->remote entity id map, outbound
        // push queue and a sync activity log.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `accounting_integrations` (
            `accounting_id` int(11) NOT NULL AUTO_INCREMENT,
            `accounting_provider` varchar(30) NOT NULL DEFAULT 'quickbooks_online',
            `accounting_realm_id` varchar(64) DEFAULT NULL,
            `accounting_client_id` varchar(255) DEFAULT NULL,
            `accounting_client_secret` text DEFAULT NULL,
            `accounting_environment` varchar(20) NOT NULL DEFAULT 'production',
            `accounting_refresh_token` text DEFAULT NULL,
            `accounting_access_token` text DEFAULT NULL,
            `accounting_token_expires_at` datetime DEFAULT NULL,
            `accounting_default_income_account_id` varchar(64) DEFAULT NULL,
            `accounting_auto_push` tinyint(4) NOT NULL DEFAULT 1,
            `accounting_enabled` tinyint(4) NOT NULL DEFAULT 0,
            `accounting_connected_at` datetime DEFAULT NULL,
            `accounting_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`accounting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `accounting_entity_map` (
            `map_id` int(11) NOT NULL AUTO_INCREMENT,
            `map_accounting_id` int(11) NOT NULL,
            `map_local_type` varchar(20) NOT NULL,
            `map_local_id` int(11) NOT NULL,
            `map_remote_id` varchar(64) DEFAULT NULL,
            `map_remote_sync_token` varchar(32) DEFAULT NULL,
            `map_last_synced_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`map_id`),
            UNIQUE KEY `uniq_map` (`map_accounting_id`,`map_local_type`,`map_local_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `accounting_sync_queue` (
            `queue_id` int(11) NOT NULL AUTO_INCREMENT,
            `queue_accounting_id` int(11) NOT NULL,
            `queue_local_type` varchar(20) NOT NULL,
            `queue_local_id` int(11) NOT NULL,
            `queue_op` varchar(10) NOT NULL DEFAULT 'push',
            `queue_status` enum('pending','delivered','failed') NOT NULL DEFAULT 'pending',
            `queue_attempts` tinyint(4) NOT NULL DEFAULT 0,
            `queue_last_error` varchar(500) DEFAULT NULL,
            `queue_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `queue_next_attempt_at` datetime NOT NULL DEFAULT current_timestamp(),
            `queue_delivered_at` datetime DEFAULT NULL,
            PRIMARY KEY (`queue_id`),
            UNIQUE KEY `uniq_queue` (`queue_accounting_id`,`queue_local_type`,`queue_local_id`,`queue_op`),
            KEY `idx_status_next` (`queue_status`,`queue_next_attempt_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `accounting_sync_log` (
            `log_id` int(11) NOT NULL AUTO_INCREMENT,
            `log_accounting_id` int(11) NOT NULL,
            `log_local_type` varchar(20) DEFAULT NULL,
            `log_local_id` int(11) DEFAULT NULL,
            `log_status` varchar(20) DEFAULT NULL,
            `log_message` varchar(1000) DEFAULT NULL,
            `log_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`log_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // --- Analytics / reporting ----------------------------------------
        // Pre-aggregated daily ticket metrics and scheduled report configs.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_metrics_daily` (
            `metric_date` date NOT NULL,
            `company_id` int(11) NOT NULL,
            `opened` int(11) DEFAULT 0,
            `resolved` int(11) DEFAULT 0,
            `closed` int(11) DEFAULT 0,
            `backlog_open` int(11) DEFAULT 0,
            UNIQUE KEY `uniq_company_date` (`company_id`,`metric_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `report_schedules` (
            `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
            `schedule_report` varchar(60) NOT NULL,
            `schedule_frequency` varchar(20) NOT NULL,
            `schedule_recipients` text DEFAULT NULL,
            `schedule_last_sent` datetime DEFAULT NULL,
            `schedule_active` tinyint(4) DEFAULT 1,
            `schedule_created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`schedule_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // --- CRM / leads / opportunities ----------------------------------
        // Sales pipeline, lead qualification fields on clients, activity
        // timeline, saved segments and email campaigns.
        mysqli_query($mysqli, "ALTER TABLE `clients`
            ADD COLUMN IF NOT EXISTS `client_lead_source` varchar(60) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `client_lead_status` varchar(40) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `client_lead_owner` int(11) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `client_lead_score` int(11) DEFAULT NULL");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `opportunities` (
            `opportunity_id` int(11) NOT NULL AUTO_INCREMENT,
            `opportunity_name` varchar(255) NOT NULL,
            `opportunity_client_id` int(11) DEFAULT NULL,
            `opportunity_contact_id` int(11) DEFAULT NULL,
            `opportunity_stage` varchar(40) NOT NULL DEFAULT 'Qualification',
            `opportunity_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
            `opportunity_probability` tinyint(4) NOT NULL DEFAULT 0,
            `opportunity_close_date` date DEFAULT NULL,
            `opportunity_status` varchar(12) NOT NULL DEFAULT 'open',
            `opportunity_owner` int(11) DEFAULT NULL,
            `opportunity_notes` text DEFAULT NULL,
            `opportunity_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `opportunity_updated_at` datetime DEFAULT NULL,
            `opportunity_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`opportunity_id`),
            KEY `opportunity_client_id` (`opportunity_client_id`),
            KEY `opportunity_stage` (`opportunity_stage`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `crm_activities` (
            `activity_id` int(11) NOT NULL AUTO_INCREMENT,
            `activity_type` varchar(20) NOT NULL,
            `activity_subject` varchar(255) DEFAULT NULL,
            `activity_body` text DEFAULT NULL,
            `activity_related_type` varchar(12) NOT NULL,
            `activity_related_id` int(11) NOT NULL,
            `activity_at` datetime DEFAULT current_timestamp(),
            `activity_due_at` datetime DEFAULT NULL,
            `activity_reminder_at` datetime DEFAULT NULL,
            `activity_completed` tinyint(4) DEFAULT 0,
            `activity_owner` int(11) DEFAULT NULL,
            `activity_created_at` datetime DEFAULT current_timestamp(),
            `activity_reminded_at` datetime DEFAULT NULL,
            PRIMARY KEY (`activity_id`),
            KEY `activity_related` (`activity_related_type`,`activity_related_id`),
            KEY `activity_reminder` (`activity_reminder_at`,`activity_completed`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `crm_segments` (
            `segment_id` int(11) NOT NULL AUTO_INCREMENT,
            `segment_name` varchar(150) DEFAULT NULL,
            `segment_criteria_json` text DEFAULT NULL,
            `segment_created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`segment_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `crm_campaigns` (
            `campaign_id` int(11) NOT NULL AUTO_INCREMENT,
            `campaign_name` varchar(150) DEFAULT NULL,
            `campaign_segment_id` int(11) DEFAULT NULL,
            `campaign_subject` varchar(255) DEFAULT NULL,
            `campaign_body` mediumtext DEFAULT NULL,
            `campaign_status` varchar(20) DEFAULT 'draft',
            `campaign_sent_at` datetime DEFAULT NULL,
            `campaign_created_at` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`campaign_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `crm_campaign_recipients` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `campaign_id` int(11) DEFAULT NULL,
            `contact_id` int(11) DEFAULT NULL,
            `email` varchar(255) DEFAULT NULL,
            `sent` tinyint(4) DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `campaign_id` (`campaign_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // --- AI ticket-summary cache --------------------------------------
        // Cache of the generated ticket summary (includes/ai_functions.php).
        // This table was applied directly to beta and is NOT created by the
        // 2.6.29 AI block, so it is captured here to reach production.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `ticket_ai_summaries` (
            `ticket_id` int(11) NOT NULL,
            `summary_html` text DEFAULT NULL,
            `based_on_reply_id` int(11) DEFAULT NULL,
            `model_used` varchar(120) DEFAULT NULL,
            `tokens` int(11) DEFAULT NULL,
            `stale` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            UNIQUE KEY `uq_ticket_ai_summaries_ticket_id` (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.31'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.31') {

        // --- RMM connect/status preference (asset_rmm_links) --------------
        // An asset can be linked to more than one RMM integration at once
        // (e.g. both Tactical RMM and Level.io tracking the same physical
        // device). Wherever the app previously picked a single link to act
        // on (Connect/Remote button, status badges) it did so with no
        // ORDER BY, so whichever integration synced/linked last "won"
        // arbitrarily. This toggle lets admins pin that preference to
        // Tactical RMM (default) instead.
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_rmm_prefer_tactical` tinyint(1) NOT NULL DEFAULT 1");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.32'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.32') {

        // --- Payroll: gross-pay-only payroll runs from manually-entered hours ---
        // No tax withholding (federal/state/FICA) is calculated anywhere in this
        // feature - by design, that stays outside ITFlow. Hours are entered
        // manually for now (a future integration can poll an external timeclock
        // and upsert into payroll_hours_entries with source='api' - the schema
        // is deliberately shaped so that needs no redesign later).
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_payroll_overtime_threshold_hours` decimal(6,2) NOT NULL DEFAULT 40.00,
            ADD COLUMN IF NOT EXISTS `config_payroll_overtime_multiplier` decimal(4,2) NOT NULL DEFAULT 1.50,
            ADD COLUMN IF NOT EXISTS `config_payroll_default_pay_frequency` varchar(20) NOT NULL DEFAULT 'biweekly',
            ADD COLUMN IF NOT EXISTS `config_module_enable_payroll` tinyint(1) NOT NULL DEFAULT 0");

        // One CURRENT pay rate per enrolled employee - not effective-dated.
        // History/immutability comes from snapshotting into payroll_run_line_items
        // at compute time, not from versioning this table.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_pay_rates` (
            `payroll_pay_rate_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_pay_rate_user_id` int(11) NOT NULL,
            `payroll_pay_rate_type` enum('hourly','salary') NOT NULL DEFAULT 'hourly',
            `payroll_pay_rate_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_pay_rate_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_pay_rate_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `payroll_pay_rate_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`payroll_pay_rate_id`),
            KEY `idx_payroll_pay_rate_user` (`payroll_pay_rate_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // User-defined deduction categories (no tax/benefit rules built in).
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_deduction_categories` (
            `payroll_deduction_category_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_deduction_category_name` varchar(100) NOT NULL,
            `payroll_deduction_category_description` varchar(255) DEFAULT NULL,
            `payroll_deduction_category_order` int(11) NOT NULL DEFAULT 0,
            `payroll_deduction_category_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`payroll_deduction_category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Standing per-employee deduction assignment - applied every run until archived.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_employee_deductions` (
            `payroll_employee_deduction_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_employee_deduction_user_id` int(11) NOT NULL,
            `payroll_employee_deduction_category_id` int(11) NOT NULL,
            `payroll_employee_deduction_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_employee_deduction_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_employee_deduction_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `payroll_employee_deduction_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`payroll_employee_deduction_id`),
            KEY `idx_payroll_employee_deduction_user` (`payroll_employee_deduction_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Pay periods - status flips to 'locked' the moment its run is finalized.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_periods` (
            `payroll_period_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_period_start_date` date NOT NULL,
            `payroll_period_end_date` date NOT NULL,
            `payroll_period_status` enum('open','locked') NOT NULL DEFAULT 'open',
            `payroll_period_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_period_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `payroll_period_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`payroll_period_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Hours entries keyed by (employee, workweek) - NOT by period, so a
        // future timeclock-API pull can upsert here with source='api' without
        // any schema change or payroll-math change.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_hours_entries` (
            `payroll_hours_entry_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_hours_entry_user_id` int(11) NOT NULL,
            `payroll_hours_entry_week_start_date` date NOT NULL,
            `payroll_hours_entry_worked_time` time NOT NULL DEFAULT '00:00:00',
            `payroll_hours_entry_source` enum('manual','api') NOT NULL DEFAULT 'manual',
            `payroll_hours_entry_note` varchar(255) DEFAULT NULL,
            `payroll_hours_entry_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_hours_entry_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`payroll_hours_entry_id`),
            UNIQUE KEY `uq_payroll_hours_entry` (`payroll_hours_entry_user_id`, `payroll_hours_entry_week_start_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // One row per created/finalized payroll run.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_runs` (
            `payroll_run_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_run_period_id` int(11) NOT NULL,
            `payroll_run_status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
            `payroll_run_total_gross` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_total_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_total_net` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_currency_code` varchar(200) NOT NULL DEFAULT 'USD',
            `payroll_run_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_run_created_by` int(11) NOT NULL,
            `payroll_run_finalized_at` datetime DEFAULT NULL,
            `payroll_run_finalized_by` int(11) DEFAULT NULL,
            `payroll_run_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`payroll_run_id`),
            KEY `idx_payroll_run_period` (`payroll_run_period_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // One row per employee per run - every value is a SNAPSHOT taken at
        // compute time (rate, pay type, overtime rule, even the employee's
        // display name), so later edits elsewhere can never alter a historical
        // run. Mirrors the existing invoices -> invoice_items parent/child shape.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_run_line_items` (
            `payroll_run_line_item_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_run_line_item_run_id` int(11) NOT NULL,
            `payroll_run_line_item_user_id` int(11) NOT NULL,
            `payroll_run_line_item_employee_name_snapshot` varchar(200) NOT NULL,
            `payroll_run_line_item_pay_type_snapshot` enum('hourly','salary') NOT NULL,
            `payroll_run_line_item_rate_snapshot` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_overtime_threshold_snapshot` decimal(6,2) NOT NULL DEFAULT 40.00,
            `payroll_run_line_item_overtime_multiplier_snapshot` decimal(4,2) NOT NULL DEFAULT 1.50,
            `payroll_run_line_item_regular_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_overtime_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_regular_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_overtime_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_gross_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_total_deductions` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_net_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_currency_code` varchar(200) NOT NULL DEFAULT 'USD',
            `payroll_run_line_item_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_run_line_item_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`payroll_run_line_item_id`),
            KEY `idx_payroll_run_line_item_run` (`payroll_run_line_item_run_id`),
            KEY `idx_payroll_run_line_item_user` (`payroll_run_line_item_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Itemized per-employee-per-run deduction breakdown - mirrors
        // invoices -> invoice_items again.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_run_line_item_deductions` (
            `payroll_run_line_item_deduction_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_run_line_item_deduction_line_item_id` int(11) NOT NULL,
            `payroll_run_line_item_deduction_category_id` int(11) DEFAULT NULL,
            `payroll_run_line_item_deduction_category_name_snapshot` varchar(200) NOT NULL,
            `payroll_run_line_item_deduction_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`payroll_run_line_item_deduction_id`),
            KEY `idx_payroll_run_line_item_deduction_item` (`payroll_run_line_item_deduction_line_item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.33'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.33') {

        // --- Payroll: per-job pay rate overrides ---------------------------
        // Sometimes an employee is paid a different one-off rate for a
        // specific job (ticket) than their standard rate - e.g. hazard pay,
        // a rush job, a special engagement. These hours are tracked
        // separately from the weekly hourly/salary bucket and do NOT
        // participate in the weekly overtime threshold calculation - they are
        // always paid flat as hours x the one-off rate entered for that job.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_job_hours_entries` (
            `payroll_job_hours_entry_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_job_hours_entry_period_id` int(11) NOT NULL,
            `payroll_job_hours_entry_user_id` int(11) NOT NULL,
            `payroll_job_hours_entry_ticket_id` int(11) NOT NULL,
            `payroll_job_hours_entry_worked_time` time NOT NULL DEFAULT '00:00:00',
            `payroll_job_hours_entry_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_job_hours_entry_note` varchar(255) DEFAULT NULL,
            `payroll_job_hours_entry_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `payroll_job_hours_entry_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`payroll_job_hours_entry_id`),
            KEY `idx_payroll_job_hours_entry_period` (`payroll_job_hours_entry_period_id`),
            KEY `idx_payroll_job_hours_entry_user` (`payroll_job_hours_entry_user_id`),
            KEY `idx_payroll_job_hours_entry_ticket` (`payroll_job_hours_entry_ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Snapshot totals on the run line item, mirroring regular/overtime.
        mysqli_query($mysqli, "ALTER TABLE `payroll_run_line_items`
            ADD COLUMN IF NOT EXISTS `payroll_run_line_item_job_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
            ADD COLUMN IF NOT EXISTS `payroll_run_line_item_job_pay` decimal(15,2) NOT NULL DEFAULT 0.00");

        // Itemized per-job breakdown, mirrors payroll_run_line_item_deductions.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `payroll_run_line_item_jobs` (
            `payroll_run_line_item_job_id` int(11) NOT NULL AUTO_INCREMENT,
            `payroll_run_line_item_job_line_item_id` int(11) NOT NULL,
            `payroll_run_line_item_job_ticket_id` int(11) DEFAULT NULL,
            `payroll_run_line_item_job_ticket_label_snapshot` varchar(255) NOT NULL,
            `payroll_run_line_item_job_hours` decimal(7,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_job_rate` decimal(15,2) NOT NULL DEFAULT 0.00,
            `payroll_run_line_item_job_pay` decimal(15,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`payroll_run_line_item_job_id`),
            KEY `idx_payroll_run_line_item_job_item` (`payroll_run_line_item_job_line_item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.34'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.34') {

        // AI provider API keys are now stored encrypted (ENC:<base64 iv+ciphertext>) instead of
        // plaintext - widen the column so the encrypted value (larger than the original plaintext)
        // never gets silently truncated, matching every other encrypted-secret column in this app
        // (mailbox_imap_password_enc, mailbox_oauth_refresh_token_enc, accounting_client_secret),
        // all of which use `text` rather than a fixed-length varchar.
        mysqli_query($mysqli, "ALTER TABLE `ai_providers`
            MODIFY COLUMN `ai_provider_api_key` text DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.35'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.35') {

        // Seed sensible default colors on the built-in asset_status categories so the
        // status pill on the assets list is color-coded out of the box - these rows were
        // originally inserted without a category_color, leaving the color-coding feature
        // invisible until an admin manually configured colors via Settings > Categories.
        // Only touch rows that are still unconfigured (color IS NULL) so any admin who
        // already customized a status color is left alone.
        mysqli_query($mysqli, "UPDATE `categories` SET `category_color` = '#0dcaf0' WHERE `category_type` = 'asset_status' AND `category_name` = 'Ready to Deploy' AND `category_color` IS NULL");
        mysqli_query($mysqli, "UPDATE `categories` SET `category_color` = '#198754' WHERE `category_type` = 'asset_status' AND `category_name` = 'Deployed' AND `category_color` IS NULL");
        mysqli_query($mysqli, "UPDATE `categories` SET `category_color` = '#fd7e14' WHERE `category_type` = 'asset_status' AND `category_name` = 'Out for Repair' AND `category_color` IS NULL");
        mysqli_query($mysqli, "UPDATE `categories` SET `category_color` = '#dc3545' WHERE `category_type` = 'asset_status' AND `category_name` = 'Lost' AND `category_color` IS NULL");
        mysqli_query($mysqli, "UPDATE `categories` SET `category_color` = '#dc3545' WHERE `category_type` = 'asset_status' AND `category_name` = 'Stolen' AND `category_color` IS NULL");
        mysqli_query($mysqli, "UPDATE `categories` SET `category_color` = '#6c757d' WHERE `category_type` = 'asset_status' AND `category_name` = 'Retired' AND `category_color` IS NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.36'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.36') {

        // CSAT: upgrade the binary Good/Bad ticket_feedback rating to a proper 1-5
        // star scale + optional comment. ticket_feedback is left in place, untouched,
        // and is no longer written to by any handler after this version - kept only
        // so nothing reading it directly at the DB level breaks.
        mysqli_query($mysqli, "ALTER TABLE `tickets`
            ADD COLUMN IF NOT EXISTS `ticket_csat_rating` tinyint(4) DEFAULT NULL COMMENT '1-5 CSAT star rating, NULL = not yet rated' AFTER `ticket_feedback`,
            ADD COLUMN IF NOT EXISTS `ticket_csat_comment` text DEFAULT NULL AFTER `ticket_csat_rating`,
            ADD COLUMN IF NOT EXISTS `ticket_csat_rated_at` datetime DEFAULT NULL AFTER `ticket_csat_comment`,
            ADD COLUMN IF NOT EXISTS `ticket_csat_reminded_at` datetime DEFAULT NULL AFTER `ticket_csat_rated_at`");

        // One-time backfill so legacy Good/Bad tickets still contribute to the new
        // 1-5 average/trend views instead of CSAT history appearing to start empty
        // on upgrade day. Good -> 5, Bad -> 1 (the two extremes of the new scale -
        // the only honest mapping of a binary rating onto a 5-point one). There's no
        // historical timestamp for when the old rating was submitted, so
        // ticket_closed_at (the earliest point a rating could have been given) is
        // used as the closest available approximation. Idempotent: only touches rows
        // that don't already have a rating.
        mysqli_query($mysqli, "UPDATE `tickets`
            SET `ticket_csat_rating` = CASE `ticket_feedback` WHEN 'Good' THEN 5 WHEN 'Bad' THEN 1 ELSE NULL END,
                `ticket_csat_rated_at` = `ticket_closed_at`
            WHERE `ticket_feedback` IN ('Good', 'Bad') AND `ticket_csat_rating` IS NULL");

        // Settings: master enable + reminder delay + low-rating follow-up threshold.
        mysqli_query($mysqli, "ALTER TABLE `settings`
            ADD COLUMN IF NOT EXISTS `config_ticket_csat_enable` tinyint(1) NOT NULL DEFAULT 1 AFTER `config_ticket_autoclose_hours`,
            ADD COLUMN IF NOT EXISTS `config_ticket_csat_reminder_days` int(5) NOT NULL DEFAULT 3 AFTER `config_ticket_csat_enable`,
            ADD COLUMN IF NOT EXISTS `config_ticket_csat_low_rating_threshold` tinyint(4) NOT NULL DEFAULT 2 AFTER `config_ticket_csat_reminder_days`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.37'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.37') {
        // Adds an atomic-claim state to the QBO sync queue: cron/cron.php (hourly),
        // cron/accounting_sync_standalone.php (every 15m), and the "sync now" button
        // in admin/settings_accounting.php can all pick up the same pending job
        // concurrently. 'processing' lets accountingSyncProcessJob() claim a job with
        // a single conditional UPDATE before doing any work, closing that race.
        mysqli_query($mysqli, "ALTER TABLE `accounting_sync_queue` MODIFY COLUMN `queue_status` enum('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending'");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.38'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.38') {
        // rmm_scripts.tactical_script_id is a single int reused for BOTH Tactical
        // RMM and Level.io's numeric script IDs, with no per-integration scoping -
        // a Level script whose ID happens to collide with an existing Tactical
        // script's ID silently overwrites that row's name/body/type on sync. Adds
        // rmm_integration_id so agent/post/rmm_sync.php can scope the lookup.
        // Existing rows predate Level support and were all synced from the
        // Tactical integration, so they backfill to that integration's id.
        mysqli_query($mysqli, "ALTER TABLE `rmm_scripts` ADD COLUMN IF NOT EXISTS `rmm_integration_id` int(11) NOT NULL DEFAULT 0 AFTER `tactical_script_id`");
        mysqli_query($mysqli, "UPDATE `rmm_scripts` SET `rmm_integration_id` = (SELECT id FROM rmm_integrations WHERE type = 'tactical_rmm' ORDER BY id ASC LIMIT 1) WHERE `rmm_integration_id` = 0");
        mysqli_query($mysqli, "ALTER TABLE `rmm_scripts` ADD INDEX IF NOT EXISTS `idx_integration_script` (`rmm_integration_id`, `tactical_script_id`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.39'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.39') {
        // Optional CTA shown to clients after a 4-5 star CSAT rating, prompting a
        // public Google review. Starts empty/unconfigured (CTA stays hidden until
        // an admin sets it in Settings > Ticketing).
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_ticket_csat_google_review_url` varchar(255) DEFAULT NULL AFTER `config_ticket_csat_low_rating_threshold`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.40'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.40') {
        // Mobile app crash reports (POST /api/v1/crash-reports) are logged via the
        // existing logApp() -> app_logs pipeline (category 'mobile_crash') so they
        // show up in the existing App Logs admin page instead of needing a bespoke
        // new screen. app_log_details was VARCHAR(1000), too small to hold a full
        // Kotlin stack trace plus device/app metadata - widen it to TEXT.
        mysqli_query($mysqli, "ALTER TABLE `app_logs` MODIFY COLUMN `app_log_details` TEXT NULL DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.41'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.41') {
        // Included remote-support hours for clients whose subscription covers a
        // certain amount of support time per month. NULL = feature inactive for
        // that client (default for everyone). Usage is always computed live from
        // ticket_replies.ticket_reply_time_worked for the current calendar month -
        // no stored balance/reset cron needed.
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_support_hours_included` decimal(6,2) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.42'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.42') {
        // Replace the hours-based included-support allowance with a per-month
        // issue COUNT, split remote vs onsite - matches how residential
        // subscription plans are actually sold (e.g. "3 remote issues/mo"),
        // doesn't depend on technicians consistently logging worked time, and
        // isn't skewed by how fast/slow a given issue was resolved. NULL =
        // feature inactive for that allowance (default for everyone). No client
        // had a non-null client_support_hours_included set on either host at the
        // time of this migration, so there is nothing to carry forward.
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_support_issues_included_remote` int(11) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_support_issues_included_onsite` int(11) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `clients` DROP COLUMN IF EXISTS `client_support_hours_included`");

        // Which allowance a ticket counts against. NULL = not classified (most
        // tickets, e.g. anyone without an issues-included plan configured).
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `ticket_delivery_method` varchar(20) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.43'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.43') {
        // Every one of these tables carried only a PRIMARY KEY - every
        // client-scoped lookup on agent/client_overview.php (and, for
        // ticket_replies, every ticket detail page load) was a full table
        // scan. Cheap and safe at current row counts; closes off page-load
        // degradation as data grows. See EXPLAIN evidence from the perf audit
        // that prompted this - tickets/logs/ticket_replies confirmed type=ALL
        // before this migration.
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD INDEX IF NOT EXISTS `idx_tickets_client_archived_updated` (`ticket_client_id`, `ticket_archived_at`, `ticket_updated_at`)");
        mysqli_query($mysqli, "ALTER TABLE `logs` ADD INDEX IF NOT EXISTS `idx_logs_client_created` (`log_client_id`, `log_created_at`)");
        mysqli_query($mysqli, "ALTER TABLE `ticket_replies` ADD INDEX IF NOT EXISTS `idx_ticket_replies_ticket_archived` (`ticket_reply_ticket_id`, `ticket_reply_archived_at`)");
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD INDEX IF NOT EXISTS `idx_assets_client_archived` (`asset_client_id`, `asset_archived_at`)");
        mysqli_query($mysqli, "ALTER TABLE `credentials` ADD INDEX IF NOT EXISTS `idx_credentials_client_archived` (`credential_client_id`, `credential_archived_at`)");
        mysqli_query($mysqli, "ALTER TABLE `domains` ADD INDEX IF NOT EXISTS `idx_domains_client_archived_expire` (`domain_client_id`, `domain_archived_at`, `domain_expire`)");
        mysqli_query($mysqli, "ALTER TABLE `certificates` ADD INDEX IF NOT EXISTS `idx_certificates_client_archived_expire` (`certificate_client_id`, `certificate_archived_at`, `certificate_expire`)");
        mysqli_query($mysqli, "ALTER TABLE `software` ADD INDEX IF NOT EXISTS `idx_software_client_archived_expire` (`software_client_id`, `software_archived_at`, `software_expire`)");
        mysqli_query($mysqli, "ALTER TABLE `shared_items` ADD INDEX IF NOT EXISTS `idx_shared_items_client_active_created` (`item_client_id`, `item_active`, `item_created_at`)");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD INDEX IF NOT EXISTS `idx_contacts_client_archived` (`contact_client_id`, `contact_archived_at`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.44'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.44') {
        // Defense in depth for /opt/scripts/itflow_rmm_sync.php's alert-sync
        // loop (SELECT-then-INSERT, no transaction): a proper flock() mutex
        // was added there to stop overlapping cron runs, but a unique
        // constraint closes the same gap at the DB layer too, in case
        // anything else ever writes here without going through that lock.
        mysqli_query($mysqli, "ALTER TABLE `rmm_alerts` ADD UNIQUE INDEX IF NOT EXISTS `uniq_integration_alert` (`integration_id`, `tactical_alert_id`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.45'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.45') {
        // Admin > Settings > Tickets > Default Ticket Assignee. 0 = not set
        // (matches this codebase's 0-sentinel convention for other nullable
        // FK-style ticket settings, e.g. no separate "enabled" flag needed).
        // Applied at ticket-creation time via resolveTicketAssignee() in
        // functions.php - fills in ticket_assigned_to only when nothing else
        // (an explicit form field, a recurring ticket's own stored assignee,
        // an automation rule) already set it.
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_ticket_default_technician_id` int(11) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.46'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.46') {
        // Move the included-issues allowance from the client to the contract.
        // A client can have several active contracts (tickets already pick one
        // via ticket_contract_id for SLA purposes) - a single client-level
        // number couldn't represent "3 remote/mo on the residential plan, 10
        // remote/mo on the managed-services plan" for a client on both.
        // Usage (getContractIncludedIssuesUsage()) is now scored per contract,
        // matched on ticket_contract_id instead of just ticket_client_id.
        mysqli_query($mysqli, "ALTER TABLE `contracts` ADD COLUMN IF NOT EXISTS `contract_support_issues_included_remote` int(11) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `contracts` ADD COLUMN IF NOT EXISTS `contract_support_issues_included_onsite` int(11) DEFAULT NULL");

        // Carry forward existing client-level values, but only where the
        // destination is unambiguous: a client with exactly one active,
        // non-archived contract. A client with zero or multiple active
        // contracts has no single correct contract to attribute the old
        // client-level number to, so those are left unmigrated - re-enter the
        // allowance on the correct contract by hand for any such client.
        mysqli_query($mysqli, "
            UPDATE contracts c
            JOIN (
                SELECT contract_client_id
                FROM contracts
                WHERE contract_status = 'Active' AND contract_archived_at IS NULL
                GROUP BY contract_client_id
                HAVING COUNT(*) = 1
            ) one ON one.contract_client_id = c.contract_client_id
            JOIN clients cl ON cl.client_id = c.contract_client_id
            SET c.contract_support_issues_included_remote = cl.client_support_issues_included_remote,
                c.contract_support_issues_included_onsite = cl.client_support_issues_included_onsite
            WHERE c.contract_status = 'Active' AND c.contract_archived_at IS NULL
              AND (cl.client_support_issues_included_remote IS NOT NULL OR cl.client_support_issues_included_onsite IS NOT NULL)
        ");

        mysqli_query($mysqli, "ALTER TABLE `clients` DROP COLUMN IF EXISTS `client_support_issues_included_remote`");
        mysqli_query($mysqli, "ALTER TABLE `clients` DROP COLUMN IF EXISTS `client_support_issues_included_onsite`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.47'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.47') {
        // Public CSAT API (GET /api/v1/csat) only publishes a rating's comment
        // when a staff member has explicitly opted it in - CSAT comments were
        // captured with "anything you'd like to add? (optional)", never with
        // the client's knowledge it might be quoted on the public website, and
        // free text can carry PII (names, phone numbers) the structured fields
        // deliberately exclude. Defaults to 0 (not approved) for every existing
        // rating, so nothing already collected goes public without review.
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `ticket_csat_public_approved` tinyint(1) NOT NULL DEFAULT 0 AFTER `ticket_csat_rated_at`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.48'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.48') {
        // Dedicated AnyDesk ID field for assets, with a one-click "anydesk:"
        // launch link on the asset details page - separate from the generic
        // asset_uri/_2/_client fields so it's discoverable without needing to
        // know AnyDesk's URI scheme.
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD COLUMN IF NOT EXISTS `asset_anydesk_id` varchar(50) DEFAULT NULL AFTER `asset_uri_client`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.49'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.49') {
        // CRM (Pipeline/Opportunities/Leads/Campaigns/Segments) is MSP sales-
        // pipeline terminology that doesn't fit an internal-IT department -
        // off by default here, same reasoning as the accounting module.
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_module_enable_crm` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_module_enable_payroll`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.50'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.50') {
        // Master-plan Phase 0: central audit trail. Append-only from the app's
        // perspective - nothing in agent/admin code gets an UPDATE/DELETE path
        // for this table, only INSERT via src/Audit/AuditService.php.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `audit_events` (
            `audit_id` int(11) NOT NULL AUTO_INCREMENT,
            `event_type` varchar(100) NOT NULL,
            `actor_user_id` int(11) DEFAULT NULL,
            `entity_type` varchar(100) DEFAULT NULL,
            `entity_id` varchar(64) DEFAULT NULL,
            `action` varchar(50) NOT NULL,
            `summary` varchar(500) DEFAULT NULL,
            `metadata_json` text DEFAULT NULL,
            `ip_address` varchar(64) DEFAULT NULL,
            `user_agent` varchar(255) DEFAULT NULL,
            `request_id` varchar(64) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`audit_id`),
            KEY `idx_audit_events_type_created` (`event_type`, `created_at`),
            KEY `idx_audit_events_entity` (`entity_type`, `entity_id`),
            KEY `idx_audit_events_actor` (`actor_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.51'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.51') {
        // Master-plan Phase 0: DB-backed job queue (Section 33) - foundation
        // for async Microsoft/Odoo/RMM sync work in later phases. Nothing
        // enqueues jobs yet; this just makes the table/worker exist first.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `integration_jobs` (
            `job_id` int(11) NOT NULL AUTO_INCREMENT,
            `integration_id` int(11) DEFAULT NULL,
            `job_type` varchar(100) NOT NULL,
            `resource_type` varchar(100) DEFAULT NULL,
            `status` enum('pending','running','completed','failed','dead_letter') NOT NULL DEFAULT 'pending',
            `priority` int(11) NOT NULL DEFAULT 0,
            `attempts` int(11) NOT NULL DEFAULT 0,
            `max_attempts` int(11) NOT NULL DEFAULT 5,
            `available_at` datetime NOT NULL DEFAULT current_timestamp(),
            `started_at` datetime DEFAULT NULL,
            `completed_at` datetime DEFAULT NULL,
            `payload` text DEFAULT NULL,
            `result` text DEFAULT NULL,
            `error` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`job_id`),
            KEY `idx_integration_jobs_status_available` (`status`, `available_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.52'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.52') {
        // Master-plan Phase 1: organization-level fields (Section 6.1).
        // Added to the existing single companies row rather than a new
        // parallel `organizations` table - company_id=1 already IS the one
        // organization, a second table would just be a second source of
        // truth for the same row.
        mysqli_query($mysqli, "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `company_ms_tenant_id` varchar(100) DEFAULT NULL AFTER `company_tax_id`");
        mysqli_query($mysqli, "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `company_default_email_domain` varchar(200) DEFAULT NULL AFTER `company_ms_tenant_id`");
        mysqli_query($mysqli, "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `company_security_contact_email` varchar(200) DEFAULT NULL AFTER `company_default_email_domain`");
        mysqli_query($mysqli, "ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `company_hr_contact_email` varchar(200) DEFAULT NULL AFTER `company_security_contact_email`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.53'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.53') {
        // Master-plan Phase 1: department fields (Section 6.2). client_status
        // is a free-text label (not a rigid enum) matching how asset_status
        // works elsewhere in this app - client_archived_at already covers
        // the hard archived/active boundary, this is a softer descriptive
        // status on top of that.
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_parent_id` int(11) DEFAULT NULL AFTER `client_id`");
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_head_contact_id` int(11) DEFAULT NULL AFTER `client_parent_id`");
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_cost_center` varchar(100) DEFAULT NULL AFTER `client_head_contact_id`");
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_status` varchar(50) NOT NULL DEFAULT 'Active' AFTER `client_cost_center`");
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_security_classification` enum('General','Confidential','Restricted') NOT NULL DEFAULT 'General' AFTER `client_status`");

        // Master-plan Phase 1: site (location) fields (Section 6.3).
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD COLUMN IF NOT EXISTS `location_type` varchar(50) DEFAULT NULL AFTER `location_name`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD COLUMN IF NOT EXISTS `location_manager_contact_id` int(11) DEFAULT NULL AFTER `location_type`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD COLUMN IF NOT EXISTS `location_emergency_contacts` text DEFAULT NULL AFTER `location_hours`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD COLUMN IF NOT EXISTS `location_shipping_instructions` text DEFAULT NULL AFTER `location_emergency_contacts`");

        // Master-plan Phase 1: department_sites many-to-many (Section 6.3).
        // Additive only - location_client_id (single owner) stays the primary
        // relationship every existing query/page already uses; this junction
        // table is available for "one site serves several departments" once
        // something is built to use it. Not yet wired into any UI.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `department_sites` (
            `department_site_id` int(11) NOT NULL AUTO_INCREMENT,
            `client_id` int(11) NOT NULL,
            `location_id` int(11) NOT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`department_site_id`),
            UNIQUE KEY `uniq_department_site` (`client_id`, `location_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.54'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.54') {
        // Master-plan Phase 2: People directory fields on the existing
        // `contacts` table (Section 7.1/7.2) - not a new parallel `people`
        // table. contacts already carries name/email/phone/location/client
        // linkage and is already what every ticket/asset/credential
        // assignment references; a second person table would fork that.
        // contact_manager_id is self-referential (another row in the same
        // table), matching Section 7.1's manager relationship.
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_employee_id` varchar(50) DEFAULT NULL AFTER `contact_name`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_employee_type` varchar(30) NOT NULL DEFAULT 'employee' AFTER `contact_employee_id`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_manager_id` int(11) DEFAULT NULL AFTER `contact_employee_type`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_employment_status` varchar(30) NOT NULL DEFAULT 'active' AFTER `contact_manager_id`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_work_arrangement` varchar(20) DEFAULT NULL AFTER `contact_employment_status`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_start_date` date DEFAULT NULL AFTER `contact_work_arrangement`");
        mysqli_query($mysqli, "ALTER TABLE `contacts` ADD COLUMN IF NOT EXISTS `contact_expected_end_date` date DEFAULT NULL AFTER `contact_start_date`");

        // Master-plan Phase 2: import history (Section 7.4) - one row per
        // CSV import run, so admins can see what was imported and when.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `people_import_runs` (
            `import_run_id` int(11) NOT NULL AUTO_INCREMENT,
            `imported_by_user_id` int(11) DEFAULT NULL,
            `original_filename` varchar(255) DEFAULT NULL,
            `row_count` int(11) NOT NULL DEFAULT 0,
            `created_count` int(11) NOT NULL DEFAULT 0,
            `updated_count` int(11) NOT NULL DEFAULT 0,
            `skipped_count` int(11) NOT NULL DEFAULT 0,
            `status` enum('previewed','approved','failed') NOT NULL DEFAULT 'previewed',
            `results_json` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`import_run_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.55'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.55') {
        // Master-plan Phase 3 & 8: Microsoft Entra/Graph and Odoo
        // integration config tables, mirroring the existing
        // accounting_integrations/unifi_integrations shape. Scaffolding
        // only per PROGRESS.md decision log - no real tenant/API
        // credentials exist yet, enabled defaults to 0.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `microsoft_integrations` (
            `microsoft_integration_id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` varchar(100) DEFAULT NULL,
            `client_id` varchar(100) DEFAULT NULL,
            `client_secret_enc` text DEFAULT NULL,
            `sync_scope` varchar(20) NOT NULL DEFAULT 'read_only',
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `last_test_at` datetime DEFAULT NULL,
            `last_test_success` tinyint(1) DEFAULT NULL,
            `last_test_error` varchar(500) DEFAULT NULL,
            `last_sync_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`microsoft_integration_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `odoo_integrations` (
            `odoo_integration_id` int(11) NOT NULL AUTO_INCREMENT,
            `base_url` varchar(255) DEFAULT NULL,
            `database_name` varchar(100) DEFAULT NULL,
            `username` varchar(200) DEFAULT NULL,
            `api_key_enc` text DEFAULT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `last_test_at` datetime DEFAULT NULL,
            `last_test_success` tinyint(1) DEFAULT NULL,
            `last_test_error` varchar(500) DEFAULT NULL,
            `last_sync_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`odoo_integration_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.56'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.56') {
        // Master-plan Phase 6: asset assignment history (Section 11.5).
        // assets.asset_contact_id stays the current-assignment pointer
        // every existing query already uses - this is additive: a
        // returned_at IS NULL row is "currently assigned", closed-out rows
        // are history. Written by src/Assets/AssetAssignmentService.php.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `asset_assignments` (
            `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
            `asset_id` int(11) NOT NULL,
            `contact_id` int(11) NOT NULL,
            `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
            `returned_at` datetime DEFAULT NULL,
            `assigned_by` int(11) DEFAULT NULL,
            `returned_by` int(11) DEFAULT NULL,
            PRIMARY KEY (`assignment_id`),
            KEY `idx_asset_assignments_asset` (`asset_id`, `returned_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.57'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.57') {
        // Master-plan Phase 9: employee lifecycle workflow engine, manual-
        // first version (Sections 16-18/22-23), matching Section 53's
        // recommended first-release scope ("manual-first onboarding and
        // offboarding workflows"). Deliberately NOT built this pass:
        // task dependencies/blocking, approvals, automation actions, and
        // relative-due-date scheduling (Section 16.1/16.2's fuller model) -
        // those need real usage first to know if the added complexity is
        // worth it. This is a checklist tied to a person, with history.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `workflow_templates` (
            `workflow_template_id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(200) NOT NULL,
            `type` enum('onboarding','offboarding') NOT NULL,
            `description` text DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_by` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`workflow_template_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `workflow_template_tasks` (
            `template_task_id` int(11) NOT NULL AUTO_INCREMENT,
            `workflow_template_id` int(11) NOT NULL,
            `title` varchar(255) NOT NULL,
            `instructions` text DEFAULT NULL,
            `category` varchar(100) DEFAULT NULL,
            `default_owner` varchar(100) DEFAULT NULL,
            `required` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`template_task_id`),
            KEY `idx_template_task_template` (`workflow_template_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `workflow_runs` (
            `run_id` int(11) NOT NULL AUTO_INCREMENT,
            `workflow_template_id` int(11) DEFAULT NULL,
            `contact_id` int(11) NOT NULL,
            `type` enum('onboarding','offboarding') NOT NULL,
            `status` enum('in_progress','completed_with_exceptions','completed','cancelled') NOT NULL DEFAULT 'in_progress',
            `started_by` int(11) DEFAULT NULL,
            `started_at` datetime NOT NULL DEFAULT current_timestamp(),
            `completed_at` datetime DEFAULT NULL,
            `notes` text DEFAULT NULL,
            PRIMARY KEY (`run_id`),
            KEY `idx_workflow_runs_contact` (`contact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Tasks are snapshotted from the template onto the run (title/
        // instructions copied at start time) so editing a template later
        // doesn't rewrite the history of runs already in progress or done.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `workflow_run_tasks` (
            `run_task_id` int(11) NOT NULL AUTO_INCREMENT,
            `run_id` int(11) NOT NULL,
            `title` varchar(255) NOT NULL,
            `instructions` text DEFAULT NULL,
            `category` varchar(100) DEFAULT NULL,
            `default_owner` varchar(100) DEFAULT NULL,
            `required` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `status` enum('pending','completed','skipped') NOT NULL DEFAULT 'pending',
            `completed_by` int(11) DEFAULT NULL,
            `completed_at` datetime DEFAULT NULL,
            `skip_reason` varchar(500) DEFAULT NULL,
            PRIMARY KEY (`run_task_id`),
            KEY `idx_run_task_run` (`run_id`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.58'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.58') {
        // Master-plan Phase 4: Vault V2 - credential rotation due dates +
        // a version history snapshotted on every edit (Section 15).
        mysqli_query($mysqli, "ALTER TABLE `credentials` ADD COLUMN IF NOT EXISTS `credential_rotation_due_at` date DEFAULT NULL AFTER `credential_password_changed_at`");
        mysqli_query($mysqli, "ALTER TABLE `credentials` ADD COLUMN IF NOT EXISTS `credential_last_rotated_at` datetime DEFAULT NULL AFTER `credential_rotation_due_at`");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `credential_versions` (
            `version_id` int(11) NOT NULL AUTO_INCREMENT,
            `version_credential_id` int(11) NOT NULL,
            `version_changed_by` int(11) NOT NULL DEFAULT 0,
            `version_changed_by_name` varchar(200) NOT NULL DEFAULT '',
            `version_previous_username_enc` varbinary(500) DEFAULT NULL,
            `version_previous_password_enc` varbinary(500) DEFAULT NULL,
            `version_changed_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`version_id`),
            KEY `idx_credential_versions_credential` (`version_credential_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.59'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.59') {
        // Master-plan Phase 5: Knowledge Base V2 - review-due tracking +
        // full version history on every edit (Section 14).
        mysqli_query($mysqli, "ALTER TABLE `kb_articles` ADD COLUMN IF NOT EXISTS `kb_article_review_due_at` date DEFAULT NULL AFTER `kb_article_category_id`");
        mysqli_query($mysqli, "ALTER TABLE `kb_articles` ADD COLUMN IF NOT EXISTS `kb_article_reviewer_user_id` int(11) DEFAULT NULL AFTER `kb_article_review_due_at`");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `kb_article_versions` (
            `kb_article_version_id` int(11) NOT NULL AUTO_INCREMENT,
            `kb_article_version_kb_article_id` int(11) NOT NULL,
            `kb_article_version_content` mediumtext DEFAULT NULL,
            `kb_article_version_content_raw` mediumtext DEFAULT NULL,
            `kb_article_version_edited_by` int(11) DEFAULT NULL,
            `kb_article_version_edited_at` datetime DEFAULT current_timestamp(),
            `kb_article_version_number` int(11) NOT NULL DEFAULT 1,
            PRIMARY KEY (`kb_article_version_id`),
            KEY `kb_article_version_kb_article_id` (`kb_article_version_kb_article_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.60'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.60') {
        // Master-plan Phase 10: Service Catalog (Section 27.1, scoped down -
        // a flat list of requestable items that pre-fill a new ticket, no
        // dynamic form builder yet).
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `service_catalog_items` (
            `catalog_item_id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(200) NOT NULL,
            `description` text DEFAULT NULL,
            `icon` varchar(100) DEFAULT NULL,
            `ticket_subject_template` varchar(500) DEFAULT NULL,
            `ticket_category_id` int(11) DEFAULT NULL,
            `default_priority` varchar(200) DEFAULT NULL,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`catalog_item_id`),
            KEY `ticket_category_id` (`ticket_category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.61'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.61') {
        // Master-plan Phase 11: Incident/Problem/Change management -
        // problems and changes as first-class records, tickets optionally
        // linked to a problem.
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `ticket_problem_id` int(11) DEFAULT NULL AFTER `ticket_contract_id`");
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD INDEX IF NOT EXISTS `idx_tickets_problem` (`ticket_problem_id`)");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `changes` (
            `change_id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `reason` text DEFAULT NULL,
            `impact` text DEFAULT NULL,
            `risk` enum('low','medium','high') NOT NULL DEFAULT 'low',
            `implementation_plan` text DEFAULT NULL,
            `rollback_plan` text DEFAULT NULL,
            `scheduled_at` datetime DEFAULT NULL,
            `status` enum('draft','awaiting_approval','approved','scheduled','in_progress','successful','failed','rolled_back','cancelled') NOT NULL DEFAULT 'draft',
            `created_by` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`change_id`),
            KEY `idx_changes_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `problems` (
            `problem_id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `status` enum('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
            `change_problem_id` int(11) DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `resolved_at` datetime DEFAULT NULL,
            PRIMARY KEY (`problem_id`),
            KEY `idx_problems_status` (`status`),
            KEY `idx_problems_change` (`change_problem_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.62'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.62') {
        // Master-plan Phase 12: Automation, Webhooks V2, API v2 (Section 35) -
        // a synchronous direct-delivery log distinct from the existing
        // ticket-scoped async webhook_queue/cron.php path, plus a first
        // automation-rule table (trigger -> condition -> action).
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
            `delivery_id` int(11) NOT NULL AUTO_INCREMENT,
            `webhook_id` int(11) NOT NULL,
            `event_type` varchar(150) NOT NULL,
            `http_status` smallint(6) DEFAULT NULL,
            `duration_ms` int(11) NOT NULL DEFAULT 0,
            `attempt_number` tinyint(3) NOT NULL DEFAULT 1,
            `request_payload_json` longtext DEFAULT NULL,
            `response_body_snippet` varchar(1000) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`delivery_id`),
            KEY `idx_webhook_deliveries_webhook` (`webhook_id`, `created_at`),
            KEY `idx_webhook_deliveries_event` (`event_type`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `automation_rules` (
            `rule_id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(200) NOT NULL,
            `trigger_event` varchar(150) NOT NULL,
            `condition_json` text DEFAULT NULL,
            `action_type` enum('create_ticket','send_webhook','notify_user') NOT NULL,
            `action_config_json` text DEFAULT NULL,
            `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`rule_id`),
            KEY `idx_automation_rules_trigger` (`trigger_event`, `is_enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.63'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.63') {
        // Master-plan Phase 7: Microsoft Intune device sync into the CMDB -
        // reuses the Phase 3 Microsoft/Entra app registration (GraphClient),
        // just with the additional DeviceManagementManagedDevices.Read.All
        // permission granted separately in the Azure/Entra portal.
        mysqli_query($mysqli, "ALTER TABLE `microsoft_integrations` ADD COLUMN IF NOT EXISTS `intune_sync_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `enabled`");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `asset_intune_links` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `asset_id` int(11) NOT NULL,
            `microsoft_integration_id` int(11) NOT NULL,
            `intune_device_id` varchar(100) NOT NULL,
            `azure_ad_device_id` varchar(100) DEFAULT NULL,
            `hostname` varchar(200) DEFAULT NULL,
            `serial_number` varchar(200) DEFAULT NULL,
            `os_name` varchar(100) DEFAULT NULL,
            `os_version` varchar(100) DEFAULT NULL,
            `manufacturer` varchar(200) DEFAULT NULL,
            `model` varchar(200) DEFAULT NULL,
            `management_agent` varchar(100) DEFAULT NULL,
            `compliance_state` varchar(50) DEFAULT NULL,
            `is_encrypted` tinyint(1) DEFAULT NULL,
            `primary_user_upn` varchar(200) DEFAULT NULL,
            `enrolled_at` datetime DEFAULT NULL,
            `intune_last_sync_at` datetime DEFAULT NULL,
            `last_sync` datetime DEFAULT NULL,
            `raw_data_json` longtext DEFAULT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `asset_integration` (`asset_id`,`microsoft_integration_id`),
            KEY `intune_device_id` (`intune_device_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `intune_sync_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `microsoft_integration_id` int(11) NOT NULL,
            `started_at` datetime DEFAULT current_timestamp(),
            `finished_at` datetime DEFAULT NULL,
            `status` varchar(20) DEFAULT 'running',
            `devices_created` int(11) DEFAULT 0,
            `devices_updated` int(11) DEFAULT 0,
            `devices_matched` int(11) DEFAULT 0,
            `devices_skipped` int(11) DEFAULT 0,
            `errors` text DEFAULT NULL,
            `triggered_by` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.64'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.64') {
        // Locations become an independent entity (a department picks from
        // existing locations / links via department_sites instead of typing
        // a fresh address every time) - location_hours widened from
        // varchar(200) to fit a joined Monday-Sunday breakdown.
        mysqli_query($mysqli, "ALTER TABLE `locations` MODIFY `location_hours` text DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.65'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.65') {
        // Scheduled ticket reopen (Section: agent request) - resolve/close a
        // ticket now, but have it automatically pop back open on a future
        // date (e.g. "check back once the vendor's update ships").
        mysqli_query($mysqli, "ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `ticket_reopen_at` datetime DEFAULT NULL AFTER `ticket_closed_at`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.66'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.66') {
        // Restore client_support_issues_included_remote/_onsite, dropped by
        // an earlier migration that intended to move this allowance to a new
        // per-contract equivalent but never got the companion code (the
        // contract-level usage function/UI was never written) - every real
        // consumer (functions.php's getClientIncludedIssuesUsage(),
        // client_edit.php, client.php, client_model.php,
        // reports/included_issues.php) still reads/writes these client-level
        // columns, so their absence throws an uncaught mysqli exception
        // (blank page) on the ticket view, client overview, client edit save,
        // and included-issues report.
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_support_issues_included_remote` int(11) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `clients` ADD COLUMN IF NOT EXISTS `client_support_issues_included_onsite` int(11) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.67'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.67') {
        // credential_category has existed since the original "login_category"
        // rename (see the 2.x migration further up this file) but was never
        // read or written by any add/edit/list/view code - repurpose it as an
        // explicit credential type (Login / API Key) instead of adding a new
        // column. Existing rows predate this feature and were all logins, so
        // backfill them to 'Login' rather than leaving them blank. Renamed
        // first while still NULLable, backfilled, THEN tightened to NOT
        // NULL - going straight to NOT NULL in the CHANGE COLUMN itself
        // fails under strict mode ("Data truncated for column...") the
        // moment any existing row's value is NULL, which every single row
        // is here since the column was never written to before now.
        mysqli_query($mysqli, "ALTER TABLE `credentials` CHANGE COLUMN `credential_category` `credential_type` VARCHAR(200) DEFAULT NULL");
        mysqli_query($mysqli, "UPDATE `credentials` SET `credential_type` = 'Login' WHERE `credential_type` IS NULL OR `credential_type` = ''");
        mysqli_query($mysqli, "ALTER TABLE `credentials` MODIFY `credential_type` VARCHAR(200) NOT NULL DEFAULT 'Login'");
        mysqli_query($mysqli, "ALTER TABLE `credential_restore_staging` CHANGE COLUMN `credential_category` `credential_type` VARCHAR(200) DEFAULT NULL");
        mysqli_query($mysqli, "UPDATE `credential_restore_staging` SET `credential_type` = 'Login' WHERE `credential_type` IS NULL OR `credential_type` = ''");
        mysqli_query($mysqli, "ALTER TABLE `credential_restore_staging` MODIFY `credential_type` VARCHAR(200) NOT NULL DEFAULT 'Login'");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.68'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.68') {
        // Odoo directory sync (departments + employees), one-way pull from
        // Odoo hr.department/hr.employee into clients/contacts. Mirrors the
        // Intune device sync's link-table + sync-log pattern (asset_intune_links
        // / intune_sync_log) further up this file.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `client_odoo_links` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `client_id` int(11) NOT NULL,
            `odoo_integration_id` int(11) NOT NULL,
            `odoo_department_id` int(11) NOT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `client_integration` (`client_id`,`odoo_integration_id`),
            KEY `odoo_department_id` (`odoo_department_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `contact_odoo_links` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `contact_id` int(11) NOT NULL,
            `odoo_integration_id` int(11) NOT NULL,
            `odoo_employee_id` int(11) NOT NULL,
            `created_at` datetime DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `contact_integration` (`contact_id`,`odoo_integration_id`),
            KEY `odoo_employee_id` (`odoo_employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `odoo_sync_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `odoo_integration_id` int(11) NOT NULL,
            `started_at` datetime DEFAULT current_timestamp(),
            `finished_at` datetime DEFAULT NULL,
            `status` varchar(20) DEFAULT 'running',
            `departments_created` int(11) DEFAULT 0,
            `departments_updated` int(11) DEFAULT 0,
            `departments_matched` int(11) DEFAULT 0,
            `departments_skipped` int(11) DEFAULT 0,
            `employees_created` int(11) DEFAULT 0,
            `employees_updated` int(11) DEFAULT 0,
            `employees_matched` int(11) DEFAULT 0,
            `employees_skipped` int(11) DEFAULT 0,
            `errors` text DEFAULT NULL,
            `triggered_by` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.69'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.69') {
        // The "Intune Devices" nav link/page has never had an on/off switch -
        // it always shows even for a company with no Microsoft tenant
        // connected. Adds a company module toggle matching the existing
        // config_module_enable_rmm/config_module_enable_unifi convention
        // (Settings > Integrations), independent of microsoft_integrations'
        // own per-connection enabled/intune_sync_enabled flags.
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_module_enable_intune` tinyint(1) NOT NULL DEFAULT 0");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.70'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.70') {
        // The included-issues allowance is a count of hours, not tickets -
        // each remote ticket charges a flat 30 min against it, each onsite
        // ticket a flat hour (INCLUDED_HOURS_PER_TICKET_REMOTE/ONSITE in
        // functions.php), regardless of how long the ticket actually took.
        // Retype int (ticket count) -> decimal (hours) and rename to match.
        // No contract had a non-null value set at the time of this
        // migration, so there's no existing count to convert to hours.
        mysqli_query($mysqli, "ALTER TABLE `contracts` CHANGE COLUMN `contract_support_issues_included_remote` `contract_support_hours_included_remote` decimal(6,2) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `contracts` CHANGE COLUMN `contract_support_issues_included_onsite` `contract_support_hours_included_onsite` decimal(6,2) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.71'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.71') {
        // Legacy API keys (admin/api_keys.php, X-Api-Key auth) previously had no
        // permission concept at all - every key granted full read/write access
        // to everything the resolved admin user could do. 'write' is the default
        // so every existing key keeps its current (full) behavior unchanged.
        mysqli_query($mysqli, "ALTER TABLE `api_keys` ADD COLUMN IF NOT EXISTS `api_key_permission` ENUM('read','write') NOT NULL DEFAULT 'write' AFTER `api_key_client_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.72'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.72') {
        // Geocoded once at save time (agent/post/location.php, via
        // geocodeAddress() in functions.php calling OpenStreetMap's Nominatim
        // API) and cached here rather than re-geocoded on every page view -
        // Nominatim's usage policy caps public requests at ~1/sec and expects
        // results to be cached, not looked up repeatedly. NULL means never
        // successfully geocoded yet (no address entered, or the lookup failed).
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD COLUMN IF NOT EXISTS `location_latitude` decimal(10,7) DEFAULT NULL AFTER `location_country`");
        mysqli_query($mysqli, "ALTER TABLE `locations` ADD COLUMN IF NOT EXISTS `location_longitude` decimal(10,7) DEFAULT NULL AFTER `location_latitude`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.73'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.73') {
        // The six module_rmm* permission modules gate 77 call sites across the RMM
        // feature set, and module_kb gates the knowledge base - but none of them was
        // ever seeded by setup/index.php or scripts/setup_cli.php. module_kb was only
        // ever inserted by the 2.5.x migration below, so a FRESH install (which applies
        // db.sql and stamps itself current without running this chain) never got it
        // either. lookupUserPermission() JOINs modules -> user_role_permissions and
        // returns false when no modules row exists, so every non-admin was denied the
        // entire RMM surface and the KB. Admins never noticed because they bypass the
        // lookup. modules.module_name has no unique key, so these are guarded with
        // NOT EXISTS rather than INSERT IGNORE - re-running must not duplicate rows.
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_kb', 'Access to the knowledge base' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_kb')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_rmm', 'Access to RMM device monitoring and dashboards' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_rmm')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_rmm_alerts', 'View RMM alerts' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_rmm_alerts')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_rmm_alerts_ack', 'Acknowledge and resolve RMM alerts' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_rmm_alerts_ack')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_rmm_scripts', 'Run RMM scripts on managed endpoints' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_rmm_scripts')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_rmm_sync', 'Trigger RMM integration syncs' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_rmm_sync')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_rmm_remote_connect', 'Launch remote sessions to managed endpoints' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_rmm_remote_connect')");

        // Every RMM sync matches agents on assets.asset_serial (see
        // RmmAssetMapper::syncAgent step 2) and the column had no index.
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD INDEX IF NOT EXISTS `idx_assets_serial` (`asset_serial`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.74'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.74') {
        // Device metrics subsystem. Design and rationale: docs/REDESIGN_ARCHITECTURE_REPORT.md
        // sections K (registry), N (schema) and O (rollups).
        //
        // device_metric_samples deliberately has NO surrogate key. Its composite primary key
        // (asset_id, metric_id, instance_id, sampled_at) clusters every chart read - one device,
        // one metric, one instance, one time range - into a contiguous leaf-page scan, AND doubles
        // as the idempotency key so a retried collector batch dedupes via ON DUPLICATE KEY UPDATE
        // rather than needing a nullable fingerprint column. That distinction matters: a nullable
        // column inside a UNIQUE key does NOT dedupe in MariaDB, because NULLs compare as distinct -
        // three identical inserts would produce three rows. Verified on MariaDB 10.11 before shipping.
        //
        // sampled_at is UTC. This is a deliberate divergence from the app's local-time convention so
        // that hour bucketing is pure field extraction with no timezone function in the query path.
        //
        // Rollups carry sum + count rather than a precomputed average, so a coarser tier can be
        // re-aggregated from a finer one without compounding rounding error.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `device_metric_defs` (
            `metric_id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
            `metric_key` varchar(64) NOT NULL,
            `display_name` varchar(100) NOT NULL,
            `unit` varchar(24) NOT NULL,
            `metric_kind` enum('gauge','counter') NOT NULL DEFAULT 'gauge',
            `value_min` double DEFAULT NULL,
            `value_max` double DEFAULT NULL,
            `metric_dim` varchar(16) DEFAULT NULL,
            `display_precision` tinyint(3) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY (`metric_id`),
            UNIQUE KEY `metric_key` (`metric_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `device_metric_instances` (
            `instance_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `asset_id` int(11) NOT NULL,
            `metric_dim` varchar(16) NOT NULL,
            `instance_key` varchar(96) NOT NULL,
            `instance_label` varchar(128) DEFAULT NULL,
            `first_seen_at` datetime NOT NULL,
            `last_seen_at` datetime NOT NULL,
            PRIMARY KEY (`instance_id`),
            UNIQUE KEY `asset_dim_key` (`asset_id`,`metric_dim`,`instance_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `device_metric_samples` (
            `asset_id` int(11) NOT NULL,
            `metric_id` smallint(5) unsigned NOT NULL,
            `instance_id` int(10) unsigned NOT NULL DEFAULT 0,
            `sampled_at` datetime NOT NULL,
            `metric_value` double NOT NULL,
            PRIMARY KEY (`asset_id`,`metric_id`,`instance_id`,`sampled_at`),
            KEY `idx_samples_sampled_at` (`sampled_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `device_metric_rollups` (
            `bucket` enum('hour','day') NOT NULL,
            `asset_id` int(11) NOT NULL,
            `metric_id` smallint(5) unsigned NOT NULL,
            `instance_id` int(10) unsigned NOT NULL DEFAULT 0,
            `period_start` datetime NOT NULL,
            `min_value` double NOT NULL,
            `max_value` double NOT NULL,
            `sum_value` double NOT NULL,
            `sample_count` int(10) unsigned NOT NULL,
            PRIMARY KEY (`bucket`,`asset_id`,`metric_id`,`instance_id`,`period_start`),
            KEY `idx_rollup_period` (`bucket`,`period_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `device_metric_rollup_state` (
            `bucket` enum('hour','day') NOT NULL,
            `rolled_through` datetime NOT NULL,
            `last_run_at` datetime DEFAULT NULL,
            PRIMARY KEY (`bucket`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `device_metric_collection_state` (
            `asset_id` int(11) NOT NULL,
            `integration_id` int(11) NOT NULL,
            `last_collected_at` datetime DEFAULT NULL,
            `last_sample_at` datetime DEFAULT NULL,
            `last_error` varchar(255) DEFAULT NULL,
            `consecutive_failures` int(10) unsigned NOT NULL DEFAULT 0,
            `vendor_cursor_json` text DEFAULT NULL,
            PRIMARY KEY (`asset_id`,`integration_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_enable_device_metrics` tinyint(1) NOT NULL DEFAULT 0");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_metrics_collect_interval_seconds` int(11) NOT NULL DEFAULT 300");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_metrics_raw_retention_days` int(11) NOT NULL DEFAULT 14");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_metrics_hour_retention_days` int(11) NOT NULL DEFAULT 90");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.75'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.75') {
        // The Accounting/QuickBooks surface is removed from this edition - the owner's
        // standing rule is "no billable anything here". admin/settings_accounting.php,
        // admin/accounting_*_mapping.php, admin/accounting_sync_status.php and
        // admin/oauth_quickbooks_*.php are deleted, and every nav entry that pointed at
        // them is gone. Force the module flag off so the ~40 remaining call sites that
        // still branch on `$config_module_enable_accounting` (agent sidebar Billing and
        // Finance groups, the dashboard financial cards, ticket billable controls, the
        // financial reports, the department portal invoice tab, api/v1/me.php) all
        // resolve to hidden, and so cron/cron.php's accounting sync stays dormant.
        //
        // The column, every other accounting/QuickBooks settings column, and all
        // invoice/quote/payment tables are deliberately left in place - this hides the
        // surface, it does not destroy data. includes/accounting_functions.php and
        // cron/accounting_sync*.php also stay on disk, unreferenced, so the port
        // lineage to the MSP fork survives and this is one commit from being restored.
        mysqli_query($mysqli, "UPDATE `settings` SET `config_module_enable_accounting` = 0 WHERE `config_module_enable_accounting` <> 0");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.76'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.76') {

        // Re-wrap every settings/integration secret that was written while
        // $config_settings_enc_key did not exist.
        //
        // encryptSetting() used to return its input unchanged when that config variable
        // was empty, and no code path ever created it - so every "encrypted" column on
        // every install is cleartext today, including settings.config_vault_canonical_key,
        // which holds the credential-vault MASTER KEY sitting next to the ciphertexts it
        // unlocks. encryptSetting() now throws instead of downgrading, setup/index.php and
        // scripts/setup_cli.php mint the key, and this pass wraps what is already stored.
        //
        // Idempotent: a value that already starts with ENC2: (aes-256-gcm) or ENC: (the
        // legacy aes-128-cbc) is skipped, so re-running this can never double-encrypt.
        //
        // NOT every encrypted-at-rest column is listed below. Five settings columns are
        // deliberately left alone because they still have readers that pull the column
        // straight out of the row WITHOUT calling decryptSetting(), and encrypting them
        // here would break mail and Microsoft SSO for legitimate users:
        //   config_smtp_password             login.php:92, guest/guest_post.php:45 & :110, cron/cron.php:49
        //   config_azure_client_secret       client/login_microsoft.php:30
        //   config_mail_oauth_client_secret  cron/mail_queue.php:68
        //   config_mail_oauth_refresh_token  cron/mail_queue.php:70
        //   config_mail_oauth_access_token   cron/mail_queue.php:71
        // Those call sites need a decryptSetting() wrap before those columns can be
        // migrated; until then they stay legacy plaintext, which decryptSetting() still
        // reads transparently. Note this also means the next Mail Settings / Identity
        // Provider save WILL encrypt them (the write path already goes through
        // encryptSetting()), so fixing those readers is not optional for long.
        $enc_key = $GLOBALS['config_settings_enc_key'] ?? '';
        if (empty($enc_key)) {
            // Refuse to advance the version rather than silently skip the re-wrap and
            // leave the secrets in cleartext for good. Nothing has been changed yet, so
            // the install keeps working exactly as before; add the key and run it again.
            echo "Database update 2.6.76 -> 2.6.77 aborted: \$config_settings_enc_key is missing from config.php.\n";
            echo "This update re-encrypts stored secrets, so it needs a key first. Add this line to config.php:\n";
            echo "    \$config_settings_enc_key = bin2hex(random_bytes(32));\n";
            echo "then run the database update again. The database has NOT been modified.\n";
            exit();
        }

        // table => [primary key column, [secret columns]]
        $enc_rewrap_targets = [
            'settings'                => ['company_id', [
                                             'config_vault_canonical_key',
                                             'config_imap_password',
                                             'config_outlook_cal_client_secret',
                                             'config_comet_admin_pass',
                                             'config_comet_totp_secret',
                                             'config_comet_webhook_secret',
                                         ]],
            'payment_providers'       => ['payment_provider_id', ['payment_provider_private_key', 'payment_provider_webhook_secret']],
            'ai_providers'            => ['ai_provider_id', ['ai_provider_api_key']],
            'webhooks'                => ['webhook_id', ['webhook_secret']],
            'rmm_integrations'        => ['id', ['api_key_enc']],
            'unifi_integrations'      => ['id', ['api_key_enc']],
            'microsoft_integrations'  => ['microsoft_integration_id', ['client_secret_enc']],
            'odoo_integrations'       => ['odoo_integration_id', ['api_key_enc']],
            'mailboxes'               => ['mailbox_id', ['mailbox_imap_password_enc', 'mailbox_oauth_refresh_token_enc', 'mailbox_oauth_access_token_enc']],
            'accounting_integrations' => ['accounting_id', ['accounting_client_secret', 'accounting_refresh_token', 'accounting_access_token']],
        ];

        foreach ($enc_rewrap_targets as $enc_table => $enc_spec) {
            list($enc_pk, $enc_cols) = $enc_spec;

            foreach ($enc_cols as $enc_col) {

                // Several of these columns are varchar, and a wrapped value is longer than
                // the plaintext it replaces. Silently letting MySQL truncate one would
                // destroy the secret permanently, so look the width up and skip (loudly)
                // anything that would not fit.
                $enc_len_row = mysqli_fetch_assoc(mysqli_query($mysqli,
                    "SELECT CHARACTER_MAXIMUM_LENGTH AS max_len FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$enc_table' AND COLUMN_NAME = '$enc_col'"
                ));
                if (!$enc_len_row) {
                    continue; // table/column not present on this install - nothing to re-wrap
                }
                $enc_max_len = intval($enc_len_row['max_len']);

                // Only untouched, non-empty values. NOT LIKE 'ENC:%' / 'ENC2:%' is what
                // makes this safe to run twice.
                $enc_rows = mysqli_query($mysqli,
                    "SELECT `$enc_pk` AS pk, `$enc_col` AS val FROM `$enc_table`
                     WHERE `$enc_col` IS NOT NULL AND `$enc_col` <> ''
                       AND `$enc_col` NOT LIKE 'ENC:%' AND `$enc_col` NOT LIKE 'ENC2:%'"
                );
                if (!$enc_rows) {
                    continue;
                }

                while ($enc_row = mysqli_fetch_assoc($enc_rows)) {
                    $enc_wrapped = encryptSetting($enc_row['val']);

                    if ($enc_max_len > 0 && strlen($enc_wrapped) > $enc_max_len) {
                        logApp("Database", "error", "DB update 2.6.77: left $enc_table.$enc_col (row {$enc_row['pk']}) in cleartext - the encrypted value needs " . strlen($enc_wrapped) . " chars but the column only holds $enc_max_len. Re-save this secret after widening the column.");
                        echo "WARNING: $enc_table.$enc_col row {$enc_row['pk']} left as-is - encrypted value does not fit in the column.\n";
                        continue;
                    }

                    $enc_wrapped_esc = mysqli_real_escape_string($mysqli, $enc_wrapped);
                    $enc_pk_val      = intval($enc_row['pk']);
                    mysqli_query($mysqli, "UPDATE `$enc_table` SET `$enc_col` = '$enc_wrapped_esc' WHERE `$enc_pk` = $enc_pk_val");
                }
            }
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.77'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.77') {

        /* DEPLOY ORDER IS ENFORCED HERE, NOT DOCUMENTED IN A RUNBOOK.
         *
         * The storage migration further down rewrites every stored KB media URL
         * to point at /agent/kb_media.php. Running it against an install whose
         * CODE has not been deployed yet turns every KB image on the agent
         * page, the department portal and the Android app into a 404 - measured
         * on the live box 2026-09-08, where /var/www/mw-itflow.foleyit.com has
         * no agent/kb_media.php and no client/kb_media.php at all, while
         * midwest_itflow.kb_articles holds 15 raw /uploads/kb/<id>/<name> URLs
         * across 3 rows that render correctly today.
         *
         * So refuse rather than half-apply, the same way the 2.6.77 block above
         * refuses when $config_settings_enc_key is missing. Nothing has been
         * modified at this point, so the install keeps working exactly as it
         * did; deploy the code and run the update again.
         *
         * dirname(__DIR__), not $_SERVER['DOCUMENT_ROOT']: this file is reached
         * both over HTTP (admin/post/update.php:297) and from the CLI
         * (scripts/update_cli.php:117), and DOCUMENT_ROOT is unset in the
         * second. __DIR__ is this file's own directory, admin/, in both. */
        /* BOTH endpoints, not just the agent one. The portal's render path is
           repointed at /client/kb_media.php unconditionally at code-deploy time -
           client/kb_article.php calls MediaUrlRewriter::toPortal() whether or not
           this update has run - so an install carrying agent/kb_media.php but not
           client/kb_media.php would pass a one-file guard and still have every
           image broken for every department contact. Check what is actually
           required, which is the whole serving surface. */
        foreach (['agent/kb_media.php', 'client/kb_media.php'] as $kb_media_serve_endpoint) {
            if (is_file(dirname(__DIR__) . '/' . $kb_media_serve_endpoint)) {
                continue;
            }
            echo "Database update 2.6.77 -> 2.6.78 aborted: $kb_media_serve_endpoint is not on disk.\n";
            echo "This update rewrites stored KB media URLs to point at those endpoints, so the\n";
            echo "application code has to be deployed BEFORE the database is updated.\n";
            echo "Deploy the code, then run the database update again.\n";
            echo "The database has NOT been modified.\n";
            /* exit(1), not exit(). deploy/update.sh runs this and checks the exit
               status; a bare exit() returns 0, so a refused migration was reported
               as a SUCCESS and the script carried on to composer install and the
               php-fpm reload as if the database were up to date. */
            exit(1);
        }

        /* KB media capability-token signing key.
         *
         * Holds an ENC2-wrapped 64-hex secret used by \ITFlow\KB\MediaToken to
         * sign the media URLs api/v1/kb.php hands to the Android app, which has
         * no way to send a session cookie or an API key on a WebView <img> or an
         * external-browser download.
         *
         * The COLUMN is created here; the VALUE is minted lazily by
         * MediaToken::keyForSigning() on first SIGNING use, with a race-free
         * conditional UPDATE. That is deliberate - an install that upgrades and
         * immediately serves an API request must work without an admin visiting
         * a settings screen, and generating it here would put a secret in the
         * update path of every install whether or not it ever uses the API.
         * (Minting deliberately does NOT hang off the verification path; see
         * the note on MediaToken::key(), which is read-only for that reason.)
         *
         * VARCHAR(300), not 255: encryptSetting() wraps 64 hex characters as
         * 'ENC2:' + base64(12-byte nonce + 16-byte tag + 64-byte ciphertext) =
         * 5 + ceil(92/3)*4 = 129 characters. 255 would fit, but the 2.6.77
         * update exists precisely because several settings columns were too
         * narrow for their wrapped secrets and had to be length-guarded at
         * write time; 300 leaves room and costs nothing on utf8mb4 VARCHAR.
         *
         * Deliberately NOT surfaced in includes/load_global_settings.php. That
         * file does SELECT *, so the ciphertext is transiently in its $row, but
         * it must never become a page-scope global that a var_dump or a verbose
         * error handler would print. Only MediaToken reads it. */
        /* IF NOT EXISTS because the 2.6.78 block does substantial work AFTER this
         * line - the whole storage migration - and the version bump only happens at
         * the end. If anything between them fails, the update is re-run from
         * 2.6.77, and a bare ADD would then die on "Duplicate column" before
         * reaching the rewrite that had not finished. MariaDB 10.11 supports it. */
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_kb_media_key` VARCHAR(300) NULL DEFAULT NULL");

        /* STORAGE MIGRATION: legacy /uploads/kb/... -> the canonical
         * authenticated URL, in the two columns that hold rendered article HTML.
         *
         * WHY IT HAS TO BE A STORAGE CHANGE and not just the render-time
         * MediaUrlRewriter that agent/kb_article.php, client/kb_article.php,
         * the version-history modal and api/v1/kb.php already run: the TinyMCE
         * EDIT modal loads the RAW stored HTML on purpose - see the comment
         * above the $kb_article_content assignment in
         * agent/modals/kb_article/kb_article_edit.php; normalising there would
         * rewrite stored content as a side effect of opening an editor. So
         * without this, once
         * /uploads/kb/ is denied at the web server an agent editing an
         * unmigrated article sees broken images inside the editor and Save
         * re-bakes the dead URL (agent/post/kb_article.php stores what the
         * editor submitted). The corpus could never converge. The render-time
         * rewriters stay as belt-and-braces for anything this misses.
         *
         * WHAT IS REWRITTEN. Only the two shapes the two writers actually
         * produced - /uploads/kb/<article_id>/<name> (the DOCX and PDF
         * importers) and flat /uploads/kb/<name> (the TinyMCE uploader pool) -
         * and only when <name> matches the ONE definition of a valid reference
         * name, functions.php::isUploadReferenceName(): [A-Za-z0-9_-]+.[A-Za-z0-9]+.
         * A name outside that class is left alone deliberately: the serve
         * endpoints re-validate against the same pattern and would refuse it,
         * so rewriting it would swap a working URL for a guaranteed 403.
         *
         * THE TRAILING (?!...) IS LOAD-BEARING, and it is there because the
         * first version of this migration was wrong. Without it the name part
         * is unanchored, so '/uploads/kb/13/archive.tar.gz' matched only as far
         * as 'archive.tar' and the row came out holding
         * '/agent/kb_media.php?a=13&amp;f=archive.tar.gz' - a URL the serve
         * endpoint refuses (two dots is not a reference name) built out of a
         * path that used to work. Reproduced against the copy of the live
         * tables in kbmedia_lane3 on MariaDB 10.11.14; with the lookahead the
         * same row is left byte-identical and counted in the warning below.
         * The class is the set of characters a longer filename, a percent
         * escape, a query string or a fragment would continue with, so a
         * partial match is impossible in all four cases.
         *
         * The two patterns cannot overlap. '/uploads/kb/13/x.png' does not
         * match the flat pattern, because after '/uploads/kb/' the flat pattern
         * needs a dot before the next '/' and '13' has none. Verified on this
         * MariaDB 10.11.14 before writing this, and again on a full copy of the
         * live KB tables (kbmedia_lane3).
         *
         * Idempotent, so a re-run or a partially-applied update cannot corrupt
         * anything: the WHERE only selects rows that still contain the legacy
         * prefix, and REGEXP_REPLACE on an already-canonical row is a no-op
         * because '/agent/kb_media.php?a=' does not match either pattern.
         * Measured: running the two statements a second time left
         * MD5(GROUP_CONCAT(kb_article_content)) unchanged at
         * 704a6f9e8ceb58952a120ab3b16ba79a.
         *
         * The *_content_raw columns are NOT touched: they are the plaintext
         * FULLTEXT index columns and hold no markup (measured on live -
         * 0 rows of kb_articles or kb_article_versions have '/uploads/kb' in
         * them). */
        $kb_media_name_end        = '(?![A-Za-z0-9_.%?#-])';
        $kb_media_article_pattern = '/uploads/kb/([0-9]+)/([A-Za-z0-9_-]+[.][A-Za-z0-9]+)' . $kb_media_name_end;
        $kb_media_pool_pattern    = '/uploads/kb/([A-Za-z0-9_-]+[.][A-Za-z0-9]+)' . $kb_media_name_end;

        // '&amp;', not '&': every one of these URLs lives in an HTML attribute
        // in the stored content, which is what the DOCX importer already writes
        // (agent/post/kb_article.php) and what the purifier expects on the way
        // back out.
        mysqli_query($mysqli,
            "UPDATE `kb_articles`
                SET `kb_article_content` = REGEXP_REPLACE(
                        REGEXP_REPLACE(`kb_article_content`,
                            '$kb_media_article_pattern', '/agent/kb_media.php?a=\\\\1&amp;f=\\\\2'),
                        '$kb_media_pool_pattern', '/agent/kb_media.php?f=\\\\1')
              WHERE `kb_article_content` LIKE '%/uploads/kb/%'"
        );
        $kb_media_migrated_articles = mysqli_affected_rows($mysqli);

        mysqli_query($mysqli,
            "UPDATE `kb_article_versions`
                SET `kb_article_version_content` = REGEXP_REPLACE(
                        REGEXP_REPLACE(`kb_article_version_content`,
                            '$kb_media_article_pattern', '/agent/kb_media.php?a=\\\\1&amp;f=\\\\2'),
                        '$kb_media_pool_pattern', '/agent/kb_media.php?f=\\\\1')
              WHERE `kb_article_version_content` LIKE '%/uploads/kb/%'"
        );
        $kb_media_migrated_versions = mysqli_affected_rows($mysqli);

        /* Anything still holding a legacy path after that is a shape neither
         * writer produces - a nested subdirectory, or a filename outside the
         * reference-name class - and it will break when /uploads/kb/ is denied.
         * Name it now, loudly, rather than letting it turn up as a broken image
         * weeks later: there is no automatic repair for it, the file has to be
         * re-inserted through the editor. */
        $kb_media_leftover = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT
                (SELECT COUNT(*) FROM `kb_articles` WHERE `kb_article_content` LIKE '%/uploads/kb/%')
              + (SELECT COUNT(*) FROM `kb_article_versions` WHERE `kb_article_version_content` LIKE '%/uploads/kb/%')
              AS cnt"))['cnt']);

        echo "KB media: migrated $kb_media_migrated_articles article row(s) and $kb_media_migrated_versions version row(s) to the authenticated media URL.\n";
        if ($kb_media_leftover > 0) {
            echo "WARNING: $kb_media_leftover KB row(s) still contain a /uploads/kb/ path in an unrecognised shape (nested directory, or a filename outside [A-Za-z0-9_-]+.[A-Za-z0-9]+). Those images will break once /uploads/kb/ is denied at the web server; re-insert them through the editor.\n";
            logApp("Database", "warning", "DB update 2.6.78: $kb_media_leftover KB content row(s) kept an unrecognised /uploads/kb/ path and were not migrated.");
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.78'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.78') {

        /* Lets a project TASK spawn a real ticket, and remember that it did.
         *
         * Deliberately a SEPARATE column from task_ticket_id, not a reuse of it.
         * task_ticket_id already means something else entirely: it is how a
         * ticket's own checklist items are stored - agent/ticket.php and
         * agent/post/ticket.php both INSERT INTO tasks with task_ticket_id set,
         * and project_details.php's own task query LEFT JOINs tickets ON
         * tickets.ticket_id = tasks.task_ticket_id specifically to pull a linked
         * ticket's checklist into the project view alongside its own tasks. A
         * project task (task_project_id set) normally has task_ticket_id NULL;
         * overloading that column for "this task became a ticket" would make a
         * plain project task indistinguishable from a ticket checklist item the
         * instant it got a ticket - breaking that exact JOIN and the milestone
         * grouping built on top of it.
         *
         * Nullable and additive - every existing task row is unaffected. */
        mysqli_query($mysqli, "ALTER TABLE `tasks` ADD COLUMN `task_created_ticket_id` int(11) DEFAULT NULL AFTER `task_ticket_id`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.79'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.79') {

        // Directory Sync batch: configurable field mapping (Odoo already synced
        // employees with 4 fields hardcoded in OdooDirectoryMapper::syncEmployee -
        // job_title/work_phone/mobile_phone/work_email straight to
        // contact_title/contact_phone/contact_mobile/contact_email, no way to
        // change or disable any of them), a Google Workspace directory-sync
        // provider (did not exist at all), a Microsoft Entra ID USER/contact sync
        // (microsoft_integrations only ever drove Intune *device* sync until now -
        // grepped src/Integrations/Microsoft/GraphClient.php and
        // src/Integrations/Microsoft/IntuneAssetMapper.php before writing this;
        // neither touches contacts), and 4 additive domains columns to stop
        // throwing away most of a WHOIS response (cron/domain_refresher.php today
        // keeps only IP/NS/MX/TXT plus a 254-char-truncated raw blob and a
        // regex-matched expiry date - Creation Date, registrar name, EPP status
        // and DNSSEC state are all in the same WHOIS response and are discarded).
        // Every table/column name here is the FIXED CONTRACT every other lane of
        // this batch is coding against in parallel - see the batch's contract doc.

        // ----- directory_field_mappings -----
        // provider/source_field/target_field are all admin-facing strings, not
        // structural identifiers this schema enforces referential integrity on -
        // varchar, not enum, so a future provider or field needs no migration to
        // add. target_field is validated against a hardcoded allow-list of real
        // contacts.* columns at READ time by src/Directory/FieldMapping.php
        // (forProvider() silently drops any row that fails it) rather than here,
        // because the allow-list is a PHP-side security control that has to keep
        // working even if a row is later edited directly in the database.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `directory_field_mappings` (
            `mapping_id` int(11) NOT NULL AUTO_INCREMENT,
            `provider` varchar(20) NOT NULL,
            `source_field` varchar(60) NOT NULL,
            `target_field` varchar(60) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`mapping_id`),
            UNIQUE KEY `provider_source_field` (`provider`,`source_field`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Seed the same 4 mappings OdooDirectoryMapper::syncEmployee() hardcoded
        // before this table existed, so an install that is upgrading (not
        // installing fresh from db.sql, which seeds these same 4 rows directly)
        // keeps syncing Odoo employees exactly as it did before this update - the
        // FIXED CONTRACT requires this seed explicitly. INSERT IGNORE, not a
        // bare INSERT: the UNIQUE KEY on (provider, source_field) means a re-run
        // of this block (partially-applied update, resumed) would otherwise die
        // on "Duplicate entry" before reaching anything after it.
        mysqli_query($mysqli, "INSERT IGNORE INTO `directory_field_mappings`
            (`provider`, `source_field`, `target_field`, `enabled`) VALUES
            ('odoo', 'job_title', 'contact_title', 1),
            ('odoo', 'work_phone', 'contact_phone', 1),
            ('odoo', 'mobile_phone', 'contact_mobile', 1),
            ('odoo', 'work_email', 'contact_email', 1)");

        // ----- google_integrations / google_sync_log -----
        // Shape mirrors microsoft_integrations (credential blob + enabled flag +
        // last_test_*/last_sync_at) and odoo_sync_log (per-run stats +
        // triggered_by) respectively, per the FIXED CONTRACT. No OAuth
        // client_secret here - Google's service-account flow authenticates with
        // an RS256-signed JWT built from the pasted key file's own private key
        // (src/Integrations/Google/GoogleDirectoryClient.php), not a
        // Microsoft-style client id/secret pair.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `google_integrations` (
            `google_integration_id` int(11) NOT NULL AUTO_INCREMENT,
            `service_account_json_enc` text DEFAULT NULL,
            `delegated_admin_email` varchar(255) DEFAULT NULL,
            `workspace_domain` varchar(255) DEFAULT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT 0,
            `last_test_at` datetime DEFAULT NULL,
            `last_test_success` tinyint(1) DEFAULT NULL,
            `last_test_error` varchar(500) DEFAULT NULL,
            `last_sync_at` datetime DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`google_integration_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `google_sync_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `google_integration_id` int(11) NOT NULL,
            `started_at` datetime DEFAULT current_timestamp(),
            `finished_at` datetime DEFAULT NULL,
            `status` varchar(20) DEFAULT 'running',
            `departments_created` int(11) DEFAULT 0,
            `departments_updated` int(11) DEFAULT 0,
            `departments_matched` int(11) DEFAULT 0,
            `departments_skipped` int(11) DEFAULT 0,
            `employees_created` int(11) DEFAULT 0,
            `employees_updated` int(11) DEFAULT 0,
            `employees_matched` int(11) DEFAULT 0,
            `employees_skipped` int(11) DEFAULT 0,
            `errors` text DEFAULT NULL,
            `triggered_by` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ----- microsoft_integrations: separate the new USER/contact sync from -----
        // ----- the existing Intune DEVICE sync it already drives -----
        // intune_sync_enabled (existing) stays exactly as-is and keeps meaning
        // "sync devices from Intune" - directory_sync_enabled is new and
        // independent, so an install can run one, both, or neither. Both reuse
        // the SAME tenant_id/client_id/client_secret_enc credential row per the
        // FIXED CONTRACT (Directory Sync tab is the one source of truth for the
        // Microsoft 365 connection; Device Sync's card links to it rather than
        // duplicating the fields) - reusing GraphClient for HTTP/auth is what
        // makes that single-credential-row design work.
        mysqli_query($mysqli, "ALTER TABLE `microsoft_integrations`
            ADD COLUMN IF NOT EXISTS `directory_sync_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `intune_sync_enabled`,
            ADD COLUMN IF NOT EXISTS `last_directory_sync_at` datetime DEFAULT NULL AFTER `last_sync_at`");

        // microsoft_directory_sync_log mirrors odoo_sync_log's shape exactly
        // (prefixed microsoft_, keyed on microsoft_integration_id instead of
        // odoo_integration_id) per the FIXED CONTRACT - kept entirely separate
        // from the existing intune_sync_log table (device-sync runs), since this
        // logs USER/contact directory-sync runs and the two have unrelated stat
        // columns (devices_* vs departments_*/employees_*).
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `microsoft_directory_sync_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `microsoft_integration_id` int(11) NOT NULL,
            `started_at` datetime DEFAULT current_timestamp(),
            `finished_at` datetime DEFAULT NULL,
            `status` varchar(20) DEFAULT 'running',
            `departments_created` int(11) DEFAULT 0,
            `departments_updated` int(11) DEFAULT 0,
            `departments_matched` int(11) DEFAULT 0,
            `departments_skipped` int(11) DEFAULT 0,
            `employees_created` int(11) DEFAULT 0,
            `employees_updated` int(11) DEFAULT 0,
            `employees_matched` int(11) DEFAULT 0,
            `employees_skipped` int(11) DEFAULT 0,
            `errors` text DEFAULT NULL,
            `triggered_by` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ----- domains: keep what WHOIS actually returns, not just what the -----
        // ----- 254-char truncated blob happens to still contain -----
        // All 4 additive and nullable, so every existing domain row is unaffected
        // until the next cron/domain_refresher.php pass populates them. Verified
        // on the live install 2026-09-09: mwautomation.com's raw WHOIS carries
        // Registry Domain ID, Registrar WHOIS Server, Registrar URL, Updated Date
        // and Creation Date in its first ~7 lines alone - domain_registered_at
        // captures Creation Date (the one the FIXED CONTRACT calls out by name),
        // domain_registrar_name the WHOIS record's own registrar name (distinct
        // from the domain_registrar vendor_id FK column that already exists -
        // that FK is which vendor row ITFlow considers the registrar; this is
        // what the WHOIS record itself reports as the registrar's name),
        // domain_status the EPP status codes (e.g. clientTransferProhibited -
        // varchar(500) since a domain commonly carries several, semicolon/
        // comma-joined), domain_dnssec whatever DNSSEC state WHOIS reports
        // (signedDelegation / unsigned / a per-TLD variant string).
        mysqli_query($mysqli, "ALTER TABLE `domains`
            ADD COLUMN IF NOT EXISTS `domain_registered_at` date DEFAULT NULL AFTER `domain_expire`,
            ADD COLUMN IF NOT EXISTS `domain_registrar_name` varchar(255) DEFAULT NULL AFTER `domain_raw_whois`,
            ADD COLUMN IF NOT EXISTS `domain_status` varchar(500) DEFAULT NULL AFTER `domain_registrar_name`,
            ADD COLUMN IF NOT EXISTS `domain_dnssec` varchar(50) DEFAULT NULL AFTER `domain_status`");

        // ----- user_settings: per-user dashboard chart-type choice -----
        // Additive, NOT NULL with a default matching each widget's current
        // hardcoded Chart.js type (agent/dashboard.php today: line charts under
        // Financial, a bar chart under Technical) - so an existing user's
        // dashboard renders identically after this update until they actually
        // change the new setting. Placed next to the enable flag each governs.
        mysqli_query($mysqli, "ALTER TABLE `user_settings`
            ADD COLUMN IF NOT EXISTS `user_config_dashboard_financial_chart_type` varchar(20) NOT NULL DEFAULT 'line' AFTER `user_config_dashboard_financial_enable`,
            ADD COLUMN IF NOT EXISTS `user_config_dashboard_technical_chart_type` varchar(20) NOT NULL DEFAULT 'bar' AFTER `user_config_dashboard_technical_enable`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.80'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.80') {

        /* INTERACTIVE KB (IKB) - the two tables the interactive block vocabulary
         * needs. Purely additive: no existing table, column, index or row is
         * touched, so this block cannot conflict with anything already stored.
         *
         * Both statements are byte-identical to the CREATE TABLE bodies appended
         * to db.sql (search either table name there). That is the convergence
         * contract for this repo - a fresh install loads db.sql, an existing
         * install runs this block, and the two must end up with the same
         * SHOW CREATE TABLE output. Proved by building both and diffing; see the
         * commit message for the run.
         *
         * ---------------------------------------------------------------------
         * kb_article_progress - PER-READER STATE, WHICH IS NOT CONTENT
         * ---------------------------------------------------------------------
         * A checklist tick and a wizard's position belong to the READER, not to
         * the article, so they cannot live in kb_articles.kb_article_content
         * (which is snapshotted into kb_article_versions and served to every
         * reader alike). One row per (article, block, part, principal).
         *
         * THE PRINCIPAL IS A PAIR, and that is the whole point of the char(1).
         * A department contact is a contacts row, an agent is a users row, and
         * the two id spaces overlap numerically - contact 7 and user 7 are
         * different people. The type character sits INSIDE the unique key, so
         * their progress can never collide. No foreign keys, because this
         * codebase uses none anywhere; a deleted user or contact leaves rows
         * that are simply never selected again.
         *
         * _state is smallint, not tinyint(1), because it carries two meanings:
         *   0/1              a checklist tick
         *   0-based index    a wizard's saved position, stored under the
         *                    RESERVED part key '_at'
         * '_at' cannot collide with a real part key by construction, not by
         * convention: content part keys match /^[a-z0-9][a-z0-9-]{0,23}$/ and
         * '_' is not in that character class, so no author-supplied or
         * normaliser-minted key can ever spell it. (The winning design used the
         * literal 'at' and defended it with "minted keys are always 8 hex
         * characters", which is an invariant living in one function that a later
         * change to the minting format would silently break. The underscore
         * moves that guarantee into the validator.)
         *
         * _part_hash is the stale-tick marker: substr(sha1(normalised label .
         * "\x1f" . normalised body), 0, 16), computed by InteractiveBlocks::
         * partHash() at RENDER time, server-side - never by the reader's
         * client, which only copies the value out of data-ikb-hashes and
         * hands it back unchanged at tick time. When the key still matches but the
         * hash does not, the step has been REWRITTEN since it was ticked and the
         * render layer says so instead of silently keeping or silently dropping
         * the tick. It hashes label AND body deliberately: the design hashed the
         * label alone, which leaves "reboot the switch" -> "do NOT reboot the
         * switch" in a step BODY completely unflagged, and that is the more
         * dangerous edit of the two. Hence the column name - it is not a label
         * hash and must not be read as one.
         *
         * INDEX SIZES. The unique key is 4 + 97 + 97 + 4 + 4 = 206 bytes of the
         * 3072-byte InnoDB DYNAMIC limit (int = 4; varchar(24) utf8mb4 = 24*4+1;
         * char(1) utf8mb4 = 4), so both varchars can stay at their full width.
         * The reader index is the one the article page uses: it selects every
         * row for one article and one principal in one range scan.
         *
         * WHAT ADDING A FIFTH BLOCK TYPE COSTS HERE: nothing. The table stores
         * opaque (block, part) keys and one small integer; it has no idea what a
         * checklist or a wizard is. A new block type that needs per-reader state
         * either uses ordinary part keys, or reserves one more underscore-
         * prefixed key beside '_at' - one entry in the reserved list in
         * agent/includes/kb_progress_store.php. No migration, no new column.
         *
         * ---------------------------------------------------------------------
         * kb_article_embeds - THE ESCAPE HATCH'S UN-PURIFIABLE HTML
         * ---------------------------------------------------------------------
         * kb_article_embed_untrusted_html IS THE ONLY COLUMN IN THIS DATABASE
         * THAT HOLDS HTML NO FILTER EVER TOUCHED. It is named that way so every
         * future call site has to read the word "untrusted" before using it. It
         * is never purified (purifying it would defeat the escape hatch), never
         * echoed into an app page, and reaches a browser only through
         * agent/kb_embed.php / client/kb_embed.php, which serve it into an
         * opaque-origin sandbox with their own Content-Security-Policy. Read the
         * header comment of agent/includes/kb_embed_serve.php before touching
         * any of it.
         *
         * kb_article_embed_kb_article_id IS THE ENTIRE AUTHORIZATION MODEL. Both
         * serve endpoints look the row up by id AND re-derive the caller's access
         * to THAT article, so the endpoint is a join rather than an enumeration
         * oracle.
         *
         * kb_article_embed_text is strip_tags() of the stored document, kept so
         * the authoring path can append it to kb_articles.kb_article_content_raw
         * and the embed stops being completely invisible to FULLTEXT search.
         * kb_article_embed_sha256 identifies the exact bytes for the audit log.
         *
         * IF NOT EXISTS on both, so a half-applied update can be re-run. The
         * version row is only advanced once both tables are proved present.
         *
         * THE try/catch IS NOT DECORATION. PHP 8.1+ defaults to
         * MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT and this codebase never calls
         * mysqli_report() (the same fact src/KB/MediaToken.php:497-505 documents
         * and measured), so a refused CREATE THROWS rather than returning false.
         * Measured here on PHP 8.4.25 against a scratch database whose user had
         * no CREATE privilege: without the catch the update died with an
         * uncaught mysqli_sql_exception and a stack trace, and the operator got
         * no instruction at all. The version row was still not advanced - the
         * safety property held either way - but "the update crashed" and "the
         * update refused, here is why, re-run it after fixing X" are different
         * products. */

        $ikb_create_error = '';
        try {
            mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `kb_article_progress` (
  `kb_article_progress_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_progress_kb_article_id` int(11) NOT NULL,
  `kb_article_progress_block_key` varchar(24) NOT NULL,
  `kb_article_progress_part_key` varchar(24) NOT NULL,
  `kb_article_progress_principal_type` char(1) NOT NULL,
  `kb_article_progress_principal_id` int(11) NOT NULL,
  `kb_article_progress_state` smallint(6) NOT NULL DEFAULT 0,
  `kb_article_progress_part_hash` char(16) NOT NULL DEFAULT '',
  `kb_article_progress_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kb_article_progress_id`),
  UNIQUE KEY `kb_article_progress_unique` (`kb_article_progress_kb_article_id`,`kb_article_progress_block_key`,`kb_article_progress_part_key`,`kb_article_progress_principal_type`,`kb_article_progress_principal_id`),
  KEY `kb_article_progress_reader` (`kb_article_progress_kb_article_id`,`kb_article_progress_principal_type`,`kb_article_progress_principal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `kb_article_embeds` (
  `kb_article_embed_id` int(11) NOT NULL AUTO_INCREMENT,
  `kb_article_embed_kb_article_id` int(11) NOT NULL,
  `kb_article_embed_name` varchar(255) NOT NULL,
  `kb_article_embed_untrusted_html` mediumtext DEFAULT NULL,
  `kb_article_embed_text` mediumtext DEFAULT NULL,
  `kb_article_embed_sha256` char(64) NOT NULL DEFAULT '',
  `kb_article_embed_height` int(11) NOT NULL DEFAULT 480,
  `kb_article_embed_created_by` int(11) NOT NULL DEFAULT 0,
  `kb_article_embed_created_at` datetime DEFAULT current_timestamp(),
  `kb_article_embed_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`kb_article_embed_id`),
  KEY `kb_article_embed_kb_article_id` (`kb_article_embed_kb_article_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $ikb_create_exception) {
            $ikb_create_error = $ikb_create_exception->getMessage();
        }

        /* Do not advance the version on a CREATE that did not happen. Without
         * this, a failed statement (disk full, a pre-existing table of the wrong
         * shape, a permissions problem) would leave the install claiming 2.6.81
         * with no tables, and no later block would ever create them - the
         * failure would surface days later as a fatal in the article page.
         * Ask the schema, not mysqli_query()'s return value: IF NOT EXISTS
         * returns true for a table that already existed in some other shape -
         * which is exactly why this checks COLUMN NAMES, not just table names.
         * A name-only check (TABLE_NAME IN (...), COUNT(*) = 2) is confirmed
         * present on this install for both tables and does not regress: it still
         * catches "table missing entirely". It does NOT catch "kb_article_embeds
         * already existed as, say, a stub with three columns" - IF NOT EXISTS
         * leaves that alone and this code would otherwise report success while
         * every write below fails on an unknown column. Comparing column NAMES
         * (not full types - `int(11)` vs `int` vary by MySQL/MariaDB version and
         * are not the blind spot this guards) is the cheap, portable half of
         * "ask the schema" that actually closes it. */
        $ikb_expected_columns = [
            'kb_article_progress' => [
                'kb_article_progress_id', 'kb_article_progress_kb_article_id',
                'kb_article_progress_block_key', 'kb_article_progress_part_key',
                'kb_article_progress_principal_type', 'kb_article_progress_principal_id',
                'kb_article_progress_state', 'kb_article_progress_part_hash',
                'kb_article_progress_updated_at',
            ],
            'kb_article_embeds' => [
                'kb_article_embed_id', 'kb_article_embed_kb_article_id',
                'kb_article_embed_name', 'kb_article_embed_untrusted_html',
                'kb_article_embed_text', 'kb_article_embed_sha256',
                'kb_article_embed_height', 'kb_article_embed_created_by',
                'kb_article_embed_created_at', 'kb_article_embed_updated_at',
            ],
        ];

        $ikb_shape_problems = [];
        try {
            foreach ($ikb_expected_columns as $ikb_table => $ikb_columns) {
                $ikb_found_columns = [];
                $ikb_col_result = mysqli_query($mysqli,
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$ikb_table'");
                if ($ikb_col_result) {
                    while ($ikb_col_row = mysqli_fetch_assoc($ikb_col_result)) {
                        $ikb_found_columns[$ikb_col_row['COLUMN_NAME']] = true;
                    }
                }

                if (empty($ikb_found_columns)) {
                    $ikb_shape_problems[] = "$ikb_table does not exist";
                    continue;
                }

                $ikb_missing = array_values(array_diff($ikb_columns, array_keys($ikb_found_columns)));
                if ($ikb_missing) {
                    $ikb_shape_problems[] = "$ikb_table is missing column(s): " . implode(', ', $ikb_missing);
                }
            }
        } catch (\Throwable $ikb_count_exception) {
            $ikb_create_error = $ikb_create_error !== ''
                ? $ikb_create_error
                : $ikb_count_exception->getMessage();
            $ikb_shape_problems[] = 'the schema could not be inspected';
        }

        if ($ikb_shape_problems) {
            echo "Database update 2.6.80 -> 2.6.81 aborted: " . implode('; ', $ikb_shape_problems) . ".\n";
            echo "kb_article_progress and kb_article_embeds could not be created in the shape\n";
            echo "this update expects. The database version has NOT been advanced, so this\n";
            echo "update can be re-run once the cause is fixed (both CREATE statements are\n";
            echo "IF NOT EXISTS and are safe to repeat; a table that exists with the wrong\n";
            echo "columns needs to be dropped or renamed out of the way by hand first).\n";
            echo "MySQL said: " . ($ikb_create_error !== '' ? $ikb_create_error : mysqli_error($mysqli)) . "\n";
            /* exit(1), not exit(): deploy/update.sh checks the exit status, and
               the 2.6.77 block above sets the precedent that a refused migration
               has to be reported as a failure rather than as success. */
            exit(1);
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.81'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.81') {
        // Catch-up: the 2.6.48 -> 2.6.49 block above (asset_anydesk_id) was
        // added to this file after this installation's database_version had
        // already advanced past '2.6.48', so that block's condition can never
        // match again here and the column was never actually added - every
        // asset_details.php load has been throwing "Undefined array key
        // asset_anydesk_id" ever since. IF NOT EXISTS makes this safe to run
        // regardless of whether an install somehow does already have it.
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD COLUMN IF NOT EXISTS `asset_anydesk_id` varchar(50) DEFAULT NULL AFTER `asset_uri_client`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.82'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.82') {
        // The KB sandboxed-embed cap (src/KB/HtmlImporter.php MAX_EMBED_BYTES,
        // agent/includes/kb_embed_serve.php KB_EMBED_MAX_BYTES - both must agree)
        // is being raised from 512 KB to 50 MB to match the HTML-import ceiling.
        // mediumtext (16 MB) has no headroom for that once mysqli_real_escape_string()
        // overhead is counted, so this widens the column to longtext (4 GB) - the
        // same column this fork already documented as "16 MB, nowhere near the cap"
        // when the cap was still 512 KB.
        mysqli_query($mysqli, "ALTER TABLE `kb_article_embeds` MODIFY COLUMN `kb_article_embed_untrusted_html` longtext DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.83'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.83') {
        // Admin > Localization's new "Phone Numbers" section: a default calling
        // code to prefill new records' phone_country_code boxes with, and a
        // toggle for whether WhatsApp click-to-chat links are offered next to
        // mobile numbers app-wide.
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_phone_default_country_code` varchar(10) NOT NULL DEFAULT '1' AFTER `config_timezone`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_phone_default_country_code`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.84'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.84') {
        // Printers and Network Drives: structured IT-documentation modules under
        // the Knowledge sidebar dropdown, modeled on the Networks/Assets shape
        // (fixed typed fields + list/detail views), not the KB free-text wiki.
        // See agent/printers.php, agent/network_drives.php.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `printers` (
            `printer_id` int(11) NOT NULL AUTO_INCREMENT,
            `printer_client_id` int(11) NOT NULL DEFAULT 0,
            `printer_name` varchar(200) NOT NULL,
            `printer_ip_address` varchar(200) DEFAULT NULL,
            `printer_location` varchar(200) DEFAULT NULL,
            `printer_model` varchar(200) DEFAULT NULL,
            `printer_serial_number` varchar(200) DEFAULT NULL,
            `printer_mac_address` varchar(200) DEFAULT NULL,
            `printer_notes` text DEFAULT NULL,
            `printer_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `printer_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `printer_archived_at` datetime DEFAULT NULL,
            `printer_created_by` int(11) NOT NULL DEFAULT 0,
            `printer_updated_by` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`printer_id`),
            KEY `idx_printers_client_archived` (`printer_client_id`,`printer_archived_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `network_drives` (
            `network_drive_id` int(11) NOT NULL AUTO_INCREMENT,
            `network_drive_client_id` int(11) NOT NULL DEFAULT 0,
            `network_drive_name` varchar(200) NOT NULL,
            `network_drive_letter` varchar(10) DEFAULT NULL,
            `network_drive_path` varchar(500) DEFAULT NULL,
            `network_drive_purpose` varchar(200) DEFAULT NULL,
            `network_drive_notes` text DEFAULT NULL,
            `network_drive_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `network_drive_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `network_drive_archived_at` datetime DEFAULT NULL,
            `network_drive_created_by` int(11) NOT NULL DEFAULT 0,
            `network_drive_updated_by` int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (`network_drive_id`),
            KEY `idx_network_drives_client_archived` (`network_drive_client_id`,`network_drive_archived_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.85'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.85') {
        // Printers' Location field splits in two, matching the Assets module's
        // own Location/Physical Location pair: a real Location record picked
        // from the `locations` table (printer_location_id, like asset_location_id)
        // plus free-text for where within that location (printer_physical_location,
        // like asset_physical_location - "Floor 2, Closet B"). The rename below
        // preserves whatever free text is already in printer_location (a real
        // user was already using this module before this migration landed).
        mysqli_query($mysqli, "ALTER TABLE `printers` ADD COLUMN IF NOT EXISTS `printer_location_id` int(11) NOT NULL DEFAULT 0 AFTER `printer_client_id`");
        mysqli_query($mysqli, "ALTER TABLE `printers` CHANGE COLUMN `printer_location` `printer_physical_location` varchar(200) DEFAULT NULL");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.86'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.86') {
        // Ticket-creation defaults (Admin > Settings > Tickets): a configured
        // default category/status override the ad-hoc "Remote"/"New"-or-
        // "Assigned" by-name fallback that resolveTicketCategory()/
        // resolveTicketCreationStatus() (functions.php) already use for API/
        // portal-created tickets, and now also drive agent-UI ticket creation
        // (agent/post/ticket.php), which previously had no fallback at all
        // (category) or a hardcoded, wrong-on-this-install status id pair
        // (1/2, meant to mean New/Assigned but actually New/Open).
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_ticket_default_category_id` int(11) NOT NULL DEFAULT 0 AFTER `config_ticket_default_technician_id`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_ticket_default_status_id` int(11) NOT NULL DEFAULT 0 AFTER `config_ticket_default_category_id`");

        // Avg Resolution Time (dashboard): whether project-linked tickets
        // count toward the average, and whether the tile shows at all.
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_avg_resolution_exclude_projects` tinyint(1) NOT NULL DEFAULT 1 AFTER `config_ticket_default_status_id`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_dashboard_avg_resolution_enable` tinyint(1) NOT NULL DEFAULT 1 AFTER `config_avg_resolution_exclude_projects`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.87'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.87') {
        // Holiday catalog: a real, persisted table of country-holiday rows
        // (system-generated from includes/holiday_functions.php's rule
        // engine, or hand-added), managed on its own admin page
        // (admin/holidays.php) and picked from - not just computed and
        // thrown directly into one SLA calendar - when adding a holiday to
        // any SLA Business Hours calendar (admin/modals/sla/calendar_edit.php).
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `holidays` (
            `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
            `holiday_country` varchar(200) NOT NULL,
            `holiday_year` int(4) NOT NULL,
            `holiday_date` date NOT NULL,
            `holiday_name` varchar(150) NOT NULL,
            `holiday_is_custom` tinyint(1) NOT NULL DEFAULT 0,
            `holiday_created_by` int(11) NOT NULL DEFAULT 0,
            `holiday_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`holiday_id`),
            KEY `idx_holidays_country_year` (`holiday_country`,`holiday_year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.88'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.88') {
        // Backup > Remote Storage: upload each backup zip to an S3-compatible
        // bucket (AWS S3 itself, or a self-hosted service like RustFS/MinIO)
        // in addition to keeping it in webroot/backups. config_backup_s3_secret_key
        // is encrypted at rest via encryptSetting()/decryptSetting(), same as
        // config_comet_admin_pass.
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `config_backup_retain_count`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_endpoint` varchar(255) DEFAULT NULL AFTER `config_backup_s3_enabled`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_region` varchar(100) NOT NULL DEFAULT 'us-east-1' AFTER `config_backup_s3_endpoint`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_bucket` varchar(255) DEFAULT NULL AFTER `config_backup_s3_region`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_access_key` varchar(255) DEFAULT NULL AFTER `config_backup_s3_bucket`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_secret_key` text DEFAULT NULL AFTER `config_backup_s3_access_key`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_path_style` tinyint(1) NOT NULL DEFAULT 1 AFTER `config_backup_s3_secret_key`");
        mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `config_backup_s3_prefix` varchar(255) DEFAULT NULL AFTER `config_backup_s3_path_style`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.89'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.89') {
        // Mobile device tracking (Assets > Mobile filter): PIN/passcode for
        // phones/tablets, alongside the existing asset_tag/asset_serial
        // fields already on this table.
        mysqli_query($mysqli, "ALTER TABLE `assets` ADD COLUMN IF NOT EXISTS `asset_pin` varchar(50) DEFAULT NULL AFTER `asset_serial`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.90'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.90') {
        // Training / LMS Phase 1 - foundation + course builder (plan rev 3 §B).
        // Idempotent: IF NOT EXISTS / INSERT IGNORE / NOT EXISTS throughout, so a half-applied
        // run can be re-run. No triggers (plan A8). No contact references.
        // One mysqli_query per CREATE TABLE, DDL exactly as spec §2.1 incl. table options (29 statements).
        // Keep db.sql in step with these (same column order, keys and table options).

        // ---------- catalog config (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_categories` (
            `tcat_id` int(11) NOT NULL AUTO_INCREMENT,
            `tcat_name` varchar(100) NOT NULL,
            `tcat_color` char(7) NOT NULL DEFAULT '#0D9488',
            `tcat_icon` varchar(40) NOT NULL DEFAULT 'graduation-cap',
            `tcat_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `tcat_created_by` int(11) NOT NULL DEFAULT 0,
            `tcat_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `tcat_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `tcat_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`tcat_id`),
            UNIQUE KEY `uq_training_tcat_name` (`tcat_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_tags` (
            `ttag_id` int(11) NOT NULL AUTO_INCREMENT,
            `ttag_name` varchar(60) NOT NULL,
            `ttag_color` char(7) DEFAULT NULL,
            `ttag_created_by` int(11) NOT NULL,
            `ttag_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `ttag_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`ttag_id`),
            UNIQUE KEY `uq_training_ttag_name` (`ttag_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_tag_links` (
            `ttlink_tag_id` int(11) NOT NULL,
            `ttlink_entity` enum('course','lesson') NOT NULL,
            `ttlink_entity_id` int(11) NOT NULL,
            PRIMARY KEY (`ttlink_entity`,`ttlink_entity_id`,`ttlink_tag_id`),
            KEY `idx_training_ttlink_tag` (`ttlink_tag_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Non-default-language text for course/section/quiz/path/achievement.
        // Base columns hold the course default language. Lessons and questions use their own symmetric text tables.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_i18n` (
            `ti18n_entity` varchar(20) NOT NULL,
            `ti18n_entity_id` int(11) NOT NULL,
            `ti18n_lang` varchar(10) NOT NULL,
            `ti18n_field` varchar(40) NOT NULL,
            `ti18n_value` mediumtext NOT NULL,
            `ti18n_updated_by` int(11) NOT NULL,
            `ti18n_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`ti18n_entity`,`ti18n_entity_id`,`ti18n_lang`,`ti18n_field`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ---------- draft content (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_courses` (
            `course_id` int(11) NOT NULL AUTO_INCREMENT,
            `course_uid` char(12) NOT NULL,
            `course_kind` enum('training','document') NOT NULL DEFAULT 'training',
            `course_code` varchar(40) DEFAULT NULL,
            `course_name` varchar(200) NOT NULL,
            `course_summary` varchar(500) DEFAULT NULL,
            `course_description_html` mediumtext DEFAULT NULL,
            `course_category_id` int(11) DEFAULT NULL,
            `course_cover_media_id` int(11) DEFAULT NULL,
            `course_color` char(7) DEFAULT NULL,
            `course_default_language` varchar(10) NOT NULL DEFAULT 'en',
            `course_languages` varchar(40) NOT NULL DEFAULT 'en',
            `course_required_languages` varchar(40) NOT NULL DEFAULT 'en',
            `course_regulation_ref` varchar(100) DEFAULT NULL,
            `course_responsible_user_id` int(11) DEFAULT NULL,
            `course_sequential` tinyint(1) NOT NULL DEFAULT 1,
            `course_est_minutes` smallint(5) unsigned DEFAULT NULL,
            `course_validity_months` smallint(5) unsigned DEFAULT NULL,
            `course_renewal_lead_days` smallint(5) unsigned NOT NULL DEFAULT 30,
            `course_requires_signature` tinyint(1) NOT NULL DEFAULT 1,
            `course_attestation_text` text DEFAULT NULL,
            `course_is_qualification` tinyint(1) NOT NULL DEFAULT 0,
            `course_needs_online` tinyint(1) NOT NULL DEFAULT 1,
            `course_needs_session` tinyint(1) NOT NULL DEFAULT 0,
            `course_needs_practical` tinyint(1) NOT NULL DEFAULT 0,
            `course_external_only` tinyint(1) NOT NULL DEFAULT 0,
            `course_component_window_days` smallint(5) unsigned NOT NULL DEFAULT 90,
            `course_allow_trainer_attest` tinyint(1) NOT NULL DEFAULT 1,
            `course_eval_checklist` text DEFAULT NULL,
            `course_template_key` varchar(40) DEFAULT NULL,
            `course_current_revision_id` int(11) DEFAULT NULL,
            `course_draft_updated_at_utc` datetime(3) DEFAULT NULL,
            `course_version` int(10) unsigned NOT NULL DEFAULT 0,
            `course_created_by` int(11) NOT NULL,
            `course_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `course_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `course_archived_at` datetime DEFAULT NULL,
            `course_archived_by` int(11) DEFAULT NULL,
            PRIMARY KEY (`course_id`),
            UNIQUE KEY `uq_training_course_uid` (`course_uid`),
            UNIQUE KEY `uq_training_course_code` (`course_code`),
            KEY `idx_training_course_archived` (`course_archived_at`,`course_draft_updated_at_utc`),
            KEY `idx_training_course_category` (`course_category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_course_sections` (
            `csection_id` int(11) NOT NULL AUTO_INCREMENT,
            `csection_uid` char(12) NOT NULL,
            `csection_course_id` int(11) NOT NULL,
            `csection_title` varchar(200) NOT NULL,
            `csection_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `csection_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `csection_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`csection_id`),
            UNIQUE KEY `uq_training_csection_uid` (`csection_uid`),
            KEY `idx_training_csection_course` (`csection_course_id`,`csection_sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_lessons` (
            `lesson_id` int(11) NOT NULL AUTO_INCREMENT,
            `lesson_uid` char(12) NOT NULL,
            `lesson_course_id` int(11) NOT NULL,
            `lesson_section_id` int(11) DEFAULT NULL,
            `lesson_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `lesson_type` enum('article','document','video','image','quiz','acknowledgment') NOT NULL,
            `lesson_required` tinyint(1) NOT NULL DEFAULT 1,
            `lesson_duration_s` int(10) unsigned DEFAULT NULL,
            `lesson_allow_download` tinyint(1) NOT NULL DEFAULT 0,
            `lesson_preview_enabled` tinyint(1) NOT NULL DEFAULT 0,
            `lesson_responsible_user_id` int(11) DEFAULT NULL,
            `lesson_thumb_media_id` int(11) DEFAULT NULL,
            `lesson_min_watch_pct` tinyint(3) unsigned NOT NULL DEFAULT 90,
            `lesson_ack_require_signature` tinyint(1) NOT NULL DEFAULT 1,
            `lesson_ack_require_pin` tinyint(1) NOT NULL DEFAULT 1,
            `lesson_version` int(10) unsigned NOT NULL DEFAULT 0,
            `lesson_created_by` int(11) NOT NULL,
            `lesson_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `lesson_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `lesson_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`lesson_id`),
            UNIQUE KEY `uq_training_lesson_uid` (`lesson_uid`),
            KEY `idx_training_lesson_course` (`lesson_course_id`,`lesson_archived_at`,`lesson_section_id`,`lesson_sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Symmetric per-language lesson content (default language included).
        // Video verification lives in training_video_checks (per provider+id), not here.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_lesson_variants` (
            `lvar_lesson_id` int(11) NOT NULL,
            `lvar_lang` varchar(10) NOT NULL,
            `lvar_title` varchar(200) NOT NULL DEFAULT '',
            `lvar_description_html` mediumtext DEFAULT NULL,
            `lvar_body_html` mediumtext DEFAULT NULL,
            `lvar_word_count` int(10) unsigned NOT NULL DEFAULT 0,
            `lvar_media_id` int(11) DEFAULT NULL,
            `lvar_caption` varchar(500) DEFAULT NULL,
            `lvar_video_provider` enum('upload','youtube','vimeo') DEFAULT NULL,
            `lvar_video_ext_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            `lvar_video_ext_hash` varchar(20) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            `lvar_kb_source_article_id` int(11) DEFAULT NULL,
            `lvar_kb_source_sha256` char(64) DEFAULT NULL,
            `lvar_kb_import_body_sha256` char(64) DEFAULT NULL,
            `lvar_kb_imported_at_utc` datetime(3) DEFAULT NULL,
            `lvar_updated_by` int(11) DEFAULT NULL,
            `lvar_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`lvar_lesson_id`,`lvar_lang`),
            KEY `idx_training_lvar_media` (`lvar_media_id`),
            KEY `idx_training_lvar_kb` (`lvar_kb_source_article_id`),
            KEY `idx_training_lvar_video` (`lvar_video_provider`,`lvar_video_ext_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_lesson_resources` (
            `lres_id` int(11) NOT NULL AUTO_INCREMENT,
            `lres_uid` char(12) NOT NULL,
            `lres_lesson_id` int(11) NOT NULL,
            `lres_lang` varchar(10) DEFAULT NULL,
            `lres_kind` enum('file','link') NOT NULL,
            `lres_title` varchar(200) NOT NULL,
            `lres_media_id` int(11) DEFAULT NULL,
            `lres_url` varchar(500) DEFAULT NULL,
            `lres_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `lres_created_by` int(11) NOT NULL,
            `lres_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `lres_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`lres_id`),
            UNIQUE KEY `uq_training_lres_uid` (`lres_uid`),
            KEY `idx_training_lres_lesson` (`lres_lesson_id`,`lres_archived_at`,`lres_sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // One row per external video (provider + id + privacy hash), shared by every lesson/variant/course that uses it.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_video_checks` (
            `vcheck_id` int(11) NOT NULL AUTO_INCREMENT,
            `vcheck_provider` enum('youtube','vimeo') NOT NULL,
            `vcheck_ext_id` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `vcheck_ext_hash` varchar(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            `vcheck_title` varchar(255) DEFAULT NULL,
            `vcheck_author` varchar(255) DEFAULT NULL,
            `vcheck_thumb_media_id` int(11) DEFAULT NULL,
            `vcheck_status` enum('ok','not_found','private','embed_disabled','live','error') DEFAULT NULL,
            `vcheck_http` smallint(5) unsigned DEFAULT NULL,
            `vcheck_meta_duration_s` int(10) unsigned DEFAULT NULL,
            `vcheck_meta_duration_source` enum('oembed','data_api') DEFAULT NULL,
            `vcheck_checked_at_utc` datetime(3) DEFAULT NULL,
            `vcheck_play_duration_s` int(10) unsigned DEFAULT NULL,
            `vcheck_verified_at_utc` datetime(3) DEFAULT NULL,
            `vcheck_verified_by` int(11) DEFAULT NULL,
            `vcheck_last_error` varchar(40) DEFAULT NULL,
            `vcheck_last_error_at_utc` datetime(3) DEFAULT NULL,
            `vcheck_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`vcheck_id`),
            UNIQUE KEY `uq_training_vcheck` (`vcheck_provider`,`vcheck_ext_id`,`vcheck_ext_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ---------- quizzes (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_question_banks` (
            `qbank_id` int(11) NOT NULL AUTO_INCREMENT,
            `qbank_uid` char(12) NOT NULL,
            `qbank_parent_id` int(11) DEFAULT NULL,
            `qbank_name` varchar(150) NOT NULL,
            `qbank_description` varchar(500) DEFAULT NULL,
            `qbank_course_id` int(11) DEFAULT NULL,
            `qbank_quiz_lesson_id` int(11) DEFAULT NULL,
            `qbank_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `qbank_created_by` int(11) NOT NULL,
            `qbank_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `qbank_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `qbank_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`qbank_id`),
            UNIQUE KEY `uq_training_qbank_uid` (`qbank_uid`),
            KEY `idx_training_qbank_parent` (`qbank_parent_id`,`qbank_archived_at`,`qbank_sort`),
            KEY `idx_training_qbank_course` (`qbank_course_id`),
            KEY `idx_training_qbank_lesson` (`qbank_quiz_lesson_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Language-neutral question: structure and answer key are shared by every language.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_questions` (
            `question_id` int(11) NOT NULL AUTO_INCREMENT,
            `question_uid` char(12) NOT NULL,
            `question_bank_id` int(11) NOT NULL,
            `question_type` enum('single','multi','truefalse') NOT NULL,
            `question_media_id` int(11) DEFAULT NULL,
            `question_points` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `question_critical` tinyint(1) NOT NULL DEFAULT 0,
            `question_sort` int(10) unsigned NOT NULL DEFAULT 0,
            `question_version` int(10) unsigned NOT NULL DEFAULT 0,
            `question_created_by` int(11) NOT NULL,
            `question_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `question_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `question_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`question_id`),
            UNIQUE KEY `uq_training_question_uid` (`question_uid`),
            KEY `idx_training_question_bank` (`question_bank_id`,`question_archived_at`,`question_sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_question_texts` (
            `qtext_question_id` int(11) NOT NULL,
            `qtext_lang` varchar(10) NOT NULL,
            `qtext_text` text NOT NULL,
            `qtext_explanation` text DEFAULT NULL,
            `qtext_topic` varchar(100) DEFAULT NULL,
            `qtext_media_id` int(11) DEFAULT NULL,
            `qtext_updated_by` int(11) NOT NULL,
            `qtext_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`qtext_question_id`,`qtext_lang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_question_options` (
            `option_id` int(11) NOT NULL AUTO_INCREMENT,
            `option_uid` char(12) NOT NULL,
            `option_question_id` int(11) NOT NULL,
            `option_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `option_is_correct` tinyint(1) NOT NULL DEFAULT 0,
            `option_pinned` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`option_id`),
            UNIQUE KEY `uq_training_option_uid` (`option_uid`),
            KEY `idx_training_option_question` (`option_question_id`,`option_sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_option_texts` (
            `otext_option_id` int(11) NOT NULL,
            `otext_lang` varchar(10) NOT NULL,
            `otext_text` varchar(1000) NOT NULL,
            `otext_feedback` varchar(500) DEFAULT NULL,
            PRIMARY KEY (`otext_option_id`,`otext_lang`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_quizzes` (
            `quiz_id` int(11) NOT NULL AUTO_INCREMENT,
            `quiz_uid` char(12) NOT NULL,
            `quiz_lesson_id` int(11) NOT NULL,
            `quiz_role` enum('standalone','exam','check') NOT NULL,
            `quiz_pass_pct` tinyint(3) unsigned NOT NULL DEFAULT 80,
            `quiz_max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 3,
            `quiz_time_limit_s` smallint(5) unsigned DEFAULT NULL,
            `quiz_shuffle_questions` tinyint(1) NOT NULL DEFAULT 1,
            `quiz_shuffle_options` tinyint(1) NOT NULL DEFAULT 1,
            `quiz_feedback_mode` enum('score_only','missed_questions','answers_after_pass') NOT NULL DEFAULT 'missed_questions',
            `quiz_show_review` tinyint(1) NOT NULL DEFAULT 1,
            `quiz_must_pass` tinyint(1) NOT NULL DEFAULT 1,
            `quiz_intro` varchar(1000) DEFAULT NULL,
            `quiz_version` int(10) unsigned NOT NULL DEFAULT 0,
            `quiz_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `quiz_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`quiz_id`),
            UNIQUE KEY `uq_training_quiz_uid` (`quiz_uid`),
            UNIQUE KEY `uq_training_quiz_lesson` (`quiz_lesson_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_quiz_rules` (
            `qrule_id` int(11) NOT NULL AUTO_INCREMENT,
            `qrule_uid` char(12) NOT NULL,
            `qrule_quiz_id` int(11) NOT NULL,
            `qrule_bank_id` int(11) NOT NULL,
            `qrule_include_descendants` tinyint(1) NOT NULL DEFAULT 1,
            `qrule_count` smallint(5) unsigned NOT NULL DEFAULT 0,
            `qrule_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`qrule_id`),
            UNIQUE KEY `uq_training_qrule_uid` (`qrule_uid`),
            KEY `idx_training_qrule_quiz` (`qrule_quiz_id`,`qrule_sort`),
            KEY `idx_training_qrule_bank` (`qrule_bank_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ---------- paths / prereqs / achievements (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_paths` (
            `tpath_id` int(11) NOT NULL AUTO_INCREMENT,
            `tpath_uid` char(12) NOT NULL,
            `tpath_name` varchar(200) NOT NULL,
            `tpath_description` varchar(1000) DEFAULT NULL,
            `tpath_color` char(7) DEFAULT NULL,
            `tpath_cover_media_id` int(11) DEFAULT NULL,
            `tpath_sequential` tinyint(1) NOT NULL DEFAULT 1,
            `tpath_achievement_id` int(11) DEFAULT NULL,
            `tpath_version` int(10) unsigned NOT NULL DEFAULT 0,
            `tpath_created_by` int(11) NOT NULL,
            `tpath_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `tpath_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `tpath_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`tpath_id`),
            UNIQUE KEY `uq_training_tpath_uid` (`tpath_uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_path_courses` (
            `tpcourse_path_id` int(11) NOT NULL,
            `tpcourse_course_id` int(11) NOT NULL,
            `tpcourse_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `tpcourse_required` tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (`tpcourse_path_id`,`tpcourse_course_id`),
            KEY `idx_training_tpcourse_course` (`tpcourse_course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_course_prereqs` (
            `prereq_course_id` int(11) NOT NULL,
            `prereq_requires_course_id` int(11) NOT NULL,
            `prereq_created_by` int(11) NOT NULL,
            `prereq_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`prereq_course_id`,`prereq_requires_course_id`),
            KEY `idx_training_prereq_requires` (`prereq_requires_course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_achievements` (
            `achievement_id` int(11) NOT NULL AUTO_INCREMENT,
            `achievement_uid` char(12) NOT NULL,
            `achievement_name` varchar(100) NOT NULL,
            `achievement_description` varchar(500) DEFAULT NULL,
            `achievement_icon` varchar(40) NOT NULL DEFAULT 'award',
            `achievement_color` char(7) NOT NULL DEFAULT '#D97706',
            `achievement_rule_type` enum('manual','course_completed','category_completed','path_completed','perfect_score','first_attempt_pass','on_time_streak','courses_completed_count') NOT NULL DEFAULT 'manual',
            `achievement_rule_json` text DEFAULT NULL,
            `achievement_active` tinyint(1) NOT NULL DEFAULT 1,
            `achievement_sort` smallint(5) unsigned NOT NULL DEFAULT 0,
            `achievement_version` int(10) unsigned NOT NULL DEFAULT 0,
            `achievement_created_by` int(11) NOT NULL,
            `achievement_created_at` datetime NOT NULL DEFAULT current_timestamp(),
            `achievement_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            `achievement_archived_at` datetime DEFAULT NULL,
            PRIMARY KEY (`achievement_id`),
            UNIQUE KEY `uq_training_achievement_uid` (`achievement_uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ---------- media, revisions, ledger (A) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_media` (
            `media_id` int(11) NOT NULL AUTO_INCREMENT,
            `media_sha256` char(64) NOT NULL,
            `media_kind` enum('pdf','page','video','image','file','evidence') NOT NULL,
            `media_mime` varchar(100) NOT NULL,
            `media_ext` varchar(10) NOT NULL,
            `media_bytes` bigint(20) unsigned NOT NULL,
            `media_path` varchar(255) NOT NULL,
            `media_original_name` varchar(255) DEFAULT NULL,
            `media_width` smallint(5) unsigned DEFAULT NULL,
            `media_height` smallint(5) unsigned DEFAULT NULL,
            `media_page_count` smallint(5) unsigned DEFAULT NULL,
            `media_duration_ms` int(10) unsigned DEFAULT NULL,
            `media_video_codec` varchar(8) DEFAULT NULL,
            `media_audio_codec` varchar(8) DEFAULT NULL,
            `media_faststart` tinyint(1) DEFAULT NULL,
            `media_uploaded_by` int(11) NOT NULL,
            `media_created_at_utc` datetime(3) NOT NULL,
            `media_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `media_row_sha256` char(64) NOT NULL,
            PRIMARY KEY (`media_id`),
            UNIQUE KEY `uq_training_media_sha_kind` (`media_sha256`,`media_kind`),
            KEY `idx_training_media_kind` (`media_kind`,`media_created_at_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_media_pages` (
            `mpage_pdf_media_id` int(11) NOT NULL,
            `mpage_number` smallint(5) unsigned NOT NULL,
            `mpage_media_id` int(11) NOT NULL,
            `mpage_created_at_utc` datetime(3) NOT NULL,
            `mpage_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `mpage_row_sha256` char(64) NOT NULL,
            PRIMARY KEY (`mpage_pdf_media_id`,`mpage_number`),
            KEY `idx_training_mpage_media` (`mpage_media_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_revisions` (
            `revision_id` int(11) NOT NULL AUTO_INCREMENT,
            `revision_course_id` int(11) NOT NULL,
            `revision_number` smallint(5) unsigned NOT NULL,
            `revision_kind` enum('training','document') NOT NULL,
            `revision_schema` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `revision_json` longtext NOT NULL,
            `revision_sha256` char(64) NOT NULL,
            `revision_languages` varchar(40) NOT NULL,
            `revision_change_note` varchar(1000) NOT NULL,
            `revision_requires_retraining` tinyint(1) NOT NULL DEFAULT 0,
            `revision_retrain_due_days` smallint(5) unsigned DEFAULT NULL,
            `revision_published_by` int(11) NOT NULL,
            `revision_published_at_utc` datetime(3) NOT NULL,
            `revision_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `revision_row_sha256` char(64) NOT NULL,
            PRIMARY KEY (`revision_id`),
            UNIQUE KEY `uq_training_revision` (`revision_course_id`,`revision_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_revision_media` (
            `rmedia_revision_id` int(11) NOT NULL,
            `rmedia_media_id` int(11) NOT NULL,
            `rmedia_media_sha256` char(64) NOT NULL,
            `rmedia_downloadable` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`rmedia_revision_id`,`rmedia_media_id`),
            KEY `idx_training_rmedia_media` (`rmedia_media_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_events` (
            `tevent_seq` bigint(20) unsigned NOT NULL,
            `tevent_at_utc` datetime(3) NOT NULL,
            `tevent_type` varchar(64) NOT NULL,
            `tevent_actor_type` enum('user','contact','kiosk','system') NOT NULL,
            `tevent_actor_user_id` int(11) DEFAULT NULL,
            `tevent_actor_contact_id` int(11) DEFAULT NULL,
            `tevent_kiosk_id` int(11) DEFAULT NULL,
            `tevent_ksess_id` int(11) DEFAULT NULL,
            `tevent_subject_contact_id` int(11) DEFAULT NULL,
            `tevent_course_id` int(11) DEFAULT NULL,
            `tevent_entity_type` varchar(40) DEFAULT NULL,
            `tevent_entity_id` int(11) DEFAULT NULL,
            `tevent_entity_sha256` char(64) DEFAULT NULL,
            `tevent_payload_json` mediumtext NOT NULL,
            `tevent_user_agent` varchar(255) DEFAULT NULL,
            `tevent_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `tevent_prev_hash` char(64) NOT NULL,
            `tevent_hash` char(64) NOT NULL,
            PRIMARY KEY (`tevent_seq`),
            UNIQUE KEY `uq_training_tevent_hash` (`tevent_hash`),
            KEY `idx_training_tevent_subject` (`tevent_subject_contact_id`,`tevent_at_utc`),
            KEY `idx_training_tevent_type` (`tevent_type`,`tevent_at_utc`),
            KEY `idx_training_tevent_entity` (`tevent_entity_type`,`tevent_entity_id`),
            KEY `idx_training_tevent_kiosk` (`tevent_kiosk_id`,`tevent_type`,`tevent_at_utc`),
            KEY `idx_training_tevent_course` (`tevent_course_id`,`tevent_at_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_ledger_head` (
            `lhead_id` tinyint(3) unsigned NOT NULL,
            `lhead_last_seq` bigint(20) unsigned NOT NULL,
            `lhead_last_hash` char(64) NOT NULL,
            `lhead_updated_at_utc` datetime(3) DEFAULT NULL,
            PRIMARY KEY (`lhead_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // ---------- certificates (A5) - created now, first written in Phase 2 ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_cert_counters` (
            `certctr_year` smallint(5) unsigned NOT NULL,
            `certctr_last_seq` int(10) unsigned NOT NULL DEFAULT 0,
            `certctr_updated_at_utc` datetime(3) DEFAULT NULL,
            PRIMARY KEY (`certctr_year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_cert_tokens` (
            `certtok_id` int(11) NOT NULL AUTO_INCREMENT,
            `certtok_completion_id` int(11) NOT NULL,
            `certtok_nonce` char(32) NOT NULL,
            `certtok_token_sha256` char(64) NOT NULL,
            `certtok_created_at_utc` datetime(3) NOT NULL,
            `certtok_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
            `certtok_row_sha256` char(64) NOT NULL,
            PRIMARY KEY (`certtok_id`),
            UNIQUE KEY `uq_training_certtok_completion` (`certtok_completion_id`),
            UNIQUE KEY `uq_training_certtok_token` (`certtok_token_sha256`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // The ONLY place the ledger head is created (plus db.sql). Ledger::append never inserts it.
        mysqli_query($mysqli, "INSERT IGNORE INTO `training_ledger_head` (`lhead_id`,`lhead_last_seq`,`lhead_last_hash`,`lhead_updated_at_utc`) VALUES (1, 0, REPEAT('0',64), NULL)");

        foreach ([['Safety','#DC2626','hard-hat',1], ['Equipment','#D97706','truck-loading',2], ['Quality','#2563EB','check-double',3],
                  ['HR & Policy','#7C3AED','user-shield',4], ['IT','#0891B2','laptop',5], ['Other','#475569','graduation-cap',6]] as [$n,$c,$i,$s]) {
            mysqli_query($mysqli, "INSERT IGNORE INTO `training_categories` (`tcat_name`,`tcat_color`,`tcat_icon`,`tcat_sort`,`tcat_created_by`) VALUES ('" . mysqli_real_escape_string($mysqli, $n) . "','$c','$i',$s,0)");
        }

        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_training', 'Training: courses, content, quizzes, records and reports' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_training')");
        mysqli_query($mysqli, "INSERT INTO `modules` (`module_name`, `module_description`)
            SELECT 'module_training_kiosk', 'Training kiosks and learner PINs (grants impersonation ability)' FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `module_name` = 'module_training_kiosk')");

        foreach ([
            "`config_module_enable_training` tinyint(1) NOT NULL DEFAULT 0",
            "`config_training_languages` varchar(40) NOT NULL DEFAULT 'en,es'",
            "`config_training_default_pass_pct` tinyint(3) unsigned NOT NULL DEFAULT 80",
            "`config_training_default_max_attempts` tinyint(3) unsigned NOT NULL DEFAULT 3",
            "`config_training_attestation_text` text DEFAULT NULL",
            "`config_training_video_max_mb` smallint(5) unsigned NOT NULL DEFAULT 95",
            "`config_training_pdf_max_mb` smallint(5) unsigned NOT NULL DEFAULT 50",
            "`config_training_pdf_max_pages` smallint(5) unsigned NOT NULL DEFAULT 150",
            "`config_training_image_max_mb` smallint(5) unsigned NOT NULL DEFAULT 15",
            "`config_training_file_max_mb` smallint(5) unsigned NOT NULL DEFAULT 50",
            "`config_training_media_budget_mb` int(10) unsigned NOT NULL DEFAULT 1024",
            "`config_training_youtube_api_key` text DEFAULT NULL",
            "`config_training_ledger_verified_at_utc` datetime(3) DEFAULT NULL",
            "`config_training_ledger_verify_result` varchar(255) DEFAULT NULL",
        ] as $col) {
            mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS $col");
        }

        // A7: per-integration Odoo API protocol. Existing rows keep today's behaviour until a JSON-2 test passes.
        mysqli_query($mysqli, "ALTER TABLE `odoo_integrations` ADD COLUMN IF NOT EXISTS `api_protocol` varchar(10) NOT NULL DEFAULT 'jsonrpc' AFTER `api_key_enc`");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.91'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.91') {
        // Training / LMS Phase 2 - assignments, compliance, records, reports (plan rev 3 §B; A5/A6/A8/A10/A16/A22).
        // Idempotent: IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / INSERT IGNORE. No triggers, no FKs (plan A8).
        // One mysqli_query per CREATE TABLE, DDL exactly as Phase 2 spec §2.1 incl. table options (17 statements).

        // ---------- people / directory (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `contact_odoo_attributes` (
              `coattr_contact_id` int(11) NOT NULL,
              `coattr_odoo_integration_id` int(11) NOT NULL,
              `coattr_odoo_employee_id` int(11) NOT NULL,
              `coattr_odoo_name` varchar(200) DEFAULT NULL,
              `coattr_odoo_target_sha` char(64) DEFAULT NULL,
              `coattr_job_id` int(11) DEFAULT NULL,
              `coattr_job_name` varchar(200) DEFAULT NULL,
              `coattr_work_location_id` int(11) DEFAULT NULL,
              `coattr_work_location_name` varchar(200) DEFAULT NULL,
              `coattr_odoo_create_date` date DEFAULT NULL,
              `coattr_odoo_pin_ok` tinyint(1) DEFAULT NULL,
              `coattr_attrs_synced_at_utc` datetime(3) DEFAULT NULL,
              `coattr_pin_synced_at_utc` datetime(3) DEFAULT NULL,
              `coattr_link_state` enum('unchecked','ok','repointed','mismatch','missing') NOT NULL DEFAULT 'unchecked',
              `coattr_link_detail` varchar(255) DEFAULT NULL,
              `coattr_link_seen_name` varchar(200) DEFAULT NULL,
              `coattr_link_suggested_employee_id` int(11) DEFAULT NULL,
              `coattr_link_checked_at_utc` datetime(3) DEFAULT NULL,
              `coattr_link_confirmed_by` int(11) DEFAULT NULL,
              `coattr_link_confirmed_at_utc` datetime(3) DEFAULT NULL,
              `coattr_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`coattr_contact_id`),
              KEY `idx_coattr_job` (`coattr_job_id`),
              KEY `idx_coattr_location` (`coattr_work_location_id`),
              KEY `idx_coattr_employee` (`coattr_odoo_integration_id`,`coattr_odoo_employee_id`),
              KEY `idx_coattr_state` (`coattr_link_state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_roster` (
              `roster_contact_id` int(11) NOT NULL,
              `roster_state` enum('auto','include','exclude') NOT NULL DEFAULT 'auto',
              `roster_reason` varchar(255) DEFAULT NULL,
              `roster_updated_by` int(11) NOT NULL,
              `roster_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`roster_contact_id`),
              KEY `idx_training_roster_state` (`roster_state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_job_groups` (
              `jobgroup_id` int(11) NOT NULL AUTO_INCREMENT,
              `jobgroup_name` varchar(100) NOT NULL,
              `jobgroup_description` varchar(255) DEFAULT NULL,
              `jobgroup_version` int(10) unsigned NOT NULL DEFAULT 0,
              `jobgroup_created_by` int(11) NOT NULL,
              `jobgroup_created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `jobgroup_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              `jobgroup_archived_at` datetime DEFAULT NULL,
              PRIMARY KEY (`jobgroup_id`),
              UNIQUE KEY `uq_training_jobgroup_name` (`jobgroup_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_job_group_members` (
              `jgmember_jobgroup_id` int(11) NOT NULL,
              `jgmember_contact_id` int(11) NOT NULL,
              `jgmember_added_by` int(11) NOT NULL,
              `jgmember_added_at` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`jgmember_jobgroup_id`,`jgmember_contact_id`),
              KEY `idx_training_jgmember_contact` (`jgmember_contact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_job_group_titles` (
              `jgtitle_jobgroup_id` int(11) NOT NULL,
              `jgtitle_normalized` varchar(200) NOT NULL,
              PRIMARY KEY (`jgtitle_jobgroup_id`,`jgtitle_normalized`),
              KEY `idx_training_jgtitle` (`jgtitle_normalized`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");


        // ---------- rules and assignments (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_requirements` (
              `requirement_id` int(11) NOT NULL AUTO_INCREMENT,
              `requirement_request_uid` char(32) DEFAULT NULL,
              `requirement_name` varchar(150) NOT NULL,
              `requirement_course_id` int(11) NOT NULL,
              `requirement_all_people` tinyint(1) NOT NULL DEFAULT 0,
              `requirement_required` tinyint(1) NOT NULL DEFAULT 1,
              `requirement_new_hires_only` tinyint(1) NOT NULL DEFAULT 0,
              `requirement_due_days` smallint(5) unsigned NOT NULL DEFAULT 30,
              `requirement_baseline_due_on` date NOT NULL,
              `requirement_due_days_from_hire` smallint(5) unsigned NOT NULL DEFAULT 7,
              `requirement_one_time` tinyint(1) NOT NULL DEFAULT 0,
              `requirement_is_manual` tinyint(1) NOT NULL DEFAULT 0,
              `requirement_note` varchar(500) DEFAULT NULL,
              `requirement_effective_on` date NOT NULL,
              `requirement_criteria_sha256` char(64) NOT NULL,
              `requirement_version` int(10) unsigned NOT NULL DEFAULT 0,
              `requirement_created_by` int(11) NOT NULL,
              `requirement_created_at_utc` datetime(3) NOT NULL,
              `requirement_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              `requirement_archived_at` datetime DEFAULT NULL,
              `requirement_archived_by` int(11) DEFAULT NULL,
              PRIMARY KEY (`requirement_id`),
              UNIQUE KEY `uq_training_req_request` (`requirement_request_uid`),
              KEY `idx_training_req_course` (`requirement_course_id`,`requirement_archived_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_requirement_criteria` (
              `rcrit_requirement_id` int(11) NOT NULL,
              `rcrit_kind` enum('department','odoo_job','odoo_location','jobgroup','contact') NOT NULL,
              `rcrit_value_id` int(11) NOT NULL,
              `rcrit_value_label` varchar(200) DEFAULT NULL,
              PRIMARY KEY (`rcrit_requirement_id`,`rcrit_kind`,`rcrit_value_id`),
              KEY `idx_training_rcrit_value` (`rcrit_kind`,`rcrit_value_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_assignments` (
              `tassign_id` int(11) NOT NULL AUTO_INCREMENT,
              `tassign_contact_id` int(11) NOT NULL,
              `tassign_course_id` int(11) NOT NULL,
              `tassign_reason` enum('requirement','renewal','retrain','reissue') NOT NULL,
              `tassign_anchor` varchar(64) NOT NULL,
              `tassign_requirement_id` int(11) DEFAULT NULL,
              `tassign_required` tinyint(1) NOT NULL DEFAULT 1,
              `tassign_due_on` date NOT NULL,
              `tassign_original_due_on` date NOT NULL,
              `tassign_onboarding_from_on` date DEFAULT NULL,
              `tassign_status` enum('open','completed','waived','cancelled') NOT NULL DEFAULT 'open',
              `tassign_open_guard` tinyint(1) DEFAULT 1,
              `tassign_waived_until` date DEFAULT NULL,
              `tassign_completion_id` int(11) DEFAULT NULL,
              `tassign_created_at_utc` datetime(3) NOT NULL,
              `tassign_created_by` int(11) DEFAULT NULL,
              `tassign_closed_at_utc` datetime(3) DEFAULT NULL,
              `tassign_closed_by_user_id` int(11) DEFAULT NULL,
              `tassign_close_reason` varchar(40) DEFAULT NULL,
              `tassign_close_note` varchar(500) DEFAULT NULL,
              `tassign_reopened_count` smallint(5) unsigned NOT NULL DEFAULT 0,
              `tassign_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`tassign_id`),
              UNIQUE KEY `uq_training_assign_open` (`tassign_contact_id`,`tassign_course_id`,`tassign_open_guard`),
              KEY `idx_training_assign_status_due` (`tassign_status`,`tassign_due_on`),
              KEY `idx_training_assign_course` (`tassign_course_id`,`tassign_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");


        // ---------- records (A) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_completions` (
              `completion_id` int(11) NOT NULL AUTO_INCREMENT,
              `completion_contact_id` int(11) NOT NULL,
              `completion_course_id` int(11) NOT NULL,
              `completion_course_kind` enum('training','document') NOT NULL,
              `completion_revision_id` int(11) DEFAULT NULL,
              `completion_revision_sha256` char(64) DEFAULT NULL,
              `completion_assignment_id` int(11) DEFAULT NULL,
              `completion_source_key` varchar(48) NOT NULL,
              `completion_method` enum('online','session','blended','evaluation','external','legacy_paper') NOT NULL,
              `completion_proof` enum('self_pin_signature','evaluator_signed','self_pin','portal_login','trainer_attested','document','agent_recorded') NOT NULL,
              `completion_completed_on` date NOT NULL,
              `completion_trained_on` date DEFAULT NULL,
              `completion_evaluated_on` date DEFAULT NULL,
              `completion_expires_on` date DEFAULT NULL,
              `completion_language` varchar(10) NOT NULL DEFAULT 'en',
              `completion_score_pct` decimal(5,2) DEFAULT NULL,
              `completion_pass_mark_pct` tinyint(3) unsigned DEFAULT NULL,
              `completion_attempts_used` tinyint(3) unsigned DEFAULT NULL,
              `completion_duration_minutes` smallint(5) unsigned DEFAULT NULL,
              `completion_run_id` int(11) DEFAULT NULL,
              `completion_attempt_id` int(11) DEFAULT NULL,
              `completion_tsession_id` int(11) DEFAULT NULL,
              `completion_tattendee_id` int(11) DEFAULT NULL,
              `completion_evaluation_id` int(11) DEFAULT NULL,
              `completion_trainer_contact_id` int(11) DEFAULT NULL,
              `completion_trainer_user_id` int(11) DEFAULT NULL,
              `completion_trainer_name` varchar(200) DEFAULT NULL,
              `completion_evaluator_name` varchar(200) DEFAULT NULL,
              `completion_learner_tsig_id` int(11) DEFAULT NULL,
              `completion_trainer_tsig_id` int(11) DEFAULT NULL,
              `completion_kiosk_id` int(11) DEFAULT NULL,
              `completion_asset_id` int(11) DEFAULT NULL,
              `completion_pin_source` enum('odoo','local') DEFAULT NULL,
              `completion_odoo_employee_id` int(11) DEFAULT NULL,
              `completion_external_issuer` varchar(200) DEFAULT NULL,
              `completion_external_ref` varchar(100) DEFAULT NULL,
              `completion_evidence_media_id` int(11) DEFAULT NULL,
              `completion_recorded_by_user_id` int(11) DEFAULT NULL,
              `completion_attestation_text` text DEFAULT NULL,
              `completion_notes` text DEFAULT NULL,
              `completion_cert_number` varchar(20) DEFAULT NULL,
              `completion_snap_contact_name` varchar(200) NOT NULL,
              `completion_snap_contact_title` varchar(200) DEFAULT NULL,
              `completion_snap_client_id` int(11) NOT NULL DEFAULT 0,
              `completion_snap_client_name` varchar(200) DEFAULT NULL,
              `completion_snap_course_name` varchar(200) NOT NULL,
              `completion_snap_course_code` varchar(40) DEFAULT NULL,
              `completion_snap_revision_number` smallint(5) unsigned DEFAULT NULL,
              `completion_snap_regulation_ref` varchar(100) DEFAULT NULL,
              `completion_supersedes_id` int(11) DEFAULT NULL,
              `completion_recorded_at_utc` datetime(3) NOT NULL,
              `completion_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
              `completion_row_sha256` char(64) NOT NULL,
              PRIMARY KEY (`completion_id`),
              UNIQUE KEY `uq_training_completion_source` (`completion_source_key`),
              UNIQUE KEY `uq_training_completion_cert` (`completion_cert_number`),
              KEY `idx_training_completion_contact` (`completion_contact_id`,`completion_course_id`,`completion_completed_on`),
              KEY `idx_training_completion_course_exp` (`completion_course_id`,`completion_expires_on`),
              KEY `idx_training_completion_exp` (`completion_expires_on`),
              KEY `idx_training_completion_recorded` (`completion_recorded_at_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_completion_voids` (
              `cvoid_id` int(11) NOT NULL AUTO_INCREMENT,
              `cvoid_completion_id` int(11) NOT NULL,
              `cvoid_reason` varchar(1000) NOT NULL,
              `cvoid_by_user_id` int(11) NOT NULL,
              `cvoid_at_utc` datetime(3) NOT NULL,
              `cvoid_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
              `cvoid_row_sha256` char(64) NOT NULL,
              PRIMARY KEY (`cvoid_id`),
              UNIQUE KEY `uq_training_cvoid` (`cvoid_completion_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");


        // ---------- sessions (M while open; digest-frozen at finalize) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_sessions` (
              `tsession_id` int(11) NOT NULL AUTO_INCREMENT,
              `tsession_request_uid` char(32) DEFAULT NULL,
              `tsession_course_id` int(11) NOT NULL,
              `tsession_revision_id` int(11) DEFAULT NULL,
              `tsession_status` enum('open','finalized','cancelled') NOT NULL DEFAULT 'open',
              `tsession_held_on` date NOT NULL,
              `tsession_start_time` time DEFAULT NULL,
              `tsession_duration_minutes` smallint(5) unsigned DEFAULT NULL,
              `tsession_client_id` int(11) NOT NULL DEFAULT 0,
              `tsession_location` varchar(200) DEFAULT NULL,
              `tsession_topic` varchar(200) DEFAULT NULL,
              `tsession_notes` text DEFAULT NULL,
              `tsession_trainer_contact_id` int(11) DEFAULT NULL,
              `tsession_trainer_user_id` int(11) DEFAULT NULL,
              `tsession_trainer_name` varchar(200) NOT NULL,
              `tsession_channel` enum('agent','kiosk') NOT NULL DEFAULT 'agent',
              `tsession_is_backfill` tinyint(1) NOT NULL DEFAULT 0,
              `tsession_evidence_media_id` int(11) DEFAULT NULL,
              `tsession_created_by_user_id` int(11) DEFAULT NULL,
              `tsession_created_kiosk_id` int(11) DEFAULT NULL,
              `tsession_started_at_utc` datetime(3) DEFAULT NULL,
              `tsession_trainer_tsig_id` int(11) DEFAULT NULL,
              `tsession_finalized_at_utc` datetime(3) DEFAULT NULL,
              `tsession_finalized_by_user_id` int(11) DEFAULT NULL,
              `tsession_finalized_by_contact_id` int(11) DEFAULT NULL,
              `tsession_finalize_attest` varchar(255) DEFAULT NULL,
              `tsession_digest_v` tinyint(3) unsigned DEFAULT NULL,
              `tsession_sha256` char(64) DEFAULT NULL,
              `tsession_cancel_reason` varchar(255) DEFAULT NULL,
              `tsession_version` int(10) unsigned NOT NULL DEFAULT 0,
              `tsession_created_at` datetime NOT NULL DEFAULT current_timestamp(),
              `tsession_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`tsession_id`),
              UNIQUE KEY `uq_training_tsession_request` (`tsession_request_uid`),
              KEY `idx_training_tsession_course` (`tsession_course_id`,`tsession_held_on`),
              KEY `idx_training_tsession_status` (`tsession_status`,`tsession_held_on`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_session_attendees` (
              `tattendee_id` int(11) NOT NULL AUTO_INCREMENT,
              `tattendee_tsession_id` int(11) NOT NULL,
              `tattendee_contact_id` int(11) NOT NULL,
              `tattendee_proof` enum('self_pin_signature','self_pin','trainer_attested','document','agent_recorded') NOT NULL,
              `tattendee_attest_reason` varchar(255) DEFAULT NULL,
              `tattendee_checked_in_at_utc` datetime(3) NOT NULL,
              `tattendee_kiosk_id` int(11) DEFAULT NULL,
              `tattendee_tsig_id` int(11) DEFAULT NULL,
              `tattendee_attendance` enum('present','partial','absent') NOT NULL DEFAULT 'present',
              `tattendee_practical` enum('not_evaluated','pass','fail') NOT NULL DEFAULT 'not_evaluated',
              `tattendee_notes` varchar(500) DEFAULT NULL,
              `tattendee_marked_by_contact_id` int(11) DEFAULT NULL,
              `tattendee_marked_by_user_id` int(11) DEFAULT NULL,
              `tattendee_removed_at_utc` datetime(3) DEFAULT NULL,
              `tattendee_removed_reason` varchar(255) DEFAULT NULL,
              PRIMARY KEY (`tattendee_id`),
              UNIQUE KEY `uq_training_tattendee` (`tattendee_tsession_id`,`tattendee_contact_id`),
              KEY `idx_training_tattendee_contact` (`tattendee_contact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_evaluations` (
              `evaluation_id` int(11) NOT NULL AUTO_INCREMENT,
              `evaluation_source_key` varchar(48) NOT NULL,
              `evaluation_contact_id` int(11) NOT NULL,
              `evaluation_course_id` int(11) NOT NULL,
              `evaluation_revision_id` int(11) DEFAULT NULL,
              `evaluation_run_id` int(11) DEFAULT NULL,
              `evaluation_tsession_id` int(11) DEFAULT NULL,
              `evaluation_channel` enum('agent','kiosk','session') NOT NULL,
              `evaluation_evaluator_contact_id` int(11) DEFAULT NULL,
              `evaluation_evaluator_user_id` int(11) DEFAULT NULL,
              `evaluation_evaluator_name` varchar(200) NOT NULL,
              `evaluation_evaluated_on` date NOT NULL,
              `evaluation_result` enum('pass','fail') NOT NULL,
              `evaluation_equipment` varchar(200) DEFAULT NULL,
              `evaluation_checklist_json` text DEFAULT NULL,
              `evaluation_notes` text DEFAULT NULL,
              `evaluation_proof` enum('evaluator_signed','document','agent_recorded') NOT NULL,
              `evaluation_evaluator_tsig_id` int(11) DEFAULT NULL,
              `evaluation_evaluatee_tsig_id` int(11) DEFAULT NULL,
              `evaluation_evidence_media_id` int(11) DEFAULT NULL,
              `evaluation_kiosk_id` int(11) DEFAULT NULL,
              `evaluation_recorded_by_user_id` int(11) DEFAULT NULL,
              `evaluation_recorded_at_utc` datetime(3) NOT NULL,
              `evaluation_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
              `evaluation_row_sha256` char(64) NOT NULL,
              PRIMARY KEY (`evaluation_id`),
              UNIQUE KEY `uq_training_eval_source` (`evaluation_source_key`),
              KEY `idx_training_eval_contact` (`evaluation_contact_id`,`evaluation_course_id`,`evaluation_evaluated_on`),
              KEY `idx_training_eval_session` (`evaluation_tsession_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");


        // ---------- trainers (M) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_trainers` (
              `trainer_contact_id` int(11) NOT NULL,
              `trainer_user_id` int(11) DEFAULT NULL,
              `trainer_title` varchar(100) DEFAULT NULL,
              `trainer_can_train` tinyint(1) NOT NULL DEFAULT 1,
              `trainer_can_evaluate` tinyint(1) NOT NULL DEFAULT 0,
              `trainer_can_setup_pins` tinyint(1) NOT NULL DEFAULT 0,
              `trainer_can_unlock` tinyint(1) NOT NULL DEFAULT 0,
              `trainer_can_view_team` tinyint(1) NOT NULL DEFAULT 1,
              `trainer_all_courses` tinyint(1) NOT NULL DEFAULT 0,
              `trainer_all_departments` tinyint(1) NOT NULL DEFAULT 0,
              `trainer_qualifications` text DEFAULT NULL,
              `trainer_active` tinyint(1) NOT NULL DEFAULT 1,
              `trainer_version` int(10) unsigned NOT NULL DEFAULT 0,
              `trainer_added_by` int(11) NOT NULL,
              `trainer_added_at` datetime NOT NULL DEFAULT current_timestamp(),
              `trainer_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`trainer_contact_id`),
              KEY `idx_training_trainer_user` (`trainer_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_trainer_courses` (
              `ttcourse_contact_id` int(11) NOT NULL,
              `ttcourse_course_id` int(11) NOT NULL,
              PRIMARY KEY (`ttcourse_contact_id`,`ttcourse_course_id`),
              KEY `idx_training_ttcourse_course` (`ttcourse_course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_trainer_departments` (
              `ttdept_contact_id` int(11) NOT NULL,
              `ttdept_client_id` int(11) NOT NULL,
              PRIMARY KEY (`ttdept_contact_id`,`ttdept_client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");


        // ---------- snapshots (D) ----------
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_compliance_daily` (
              `tdaily_date` date NOT NULL,
              `tdaily_client_id` int(11) NOT NULL,
              `tdaily_course_id` int(11) NOT NULL,
              `tdaily_eligible_people` int(11) NOT NULL DEFAULT 0,
              `tdaily_required_pairs` int(11) NOT NULL DEFAULT 0,
              `tdaily_current_pairs` int(11) NOT NULL DEFAULT 0,
              `tdaily_overdue_pairs` int(11) NOT NULL DEFAULT 0,
              `tdaily_overdue_people` int(11) NOT NULL DEFAULT 0,
              `tdaily_expiring_30` int(11) NOT NULL DEFAULT 0,
              `tdaily_waived_pairs` int(11) NOT NULL DEFAULT 0,
              `tdaily_completions` int(11) NOT NULL DEFAULT 0,
              `tdaily_created_at` datetime NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`tdaily_date`,`tdaily_client_id`,`tdaily_course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        foreach ([
            "`config_training_due_soon_days` smallint(5) unsigned NOT NULL DEFAULT 30",
            "`config_training_reissue_days` smallint(5) unsigned NOT NULL DEFAULT 14",
            "`config_training_reopen_window_days` smallint(5) unsigned NOT NULL DEFAULT 90",
            "`config_training_evidence_max_mb` smallint(5) unsigned NOT NULL DEFAULT 20",
            "`config_training_compliance_target_pct` tinyint(3) unsigned NOT NULL DEFAULT 95",
            "`config_training_reconciled_at_utc` datetime(3) DEFAULT NULL",
            "`config_training_snapshot_last_on` date DEFAULT NULL",
            "`config_training_odoo_sync_enabled` tinyint(1) NOT NULL DEFAULT 0",
            "`config_training_odoo_sync_last_on` date DEFAULT NULL",
            "`config_training_odoo_sync_last_result` varchar(255) DEFAULT NULL",
            "`config_training_odoo_link_checked_at_utc` datetime(3) DEFAULT NULL",
            "`config_training_odoo_target_sha` char(64) DEFAULT NULL",
            "`config_training_hire_fill_since` date DEFAULT NULL",
        ] as $col) {
            mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS $col");
        }

        // Records mutex (spec §0 #4 / §1.4 #11): the row every completion writer locks first.
        // The ONLY place it is created (plus db.sql); RecordsMutex::acquire never inserts it.
        mysqli_query($mysqli, "INSERT IGNORE INTO `training_cert_counters` (`certctr_year`, `certctr_last_seq`, `certctr_updated_at_utc`) VALUES (0, 0, NULL)");

        // Odoo link baseline + accepted target (spec §1.4 #12). Same integration selection as the sync handler.
        // The expression for $tr_sha is frozen and must equal Directory\OdooTarget::sha().
        $tr_oi = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT odoo_integration_id, base_url, database_name FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1"));
        if ($tr_oi) {
            $tr_iid = intval($tr_oi['odoo_integration_id']);
            $tr_sha = hash('sha256', strtolower(rtrim(trim((string) $tr_oi['base_url']), '/')) . '|' . trim((string) $tr_oi['database_name']));
            mysqli_query($mysqli, "INSERT IGNORE INTO `contact_odoo_attributes`
                (`coattr_contact_id`, `coattr_odoo_integration_id`, `coattr_odoo_employee_id`, `coattr_odoo_name`, `coattr_odoo_target_sha`, `coattr_link_state`)
                SELECT l.contact_id, l.odoo_integration_id, l.odoo_employee_id, LEFT(c.contact_name, 200), '$tr_sha', 'unchecked'
                FROM contact_odoo_links l JOIN contacts c ON c.contact_id = l.contact_id
                WHERE l.odoo_integration_id = $tr_iid");
            mysqli_query($mysqli, "UPDATE `settings` SET `config_training_odoo_target_sha` = '$tr_sha' WHERE `config_training_odoo_target_sha` IS NULL");
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.92'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.92') {
        // Training / LMS Phase 3+4 - kiosk, learner evidence, achievement awards (plan A1/A2/A3/A4/A15/A19/A21).
        // Idempotent (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS). No triggers, no FKs, no module rows (2.6.91 made them).
        // 13 CREATE TABLE statements exactly as P3 spec §2.2 incl. table options, one mysqli_query each.
        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_kiosks` (
          `kiosk_id` int(11) NOT NULL AUTO_INCREMENT,
          `kiosk_asset_id` int(11) NOT NULL,
          `kiosk_asset_type` varchar(200) NOT NULL,
          `kiosk_asset_serial` varchar(200) DEFAULT NULL,
          `kiosk_personal_contact_id` int(11) DEFAULT NULL,
          `kiosk_label` varchar(100) NOT NULL,
          `kiosk_default_client_id` int(11) NOT NULL DEFAULT 0,
          `kiosk_status` enum('pending','active','revoked') NOT NULL DEFAULT 'pending',
          `kiosk_enroll_method` enum('agent_device','setup_code') DEFAULT NULL,
          `kiosk_enroll_code_hash` char(64) DEFAULT NULL,
          `kiosk_enroll_expires_at_utc` datetime(3) DEFAULT NULL,
          `kiosk_enroll_failures` tinyint(3) unsigned NOT NULL DEFAULT 0,
          `kiosk_token_hash` char(64) DEFAULT NULL,
          `kiosk_token_issued_at_utc` datetime(3) DEFAULT NULL,
          `kiosk_enrolled_at_utc` datetime(3) DEFAULT NULL,
          `kiosk_enrolled_by` int(11) DEFAULT NULL,
          `kiosk_created_by` int(11) NOT NULL,
          `kiosk_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `kiosk_last_seen_at_utc` datetime(3) DEFAULT NULL,
          `kiosk_last_user_agent` varchar(255) DEFAULT NULL,
          `kiosk_cooldown_until_utc` datetime(3) DEFAULT NULL,
          `kiosk_cooldown_reason` varchar(40) DEFAULT NULL,
          `kiosk_revoked_at_utc` datetime(3) DEFAULT NULL,
          `kiosk_revoked_by` int(11) DEFAULT NULL,
          `kiosk_revoke_reason` varchar(255) DEFAULT NULL,
          `kiosk_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`kiosk_id`),
          UNIQUE KEY `uq_training_kiosk_token` (`kiosk_token_hash`),
          UNIQUE KEY `uq_training_kiosk_code` (`kiosk_enroll_code_hash`),
          KEY `idx_training_kiosk_asset` (`kiosk_asset_id`,`kiosk_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_kiosk_sessions` (
          `ksess_id` int(11) NOT NULL AUTO_INCREMENT,
          `ksess_kiosk_id` int(11) NOT NULL,
          `ksess_contact_id` int(11) NOT NULL,
          `ksess_role` enum('learner','trainer','checkin','handoff') NOT NULL,
          `ksess_tsession_id` int(11) DEFAULT NULL,
          `ksess_token_hash` char(64) NOT NULL,
          `ksess_pin_source` enum('odoo','local') NOT NULL,
          `ksess_odoo_employee_id` int(11) DEFAULT NULL,
          `ksess_language` varchar(10) NOT NULL DEFAULT 'en',
          `ksess_idle_limit_s` smallint(5) unsigned NOT NULL,
          `ksess_started_at_utc` datetime(3) NOT NULL,
          `ksess_last_seen_at_utc` datetime(3) NOT NULL,
          `ksess_absolute_until_utc` datetime(3) NOT NULL,
          `ksess_ended_at_utc` datetime(3) DEFAULT NULL,
          `ksess_end_reason` enum('done','idle','absolute','replaced','revoked','device','contact_ineligible','pin_locked','checkin_enter','checkin_exit','handoff_enter','handoff_exit','error') DEFAULT NULL,
          `ksess_open_guard` tinyint(1) DEFAULT 1,
          `ksess_user_agent` varchar(255) DEFAULT NULL,
          PRIMARY KEY (`ksess_id`),
          UNIQUE KEY `uq_training_ksess_token` (`ksess_token_hash`),
          UNIQUE KEY `uq_training_ksess_open` (`ksess_kiosk_id`,`ksess_open_guard`),
          KEY `idx_training_ksess_contact` (`ksess_contact_id`,`ksess_started_at_utc`),
          KEY `idx_training_ksess_open_seen` (`ksess_open_guard`,`ksess_last_seen_at_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_learner_credentials` (
          `tcred_contact_id` int(11) NOT NULL,
          `tcred_source` enum('odoo','local') NOT NULL DEFAULT 'local',
          `tcred_source_pinned` tinyint(1) NOT NULL DEFAULT 0,
          `tcred_odoo_integration_id` int(11) DEFAULT NULL,
          `tcred_odoo_employee_id` int(11) DEFAULT NULL,
          `tcred_odoo_confirmed_at_utc` datetime(3) DEFAULT NULL,
          `tcred_odoo_blocked` tinyint(1) NOT NULL DEFAULT 0,
          `tcred_odoo_fp_hash` varchar(255) DEFAULT NULL,
          `tcred_odoo_fp_changed_at_utc` datetime(3) DEFAULT NULL,
          `tcred_pin_hash` varchar(255) DEFAULT NULL,
          `tcred_prev_pin_hash` varchar(255) DEFAULT NULL,
          `tcred_pepper_version` tinyint(3) unsigned NOT NULL DEFAULT 1,
          `tcred_set_method` enum('setup_code','trainer_assisted','self_change') DEFAULT NULL,
          `tcred_set_at_utc` datetime(3) DEFAULT NULL,
          `tcred_set_by_trainer_contact_id` int(11) DEFAULT NULL,
          `tcred_setup_code_hash` varchar(255) DEFAULT NULL,
          `tcred_setup_code_expires_at_utc` datetime(3) DEFAULT NULL,
          `tcred_setup_code_issued_by` int(11) DEFAULT NULL,
          `tcred_setup_code_issued_at_utc` datetime(3) DEFAULT NULL,
          `tcred_setup_token_hash` char(64) DEFAULT NULL,
          `tcred_setup_token_expires_at_utc` datetime(3) DEFAULT NULL,
          `tcred_failed_count` smallint(5) unsigned NOT NULL DEFAULT 0,
          `tcred_locked_until_utc` datetime(3) DEFAULT NULL,
          `tcred_hard_locked` tinyint(1) NOT NULL DEFAULT 0,
          `tcred_last_success_at_utc` datetime(3) DEFAULT NULL,
          `tcred_reset_notice` tinyint(1) NOT NULL DEFAULT 0,
          `tcred_reset_notice_at_utc` datetime(3) DEFAULT NULL,
          `tcred_reset_by_label` varchar(200) DEFAULT NULL,
          `tcred_created_at` datetime NOT NULL DEFAULT current_timestamp(),
          `tcred_updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
          PRIMARY KEY (`tcred_contact_id`),
          KEY `idx_training_tcred_source` (`tcred_source`,`tcred_odoo_employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_learner_prefs` (
          `tpref_contact_id` int(11) NOT NULL,
          `tpref_language` varchar(10) NOT NULL DEFAULT 'en',
          `tpref_updated_at_utc` datetime(3) NOT NULL,
          PRIMARY KEY (`tpref_contact_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_runs` (
          `trun_id` int(11) NOT NULL AUTO_INCREMENT,
          `trun_contact_id` int(11) NOT NULL,
          `trun_course_id` int(11) NOT NULL,
          `trun_revision_id` int(11) NOT NULL,
          `trun_revision_sha256` char(64) NOT NULL,
          `trun_assignment_id` int(11) DEFAULT NULL,
          `trun_language` varchar(10) NOT NULL,
          `trun_status` enum('in_progress','awaiting_signature','awaiting_session','awaiting_evaluation','completed','abandoned','superseded') NOT NULL DEFAULT 'in_progress',
          `trun_open_guard` tinyint(1) DEFAULT 1,
          `trun_channel` enum('kiosk','portal') NOT NULL DEFAULT 'kiosk',
          `trun_started_at_utc` datetime(3) NOT NULL,
          `trun_started_kiosk_id` int(11) DEFAULT NULL,
          `trun_current_lesson_uid` char(12) DEFAULT NULL,
          `trun_lesson_opened_at_utc` datetime(3) DEFAULT NULL,
          `trun_lesson_last_tick_at_utc` datetime(3) DEFAULT NULL,
          `trun_lesson_last_active` tinyint(1) NOT NULL DEFAULT 0,
          `trun_lesson_credit_s` int(10) unsigned NOT NULL DEFAULT 0,
          `trun_lesson_max_position` int(10) unsigned NOT NULL DEFAULT 0,
          `trun_lesson_pages_hex` varchar(64) DEFAULT NULL,
          `trun_lesson_rejected_ticks` smallint(5) unsigned NOT NULL DEFAULT 0,
          `trun_progress_pct` tinyint(3) unsigned NOT NULL DEFAULT 0,
          `trun_extra_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
          `trun_locked_at_utc` datetime(3) DEFAULT NULL,
          `trun_locked_lesson_uid` char(12) DEFAULT NULL,
          `trun_blocked_reason` enum('video_changed','video_unavailable') DEFAULT NULL,
          `trun_blocked_lesson_uid` char(12) DEFAULT NULL,
          `trun_passed_attempt_id` int(11) DEFAULT NULL,
          `trun_attested_at_utc` datetime(3) DEFAULT NULL,
          `trun_attest_tsig_id` int(11) DEFAULT NULL,
          `trun_attest_proof` enum('self_pin_signature','self_pin') DEFAULT NULL,
          `trun_attest_pin_source` enum('odoo','local') DEFAULT NULL,
          `trun_attest_odoo_employee_id` int(11) DEFAULT NULL,
          `trun_last_activity_at_utc` datetime(3) NOT NULL,
          `trun_ended_at_utc` datetime(3) DEFAULT NULL,
          `trun_completion_id` int(11) DEFAULT NULL,
          `trun_superseded_by_run_id` int(11) DEFAULT NULL,
          PRIMARY KEY (`trun_id`),
          UNIQUE KEY `uq_training_run_open` (`trun_contact_id`,`trun_course_id`,`trun_open_guard`),
          KEY `idx_training_run_contact` (`trun_contact_id`,`trun_status`),
          KEY `idx_training_run_status` (`trun_status`,`trun_last_activity_at_utc`),
          KEY `idx_training_run_revision` (`trun_revision_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_lesson_completions` (
          `lcomp_id` int(11) NOT NULL AUTO_INCREMENT,
          `lcomp_run_id` int(11) NOT NULL,
          `lcomp_contact_id` int(11) NOT NULL,
          `lcomp_course_id` int(11) NOT NULL,
          `lcomp_revision_id` int(11) NOT NULL,
          `lcomp_lesson_uid` char(12) NOT NULL,
          `lcomp_lesson_type` varchar(20) NOT NULL,
          `lcomp_language` varchar(10) NOT NULL,
          `lcomp_opened_at_utc` datetime(3) NOT NULL,
          `lcomp_completed_at_utc` datetime(3) NOT NULL,
          `lcomp_server_seconds` int(10) unsigned NOT NULL,
          `lcomp_required_seconds` int(10) unsigned NOT NULL,
          `lcomp_max_position` int(10) unsigned DEFAULT NULL,
          `lcomp_coverage_json` varchar(1000) DEFAULT NULL,
          `lcomp_attempt_id` int(11) DEFAULT NULL,
          `lcomp_tsig_id` int(11) DEFAULT NULL,
          `lcomp_ksess_id` int(11) DEFAULT NULL,
          `lcomp_kiosk_id` int(11) DEFAULT NULL,
          `lcomp_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
          `lcomp_row_sha256` char(64) NOT NULL,
          PRIMARY KEY (`lcomp_id`),
          UNIQUE KEY `uq_training_lcomp` (`lcomp_run_id`,`lcomp_lesson_uid`),
          KEY `idx_training_lcomp_contact` (`lcomp_contact_id`,`lcomp_course_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_attempts` (
          `tattempt_id` int(11) NOT NULL AUTO_INCREMENT,
          `tattempt_run_id` int(11) NOT NULL,
          `tattempt_contact_id` int(11) NOT NULL,
          `tattempt_course_id` int(11) NOT NULL,
          `tattempt_revision_id` int(11) NOT NULL,
          `tattempt_lesson_uid` char(12) NOT NULL,
          `tattempt_quiz_uid` char(12) NOT NULL,
          `tattempt_kind` enum('standalone','exam','check') NOT NULL,
          `tattempt_number` smallint(5) unsigned NOT NULL,
          `tattempt_language` varchar(10) NOT NULL,
          `tattempt_draw_json` text NOT NULL,
          `tattempt_pass_mark_pct` tinyint(3) unsigned NOT NULL,
          `tattempt_time_limit_s` smallint(5) unsigned DEFAULT NULL,
          `tattempt_started_at_utc` datetime(3) NOT NULL,
          `tattempt_deadline_utc` datetime(3) DEFAULT NULL,
          `tattempt_ksess_id` int(11) DEFAULT NULL,
          `tattempt_kiosk_id` int(11) DEFAULT NULL,
          `tattempt_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
          `tattempt_row_sha256` char(64) NOT NULL,
          PRIMARY KEY (`tattempt_id`),
          UNIQUE KEY `uq_training_attempt` (`tattempt_run_id`,`tattempt_lesson_uid`,`tattempt_number`),
          KEY `idx_training_attempt_contact` (`tattempt_contact_id`,`tattempt_course_id`),
          KEY `idx_training_attempt_deadline` (`tattempt_deadline_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_attempt_results` (
          `tresult_attempt_id` int(11) NOT NULL,
          `tresult_submitted_at_utc` datetime(3) NOT NULL,
          `tresult_points_earned` smallint(5) unsigned NOT NULL,
          `tresult_points_possible` smallint(5) unsigned NOT NULL,
          `tresult_score_pct` decimal(5,2) NOT NULL,
          `tresult_pass_mark_pct` tinyint(3) unsigned NOT NULL,
          `tresult_critical_missed` tinyint(3) unsigned NOT NULL,
          `tresult_passed` tinyint(1) NOT NULL,
          `tresult_timed_out` tinyint(1) NOT NULL DEFAULT 0,
          `tresult_duration_seconds` int(10) unsigned NOT NULL,
          `tresult_rapid_flag` tinyint(1) NOT NULL DEFAULT 0,
          `tresult_finalized_by` enum('learner','finalizer') NOT NULL DEFAULT 'learner',
          `tresult_answers_sha256` char(64) NOT NULL,
          `tresult_log_sha256` char(64) NOT NULL,
          `tresult_log_max_id` bigint(20) unsigned NOT NULL DEFAULT 0,
          `tresult_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
          `tresult_row_sha256` char(64) NOT NULL,
          PRIMARY KEY (`tresult_attempt_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_attempt_answers` (
          `tanswer_attempt_id` int(11) NOT NULL,
          `tanswer_question_uid` char(12) NOT NULL,
          `tanswer_position` smallint(5) unsigned NOT NULL,
          `tanswer_type` enum('single','multi','truefalse') NOT NULL,
          `tanswer_presented` varchar(255) NOT NULL,
          `tanswer_selected` varchar(255) NOT NULL DEFAULT '',
          `tanswer_is_correct` tinyint(1) NOT NULL,
          `tanswer_points_awarded` tinyint(3) unsigned NOT NULL,
          `tanswer_points_possible` tinyint(3) unsigned NOT NULL,
          `tanswer_critical` tinyint(1) NOT NULL,
          PRIMARY KEY (`tanswer_attempt_id`,`tanswer_question_uid`),
          KEY `idx_training_tanswer_question` (`tanswer_question_uid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_attempt_answer_log` (
          `talog_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `talog_attempt_id` int(11) NOT NULL,
          `talog_question_uid` char(12) NOT NULL,
          `talog_selected` varchar(255) NOT NULL DEFAULT '',
          `talog_saved_at_utc` datetime(3) NOT NULL,
          `talog_ksess_id` int(11) DEFAULT NULL,
          PRIMARY KEY (`talog_id`),
          KEY `idx_training_talog_attempt` (`talog_attempt_id`,`talog_question_uid`,`talog_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_signatures` (
          `tsig_id` int(11) NOT NULL AUTO_INCREMENT,
          `tsig_purpose` enum('learner_attest','ack','attendee','trainer','evaluator','evaluatee') NOT NULL,
          `tsig_contact_id` int(11) DEFAULT NULL,
          `tsig_signer_name` varchar(200) NOT NULL,
          `tsig_png_base64` mediumtext NOT NULL,
          `tsig_png_sha256` char(64) NOT NULL,
          `tsig_width` smallint(5) unsigned NOT NULL,
          `tsig_height` smallint(5) unsigned NOT NULL,
          `tsig_ink_px` int(10) unsigned NOT NULL,
          `tsig_statement_sha256` char(64) DEFAULT NULL,
          `tsig_kiosk_id` int(11) DEFAULT NULL,
          `tsig_ksess_id` int(11) DEFAULT NULL,
          `tsig_run_id` int(11) DEFAULT NULL,
          `tsig_tsession_id` int(11) DEFAULT NULL,
          `tsig_captured_at_utc` datetime(3) NOT NULL,
          `tsig_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
          `tsig_row_sha256` char(64) NOT NULL,
          PRIMARY KEY (`tsig_id`),
          KEY `idx_training_tsig_contact` (`tsig_contact_id`),
          KEY `idx_training_tsig_run` (`tsig_run_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_achievement_awards` (
          `taward_id` int(11) NOT NULL AUTO_INCREMENT,
          `taward_achievement_id` int(11) NOT NULL,
          `taward_achievement_uid` char(12) NOT NULL,
          `taward_contact_id` int(11) NOT NULL,
          `taward_rule_type` varchar(40) NOT NULL,
          `taward_scope_key` varchar(64) NOT NULL DEFAULT '',
          `taward_source` enum('rule','manual') NOT NULL,
          `taward_evidence_json` varchar(1000) DEFAULT NULL,
          `taward_reason` varchar(500) DEFAULT NULL,
          `taward_awarded_by_user_id` int(11) DEFAULT NULL,
          `taward_awarded_by_contact_id` int(11) DEFAULT NULL,
          `taward_snap_name` varchar(100) NOT NULL,
          `taward_snap_icon` varchar(40) NOT NULL,
          `taward_snap_color` char(7) NOT NULL,
          `taward_awarded_at_utc` datetime(3) NOT NULL,
          `taward_kiosk_id` int(11) DEFAULT NULL,
          `taward_hash_v` tinyint(3) unsigned NOT NULL DEFAULT 1,
          `taward_row_sha256` char(64) NOT NULL,
          PRIMARY KEY (`taward_id`),
          UNIQUE KEY `uq_training_taward` (`taward_achievement_id`,`taward_contact_id`,`taward_scope_key`),
          KEY `idx_training_taward_contact` (`taward_contact_id`,`taward_awarded_at_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `training_rate_buckets` (
          `trate_key` varchar(80) NOT NULL,
          `trate_window_start_utc` datetime NOT NULL,
          `trate_count` int(10) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (`trate_key`,`trate_window_start_utc`),
          KEY `idx_training_trate_window` (`trate_window_start_utc`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Kiosk settings (spec §2.3). config_training_odoo_pin_enabled defaults to 0: Odoo integration row 1 is a
        // stale staging copy, so Odoo-PIN sign-in stays off until the §9.3 step 7 owner sequence.
        foreach ([
            "`config_training_kiosk_idle_s` smallint(5) unsigned NOT NULL DEFAULT 180",
            "`config_training_trainer_idle_s` smallint(5) unsigned NOT NULL DEFAULT 300",
            "`config_training_checkin_idle_s` smallint(5) unsigned NOT NULL DEFAULT 1200",
            "`config_training_learner_max_minutes` smallint(5) unsigned NOT NULL DEFAULT 60",
            "`config_training_trainer_max_minutes` smallint(5) unsigned NOT NULL DEFAULT 240",
            "`config_training_pin_soft_failures` tinyint(3) unsigned NOT NULL DEFAULT 5",
            "`config_training_pin_lock_minutes` smallint(5) unsigned NOT NULL DEFAULT 15",
            "`config_training_pin_hard_failures` tinyint(3) unsigned NOT NULL DEFAULT 10",
            "`config_training_kiosk_fail_cap` smallint(5) unsigned NOT NULL DEFAULT 15",
            "`config_training_global_fail_cap` smallint(5) unsigned NOT NULL DEFAULT 40",
            "`config_training_kiosk_fail_cap_24h` smallint(5) unsigned NOT NULL DEFAULT 60",
            "`config_training_global_fail_cap_24h` smallint(5) unsigned NOT NULL DEFAULT 200",
            "`config_training_kiosk_distinct_cap_24h` smallint(5) unsigned NOT NULL DEFAULT 20",
            "`config_training_kiosk_search_per_min` smallint(5) unsigned NOT NULL DEFAULT 60",
            "`config_training_setup_code_days` tinyint(3) unsigned NOT NULL DEFAULT 7",
            "`config_training_odoo_pin_enabled` tinyint(1) NOT NULL DEFAULT 0",
            "`config_training_pin_pause_until_utc` datetime(3) DEFAULT NULL",
            "`config_training_enroll_pause_until_utc` datetime(3) DEFAULT NULL",
            "`config_training_odoo_breaker_errors` tinyint(3) unsigned NOT NULL DEFAULT 0",
            "`config_training_odoo_breaker_until_utc` datetime(3) DEFAULT NULL",
            "`config_training_pin_sources_synced_at_utc` datetime(3) DEFAULT NULL",
        ] as $col) {
            mysqli_query($mysqli, "ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS $col");
        }

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.93'");
    }

    if (CURRENT_DATABASE_VERSION == '2.6.93') {
        // Training kiosk devices (owner request 2026-09-25): UNLISTED devices that are not in Assets
        // (kiosk_asset_id / kiosk_asset_type NULL) and TEMPORARY devices (kiosk_expires_at_utc, UTC; past it
        // the device is revoked on its next request or by cron/training_kiosk_cron.php).
        // Idempotent: MODIFY re-applies the same definition; ADD COLUMN / ADD INDEX IF NOT EXISTS.
        mysqli_query($mysqli, "ALTER TABLE `training_kiosks` MODIFY `kiosk_asset_id` int(11) DEFAULT NULL, MODIFY `kiosk_asset_type` varchar(200) DEFAULT NULL");
        mysqli_query($mysqli, "ALTER TABLE `training_kiosks` ADD COLUMN IF NOT EXISTS `kiosk_expires_at_utc` datetime(3) DEFAULT NULL AFTER `kiosk_enrolled_by`");
        mysqli_query($mysqli, "ALTER TABLE `training_kiosks` ADD INDEX IF NOT EXISTS `idx_training_kiosk_expires` (`kiosk_status`,`kiosk_expires_at_utc`)");

        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.94'");
    }
