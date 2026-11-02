<?php

namespace ITFlow\Workflow;

/**
 * Starts one workflow template for many people at once: from a CSV (email column, optional answers to the template's onboarding form),
 * from a list of contact ids (People import, multi-select), in two steps like the People import: preview() writes nothing, commit() starts
 * only rows the preview marked ready. Each start is the same call a single Start button makes (WorkflowService::startRunIfNone), so a
 * person with an open run of the template is skipped, never duplicated.
 *
 * Department scoping is the caller's job through $canAccess (client_id => bool): a row for a person the agent may not manage is an error.
 */
class BulkOnboarding
{
    public const MAX_ROWS = 200;

    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Parses CSV text. Header: email (required), optional columns named like the template's form fields (needs_laptop, ...).
     * @return array{error:?string, rows:list<array{email:string,answers:array<string,string>}>}
     */
    public static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (!$lines || trim($lines[0]) === '') {
            return ['error' => 'The list is empty.', 'rows' => []];
        }
        $first = str_getcsv($lines[0], ',', '"', '');
        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $first);
        $hasHeader = in_array('email', $header, true);
        if (!$hasHeader) {
            // A plain list of email addresses, one per line.
            $header = ['email'];
            $data = $lines;
        } else {
            $data = array_slice($lines, 1);
        }
        $emailIdx = array_search('email', $header, true);
        $rows = [];
        foreach ($data as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            $email = trim((string) ($cells[$emailIdx] ?? ''));
            $answers = [];
            foreach ($header as $i => $col) {
                if ($i === $emailIdx || $col === '') {
                    continue;
                }
                $answers[preg_replace('/^var_/', '', $col)] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = ['email' => $email, 'answers' => $answers];
            if (count($rows) > self::MAX_ROWS) {
                return ['error' => 'At most ' . self::MAX_ROWS . ' people at a time.', 'rows' => []];
            }
        }

        return $rows ? ['error' => null, 'rows' => $rows] : ['error' => 'No email addresses found.', 'rows' => []];
    }

    /**
     * @param list<array{email?:string,contact_id?:int,answers?:array<string,string>}> $rows
     * @param callable(int):bool|null $canAccess department scoping
     * @return array{error:?string, template:?array<string,mixed>, rows:list<array<string,mixed>>}
     */
    public function preview(int $templateId, array $rows, ?callable $canAccess = null): array
    {
        $stmt = mysqli_prepare($this->mysqli, 'SELECT workflow_template_id, name, type, variables_json FROM workflow_templates WHERE workflow_template_id = ? AND archived_at IS NULL AND is_active = 1');
        mysqli_stmt_bind_param($stmt, 'i', $templateId);
        mysqli_stmt_execute($stmt);
        $template = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
        if (!$template) {
            return ['error' => 'Choose an active workflow template.', 'template' => null, 'rows' => []];
        }
        if (count($rows) > self::MAX_ROWS) {
            return ['error' => 'At most ' . self::MAX_ROWS . ' people at a time.', 'template' => $template, 'rows' => []];
        }
        $defs = TemplateVariables::definitions($template['variables_json']);
        $service = new WorkflowService($this->mysqli);
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $row = ['email' => (string) ($r['email'] ?? ''), 'contact_id' => 0, 'name' => '', 'status' => 'error', 'message' => '', 'answers' => []];
            $contact = $this->find($r);
            if (is_string($contact)) {
                $row['message'] = $contact;
                $out[] = $row;
                continue;
            }
            $row['contact_id'] = (int) $contact['contact_id'];
            $row['name'] = (string) $contact['contact_name'];
            $row['email'] = (string) $contact['contact_email'];
            if (isset($seen[$row['contact_id']])) {
                $row['message'] = 'Listed twice.';
            } elseif ($canAccess !== null && !$canAccess((int) $contact['contact_client_id'])) {
                $row['message'] = 'You do not manage this person\'s department.';
            } else {
                try {
                    $row['answers'] = TemplateVariables::resolve($defs, (array) ($r['answers'] ?? []), true);
                    if ($service->openRunId($templateId, $row['contact_id']) > 0) {
                        $row['status'] = 'skip';
                        $row['message'] = 'Already has this workflow open.';
                    } else {
                        $row['status'] = 'ready';
                    }
                } catch (\InvalidArgumentException $e) {
                    $row['message'] = $e->getMessage();
                }
            }
            $seen[$row['contact_id']] = true;
            $out[] = $row;
        }

        return ['error' => null, 'template' => $template, 'rows' => $out];
    }

    /**
     * Starts the rows the preview marked ready. Re-checks everything (the preview may be old). @param list<array<string,mixed>> $previewRows
     * @return array{started:int,skipped:int,failed:int,run_ids:list<int>,messages:list<string>}
     */
    public function commit(int $templateId, array $previewRows, ?int $startedBy, ?callable $canAccess = null, ?ActionGateway $gateway = null): array
    {
        $fresh = $this->preview($templateId, array_map(static fn ($r) => ['contact_id' => (int) $r['contact_id'], 'answers' => (array) ($r['answers'] ?? [])], array_filter($previewRows, static fn ($r) => ($r['status'] ?? '') === 'ready')), $canAccess);
        $res = ['started' => 0, 'skipped' => 0, 'failed' => 0, 'run_ids' => [], 'messages' => []];
        if ($fresh['error'] !== null) {
            $res['messages'][] = $fresh['error'];

            return $res;
        }
        $service = new WorkflowService($this->mysqli, $gateway);
        $type = (string) $fresh['template']['type'];
        foreach ($fresh['rows'] as $r) {
            if ($r['status'] === 'skip') {
                $res['skipped']++;
                continue;
            }
            if ($r['status'] !== 'ready') {
                $res['failed']++;
                $res['messages'][] = $r['name'] . ': ' . $r['message'];
                continue;
            }
            try {
                $runId = $service->startRunIfNone($templateId, (int) $r['contact_id'], $startedBy, $r['answers']);
            } catch (\Throwable $e) {
                $res['failed']++;
                $res['messages'][] = $r['name'] . ': ' . $e->getMessage();
                continue;
            }
            if ($runId === null) {
                $res['skipped']++;
                continue;
            }
            $res['started']++;
            $res['run_ids'][] = $runId;
            ($gateway ?? new LiveActionGateway($this->mysqli))->audit('workflow.' . $type . '_started', $startedBy, 'contact', (int) $r['contact_id'], 'started', 'Bulk start of ' . $fresh['template']['name'] . ' for ' . $r['name'], ['run_id' => $runId, 'template_id' => $templateId, 'bulk' => true]);
        }

        return $res;
    }

    /** @param array<string,mixed> $r @return array<string,mixed>|string the contact row, or a message */
    private function find(array $r)
    {
        if (!empty($r['contact_id'])) {
            $stmt = mysqli_prepare($this->mysqli, 'SELECT contact_id, contact_name, contact_email, contact_client_id FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL');
            $v = (int) $r['contact_id'];
        } else {
            $email = trim((string) ($r['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email === '' ? 'No email address.' : 'Not a valid email address.';
            }
            $stmt = mysqli_prepare($this->mysqli, 'SELECT contact_id, contact_name, contact_email, contact_client_id FROM contacts WHERE contact_email = ? AND contact_archived_at IS NULL LIMIT 2');
            $v = $email;
        }
        mysqli_stmt_bind_param($stmt, is_int($v) ? 'i' : 's', $v);
        mysqli_stmt_execute($stmt);
        $found = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        if (count($found) === 0) {
            return 'No active person with that email.';
        }
        if (count($found) > 1) {
            return 'More than one person has that email; start it from the person\'s page.';
        }

        return $found[0];
    }
}
