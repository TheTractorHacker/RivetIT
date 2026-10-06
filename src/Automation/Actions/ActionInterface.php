<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** One thing an event rule can do. validate() cleans admin input; execute() does it, or (dry run) only reports what it would do. */
interface ActionInterface
{
    public function key(): string;

    public function label(): string;

    /** @param array<string,mixed> $input raw form values @return array<string,mixed> the cleaned config to store @throws \InvalidArgumentException */
    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array;

    /**
     * @param array<string,mixed> $config stored config @param array<string,string> $context flat event context @param array<string,mixed> $rule the rule row
     * @param bool $dry true: read only, change and send nothing, return what WOULD happen
     * @return string result message for the run log @throws \RuntimeException when the action cannot do its job
     */
    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string;
}
