<?php

/*
 * Static index of admin Settings pages/sections, used by the global search
 * (agent/ajax.php's global_search_live + agent/global_search.php) so settings
 * are findable the same way departments/tickets/etc. are - Settings pages
 * aren't database rows, so there's nothing to LIKE-match in SQL; this is a
 * plain PHP array instead, filtered in-process against the search query.
 *
 * Each entry's "visible" key mirrors the same $config_module_enable_* flags
 * admin/includes/side_nav.php already uses to hide a settings page/section
 * when the feature it configures is turned off - a disabled module's
 * settings shouldn't be surfaced as a search result either.
 *
 * An entry for a page with in-page sections may carry 'sections' (anchor,
 * label, keywords): their words match the entry too, and a query that hits
 * a section's label or words opens the page at '#anchor', titled
 * "Page › Section". Still one result per page.
 */

function getSettingsSearchIndex(): array {
    global $config_module_enable_accounting, $config_module_enable_rmm, $config_module_enable_unifi,
           $config_module_enable_intune, $config_client_portal_enable;

    return [
        ['label' => 'Company Details',        'keywords' => ['company', 'address', 'logo', 'business info'],                          'url' => '/admin/settings_company.php',            'visible' => true],
        ['label' => 'Localization',            'keywords' => ['locale', 'timezone', 'currency', 'language', 'date format'],            'url' => '/admin/settings_localization.php',       'visible' => true],
        ['label' => 'Theme',                   'keywords' => ['theme', 'dark mode', 'color', 'accent', 'favicon'],                     'url' => '/admin/settings_theme.php',              'visible' => true],
        ['label' => 'Knowledge Base Settings', 'keywords' => ['knowledge base', 'kb', 'articles', 'documentation'],                  'url' => '/admin/settings_kb.php',                 'visible' => true],
        ['label' => 'Appearance',              'keywords' => ['appearance', 'logo', 'branding'],                                       'url' => '/admin/settings_appearance.php',         'visible' => true],
        ['label' => 'Security',                'keywords' => ['security', 'vault', 'encryption', 'login key', 'passkey'],              'url' => '/admin/settings_security.php',           'visible' => true],
        ['label' => 'Mail',                    'keywords' => ['smtp', 'imap', 'email', 'oauth', 'mail'],                               'url' => '/admin/settings_mail.php',               'visible' => true],
        ['label' => 'Notifications',           'keywords' => ['notification', 'alert'],                                                'url' => '/admin/settings_notification.php',       'visible' => true],
        ['label' => 'Defaults',                'keywords' => ['default', 'default technician'],                                        'url' => '/admin/settings_default.php',            'visible' => true],
        ['label' => 'Invoice Settings',        'keywords' => ['invoice', 'tax id', 'late fee', 'recurring invoice'],                    'url' => '/admin/settings_invoice.php',            'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Quote Settings',          'keywords' => ['quote', 'estimate'],                                                    'url' => '/admin/settings_quote.php',              'visible' => (bool) $config_module_enable_accounting],
        ['label' => 'Project Settings',        'keywords' => ['project'],                                                              'url' => '/admin/settings_project.php',            'visible' => true],
        ['label' => 'Ticket Settings',         'keywords' => ['ticket', 'csat', 'customer satisfaction', 'rating', 'survey'],           'url' => '/admin/settings_ticket.php',             'visible' => true],
        ['label' => 'Outlook Calendar Sync',   'keywords' => ['outlook', 'calendar', 'azure', 'sync', 'appointment'],                   'url' => '/admin/settings_calendar_sync.php',      'visible' => true],
        ['label' => 'Telemetry',               'keywords' => ['telemetry', 'analytics', 'usage data'],                                 'url' => '/admin/settings_telemetry.php',          'visible' => true],
        ['label' => 'Modules',                 'keywords' => ['module', 'documentation', 'knowledge base', 'live chat', 'department portal', 'enable'], 'url' => '/admin/settings_module.php', 'visible' => true],
        // One page, five sections: a query that matches a section's words opens that section.
        ['label' => 'Training',                'keywords' => ['training', 'lms', 'training settings'], 'url' => '/admin/settings_training.php', 'visible' => true,
         'sections' => [
            ['anchor' => 'general',    'label' => 'General & media',          'keywords' => ['course', 'quiz', 'safety', 'language', 'spanish', 'pass mark', 'attempts', 'attestation', 'media', 'upload', 'video', 'pdf', 'youtube', 'api key', 'storage', 'budget', 'purge', 'backup size']],
            ['anchor' => 'compliance', 'label' => 'Compliance & assignments', 'keywords' => ['compliance', 'assignment', 'due soon', 'reissue', 'reopen', 'target', 'evidence', 'hire date', 'certificate', 'recalculate', 'reconcile', 'snapshot']],
            ['anchor' => 'odoo',       'label' => 'Employee links (Odoo)',    'keywords' => ['odoo', 'employee link', 'links', 'relink', 'unlink', 'accept target', 'directory sync', 'nightly sync', 'sync']],
            ['anchor' => 'kiosk',      'label' => 'Kiosk & sign-in',          'keywords' => ['kiosk', 'ipad', 'pin', 'odoo pin', 'lockout', 'idle', 'sign-in', 'sign in', 'setup slip', 'setup code']],
            ['anchor' => 'ledger',     'label' => 'Records ledger',           'keywords' => ['ledger', 'integrity', 'verify', 'tamper', 'hash']],
         ]],
        ['label' => 'Webhooks',                'keywords' => ['webhook', 'api', 'delivery log'],                                       'url' => '/admin/settings_webhooks.php',           'visible' => true],
        ['label' => 'RMM Integration',         'keywords' => ['rmm', 'remote monitoring', 'tactical', 'level.io', 'sophos', 'action1', 'connectwise'], 'url' => '/admin/settings_integrations.php?tab=rmm', 'visible' => true],
        ['label' => 'Backups Integration',     'keywords' => ['backup', 'comet'],                                                      'url' => '/admin/settings_integrations.php?tab=backups', 'visible' => true],
        ['label' => 'Firewalls Integration',   'keywords' => ['firewall', 'sophos central'],                                           'url' => '/admin/settings_integrations.php?tab=firewalls', 'visible' => true],
        ['label' => 'UniFi Integration',       'keywords' => ['unifi', 'wifi', 'network', 'ubiquiti'],                                  'url' => '/admin/settings_integrations.php?tab=unifi', 'visible' => true],
        ['label' => 'Intune Devices Module',   'keywords' => ['intune', 'devices', 'microsoft', 'entra', 'azure ad', 'mdm'],            'url' => '/admin/settings_integrations.php?tab=directorysync', 'visible' => true],
        ['label' => 'Microsoft 365 / Entra ID','keywords' => ['microsoft 365', 'entra', 'azure ad', 'tenant', 'graph api'],             'url' => '/admin/settings_integrations.php?tab=directorysync', 'visible' => true],
        ['label' => 'Odoo Integration',        'keywords' => ['odoo', 'erp'],                                                          'url' => '/admin/settings_integrations.php?tab=directorysync', 'visible' => true],
        ['label' => 'Custom Fields',           'keywords' => ['custom field'],                                                         'url' => '/admin/settings_custom_fields.php',      'visible' => true],
        ['label' => 'API Keys',                'keywords' => ['api key', 'api access'],                                                'url' => '/admin/api_keys.php',                    'visible' => true],
        ['label' => 'API Documentation',       'keywords' => ['api docs', 'api reference', 'openapi'],                                 'url' => '/admin/api_docs.php',                    'visible' => true],
        ['label' => 'Users',                   'keywords' => ['user', 'technician', 'agent', 'staff'],                                 'url' => '/admin/users.php',                       'visible' => true],
        ['label' => 'Roles',                   'keywords' => ['role', 'permission'],                                                   'url' => '/admin/roles.php',                       'visible' => true],
        ['label' => 'Department Portal Preview', 'keywords' => ['portal preview', 'view portal', 'department portal', 'impersonate', 'log in as', 'client portal'], 'url' => '/admin/portal_preview.php', 'visible' => (bool) $config_client_portal_enable],
        ['label' => 'Identity Provider (SSO)', 'keywords' => ['sso', 'saml', 'identity provider', 'single sign-on'],                    'url' => '/admin/identity_provider.php',           'visible' => (bool) $config_client_portal_enable],
    ];
}

/*
 * Returns up to $limit matching settings entries (label or keyword contains
 * $query, case-insensitive), shaped the same as every other search group's
 * rows: title/subtitle/url. Admin-only - settings aren't relevant/visible to
 * a non-admin technician, mirroring $session_is_admin checks elsewhere.
 */
function searchSettingsIndex(string $query, int $limit = 5): array {
    global $session_is_admin;

    if (empty($session_is_admin) || $query === '') {
        return [];
    }

    $needle = mb_strtolower($query);
    $matches = [];

    foreach (getSettingsSearchIndex() as $entry) {
        if (!$entry['visible']) {
            continue;
        }
        $title = $entry['label'];
        $url = $entry['url'];
        $haystack = mb_strtolower($entry['label'] . ' ' . implode(' ', $entry['keywords']));
        $section_hit = null;
        foreach ($entry['sections'] ?? [] as $section) {
            $section_text = mb_strtolower($section['label'] . ' ' . implode(' ', $section['keywords']));
            $haystack .= ' ' . $section_text;
            if ($section_hit === null && mb_strpos($section_text, $needle) !== false) {
                $section_hit = $section;
            }
        }
        if (mb_strpos($haystack, $needle) === false) {
            continue;
        }
        if ($section_hit !== null) {
            $title .= ' › ' . $section_hit['label'];
            $url .= '#' . $section_hit['anchor'];
        }
        $matches[] = [
            'title' => $title,
            'subtitle' => 'Settings',
            'url' => $url,
        ];
        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}
