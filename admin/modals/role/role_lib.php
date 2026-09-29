<?php

/*
 * Role editor helpers, shared by admin/modals/role/role_add.php + role_edit.php (the form),
 * admin/roles.php (which rows are protected) and admin/post/roles.php (the server-side guard).
 *
 *  - itflow_role_catalog():          plain labels, IT / Business / Training groups and per-level
 *                                    help for every permission module (audit F14, plan P3).
 *  - itflow_role_other_admin_roles(): how many OTHER administrator roles still have an active user;
 *                                    0 means this is the last one, so it can't lose admin access or
 *                                    be archived (replaces the old literal "role id 3" protection).
 *  - itflow_role_form_render():      the Details + Permissions tabs, the "Start from…" presets and
 *                                    the "this role will see" sidebar preview (js/role_editor.js).
 *  - itflow_role_posted_levels():    the form's levels, checked against the modules table, 0-3.
 *  - itflow_role_access_help_render(): the user form's Access-tab note on department ticks (F13).
 *  - itflow_role_summary():          the one-line permission summary on admin/roles.php.
 *
 * Read-only: nothing here writes to the database.
 */

// An include, never a page of its own (admin/modals/*.php is reachable over HTTP).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('itflow_role_catalog')) {

    /**
     * Every known permission module, in display order. A module the database has but this list
     * doesn't know (a future migration) still renders, under "Other", with its own description.
     * 'flag' modules are only ever checked for "any level" in the code, so they show as Off / On.
     * 'setting' names the Settings › Modules switch that has to be on for the module to show up.
     *
     * @return array<string, array{group:string, label:string, about:string, levels:array<int,string>, flag?:bool, sub?:bool, setting?:string}>
     */
    function itflow_role_catalog(): array
    {
        return [
            // ---- IT ----
            'module_client' => [
                'group' => 'it',
                'label' => 'Departments',
                'about' => 'Departments, contacts, the org chart and the People directory.',
                'levels' => [
                    0 => 'No Organization or People menus.',
                    1 => 'View departments, contacts and locations.',
                    2 => 'Also add, edit and archive them.',
                    3 => 'Also delete them.',
                ],
            ],
            'module_support' => [
                'group' => 'it',
                'label' => 'Tickets, assets & docs',
                'about' => 'Service Desk, projects, the IT calendar, assets and IT documentation.',
                'levels' => [
                    0 => 'No Service Desk, Work, Infrastructure or IT documentation.',
                    1 => 'View tickets (including internal replies), projects, the calendar, assets and documents.',
                    2 => 'Also create and edit tickets, projects, assets and documents.',
                    3 => 'Also delete them.',
                ],
            ],
            'module_assets' => [
                'group' => 'it',
                'label' => 'Assets',
                'about' => 'Asset pages on their own, without tickets. "Tickets, assets & docs" already includes assets.',
                'levels' => [
                    0 => 'No asset pages (unless "Tickets, assets & docs" is granted).',
                    1 => 'View assets.',
                    2 => 'Also add and edit assets.',
                    3 => 'Also delete assets.',
                ],
            ],
            'module_credential' => [
                'group' => 'it',
                'label' => 'Credentials',
                'about' => 'Usernames, passwords and 2FA codes. Only shows up together with "Tickets, assets & docs".',
                'levels' => [
                    0 => 'Passwords and 2FA codes stay hidden.',
                    1 => 'View and copy passwords and 2FA codes.',
                    2 => 'Also add and edit credentials.',
                    3 => 'Also delete credentials.',
                ],
            ],
            'module_rmm' => [
                'group' => 'it',
                'label' => 'RMM devices',
                'about' => 'Monitoring dashboards and managed devices.',
                'setting' => 'rmm',
                'levels' => [
                    0 => 'No Endpoints menu.',
                    1 => 'View the RMM dashboard, devices, checks and the network page.',
                    2 => 'Also edit checks and run patch scans and installs.',
                    3 => 'Same as Modify (nothing extra).',
                ],
            ],
            'module_rmm_alerts' => [
                'group' => 'it',
                'label' => 'RMM alerts',
                'about' => 'The Alerts page and alert counts.',
                'flag' => true,
                'sub' => true,
                'levels' => [
                    0 => 'No Alerts page.',
                    1 => 'See RMM alerts.',
                ],
            ],
            'module_rmm_alerts_ack' => [
                'group' => 'it',
                'label' => 'Acknowledge RMM alerts',
                'about' => 'Acknowledge and resolve alerts.',
                'flag' => true,
                'sub' => true,
                'levels' => [
                    0 => 'Alerts are view-only.',
                    1 => 'Acknowledge and resolve alerts.',
                ],
            ],
            'module_rmm_scripts' => [
                'group' => 'it',
                'label' => 'RMM scripts',
                'about' => 'The script library and running scripts on managed devices.',
                'sub' => true,
                'levels' => [
                    0 => 'No scripts.',
                    1 => 'View the script library.',
                    2 => 'Also run scripts on devices and add or edit scripts.',
                    3 => 'Also delete scripts.',
                ],
            ],
            'module_rmm_sync' => [
                'group' => 'it',
                'label' => 'RMM sync',
                'about' => 'The "Sync now" buttons for RMM and network integrations.',
                'flag' => true,
                'sub' => true,
                'levels' => [
                    0 => 'Can\'t start a sync.',
                    1 => 'Start RMM and network syncs.',
                ],
            ],
            'module_rmm_remote_connect' => [
                'group' => 'it',
                'label' => 'RMM remote connect',
                'about' => 'Remote sessions, reboots and commands on managed devices.',
                'flag' => true,
                'sub' => true,
                'levels' => [
                    0 => 'No remote sessions, reboots or commands.',
                    1 => 'Open remote sessions, reboot devices and run commands.',
                ],
            ],

            // ---- Business ----
            'module_sales' => [
                'group' => 'business',
                'label' => 'Sales',
                'about' => 'Quotes, invoices and products (and CRM when it is turned on).',
                'levels' => [
                    0 => 'No quotes, invoices or products.',
                    1 => 'View quotes, invoices and products.',
                    2 => 'Also create and edit them.',
                    3 => 'Also delete them.',
                ],
            ],
            'module_financial' => [
                'group' => 'business',
                'label' => 'Financial',
                'about' => 'Payments, expenses, accounts and budgets.',
                'setting' => 'accounting',
                'levels' => [
                    0 => 'No Finance pages.',
                    1 => 'View payments, expenses and accounts.',
                    2 => 'Also add and edit them.',
                    3 => 'Also delete them.',
                ],
            ],
            'module_reporting' => [
                'group' => 'business',
                'label' => 'Reporting',
                'about' => 'The Reports menu (every report).',
                'flag' => true,
                'levels' => [
                    0 => 'No Reports menu.',
                    1 => 'Open every report.',
                ],
            ],
            'module_kb' => [
                'group' => 'business',
                'label' => 'Knowledge base',
                'about' => 'Knowledge base articles.',
                'setting' => 'kb',
                'levels' => [
                    0 => 'No knowledge base.',
                    1 => 'Read articles.',
                    2 => 'Also write and edit articles.',
                    3 => 'Also delete articles and categories.',
                ],
            ],

            // ---- Training ----
            'module_training' => [
                'group' => 'training',
                'label' => 'Training',
                'about' => 'Courses, assignments, records and reports.',
                'setting' => 'training',
                'levels' => [
                    0 => 'No Training menu. (Signing in at a kiosk with a PIN doesn\'t need this.)',
                    1 => 'View published courses, paths, assignments, records and reports, for people in the departments ticked on the user\'s Access tab. No ticks = nobody.',
                    2 => 'Also write courses, quizzes and question banks, assign training, award badges and unlock locked courses. Still only the ticked departments.',
                    3 => 'Everything in Training, for every department (Access-tab ticks are ignored): trainers, groups, auto-assign rules, voiding records and Training settings.',
                ],
            ],
            'module_training_kiosk' => [
                'group' => 'training',
                'label' => 'Training kiosk',
                'about' => 'Kiosk devices and learner PINs. PIN slips show learners\' PINs, so grant Modify or Full with care.',
                'setting' => 'training',
                'levels' => [
                    0 => 'No Devices & PINs page.',
                    1 => 'See kiosk devices and people\'s PIN status.',
                    2 => 'Also unlock PINs, print PIN slips and clear kiosk cooldowns.',
                    3 => 'Also set up, reissue and revoke kiosk devices. Picking the device from Assets also needs Assets (Read); without it, devices are set up as "not in Assets".',
                ],
            ],
        ];
    }

    /** @return array<string, string> group key => heading, in display order */
    function itflow_role_groups(): array
    {
        return [
            'it' => 'IT',
            'business' => 'Business',
            'training' => 'Training',
            'other' => 'Other',
        ];
    }

    /**
     * How many administrator roles OTHER than $role_id are still in use: not archived, and holding
     * at least one active, enabled agent. 0 means $role_id is the last one, so it must keep admin
     * access and can't be archived (otherwise nobody could reach Admin again).
     */
    function itflow_role_other_admin_roles(mysqli $db, int $role_id): int
    {
        $row = mysqli_fetch_row(mysqli_query(
            $db,
            "SELECT COUNT(DISTINCT r.role_id)
               FROM user_roles r
               JOIN users u ON u.user_role_id = r.role_id
              WHERE r.role_is_admin = 1
                AND r.role_archived_at IS NULL
                AND r.role_id <> " . intval($role_id) . "
                AND u.user_archived_at IS NULL
                AND u.user_status = 1
                AND u.user_type = 1"
        ));
        return intval($row[0] ?? 0);
    }

    /**
     * The module levels posted by the role form ("<module_id>##<module_name>" => level), checked
     * against the modules table and clamped to 0-3. One level per module.
     *
     * @return array<int, int> module_id => level (levels above 0 only)
     */
    function itflow_role_posted_levels(mysqli $db, array $post): array
    {
        $valid = [];
        $sql = mysqli_query($db, "SELECT module_id FROM modules");
        while ($row = mysqli_fetch_assoc($sql)) {
            $valid[intval($row['module_id'])] = true;
        }
        $levels = [];
        foreach ($post as $key => $value) {
            if (!is_string($key) || !str_contains($key, '##module_') || is_array($value)) {
                continue;
            }
            $module_id = intval(explode('##', $key)[0]);
            $level = max(0, min(3, intval($value)));
            if (isset($valid[$module_id]) && $level > 0) {
                $levels[$module_id] = $level;
            }
        }
        return $levels;
    }

    /** module_name => level for one role (only rows above 0). */
    function itflow_role_levels(mysqli $db, int $role_id): array
    {
        $levels = [];
        $sql = mysqli_query(
            $db,
            "SELECT m.module_name, p.user_role_permission_level
               FROM user_role_permissions p
               JOIN modules m ON m.module_id = p.module_id
              WHERE p.user_role_id = " . intval($role_id)
        );
        while ($row = mysqli_fetch_assoc($sql)) {
            $level = max(0, min(3, intval($row['user_role_permission_level'])));
            if ($level > 0) {
                $levels[$row['module_name']] = max($levels[$row['module_name']] ?? 0, $level);
            }
        }
        return $levels;
    }

    /**
     * One line for the Roles list: "Training Full · Training kiosk Full · Assets Modify" (plain labels, the role
     * editor's level names, catalog order; modules the catalog doesn't know go last). Plain text: escape it.
     */
    function itflow_role_summary(array $levels, bool $is_admin): string
    {
        if ($is_admin) {
            return 'Administrator: everything';
        }
        $catalog = itflow_role_catalog();
        $parts = [];
        foreach ($catalog as $module => $meta) {
            $level = intval($levels[$module] ?? 0);
            if ($level > 0) {
                $parts[] = $meta['label'] . (!empty($meta['flag']) ? '' : ' ' . ([1 => 'Read', 2 => 'Modify', 3 => 'Full'][min(3, $level)]));
            }
        }
        foreach ($levels as $module => $level) {
            if (!isset($catalog[$module]) && intval($level) > 0) {
                $parts[] = ucfirst(str_replace('_', ' ', preg_replace('/^module_/', '', (string) $module))) . ' ' . ([1 => 'Read', 2 => 'Modify', 3 => 'Full'][min(3, intval($level))]);
            }
        }
        return $parts ? implode(' · ', $parts) : 'No permissions';
    }

    /**
     * The "Start from…" presets (filled in the browser; nothing is stored). "Technician" copies
     * the current Technician role, so it follows whatever that role holds today.
     */
    function itflow_role_presets(mysqli $db): array
    {
        $tech_levels = null;
        $tech_row = mysqli_fetch_assoc(mysqli_query(
            $db,
            "SELECT role_id FROM user_roles
              WHERE role_name = 'Technician' AND role_is_admin = 0 AND role_archived_at IS NULL
              ORDER BY role_id ASC LIMIT 1"
        ));
        if ($tech_row) {
            $tech_levels = itflow_role_levels($db, intval($tech_row['role_id']));
        }
        $tech_note = 'The same permissions the Technician role has right now.';
        if ($tech_levels === null) {
            // No Technician role on this install: the standard technician levels.
            $tech_levels = ['module_client' => 2, 'module_support' => 2, 'module_assets' => 2, 'module_credential' => 2, 'module_sales' => 2];
            $tech_note = 'Standard technician permissions (there is no Technician role to copy).';
        }

        return [
            'training_manager' => [
                'label' => 'Training Manager',
                'name' => 'Training Manager',
                'description' => 'Runs Training for every department',
                'levels' => ['module_training' => 3, 'module_training_kiosk' => 3, 'module_kb' => 2],
                'note' => 'Training Full, Training kiosk Full and Knowledge Base Write (so they can edit the articles learners read in the Learning Center), nothing else. Sees every department in Training; Access-tab ticks don\'t narrow it. Devices are set up as "not in Assets" unless you also give Assets Read.',
            ],
            'training_supervisor' => [
                'label' => 'Training Supervisor (department)',
                'name' => 'Training Supervisor',
                'description' => 'Training for their own department',
                'levels' => ['module_training' => 2, 'module_training_kiosk' => 1],
                'note' => 'Training Modify and Training kiosk Read. After saving, tick each supervisor\'s departments on their Access tab (Admin › Users › Edit › Access). With no ticks they see nobody in Training.',
            ],
            'learner' => [
                'label' => 'Learner',
                'name' => 'Learner',
                'description' => 'Takes assigned training',
                'levels' => ['module_training' => 1],
                'note' => 'Training Read only. People and records are limited to the departments ticked on the user\'s Access tab (no ticks = nobody).',
            ],
            'technician' => [
                'label' => 'Technician',
                'name' => '',
                'description' => '',
                'levels' => $tech_levels,
                'note' => $tech_note,
            ],
            'none' => [
                'label' => 'Nothing (clear every permission)',
                'name' => '',
                'description' => '',
                'levels' => [],
                'note' => 'Every permission set to None.',
            ],
        ];
    }

    /** Settings the sidebar preview needs, from the globals load_global_settings.php set. */
    function itflow_role_preview_context(mysqli $db): array
    {
        $g = static function (string $name): int {
            return intval($GLOBALS[$name] ?? 0);
        };
        $links = [];
        $sql = mysqli_query($db, "SELECT custom_link_name FROM custom_links WHERE custom_link_location = 1 AND custom_link_archived_at IS NULL ORDER BY custom_link_order ASC, custom_link_name ASC");
        while ($sql && ($row = mysqli_fetch_assoc($sql))) {
            $links[] = (string) $row['custom_link_name'];
        }
        $start = basename((string) ($GLOBALS['config_start_page'] ?? 'dashboard.php'));
        $start_label = ucfirst(str_replace('_', ' ', preg_replace('/\.php$/', '', $start)));
        if ($start === 'clients.php') {
            $start_label = 'Departments';
        }

        return [
            // Once the module-access layer (includes/module_access.php) is in, logins without
            // Departments / Tickets / Assets get a trimmed sidebar; before it, everyone gets the
            // Dashboard and Work › Calendar.
            'scoped' => is_file(dirname(__DIR__, 3) . '/includes/module_access.php'),
            'on' => [
                'ticketing' => $g('config_module_enable_ticketing') === 1,
                'csat' => !empty($GLOBALS['config_ticket_csat_enable']),
                'itdoc' => $g('config_module_enable_itdoc') === 1,
                'kb' => $g('config_module_enable_kb') === 1,
                'training' => $g('config_module_enable_training') === 1,
                'rmm' => $g('config_module_enable_rmm') === 1,
                'accounting' => $g('config_module_enable_accounting') === 1,
                'ticket_charges' => $g('config_module_enable_ticket_charges') === 1,
                'crm' => $g('config_module_enable_crm') === 1,
                'intune' => $g('config_module_enable_intune') === 1,
                'comet' => $g('config_comet_enabled') === 1,
            ],
            'start_page' => $start,
            'start_label' => $start_label,
            'custom_links' => $links,
        ];
    }

    /**
     * Renders the role form's tabs (Details + Permissions). The caller prints the modal header,
     * the <form> tag, the hidden csrf/role_id inputs and the footer buttons.
     *
     * @param array{id:int, name:string, description:string, is_admin:bool, levels:array<string,int>, locked_admin:bool, is_own_role:bool, members:int} $role
     *        raw (unescaped) values; id 0 = a new role
     */
    function itflow_role_form_render(mysqli $db, array $role): void
    {
        $h = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $is_new = intval($role['id']) === 0;
        $uid = $is_new ? 'new' : (string) intval($role['id']);
        $is_admin = !empty($role['is_admin']);
        $locked_admin = !empty($role['locked_admin']);
        $catalog = itflow_role_catalog();
        $groups = itflow_role_groups();
        $preview = itflow_role_preview_context($db);
        $settings_on = $preview['on'];
        $setting_names = ['rmm' => 'RMM', 'accounting' => 'Accounting', 'kb' => 'Knowledge base', 'training' => 'Training'];

        // modules table -> rows per group, catalog order first, unknown modules after
        $modules = [];
        $sql = mysqli_query($db, "SELECT module_id, module_name, module_description FROM modules ORDER BY module_id ASC");
        while ($row = mysqli_fetch_assoc($sql)) {
            $modules[$row['module_name']] = $row;
        }
        $order = array_merge(array_keys($catalog), array_keys($modules));
        $by_group = [];
        foreach (array_unique($order) as $name) {
            if (!isset($modules[$name])) {
                continue; // e.g. module_assets before its migration has run
            }
            $meta = $catalog[$name] ?? null;
            $group = $meta['group'] ?? 'other';
            $by_group[$group][] = [$modules[$name], $meta];
        }

        $config = [
            'presets' => itflow_role_presets($db),
            'preview' => $preview,
            'isNew' => $is_new,
            'isOwnRole' => !empty($role['is_own_role']),
            'wasAdmin' => $is_admin,
        ];
        ?>
        <div class="js-role-form" data-role-uid="<?= $h($uid) ?>" data-role-config="<?= $h(json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">

            <ul class="nav nav-pills nav-justified mb-3" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="pill" href="#role-details-<?= $h($uid) ?>">Details</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#role-permissions-<?= $h($uid) ?>">Permissions</a>
                </li>
            </ul>

            <hr>

            <div class="tab-content">

                <!-- DETAILS -->
                <div class="tab-pane fade show active" id="role-details-<?= $h($uid) ?>">

                    <div class="form-group">
                        <label for="role-name-<?= $h($uid) ?>">Name <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-user-shield"></i></span>
                            <input type="text" class="form-control js-role-name" id="role-name-<?= $h($uid) ?>" name="role_name" placeholder="Role name" maxlength="200" value="<?= $h($role['name']) ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="role-desc-<?= $h($uid) ?>">Description <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-chevron-right"></i></span>
                            <input type="text" class="form-control js-role-description" id="role-desc-<?= $h($uid) ?>" name="role_description" placeholder="What this role is for" maxlength="200" value="<?= $h($role['description']) ?>" required>
                        </div>
                    </div>

                    <div class="form-group mb-0" role="radiogroup" aria-labelledby="role-admin-label-<?= $h($uid) ?>">
                        <label id="role-admin-label-<?= $h($uid) ?>">Admin access <strong class="text-danger">*</strong></label>
                        <div class="form-check mb-2">
                            <input type="radio" class="form-check-input js-role-admin" id="role-admin-no-<?= $h($uid) ?>" name="role_is_admin" value="0" <?= $is_admin ? '' : 'checked' ?> <?= $locked_admin ? 'disabled' : '' ?> required>
                            <label class="form-check-label" for="role-admin-no-<?= $h($uid) ?>">
                                No - use the permissions on the next tab
                            </label>
                        </div>
                        <div class="form-check">
                            <input type="radio" class="form-check-input js-role-admin" id="role-admin-yes-<?= $h($uid) ?>" name="role_is_admin" value="1" <?= $is_admin ? 'checked' : '' ?> required>
                            <label class="form-check-label" for="role-admin-yes-<?= $h($uid) ?>">
                                Yes - full access to everything, including Admin settings
                            </label>
                        </div>
                        <?php if ($locked_admin) { ?>
                            <div class="form-text text-secondary mt-2">
                                <i class="fas fa-fw fa-lock me-1"></i>This is the only administrator role with an active user, so it has to stay an administrator. Give another role admin access (and a user) first.
                            </div>
                        <?php } ?>
                        <div class="alert alert-warning py-2 px-3 small mt-3 mb-0 js-role-self-warning" hidden>
                            <i class="fas fa-exclamation-triangle me-1"></i>You are in this role. Saving it without admin access takes away your own access to Admin.
                        </div>
                    </div>

                    <?php if ($is_new) { ?>
                        <p class="small text-secondary mt-3 mb-0">
                            <i class="fas fa-fw fa-magic me-1"></i>Setting up a Training role? The Permissions tab can
                            <a href="#role-permissions-<?= $h($uid) ?>" class="js-role-goto-permissions">start from a preset</a>
                            such as Training Manager.
                        </p>
                    <?php } ?>

                </div>

                <!-- PERMISSIONS -->
                <div class="tab-pane fade" id="role-permissions-<?= $h($uid) ?>">

                    <div class="alert alert-info py-2 px-3 small js-role-admin-note" <?= $is_admin ? '' : 'hidden' ?>>
                        <i class="fas fa-info-circle me-1"></i>Administrators always have full access, so these permissions don't apply. Choose "No" under Admin access to use them.
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-7 js-role-perms">

                            <div class="border rounded p-2 mb-3 role-preset-box">
                                <label class="form-label mb-1" for="role-preset-<?= $h($uid) ?>"><i class="fas fa-fw fa-magic me-1"></i>Start from…</label>
                                <select class="form-select form-select-sm js-role-preset" id="role-preset-<?= $h($uid) ?>" aria-describedby="role-preset-status-<?= $h($uid) ?>">
                                    <option value="">Choose a preset…</option>
                                    <?php foreach ($config['presets'] as $key => $preset) { ?>
                                        <option value="<?= $h($key) ?>"><?= $h($preset['label']) ?></option>
                                    <?php } ?>
                                </select>
                                <div class="small text-secondary mt-1 js-role-preset-status" id="role-preset-status-<?= $h($uid) ?>" aria-live="polite">Fills the permissions in this form only. Nothing is saved until you click <?= $is_new ? 'Create' : 'Save' ?>.</div>
                            </div>

                            <?php foreach ($groups as $group_key => $group_label) {
                                if (empty($by_group[$group_key])) {
                                    continue;
                                }
                                $sub_rows = [];
                                ?>
                                <h6 class="text-uppercase text-secondary fw-bold small mt-3 mb-2 role-perm-group"><?= $h($group_label) ?></h6>
                                <?php
                                foreach ($by_group[$group_key] as [$module, $meta]) {
                                    if (!empty($meta['sub'])) {
                                        $sub_rows[] = [$module, $meta];
                                        continue;
                                    }
                                    itflow_role_perm_row($module, $meta, $role['levels'], $uid, $settings_on, $setting_names);
                                }
                                if ($sub_rows) {
                                    $sub_open = false;
                                    foreach ($sub_rows as [$module, $meta]) {
                                        if (intval($role['levels'][$module['module_name']] ?? 0) > 0) {
                                            $sub_open = true;
                                        }
                                    }
                                    ?>
                                    <details class="mb-2 role-perm-more" <?= $sub_open ? 'open' : '' ?>>
                                        <summary class="small fw-bold mb-2">More RMM permissions (<?= count($sub_rows) ?>)</summary>
                                        <?php foreach ($sub_rows as [$module, $meta]) {
                                            itflow_role_perm_row($module, $meta, $role['levels'], $uid, $settings_on, $setting_names);
                                        } ?>
                                    </details>
                                    <?php
                                }
                            } ?>

                        </div>

                        <div class="col-lg-5">
                            <div class="border rounded p-3 role-preview" aria-live="polite">
                                <div class="fw-bold mb-1"><i class="fas fa-fw fa-eye me-1"></i>This role will see</div>
                                <div class="small text-secondary mb-2">The sidebar for someone in this role.</div>
                                <div class="js-role-preview"><span class="small text-secondary">The preview needs JavaScript.</span></div>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        <style>
            .role-perm-row { padding: .5rem 0; border-bottom: 1px solid var(--tblr-border-color, rgba(0,0,0,.08)); }
            .role-perm-row:last-child { border-bottom: 0; }
            .role-perm-row .btn-group { width: 100%; }
            .role-perm-row .btn-group .btn { flex: 1 1 0; }
            .role-perm-row .btn:has(input:focus-visible) { outline: 2px solid var(--tblr-primary, #0d6efd); outline-offset: 2px; z-index: 2; }
            .role-perm-row .role-perm-help { min-height: 1.25rem; }
            .role-perm-more > summary { cursor: pointer; }
            .role-perms-disabled { opacity: .5; }
            /* css/itflow_design.css's dark-mode label colour for .btn-outline-warning outranks its own
               .active rule, so a chosen "Modify" would be orange text on an orange fill. */
            :root[data-bs-theme="dark"] .role-perm-row .btn-outline-warning.active,
            body.dark-mode .role-perm-row .btn-outline-warning.active { color: #fff; }
            .role-preview { position: sticky; top: .5rem; }
            .role-preview ul { padding-left: 1.1rem; margin-bottom: .35rem; }
            .role-preview .role-preview-section { font-weight: 600; }
        </style>

        <script src="/js/role_editor.js?v=1"></script>
        <?php
    }

    /**
     * The Access tab's explanation of department ticks (admin/modals/user/user_add.php and
     * user_edit.php). Ticks mean different things in Training (audit F13): app-wide, no ticks =
     * every department; Training Read / Modify, no ticks = nobody; Training Full and admins
     * ignore ticks. js/role_editor.js adds a line for the role picked on the Details tab.
     */
    function itflow_role_access_help_render(mysqli $db, string $role_select_id): void
    {
        $roles = [];
        $sql = mysqli_query(
            $db,
            "SELECT r.role_id, r.role_name, r.role_is_admin,
                    COALESCE(MAX(CASE WHEN m.module_name = 'module_training' THEN p.user_role_permission_level END), 0) AS training,
                    COALESCE(MAX(CASE WHEN m.module_name IN ('module_client', 'module_support', 'module_assets') THEN p.user_role_permission_level END), 0) AS it
               FROM user_roles r
               LEFT JOIN user_role_permissions p ON p.user_role_id = r.role_id
               LEFT JOIN modules m ON m.module_id = p.module_id
              WHERE r.role_archived_at IS NULL
              GROUP BY r.role_id, r.role_name, r.role_is_admin"
        );
        while ($row = mysqli_fetch_assoc($sql)) {
            $roles[intval($row['role_id'])] = [
                'name' => (string) $row['role_name'],
                'admin' => intval($row['role_is_admin']) === 1,
                'training' => max(0, min(3, intval($row['training']))),
                // Departments, Tickets/assets/docs or Assets: 0 = the role has none of the pages ticks narrow.
                'it' => max(0, min(3, intval($row['it']))),
            ];
        }
        $training_on = intval($GLOBALS['config_module_enable_training'] ?? 0) === 1;
        $h = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        ?>
        <div class="alert alert-info py-2 px-3 small js-user-access-help"
             data-roles="<?= $h(json_encode($roles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
             data-role-select="<?= $h($role_select_id) ?>"
             data-training-on="<?= $training_on ? '1' : '0' ?>">
            <div class="fw-bold mb-1">Tick the departments this person works with.</div>
            <ul class="mb-0 ps-3">
                <li>Most pages (tickets, contacts, assets, documents): only the ticked departments. No ticks = every department.</li>
                <?php if ($training_on) { ?>
                    <li>Training with Read or Modify: only people in the ticked departments. No ticks = nobody.</li>
                    <li>Training with Full, and administrators: every department. Ticks don't apply.</li>
                <?php } else { ?>
                    <li>Administrators: every department. Ticks don't apply.</li>
                <?php } ?>
            </ul>
            <div class="js-user-access-role" hidden></div>
        </div>
        <script src="/js/role_editor.js?v=1"></script>
        <?php
    }

    /** One module's row: label, what it covers, the level buttons and the help for the chosen level. */
    function itflow_role_perm_row(array $module, ?array $meta, array $levels, string $uid, array $settings_on, array $setting_names): void
    {
        $h = static function ($v): string {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $module_id = intval($module['module_id']);
        $module_name = (string) $module['module_name'];
        $current = max(0, min(3, intval($levels[$module_name] ?? 0)));
        $flag = !empty($meta['flag']);
        $label = $meta['label'] ?? ucfirst(str_replace('_', ' ', preg_replace('/^module_/', '', $module_name)));
        $about = $meta['about'] ?? (string) $module['module_description'];
        $help = $meta['levels'] ?? [0 => 'No access.', 1 => 'View only.', 2 => 'View, add, edit and archive.', 3 => 'View, add, edit, archive and delete.'];
        $field = $module_id . '##' . $module_name;
        $base_id = 'perm-' . $uid . '-' . $module_id;
        $off_setting = null;
        if (!empty($meta['setting']) && empty($settings_on[$meta['setting']])) {
            $off_setting = $setting_names[$meta['setting']] ?? $meta['setting'];
        }

        if ($flag) {
            // Only "any level" is ever checked for these, so Off / On. "On" keeps a stored 2 or 3
            // as it is, so saving an untouched role writes back exactly what it had.
            $buttons = [
                [0, 'Off', 'btn-outline-secondary', 'fa-ban'],
                [$current > 0 ? $current : 1, 'On', 'btn-outline-primary', 'fa-check'],
            ];
            $help_js = [0 => $help[0], 1 => $help[1], 2 => $help[1], 3 => $help[1]];
        } else {
            $buttons = [
                [0, 'None', 'btn-outline-secondary', 'fa-ban'],
                [1, 'Read', 'btn-outline-primary', 'fa-eye'],
                [2, 'Modify', 'btn-outline-warning', 'fa-edit'],
                [3, 'Full', 'btn-outline-danger', 'fa-trash'],
            ];
            $help_js = $help;
        }
        $level_names = $flag ? [0 => 'Off', 1 => 'On', 2 => 'On', 3 => 'On'] : [0 => 'None', 1 => 'Read', 2 => 'Modify', 3 => 'Full'];
        ?>
        <div class="role-perm-row js-role-perm" data-module="<?= $h($module_name) ?>" data-flag="<?= $flag ? '1' : '0' ?>" data-help="<?= $h(json_encode($help_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>" data-level-names="<?= $h(json_encode($level_names)) ?>">
            <div class="d-flex flex-wrap align-items-baseline gap-2">
                <span class="fw-bold" id="<?= $h($base_id) ?>-label"><?= $h($label) ?></span>
                <?php if ($off_setting !== null) { ?>
                    <span class="badge bg-secondary-lt text-secondary" title="Turned off in Admin › Settings › Modules">
                        <?= $h($off_setting) ?> is off
                    </span>
                <?php } ?>
            </div>
            <div class="small text-secondary mb-1"><?= $h($about) ?></div>
            <div class="btn-group js-btn-group-toggle" role="radiogroup" aria-labelledby="<?= $h($base_id) ?>-label">
                <?php foreach ($buttons as $i => [$value, $text, $class, $icon]) {
                    $checked = $flag ? (($value > 0) === ($current > 0)) : ($value === $current);
                    ?>
                    <label class="btn <?= $h($class) ?> btn-sm <?= $checked ? 'active' : '' ?>">
                        <input type="radio" name="<?= $h($field) ?>" id="<?= $h($base_id . '-' . $i) ?>" value="<?= intval($value) ?>" autocomplete="off" <?= $checked ? 'checked' : '' ?>>
                        <i class="fas fa-fw <?= $h($icon) ?> me-1" aria-hidden="true"></i><?= $h($text) ?>
                    </label>
                <?php } ?>
            </div>
            <div class="small mt-1 role-perm-help js-role-perm-help"><span class="fw-bold"><?= $h($level_names[$current]) ?>:</span> <?= $h($help_js[$current] ?? '') ?></div>
        </div>
        <?php
    }
}
