<?php
require_once "includes/inc_all_admin.php";
require_once "includes/admin_directory.php";

renderAdminDirectory('Templates', 'Create and manage reusable content.', 'fa-copy', [
    'service' => [
        'title' => 'Service desk', 'icon' => 'fa-life-ring',
        'description' => 'Standardize tickets, responses, and service requests.',
        'items' => [
            ['Ticket templates', 'Start tickets with a consistent structure.', 'ticket_template.php', 'fa-life-ring', true, ['url' => '/admin/modals/ticket_template/ticket_template_add.php', 'size' => 'lg']],
            ['Service catalog', 'Manage available request types.', 'service_catalog.php', 'fa-th-large'],
            ['Canned responses', 'Reuse common ticket replies.', 'canned_responses.php', 'fa-comment-dots', true, ['url' => '/admin/modals/canned_response/canned_response_add.php', 'size' => 'lg']],
            ['Worksheet templates', 'Reuse task checklists.', 'worksheet_template.php', 'fa-clipboard-list', true, ['url' => '/admin/modals/worksheet_template/worksheet_template_add.php', 'size' => 'lg']],
        ],
    ],
    'documents' => [
        'title' => 'Documents & projects', 'icon' => 'fa-file-alt',
        'description' => 'Reuse agreements, project plans, and documents.',
        'items' => [
            ['Contract templates', 'Build contracts from a standard format.', 'contract_template.php', 'fa-file-contract', true, ['url' => '/admin/modals/contract_template/contract_template_add.php', 'size' => 'lg']],
            ['Project templates', 'Start projects with predefined work.', 'project_template.php', 'fa-project-diagram', true, ['url' => '/admin/modals/project_template/project_template_add.php']],
            ['Document templates', 'Create standard documents.', 'document_template.php', 'fa-file-alt', true, ['url' => '/admin/modals/document_template/document_template_add.php', 'size' => 'xl']],
        ],
    ],
    'people-assets' => [
        'title' => 'People & assets', 'icon' => 'fa-users',
        'description' => 'Prepare repeatable records for people and assets.',
        'items' => [
            ['Onboarding templates', 'Reuse new-person workflows.', 'onboarding_templates.php', 'fa-user-plus', true, ['url' => '/admin/modals/onboarding_template/onboarding_template_add.php']],
            ['Vendor templates', 'Standardize vendor records.', 'vendor_template.php', 'fa-building', true, ['url' => '/admin/modals/vendor_template/vendor_template_add.php']],
            ['License templates', 'Standardize software license records.', 'software_template.php', 'fa-rocket', true, ['url' => '/admin/modals/software_template/software_template_add.php']],
        ],
    ],
]);

require_once "../includes/footer.php";
