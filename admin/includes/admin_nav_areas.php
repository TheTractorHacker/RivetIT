<?php

/** Admin directories and the detail pages each one owns. */
function itflowAdminNavAreas(): array
{
    return [
        'catalog_setup' => [
            'title' => 'Tags & categories', 'icon' => 'fa-sliders-h',
            'pages' => ['catalog_setup.php', 'tag.php', 'category.php', 'custom_link.php', 'ai_provider.php', 'ai_model.php', 'people_import.php', 'employee_workflow_templates.php', 'employee_workflow_template_details.php'],
        ],
        'ticketing_setup' => [
            'title' => 'Ticketing', 'icon' => 'fa-life-ring',
            'pages' => ['ticketing_setup.php', 'ticket_status.php', 'labor_type.php', 'ticket_automation.php', 'mailbox.php', 'mail_requests.php', 'sla_calendars.php', 'sla_policies.php', 'holidays.php'],
        ],
        'template_library' => [
            'title' => 'Templates', 'icon' => 'fa-copy',
            'pages' => ['template_library.php', 'contract_template.php', 'contract_template_details.php', 'project_template.php', 'project_template_details.php', 'onboarding_templates.php', 'onboarding_template_details.php', 'ticket_template.php', 'ticket_template_details.php', 'service_catalog.php', 'canned_responses.php', 'worksheet_template.php', 'worksheet_template_details.php', 'vendor_template.php', 'software_template.php', 'document_template.php', 'document_template_details.php'],
        ],
        'maintenance' => [
            'title' => 'Maintenance', 'icon' => 'fa-tools',
            'pages' => ['maintenance.php', 'cron.php', 'mail_queue.php', 'email_log.php', 'audit_log.php', 'app_log.php', 'backup.php', 'server_tasks.php', 'settings_redis.php', 'debug.php', 'update.php', 'credential_restore.php'],
        ],
    ];
}
