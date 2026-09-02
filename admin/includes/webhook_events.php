<?php

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
    return [
        'Ticket Events' => [
            'ticket.created',
            'ticket.replied',
            'ticket.assigned',
            'ticket.status_changed',
            'ticket.resolved',
        ],
        'Platform Events (Audit Trail)' => [
            'workflow.onboarding_started',
            'workflow.offboarding_started',
            'workflow.cancelled',
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

function all_webhook_event_types(): array
{
    return array_merge(...array_values(webhook_event_groups()));
}
