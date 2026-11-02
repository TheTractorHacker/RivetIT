<?php

namespace ITFlow\Automation;

/**
 * How the "Run an RMM script" rule action reaches the RMM module. The real one (CoreRmmScriptDispatcher) calls the module's technician API AS a named
 * technician, so the module's own checks apply to automation exactly as to a person: the technician's permission to run library scripts, department
 * scope, the script's platform check, signed script bodies and the second-person approval rule (a run that needs approval is queued for approval and
 * does not run). Tests hand in a recording fake.
 */
interface RmmScriptDispatcher
{
    /** Name of a library script as that technician sees it, or null when it does not exist or is not visible to them. */
    public function scriptName(int $asUserId, string $asUserName, int $scriptId): ?string;

    /**
     * @param array<string,string> $params fixed parameters from the rule
     * @return array{queued:bool,pending_approval:bool,message:string} @throws \RuntimeException with the module's reason when it refuses
     */
    public function dispatch(int $asUserId, string $asUserName, int $deviceId, int $scriptId, array $params, ?int $timeoutS): array;
}
