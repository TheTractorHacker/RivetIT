<?php

// Query Settings
$sql_settings = mysqli_query($mysqli, "SELECT * FROM settings WHERE company_id = 1");
$row = mysqli_fetch_assoc($sql_settings);

// Database version
DEFINE("CURRENT_DATABASE_VERSION", $row['config_current_database_version']);

// Microsoft OAuth (SSO / Identity Provider)
$config_azure_client_id = $row['config_azure_client_id'];
$config_azure_client_secret = decryptSetting($row['config_azure_client_secret'] ?? '');

// Outlook Calendar Sync
$config_outlook_cal_client_id     = $row['config_outlook_cal_client_id'] ?? '';
$config_outlook_cal_client_secret = decryptSetting($row['config_outlook_cal_client_secret'] ?? '');
$config_outlook_cal_tenant_id     = $row['config_outlook_cal_tenant_id'] ?? '';

// Mail - SMTP
$config_smtp_provider = $row['config_smtp_provider'];
$config_smtp_host = $row['config_smtp_host'];
$config_smtp_port = intval($row['config_smtp_port']);
$config_smtp_encryption = $row['config_smtp_encryption'];
$config_smtp_username = $row['config_smtp_username'];
$config_smtp_password = decryptSetting($row['config_smtp_password'] ?? '');
$config_mail_from_email = $row['config_mail_from_email'];
$config_mail_from_name = $row['config_mail_from_name'];

// Mail - IMAP
$config_imap_provider = $row['config_imap_provider'];
$config_imap_host = $row['config_imap_host'];
$config_imap_port = intval($row['config_imap_port']);
$config_imap_encryption = $row['config_imap_encryption'];
$config_imap_username = $row['config_imap_username'];
$config_imap_password = decryptSetting($row['config_imap_password'] ?? '');

// Mail OAUTH2
$config_mail_oauth_client_id = $row['config_mail_oauth_client_id'];
$config_mail_oauth_client_secret = decryptSetting($row['config_mail_oauth_client_secret'] ?? '');
$config_mail_oauth_tenant_id = $row['config_mail_oauth_tenant_id'];
$config_mail_oauth_refresh_token = decryptSetting($row['config_mail_oauth_refresh_token'] ?? '');
$config_mail_oauth_access_token = decryptSetting($row['config_mail_oauth_access_token'] ?? '');
$config_mail_oauth_access_token_expires_at = $row['config_mail_oauth_access_token_expires_at'];

// Defaults
$config_start_page = $row['config_start_page'] ?? 'clients.php';
$config_default_transfer_from_account = intval($row['config_default_transfer_from_account']);
$config_default_transfer_to_account = intval($row['config_default_transfer_to_account']);
$config_default_payment_account = intval($row['config_default_payment_account']);
$config_default_expense_account = intval($row['config_default_expense_account']);
$config_default_payment_method = $row['config_default_payment_method'];
$config_default_expense_payment_method = $row['config_default_expense_payment_method'];
$config_default_calendar = intval($row['config_default_calendar']);
$config_default_net_terms = intval($row['config_default_net_terms']);
$config_default_hourly_rate = floatval($row['config_default_hourly_rate']);

// Invoice
$config_invoice_prefix = $row['config_invoice_prefix'];
$config_invoice_next_number = intval($row['config_invoice_next_number']);
$config_invoice_footer = $row['config_invoice_footer'];
$config_invoice_from_name = $row['config_invoice_from_name'];
$config_invoice_from_email = $row['config_invoice_from_email'];
$config_invoice_late_fee_enable = intval($row['config_invoice_late_fee_enable']);
$config_invoice_late_fee_percent = floatval($row['config_invoice_late_fee_percent']);
$config_invoice_paid_notification_email = $row['config_invoice_paid_notification_email'];
$config_invoice_show_tax_id = intval($row['config_invoice_show_tax_id']);

// Recurring Invoices
$config_recurring_invoice_prefix = $row['config_recurring_invoice_prefix'];
$config_recurring_invoice_next_number = intval($row['config_recurring_invoice_next_number']);

// Quotes
$config_quote_prefix = $row['config_quote_prefix'];
$config_quote_next_number = intval($row['config_quote_next_number']);
$config_quote_footer = $row['config_quote_footer'];
$config_quote_from_name = $row['config_quote_from_name'];
$config_quote_from_email = $row['config_quote_from_email'];
$config_quote_notification_email = $row['config_quote_notification_email'];

// Projects
$config_project_prefix = $row['config_project_prefix'];
$config_project_next_number = intval($row['config_project_next_number']);

// Tickets
$config_ticket_prefix = $row['config_ticket_prefix'];
$config_ticket_next_number = intval($row['config_ticket_next_number']);
$config_ticket_from_name = $row['config_ticket_from_name'];
$config_ticket_from_email = $row['config_ticket_from_email'];
$config_ticket_email_parse = intval($row['config_ticket_email_parse']);
$config_ticket_email_parse_unknown_senders = intval($row['config_ticket_email_parse_unknown_senders']);
$config_ticket_client_general_notifications = intval($row['config_ticket_client_general_notifications']);
$config_ticket_autoclose_hours = intval($row['config_ticket_autoclose_hours']);
$config_ticket_csat_enable = intval($row['config_ticket_csat_enable'] ?? 1);
$config_ticket_csat_reminder_days = intval($row['config_ticket_csat_reminder_days'] ?? 3);
$config_ticket_csat_low_rating_threshold = intval($row['config_ticket_csat_low_rating_threshold'] ?? 2);
$config_ticket_csat_google_review_url = $row['config_ticket_csat_google_review_url'] ?? '';
$config_ticket_new_ticket_notification_email = $row['config_ticket_new_ticket_notification_email'];
$config_ticket_default_billable = intval($row['config_ticket_default_billable']);
$config_ticket_default_view = intval($row['config_ticket_default_view']);
$config_ticket_moving_columns = intval($row['config_ticket_moving_columns']);
$config_ticket_ordering = intval($row['config_ticket_ordering']);
$config_ticket_timer_autostart = intval($row['config_ticket_timer_autostart']);
$config_ticket_default_technician_id = intval($row['config_ticket_default_technician_id'] ?? 0);
// Category/status a UI-created ticket lands on when left blank - see
// resolveTicketCategory()/resolveTicketCreationStatus() (functions.php),
// which fall back further (by-name "Remote"/"New"/"Assigned" lookup) when
// these are 0 (unset).
$config_ticket_default_category_id = intval($row['config_ticket_default_category_id'] ?? 0);
$config_ticket_default_status_id = intval($row['config_ticket_default_status_id'] ?? 0);
// Avg Resolution Time (dashboard tile): whether project-linked tickets count
// toward the average, and whether the tile shows at all.
$config_avg_resolution_exclude_projects = intval($row['config_avg_resolution_exclude_projects'] ?? 1);
$config_dashboard_avg_resolution_enable = intval($row['config_dashboard_avg_resolution_enable'] ?? 1);

// Cron
$config_enable_cron = intval($row['config_enable_cron']);

// Alerts & Notifications
$config_recurring_auto_send_invoice = intval($row['config_recurring_auto_send_invoice']);
$config_enable_alert_domain_expire = intval($row['config_enable_alert_domain_expire']);
$config_send_invoice_reminders = intval($row['config_send_invoice_reminders']);
$config_invoice_overdue_reminders = intval($row['config_invoice_overdue_reminders']);

// Modules
$config_module_enable_itdoc = intval($row['config_module_enable_itdoc']);
$config_module_enable_ticketing = intval($row['config_module_enable_ticketing']);
$config_module_enable_accounting = intval($row['config_module_enable_accounting']);
$config_module_enable_ticket_charges = intval($row['config_module_enable_ticket_charges'] ?? 1);
$config_module_enable_kb = intval($row['config_module_enable_kb'] ?? 0);
$config_module_enable_live_chat = intval($row['config_module_enable_live_chat'] ?? 0);
$config_module_enable_payroll = intval($row['config_module_enable_payroll'] ?? 0);
$config_module_enable_crm = intval($row['config_module_enable_crm'] ?? 0);
$config_payroll_overtime_threshold_hours = floatval($row['config_payroll_overtime_threshold_hours'] ?? 40.00);
$config_payroll_overtime_multiplier = floatval($row['config_payroll_overtime_multiplier'] ?? 1.50);
$config_payroll_default_pay_frequency = (string) ($row['config_payroll_default_pay_frequency'] ?? 'biweekly');
$config_client_portal_enable = intval($row['config_client_portal_enable']);

// RMM Integration (Syncro-Beta)
$config_module_enable_rmm = intval($row['config_module_enable_rmm'] ?? 0);
$config_rmm_default_integration_id = intval($row['config_rmm_default_integration_id'] ?? 0);
$config_rmm_auto_ticket_severities = sanitizeInput($row['config_rmm_auto_ticket_severities'] ?? '');
$config_rmm_prefer_tactical = intval($row['config_rmm_prefer_tactical'] ?? 1);

// Device metrics ("Metrics", never telemetry - config_telemetry below is the
// unrelated anonymous phone-home flag). Defaults mirror the 2.6.75 migration's
// column defaults so an install that has not run the migration yet reads as
// "off" rather than as a PHP notice.
$config_enable_device_metrics = intval($row['config_enable_device_metrics'] ?? 0);
$config_metrics_collect_interval_seconds = intval($row['config_metrics_collect_interval_seconds'] ?? 300);
$config_metrics_raw_retention_days = intval($row['config_metrics_raw_retention_days'] ?? 14);
$config_metrics_hour_retention_days = intval($row['config_metrics_hour_retention_days'] ?? 90);

// UniFi Integration (Syncro-Beta)
$config_module_enable_unifi = intval($row['config_module_enable_unifi'] ?? 0);
$config_unifi_default_integration_id = intval($row['config_unifi_default_integration_id'] ?? 0);

// Intune Devices nav/page - independent of microsoft_integrations' own
// per-connection enabled/intune_sync_enabled flags (Settings > Integrations > Directory Sync)
$config_module_enable_intune = intval($row['config_module_enable_intune'] ?? 0);

// Training (LMS). ?? defaults mirror the 2.6.91 column defaults, so before the migration the app reads "off".
// $config_training_schema_ready lets admin/post/settings_module.php skip the column until 2.6.91 has run.
$config_module_enable_training = intval($row['config_module_enable_training'] ?? 0);
$config_training_schema_ready  = is_array($row) && array_key_exists('config_module_enable_training', $row);

// Login
$config_login_message = $row['config_login_message'];
$config_login_key_required = $row['config_login_key_required'];
$config_login_key_secret = $row['config_login_key_secret'];
$config_login_remember_me_expire = max(30, intval($row['config_login_remember_me_expire']));
$config_login_session_lifetime = max(43200, intval($row['config_login_session_lifetime'] ?? 43200));
$config_log_retention = intval($row['config_log_retention']);
$config_backup_auto_enabled  = intval($row['config_backup_auto_enabled'] ?? 0);
$config_backup_frequency     = $row['config_backup_frequency'] ?? 'daily';
$config_backup_retain_count  = max(1, intval($row['config_backup_retain_count'] ?? 7));
// Backup > Remote Storage (S3-compatible - AWS S3 or a self-hosted service
// like RustFS/MinIO). Secret key is encrypted at rest, same as Comet's below.
$config_backup_s3_enabled     = intval($row['config_backup_s3_enabled'] ?? 0);
$config_backup_s3_endpoint    = $row['config_backup_s3_endpoint'] ?? '';
$config_backup_s3_region      = $row['config_backup_s3_region'] ?? 'us-east-1';
$config_backup_s3_bucket      = $row['config_backup_s3_bucket'] ?? '';
$config_backup_s3_access_key  = $row['config_backup_s3_access_key'] ?? '';
$config_backup_s3_secret_key  = decryptSetting($row['config_backup_s3_secret_key'] ?? '');
$config_backup_s3_path_style  = intval($row['config_backup_s3_path_style'] ?? 1);
$config_backup_s3_prefix      = $row['config_backup_s3_prefix'] ?? '';
$config_backup_passphrase     = decryptSetting($row['config_backup_passphrase'] ?? '');
// Comet Backup
$config_comet_enabled      = intval($row['config_comet_enabled'] ?? 0);
$config_comet_server_url   = $row['config_comet_server_url'] ?? 'http://10.1.0.35:8060';
$config_comet_admin_user   = $row['config_comet_admin_user'] ?? '';
$config_comet_admin_pass   = decryptSetting($row['config_comet_admin_pass'] ?? '');
$config_comet_auto_ticket    = intval($row['config_comet_auto_ticket'] ?? 0);
$config_comet_totp_secret    = decryptSetting($row['config_comet_totp_secret'] ?? '');
$config_comet_webhook_secret = decryptSetting($row['config_comet_webhook_secret'] ?? '');
// Locale
$config_currency_format = "US_en";
$config_timezone = $row['config_timezone'];
$config_date_format = "M d, Y";
$config_time_format = "g:i A";
// Phone Numbers (Admin > Localization): default calling code prefilled into
// new records' phone_country_code boxes, and whether WhatsApp click-to-chat
// links are offered next to mobile numbers app-wide.
$config_phone_default_country_code = $row['config_phone_default_country_code'] ?? '1';
$config_whatsapp_enabled = intval($row['config_whatsapp_enabled'] ?? 0);

// Theme
$config_theme = $row['config_theme'];
// Appearance customizer (per-company): optional custom accent hex + card radius override + default dark mode
$config_theme_accent_custom = $row['config_theme_accent_custom'] ?? null;   // #RRGGBB, overrides the preset accent when set
$config_theme_card_radius = $row['config_theme_card_radius'] ?? null;       // e.g. "14px", overrides --card-radius when set
$config_theme_dark_default = intval($row['config_theme_dark_default'] ?? 0); // company-wide default dark mode (per-user pref can opt into dark)

// Telemetry
$config_telemetry = intval($row['config_telemetry']);

// AI
$config_ai_enable = intval($row['config_ai_enable'] ?? 1);
$config_ai_max_input_chars = intval($row['config_ai_max_input_chars'] ?? 12000);
$config_ai_timeout_seconds = intval($row['config_ai_timeout_seconds'] ?? 25);

// Destructive Deletes
$config_destructive_deletes_enable = intval($row['config_destructive_deletes_enable']);


// Select Arrays
$colors_array = array (
    'lightblue',
    'blue',
    'green',
    'cyan',
    'yellow',
    'red',
    'black',
    'gray-dark',
    'gray',
    'light',
    'indigo',
    'navy',
    'purple',
    'fuchsia',
    'pink',
    'maroon',
    'orange',
    'lime',
    'teal',
    'olive'
);

$records_per_page_array = array ('5','10','15','20','30','50','100');

include_once "settings_localization_array.php";

$asset_types_array = array (
    'Laptop'=>'fa-laptop',
    'Desktop'=>'fa-desktop',
    'Server'=>'fa-server',
    'Phone'=>'fa-phone',
    'Mobile Phone'=>'fa-mobile-alt',
    'Tablet'=>'fa-tablet-alt',
    'Firewall/Router'=>'fa-fire-alt',
    'Switch'=>'fa-network-wired',
    'Access Point'=>'fa-wifi',
    'Printer'=>'fa-print',
    'Display'=>'fa-tv',
    'Camera'=>'fa-video',
    'Virtual Machine'=>'fa-cloud',
    'Other'=>'fa-tag'
);
