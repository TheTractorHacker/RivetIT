<?php

namespace ITFlow\Workflow;

/**
 * Everything a lifecycle workflow does to the outside world goes through here: tickets, mail, notifications, bus events, audit,
 * and the portal-login switch-off. The live implementation uses the app's own functions; tests hand in a recording fake, which is
 * how the dry-run guarantee (nothing sent, nothing queued) is proven.
 */
interface ActionGateway
{
    /** Create a ticket the same way every other automatic ticket path does. @return int ticket id */
    public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int;

    /** Put one email on the outgoing mail queue (the mail queue cron sends it). */
    public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void;

    /** Notify one agent, or every active agent when $userId is 0. */
    public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void;

    /** Emit an event on the event bus (subscribed webhooks and event rules). */
    public function emitEvent(string $event, array $data): void;

    /** Record an audit event; never throws. */
    public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void;

    /** Archive the person's portal login (never deletes anything). @return string what happened, for the log */
    public function disablePortalLogin(int $contactId): string;
}
