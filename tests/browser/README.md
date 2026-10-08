# Browser smoke suite (RivetIT and RivetMSP)

A real-browser end-to-end check of the pages and widgets that plain HTTP tests cannot see: JavaScript pickers,
the webhook wizard, event rule editor, modals, theme switching, phone-width layout and keyboard behaviour.
The **same code** runs against both editions; the few differences (RivetIT's webhook wizard markup vs RivetMSP's,
edition-only pages) live in `editions.mjs`.

It drives Chrome over the DevTools Protocol with Node's built-in `WebSocket` and `fetch`: **no npm dependencies**,
nothing to `npm install`, and nothing added to the product's `composer.json` or root `package.json`.

## Prerequisites

| Need | How |
| --- | --- |
| Node.js 22 or newer (global `WebSocket`) | `node -v`; install from nodejs.org or your package manager |
| A Chrome / Chromium binary | any one of: Puppeteer's download (`npx @puppeteer/browsers install chrome@stable`, lands in `~/.cache/puppeteer`), Playwright's (`npx playwright install chromium`, lands in `~/.cache/ms-playwright`), `apt install chromium`, or Google Chrome. The suite looks in those places; otherwise set `CHROME_BIN=/path/to/chrome` |
| A **throwaway** install of the edition under test | recipe below |
| A throwaway Redis (optional) | `redis-server --bind 127.0.0.1 --port 6393 --save "" --appendonly no`, so the Redis page has something to talk to |

Snap-packaged Chromium often cannot be driven from outside its confinement; prefer a downloaded Chrome for Testing.

## Running

```bash
BASE_URL=http://127.0.0.1:8620 \
ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD='Scratch-Admin-1234' \
tests/browser/run.sh
```

| Variable | Meaning |
| --- | --- |
| `BASE_URL`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` | required; the suite signs in through the real login form |
| `EDITION` | `it` or `msp`; auto-detected from the login page when unset |
| `SMOKE_OUT` | where failure screenshots (`<edition>-<nn>-<check>.png` plus a `.txt` with the URL and errors) and `<edition>-results.json` go; default `tests/browser/out` |
| `ONLY` | regular expression; run only the checks whose name matches (a check that needs earlier state, such as the new ticket, will then fail) |
| `WEBHOOK_PUBLIC_URL` | an `https` URL whose host resolves in DNS (default `https://example.com/webhook/rivet-smoke`); the wizard's live check needs DNS. Nothing is ever sent to it by this suite |
| `CHROME_BIN`, `HEADED=1` | pick the browser binary / watch it run |
| `ALLOW_NON_LOCAL=1` | the suite refuses non-loopback URLs because it writes data; only override for a disposable remote install |

Exit status: `0` all checks passed, `1` a check failed, `2` usage or prerequisite problem. The run ends with a
summary table (PASS / FAIL / SKIP counts).

## What each check does

Every check also fails on any **uncaught JavaScript error, `console.error`, failed or 4xx/5xx first-party request**
that occurred while it ran (unless the check explicitly allows it). Third-party resource failures (fonts and CDNs
in a sandbox) are ignored.

Sign in (and a wrong password is refused); dashboard; create a department/client through the modal; ticket list date
picker (open, "Last 7 days" auto-submit, reopen shows the selection, custom range by two real calendar clicks);
save a ticket view with the icon picker (search, pick, save, appears in the list with the icon); create a ticket
through the modal form and open it; Administration settings directory; global search (live dropdown and results
page); webhook guided add (n8n, private-address warning proves the strict network policy, public-address live check,
advanced disclosure, events search plus group selection, review summary, create); webhook guides search, open guide,
copy button; event rules list; new rule from a recipe, add a condition row, Save and test drawer, rule appears in the
list; job queue; audit trail filter and CSV export; Redis page; compliance status; edition-only pages; dark theme
switch (and back); phone-width (390 px) overflow check on the dashboard, ticket list, webhook wizard and event
rules list; keyboard focus reaches the side navigation; Esc closes a modal, the date picker and the icon picker.

## Scratch install recipe (per edition)

Never run this against a live site or live database.

1. Copy the tree (exclude `.git`, `.claude`, `backups`, `uploads`, `node_modules`, `android`, `docs`, `tests`, and
   any `config.php`) to a scratch directory. For a RivetIT checkout use the vendor tree of a working install and make
   sure `vendor/rivet/rivet-core` is the version `composer.json` requires, then `composer dump-autoload --no-dev --optimize`.
2. Create a database and user whose names contain `scratch`, with a random password.
3. From the copy's `scripts/` directory (stdin from `/dev/null`):
   `RIVETIT_DB_PASSWORD=... ITFLOW_DB_PASSWORD=... php setup_cli.php --host=localhost --username=... --database=...
   --base-url=127.0.0.1:<port> --locale=en_US --timezone=UTC --currency=USD --company-name="Scratch Org"
   --country="United States" --user-name="Scratch Admin" --user-email=admin@scratch.test
   --user-password='Scratch-Admin-1234' --non-interactive`
4. In the copy's `config.php` set `$config_https_only = FALSE;` and make sure `$config_settings_enc_key` exists.
5. `php scripts/update_cli.php --update_db`
6. Serve it: `PHP_CLI_SERVER_WORKERS=4 RIVETIT_REDIS_PORT=6393 php -S 127.0.0.1:<port> -t <copy>` (RivetMSP:
   `RIVETMSP_REDIS_PORT`). Leave `RIVETIT_WEBHOOK_ALLOW_PRIVATE` / `RIVETMSP_WEBHOOK_ALLOW_PRIVATE` **unset**: the
   wizard check asserts the strict private-address policy. `PHP_CLI_SERVER_WORKERS` matters: with a single PHP
   worker, page assets queue behind each other and the checks time out on a busy machine.
7. Run the suite. When finished, kill the PHP and Redis processes by PID, drop the database and user, and delete the copy.

## Limits

* Headless Chrome, one viewport at a time; no cross-browser coverage.
* The wizard's "Send test" and webhook deliveries are not exercised (they would make outbound requests).
* The checks that need DNS (`example.com` live check) report a failure if the sandbox has no resolver; set
  `WEBHOOK_PUBLIC_URL` to a resolvable host.
