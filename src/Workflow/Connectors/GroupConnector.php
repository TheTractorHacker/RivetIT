<?php

namespace ITFlow\Workflow\Connectors;

/** An identity connector that can also add an existing account to groups (role change, department transfer). Idempotent; never removes anyone. */
interface GroupConnector extends IdentityConnector
{
    /**
     * Add the existing account to each group (a group name or the system's own group id). Already a member counts as done.
     * Only touches an account that belongs to $expectedDepartment. @param string[] $groups @return string result for the log (no secrets)
     * @throws \RuntimeException when it cannot be done, including "no such account"
     */
    public function addToGroups(string $identifier, array $groups, string $expectedDepartment): string;
}
