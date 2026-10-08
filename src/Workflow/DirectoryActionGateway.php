<?php

namespace ITFlow\Workflow;

/**
 * What the Entra account actions (entra_disable_account, entra_create_account, entra_add_to_groups) need from the outside world.
 * Kept apart from ActionGateway so existing gateways and fakes are untouched. LiveActionGateway implements it over Microsoft Graph;
 * every method refuses to do anything (and says why) while 'Allow RivetIT to change Entra accounts' is off.
 */
interface DirectoryActionGateway
{
    /** Is the 'Allow RivetIT to change Entra accounts' setting on? */
    public function entraWritesAllowed(): bool;

    /** Disable sign-in (never delete) and optionally revoke sessions, only for an account in $expectedDepartment (IT-9). @return string result for the log (no secrets) */
    public function entraDisableAccount(string $email, bool $revokeSessions, int $runTaskId, string $expectedDepartment = ''): string;

    /**
     * Create the account unless one with this UPN exists. The temporary password is generated inside, handed only to the run
     * task's secret slot for its technician, and never returned. @param array{upn:string,display_name:string,enabled:bool,groups:string[]} $spec
     * @return string result for the log (no secrets)
     */
    public function entraCreateAccount(array $spec, int $runTaskId): string;

    /**
     * @param string[] $groupIds @return string result for the log
     * $expectedDepartment is the contact's department; the Entra account must carry the same department attribute (IT-9).
     */
    public function entraAddToGroups(string $email, array $groupIds, int $runTaskId, string $expectedDepartment = ''): string;
}
