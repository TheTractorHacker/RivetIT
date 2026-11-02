<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Adds Microsoft 365 licences (SKU GUIDs) to the person's Entra account. */
class EntraAssignLicenseAction extends AbstractEntraAction
{
    public function type(): string
    {
        return 'entra_assign_license';
    }

    public function label(): string
    {
        return 'Entra: assign Microsoft 365 licences';
    }

    public function templateType(): string
    {
        return 'onboarding';
    }

    public function templateTypes(): array
    {
        return ['onboarding', 'role_change', 'transfer'];
    }

    public function validate(array $config): array
    {
        $skus = \ITFlow\Integrations\Microsoft\EntraAccountService::cleanGroupIds($config['skus'] ?? '');
        $given = is_array($config['skus'] ?? null) ? $config['skus'] : preg_split('/[\s,;]+/', (string) ($config['skus'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!$skus || count($skus) !== count(array_unique(array_map('strtolower', array_map('trim', $given))))) {
            throw new \InvalidArgumentException('Enter one or more licence SKU IDs (GUIDs, one per line). Find them in the Microsoft 365 admin centre or Graph subscribedSkus.');
        }

        return ['upn' => $this->text($config, 'upn', 200, false, 'user principal name') ?: '{{employee_email}}', 'skus' => implode("\n", $skus)];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Would send to Microsoft Graph: assign ' . count($this->skus($config)) . ' licence(s) to ' . Placeholders::render((string) ($config['upn'] ?? '{{employee_email}}'), $ctx['vars'] ?? []) . ('. Needs "Allow ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' to change Entra accounts".');
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $dir = $this->directory($gateway);
        return $dir->entraChangeLicenses($this->upnFrom($config, $ctx), $this->skus($config), [], (int) ($ctx['run_task_id'] ?? 0), (string) ($ctx['vars']['department'] ?? ''));
    }
}
