<?php

namespace ITFlow\Integrations\Microsoft;

use ITFlow\Directory\FieldMapping;

/**
 * MicrosoftDirectoryMapper — matches Microsoft Graph users to RivetIT
 * clients (departments) and contacts (employees), the Entra/Microsoft 365
 * counterpart to OdooDirectoryMapper. This is NET NEW: there is no
 * Microsoft user/contact sync anywhere in this codebase before this class -
 * only Intune DEVICE sync (IntuneAssetMapper), which is unrelated. Mirrors
 * OdooDirectoryMapper's shape closely (constructor, syncDepartments()/
 * syncEmployees() stats shape, startSyncLog()/finishSyncLog() lifecycle)
 * per the directory-sync fixed contract.
 *
 * PERMISSION SCOPE — read this before wiring up cron/settings: this class
 * (via GraphClient::getUsers()) needs the Graph APPLICATION permission
 * User.Read.All, granted with admin consent on the SAME Entra app
 * registration Intune device sync already uses. That is a DIFFERENT
 * permission from DeviceManagementManagedDevices.Read.All (which device
 * sync needs) — Blake will need to grant User.Read.All separately in the
 * Entra portal before directory sync can run; Intune device sync keeps
 * working with only its existing permission if that grant hasn't happened.
 *
 * DEPARTMENTS — v1 uses the department STRING property on each Graph user
 * (user.department), not Entra group membership. This is the documented,
 * simpler-of-two option from the fixed contract: no group-membership Graph
 * calls needed, but it only reflects whatever free-text department value
 * is set on the user's Entra profile (no hierarchy, no dedup beyond exact
 * string match). Entra group membership would be more accurate (and could
 * carry a parent/child hierarchy the way Odoo's hr.department does) but
 * needs a heavier Graph call (transitive group membership per user, or a
 * group-to-department mapping UI of its own) — left as a documented
 * limitation for a future version, not silently half-built here.
 *
 * MATCHING, NO LINK TABLE — the fixed contract does not define
 * client_microsoft_links / contact_microsoft_links tables (unlike Odoo's
 * real client_odoo_links / contact_odoo_links, or Google's equivalents),
 * and this lane does not own db.sql/admin/database_updates.php to add
 * them. So there is no "Step 1: already-linked-by-external-id" fast path
 * here the way Odoo has — every sync run matches purely by value:
 *   - Departments: exact `clients.client_name` match among non-archived
 *     clients (Odoo's own Step 2), or create. There is no separate
 *     Microsoft "department id" to link against anyway in the v1
 *     string-property design above, so this loses nothing Odoo has for
 *     departments specifically.
 *   - Employees: exact `contacts.contact_email` match against ANY contact,
 *     archived or not (see syncEmployee() below for why this differs from
 *     the departments match just above), or create. A Graph user with
 *     neither `mail` nor `userPrincipalName` set is skipped rather than
 *     risking a duplicate contact every run, since there is no other
 *     durable identity signal available without a link table.
 * Net effect: this mapper never produces Odoo's "updated" outcome (that
 * requires a persisted link surviving an email change) — only
 * created/matched/skipped. See "deviations" in this lane's task output
 * for why, and a real client_microsoft_links/contact_microsoft_links pair
 * (mirroring client_odoo_links/contact_odoo_links exactly) is the fix, for
 * whichever lane owns schema next.
 *
 * TWO CONCRETE RISKS this email-only matching carries in practice, not just
 * a theoretical "no updated outcome":
 *   1. DUPLICATE CONTACTS ON EMAIL CHANGE. The match query searches by THIS
 *      run's Graph `mail`/`userPrincipalName` value. If that value changed
 *      since the contact was created (a rename, a domain migration), the
 *      row holding the OLD email is never found - this run creates a
 *      second contact instead of updating the first, and the mapper has
 *      no way to notice or reconcile the two afterward.
 *   2. UNRELATED ARCHIVES GET SILENTLY REACTIVATED. Because the match is
 *      not filtered to non-archived contacts (see above - that filter is
 *      what caused a re-enabled Entra user to wrongly duplicate instead of
 *      reactivate), any contact sharing that email gets un-archived the
 *      moment a Graph user with accountEnabled=true syncs to it - even if
 *      that contact was archived through a completely unrelated path (a
 *      manual archive, an Odoo-driven termination). There is no way for
 *      this mapper to tell "archived by a previous Microsoft sync run" apart
 *      from "archived for an unrelated reason" without a link table.
 * Both are accepted v1 trade-offs of the no-link-table design above, not
 * bugs to work around case-by-case - the fix for both is the same
 * client_microsoft_links/contact_microsoft_links pair.
 *
 * FIELD MAPPING — calls FieldMapping::forProvider($mysqli, 'microsoft')
 * for jobTitle, mobilePhone and businessPhones (first element only — Graph
 * returns this as an array; same "pick one field from a small set"
 * approach Odoo already uses). `mail` is deliberately NOT routed through
 * FieldMapping: it is this mapper's only identity signal (see "MATCHING,
 * NO LINK TABLE" above), so contact_email is always set directly from the
 * raw Graph `mail` value on create, matching OdooDirectoryMapper's own
 * current hardcoded treatment of work_email. `mail` is still offered as a
 * normal, editable, enabled-by-default Field Mapping row for the settings
 * UI (mirroring Odoo's seeded work_email -> contact_email row) so an admin
 * can SEE that mapping and turn it off if they want the other three
 * fields it might otherwise also drive — but doing so never disables
 * matching/creation itself, since this mapper has no fallback identity
 * signal to fall back on.
 *
 * One-way pull only — never writes to Microsoft/Entra.
 */
class MicrosoftDirectoryMapper
{
    private $mysqli;
    private int $microsoftIntegrationId;
    private int $triggeredBy;
    private ?array $clientDefaults = null;

    public function __construct($mysqli, int $microsoftIntegrationId, int $triggeredBy = 0)
    {
        $this->mysqli = $mysqli;
        $this->microsoftIntegrationId = $microsoftIntegrationId;
        $this->triggeredBy = $triggeredBy;
    }

    /**
     * Accepts, per element, whichever of these the caller has on hand —
     * this lane does not own the cron/settings code that will call it, and
     * the fixed contract itself names the parameter
     * $entraGroupsOrDepartments, leaving the exact shape open:
     *   - a plain department-name string, or
     *   - a raw Graph user dict (as returned by GraphClient::getUsers()) —
     *     its `department` field is read, and ONLY that field: a user with
     *     no department set contributes nothing (extractDepartmentName()
     *     deliberately does not fall back to displayName/name for an
     *     array entry — the real caller, settings_directory_sync.php,
     *     passes raw Graph user dicts here, and a name-based fallback
     *     fabricated a bogus one-off department for every such user).
     * Duplicate names (expected — many users share one department string)
     * are resolved once and reused; the `clients` table is never queried
     * twice for the same name within one run.
     *
     * @param array $entraGroupsOrDepartments see above
     * @return array{created:int,updated:int,matched:int,skipped:int,errors:string[],idMap:array<string,int>}
     */
    public function syncDepartments(array $entraGroupsOrDepartments): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => [], 'idMap' => []];
        $idMap = []; // departmentKey(name) => client_id, this run only (no persisted link table — see class docblock)

        foreach ($entraGroupsOrDepartments as $entry) {
            $name = $this->extractDepartmentName($entry);

            if ($name === '') {
                $stats['skipped']++;
                continue;
            }

            $key = $this->departmentKey($name);
            if (isset($idMap[$key])) {
                continue; // already resolved this run — don't re-query or double-count
            }

            try {
                [$outcome, $clientId] = $this->syncDepartment($name);
                $stats[$outcome]++;
                $idMap[$key] = $clientId;
            } catch (\Exception $e) {
                $stats['errors'][] = "$name: " . $e->getMessage();
                $stats['skipped']++;
            }
        }

        $stats['idMap'] = $idMap;

        return $stats;
    }

    /**
     * @param array $graphUsers raw Graph user dicts from GraphClient::getUsers()
     * @param array<string,int> $departmentIdMap from syncDepartments()['idMap']
     * @return array{created:int,updated:int,matched:int,skipped:int,errors:string[]}
     */
    public function syncEmployees(array $graphUsers, array $departmentIdMap): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];

        // Loaded once per run, not per user — same reasoning as
        // OdooDirectoryMapper caching client defaults: this table doesn't
        // change mid-sync, and a Graph tenant can easily have hundreds of
        // users.
        $fieldMap = FieldMapping::forProvider($this->mysqli, 'microsoft');

        foreach ($graphUsers as $user) {
            $label = trim((string) ($user['displayName'] ?? '')) ?: ((string) ($user['id'] ?? 'unknown'));
            try {
                $outcome = $this->syncEmployee($user, $departmentIdMap, $fieldMap);
                $stats[$outcome]++;
            } catch (\Exception $e) {
                $stats['errors'][] = "$label: " . $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /** @return array{0:string,1:int} [outcome, client_id] */
    private function syncDepartment(string $name): array
    {
        $m = $this->mysqli;
        $name_esc = mysqli_real_escape_string($m, $name);

        // Exact-name match among non-archived clients — Odoo's own Step 2
        // (see class docblock: there is no Step 1 link-table fast path here).
        $matched = mysqli_fetch_assoc(mysqli_query(
            $m,
            "SELECT client_id FROM clients WHERE client_name='$name_esc' AND client_archived_at IS NULL LIMIT 1"
        ));

        if ($matched) {
            return ['matched', intval($matched['client_id'])];
        }

        [$currency, $net_terms] = $this->getClientDefaults();
        $currency_esc = mysqli_real_escape_string($m, $currency);

        mysqli_query(
            $m,
            "INSERT INTO clients SET
             client_name='$name_esc', client_status='Active',
             client_currency_code='$currency_esc', client_net_terms=$net_terms,
             client_created_at=NOW()"
        );

        return ['created', intval(mysqli_insert_id($m))];
    }

    private function syncEmployee(array $user, array $departmentIdMap, array $fieldMap): string
    {
        $m = $this->mysqli;

        $name = trim((string) ($user['displayName'] ?? ''));
        // `mail` is Graph's real mailbox address; `userPrincipalName` is the
        // sign-in name and is usually (not always, e.g. B2B guests) also an
        // email — the same fallback GraphClient::listUsers() already uses
        // for ExternalUser::email.
        $email = trim((string) ($user['mail'] ?? $user['userPrincipalName'] ?? ''));

        if ($name === '') {
            return 'skipped';
        }

        // No durable identity signal without an email — see class docblock
        // "MATCHING, NO LINK TABLE". Syncing this user again next run with
        // no email would create a second contact rather than finding this
        // one, so it's skipped rather than risking that duplicate.
        if ($email === '') {
            return 'skipped';
        }

        $email_esc = mysqli_real_escape_string($m, $email);
        $name_esc = mysqli_real_escape_string($m, $name);

        $active = !empty($user['accountEnabled']);
        $employment_status = $active ? 'active' : 'terminated';

        $dept_name = trim((string) ($user['department'] ?? ''));
        $contact_client_id = $dept_name !== '' ? intval($departmentIdMap[$this->departmentKey($dept_name)] ?? 0) : 0;

        $mapped_set_sql = $this->buildMappedFieldsSql($user, $fieldMap);

        // Deliberately NOT filtered to non-archived contacts, unlike
        // syncDepartment()'s client match below — proven wrong by this
        // lane's own fixture test (see verificationRun): with no link
        // table, this email match IS this mapper's only "already known"
        // path (Odoo's equivalent Step 1), not just its Step 2 "is this a
        // genuinely new person" fallback. A Graph user disabled in one run
        // (contact_archived_at gets set below) and re-enabled in the next
        // must still find and reactivate that same contact — filtering to
        // non-archived here made a re-enabled employee's next sync silently
        // create a second contact with the same email instead.
        $matched = mysqli_fetch_assoc(mysqli_query(
            $m,
            "SELECT contact_id FROM contacts WHERE contact_email='$email_esc' LIMIT 1"
        ));

        if ($matched) {
            $contact_id = intval($matched['contact_id']);
            $this->applyEmployeeFields($contact_id, $name_esc, $employment_status, $active, $contact_client_id, $mapped_set_sql);
            return 'matched';
        }

        $archived_sql = $active ? 'NULL' : 'NOW()';
        mysqli_query(
            $m,
            "INSERT INTO contacts SET
             contact_name='$name_esc', contact_email='$email_esc',
             contact_client_id=$contact_client_id, contact_employee_type='employee',
             contact_employment_status='$employment_status', contact_archived_at=$archived_sql,
             contact_created_at=NOW()" . ($mapped_set_sql !== '' ? ", $mapped_set_sql" : '')
        );

        return 'created';
    }

    private function applyEmployeeFields(int $contactId, string $nameEsc, string $employmentStatus, bool $active, int $contactClientId, string $mappedSetSql): void
    {
        $sql = "UPDATE contacts SET
            contact_name='$nameEsc',
            contact_employment_status='$employmentStatus',
            contact_archived_at=" . ($active ? 'NULL' : 'COALESCE(contact_archived_at, NOW())');

        if ($mappedSetSql !== '') {
            $sql .= ", $mappedSetSql";
        }

        // Same reasoning as OdooDirectoryMapper::applyEmployeeFields(): only
        // move a contact's department when this run actually resolved one -
        // a transient lookup miss shouldn't regress an already-assigned
        // contact back to unassigned.
        if ($contactClientId > 0) {
            $sql .= ", contact_client_id=$contactClientId";
        }

        $sql .= " WHERE contact_id=$contactId";

        mysqli_query($this->mysqli, $sql);
    }

    // Builds the SET fragment for whatever contacts.* columns the admin has
    // enabled via Field Mapping for provider='microsoft' — jobTitle,
    // mobilePhone and businessPhones only (`mail` is handled separately in
    // syncEmployee(), see class docblock). $fieldMap is already filtered to
    // enabled rows with an allow-listed target_field by
    // FieldMapping::forProvider(), but isValidTargetField() is checked
    // again here anyway — target_field becomes a bare SQL column
    // identifier below, and the fixed contract requires that check at
    // every point a target_field reaches SQL, not just inside FieldMapping
    // itself.
    private function buildMappedFieldsSql(array $user, array $fieldMap): string
    {
        $m = $this->mysqli;

        $sourceValues = [
            'jobTitle' => trim((string) ($user['jobTitle'] ?? '')),
            'mobilePhone' => trim((string) ($user['mobilePhone'] ?? '')),
            // businessPhones is an array in Graph's response; only the
            // first element is used as this source field's value.
            'businessPhones' => trim((string) ($user['businessPhones'][0] ?? '')),
        ];

        $assignments = [];
        foreach ($fieldMap as $sourceField => $targetField) {
            if (!array_key_exists($sourceField, $sourceValues)) {
                continue; // a mapping row for a source field this provider doesn't offer (e.g. a stray 'mail' row, or garbage)
            }
            if (!FieldMapping::isValidTargetField($targetField)) {
                continue;
            }

            $value_esc = mysqli_real_escape_string($m, $sourceValues[$sourceField]);
            $assignments[] = "`$targetField`='$value_esc'";
        }

        return implode(', ', $assignments);
    }

    private function extractDepartmentName($entry): string
    {
        if (is_string($entry)) {
            return trim($entry);
        }
        if (is_array($entry)) {
            // Every real caller (settings_directory_sync.php) passes raw Graph
            // USER dicts here, not group/record entries - every one of those
            // carries a `department` key (possibly null/blank) AND a
            // `displayName`. A displayName/name fallback therefore fired for
            // every user with no department set, fabricating a bogus
            // department record in that user's own name. v1 is documented
            // (class docblock, "DEPARTMENTS") as user.department-only - a
            // blank department means "no department", full stop, not "guess
            // one from the name". No fallback for a user-shaped entry.
            $name = $entry['department'] ?? '';
            return trim((string) $name);
        }

        return '';
    }

    // Case-insensitive key so "Sales" and "sales" from two different Graph
    // responses (or a user vs. a group's displayName) resolve to the same
    // client instead of silently creating two departments.
    private function departmentKey(string $name): string
    {
        return strtolower($name);
    }

    // Same values, same reasoning as OdooDirectoryMapper::getClientDefaults()
    // - client_currency_code/client_net_terms are NOT NULL with no DB
    // default, so a newly-created client needs something. Not shared code
    // with OdooDirectoryMapper since these are two independent, self-
    // contained provider mappers (matches IntuneAssetMapper/
    // OdooDirectoryMapper's existing convention of not sharing a base
    // class).
    private function getClientDefaults(): array
    {
        if ($this->clientDefaults === null) {
            $m = $this->mysqli;

            $currency = 'USD';
            $row = mysqli_fetch_assoc(mysqli_query($m, "SELECT company_currency FROM companies LIMIT 1"));
            if ($row && !empty($row['company_currency'])) {
                $currency = $row['company_currency'];
            }

            $net_terms = 30;
            $row2 = mysqli_fetch_assoc(mysqli_query($m, "SELECT config_default_net_terms FROM settings LIMIT 1"));
            if ($row2 && $row2['config_default_net_terms'] !== null) {
                $net_terms = intval($row2['config_default_net_terms']);
            }

            $this->clientDefaults = [$currency, $net_terms];
        }

        return $this->clientDefaults;
    }

    public function startSyncLog(): int
    {
        $m = $this->mysqli;
        mysqli_query(
            $m,
            "INSERT INTO microsoft_directory_sync_log SET microsoft_integration_id={$this->microsoftIntegrationId}, triggered_by={$this->triggeredBy}"
        );

        return intval(mysqli_insert_id($m));
    }

    public function finishSyncLog(int $logId, array $deptStats, array $empStats): void
    {
        $m = $this->mysqli;

        $allErrors = array_merge($deptStats['errors'] ?? [], $empStats['errors'] ?? []);
        $status = empty($allErrors) ? 'success' : 'failed';
        $errors_esc = mysqli_real_escape_string($m, implode('; ', $allErrors));

        mysqli_query(
            $m,
            "UPDATE microsoft_directory_sync_log SET
             finished_at=NOW(), status='$status',
             departments_created={$deptStats['created']}, departments_updated={$deptStats['updated']},
             departments_matched={$deptStats['matched']}, departments_skipped={$deptStats['skipped']},
             employees_created={$empStats['created']}, employees_updated={$empStats['updated']},
             employees_matched={$empStats['matched']}, employees_skipped={$empStats['skipped']},
             errors='$errors_esc'
             WHERE id=$logId"
        );

        mysqli_query(
            $m,
            "UPDATE microsoft_integrations SET last_directory_sync_at = NOW() WHERE microsoft_integration_id = {$this->microsoftIntegrationId}"
        );
    }
}
