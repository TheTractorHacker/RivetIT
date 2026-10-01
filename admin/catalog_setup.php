<?php
require_once "includes/inc_all_admin.php";
require_once "includes/admin_directory.php";

renderAdminDirectory('Tags & categories', 'Manage shared labels, links, and setup data.', 'fa-sliders-h', [
    'labels' => [
        'title' => 'Labels', 'icon' => 'fa-tags',
        'description' => 'Organize records with consistent names.',
        'items' => [
            ['Tags', 'Reusable labels for records.', 'tag.php', 'fa-tags'],
            ['Categories', 'Group records by type.', 'category.php', 'fa-list-ul'],
        ],
    ],
    'connections' => [
        'title' => 'Links & AI', 'icon' => 'fa-link',
        'description' => 'Manage navigation shortcuts and AI providers.',
        'items' => [
            ['Custom links', 'Add links to app menus.', 'custom_link.php', 'fa-external-link-alt'],
            ['AI providers', 'Connect providers and manage models.', 'ai_provider.php', 'fa-robot'],
        ],
    ],
    'people' => [
        'title' => 'People & workflows', 'icon' => 'fa-users',
        'description' => 'Import people and standardize employee tasks.',
        'items' => [
            ['People import', 'Bring people into RivetIT.', 'people_import.php', 'fa-file-import'],
            ['Employee workflow templates', 'Reuse onboarding and employee tasks.', 'employee_workflow_templates.php', 'fa-tasks'],
        ],
    ],
]);

require_once "../includes/footer.php";
