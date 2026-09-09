<?php

namespace ITFlow\Directory;

/**
 * Directory-sync field mapping: lets each provider's sync (Odoo today,
 * Microsoft/Google added alongside this class) write whatever contacts.*
 * column an admin picked for a given source field - job_title could go to
 * contact_title, or contact_department, or the mapping can just be turned
 * off - instead of a mapper hardcoding contact_title/contact_phone/etc.
 * the way OdooDirectoryMapper::syncEmployee() did before this table
 * existed. Backing table: directory_field_mappings (DB update 2.6.79 /
 * db.sql).
 *
 * SECURITY - why forProvider() filters rather than trusting the table:
 * every caller of forProvider() takes its returned target_field and
 * interpolates it as a bare SQL column identifier into an UPDATE/INSERT
 * (there is no mysqli placeholder syntax for a column name). target_field
 * is admin-entered through the settings UI's save-mapping handler, which
 * should itself validate against isValidTargetField()/ALLOWED_TARGET_FIELDS
 * before writing - but a row can also reach this table by other paths (a
 * restored backup from an older/different schema, a manual fix directly in
 * the database), and this class has no way to know which path any given
 * row came from. So the ALLOW-LIST is enforced again here, on every read,
 * as the last line of defense before a target_field ever reaches a
 * mapper's SQL - not just at the point a row is saved. A row that fails
 * the check is dropped silently (not an exception): one admin fat-fingering
 * or corrupting a single mapping must not take down every other field's
 * sync in the same run. allForProvider(), used only to render the settings
 * UI (never to build SQL), returns the raw row set unfiltered so a bad row
 * is actually visible there for the admin to fix.
 */
final class FieldMapping
{
    /** The only values directory_field_mappings.provider is ever seeded/written with. */
    public const VALID_PROVIDERS = ['odoo', 'microsoft', 'google'];

    /**
     * Every contacts.* column a directory sync is allowed to write, grepped
     * from db.sql's real `contacts` CREATE TABLE (2026-09-09) - these six
     * are the plain identity/contact-info fields; contact_manager_id,
     * contact_employment_status, contact_client_id and similar structural/
     * relationship columns are deliberately excluded even though they are
     * real columns - each sync mapper sets those itself via its own
     * dedicated department/manager-matching logic (see
     * OdooDirectoryMapper::syncEmployee()), not through a free-form,
     * admin-editable field mapping that could repoint them at arbitrary
     * incoming text.
     */
    public const ALLOWED_TARGET_FIELDS = [
        'contact_name',
        'contact_title',
        'contact_phone',
        'contact_mobile',
        'contact_email',
        'contact_department',
    ];

    public static function isValidTargetField(string $targetField): bool
    {
        return in_array($targetField, self::ALLOWED_TARGET_FIELDS, true);
    }

    /**
     * [source_field => target_field] for every ENABLED mapping of
     * $provider, with any row whose target_field fails the allow-list
     * silently excluded (see class docblock). This is what every sync
     * mapper calls instead of hardcoding field names.
     *
     * @return array<string,string>
     */
    public static function forProvider(\mysqli $mysqli, string $provider): array
    {
        $map = [];

        foreach (self::allForProvider($mysqli, $provider) as $row) {
            if (!$row['enabled']) {
                continue;
            }
            if (!self::isValidTargetField($row['target_field'])) {
                continue;
            }
            $map[$row['source_field']] = $row['target_field'];
        }

        return $map;
    }

    /**
     * Every mapping row for $provider, enabled or not, for the settings UI
     * to render one row per source field with its current target_field and
     * on/off state. Unlike forProvider(), this does NOT filter by the
     * allow-list - the UI needs to see a misconfigured row to let the admin
     * fix it, and nothing here ever reaches SQL as an identifier.
     *
     * @return list<array{mapping_id:int,source_field:string,target_field:string,enabled:bool}>
     */
    public static function allForProvider(\mysqli $mysqli, string $provider): array
    {
        // Unrecognised provider: return no rows rather than querying with an
        // arbitrary string. $provider is escaped below regardless (defense in
        // depth, same reasoning as target_field above), but this allow-list
        // check means a typo'd/garbage provider fails visibly (empty result)
        // instead of silently matching zero rows for a subtly wrong reason.
        if (!in_array($provider, self::VALID_PROVIDERS, true)) {
            return [];
        }

        $providerEsc = mysqli_real_escape_string($mysqli, $provider);

        $result = mysqli_query(
            $mysqli,
            "SELECT `mapping_id`, `source_field`, `target_field`, `enabled`
               FROM `directory_field_mappings`
              WHERE `provider` = '$providerEsc'
              ORDER BY `source_field`"
        );

        $rows = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = [
                    'mapping_id'   => intval($row['mapping_id']),
                    'source_field' => (string) $row['source_field'],
                    'target_field' => (string) $row['target_field'],
                    'enabled'      => intval($row['enabled']) === 1,
                ];
            }
        }

        return $rows;
    }
}
