<?php

namespace ITFlow\Automation;

/** The only OdooUserGateway that ships: it writes nothing anywhere. */
final class UnconfiguredOdooUserGateway implements OdooUserGateway
{
    public function createUser(array $spec): string
    {
        throw new \RuntimeException('no Odoo write gateway is installed (the Odoo connection is read only); no user was created');
    }
}
