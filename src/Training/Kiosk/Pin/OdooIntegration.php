<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Core\Db;

/**
 * The ONE Odoo integration row the kiosk uses, chosen exactly like the rest of the app
 * (cron/cron.php, admin directory sync, admin integrations page): the newest ENABLED row.
 * An explicit column list - the connector factory needs no more (P3 spec §3.2, critique #6).
 */
final class OdooIntegration
{
    public const COLUMNS = 'odoo_integration_id, base_url, database_name, username, api_key_enc, api_protocol, enabled';

    public static function current(\mysqli $db): ?array
    {
        try {
            $row = Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM odoo_integrations WHERE enabled = 1 ORDER BY odoo_integration_id DESC LIMIT 1');
        } catch (\mysqli_sql_exception $e) {
            error_log('Kiosk OdooIntegration: ' . get_class($e) . ' ' . $e->getCode());
            return null;
        }
        if ($row === null) {
            return null;
        }
        $row['odoo_integration_id'] = (int) $row['odoo_integration_id'];
        return $row;
    }

    /** The contact's linked Odoo employee id for integration $integrationId (contact_odoo_links), or null. */
    public static function linkedEmployee(\mysqli $db, int $contactId, int $integrationId): ?int
    {
        $r = Db::one($db, 'SELECT odoo_employee_id FROM contact_odoo_links WHERE contact_id = ? AND odoo_integration_id = ?', 'ii', [$contactId, $integrationId]);
        return ($r === null || (int) $r['odoo_employee_id'] < 1) ? null : (int) $r['odoo_employee_id'];
    }
}
