<?php

/*
 * Organizational Chart ("Rootline")
 *
 * Renders the company's manager / direct-report tree straight from
 * contacts.contact_manager_id - data that already exists and is already
 * populated today via the "Manager" dropdown on
 * agent/modals/contact/contact_edit.php (agent/contact_details.php already shows
 * one contact's own "Reports to" line and "Direct Reports" list from the same
 * column). No schema change, no new table - this page only reads.
 *
 * SCOPING matches every other company-wide page in this app (see
 * agent/contacts.php's no-client_id branch): $access_permission_query, built once
 * per session in includes/load_user_session.php, restricts a non-admin
 * technician to the departments (clients) they hold a row for in
 * user_client_permissions. An optional ?client_id=N narrows the whole page to one
 * department and is checked with the exact same enforceClientAccess() every
 * per-department page in this app already uses - never trusted from intval()
 * alone.
 *
 * TREE CONSTRUCTION AND RENDERING never use native PHP function recursion -
 * explicit stacks only, see org_chart_render_subtree_html() below.
 * contact_manager_id has no FK and nothing stops a bad edit from creating a
 * cycle (A manages B manages A, or a contact set as its own manager), so this
 * page has to survive that without an infinite loop or a stack overflow at a
 * real company's headcount, not just in theory.
 *
 * INTERACTIVITY is optional. The employee list is server-rendered and works
 * without JavaScript. On Chart, agent/js/org_chart.js reads the authorized,
 * cycle-safe hierarchy below and passes those nodes to a locally hosted D3
 * chart. Text is escaped again when inserted into D3's HTML card templates.
 * The chart library loads only after the user selects Chart; the list stays
 * available if it cannot load.
 */

require_once "includes/inc_all.php";
require_once __DIR__ . '/includes/org_chart_filter.php';

enforceUserPermission('module_client');

// Optional department filter. 0 = company-wide (default), matching contacts.php's
// own no-client_id company-wide branch. A non-zero value goes through the exact
// same enforceClientAccess() gate every per-department page in this app uses, so
// a technician can't fish another department's chart out just by editing the URL.
$department_filter_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$location_filter_id = isset($_GET['location_id']) ? max(0, intval($_GET['location_id'])) : 0;
$status_filter = isset($_GET['status']) && is_string($_GET['status']) ? trim($_GET['status']) : '';
if ($department_filter_id > 0) {
    enforceClientAccess($department_filter_id);
}

// Department picker options - same shape as contacts.php's own "- All
// Departments -" filter: only departments this user can see, not archived, that
// actually have at least one active contact (an empty department has no chart to
// show).
$sql_departments_filter = mysqli_query($mysqli, "
    SELECT DISTINCT clients.client_id, clients.client_name
    FROM clients
    JOIN contacts ON contacts.contact_client_id = clients.client_id
    WHERE clients.client_archived_at IS NULL
    AND contacts.contact_archived_at IS NULL
    $access_permission_query
    ORDER BY clients.client_name ASC
");

// The scope this render is allowed to pull nodes from - one department (already
// access-checked above) or every department the session can see.
if ($department_filter_id > 0) {
    $scope_where = "AND contacts.contact_client_id = $department_filter_id";
} else {
    $scope_where = "AND clients.client_archived_at IS NULL $access_permission_query";
}

// The contact set this render is allowed to use as tree NODES. Archived contacts
// are excluded as nodes - matching agent/contact_details.php's own
// direct-reports query ("contact_archived_at IS NULL") - but an archived (or
// otherwise out-of-scope) contact_manager_id is still a perfectly good reason a
// contact becomes a ROOT: see org_chart_build_tree_index() below, a manager id
// that is not a key of $contacts_by_id is treated as "missing" no matter why.
//
// contact_photo rides along here purely to decide avatar-vs-initials per node
// (org_chart_node_card_html() below) - same column, same fallback rule, and the
// same "../uploads/clients/{client_id}/{photo}" path every other page in this
// app already serves it from (agent/contacts.php:477-479,
// agent/contact_details.php:232-233).
$sql_contacts = mysqli_query($mysqli, "
    SELECT contacts.contact_id, contacts.contact_name, contacts.contact_title,
           contacts.contact_department, contacts.contact_manager_id,
           contacts.contact_employment_status, contacts.contact_client_id,
           contacts.contact_photo, contacts.contact_location_id,
           locations.location_name, clients.client_name
    FROM contacts
    LEFT JOIN clients ON clients.client_id = contacts.contact_client_id
    LEFT JOIN locations ON locations.location_id = contacts.contact_location_id
        AND locations.location_client_id = contacts.contact_client_id
    WHERE contacts.contact_archived_at IS NULL
    $scope_where
    ORDER BY contacts.contact_name ASC
");

$contacts_by_id = [];
$locations_by_id = [];
$statuses = [];
while ($row = mysqli_fetch_assoc($sql_contacts)) {
    $id = intval($row['contact_id']);
    // initials() is called on the already-escaped name, matching contacts.php's
    // own $contact_initials = initials($contact_name) (contact_name there is set
    // via nullable_htmlentities() first too) - same convention, not a new one.
    $escaped_name = nullable_htmlentities($row['contact_name']);
    $contacts_by_id[$id] = [
        'id'                 => $id,
        'name'               => $escaped_name,
        'initials'           => initials($escaped_name),
        'title'              => nullable_htmlentities($row['contact_title']),
        'group'              => nullable_htmlentities($row['contact_department']),
        'department_name'    => nullable_htmlentities($row['client_name']),
        'department_id'      => intval($row['contact_client_id']),
        'employment_status'  => nullable_htmlentities($row['contact_employment_status'] ?? 'active'),
        'raw_status'         => $row['contact_employment_status'] ?? 'active',
        'location_id'        => intval($row['contact_location_id']),
        'location_name'      => nullable_htmlentities($row['location_name']),
        'manager_id'         => intval($row['contact_manager_id']),
        'photo'              => nullable_htmlentities($row['contact_photo']),
    ];
    if ($row['location_name'] !== null && $row['location_name'] !== '') {
        $locations_by_id[intval($row['contact_location_id'])] = nullable_htmlentities($row['location_name']);
    }
    $statuses[$row['contact_employment_status'] ?? 'active'] = true;
}
asort($locations_by_id, SORT_NATURAL | SORT_FLAG_CASE);
ksort($statuses);
if ($location_filter_id > 0 && !isset($locations_by_id[$location_filter_id])) {
    $location_filter_id = 0;
}
if ($status_filter !== '' && !isset($statuses[$status_filter])) {
    $status_filter = '';
}

[$contacts_by_id, $matching_contacts] = org_chart_filter_contacts($contacts_by_id, $location_filter_id, $status_filter);

/*
 * Splits $contacts_by_id into a manager_id => [child contact_id, ...] map and a
 * flat list of root ids. A root is a contact whose manager_id is 0/NULL, OR
 * whose manager_id does not exist as a key in $contacts_by_id at all - archived,
 * outside the current department filter, or genuinely gone are all the same
 * case here: the manager cannot be shown, so the tree has to start here instead.
 */
function org_chart_build_tree_index(array $contacts_by_id): array
{
    $children_by_manager = [];
    $roots = [];

    foreach ($contacts_by_id as $id => $node) {
        $manager_id = $node['manager_id'];
        if ($manager_id > 0 && isset($contacts_by_id[$manager_id])) {
            $children_by_manager[$manager_id][] = $id;
        } else {
            $roots[] = $id;
        }
    }

    return [$children_by_manager, $roots];
}

// Employment-status badge, only shown when it is not the common case. Colors
// reuse this app's existing text-bg-* badge vocabulary (grepped from
// agent/*.php) rather than inventing a new palette.
function org_chart_status_badge(string $status): string
{
    if ($status === '' || $status === 'active') {
        return '';
    }

    $classes = [
        'pre-hire'             => 'text-bg-info',
        'leave'                => 'text-bg-warning',
        'suspended'             => 'text-bg-warning',
        'transfer_pending'      => 'text-bg-info',
        'termination_pending'   => 'text-bg-danger',
        'terminated'            => 'text-bg-danger',
        'archived'              => 'text-bg-secondary',
    ];
    $class = $classes[$status] ?? 'text-bg-secondary';
    $label = ucwords(str_replace('_', ' ', $status));

    return " <span class='badge $class'>$label</span>";
}

/*
 * The card shown for one contact, reused for both an expandable node's
 * <summary> and a childless node's plain <li> - same avatar-initials-in-a-circle
 * fallback markup agent/contacts.php already uses for its own contact rows, now
 * with a real contact_photo <img> when one exists.
 *
 * $report_count and $ancestor_path are supplied by the caller
 * (org_chart_render_subtree_html()) rather than recomputed here - both are O(1)
 * byproducts of the explicit-stack walk it already does, so no extra pass over
 * the tree is needed to hand this data to the browser.
 */
function org_chart_node_card_html(array $node, array $contacts_by_id, int $report_count, string $ancestor_path, bool $show_department): string
{
    $link = "contact_details.php?client_id={$node['department_id']}&contact_id={$node['id']}";
    $status_badge = org_chart_status_badge($node['employment_status']);

    $subtitle_parts = [];
    if ($node['title'] !== '') {
        $subtitle_parts[] = $node['title'];
    }
    if ($node['group'] !== '') {
        $subtitle_parts[] = $node['group'];
    }
    // A manager may belong to another department. In the company-wide view,
    // the section heading then describes the root, not every person below it.
    if ($show_department && $node['department_name'] !== '') {
        $subtitle_parts[] = $node['department_name'];
    }
    if ($node['location_name'] !== '') {
        $subtitle_parts[] = $node['location_name'];
    }
    $subtitle = implode(' &middot; ', $subtitle_parts);

    // Photo-vs-initials avatar - identical fallback rule to every other page in
    // this app that renders a contact avatar. loading="lazy" decoding="async"
    // are non-optional here: this is the first page in the app that can put a
    // few hundred of these <img> tags on one screen at once.
    if ($node['photo'] !== '') {
        $avatar_inner = "<img class='org-node-avatar-img' loading='lazy' decoding='async' src='../uploads/clients/{$node['department_id']}/{$node['photo']}' alt=''>";
    } else {
        $avatar_inner = "<span class='fa-stack fa-1x flex-shrink-0'><i class='fa fa-circle fa-stack-2x text-secondary'></i><span class='fa fa-stack-1x text-white org-node-initials'>{$node['initials']}</span></span>";
    }
    // Department color ring: stable per department_id (not per render
    // position - a department's color never shifts across reloads just
    // because a different set of departments happens to be in view), drawn
    // from the app's validated 8-color categorical set
    // (agent/css/org_chart.css). Decorative only, never the sole signal - the
    // department name is always also visible as plain text in the subtitle and
    // as the section's card-header label.
    $dept_slot = $node['department_id'] % 8;
    $avatar = "<span class='org-node-avatar org-dept-slot-$dept_slot'>$avatar_inner</span>";

    // '' when manager_id is 0 (a real root) or points outside the current
    // scope (archived / different department under a filter) - same "missing
    // manager" case org_chart_build_tree_index() already treats as a root.
    $manager_present = isset($contacts_by_id[$node['manager_id']]);
    $manager_name = $manager_present ? $contacts_by_id[$node['manager_id']]['name'] : '';

    // Every value below is already HTML-escaped via nullable_htmlentities()
    // (ENT_QUOTES) back where $contacts_by_id was built, so it is as safe to
    // drop into a "..."-quoted attribute as it already is inside the visible
    // markup a few lines down - no second escaping pass, no new function.
    $html = "<span class='org-node' data-contact-id='{$node['id']}' data-name=\"{$node['name']}\" data-title=\"{$node['title']}\" data-group=\"{$node['group']}\" data-dept=\"{$node['department_name']}\" data-status=\"{$node['employment_status']}\" data-manager-name=\"$manager_name\" data-report-count='$report_count' data-ancestor-path=\"$ancestor_path\">";
    $html .= $avatar;
    $html .= "<span class='org-node-text'>";
    $html .= "<a class='org-node-name text-dark fw-bold' href='" . nullable_htmlentities($link) . "' title=\"{$node['name']}\">{$node['name']}</a>{$status_badge}";
    if ($subtitle !== '') {
        $html .= "<div class='text-secondary small' title=\"$subtitle\">$subtitle</div>";
    }
    if ($node['is_context']) {
        $html .= "<span class='badge text-bg-secondary org-context-badge'>Context</span>";
    }
    if ($node['manager_id'] > 0 && !$manager_present) {
        $html .= "<span class='badge text-bg-warning org-context-badge'>Manager unavailable</span>";
    }
    $html .= "</span>";
    if ($report_count > 0) {
        $report_label = $report_count === 1 ? 'visible direct report' : 'visible direct reports';
        $html .= "<span class='org-report-count' title='$report_count $report_label'>$report_count <span class='visually-hidden'>$report_label</span><i class='fa fa-users' aria-hidden='true'></i></span>";
    }
    $html .= "<span class='org-node-actions'>";
    $html .= "<button type='button' class='org-node-action org-node-trace-btn' data-action='trace' aria-label='Trace {$node['name']} to root' title='Trace to root'><i class='fa fa-route' aria-hidden='true'></i></button>";
    $html .= "</span>";
    $html .= "</span>";

    return $html;
}

function org_chart_open_node_html(array $node, int $depth, bool $has_children, array $contacts_by_id, int $report_count, string $ancestor_path, bool $show_department): string
{
    $card = org_chart_node_card_html($node, $contacts_by_id, $report_count, $ancestor_path, $show_department);

    if (!$has_children) {
        // A leaf has no <summary>/chevron. It gets an inert same-width
        // spacer in the chevron's place so its avatar/text column still lines
        // up with an expandable sibling's - a guessed CSS padding value would
        // drift the moment the chevron icon's own box model changes.
        return "<li class='org-leaf'><span class='org-node-row'><span class='org-node-chevron-spacer' aria-hidden='true'></span>$card</span></li>";
    }

    $open_attr = ($depth === 0) ? ' open' : '';
    // The disclosure chevron is a real element inside <summary>, not a
    // ::marker restyle - a bare ::marker cannot be transitioned/rotated in any
    // browser today, and this node needs to rotate it open/closed.
    $chevron = "<i class='fa fa-chevron-right org-node-chevron' aria-hidden='true'></i>";
    return "<li><details$open_attr><summary>$chevron$card</summary><ul class='org-tree'>";
}

/*
 * Renders one node and its whole subtree as nested <li>/<details> markup, using
 * an EXPLICIT STACK instead of PHP function recursion, and two independent
 * guards against bad manager data that was never validated at write time:
 *
 *   - $ancestry (per-branch): if a "child" id is already an ancestor of the node
 *     currently being opened, that edge is a cycle back on itself - it is
 *     skipped rather than followed. This is what actually breaks a cycle.
 *   - $rendered (whole-tree, passed by reference and shared across every call
 *     made against it below - once per department root, then again for
 *     whatever the leftover-cycle sweep finds): once an id has been drawn once
 *     it is never drawn again, so a contact whose manager chain never reaches a
 *     real root - a pure cycle nobody in it points out of - still gets drawn
 *     exactly once (from wherever that leftover sweep starts it) instead of
 *     silently vanishing from the page.
 *
 * $ancestry_path is an ORDERED twin of $ancestry, pushed/extended at the exact
 * same two places $ancestry itself is (root init just below, per-child
 * extension further down) - zero extra traversal over the existing stack walk.
 * It exists purely so each rendered node's data-ancestor-path attribute (read
 * by agent/js/org_chart.js for "Trace to Root" and the sticky breadcrumb) can
 * be built in the same pass instead of a second walk over the tree.
 *
 * A node's own <details> defaults OPEN only at depth 0, so a root and its
 * immediate direct reports are visible on load and everything below that is one
 * click away - the "collapse per branch" the tree needs so a few hundred
 * contacts does not render as one unusable page.
 *
 * $max_iterations is a hard cap purely as insurance on top of the two guards
 * above. It should never bind on real data; a page that stops instead of one
 * that hangs is the right failure mode if it ever does.
 */
function org_chart_render_subtree_html(int $start_id, array $contacts_by_id, array $children_by_manager, array &$rendered, bool $show_department, int $max_iterations = 200000): string
{
    if (isset($rendered[$start_id])) {
        return '';
    }

    $rendered[$start_id] = true;
    $has_children = !empty($children_by_manager[$start_id]);
    $report_count = count($children_by_manager[$start_id] ?? []);
    $html = org_chart_open_node_html($contacts_by_id[$start_id], 0, $has_children, $contacts_by_id, $report_count, '', $show_department);

    if (!$has_children) {
        return $html;
    }

    $stack = [[
        'children'      => $children_by_manager[$start_id],
        'idx'           => 0,
        'depth'         => 0,
        'ancestry'      => [$start_id => true],
        'ancestry_path' => [$start_id],
    ]];

    $guard = 0;
    while (!empty($stack)) {
        if (++$guard > $max_iterations) {
            break;
        }

        $top_index = count($stack) - 1;
        $top = &$stack[$top_index];

        if ($top['idx'] < count($top['children'])) {
            $child_id = $top['children'][$top['idx']];
            $top['idx']++;

            if (isset($top['ancestry'][$child_id]) || isset($rendered[$child_id])) {
                // Cycle edge (points back at one of its own ancestors) or a
                // duplicate reference - never descend into either.
                continue;
            }

            $rendered[$child_id] = true;
            $child_depth = $top['depth'] + 1;
            $child_has_children = !empty($children_by_manager[$child_id]);
            $child_report_count = count($children_by_manager[$child_id] ?? []);
            // Ancestors of $child_id, root-first, NOT including $child_id
            // itself - exactly $top['ancestry_path'] as it stands before this
            // child is appended to it below.
            $child_ancestor_path = implode(' ', $top['ancestry_path']);
            $html .= org_chart_open_node_html($contacts_by_id[$child_id], $child_depth, $child_has_children, $contacts_by_id, $child_report_count, $child_ancestor_path, $show_department);

            if ($child_has_children) {
                $child_ancestry = $top['ancestry'];
                $child_ancestry[$child_id] = true;
                $child_ancestry_path = $top['ancestry_path'];
                $child_ancestry_path[] = $child_id;
                $stack[] = [
                    'children'      => $children_by_manager[$child_id],
                    'idx'           => 0,
                    'depth'         => $child_depth,
                    'ancestry'      => $child_ancestry,
                    'ancestry_path' => $child_ancestry_path,
                ];
            }
        } else {
            $html .= "</ul></details></li>";
            array_pop($stack);
        }
        unset($top);
    }

    return $html;
}

[$children_by_manager, $roots] = org_chart_build_tree_index($contacts_by_id);

// Roots, grouped by department for the company-wide view so a chart spanning
// many departments reads as sections rather than one flat pile. Filtered to a
// single department, every root already shares that one department, so the
// grouping collapses to a single implicit group.
$roots_by_department = [];
foreach ($roots as $root_id) {
    $dept_id = $contacts_by_id[$root_id]['department_id'];
    $dept_name = $contacts_by_id[$root_id]['department_name'];
    if (!isset($roots_by_department[$dept_id])) {
        $roots_by_department[$dept_id] = ['name' => $dept_name !== '' ? $dept_name : '(No Department)', 'root_ids' => []];
    }
    $roots_by_department[$dept_id]['root_ids'][] = $root_id;
}
uasort($roots_by_department, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$rendered = [];
$tree_html = '';
foreach ($roots_by_department as $dept_id => $dept) {
    $dept_body = '';
    foreach ($dept['root_ids'] as $root_id) {
        $dept_body .= org_chart_render_subtree_html($root_id, $contacts_by_id, $children_by_manager, $rendered, $department_filter_id === 0);
    }
    // $dept['name'] is already HTML-escaped: it's built from department_name
    // (nullable_htmlentities($row['client_name']), line 96) or the literal
    // '(No Department)' fallback above - escaping it again here would
    // double-encode any department name containing &, <, >, or a quote.
    $dept_name_display = $dept['name'];
    $root_count = count($dept['root_ids']);
    $root_label = $root_count === 1 ? 'root' : 'roots';
    $tree_html .= "<div class='card mb-3'>";
    $tree_html .= "<div class='card-header py-2 d-flex justify-content-between align-items-center'>";
    $tree_html .= "<span><i class='fas fa-fw fa-building me-2'></i>$dept_name_display</span>";
    $tree_html .= "<span class='badge text-bg-secondary'>$root_count $root_label</span>";
    $tree_html .= "</div><div class='card-body'><ul class='org-tree'>$dept_body</ul></div></div>";
}

// Anything not picked up above never routes to a real root - it is a pure
// reporting cycle (manager chain loops back on itself and never hits
// NULL/archived/out-of-scope). Sweep it up and draw it anyway, starting from
// the lowest contact_id in each remaining group for a stable, repeatable
// choice of where the display-only cut happens - this changes nothing in the
// database, it only decides where THIS page's rendering breaks the loop.
$cycle_leftover_ids = array_diff(array_keys($contacts_by_id), array_keys($rendered));
sort($cycle_leftover_ids);
$cycle_html = '';
foreach ($cycle_leftover_ids as $leftover_id) {
    $cycle_html .= org_chart_render_subtree_html($leftover_id, $contacts_by_id, $children_by_manager, $rendered, $department_filter_id === 0);
}

$total_contacts = count($contacts_by_id);
$context_contacts = $total_contacts - $matching_contacts;
$department_ids = [];
$unavailable_manager_count = 0;
foreach ($contacts_by_id as $contact) {
    $department_ids[$contact['department_id']] = true;
    if ($contact['manager_id'] > 0 && !isset($contacts_by_id[$contact['manager_id']])) {
        $unavailable_manager_count++;
    }
}
$total_departments = count($department_ids);

?>

<link rel="stylesheet" href="css/org_chart.css?v=<?= file_exists(__DIR__ . '/css/org_chart.css') ? filemtime(__DIR__ . '/css/org_chart.css') : time() ?>">

<div class="card card-dark mb-3">
    <div class="card-header py-2 org-chart-header">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-sitemap me-2"></i>Organizational Chart</h3>
        <div class="card-tools">
            <div class="btn-group" role="group" aria-label="Org chart view">
                <button type="button" class="btn btn-primary" id="orgChartShowList" aria-pressed="true">List</button>
                <button type="button" class="btn btn-secondary" id="orgChartShowMap" aria-pressed="false" hidden>Chart</button>
            </div>
            <div class="btn-group ms-2" id="orgChartControls" role="group" aria-label="Chart controls" hidden>
                <button type="button" class="btn btn-secondary" id="orgChartExpandAll" title="Expand all branches" aria-label="Expand all branches"><i class="fas fa-angle-double-down"></i></button>
                <button type="button" class="btn btn-secondary" id="orgChartCollapseAll" title="Collapse all branches" aria-label="Collapse all branches"><i class="fas fa-angle-double-up"></i></button>
                <button type="button" class="btn btn-secondary" id="orgChartZoomOut" aria-label="Zoom out" title="Zoom out">−</button>
                <button type="button" class="btn btn-secondary" id="orgChartZoomReset" title="Reset zoom">100%</button>
                <button type="button" class="btn btn-secondary" id="orgChartZoomIn" aria-label="Zoom in" title="Zoom in">+</button>
                <button type="button" class="btn btn-secondary" id="orgChartZoomFit" title="Fit chart to available width">Fit</button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off" method="get">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="orgChartDepartment">Department</label>
                    <select class="form-control select2 auto-submit-select" id="orgChartDepartment" name="client_id">
                        <option value="" <?php if (!$department_filter_id) { echo "selected"; } ?>>- All Departments -</option>
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_departments_filter)) {
                            $dept_option_id = intval($row['client_id']);
                            $dept_option_name = nullable_htmlentities($row['client_name']);
                        ?>
                            <option value="<?php echo $dept_option_id; ?>" <?php if ($department_filter_id === $dept_option_id) { echo "selected"; } ?>><?php echo $dept_option_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="orgChartLocation">Location</label>
                    <select class="form-control select2 auto-submit-select" id="orgChartLocation" name="location_id">
                        <option value="">All Locations</option>
                        <?php foreach ($locations_by_id as $location_id => $location_name) { ?>
                            <option value="<?php echo $location_id; ?>" <?php if ($location_filter_id === $location_id) { echo 'selected'; } ?>><?php echo $location_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="orgChartStatus">Employment status</label>
                    <select class="form-control select2 auto-submit-select" id="orgChartStatus" name="status">
                        <option value="">All Statuses</option>
                        <?php foreach (array_keys($statuses) as $status) { ?>
                            <option value="<?php echo nullable_htmlentities($status); ?>" <?php if ($status_filter === $status) { echo 'selected'; } ?>><?php echo nullable_htmlentities(ucwords(str_replace('_', ' ', $status))); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4" id="orgChartMapSearchWrap" hidden>
                    <label class="form-label" for="orgChartSearch">Search</label>
                    <input type="search" class="form-control" id="orgChartSearch" placeholder="Search name, title, team&hellip;" autocomplete="off">
                    <div id="orgChartSearchCounter" class="small text-secondary mt-1" hidden>
                        <span id="orgChartSearchCount">0 matches</span>
                        <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="orgChartSearchPrev" aria-label="Previous match" title="Previous match (Enter)"><i class="fa fa-fw fa-chevron-up"></i></button>
                        <button type="button" class="btn btn-sm btn-link p-0 ms-1" id="orgChartSearchNext" aria-label="Next match" title="Next match (Enter)"><i class="fa fa-fw fa-chevron-down"></i></button>
                    </div>
                </div>
                <div class="col-12 text-secondary">
                    <?php echo $matching_contacts; ?> <?php echo ($location_filter_id > 0 || $status_filter !== '') ? 'matching ' : ''; ?>contact<?php echo $matching_contacts === 1 ? '' : 's'; ?><?php if ($context_contacts > 0) { ?>, plus <?php echo $context_contacts; ?> reporting ancestor<?php echo $context_contacts === 1 ? '' : 's'; ?> for context<?php } ?> across <?php echo $total_departments; ?> department<?php echo $total_departments === 1 ? '' : 's'; ?> shown below.
                    <?php if ($location_filter_id > 0 || $status_filter !== '') { ?>Ancestors outside the filters are marked Context. <a href="org_chart.php<?php echo $department_filter_id ? '?client_id=' . $department_filter_id : ''; ?>">Clear location and status filters</a>.<?php } ?>
                    <?php if (!empty($cycle_leftover_ids)) { ?>
                        <span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i><?php echo count($cycle_leftover_ids); ?> contact<?php echo count($cycle_leftover_ids) === 1 ? '' : 's'; ?> could not be placed under a real root - see "Reporting Cycle Detected" below.</span>
                    <?php } ?>
                    <?php if ($unavailable_manager_count > 0) { ?>
                        <span><?php echo $unavailable_manager_count; ?> contact<?php echo $unavailable_manager_count === 1 ? '' : 's'; ?> report to a manager unavailable in this view.</span>
                    <?php } ?>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($total_contacts > 0) { ?>
<section class="card mb-3" id="orgChartList" aria-label="Employee list">
    <div class="card-body">
        <label class="form-label" for="orgChartListSearch">Find a person in the list</label>
        <input class="form-control mb-3" type="search" id="orgChartListSearch" autocomplete="off" placeholder="Name, title, department, location, or manager">
        <div class="table-responsive org-list-scroll">
            <table class="table table-striped table-hover align-middle">
                <caption class="visually-hidden">Authorized employees shown in the organizational chart</caption>
                <thead><tr><th scope="col">Name</th><th scope="col">Title</th><th scope="col">Department</th><th scope="col">Location</th><th scope="col">Reports to</th><th scope="col">Direct reports</th></tr></thead>
                <tbody>
                <?php foreach ($contacts_by_id as $contact) {
                    $manager = $contacts_by_id[$contact['manager_id']] ?? null;
                    $manager_display = $manager ? $manager['name'] : ($contact['manager_id'] > 0 ? 'Manager unavailable' : 'Top level');
                    $profile_url = 'contact_details.php?client_id=' . $contact['department_id'] . '&contact_id=' . $contact['id'];
                ?>
                    <tr class="org-list-row">
                        <th scope="row"><a href="<?php echo nullable_htmlentities($profile_url); ?>"><?php echo $contact['name']; ?></a><?php if ($contact['is_context']) { ?> <span class="badge text-bg-secondary">Context</span><?php } ?></th>
                        <td><?php echo $contact['title']; ?></td>
                        <td><?php echo $contact['department_name']; ?></td>
                        <td><?php echo $contact['location_name']; ?></td>
                        <td><?php echo $manager_display; ?></td>
                        <td><?php echo count($children_by_manager[$contact['id']] ?? []); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <p id="orgChartListEmpty" class="text-secondary" hidden>No people match this search.</p>
    </div>
</section>
<?php } ?>

<p id="orgChartLoading" class="text-secondary" role="status" hidden>Building chart…</p>
<div id="orgChartRoot"<?php if ($total_contacts > 0) { ?> hidden<?php } ?>>
    <?php if ($total_contacts === 0) { ?>
        <div class="card"><div class="card-body text-secondary text-center"><?php echo ($location_filter_id > 0 || $status_filter !== '') ? 'No contacts match these filters.' : 'No contacts to chart' . ($department_filter_id ? ' for this department.' : '.'); ?></div></div>
    <?php } else {
        // The accessible employee list above works without JavaScript. Keep
        // the already access-filtered hierarchy as a hidden data source; the
        // D3 view reads it only after the user selects Chart.
        echo '<div id="orgChartSource" hidden>' . $tree_html;
        if ($cycle_html !== '') {
            echo '<ul class="org-tree">' . $cycle_html . '</ul>';
        }
        echo '</div>';
        echo '<p class="small text-secondary mb-2">Drag to pan, scroll to zoom, and use + to expand a department.</p>';
        echo '<div id="orgChartCanvas" class="org-d3-frame" role="region" aria-label="Interactive organizational chart"></div>';
    } ?>

    <?php if (!empty($cycle_leftover_ids)) { ?>
        <div class="card border-danger mb-3">
            <div class="card-header py-2 text-danger"><i class="fas fa-fw fa-exclamation-triangle me-2"></i>Reporting Cycle Detected</div>
            <div class="card-body">
                <p class="text-secondary">These contacts' manager chains loop back on themselves (e.g. A reports to B who reports back to A) and never reach a contact with no manager, so they cannot be placed under a real root. Fix the "Manager" field on one contact in each loop to break it.</p>
                <p class="text-secondary mb-0">The chart shows each affected contact once, with one reporting link cut for display.</p>
            </div>
        </div>
    <?php } ?>
</div>

<?php
/*
 * includes/header.php sends a CSP of "script-src 'self' 'nonce-$csp_nonce' ..."
 * with no 'unsafe-inline' - a plain <script> block with no nonce is silently
 * dropped by the browser, not merely discouraged (this is what quietly broke
 * this exact page's old inline Expand All / Collapse All handler; see
 * agent/project_kanban.php for the same working nonce pattern used here).
 *
 * Load order matters: js/org_chart.js (which defines window.OrgChart) has to
 * run BEFORE the nonce'd call below that invokes OrgChart.init() - so the
 * external <script src> is emitted first, then the inline call.
 */
?>
<script src="js/org_chart.js?v=<?= file_exists(__DIR__ . '/js/org_chart.js') ? filemtime(__DIR__ . '/js/org_chart.js') : time() ?>"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    if (window.OrgChart) {
        OrgChart.init();
    }
</script>

<?php
require_once "../includes/footer.php";
