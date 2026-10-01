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
own text search. Search in the chart opens matching branches; Trace to Root
highlights one reporting chain. Each name opens the existing contact profile.

Zoom controls scale the chart without changing its data. **Fit** scales each
department chart to its available width; **100%** restores natural size.
Horizontal scrolling remains available for large charts. The chart initially
renders the authorized contacts in one database query, then lays out visible
branches in the browser. No full employee profiles are loaded for the chart.
For very large organizations, use a department filter to reduce the number of
nodes before expanding all branches.

In a synthetic browser fixture with 1,000 contacts (950 independent roots and
one 50-person reporting chain), headless Chromium 153 on a development host
with 12 Xeon Gold 6130 vCPUs and 15 GiB RAM
initialized the chart in 169 ms and found one name search match. This checks
the browser layout and search path, not database query time or a production
page with real photos and styles.

Run `php tests/org_chart_filter.php` to check that location/status filters
retain authorized ancestors, terminate on reporting cycles, and do not
restore an inaccessible manager.
