<!-- Main Sidebar Container (AdminLTE 4). data-bs-theme="dark" keeps the sidebar dark in both app themes. -->
<aside class="app-sidebar shadow d-print-none" data-bs-theme="dark">

    <div class="sidebar-brand">
        <a class="brand-link" href="/agent/<?php echo $config_start_page ?>">
            <i class="nav-icon fas fa-arrow-left ms-1 me-2"></i>
            <span class="brand-text">Back | <strong>Administration</strong></span>
        </a>
    </div>

    <!-- Sidebar -->
    <div class="sidebar-wrapper">

        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" role="menu">
                <li class="nav-header">ACCESS</li>
                <li class="nav-item">
                    <a href="/admin/users.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "users.php") {echo "active";} ?>">
                        <i class="nav-icon fas fa-users"></i>
                        <p>Users</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/roles.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "roles.php") {echo "active";} ?>">
                        <i class="nav-icon fas fa-user-shield"></i>
                        <p>Roles</p>
                    </a>
                </li>
                <!-- 2025-12-05 JQ - Hide Permission Modules currently just shows modules
                <li class="nav-item">
                    <a href="/admin/modules.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "modules.php") {echo "active";} ?>">
                        <i class="nav-icon fas fa-puzzle-piece"></i>
                        <p>Modules</p>
                    </a>
                </li>
                -->
                <li class="nav-item">
                    <a href="/admin/api_keys.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "api_keys.php") {echo "active";} ?>">
                        <i class="nav-icon fas fa-key"></i>
                        <p>API Keys</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/admin/api_docs.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "api_docs.php") {echo "active";} ?>">
                        <i class="nav-icon fas fa-code"></i>
                        <p>API Docs</p>
                    </a>
                </li>

                <li class="nav-header">CONFIGURATION</li>

                <!-- TAGS & CATEGORIES Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['tag.php', 'category.php', 'custom_link.php', 'ai_provider.php', 'ai_model.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-sliders-h"></i>
                        <p>
                            Tags &amp; Categories
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/tag.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'tag.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-tags"></i>
                                <p>Tags</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/category.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'category.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-list-ul"></i>
                                <p>Categories</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/custom_link.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'custom_link.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-external-link-alt"></i>
                                <p>Custom Links</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/ai_provider.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['ai_provider.php', 'ai_model.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-robot"></i>
                                <p>AI Providers</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <?php if ($config_module_enable_accounting) { ?>
                <!-- BILLING Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['tax.php', 'payment_method.php', 'payment_provider.php', 'saved_payment_method.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-hand-holding-usd"></i>
                        <p>
                            Billing
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/tax.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'tax.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-balance-scale"></i>
                                <p>Taxes</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/payment_method.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'payment_method.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-money-check-alt"></i>
                                <p>Payment Methods</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/payment_provider.php"
                               class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payment_provider.php', 'saved_payment_method.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon far fa-credit-card"></i>
                                <p>Payment Providers</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_payroll) { ?>
                <!-- PAYROLL Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_employees.php', 'payroll_employee.php', 'payroll_deductions.php', 'payroll_periods.php', 'payroll_period.php', 'payroll_runs.php', 'payroll_run.php', 'payroll_settings.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-money-check"></i>
                        <p>
                            Payroll
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/payroll_employees.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_employees.php', 'payroll_employee.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-user-tag"></i>
                                <p>Employees</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/payroll_deductions.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_deductions.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-minus-circle"></i>
                                <p>Deductions</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/payroll_periods.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_periods.php', 'payroll_period.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-calendar-alt"></i>
                                <p>Periods</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/payroll_runs.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_runs.php', 'payroll_run.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-play-circle"></i>
                                <p>Runs</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/payroll_settings.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_settings.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-sliders-h"></i>
                                <p>Settings</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_ticketing) { ?>
                <!-- TICKETING Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['ticket_status.php', 'labor_type.php', 'ticket_automation.php', 'mailbox.php', 'mail_requests.php', 'sla_calendars.php', 'sla_policies.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-life-ring"></i>
                        <p>
                            Ticketing
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/ticket_status.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'ticket_status.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-info-circle"></i>
                                <p>Ticket Statuses</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/labor_type.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'labor_type.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-clock"></i>
                                <p>Labor Types</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/mailbox.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'mailbox.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-inbox"></i>
                                <p>Mailboxes</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/mail_requests.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'mail_requests.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-envelope-open-text"></i>
                                <p>Requests</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/ticket_automation.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'ticket_automation.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-robot"></i>
                                <p>Ticket Automation</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/sla_policies.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'sla_policies.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-stopwatch"></i>
                                <p>SLA Policies</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/sla_calendars.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'sla_calendars.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-business-time"></i>
                                <p>SLA Business Hours</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_kb == 1 && lookupUserPermission("module_kb") >= 1) { ?>
                    <li class="nav-item">
                        <a href="/agent/kb_articles.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['kb_articles.php', 'kb_article.php']) ? 'active' : ''); ?>">
                            <i class="nav-icon fas fa-book"></i>
                            <p>Knowledge Base</p>
                        </a>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc) { ?>
                <!-- TEMPLATES Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['contract_template.php', 'contract_template_details.php', 'project_template.php', 'project_template_details.php', 'onboarding_templates.php', 'onboarding_template_details.php', 'ticket_template.php', 'ticket_template_details.php', 'canned_responses.php', 'worksheet_template.php', 'worksheet_template_details.php', 'vendor_template.php', 'software_template.php', 'document_template.php', 'document_template_details.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-copy"></i>
                        <p>
                            Templates
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/contract_template.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['contract_template.php', 'contract_template_details.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-file-contract"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/contract_template/contract_template_add.php" data-modal-size="lg"></span>
                                    Contract Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/project_template.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['project_template.php', 'project_template_details.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-project-diagram"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/project_template/project_template_add.php"></span>
                                    Project Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/onboarding_templates.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['onboarding_templates.php', 'onboarding_template_details.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-user-plus"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/onboarding_template/onboarding_template_add.php"></span>
                                    Onboarding Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/ticket_template.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['ticket_template.php', 'ticket_template_details.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-life-ring"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/ticket_template/ticket_template_add.php" data-modal-size="lg"></span>
                                    Ticket Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/canned_responses.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'canned_responses.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-comment-dots"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/canned_response/canned_response_add.php" data-modal-size="lg"></span>
                                    Canned Responses
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/worksheet_template.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['worksheet_template.php', 'worksheet_template_details.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-clipboard-list"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/worksheet_template/worksheet_template_add.php" data-modal-size="lg"></span>
                                    Worksheet Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/vendor_template.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'vendor_template.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-building"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/vendor_template/vendor_template_add.php"></span>
                                    Vendor Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/software_template.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'software_template.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-rocket"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/software_template/software_template_add.php"></span>
                                    License Templates
                                </p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/document_template.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['document_template.php', 'document_template_details.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-file-alt"></i>
                                <p>
                                    <span href="#" class="fas fa-plus-circle float-end ajax-modal" data-modal-url="/admin/modals/document_template/document_template_add.php" data-modal-size="xl"></span>
                                    Document Templates
                                </p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php } ?>

                <!-- MAINTENANCE Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['cron.php', 'mail_queue.php', 'email_log.php', 'audit_log.php', 'app_log.php', 'backup.php', 'debug.php', 'update.php', 'credential_restore.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-tools"></i>
                        <p>
                            Maintenance
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/cron.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'cron.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-clock"></i>
                                <p>Cron</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/mail_queue.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'mail_queue.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-mail-bulk"></i>
                                <p>Mail Queue</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/email_log.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'email_log.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-envelope-open-text"></i>
                                <p>Email Log</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/audit_log.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'audit_log.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-history"></i>
                                <p>Audit Logs</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/app_log.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'app_log.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-history"></i>
                                <p>App Logs</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/backup.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-cloud-upload-alt"></i>
                                <p>Backup</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/credential_restore.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'credential_restore.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-key"></i>
                                <p>Credential Restore</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/debug.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'debug.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-bug"></i>
                                <p>Debug</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/update.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'update.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-download"></i>
                                <p>Update</p>
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- SETTINGS Section -->
                <li class="nav-item has-treeview mt-2 <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['settings_company.php', 'settings_localization.php', 'settings_theme.php', 'settings_appearance.php', 'settings_security.php', 'settings_mail.php', 'settings_notification.php', 'settings_default.php', 'settings_invoice.php', 'settings_quote.php', 'settings_online_payment.php', 'settings_online_payment_clients.php', 'settings_project.php', 'settings_ticket.php', 'settings_ai.php', 'identity_provider.php', 'settings_telemetry.php', 'settings_module.php', 'settings_calendar_sync.php', 'settings_webhooks.php', 'settings_integrations.php', 'settings_comet.php', 'comet_status.php', 'settings_rmm.php', 'settings_unifi.php', 'settings_accounting.php']) ? 'menu-open' : ''); ?>">
                    <a href="#" class="nav-link">
                        <i class="nav-icon fas fa-cog"></i>
                        <p>
                            Settings
                            <i class="nav-arrow fas fa-angle-right"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="/admin/settings_company.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_company.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fa fa-briefcase"></i>
                                <p>Company Details</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_localization.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_localization.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fa fa-globe"></i>
                                <p>Localization</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_theme.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_theme.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fa fa-paint-brush"></i>
                                <p>Theme</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_appearance.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_appearance.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fa fa-palette"></i>
                                <p>Appearance</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_security.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_security.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-shield-alt"></i>
                                <p>Security</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_mail.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_mail.php' ? 'active' : ''); ?>">
                                <i class="nav-icon far fa-envelope"></i>
                                <p>Mail</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_notification.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_notification.php' ? 'active' : ''); ?>">
                                <i class="nav-icon far fa-bell"></i>
                                <p>Notifications</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_default.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_default.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-cogs"></i>
                                <p>Defaults</p>
                            </a>
                        </li>
                        <?php if ($config_module_enable_accounting) { ?>
                            <li class="nav-item">
                                <a href="/admin/settings_invoice.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_invoice.php' ? 'active' : ''); ?>">
                                    <i class="nav-icon fas fa-file-invoice"></i>
                                    <p>Invoice</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/admin/settings_quote.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_quote.php' ? 'active' : ''); ?>">
                                    <i class="nav-icon fas fa-comment-dollar"></i>
                                    <p>Quote</p>
                                </a>
                            </li>
                        <?php } ?>
                        <?php if ($config_module_enable_ticketing) { ?>
                            <li class="nav-item">
                                <a href="/admin/settings_project.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_project.php' ? 'active' : ''); ?>">
                                    <i class="nav-icon fas fa-project-diagram"></i>
                                    <p>Project</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/admin/settings_ticket.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_ticket.php' ? 'active' : ''); ?>">
                                    <i class="nav-icon fas fa-life-ring"></i>
                                    <p>Ticket</p>
                                </a>
                            </li>
                        <?php } ?>
                        <li class="nav-item">
                            <a href="/admin/settings_ai.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_ai.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-robot"></i>
                                <p>AI</p>
                            </a>
                        </li>
                        <!-- Currently the only integration is the client portal SSO -->
                        <?php if ($config_client_portal_enable) { ?>
                            <li class="nav-item">
                                <a href="/admin/identity_provider.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'identity_provider.php' ? 'active' : ''); ?>">
                                    <i class="nav-icon fas fa-fingerprint"></i>
                                    <p>Identity Provider</p>
                                </a>
                            </li>
                        <?php } ?>
                        <li class="nav-item">
                            <a href="/admin/settings_calendar_sync.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_calendar_sync.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-calendar-alt"></i>
                                <p>Calendar Sync</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_telemetry.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_telemetry.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-satellite-dish"></i>
                                <p>Telemetry</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_module.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_module.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-cube"></i>
                                <p>Modules</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_webhooks.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_webhooks.php' ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-satellite-dish"></i>
                                <p>Webhooks</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="/admin/settings_integrations.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['settings_integrations.php','settings_comet.php','comet_status.php','settings_rmm.php','settings_unifi.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-plug"></i>
                                <p>Integrations</p>
                            </a>
                        </li>
                        <?php if ($config_module_enable_accounting) { ?>
                        <li class="nav-item">
                            <a href="/admin/settings_accounting.php" class="nav-link <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['settings_accounting.php', 'accounting_client_mapping.php', 'accounting_item_mapping.php', 'accounting_sync_status.php']) ? 'active' : ''); ?>">
                                <i class="nav-icon fas fa-file-invoice-dollar"></i>
                                <p>Accounting</p>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                </li>

                <?php
                $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links
                    WHERE custom_link_location = 4 AND custom_link_archived_at IS NULL
                    ORDER BY custom_link_order ASC, custom_link_name ASC"
                );

                while ($row = mysqli_fetch_assoc($sql_custom_links)) {
                    $custom_link_name = nullable_htmlentities($row['custom_link_name']);
                    $custom_link_uri = sanitize_url($row['custom_link_uri']);
                    $custom_link_icon = nullable_htmlentities($row['custom_link_icon']);
                    $custom_link_new_tab = intval($row['custom_link_new_tab']);
                    if ($custom_link_new_tab == 1) {
                        $target = "target='_blank' rel='noopener noreferrer'";
                    } else {
                        $target = "";
                    }

                    ?>

                <li class="nav-item">
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo "active"; } ?>">
                        <i class="fas fa-<?php echo $custom_link_icon; ?> nav-icon"></i>
                        <p><?php echo $custom_link_name; ?></p>
                        <i class="fas fa-angle-right nav-icon float-end"></i>
                    </a>
                </li>

                <?php } ?>

            </ul>
        </nav>
        <!-- /.sidebar-menu -->

        <div class="mb-3"></div>

    </div>
    <!-- /.sidebar-wrapper -->

</aside>
