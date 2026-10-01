<?php
require_once "includes/inc_all_admin.php";

// Keep the settings directory in one place instead of expanding every destination
// in the global sidebar. Feature switches mirror the links they replace.
$settings_groups = [
    'general' => [
        'title' => 'General', 'icon' => 'fa-sliders-h',
        'description' => 'Set up the organization, appearance, and everyday defaults.',
        'items' => [
            ['Company details', 'Business identity, address, and contact details.', 'settings_company.php', 'fa-briefcase'],
            ['Language & region', 'Time zone, currency, and date formats.', 'settings_localization.php', 'fa-globe'],
            ['Theme', 'Colors, light and dark mode, and favicon.', 'settings_theme.php', 'fa-paint-brush'],
            ['Appearance', 'Branding and display options.', 'settings_appearance.php', 'fa-palette'],
            ['Defaults', 'Starting page and default accounts.', 'settings_default.php', 'fa-cogs'],
            ['Modules', 'Turn app features on or off.', 'settings_module.php', 'fa-cube'],
        ],
    ],
    'workflows' => [
        'title' => 'Workflows', 'icon' => 'fa-stream',
        'description' => 'Choose how work, billing, and learning behave.',
        'items' => [
            ['Ticketing', 'Ticket defaults and customer satisfaction.', 'settings_ticket.php', 'fa-life-ring', (bool) $config_module_enable_ticketing],
            ['Projects', 'Project defaults and options.', 'settings_project.php', 'fa-project-diagram', (bool) $config_module_enable_ticketing],
            ['Invoices', 'Invoice numbering and payment terms.', 'settings_invoice.php', 'fa-file-invoice', (bool) $config_module_enable_accounting],
            ['Quotes', 'Quote defaults and numbering.', 'settings_quote.php', 'fa-comment-dollar', (bool) $config_module_enable_accounting],
            ['Payroll', 'Pay periods, deductions, and payroll options.', 'payroll_settings.php', 'fa-money-check', (bool) $config_module_enable_payroll],
            ['Training', 'Courses, compliance, kiosks, and automation.', 'settings_training.php', 'fa-hard-hat'],
            ['Knowledge Base', 'Article access and Knowledge Base status.', 'settings_kb.php', 'fa-book', lookupUserPermission('module_kb') >= 1],
            ['Custom fields', 'Extra fields for your records.', 'settings_custom_fields.php', 'fa-list-alt'],
        ],
    ],
    'access' => [
        'title' => 'Access & communication', 'icon' => 'fa-shield-alt',
        'description' => 'Control sign-in, email, and alerts.',
        'items' => [
            ['Security', 'Sign-in and security options.', 'settings_security.php', 'fa-shield-alt'],
            ['Mail', 'Sending and receiving email.', 'settings_mail.php', 'fa-envelope'],
            ['Notifications', 'Choose which events send alerts.', 'settings_notification.php', 'fa-bell'],
            ['Identity provider', 'Set up portal single sign-on.', 'identity_provider.php', 'fa-fingerprint', (bool) $config_client_portal_enable],
            ['Portal preview', 'See the department portal as a user.', 'portal_preview.php', 'fa-eye', (bool) $config_client_portal_enable],
        ],
    ],
    'connections' => [
        'title' => 'Connections & data', 'icon' => 'fa-plug',
        'description' => 'Connect external services and manage data sharing.',
        'items' => [
            ['Integrations', 'RMM, backups, UniFi, and directory sync.', 'settings_integrations.php', 'fa-plug'],
            ['Calendar sync', 'Connect Outlook calendars.', 'settings_calendar_sync.php', 'fa-calendar-alt'],
            ['Webhooks', 'Send events to other systems.', 'settings_webhooks.php', 'fa-satellite-dish'],
            ['AI', 'Configure AI features.', 'settings_ai.php', 'fa-robot'],
            ['Telemetry', 'Manage usage data sharing.', 'settings_telemetry.php', 'fa-chart-line'],
        ],
    ],
];
?>
<style nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
    .settings-directory [id] { scroll-margin-top: 5rem; }
    .settings-directory__head { margin-bottom: 1rem; }
    .settings-directory__head h1 { margin: 0; }
    .settings-directory__head p { margin: .2rem 0 0; color: var(--if-muted, #5d6f76); }
    .settings-directory__nav { position: sticky; top: 0; z-index: 20; margin-bottom: 1.25rem; padding: .5rem 0; background: var(--if-bg, #eef2f2); }
    .settings-directory__nav ul { display: flex; gap: .25rem; overflow-x: auto; margin: 0; padding: .3rem; list-style: none; background: var(--if-surface, #fff); border: 1px solid var(--if-border, #e3e9ea); border-radius: var(--if-radius, 12px); }
    .settings-directory__nav li { flex: 0 0 auto; }
    .settings-directory__nav a { display: flex; align-items: center; gap: .45rem; padding: .45rem .8rem; border-radius: 8px; color: var(--if-muted, #5d6f76); font-weight: 500; white-space: nowrap; text-decoration: none; }
    .settings-directory__nav a:hover, .settings-directory__nav a:focus-visible { color: var(--if-primary, #0d9488); background: rgba(var(--if-primary-rgb, 13, 148, 136), .1); }
    .settings-directory__section + .settings-directory__section { margin-top: 1.5rem; }
    .settings-directory__section-head { margin-bottom: .75rem; padding-bottom: .6rem; border-bottom: 1px solid var(--if-border-strong, #d3dbdc); }
    .settings-directory__section-head h2 { margin: 0; font-size: 1.25rem; }
    .settings-directory__section-head h2 i { color: var(--if-primary, #0d9488); }
    .settings-directory__section-head p { margin: .2rem 0 0; color: var(--if-muted, #5d6f76); font-size: .875rem; }
    .settings-directory__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr)); gap: .65rem; }
    .settings-directory__item { display: flex; align-items: flex-start; gap: .75rem; min-height: 4.5rem; padding: .85rem; background: var(--if-surface, #fff); border: 1px solid var(--if-border, #e3e9ea); border-radius: var(--if-radius, 12px); color: var(--if-ink, #16232a); text-decoration: none; }
    .settings-directory__item:hover, .settings-directory__item:focus-visible { border-color: var(--if-primary, #0d9488); color: var(--if-ink, #16232a); box-shadow: var(--if-shadow, none); }
    .settings-directory__item > i { margin-top: .15rem; color: var(--if-primary, #0d9488); }
    .settings-directory__item strong, .settings-directory__item small { display: block; }
    .settings-directory__item small { margin-top: .12rem; color: var(--if-muted, #5d6f76); line-height: 1.35; }
</style>

<div class="settings-directory">
    <header class="settings-directory__head">
        <h1 class="h2"><i class="fas fa-fw fa-cog me-2" aria-hidden="true"></i>Settings</h1>
        <p>Choose what you want to configure.</p>
    </header>
    <nav class="settings-directory__nav" aria-label="Settings sections">
        <ul>
            <?php foreach ($settings_groups as $group_id => $group) { ?>
                <li><a href="#<?php echo nullable_htmlentities($group_id); ?>"><i class="fas fa-fw <?php echo nullable_htmlentities($group['icon']); ?>" aria-hidden="true"></i><?php echo nullable_htmlentities($group['title']); ?></a></li>
            <?php } ?>
        </ul>
    </nav>

    <?php foreach ($settings_groups as $group_id => $group) { ?>
        <section id="<?php echo nullable_htmlentities($group_id); ?>" class="settings-directory__section" aria-labelledby="settings-<?php echo nullable_htmlentities($group_id); ?>-title">
            <div class="settings-directory__section-head">
                <h2 id="settings-<?php echo nullable_htmlentities($group_id); ?>-title"><i class="fas fa-fw <?php echo nullable_htmlentities($group['icon']); ?> me-2" aria-hidden="true"></i><?php echo nullable_htmlentities($group['title']); ?></h2>
                <p><?php echo nullable_htmlentities($group['description']); ?></p>
            </div>
            <div class="settings-directory__grid">
                <?php foreach ($group['items'] as $item) {
                    [$label, $description, $page, $icon] = $item;
                    if (isset($item[4]) && !$item[4]) { continue; } ?>
                    <a class="settings-directory__item" href="/admin/<?php echo nullable_htmlentities($page); ?>">
                        <i class="fas fa-fw <?php echo nullable_htmlentities($icon); ?>" aria-hidden="true"></i>
                        <span><strong><?php echo nullable_htmlentities($label); ?></strong><small><?php echo nullable_htmlentities($description); ?></small></span>
                    </a>
                <?php } ?>
            </div>
        </section>
    <?php } ?>
</div>

<?php require_once "../includes/footer.php"; ?>
