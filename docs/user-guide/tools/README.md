# User-guide tooling

Everything needed to regenerate the screenshots in the [user guide](../README.md) from a throw-away
demo instance. Screenshots go stale as the interface changes; this makes refreshing them a two-command job
instead of a manual afternoon.

The demo is a fictional company, **Summit Ridge Manufacturing** (7 departments, 21 employees, 3 sites).
Nothing in it is real: addresses use the reserved `.example` domain, phone numbers are `555-01xx`, and every
password, license key and serial is invented.

## Regenerate every screenshot

```bash
# 1. Build a fresh demo instance (about 3 minutes: the training seed waits out lesson reading times;
#    leaves it running on http://127.0.0.1:8080)
docs/user-guide/tools/build-demo.sh

# 2. Re-shoot everything (several minutes)
NODE_PATH=$(npm root -g) node docs/user-guide/tools/run-all.cjs

# Just one group:
NODE_PATH=$(npm root -g) node docs/user-guide/tools/run-all.cjs service-desk
```

`build-demo.sh` works on a **scratch copy** of the app (default `$TMPDIR/rivetit-user-guide-demo`), never on
the repository itself, so the repo never gains a `config.php` or an `uploads/` tree. It drops and recreates
the demo database (it refuses names that don't look like a demo/test database, and refuses a directory it
did not create). See `build-demo.sh --help` for the options (`--app-dir`, `--db`, `--port`, `--only`,
`--no-server`).

Requirements: bash, PHP with `mysqli` and `zip`, [Composer](https://getcomposer.org), a MariaDB/MySQL server that `mysql -u root` can reach over
the local socket, Node 18+ and [Playwright](https://playwright.dev) with Chromium
(`npm i -g playwright && npx playwright install chromium`).

## Plain screenshots without call-outs

[`../images-clean/`](../images-clean/README.md) holds the same pictures with the same file names but without
the numbered red call-outs the guide draws on them, for reuse outside the guide (a web site, slides). The same
scripts make them; clean mode just skips the call-out step and writes to `images-clean/`:

```bash
NODE_PATH=$(npm root -g) node docs/user-guide/tools/run-all.cjs --clean    # or: GUIDE_CLEAN=1 node capture/<group>.cjs
docs/user-guide/tools/optimize-images.sh --lossless docs/user-guide/images-clean
```

Run it against the same demo build as the annotated set so both show the same data.

After re-shooting, finish with:

```bash
docs/user-guide/tools/optimize-images.sh          # shrink the PNGs
python3 docs/user-guide/tools/build-visual-tour.py # refresh the gallery page
python3 docs/user-guide/tools/check-guide.py       # links, anchors, images, filler words, stray real data
```

## Demo sign-ins

| Who | Email | Password |
|---|---|---|
| Administrator | `alex.morgan@summitridge.example` | `DemoAdmin#2026` |
| Technician | `priya.nair@summitridge.example` | `DemoPass#2026` |
| Technician | `marcus.lee@summitridge.example` | `DemoPass#2026` |
| Employee (portal) | see `seed/70-portal.php` | `DemoPass#2026` |

## What is in this folder

| Path | What it is |
|---|---|
| `build-demo.sh` | Rebuilds the demo instance: copy of the app, fresh database, `setup_cli.php`, module toggles, every seed, web server. |
| `seed/` | Demo data. Files are replayed **in filename order**, so the numeric prefix is the apply order. `00-core.sql` (departments, people, locations, agent logins) comes first; everything else looks its parents up **by name**, never by numeric id. `.sql` files run through `mysql`; `.php` files run through `php` with `RIVETIT_APP_DIR` set (used where the app encrypts values or keeps a hash-chained ledger, so the app's own code has to write them). |
| `capture/` | One Playwright script per guide group. Each logs in, walks its pages and writes PNGs to `../images/<group>/`. |
| `lib.cjs` | The shared helpers the capture scripts use: `launch`, `login`, `goto`, `shot`, and `callout` (numbered red badges drawn on the live page). |
| `optimize-images.sh` | Shrinks the screenshots (about 30 MB to 12 MB) without visible change, using `pngquant` and `optipng`. Run it after re-shooting, before committing. |
| `build-visual-tour.py` | Regenerates [visual-tour.md](../visual-tour.md) (a gallery of representative screenshots) from the guide pages. Run it after re-shooting. |
| `check-guide.py` | Lints the guide: broken links, anchors and images, unreferenced screenshots, filler words, and anything that looks like real data (non-`.example` emails, public IPs). |
| `run-all.cjs` | Runs every capture script in turn and reports which failed. |
| `setup-wizard.cjs` | Walks the browser installer on a brand-new instance for the [first-time setup](../00-first-time-setup.md) pictures. Unlike the capture scripts it *installs* the app, so it is not part of `run-all`. |

[app-findings.md](app-findings.md) lists the application bugs and rough edges the writers noticed while documenting each module (none were changed).

## Rules the scripts follow

- **Seeds are idempotent.** Re-running one never duplicates a row (each insert is guarded on a natural key),
  and dates are relative (`NOW() - INTERVAL n DAY`) so the data always looks recent.
- **Capture scripts never save anything.** They open forms and pop-ups, may type illustrative values, take the
  picture, and cancel. That is why they can be re-run at any time and against any demo instance.
- **Images are generated, not edited.** Change the seed or the capture script and re-run it; don't touch a PNG
  by hand.

## Adding or changing a page of the guide

1. Add demo data to a new `seed/NN-<group>.sql` (or `.php`) file — pick a prefix that sorts after whatever it depends on.
2. Add `capture/<group>.cjs`. Number images `NN-slug.png` in the order they appear on the page.
3. `build-demo.sh`, then `run-all.cjs <group>`, and **look at every picture** before you commit it.
4. Write the page in `docs/user-guide/`, embedding images as `![alt](images/<group>/NN-slug.png)`.
