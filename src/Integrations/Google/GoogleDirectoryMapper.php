<?php

namespace ITFlow\Integrations\Google;

use ITFlow\Directory\FieldMapping;

/**
 * GoogleDirectoryMapper — matches Google Workspace org units/users (pulled
 * via GoogleDirectoryClient::listOrgUnits()/listUsers()) to RivetIT clients
 * (departments) and contacts (employees). Mirrors
 * OdooDirectoryMapper's structure/conventions closely: per-record
 * try/catch, a department-then-employee two-pass sync so a child org unit
 * seen before its parent within one page still resolves correctly, and the
 * same startSyncLog()/finishSyncLog() pattern against this provider's own
 * sync-log table (google_sync_log).
 *
 * DEVIATION FROM THE ODOO PATTERN (documented per the workflow's "say so
 * loudly" instruction, not silent): THE FIXED CONTRACT specifies
 * google_integrations and google_sync_log tables but no client_google_links
 * / contact_google_links tables analogous to Odoo's client_odoo_links /
 * contact_odoo_links, and this lane owns only src/Integrations/Google/ - it
 * may not invent a table that isn't in the contract. So there is no
 * "existing link row" match step here: departments match by exact
 * clients.client_name, employees match by exact contacts.contact_email -
 * the same identifiers Odoo's own step-2 fallback uses, but WITHOUT
 * Odoo's "AND ...archived_at IS NULL" restriction (see syncDepartment()'s
 * and syncEmployee()'s own comments for exactly why - short version:
 * Odoo can afford to exclude archived rows there because its step-1
 * link-table lookup, unaffected by archived status, is what makes every
 * later run find the row again regardless; this class has no step 1, so
 * its only match query has to keep working even after the matched row
 * gets archived, or a terminated/suspended user duplicates on every
 * subsequent sync - a real bug this class's own scratch-DB test caught
 * before it shipped, see handoffNotes).
 * Practical effect: the 'updated' outcome never fires (there's no
 * link-table event that means "we've synced this exact external record
 * before"; a re-sync of an already-matched record reports 'matched'
 * again, not 'updated') - the stats array keys are unchanged and no
 * caller needs to change, but their distribution differs from Odoo's. It
 * also means a renamed org unit or an employee whose primaryEmail changed
 * since the last sync will not be re-linked to its old client/contact row
 * - it will read as a new department/employee instead.
 *
 * THIS IS NOT ONLY A RENAME-TIME EDGE CASE - bare-name matching against
 * `clients.client_name` can collide on the very FIRST sync, not just after
 * a later rename: if a client/department with that exact name already
 * exists for any unrelated reason (created by hand, or by Odoo sync -
 * "Sales" is exactly the kind of name two independent systems both use),
 * this mapper silently attaches to and starts writing Google-sourced
 * employees into it, with no signal to the admin that two different
 * external sources are now feeding the same department. If full parity
 * with Odoo's rename-survival AND day-one collision safety is wanted
 * later, add client_google_links/contact_google_links mirroring
 * client_odoo_links/contact_odoo_links exactly and this class can adopt
 * the same three-step match Odoo uses.
 *
 * FIELD MAPPING: contact_name (from name.fullName) and the department
 * link (from orgUnitPath, structural - always used to resolve
 * contact_client_id regardless of what's mapped) are NOT configurable,
 * exactly mirroring how Odoo's own 'name' and 'department_id' fields are
 * hardcoded/structural and were never among the four seeded
 * directory_field_mappings rows. Every OTHER contact column
 * (contact_title, contact_phone, contact_mobile, contact_email, and
 * contact_department if the admin chooses to target it) is written only
 * per FieldMapping::forProvider($mysqli, 'google') - see
 * resolveTargetValues() - never hardcoded here, per THE FIXED CONTRACT.
 * primaryEmail is read directly (not gated on its own mapping being
 * enabled) ONLY to decide which local contact this external user
 * corresponds to; whether it's actually WRITTEN into contact_email still
 * depends entirely on whether the admin has that mapping enabled, exactly
 * mirroring OdooDirectoryMapper::applyEmployeeFields()'s own
 * "$email/$emailEsc kept separate" comment.
 *
 * The five source_field strings this class recognizes for the google
 * provider - these MUST be the exact literal strings any migration/seed
 * data and the settings UI's mapping-row options use, or a configured row
 * will silently resolve to '' every sync (see extractSourceValue()'s
 * default case) rather than error:
 *   organizations[0].title  (index 0 literally, per THE FIXED CONTRACT's
 *                            own naming - not "whichever entry is flagged
 *                            primary", since Google does not guarantee
 *                            primary is at index 0 and the contract named
 *                            this field literally)
 *   phones.work              (phones[] entry with type === "work")
 *   phones.mobile             (phones[] entry with type === "mobile")
 *   primaryEmail
 *   orgUnitPath                (also always used structurally, see above -
 *                               a mapping for it, e.g. to contact_department,
 *                               is purely an ADDITIONAL write, never a
 *                               substitute for the structural department link)
 * All five confirmed against Google's live Admin SDK Directory API REST
 * reference for the User resource on 2026-09-09 (see
 * GoogleDirectoryClient's docblock) - phones[].type's real enum includes
 * "work" and "mobile" exactly as named.
 *
 * One-way pull only - never writes to Google.
 */
class GoogleDirectoryMapper
{
    private $mysqli;
    private int $googleIntegrationId;
    private int $triggeredBy;
    private ?array $clientDefaults = null;

    public function __construct($mysqli, int $googleIntegrationId, int $triggeredBy = 0)
    {
        $this->mysqli = $mysqli;
        $this->googleIntegrationId = $googleIntegrationId;
        $this->triggeredBy = $triggeredBy;
    }

    /**
     * @param array $orgUnits raw Admin SDK orgunit resources from GoogleDirectoryClient::listOrgUnits()
     * @return array{created:int,updated:int,matched:int,skipped:int,errors:string[],idMap:array<string,int>}
     *   idMap is keyed by orgUnitPath (string), NOT a numeric external id -
     *   orgUnitPath is Google's own stable, unique handle for an org unit
     *   and is exactly what a user resource's own orgUnitPath field needs
     *   to look up against in syncEmployees() below, the same role
     *   OdooDirectoryMapper's numeric odoo_department_id idMap keys play
     *   for hr.employee.department_id.
     */
    public function syncDepartments(array $orgUnits): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => [], 'idMap' => []];

        $clientMap = []; // orgUnitPath => client_id
        $parentMap = []; // orgUnitPath => parentOrgUnitPath|null

        foreach ($orgUnits as $ou) {
            $label = trim((string) ($ou['name'] ?? '')) ?: (trim((string) ($ou['orgUnitPath'] ?? '')) ?: 'unnamed org unit');
            try {
                [$outcome, $clientId, $path] = $this->syncDepartment($ou);

                if ($outcome === 'skipped') {
                    $stats['skipped']++;
                    continue;
                }

                $stats[$outcome]++;
                $clientMap[$path] = $clientId;
                $parentMap[$path] = trim((string) ($ou['parentOrgUnitPath'] ?? '')) ?: null;
            } catch (\Exception $e) {
                $stats['errors'][] = "$label: " . $e->getMessage();
                $stats['skipped']++;
            }
        }

        // Second pass: resolve client_parent_id now that every org unit in
        // this run has a client_id, since Google can return a child org unit
        // before its parent within one page of results (same reasoning as
        // OdooDirectoryMapper::syncDepartments()'s own second pass).
        foreach ($clientMap as $path => $clientId) {
            $parentPath = $parentMap[$path] ?? null;
            $parentClientId = $parentPath !== null ? ($clientMap[$parentPath] ?? null) : null;

            if ($parentClientId !== null && $parentClientId === $clientId) {
                $stats['errors'][] = "Org unit ($path) lists itself as its own parent - skipped";
                continue;
            }

            $value = $parentClientId !== null ? $parentClientId : 'NULL';
            mysqli_query($this->mysqli, "UPDATE clients SET client_parent_id=$value WHERE client_id=$clientId");
        }

        $stats['idMap'] = $clientMap;

        return $stats;
    }

    /**
     * @param array $users raw Admin SDK user resources from GoogleDirectoryClient::listUsers()
     * @param array<string,int> $departmentIdMap from syncDepartments()['idMap'], keyed by orgUnitPath
     * @return array{created:int,updated:int,matched:int,skipped:int,errors:string[]}
     */
    public function syncEmployees(array $users, array $departmentIdMap): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($users as $user) {
            $label = trim((string) ($user['primaryEmail'] ?? '')) ?: ('id ' . trim((string) ($user['id'] ?? '')));
            try {
                $outcome = $this->syncEmployee($user, $departmentIdMap);
                $stats[$outcome]++;
            } catch (\Exception $e) {
                $stats['errors'][] = "$label: " . $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /** @return array{0:string,1:?int,2:?string} [outcome, client_id|null, orgUnitPath|null] */
    private function syncDepartment(array $ou): array
    {
        $m = $this->mysqli;

        $name = trim((string) ($ou['name'] ?? ''));
        $path = trim((string) ($ou['orgUnitPath'] ?? ''));

        if ($name === '' || $path === '') {
            return ['skipped', null, null];
        }

        $name_esc = mysqli_real_escape_string($m, $name);

        // No client_google_links table exists in THE FIXED CONTRACT (unlike
        // Odoo's client_odoo_links) - match purely by exact client_name.
        // Deliberately NOT restricted to non-archived clients, unlike
        // Odoo's own step-2 fallback: Odoo can afford that restriction
        // because its step-1 link-table lookup (unaffected by archived
        // status) is what makes every run AFTER the first one find an
        // already-matched row again. This class has no such step 1 - this
        // query IS the only match path on every run - so excluding archived
        // clients here would mean a client that later gets archived can
        // never be found again and gets recreated as a duplicate on every
        // subsequent sync. Caught by this class's own scratch-DB test
        // (see handoffNotes) before it shipped.
        $matched = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT client_id FROM clients WHERE client_name='$name_esc' LIMIT 1"
        ));

        if ($matched) {
            $client_id = intval($matched['client_id']);
            mysqli_query($m, "UPDATE clients SET client_name='$name_esc' WHERE client_id=$client_id");
            return ['matched', $client_id, $path];
        }

        [$currency, $net_terms] = $this->getClientDefaults();
        $currency_esc = mysqli_real_escape_string($m, $currency);

        mysqli_query($m,
            "INSERT INTO clients SET
             client_name='$name_esc', client_status='Active',
             client_currency_code='$currency_esc', client_net_terms=$net_terms,
             client_created_at=NOW()"
        );
        $client_id = intval(mysqli_insert_id($m));

        return ['created', $client_id, $path];
    }

    private function syncEmployee(array $user, array $departmentIdMap): string
    {
        $m = $this->mysqli;

        $google_user_id = trim((string) ($user['id'] ?? ''));
        $email = trim((string) ($user['primaryEmail'] ?? ''));

        // primaryEmail is required on every real Google Workspace account -
        // without it (or an id) there's no stable identity to sync on at all.
        if ($google_user_id === '' || $email === '') {
            return 'skipped';
        }

        $fullName = trim((string) ($user['name']['fullName'] ?? ''));
        if ($fullName === '') {
            // Sparse fixture/edge-case fallback so a record with no display
            // name still gets a usable contact_name instead of being skipped
            // outright - Odoo's equivalent requires a non-blank 'name' to
            // sync at all, but Google's primaryEmail is a far more reliable
            // required field to hang identity on than Odoo's optional name.
            $localPart = strstr($email, '@', true);
            $fullName = $localPart !== false ? $localPart : $email;
        }

        $suspended = !empty($user['suspended']);
        $employment_status = $suspended ? 'terminated' : 'active';

        $orgUnitPath = trim((string) ($user['orgUnitPath'] ?? ''));
        $contact_client_id = ($orgUnitPath !== '' && isset($departmentIdMap[$orgUnitPath]))
            ? intval($departmentIdMap[$orgUnitPath])
            : 0;

        // [target_field => raw value] for every ENABLED google mapping row
        // that resolved to a non-empty value on this record - see
        // resolveTargetValues()/extractSourceValue().
        $mappedTargets = $this->resolveTargetValues($user);

        // ----- Step 1: match by email (no link table - see class docblock)
        // -----
        // Deliberately NOT restricted to non-archived contacts, unlike
        // Odoo's own step-2 email match: this class has no step-1 link-table
        // lookup to fall back on for a repeat run, so this query alone has
        // to keep finding the same contact on every sync no matter its
        // archived status - a suspended/terminated Google user gets
        // archived here (see applyEmployeeFields()'s contact_archived_at
        // handling below), and the NEXT sync run must still match that same
        // now-archived contact rather than recreate it. Confirmed by this
        // class's own scratch-DB round-2 idempotency test (see
        // handoffNotes) - restricting this to non-archived rows, mirroring
        // Odoo verbatim, produced a fresh duplicate contact for every
        // already-terminated employee on every subsequent sync.
        $matched = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT contact_id FROM contacts WHERE contact_email='" . mysqli_real_escape_string($m, $email) . "' LIMIT 1"
        ));

        if ($matched) {
            $contact_id = intval($matched['contact_id']);
            $this->applyEmployeeFields($contact_id, $fullName, $mappedTargets, $employment_status, !$suspended, $contact_client_id);
            return 'matched';
        }

        // ----- Step 2: create a new contact -----
        // contact_email is set unconditionally here (unlike the update path)
        // - a newly-created, Google-sourced contact should always start out
        // seeded with the address it was found by, structural rather than
        // subject to the field-mapping toggle, exactly mirroring
        // OdooDirectoryMapper::syncEmployee()'s own unconditional
        // contact_email in its create branch.
        $fields = array_merge(['contact_name' => $fullName, 'contact_email' => $email], $mappedTargets);

        $setParts = [];
        foreach ($fields as $col => $val) {
            $setParts[] = "$col='" . mysqli_real_escape_string($m, (string) $val) . "'";
        }
        $setParts[] = "contact_employment_status='" . mysqli_real_escape_string($m, $employment_status) . "'";
        $setParts[] = "contact_client_id=$contact_client_id";
        $setParts[] = "contact_employee_type='employee'";
        $setParts[] = 'contact_archived_at=' . ($suspended ? 'NOW()' : 'NULL');
        $setParts[] = 'contact_created_at=NOW()';

        mysqli_query($m, 'INSERT INTO contacts SET ' . implode(', ', $setParts));

        return 'created';
    }

    // mappedTargets can re-specify contact_name (e.g. an admin deliberately
    // mapping primaryEmail onto contact_name) - that explicit, admin-configured
    // choice is applied on top of (wins over) this structural default, which
    // is the "full control" THE FIXED CONTRACT asks for.
    private function applyEmployeeFields(int $contactId, string $fullName, array $mappedTargets, string $employmentStatus, bool $active, int $contactClientId): void
    {
        $m = $this->mysqli;

        $fields = array_merge(['contact_name' => $fullName], $mappedTargets);

        $setParts = [];
        foreach ($fields as $col => $val) {
            $setParts[] = "$col='" . mysqli_real_escape_string($m, (string) $val) . "'";
        }
        $setParts[] = "contact_employment_status='" . mysqli_real_escape_string($m, $employmentStatus) . "'";
        $setParts[] = 'contact_archived_at=' . ($active ? 'NULL' : 'COALESCE(contact_archived_at, NOW())');
        // Only move a contact's department when this run actually resolved
        // one - a transient lookup miss (e.g. that org unit errored this
        // run) shouldn't regress an already-assigned contact back to
        // unassigned, same defensive reasoning as
        // OdooDirectoryMapper::applyEmployeeFields().
        if ($contactClientId > 0) {
            $setParts[] = "contact_client_id=$contactClientId";
        }

        $sql = 'UPDATE contacts SET ' . implode(', ', $setParts) . " WHERE contact_id=$contactId";
        mysqli_query($m, $sql);
    }

    /**
     * @return array<string,string> [target_field => raw value] - only for
     *   ENABLED google mappings whose extracted value is non-empty; a
     *   mapping whose target_field fails the allow-list check, or whose
     *   source_field this class doesn't recognize, or whose value came back
     *   blank, simply contributes nothing (never fatal, never blanks an
     *   existing contact column - applyEmployeeFields()/the create path
     *   above only ever SET a column this returns a key for).
     */
    private function resolveTargetValues(array $user): array
    {
        $targets = [];

        foreach (FieldMapping::forProvider($this->mysqli, 'google') as $sourceField => $targetField) {
            // target_field ultimately comes from admin-supplied, DB-stored
            // config (directory_field_mappings), so even though
            // FieldMapping::forProvider() is expected to only ever return
            // real column names, this class never trusts that assumption
            // blindly before interpolating a target_field into a raw SQL
            // identifier position, per THE FIXED CONTRACT's explicit
            // instruction - re-checked here against the SAME shared
            // allow-list forProvider() itself already filtered by
            // (FieldMapping::isValidTargetField()), not a second,
            // independently-maintained copy of it.
            if (!FieldMapping::isValidTargetField($targetField)) {
                continue;
            }

            $value = $this->extractSourceValue($user, $sourceField);
            if ($value !== '') {
                $targets[$targetField] = $value;
            }
        }

        return $targets;
    }

    private function extractSourceValue(array $user, string $sourceField): string
    {
        switch ($sourceField) {
            case 'primaryEmail':
                return trim((string) ($user['primaryEmail'] ?? ''));

            case 'organizations[0].title':
                $orgs = $user['organizations'] ?? [];
                return trim((string) ($orgs[0]['title'] ?? ''));

            case 'phones.work':
                return $this->extractPhoneByType($user, 'work');

            case 'phones.mobile':
                return $this->extractPhoneByType($user, 'mobile');

            case 'orgUnitPath':
                return trim((string) ($user['orgUnitPath'] ?? ''));

            default:
                // An unrecognized source_field (e.g. left over from a schema/UI
                // change this class predates) - never fatal, just contributes
                // nothing, same as a disabled or blank-valued mapping.
                return '';
        }
    }

    private function extractPhoneByType(array $user, string $type): string
    {
        foreach ($user['phones'] ?? [] as $phone) {
            if (($phone['type'] ?? '') === $type) {
                return trim((string) ($phone['value'] ?? ''));
            }
        }

        return '';
    }

    // client_currency_code/client_net_terms are NOT NULL with no DB default,
    // so a newly-created client needs something - same source as
    // OdooDirectoryMapper::getClientDefaults() (companies.company_currency,
    // settings.config_default_net_terms), cached for the life of this mapper.
    // Duplicated rather than shared since neither class exposes this as a
    // reusable helper and this lane owns only src/Integrations/Google/.
    private function getClientDefaults(): array
    {
        if ($this->clientDefaults === null) {
            $m = $this->mysqli;

            $currency = 'USD';
            $row = mysqli_fetch_assoc(mysqli_query($m, 'SELECT company_currency FROM companies LIMIT 1'));
            if ($row && !empty($row['company_currency'])) {
                $currency = $row['company_currency'];
            }

            $net_terms = 30;
            $row2 = mysqli_fetch_assoc(mysqli_query($m, 'SELECT config_default_net_terms FROM settings LIMIT 1'));
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
        mysqli_query($m,
            "INSERT INTO google_sync_log SET google_integration_id={$this->googleIntegrationId}, triggered_by={$this->triggeredBy}"
        );

        return intval(mysqli_insert_id($m));
    }

    public function finishSyncLog(int $logId, array $deptStats, array $empStats): void
    {
        $m = $this->mysqli;

        $allErrors = array_merge($deptStats['errors'] ?? [], $empStats['errors'] ?? []);
        $status = empty($allErrors) ? 'success' : 'failed';
        $errors_esc = mysqli_real_escape_string($m, implode('; ', $allErrors));

        mysqli_query($m,
            "UPDATE google_sync_log SET
             finished_at=NOW(), status='$status',
             departments_created={$deptStats['created']}, departments_updated={$deptStats['updated']},
             departments_matched={$deptStats['matched']}, departments_skipped={$deptStats['skipped']},
             employees_created={$empStats['created']}, employees_updated={$empStats['updated']},
             employees_matched={$empStats['matched']}, employees_skipped={$empStats['skipped']},
             errors='$errors_esc'
             WHERE id=$logId"
        );

        mysqli_query($m,
            "UPDATE google_integrations SET last_sync_at = NOW() WHERE google_integration_id = {$this->googleIntegrationId}"
        );
    }
}
