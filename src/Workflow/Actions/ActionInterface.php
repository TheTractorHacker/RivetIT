<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** One kind of automated lifecycle task. Registered in TaskActionRunner::registry(). */
interface ActionInterface
{
    /** The stored action_type key. */
    public function type(): string;

    /** Plain-language name shown in the template editor. */
    public function label(): string;

    /**
     * Checks and normalizes a task's action_config when a template is saved.
     * @param array<string,mixed> $config
     * @return array<string,mixed> the clean config to store
     * @throws \InvalidArgumentException with a message safe to show an administrator
     */
    public function validate(array $config): array;

    /**
     * One plain sentence saying what this would do for this person, with placeholders filled in. Used by the dry-run preview;
     * it must not write, send or queue anything.
     * @param array<string,mixed> $config
     * @param array<string,mixed> $ctx see TaskActionRunner::buildContext()
     */
    public function describe(array $config, array $ctx): string;

    /**
     * Does the work. Throws on failure (the runner retries, then marks the task action_failed for a person to finish by hand).
     * @param array<string,mixed> $config
     * @param array<string,mixed> $ctx
     * @return string short result for the task log (no secrets)
     */
    public function execute(array $config, array $ctx, ActionGateway $gateway): string;
}
