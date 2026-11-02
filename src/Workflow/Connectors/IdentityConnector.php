<?php

namespace ITFlow\Workflow\Connectors;

/**
 * One external identity system a lifecycle workflow can provision or deprovision a person in. The interface is deliberately small and
 * idempotent: a repeat call for the same person must be a safe no-op. Implementations never delete accounts and never return or log a
 * password; a new account's first credential, if the system makes one, is handled inside the implementation.
 */
interface IdentityConnector
{
    /** Stored key: ad, vpn, okta, google. */
    public function key(): string;

    public function label(): string;

    /** Is a live implementation present and configured on this install? When false every call refuses with isConfiguredHint(). */
    public function isConfigured(): bool;

    public function isConfiguredHint(): string;

    /**
     * Create the account unless it exists, disabled unless $spec['enabled'] is true, then add it to $spec['groups'].
     * @param array{identifier:string,display_name:string,enabled:bool,groups:string[],department:string} $spec
     * @return string result for the log (no secrets)
     * @throws \RuntimeException when it cannot be done
     */
    public function provision(array $spec): string;

    /**
     * Disable the account (never delete), only if it belongs to $expectedDepartment. @return string result for the log
     * @throws \RuntimeException when it cannot be done, including "no such account": a security step must not look done when nothing was found
     */
    public function deprovision(string $identifier, string $expectedDepartment): string;
}
