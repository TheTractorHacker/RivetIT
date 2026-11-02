<?php

namespace ITFlow\Workflow;

use ITFlow\Workflow\Connectors\IdentityConnector;

/**
 * The lifecycle actions beyond tickets/mail/Entra: devices, the person's record, Odoo, and the generic identity connectors
 * (Active Directory, VPN, Okta, Google Workspace). Kept apart from ActionGateway so existing gateways and fakes are untouched;
 * LiveActionGateway implements it, tests hand in a recording fake.
 */
interface LifecycleGateway
{
    /**
     * Give the person a device. $spec: asset_id (a specific one) or asset_type (pick one from the stock), from_status, to_status.
     * Only an unassigned, non-archived asset of the person's own department is ever taken. @return string result for the log
     */
    public function assignAsset(int $contactId, array $spec, int $runTaskId): string;

    /** Take back every device assigned to the person (optionally only some types) and set its status. @return string result for the log */
    public function collectAssets(int $contactId, array $spec, int $runTaskId): string;

    /** Archive the person's record and portal login (not a delete; restoring the contact restores it). @return string result for the log */
    public function archiveContact(int $contactId): string;

    /** Is 'Allow lifecycle workflows to change Odoo' (settings.config_lifecycle_odoo_actions) on? */
    public function odooActionsAllowed(): bool;

    /** Create the employee in Odoo (hr.employee) unless one with this work email exists. @param array{name:string,email:string,title:string} $spec */
    public function odooProvisionEmployee(array $spec, int $runTaskId): string;

    /** Archive the employee in Odoo (active = false, never delete). */
    public function odooDisableEmployee(string $email, int $runTaskId): string;

    /** A registered identity connector by key (ad, vpn, okta, google). Never null: an unknown or unconfigured one refuses with a clear message. */
    public function identityConnector(string $key): IdentityConnector;
}
