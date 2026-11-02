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
    public const FIELD_TYPES = ['text', 'textarea', 'select', 'checkbox', 'date', 'number', 'table'];
    /** Repeating-table limits (DB 2.6.211 needs no schema for them: the columns live in service_catalog_fields.options). */
    public const TABLE_MAX_COLUMNS = 8;
    public const TABLE_MAX_ROWS = 25;
    public const TABLE_COLUMN_TYPES = ['text', 'number', 'date'];
    /** Risk-rule operators: add points to the computed risk score when an answer matches. */
    public const RISK_OPS = ['equals', 'in', 'not_empty', 'gte', 'lte'];
    public const SHOW_IF_MAX_CONDITIONS = 3;
    /** Timeout actions (item level). */
    public const MAX_REMINDERS = 5;
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

    /**
     * The item's approval steps. With $risk given, only the steps whose risk band (risk_min..risk_max) contains that computed score:
     * that is the risk-based routing, a request only ever sees the chain meant for its risk level.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSteps(int $catalogItemId, ?int $risk = null): array
    {
        $rows = [];
        $where = $risk === null ? '' : ' AND risk_min <= ' . intval($risk) . ' AND risk_max >= ' . intval($risk);
        $res = $this->db->query("SELECT * FROM service_catalog_approval_steps WHERE catalog_item_id = $catalogItemId$where ORDER BY step_order ASC, step_id ASC");
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
            if ($type === 'table') {
                $options = self::normalizeTableColumns((string) ($post['field_options'][$i] ?? ''));
                if ($options === '') {
                    $type = 'text'; // a table with no usable column is just a text question
                    $options = null;
                }
            } elseif ($type === 'select') {
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
                'show_if' => self::buildShowIfMulti([
                    [(string) ($post['field_showif_field'][$i] ?? ''), (string) ($post['field_showif_op'][$i] ?? ''), (string) ($post['field_showif_value'][$i] ?? '')],
                    [(string) ($post['field_showif2_field'][$i] ?? ''), (string) ($post['field_showif2_op'][$i] ?? ''), (string) ($post['field_showif2_value'][$i] ?? '')],
                    [(string) ($post['field_showif3_field'][$i] ?? ''), (string) ($post['field_showif3_op'][$i] ?? ''), (string) ($post['field_showif3_value'][$i] ?? '')],
                ], (string) ($post['field_showif_match'][$i] ?? 'all')),
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
     * Parallel groups: a row with step_parallel[i] ticked joins the row before it (in step order) as one stage, so several
     * approvers or roles decide at the same time. A stage is complete when every one of its rows is satisfied. A row with no
     * tick starts a new stage, which is exactly how steps behaved before parallel groups existed.
     * Risk band: step_risk_min[i] / step_risk_max[i] (0-100, default 0-100) make a row apply only to requests whose computed risk
     * score falls inside the band, which is how different risk levels get different approval chains.
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
            $min = max(0, min(100, intval($post['step_risk_min'][$i] ?? 0)));
            $maxRaw = $post['step_risk_max'][$i] ?? '';
            $max = ($maxRaw === '' || $maxRaw === null) ? 100 : max(0, min(100, intval($maxRaw)));
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }
            $out[] = [
                'approver_type' => $type,
                'approver_id' => $type === 'requester_manager' ? null : $ref,
                'mode' => in_array($mode, self::MODES, true) ? $mode : 'any',
                'risk_min' => $min,
                'risk_max' => $max,
                'parallel' => !empty($post['step_parallel'][$i]),
                'sort' => intval($post['step_order'][$i] ?? 0),
                '_pos' => count($out),
            ];
        }
        usort($out, fn($a, $b) => [$a['sort'], $a['_pos']] <=> [$b['sort'], $b['_pos']]);
        $stage = 0;
        foreach ($out as $n => &$r) {
            if ($n === 0 || !$r['parallel']) {
                $stage++;
            }
            $r['step_order'] = $stage;
            unset($r['sort'], $r['_pos']);
        }
        unset($r);
        return $out;
    }

    /**
     * Normalise the columns of a repeating-table question: one column per line, "Heading" or "Heading|type" with type text, number
     * or date. At most TABLE_MAX_COLUMNS columns, headings up to 60 characters, duplicate headings dropped. Returns the lines joined
     * with "\n" ('' when no usable column).
     */
    public static function normalizeTableColumns(string $raw): string
    {
        $out = [];
        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $parts = explode('|', $line, 2);
            $label = trim(str_replace(['|', "\n"], ' ', $parts[0]));
            $type = strtolower(trim($parts[1] ?? 'text'));
            if ($label === '') {
                continue;
            }
            $label = mb_substr($label, 0, 60);
            if (isset($seen[strtolower($label)])) {
                continue;
            }
            $seen[strtolower($label)] = true;
            $out[] = $label . '|' . (in_array($type, self::TABLE_COLUMN_TYPES, true) ? $type : 'text');
            if (count($out) >= self::TABLE_MAX_COLUMNS) {
                break;
            }
        }
        return implode("\n", $out);
    }

    /** @return array<int,array{label:string,type:string}> the columns of a table question */
    public static function tableColumns(?string $options): array
    {
        $cols = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $options) ?: [] as $line) {
            $parts = explode('|', $line, 2);
            $label = trim($parts[0]);
            if ($label === '') {
                continue;
            }
            $type = strtolower(trim($parts[1] ?? 'text'));
            $cols[] = ['label' => $label, 'type' => in_array($type, self::TABLE_COLUMN_TYPES, true) ? $type : 'text'];
        }
        return array_slice($cols, 0, self::TABLE_MAX_COLUMNS);
    }

    /**
     * Turn the admin form's risk-rule arrays into clean rows. A rule adds (or, when negative, removes) points from the item's base
     * risk score when an answer matches: risk_field[i] = question key, risk_op[i], risk_value[i], risk_points[i] (-100..100).
     *
     * @param array<string,mixed> $post
     * @return array<int,array{field_key:string,op:string,value:?string,points:int,sort_order:int}>
     */
    public static function normalizeRiskRuleRows(array $post): array
    {
        $out = [];
        foreach ((array) ($post['risk_field'] ?? []) as $i => $field) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower(is_scalar($field) ? (string) $field : ''));
            $op = (string) ($post['risk_op'][$i] ?? '');
            $points = intval($post['risk_points'][$i] ?? 0);
            if ($key === '' || !in_array($op, self::RISK_OPS, true) || $points === 0 || !empty($post['risk_remove'][$i])) {
                continue;
            }
            $value = trim((string) ($post['risk_value'][$i] ?? ''));
            if ($op === 'in') {
                $value = implode('|', array_slice(array_values(array_unique(array_filter(array_map('trim', explode('|', $value)), 'strlen'))), 0, 50));
            }
            if ($op === 'gte' || $op === 'lte') {
                if (!is_numeric($value)) {
                    continue;
                }
                $value = (string) (0 + $value);
            }
            if (($op === 'equals' || $op === 'in') && $value === '') {
                continue;
            }
            $out[] = [
                'field_key' => substr($key, 0, 64),
                'op' => $op,
                'value' => $op === 'not_empty' ? null : substr($value, 0, 400),
                'points' => max(-100, min(100, $points)),
                'sort_order' => count($out) + 1,
            ];
            if (count($out) >= 30) {
                break;
            }
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

    /**
     * Build the stored JSON from up to SHOW_IF_MAX_CONDITIONS conditions typed in the admin editor. One usable condition is stored in the
     * original single-rule shape (so older readers and the mobile API keep working); two or more are stored as
     * {"match":"all|any","rules":[...]}. Null when no usable condition was given.
     *
     * @param array<int,array{0:string,1:string,2:string}> $conditions list of [field, op, value]
     */
    public static function buildShowIfMulti(array $conditions, string $match = 'all'): ?string
    {
        $rules = [];
        foreach ($conditions as $c) {
            $json = self::buildShowIf((string) ($c[0] ?? ''), (string) ($c[1] ?? ''), (string) ($c[2] ?? ''));
            if ($json !== null) {
                $rules[] = json_decode($json, true);
            }
            if (count($rules) >= self::SHOW_IF_MAX_CONDITIONS) {
                break;
            }
        }
        if (!$rules) {
            return null;
        }
        if (count($rules) === 1) {
            return json_encode($rules[0], JSON_UNESCAPED_UNICODE);
        }
        return json_encode(['match' => $match === 'any' ? 'any' : 'all', 'rules' => $rules], JSON_UNESCAPED_UNICODE);
    }

    /** Validate one single rule array (field, op, value); returns it or null. */
    private static function parseSingleRule($rule): ?array
    {
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
     * Decode a stored rule defensively; null when absent or malformed (a malformed rule never hides a field). Returns either one rule
     * {"field","op","value"} or a compound {"match":"all|any","rules":[rule, ...]} (every inner rule must be valid, else null).
     */
    public static function parseShowIf($raw): ?array
    {
        $rule = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($rule)) {
            return null;
        }
        if (isset($rule['rules'])) {
            if (!is_array($rule['rules']) || !$rule['rules'] || count($rule['rules']) > self::SHOW_IF_MAX_CONDITIONS) {
                return null;
            }
            $inner = [];
            foreach (array_values($rule['rules']) as $r) {
                $one = self::parseSingleRule($r);
                if ($one === null) {
                    return null;
                }
                $inner[] = $one;
            }
            return ['match' => (($rule['match'] ?? 'all') === 'any') ? 'any' : 'all', 'rules' => $inner];
        }
        return self::parseSingleRule($rule);
    }

    /** The single rules inside a parsed rule (one for a plain rule, several for a compound one). @return array<int,array<string,mixed>> */
    public static function showIfRules(?array $rule): array
    {
        if ($rule === null) {
            return [];
        }
        return isset($rule['rules']) ? $rule['rules'] : [$rule];
    }

    /**
     * Is a rule met? $answers maps field key => the effective answer as a string ('' when empty or hidden; a checkbox is
     * 'Yes' when ticked). Used by the server; js/catalog_show_if.js mirrors it in the browser. A compound rule is met when all
     * (match = all) or at least one (match = any) of its inner rules is met.
     *
     * @param array<string,string> $answers
     */
    public static function showIfMet(?array $rule, array $answers): bool
    {
        if ($rule === null) {
            return true;
        }
        if (isset($rule['rules'])) {
            $any = ($rule['match'] ?? 'all') === 'any';
            foreach ($rule['rules'] as $inner) {
                $met = self::showIfMet($inner, $answers);
                if ($any && $met) {
                    return true;
                }
                if (!$any && !$met) {
                    return false;
                }
            }
            return !$any;
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
     * exists and comes EARLIER in the form; that also rules out cycles and self-references. Every condition of a compound
     * rule is checked. Returns error messages.
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
                continue;
            }
            foreach (self::showIfRules($rule) as $one) {
                if (!isset($pos[$one['field']])) {
                    $errors[] = "The condition on \"$label\" refers to a question that does not exist.";
                    break;
                } elseif ($pos[$one['field']] >= $n) {
                    $errors[] = "The condition on \"$label\" must refer to a question that comes earlier in the form.";
                    break;
                }
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
        if (isset($rule['rules'])) {
            $parts = [];
            foreach ($rule['rules'] as $one) {
                $parts[] = preg_replace('/^Shown when /', '', self::describeShowIf($one, $labelsByKey));
            }
            return 'Shown when ' . implode(($rule['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ', $parts);
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
            $stmt = $this->db->prepare("INSERT INTO service_catalog_approval_steps (catalog_item_id, step_order, approver_type, approver_id, mode, risk_min, risk_max) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $min = intval($r['risk_min'] ?? 0);
            $max = intval($r['risk_max'] ?? 100);
            $stmt->bind_param('iisisii', $catalogItemId, $r['step_order'], $r['approver_type'], $r['approver_id'], $r['mode'], $min, $max);
            $stmt->execute();
            $stmt->close();
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function getRiskRules(int $catalogItemId): array
    {
        $rows = [];
        $res = $this->db->query("SELECT * FROM service_catalog_risk_rules WHERE catalog_item_id = $catalogItemId ORDER BY sort_order ASC, rule_id ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** Replace an item's risk rules with the (already normalised) rows. */
    public function saveRiskRules(int $catalogItemId, array $rows): void
    {
        $this->db->query("DELETE FROM service_catalog_risk_rules WHERE catalog_item_id = $catalogItemId");
        foreach ($rows as $r) {
            $stmt = $this->db->prepare("INSERT INTO service_catalog_risk_rules (catalog_item_id, field_key, op, value, points, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssii', $catalogItemId, $r['field_key'], $r['op'], $r['value'], $r['points'], $r['sort_order']);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Compute a request's risk score: the item's base score plus the points of every rule whose condition the answers meet,
     * clamped to 0-100. Hidden questions are not in $values, so they count as unanswered. Returns the score and an explanation
     * (base, then one line per matched rule) that is stored on the request so the score can be audited later.
     *
     * @param array<string,mixed> $item  a service_catalog_items row
     * @param array<int,array<string,string>> $values  validateInput()['values']
     * @param array<int,array<string,mixed>>|null $rules  risk rules (loaded from the database when null)
     * @return array{score:int,base:int,matched:array<int,array{field_key:string,op:string,value:?string,points:int}>}
     */
    public function computeRisk(array $item, array $values, ?array $rules = null): array
    {
        $base = max(0, min(100, intval($item['risk_score'] ?? 0)));
        $rules ??= $this->getRiskRules(intval($item['catalog_item_id'] ?? 0));
        $answers = [];
        foreach ($values as $v) {
            $val = (string) ($v['value'] ?? '');
            $type = (string) ($v['type'] ?? '');
            if ($type === 'checkbox') {
                $val = $val === 'Yes' ? 'Yes' : '';
            } elseif ($type === 'table') {
                $decoded = json_decode($val, true);
                $n = is_array($decoded['rows'] ?? null) ? count($decoded['rows']) : 0;
                $val = $n > 0 ? (string) $n : '';
            }
            $answers[(string) $v['key']] = $val;
        }
        $total = $base;
        $matched = [];
        foreach ($rules as $r) {
            $a = (string) ($answers[(string) $r['field_key']] ?? '');
            $op = (string) $r['op'];
            $val = (string) ($r['value'] ?? '');
            $hit = false;
            switch ($op) {
                case 'equals':
                    $hit = $a !== '' && strcasecmp($a, $val) === 0;
                    break;
                case 'in':
                    foreach (explode('|', $val) as $opt) {
                        if ($a !== '' && strcasecmp($a, trim($opt)) === 0) { $hit = true; break; }
                    }
                    break;
                case 'not_empty':
                    $hit = $a !== '';
                    break;
                case 'gte':
                    $hit = $a !== '' && is_numeric($a) && is_numeric($val) && (float) $a >= (float) $val;
                    break;
                case 'lte':
                    $hit = $a !== '' && is_numeric($a) && is_numeric($val) && (float) $a <= (float) $val;
                    break;
            }
            if ($hit) {
                $total += intval($r['points']);
                $matched[] = ['field_key' => (string) $r['field_key'], 'op' => $op, 'value' => $r['value'] === null ? null : (string) $r['value'], 'points' => intval($r['points'])];
            }
        }
        return ['score' => max(0, min(100, $total)), 'base' => $base, 'matched' => $matched];
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
            if ($type === 'table') {
                $t = self::validateTable($f, $raw);
                if ($t['error'] !== null) {
                    $errors[] = $t['error'];
                    continue;
                }
                if (!$t['rows']) {
                    if ($required) {
                        $errors[] = "$label needs at least one row.";
                        continue;
                    }
                    $values[] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => ''];
                    $answers[$key] = '';
                    continue;
                }
                $values[] = ['key' => $key, 'label' => $label, 'type' => $type, 'value' => json_encode(['cols' => $t['cols'], 'rows' => $t['rows']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                $answers[$key] = (string) count($t['rows']);
                continue;
            }
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
     * Validate one repeating-table answer. Each posted row is a list of cells in column order (or an object keyed by column heading,
     * which is how the API sends them). Rows whose cells are all empty are dropped; at most TABLE_MAX_ROWS rows are kept.
     *
     * @param array<string,mixed> $f  the field row
     * @return array{error:?string,cols:string[],rows:array<int,string[]>}
     */
    public static function validateTable(array $f, $raw): array
    {
        $label = (string) $f['label'];
        $cols = self::tableColumns($f['options'] ?? '');
        $heads = array_column($cols, 'label');
        $fail = fn(string $m) => ['error' => $m, 'cols' => $heads, 'rows' => []];
        if (!$cols) {
            return $fail("$label has no columns.");
        }
        if ($raw === null || $raw === '') {
            return ['error' => null, 'cols' => $heads, 'rows' => []];
        }
        if (!is_array($raw) || count($raw) > 4 * self::TABLE_MAX_ROWS) {
            return $fail("$label is not valid.");
        }
        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                return $fail("$label is not valid.");
            }
            $cells = [];
            $any = false;
            foreach ($cols as $ci => $col) {
                $cell = $row[$ci] ?? $row[$col['label']] ?? $row['c' . $ci] ?? '';
                if (is_bool($cell)) {
                    $cell = $cell ? '1' : '0';
                } elseif (is_int($cell) || is_float($cell)) {
                    $cell = (string) $cell;
                }
                if (!is_string($cell)) {
                    return $fail("$label is not valid.");
                }
                $cell = trim($cell);
                if ($cell !== '') {
                    $any = true;
                    if (mb_strlen($cell) > 200) {
                        return $fail("$label: \"{$col['label']}\" is too long (200 characters at most).");
                    }
                    if ($col['type'] === 'number' && (!is_numeric($cell) || abs((float) $cell) > 1.0E+12)) {
                        return $fail("$label: \"{$col['label']}\" must be a number.");
                    }
                    if ($col['type'] === 'date') {
                        $d = \DateTime::createFromFormat('Y-m-d', $cell);
                        if (!$d || $d->format('Y-m-d') !== $cell) {
                            return $fail("$label: \"{$col['label']}\" must be a valid date.");
                        }
                    }
                }
                $cells[] = $cell;
            }
            if ($any) {
                $rows[] = $cells;
            }
        }
        if (count($rows) > self::TABLE_MAX_ROWS) {
            return $fail("$label has too many rows (" . self::TABLE_MAX_ROWS . " at most).");
        }
        return ['error' => null, 'cols' => $heads, 'rows' => $rows];
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
        $hasTable = false;
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
            if ($type === 'table') {
                $hasTable = true;
                $cols = self::tableColumns($f['options'] ?? '');
                $out .= str_replace('data-catalog-key=', 'data-catalog-table="' . $h($f['field_key']) . '" data-catalog-key=', $wrap)
                    . '<label>' . $h($f['label']) . $star . '</label><div class="table-responsive"><table class="table table-sm mb-1"><thead><tr>';
                foreach ($cols as $c) {
                    $out .= '<th>' . $h($c['label']) . '</th>';
                }
                $out .= '</tr></thead><tbody>';
                for ($row = 0; $row < 3; $row++) {
                    $out .= '<tr data-catalog-table-row>';
                    foreach ($cols as $ci => $c) {
                        $inType = ['date' => 'date', 'number' => 'number'][$c['type']] ?? 'text';
                        $step = $c['type'] === 'number' ? ' step="any"' : ' maxlength="200"';
                        $cellName = $preview ? '' : ' name="catalog_field[' . $h($f['field_key']) . '][' . $row . '][' . $ci . ']"';
                        $out .= '<td><input type="' . $inType . '" class="form-control form-control-sm"' . $cellName . $step . ' aria-label="' . $h($c['label']) . ' row ' . ($row + 1) . '"></td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</tbody></table></div><button type="button" class="btn btn-sm btn-outline-secondary" data-catalog-table-add>+ Add row</button>'
                    . '<small class="text-secondary ms-2">Up to ' . self::TABLE_MAX_ROWS . ' rows; blank rows are ignored.</small></div>';
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
        if ($hasTable) {
            $out .= '<script src="/js/catalog_table.js?v=' . (int) @filemtime(__DIR__ . '/../../js/catalog_table.js') . '"></script>';
        }
        if ($conditional) {
            // Minimal show/hide script; it mirrors showIfMet() and is only a convenience - the server decides.
            $out .= '<script src="/js/catalog_show_if.js?v=' . (int) @filemtime(__DIR__ . '/../../js/catalog_show_if.js') . '"></script>';
        }
        return $out;
    }

    /**
     * Does a request for this item have to wait for approval? Requires the switch, at least one step, and a risk score
     * that is not below the auto-approve threshold (threshold 0 = never auto-approve). $item['risk_score'] is the score to judge,
     * so submit() passes the COMPUTED score; $stepCount is the number of steps that apply at that score.
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
     * first stage's approvers. The risk score is COMPUTED here (base score plus matching risk rules) and decides both the
     * auto-approve test and which approval steps apply (each step has a risk band). Returns ['request_id'=>int,'status'=>string].
     *
     * @param array<string,mixed> $item
     * @param array<int,array<string,string>> $values  output of validateInput()['values']
     * @return array{request_id:int,status:string}
     */
    public function submit(array $item, int $ticketId, int $clientId, int $contactId, int $requestedByUserId, array $values): array
    {
        $itemId = intval($item['catalog_item_id']);
        $riskInfo = $this->computeRisk($item, $values);
        $risk = $riskInfo['score'];
        $allSteps = $this->getSteps($itemId);
        $steps = $this->getSteps($itemId, $risk);
        $needs = $this->needsApproval(array_merge($item, ['risk_score' => $risk]), count($steps));
        // A below-threshold risk score on an item that asks for approval is auto-approved (recorded as approved), and so is a risk
        // level for which no step is configured (risk routing: the chain only exists for the levels that need one).
        $status = $needs ? 'pending_approval' : (!empty($item['requires_approval']) && $allSteps ? 'approved' : 'not_required');
        $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $breakdown = $riskInfo['matched'] ? json_encode(['base' => $riskInfo['base'], 'matched' => $riskInfo['matched']], JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->db->prepare("INSERT INTO service_catalog_requests (catalog_item_id, ticket_id, client_id, contact_id, requested_by_user_id, field_values, status, current_step, risk_score, risk_breakdown) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
        $stmt->bind_param('iiiiissis', $itemId, $ticketId, $clientId, $contactId, $requestedByUserId, $json, $status, $risk, $breakdown);
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
     * must belong to $clientId). Only a pending row for the request's CURRENT stage can be decided, so a stale, replayed
     * or guessed request id changes nothing. $override lets an administrator decide a stage on behalf of its approvers
     * (used when e.g. a manager has no portal login); it is recorded in the comment.
     *
     * A stage may hold several parallel branches (rows that share a step order). The stage is complete when every branch is
     * satisfied: an "any" branch once one of its approvers approved, an "all" branch once none of its approvers is still pending.
     * An escalation approver (added by the timeout worker) decides the whole stage, like an override.
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
            $stageWide = false; // true when this decision settles the whole stage (override or escalation approver)
            $myRows = [];

            if ($override && $userId) {
                $where = "request_id = $requestId AND step_order = $step AND status = 'pending'";
                $row = $this->db->query("SELECT approval_id FROM service_catalog_request_approvals WHERE $where ORDER BY approval_id ASC LIMIT 1")->fetch_assoc();
                if (!$row) {
                    $this->db->rollback();
                    return ['ok' => false, 'error' => 'There is nothing pending on this request.', 'status' => $req['status']];
                }
                // An override decides the whole stage: the first pending row carries the decision, the rest are skipped.
                $approvalId = intval($row['approval_id']);
                $myRows = [$approvalId];
                $comment = trim('[Administrator override] ' . $comment);
                $this->db->query("UPDATE service_catalog_request_approvals SET approver_user_id = " . intval($userId) . ", approver_contact_id = NULL WHERE approval_id = $approvalId");
                $stageWide = true;
            } else {
                $who = $userId ? "approver_user_id = " . intval($userId) : "approver_contact_id = " . intval($contactId);
                $res = $this->db->query("SELECT approval_id, is_escalation FROM service_catalog_request_approvals WHERE request_id = $requestId AND step_order = $step AND status = 'pending' AND $who ORDER BY approval_id ASC");
                while ($res && ($row = $res->fetch_assoc())) {
                    $myRows[] = intval($row['approval_id']);
                    if (!empty($row['is_escalation'])) {
                        $stageWide = true;
                    }
                }
                if (!$myRows) {
                    $this->db->rollback();
                    return ['ok' => false, 'error' => 'You are not an approver for this request, or you already decided.', 'status' => $req['status']];
                }
            }

            // One person can sit in two parallel branches; one decision covers every row they hold in this stage.
            $new = $approve ? 'approved' : 'rejected';
            $ids = implode(',', array_map('intval', $myRows));
            $stmt = $this->db->prepare("UPDATE service_catalog_request_approvals SET status = ?, comment = ?, decided_at = NOW() WHERE approval_id IN ($ids) AND status = 'pending'");
            $stmt->bind_param('ss', $new, $comment);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed < 1) {
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
                $stageDone = $stageWide || $this->stageSatisfied($requestId, $step);
                if ($stageDone) {
                    $this->db->query("UPDATE service_catalog_request_approvals SET status = 'skipped', decided_at = NOW() WHERE request_id = $requestId AND step_order = $step AND status = 'pending'");
                    $next = $this->nextStageOrder(intval($req['catalog_item_id']), $step, intval($req['risk_score']));
                    if ($next === null) {
                        $this->db->query("UPDATE service_catalog_requests SET status = 'approved', decided_at = NOW() WHERE request_id = $requestId");
                        $final = 'approved';
                    } else {
                        $this->db->commit();
                        $this->activateStep($requestId, $next);
                        return ['ok' => true, 'error' => null, 'status' => 'pending_approval'];
                    }
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['ok' => false, 'error' => 'The decision could not be saved.', 'status' => null];
        }

        $this->finalize($req, $final, $comment, $userId);
        return ['ok' => true, 'error' => null, 'status' => $final ?? 'pending_approval'];
    }

    /** After a final decision: release or close the ticket, tell the requester, emit the event. */
    private function finalize(array $req, ?string $final, string $comment, ?int $userId): void
    {
        if ($final === 'approved') {
            $this->releaseTicket(intval($req['ticket_id']));
            $this->notifyOutcome($req, true, '');
            $this->emitDecision($req, 'catalog.request_approved', $userId, '');
        } elseif ($final === 'rejected') {
            $this->closeRejected(intval($req['ticket_id']), $comment, intval($userId));
            $this->notifyOutcome($req, false, $comment);
            $this->emitDecision($req, 'catalog.request_rejected', $userId, $comment);
        }
    }

    /** The next stage after $step that has at least one step applying at $risk, or null when $step was the last. */
    private function nextStageOrder(int $itemId, int $step, int $risk): ?int
    {
        $next = $this->db->query("SELECT MIN(step_order) FROM service_catalog_approval_steps WHERE catalog_item_id = $itemId AND step_order > $step AND risk_min <= $risk AND risk_max >= $risk")->fetch_row()[0];
        return $next === null ? null : intval($next);
    }

    /**
     * Is every parallel branch of the stage satisfied? A branch is the set of rows created from one approval step (step_id; rows
     * from before parallel groups existed have none and form one branch). Escalation rows are not branches. As a side effect the
     * still-pending rows of a satisfied "any" branch are marked skipped.
     */
    private function stageSatisfied(int $requestId, int $step): bool
    {
        $res = $this->db->query("SELECT COALESCE(step_id, 0) AS branch, MIN(step_mode) AS mode, SUM(status = 'approved') AS ap, SUM(status = 'pending') AS pe
            FROM service_catalog_request_approvals WHERE request_id = $requestId AND step_order = $step AND is_escalation = 0 GROUP BY COALESCE(step_id, 0)");
        $all = true;
        while ($res && ($b = $res->fetch_assoc())) {
            $sat = $b['mode'] === 'any' ? intval($b['ap']) > 0 : (intval($b['pe']) === 0 && intval($b['ap']) > 0);
            if ($sat && $b['mode'] === 'any' && intval($b['pe']) > 0) {
                $this->db->query("UPDATE service_catalog_request_approvals SET status = 'skipped', decided_at = NOW() WHERE request_id = $requestId AND step_order = $step AND is_escalation = 0 AND COALESCE(step_id, 0) = " . intval($b['branch']) . " AND status = 'pending'");
            }
            $all = $all && $sat;
        }
        return $all;
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
     * Make $stepOrder the current stage: for every step (parallel branch) of that stage that applies at the request's risk score,
     * resolve its approvers, write one pending row each (carrying the branch's step_id), notify them. When a branch resolves to nobody
     * (e.g. the requester has no manager on file) it falls back to the administrators, so a request can never be stuck with nobody
     * able to approve it. An approver who has set an out-of-office delegate is replaced by that delegate.
     */
    public function activateStep(int $requestId, int $stepOrder): void
    {
        $req = $this->db->query("SELECT * FROM service_catalog_requests WHERE request_id = $requestId")->fetch_assoc();
        if (!$req) {
            return;
        }
        $itemId = intval($req['catalog_item_id']);
        $risk = intval($req['risk_score']);
        $branches = $this->db->query("SELECT * FROM service_catalog_approval_steps WHERE catalog_item_id = $itemId AND step_order = $stepOrder AND risk_min <= $risk AND risk_max >= $risk ORDER BY step_id ASC");
        $steps = [];
        while ($branches && ($b = $branches->fetch_assoc())) {
            $steps[] = $b;
        }
        if (!$steps) {
            return;
        }
        $this->db->query("UPDATE service_catalog_requests SET current_step = $stepOrder WHERE request_id = $requestId");

        $notify = [];
        foreach ($steps as $step) {
            $approvers = $this->resolveApprovers($step, intval($req['client_id']), intval($req['contact_id']), intval($req['requested_by_user_id']));
            $mode = in_array($step['mode'], self::MODES, true) ? $step['mode'] : 'any';
            $stepId = intval($step['step_id']);
            foreach ($approvers as $a) {
                $u = $a['user'];
                $c = $a['contact'];
                $from = $a['from'] ?? null;
                $stmt = $this->db->prepare("INSERT INTO service_catalog_request_approvals (request_id, step_order, step_mode, step_id, approver_user_id, approver_contact_id, delegated_from_user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->bind_param('iisiiii', $requestId, $stepOrder, $mode, $stepId, $u, $c, $from);
                $stmt->execute();
                $stmt->close();
                $notify[($u ? 'u' . $u : 'c' . $c)] = $a;
            }
        }
        $this->notifyApprovers($req, array_values($notify));
    }

    /**
     * Who may decide a step. Agents come back as ['user'=>id,'contact'=>null], portal contacts the other way round. An agent who is
     * out of office with a delegate is replaced by the delegate and the entry carries 'from' (the original approver's user id).
     *
     * @param array<string,mixed> $step
     * @return array<int,array{user:?int,contact:?int,from?:?int}>
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
        if (!$found) {
            // Fallback: administrators (not the requester, unless they are the only ones).
            $admins = $this->activeAgents("r.role_is_admin = 1");
            $notSelf = array_values(array_filter($admins, fn($id) => $id !== $requesterUserId));
            $admins = $notSelf ?: $admins;
            $found = array_map(fn($id) => ['user' => $id, 'contact' => null], $admins);
        }
        return $this->applyDelegation($found, $requesterUserId);
    }

    /**
     * Swap out-of-office agents for their delegates (one hop only, so delegations can never loop). A delegate who is the requester
     * is ignored (nobody approves their own request), and the same person never appears twice.
     *
     * @param array<int,array{user:?int,contact:?int}> $list
     * @return array<int,array{user:?int,contact:?int,from?:?int}>
     */
    private function applyDelegation(array $list, int $requesterUserId): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $a) {
            if ($a['user']) {
                $delegate = $this->activeDelegate(intval($a['user']));
                if ($delegate && $delegate !== $requesterUserId) {
                    $a = ['user' => $delegate, 'contact' => null, 'from' => intval($a['user'])];
                }
            }
            $k = $a['user'] ? 'u' . $a['user'] : 'c' . $a['contact'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $a;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Out of office: approver delegation
    // ------------------------------------------------------------------------------------------------------------

    /** The agent who covers for $userId right now (a delegation whose window contains the present, delegate active), or null. */
    public function activeDelegate(int $userId): ?int
    {
        $row = $this->db->query("SELECT d.delegate_user_id FROM service_catalog_delegations d
            JOIN users u ON u.user_id = d.delegate_user_id AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL
            WHERE d.user_id = $userId AND d.delegate_user_id <> $userId AND NOW() BETWEEN d.starts_at AND d.ends_at
            ORDER BY d.delegation_id DESC LIMIT 1")->fetch_row();
        return $row ? intval($row[0]) : null;
    }

    /**
     * Create an out-of-office delegation. Returns ['ok'=>bool,'error'=>?string,'id'=>int]. The delegate must be another active agent,
     * the window must end after it starts and last at most a year.
     */
    public function addDelegation(int $userId, int $delegateUserId, string $startsAt, string $endsAt, string $note = ''): array
    {
        $s = \DateTime::createFromFormat('Y-m-d H:i:s', $startsAt) ?: \DateTime::createFromFormat('Y-m-d', $startsAt);
        $e = \DateTime::createFromFormat('Y-m-d H:i:s', $endsAt) ?: \DateTime::createFromFormat('Y-m-d', $endsAt);
        if (!$s || !$e) {
            return ['ok' => false, 'error' => 'Enter a valid start and end date.', 'id' => 0];
        }
        if (strlen($startsAt) === 10) { $s->setTime(0, 0, 0); }
        if (strlen($endsAt) === 10) { $e->setTime(23, 59, 59); }
        if ($e <= $s) {
            return ['ok' => false, 'error' => 'The end must be after the start.', 'id' => 0];
        }
        if ($e->getTimestamp() - $s->getTimestamp() > 366 * 86400) {
            return ['ok' => false, 'error' => 'A delegation can last at most one year.', 'id' => 0];
        }
        if ($delegateUserId === $userId || !$this->activeAgents("u.user_id = " . $delegateUserId)) {
            return ['ok' => false, 'error' => 'Pick another active agent as the delegate.', 'id' => 0];
        }
        $note = mb_substr(trim($note), 0, 200);
        $sv = $s->format('Y-m-d H:i:s');
        $ev = $e->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare("INSERT INTO service_catalog_delegations (user_id, delegate_user_id, starts_at, ends_at, note) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iisss', $userId, $delegateUserId, $sv, $ev, $note);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /** Remove one of the user's own delegations. */
    public function deleteDelegation(int $delegationId, int $userId): bool
    {
        $this->db->query("DELETE FROM service_catalog_delegations WHERE delegation_id = $delegationId AND user_id = $userId");
        return $this->db->affected_rows > 0;
    }

    /** @return array<int,array<string,mixed>> the user's delegations that have not ended yet, soonest first */
    public function listDelegations(int $userId): array
    {
        $rows = [];
        $res = $this->db->query("SELECT d.*, u.user_name AS delegate_name FROM service_catalog_delegations d LEFT JOIN users u ON u.user_id = d.delegate_user_id WHERE d.user_id = $userId AND d.ends_at >= NOW() ORDER BY d.starts_at ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Reminders, escalation and timeouts (cron/catalog_approvals.php; switched on by settings.config_catalog_approval_cron)
    // ------------------------------------------------------------------------------------------------------------

    /** Is the reminder / escalation / timeout worker switched on? Off by default; the column may not exist before DB 2.6.212. */
    public static function timeoutsEnabled(\mysqli $db): bool
    {
        try {
            $res = $db->query("SELECT config_catalog_approval_cron FROM settings WHERE company_id = 1");
            $row = $res ? $res->fetch_row() : null;
        } catch (\Throwable $e) {
            return false; // the column does not exist yet (database not updated)
        }
        return $row && intval($row[0]) === 1;
    }

    /**
     * One pass of the worker. For every request waiting on approval whose item has the matching setting (hours, 0 = off):
     *   1. reroute  - pending rows whose approver has since gone out of office move to the delegate;
     *   2. expire   - the stage has waited expire_after_hours: the request is rejected ("no decision in time") and the ticket closed;
     *   3. escalate - the stage has waited escalate_after_hours: the active administrators are added as escalation approvers (once);
     *   4. remind   - every approver who has not decided hears from us again each reminder_after_hours (at most MAX_REMINDERS times).
     * Safe to run repeatedly: every step is claimed in the database first, so overlapping runs cannot double-send.
     *
     * @return array{rerouted:int,expired:int,escalated:int,reminded:int}
     */
    public function processTimeouts(): array
    {
        $out = ['rerouted' => 0, 'expired' => 0, 'escalated' => 0, 'reminded' => 0];
        $out['rerouted'] = $this->rerouteDelegated();

        $res = $this->db->query("SELECT r.*, i.reminder_after_hours, i.escalate_after_hours, i.expire_after_hours,
                TIMESTAMPDIFF(MINUTE, (SELECT MIN(a.created_at) FROM service_catalog_request_approvals a WHERE a.request_id = r.request_id AND a.step_order = r.current_step AND a.is_escalation = 0), NOW()) AS stage_minutes
            FROM service_catalog_requests r JOIN service_catalog_items i ON i.catalog_item_id = r.catalog_item_id
            WHERE r.status = 'pending_approval' AND (i.reminder_after_hours > 0 OR i.escalate_after_hours > 0 OR i.expire_after_hours > 0)");
        $reqs = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $reqs[] = $r;
        }
        foreach ($reqs as $r) {
            $age = $r['stage_minutes'] === null ? 0 : intval($r['stage_minutes']);
            $rid = intval($r['request_id']);
            $step = intval($r['current_step']);
            if (intval($r['expire_after_hours']) > 0 && $age >= intval($r['expire_after_hours']) * 60) {
                if ($this->expireRequest($r, intval($r['expire_after_hours']))) {
                    $out['expired']++;
                    continue;
                }
            }
            if (intval($r['escalate_after_hours']) > 0 && $age >= intval($r['escalate_after_hours']) * 60) {
                $out['escalated'] += $this->escalateStage($r, intval($r['escalate_after_hours']));
            }
            if (intval($r['reminder_after_hours']) > 0) {
                $out['reminded'] += $this->remindStage($r, intval($r['reminder_after_hours']));
            }
        }
        return $out;
    }

    /** Move pending rows from agents who are now out of office to their delegates. @return int rows moved */
    public function rerouteDelegated(): int
    {
        $moved = 0;
        $res = $this->db->query("SELECT a.*, r.ticket_id, r.client_id, r.requested_by_user_id, r.request_id AS rid FROM service_catalog_request_approvals a
            JOIN service_catalog_requests r ON r.request_id = a.request_id AND r.status = 'pending_approval' AND r.current_step = a.step_order
            WHERE a.status = 'pending' AND a.approver_user_id IS NOT NULL AND a.is_escalation = 0");
        $rows = [];
        while ($res && ($a = $res->fetch_assoc())) {
            $rows[] = $a;
        }
        foreach ($rows as $a) {
            $orig = intval($a['approver_user_id']);
            $delegate = $this->activeDelegate($orig);
            if (!$delegate || $delegate === intval($a['requested_by_user_id'])) {
                continue;
            }
            $aid = intval($a['approval_id']);
            $stmt = $this->db->prepare("UPDATE service_catalog_request_approvals SET status = 'skipped', decided_at = NOW(), comment = 'Out of office: handed to a delegate' WHERE approval_id = ? AND status = 'pending'");
            $stmt->bind_param('i', $aid);
            $stmt->execute();
            $claimed = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$claimed) {
                continue;
            }
            $dupe = (int) $this->db->query("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id = " . intval($a['rid']) . " AND step_order = " . intval($a['step_order']) . " AND COALESCE(step_id,0) = " . intval($a['step_id']) . " AND approver_user_id = $delegate AND status = 'pending'")->fetch_row()[0];
            if ($dupe === 0) {
                $stmt = $this->db->prepare("INSERT INTO service_catalog_request_approvals (request_id, step_order, step_mode, step_id, approver_user_id, delegated_from_user_id, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $rid = intval($a['rid']); $so = intval($a['step_order']); $sid = $a['step_id'] === null ? null : intval($a['step_id']);
                $stmt->bind_param('iisiii', $rid, $so, $a['step_mode'], $sid, $delegate, $orig);
                $stmt->execute();
                $stmt->close();
                $this->notifyApprovers(['request_id' => $rid, 'ticket_id' => $a['ticket_id'], 'client_id' => $a['client_id']], [['user' => $delegate, 'contact' => null]], 'Approval needed (covering for a colleague)');
            }
            $moved++;
        }
        return $moved;
    }

    /** Reject a request nobody decided in time. @param array<string,mixed> $req  @return bool true when this call did it */
    private function expireRequest(array $req, int $hours): bool
    {
        $reason = "No decision within $hours hour" . ($hours === 1 ? '' : 's') . ' (approval timed out).';
        $rid = intval($req['request_id']);
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("UPDATE service_catalog_requests SET status = 'rejected', rejection_reason = ?, decided_at = NOW() WHERE request_id = ? AND status = 'pending_approval'");
            $stmt->bind_param('si', $reason, $rid);
            $stmt->execute();
            $did = $stmt->affected_rows === 1;
            $stmt->close();
            if (!$did) {
                $this->db->rollback();
                return false;
            }
            $this->db->query("UPDATE service_catalog_request_approvals SET status = 'skipped', decided_at = NOW(), comment = 'Timed out' WHERE request_id = $rid AND status = 'pending'");
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return false;
        }
        $this->finalize($req, 'rejected', $reason, null);
        return true;
    }

    /** Add the active administrators as escalation approvers for the current stage, once. @return int 1 when escalated */
    private function escalateStage(array $req, int $hours): int
    {
        $rid = intval($req['request_id']);
        $step = intval($req['current_step']);
        $already = (int) $this->db->query("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id = $rid AND step_order = $step AND is_escalation = 1")->fetch_row()[0];
        if ($already > 0) {
            return 0;
        }
        $req2 = $this->db->query("SELECT status, current_step FROM service_catalog_requests WHERE request_id = $rid")->fetch_assoc();
        if (!$req2 || $req2['status'] !== 'pending_approval' || intval($req2['current_step']) !== $step) {
            return 0;
        }
        $requester = intval($req['requested_by_user_id']);
        $admins = $this->activeAgents("r.role_is_admin = 1");
        $notSelf = array_values(array_filter($admins, fn($id) => $id !== $requester));
        $admins = $notSelf ?: $admins;
        $list = [];
        foreach ($admins as $id) {
            $d = $this->activeDelegate($id);
            $uid = ($d && $d !== $requester) ? $d : $id;
            $list[$uid] = ['user' => $uid, 'contact' => null, 'from' => $uid === $id ? null : $id];
        }
        if (!$list) {
            return 0;
        }
        foreach ($list as $a) {
            $u = $a['user']; $from = $a['from'];
            $stmt = $this->db->prepare("INSERT INTO service_catalog_request_approvals (request_id, step_order, step_mode, approver_user_id, delegated_from_user_id, status, is_escalation, escalated_at) VALUES (?, ?, 'any', ?, ?, 'pending', 1, NOW())");
            $stmt->bind_param('iiii', $rid, $step, $u, $from);
            $stmt->execute();
            $stmt->close();
        }
        $this->systemNote(intval($req['ticket_id']), "Approval escalated to the administrators after waiting $hours hour" . ($hours === 1 ? '' : 's') . '. Any of them can decide it.', 0);
        $this->notifyApprovers($req, array_values($list), 'Escalated approval needed');
        return 1;
    }

    /** Remind approvers of the current stage who have not decided. @return int reminders sent */
    private function remindStage(array $req, int $hours): int
    {
        $rid = intval($req['request_id']);
        $step = intval($req['current_step']);
        $mins = $hours * 60;
        $res = $this->db->query("SELECT approval_id, approver_user_id, approver_contact_id FROM service_catalog_request_approvals
            WHERE request_id = $rid AND step_order = $step AND status = 'pending' AND reminder_count < " . self::MAX_REMINDERS . "
              AND TIMESTAMPDIFF(MINUTE, COALESCE(last_reminded_at, created_at), NOW()) >= $mins");
        $due = [];
        while ($res && ($a = $res->fetch_assoc())) {
            $due[] = $a;
        }
        $sent = 0;
        foreach ($due as $a) {
            $aid = intval($a['approval_id']);
            // Claim first (the WHERE repeats the due test), so two overlapping runs cannot both remind.
            $this->db->query("UPDATE service_catalog_request_approvals SET last_reminded_at = NOW(), reminder_count = reminder_count + 1 WHERE approval_id = $aid AND status = 'pending' AND reminder_count < " . self::MAX_REMINDERS . " AND TIMESTAMPDIFF(MINUTE, COALESCE(last_reminded_at, created_at), NOW()) >= $mins");
            if ($this->db->affected_rows !== 1) {
                continue;
            }
            $who = $a['approver_user_id'] ? ['user' => intval($a['approver_user_id']), 'contact' => null] : ['user' => null, 'contact' => intval($a['approver_contact_id'])];
            $this->notifyApprovers($req, [$who], 'Reminder: approval still needed');
            $sent++;
        }
        return $sent;
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
    private function notifyApprovers(array $req, array $approvers, string $headline = 'Approval needed'): void
    {
        global $config_base_url;
        $ticketId = intval($req['ticket_id']);
        $label = $this->ticketLabel($ticketId);
        $esc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $base = !empty($config_base_url) ? 'https://' . $config_base_url : '';
        foreach ($approvers as $a) {
            if ($a['user']) {
                if (function_exists('notifyUser')) {
                    notifyUser($a['user'], 'Ticket', "$headline: $label", '/agent/service_catalog_approvals.php?request_id=' . intval($req['request_id']), intval($req['client_id']), $ticketId);
                }
                $u = $this->db->query("SELECT user_name, user_email FROM users WHERE user_id = " . $a['user'])->fetch_assoc();
                if ($u) {
                    $this->queueMail((string) $u['user_email'], (string) $u['user_name'], "$headline: $label", "A service request is waiting for your decision.<br><br><b>$esc</b><br><br><a href=\"$base/agent/service_catalog_approvals.php\">Review the request</a>");
                }
            } elseif ($a['contact']) {
                $c = $this->db->query("SELECT contact_name, contact_email FROM contacts WHERE contact_id = " . $a['contact'])->fetch_assoc();
                if ($c) {
                    $this->queueMail((string) $c['contact_email'], (string) $c['contact_name'], "$headline: $label", "A service request is waiting for your decision.<br><br><b>$esc</b><br><br><a href=\"$base/client/my_approvals.php\">Review the request</a>");
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
                if (($v['type'] ?? '') === 'table' && $val !== '') {
                    $out .= '<dt class="col-sm-4">' . $h($v['label'] ?? '') . '</dt><dd class="col-sm-8">' . self::tableValueHtml($val) . '</dd>';
                    continue;
                }
                $out .= '<dt class="col-sm-4">' . $h($v['label'] ?? '') . '</dt><dd class="col-sm-8">' . ($val === '' ? '<span class="text-secondary">-</span>' : nl2br($h($val))) . '</dd>';
            }
            $out .= '</dl>';
        }
        if (in_array($req['status'], ['pending_approval', 'approved', 'rejected'], true)) {
            $bd = json_decode((string) ($req['risk_breakdown'] ?? ''), true);
            $out .= '<p class="small text-secondary mt-2 mb-0">Risk score <strong>' . intval($req['risk_score']) . '</strong>';
            if (is_array($bd) && !empty($bd['matched'])) {
                $out .= ' (base ' . intval($bd['base'] ?? 0);
                foreach ($bd['matched'] as $m) {
                    $out .= ', ' . $h($m['field_key'] ?? '') . ' ' . ((intval($m['points'] ?? 0) >= 0) ? '+' : '') . intval($m['points'] ?? 0);
                }
                $out .= ')';
            }
            $out .= '</p>';
        }

        $trail = $this->db->query("SELECT a.*, u.user_name, c.contact_name FROM service_catalog_request_approvals a LEFT JOIN users u ON u.user_id = a.approver_user_id LEFT JOIN contacts c ON c.contact_id = a.approver_contact_id WHERE a.request_id = " . intval($req['request_id']) . " ORDER BY a.step_order ASC, a.approval_id ASC");
        if ($trail && $trail->num_rows > 0) {
            $out .= '<hr><h6>Approvals</h6><ul class="list-unstyled mb-0">';
            while ($a = $trail->fetch_assoc()) {
                $name = $a['user_name'] ?: ($a['contact_name'] ?: 'Approver');
                $cls = ['approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', 'skipped' => 'secondary'][$a['status']] ?? 'secondary';
                $note = '';
                if (!empty($a['is_escalation'])) { $note = ' <span class="badge text-bg-info">Escalated</span>'; }
                if (!empty($a['delegated_from_user_id'])) { $note .= ' <small class="text-secondary">(covering for a colleague)</small>'; }
                $out .= '<li>Step ' . intval($a['step_order']) . ': ' . $h($name) . $note . ' <span class="badge text-bg-' . $cls . '">' . $h(ucfirst($a['status'])) . '</span>';
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

    /** A stored repeating-table answer as plain text, one row per line ("Model: Dell, Qty: 2"); for the mobile API and chat. */
    public static function tableValueText(string $json): string
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !is_array($d['cols'] ?? null) || !is_array($d['rows'] ?? null)) {
            return $json;
        }
        $lines = [];
        foreach ($d['rows'] as $row) {
            $parts = [];
            foreach ((array) $row as $i => $cell) {
                if ((string) $cell !== '') {
                    $parts[] = ($d['cols'][$i] ?? 'Column ' . ($i + 1)) . ': ' . $cell;
                }
            }
            $lines[] = implode(', ', $parts);
        }
        return implode("\n", $lines);
    }

    /** A stored repeating-table answer ({"cols":[...],"rows":[[...]]}) as an escaped HTML table. */
    public static function tableValueHtml(string $json): string
    {
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $d = json_decode($json, true);
        if (!is_array($d) || !is_array($d['cols'] ?? null) || !is_array($d['rows'] ?? null)) {
            return $h($json);
        }
        $out = '<table class="table table-sm table-bordered mb-0"><thead><tr>';
        foreach ($d['cols'] as $c) {
            $out .= '<th>' . $h($c) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($d['rows'] as $row) {
            $out .= '<tr>';
            foreach ((array) $row as $cell) {
                $out .= '<td>' . $h($cell) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>';
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

    // ------------------------------------------------------------------------------------------------------------
    // Categories: the visual catalog (tiles grouped by category)
    // ------------------------------------------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> catalog categories, in display order */
    public function getCategories(bool $activeOnly = true): array
    {
        $rows = [];
        $res = $this->db->query("SELECT * FROM service_catalog_categories" . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY sort_order ASC, name ASC, category_id ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    /** Create ($id = 0) or update a category; returns its id. The icon is a Font Awesome class name (letters, digits, dashes). */
    public function saveCategory(int $id, string $name, string $description, string $icon, int $sortOrder, bool $active): int
    {
        $name = mb_substr(trim($name), 0, 100);
        $description = mb_substr(trim($description), 0, 500);
        $icon = substr(preg_replace('/[^a-z0-9 -]/', '', strtolower(trim($icon))), 0, 100);
        $desc = $description !== '' ? $description : null;
        $ic = $icon !== '' ? $icon : null;
        $act = $active ? 1 : 0;
        if ($id > 0) {
            $stmt = $this->db->prepare("UPDATE service_catalog_categories SET name = ?, description = ?, icon = ?, sort_order = ?, is_active = ? WHERE category_id = ?");
            $stmt->bind_param('sssiii', $name, $desc, $ic, $sortOrder, $act, $id);
            $stmt->execute();
            $stmt->close();
            return $id;
        }
        $stmt = $this->db->prepare("INSERT INTO service_catalog_categories (name, description, icon, sort_order, is_active) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssii', $name, $desc, $ic, $sortOrder, $act);
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();
        return $newId;
    }

    /** Delete a category; its items stay and fall back to the uncategorised group. */
    public function deleteCategory(int $id): void
    {
        $this->db->query("UPDATE service_catalog_items SET catalog_category_id = NULL WHERE catalog_category_id = $id");
        $this->db->query("DELETE FROM service_catalog_categories WHERE category_id = $id");
    }

    /**
     * Group item rows into category tiles for the catalog pages: one entry per ACTIVE category that has at least one item, in
     * category order, then "Other" (id 0) for items with no (or an inactive/missing) category. Items keep the order they came in.
     *
     * @param array<int,array<string,mixed>> $items rows of service_catalog_items
     * @return array<int,array{id:int,name:string,description:string,icon:string,items:array<int,array<string,mixed>>}>
     */
    public function groupByCategory(array $items): array
    {
        $cats = [];
        foreach ($this->getCategories(true) as $c) {
            $cats[intval($c['category_id'])] = ['id' => intval($c['category_id']), 'name' => (string) $c['name'], 'description' => (string) ($c['description'] ?? ''), 'icon' => (string) ($c['icon'] ?: 'fa-folder'), 'items' => []];
        }
        $other = ['id' => 0, 'name' => 'Other', 'description' => '', 'icon' => 'fa-ellipsis-h', 'items' => []];
        foreach ($items as $it) {
            $cid = intval($it['catalog_category_id'] ?? 0);
            if ($cid > 0 && isset($cats[$cid])) {
                $cats[$cid]['items'][] = $it;
            } else {
                $other['items'][] = $it;
            }
        }
        $out = array_values(array_filter($cats, fn($c) => $c['items']));
        if ($other['items']) {
            // With no categories at all, the "Other" label would only be noise: call it by what it is.
            if (!$out) {
                $other['name'] = 'All requests';
            }
            $out[] = $other;
        }
        return $out;
    }

    /** Active catalog items, in display order. @return array<int,array<string,mixed>> */
    public function activeItems(): array
    {
        $rows = [];
        $res = $this->db->query("SELECT * FROM service_catalog_items WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $rows[] = $r;
        }
        return $rows;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Programmatic requests (mobile API, and chat: Slack / Teams, GitHub issue #36)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * A catalog item as a plain, serialisable description: name, category, approval flag and the questions (key, label, type,
     * choices or table columns, required, show_if). The mobile API returns exactly this and chat clients (Slack / Teams) can use it to
     * ask the questions in a form.
     *
     * @param array<string,mixed> $row a service_catalog_items row
     * @return array<string,mixed>
     */
    public function describeItem(array $row): array
    {
        $fields = [];
        foreach ($this->getFields(intval($row['catalog_item_id'])) as $f) {
            $type = (string) $f['field_type'];
            $fields[] = [
                'key'         => (string) $f['field_key'],
                'label'       => (string) $f['label'],
                'type'        => $type,
                'options'     => $type === 'select' ? array_values(array_filter(array_map('trim', explode("\n", (string) ($f['options'] ?? ''))), 'strlen')) : [],
                // Table questions: [{"label":"Heading","type":"text|number|date"}]. Rows are posted as lists in column order, or objects keyed by heading.
                'columns'     => $type === 'table' ? self::tableColumns($f['options'] ?? '') : [],
                'required'    => !empty($f['is_required']),
                'placeholder' => (string) ($f['placeholder'] ?? ''),
                // One rule {"field","op","value"} or {"match":"all|any","rules":[...]}, or null (always visible); the rule the web form uses.
                'show_if'     => self::parseShowIf($f['show_if'] ?? null),
            ];
        }
        return [
            'id'                => intval($row['catalog_item_id']),
            'name'              => (string) $row['name'],
            'description'       => (string) ($row['description'] ?? ''),
            'icon'              => (string) (($row['icon'] ?? '') ?: 'fa-ticket-alt'),
            'category_id'       => intval($row['catalog_category_id'] ?? 0) ?: null,
            'requires_approval' => !empty($row['requires_approval']),
            'risk_score'        => intval($row['risk_score'] ?? 0),
            'fields'            => $fields,
        ];
    }

    /**
     * Raise a ticket from a catalog item with the given answers: validate, create the ticket, record the request (form values, computed
     * risk, approval hold) and run the usual ticket-created side effects. This is the one entry point behind POST /api/v1/service_catalog.php
     * and the one a chat integration should call.
     *
     * THE CALLER decides who may do this: it must already have authenticated the person and checked access to $clientId (the API
     * uses api_mobile_client_ok(); a chat bridge has to map the chat user to an agent or contact first). Needs the application
     * functions loaded (functions.php), which is always true inside the app.
     *
     * @param array<string,mixed> $answers  field key => scalar (table questions: list of rows)
     * @return array{ok:bool,code:int,error:?string,errors:string[],ticket_id:int,status:string,request_id:int}  status is 'created' or 'pending_approval'
     */
    public function createRequest(int $itemId, array $answers, int $clientId, int $contactId, int $userId, string $source = 'API', string $detailsNote = ''): array
    {
        $fail = fn(int $code, string $msg, array $errs = []) => ['ok' => false, 'code' => $code, 'error' => $msg, 'errors' => $errs ?: [$msg], 'ticket_id' => 0, 'status' => '', 'request_id' => 0];
        $stmt = $this->db->prepare("SELECT * FROM service_catalog_items WHERE catalog_item_id = ? AND is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) {
            return $fail(404, 'Catalog item not found');
        }
        if ($clientId > 0) {
            $c = $this->db->query("SELECT client_id FROM clients WHERE client_id = $clientId AND client_archived_at IS NULL LIMIT 1")->fetch_assoc();
            if (!$c) {
                return $fail(422, 'client_id does not exist');
            }
        }
        // Scalars arrive as the web form's strings (a JSON true/false/number becomes '1'/'0'/'12'). Arrays are left for validateInput().
        $post = [];
        foreach ($answers as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? '1' : '0';
            } elseif (is_int($v) || is_float($v)) {
                $v = (string) $v;
            }
            $post[(string) $k] = $v;
        }
        $check = self::validateInput($this->getFields($itemId), $post);
        if ($check['errors']) {
            return $fail(422, implode(' ', $check['errors']), $check['errors']);
        }
        foreach (['resolveTicketCategory', 'resolveTicketAssignee', 'resolveTicketCreationStatus', 'randomString'] as $fn) {
            if (!function_exists($fn)) {
                return $fail(500, 'The ticket helpers are not loaded.');
            }
        }

        $subject = trim((string) ($item['ticket_subject_template'] ?? '')) !== '' ? trim((string) $item['ticket_subject_template']) : (string) $item['name'];
        $note = $detailsNote !== '' ? $detailsNote : "Requested via $source: " . $item['name'];
        $details = '<p>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>';
        $priority = ucfirst(strtolower((string) ($item['default_priority'] ?? '')));
        if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'], true)) {
            $priority = 'Low';
        }
        $category = intval($item['ticket_category_id'] ?? 0);
        if ($category) {
            $cat = $this->db->query("SELECT category_id FROM categories WHERE category_id = $category AND category_type = 'Ticket' AND category_archived_at IS NULL LIMIT 1")->fetch_assoc();
            if (!$cat) {
                $category = 0;
            }
        }
        $category = \resolveTicketCategory($category);
        $assigned = \resolveTicketAssignee(0);
        $status = \resolveTicketCreationStatus($assigned);

        $this->db->query("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
        $nextNum = (int) $this->db->insert_id;
        global $config_ticket_prefix;
        $prefix = (string) ($config_ticket_prefix ?? '');
        $urlKey = \randomString(32);
        $stmt = $this->db->prepare("INSERT INTO tickets (ticket_prefix, ticket_subject, ticket_details, ticket_client_id, ticket_contact_id, ticket_priority, ticket_status, ticket_assigned_to, ticket_created_by, ticket_source, ticket_number, ticket_category, ticket_url_key, ticket_catalog_item_id, ticket_created_at, ticket_updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('sssiisiiisiisi', $prefix, $subject, $details, $clientId, $contactId, $priority, $status, $assigned, $userId, $source, $nextNum, $category, $urlKey, $itemId);
        $ok = $stmt->execute();
        $ticketId = (int) $this->db->insert_id;
        $stmt->close();
        if (!$ok || !$ticketId) {
            return $fail(500, 'Could not create the ticket');
        }

        $statusOut = 'created';
        $requestId = 0;
        if ($this->hasRequestFlow($item)) {
            $sub = $this->submit($item, $ticketId, $clientId, $contactId, $userId, $check['values']);
            $requestId = $sub['request_id'];
            if ($sub['status'] === 'pending_approval') {
                $statusOut = 'pending_approval';
            }
        }
        return ['ok' => true, 'code' => 201, 'error' => null, 'errors' => [], 'ticket_id' => $ticketId, 'status' => $statusOut, 'request_id' => $requestId, 'subject' => $subject, 'ticket_number' => $nextNum, 'assigned_to' => $assigned, 'category_id' => $category];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Request statistics for requests created between $from and $to (Y-m-d, inclusive), per catalog item and in total:
     * volume, outcome counts, rejection rate (rejected / decided) and approval turnaround (hours from submission to the final decision,
     * average and longest, over requests that went through approval and were decided).
     * $scopeSql is a trusted SQL fragment limiting rows to the viewer's departments (e.g. ReportScope::clause('r.client_id')).
     *
     * @return array{totals:array<string,mixed>,items:array<int,array<string,mixed>>,by_category:array<int,array<string,mixed>>,trend:array<int,array<string,mixed>>}
     */
    public function requestStats(string $from, string $to, string $scopeSql = ''): array
    {
        // Only real Y-m-d dates reach the query; anything else falls back to "from the beginning" / "until today".
        $valid = fn(string $d) => (($dt = \DateTime::createFromFormat('!Y-m-d', $d)) && $dt->format('Y-m-d') === $d);
        $f = $valid($from) ? $from : '1970-01-01';
        $t = $valid($to) ? $to : date('Y-m-d');
        $where = "r.created_at >= '$f 00:00:00' AND r.created_at <= '$t 23:59:59' $scopeSql";
        $agg = "COUNT(*) AS requests,
                SUM(r.status = 'pending_approval') AS pending,
                SUM(r.status = 'approved') AS approved,
                SUM(r.status = 'rejected') AS rejected,
                SUM(r.status = 'not_required') AS no_approval,
                SUM(r.status = 'approved' AND NOT EXISTS (SELECT 1 FROM service_catalog_request_approvals a WHERE a.request_id = r.request_id)) AS auto_approved,
                AVG(CASE WHEN r.status IN ('approved','rejected') AND r.decided_at IS NOT NULL AND EXISTS (SELECT 1 FROM service_catalog_request_approvals a2 WHERE a2.request_id = r.request_id) THEN TIMESTAMPDIFF(MINUTE, r.created_at, r.decided_at) END) / 60 AS avg_hours,
                MAX(CASE WHEN r.status IN ('approved','rejected') AND r.decided_at IS NOT NULL AND EXISTS (SELECT 1 FROM service_catalog_request_approvals a3 WHERE a3.request_id = r.request_id) THEN TIMESTAMPDIFF(MINUTE, r.created_at, r.decided_at) END) / 60 AS max_hours";
        $norm = function (array $r): array {
            foreach (['requests', 'pending', 'approved', 'rejected', 'no_approval', 'auto_approved'] as $k) {
                $r[$k] = intval($r[$k] ?? 0);
            }
            $decided = $r['approved'] + $r['rejected'];
            $r['rejection_rate'] = $decided > 0 ? round($r['rejected'] / $decided * 100, 1) : null;
            $r['avg_hours'] = $r['avg_hours'] === null ? null : round((float) $r['avg_hours'], 1);
            $r['max_hours'] = $r['max_hours'] === null ? null : round((float) $r['max_hours'], 1);
            return $r;
        };
        $totals = $norm(($this->db->query("SELECT $agg FROM service_catalog_requests r WHERE $where")->fetch_assoc()) ?: []);

        $items = [];
        $res = $this->db->query("SELECT i.catalog_item_id, i.name, i.catalog_category_id, COALESCE(c.name, 'Other') AS category_name, $agg
            FROM service_catalog_requests r JOIN service_catalog_items i ON i.catalog_item_id = r.catalog_item_id
            LEFT JOIN service_catalog_categories c ON c.category_id = i.catalog_category_id
            WHERE $where GROUP BY i.catalog_item_id, i.name, i.catalog_category_id, c.name ORDER BY requests DESC, i.name ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $items[] = $norm($r);
        }

        $cats = [];
        $res = $this->db->query("SELECT COALESCE(c.name, 'Other') AS category_name, $agg
            FROM service_catalog_requests r JOIN service_catalog_items i ON i.catalog_item_id = r.catalog_item_id
            LEFT JOIN service_catalog_categories c ON c.category_id = i.catalog_category_id
            WHERE $where GROUP BY COALESCE(c.name, 'Other') ORDER BY requests DESC");
        while ($res && ($r = $res->fetch_assoc())) {
            $cats[] = $norm($r);
        }

        $trend = [];
        $res = $this->db->query("SELECT DATE(r.created_at) AS day, COUNT(*) AS requests FROM service_catalog_requests r WHERE $where GROUP BY DATE(r.created_at) ORDER BY day ASC");
        while ($res && ($r = $res->fetch_assoc())) {
            $trend[] = ['day' => $r['day'], 'requests' => intval($r['requests'])];
        }
        return ['totals' => $totals, 'items' => $items, 'by_category' => $cats, 'trend' => $trend];
    }
}
