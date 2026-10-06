<?php

namespace ITFlow\Directory;

/**
 * People CSV import (master plan Section 7.4). Two-step: preview() parses
 * and validates without writing anything; commit() writes only rows the
 * caller has already shown the user and had approved. Nothing is written
 * on preview - matches the plan's explicit "no records are written until
 * preview is approved."
 *
 * Expected CSV header (order doesn't matter, matched by name):
 *   employee_id, name, email, job_title, department, site, manager_email,
 *   phone, mobile, start_date, employee_type, employment_status, work_arrangement
 *
 * "department" is matched against clients.client_name (the Department
 * entity), "site" against locations.location_name scoped to that
 * department. Simplification vs. the full plan: no interactive column-
 * mapping UI - the header names above are required as-is. A generic mapper
 * is real added complexity for zero benefit at today's scale (one company,
 * one HR person doing occasional imports) - revisit if that changes.
 */
class PersonImportService
{
    private \mysqli $mysqli;

    private const REQUIRED_HEADERS = ['name', 'email'];
    private const KNOWN_HEADERS = [
        'employee_id', 'name', 'email', 'job_title', 'department', 'site',
        'manager_email', 'phone', 'mobile', 'start_date', 'employee_type',
        'employment_status', 'work_arrangement',
    ];
    private const VALID_EMPLOYEE_TYPES = ['employee', 'contractor', 'vendor', 'intern', 'service_account_owner'];
    private const VALID_EMPLOYMENT_STATUSES = ['pre-hire', 'active', 'leave', 'suspended', 'transfer_pending', 'termination_pending', 'terminated', 'archived'];
    private const VALID_WORK_ARRANGEMENTS = ['remote', 'hybrid', 'onsite'];

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @return array{headerError: ?string, rows: array}
     */
    public function preview(string $csvFilePath): array
    {
        $handle = fopen($csvFilePath, 'r');
        if (!$handle) {
            return ['headerError' => 'Could not read the uploaded file.', 'rows' => []];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return ['headerError' => 'The file is empty.', 'rows' => []];
        }
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $missing = array_diff(self::REQUIRED_HEADERS, $header);
        if ($missing) {
            fclose($handle);
            return ['headerError' => 'Missing required column(s): ' . implode(', ', $missing), 'rows' => []];
        }

        $previewRows = [];
        $seenInFile = []; // dedup within the file itself: email => true
        while (($line = fgetcsv($handle)) !== false) {
            if (count(array_filter($line, fn($v) => trim((string) $v) !== '')) === 0) {
                continue; // skip blank lines
            }
            $raw = [];
            foreach ($header as $i => $col) {
                if (in_array($col, self::KNOWN_HEADERS, true)) {
                    $raw[$col] = trim($line[$i] ?? '');
                }
            }
            $previewRows[] = $this->validateRow($raw, $seenInFile);
        }
        fclose($handle);

        return ['headerError' => null, 'rows' => $previewRows];
    }

    private function validateRow(array $raw, array &$seenInFile): array
    {
        $errors = [];
        $name = $raw['name'] ?? '';
        $email = $raw['email'] ?? '';

        if ($name === '') {
            $errors[] = 'Missing name';
        }
        if ($email === '') {
            $errors[] = 'Missing email';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email';
        } elseif (isset($seenInFile[strtolower($email)])) {
            $errors[] = 'Duplicate email within this file';
        }

        $clientId = null;
        $departmentName = $raw['department'] ?? '';
        if ($departmentName !== '') {
            $clientId = $this->findClientIdByName($departmentName);
            if ($clientId === null) {
                $errors[] = "Department \"$departmentName\" not found";
            }
        } else {
            $errors[] = 'Missing department';
        }

        $locationId = null;
        $siteName = $raw['site'] ?? '';
        if ($siteName !== '' && $clientId !== null) {
            $locationId = $this->findLocationIdByName($siteName, $clientId);
            if ($locationId === null) {
                $errors[] = "Site \"$siteName\" not found in that department";
            }
        }

        $managerId = null;
        $managerEmail = $raw['manager_email'] ?? '';
        if ($managerEmail !== '') {
            $managerId = $this->findContactIdByEmail($managerEmail);
            if ($managerId === null) {
                $errors[] = "Manager \"$managerEmail\" not found (import managers in an earlier row/file first)";
            }
        }

        $employeeType = strtolower($raw['employee_type'] ?? 'employee') ?: 'employee';
        if (!in_array($employeeType, self::VALID_EMPLOYEE_TYPES, true)) {
            $errors[] = "Invalid employee_type \"$employeeType\"";
        }

        $employmentStatus = strtolower($raw['employment_status'] ?? 'active') ?: 'active';
        if (!in_array($employmentStatus, self::VALID_EMPLOYMENT_STATUSES, true)) {
            $errors[] = "Invalid employment_status \"$employmentStatus\"";
        }

        $workArrangement = strtolower($raw['work_arrangement'] ?? '') ?: null;
        if ($workArrangement !== null && !in_array($workArrangement, self::VALID_WORK_ARRANGEMENTS, true)) {
            $errors[] = "Invalid work_arrangement \"$workArrangement\"";
        }

        $startDate = $raw['start_date'] ?? '';
        if ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $errors[] = 'start_date must be YYYY-MM-DD';
        }

        // Dedup priority per Section 7.4: employee ID, then primary email.
        // No external-source-ID concept exists yet (no system of record is
        // connected), so that tier is skipped.
        $existingContactId = null;
        $action = 'create';
        if (!empty($raw['employee_id'])) {
            $existingContactId = $this->findContactIdByEmployeeId($raw['employee_id']);
        }
        if ($existingContactId === null && $email !== '') {
            $existingContactId = $this->findContactIdByEmail($email);
        }
        if ($existingContactId !== null) {
            $action = 'update';
        }

        if ($email !== '') {
            $seenInFile[strtolower($email)] = true;
        }

        return [
            'raw' => $raw,
            'resolved' => [
                'client_id' => $clientId,
                'location_id' => $locationId,
                'manager_id' => $managerId,
                'employee_type' => $employeeType,
                'employment_status' => $employmentStatus,
                'work_arrangement' => $workArrangement,
                'start_date' => $startDate !== '' ? $startDate : null,
                'existing_contact_id' => $existingContactId,
            ],
            'action' => $errors ? 'error' : $action,
            'errors' => $errors,
        ];
    }

    /**
     * Writes only the rows passed in (caller is expected to have dropped
     * any with action === 'error' after showing the preview).
     */
    public function commit(array $approvedRows, ?int $importedByUserId): array
    {
        $created = 0;
        $updated = 0;

        foreach ($approvedRows as $row) {
            $raw = $row['raw'];
            $resolved = $row['resolved'];

            $name = $this->mysqli->real_escape_string($raw['name']);
            $email = $this->mysqli->real_escape_string($raw['email']);
            $jobTitle = $this->mysqli->real_escape_string($raw['job_title'] ?? '');
            $phone = $this->mysqli->real_escape_string($raw['phone'] ?? '');
            $mobile = $this->mysqli->real_escape_string($raw['mobile'] ?? '');
            $employeeId = $this->mysqli->real_escape_string($raw['employee_id'] ?? '');
            $employeeType = $this->mysqli->real_escape_string($resolved['employee_type']);
            $employmentStatus = $this->mysqli->real_escape_string($resolved['employment_status']);
            $workArrangement = $resolved['work_arrangement'] !== null ? "'" . $this->mysqli->real_escape_string($resolved['work_arrangement']) . "'" : 'NULL';
            $startDate = $resolved['start_date'] !== null ? "'" . $this->mysqli->real_escape_string($resolved['start_date']) . "'" : 'NULL';
            $clientId = (int) $resolved['client_id'];
            $locationId = $resolved['location_id'] !== null ? (int) $resolved['location_id'] : 0;
            $managerId = $resolved['manager_id'] !== null ? (int) $resolved['manager_id'] : 'NULL';

            if ($resolved['existing_contact_id'] !== null) {
                $contactId = (int) $resolved['existing_contact_id'];
                // An update only changes what the CSV actually provides: a blank cell keeps the stored value
                // (it used to blank titles, phones, manager, start date and so on for every row).
                $lifecycleBefore = \ITFlow\Workflow\LifecycleEvents::snapshot($this->mysqli, $contactId);
                $given = static fn(string $key): bool => trim((string) ($raw[$key] ?? '')) !== '';
                $keepStr = fn(string $col, string $key, string $val): string => $given($key) ? "$col = '$val'" : "$col = $col";
                $keepSql = fn(string $col, string $key, string $sql): string => $given($key) ? "$col = $sql" : "$col = $col";
                $this->mysqli->query(
                    "UPDATE contacts SET
                        contact_name = '$name', contact_email = '$email',
                        " . $keepStr('contact_title', 'job_title', $jobTitle) . ",
                        " . $keepStr('contact_phone', 'phone', $phone) . ",
                        " . $keepStr('contact_mobile', 'mobile', $mobile) . ",
                        " . $keepStr('contact_employee_id', 'employee_id', $employeeId) . ",
                        " . $keepStr('contact_employee_type', 'employee_type', $employeeType) . ",
                        " . $keepStr('contact_employment_status', 'employment_status', $employmentStatus) . ",
                        " . $keepSql('contact_work_arrangement', 'work_arrangement', $workArrangement) . ",
                        " . $keepSql('contact_start_date', 'start_date', $startDate) . ",
                        " . $keepSql('contact_manager_id', 'manager_email', (string) $managerId) . ",
                        " . $keepSql('contact_location_id', 'site', (string) $locationId) . ",
                        contact_client_id = $clientId
                     WHERE contact_id = $contactId"
                );
                \ITFlow\Workflow\LifecycleEvents::afterChange($this->mysqli, $contactId, $lifecycleBefore);
                $updated++;
            } else {
                $this->mysqli->query(
                    "INSERT INTO contacts SET
                        contact_name = '$name', contact_email = '$email', contact_title = '$jobTitle',
                        contact_phone = '$phone', contact_mobile = '$mobile',
                        contact_employee_id = '$employeeId', contact_employee_type = '$employeeType',
                        contact_employment_status = '$employmentStatus', contact_work_arrangement = $workArrangement,
                        contact_start_date = $startDate, contact_manager_id = $managerId,
                        contact_location_id = $locationId, contact_client_id = $clientId"
                );
                \ITFlow\Workflow\LifecycleEvents::afterChange($this->mysqli, (int) $this->mysqli->insert_id, null);
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function findClientIdByName(string $name): ?int
    {
        $escaped = $this->mysqli->real_escape_string($name);
        $result = $this->mysqli->query("SELECT client_id FROM clients WHERE client_name = '$escaped' AND client_archived_at IS NULL LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int) $row['client_id'] : null;
    }

    private function findLocationIdByName(string $name, int $clientId): ?int
    {
        $escaped = $this->mysqli->real_escape_string($name);
        $result = $this->mysqli->query("SELECT location_id FROM locations WHERE location_name = '$escaped' AND (location_client_id = $clientId OR EXISTS (SELECT 1 FROM department_sites ds WHERE ds.location_id = locations.location_id AND ds.client_id = $clientId)) AND location_archived_at IS NULL LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int) $row['location_id'] : null;
    }

    private function findContactIdByEmail(string $email): ?int
    {
        $escaped = $this->mysqli->real_escape_string($email);
        $result = $this->mysqli->query("SELECT contact_id FROM contacts WHERE contact_email = '$escaped' AND contact_archived_at IS NULL LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int) $row['contact_id'] : null;
    }

    private function findContactIdByEmployeeId(string $employeeId): ?int
    {
        $escaped = $this->mysqli->real_escape_string($employeeId);
        $result = $this->mysqli->query("SELECT contact_id FROM contacts WHERE contact_employee_id = '$escaped' AND contact_archived_at IS NULL LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int) $row['contact_id'] : null;
    }
}
