<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\LifecycleGateway;

/** Shared gateway check for the actions that need LifecycleGateway. */
abstract class AbstractLifecycleAction extends AbstractAction
{
    protected function lifecycle(ActionGateway $gateway): LifecycleGateway
    {
        if (!$gateway instanceof LifecycleGateway) {
            throw new \RuntimeException('This action is not available here');
        }

        return $gateway;
    }

    protected function contactId(array $ctx): int
    {
        $id = (int) ($ctx['contact_id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('there is no person to act on');
        }

        return $id;
    }
}
