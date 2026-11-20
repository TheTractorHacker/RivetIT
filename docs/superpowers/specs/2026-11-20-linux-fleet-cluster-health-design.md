# Linux Fleet — cluster health page (short design)

Status: DRAFT, awaiting Blake Foley's approval, 2026-11-20. Applies to RivetIT and RivetMSP.

## Intent
Blake runs about 20 Linux servers (dev work, apps, most with PostgreSQL) and wants one place to see whether each cluster is healthy and what needs patching.

## Goals
- New read-only page under RMM: **Linux Fleet**, one card per cluster. A cluster is an existing RMM group (no new cluster object).
- Per cluster: health badge ("3 of 4 healthy"), and a node table: online/offline, pending updates (security / other), reboot pending, days since last scan, disk / CPU / memory from existing checks, open alerts, PostgreSQL status.
- Fleet strip on top (counts) and "needs attention first" sort. Each node links to the existing Patches, device and Remote screens.
- Cluster flags: nodes drifted in OS or kernel version from the rest; more than one node needing a reboot at once.
- PostgreSQL health through the existing custom **script check** type: a starter script (pg_isready, connection count vs max, replication lag when a replica, long-running transactions). Shown as a column; no agent change.
- "Healthy" = online, no critical or security updates overdue, no failing checks, disk below alert threshold, Postgres check passing where one is deployed.

## Non-goals (this round)
Patch, reboot or restart buttons; rolling patch order; new cluster object or tables; managing Postgres itself.

## Anti-goals
Anything that changes a server from this page. Anything that stores a database credential in plaintext (the Postgres script uses the server's local peer auth or a read-only role the tech sets up).

## Constraints
- Read only existing data (RivetCore technician API: patch scans, checks, metrics, alerts, groups); honour the module switch, abilities and department scope.
- Both editions; code in each edition's own UI layer. Core changes only if a needed read is missing, and Core is not tagged without Blake's go.
- Only apt is proven for Linux patching; dnf, yum and zypper show scan data only.

## Approaches considered
- **A. Fleet page grouped by RMM groups (chosen).** Little new backend, no agent change.
- B. Cluster object with rolling patching: new tables, approvals, rollback; revisit once A is in use.
- C. Linux preset on the Patches tab: no cluster view.

## Left to the builder
Exact page location and route name, column order and widths, badge thresholds, the starter script's exact queries.
