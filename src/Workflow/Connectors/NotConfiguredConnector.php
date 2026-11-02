<?php

namespace ITFlow\Workflow\Connectors;

/**
 * Placeholder for an identity system that has no live implementation in this build. It refuses every call with a message that says
 * so, so a template can be designed and previewed today and the task becomes a manual task (never a silent success) when it runs.
 * Replace it with a real IdentityConnector through ConnectorRegistry::register() when the integration exists.
 */
class NotConfiguredConnector implements IdentityConnector
{
    public function __construct(private string $key, private string $label)
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isConfiguredHint(): string
    {
        return $this->label . ' is not connected: this build has no live ' . $this->label . ' connector yet, so nothing was changed. Do this step by hand.';
    }

    public function provision(array $spec): string
    {
        throw new \RuntimeException($this->isConfiguredHint());
    }

    public function deprovision(string $identifier, string $expectedDepartment): string
    {
        throw new \RuntimeException($this->isConfiguredHint());
    }
}
