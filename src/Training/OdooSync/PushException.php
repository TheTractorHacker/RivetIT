<?php

namespace ITFlow\Training\OdooSync;

/**
 * A push outcome the Pusher already knows how to classify (spec §3.4): Odoo answered, but the
 * answer means this row cannot go through - e.g. 'odoo_record_missing', 'odoo_record_moved',
 * 'bad_create_response'. $errorClass is one of OutboxRepo's classes ('permanent', 'policy', …);
 * ErrorClass::of() returns it unchanged.
 */
final class PushException extends \RuntimeException
{
    public function __construct(public readonly string $errorClass, string $message)
    {
        parent::__construct($message);
    }
}
