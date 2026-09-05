# ITFlow Internal IT — Redesign & RMM Telemetry Architecture Report

**Status:** Phase 0 deliverable. Audit and architecture only — no implementation has been performed.
**Scope:** The two programmes described in `ITFlow_Internal_IT_Complete_Redesign_RMM_Telemetry_Master_Plan.md`: replacing AdminLTE 4 with Tabler as the application shell, and building a provider-neutral endpoint telemetry subsystem.
**Method:** 17 parallel read-only investigations across the codebase, a completeness critic pass over their findings, targeted vendor/library feasibility research, and adversarial review of the riskiest proposals. Every factual claim below is cited to a file, a line, a live log, or a vendor document.

---

## Executive summary

Three findings should be read before anything else in this report, because each one changes what the programme is.

**1. The RMM data is not in this install.** The repository this report audits — `/var/www/mw-itflow.foleyit.com`, database `midwest_itflow` — has **zero cron entries**. None of its background jobs run. The live RMM sync is `/opt/scripts/itflow_rmm_sync.php`, executing every minute from `/etc/crontab:24`, and it hard-codes `$_SERVER['DOCUMENT_ROOT'] = '/var/www/itflow.foleyit.com'` — a different install with a different database. `/etc/cron.d/itflow` points at that same other install. At least five ITFlow installs share this machine (`mw-itflow`, `itflow`, `beta-itflow`, `itflow-staging`, `itpsa`) behind one nginx and one PHP-FPM pool. **A telemetry subsystem built in this repository would, today, receive no data.** Which install the programme targets is an open decision and a prerequisite to everything in Part 2.

**2. The fleet is roughly 26 endpoints, not 130.** From `/var/log/itflow_rmm_sync.log`, every minute: Tactical RMM 21 devices updated and 5 skipped, Level.io 3, Sophos Central 2 firewalls. The master plan's storage arithmetic assumed 130 endpoints and derived 3.74M samples/day; the real figure is roughly a fifth of that. Several of the plan's heavier provisions — partitioning, an eventual dedicated time-series database — are premature at this size. This report states the fleet size at which each becomes necessary rather than adopting them by default.

**3. AdminLTE is far less embedded than the file counts suggest.** 218 first-party PHP files reference an AdminLTE class, which looks alarming. But there are **zero** calls to the AdminLTE JavaScript API anywhere outside `plugins/`, only **three** `data-lte-*` attributes in the entire repository, and the single most widespread AdminLTE class — `card-dark`, 307 occurrences across 161 files — is already overridden into a visual no-op by first-party CSS. The structural shell is opened and closed in exactly **three files**, and Tabler's own shell contract maps onto that same split one-for-one. The genuine dependency is narrow and nameable, and is enumerated in Section B.

Alongside these, the audit found three defects worth fixing regardless of whether either programme proceeds: the entire RMM feature set is **admin-only** because its permission modules were never seeded (Section X); every Chart.js chart is **unreadable in dark mode** (Section A.6); and five Tactical agents **fail to match an asset on every single sync cycle** (Section J.4).

## Contents

**Part 1 — Current state**
- [A. Current frontend architecture](#a-current-frontend-architecture)
- [B. Current AdminLTE dependencies](#b-current-adminlte-dependencies)
- [H. Current RMM architecture](#h-current-rmm-architecture)
- [I. Current RMM providers and capabilities](#i-current-rmm-providers-and-capabilities)
- [J. Existing telemetry available](#j-existing-telemetry-available)

**Part 2 — Proposed frontend architecture**
- [C. AdminLTE → Tabler migration strategy](#c-adminlte--tabler-migration-strategy)
- [D. Proposed global shell](#d-proposed-global-shell)
- [E. Reusable UI component architecture](#e-reusable-ui-component-architecture)
- [F. CSS file architecture](#f-css-file-architecture)
- [G. JavaScript module architecture](#g-javascript-module-architecture)

**Part 3 — Proposed telemetry subsystem**
- [K. Canonical metric registry](#k-canonical-metric-registry)
- [L. Provider interface](#l-provider-interface)
- [M. Metric ingestion flow](#m-metric-ingestion-flow)
- [N. Time-series database schema](#n-time-series-database-schema)
- [O. Retention and rollup strategy](#o-retention-and-rollup-strategy)
- [P. Metrics API](#p-metrics-api)
- [Q. Live metrics over SSE](#q-live-metrics-over-sse)
- [T. GPU and extended telemetry feasibility](#t-gpu-and-extended-telemetry-feasibility)

**Part 4 — Proposed product surfaces**
- [R. Device performance page](#r-device-performance-page)
- [S. Fleet performance page](#s-fleet-performance-page)
- [U. Alert and condition integration](#u-alert-and-condition-integration)
- [V. Ticket integration](#v-ticket-integration)

**Part 5 — Execution**
- [W. Database migrations required](#w-database-migrations-required)
- [X. Security risks](#x-security-risks)
- [Y. Migration risks](#y-migration-risks)
- [Z. Phased implementation order](#z-phased-implementation-order)
- [Appendix: files likely to be created or modified](#appendix-files-likely-to-be-created-or-modified)

### A note on verification

Findings were produced by parallel investigators and then attacked by a completeness critic, which surfaced ten internal contradictions and eight load-bearing unknowns. Where investigators disagreed, the disagreement was resolved by re-reading the source rather than averaging the claims. The following load-bearing claims were additionally re-verified by hand before publication:

| Claim | Verified |
|---|---|
| Zero AdminLTE JS API call sites outside `plugins/` | ✅ every hit is a `<link>`/`<script>` tag or a comment |
| Exactly three `data-lte-*` attributes repo-wide | ✅ `top_nav.php:8`, `agent/…/side_nav.php:46`, `admin/…/side_nav.php:15` |
| `.card-dark` neutralised by first-party CSS | ✅ `itflow_custom.css:270-300` |
| `.card-tools`/`.btn-tool` have no first-party base definition | ✅ only compensating patches at `bridge:217-218`, `custom:279`, `custom:815-818` |
| `Chart.defaults.color = '#292b2c'` duplicated across 13 files | ✅ exactly 13 |
| Six `module_rmm*` names gate 77 call sites; none is ever seeded | ✅ only 7 modules seeded (client, support, credential, sales, financial, reporting, kb) |
| This repo has no cron; the live sync targets another install | ✅ `/etc/crontab:24` → `/opt/scripts/itflow_rmm_sync.php` → `/var/www/itflow.foleyit.com` |
| Fleet is ~26 endpoints | ✅ `/var/log/itflow_rmm_sync.log`: Tactical 21 (+5 skipped), Level 3, Sophos 2 |

---

# PART 1 — CURRENT STATE

## A. Current frontend architecture

### A.1 The shell is three files, and only three

Every agent, admin, reports and user page is assembled by one of eight `inc_all*.php` orchestrators, which require, in fixed order: `config.php` → `functions.php` → `check_login.php` → `page_title.php` → `includes/header.php` → `includes/top_nav.php` → a sidebar → `includes/inc_wrapper.php` → `inc_alert_feedback.php` → `filter_header.php`. The page then emits its own body content and ends by requiring `includes/footer.php`.

The structural wrappers are split across exactly three of those files:

| File | Opens | Closes |
|---|---|---|
| `includes/header.php` (ends :147) | `<html>`, `<body>`, `<div class="app-wrapper">` | nothing |
| `includes/inc_wrapper.php` (6 lines) | `<main class="app-main">`, `.app-content`, `.container-fluid` | nothing |
| `includes/footer.php` (:17-20, :80-81) | nothing | all six, in reverse |

A tag-balance pass confirms it precisely: `header.php` leaves `[html, body, div]` open, `inc_wrapper.php` leaves `[main, div, div]` open, `footer.php` carries six extra closes. Every sidebar file, `top_nav.php` and `inc_client_top_head.php` is internally balanced.

This is the single most important structural fact in the report. **The shell swap is a three-file change** for the four AdminLTE-4 portals. Nothing else in 252 pages opens or closes a structural wrapper.

Coverage: 8 orchestrators over 252 pages — `agent/includes/inc_all.php` (65 pages), `inc_all_client.php` (42), `inc_client_overview_all.php` (6), `agent/reports/includes/inc_all_reports.php` (25), `agent/user/includes/inc_all_user.php` (6), `admin/includes/inc_all_admin.php` (79), `client/includes/inc_all.php` (23), `guest/includes/inc_all_guest.php` (6). 209 files require `includes/footer.php`.

### A.2 Two portals do not share the shell at all

The **client portal** (23 pages) is a wholly separate, non-AdminLTE shell: `client/includes/header.php` emits its own doctype, a plain Bootstrap 5 navbar, a welcome banner and its own inline alert mechanism, then opens `<div class="container">`. It emits **no `<body>` tag at all**, and `client/includes/footer.php` closes one `<div>` and never emits `</body></html>`. The markup is structurally invalid, and because there is no `<body>`, the client portal can never receive the `dark-mode` class the rest of the app uses (see A.5).

The **guest portal** (6 pages) uses its own header and wrapper — `guest/includes/guest_header.php` hard-codes `data-bs-theme="light"`, loads a reduced stylesheet set, and opens `.app-wrapper` — but then shares the **common** `includes/footer.php`. That cross-portal coupling means any edit to the shared footer's closing tags must satisfy both wrapper shapes simultaneously.

### A.3 Two sidebar generations run side by side

`agent/includes/side_nav.php` and `admin/includes/side_nav.php` emit AdminLTE **4** markup (`aside.app-sidebar`, `.sidebar-menu`, `data-lte-toggle="treeview"`, `.nav-treeview`, `menu-open`).

Four others — `client_side_nav.php`, `client_overview_side_nav.php`, `reports_side_nav.php`, `user_side_nav.php` — still emit AdminLTE **3** markup (`aside.main-sidebar`, `.nav-sidebar`, `data-widget="treeview"`). AdminLTE 4 defines **none** of `.main-sidebar`, `.nav-sidebar`, `.right` or `.sidebar-dark-primary`; their entire appearance comes from hand-written first-party CSS at `css/itflow_bs5_bridge.css:442-533`, including `body:has(.main-sidebar) .app-header { margin-left: 250px }` and an off-canvas media query.

There is one hidden coupling here: bridge line 530, `.sidebar-open .main-sidebar { margin-left: 0 }`, piggybacks on the body class that AdminLTE 4's PushMenu JavaScript sets. Removing AdminLTE's JS therefore breaks **mobile navigation on 79 pages** unless the replacement toggle sets the same class.

Seven navigation files share no code. Each hand-writes markup interleaved with `basename($_SERVER["PHP_SELF"])` comparisons, module flags, `lookupUserPermission()` calls, and — in four of them — raw `mysqli_query()` badge counts. Chrome SQL is not trivial: a global agent page runs about 14 navigation queries; a client-context page about 45.

### A.4 CSS: four token namespaces, three project files, one accidental winner

Load order, emitted by `includes/header.php`: Bootstrap 5.3.3 → AdminLTE 4.0.0-beta3 (which re-ships a **second full copy** of Bootstrap 5.3.3) → five plugin sheets → `itflow_bs5_bridge.css` (557 lines) → `itflow_custom.css` (821) → `itflow_design.css` (400) → an inline per-company accent `<style>`.

| Namespace | Owner | Purpose |
|---|---|---|
| `--color-*` | `itflow_custom.css:2-30` | the original "Alga" palette, 13 tokens |
| `--if-*` | `itflow_design.css:17-56` | 12 tokens + 4 body-level accent aliases onto `--color-*` |
| `--bs-*` | Bootstrap | rebound to `--color-*` by the bridge, re-pinned by the inline accent block |
| `--lte-*` | AdminLTE | six written by the bridge onto `.app-sidebar` |

`itflow_design.css` loads last and wins most component ties, but `itflow_custom.css` retains `.card` by higher specificity (`.card:not(.card-outline)`) and `.card-header` by `!important` — producing **two different card radii in the same application** (14px plain, 12px outline).

`tokens/*.css`, `styles.css`, `ui_kits/*` and `_ds_bundle.js` are entirely dead — reachable only from `ui_kits/itflow/index.html`, never from PHP. About 30 PHP pages carry inline `<style>` blocks; one ~28-line `.info-box.bg-*` block is duplicated verbatim **eight times**.

### A.5 Dark mode has two non-equivalent triggers

`includes/header.php:52` emits `<html data-bs-theme="dark">` and `:144` separately emits `<body class="... dark-mode">`, both from the same server-side boolean. They are not equivalent: `data-bs-theme` drives `--bs-*` and `--if-*`; `body.dark-mode` drives only `--color-*`. AdminLTE 4 contains **zero** `.dark-mode` selectors. The client portal, emitting no `<body>`, never receives the class at all. There is no client-side toggle; theme is a server-rendered decision requiring a page load to change.

### A.6 Charts: 28 inline definitions, hard-coded colours, broken in dark mode

One charting asset is vendored: `plugins/chart.js/chart.umd.min.js`, **Chart.js v4.5.1** UMD (204KB), loaded with `defer` on every authenticated page from `includes/footer.php:56`. No date adapter, no zoom, annotation or streaming plugin.

There are **28 `new Chart()` calls across 13 files** — 9 in `agent/dashboard.php`, 4 in `agent/rmm_dashboard.php`, 15 across `agent/reports/`. Only line, doughnut and bar are used. Every chart is server-rendered inline: PHP `json_encode()`s straight into the JavaScript object literal, and `agent/dashboard.php` runs `mysqli_query()` loops and `CREATE TEMPORARY TABLE` statements *inside* the `<script>` body. **No chart fetches data over AJAX or from `api/v1/`. None uses `data-*` attributes.**

No chart is ever updated, destroyed or re-rendered — there is no `.update()`, `.destroy()` or `Chart.getChart()` anywhere. Every filter performs a full form submit and page reload.

Colour handling is entirely hard-coded: literal Bootstrap-4-era hexes, PHP-side severity maps, database colour columns, and one `md5(rand())` generator. Nothing reads a CSS variable; `getComputedStyle` appears nowhere in the front end. `Chart.defaults.font.family` and `Chart.defaults.color = '#292b2c'` are duplicated verbatim in all 13 files.

The consequence: **charts are visibly broken in dark mode today.** Near-black text and `rgba(0,0,0,.125)` gridlines are drawn onto a `#14201f` surface. This is a live defect independent of either programme.

### A.7 Component library: correct shape, near-zero adoption

`includes/ui/` is a 7-file, ~230-line PHP component library. `components.php` requires the other six and is itself required once at `functions.php:6`, so every portal inherits the helpers automatically. Six render functions exist: `render_page_header`, `render_card_open`/`render_card_close`, `render_stat_card`, `render_status_badge`, `render_priority_badge`, `render_empty_state`.

Adoption is essentially zero — only `agent/reports/index.php` calls them (9 sites). `render_card_open`/`close`, `render_status_badge` and `render_priority_badge` have **no call sites anywhere**.

Architecturally it is in good shape: the helpers emit `.it-*` classes styled against `--if-*` tokens and use **no AdminLTE class at all**. The only Bootstrap classes anywhere in the library are `.breadcrumb`/`.breadcrumb-item`, which Tabler retains. The library would survive a Tabler swap unchanged.

Everything around it is the problem. The application is Bootstrap-4-era markup running on Bootstrap 5 through the 557-line bridge, which re-implements `.form-group`, `.input-group-prepend/-append`, `.close`, `.media`, `.badge-*` and the `.ml-*`/`.mr-*`/`.float-left` utility families. Measured duplication across `agent/` and `admin/` (728 files, ~146k LOC):

| Pattern | Occurrences |
|---|---|
| `class="card` | ~1,762 |
| `btn btn-` | 1,728 |
| `form-group` | 1,531 |
| `input-group-prepend` | 1,170 |
| `card-header` | 387 |
| `card-title` | 337 |
| `modal-header bg-dark` | 327 (across 324 files) |
| hand-written sortable table headers | 292 (across 60 files) |
| `table-responsive` | 229 |
| `card-tools` | 167 |

Only pagination (`includes/filter_footer.php`, 58 pages) and the modal outer shell (`js/ajax_modal.js`) are componentised today.

### A.8 Security and asset-loading mechanics worth carrying forward

A CSP nonce is minted in `includes/header.php` itself and consumed by roughly 100 files. `includes/footer.php` exposes `window.CSP_NONCE` and `window.csrfToken`, and loads 17 deferred `<script>` tags with `filemtime()` cache-busting on first-party JS. `js/ajax_modal.js` hard-codes an AdminLTE class name for its modal host — a small but real coupling. One code path renders `header.php` with no matching footer, leaving the wrapper permanently unclosed.

---

## B. Current AdminLTE dependencies

AdminLTE **v4.0.0-beta3** (`plugins/adminlte4/`, 297KB CSS + 11KB JS) is the shell. A vestigial **AdminLTE 3.2.0** copy (`plugins/adminlte/`, 1.36MB CSS + 46KB JS) also exists, loaded by exactly one page: `setup/index.php`.

AL4 CSS is linked from six places (`includes/header.php:71`, `client/includes/header.php:33`, `guest/includes/guest_header.php:24`, `login.php:729`, `client/login_reset.php:199`, `agent/user/mfa_enforcement.php:48`); AL4 JS from three (`includes/footer.php:66` — inherited by 209 files — plus `login.php:925` and `client/login_reset.php:306`). The client portal loads AL4 CSS but **not** its JS.

### B.1 The JavaScript surface is two behaviours, not six

An exhaustive grep for `data-lte-` outside `plugins/` returns **three hits total**: `data-lte-toggle="sidebar"` (`includes/top_nav.php:8`) and `data-lte-toggle="treeview"` (`agent/includes/side_nav.php:46`, `admin/includes/side_nav.php:15`). There is not one occurrence of `card-collapse`, `card-remove`, `card-maximize`, `fullscreen`, `chat-pane` or `data-lte-icon`. Greps for `adminlte.`, `window.adminlte`, `new PushMenu`, `new Treeview`, `new CardWidget`, `new FullScreen` outside `plugins/` return **zero call sites** — every hit is a tag or a comment.

AdminLTE's bundle exports `CardWidget`, `DirectChat`, `FullScreen`, `Layout`, `PushMenu`, `Treeview`. Three are loaded and never used and can be deleted outright. Only **PushMenu** (sidebar toggle) and **Treeview** (submenu expand) need replacing.

Two traps sit inside those two behaviours:

- **PushMenu reads its breakpoint out of a CSS `::before` content string.** `addSidebarBreakPoint()` calls `getComputedStyle(el, '::before').getPropertyValue('content')` on `<body>` and parses the number out of it. AL4's CSS supplies that value only inside a media query: `@media (max-width:991.98px){ .sidebar-expand-lg::before{ content:"991.98px" } }`. Replace the CSS while keeping the JS and the breakpoint silently becomes `0` — no console error, the sidebar simply stops responding to viewport size. **CSS and JS for the sidebar must move in the same change, or neither.**
- **`data-accordion="false"` is silently ignored.** AL4 beta3's Treeview uses a module-level constant `S={animationSpeed:300,accordion:true}` and never reads the attribute (AdminLTE 3 did). The current accordion behaviour is accidental, not chosen — so a Tabler rewrite is free to pick either behaviour without regressing anything, and the attribute can be stripped from seven files.

Two behaviours users might expect are **not actually present today**, so they cannot be regressions and should not be budgeted for: sidebar collapse state is not persisted (`data-enable-remember="TRUE"` at `top_nav.php:8` is an AdminLTE *3* option; AL4 beta3 contains no persistence code at all), and there is no icon-rail mini sidebar (`<body>` lacks `sidebar-mini`, so the hamburger hides the sidebar entirely rather than shrinking it).

### B.2 Dependency classification

The naive census — 218 PHP files referencing an AdminLTE class — badly overstates the real coupling. Classified by what actually breaks:

**LOAD-BEARING, must be replaced before AL4 CSS is removed**

| Dependency | Footprint | What breaks |
|---|---|---|
| `.app-wrapper` CSS grid + `grid-area` assignments | 6 shell files | Every chrome-bearing page collapses to an unstyled vertical stack. No first-party CSS declares `display:grid` or any `grid-area` for these selectors — this is pure vendor. |
| `sidebar-expand-lg` / `layout-fixed` responsive rules | `<body>` | Responsive sidebar, plus the `::before` breakpoint the JS parses (B.1). |
| `.card-tools` / `.btn-tool` | 117 files / 170 occ; 18 files / 80 occ | **No first-party base definition exists** — only a *patch* at `bridge:217-220` compensating for AL4's `margin:-1rem 0`. Drop AL4 first and that patch applies a −.5rem correction against a zero baseline, pushing icon buttons out of position across ~117 pages. |
| `.card-outline` | 26 files / 35 occ | AL4's `.card.card-outline{border-top:3px solid …}` (specificity 0,2,0) still beats `itflow_design.css`'s `.card{border:1px solid …}` (0,1,0). Easy to misclassify as dead by analogy with `card-dark` — it is not. |
| `.user-menu` dropdown | every page | AL4 supplies the 280px panel width, the suppressed `::after` caret and the `user-header` base; first-party CSS only decorates. ~30 lines to rebuild. |
| `.login-page` / `.login-box` centering | 3 entry pages | Full-viewport centering exists only in AL4. `login.php` overrides width and logo but never re-declares the centering. Small, self-contained, easily forgotten. |
| `.sidebar-open` body class | 79 pages (indirect) | `bridge:530` makes the four AdminLTE-3 sidebars depend on AL4's PushMenu setting this class. Removing AL4 JS without reproducing it strands mobile users with no way to open navigation. |

**ALREADY SELF-HOSTED — the bridge is sufficient, no work needed**

`itflow_bs5_bridge.css` contains complete AdminLTE-3 re-implementations of `.small-box` (:283-290, 9 files), `.info-box` plus a 24-line `.bg-*` recolour block (:292-330, 12 files), `.callout` (:332-334) and `.timeline` (:336-345). Because the bridge loads after AL4, identical single-class selectors win on load order. `.callout` and `.timeline` have **zero consumers** (`class="timeline` has no matches; `callout` matches one HTML comment) — dead code in the bridge that can be deleted. `direct-chat` appears nowhere outside `plugins/`. **Budget no Tabler work for timeline, callout or direct-chat.**

**ALREADY INERT — free deletions, roughly a third of the raw grep hits**

`card-dark` (161 files / 307 occ) is overridden wholesale by `itflow_custom.css:269-300` into a visual no-op — strippable by mechanical `sed` with zero visual change, or simply left in place where Tabler ignores it. Also verified inert: `text-sm` on `<body>` (an AL3 utility; Bootstrap 5.3 defines `.text-sm{` zero times), `layout-top-nav`, `sidebar-dark-primary`, `has-treeview` (7×, defined by neither AL3 nor AL4), `<span class="right badge">` (53 occurrences — placement actually comes from `bridge:459-464`), `data-widget="treeview"` (5 files, no handler), and `itflow_custom.css`'s `.content-wrapper` / `.main-header.navbar` rules which have no matching markup.

Two live bugs surface from this tail: `js/outtake.js:22` appends a loading spinner to `.content-wrapper`, an element that no longer exists in the AL4 shell, so it silently never renders. And **29 AdminLTE-3 `data-card-widget` buttons across 4 pages** (`agent/dashboard.php` ×17, `invoice.php` ×6, `quote.php` ×4, `recurring_invoice.php` ×2) are dead markup — AL4 binds only `data-lte-toggle="card-*"`, and `js/app.js` has no handler either. Users see collapse and X buttons that do nothing.

### B.3 Complications that will not show up in a class census

- **Five pages duplicate the bridge's `.info-box` colour block in page-local `<style>` tags** (`agent/reports/mrr.php:43-66`, `agent/rmm_dashboard.php:456-478`, plus `invoices.php`, `csat.php`, `service_desk.php`, `technician_performance.php`). Any retirement sweep must grep `.php` files for `.info-box`/`.small-box`, not just `.css`.
- **The cascade depends on load order.** Several first-party rules win only because they come later at *equal* specificity. Inserting Tabler at a different position, or removing AL4 so the bridge copies become the sole definition, changes which declarations apply. Verify `small-box`/`info-box` visually rather than assuming the bridge copy is byte-equivalent to what renders today.
- **AL4's overflow model already fights the app.** `admin/api_docs.php:79-93` carries a 15-line comment and an `!important` escape hatch because `.sidebar-expand-*.layout-fixed .app-main{overflow:auto}` makes `.app-main` a scroll container that never scrolls, silently neutering `position:sticky` inside it. Any Tabler shell must be tested against the same failure mode; the one-off `!important` can likely then be deleted.
- **`setup/index.php` is a separate migration island** still on AdminLTE 3.2.0 with its own 1.36MB CSS. Leaving it behind keeps a large vendor dependency alive for one page; porting it means touching the installer, which is the page nobody exercises.

---

## H. Current RMM architecture

### H.1 Provider dispatch

Four providers are dispatched through one factory, `getRmmClient()` in `includes/rmm_client_factory.php`, keyed on `rmm_integrations.type`: `tactical_rmm` (also the fallback), `level`, `action1`, `sophos_central`. Each client is a hand-rolled cURL wrapper. Credentials live encrypted in `rmm_integrations.api_key_enc` — a single key for Tactical and Level, a JSON `{client_id, client_secret}` pair for the two OAuth providers — and are decrypted in the constructor via `decryptSetting()`.

Every provider normalises to a common "agent" array which is fed to `RmmAssetMapper::syncAgents()`. That upserts exactly one row per `(asset_id, integration_id)` into `asset_rmm_links`.

An asset may link to several integrations at once (the unique key is `(asset_id, integration_id)`, not `asset_id`). Where the UI must pick one, it consults a single global boolean, `settings.config_rmm_prefer_tactical` (default 1) — `asset_details.php` orders by `(i.type='tactical_rmm') DESC`, while `rmm_assets.php`, `assets.php` and `rmm_dashboard.php` deduplicate in PHP.

### H.2 Device identity is a four-step fallback chain

`RmmAssetMapper::syncAgent()` matches an incoming agent to an asset in this order:

1. an existing `asset_rmm_links` row on `(integration_id, tactical_agent_id)`;
2. exact `assets.asset_serial` match;
3. MAC address via `asset_interfaces.interface_mac` — but MACs are extracted only from Tactical's `wmi_detail.network_config[].MACAddress`, so this step is Tactical-only in practice;
4. case-insensitive `assets.asset_name` equal to the hostname, and **only when exactly one asset matches**.

If nothing matches it **creates a new asset**, unless the agent carries a client/group name that failed to resolve — in which case it is counted as `skipped`. There is a re-enrollment path: step 4 of the link upsert looks for an existing `(asset_id, integration_id)` row under a *different* `tactical_agent_id` and updates it in place rather than colliding with the unique key.

Notably, `assets` has **no index on `asset_serial`** despite every sync matching on it.

### H.3 Three entry points, none of them scheduled in this install

The sync runs from three places: `cron/cron.php` (gated on `config_module_enable_rmm`), `/opt/scripts/itflow_rmm_sync.php` (flock-guarded, designed for per-minute execution), and the manual AJAX handler `agent/post/rmm_sync.php`.

`cron/cron.php` is a 1,934-line straight-line monolith that runs every block unconditionally, top to bottom, on every invocation. Its only gate is the global `config_enable_cron`. It has **no lock file**, no run-duration instrumentation, no per-block schedule expression and no timing table; logging goes to the unindexed `app_logs` table via `logApp()`.

The admin Cron Manager (`/admin/cron.php`) parses and rewrites exactly one file — `/etc/cron.d/itflow` — via `sudo /usr/bin/tee`, and only ever replaces the single line containing `cron/cron.php`. That file hard-codes `/var/www/itflow.foleyit.com`, **a different install**.

As established in the executive summary: **nothing in `/etc/cron.d`, `/etc/crontab` or any user crontab references this repository.** Its background jobs do not run.

### H.4 Alerting is entirely vendor-driven

Two alert stores exist and ITFlow never evaluates a threshold for either. `rmm_alerts` is populated by exactly **one** INSERT site — `RmmAssetMapper::syncAlert()` from `$rmmClient->getAlerts()`. Its lifecycle is a flat `status` varchar with three values (new / acknowledged / resolved), plus `acknowledged_by`/`_at`, `resolved_at`, `ticket_id` and `automation_processed_at`. Resolution comes from the vendor clearing the alert, a technician clicking acknowledge/resolve (which writes back to Tactical), or the API. `comet_backup_alerts` is a parallel, differently-shaped table written by the Comet webhook and cron poll; its `alert_ticket_id` is `NOT NULL`, so every backup alert must own a ticket.

Alerts become tickets three ways: a severity allow-list in cron (`config_rmm_auto_ticket_severities`), a manual button, and the `create_ticket_from_alert` automation action — all funnelling into `createTicketFromRmmAlert()`.

The rule engine (`ticket_automation_rules`) is one flat AND-ed list of `{field, op, value}` conditions with five triggers (schedule, ticket_created, rmm_alert, asset_offline, asset_online) and five operators (equals, not_equals, greater_than, less_than, contains). **There is no duration, no hysteresis, no cooldown, and no field that carries a metric.** Notifications fan out to every `user_type=1` user; there is no RMM/alert notification category and no email path for any alert.

---

## I. Current RMM providers and capabilities

`RmmAssetMapper::computeHealth()` is the sole extraction point for any performance value in the entire application. What each provider actually supplies today:

| Provider | Live endpoints | CPU | RAM | Disk | Other health | Everything else |
|---|---|---|---|---|---|---|
| **Tactical RMM** | 21 (+5 unmatched) | ✅ `cpu_load`, WMI `LoadPercentage` fallback | ✅ `mem`, WMI `(Total−Free)/Total` fallback | ✅ max of `disks[].percent` | `boot_time`, `needs_reboot`, `maintenance_mode`, `has_patches_pending` | Rich per-volume disk, CPU model, RAM total, GPU **name**, IPs — fetched live, never stored |
| **Level.io** | 3 | ❌ | ❌ | ❌ | `maintenance_mode`, `last_reboot_time` only | CPU/RAM are static *description strings* (model name, total size), not utilisation |
| **Action1** | 0 in use | ❌ | ❌ | ❌ | none | Seven identity fields only. `getAgentWmi()`, `getAgentSoftware()`, `getAgentServices()`, `getAlerts()`, `getScripts()`, `getAgentChecks()` all `return []` |
| **Sophos Central** | 2 firewalls | ❌ | ❌ | ❌ | none | Firewall inventory only (model, firmware, serial, status, last seen, IP) + tenant-wide alerts |

Three details matter for the telemetry design:

**Level.io's omission is deliberate and documented in its own source.** `class_level_rmm.php:386-390` states: *"Level exposes maintenance mode and last reboot time but not live CPU/RAM/disk usage percentages, so those stay NULL."* `getAgentPatches()` returns `[]` unconditionally; `reboot()` and `runCommand()` throw "does not support … via the API".

**Only Tactical ever gets a second API call.** `resolveDetailBundle()` gates the extra detail fetch on `$this->rmmClient instanceof TacticalRmmClient`. No other provider is ever asked for hardware detail.

**Sophos Central's field mapping is self-declared as unverified.** The file header warns that the JSON field names *"could not be verified against a live tenant."*

### I.1 The closest thing to an existing metric feed

`agent/post/rmm_live_data.php` proxies `type=wmi` to `TacticalRmmClient::getAgentWmi()`, returning `make_model`, `cpu_model`, `total_ram`, `disks`, `physical_disks`, `graphics`, `local_ips` and `wmi_detail`. The browser renders `disks[].{device, fstype, used, free, total, percent}` as progress bars. `type=checks` returns Tactical's own cpuload/memory/diskspace check evaluations with `{status, more_info, last_run}`.

**None of it is written to any table.** Every page view re-hits the vendor API. This is rich, per-volume, already-working data that is thrown away on every render — and it is the most obvious first source for a telemetry store.

---

## J. Existing telemetry available

**There is no time-series data in this system. Nothing appends. There is no history table, no rollup and no retention.**

### J.1 The complete performance footprint

Seven columns on `asset_rmm_links`, overwritten in place on every sync:

```
rmm_cpu_percent       int, clamped 0-100    (Tactical only)
rmm_ram_percent       int, clamped 0-100    (Tactical only)
rmm_disk_percent      int, clamped 0-100    (Tactical only, max across volumes)
rmm_needs_reboot      bool
rmm_last_boot         datetime
rmm_maintenance_mode  bool
rmm_patches_pending   int
rmm_health_updated_at datetime              (stamp)
```

The table's unique key is `(asset_id, integration_id)` — exactly one row per asset per integration. The only writer is `RmmAssetMapper::healthSetSql()`, which emits a `SET` fragment embedded in `UPDATE`/`INSERT` statements. Yesterday's value is gone the moment today's sync runs. `raw_data_json` (longtext) holds the full vendor payload and is likewise overwritten each cycle.

### J.2 What does not exist at all

Verified by exhaustive grep across `includes/`, `agent/`, `api/`, `src/` and `scripts/` — **zero hits**: GPU utilisation, VRAM, GPU temperature or power; CPU temperature; fan speed; battery charge or health; network RX/TX throughput or errors; disk read/write throughput, IOPS, active time or SMART; per-process CPU/RAM; load average; uptime as a metric.

The only `graphics` reference is a GPU **name string** passed through Tactical's `getAgentWmi()` and rendered as text. Every `temperature` hit in the codebase is an LLM sampling parameter in `ai_functions.php` / `automation_functions.php`.

The `assets` table itself (29 columns) carries **zero** device-state fields — no last-seen, no online/offline, no CPU/RAM/disk, no uptime. It is pure static inventory.

### J.3 Two traps that read like telemetry and are not

- **`cron/metrics_rollup.php` and `ticket_metrics_daily`** count ticket flow (opened / resolved / closed / backlog). Despite the names, they contain no endpoint metrics.
- **`getRmmHealthReport()`** (`functions.php:2251`) charts alert volume and MTTA/MTTR from `rmm_alerts` — not resource usage.

### J.4 Device identity is not yet stable enough to hang history from

`/var/log/itflow_rmm_sync.log` shows `skipped=5` on **every single minute-by-minute Tactical sync**. Five agents never resolve to an asset. Combined with a mapper that creates a *new* asset row when the serial is blank and the hostname does not uniquely match, and an `assets` table with no index on `asset_serial`, the device-identity model will silently orphan or misattribute history if metrics are keyed naively to `asset_id`.

There is also no referential integrity to protect it: **not one** of the RMM, Intune, Comet or UniFi tables declares a foreign key (real FKs to `assets(asset_id)` exist on only 15 legacy tables), and asset hard-delete (`agent/post/asset.php:228,736`) is a bare `DELETE FROM assets` that orphans every RMM row.

### J.5 Conclusion

The telemetry subsystem starts from zero. Three integers per device, from one of four providers, overwritten every cycle, for a fleet of roughly 26 endpoints of which 21 are covered at all. Whether the richer metric families in the master plan are obtainable from these vendors — or require a collector on the endpoint — is answered in Section T.

---

# PART 2 — PROPOSED FRONTEND ARCHITECTURE

> Tabler findings below were verified against the shipped artifact: `@tabler/core` 1.5.0 was downloaded from the npm registry and `dist/css/tabler.css`, `dist/js/tabler.js` and `dist/css/tabler-themes.css` inspected directly, then cross-checked byte-for-byte against jsDelivr.

## C. AdminLTE → Tabler migration strategy

### C.0 One go/no-go item, first

**Tabler 1.5.0 has a hard browser floor with no graceful degradation.** Measured in `dist/css/tabler.css`: `color-mix()` used **609 times**, `:has()` **251 times**, `light-dark()` **69 times**. `--tblr-body-bg` is literally `light-dark(#fff, #111827)`. Tabler's own documentation states the requirement plainly — Chrome 123+, Firefox 128+, Safari 17.5+, Edge 123+, iOS Safari 17.5+ — and that *"none of them have a fallback, so a browser that misses one drops those declarations and the page renders wrong."*

A browser below the floor does not get a degraded page. It loses the page background.

**Before any money is spent, parse the nginx access logs for User-Agent distribution across all five vhosts.** This is the only genuine go/no-go in the programme, and it costs half a day.

A second, softer caution: `@tabler/core` 1.5.0 was released **2026-09-05** — the day this report was written. It has zero field burn-in, and its release notes reference an "Upgrade to 1.5" breaking-changes guide. Pinning 1.4.0 is *not* a safe substitute: 1.4.0 predates the change that moved Bootstrap into Tabler's own source tree, so its dependency shape is different and was not verified.

### C.1 What Tabler actually costs

| Question | Finding |
|---|---|
| Does it add a third Bootstrap? | **No.** Tabler 1.5.0 vendors Bootstrap 5.3.8 into its own SCSS tree and compiles it under the `--tblr-` prefix. `package.json` lists one runtime dependency (`@popperjs/core`) and no Bootstrap. `tabler.min.css` **replaces both** `bootstrap.min.css` and `adminlte.min.css`. |
| Asset budget | Dropping both (529,961 B raw / 71,099 B gzip) for `tabler.min.css` (691,465 B / 80,188 B) = **+161KB raw, +9KB gzip**. Self-contained: zero `@font-face`, zero non-`data:` URLs. Skip `dist/img/` (3.3MB) and `dist/libs/` entirely. |
| Class collisions | **Almost nil.** Of 1,127 distinct classes used across the app's PHP, only **seven** are styled by Tabler but not by stock Bootstrap: `.icon` (52 uses — the only one that matters), `.btn-outline-purple` (4), `.bg-teal` (3), `.btn-google` (2), `.bg-pink` (2), `.mx-n3` (1), `.bg-orange` (1). |
| Does it break the 1,762 cards? | No — it *restyles* them, uniformly and by design, because Tabler restyles Bootstrap's own `.card`. Base font-size moves 1rem → 0.875rem globally. |
| Bridge survival | **Zero collision** with `.small-box`, `.info-box`, `.main-sidebar`, `.card-tools`, `.btn-tool`, `.card-outline`, `.card-dark` — Tabler defines none of them. The bridge's AdminLTE-3 shims survive untouched. Zero collision with the `.it-*` component namespace. |
| Shell namespaces | Disjoint. Tabler's `.page`/`.page-wrapper`/`.page-body`/`.navbar-vertical` vs AdminLTE's `.app-wrapper`/`.app-header`/`.app-sidebar`/`.app-main`. Neither styles the other's classes. |
| CSP | Clean. Zero `eval(`, zero `new Function(`, zero `document.write`, zero style injection. Four `.innerHTML` assignments, all through Bootstrap's sanitiser. |
| Icons | **Skip Tabler Icons.** `tabler.css` contains zero `.ti` rules and the app uses zero `ti-*` classes — Font Awesome 6 keeps working unchanged inside Tabler markup. Adding the webfont would cost ~673KB for no functional gain. |

**Dark mode is a free win.** Tabler keys off `html[data-bs-theme="dark"]` — precisely what `includes/header.php:52` already server-renders. The app's two non-equivalent triggers (A.5) collapse to one, and the client portal — which emits no `<body>` and therefore never receives `.dark-mode` — starts rendering dark correctly for free.

**One thing breaks silently.** Tabler emits essentially no `--bs-*` properties (one in 800KB). The per-company accent block at `includes/header.php:105-127` pins `--bs-primary`, `--bs-primary-rgb`, `--bs-link-color` and `--bs-link-hover-color` across three selectors. The moment `bootstrap.min.css` is dropped, **the entire branding feature stops working with no error**. Section F resolves this structurally.

### C.2 Recommendation: staged single-shell swap, not dual-shell coexistence

**This report recommends against the dual-shell transition the master plan asks for.** The reasoning is specific rather than aesthetic.

Tabler and AdminLTE *shells* are namespace-disjoint, so per-page shell selection is structurally possible. But Tabler and AdminLTE *components* are not disjoint — both restyle Bootstrap's own `.card`, `.btn`, `.table`, `.form-control` and `.modal-header`, and Tabler moves base font-size globally. The 1,762 cards and 1,728 buttons live in page **bodies**, not shells. The moment `tabler.min.css` is on the page, every page restyles — migrated or not.

Dual-shell therefore buys per-page control over the chrome only, while paying: ~1.2MB of CSS (three Bootstraps), two sidebars maintained in parallel for the duration (the agent sidebar alone is 500+ lines of permission-gated links with live badge counts — every future nav change done twice), a doubled QA surface, and a violation of the plan's own "do not load two themes" rule. It is roughly double the work for a worse intermediate state.

**Put coexistence in deployment instead of in the codebase.** `/var/www/beta-itflow.foleyit.com` already exists on this box. Run each stage there against a database copy, verify, then fast-forward. That yields real side-by-side comparison with no config flag, no two themes on a page, and no long-lived half-migrated branch.

### C.3 The stages

Each stage is a single-commit, instantly revertible flip.

| Stage | What happens | Effort | Rollback |
|---|---|---|---|
| **0 — Browser floor** | Parse nginx UA distribution across all five vhosts. Go/no-go. | 0.5 d | n/a |
| **1 — Own the shell, then delete AdminLTE** | Extend `itflow_bs5_bridge.css` by ~250-300 lines covering the narrow-but-fatal layer (B.2), remove the AdminLTE `<link>`/`<script>` lines from four files, replace `adminlte.min.js` with a ~35-line `js/shell.js`. **Zero markup changes. Zero intended visual change. 218 files untouched.** The app then runs on Bootstrap 5 + bridge — strictly *fewer* stylesheets than today, since AdminLTE re-ships a second Bootstrap. | 4-6 d | `git revert` |
| **2 — Swap Bootstrap for Tabler** | Three `<link>` lines change. Ship `tabler.min.css` **only**; keep `bootstrap.bundle.min.js`; do **not** ship `tabler.min.js`. Add a binding block aliasing `--tblr-*` onto existing tokens. Rename the 46 `class="icon"` divs. This is the visual big-bang. | 3-5 d | `git revert` |
| **3 — Tabler-native shell markup** | Rewrite ~13 files: `header.php`, `top_nav.php`, `inc_wrapper.php`, `footer.php`, four sidebars, the client and guest headers/footers, `login.php`. Collapse the two dark-mode triggers into one. Delete the Stage-1 shim. | 5-7 d | riskiest stage — see below |
| **4 — Bridge retirement** | `.form-group` → `mb-3`, `.input-group-prepend`, `.close`, `.ml-*`/`.mr-*`. Tabler styles none of these, so the bridge keeps owning them indefinitely at zero cost. **Never schedule this.** | — | — |

**Total for Stages 0-3: ~15.5 engineer-days (range 12-19).**

Do **not** ship `tabler.min.js`. It is a Bootstrap re-implementation exporting `window.tabler`, not `window.bootstrap`, and it self-wires the `data-bs-toggle` data-api at load — which would double-wire against the app's 22 existing `window.bootstrap.*` call sites. Keeping `bootstrap.bundle.min.js` means all of them keep working with zero edits, and saves 25KB gzip. The cost is Tabler's folded-sidebar JS, which `js/shell.js` already replaces.

### C.4 Proving parity without a test suite

There is no CI and no tests, so parity verification must be cheap and mechanical. Two throwaway tools, neither adding a build step:

1. **`tools/route_check.php`** — a CLI script walking ~120 routes with an authenticated session cookie, asserting HTTP 200, zero `Warning:`/`Notice:`/`Fatal` in the body, and the presence of expected shell anchors. Run before and after every stage.
2. **A headless-Chrome screenshot differ** over a ~25-page canary set. Canaries should include `agent/dashboard.php` (9 charts, small-box and info-box), `agent/ticket.php` (39 `card-tools`/`btn-tool` references — the densest page in the app), `agent/reports/index.php` (the only `includes/ui/` consumer), a client-overview page (the dead-treeview sidebar), `admin/settings_theme.php` (accent injection), `client/index.php`, one `guest/` page, and `login.php`.

### C.5 Honest risk notes

- **Stage 1's shim is the load-bearing bet.** If those ~250-300 lines miss something AdminLTE was quietly providing, the failure appears as subtle layout drift across 218 files rather than a hard error.
- **Stage 3 is the only stage where partial failure is plausible** — 13 shell files rewritten at once, where a mistake in `includes/footer.php` breaks all 209 pages that require it and a mistake in `client/includes/footer.php` breaks 23 different ones.
- **The 327 `modal-header bg-dark` blocks are the likeliest long tail.** Tabler sets `.modal-header` to transparent with `min-height: 3.5rem`, so the `bg-dark` utility will fight it across 324 files.
- **Nothing in this app has ever been rendered under `tabler.min.css`.** The declaration-level deltas are known; the aggregate visual result is not.
- **This proposal was not adversarially reviewed.** Four of the five riskiest proposals in this report went through a refutation pass; a filter error excluded this one. Treat its effort estimates as unaudited.

`setup/index.php` (AdminLTE 3.2.0) should be scoped out entirely and `plugins/adminlte/` left on disk for it. It is the installer, it runs once, and touching it buys nothing.

## D. Proposed global shell

The shell contract maps one-for-one onto the existing three-file split (A.1), which is why this is tractable:

| File | Today opens | Under Tabler opens |
|---|---|---|
| `includes/header.php` | `<html>`, `<body>`, `.app-wrapper` | `<html>`, `<body>`, `div.page` |
| *(sidebar include)* | `aside.app-sidebar` | `aside.navbar.navbar-vertical.navbar-expand-lg` |
| `includes/inc_wrapper.php` | `main.app-main`, `.app-content`, `.container-fluid` | `.page-wrapper`, `.page-body`, `.container-xl` |
| `includes/footer.php` | closes all six | closes all six |

**The invariant to preserve is nesting depth, not class names.** The agent shell closes `container-fluid / page-body / page-wrapper / page`; the guest shell closes `container / page-body / page-wrapper / page`. Both are four levels. That fact should be written into a comment in `footer.php`, because the shared footer serves both.

The eight `inc_all*.php` orchestrators need no structural change — they continue to require the same files in the same order. Only the three shell files and the sidebars change.

**The client portal is the natural pilot.** Its shell contains no AdminLTE classes at all, yet it loads `adminlte.min.css`; that link can be dropped first, in isolation. Two structural defects should be repaired in the same change: it emits no `<body>` tag and no `</body></html>`, both of which `div.page` needs in order to wrap correctly.

## E. Reusable UI component architecture

### E.1 The premise has to be corrected first

A components programme justified as *"so the Tabler swap works"* rests on a false premise. The Tabler research is unambiguous: a hand-written `<div class="card">` survives the swap intact, because Tabler restyles Bootstrap's own `.card`. Componentising 1,762 cards is not required by the migration and should not be budgeted against it.

There are three real justifications, and each is measurable:

1. **Markup Tabler genuinely breaks.** The 327 `modal-header bg-dark` blocks across 324 files, each also carrying a Bootstrap-4 `.close` button that works only because the bridge shims it. Byte-identical duplication — the ideal codemod target, and the largest visual-cleanup tail in the migration.
2. **Defect surface, not line count.** The 273 hand-written sortable `<th>` anchors each re-derive `$url_query_strings_sort`, `$sort`, `$disp` and `$order_icon` by hand. Every one is an independent opportunity to drop a filter parameter or omit the active-sort icon. The same applies to the 275 `small-box`/`info-box` stat tiles across 18 files.
3. **Permission rendering is currently a performance bug.** `lookupUserPermission()` (`functions.php:3342`) issues a `mysqli_query()` on **every call, with no cache**. There are 224 call sites; `agent/ticket.php` alone calls it **42 times** per render. Routing permission-aware rendering through components is the natural place to memoise it — and that is a real win independent of any redesign.

### E.2 Convention

Keep procedural `render_x()` functions. No templating engine (the plan forbids one), no autoloader change — `functions.php:6` already pulls `includes/ui/components.php` into every entry point, so new components are available everywhere for free.

One flaw in the existing convention blocks everything that follows: `render_card_open($title, $header_actions_html)` takes header actions as a **string**, while `render_status_badge()` **echoes**. You cannot place a badge inside a card header without output buffering. Fix this before adding components — every helper should have a `_str` return-value twin, or all helpers should return strings and a single `ui_out()` echo them.

### E.3 Priority order

Ranked by defect surface eliminated rather than by line count:

| Component | Sites | Why |
|---|---|---|
| `ui_modal_open`/`_close` | 327 in 324 files | The one thing Tabler actually breaks; byte-identical duplication |
| `ui_table_open`/`ui_th`/`ui_table_close` | 273 sort anchors in 60 files | Highest defect density in the app |
| `ui_field`/`ui_select` | ~3,063 | Absorbs `.form-group` + `.input-group-prepend` so Stage 4 never needs scheduling |
| `ui_btn` | ~2,512 | Uniformity; cheap |
| `ui_stat_card` | 275 in 18 files | Retires `small-box`/`info-box` and the eight duplicated `<style>` blocks |
| `ui_card_open`/`_close` | 411 header-bearing cards | Lowest priority — plain cards need nothing |

## F. CSS file architecture

### F.1 Invert the aliasing — one authored namespace

Today `itflow_design.css` declares `--if-primary: var(--color-accent)`, and `--color-accent` is set by the inline accent block, which *also* hand-pins five `--bs-*` properties. That block **is** the per-company branding feature, and it is the thing most likely to die silently under Tabler (C.1).

**Make `--if-*` the only namespace anyone authors.** The inline block stops naming any framework variable and emits six properties on `:root`: `--if-primary`, `--if-primary-rgb`, `--if-primary-hover`, `--if-primary-soft`, `--if-primary-fg`, `--if-radius-scale`. Each framework then gets a thin, disposable *binding* file mapping `--if-*` onto its own prefix:

- `css/itflow.bind-bootstrap.css` — loaded on AdminLTE-shell pages, deleted the day AdminLTE dies
- `css/itflow.bind-tabler.css` — loaded on Tabler-shell pages, permanent

The accent feature then survives the framework swap **by construction**, because nothing in it mentions Bootstrap or Tabler. `--color-*` becomes a pure deprecation shim (`--color-accent: var(--if-primary)`) so the ~500 existing `var(--color-…)` reads keep resolving, then a `sed` sweep deletes it. `--lte-*` is never authored by us and dies with AdminLTE.

**One trap falls straight out of the current code.** `--if-primary` is deliberately declared on `body`, not `:root`, because the dark override lives on `body.dark-mode`. When the dark trigger moves to `:root[data-bs-theme]`, that hack must be removed and the accent tokens hoisted to `:root` — otherwise `getComputedStyle(document.documentElement)` returns an empty string for `--if-primary` and the chart theming layer in Section G reads nothing. **Those two changes belong in the same commit.**

### F.2 Split the bridge on its retirement seam

`itflow_bs5_bridge.css` is 557 lines doing two unrelated jobs with two different retirement dates, which is exactly why nobody can safely delete anything from it:

- **`css/itflow.shim-bs4.css`** — the Bootstrap-4-era markup shim: `.form-group` (1,531), `.input-group-prepend/-append` (1,170), `.close`, `.media`, `.badge-*`, `.btn-block`, `.thead-*`, `.sr-only`, `.float-left`, `.ml-*`/`.mr-*`, `.font-weight-*`. Framework-independent — **Tabler styles none of these**, so this file keeps working indefinitely at zero cost and Stage 4 never has to be scheduled.
- **`css/itflow.shim-adminlte.css`** — `.small-box`, `.info-box` and variants, `.main-sidebar` system, `.card-tools`, `.btn-tool`, `.card-outline`. Retires when the last AdminLTE-generation markup does.

Deleting the four dead files (`tokens/*.css`, `styles.css`, `ui_kits/`, `_ds_bundle.js`) is free and should happen first.

## G. JavaScript module architecture

No bundler is permitted and none is needed. Use **native ES modules** with an import map — the app already emits a per-request CSP nonce, and `<script type="module">` works with `'self'` without inline code. Existing global-scope files continue to load as classic scripts alongside; the two coexist.

The high-value piece is not the module tree, it is the **chart theming layer**, which fixes a live defect (A.6):

```
js/src/charts/theme.js   — reads --if-* / --tblr-* via getComputedStyle, builds a
                           Chart.defaults palette, and re-applies on theme change
js/src/charts/factory.js — one createChart() wrapper; keeps a registry so every
                           chart can be repainted rather than page-reloaded
```

Tabler ships `window.tabler.getColor(name)` — `getComputedStyle(document.body).getPropertyValue('--tblr-' + name)` — which is exactly the CSS-variable read the 13 chart files lack. Even without shipping `tabler.min.js`, that is four lines to reimplement.

Migrating the 28 charts is mechanical and can proceed one file at a time: delete the two duplicated `Chart.defaults` lines, replace the literal hex arrays with palette lookups, and route construction through `createChart()`. **This should be paired with the Stage-2 Tabler swap**, because that is the moment the dark-mode defect becomes most visible — and it is a genuine user-facing bug fix that costs about a day.

---

# PART 3 — PROPOSED TELEMETRY SUBSYSTEM

## T. GPU and extended telemetry feasibility

**This section is placed first because every other section in Part 3 depends on its answer, and the answer is not the one the master plan assumes.**

### T.1 Tactical RMM is a state-snapshot RMM, not a metrics platform

Verified against the Tactical RMM source (`amidaware/tacticalrmm` v0.9.0–v1.5.2 and `amidaware/rmmagent`):

**Two findings invalidate assumptions in the current integration.**

First: **`cpu_load` and `mem` — the fields `class_rmm_asset_mapper.php:549-550` reads — do not exist on the Tactical Agent model or serializer in any release from v0.9.0 through v1.5.2.** Every CPU and RAM percentage ITFlow displays today therefore comes from the WMI fallback path (`wmiCpuPercent()`/`wmiRamPercent()`), not the primary path the code appears to prefer.

Second: **that WMI blob is refreshed by the agent only every 3,000–4,000 seconds** (~50–67 minutes), and the `disks` array only every 1,000–2,000 seconds (~17–33 minutes). The per-minute sync currently running against the other install is re-reading data that is, on average, **half an hour stale**. The system is manufacturing false freshness today.

Tactical exposes exactly one time-series store: `checks.CheckHistory`, holding a single integer per sample, and only for `cpuload` / `memory` / `diskspace` check types (plus 0/1 pass-fail for script/ping/service checks). It is retrieved one HTTP call per check per agent, its `timeFilter` is denominated in **days** with no sub-day option, and the server default prunes it at 30 days.

There is **no metrics endpoint, no per-core CPU, no GPU utilisation, no temperature, no battery, no disk IOPS or throughput, and no network throughput** anywhere in the agent's collection code.

### T.2 The other three vendors supply nothing — with one free correction

Verified against the machine-readable OpenAPI specifications for Level v2, Sophos Central (firewall-v1 / endpoint-v1 / common-v1) and Action1 3.0. **None exposes live performance-utilisation telemetry, and none exposes historical data of any kind.** This is a vendor limitation, not a verification gap.

Three practical consequences:

- **Level.io's own code comment is wrong about disk.** `GET /v2/devices?include_disks=true` returns `disk_partitions[].size` and `.free_space`, from which `rmm_disk_percent` is directly computable. **The current client never sends any `include_*` flag, so it is discarding data the API will give it for free.** Level also supplies `last_reboot_time` (uptime) and `total_memory`. The client additionally mis-calls the API in three ways a spec diff makes obvious: it sends `per_page`/`page` where Level v2 takes `limit`/`starting_after`, and reads `meta.next_page` where responses carry `has_more`. Separately, `POST /v2/alerts/{id}/resolve` now exists, so the client's "not supported" exception for resolve is stale.
- **Sophos firewall telemetry is a build-or-drop decision, not an integration decision.** The Central Firewall Management API has 18 routes, all configuration/firmware/threat-feed, with zero performance fields. The only source of firewall CPU/memory/interface counters is **SNMP directly against the appliance** (SFOS-FIREWALL-MIB + IF-MIB) — out-of-band from Sophos Central entirely, requiring new credential storage and network-reachability assumptions.
- **Anything beyond that must be manufactured by each vendor's own scripting engine** and read back out of a custom field or report. Level and Action1 both support this properly; Sophos does not. That is a materially different project from writing an API poller — it means shipping and maintaining scripts inside three separate vendor automation engines.

### T.3 Two metric families should be dropped from the plan

**CPU temperature is effectively unobtainable on a commodity Windows fleet.** `MSAcpi_ThermalZoneTemperature` returns "Not Supported" on most machines and always inside VMs, and reports an ACPI zone rather than die temperature. `windows_exporter` **deprecated its thermalzone collector on 2025-09-26**. Real die temperature requires a WinRing0-class kernel driver, which Microsoft Defender flags as `HackTool:Win32/Winring0` and treats as a vulnerable driver. Shipping that to endpoints is not defensible.

**GPU utilisation is obtainable only on discrete NVIDIA hardware**, via `nvidia-smi`/NVML. Intel integrated graphics — likely the majority of this fleet — and AMD have no free CLI equivalent. The Windows `GPU Engine\Utilization Percentage` counter is per-process-per-engine and must be summed, with no total available.

**Recommendation: remove CPU temperature entirely from the plan, and reclassify GPU as NVIDIA-only opportunistic.** Build no UI and no alert rule that assumes either. This is the single largest scope reduction available and it costs nothing real — the metrics were never obtainable.

### T.4 Do not build a first-party agent

A first-party ITFlow collector agent is a **3–6 month programme** with permanent ongoing cost: code signing, auto-update, antivirus false-positive management, secure enrollment, and a security review burden — for a **26-endpoint fleet**. That is the wrong trade.

**Recommended path instead, in two stages:**

**Stage A — script check, no new software.** Write one PowerShell collector (~150 lines) and register it in Tactical RMM as a Script Check on a 300-second interval across the 21 Tactical-managed endpoints. `class_tactical_rmm.php` already has `runScript()`, `runCommand()`, `createCheck()`, `getAgentChecks()` and `getScriptRunResult()` — the plumbing exists. It gathers per-core CPU, memory, per-volume disk, PhysicalDisk IOPS/queue/latency, per-NIC bytes, uptime, pending reboot and top-5 processes, and POSTs them to ITFlow.

**Stage B — Telegraf, if Stage A proves insufficient.** Telegraf is MIT-licensed, a single static Go binary with no Node or build step, and — decisively — it **pushes**. `windows_exporter` and Zabbix are pull models that would need PushProx or firewall exceptions on roaming laptops. Tactical's script execution becomes the one-time deployment vehicle; Telegraf's `outputs.http` posts batch JSON to the ITFlow ingest endpoint.

Reliably obtainable this way: CPU total and per-core, RAM, per-volume disk usage, per-physical-disk IOPS/queue/latency, per-adapter network throughput and errors, uptime, top-N processes, battery charge and wear. Partially: GPU (NVIDIA only). Not at all: CPU temperature.

### T.5 The consequence for retention

**Because no vendor stores history, ITFlow's own table is the only history that will ever exist.** There is no backfill. If collection is down for a day, that day is permanently gone for all four providers. Retention and rollup policy are therefore load-bearing rather than nice-to-have, and collection gaps must be visible in the UI rather than interpolated over.

## K. Canonical metric registry

The registry must not promise metrics no available source can supply. Given Section T, metrics fall into three honest tiers:

| Tier | Definition | Contents |
|---|---|---|
| **1 — deliverable now** | From existing provider fields, no new deployment | `cpu.utilization`, `memory.utilization`, `disk.utilization` (per volume), `system.uptime_seconds`, `system.pending_reboot` — Tactical for 21 devices; disk + uptime for Level's 3 once the client is fixed |
| **2 — deliverable with a collector** | Stage A/B of T.4 | per-core CPU, `memory.used_bytes`/`available_bytes`, per-volume free/total, `disk.read_iops`/`write_iops`/`queue_length`/`latency`, `network.rx_bytes_per_s`/`tx_bytes_per_s`/`errors`, `battery.charge_percent`/`health_percent`, top-N processes |
| **3 — opportunistic or never** | Hardware/driver dependent | `gpu.utilization`, `gpu.memory_used_bytes`, `gpu.temperature` — **NVIDIA discrete only**. `cpu.temperature` — **drop entirely** |

Each metric defines: canonical key, display name, unit, kind (**gauge** vs **counter** — counters such as network and disk bytes must be rate-derived, and this distinction must exist in the registry from day one), value range, aggregation semantics, chart type, display precision, and whether it is dimensioned (per-core / per-volume / per-adapter / per-GPU) and by what key.

**Capability detection must distinguish three states that are easy to conflate and must never look alike in the UI:** *this device does not support this metric*, *collection failed this cycle*, and *the device is offline*. A missing GPU renders as no card; a failed collection renders as a gap; an offline device renders as offline.

> **Naming — act on this before any code is written.** "Telemetry" is **already a shipped, unrelated feature**: `config_telemetry` exists today as a settings column with values Disabled/Basic/Detailed, surfaced at `admin/settings_telemetry.php`, read at `load_global_settings.php:174`, seeded by `setup/index.php:678`, and added in `admin/database_updates.php:455`. It means anonymous phone-home usage reporting. Naming the device-metrics subsystem `telemetry_*` would make "telemetry disabled" ambiguous in settings, filenames and URL space forever. **Use `Metrics` throughout**: `src/Metrics/`, `api/v1/metrics.php`, `device_metric_*` tables, `config_enable_device_metrics`. This costs nothing now and is not cheaply fixable after six tables ship.

## L. Provider interface — and M. Ingestion flow

The interface should compose the existing RMM clients rather than duplicating them, declare capabilities as data (so a provider supplying nothing is a first-class, non-broken state rather than an exception path), and never create an asset — `RmmAssetMapper` must remain the sole owner of `assets` and `asset_rmm_links`.

**This proposal failed adversarial review with fatal objections.** The architectural instincts are sound and worth keeping; the execution as drafted does not survive contact with this server. The defects a competent implementer would hit, all of which must be fixed before code is written:

1. **The idempotency claim is false on this server, and it was tested.** The proposed unique key `(asset_id, metric_key, dim, observed_at, source, fingerprint)` does not dedupe, because `fingerprint` is nullable and is NULL on essentially every sample. On the live MariaDB 10.11.14, three identical `INSERT IGNORE` statements against a UNIQUE key containing a NULL column produced **three rows** — MariaDB, like MySQL, treats NULLs as distinct in a unique index. That single flaw takes down three load-bearing claims at once: structural idempotency, "replay protection needs no nonce table", and the fix for re-reading a stale blob. **Fix:** make the identity a `NOT NULL` hash column and make *that* the unique key — narrower index, no NULL semantics — and write a test asserting `affected_rows == 0` on a repeated batch before anything ships.
2. **The collector re-fetches a full day of history every cycle.** Tactical's `timeFilter` is denominated in days with no sub-day option, and the draft never filters returned points by `$since`. At a 5-minute cadence that is ~84 history calls returning ~60,000 points per cycle to discover ~150 new ones — roughly 66× the proposal's own arithmetic, and combined with the dedupe failure, ~17M duplicate rows/day instead of 262k.
3. **Counter state is persisted before validation**, so a rejected out-of-range reading still becomes the next cycle's baseline, poisoning the following rate calculation.
4. **PSR-4 violations.** `composer.json` maps `ITFlow\` to `src/`, so eight value classes stuffed into one interface file will not autoload; one referenced class (`RejectedSample`) is never defined at all; and only `includes/redis_functions.php` requires `vendor/autoload.php` anywhere, so a standalone CLI runner must require it explicitly.
5. **The push endpoint as drafted is a decompression bomb.** `api/v1/index.php:158-166` reads and `json_decode`s the entire request body *before* routing whenever no Bearer token matched — which is exactly what a device token does. Underneath, nginx sets `client_max_body_size 500M` with no per-location override, so 500MB is buffered to disk before PHP checks anything. And nginx has no request-body gunzip, so a 256KB gzip body can expand to hundreds of MB inside PHP. **Fix:** route the ingest endpoint *above* the Bearer lookup with its own early exit, add a `location` block capping the body at 512KB, and either stream decompression with an explicit byte budget or drop gzip entirely — 26 devices posting a few KB do not need it.

**Device identity must be resolved before ingestion is designed**, not after. Metrics keyed naively to `asset_id` will orphan or misattribute history given the `skipped=5` condition and a mapper that creates new asset rows on hostname mismatch (J.4).

## N. Time-series schema — and O. Retention and rollup

### N.1 Sizing, from the real fleet

A realistic Windows endpoint under the Stage-A collector emits roughly **40 distinct series**: CPU total plus 8 cores, 4 memory values, ~5 across 2 volumes, ~3 across 2 NICs, uptime, battery charge and wear, plus GPU where NVIDIA is present. Blended across 21 Tactical endpoints (~40 each), 3 Level devices (~2 each), and 2 Sophos firewalls via a later SNMP collector (~10 each), the fleet is **~1,000 active series**.

**That number, not the device count, sizes everything.**

| Cadence | Raw rows/day | Storage/day at ~85 B/row |
|---|---|---|
| 5-minute | 288,000 | **24.5 MB** |
| 1-minute | 1,440,000 | 122 MB |

**Build ingest for 5 minutes and make 1 minute a configuration change.** The sources do not justify faster: Tactical's WMI blob refreshes every 50–67 minutes, its disk array every 17–33, and a script check's practical floor is the agent's 120-second default plus jitter. A 1-minute poll manufactures false freshness — which is already this system's bug (T.1) and the new schema should not inherit it.

**Partitioning is not warranted at this scale.** It becomes worth revisiting at roughly 10× the series count or once raw retention exceeds a few hundred million rows — state that as the trigger rather than adopting it now.

### N.2 The core schema decisions

- **No surrogate key.** The primary key is the composite `(asset_id, metric_id, instance_id, sampled_at)`, with one secondary index on `sampled_at`. Every chart read is "one device, one metric, one instance, one time range", which under a series-clustered PK is a contiguous leaf-page scan with no secondary lookup. The usual objection — that inserts scatter across 1,000 clustering positions — is real at 100,000 series and meaningless at 1,000, where the hot right-edge pages total ~16 MB and stay resident. Dropping the BIGINT id and the secondary index it would have required saves ~35% of total storage, and the PK doubles as the idempotency key.
- **Metric names and dimensions are catalogued, not repeated.** `device_metric_defs` holds a 2-byte `metric_id`; `device_metric_instances` is keyed `(asset_id, metric_dim, instance_key)` where `metric_dim` ∈ `volume|nic|core|gpu|disk|battery`, with `instance_id = 0` reserved as the host-level sentinel. Putting an adapter name in 288,000 rows/day would cost more than the value column itself.
- **`metric_value` is DOUBLE.** FLOAT's 24-bit mantissa loses precision on byte counters; DECIMAL is variable-width and slow for no benefit; scaled integers are a permanent per-metric footgun. Four extra bytes costs 1.2 MB/day.
- **`sampled_at` is DATETIME in UTC** — not TIMESTAMP (2038, and session-timezone-dependent reads would silently shift history), not an epoch int (232 tables use DATETIME; the next person writing an ad-hoc report will misread it). UTC is a deliberate divergence from the app's local-time convention and **must be commented in both `db.sql` and the ingest handler**. It buys DST-safe bucketing via pure field extraction.

Rollups (15-minute → hourly → daily) carry min/max/**sum and count** rather than a precomputed average, so tiers can be re-aggregated without compounding error. Rollup jobs must be idempotent and able to catch up after downtime, tracked by a `rollup_state` watermark.

Retention: raw 7–14 days, 15-minute 30–90 days, hourly 12–24 months, daily indefinitely. **Pruning must be chunked** — large `DELETE`s on InnoDB hold locks and there is no partitioning to drop.

### N.3 Review status

This proposal was adversarially reviewed and **did not hold up as written** (severity: serious). The reviewer accepted the series-clustered PK at this scale, sum+count over avg, DOUBLE, DATETIME-UTC, and deferring partitioning — but found three defects requiring correction before implementation. Treat the shape as sound and the DDL as a draft.

## P. Metrics API

Build it in **`api/v1`, not `api/v2`.** This is not a stylistic choice: `api/v2` has no rate limiting, no permission checks, and does not include `api_db` or `api_permissions` at all. Putting a high-volume write path there would be the single worst security decision available in this project.

The API needs three endpoint shapes: a single-metric query, a **batch** endpoint (so a device page with eight charts issues one request rather than eight), and a **capability/discovery** endpoint so the UI knows which metric cards to render for a given device.

Parameters: metric key(s), `from`/`to`, `resolution` including `auto`, and dimension filters. `auto` must map a requested range deterministically onto a rollup tier, and that mapping should be documented rather than implicit.

Two things the response shape must get right: **gaps must be representable** so charts draw a break instead of interpolating a lie across an offline period, and **units and capability must travel with the data** so the client never has to hard-code them.

Hard limits are required by the plan's own performance target ("no chart query returning millions of raw rows"): cap points per response, maximum range, and metrics per batch, and return a clear error rather than truncating silently.

**Authorisation is currently broken for this surface.** Whichever module gates metrics must actually exist in the `modules` catalog — see Section X.

## Q. Live metrics over SSE

**Recommendation: do not add a fourth SSE stream. Poll the batch metrics endpoint instead.**

The capacity arithmetic decides it. There is **one PHP-FPM pool with `pm.max_children = 100` shared across four ITFlow vhosts**, and each open SSE connection pins a worker for its full duration — the existing ticket stream holds a worker ~94% of the time. Collection cadence is 60–300 seconds. Streaming a value that changes every five minutes over a connection that occupies a worker continuously is a poor trade: a handful of technicians with device pages open would consume a visible fraction of the pool that four separate applications share.

A 30–60 second poll of the batch endpoint delivers the same perceived freshness, holds a worker for milliseconds, degrades gracefully, and needs no new infrastructure.

If live streaming is nonetheless pursued later, one existing defect must be fixed first: **all four installs publish to the same unprefixed Redis channels** (`ticket.{id}`, `user.{id}.notifications`), which is a genuine cross-instance collision. Any new channel must be namespaced per install.

Historical chart data should never come over SSE — the plan says so and that is correct.

This proposal was adversarially reviewed. The headline call (poll, don't stream) was **upheld**; the concrete artifacts were **not** implementable as drafted, primarily because `api/v1` has no session-cookie authentication path, so a browser polling it needs a token strategy the draft did not supply.

---

# PART 4 — PROPOSED PRODUCT SURFACES

## R. Device performance page

**Performance should be a tab in the existing RMM strip, not a separate page — but landing it must be paired with extracting that strip out of `agent/asset_details.php` into partials. Those are one decision, not two.**

The user-facing argument for a tab: performance is an attribute of a device, and a technician reading a ticket wants CPU history under the same breadcrumb, identity column and action bar as the serial number and the reboot button. A separate page would need its own identity header, its own client-scope enforcement and its own back-navigation, and would drift immediately.

The maintainability argument only runs the other way if the tab is written inline — and it must not be. Measured against the current 2,266-line file: lines 676–1105 are the RMM tab markup (430 lines) and lines 1919–2262 are its JavaScript (344 lines). That is **774 lines, 34% of the file**, all inside one `if ($rmm_link)` guard and all independent of the identity, documents, files and services sections around it. Extract it to `agent/includes/asset/rmm_tab_strip.php` + `rmm_tab_panes.php` and `agent/js/asset_rmm.js`, and `asset_details.php` drops to roughly 1,490 lines. Performance then arrives as one `<li>` and one `require` — **two lines added to a file that is smaller after the work than before it.**

That is the real justification: not that a tab is cheaper than a page, but that the extraction which makes a tab cheap is the refactor the file already needed, and it is a cut-and-paste with no logic change.

One cost to name: moving inline JS out of a `<script nonce>` block loses its PHP interpolation, so `csrf_token`, `link_id` and similar must move to `data-` attributes on a container element.

**The page must look deliberate for a device with almost no data.** Level devices will have disk and uptime only; Sophos entries are firewalls. Capability detection (Section K) drives which cards render at all — a device without a GPU shows no GPU card, rather than a card reading zero. Empty and first-run states need designing, not just an absent chart.

Chart interaction should ship with range presets rather than free zoom. Real zoom requires vendoring `chartjs-plugin-zoom`, which is a new dependency for a feature that presets cover at this scale.

## S. Fleet performance page

**The governing constraint: 21 of 26 devices report CPU and RAM, 3 report almost nothing, and 2 report nothing at all. Any design that leads with a fleet average is lying by construction.** "Fleet CPU 34%" computed over 21 of 26 devices is not a fleet number — and when a Tactical agent goes offline, the same average silently *improves*.

The organising rule for the whole page: **the headline number on every card is a count of devices in a named state, not an average, and no aggregate renders without its denominator.** This should be enforced in the helper signature rather than by convention — `render_fleet_metric_card()` taking `$n_reporting` and `$n_total` as required arguments means a caller physically cannot render "34%" without also rendering "21 / 26 reporting". Conventions get forgotten; a required parameter does not.

The page (`agent/rmm_performance.php`, gated on `module_rmm`) leads with six pressure cards — CPU, memory, low disk, thermal, offline, pending reboot — each showing a count, its denominator, and a link into a filtered device list.

**Pressure must be duration-aware, not instantaneous.** A device counts as under CPU pressure when the hourly average is at or above threshold across the last three complete buckets — not when one sample crossed a line. This mirrors the alerting model in Section U and avoids a page that flickers with every collection cycle.

For the device-list sparklines the plan asks for: fetch all series in **one** query against the rollup tier and render inline SVG rather than instantiating 26 Chart.js instances. Chart.js is the wrong tool for a 60-pixel table sparkline.

## U. Alert and condition integration

**Build a dedicated metric-alert evaluator on the input side, and reuse the existing RMM alert plumbing verbatim on the output side.** The evaluator's job ends the moment it inserts a row into `rmm_alerts`; everything downstream already works and inherits metric alerts with near-zero new code — the `rmm_alert` automation trigger, `config_rmm_auto_ticket_severities` auto-ticketing, `createTicketFromRmmAlert()`, the "Linked RMM Alerts" ticket sidebar card, `agent/post/rmm_alert.php` and `api/v1/alerts.php`.

**Do not extend `ticket_automation_rules`.** Three reasons, each verifiable:

1. Its evaluator (`automationConditionsMatch()`) compares numbers but has no concept of a time window, a previous value, or state carried between runs. Adding one means rewriting the function every existing rule depends on.
2. Every one of its five triggers is gated by an `automation_processed_at` watermark on **a source row that already exists**. "CPU above 95% for 15 minutes" has no source row — the event does not exist until an evaluator invents it. That inversion is the entire design problem.
3. All thirteen of its actions are ticket-shaped. A metric rule needs exactly one action — raise an alert — and then wants the ticket-shaped actions to run *afterwards*, which is precisely what routing through `rmm_alerts` provides.

The rule model needs metric key, dimension, operator, threshold, **sustained duration**, severity, scope, **recovery threshold with hysteresis**, and **cooldown**. State is carried in its own table as a firing/pending/resolved machine.

**Duration-based conditions are incompatible with the current scheduling reality.** An hourly cron cannot evaluate "for 15 minutes" meaningfully, and this install has no cron at all. The evaluator needs a cadence at or below its shortest supported duration — which makes Section Z's scheduling work a hard prerequisite, not a parallel track.

## V. Ticket integration

The linkage already exists and is richer than expected: `tickets` carries `ticket_asset_id` as a primary asset plus a `ticket_assets` join table (with real foreign keys and `ON DELETE CASCADE`) for additional assets. `agent/ticket.php` already renders a minimal RMM strip — hostname, status, OS, last seen, user — for the primary asset, in the **main** column rather than the sidebar.

A device-health panel is therefore an extension of an existing block, not new plumbing. It should show current values with their collection timestamp, a small sparkline per family, and a link to the Performance tab.

Two cases must be handled explicitly rather than assumed away: a ticket may reference an asset with **no RMM counterpart at all** (the panel must be absent, not empty), and both asset selectors filter by `asset_client_id = $client_id`, so **a ticket with no department cannot pick an asset**.

When a ticket is created from a metric alert, capture the metric context — metric key, threshold, duration, start time, peak value and device — into the ticket body so the ticket remains meaningful after the alert is pruned.

---

# PART 5 — EXECUTION

## W. Database migrations required

Any schema change lands **twice** — `db.sql` for fresh installs and `admin/database_updates.php` for existing ones — and that file carries three confirmed hazards that must be addressed before adding to it.

**Hazard 1 — the version gate compares strings.** `admin/database_updates.php:16` uses PHP `>` on version strings, so `"2.6.73" > "2.6.9"` is `false` and an install parked at 2.6.8 or 2.6.9 is permanently stranded. **`admin/update.php:47` contains the identical comparison** and controls whether the update button renders at all — so fixing only the first leaves the web path dead. Both must move to `version_compare()` in the same change.

**Hazard 2 — one step per invocation.** `CURRENT_DATABASE_VERSION` is a constant fixed for the request and the blocks are sequential `if`s, so exactly one version advances per run. Multi-version upgrades require repeated runs of `admin/update.php` or `scripts/update_cli.php --update_db`.

**Hazard 3 — silent failure.** Of 1,420 `mysqli_query()` calls in the migration file, **two** check for errors. There are no transactions and no `mysqli_report()`, so a failed DDL is silent *and the version stamp still advances*. Note the tempting fix is worse than the disease: a helper that throws on failure produces an uncaught fatal in both callers (`admin/post/update.php:297` and `scripts/update_cli.php:117` both bare-`require` the file), and because blocks are not idempotent and there is no transaction, a retry re-runs the statements that already succeeded. Error handling must be added together with per-block idempotency, not before it.

**A trap specific to the module-seeding fix (Section X).** `db.sql` contains exactly **one** `INSERT INTO` statement in the entire file (into `ticket_saved_views`); `CREATE TABLE modules` has no seed data. Modules are seeded by `setup/index.php` *after* it imports `db.sql`. So "append to the modules INSERT set in db.sql" is not a change that can be made — there is no such set — and adding one would collide with the installer's own seeding on every fresh install. The fix belongs in `setup/index.php` (for new installs) and a migration block (for existing ones), with `INSERT ... ON DUPLICATE KEY UPDATE` or a `NOT EXISTS` guard.

Proposed new objects for the metrics subsystem, at version 2.6.74: `device_metric_defs`, `device_metric_instances`, `device_metric_samples`, `device_metric_rollups`, `device_metric_rollup_state`, plus settings columns for cadence, retention and enablement. Also recommended in the same window: an index on `assets.asset_serial`, which every RMM sync matches on and which has none today.

## X. Security risks

**X.1 — The RMM feature set is admin-only today, and it is worse than that.** Six module names gate **77 call sites** (`module_rmm` ×22, `module_rmm_alerts_ack` ×21, `module_rmm_scripts` ×11, `module_rmm_alerts` ×9, `module_rmm_sync` ×8, `module_rmm_remote_connect` ×6). None is seeded by `setup/index.php`, `scripts/setup_cli.php` or any migration — only seven module names are ever seeded. `lookupUserPermission()` finds no row and returns `false`, so every non-admin is denied. Admins never notice because they bypass the lookup entirely.

Direct query of the live `midwest_itflow` database shows the `modules` table holds **six** rows — `module_client`, `module_support`, `module_credential`, `module_sales`, `module_financial`, `module_reporting`. **`module_kb` is also absent**, so the knowledge base is admin-only on this install too, while the install is stamped `config_current_database_version = 2.6.73`. That is provable schema/data drift today, and it is invisible to a `SHOW CREATE TABLE`-based drift checker because it is row data, not schema.

**This should be fixed independently of both programmes, and it is small.**

**X.2 — Remote actions already exist and are only partly audited.** Reboot, arbitrary shell and PowerShell execution, patch scan/install, script execution, remote-session URL minting and vendor check management are all live today (`agent/post/rmm_action.php`, `rmm_script_run.php`, `rmm_remote.php`, `rmm_check.php`). There is no shutdown, process-kill, service-control or uninstall capability. RMM events are logged via `logAction()` into the `logs` table; `audit_events` (via `AuditService`) has 15 event types, **none of them RMM**, and **no reader anywhere in the application**. Any new remote capability should write to a surface someone can actually read.

**X.3 — A metrics ingest endpoint is new attack surface.** It needs its own token class (device enrollment tokens, not user Bearer tokens), strict payload validation, per-device rate limiting, and the structural fixes in Section L.5 — routing above the pre-auth body read, an nginx body cap, and either bounded decompression or no gzip.

**X.4 — Two existing fail-open behaviours.** The API rate limiter fails open when Redis is down, and `api_client_scope_ok()` treats a user with zero `user_client_permissions` rows as unrestricted. Both are defensible defaults that should be conscious rather than inherited.

**X.5 — `api/v2` has no permission checks and no rate limiting at all.** It should not receive new endpoints until that is remedied.

**X.6 — Privacy.** Per-process and per-device telemetry reveals individual user activity patterns. Top-N process collection in particular should be justified, scoped and retention-limited, or omitted. The plan's own instruction — collect only what has operational value — should be enforced at the registry level.

## Y. Migration risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Browser floor excludes real users** (Tabler needs Chrome 123+/FF 128+/Safari 17.5+, no fallbacks) | Unknown until measured | Project-ending for the frontend track | Stage 0: parse nginx UA logs across all five vhosts before spending |
| **Tabler 1.5.0 has zero field burn-in** (released 2026-09-05) | Certain | Unknown-unknowns | Pin the exact version, vendor from tarball, read the 1.5 upgrade guide |
| **Stage-1 shim misses something AdminLTE quietly provided** | Moderate | Subtle layout drift across 218 files | Screenshot differ over the 25-page canary set |
| **Stage 3 partial failure** (13 shell files at once; a footer mistake breaks 209 pages) | Moderate | App-wide outage | Single commit, `git revert`, nesting-depth invariant documented |
| **No test suite, no CI, live web root** | Certain | Every intermediate state is served to production | Route-check script + canary screenshots; stage on beta first |
| **Migration stamps version despite failed DDL** | Already true | Silent half-migrated installs | Fix `version_compare` in both files; add error checking *with* idempotency |
| **`db.sql` / `database_updates.php` drift** | Confirmed in both directions | Fresh and upgraded installs diverge | Schema drift checker — but note it cannot catch row-data drift like the missing modules |

**Two mitigations named in the source plan do not exist and must not be relied upon.**

First, **`/var/www/itflow-staging` is not a git repository** — there is no branch to deploy to it. It runs a different database (`itflow_staging`) on `127.0.0.1:8899` with its own database version. Any "test on staging first" mitigation needs a staging environment to be *created*, and `/var/www/beta-itflow.foleyit.com` (which does have cron and is a real vhost) is the better candidate.

Second, and most important: **the telemetry track cannot be verified in this repository at all.** Direct queries against `midwest_itflow` return `assets = 0`, `asset_rmm_links = 0`, `rmm_integrations = 0`, `rmm_alerts = 0`. Every verification step in a telemetry phase plan — "EXPLAIN shows the serial index is used", "one sync cycle logs five named skips", "Level devices now report a disk percentage" — has nothing to run against. The 21 Tactical agents live in the other install's database.

## Z. Phased implementation order

**Phase 0 — Decide install ownership. Blocking.**
Nothing else in the telemetry track can be scheduled until it is settled whether metrics belong to `/var/www/mw-itflow.foleyit.com` (empty, no cron) or `/var/www/itflow.foleyit.com` (live data, real cron, the target of the per-minute sync). If the answer is "here", the plan must additionally cover duplicating Tactical credentials, doubling Tactical API load, and preventing two installs from both provisioning checks on the same 21 production agents. **This is a decision, not a risk.**

**Phase 1 — Cheap fixes that need neither programme (~2–3 days).** These deliver value immediately and are independent of every decision above:
- Seed the six `module_rmm*` modules **and `module_kb`** (X.1) — via `setup/index.php` plus a guarded migration block, not `db.sql`.
- Fix `version_compare()` in **both** `admin/database_updates.php:16` and `admin/update.php:47` (W).
- Fix the Chart.js dark-mode defect: one shared theming module replacing 13 duplicated `Chart.defaults` blocks (A.6, G).
- Fix the Level.io client's three API mis-calls and enable `include_disks` — this alone gives 3 devices real disk metrics for roughly an hour's work (T.2).
- Delete the four dead CSS/JS files and the 29 dead `data-card-widget` buttons.
- Add the missing index on `assets.asset_serial`.

**Phase 2 — Device identity (~2–3 days).** Resolve the `skipped=5` condition and decide the durable device key before any history is written against it (J.4). History keyed to an unstable identity is worse than no history.

**Phase 3 — Metrics foundation (~8–12 days).** Registry, schema, ingest, rollups, retention, metrics API. Ship the Tactical script-check collector (T.4 Stage A) rather than a first-party agent. Correct the fatal defects in Section L before writing provider code.

**Phase 4 — Device performance UI (~5–7 days).** The `asset_details.php` extraction plus the Performance tab (R).

**Phase 5 — Fleet view and metric alerting (~6–9 days).** Sections S and U. Requires a working sub-hourly scheduler, which Phase 0 determines.

**The frontend track (Stages 0–3 of Section C, ~15.5 days) is independent of all of the above** and can run in parallel or first. Given that Tabler carries a hard browser floor and a release with no burn-in, while the telemetry track's risk is bounded by the `db.sql`/`database_updates.php` lockstep, **sequencing telemetry first is the lower-risk order** — but the Phase 1 fixes above should precede both.

### What should not be built

- **A first-party collector agent** — 3–6 months of permanent-cost engineering for 26 endpoints (T.4).
- **CPU temperature anywhere** — not obtainable on commodity Windows without a driver Defender flags as a threat (T.3).
- **Universal GPU metrics** — NVIDIA discrete only; build no UI that assumes otherwise (T.3).
- **A fourth SSE stream** — poll the batch endpoint instead (Q).
- **Dual-shell coexistence** — roughly double the work for a worse intermediate state (C.2).
- **Table partitioning or a dedicated TSDB** — not at ~1,000 series; revisit at 10× (N.1).
- **Bridge retirement as scheduled work** — Tabler styles none of those classes, so it costs nothing to leave (C.3, Stage 4).
- **Sophos firewall telemetry via the Central API** — it does not exist; SNMP is a separate project (T.2).

---

## Appendix: files likely to be created or modified

**Frontend track — modify:** `includes/header.php`, `includes/top_nav.php`, `includes/inc_wrapper.php`, `includes/footer.php`, `agent/includes/side_nav.php`, `admin/includes/side_nav.php`, `agent/includes/client_side_nav.php`, `agent/includes/client_overview_side_nav.php`, `agent/reports/includes/reports_side_nav.php`, `agent/user/includes/user_side_nav.php`, `client/includes/header.php`, `client/includes/footer.php`, `guest/includes/guest_header.php`, `guest/includes/inc_wrapper.php`, `login.php`, `client/login_reset.php`, `agent/user/mfa_enforcement.php`, plus the 13 files carrying `Chart.defaults`.
**Frontend track — create:** `plugins/tabler/css/tabler.min.css`, `js/shell.js`, `css/itflow.tokens.css`, `css/itflow.bind-tabler.css`, `css/itflow.shim-bs4.css`, `css/itflow.shim-adminlte.css`, `js/src/charts/theme.js`, `js/src/charts/factory.js`, `tools/route_check.php`.
**Frontend track — delete:** `tokens/*.css`, `styles.css`, `ui_kits/`, `_ds_bundle.js`, and eventually `plugins/adminlte4/` (keeping `plugins/adminlte/` for `setup/index.php`).

**Telemetry track — create:** `src/Metrics/` (registry, provider interface, normaliser, ingest, query, retention services and per-provider adapters, one class per file per PSR-4), `api/v1/metrics.php`, `cron/metrics_collect.php`, `cron/metrics_rollup_devices.php`, `cron/metrics_prune.php`, `agent/rmm_performance.php`, `agent/includes/asset/rmm_tab_strip.php`, `agent/includes/asset/rmm_tab_panes.php`, `agent/js/asset_rmm.js`, the PowerShell collector script.
**Telemetry track — modify:** `db.sql`, `admin/database_updates.php`, `includes/database_version.php`, `admin/update.php`, `setup/index.php`, `scripts/setup_cli.php`, `includes/class_level_rmm.php`, `includes/class_rmm_asset_mapper.php`, `includes/class_tactical_rmm.php`, `api/v1/index.php`, `agent/asset_details.php`, `agent/ticket.php`, `/etc/nginx/snippets/itflow-locations.conf`.

---

*End of Phase 0 report. Per the master plan: no implementation should begin until this has been reviewed and the Phase 0 install-ownership decision is made.*
