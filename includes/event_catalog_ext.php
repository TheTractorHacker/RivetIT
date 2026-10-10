<?php

/*
 * The event list every picker shows: RivetCore's EventCatalog (searchable, grouped, described) plus the few events THIS
 * edition emits that the Core catalog does not list (it names them differently or reserves them as "planned"). Used by the
 * shared event picker (includes/event_picker.php, js/event_picker.js) and by the webhook form's server-side validation, so what
 * can be chosen and what is accepted can never drift apart.
 */

if (!function_exists('rivetEventCatalogExtras')) {
    /** @return list<array{id:string,group:string,label:string,description:string,severity:string,tags:list<string>}> */
    function rivetEventCatalogExtras(): array
    {
        return [
            ['id' => 'ticket.updated', 'group' => 'tickets', 'label' => 'Ticket updated', 'description' => 'A ticket\'s details were edited.', 'severity' => 'info', 'tags' => ['edit', 'change']],
            ['id' => 'ticket.sla_warning', 'group' => 'tickets', 'label' => 'SLA warning', 'description' => 'A ticket is close to breaching its SLA.', 'severity' => 'warning', 'tags' => ['sla', 'deadline', 'due']],
            ['id' => 'ticket.sla_breached', 'group' => 'tickets', 'label' => 'SLA breached', 'description' => 'A ticket has passed its SLA deadline.', 'severity' => 'critical', 'tags' => ['sla', 'overdue', 'late']],
            ['id' => 'catalog.request_approved', 'group' => 'approvals', 'label' => 'Catalog request approved', 'description' => 'A service catalog request was approved.', 'severity' => 'info', 'tags' => ['service', 'request']],
            ['id' => 'backup.failed', 'group' => 'recovery', 'label' => 'Backup failed', 'description' => 'A backup run did not complete, or its off-site copy failed.', 'severity' => 'critical', 'tags' => ['backup', 'failure', 'recovery']],
            ['id' => 'backup.stale', 'group' => 'recovery', 'label' => 'Backup out of date', 'description' => 'The newest successful backup is older than the configured limit (26 hours by default).', 'severity' => 'critical', 'tags' => ['backup', 'overdue', 'recovery']],
            ['id' => 'restore_drill.failed', 'group' => 'recovery', 'label' => 'Restore drill failed', 'description' => 'The nightly restore drill could not prove the newest backup restores, or it stopped running.', 'severity' => 'critical', 'tags' => ['backup', 'restore', 'recovery']],
            ['id' => 'integration.sync_failed', 'group' => 'recovery', 'label' => 'Integration sync failing', 'description' => 'An RMM, Intune or UniFi sync has errored several runs in a row.', 'severity' => 'warning', 'tags' => ['sync', 'rmm', 'intune', 'unifi']],
            ['id' => 'integration.sync_stale', 'group' => 'recovery', 'label' => 'Integration sync stopped', 'description' => 'An RMM, Intune or UniFi sync has not run for three times its interval.', 'severity' => 'warning', 'tags' => ['sync', 'rmm', 'intune', 'unifi', 'overdue']],
            ['id' => 'audit.chain_broken', 'group' => 'recovery', 'label' => 'Audit trail tampering suspected', 'description' => 'The nightly check of the audit trail hash chain found an edited, removed or reordered row.', 'severity' => 'critical', 'tags' => ['audit', 'security', 'tamper']],
            ['id' => 'document.review_due', 'group' => 'docs', 'label' => 'Document review due', 'description' => 'A document has reached its review date.', 'severity' => 'info', 'tags' => ['document', 'review', 'due']],
            ['id' => 'asset.auto_retire', 'group' => 'assets', 'label' => 'Stale asset retired', 'description' => 'An asset whose RMM agent has been silent past the configured number of days was retired (when auto-retire is on).', 'severity' => 'info', 'tags' => ['asset', 'rmm', 'stale']],
            // RMM Phase 1 (RivetCore 1.0.0-rc.9): published by EndpointEvents (src/Core/Adapter/Endpoint) onto the event bus. RivetCore 1.0.0-rc.9's own catalog lists them too
            // (group "rmm"); they are named here as well so the picker, the webhook form and the event rules keep offering them if an older package is ever installed.
            ['id' => 'rmm.device.enrolled', 'group' => 'rmm', 'label' => 'Device enrolled', 'description' => 'An endpoint agent enrolled (or re-enrolled) a device; it may still wait for approval.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'onboarding']],
            ['id' => 'rmm.device.offline', 'group' => 'rmm', 'label' => 'Device offline', 'description' => 'A device stopped checking in for longer than the offline threshold.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'down', 'unreachable']],
            ['id' => 'rmm.device.online', 'group' => 'rmm', 'label' => 'Device back online', 'description' => 'A device that was reported offline checked in again.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'recovered', 'up']],
            ['id' => 'rmm.check.failed', 'group' => 'rmm', 'label' => 'Device check failed', 'description' => 'A device check stayed failing or warning long enough to open an alert.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'monitoring', 'alert']],
            ['id' => 'rmm.check.recovered', 'group' => 'rmm', 'label' => 'Device check recovered', 'description' => 'A device check that had an open alert is healthy again.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'monitoring', 'resolved']],
            ['id' => 'rmm.job.completed', 'group' => 'rmm', 'label' => 'Device job completed', 'description' => 'A job ran to completion on a device.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'script', 'reboot']],
            ['id' => 'rmm.job.failed', 'group' => 'rmm', 'label' => 'Device job failed', 'description' => 'A job failed or timed out on a device.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'script', 'error', 'timeout']],
            ['id' => 'rmm.software.installed', 'group' => 'rmm', 'label' => 'Software installed', 'description' => 'New software appeared on a device (the first report of a device is a baseline and publishes nothing).', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'software', 'inventory', 'install']],
            ['id' => 'rmm.software.removed', 'group' => 'rmm', 'label' => 'Software removed', 'description' => 'Software disappeared from a device.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'software', 'inventory', 'uninstall']],
            ['id' => 'catalog.request_rejected', 'group' => 'approvals', 'label' => 'Catalog request rejected', 'description' => 'A service catalog request was rejected.', 'severity' => 'info', 'tags' => ['service', 'request', 'denied']],
        ];
    }

    /**
     * The catalog as the picker consumes it: {groups: {key: {label}}, events: [{id, group, groupLabel, label, description, severity, since, tags}]}.
     *
     * @return array{groups:array<string,array{label:string,count:int}>,events:list<array<string,mixed>>}
     */
    function rivetEventCatalogData(): array
    {
        $events = [];
        $groups = [];
        foreach (\RivetCore\Webhooks\EventCatalog::all() as $e) {
            $groups[$e->group] ??= ['label' => $e->groupLabel, 'count' => 0];
            $events[] = ['id' => $e->id, 'group' => $e->group, 'groupLabel' => $e->groupLabel, 'label' => $e->label, 'description' => $e->description,
                'severity' => $e->severity, 'since' => $e->since, 'tags' => $e->tags];
        }
        foreach (rivetEventCatalogExtras() as $x) {
            if (\RivetCore\Webhooks\EventCatalog::has($x['id'])) {
                continue;
            }
            $label = $groups[$x['group']]['label'] ?? ucfirst($x['group']);
            $groups[$x['group']] ??= ['label' => $label, 'count' => 0];
            $events[] = ['id' => $x['id'], 'group' => $x['group'], 'groupLabel' => $label, 'label' => $x['label'], 'description' => $x['description'],
                'severity' => $x['severity'], 'since' => null, 'tags' => $x['tags']];
        }
        // Keep each group's events together and the groups in the catalog's order.
        $order = array_flip(array_keys($groups));
        usort($events, static fn (array $a, array $b): int => ($order[$a['group']] <=> $order[$b['group']]));
        foreach ($groups as $k => $_) {
            $groups[$k]['count'] = 0;
        }
        foreach ($events as $e) {
            $groups[$e['group']]['count']++;
        }

        return ['groups' => $groups, 'events' => $events];
    }

    /** Ids of every event in the picker's catalog. @return list<string> */
    function rivetEventCatalogIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = array_map(static fn (array $e): string => $e['id'], rivetEventCatalogData()['events']);
        }

        return $ids;
    }
}
