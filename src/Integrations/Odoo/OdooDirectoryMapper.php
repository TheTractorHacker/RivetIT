<?php

namespace ITFlow\Integrations\Odoo;

use ITFlow\Directory\FieldMapping;

/**
 * OdooDirectoryMapper — matches Odoo hr.department/hr.employee records
 * (pulled via OdooClient::listDepartments()/listEmployees()) to ITFlow
 * clients (departments) and contacts (employees). Mirrors
 * IntuneAssetMapper's structure/conventions closely: per-record try/catch,
 * a dedicated link table per entity, and a defensive "upsert the link row"
 * step to handle a matched/created local row that's already linked under a
 * different Odoo id (renamed/recreated department, rehired employee).
 *
 * Match priority for departments: (1) existing client_odoo_links row for
 * this odoo_department_id, (2) exact clients.client_name match among
 * non-archived clients, (3) create a new client.
 *
 * Match priority for employees: (1) existing contact_odoo_links row for
 * this odoo_employee_id, (2) exact contacts.contact_email match among
 * non-archived contacts, (3) create a new contact.
 *
 * One-way pull only - never writes to Odoo.
 */
class OdooDirectoryMapper {

    private $mysqli;
    private int $odooIntegrationId;
    private int $triggeredBy;
    private ?array $clientDefaults = null;

    public function __construct($mysqli, int $odooIntegrationId, int $triggeredBy = 0) {
        $this->mysqli            = $mysqli;
        $this->odooIntegrationId = $odooIntegrationId;
        $this->triggeredBy       = $triggeredBy;
    }

    /**
     * @param array $departments raw hr.department records from OdooClient::listDepartments()
     * @return array{created:int,updated:int,matched:int,skipped:int,errors:string[],idMap:array<int,int>}
     */
    public function syncDepartments(array $departments): array {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => [], 'idMap' => []];

        $clientMap = []; // odoo_department_id => client_id
        $parentMap = []; // odoo_department_id => parent odoo_department_id|null

        foreach ($departments as $dept) {
            $label = trim((string) ($dept['name'] ?? '')) ?: ('id ' . intval($dept['id'] ?? 0));
            try {
                [$outcome, $clientId] = $this->syncDepartment($dept);

                if ($outcome === 'skipped') {
                    $stats['skipped']++;
                    continue;
                }

                $stats[$outcome]++;

                $odoo_id = intval($dept['id']);
                $clientMap[$odoo_id] = $clientId;

                $parent_field = $dept['parent_id'] ?? false;
                $parentMap[$odoo_id] = is_array($parent_field) ? intval($parent_field[0]) : null;
            } catch (\Exception $e) {
                $stats['errors'][] = "$label: " . $e->getMessage();
                $stats['skipped']++;
            }
        }

        // Second pass: resolve client_parent_id now that every department in
        // this run has a client_id, since Odoo can return a child department
        // before its parent within one page of results.
        foreach ($clientMap as $odooId => $clientId) {
            $parentOdooId = $parentMap[$odooId] ?? null;
            $parentClientId = $parentOdooId !== null ? ($clientMap[$parentOdooId] ?? null) : null;

            if ($parentClientId !== null && $parentClientId === $clientId) {
                $stats['errors'][] = "Department (odoo id $odooId) lists itself as its own parent - skipped";
                continue;
            }

            $value = $parentClientId !== null ? $parentClientId : 'NULL';
            mysqli_query($this->mysqli, "UPDATE clients SET client_parent_id=$value WHERE client_id=$clientId");
        }

        $stats['idMap'] = $clientMap;

        return $stats;
    }

    /**
     * @param array $employees raw hr.employee records from OdooClient::listEmployees()
     * @param array<int,int> $departmentIdMap from syncDepartments()['idMap']
     * @return array{created:int,updated:int,matched:int,skipped:int,errors:string[]}
     */
    public function syncEmployees(array $employees, array $departmentIdMap): array {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];

        // Loaded once per run, not per employee - same reasoning as
        // MicrosoftDirectoryMapper::syncEmployees(): this table doesn't
        // change mid-sync. FieldMapping::forProvider() already filters to
        // enabled rows with an allow-listed target_field.
        $fieldMap = FieldMapping::forProvider($this->mysqli, 'odoo');

        foreach ($employees as $emp) {
            $label = trim((string) ($emp['name'] ?? '')) ?: ('id ' . intval($emp['id'] ?? 0));
            try {
                $outcome = $this->syncEmployee($emp, $departmentIdMap, $fieldMap);
                $stats[$outcome]++;
            } catch (\Exception $e) {
                $stats['errors'][] = "$label: " . $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /** @return array{0:string,1:?int} [outcome, client_id|null] */
    private function syncDepartment(array $dept): array {
        $m       = $this->mysqli;
        $intg_id = $this->odooIntegrationId;

        $odoo_department_id = intval($dept['id'] ?? 0);
        $name = trim((string) ($dept['name'] ?? ''));

        if ($odoo_department_id <= 0 || $name === '') {
            return ['skipped', null];
        }

        $name_esc = mysqli_real_escape_string($m, $name);

        // ----- Step 1: check existing link -----
        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT client_id FROM client_odoo_links WHERE odoo_integration_id=$intg_id AND odoo_department_id=$odoo_department_id LIMIT 1"
        ));

        if ($existing) {
            $client_id = intval($existing['client_id']);
            mysqli_query($m, "UPDATE clients SET client_name='$name_esc' WHERE client_id=$client_id");
            return ['updated', $client_id];
        }

        // ----- Step 2: exact-name match among non-archived clients -----
        $matched = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT client_id FROM clients WHERE client_name='$name_esc' AND client_archived_at IS NULL LIMIT 1"
        ));

        if ($matched) {
            $client_id = intval($matched['client_id']);
            $this->upsertDepartmentLink($client_id, $odoo_department_id);
            return ['matched', $client_id];
        }

        // ----- Step 3: create a new client -----
        [$currency, $net_terms] = $this->getClientDefaults();
        $currency_esc = mysqli_real_escape_string($m, $currency);

        mysqli_query($m,
            "INSERT INTO clients SET
             client_name='$name_esc', client_status='Active',
             client_currency_code='$currency_esc', client_net_terms=$net_terms,
             client_created_at=NOW()"
        );
        $client_id = intval(mysqli_insert_id($m));
        $this->upsertDepartmentLink($client_id, $odoo_department_id);

        return ['created', $client_id];
    }

    private function upsertDepartmentLink(int $clientId, int $odooDepartmentId): void {
        $m       = $this->mysqli;
        $intg_id = $this->odooIntegrationId;

        // client_odoo_links has a UNIQUE KEY on (client_id, odoo_integration_id) -
        // the client we just matched/created may already carry a link row for
        // this integration under a *different* odoo_department_id (e.g. the
        // department was renamed/recreated on the Odoo side). Update that row
        // in place instead of blind-inserting, which would otherwise throw a
        // duplicate-key error and abort the sync for this department.
        $existing_link = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id FROM client_odoo_links WHERE client_id=$clientId AND odoo_integration_id=$intg_id LIMIT 1"
        ));

        if ($existing_link) {
            mysqli_query($m, "UPDATE client_odoo_links SET odoo_department_id=$odooDepartmentId WHERE id=" . intval($existing_link['id']));
        } else {
            mysqli_query($m, "INSERT INTO client_odoo_links SET client_id=$clientId, odoo_integration_id=$intg_id, odoo_department_id=$odooDepartmentId");
        }
    }

    private function syncEmployee(array $emp, array $departmentIdMap, array $fieldMap): string {
        $m       = $this->mysqli;
        $intg_id = $this->odooIntegrationId;

        $odoo_employee_id = intval($emp['id'] ?? 0);
        $name = trim((string) ($emp['name'] ?? ''));

        if ($odoo_employee_id <= 0 || $name === '') {
            return 'skipped';
        }

        $name_esc  = mysqli_real_escape_string($m, $name);
        // work_email is the unconditional match/create identity field, same
        // as Microsoft's mail/userPrincipalName - it is never itself
        // reconfigurable via Field Mapping, only the other 3 source fields
        // (job_title/work_phone/mobile_phone) are.
        $email     = trim((string) ($emp['work_email'] ?? ''));
        $email_esc = mysqli_real_escape_string($m, $email);
        $mapped_set_sql = $this->buildMappedFieldsSql($emp, $fieldMap);
        $active    = !empty($emp['active']);
        $employment_status = $active ? 'active' : 'terminated';

        $dept_field   = $emp['department_id'] ?? false;
        $dept_odoo_id = is_array($dept_field) ? intval($dept_field[0]) : null;
        $contact_client_id = ($dept_odoo_id !== null && isset($departmentIdMap[$dept_odoo_id]))
            ? intval($departmentIdMap[$dept_odoo_id])
            : 0;

        // ----- Step 1: check existing link -----
        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT contact_id FROM contact_odoo_links WHERE odoo_integration_id=$intg_id AND odoo_employee_id=$odoo_employee_id LIMIT 1"
        ));

        if ($existing) {
            $contact_id = intval($existing['contact_id']);
            $this->applyEmployeeFields($contact_id, $name_esc, $email, $email_esc, $employment_status, $active, $contact_client_id, $mapped_set_sql);
            return 'updated';
        }

        // ----- Step 2: match by email among non-archived contacts -----
        $contact_id = null;
        if ($email !== '') {
            $matched = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT contact_id FROM contacts WHERE contact_email='$email_esc' AND contact_archived_at IS NULL LIMIT 1"
            ));
            if ($matched) {
                $contact_id = intval($matched['contact_id']);
            }
        }

        if ($contact_id !== null) {
            $this->applyEmployeeFields($contact_id, $name_esc, $email, $email_esc, $employment_status, $active, $contact_client_id, $mapped_set_sql);
            $this->upsertEmployeeLink($contact_id, $odoo_employee_id);
            return 'matched';
        }

        // ----- Step 3: create a new contact -----
        $archived_sql = $active ? 'NULL' : 'NOW()';
        mysqli_query($m,
            "INSERT INTO contacts SET
             contact_name='$name_esc', contact_email='$email_esc',
             contact_client_id=$contact_client_id, contact_employee_type='employee',
             contact_employment_status='$employment_status', contact_archived_at=$archived_sql,
             contact_created_at=NOW()" . ($mapped_set_sql !== '' ? ", $mapped_set_sql" : '')
        );
        $contact_id = intval(mysqli_insert_id($m));
        $this->upsertEmployeeLink($contact_id, $odoo_employee_id);

        return 'created';
    }

    // Builds the SET fragment for whatever contacts.* columns the admin has
    // enabled via Field Mapping for provider='odoo' (job_title/work_phone/
    // mobile_phone - work_email is handled separately in syncEmployee(),
    // it is this mapper's identity field and not itself reconfigurable).
    // $fieldMap is already filtered to enabled rows with an allow-listed
    // target_field by FieldMapping::forProvider(), but isValidTargetField()
    // is checked again here anyway - same reasoning as
    // MicrosoftDirectoryMapper::buildMappedFieldsSql(): target_field becomes
    // a bare SQL column identifier below, and the fixed contract requires
    // that check at every point a target_field reaches SQL.
    private function buildMappedFieldsSql(array $emp, array $fieldMap): string {
        $m = $this->mysqli;

        $sourceValues = [
            'job_title'     => trim((string) ($emp['job_title'] ?? '')),
            'work_phone'    => trim((string) ($emp['work_phone'] ?? '')),
            'mobile_phone'  => trim((string) ($emp['mobile_phone'] ?? '')),
        ];

        $assignments = [];
        foreach ($fieldMap as $sourceField => $targetField) {
            if (!array_key_exists($sourceField, $sourceValues)) {
                continue; // a mapping row for a source field this provider doesn't offer (e.g. a stray 'work_email' row, or garbage)
            }
            if (!FieldMapping::isValidTargetField($targetField)) {
                continue;
            }

            $value_esc = mysqli_real_escape_string($m, $sourceValues[$sourceField]);
            $assignments[] = "`$targetField`='$value_esc'";
        }

        return implode(', ', $assignments);
    }

    // $email/$emailEsc kept separate since an empty Odoo work_email should never
    // blank out a human-entered contact_email - the field is only included in
    // the SET clause when Odoo actually supplied a value.
    private function applyEmployeeFields(int $contactId, string $nameEsc, string $email, string $emailEsc, string $employmentStatus, bool $active, int $contactClientId, string $mappedSetSql): void {
        $sql = "UPDATE contacts SET
            contact_name='$nameEsc',
            contact_employment_status='$employmentStatus',
            contact_archived_at=" . ($active ? 'NULL' : 'COALESCE(contact_archived_at, NOW())');

        if ($email !== '') {
            $sql .= ", contact_email='$emailEsc'";
        }
        if ($mappedSetSql !== '') {
            $sql .= ", $mappedSetSql";
        }
        // Only move a contact's department when this run actually resolved one -
        // a transient lookup miss (e.g. that department errored this run)
        // shouldn't regress an already-assigned contact back to unassigned.
        if ($contactClientId > 0) {
            $sql .= ", contact_client_id=$contactClientId";
        }

        $sql .= " WHERE contact_id=$contactId";

        mysqli_query($this->mysqli, $sql);
    }

    private function upsertEmployeeLink(int $contactId, int $odooEmployeeId): void {
        $m       = $this->mysqli;
        $intg_id = $this->odooIntegrationId;

        // Same defensive upsert as upsertDepartmentLink() - a matched contact
        // may already carry a link row for this integration under a different
        // odoo_employee_id (e.g. a rehired employee gets a new Odoo employee id
        // but keeps the same work email).
        $existing_link = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id FROM contact_odoo_links WHERE contact_id=$contactId AND odoo_integration_id=$intg_id LIMIT 1"
        ));

        if ($existing_link) {
            mysqli_query($m, "UPDATE contact_odoo_links SET odoo_employee_id=$odooEmployeeId WHERE id=" . intval($existing_link['id']));
        } else {
            mysqli_query($m, "INSERT INTO contact_odoo_links SET contact_id=$contactId, odoo_integration_id=$intg_id, odoo_employee_id=$odooEmployeeId");
        }
    }

    // client_currency_code/client_net_terms are NOT NULL with no DB default,
    // so a newly-created client needs something - source the same values
    // agent/post/client.php's own creators use (companies.company_currency,
    // settings.config_default_net_terms), cached for the life of this mapper.
    private function getClientDefaults(): array {
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

    public function startSyncLog(): int {
        $m = $this->mysqli;
        mysqli_query($m,
            "INSERT INTO odoo_sync_log SET odoo_integration_id={$this->odooIntegrationId}, triggered_by={$this->triggeredBy}"
        );
        return intval(mysqli_insert_id($m));
    }

    public function finishSyncLog(int $logId, array $deptStats, array $empStats): void {
        $m = $this->mysqli;

        $allErrors = array_merge($deptStats['errors'] ?? [], $empStats['errors'] ?? []);
        $status = empty($allErrors) ? 'success' : 'failed';
        $errors_esc = mysqli_real_escape_string($m, implode('; ', $allErrors));

        mysqli_query($m,
            "UPDATE odoo_sync_log SET
             finished_at=NOW(), status='$status',
             departments_created={$deptStats['created']}, departments_updated={$deptStats['updated']},
             departments_matched={$deptStats['matched']}, departments_skipped={$deptStats['skipped']},
             employees_created={$empStats['created']}, employees_updated={$empStats['updated']},
             employees_matched={$empStats['matched']}, employees_skipped={$empStats['skipped']},
             errors='$errors_esc'
             WHERE id=$logId"
        );

        mysqli_query($m,
            "UPDATE odoo_integrations SET last_sync_at = NOW() WHERE odoo_integration_id = {$this->odooIntegrationId}"
        );
    }
}
