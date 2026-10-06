<?php

namespace ITFlow\Workflow;

/**
 * Employee lifecycle events: employee.hired and employee.terminated.
 *
 * Detection is a pure comparison of a person's row before and after a change (detect()), called from every place that changes
 * employment data: the contact edit form, the people import and the Odoo directory sync. Events are recorded as audit events,
 * which also puts them on the event bus (webhooks and event rules, including the start_workflow rule action).
 *
 * Off by default: nothing is detected or emitted until Administration > Employee workflows turns on
 * settings.config_lifecycle_auto_start, so a directory sync can never start surprise workflows on an install that has not opted in.
 */
class LifecycleEvents
{
    /** A new person counts as a hire when their start date is within this many days of today (either side). */
    public const HIRE_WINDOW_DAYS = 30;

    private const LEAVING = ['termination_pending', 'terminated'];
    private const NOT_YET_ACTIVE = ['pre-hire', 'terminated', 'archived', ''];

    public static function enabled(\mysqli $mysqli): bool
    {
        $res = @mysqli_query($mysqli, 'SELECT config_lifecycle_auto_start FROM settings WHERE company_id = 1');
        $row = $res ? mysqli_fetch_row($res) : null;

        return $row !== null && (int) $row[0] === 1;
    }

    /** The columns detect() compares. @return array<string,mixed>|null null when the person does not exist (yet) */
    public static function snapshot(\mysqli $mysqli, int $contactId): ?array
    {
        $res = mysqli_query($mysqli, "SELECT contact_id, contact_client_id, contact_employment_status, contact_start_date, contact_expected_end_date, contact_employee_type FROM contacts WHERE contact_id = $contactId");
        $row = $res ? mysqli_fetch_assoc($res) : null;

        return $row ?: null;
    }

    /**
     * @param array<string,mixed>|null $before null for a brand-new person
     * @param array<string,mixed> $after
     * @return list<string> employee.hired and/or employee.terminated
     */
    public static function detect(?array $before, array $after, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $events = [];
        $status = strtolower(trim((string) ($after['contact_employment_status'] ?? '')));
        $oldStatus = $before === null ? null : strtolower(trim((string) ($before['contact_employment_status'] ?? '')));

        // Hired: a new person who starts around now, or a person whose status turns active (from pre-hire, or back from terminated/archived).
        if ($before === null) {
            $start = (string) ($after['contact_start_date'] ?? '');
            if (in_array($status, ['active', 'pre-hire'], true) && self::isDate($start)) {
                $days = (int) round((strtotime($start) - strtotime($today)) / 86400);
                if (abs($days) <= self::HIRE_WINDOW_DAYS) {
                    $events[] = 'employee.hired';
                }
            }
        } elseif ($status === 'active' && in_array($oldStatus, self::NOT_YET_ACTIVE, true)) {
            $events[] = 'employee.hired';
        }

        // Terminated: status turns termination_pending/terminated, or an end date is set on someone who was not already leaving.
        $wasLeaving = $oldStatus !== null && in_array($oldStatus, self::LEAVING, true);
        if ($before !== null && !$wasLeaving) {
            $endNow = (string) ($after['contact_expected_end_date'] ?? '');
            $endBefore = (string) ($before['contact_expected_end_date'] ?? '');
            if (in_array($status, self::LEAVING, true) || (self::isDate($endNow) && $endNow !== $endBefore)) {
                $events[] = 'employee.terminated';
            }
        }

        return $events;
    }

    private static function isDate(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && $v !== '0000-00-00';
    }

    /**
     * Compare the person now with the snapshot taken before the change and record any lifecycle events. Never throws: a failure
     * here must not break the contact save or the sync that called it.
     *
     * @param array<string,mixed>|null $before from snapshot(), or null when the person was just created
     */
    public static function afterChange(\mysqli $mysqli, int $contactId, ?array $before, ?ActionGateway $gateway = null): array
    {
        try {
            if (!self::enabled($mysqli)) {
                return [];
            }
            $after = self::snapshot($mysqli, $contactId);
            if ($after === null) {
                return [];
            }
            $events = self::detect($before, $after);
            if ($events) {
                $gateway ??= new LiveActionGateway($mysqli);
                foreach ($events as $event) {
                    $gateway->audit($event, null, 'contact', $contactId, 'detected', $event === 'employee.hired' ? 'A new employee start was detected' : 'An employee departure was detected', [
                        'contact_id' => $contactId,
                        'client_id' => (int) $after['contact_client_id'],
                        'employment_status' => (string) $after['contact_employment_status'],
                        'employee_type' => (string) $after['contact_employee_type'],
                        'start_date' => (string) ($after['contact_start_date'] ?? ''),
                        'end_date' => (string) ($after['contact_expected_end_date'] ?? ''),
                    ]);
                }
            }

            return $events;
        } catch (\Throwable $e) {
            error_log('lifecycle events skipped: ' . $e->getMessage());

            return [];
        }
    }
}
