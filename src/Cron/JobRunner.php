<?php

namespace ITFlow\Cron;

/**
 * Compatibility shim: the runner now lives in RivetCore (RivetCore\Cron\JobRunner). It is subclassed only to keep
 * RivetIT's original default state directory (/tmp/rivetit-jobs), so PID and log files from jobs started before
 * the upgrade are still found. The static helpers (logPathFromCommand, argumentsFromCommand, phpBinaryFromCommand,
 * tail) are inherited.
 *
 * @deprecated since 26.10.26 use \RivetCore\Cron\JobRunner (pass the state directory yourself; the old default is sys_get_temp_dir()/rivetit-jobs). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
final class JobRunner extends \RivetCore\Cron\JobRunner
{
    public function __construct(string $appRoot, ?string $stateDir = null)
    {
        parent::__construct($appRoot, $stateDir ?? (sys_get_temp_dir() . '/rivetit-jobs'));
    }
}
