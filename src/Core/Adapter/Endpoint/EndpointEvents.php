<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\RmmEventsInterface;

/**
 * Puts the module's `rmm.*` events (RivetCore 1.0.0-rc.9, RmmEventsInterface) on RivetIT's event bus (includes/event_bus.php): one queued, signed
 * webhook delivery per subscribed endpoint, and every enabled event rule (Administration > Event rules) that matches. The module calls this AFTER
 * the change that caused the event has been committed and swallows anything thrown here; rivetEmitEvent() never throws anyway.
 *
 * The bus works on the global connection, so a module built over another connection (a test, a worker) is pointed at its own for the duration of
 * the call and put back afterwards.
 */
final class EndpointEvents implements RmmEventsInterface
{
    public function __construct(private \mysqli $mysqli)
    {
    }

    public function publish(string $event, array $payload): void
    {
        if (!function_exists('rivetEmitEvent')) {
            require_once dirname(__DIR__, 4) . '/includes/event_bus.php';
        }
        $previous = $GLOBALS['mysqli'] ?? null;
        $GLOBALS['mysqli'] = $this->mysqli;
        try {
            rivetEmitEvent($event, $payload);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['mysqli']);
            } else {
                $GLOBALS['mysqli'] = $previous;
            }
        }
    }
}
