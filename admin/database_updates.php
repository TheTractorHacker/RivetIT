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
if (LATEST_DATABASE_VERSION > CURRENT_DATABASE_VERSION) {

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

