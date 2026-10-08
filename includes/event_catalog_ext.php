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
