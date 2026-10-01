# Organizational chart

The chart is available to IT staff with `module_client` access. It reads the
existing `contacts.contact_manager_id` links and does not edit employee or
directory data. Department Portal users do not see this page.

The company-wide view includes only contacts in departments the signed-in
staff member may access. Selecting a department uses the existing department
access check. A manager outside that scope is shown as **Manager unavailable**;
the page does not reveal that manager's name or profile. An unset manager is
shown as **Top level** in the list. Reporting cycles are displayed separately
with a warning, so they cannot make the renderer loop indefinitely.

Location and employment-status filters retain authorized ancestors needed to
show where each matching employee sits in the hierarchy. Those ancestors are
labeled **Context**. The count above the chart separates matches from context
contacts. The list view contains the same authorized, filtered set and has its
own text search. Chart search counts matches; Enter or the next/previous
buttons focus a person and open the reporting path. Each name opens the
existing contact profile.

The page opens in a compact, searchable list with its own bounded scroll area.
Choose **Chart** to load the locally hosted `d3-org-chart` library and build a
compact, pannable hierarchy on demand. Its department groups open into people;
the chart starts with groups collapsed when multiple departments are present.
Scroll or use the controls to zoom, and use **Fit** to frame visible nodes.
**100%** restores the natural zoom level. The chart is bounded to the viewport,
and the list remains available for keyboard navigation or if the library fails.
No full employee profiles are loaded for either view. For very large
organizations, use a department filter before expanding all branches.

The D3, d3-flextree, and d3-org-chart browser builds are pinned locally under
`agent/js/vendor/org-chart/`, with their license texts beside them. They load
only when Chart is selected, matching the page's self-only Content Security
Policy and avoiding any third-party browser request.

Run `php tests/org_chart_filter.php` to check that location/status filters
retain authorized ancestors, terminate on reporting cycles, and do not
restore an inaccessible manager.
