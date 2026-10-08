<?php

/*
 * Which module (and level) each agent pop-up needs (roles audit 2026-09-26, F3 / P1d).
 *
 * includes/modal_header.php runs itflow_modal_check() for every pop-up it serves. Before this map, a pop-up
 * checked nothing but the login, so a Training-only role could open ticket replies, expense forms, the
 * calendar event editor and so on by URL. Now the requested file must pass its entry below first; the
 * file's own checks (enforceUserPermission, enforceClientAccess, read-only "inert" forms) still run after.
 *
 * HOW TO READ AN ENTRY
 *   'ticket' => ['module_support' => 1]
 *       Every file in agent/modals/ticket/ needs Tickets, assets & docs at level 1 (view) or above.
 *   'ticket/ticket_merge.php' => ['module_support' => 2]
 *       A file entry replaces its folder's entry. Level 2 (edit) is used only for pop-ups that are pure
 *       write forms AND whose buttons the pages already hide from view-only roles, so nobody loses a
 *       button they can see today.
 *   ['module_assets|module_support' => 1]
 *       "|" means any one of them. Several keys mean all of them.
 *   'it_agent' (only inside a "|" list): Departments or Tickets, assets & docs at that level - a full IT
 *       agent. Used only as the legacy fallback below and never named in denial messages.
 *   []  Any signed-in agent (the file filters its own rows, e.g. your own notifications).
 *
 * WHY SOME FOLDERS LIST MORE THAN ONE MODULE
 *   - Asset pop-ups accept Assets (module_assets, from DB 2.6.95) or Tickets, assets & docs (P4).
 *     Until that migration runs, lookupUserPermission('module_assets') is false and only module_support
 *     counts.
 *   - Finance and knowledge-base pop-ups were open to every agent before this map. Their own module comes
 *     first; "it_agent" keeps them for full IT agents (the Technician role holds neither Finance nor
 *     Knowledge base, and must see exactly what it saw before). The pages and the save handlers still
 *     require Finance / Knowledge base. Module-only logins (Training, Sales, ...) are denied.
 *
 * A FILE WITH NO ENTRY needs Departments or Tickets, assets & docs or Assets (not a module-only login),
 * and is logged to the PHP error log so the gap gets noticed.
 *
 * Admin roles pass everything (lookupUserPermission() answers 3 for them). admin/modals/* keep their own
 * admin-only gate in modal_header.php and never reach this map.
 */

function itflow_modal_permission_map(): array {
    // Finance / knowledge base: own module first, then the "full IT agent" fallback (see above).
    $finance = ['module_financial|it_agent' => 1];
    $kb      = ['module_kb|it_agent' => 1];
    $assets  = ['module_assets|module_support' => 1];

    return [
        'folders' => [
            // Departments & contacts
            'client'            => ['module_client' => 1],
            'contact'           => ['module_client' => 1],
            'location'          => ['module_client|module_support' => 1],   // page: support, handler: client
            'vendor'            => ['module_client|module_support|module_financial' => 1],  // file checks client/financial per vendor

            // Tickets, assets & docs
            'ticket'            => ['module_support' => 1],
            'recurring_ticket'  => ['module_support' => 1],
            'project'           => ['module_support' => 1],
            'calendar'          => ['module_support' => 1],   // P1c: the calendar belongs to Tickets, assets & docs
            'problem'           => ['module_support' => 1],
            'change'            => ['module_support' => 1],
            'mail_request'      => ['module_support' => 1],
            'contract'          => ['module_support' => 1],   // agent/post/contract.php checks module_support
            'document'          => ['module_support' => 1],
            'file'              => ['module_support' => 1],
            'folder'            => ['module_support' => 1],
            'domain'            => ['module_support' => 1],
            'certificate'       => ['module_support' => 1],
            'software'          => ['module_support' => 1],
            'service'           => ['module_support' => 1],
            'network'           => ['module_support' => 1],
            'network_drive'     => ['module_support' => 1],
            'printer'           => ['module_support' => 1],
            'rack'              => ['module_support' => 1],
            'asset'             => $assets,

            // Credentials
            'credential'        => ['module_credential' => 1],

            // Sales
            'quote'             => ['module_sales' => 1],
            'invoice'           => ['module_sales' => 1],
            'recurring_invoice' => ['module_sales' => 1],
            'product'           => ['module_sales' => 1],
            'opportunity'       => ['module_sales' => 1],
            'crm'               => ['module_sales' => 1],
            'payment'           => ['module_sales|module_financial' => 1],   // opened from invoices (Sales) and Payments (Finance)
            'revenue'           => ['module_financial|module_sales' => 1],   // page: Finance, handler: Sales

            // Finance
            'account'           => $finance,
            'expense'           => $finance,
            'recurring_expense' => $finance,
            'transfer'          => $finance,
            'trip'              => $finance,

            // Knowledge base
            'kb_article'        => $kb,
            'kb_category'       => $kb,
        ],

        'files' => [
            // Asset pop-ups that are really about tickets or docs keep Tickets, assets & docs.
            'asset/asset_bulk_add_ticket.php'   => ['module_support' => 1],
            'asset/asset_link_credential.php'   => ['module_support' => 1, 'module_credential' => 1],   // lists credentials
            'asset/asset_link_document.php'     => ['module_support' => 1],
            'asset/asset_link_file.php'         => ['module_support' => 1],
            'asset/asset_link_service.php'      => ['module_support' => 1],
            'asset/asset_link_software.php'     => ['module_support' => 1],
            'asset/asset_import.php'            => ['module_assets|module_support' => 2],

            // Creates tickets for the selected departments.
            'client/client_bulk_add_ticket.php' => ['module_client' => 1, 'module_support' => 1],

            // Linking a person to IT records lists those records: the person (Departments) and the records' module.
            'contact/contact_link_asset.php'      => ['module_client' => 1, 'module_assets|module_support' => 1],
            'contact/contact_link_credential.php' => ['module_client' => 1, 'module_credential' => 1],
            'contact/contact_link_document.php'   => ['module_client' => 1, 'module_support' => 1],
            'contact/contact_link_file.php'       => ['module_client' => 1, 'module_support' => 1],
            'contact/contact_link_service.php'    => ['module_client' => 1, 'module_support' => 1],
            'contact/contact_link_software.php'   => ['module_client' => 1, 'module_support' => 1],

            // Write-only forms whose buttons are already hidden from view-only roles.
            'client/client_add.php'             => ['module_client' => 2],
            'client/client_import.php'          => ['module_client' => 2],
            'project/project_add.php'           => ['module_support' => 2],
            'service/service_add.php'           => ['module_support' => 2],
            'quote/quote_add.php'               => ['module_sales' => 2],
            'quote/quote_copy.php'              => ['module_sales' => 2],
            'invoice/invoice_add.php'           => ['module_sales' => 2],
            'recurring_invoice/recurring_invoice_add.php' => ['module_sales' => 2],
            'product/product_add.php'           => ['module_sales' => 2],
            'recurring_ticket/recurring_ticket_bulk_agent_edit.php'    => ['module_support' => 2],
            'recurring_ticket/recurring_ticket_bulk_billable_edit.php' => ['module_support' => 2],
            'recurring_ticket/recurring_ticket_bulk_category_edit.php' => ['module_support' => 2],
            'recurring_ticket/recurring_ticket_bulk_next_run_edit.php' => ['module_support' => 2],
            'recurring_ticket/recurring_ticket_bulk_priority_edit.php' => ['module_support' => 2],
            'ticket/ticket_add_watcher.php'     => ['module_support' => 2],
            'ticket/ticket_attachment_add.php'  => ['module_support' => 2],
            'ticket/ticket_bulk_add_project.php' => ['module_support' => 2],
            'ticket/ticket_bulk_assign.php'     => ['module_support' => 2],
            'ticket/ticket_bulk_edit_category.php' => ['module_support' => 2],
            'ticket/ticket_bulk_edit_priority.php' => ['module_support' => 2],
            'ticket/ticket_bulk_merge.php'      => ['module_support' => 2],
            'ticket/ticket_bulk_reply.php'      => ['module_support' => 2],
            'ticket/ticket_bulk_resolve.php'    => ['module_support' => 2],
            'ticket/ticket_change_client.php'   => ['module_support' => 2],
            'ticket/ticket_charge_add.php'      => ['module_support' => 2],
            'ticket/ticket_contact.php'         => ['module_support' => 2],
            'ticket/ticket_delivery_method.php' => ['module_support' => 2],
            'ticket/ticket_edit_schedule.php'   => ['module_support' => 2],
            'ticket/ticket_edit_asset.php'      => ['module_support' => 2],
            'ticket/ticket_edit_vendor.php'     => ['module_support' => 2],
            'ticket/ticket_merge.php'           => ['module_support' => 2],
            'ticket/ticket_priority.php'        => ['module_support' => 2],
            'ticket/ticket_reply_edit.php'      => ['module_support' => 2],
            'ticket/ticket_reply_redact.php'    => ['module_support' => 2],
            'ticket/ticket_schedule_add.php'    => ['module_support' => 2],
            'ticket/ticket_status.php'          => ['module_support' => 2],
            'ticket/ticket_tags.php'            => ['module_support' => 2],
            'ticket/ticket_worksheet_add.php'   => ['module_support' => 2],
            // Turn a ticket into a quote / invoice: ticket.php shows these only with edit on both.
            'ticket/ticket_quote_add.php'       => ['module_support' => 2, 'module_sales' => 2],
            'ticket/ticket_invoice_add.php'     => ['module_support' => 2, 'module_sales' => 2],
        ],

        // /modals/*.php (outside agent/)
        'root' => [
            'notifications.php' => [],   // the signed-in user's own notifications
            'icon_catalog.php'  => [],   // static icon list for the shared icon picker
        ],
    ];
}

/** Requirement for a file with no entry: not a module-only login. */
const ITFLOW_MODAL_DEFAULT_REQUIREMENT = ['module_client|module_support|module_assets' => 1];

/**
 * The requirement for the pop-up at $script_name (a SCRIPT_NAME such as "/agent/modals/ticket/ticket_merge.php"),
 * or null when the script is not an agent pop-up (admin pop-ups have their own gate; pages are not checked here).
 */
function itflow_modal_requirement(string $script_name): ?array {
    $s = preg_replace('#/+#', '/', '/' . ltrim(str_replace('\\', '/', $script_name), '/'));
    $map = itflow_modal_permission_map();

    // Found anywhere in the path (not only at the start), like the admin/modals gate in modal_header.php,
    // so an install under a sub-path is checked too.
    $pos = strpos($s, '/agent/modals/');
    if ($pos !== false) {
        $rel = substr($s, $pos + strlen('/agent/modals/'));
        if (preg_match('#^([a-z0-9_]+)/[a-z0-9_]+\.php$#', $rel, $m)) {
            if (array_key_exists($rel, $map['files'])) {
                return $map['files'][$rel];
            }
            if (array_key_exists($m[1], $map['folders'])) {
                return $map['folders'][$m[1]];
            }
        }
        error_log("modal_permissions: no entry for $s - using the default requirement");
        return ITFLOW_MODAL_DEFAULT_REQUIREMENT;
    }

    if (strpos($s, '/admin/modals/') !== false) {
        return null;   // admin-only gate in modal_header.php
    }

    if (preg_match('#/modals/([A-Za-z0-9_]+\.php)$#', $s, $m)) {
        if (array_key_exists($m[1], $map['root'])) {
            return $map['root'][$m[1]];
        }
        error_log("modal_permissions: no entry for $s - using the default requirement");
        return ITFLOW_MODAL_DEFAULT_REQUIREMENT;
    }

    return null;
}

/** The signed-in role's level for $module as an int (admin = 3, none = 0); 'it_agent' = Departments or Tickets. */
function itflow_modal_level(string $module): int {
    if ($module === 'it_agent') {
        return max(intval(lookupUserPermission('module_client')), intval(lookupUserPermission('module_support')));
    }
    return intval(lookupUserPermission($module));
}

/** True when the signed-in role meets every key of $requirement (a key "a|b" is met by either module). */
function itflow_modal_requirement_met(array $requirement): bool {
    global $session_is_admin;
    if (isset($session_is_admin) && $session_is_admin === true) {
        return true;
    }
    foreach ($requirement as $modules => $level) {
        $met = false;
        foreach (explode('|', $modules) as $module) {
            if (itflow_modal_level($module) >= intval($level)) {
                $met = true;
                break;
            }
        }
        if (!$met) {
            return false;
        }
    }
    return true;
}

/** Plain-language "view access to Assets or Tickets, assets & docs" for a denial message ('it_agent' is not named). */
function itflow_modal_requirement_text(array $requirement): string {
    $parts = [];
    foreach ($requirement as $modules => $level) {
        $names = [];
        foreach (explode('|', $modules) as $module) {
            if ($module !== 'it_agent') {
                $names[] = itflow_module_label($module);   // includes/module_access.php
            }
        }
        $parts[] = itflow_level_label(intval($level) >= 2 ? 2 : 1) . ' access to ' . implode(' or ', $names);
    }
    return implode(' and ', $parts);
}

/**
 * For pages: true when the signed-in role may open the pop-up agent/modals/$rel ("ticket/ticket_add.php"), so a
 * page can hide a button this map would answer with 403. Same rule as itflow_modal_check().
 */
function itflow_modal_allowed(string $rel): bool {
    $requirement = itflow_modal_requirement('/agent/modals/' . ltrim($rel, '/'));
    return $requirement === null || itflow_modal_requirement_met($requirement);
}

/**
 * modal_header.php calls this for every pop-up. Denied: HTTP 403 with {"ok":false,"error":...} through the core
 * denial helper itflow_render_denied() (includes/module_access.php): same shape and status as every other
 * agent-side denial, no flash message left behind for the next page, nothing else sent.
 */
function itflow_modal_check(string $script_name): void {
    $requirement = itflow_modal_requirement($script_name);
    if ($requirement === null || itflow_modal_requirement_met($requirement)) {
        return;
    }
    itflow_render_denied(
        'Your role needs ' . itflow_modal_requirement_text($requirement) . '. Ask an administrator if you need it.',
        "You don't have access to this"
    );   // exits (JSON 403 for every /modals/ path)
}
