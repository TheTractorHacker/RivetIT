<?php

require_once __DIR__ . '/../../includes/event_catalog_ext.php';

/**
 * Single source of truth for events a webhook can subscribe to, shared by
 * settings_webhooks.php, its add/edit modals, and its POST handler so the
 * validation list and the checkbox list can never drift apart.
 *
 * 'Ticket Events' are the original set, delivered async via
 * queueWebhookEvent()/webhook_queue/cron.php. 'Platform Events' are
 * AuditService event_type strings (see src/Audit/AuditService.php callers) -
 * subscribing to one only records the subscription today; nothing calls
 * WebhookDispatcher::deliver() for them yet.
 */
function webhook_event_groups(): array
{
    $groups = webhook_event_groups_static();

    // Every audit event type this install has recorded can be subscribed to or automated too, not only the ones listed above.
    global $mysqli;
    if (isset($mysqli) && $mysqli instanceof \mysqli) {
        $known = array_merge(...array_values($groups));
        $extra = [];
        try {
            $res = mysqli_query($mysqli, "SELECT DISTINCT event_type FROM audit_events WHERE event_type REGEXP '^[a-z0-9_.]+$' ORDER BY event_type LIMIT 400");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                if (!in_array($r['event_type'], $known, true) && $r['event_type'] !== 'automation.rule_fired') {
                    $extra[] = $r['event_type'];
                }
            }
        } catch (\Throwable $e) {
            // the audit table may not exist yet
        }
        if ($extra) {
            $groups['Other events seen on this server'] = $extra;
        }
    }

    return $groups;
}

function webhook_event_groups_static(): array
{
    return [
        'Ticket Events' => [
            'ticket.created',
            'ticket.replied',
            'ticket.assigned',
            'ticket.status_changed',
            'ticket.resolved',
            'ticket.updated',
            'ticket.sla_warning',
            'ticket.sla_breached',
        ],
        'Platform Events (Audit Trail)' => [
            'workflow.onboarding_started',
            'workflow.offboarding_started',
            'workflow.cancelled',
            'workflow.action_executed',
            'workflow.action_failed',
            'workflow.approval_approved',
            'workflow.approval_rejected',
            'workflow.approval_overridden',
            'workflow.task_webhook',
            'workflow.task_completed',
            'asset.created',
            'contact.created',
            'catalog.request_approved',
            'catalog.request_rejected',
            'employee.hired',
            'employee.terminated',
            'people.import_approved',
            'problem.created',
            'problem.status_changed',
            'change.created',
            'change.status_changed',
            'kb_article.restored',
            'vault.credential_revealed',
            'auth.login_success',
            'auth.login_failed',
            'auth.mfa_failed',
            'integration.microsoft.test',
            'integration.odoo.test',
        ],
    ];
}

/** Every event id a webhook or event rule may name: the picker's catalog, the original list and what this server has recorded. */
function all_webhook_event_types(): array
{
    return array_values(array_unique(array_merge(rivetEventCatalogIds(), ...array_values(webhook_event_groups()))));
}

/**
 * Events this server has recorded that are not in the catalog (shown by the picker as "Other events seen on this server").
 *
 * @return list<string>
 */
function webhook_events_seen_elsewhere(): array
{
    $known = array_flip(array_merge(rivetEventCatalogIds(), ...array_values(webhook_event_groups_static())));

    return array_values(array_filter(webhook_event_groups()['Other events seen on this server'] ?? [], static fn (string $e): bool => !isset($known[$e])));
}
