<?php

/*
 * Shared form pieces for the API Keys add / edit pop-ups (and nothing else): the department chooser and the access chooser.
 * Field names are the ones admin/post/api_keys.php reads (dept_mode, departments[], scopes[]).
 */

use ITFlow\Integration\ApiScopes;
use ITFlow\Integration\IntegrationAuth;

/** Plain-language names for the non-general-API scopes (the scope string stays in the tooltip). */
function ak_other_scopes(): array
{
    return [
        'lms:read' => 'Training: read the course catalog and assignments',
        'lms:assign' => 'Training: create (or reuse) assignments',
        'lms:completions:read' => 'Training: read completions',
        'lms:evidence:read' => 'Training: download completion evidence files',
        'directory:read' => 'Directory: read employees and departments',
        'quality:events' => 'Quality events (reserved, not served yet)',
        'sso' => 'Single sign-on (OpenID Connect); addresses are set under Single sign-on provider',
    ];
}

/** Non-archived departments, id => name. */
function ak_departments(mysqli $mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
    while ($res && ($d = mysqli_fetch_assoc($res))) {
        $out[(int) $d['client_id']] = (string) $d['client_name'];
    }
    return $out;
}

/**
 * Department chooser. $all = true/false for a saved key, null for a new one (nothing pre-selected: an explicit choice is required).
 * $granted = department ids the key holds. Checkboxes are disabled while "All departments" is chosen (the handler refuses both together).
 */
function ak_dept_fields(mysqli $mysqli, ?bool $all, array $granted, string $p): string
{
    $departments = ak_departments($mysqli);
    $h = '<fieldset class="mb-3 ak-dept-fieldset"><legend class="form-label fs-5 mb-1">Departments <span class="text-danger">*</span></legend>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="dept_mode" value="all" id="' . $p . 'All"' . ($all === true ? ' checked' : '') . ' required>'
        . '<label class="form-check-label" for="' . $p . 'All">All departments (including people with no department)</label></div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="dept_mode" value="selected" id="' . $p . 'Sel"' . ($all === false ? ' checked' : '') . ' required>'
        . '<label class="form-check-label" for="' . $p . 'Sel">Only these departments</label></div>'
        . '<div class="ak-dept-list border rounded mt-2 p-2" role="group" aria-label="Departments">';
    $i = 0;
    foreach ($departments as $id => $name) {
        $h .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="departments[]" value="' . $id . '" id="' . $p . 'D' . (++$i) . '"'
            . (in_array($id, $granted, true) ? ' checked' : '') . ($all !== false ? ' disabled' : '') . '>'
            . '<label class="form-check-label" for="' . $p . 'D' . $i . '">' . nullable_htmlentities($name) . '</label></div>';
    }
    // A department that was granted and has since been archived stays visible so the admin can see it and remove it (saving it again is refused).
    foreach (array_diff($granted, array_keys($departments)) as $id) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT client_name FROM clients WHERE client_id = ' . (int) $id));
        $h .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="departments[]" value="' . (int) $id . '" id="' . $p . 'D' . (++$i) . '" checked' . ($all !== false ? ' disabled' : '') . '>'
            . '<label class="form-check-label text-danger" for="' . $p . 'D' . $i . '">' . nullable_htmlentities($r ? $r['client_name'] . ' (archived: untick to remove)' : 'Department #' . (int) $id . ' (removed: untick to remove)') . '</label></div>';
    }
    if ($departments === [] && $granted === []) {
        $h .= '<span class="text-muted small">There are no departments yet.</span>';
    }
    return $h . '</div><div class="form-text">The key sees only the people, assignments, completions and courses of the departments chosen here. A key with no department sees nothing.</div></fieldset>';
}

/** The access chooser: compact grouped table (one row per area, Read / Write / Delete) plus the other access options. */
function ak_scope_fields(array $selected, string $p): string
{
    $h = '<fieldset class="mb-3 ak-scope-fieldset"><legend class="form-label fs-5 mb-1">Access <span class="text-danger">*</span></legend>'
        . '<div class="form-text mb-2">Tick what the key may do. Nothing is allowed unless ticked, and there is no wildcard. Read = view, Write = create and change, Delete = remove.</div>'
        . '<div class="ak-presets mb-2" role="group" aria-label="Presets">'
        . '<span class="text-muted small me-1">Presets:</span>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-ak-preset="read">Read only</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-ak-preset="readwrite">Read &amp; write</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" data-ak-preset="clear">Clear</button></div>'
        . '<div class="ak-scope-scroll border rounded"><table class="table table-sm table-borderless align-middle mb-0 ak-scope-table">'
        . '<thead><tr><th scope="col">Area</th><th scope="col" class="text-center">Read</th><th scope="col" class="text-center">Write</th><th scope="col" class="text-center">Delete</th></tr></thead><tbody>';
    foreach (ApiScopes::RESOURCES as $res => $label) {
        $h .= '<tr><th scope="row" class="fw-normal">' . nullable_htmlentities($label) . '</th>';
        foreach (ApiScopes::ACTIONS as $act) {
            $sc = ApiScopes::scope($res, $act);
            $id = $p . preg_replace('/[^a-z]/', '_', $sc);
            $h .= '<td class="text-center"><input class="form-check-input" type="checkbox" name="scopes[]" value="' . $sc . '" id="' . $id . '" data-ak-action="' . $act . '"'
                . ' aria-label="' . nullable_htmlentities($label) . ': ' . $act . '" title="' . $sc . '"' . (in_array($sc, $selected, true) ? ' checked' : '') . '></td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div><div class="form-text">Person-bound areas (credentials, profile, notifications, approvals) are never available to an API key.</div>'
        . '<div class="mt-3 fw-bold small text-muted">Other access (training, directory, sign-in)</div>';
    $help = ak_other_scopes();
    foreach (IntegrationAuth::SCOPES as $s) {
        $id = $p . preg_replace('/[^a-z]/', '_', $s);
        $h .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="' . nullable_htmlentities($s) . '" id="' . $id . '"' . (in_array($s, $selected, true) ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '" title="' . nullable_htmlentities($s) . '">' . nullable_htmlentities($help[$s] ?? $s) . '</label></div>';
    }
    return $h . '</fieldset>';
}
