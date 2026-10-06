<?php

namespace ITFlow\ITSM;

/**
 * Service catalog request forms and approval chains (GitHub issue #30).
 *
 * A catalog item can carry a request form (service_catalog_fields) and an ordered approval chain
 * (service_catalog_approval_steps). Both are empty by default, so an item that has neither behaves exactly as it
 * always did: the ticket is created and nothing else happens.
 *
 * Request lifecycle (service_catalog_requests.status):
 *   not_required     - the item has a form but no approval (or the risk score is below the auto-approve threshold)
 *   pending_approval - the ticket exists but is held (status resolved by NAME: "Pending Approval", else "On Hold")
 *   approved         - every step approved; the ticket was released to its normal creation status
 *   rejected         - any approver rejected; the ticket was closed with the reason
 *
 * Each activated step writes one service_catalog_request_approvals row per eligible approver. A step is complete when
 * one row is approved ("any") or every row is approved ("all"). Any rejection ends the request.
 *
 * Takes a plain mysqli handle (no framework). Notifications go through the app's own notifyUser / addToMailQueue /
 * publishTicketEvent when those exist, and are skipped silently when they do not (unit tests, CLI).
 */
class ServiceCatalogService
{
    public const FIELD_TYPES = ['text', 'textarea', 'select', 'checkbox', 'date', 'number'];
    /** Conditional-visibility operators for a field's show_if rule. */
    public const SHOW_IF_OPS = ['equals', 'in', 'not_empty'];
    public const APPROVER_TYPES = ['user', 'role', 'requester_manager'];
    public const MODES = ['any', 'all'];

    private \mysqli $db;

    public function __construct(\mysqli $mysqli)
    {
        $this->db = $mysqli;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Form definition: read, normalise, save
    // ------------------------------------------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function getFields(int $catalogItemId): array
    {
        $rows = [];
        $res = $this->db->query("SELECT * FROM service_catalog_fields WHERE catalog_item_id = $catalogItemId ORDER BY sort_order ASC, field_id ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function getSteps(int $catalogItemId): array
    {
        $rows = [];
        $res = $this->db->query("SELECT * FROM service_catalog_approval_steps WHERE catalog_item_id = $catalogItemId ORDER BY step_order ASC, step_id ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** Lower-case slug used as a field's storage key. */
    public static function slug(string $label): string
    {
        $s = strtolower(trim($label));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string) $s, '_');
        return substr($s !== '' ? $s : 'field', 0, 60);
    }

    /**
     * Turn the admin form's parallel POST arrays into clean field rows. A row with an empty label, or with its remove box
     * ticked, is dropped. Rows are ordered by the order number the admin typed (ties keep their form position), keys are
     * kept from the form when present (so a rename does not orphan the key) and de-duplicated.
     *
     * @param array<string,mixed> $post
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeFieldRows(array $post): array
    {
        $labels = (array) ($post['field_label'] ?? []);
        $out = [];
        $used = [];
        foreach ($labels as $i => $label) {
            $label = trim(is_scalar($label) ? (string) $label : '');
            if ($label === '' || !empty($post['field_remove'][$i])) {
                continue;
            }
            $type = (string) ($post['field_type'][$i] ?? 'text');
            if (!in_array($type, self::FIELD_TYPES, true)) {
                $type = 'text';
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($post['field_key'][$i] ?? '')));
            $key = $key !== '' ? substr($key, 0, 64) : self::slug($label);
            $base = $key;
            for ($n = 2; isset($used[$key]); $n++) {
                $key = substr($base, 0, 60) . '_' . $n;
            }
            $used[$key] = true;

            $options = null;
            if ($type === 'select') {
                $opts = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($post['field_options'][$i] ?? '')) ?: []), 'strlen')));
                $options = implode("\n", array_map(fn($o) => substr($o, 0, 200), $opts));
            }
            $out[] = [
                'field_key' => $key,
                'label' => substr($label, 0, 200),
                'field_type' => $type,
                'options' => $options,
                'is_required' => !empty($post['field_required'][$i]) ? 1 : 0,
                'placeholder' => ($p = substr(trim((string) ($post['field_placeholder'][$i] ?? '')), 0, 200)) !== '' ? $p : null,
                'sort_order' => intval($post['field_order'][$i] ?? 0),
                'show_if' => self::buildShowIf(
                    (string) ($post['field_showif_field'][$i] ?? ''),
                    (string) ($post['field_showif_op'][$i] ?? ''),
                    (string) ($post['field_showif_value'][$i] ?? '')
                ),
                '_pos' => count($out),
            ];
        }
        usort($out, fn($a, $b) => [$a['sort_order'], $a['_pos']] <=> [$b['sort_order'], $b['_pos']]);
        foreach ($out as $n => &$r) {
            $r['sort_order'] = $n + 1;
            unset($r['_pos']);
        }
        return $out;
    }

    /**
     * Same for approval steps. approver_ref[i] is the user id or role id; requester_manager needs none.
     *
     * @param array<string,mixed> $post
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeStepRows(array $post): array
    {
        $types = (array) ($post['step_type'] ?? []);
        $out = [];
        foreach ($types as $i => $type) {
            $type = (string) $type;
            if (!in_array($type, self::APPROVER_TYPES, true) || !empty($post['step_remove'][$i])) {
                continue;
            }
            $ref = intval($post['step_ref_' . $type][$i] ?? 0);
            if ($type !== 'requester_manager' && $ref <= 0) {
                continue; // a user/role step with nobody picked is an empty row
            }
            $mode = (string) ($post['step_mode'][$i] ?? 'any');
            $out[] = [
                'approver_type' => $type,
                'approver_id' => $type === 'requester_manager' ? null : $ref,
                'mode' => in_array($mode, self::MODES, true) ? $mode : 'any',
                'sort' => intval($post['step_order'][$i] ?? 0),
                '_pos' => count($out),
            ];
        }
        usort($out, fn($a, $b) => [$a['sort'], $a['_pos']] <=> [$b['sort'], $b['_pos']]);
        foreach ($out as $n => &$r) {
            $r['step_order'] = $n + 1;
            unset($r['sort'], $r['_pos']);
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Conditional fields: a field may carry a show_if rule {"field":"<earlier key>","op":"equals|in|not_empty","value":...}.
    // A field whose rule is not met is hidden in the browser AND ignored by the server: not required, not stored.
    // ------------------------------------------------------------------------------------------------------------

    /** Build the stored JSON for a rule typed in the admin editor; null when no rule (or an unusable one) was given. */
    public static function buildShowIf(string $field, string $op, string $value): ?string
    {
        $field = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($field)));
        if ($field === '' || !in_array($op, self::SHOW_IF_OPS, true)) {
            return null;
        }
        $rule = ['field' => substr($field, 0, 64), 'op' => $op];
        if ($op === 'equals') {
            $rule['value'] = substr(trim($value), 0, 200);
        } elseif ($op === 'in') {
            $rule['value'] = array_slice(array_values(array_unique(array_filter(array_map(fn($v) => substr(trim($v), 0, 200), explode('|', $value)), 'strlen'))), 0, 50);
            if (!$rule['value']) {
                return null;
            }
        }
        return json_encode($rule, JSON_UNESCAPED_UNICODE);
    }

    /** Decode a stored rule defensively; null when absent or malformed (a malformed rule never hides a field). */
    public static function parseShowIf($raw): ?array
    {
        if (is_array($raw)) {
            $rule = $raw;
        } else {
            $rule = json_decode((string) $raw, true);
        }
        if (!is_array($rule) || !isset($rule['field'], $rule['op']) || !is_string($rule['field']) || !in_array($rule['op'], self::SHOW_IF_OPS, true)) {
            return null;
        }
        if ($rule['op'] === 'equals' && !is_string($rule['value'] ?? null)) {
            return null;
        }
        if ($rule['op'] === 'in' && (!is_array($rule['value'] ?? null) || !$rule['value'])) {
            return null;
        }
        return $rule;
    }

    /**
     * Is a rule met? $answers maps field key => the effective answer as a string ('' when empty or hidden; a checkbox is
     * 'Yes' when ticked). Used by the server; js/catalog_show_if.js mirrors it in the browser.
     *
     * @param array<string,string> $answers
     */
    public static function showIfMet(?array $rule, array $answers): bool
    {
        if ($rule === null) {
            return true;
        }
        $v = (string) ($answers[$rule['field']] ?? '');
        switch ($rule['op']) {
            case 'equals':
                return $v === (string) $rule['value'];
            case 'in':
                return in_array($v, array_map('strval', $rule['value']), true);
            case 'not_empty':
                return $v !== '';
        }
        return true;
    }

    /**
     * Check the rules on a normalised, ordered field set (what the admin is about to save). A rule must name a field that
     * exists and comes EARLIER in the form; that also rules out cycles and self-references. Returns error messages.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return string[]
     */
    public static function validateShowIf(array $rows): array
    {
        $errors = [];
        $pos = [];
        foreach ($rows as $n => $r) {
            $pos[(string) $r['field_key']] = $n;
        }
        foreach ($rows as $n => $r) {
            if (($r['show_if'] ?? null) === null) {
                continue;
            }
            $rule = self::parseShowIf($r['show_if']);
            $label = (string) $r['label'];
            if ($rule === null) {
                $errors[] = "The condition on \"$label\" is not valid.";
            } elseif (!isset($pos[$rule['field']])) {
                $errors[] = "The condition on \"$label\" refers to a question that does not exist.";
            } elseif ($pos[$rule['field']] >= $n) {
                $errors[] = "The condition on \"$label\" must refer to a question that comes earlier in the form.";
            }
        }
        return $errors;
    }

    /** Human-readable form of a rule for the admin editor, e.g. 'Shown when "Device" is Laptop'. */
    public static function describeShowIf(?array $rule, array $labelsByKey = []): string
    {
        if ($rule === null) {
            return '';
        }
        $name = '"' . ($labelsByKey[$rule['field']] ?? $rule['field']) . '"';
        return match ($rule['op']) {
            'equals' => "Shown when $name is " . $rule['value'],
            'in' => "Shown when $name is one of: " . implode(', ', $rule['value']),
            default => "Shown when $name is answered",
        };
    }

    /** Replace an item's form fields with the (already normalised) rows. */
    public function saveFields(int $catalogItemId, array $rows): void
    {
        $this->db->query("DELETE FROM service_catalog_fields WHERE catalog_item_id = $catalogItemId");
        foreach ($rows as $r) {
            $stmt = $this->db->prepare("INSERT INTO service_catalog_fields (catalog_item_id, field_key, label, field_type, options, is_required, placeholder, sort_order, show_if) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $showIf = $r['show_if'] ?? null;
            $stmt->bind_param('issssisis', $catalogItemId, $r['field_key'], $r['label'], $r['field_type'], $r['options'], $r['is_required'], $r['placeholder'], $r['sort_order'], $showIf);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** Replace an item's approval chain with the (already normalised) rows. */
    public function saveSteps(int $catalogItemId, array $rows): void
    {
        $this->db->query("DELETE FROM service_catalog_approval_steps WHERE catalog_item_id = $catalogItemId");
        foreach ($rows as $r) {
            $stmt = $this->db->prepare("INSERT INTO service_catalog_approval_steps (catalog_item_id, step_order, approver_type, approver_id, mode) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iisis', $catalogItemId, $r['step_order'], $r['approver_type'], $r['approver_id'], $r['mode']);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Submission: validate the form, store the request
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Validate submitted values against the item's fields (server-side; the browser's checks are a convenience).
     * Returns the cleaned values as a list of {key,label,type,value} (labels are snapshotted so a later rename does not
     * rewrite history) and a list of human-readable errors.
     *
     * @param array<int,array<string,mixed>> $fields
     * @param array<string,mixed> $input  the posted catalog_field[] array
     * @return array{errors:string[],values:array<int,array<string,string>>}
     */
    public static function validateInput(array $fields, array $input): array
    {
        $errors = [];
        $values = [];
        $answers = []; // effective answers of earlier fields, for show_if rules; a hidden field counts as empty
        foreach ($fields as $f) {
            $key = (string) $f['field_key'];
            $label = (string) $f['label'];
            $type = (string) $f['field_type'];
            $required = !empty($f['is_required']);
            // Conditional field: when its rule is not met it is neither required nor stored, whatever was posted.
            if (!self::showIfMet(self::parseShowIf($f['show_if'] ?? null), $answers)) {
                continue;
            }
            $raw = $input[$key] ?? null;
            if (is_array($raw)) {
                $errors[] = "$label is not valid.";
                continue;
            }
            $v = is_string($raw) ? trim($raw) : '';

            if ($type === 'checkbox') {
                $checked = $v !== '' && $v !== '0';
                if ($required && !$checked) {
                    $errors[] = "$label must be ticked.";
                    continue;
                }
                $values[] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => $checked ? 'Yes' : 'No'];
                $answers[$key] = $checked ? 'Yes' : '';
                continue;
            }

            if ($v === '') {
                if ($required) {
                    $errors[] = "$label is required.";
                    continue;
                }
                $values[] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => ''];
                $answers[$key] = '';
                continue;
            }

            switch ($type) {
                case 'text':
                    if (mb_strlen($v) > 500) { $errors[] = "$label is too long (500 characters at most)."; continue 2; }
                    break;
                case 'textarea':
                    if (mb_strlen($v) > 5000) { $errors[] = "$label is too long (5000 characters at most)."; continue 2; }
                    break;
                case 'number':
                    if (!is_numeric($v) || abs((float) $v) > 1.0E+12) { $errors[] = "$label must be a number."; continue 2; }
                    break;
                case 'date':
                    $d = \DateTime::createFromFormat('Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v) { $errors[] = "$label must be a valid date."; continue 2; }
                    break;
                case 'select':
                    $allowed = array_filter(array_map('trim', explode("\n", (string) ($f['options'] ?? ''))), 'strlen');
                    if (!in_array($v, $allowed, true)) { $errors[] = "$label must be one of the listed choices."; continue 2; }
                    break;
                default:
                    $errors[] = "$label has an unknown type.";
                    continue 2;
            }
            $values[] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => $v];
            $answers[$key] = $v;
        }
        return ['errors' => $errors, 'values' => $values];
    }

    /**
     * The request form's inputs as escaped HTML (the same markup in the portal and in the agent's New Ticket window).
     * Values post back as catalog_field[<key>]; HTML5 required/type attributes are only a convenience, validateInput()
     * is what enforces them.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    public static function renderInputs(array $fields, bool $preview = false): string
    {
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $out = '';
        $conditional = false;
        foreach ($fields as $f) {
            // $preview: the admin editor's sample form - inputs carry no name (nothing posts) and nothing is required.
            $name = ($preview ? 'preview_' : 'catalog_field[') . $h($f['field_key']) . ($preview ? '' : ']');
            $id = ($preview ? 'catalogPreview_' : 'catalogField_') . $h($f['field_key']);
            $req = !empty($f['is_required']) && !$preview;
            $rule = self::parseShowIf($f['show_if'] ?? null);
            $wrap = '<div class="form-group" data-catalog-key="' . $h($f['field_key']) . '"';
            if ($rule !== null) {
                $conditional = true;
                $wrap .= ' data-show-if="' . $h(json_encode($rule, JSON_UNESCAPED_UNICODE)) . '" hidden';
            }
            $wrap .= '>';
            $star = $req ? ' <strong class="text-danger">*</strong>' : '';
            $ph = (string) ($f['placeholder'] ?? '') !== '' ? ' placeholder="' . $h($f['placeholder']) . '"' : '';
            $r = $req ? ' required' : '';
            $type = (string) $f['field_type'];
            if ($type === 'checkbox') {
                $out .= $wrap . '<div class="form-check"><input type="checkbox" class="form-check-input" id="' . $id . '" name="' . $name . '" value="1"' . $r . '> <label class="form-check-label" for="' . $id . '">' . $h($f['label']) . $star . '</label></div></div>';
                continue;
            }
            $out .= $wrap . '<label for="' . $id . '">' . $h($f['label']) . $star . '</label>';
            if ($type === 'textarea') {
                $out .= '<textarea class="form-control" id="' . $id . '" name="' . $name . '" rows="3" maxlength="5000"' . $ph . $r . '></textarea>';
            } elseif ($type === 'select') {
                $out .= '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $r . '><option value="">- Select -</option>';
                foreach (array_filter(array_map('trim', explode("\n", (string) ($f['options'] ?? ''))), 'strlen') as $o) {
                    $out .= '<option value="' . $h($o) . '">' . $h($o) . '</option>';
                }
                $out .= '</select>';
            } else {
                $html = ['date' => 'date', 'number' => 'number'][$type] ?? 'text';
                $step = $type === 'number' ? ' step="any"' : ' maxlength="500"';
                $out .= '<input type="' . $html . '" class="form-control" id="' . $id . '" name="' . $name . '"' . $step . $ph . $r . '>';
            }
            $out .= '</div>';
        }
        if ($conditional) {
            // Minimal show/hide script; it mirrors showIfMet() and is only a convenience - the server decides.
            $out .= '<script src="/js/catalog_show_if.js?v=' . (int) @filemtime(__DIR__ . '/../../js/catalog_show_if.js') . '"></script>';
        }
        return $out;
    }

    /**
     * Does a request for this item have to wait for approval? Requires the switch, at least one step, and a risk score
     * that is not below the auto-approve threshold (threshold 0 = never auto-approve).
     *
     * @param array<string,mixed> $item  a service_catalog_items row
     */
    public function needsApproval(array $item, ?int $stepCount = null): bool
    {
        if (empty($item['requires_approval'])) {
            return false;
        }
        $threshold = intval($item['auto_approve_below'] ?? 0);
        if ($threshold > 0 && intval($item['risk_score'] ?? 0) < $threshold) {
            return false;
        }
        $stepCount ??= count($this->getSteps(intval($item['catalog_item_id'])));
        return $stepCount > 0;
    }

    /** Does this item need any request-side handling at all (a form or an approval)? */
    public function hasRequestFlow(array $item): bool
    {
        $id = intval($item['catalog_item_id']);
        return !empty($item['requires_approval']) || count($this->getFields($id)) > 0;
    }

    /**
     * Record the request for a freshly created ticket and, when the item needs approval, hold the ticket and ask the
     * first step's approvers. Returns ['request_id'=>int,'status'=>string].
     *
     * @param array<string,mixed> $item
     * @param array<int,array<string,string>> $values  output of validateInput()['values']
     * @return array{request_id:int,status:string}
     */
    public function submit(array $item, int $ticketId, int $clientId, int $contactId, int $requestedByUserId, array $values): array
    {
        $itemId = intval($item['catalog_item_id']);
        $risk = intval($item['risk_score'] ?? 0);
        $steps = $this->getSteps($itemId);
        $needs = $this->needsApproval($item, count($steps));
        // A below-threshold risk score on an item that asks for approval is auto-approved (recorded as approved).
        $status = $needs ? 'pending_approval' : (!empty($item['requires_approval']) && $steps ? 'approved' : 'not_required');
        $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare("INSERT INTO service_catalog_requests (catalog_item_id, ticket_id, client_id, contact_id, requested_by_user_id, field_values, status, current_step, risk_score) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");
        $stmt->bind_param('iiiiissi', $itemId, $ticketId, $clientId, $contactId, $requestedByUserId, $json, $status, $risk);
        $stmt->execute();
        $requestId = (int) $this->db->insert_id;
        $stmt->close();
        if ($status === 'approved') {
            $this->db->query("UPDATE service_catalog_requests SET decided_at = NOW() WHERE request_id = $requestId");
        }

        if ($needs) {
            $this->holdTicket($ticketId);
            $this->activateStep($requestId, intval($steps[0]['step_order']));
        }
        return ['request_id' => $requestId, 'status' => $status];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Approval state machine
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Record one approver's decision. The approver is either an agent ($userId) or a portal contact ($contactId, which
     * must belong to $clientId). Only a pending row for the request's CURRENT step can be decided, so a stale, replayed
     * or guessed request id changes nothing. $override lets an administrator decide a step on behalf of its approvers
     * (used when e.g. a manager has no portal login); it is recorded in the comment.
     *
     * @return array{ok:bool,error:?string,status:?string}
     */
    public function decide(int $requestId, ?int $userId, ?int $contactId, bool $approve, string $comment = '', bool $override = false, int $clientId = 0): array
    {
        $comment = mb_substr(trim($comment), 0, 2000);
        $this->db->begin_transaction();
        try {
            $req = $this->db->query("SELECT * FROM service_catalog_requests WHERE request_id = $requestId FOR UPDATE")->fetch_assoc();
            if (!$req || $req['status'] !== 'pending_approval') {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'This request is not waiting for approval.', 'status' => $req['status'] ?? null];
            }
            if ($contactId !== null && ($clientId <= 0 || intval($req['client_id']) !== $clientId)) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Request not found.', 'status' => null];
            }
            $step = intval($req['current_step']);

            if ($override && $userId) {
                $where = "request_id = $requestId AND step_order = $step AND status = 'pending'";
                $row = $this->db->query("SELECT approval_id FROM service_catalog_request_approvals WHERE $where LIMIT 1")->fetch_assoc();
                if (!$row) {
                    $this->db->rollback();
                    return ['ok' => false, 'error' => 'There is nothing pending on this request.', 'status' => $req['status']];
                }
                // An override decides the whole step: the first pending row carries the decision, the rest are skipped.
                $approvalId = intval($row['approval_id']);
                $comment = trim('[Administrator override] ' . $comment);
                // The decision is recorded against the administrator, and a decided step is never left half-open.
                $this->db->query("UPDATE service_catalog_request_approvals SET approver_user_id = " . intval($userId) . ", approver_contact_id = NULL, step_mode = 'any' WHERE approval_id = $approvalId");
                $this->db->query("UPDATE service_catalog_request_approvals SET step_mode = 'any' WHERE $where");
            } else {
                $who = $userId ? "approver_user_id = " . intval($userId) : "approver_contact_id = " . intval($contactId);
                $row = $this->db->query("SELECT approval_id FROM service_catalog_request_approvals WHERE request_id = $requestId AND step_order = $step AND status = 'pending' AND $who LIMIT 1")->fetch_assoc();
                if (!$row) {
                    $this->db->rollback();
                    return ['ok' => false, 'error' => 'You are not an approver for this request, or you already decided.', 'status' => $req['status']];
                }
                $approvalId = intval($row['approval_id']);
            }

            $new = $approve ? 'approved' : 'rejected';
            $stmt = $this->db->prepare("UPDATE service_catalog_request_approvals SET status = ?, comment = ?, decided_at = NOW() WHERE approval_id = ? AND status = 'pending'");
            $stmt->bind_param('ssi', $new, $comment, $approvalId);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed !== 1) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'You already decided on this request.', 'status' => $req['status']];
            }

            $final = null;
            if (!$approve) {
                $this->db->query("UPDATE service_catalog_request_approvals SET status = 'skipped', decided_at = NOW() WHERE request_id = $requestId AND step_order = $step AND status = 'pending'");
                $stmt = $this->db->prepare("UPDATE service_catalog_requests SET status = 'rejected', rejection_reason = ?, decided_at = NOW() WHERE request_id = ?");
                $stmt->bind_param('si', $comment, $requestId);
                $stmt->execute();
                $stmt->close();
                $final = 'rejected';
            } else {
                $pending = (int) $this->db->query("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id = $requestId AND step_order = $step AND status = 'pending'")->fetch_row()[0];
                $mode = (string) $this->db->query("SELECT step_mode FROM service_catalog_request_approvals WHERE approval_id = $approvalId")->fetch_row()[0];
                $stepDone = ($mode === 'any') || $pending === 0;
                if ($stepDone) {
                    $this->db->query("UPDATE service_catalog_request_approvals SET status = 'skipped', decided_at = NOW() WHERE request_id = $requestId AND step_order = $step AND status = 'pending'");
                    $next = $this->db->query("SELECT MIN(step_order) FROM service_catalog_approval_steps WHERE catalog_item_id = " . intval($req['catalog_item_id']) . " AND step_order > $step")->fetch_row()[0];
                    if ($next === null) {
                        $this->db->query("UPDATE service_catalog_requests SET status = 'approved', decided_at = NOW() WHERE request_id = $requestId");
                        $final = 'approved';
                    } else {
                        $nextStep = intval($next);
                        $this->db->commit();
                        $this->activateStep($requestId, $nextStep);
                        return ['ok' => true, 'error' => null, 'status' => 'pending_approval'];
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['ok' => false, 'error' => 'The decision could not be saved.', 'status' => null];
        }

        if ($final === 'approved') {
            $this->releaseTicket(intval($req['ticket_id']));
            $this->notifyOutcome($req, true, '');
            $this->emitDecision($req, 'catalog.request_approved', $userId, '');
        } elseif ($final === 'rejected') {
            $this->closeRejected(intval($req['ticket_id']), $comment, intval($userId));
            $this->notifyOutcome($req, false, $comment);
            $this->emitDecision($req, 'catalog.request_rejected', $userId, $comment);
        }
        return ['ok' => true, 'error' => null, 'status' => $final ?? 'pending_approval'];
    }

    /** Puts the final decision on the event bus (webhooks and event rules); never throws, never blocks the decision. */
    private function emitDecision(array $req, string $event, ?int $userId, string $reason): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
            \rivetEmitEvent($event, ['request_id' => intval($req['request_id']), 'ticket_id' => intval($req['ticket_id']), 'client_id' => intval($req['client_id']), 'contact_id' => intval($req['contact_id']),
                'catalog_item_id' => intval($req['catalog_item_id'] ?? 0), 'decided_by_user_id' => $userId ? intval($userId) : 0, 'reason' => $reason]);
        } catch (\Throwable $e) {
            error_log('catalog decision event skipped: ' . $e->getMessage());
        }
    }

    /**
     * Make $stepOrder the current step: resolve its approvers, write one pending row each, notify them. When no one can
     * be resolved (e.g. the requester has no manager on file) the step falls back to the administrators, so a request can
     * never be stuck with nobody able to approve it.
     */
    public function activateStep(int $requestId, int $stepOrder): void
    {
        $req = $this->db->query("SELECT * FROM service_catalog_requests WHERE request_id = $requestId")->fetch_assoc();
        if (!$req) {
            return;
        }
        $step = $this->db->query("SELECT * FROM service_catalog_approval_steps WHERE catalog_item_id = " . intval($req['catalog_item_id']) . " AND step_order = $stepOrder ORDER BY step_id ASC LIMIT 1")->fetch_assoc();
        if (!$step) {
            return;
        }
        $this->db->query("UPDATE service_catalog_requests SET current_step = $stepOrder WHERE request_id = $requestId");

        $approvers = $this->resolveApprovers($step, intval($req['client_id']), intval($req['contact_id']), intval($req['requested_by_user_id']));
        $mode = in_array($step['mode'], self::MODES, true) ? $step['mode'] : 'any';
        foreach ($approvers as $a) {
            $u = $a['user'];
            $c = $a['contact'];
            $stmt = $this->db->prepare("INSERT INTO service_catalog_request_approvals (request_id, step_order, step_mode, approver_user_id, approver_contact_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param('iisii', $requestId, $stepOrder, $mode, $u, $c);
            $stmt->execute();
            $stmt->close();
        }
        $this->notifyApprovers($req, $approvers);
    }

    /**
     * Who may decide a step. Agents come back as ['user'=>id,'contact'=>null], portal contacts the other way round.
     *
     * @param array<string,mixed> $step
     * @return array<int,array{user:?int,contact:?int}>
     */
    public function resolveApprovers(array $step, int $clientId, int $requesterContactId, int $requesterUserId = 0): array
    {
        $type = (string) $step['approver_type'];
        $ref = intval($step['approver_id'] ?? 0);
        $found = [];

        if ($type === 'user' && $ref > 0) {
            foreach ($this->activeAgents("u.user_id = $ref") as $id) { $found[] = ['user' => $id, 'contact' => null]; }
        } elseif ($type === 'role' && $ref > 0) {
            foreach ($this->activeAgents("u.user_role_id = $ref") as $id) { $found[] = ['user' => $id, 'contact' => null]; }
        } elseif ($type === 'requester_manager') {
            $managerId = $this->managerOf($requesterContactId, $clientId);
            if ($managerId) {
                $found[] = ['user' => null, 'contact' => $managerId];
            }
        }

        // Nobody may approve their own request when someone else can.
        if ($requesterUserId > 0) {
            $others = array_values(array_filter($found, fn($a) => $a['user'] !== $requesterUserId));
            if ($others) {
                $found = $others;
            }
        }
        if ($found) {
            return $found;
        }
        // Fallback: administrators (not the requester, unless they are the only ones).
        $admins = $this->activeAgents("r.role_is_admin = 1");
        $notSelf = array_values(array_filter($admins, fn($id) => $id !== $requesterUserId));
        $admins = $notSelf ?: $admins;
        return array_map(fn($id) => ['user' => $id, 'contact' => null], $admins);
    }

    /**
     * The contact id of $contactId's manager (contacts.contact_manager_id), or null when there is none, the manager is
     * archived, in another department, or is the requester themself.
     */
    public function managerOf(int $contactId, int $clientId): ?int
    {
        if ($contactId <= 0) {
            return null;
        }
        $row = $this->db->query("SELECT m.contact_id FROM contacts c JOIN contacts m ON m.contact_id = c.contact_manager_id WHERE c.contact_id = $contactId AND m.contact_client_id = c.contact_client_id AND m.contact_archived_at IS NULL AND m.contact_id <> c.contact_id" . ($clientId > 0 ? " AND c.contact_client_id = $clientId" : ''))->fetch_assoc();
        return $row ? intval($row['contact_id']) : null;
    }

    /** @return int[] user ids of active agents matching the extra condition (u = users, r = user_roles) */
    private function activeAgents(string $cond): array
    {
        $ids = [];
        $res = $this->db->query("SELECT u.user_id FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND $cond ORDER BY u.user_id ASC");
        while ($res && ($r = $res->fetch_row())) {
            $ids[] = intval($r[0]);
        }
        return $ids;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ticket hold / release / close (statuses are resolved by NAME; ids differ per install)
    // ------------------------------------------------------------------------------------------------------------

    private function statusIdByName(string $name): int
    {
        $stmt = $this->db->prepare("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = ? AND ticket_status_active = 1 ORDER BY ticket_status_id ASC LIMIT 1");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $row ? intval($row[0]) : 0;
    }

    /** The status a held ticket sits in: "Pending Approval" when an admin made one (flag it "Pauses SLA"), else "On Hold". 0 = none found. */
    public function holdStatusId(): int
    {
        return $this->statusIdByName('Pending Approval') ?: $this->statusIdByName('On Hold');
    }

    /** Mirrors resolveTicketCreationStatus() in functions.php (kept here so the service is testable without the app shell). */
    public function releaseStatusId(int $assignedTo): int
    {
        $default = $this->db->query("SELECT config_ticket_default_status_id FROM settings WHERE company_id = 1 LIMIT 1");
        $d = $default ? intval(($default->fetch_row()[0] ?? 0)) : 0;
        if ($d > 0) {
            return $d;
        }
        $id = $this->statusIdByName($assignedTo > 0 ? 'Assigned' : 'New');
        if (!$id) {
            $row = $this->db->query("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order ASC, ticket_status_id ASC LIMIT 1")->fetch_row();
            $id = $row ? intval($row[0]) : 0;
        }
        return $id;
    }

    private function holdTicket(int $ticketId): void
    {
        $hold = $this->holdStatusId();
        if ($hold > 0) {
            $this->db->query("UPDATE tickets SET ticket_status = $hold WHERE ticket_id = $ticketId");
        }
        $this->systemNote($ticketId, 'Held for approval. The ticket is released when every approval step has approved it.', 0);
        $this->syncSla($ticketId);
        $this->publishStatus($ticketId, $hold);
    }

    private function releaseTicket(int $ticketId): void
    {
        $t = $this->db->query("SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticketId")->fetch_assoc();
        if (!$t) {
            return;
        }
        $status = $this->releaseStatusId(intval($t['ticket_assigned_to']));
        if ($status > 0) {
            $this->db->query("UPDATE tickets SET ticket_status = $status WHERE ticket_id = $ticketId");
        }
        $this->systemNote($ticketId, 'All approvals received. Ticket released.', 0);
        $this->syncSla($ticketId);
        $this->publishStatus($ticketId, $status);
        if (intval($t['ticket_assigned_to']) > 0 && function_exists('notifyUser')) {
            notifyUser(intval($t['ticket_assigned_to']), 'Ticket', "Approved request released as ticket #$ticketId", "/agent/ticket.php?ticket_id=$ticketId", 0, $ticketId);
        }
    }

    private function closeRejected(int $ticketId, string $reason, int $byUserId): void
    {
        $closed = $this->statusIdByName('Closed') ?: 5;
        $this->db->query("UPDATE tickets SET ticket_status = $closed, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $byUserId WHERE ticket_id = $ticketId AND ticket_closed_at IS NULL");
        $this->systemNote($ticketId, 'Request rejected' . ($reason !== '' ? ': ' . $reason : '.') . ' Ticket closed.', 0);
        $this->syncSla($ticketId);
        $this->publishStatus($ticketId, $closed);
    }

    private function systemNote(int $ticketId, string $text, int $by): void
    {
        $note = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $stmt = $this->db->prepare("INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'System', ticket_reply_by = ?, ticket_reply_ticket_id = ?");
        $stmt->bind_param('sii', $note, $by, $ticketId);
        $stmt->execute();
        $stmt->close();
    }

    private function syncSla(int $ticketId): void
    {
        if (!function_exists('slaSyncPause')) {
            $f = dirname(__DIR__, 2) . '/includes/sla_functions.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
        if (function_exists('slaSyncPause')) {
            try { slaSyncPause($this->db, $ticketId); } catch (\Throwable $e) { /* SLA tables not present: nothing to pause */ }
        }
    }

    private function publishStatus(int $ticketId, int $statusId): void
    {
        if ($statusId > 0 && function_exists('publishTicketEvent') && function_exists('getTicketStatusInfo')) {
            $info = getTicketStatusInfo($this->db, $statusId);
            publishTicketEvent($ticketId, 'status', ['status_id' => $info['id'], 'status_name' => $info['name'], 'status_color' => $info['color'], 'by' => 'Approvals']);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Notifications (existing mechanisms only: notifyUser + the mail queue)
    // ------------------------------------------------------------------------------------------------------------

    private function ticketLabel(int $ticketId): string
    {
        $t = $this->db->query("SELECT ticket_prefix, ticket_number, ticket_subject FROM tickets WHERE ticket_id = $ticketId")->fetch_assoc();
        return $t ? ($t['ticket_prefix'] . $t['ticket_number'] . ' - ' . $t['ticket_subject']) : "#$ticketId";
    }

    private function queueMail(string $to, string $name, string $subject, string $bodyHtml): void
    {
        global $config_ticket_from_email, $config_ticket_from_name;
        if (!function_exists('addToMailQueue') || $to === '' || empty($config_ticket_from_email)) {
            return;
        }
        addToMailQueue([[
            'from' => $config_ticket_from_email,
            'from_name' => $config_ticket_from_name ?? '',
            'recipient' => $to,
            'recipient_name' => $name,
            'subject' => $subject,
            'body' => $bodyHtml,
        ]]);
    }

    /** @param array<int,array{user:?int,contact:?int}> $approvers */
    private function notifyApprovers(array $req, array $approvers): void
    {
        global $config_base_url;
        $ticketId = intval($req['ticket_id']);
        $label = $this->ticketLabel($ticketId);
        $esc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $base = !empty($config_base_url) ? 'https://' . $config_base_url : '';
        foreach ($approvers as $a) {
            if ($a['user']) {
                if (function_exists('notifyUser')) {
                    notifyUser($a['user'], 'Ticket', "Approval needed: $label", '/agent/service_catalog_approvals.php', intval($req['client_id']), $ticketId);
                }
                $u = $this->db->query("SELECT user_name, user_email FROM users WHERE user_id = " . $a['user'])->fetch_assoc();
                if ($u) {
                    $this->queueMail((string) $u['user_email'], (string) $u['user_name'], "Approval needed: $label", "A service request is waiting for your decision.<br><br><b>$esc</b><br><br><a href=\"$base/agent/service_catalog_approvals.php\">Review the request</a>");
                }
            } elseif ($a['contact']) {
                $c = $this->db->query("SELECT contact_name, contact_email FROM contacts WHERE contact_id = " . $a['contact'])->fetch_assoc();
                if ($c) {
                    $this->queueMail((string) $c['contact_email'], (string) $c['contact_name'], "Approval needed: $label", "A service request is waiting for your decision.<br><br><b>$esc</b><br><br><a href=\"$base/client/my_approvals.php\">Review the request</a>");
                }
            }
        }
    }

    private function notifyOutcome(array $req, bool $approved, string $reason): void
    {
        $label = $this->ticketLabel(intval($req['ticket_id']));
        $esc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $verb = $approved ? 'approved' : 'rejected';
        $extra = (!$approved && $reason !== '') ? '<br>Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : '';
        if (intval($req['contact_id']) > 0) {
            $c = $this->db->query("SELECT contact_name, contact_email FROM contacts WHERE contact_id = " . intval($req['contact_id']))->fetch_assoc();
            if ($c) {
                $this->queueMail((string) $c['contact_email'], (string) $c['contact_name'], "Your request was $verb: $label", "Your request <b>$esc</b> was $verb.$extra");
            }
        }
        if (intval($req['requested_by_user_id']) > 0 && function_exists('notifyUser')) {
            notifyUser(intval($req['requested_by_user_id']), 'Ticket', "Request $verb: $label", '/agent/ticket.php?ticket_id=' . intval($req['ticket_id']), intval($req['client_id']), intval($req['ticket_id']));
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Reading: the ticket's request details, approval inboxes, trending / recent
    // ------------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function requestForTicket(int $ticketId): ?array
    {
        $r = $this->db->query("SELECT * FROM service_catalog_requests WHERE ticket_id = $ticketId LIMIT 1")->fetch_assoc();
        return $r ?: null;
    }

    /**
     * Read-only "Request details" block for a ticket page: submitted values and the approval trail, every value escaped.
     * Returns '' when the ticket has no request. The caller is responsible for having checked access to the ticket.
     */
    public function requestDetailsHtml(int $ticketId): string
    {
        $req = $this->requestForTicket($ticketId);
        if (!$req) {
            return '';
        }
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $values = json_decode((string) $req['field_values'], true);
        $values = is_array($values) ? $values : [];
        $labels = ['pending_approval' => ['Waiting for approval', 'warning'], 'approved' => ['Approved', 'success'], 'rejected' => ['Rejected', 'danger'], 'not_required' => ['', '']];
        [$statusText, $statusClass] = $labels[$req['status']] ?? ['', ''];

        $out = '<div class="card mb-3" id="catalogRequestDetails"><div class="card-body"><h5 class="mb-3">Request details';
        if ($statusText !== '') {
            $out .= ' <span class="badge text-bg-' . $statusClass . ' ms-2">' . $h($statusText) . '</span>';
        }
        $out .= '</h5>';
        if ($values) {
            $out .= '<dl class="row mb-0">';
            foreach ($values as $v) {
                $val = (string) ($v['value'] ?? '');
                $out .= '<dt class="col-sm-4">' . $h($v['label'] ?? '') . '</dt><dd class="col-sm-8">' . ($val === '' ? '<span class="text-secondary">-</span>' : nl2br($h($val))) . '</dd>';
            }
            $out .= '</dl>';
        }

        $trail = $this->db->query("SELECT a.*, u.user_name, c.contact_name FROM service_catalog_request_approvals a LEFT JOIN users u ON u.user_id = a.approver_user_id LEFT JOIN contacts c ON c.contact_id = a.approver_contact_id WHERE a.request_id = " . intval($req['request_id']) . " ORDER BY a.step_order ASC, a.approval_id ASC");
        if ($trail && $trail->num_rows > 0) {
            $out .= '<hr><h6>Approvals</h6><ul class="list-unstyled mb-0">';
            while ($a = $trail->fetch_assoc()) {
                $name = $a['user_name'] ?: ($a['contact_name'] ?: 'Approver');
                $cls = ['approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', 'skipped' => 'secondary'][$a['status']] ?? 'secondary';
                $out .= '<li>Step ' . intval($a['step_order']) . ': ' . $h($name) . ' <span class="badge text-bg-' . $cls . '">' . $h(ucfirst($a['status'])) . '</span>';
                if ($a['decided_at']) { $out .= ' <small class="text-secondary">' . $h($a['decided_at']) . '</small>'; }
                if ((string) $a['comment'] !== '') { $out .= '<br><small class="text-secondary">' . nl2br($h($a['comment'])) . '</small>'; }
                $out .= '</li>';
            }
            $out .= '</ul>';
        }
        if ($req['status'] === 'rejected' && (string) $req['rejection_reason'] !== '') {
            $out .= '<p class="mt-2 mb-0"><strong>Rejection reason:</strong> ' . nl2br($h($req['rejection_reason'])) . '</p>';
        }
        return $out . '</div></div>';
    }

    /**
     * Pending approvals for one approver, current step only. Pass exactly one of $userId / $contactId; $clientId scopes
     * a portal contact to their own department. $all (administrators) lists everything pending.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingApprovals(?int $userId, ?int $contactId = null, int $clientId = 0, bool $all = false): array
    {
        $base = "SELECT a.approval_id, a.step_order, a.step_mode, r.request_id, r.ticket_id, r.client_id, r.contact_id, r.risk_score, r.field_values, r.created_at,
                    i.name AS item_name, t.ticket_prefix, t.ticket_number, t.ticket_subject, c.contact_name AS requester_name, cl.client_name
                 FROM service_catalog_request_approvals a
                 JOIN service_catalog_requests r ON r.request_id = a.request_id AND r.status = 'pending_approval' AND r.current_step = a.step_order
                 JOIN service_catalog_items i ON i.catalog_item_id = r.catalog_item_id
                 JOIN tickets t ON t.ticket_id = r.ticket_id
                 LEFT JOIN contacts c ON c.contact_id = r.contact_id
                 LEFT JOIN clients cl ON cl.client_id = r.client_id
                 WHERE a.status = 'pending'";
        if ($all) {
            $sql = "$base GROUP BY r.request_id ORDER BY r.created_at ASC";
        } elseif ($userId) {
            $sql = "$base AND a.approver_user_id = " . intval($userId) . " ORDER BY r.created_at ASC";
        } elseif ($contactId && $clientId > 0) {
            $sql = "$base AND a.approver_contact_id = " . intval($contactId) . " AND r.client_id = " . intval($clientId) . " ORDER BY r.created_at ASC";
        } else {
            return [];
        }
        $rows = [];
        $res = $this->db->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** Is this contact an approver (a manager somebody reports to) in this department? Drives the portal nav entry. */
    public function contactIsApprover(int $contactId, int $clientId): bool
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return false;
        }
        $a = (int) $this->db->query("SELECT COUNT(*) FROM service_catalog_request_approvals a JOIN service_catalog_requests r ON r.request_id = a.request_id WHERE a.approver_contact_id = $contactId AND r.client_id = $clientId")->fetch_row()[0];
        if ($a > 0) {
            return true;
        }
        $m = (int) $this->db->query("SELECT COUNT(*) FROM contacts WHERE contact_manager_id = $contactId AND contact_client_id = $clientId AND contact_archived_at IS NULL")->fetch_row()[0];
        return $m > 0;
    }

    /**
     * Most requested active items in the last $days days (counts tickets carrying ticket_catalog_item_id).
     *
     * @return array<int,array{catalog_item_id:int,name:string,icon:?string,uses:int}>
     */
    public function trending(int $limit = 5, int $days = 30): array
    {
        $limit = max(1, min(50, $limit));
        $days = max(1, min(365, $days));
        $rows = [];
        $res = $this->db->query("SELECT i.catalog_item_id, i.name, i.icon, COUNT(*) AS uses
            FROM tickets t JOIN service_catalog_items i ON i.catalog_item_id = t.ticket_catalog_item_id
            WHERE t.ticket_catalog_item_id IS NOT NULL AND i.is_active = 1 AND t.ticket_archived_at IS NULL
              AND t.ticket_created_at >= NOW() - INTERVAL $days DAY
            GROUP BY i.catalog_item_id, i.name, i.icon
            ORDER BY uses DESC, i.name ASC LIMIT $limit");
        while ($res && ($r = $res->fetch_assoc())) {
            $r['catalog_item_id'] = intval($r['catalog_item_id']);
            $r['uses'] = intval($r['uses']);
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * The last distinct active items this contact requested, newest first.
     *
     * @return array<int,array{catalog_item_id:int,name:string,icon:?string,last_used:string}>
     */
    public function recentForContact(int $contactId, int $clientId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $rows = [];
        $res = $this->db->query("SELECT i.catalog_item_id, i.name, i.icon, MAX(t.ticket_created_at) AS last_used
            FROM tickets t JOIN service_catalog_items i ON i.catalog_item_id = t.ticket_catalog_item_id
            WHERE t.ticket_contact_id = " . intval($contactId) . " AND t.ticket_client_id = " . intval($clientId) . "
              AND t.ticket_catalog_item_id IS NOT NULL AND i.is_active = 1 AND t.ticket_archived_at IS NULL
            GROUP BY i.catalog_item_id, i.name, i.icon
            ORDER BY last_used DESC, i.catalog_item_id DESC LIMIT $limit");
        while ($res && ($r = $res->fetch_assoc())) {
            $r['catalog_item_id'] = intval($r['catalog_item_id']);
            $rows[] = $r;
        }
        return $rows;
    }
}
