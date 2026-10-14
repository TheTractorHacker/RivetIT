# Web server routes (nginx)

RivetIT needs the web server to know a number of routes: the PHP front controllers, the API, Remote MCP
(`/mcp`, `/.well-known/oauth-*`), the OAuth endpoints, the kiosk redirect, the denials for private folders,
static caching and so on. Those used to be copied once into the site's nginx file at install time and then
hand-edited, so every new feature's route was missing on every existing server until somebody added it.
That is what broke Claude's MCP connection on production (`/.well-known/oauth-authorization-server`), and
why the kiosk fleet-link rule was still missing there.

Now there is **one shared routes file** and **one command** that keeps every server on it.

```bash
sudo /var/www/<app>/deploy/apply-web-routes.sh --domain <your domain>
```

It shows you the exact diff it would make and asks `y/N` before touching anything (add `--dry-run` to only look).
That is the whole routine. `deploy/update.sh` does **not** apply routes itself (see "Trust model" below): it
checks and tells you, and Administration > Server status tells you when it is needed.

## How it is laid out

| Piece | Where | Who writes it |
|---|---|---|
| Per-site vhost: domain, TLS, `listen`, `root`, security headers, rate-limit zone name | `/etc/nginx/sites-available/<domain>.conf` (template `deploy/templates/nginx-vhost.conf.template`) | the installer once; you for TLS changes |
| **All routes** (every `location`) | `/etc/nginx/snippets/rivetit-locations.conf` (source `deploy/templates/rivetit-locations.conf`) | `apply-web-routes.sh`, from the template. Never edit the installed copy |
| The link between them | one block in the vhost, between `# BEGIN RIVETIT ROUTES` and `# END RIVETIT ROUTES`: two `set` lines and one `include` | the installer / `apply-web-routes.sh` |

The two per-site values live in that block, so several sites on one box (each with its own PHP version and
web root) share the one routes file:

```nginx
# BEGIN RIVETIT ROUTES (managed by deploy/apply-web-routes.sh; see docs/WEB_ROUTES.md)
set $rivetit_php unix:/run/php/php8.5-fpm.sock;          # this site's PHP-FPM socket
set $rivetit_hsts "max-age=63072000; includeSubDomains"; # "" when a proxy in front owns HTTPS (nginx then sends no HSTS)
include snippets/rivetit-locations.conf;
# END RIVETIT ROUTES
```

The routes file uses `$rivetit_php` for every `fastcgi_pass`, `$rivetit_hsts` for every HSTS header and
`$document_root` everywhere else, so nothing in it is site-specific. After a PHP upgrade run the command
again with `--php-sock /run/php/phpX.Y-fpm.sock` (it rewrites that one line and nothing else).

The routes file also needs two things nginx cannot put in a `location`: the shared rate-limit zone
(`limit_req_zone ... zone=itflow_login`, created in `/etc/nginx/conf.d/itflow-rate-limit.conf` if the box
has none) and Debian's `snippets/fastcgi-php.conf` (part of the nginx package).

## Trust model: nothing from the app tree runs as root

The script runs as root, but on most installs the app tree is owned by the web user (www-data). If root ran code
or installed templates from there, a compromised web user could get root. So:

1. **No code from the app tree runs as root.** The PHP analysis and rendering (`deploy/lib/web_routes_tool.php`,
   `src/Ops/*`) is started with `runuser -u <the tool's owner>` (or `nobody` when the tool is root-owned). It writes
   nothing to disk: its stdout goes into a file root created in a fresh `0700` temp directory. Even reading the
   routes template goes through that unprivileged user. The script sources and executes nothing else from the tree.
2. **Root does only the privileged part:** backup, write, `nginx -t`, reload, rollback.
3. **Everything the tree produced is untrusted input to root**, checked by a validator embedded in the script
   itself before anything is installed:
   * the routes file may contain only `location` blocks whose keys are in the route registry (all of them, each
     once) and only the directives the routes use. Anything else is rejected: `include` of any path but the two
     fastcgi snippets, `load_module`, `lua*`/`*_by_lua*`, `perl*`, `js_*`/`njs`, `proxy_pass`, `alias`, `root`,
     `rewrite`, `set`, `ssi`, `sub_filter`, a literal `fastcgi_pass` socket (it must be `$rivetit_php`), a script
     path outside `$document_root`, any `fastcgi_param` but `SCRIPT_FILENAME`/`SCRIPT_NAME`, a header with a
     request variable, a `return` that is not 403/404 or a redirect to a path on this site;
   * a converted vhost may differ from the installed one **only** by registry-defined location blocks, an old
     shared-snippet include, and the managed block (`set $rivetit_php <socket under /run, /var/run or /tmp>`,
     `set $rivetit_hsts <empty or the standard value>`, `include <the routes file>`): everything else, other
     locations, other server blocks, every other directive, must be identical.
4. **A human reviews the diff.** An interactive run prints the diff and its SHA-256 (`DIFF-SHA`) and asks `y/N`.
   Without a terminal it refuses unless you pass `--yes-diff-sha <hash>` (the hash must match the diff it just
   computed, so a changed file or template invalidates it) or `--yes` (accepted only if a `--dry-run` within the
   last hour showed exactly this diff). Typical scripted use: `--dry-run`, read it, then repeat with the printed
   `--yes-diff-sha`.
5. **The script file itself** is the one piece of code that runs as root. If a non-root account can write it (or
   its parent directories), an interactive run warns, and an unattended run refuses unless you pass
   `--allow-untrusted-script` after reviewing it. `sudo deploy/apply-web-routes.sh --check-trust <path>` tells you
   whether a path is root-owned with no group/world write bit anywhere up the tree.

### Root-owned copy (recommended where the app tree is web-writable)

```bash
sudo deploy/apply-web-routes.sh --install-root-copy --dry-run            # lists the six files with their sha256
sudo deploy/apply-web-routes.sh --install-root-copy --yes-diff-sha <hash>
sudo /usr/local/lib/rivetit/deploy/apply-web-routes.sh --domain <domain>  # run THAT copy from now on
```

It copies the script, the tool, the registry/converter classes and the routes template to `/usr/local/lib/rivetit/`
(root-owned, `755`/`644`; it refuses symlinked sources). Trade-off: that copy is **only as fresh as the last
root-run install**: after an update that changes a route, the installed template is the old one until you re-run
`--install-root-copy` (that is a deliberate, reviewed step, which is the point). `update.sh` uses this copy, or a
root-owned checkout, to do a **dry run only** and tell you whether routes are out of date. With a web-writable
tree and no root copy it prints the command and does nothing.

## What the command does

1. Finds the vhost(s): `--domain NAME` (matched against `server_name` in `sites-enabled/` and `conf.d/`),
   `--vhost FILE` for one you name, or `--all` for every RivetIT vhost on the box. (The unprivileged analysis
   reads vhost files directly, so they must be world-readable, as the nginx package makes them.)
2. Reads each vhost with a real nginx-config parser (comments, quotes and braces understood), not text
   matching, and works out the new file. A **first run on an older server** converts the inline vhost:
   it removes only the `location` blocks the routes file now owns (recognised by their modifier and path
   against the registry below, comment lines directly above them go too), inserts the managed block where
   the first of them was, and leaves everything else exactly as written. Locations it does not recognise
   (yours) stay and are listed as "left alone". An older shared snippet that a vhost includes
   (`itflow-locations.conf` on the beta box) is replaced by the new include in that vhost; the old snippet
   file itself is left in place for sites that still use it.
3. **Declines when it cannot prove the result is safe**, leaves that vhost untouched and prints the
   reason and exact manual steps. It declines when: the file does not parse; a block it would remove has an
   access rule or redirect the routes file lacks (`allow`/`deny`, `auth_basic`, `satisfy`, `internal`,
   `limit_*`, `alias`/`root`/`rewrite`/`proxy_pass`, a `return`), because removing it could open something up;
   an old snippet holds a location the routes file does not provide; the PHP socket cannot be determined
   uniquely (several sockets in the blocks to replace, or none); `$rivetit_php` is already set by hand
   outside the managed block; or a regex location of yours (`~`, `~*`) sits between the ones that would move
   (nginx tries regex locations in file order, so moving the others past it could change which answers).
4. Validates the result (above), prints the diff and waits for confirmation (above).
5. Backs up every file it will touch into `/var/backups/rivetit/nginx-<timestamp>/` (or `--backup-dir`),
   with a manifest.
6. Writes the files (atomically, keeping owner and mode), runs `nginx -t`, and reloads nginx **only if the
   test passes**. If the test fails, the reload fails, or anything stops half-way, every file is put back
   automatically and nginx keeps running the old configuration.
7. Idempotent: when everything is current it says so, writes nothing and does **not** reload nginx.

Exit codes: `0` done / already current, `1` failed (nothing half-done; the message says what to look at),
`2` `--check` found the routes out of date, `3` done, but at least one vhost needs the manual steps printed.

Options: `--dry-run` (unified diff and `DIFF-SHA`, changes nothing), `--yes-diff-sha HASH`, `--yes`, `--check`
(monitoring: exit 2 when out of date), `--rollback DIR`, `--install-root-copy`, `--allow-untrusted-script`, `--php-sock PATH`, `--php-sock-for FILE=PATH`, `--hsts direct|proxy`, `--snippet-dir DIR`,
`--legacy-snippet NAME`, `--backup-dir DIR`; see `--help`. It must run as root and refuses to run as
`www-data` (the web user must never be able to rewrite nginx; nothing in the app can).

### Several sites sharing one routes file (the beta box)

```bash
sudo deploy/apply-web-routes.sh --all --dry-run            # prints the diff and DIFF-SHA
sudo deploy/apply-web-routes.sh --all --yes-diff-sha <hash>
```

Each vhost keeps its own `set $rivetit_php` line, so sites on different PHP versions work from one file;
the old `itflow-locations.conf` hard-coded a socket, so the socket is detected from it per vhost (or given
with `--php-sock-for site.conf=/run/php/php8.5-fpm.sock` where a site needs a different one). A site that
declines (for example because its own hand-added blocks use a second socket) is reported and left alone
while the others convert; give it its socket and run again.

## Rolling back

Every run prints its backup folder. To undo a run, files and all, then test and reload:

```bash
sudo deploy/apply-web-routes.sh --rollback /var/backups/rivetit/nginx-20261014-101500
```

(or copy the files back from `<backup>/files/` by hand; `MANIFEST` lists what was changed and which files
were new).

## Docker and Apache

* **Docker:** `docker/nginx.conf` is the single source of truth and ships in the image; there is no
  vhost to convert and nothing to run. The Server status route check says so and skips.
* **Apache:** the app's rules are `.htaccess` files; none of this applies and the check skips.
* **A box where nginx is managed by something else** (Ansible, a hosting panel): include
  `deploy/templates/rivetit-locations.conf` from your own vhost with the three-line block above, and keep that
  file in step by copying the shipped one (`--check` tells you when it differs).

## The route registry

`src/Ops/WebRoutes.php` lists every location the routes file owns: id, nginx location, purpose, the
feature it serves, how serious a gap is, and a **probe**: a harmless request that tells "route present" from
"route missing", with the expected status, an optional header expectation (for example `Pragma: no-cache`)
and an optional condition (for example "only when MCP built-in sign-in is on"). The generator
(`WebRoutesConverter`), the in-app self-check, the tests and this page all read that list.

| Id | nginx location | What it is for | Feature it breaks | Self-check probe |
|---|---|---|---|---|
| `vcs_config` | `~ ^/(\.git\|\.github\|config\.php)` | Hide .git, .github and config.php (answers 404). | Basic site security | GET `/config.php` -> 404 |
| `acme` | `^~ /.well-known/` | Keep /.well-known/ reachable for certificate renewal (ACME) while dotfiles stay denied. | Certificate renewal (certbot) | GET `/.well-known/acme-challenge/rivetit-route-check` -> 404 |
| `dotfiles` | `~ /\.` | Refuse every dotfile (.env, .gitignore, ...). | Basic site security | GET `/.rivetit-route-check` -> 403 |
| `restore_dir` | `^~ /restore/` | Refuse the folder where the Docker flow drops an encrypted backup. | Basic site security | GET `/restore/rivetit-route-check` -> 403 |
| `tooling_dirs` | `~ ^/(deploy\|docker\|docs)/` | Refuse the deployment tooling and docs shipped in the checkout. | Basic site security | GET `/docs/rivetit-route-check` -> 403 |
| `private_trees` | `~ ^/(tests\|vendor\|src\|includes\|odoo_addons\|mcp_server\|setup/setup_functions\.php)` | Refuse non-public source trees (pentest F-02). | Basic site security | GET `/src/rivetit-route-check` -> 403 |
| `root_metadata` | `~* ^/[^/]+\.(md\|lock\|sql\|yml\|yaml)$` | Refuse README.md, composer.lock, *.sql, *.yml at the web root. | Basic site security | GET `/rivetit-route-check.md` -> 403 |
| `composer_json` | `= /composer.json` | Refuse composer.json. | Basic site security | GET `/composer.json` -> 403 |
| `uploads_scripts` | `~ ^/uploads/.*\.(php\|phtml\|phar\|phps\|cgi\|pl\|sh)$` | Never run an uploaded file as a script. | Basic site security | GET `/uploads/rivetit-route-check.php` -> 403 |
| `uploads_kb` | `^~ /uploads/kb/` | Knowledge-base media is only served through the authenticated endpoints, never directly. | Knowledge base | GET `/uploads/kb/rivetit-route-check` -> 403 |
| `uploads_training` | `^~ /uploads/training/` | Training media: internal only, reached through X-Accel-Redirect after the app authorizes the request (a harmless request cannot tell it apart: both answer 404). | Training media | none (not observable by a harmless request) |
| `kiosk_entry` | `= /kiosk/` | Answer the kiosk start URLs /kiosk/?d= and ?e= with a no-log redirect so device and fleet tokens never reach the access log or PHP. | Training kiosk (device and fleet links) | GET `/kiosk/?e=rvkf1_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA` -> 302; no Cross-Origin-Opener-Policy header |
| `kiosk_includes` | `^~ /kiosk/includes/` | Refuse the kiosk include files. | Training kiosk | GET `/kiosk/includes/rivetit-route-check.txt` -> 403 |
| `kiosk_php` | `~ ^/kiosk/.*\.php$` | Kiosk PHP pages (they send their own CSP and Referrer-Policy). | Training kiosk | none (not observable by a harmless request) |
| `uploads_prefix` | `^~ /uploads/` | Force uploaded files to download instead of rendering (Content-Disposition) and keep scripts denied. | Basic site security | GET `/uploads/rivetit-route-check.txt` -> 404; header Content-Disposition contains "attachment" |
| `cron_scripts` | `~ ^/(cron\|scripts)/.*\.php$` | Cron and CLI scripts never run over HTTP. | Basic site security | GET `/scripts/rivetit-route-check.php` -> 403 |
| `backups_dir` | `^~ /backups/` | Refuse the backups folder. | Basic site security | GET `/backups/rivetit-route-check` -> 403 |
| `config_dir` | `^~ /config/` | Refuse the config folder. | Basic site security | GET `/config/rivetit-route-check` -> 403 |
| `tcpdf_tools` | `^~ /plugins/TCPDF/tools/` | Refuse the TCPDF tools folder. | Basic site security | GET `/plugins/TCPDF/tools/rivetit-route-check` -> 403 |
| `api_v1_leaf_scripts` | `~ ^/api/v1/.+/.+\.php$` | The legacy per-resource API scripts must not be reachable; only the router is (pentest). | API | GET `/api/v1/rivetit/route-check.php` -> 403 |
| `api_v1` | `/api/v1/` | API v1 clean URLs go to the front controller. | API | none (not observable by a harmless request) |
| `api_v2` | `/api/v2/` | API v2 clean URLs go to the front controller. | API | none (not observable by a harmless request) |
| `htaccess` | `~ /\.ht` | Never serve .htaccess and friends. | Basic site security | none (not observable by a harmless request) |
| `login_rate_limit` | `~ ^/(login\|setup)\.php$` | Rate-limit the two password entry points (needs the itflow_login zone). | Sign-in brute-force protection | none (not observable by a harmless request) |
| `sse_stream` | `~ /sse_ticket_stream\.php$` | Live ticket updates: no response buffering. | Live ticket updates (SSE) | none (not observable by a harmless request) |
| `api_v1_index` | `= /api/v1/index.php` | API front controller, unbuffered (streaming endpoints). | API | none (not observable by a harmless request) |
| `api_docs` | `= /api/v1/docs` | The API documentation page, framed by the Admin page (X-Frame-Options SAMEORIGIN). | API documentation | GET `/api/v1/docs` -> 200; header X-Frame-Options contains "SAMEORIGIN" |
| `odoo_callback` | `= /client/login_odoo.php` | The Odoo sign-in callback carries a one-time code, so it is never logged. | Odoo sign-in | none (not observable by a harmless request) |
| `mcp` | `= /mcp` | Remote MCP endpoint (Streamable HTTP). | Remote MCP | GET `/mcp` -> 401; only when remote mcp is switched on and set up |
| `mcp_resource_metadata` | `= /.well-known/oauth-protected-resource` | Remote MCP: OAuth protected-resource metadata clients read first. | Remote MCP | GET `/.well-known/oauth-protected-resource` -> 200; only when remote mcp is switched on and set up |
| `mcp_authserver_metadata` | `= /.well-known/oauth-authorization-server` | Remote MCP built-in sign-in: authorization-server metadata (RFC 8414). Claude cannot connect without it. | Remote MCP built-in sign-in | GET `/.well-known/oauth-authorization-server` -> 200; header Content-Type contains "json"; only when remote mcp built-in sign-in is on |
| `oauth_token_endpoints` | `~ ^/oauth/(token\|register\|revoke)\.php$` | Remote MCP built-in sign-in: token endpoints, never cached anywhere (Cache-Control no-store, Pragma no-cache). | Remote MCP built-in sign-in | GET `/oauth/token.php` -> 400/401/403/404/405; header Pragma contains "no-cache" |
| `oauth_authorize` | `= /oauth/authorize.php` | Remote MCP built-in sign-in: the consent screen (framing denied, never cached). | Remote MCP built-in sign-in | GET `/oauth/authorize.php` -> 200/302/400/401/403/404; header Pragma contains "no-cache" |
| `training_video_frame` | `= /agent/training_video_frame.php` | The one agent page the app frames itself (training video check). | Training videos | none (not observable by a harmless request) |
| `php` | `~ \.php$` | Run PHP pages (and answer the /health endpoints). | The application itself | GET `/health/live.php` -> 200 |
| `static_plugins` | `^~ /plugins/` | Cache vendor libraries; run only the two plugin scripts that are fetched directly, refuse other plugin PHP. | Page speed and the portal TOTP QR code | GET first real file under `/plugins/` -> 200; header Cache-Control contains "immutable" |
| `static_fonts` | `^~ /fonts/` | Cache fonts for a long time. | Page speed | GET first real file under `/fonts/` -> 200; header Cache-Control contains "immutable" |
| `static_css` | `^~ /css/` | Cache stylesheets for a long time (their URLs carry ?v=). | Page speed | GET first real file under `/css/` -> 200; header Cache-Control contains "immutable" |
| `static_js` | `^~ /js/` | Cache scripts for an hour. | Page speed | GET first real file under `/js/` -> 200; header Cache-Control contains "max-age=3600" |
| `default` | `/` | Everything else: a file, a folder index, or 404. | The application itself | none (not observable by a harmless request) |

### Adding a route for a new feature

1. Add the `location` to `deploy/templates/rivetit-locations.conf`. Remember that nginx takes the first
   matching regex location in file order and that `add_header` does not inherit into a location that declares
   its own (repeat the security headers, as the neighbours do). Use `$rivetit_php` for `fastcgi_pass`,
   `$document_root` for paths and `$rivetit_hsts` for HSTS; never a literal socket or domain.
2. Add **one entry** to `WebRoutes::all()` in `src/Ops/WebRoutes.php`, in the same position. Give it a probe if a
   harmless GET can tell present from missing (ask for a file that does not exist where you can: a missing
   denial then shows as 404 where the rule gives 403); set `'probe' => null` and say why in `purpose` if not.
   Use `'requires' => 'mcp_ready'` or `'mcp_builtin'` for a route that only answers when a switch is on (add a new
   condition to `WebRoutes::CONDITIONS` and `WebRouteCheck::mcpContext()` for another switch).
3. Run `php tests/web_routes_registry.php` (it fails if the registry and the template disagree, in either
   direction, or in order), `php tests/web_routes_probe.php`, `php tests/web_routes_nginx.php` and
   `php tests/web_routes_apply.php`. Regenerate the table above from the registry.
4. Existing servers pick the new route up on their next `update.sh`, or when an administrator runs the command
   shown on Administration > Server status. No database change is needed.

## Self-check in the app

Administration > Server status has a **Web server** group. The app asks its own public address (the host
of the configured base URL, nothing else, GET only, no redirects followed, 3 s connect / 5 s total per request,
12 s overall, 4 at a time) for the probe paths above and compares the answers with the registry. A route the
web server does not answer correctly becomes **"Web server is missing routes (N)"**, red when a denial is
missing and amber otherwise, listing the feature each breaks and the exact command to run as root. It also
compares the installed routes file with the one shipped with this version. Results are cached for five minutes
(`Check it now` forces a re-check). The Remote MCP settings page shows a banner when built-in sign-in is on and
the authorization-server metadata URL does not answer, and the Update page hints when the routes file is older
than the code. None of this can change nginx (the app's user cannot), and none of it can affect
`/health/ready.php` or take the page down: any failure becomes a "skipped" row.

It deliberately stands down, with a note, when it cannot judge: the site is not served by nginx, it runs in
Docker, the server cannot reach its own public address (loopback or hairpin blocked, another proxy), the
site's own `/health/live.php` does not answer 200 through that address, or something in front (a proxy or
firewall) does not return a plain 404 for an unknown address. Routes with no probe (marked
"none" in the table: the internal training-media location, the login rate limit, the API front controllers, ...)
are those a harmless request cannot tell from the fallback; the `--check` file comparison still covers them.

For monitoring, `sudo deploy/apply-web-routes.sh --domain <domain> --check` exits non-zero when out of date.

## Troubleshooting

* **"No nginx vhost ... was found"**: the domain is not in any `server_name` under `sites-enabled/` or
  `conf.d/`. Name the file: `--vhost /etc/nginx/sites-available/<file>`.
* **"... needs a manual change"**: read the reasons printed; the usual ones are a second PHP socket (pass
  `--php-sock`), an `allow`/`deny` you added inside a RivetIT location (move that rule to its own location or
  decide it should be dropped, then re-run), or your own regex location in the middle of the group.
* **"nginx rejected the new configuration"**: nothing was kept. The message is nginx's own; fix the cause
  (often a vhost edited by hand since) and run again. The backup folder is printed in any case.
* **Self-check says a route is missing right after you applied the routes**: wait out the five-minute cache or
  use "check it now"; if it persists, run `--check` and `--dry-run` on the server, and `nginx -T | grep -n
  rivetit` to confirm the vhost that serves the domain really includes the routes file (another vhost may
  answer for the domain, or a proxy in front rewrites the answers).
* **The PHP socket warning**: the path in `set $rivetit_php` does not exist on this machine. Check
  `ls /run/php/` and pass `--php-sock`.
* **A PHP upgrade moved the socket**: `sudo deploy/apply-web-routes.sh --domain <d> --php-sock /run/php/php8.5-fpm.sock`.

## Behaviour notes (what moved when the rules became shared)

The routes are the original inline rules, unchanged: `tests/web_routes_registry.php` proves it
structurally (same locations, same order, same bodies) and `tests/web_routes_nginx.php` proves it
behaviourally by serving the original inline vhost, the new split vhost and the converted original from a
private nginx and asking 79 questions (status and every header identical). Two deliberate differences:

1. `/plugins/`: the original answered the portal's TOTP QR code (`plugins/barcode/barcode.php`) and every other
   plugin `.php` as raw source text (this "^~" prefix skips the generic PHP location). Now `barcode.php` and
   PHPMailer's `get_oauth_token.php` run through PHP-FPM (never cached), any other plugin `.php` answers 403.
   (The beta box's shared snippet already ran PHP there.)
2. In proxy mode the original still sent HSTS from the four static-asset locations (`/plugins/ /fonts/ /css/
   /js/`), against its own comment that the upstream proxy owns HSTS. The shared file sends none when
   `$rivetit_hsts` is empty.

## Security notes

* The web application only ever reads the routes file and writes a small cache file in the temp directory; nothing
  in the app can change nginx.
* `apply-web-routes.sh` is root code. Run it from a root-owned location where you can (see above); on a box with a
  web-writable tree, read the script (or `git diff` it against a trusted checkout) before running it, and prefer
  `--install-root-copy`.
* Left as it is, deliberately: `deploy/update.sh`, `install.sh` and `harden.sh` are themselves root-run scripts in
  the same tree and carry the same exposure as before; this change only stops the new web-routes tool from
  adding to it. The validator checks what the tool produced against the registry, not the registry's own
  semantics: a hostile registry can only name locations, never widen the directive allow-list, and every change
  is in the diff a human confirms.
