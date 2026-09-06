<!-- Admin Sidebar (Tabler vertical navbar).
     data-bs-theme="dark" keeps the sidebar dark in both app themes, exactly as the
     AdminLTE 4 shell did.

     Collapsible groups use Tabler's .nav-item.dropdown shape but deliberately carry
     NO data-bs-toggle="dropdown": bootstrap.bundle.min.js would then wire them
     itself and fight js/shell.js, which owns this toggle. The hook shell.js binds
     is a.nav-link.dropdown-toggle[data-if-toggle="submenu"]; it flips .show on the
     toggle and on its #id-matched .dropdown-menu sibling, .active on the parent
     <li>, and aria-expanded on the toggle.

     Which group starts open is still decided SERVER-SIDE by the same in_array()
     page maps as before, so the group containing the current page is expanded on
     first paint, with or without JS. -->
<aside class="navbar navbar-vertical navbar-expand-lg d-print-none" data-bs-theme="dark">
    <div class="container-fluid">

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Brand area. The back-to-app link keeps its own .section-nav-back styling
             from css/itflow_bs5_bridge.css, so the brand box contributes no padding
             of its own (p-0) and lets the link fill it (w-100). -->
        <div class="navbar-brand p-0 w-100">
            <a class="section-nav-back" href="/agent/<?php echo $config_start_page ?>">
                <i class="fas fa-arrow-left"></i> Administration
            </a>
        </div>

        <div class="collapse navbar-collapse" id="sidebar-menu">
            <ul class="navbar-nav pt-lg-2">

                <li class="nav-item nav-section-title">ACCESS</li>
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "users.php") {echo " active";} ?>">
                    <a href="/admin/users.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "users.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-users"></i></span>
                        <span class="nav-link-title">Users</span>
                    </a>
                </li>
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "roles.php") {echo " active";} ?>">
                    <a href="/admin/roles.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "roles.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-user-shield"></i></span>
                        <span class="nav-link-title">Roles</span>
                    </a>
                </li>
                <!-- 2025-12-05 JQ - Hide Permission Modules currently just shows modules
                <li class="nav-item">
                    <a href="/admin/modules.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "modules.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-puzzle-piece"></i></span>
                        <span class="nav-link-title">Modules</span>
                    </a>
                </li>
                -->
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "api_keys.php") {echo " active";} ?>">
                    <a href="/admin/api_keys.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "api_keys.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-key"></i></span>
                        <span class="nav-link-title">API Keys</span>
                    </a>
                </li>
                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == "api_docs.php") {echo " active";} ?>">
                    <a href="/admin/api_docs.php" class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == "api_docs.php") {echo "active";} ?>">
                        <span class="nav-link-icon"><i class="fas fa-code"></i></span>
                        <span class="nav-link-title">API Docs</span>
                    </a>
                </li>

                <li class="nav-item nav-section-title">CONFIGURATION</li>

                <!-- TAGS & CATEGORIES Section -->
                <?php $nav_open_tags = in_array(basename($_SERVER['PHP_SELF']), ['tag.php', 'category.php', 'custom_link.php', 'ai_provider.php', 'ai_model.php', 'people_import.php', 'employee_workflow_templates.php', 'employee_workflow_template_details.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_tags ? ' active' : ''); ?>">
                    <a href="#nav-group-tags" class="nav-link dropdown-toggle<?php echo ($nav_open_tags ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-tags" aria-expanded="<?php echo ($nav_open_tags ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-sliders-h"></i></span>
                        <span class="nav-link-title">Tags &amp; Categories</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_tags ? ' show' : ''); ?>" id="nav-group-tags">
                        <a href="/admin/tag.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'tag.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-tags"></i></span>
                            <span class="text-truncate">Tags</span>
                        </a>
                        <a href="/admin/category.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'category.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-list-ul"></i></span>
                            <span class="text-truncate">Categories</span>
                        </a>
                        <a href="/admin/custom_link.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'custom_link.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-external-link-alt"></i></span>
                            <span class="text-truncate">Custom Links</span>
                        </a>
                        <a href="/admin/ai_provider.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['ai_provider.php', 'ai_model.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-robot"></i></span>
                            <span class="text-truncate">AI Providers</span>
                        </a>
                        <a href="/admin/people_import.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'people_import.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-file-import"></i></span>
                            <span class="text-truncate">People Import</span>
                        </a>
                        <a href="/admin/employee_workflow_templates.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['employee_workflow_templates.php', 'employee_workflow_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-tasks"></i></span>
                            <span class="text-truncate">Employee Workflow Templates</span>
                        </a>
                    </div>
                </li>

                <?php if ($config_module_enable_accounting) { ?>
                <!-- BILLING Section -->
                <?php $nav_open_billing = in_array(basename($_SERVER['PHP_SELF']), ['tax.php', 'payment_method.php', 'payment_provider.php', 'saved_payment_method.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_billing ? ' active' : ''); ?>">
                    <a href="#nav-group-billing" class="nav-link dropdown-toggle<?php echo ($nav_open_billing ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-billing" aria-expanded="<?php echo ($nav_open_billing ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-hand-holding-usd"></i></span>
                        <span class="nav-link-title">Billing</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_billing ? ' show' : ''); ?>" id="nav-group-billing">
                        <a href="/admin/tax.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'tax.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-balance-scale"></i></span>
                            <span class="text-truncate">Taxes</span>
                        </a>
                        <a href="/admin/payment_method.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payment_method.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-money-check-alt"></i></span>
                            <span class="text-truncate">Payment Methods</span>
                        </a>
                        <a href="/admin/payment_provider.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payment_provider.php', 'saved_payment_method.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="far fa-credit-card"></i></span>
                            <span class="text-truncate">Payment Providers</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_payroll) { ?>
                <!-- PAYROLL Section -->
                <?php $nav_open_payroll = in_array(basename($_SERVER['PHP_SELF']), ['payroll_employees.php', 'payroll_employee.php', 'payroll_deductions.php', 'payroll_periods.php', 'payroll_period.php', 'payroll_runs.php', 'payroll_run.php', 'payroll_settings.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_payroll ? ' active' : ''); ?>">
                    <a href="#nav-group-payroll" class="nav-link dropdown-toggle<?php echo ($nav_open_payroll ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-payroll" aria-expanded="<?php echo ($nav_open_payroll ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-money-check"></i></span>
                        <span class="nav-link-title">Payroll</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_payroll ? ' show' : ''); ?>" id="nav-group-payroll">
                        <a href="/admin/payroll_employees.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_employees.php', 'payroll_employee.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-user-tag"></i></span>
                            <span class="text-truncate">Employees</span>
                        </a>
                        <a href="/admin/payroll_deductions.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_deductions.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-minus-circle"></i></span>
                            <span class="text-truncate">Deductions</span>
                        </a>
                        <a href="/admin/payroll_periods.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_periods.php', 'payroll_period.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-calendar-alt"></i></span>
                            <span class="text-truncate">Periods</span>
                        </a>
                        <a href="/admin/payroll_runs.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['payroll_runs.php', 'payroll_run.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-play-circle"></i></span>
                            <span class="text-truncate">Runs</span>
                        </a>
                        <a href="/admin/payroll_settings.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_settings.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-sliders-h"></i></span>
                            <span class="text-truncate">Settings</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php if ($config_module_enable_ticketing) { ?>
                <!-- TICKETING Section -->
                <?php $nav_open_ticketing = in_array(basename($_SERVER['PHP_SELF']), ['ticket_status.php', 'labor_type.php', 'ticket_automation.php', 'mailbox.php', 'mail_requests.php', 'sla_calendars.php', 'sla_policies.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_ticketing ? ' active' : ''); ?>">
                    <a href="#nav-group-ticketing" class="nav-link dropdown-toggle<?php echo ($nav_open_ticketing ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-ticketing" aria-expanded="<?php echo ($nav_open_ticketing ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-life-ring"></i></span>
                        <span class="nav-link-title">Ticketing</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_ticketing ? ' show' : ''); ?>" id="nav-group-ticketing">
                        <a href="/admin/ticket_status.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ticket_status.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-info-circle"></i></span>
                            <span class="text-truncate">Ticket Statuses</span>
                        </a>
                        <a href="/admin/labor_type.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'labor_type.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-clock"></i></span>
                            <span class="text-truncate">Labor Types</span>
                        </a>
                        <a href="/admin/mailbox.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'mailbox.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-inbox"></i></span>
                            <span class="text-truncate">Mailboxes</span>
                        </a>
                        <a href="/admin/mail_requests.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'mail_requests.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-envelope-open-text"></i></span>
                            <span class="text-truncate">Requests</span>
                        </a>
                        <a href="/admin/ticket_automation.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ticket_automation.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-robot"></i></span>
                            <span class="text-truncate">Ticket Automation</span>
                        </a>
                        <a href="/admin/sla_policies.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sla_policies.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-stopwatch"></i></span>
                            <span class="text-truncate">SLA Policies</span>
                        </a>
                        <a href="/admin/sla_calendars.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sla_calendars.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-business-time"></i></span>
                            <span class="text-truncate">SLA Business Hours</span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <?php
                /*
                 * Admin > Knowledge Base is the KB *settings* page, not a cross-area redirect
                 * into the agent article browser. That browser is still one click away: from
                 * the agent sidebar and from two buttons on admin/settings_kb.php.
                 *
                 * The $config_module_enable_kb guard is deliberately NOT applied here (the old
                 * link had it). This page is where the module gets switched back on, so hiding
                 * it while the module is off would make it unreachable exactly when it is needed.
                 * The old active-state test on ['kb_articles.php','kb_article.php'] is gone too -
                 * those basenames only exist under /agent, so it could never fire on an admin page.
                 */
                ?>
                <?php if (lookupUserPermission("module_kb") >= 1) { ?>
                    <li class="nav-item<?php echo (basename($_SERVER['PHP_SELF']) == 'settings_kb.php' ? ' active' : ''); ?>">
                        <a href="/admin/settings_kb.php" class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_kb.php' ? 'active' : ''); ?>">
                            <span class="nav-link-icon"><i class="fas fa-book"></i></span>
                            <span class="nav-link-title">Knowledge Base</span>
                        </a>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc) { ?>
                <!-- TEMPLATES Section -->
                <?php $nav_open_templates = in_array(basename($_SERVER['PHP_SELF']), ['contract_template.php', 'contract_template_details.php', 'project_template.php', 'project_template_details.php', 'onboarding_templates.php', 'onboarding_template_details.php', 'ticket_template.php', 'ticket_template_details.php', 'canned_responses.php', 'worksheet_template.php', 'worksheet_template_details.php', 'vendor_template.php', 'software_template.php', 'document_template.php', 'document_template_details.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_templates ? ' active' : ''); ?>">
                    <a href="#nav-group-templates" class="nav-link dropdown-toggle<?php echo ($nav_open_templates ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-templates" aria-expanded="<?php echo ($nav_open_templates ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-copy"></i></span>
                        <span class="nav-link-title">Templates</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_templates ? ' show' : ''); ?>" id="nav-group-templates">
                        <a href="/admin/contract_template.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['contract_template.php', 'contract_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-file-contract"></i></span>
                            <span class="text-truncate">Contract Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/contract_template/contract_template_add.php" data-modal-size="lg"></span>
                        </a>
                        <a href="/admin/project_template.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['project_template.php', 'project_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-project-diagram"></i></span>
                            <span class="text-truncate">Project Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/project_template/project_template_add.php"></span>
                        </a>
                        <a href="/admin/onboarding_templates.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['onboarding_templates.php', 'onboarding_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-user-plus"></i></span>
                            <span class="text-truncate">Onboarding Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/onboarding_template/onboarding_template_add.php"></span>
                        </a>
                        <a href="/admin/ticket_template.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['ticket_template.php', 'ticket_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-life-ring"></i></span>
                            <span class="text-truncate">Ticket Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/ticket_template/ticket_template_add.php" data-modal-size="lg"></span>
                        </a>
                        <a href="/admin/service_catalog.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'service_catalog.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-th-large"></i></span>
                            <span class="text-truncate">Service Catalog</span>
                        </a>
                        <a href="/admin/canned_responses.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'canned_responses.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-comment-dots"></i></span>
                            <span class="text-truncate">Canned Responses</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/canned_response/canned_response_add.php" data-modal-size="lg"></span>
                        </a>
                        <a href="/admin/worksheet_template.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['worksheet_template.php', 'worksheet_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="text-truncate">Worksheet Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/worksheet_template/worksheet_template_add.php" data-modal-size="lg"></span>
                        </a>
                        <a href="/admin/vendor_template.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vendor_template.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-building"></i></span>
                            <span class="text-truncate">Vendor Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/vendor_template/vendor_template_add.php"></span>
                        </a>
                        <a href="/admin/software_template.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'software_template.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-rocket"></i></span>
                            <span class="text-truncate">License Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/software_template/software_template_add.php"></span>
                        </a>
                        <a href="/admin/document_template.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['document_template.php', 'document_template_details.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-file-alt"></i></span>
                            <span class="text-truncate">Document Templates</span>
                            <span href="#" class="fas fa-plus-circle ms-auto ajax-modal" data-modal-url="/admin/modals/document_template/document_template_add.php" data-modal-size="xl"></span>
                        </a>
                    </div>
                </li>
                <?php } ?>

                <!-- MAINTENANCE Section -->
                <?php $nav_open_maintenance = in_array(basename($_SERVER['PHP_SELF']), ['cron.php', 'mail_queue.php', 'email_log.php', 'audit_log.php', 'app_log.php', 'backup.php', 'debug.php', 'update.php', 'credential_restore.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_maintenance ? ' active' : ''); ?>">
                    <a href="#nav-group-maintenance" class="nav-link dropdown-toggle<?php echo ($nav_open_maintenance ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-maintenance" aria-expanded="<?php echo ($nav_open_maintenance ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-tools"></i></span>
                        <span class="nav-link-title">Maintenance</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_maintenance ? ' show' : ''); ?>" id="nav-group-maintenance">
                        <a href="/admin/cron.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cron.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-clock"></i></span>
                            <span class="text-truncate">Cron</span>
                        </a>
                        <a href="/admin/mail_queue.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'mail_queue.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-mail-bulk"></i></span>
                            <span class="text-truncate">Mail Queue</span>
                        </a>
                        <a href="/admin/email_log.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'email_log.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-envelope-open-text"></i></span>
                            <span class="text-truncate">Email Log</span>
                        </a>
                        <a href="/admin/audit_log.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'audit_log.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-history"></i></span>
                            <span class="text-truncate">Audit Logs</span>
                        </a>
                        <a href="/admin/app_log.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'app_log.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-history"></i></span>
                            <span class="text-truncate">App Logs</span>
                        </a>
                        <a href="/admin/backup.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                            <span class="text-truncate">Backup</span>
                        </a>
                        <a href="/admin/credential_restore.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credential_restore.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-key"></i></span>
                            <span class="text-truncate">Credential Restore</span>
                        </a>
                        <a href="/admin/debug.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'debug.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-bug"></i></span>
                            <span class="text-truncate">Debug</span>
                        </a>
                        <a href="/admin/update.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'update.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-download"></i></span>
                            <span class="text-truncate">Update</span>
                        </a>
                    </div>
                </li>

                <!-- SETTINGS Section -->
                <?php $nav_open_settings = in_array(basename($_SERVER['PHP_SELF']), ['settings_company.php', 'settings_localization.php', 'settings_theme.php', 'settings_appearance.php', 'settings_security.php', 'settings_mail.php', 'settings_notification.php', 'settings_default.php', 'settings_invoice.php', 'settings_quote.php', 'settings_online_payment.php', 'settings_online_payment_clients.php', 'settings_project.php', 'settings_ticket.php', 'settings_ai.php', 'identity_provider.php', 'settings_telemetry.php', 'settings_module.php', 'settings_calendar_sync.php', 'settings_webhooks.php', 'settings_integrations.php', 'settings_comet.php', 'comet_status.php', 'settings_rmm.php', 'settings_unifi.php', 'settings_accounting.php']); ?>
                <li class="nav-item dropdown mt-2<?php echo ($nav_open_settings ? ' active' : ''); ?>">
                    <a href="#nav-group-settings" class="nav-link dropdown-toggle<?php echo ($nav_open_settings ? ' show' : ''); ?>" data-if-toggle="submenu" role="button" aria-controls="nav-group-settings" aria-expanded="<?php echo ($nav_open_settings ? 'true' : 'false'); ?>">
                        <span class="nav-link-icon"><i class="fas fa-cog"></i></span>
                        <span class="nav-link-title">Settings</span>
                    </a>
                    <div class="dropdown-menu<?php echo ($nav_open_settings ? ' show' : ''); ?>" id="nav-group-settings">
                        <a href="/admin/settings_company.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_company.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fa fa-briefcase"></i></span>
                            <span class="text-truncate">Company Details</span>
                        </a>
                        <a href="/admin/settings_localization.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_localization.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fa fa-globe"></i></span>
                            <span class="text-truncate">Localization</span>
                        </a>
                        <a href="/admin/settings_theme.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_theme.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fa fa-paint-brush"></i></span>
                            <span class="text-truncate">Theme</span>
                        </a>
                        <a href="/admin/settings_appearance.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_appearance.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fa fa-palette"></i></span>
                            <span class="text-truncate">Appearance</span>
                        </a>
                        <a href="/admin/settings_security.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_security.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-shield-alt"></i></span>
                            <span class="text-truncate">Security</span>
                        </a>
                        <a href="/admin/settings_mail.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_mail.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="far fa-envelope"></i></span>
                            <span class="text-truncate">Mail</span>
                        </a>
                        <a href="/admin/settings_notification.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_notification.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="far fa-bell"></i></span>
                            <span class="text-truncate">Notifications</span>
                        </a>
                        <a href="/admin/settings_default.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_default.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-cogs"></i></span>
                            <span class="text-truncate">Defaults</span>
                        </a>
                        <?php if ($config_module_enable_accounting) { ?>
                            <a href="/admin/settings_invoice.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_invoice.php' ? 'active' : ''); ?>">
                                <span class="dropdown-item-icon"><i class="fas fa-file-invoice"></i></span>
                                <span class="text-truncate">Invoice</span>
                            </a>
                            <a href="/admin/settings_quote.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_quote.php' ? 'active' : ''); ?>">
                                <span class="dropdown-item-icon"><i class="fas fa-comment-dollar"></i></span>
                                <span class="text-truncate">Quote</span>
                            </a>
                        <?php } ?>
                        <?php if ($config_module_enable_ticketing) { ?>
                            <a href="/admin/settings_project.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_project.php' ? 'active' : ''); ?>">
                                <span class="dropdown-item-icon"><i class="fas fa-project-diagram"></i></span>
                                <span class="text-truncate">Project</span>
                            </a>
                            <a href="/admin/settings_ticket.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_ticket.php' ? 'active' : ''); ?>">
                                <span class="dropdown-item-icon"><i class="fas fa-life-ring"></i></span>
                                <span class="text-truncate">Ticket</span>
                            </a>
                        <?php } ?>
                        <a href="/admin/settings_ai.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_ai.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-robot"></i></span>
                            <span class="text-truncate">AI</span>
                        </a>
                        <!-- Currently the only integration is the client portal SSO -->
                        <?php if ($config_client_portal_enable) { ?>
                            <a href="/admin/identity_provider.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'identity_provider.php' ? 'active' : ''); ?>">
                                <span class="dropdown-item-icon"><i class="fas fa-fingerprint"></i></span>
                                <span class="text-truncate">Identity Provider</span>
                            </a>
                        <?php } ?>
                        <a href="/admin/settings_calendar_sync.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_calendar_sync.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-calendar-alt"></i></span>
                            <span class="text-truncate">Calendar Sync</span>
                        </a>
                        <a href="/admin/settings_telemetry.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_telemetry.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-satellite-dish"></i></span>
                            <span class="text-truncate">Telemetry</span>
                        </a>
                        <a href="/admin/settings_module.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_module.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-cube"></i></span>
                            <span class="text-truncate">Modules</span>
                        </a>
                        <a href="/admin/settings_webhooks.php" class="dropdown-item <?php echo (basename($_SERVER['PHP_SELF']) == 'settings_webhooks.php' ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-satellite-dish"></i></span>
                            <span class="text-truncate">Webhooks</span>
                        </a>
                        <a href="/admin/settings_integrations.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['settings_integrations.php','settings_comet.php','comet_status.php','settings_rmm.php','settings_unifi.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-plug"></i></span>
                            <span class="text-truncate">Integrations</span>
                        </a>
                        <?php if ($config_module_enable_accounting) { ?>
                        <a href="/admin/settings_accounting.php" class="dropdown-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['settings_accounting.php', 'accounting_client_mapping.php', 'accounting_item_mapping.php', 'accounting_sync_status.php']) ? 'active' : ''); ?>">
                            <span class="dropdown-item-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="text-truncate">Accounting</span>
                        </a>
                        <?php } ?>
                    </div>
                </li>

                <?php
                $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links
                    WHERE custom_link_location = 4 AND custom_link_archived_at IS NULL
                    ORDER BY custom_link_order ASC, custom_link_name ASC"
                );

                while ($row = mysqli_fetch_assoc($sql_custom_links)) {
                    $custom_link_name = nullable_htmlentities($row['custom_link_name']);
                    $custom_link_uri = sanitize_url($row['custom_link_uri']);
                    $custom_link_icon_class = itflow_nav_icon_class($row['custom_link_icon']);
                    $custom_link_new_tab = intval($row['custom_link_new_tab']);
                    if ($custom_link_new_tab == 1) {
                        $target = "target='_blank' rel='noopener noreferrer'";
                    } else {
                        $target = "";
                    }

                    ?>

                <li class="nav-item<?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo " active"; } ?>">
                    <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo "active"; } ?>">
                        <span class="nav-link-icon"><i class="fas <?php echo $custom_link_icon_class; ?>"></i></span>
                        <span class="nav-link-title"><?php echo $custom_link_name; ?></span>
                        <i class="fas fa-angle-right ms-auto"></i>
                    </a>
                </li>

                <?php } ?>

            </ul>
            <div class="mb-3"></div>
        </div>
        <!-- /.navbar-collapse -->

    </div>
</aside>
