<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\Connectors\ConnectorRegistry;

/** Disables (never deletes) the employee's account in an external identity system through its connector. */
class ConnectorDeprovisionAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'connector_deprovision';
    }

    public function label(): string
    {
        return 'Identity system: disable the employee account (AD / VPN / Okta / Google)';
    }

    public function templateTypes(): array
    {
        return ['offboarding'];
    }

    public function validate(array $config): array
    {
        $system = (string) ($config['system'] ?? '');
        if (!isset(ConnectorRegistry::SYSTEMS[$system])) {
            throw new \InvalidArgumentException('Choose the identity system.');
        }

        return ['system' => $system, 'identifier' => $this->text($config, 'identifier', 200, false, 'account name') ?: '{{employee_email}}'];
    }

    public function describe(array $config, array $ctx): string
    {
        $system = ConnectorRegistry::SYSTEMS[$config['system'] ?? ''] ?? 'the identity system';

        return 'Would disable (never delete) ' . Placeholders::render((string) ($config['identifier'] ?? '{{employee_email}}'), $ctx['vars'] ?? []) . " in $system. Needs a live connector.";
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $connector = $this->lifecycle($gateway)->identityConnector((string) ($config['system'] ?? ''));
        if (!$connector->isConfigured()) {
            throw new \RuntimeException($connector->isConfiguredHint());
        }
        $vars = $ctx['vars'] ?? [];

        return $connector->deprovision(Placeholders::render((string) ($config['identifier'] ?? '{{employee_email}}'), $vars, 'text'), (string) ($vars['department'] ?? ''));
    }
}
