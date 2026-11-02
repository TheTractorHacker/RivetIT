<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/**
 * Gives the person a device: a specific asset, or one taken from the stock (unassigned, status "Ready to Deploy") of the person's own
 * department. The asset is linked to the person and its status becomes "Deployed" (names configurable); the asset's history records it.
 */
class AssignAssetAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'assign_asset';
    }

    public function label(): string
    {
        return 'Assign a device to the employee';
    }

    public function validate(array $config): array
    {
        $assetId = (int) ($config['asset_id'] ?? 0);
        $type = $this->text($config, 'asset_type', 100, false, 'device type');
        if ($assetId <= 0 && $type === '') {
            throw new \InvalidArgumentException('Enter the device type to take from stock (for example Laptop) or a specific asset ID.');
        }

        return [
            'asset_id' => max(0, $assetId),
            'asset_type' => $type,
            'from_status' => $this->text($config, 'from_status', 100, false, 'stock status') ?: 'Ready to Deploy',
            'to_status' => $this->text($config, 'to_status', 100, false, 'assigned status') ?: 'Deployed',
        ];
    }

    public function describe(array $config, array $ctx): string
    {
        $who = $ctx['vars']['employee_name'] ?? 'the employee';
        $what = (int) ($config['asset_id'] ?? 0) > 0 ? 'asset #' . (int) $config['asset_id'] : 'one ' . ($config['asset_type'] ?? 'device') . ' from the "' . ($config['from_status'] ?? 'Ready to Deploy') . '" stock of their department';

        return "Assign $what to $who and set its status to \"" . ($config['to_status'] ?? 'Deployed') . '"';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        return $this->lifecycle($gateway)->assignAsset($this->contactId($ctx), $config, (int) ($ctx['run_task_id'] ?? 0));
    }
}
