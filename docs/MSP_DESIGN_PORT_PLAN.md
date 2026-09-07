# Porting the redesign to the MSP fork

Moving the Tabler shell, the compatibility CSS layer, the polish pass and the motion
system from **ITFlow Internal IT** into **itflow.foleyit.com** — a live production
helpdesk with real clients, active cron and no shared git history.

| | |
|---|---|
| **Source** | `ITFlow-Internal-IT` @ `215a2486` (branch `main`) |
| **Target** | `TheTractorHacker/itflow` @ `5f26c510` (branch `Syncro-Beta`) |
| **Source path** | `/home/sysadmin/ITFlow-Internal-IT` |
| **Target path** | `/var/www/itflow.foleyit.com` |
| **Drafted** | 6 September 2026 |

---

## 1. Where the two forks actually stand

Every number below was measured on disk and in the databases, not assumed.
Three of them change the shape of the plan.

| | MSP — itflow.foleyit.com | Internal IT |
|---|---|---|
| Branch / HEAD | `Syncro-Beta` @ `5f26c510` | `main` @ `215a2486` |
| Commits | 7,044 | 69 |
| Common ancestor | **None** — Internal IT is a squashed import of `itflow @ 7a9d47dd` | |
| Since fork point | 2 commits (both mirrored in Internal IT) | 66 commits |
| Uncommitted | **31 files, incl. 184 CSS lines** | clean |
| CSS framework | **AdminLTE 4 — no `plugins/tabler`** | Tabler 1.5 |
| DB version | 2.6.49 (**code declares 2.6.50**) | 2.6.75 |
| Live data | 15 clients · 80 tickets · 87 assets · 3 RMM integrations | 15 depts · 2 tickets · 0 assets |
| Cron | **Active — sends real client mail every minute** | metrics only, inert |
| Locations model | per-client (`location_client_id`, 12/12 linked) | `department_sites` junction |

The consequences:

- There is **no merge path**, so ports happen as patches, not merges.
- The MSP is on **AdminLTE 4**, so the shell swap is the whole job rather than a refresh.
- The MSP is **live, with cron sending client mail every minute**, so nothing here is a
  casual deploy.

---

## 2. Clear these before touching anything

### 2.1 — 31 uncommitted files in the MSP working tree

Includes 165 changed lines in `css/itflow_design.css` and 18 in `css/itflow_bs5_bridge.css`.
The port overwrites both files. **Commit or branch that work first** — a blind copy destroys
it silently, and it is not in any remote.

### 2.2 — One migration is already pending

The MSP database is at 2.6.49 while its own code declares 2.6.50. Run it and confirm it
lands *before* layering a UI change on top, so a schema failure cannot be mistaken for a
design failure.

### 2.3 — `ITFlow App Redesign.zip` is untracked in the MSP web root

It is the **Android** app redesign handoff (Kotlin / Jetpack Compose / Material 3) —
unrelated to this port, and it should not be sitting in a public docroot.
Move it to `~/itflow_android/`.

### 2.4 — Take a rollback point

Database dump plus a git tag on the current commit, so there is a named point to roll back
to rather than a reflex `git reset` under pressure.

---

## 3. What ports, what adapts, what must not go

The redesign and the edition are tangled together in the Internal IT history. This is the
split. The middle group is where the real work is — those files carry the new design *and*
assumptions that are false on the MSP.

### Port verbatim — no MSP-specific assumptions

| File | Notes |
|---|---|
| `plugins/tabler/css/tabler.min.css` | Vendored, MIT, 691 KB, self-contained (zero `@font-face`, all `url()` refs are inline data SVGs) |
| `css/itflow.shim-bs4.css` | 242 lines — restores the BS4 class names BS5 deleted |
| `css/itflow.shim-adminlte.css` | 372 lines — `.info-box`, `.small-box`, `.card-tools`, `.btn-tool`, `.card-outline` |
| `css/itflow.compat-color.css` | 178 lines — aliases `--color-*` onto `--if-*` |
| `css/itflow.bind-tabler.css` | 153 lines — binds app tokens onto `--tblr-*` |
| `js/chart_theme.js` | Replaces 26 duplicated `Chart.defaults` lines across 13 files |
| `js/shell.js` | Sidebar toggle + treeview, replacing `adminlte.min.js` |
| `css/itflow_motion.css` | The motion layer — in flight; port once it lands |

### Adapt — carries edition assumptions

| Area | What to do |
|---|---|
| 5 × `side_nav.php` | Take the Tabler treeview **structure**; restore the Finance, Billing and CRM groups Internal IT deleted |
| `agent/reports/index.php` | New card index is portable; re-add the billing reports to the grouping |
| `agent/dashboard.php` | Tile and chart rework is portable; MSP keeps its invoice tiles |
| `css/itflow_design.css` | 419 → 685 lines. A real merge, not a copy — MSP has 165 uncommitted lines of its own |
| `css/itflow_bs5_bridge.css` | Internal IT *shrank* this by moving rules into the shims; do not copy over MSP's larger version blindly |
| Wording | Reverse every Department → Client rename in ported markup |

### Do not port — breaks or contradicts the MSP

| Commit / area | Why |
|---|---|
| `215a2486` | The location join queries `department_sites`, which **does not exist** on the MSP. Fatal SQL error on every client page. |
| Metrics subsystem | Needs migrations 2.6.74 / 2.6.75. Separate track, separate decision. |
| `e5f09cb6` | Rebrand and Client → Department rename |
| `d034a256`, `7fecdf49` | Billing / Invoicing / Payroll / CRM removal — the MSP needs all of it |
| `4def82fc`, `ada7ef32` | `department_sites` junction, Locations as an independent entity |
| Internal-IT features | Vault V2, KB V2, Service Catalog, ITSM, employee lifecycle, Odoo, Intune |

---

## 4. Sequence

The order is load-bearing: the shell has to exist before the polish means anything, and the
polish has to settle before motion is layered on top. Each step is independently deployable
and independently revertible.

### Step 00 — Open a port channel

There is no common ancestor, so `merge` is out. `cherry-pick` still works — it applies a
diff and does not need shared history.

```bash
cd /var/www/itflow.foleyit.com
git checkout -b redesign-port
git remote add internal /home/sysadmin/ITFlow-Internal-IT
git fetch internal main
```

Work on `redesign-port` throughout. `Syncro-Beta` stays deployable the entire time.

### Step 01 — Drop in the new files

Eight pure additions. Nothing references them yet, so this commit changes no behaviour and
is safe to land on its own.

```bash
git checkout internal/main -- \
  plugins/tabler/css/tabler.min.css \
  css/itflow.shim-bs4.css css/itflow.shim-adminlte.css \
  css/itflow.compat-color.css css/itflow.bind-tabler.css \
  js/chart_theme.js js/shell.js
```

### Step 02 — Swap the shell

The single riskiest step, and the one that makes the app look different. Four shells:
agent/admin, client portal, guest, login.

Files: `includes/header.php` (148 → 341 lines), `includes/footer.php` (89 → 144),
`includes/inc_wrapper.php` (6 → 54), `includes/top_nav.php` (245 → 339), plus
`client/includes/header.php`, `client/includes/footer.php`,
`guest/includes/guest_header.php`, `guest/includes/inc_wrapper.php`, `login.php`.

Three structural facts that cost real debugging time the first time round:

- **Tabler hides the sidebar** unless `<html>` carries `data-bs-navbar-position="vertical"`.
  Silently — no console error.
- **The mirror rule hides the top navbar** when it is a direct child of `.page`. It must be
  buffered in `top_nav.php` and flushed inside `.page-wrapper`.
- **Keep `bootstrap.bundle.min.js`.** Tabler's own `tabler.min.js` exports `window.tabler`,
  not `window.bootstrap`, and self-wires the `data-bs-toggle` data-api — shipping both would
  double-wire every dropdown, tab and dismiss. Only the CSS is replaced.

Strip the `itflow_metrics.css` link from the ported header — that subsystem is not coming.

### Step 03 — Link the compat layers, in this exact order

This is the step that was botched the first time. Three of these files were written and then
never linked; 55 selectors the app still emits existed only inside them, so `.info-box`,
`.small-box`, `.card-tools`, `.form-group`, `.form-row` and `.btn-block` had no styling
anywhere. It read as a broken app, not an unstyled one.

```
tabler.min.css
  → plugin sheets (tom-select, tempus-dominus, simple-datatables, toastr, intl-tel-input)
  → itflow.shim-bs4.css
  → itflow.shim-adminlte.css      // must precede bind-tabler
  → itflow_bs5_bridge.css
  → itflow_custom.css             // declares --color-*
  → itflow_design.css             // declares --if-*
  → itflow.compat-color.css       // inert unless it follows BOTH of the above
  → itflow.bind-tabler.css
  → inline per-company accent <style nonce>
```

Apply to all four shells. Two ordering constraints are hard:

- `compat-color.css` is a pure alias layer and does nothing if linked before the tokens it
  aliases.
- `shim-adminlte.css`'s `.small-box .icon` rule has to outrank `bind-tabler.css`'s `.icon`
  reset, which is why it must load first.

### Step 04 — Rebuild navigation for MSP

Do **not** cherry-pick the five `side_nav.php` files. Internal IT's versions delete Finance,
Billing and CRM. Port the *pattern* — AdminLTE-4 collapsible treeview groups with the
`$section_pages` auto-expand map — and rebuild the MSP's own sections inside it.

> Check every permission gate as you go. The first pass of this on Internal IT accidentally
> put the whole Finance group behind `module_financial`, which silently hid Trips from users
> who previously had it.

### Step 05 — Merge the design layer, then the page fixes

`css/itflow_design.css` is a three-way problem: MSP's committed 419 lines, MSP's 165
uncommitted lines, and Internal IT's 685. Diff all three and merge deliberately; this is the
one file worth doing by hand.

Then the page-level polish, all of which applies to the MSP because it is real layout work
rather than edition framing:

- The type scale past `h3` — Tabler ships `h4` at `.875rem`, `h5` at `.75rem`, `h6` at
  `.625rem`, and the app only ever redeclared `h1`–`h3`, so page titles render *smaller*
  than the numbers beneath them.
- `.small-box` as a real component, so alerts / rmm_assets / network stop rendering raw
  Bootstrap slabs (a 423×101 solid colour block with a 70px watermark glyph).
- Sidebar scroll affordance, brand truncation, custom-link icon normalisation
  (a link saved with no icon emits `class="fas fa-"` — a 0×0 `<i>` that punches a hole in
  the icon column).
- The `roundUpToNearestMultiple()` chart-axis bug. Worth checking on **every** MSP chart that
  shares the helper: its 1000 default is *correct* for the currency charts and wrong for
  count charts, which is exactly why it survived.
- Outline-button contrast: `btn-outline-warning` measures 2.13:1 on white, `btn-outline-info`
  3.05:1 — both under the 4.5:1 minimum.

### Step 06 — Motion layer

Ports verbatim once it lands on Internal IT — it is one stylesheet plus a small addition to
`js/shell.js`, with no edition coupling. Land it **last** so that if the app feels slow,
motion is the only variable that changed.

---

## 5. Verification

The MSP is the better test target: 80 tickets and 87 assets mean tables, charts and
pagination actually render with data, where the Internal IT install is nearly empty and hid
several of these bugs.

Reuse the same harness — headless Chromium against the live host with a session cookie.

- **PHP diagnostics** — sweep every touched page for `Fatal`, `Parse`, `Warning`, `Notice`,
  `Deprecated`, `Uncaught`. Expect zero.
- **Horizontal overflow** — `scrollWidth > clientWidth`, plus any element whose right edge
  passes the viewport, at 1600 px and 1280 px.
- **Both themes** — and set dark via the **stored user preference**, not a runtime attribute
  toggle. `chart_theme.js` reads its tokens once at load, so toggling after load produces a
  false failure (the chart legend appears to vanish).
- **Reduced motion** — a `reduced_motion="reduce"` browser context, measuring computed
  `transitionDuration` / `animationDuration` rather than trusting the guard.
- **Client portal and guest views** — these have their own shells and real external users.
  Do not skip them.
- **Playwright note** — always `wait_until="domcontentloaded"` plus an explicit wait.
  `networkidle` never fires on ticket pages because of the open SSE stream.

---

## 6. Risk

This is a production helpdesk. Its cron runs `ticket_email_parser.php` and `mail_queue.php`
every minute against real client mailboxes, and `cron.php` hourly.

- **None of this port touches cron, mail or the schema.** It is CSS, shell markup and
  navigation. Keep it that way — if a step starts wanting a migration, it has escaped scope.
- **Deploy from a tag**, at low traffic, with the previous commit noted. Rollback is
  `git reset --hard <tag>` and a hard refresh; there is no schema state to unwind.
- **Cache-busting matters here.** The stylesheets are `filemtime`-versioned, but the MSP sits
  behind Cloudflare — confirm the new asset URLs are actually being served before judging
  the result.

---

## 7. Worth folding in separately

Not design work, but fixes the MSP is missing and would benefit from. Each is independent of
the port and can land on `Syncro-Beta` whenever.

| Commit | Fix |
|---|---|
| `090658ac`, `f9569b69` | `db.sql` schema drift — 38 missing tables, 63 missing columns, 4 wrong types, 12 missing indexes across 26 tables. Affects fresh installs and any restore-from-schema. |
| `c6547b21` | `ArgumentCountError` in `AuditService::log()` — `bind_param` type string was 9 chars for 10 bound values. |
| `e4d7ea53` | `ajax.php get_client_{contacts,assets,locations,vendors}` returns null for a client with none, crashing the ticket-modal JS. |
| `c4240844` | Ticket-creation modal dropdowns stay visually empty after TomSelect wraps them. |
| `429084ee` | `initTinyMCEEditors()` throws on pages that do not load TinyMCE. |
| `0c1e8cb8` | Setup wizard 500 — canonical vault key minted in the wrong step. |

---

*Measured against `ITFlow-Internal-IT @ 215a2486` and `itflow/Syncro-Beta @ 5f26c510` on
6 September 2026. Commit classifications reflect the Internal IT history at that point —
re-check before porting if either fork moves.*
