<?php

namespace ITFlow\Workflow;

/**
 * Built-in checklist presets: small groups of tasks most onboarding / offboarding / role-change templates want ("notify the manager",
 * "phone extension", "building access"...). An administrator adds a preset to any template of a matching type, or installs a starter
 * template per type. Adding is idempotent (a task with the same title is never added twice) and goes through TemplateTaskService, so
 * every preset task is validated exactly like one typed in by hand. Presets that use automated actions only use actions that are safe by
 * default: mail, notifications, device assignment, and the Entra/Odoo/connector actions are NOT in any preset.
 */
final class LifecyclePresets
{
    /**
     * key => [label, description, types, tasks[]]; a task: key, title, + TemplateTaskService input, + after (keys of tasks it waits for).
     * @return array<string,array{label:string,description:string,types:list<string>,tasks:list<array<string,mixed>>}>
     */
    public static function all(): array
    {
        return [
            'notify_manager' => [
                'label' => 'Notify the manager',
                'description' => 'Emails the manager when the checklist starts, and (onboarding) asks them to confirm the first-day plan.',
                'types' => ['onboarding', 'offboarding', 'role_change', 'transfer'],
                'tasks' => [[
                    'key' => 'notify_manager', 'title' => 'Notify the manager', 'category' => 'Communication', 'default_owner' => 'IT', 'task_type' => 'action',
                    'action_type' => 'send_mail',
                    'action_config' => ['to' => 'manager', 'subject' => '{{template_name}}: {{employee_name}}', 'body' => '<p>{{employee_name}} ({{employee_title}}, {{department}}) has a lifecycle checklist in progress: {{template_name}}.</p><p>Start date: {{start_date}}. End date: {{end_date}}.</p><p>IT will contact you if anything is needed from you.</p>'],
                ]],
            ],
            'phone_extension' => [
                'label' => 'Phone extension',
                'description' => 'Assign (or free up) a phone extension and voicemail.',
                'types' => ['onboarding', 'offboarding', 'transfer'],
                'tasks' => [[
                    'key' => 'phone_extension', 'title' => 'Phone extension and voicemail', 'category' => 'Telephony', 'default_owner' => 'IT', 'required' => 0,
                    'instructions' => 'Onboarding or transfer: assign an extension and set up voicemail. Offboarding: forward the line and free the extension.',
                    'task_type' => 'manual',
                ]],
            ],
            'building_access' => [
                'label' => 'Building access',
                'description' => 'Manager approves the access level, then facilities issues (or revokes) the badge and alarm code.',
                'types' => ['onboarding', 'offboarding', 'transfer'],
                'tasks' => [
                    ['key' => 'access_ok', 'title' => 'Manager approves building access', 'category' => 'Facilities', 'task_type' => 'approval', 'approver_type' => 'manager', 'due_offset_days' => -3, 'due_anchor' => 'start'],
                    ['key' => 'access_badge', 'title' => 'Issue or revoke the access badge and alarm code', 'category' => 'Facilities', 'default_owner' => 'Facilities', 'task_type' => 'manual', 'after' => ['access_ok'], 'due_offset_days' => -1, 'due_anchor' => 'start'],
                ],
            ],
            'laptop_assign' => [
                'label' => 'Assign a laptop from stock',
                'description' => 'Takes a laptop with status "Ready to Deploy" from the person\'s department and assigns it. If none is in stock the task becomes a manual task.',
                'types' => ['onboarding', 'transfer'],
                'tasks' => [[
                    'key' => 'laptop_assign', 'title' => 'Assign a laptop', 'category' => 'Equipment', 'default_owner' => 'IT', 'task_type' => 'action',
                    'action_type' => 'assign_asset', 'action_config' => ['asset_type' => 'Laptop', 'from_status' => 'Ready to Deploy', 'to_status' => 'Deployed'],
                ]],
            ],
            'collect_equipment' => [
                'label' => 'Collect equipment',
                'description' => 'Takes back every device assigned to the person and returns it to stock.',
                'types' => ['offboarding'],
                'tasks' => [[
                    'key' => 'collect_equipment', 'title' => 'Collect equipment', 'category' => 'Equipment', 'default_owner' => 'IT', 'task_type' => 'action',
                    'action_type' => 'collect_assets', 'action_config' => ['to_status' => 'Ready to Deploy'], 'due_offset_days' => 0, 'due_anchor' => 'end',
                ]],
            ],
            'delayed_cleanup' => [
                'label' => 'Archive the record 30 days after the end date',
                'description' => 'A scheduled task: 30 days after the end date the person\'s record and portal login are archived (nothing is deleted).',
                'types' => ['offboarding'],
                'tasks' => [[
                    'key' => 'delayed_cleanup', 'title' => 'Archive the account 30 days after the end date', 'category' => 'Clean-up', 'default_owner' => 'IT', 'required' => 0, 'task_type' => 'action',
                    'action_type' => 'archive_contact', 'action_config' => [], 'delay_days' => 30, 'due_anchor' => 'end',
                ]],
            ],
        ];
    }

    /** @return array<string,array{label:string,description:string,types:list<string>}> presets usable in a template of this type */
    public static function forType(string $type): array
    {
        $out = [];
        foreach (self::all() as $key => $p) {
            if (in_array($type, $p['types'], true)) {
                $out[$key] = ['label' => $p['label'], 'description' => $p['description'], 'types' => $p['types']];
            }
        }

        return $out;
    }

    /**
     * Adds a preset's tasks to a template. @return array{added:int,skipped:int}
     * @throws \InvalidArgumentException for an unknown preset or one that does not fit the template's type
     */
    public static function addToTemplate(\mysqli $mysqli, int $templateId, string $presetKey): array
    {
        $all = self::all();
        if (!isset($all[$presetKey])) {
            throw new \InvalidArgumentException('Unknown preset.');
        }
        $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT type FROM workflow_templates WHERE workflow_template_id = $templateId"));
        if (!$t) {
            throw new \InvalidArgumentException('Template not found.');
        }
        if (!in_array($t['type'], $all[$presetKey]['types'], true)) {
            throw new \InvalidArgumentException('"' . $all[$presetKey]['label'] . '" does not fit a ' . $t['type'] . ' template.');
        }
        $svc = new TemplateTaskService($mysqli);
        $added = 0;
        $skipped = 0;
        $ids = [];
        foreach ($all[$presetKey]['tasks'] as $task) {
            $stmt = mysqli_prepare($mysqli, 'SELECT template_task_id FROM workflow_template_tasks WHERE workflow_template_id = ? AND title = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'is', $templateId, $task['title']);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($existing) {
                $ids[$task['key']] = (int) $existing[0];
                $skipped++;
                continue;
            }
            $input = $task;
            unset($input['key'], $input['after']);
            $input['depends_on'] = array_values(array_filter(array_map(static fn ($k) => $ids[$k] ?? 0, (array) ($task['after'] ?? []))));
            $input['required'] = $task['required'] ?? 1;
            $ids[$task['key']] = $svc->add($templateId, $input);
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Starter templates, one per type, built from presets. */
    public const STARTERS = [
        'starter_onboarding' => ['name' => 'Starter: new employee', 'type' => 'onboarding', 'presets' => ['notify_manager', 'building_access', 'phone_extension', 'laptop_assign']],
        'starter_offboarding' => ['name' => 'Starter: leaver', 'type' => 'offboarding', 'presets' => ['notify_manager', 'collect_equipment', 'building_access', 'phone_extension', 'delayed_cleanup']],
        'starter_role_change' => ['name' => 'Starter: role change', 'type' => 'role_change', 'presets' => ['notify_manager']],
        'starter_transfer' => ['name' => 'Starter: department transfer', 'type' => 'transfer', 'presets' => ['notify_manager', 'building_access', 'phone_extension', 'laptop_assign']],
    ];

    /** Creates the starter template (once; a second call returns the existing one). @return array{template_id:int,created:bool} */
    public static function installStarter(\mysqli $mysqli, string $key, ?int $createdBy): array
    {
        $def = self::STARTERS[$key] ?? null;
        if ($def === null) {
            throw new \InvalidArgumentException('Unknown starter template.');
        }
        $stmt = mysqli_prepare($mysqli, 'SELECT workflow_template_id FROM workflow_templates WHERE preset_key = ? AND archived_at IS NULL LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            return ['template_id' => (int) $row[0], 'created' => false];
        }
        // Inactive: nothing starts from it until an administrator has read it and switched it on.
        $stmt = mysqli_prepare($mysqli, "INSERT INTO workflow_templates (name, type, description, is_active, created_by, preset_key) VALUES (?, ?, 'Built-in starter. Review the tasks, then switch it on.', 0, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssis', $def['name'], $def['type'], $createdBy, $key);
        mysqli_stmt_execute($stmt);
        $id = (int) mysqli_insert_id($mysqli);
        mysqli_stmt_close($stmt);
        foreach ($def['presets'] as $p) {
            self::addToTemplate($mysqli, $id, $p);
        }

        return ['template_id' => $id, 'created' => true];
    }
}
