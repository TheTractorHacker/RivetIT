<?php

namespace ITFlow\Automation;

/**
 * What the "Create an Odoo user" rule action needs from Odoo. The Odoo connection of this app is READ ONLY (directory sync; write-back is off), so NO
 * implementation ships: UnconfiguredOdooUserGateway refuses with a clear message. A real one (JSON-2 `res.users` create) must be written, reviewed and
 * switched on deliberately against a non-production Odoo first; the production Odoo is never written by this code base today.
 */
interface OdooUserGateway
{
    /**
     * Creates the user unless one with this login exists. @param array{login:string,name:string,groups:list<string>,contact_id:int} $spec
     * @return string result for the run log (no passwords) @throws \RuntimeException
     */
    public function createUser(array $spec): string;
}
