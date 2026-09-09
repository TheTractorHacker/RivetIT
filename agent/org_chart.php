<?php

/*
 * Organizational Chart
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
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_client');

// Optional department filter. 0 = company-wide (default), matching contacts.php's
// own no-client_id company-wide branch. A non-zero value goes through the exact
// same enforceClientAccess() gate every per-department page in this app uses, so
// a technician can't fish another department's chart out just by editing the URL.
$department_filter_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
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
$sql_contacts = mysqli_query($mysqli, "
    SELECT contacts.contact_id, contacts.contact_name, contacts.contact_title,
           contacts.contact_department, contacts.contact_manager_id,
           contacts.contact_employment_status, contacts.contact_client_id,
           clients.client_name
    FROM contacts
    LEFT JOIN clients ON clients.client_id = contacts.contact_client_id
    WHERE contacts.contact_archived_at IS NULL
    $scope_where
    ORDER BY contacts.contact_name ASC
");

$contacts_by_id = [];
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
        'manager_id'         => intval($row['contact_manager_id']),
    ];
}

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

// The card shown for one contact, reused for both an expandable node's
// <summary> and a childless node's plain <li> - same avatar-initials-in-a-circle
// markup agent/contacts.php already uses for its own contact rows.
function org_chart_node_card_html(array $node): string
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
    $subtitle = implode(' &middot; ', $subtitle_parts);

    $html = "<span class='org-node d-inline-flex align-items-start gap-2'>";
    $html .= "<span class='fa-stack fa-1x flex-shrink-0'><i class='fa fa-circle fa-stack-2x text-secondary'></i><span class='fa fa-stack-1x text-white org-node-initials'>{$node['initials']}</span></span>";
    $html .= "<span class='org-node-text'>";
    $html .= "<a class='text-dark fw-bold' href='" . nullable_htmlentities($link) . "'>{$node['name']}</a>{$status_badge}";
    if ($subtitle !== '') {
        $html .= "<div class='text-secondary small'>$subtitle</div>";
    }
    $html .= "</span></span>";

    return $html;
}

function org_chart_open_node_html(array $node, int $depth, bool $has_children): string
{
    if (!$has_children) {
        return "<li class='org-leaf'>" . org_chart_node_card_html($node) . "</li>";
    }

    $open_attr = ($depth === 0) ? ' open' : '';
    return "<li><details$open_attr><summary>" . org_chart_node_card_html($node) . "</summary><ul class='org-tree'>";
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
 * A node's own <details> defaults OPEN only at depth 0, so a root and its
 * immediate direct reports are visible on load and everything below that is one
 * click away - the "collapse per branch" the tree needs so a few hundred
 * contacts does not render as one unusable page.
 *
 * $max_iterations is a hard cap purely as insurance on top of the two guards
 * above. It should never bind on real data; a page that stops instead of one
 * that hangs is the right failure mode if it ever does.
 */
function org_chart_render_subtree_html(int $start_id, array $contacts_by_id, array $children_by_manager, array &$rendered, int $max_iterations = 200000): string
{
    if (isset($rendered[$start_id])) {
        return '';
    }

    $rendered[$start_id] = true;
    $has_children = !empty($children_by_manager[$start_id]);
    $html = org_chart_open_node_html($contacts_by_id[$start_id], 0, $has_children);

    if (!$has_children) {
        return $html;
    }

    $stack = [[
        'children' => $children_by_manager[$start_id],
        'idx'      => 0,
        'depth'    => 0,
        'ancestry' => [$start_id => true],
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
            $html .= org_chart_open_node_html($contacts_by_id[$child_id], $child_depth, $child_has_children);

            if ($child_has_children) {
                $child_ancestry = $top['ancestry'];
                $child_ancestry[$child_id] = true;
                $stack[] = [
                    'children' => $children_by_manager[$child_id],
                    'idx'      => 0,
                    'depth'    => $child_depth,
                    'ancestry' => $child_ancestry,
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
        $dept_body .= org_chart_render_subtree_html($root_id, $contacts_by_id, $children_by_manager, $rendered);
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
    $cycle_html .= org_chart_render_subtree_html($leftover_id, $contacts_by_id, $children_by_manager, $rendered);
}

$total_contacts = count($contacts_by_id);
$total_departments = count($roots_by_department);

?>

<link rel="stylesheet" href="css/org_chart.css">

<div class="card card-dark mb-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-sitemap me-2"></i>Organizational Chart</h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-secondary" id="orgChartExpandAll"><i class="fas fa-angle-double-down me-2"></i>Expand All</button>
                <button type="button" class="btn btn-secondary" id="orgChartCollapseAll"><i class="fas fa-angle-double-up me-2"></i>Collapse All</button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off" method="get">
            <div class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select class="form-control select2 auto-submit-select" name="client_id">
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
                <div class="col-md-8 text-secondary">
                    <?php echo $total_contacts; ?> contact<?php echo $total_contacts === 1 ? '' : 's'; ?> across <?php echo $total_departments; ?> department<?php echo $total_departments === 1 ? '' : 's'; ?> shown below.
                    <?php if (!empty($cycle_leftover_ids)) { ?>
                        <span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i><?php echo count($cycle_leftover_ids); ?> contact<?php echo count($cycle_leftover_ids) === 1 ? '' : 's'; ?> could not be placed under a real root - see "Reporting Cycle Detected" below.</span>
                    <?php } ?>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="orgChartRoot">
    <?php if ($total_contacts === 0) { ?>
        <div class="card"><div class="card-body text-secondary text-center">No contacts to chart<?php echo $department_filter_id ? ' for this department.' : '.'; ?></div></div>
    <?php } else {
        echo $tree_html;
    } ?>

    <?php if (!empty($cycle_leftover_ids)) { ?>
        <div class="card border-danger mb-3">
            <div class="card-header py-2 text-danger"><i class="fas fa-fw fa-exclamation-triangle me-2"></i>Reporting Cycle Detected</div>
            <div class="card-body">
                <p class="text-secondary">These contacts' manager chains loop back on themselves (e.g. A reports to B who reports back to A) and never reach a contact with no manager, so they cannot be placed under a real root. Fix the "Manager" field on one contact in each loop to break it.</p>
                <ul class="org-tree"><?php echo $cycle_html; ?></ul>
            </div>
        </div>
    <?php } ?>
</div>

<script>
(function () {
    var root = document.getElementById('orgChartRoot');
    if (!root) { return; }

    var expandBtn = document.getElementById('orgChartExpandAll');
    var collapseBtn = document.getElementById('orgChartCollapseAll');

    if (expandBtn) {
        expandBtn.addEventListener('click', function () {
            root.querySelectorAll('details').forEach(function (d) { d.open = true; });
        });
    }
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function () {
            root.querySelectorAll('details').forEach(function (d) { d.open = false; });
        });
    }
})();
</script>

<?php
require_once "../includes/footer.php";
