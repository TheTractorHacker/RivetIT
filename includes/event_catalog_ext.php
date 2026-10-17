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
            // RMM Phase 2 and 3 (RivetCore 1.0.0-rc.10). Published by EndpointEvents like the nine above; the not-about-one-device ones (policies, approvals, maintenance) carry device_id 0.
            ['id' => 'rmm.policy.assigned', 'group' => 'rmm', 'label' => 'Policy assigned', 'description' => 'A monitoring policy was assigned to, or removed from, a department, site, group, tag or device.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'policy', 'configuration']],
            ['id' => 'rmm.script.run', 'group' => 'rmm', 'label' => 'Library script queued', 'description' => 'A script from the RMM script library was queued on a device (by a person, a schedule or after an approval).', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'script', 'automation']],
            ['id' => 'rmm.approval.requested', 'group' => 'rmm', 'label' => 'Approval requested', 'description' => 'A script run or schedule is waiting for a second person to approve it.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'approval', 'script']],
            ['id' => 'rmm.approval.decided', 'group' => 'rmm', 'label' => 'Approval decided', 'description' => 'A script run or schedule was approved, rejected, cancelled or lapsed.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'approval', 'script']],
            ['id' => 'rmm.alert.opened', 'group' => 'rmm', 'label' => 'Device alert opened', 'description' => 'An alert opened for a device check, after debounce, maintenance and storm-control rules.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'alert', 'monitoring']],
            ['id' => 'rmm.alert.escalated', 'group' => 'rmm', 'label' => 'Device alert escalated', 'description' => 'An open alert reached an escalation step, was repeated, or became more severe.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'alert', 'escalation', 'oncall']],
            ['id' => 'rmm.alert.acknowledged', 'group' => 'rmm', 'label' => 'Device alert acknowledged', 'description' => 'A technician acknowledged an open device alert, which stops its escalation.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'alert', 'acknowledge']],
            ['id' => 'rmm.alert.resolved', 'group' => 'rmm', 'label' => 'Device alert resolved', 'description' => 'A device alert was resolved: the check recovered, a technician closed it, or the device was retired.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'alert', 'resolved']],
            ['id' => 'rmm.maintenance.started', 'group' => 'rmm', 'label' => 'Maintenance window started', 'description' => 'A maintenance window opened; matching alerts are muted or checks suppressed until it ends.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'maintenance', 'window']],
            ['id' => 'rmm.maintenance.ended', 'group' => 'rmm', 'label' => 'Maintenance window ended', 'description' => 'A maintenance window closed.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'maintenance', 'window']],
            // RMM Phase 6 and 8 (RivetCore 1.0.0-rc.11): published by EndpointEvents like the others. Off until the filetransfer / logs sub-switches are turned on.
            ['id' => 'rmm.file.transferred', 'group' => 'rmm', 'label' => 'File transferred', 'description' => 'A file was copied from a device to the server, or sent to a device, and its checksum was verified (or the transfer failed).', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'file', 'transfer', 'download', 'upload']],
            ['id' => 'rmm.log.rule_matched', 'group' => 'rmm', 'label' => 'Log rule matched', 'description' => 'A log rule matched entries collected from a device and raised or kept an alert.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'log', 'eventlog', 'syslog', 'journal', 'alert']],
            // RivetCore 1.0.0-rc.12 (optional MeshCentral agent install, automatic node-id mapping) and rc.13 (RMM starter pack). Core's catalog lists them too; named here for the same reason as above.
            ['id' => 'rmm.mesh.agent_installed', 'group' => 'rmm', 'label' => 'Mesh agent installed', 'description' => 'The MeshCentral agent was installed on a device together with the RivetIT agent (or found there).', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'mesh', 'meshcentral', 'remote']],
            ['id' => 'rmm.mesh.node_conflict', 'group' => 'rmm', 'label' => 'Mesh node conflict', 'description' => 'A device reported a MeshCentral node id that is already mapped to another live device, so it was not mapped.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'mesh', 'meshcentral', 'conflict']],
            ['id' => 'rmm.starter.installed', 'group' => 'rmm', 'label' => 'Starter pack changed', 'description' => 'Starter pack scripts or policy drafts were installed, updated, parked for review or removed (not about one device).', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'starter', 'script', 'policy', 'library']],
            // RMM Phase 4 (RivetCore 1.0.0-rc.20, patch management): published by the patch services through EndpointEvents. Named here too, like every rmm.* event, for an older package.
            ['id' => 'rmm.patch.scan_completed', 'group' => 'rmm', 'label' => 'Patch scan completed', 'description' => 'A device reported which updates it is missing.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'patch', 'update', 'windows update', 'apt', 'dnf']],
            ['id' => 'rmm.patch.approved', 'group' => 'rmm', 'label' => 'Updates approved', 'description' => 'Updates were approved for installation by a person or an approval rule.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'patch', 'update', 'approval']],
            ['id' => 'rmm.patch.install_started', 'group' => 'rmm', 'label' => 'Patch install queued', 'description' => 'An installation (or a dry run) of approved updates was queued for a device.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'patch', 'update', 'install']],
            ['id' => 'rmm.patch.install_finished', 'group' => 'rmm', 'label' => 'Patch install finished', 'description' => 'An installation of updates finished on a device.', 'severity' => 'info', 'tags' => ['agent', 'endpoint', 'rmm', 'patch', 'update', 'install']],
            ['id' => 'rmm.patch.install_failed', 'group' => 'rmm', 'label' => 'Patch install failed', 'description' => 'An installation of updates failed on a device, in whole or in part.', 'severity' => 'warning', 'tags' => ['agent', 'endpoint', 'rmm', 'patch', 'update', 'install', 'failed']],
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
