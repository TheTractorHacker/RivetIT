# RivetIT web UX/UI QA — 2026-10-21 run

See `UX_REPORT_RIVETIT.md` for findings. This README is just how to reproduce the environment.

## Environment

```
git -C /var/www/mw-itflow.foleyit.com worktree add /home/sysadmin/qa-ux-rivetit -b qa-ux-<date> beta
cp /home/sysadmin/demos/rivetit/config.php /home/sysadmin/qa-ux-rivetit/config.php
rsync -a /var/www/mw-itflow.foleyit.com/vendor/ /home/sysadmin/qa-ux-rivetit/vendor/   # fills gitignored packages (symfony/polyfill-uuid, rivet/rivet-core, …) a fresh worktree checkout is missing
cd /home/sysadmin/qa-ux-rivetit/scripts && php update_cli.php --update_db
cd /home/sysadmin/qa-ux-rivetit
PHP_CLI_SERVER_WORKERS=32 php -S 127.0.0.1:8190 -t /home/sysadmin/qa-ux-rivetit /home/sysadmin/demos/router.php
```

Bind to 127.0.0.1 only — host PHP (8.5.11) has known exploited CVEs.

## Browser automation

Playwright MCP / CLI's bundled chromium wasn't usable in this sandbox (missing `/opt/google/chrome/chrome`), and the
scratchpad's own `pylibs` has a `greenlet` wheel built for Python 3.12 that doesn't import under this host's Python 3.14.
A prior session's scratchpad (`…/54173984-f66d-4dbd-83ec-7ff70d138957/scratchpad/pylibs`) has a working 3.14-compatible
`greenlet`/`playwright` build — that's what every script here imports via `sys.path.insert`. If that path is gone in a
future run, rebuild `pylibs` fresh (`pip install --target pylibs playwright greenlet` with a 3.14-compatible greenlet wheel,
or fall back to `node_modules/playwright` + a thin JS driver).

## Real dark mode, not a CSS hack

`data-bs-theme="dark"` is rendered **server-side** from a DB-persisted per-user setting (`user_settings.user_config_theme_dark`),
set via Service Desk area > profile menu > **My Preferences** > Dark. Flipping `document.documentElement.setAttribute('data-bs-theme','dark')`
client-side after the page has already painted light does **not** reliably reproduce what a real dark-mode user sees — it
produced a false-positive "illegible chart legend" finding in this run that a genuine dark-mode screenshot immediately
disproved. Always drive dark mode through the real preference toggle before trusting a dark-mode screenshot.

## Key test record

`TCK-255` — `QA-UI- Printer offline in Warehouse`, department Engineering, created as Priya Nair. Carries the full
create → public reply → internal note → assign flow used to validate D2's fix and the general ticket workflow. Left
in `New` status, unassigned pill not confirmed changed by automation (see report "not tested").

## Scripts

Ad-hoc Playwright scripts used for this run live in the session scratchpad
(`/tmp/claude-1000/…/scratchpad/ux_it/`), not copied into the repo — they're one-off probes, not a maintained suite.
The most reusable one, if this run is repeated, is the page crawler pattern: log in once, loop over a list of paths,
screenshot + capture console/network/h1/main checks for each, in both a light and a real-dark-mode session.
