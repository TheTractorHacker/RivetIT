<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/**
 * Archives the person's record and their portal login. With "Delay (days)" on the task this is the "schedule the account clean-up for
 * 30 days after the end date" step. Nothing is deleted; restoring the contact restores it.
 */
class ArchiveContactAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'archive_contact';
    }

    public function label(): string
    {
        return "Archive the employee's record and portal login";
    }

    public function templateTypes(): array
    {
        return ['offboarding'];
    }

    public function validate(array $config): array
    {
        return [];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Archive ' . ($ctx['vars']['employee_name'] ?? 'the employee') . " and their portal login; nothing is deleted (restore the contact to undo)";
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        return $this->lifecycle($gateway)->archiveContact($this->contactId($ctx));
    }
}
