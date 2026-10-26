# Branding hand-over: RivetIT → RivetMSP

Written 2026-10-25 from the branch `branding-pull-20261025` (cut from `beta` at 6084e6290). RivetIT pins `rivet/rivet-core ^1.0.0-rc.38`;
RivetMSP `beta` (af8f4c395) pins `1.0.0-rc.37`. Nothing here moves a rule into Core: section 3 only says where each rule belongs.
Read `REBRANDING.md` first for what must **not** be renamed (the `ITFlow\` namespace, `itflow_*` functions and files, DB and config names,
API routes, backup names).

## 1. What this pull changed in RivetIT, and what RivetMSP does with it

| Commit | Change | RivetMSP action |
|---|---|---|
| `Use APP_NAME for every visible product name that was a literal` | ~100 strings (admin help, e-mail, Slack/chat, OAuth consent, MCP) now use `APP_NAME` (`htmlspecialchars(APP_NAME)` in page views, a `defined('APP_NAME') ? APP_NAME : '…'` guard in `src/`, `cron/`, `oauth/`, `mcp_server/`) | Run the same rewrite check on MSP (`tests/no_hardcoded_product_name.php`). Expect many hits: MSP has no `APP_*` constants beyond the name. |
| `Debug page: drop the link to the upstream docs.itflow.org log guide` | `admin/debug.php`: the only live upstream link left | Check MSP's debug page. |
| `Tests: guard against upstream itflow.org links and hard-coded product names` | `tests/no_upstream_branding.php`, `tests/no_hardcoded_product_name.php` (+ allow-lists) | Copy both tests and both allow-list files; empty the RivetIT-specific allow-list entry. |
| `Login: one logo backing…` | Removed `.login-box-branding .brand-logo img` from the chip rule; `.brand-name` clamped to 3 lines + `title` | Apply if MSP's login uses the same markup (it has its own backing setting `config_login_logo_bg`). |
| `Portal navbar: keep the toggler on the row…` | `navbar-expand-lg` → `navbar-expand-xl` (`client/includes/header.php:406`), `css/itflow_portal.css` small-screen media query 991.98 → 1199.98px, brand cap + non-shrinking brand in `css/itflow_custom.edition.css:95-119` | Apply to MSP's portal navbar if it is the same hand-rolled `.client-portal-nav`. |
| `Company logo: fix a broken image and three mis-sized logos…` | `guest/worksheet_sign.php:101` (bare file name as `src`), `agent/user/mfa_enforcement.php:119`, `guest/guest_view_item.php:92`; `tests/company_logo_paths.php` | Check the same three files in MSP. |
| `Appearance: warn when the company accent color is hard to read` | `includes/theme_accent.php` (`itflow_contrast_ratio`, `itflow_accent_contrast_notes`), notice in `admin/settings_appearance.php`, `tests/theme_accent_contrast.php` | Copy; it is edition-neutral. |
| `Portal footer: raise the company-name text to WCAG AA contrast` | `css/itflow_custom.edition.css:249-258` | Apply (portal footer). |
| `Certificate verification page: use the company's uploaded favicon` | `verify/index.php` | **Do not copy** (Training module). |
| `Comments: stop quoting a real install's company name` | comments only | Check MSP comments for real names. |

## 2. Branding inventory (file:line, RivetIT)

### Constants and helpers
| Item | Where |
|---|---|
| `rivetit_brand_define()` (define from earlier `define()` → `RIVETIT_<NAME>` env → default) | `includes/branding.php:22-31` |
| `APP_NAME`, `APP_SHORT_DESCRIPTION`, `APP_DESCRIPTION`, `APP_TAGLINE` | `includes/branding.php:34-37` |
| `APP_REPO_URL`, `APP_LICENSE`, `APP_REPO_PUBLIC`, `rivetit_repo_link()` | `:41`, `:42`, `:48`, `:51` |
| `APP_SOURCE_URL`, `APP_WEBSITE_URL`, `APP_DOCS_URL`, `APP_SUPPORT_URL`, `APP_CHANGELOG_URL`, `APP_RELEASES_URL` | `:56-62` |
| `APP_UPDATE_REMOTE` | `:74` |
| `APP_UPSTREAM_NAME`, `APP_UPSTREAM_URL` (GPL attribution: About/debug page, README, NOTICE only) | `:77-78`; used `admin/debug.php:565-566` |
| `APP_LOGO_URL`, `APP_LOGO_DARK_URL`, `APP_LOGO_MARK_URL`, `APP_FAVICON_URL` | `:82-85` |
| Loaded first by | `functions.php:3`, `includes/app_version.php:8` |
| `appDisplayName()` (maps `''`, `ITFlow`, `ITFlow Internal IT` to `APP_NAME`) | `functions.php:22` |
| `itflow_theme_accent_hex()`, `itflow_theme_accent_css()`, `itflow_logo_bg()`, `itflow_theme_radius()`, contrast helpers | `includes/theme_accent.php` |

### Images
`img/branding/logo.svg` (2560 B), `logo-dark.svg` (2569 B), `logo-mark.svg` (1029 B), `favicon.svg` (576 B), `favicon.ico` (34494 B);
`/favicon.ico` at the web root. Company assets live in `uploads/settings/<logo>` and `uploads/favicon.ico` and always win where a page already showed them.
Kiosk (Training, RivetIT-only): `kiosk/icons/{icon-192,icon-512,icon-512-maskable,apple-touch-icon}.png`, `kiosk/manifest.json`.

### Templates that carry branding
| Surface | File:line | Logo rule |
|---|---|---|
| Login: tab title | `login.php:1001` | company name + " \| Login" |
| Login: logo, name, "Welcome to {APP_NAME}" | `login.php:1216-1225`; CSS `login.php:1112-1160`; backing `login.php:150` | company logo, else product mark (`APP_LOGO_MARK_URL`) |
| Agent sidebar head | `agent/includes/side_nav.php:64-77` (backing `:69`) | company logo, else a building icon (no product mark) |
| Agent `<title>`, favicon | `includes/header.php:144`, `:147-151` | uploaded favicon else `/favicon.ico` + `APP_FAVICON_URL` |
| Footer "RivetIT 26.x · Docs · Source" (Admin pages only) | `includes/footer.php:10` | `APP_NAME`, `APP_VERSION`, `APP_DOCS_URL`, `APP_SOURCE_URL` |
| Portal navbar, department over company | `client/includes/header.php:406` (nav), `:450-455` (logo + two lines), title `:210` | company logo, nothing if none |
| Portal accent (CSP: no inline style) | `client/includes/header.php:301` → `client/portal_theme.css.php` | |
| Portal login reset | `client/login_reset.php:232` (title), `:245-249` (favicon) | |
| Guest pages | `guest/includes/guest_header.php:64-74`; logos `guest/guest_view_item.php:92`, `guest/outtake_sign.php:113`, `guest/worksheet_sign.php:101` | company logo only |
| MFA enforcement | `agent/user/mfa_enforcement.php:119-121` | company logo, else product mark + `APP_NAME` |
| Setup | `setup/index.php:774-776`, `:814` | product only (no company yet) |
| E-mail | `includes/mail_templates.php:269` (`mailTemplateBranding`), `:289` (`mailTemplateWrapShell`) | company logo, else company name in bold; accent stripe |
| Training certificate / transcript | `src/Training/Certificates/Brand.php`, `agent/training_certificate.php:104`, `kiosk/certificate.php:88` | company logo |
| Page titles ("Department" wording) | `includes/page_title.php:15-20` | |

### CSS (edition files; the 20 shared CSS files are byte-identical to Core `assets/ui/css`)
| Rule | File:line | Used by |
|---|---|---|
| White chip behind the company logo on the dark portal navbar | `css/itflow_design.edition.css:30` | `client/includes/header.php:451` |
| Portal navbar accent border | `css/itflow_custom.edition.css:4` | `client/includes/header.php:406`, `js/portal.js` |
| Brand margin (Tabler `margin-inline:auto`) and logo height (Tabler `img{height:auto}`) | `css/itflow_custom.edition.css:26-33` | portal navbar |
| Brand two-line text, ellipsis per line, `min-width:0` | `:95`, `:121-146` | `client/includes/header.php:455-456` |
| Brand cap below xl / non-shrinking brand from xl | `:103-118` | portal navbar |
| Read-only portal preview (inert controls, toast) | `:153`, `:211-240` | `js/portal_preview_readonly.js` |
| CSP-safe replacements for inline styles | `:170-200` (`portal-status-dot`, `-chat-scroll`, `-csat-comment`, `-chevron-sm`, `-icon-cell`, `-search-group`, `-doc-image`) | `client/ticket.php`, `contracts.php`, `allowance.php`, `kb_articles.php`, `document.php` |
| KB formatting classes (CSP-safe) | `:243-245` | `includes/kb_portal_styles.php:29-31` |
| Portal footer text contrast | `:249-258` | `client/includes/footer.php:39-46` |
| Load order (edition files link right after their Core file) | `includes/header.php:188,192`, `client/includes/header.php:257,260`, `guest/includes/guest_header.php:110,112`, `login.php:1039,1041`, `client/login_reset.php:265,267` | |

## 3. Shared (Core) or edition: where each rule belongs (nothing moved yet)

| Rule | Verdict | Why |
|---|---|---|
| Logo chip on dark chrome (`design.edition.css:30`) | **Shared**, as a class such as `.it-logo-chip`; the selector binding stays in the edition | The reasoning (a transparent black logo is 1.4:1 on `#1f2937`; a light chip is right for any logo) holds for both editions. `.client-portal-nav` is RivetIT's chrome. |
| Tabler margin/height fix for the brand (`custom.edition.css:26-33`) | **Shared** | Tabler interaction, not branding. |
| Portal navbar accent border (`:4`) | **Shared** if MSP's portal keeps `.client-portal-nav` | |
| Two-line brand, caps, breakpoint (`:95-146`, `header.php:406`) | **Edition** | "Department over company" is RivetIT wording; MSP's brand is client/company. The overflow *mechanism* (min-width:0 + per-line ellipsis + toggler room) is shared knowledge. |
| Portal preview inert/toast (`:153`, `:211-240`) | **Shared** if both editions ship the preview | |
| CSP-safe inline-style replacements (`:170-200`) | **Shared** | Same portal pages in both editions. |
| KB utility classes (`:243-245`) | **Shared** | Pure CSP-safe formatting. Also duplicated before this pull (fixed). |
| Portal footer contrast (`:249-258`) | **Shared** | |
| Login brand CSS (inline in `login.php:1112-1160`) | **Shared** once extracted to a file | Today it is inline, so it cannot be an edition/Core file. |
| `includes/theme_accent.php` incl. contrast helpers | **Shared** | Used by login, portal, kiosk and the agent shell. |
| `includes/branding.php` | **Edition** (per product) | Values differ; the `rivetit_brand_define()` mechanism is worth sharing. |
| Training certificate/verify/kiosk branding | **Edition** (RivetIT only) | |

## 4. Where RivetIT overrides or extends Core for branding
- **Core CSS/JS:** none overridden. Verified byte-for-byte: 20 of 20 shared CSS files and 5 of 6 JS files equal `vendor/rivet/rivet-core/assets/ui`.
  `js/webhook_wizard.js` differs because it predates rc.38 (not branding; re-run `install-ui-assets.php`).
- **Extension points used:** the two optional `*.edition.css` files (`MANIFEST.json` `load_order`, owner `edition`), linked in the five loaders in section 2.
- **RivetIT-only files Core does not ship:** `css/itflow_portal.css` (edited: media query at line 217), `client/portal_theme.css.php`, `includes/branding.php`, `includes/theme_accent.php`.
- **Edited in RivetIT, shared by design:** `includes/footer.php`, `includes/header.php` (title, favicon, inline accent block, which comes after every stylesheet link, checked on the login page and agent shell).

## 5. What RivetMSP must NOT copy
- Training: `kiosk/**` (icons, `manifest.json`, "RivetIT Training"), `verify/index.php`, `src/Training/**`, `agent/training_certificate.php`, `agent/includes/training_*`, the Odoo write-back wording.
- Wording: "Department", "Departments", "Department Portal", "Employee Handbook", "Waiting on Employee", `includes/page_title.php:15-20`, "internal IT" in `APP_DESCRIPTION`/`APP_TAGLINE`.
- RivetIT's repository and update settings: `APP_REPO_URL`, `APP_DOCS_URL`, `APP_UPDATE_REMOTE`.
- The mailbox folders named `ITFlow` and `ITFlow-Quarantine` (`cron/ticket_email_parser.php:750,760`) are kept on purpose for existing mailboxes; MSP should decide separately.
- `guest/outtake_sign.php`, `guest/worksheet_sign.php`, `agent/modals/ticket/outtake_sign.php`: they carry Foley IT's legal wording and a hard-coded `https://foleyit.com/ticket-terms` link (see the report; fix before anyone copies them).

## 6. Strings that differ between editions

| Item | RivetIT (`includes/branding.php`) | RivetMSP (`includes/branding.php`, 30 lines) |
|---|---|---|
| `APP_NAME` | `RivetIT` | `RivetMSP` |
| `APP_SHORT_DESCRIPTION` | Open-source internal IT operations platform. | **missing** |
| `APP_DESCRIPTION` | RivetIT is a free and open-source internal IT operations platform for managing service requests, users, devices, documentation, automation, integrations, and employee training from one centralized system. | **missing** |
| `APP_TAGLINE` | Everything your IT department needs. One platform. | **missing** |
| `APP_LICENSE`, `APP_REPO_*`, `APP_SOURCE/WEBSITE/DOCS/SUPPORT/CHANGELOG/RELEASES_URL`, `APP_UPDATE_REMOTE` | `GPL-3.0`; `github.com/TheTractorHacker/RivetIT` and derived links; remote `origin` | **missing** |
| `APP_UPSTREAM_NAME/URL` | `ITFlow`, `https://github.com/itflow-org/itflow` | **missing** (add: needed for the GPL attribution) |
| `APP_LOGO_URL` | `/img/branding/logo.svg` | `/img/branding/rivetmsp-logo.png` |
| `APP_LOGO_DARK_URL` | `/img/branding/logo-dark.svg` | **missing** |
| `APP_LOGO_MARK_URL` | `/img/branding/logo-mark.svg` | `/img/branding/logo-mark.svg?v=rivetmsp-2` |
| `APP_FAVICON_URL` | `/img/branding/favicon.svg` | `/img/branding/favicon.svg?v=rivetmsp-2` |
| Legacy names mapped to `APP_NAME` (`appDisplayName`) | `''`, `ITFlow`, `ITFlow Internal IT` | `''`, `ITFlow`, `ITFlow MSP`, `ITFlow MSP Edition`, `ITFlow — MSP Edition` |
| Company-logo URL helper | none: each page prints `/uploads/settings/<file>` | `appCompanyLogoUrl()` (uses `basename()` + `rawurlencode()`; safer, copy it back to RivetIT) |
| Login line | "Welcome to {APP_NAME}" | check |
| Core pin | `^1.0.0-rc.38` | `1.0.0-rc.37` |

## 7. Checklist for RivetMSP, in order
1. Replace `includes/branding.php` with the RivetIT structure (`rivetit_brand_define`, env overrides, repo link switch) holding MSP's own values; keep `appCompanyLogoUrl()`.
2. Add `APP_UPSTREAM_NAME/URL` and show them on MSP's About/debug page, README and NOTICE only.
3. Copy `tests/no_upstream_branding.php`, `tests/no_hardcoded_product_name.php`, `tests/company_logo_paths.php`, `tests/theme_accent_contrast.php` and the allow-list files; fix what they find.
4. Apply the logo fixes in section 1 where MSP has the same files, and the portal navbar fix if it uses the same navbar.
5. Check every surface at 360, 768 and 1280 px with no logo, a wide logo, a square logo and a very long name (section 1 lists what had to be fixed in RivetIT; section 8 how it was measured).
6. Adopt rc.38 and run `install-ui-assets.php` before comparing CSS, so differences are only the edition files.

## 8. What was checked, and what was not
Method: throwaway copy of the fictional demo database on a separate port, my branch's code, headless Chromium at 360, 768 and 1280 px.
Scenarios: no logo, wide black-on-transparent logo, wide colour logo, square logo, 112-character name, and a name with HTML and quote characters
(escaped everywhere). Surfaces measured: login, portal reset, agent shell (sidebar head, top bar), portal navbar, e-mail header (rendered from
`mailTemplateWrapShell`), MFA enforcement page, favicon and title on each. Accent colors measured: default blue, amber, lime, pure yellow, black, navy.

Not checked: invoice, quote and payment PDF headers (billing is removed from RivetIT's API and UI; the code is dormant), the Training certificate/transcript
PDF and kiosk pages (need seeded training data), the guest worksheet/outtake/share pages (need signed tokens; fixed by reading the code and by
`tests/company_logo_paths.php`), real e-mail clients and dark-mode mail clients, and anything on RivetMSP.
