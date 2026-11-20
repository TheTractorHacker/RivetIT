# Linux Fleet (cluster health page) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only "Linux Fleet" page under RMM: one card per RMM group (cluster) with a health badge and a node table (online, updates, reboot, scan age, checks, PostgreSQL), plus cluster flags.

**Architecture:** Pure functions in `includes/rmm_linux_fleet.php` turn already-fetched device facts into node rows, health verdicts and cluster flags (unit-testable, no DB). A loader in the same file reads through RivetCore as the signed-in user (same path as `includes/rmm_pat_ui.php`), a renderer returns escaped HTML, and `agent/rmm_linux_fleet.php` is the page. A documented PostgreSQL script check supplies the Postgres column through the existing script-check type.

**Tech Stack:** PHP 8.5, RivetCore 1.0.0-rc.5x technician API (`rivetRmmAutoApi`, `rivetRmmModule()->readModel()`), Tabler UI kit helpers (`rivetRmmUiPill`, `rivetRmmUiCard`, `rmmH`), CLI PASS/FAIL tests in `tests/`.

**Spec:** `docs/superpowers/specs/2026-11-20-linux-fleet-cluster-health-design.md`

## Global Constraints

- RivetIT only in this plan. RivetMSP lacks `includes/rmm_pat_ui.php` and pins Core rc.55, so it is a separate port (not in this plan).
- Read only: no button changes a server. No new tables, no Core change, no DB migration.
- Page gate = `rivetRmmPatPage()` rules: RMM module on, `patching` sub-switch on, `rmm.device.view` + `rmm.patch.view`; denial via `itflow_render_denied`. Module/switch off asks the database nothing.
- Every read goes through RivetCore as the signed-in user (department scope applies). No direct reads of RMM tables except lookups limited to ids Core just returned.
- All output escaped with `rmmH()`; no inline script or handler; no new JS file.
- Node cap: at most 60 devices per page; a larger set shows a notice, never silently truncates.
- Linux = the device's patch scan manager is one of `apt|dnf|yum|zypper`, else its `os_version` matches `/linux|ubuntu|debian|rhel|red hat|centos|rocky|alma|fedora|suse|sles|arch/i`.
- "Healthy" node = online AND no critical/security update overdue AND no failing check AND (Postgres check absent or passing).
- Files in `tests/` are gitignored: use `git add -f`.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- A group with zero Linux devices: card shows "No Linux servers in this group", not an empty table or error.
- A device with no patch scan yet: shown "Never scanned", counts as needs-attention, never as healthy.
- A device in two groups appears in both cards; fleet totals count it once.
- A device in no group: shown in an "Ungrouped" card.
- Check detail text from a device is untrusted: escape and truncate to 120 chars.
- A department-restricted user sees only their devices; a group with none of theirs does not leak its name or size.

---

### Task 1: Pure model — node, health, cluster flags

**Files:**
- Create: `includes/rmm_linux_fleet.php`
- Test: `tests/linux_fleet_model.php`

**Interfaces:**
- Produces:
  - `rivetLinuxFleetIsLinux(array $detail, array $patch): bool`
  - `rivetLinuxFleetPostgres(array $checks): array{state:string,detail:string}` — state is `none|ok|fail`; considers checks whose `key` contains `postgres` or `pg_` (case-insensitive); any non-ok status makes `fail`; `detail` is the first failing check's detail, control characters removed, truncated to 120 chars.
  - `rivetLinuxFleetNode(array $summary, array $detail, array $patch): array` returning keys: `device_id, hostname, asset_id, online(bool), status, os_version, uptime_s, last_checkin_at, scanned(bool), scan_age_days(?int), sec_count, other_count, overdue(int), reboot_pending(bool), failing_checks(list<string> check keys), postgres(array from above), manager`.
  - `rivetLinuxFleetHealth(array $node): array{state:string,reasons:list<string>}` — state `healthy|attention|down`; `down` when not online; reasons are short phrases ("Never scanned", "3 security updates overdue", "Restart pending", "Check failing: disk", "PostgreSQL failing").
  - `rivetLinuxFleetCluster(string $name, array $nodes): array{name,total,healthy,attention,down,flags:list<string>,nodes}` — flags: "N nodes need a restart" when more than one node has `reboot_pending`; "OS versions differ: a, b" when two or more distinct non-empty `os_version` values exist; sort nodes down first, then attention, then healthy, then hostname.
- Consumes: `$summary` = RmmReadModel::summary item (`device_id, hostname, asset_id, status, last_checkin_at, os_version`); `$detail` = technician device detail (`pending_reboot, uptime_s, checks[{key,status,detail}]`); `$patch` = patch summary (`scan.state, scan.scanned_at, scan.manager, scan.reboot_pending, counts.missing_by_kind, overdue.critical_or_security`).

- [ ] **Step 1: Write the failing test** `tests/linux_fleet_model.php` (CLI, prints PASS/FAIL per assertion, exit 1 on any FAIL). Assertions: apt manager → Linux; "Windows 11" with manager `wua` → not Linux; node from a never-scanned patch → `scanned=false`, health `attention` with reason "Never scanned"; offline status → `down`; overdue 3 → reason "3 security updates overdue"; `pending_reboot` true → "Restart pending"; check `postgres_ready` status `crit` → postgres `fail` and reason "PostgreSQL failing"; Postgres detail with `\x07` and 300 chars → control chars gone, length ≤ 120; cluster of three nodes with two reboots → flag "2 nodes need a restart"; nodes with `Ubuntu 22.04` and `Ubuntu 24.04` → flag "OS versions differ: Ubuntu 22.04, Ubuntu 24.04"; sort order down, attention, healthy.
- [ ] **Step 2: Run** `php tests/linux_fleet_model.php` — Expected: FAIL (functions undefined).
- [ ] **Step 3: Implement** the five functions in `includes/rmm_linux_fleet.php` (plain functions, `declare` nothing global, no includes needed for the pure part). Read `vendor/rivet/rivet-core/src/Rmm/Checks/CheckEvaluator.php` for the real check status words and treat any status other than the OK word as failing; unknown/never-reported checks do not fail a node.
- [ ] **Step 4: Run** `php tests/linux_fleet_model.php` — Expected: all PASS.
- [ ] **Step 5: Commit** `git add includes/rmm_linux_fleet.php && git add -f tests/linux_fleet_model.php`; message `Linux Fleet: pure node, health and cluster model`.

### Task 2: Loader and view model

**Files:**
- Modify: `includes/rmm_linux_fleet.php`
- Test: `tests/linux_fleet_view.php`

**Interfaces:**
- Consumes: Task 1 functions; `rivetRmmPatPage()`, `rivetRmmPatApi($ctx,'GET',[...])` from `includes/rmm_pat_ui.php` (the file `require_once`s `rmm_automation.php`); `rivetRmmModule($mysqli)->readModel()->groups($visible)` and `->listDevices(['group'=>id,'retired'=>'hide'], $visible, 60, 0)`; `rivetRmmExcludePanelDevices()`.
- Produces: `rivetLinuxFleetView(array $ctx, array $get): array` with keys `error(string)`, `clusters(list from rivetLinuxFleetCluster)`, `totals{nodes,healthy,attention,down,security_pending}`, `capped(bool)`, `filters{group:int,show:string}`; `show` is `all|attention`; `group` 0 = all groups. Per-device reads: `rivetRmmPatApi($ctx,'GET',[(string)$id])` for detail and `[(string)$id,'patches']` for the patch summary (Core authorizes each; a non-ok reply skips that device and counts it in `skipped`). Devices in no group go to a cluster named "Ungrouped". A device in two groups is in both clusters but counted once in `totals`.
- Internal seam for tests: `rivetLinuxFleetBuild(array $groups, array $membersByGroup, callable $fetch): array` — pure assembly over injected data (`$fetch($deviceId): ?array{summary,detail,patch}`); `rivetLinuxFleetView` wires real reads into it.

- [ ] **Step 1: Write failing test** `tests/linux_fleet_view.php` against `rivetLinuxFleetBuild` with fake data: empty group shows in output with zero nodes; Windows device excluded; device in two groups counted once in totals and present in both clusters; ungrouped device lands in "Ungrouped"; `fetch` returning null → skipped; 61 devices → `capped=true` and 60 nodes; `show=attention` drops healthy nodes and any cluster left empty.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** `rivetLinuxFleetBuild` and `rivetLinuxFleetView`.
- [ ] **Step 4: Run** both test files — Expected: PASS.
- [ ] **Step 5: Commit** `Linux Fleet: loader and view model`.

### Task 3: Renderer, page and navigation

**Files:**
- Modify: `includes/rmm_linux_fleet.php` (add `rivetLinuxFleetPage(array $vm): string`)
- Create: `agent/rmm_linux_fleet.php`
- Modify: `includes/rmm_bootstrap.php` (nav entry in `rivetRmmAutoNav`: `['patching', '/agent/rmm_linux_fleet.php', 'Linux fleet', 'fa-server', ['rmm_linux_fleet.php'], 'patching']`, and `'/agent/rmm_linux_fleet.php' => RmmAbility::PATCH_VIEW` in `$abilities`), `agent/includes/side_nav.php` (add `'rmm_linux_fleet.php'` to the `endpoint` section list under the Phase 4 comment)
- Test: `tests/linux_fleet_page.php`

**Interfaces:**
- Consumes: `rivetLinuxFleetView()`; helpers `rivetRmmAutoHeader`, `rivetRmmUiPill`, `rivetRmmUiCard`, `rivetRmmUiThead`, `rmmH`, `rivetRmmPatKpi`; page pattern of `agent/rmm_patches.php`.
- Produces: page layout — header "Linux fleet" with filter form (group select, "Needs attention only" checkbox, GET to `/agent/rmm_linux_fleet.php`); KPI strip (Servers, Healthy, Need attention, Down, Security updates pending); one card per cluster titled `name` with a badge `"<healthy> of <total> healthy"` (pill `ok` all healthy, `warn` some attention, `crit` any down), flags as `alert-warning` lines, then a table with columns Server (link to `/agent/rmm_asset.php?asset_id=<id>` when `asset_id>0`, else plain), Status pill, Updates (`<sec> security / <other> other` or "Never scanned"), Restart, Last scan ("<n>d ago"), Checks ("OK" or failing keys), PostgreSQL ("—" / OK / "Failing: <detail>"), Why (reasons joined). Links to `/agent/rmm_patches.php` ("Patch compliance") in the header. Empty cluster text: "No Linux servers in this group". Escape every value; `capped` shows a notice "Showing the first 60 servers."

- [ ] **Step 1: Write failing test** `tests/linux_fleet_page.php`: render a fixture view model; assert hostname escaped (`<script>` in a hostname appears as `&lt;script&gt;`), badge text "2 of 3 healthy", the "No Linux servers in this group" text, the capped notice, `rmm_asset.php?asset_id=` link only when asset id > 0; source-wiring assertions that `agent/rmm_linux_fleet.php` calls `enforceUserPermission('module_rmm')` then `rivetRmmPatPage(`, contains no `<script` and no `onclick`; that the nav entry and side_nav page name exist.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** renderer, page file (copy the 20-line shape of `agent/rmm_patches.php`), nav entries.
- [ ] **Step 4: Run** all three test files plus `php -l` on touched PHP — Expected: PASS.
- [ ] **Step 5: Commit** `Linux Fleet: page, renderer and RMM navigation`.

### Task 4: PostgreSQL check script and docs

**Files:**
- Create: `docs/rmm/POSTGRES_CHECK.md`
- Modify: `docs/ENDPOINT_AGENT.md` (new section "Linux fleet page" after section 25), `CHANGELOG.md` (entry), `PROGRESS.md` only if it lists RMM phases.

**Interfaces:** documents the script text; the page matches a check whose key contains `postgres` or `pg_`.

- [ ] **Step 1: Write** `docs/rmm/POSTGRES_CHECK.md`: how to add a Script check policy (Policies page, platform Linux, check type `script`), named so its key contains `postgres`. Include a read-only bash script: exits 0 when healthy, 1 otherwise, prints one line of detail. It runs `pg_isready` (fail if not accepting); compares `count(*)` of `pg_stat_activity` with `max_connections` (fail ≥ 90%); on a replica (`pg_is_in_recovery()`) fails when replay lag > 60 s; fails on any transaction older than 15 min. Uses local peer auth as the `postgres` user via `sudo -u postgres psql -Atqc`, no password anywhere. State plainly it was run only as written here and note the agent-side script runner's own limits (read the existing script-check section of `docs/ENDPOINT_AGENT.md` first and match its exit-code convention).
- [ ] **Step 2: Run the script's SQL** against a scratch PostgreSQL if one is installed (`which psql`); if none, say so in the doc under "Not run on a real server" instead of claiming it works.
- [ ] **Step 3: Docs/CHANGELOG** entries: page location, switches needed, what "healthy" means, MSP not included yet.
- [ ] **Step 4: Commit** `Linux Fleet: PostgreSQL check script and docs`.

### Task 5: Verify on a private database and in a browser

**Files:** none committed (evidence under `/home/sysadmin/scratch-linux-fleet/`).

- [ ] **Step 1:** Build a schema-only scratch DB from repo files (repo `db.sql` + migrations only, never live rows or secrets; follow `/home/sysadmin/scratch-onboarding-db/` method from the Onboarding Packet run). Seed ~8 fake Linux devices in 2 groups: healthy, offline, never scanned, overdue security, reboot pending x2, OS-version drift, failing Postgres check, one Windows device, one ungrouped.
- [ ] **Step 2:** Run all three new test files, `php -l` on changed files, and the existing RMM tests that touch nav (`tests/` names containing `rmm_nav` or `side_nav`).
- [ ] **Step 3:** Load the page in a headless browser as an admin and as a department-restricted tech; screenshot at desktop and phone width; confirm no JS console errors from this page and that restricted user sees only their devices.
- [ ] **Step 4:** Write `docs/superpowers/plans/…` evidence report under the SDD workspace listing what was verified and what was not (real agents, dnf/yum/zypper, real Postgres, nginx/FPM).
