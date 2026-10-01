# deploy/ — RivetIT deployment tooling

Scripts to stand up, harden, back up, and update a **standalone** RivetIT instance on a
fresh (or already-running) Ubuntu/Debian box. This is **not** a multi-tenant installer — every company
gets its own app directory, its own database, its own database user, and its own nginx vhost. Running
these scripts a second time with a different `--domain` adds a second, fully independent company's
instance alongside the first, on the same box.

| Script | What it's for |
|---|---|
| `full-restore-deploy.sh` | Interactive front-end for `install.sh` (and optionally `harden.sh`) — asks restore-vs-fresh, database name, and SSL certificate questions, then hands off with the answers as flags. The easiest way to run either script without already knowing their flags. |
| `install.sh` | Stand up a brand-new instance end to end: packages, code, database, TLS/vhost, hardening, firewall, cron, and the app's own first-run setup. |
| `harden.sh` | A standalone, idempotent, re-runnable hardening pass — the fuller superset of what `install.sh` applies inline during a fresh install. |
| `backup.sh` (+ systemd timer) | Encrypted, scheduled backups of the database and `uploads/`. |
| `restore.sh` | Restore a `backup.sh` archive (`backup-*.tar.gz.enc`) into an instance — standalone, or via `install.sh --restore-from` to stand up a brand-new box straight from a backup. |
| `restore_admin_zip.sh` | Restore the *other* backup format — the admin-panel zip from Settings → Backup / the cron auto-backup — with a master-key fallback (`--admin-user`) for when the backup passphrase is lost. |
| `update.sh` | Pull application updates and run any pending database migrations. |
| `lib/common.sh` | Shared helpers (logging, `gen_secret`, OS detection, service checks, manifest reading) — sourced by every script above, never run directly. |
| `lib/admin_auth_check.php` | PHP shim `restore_admin_zip.sh` shells out to for `--admin-user` mode — real `password_verify()`/role checks against the target's own database, not reimplemented in bash. |
| `lib/safe_zip_extract.php` | PHP shim `restore_admin_zip.sh` shells out to for extracting the admin-panel zip (boundary/symlink-checked via the app's own `safeExtractZip()`), not reimplemented in bash. |
| `templates/` | The actual config content applied by `install.sh`/`harden.sh` — nginx vhost, PHP-FPM hardening ini, MariaDB hardening cnf, fail2ban jail. Read these if you want to see exactly what gets changed on your box before running anything. |

All scripts must be run as **root** (`sudo`) — they touch `/etc`, install packages, and manage
services. All of them log what they're about to do before doing anything invasive (a service restart,
`ufw enable`, a destructive file write), and none of them will disable SSH access as a side effect.

**Names that still say `itflow`.** RivetIT was called ITFlow Internal IT before, and the names these
scripts give to things on the server were kept so that existing boxes keep working and re-runs find what
earlier runs installed: some log files (`/var/log/itflow-*.log`), legacy cron files,
the systemd units (`itflow-backup.service` / `.timer`), the passphrase file (`/etc/itflow/backup-passphrase`),
the fail2ban jail and filter (`itflow-auth`), the nginx rate-limit zone (`itflow_login`,
`/etc/nginx/conf.d/itflow-rate-limit.conf`) and the PHP-FPM / MariaDB / unattended-upgrades drop-ins
(`99-itflow-hardening.*`, `51-itflow-unattended-upgrades`) — and, following the same convention,
`restore_admin_zip.sh`'s own log file (`/var/log/itflow-restore-admin-zip.log`). The text of the files `harden.sh` and
`install.sh` compare byte for byte before writing (the hardening templates and the inline fail2ban
filter, rate-limit and unattended-upgrades files) is unchanged too, so a re-run does not restart
PHP-FPM, MariaDB or fail2ban just for a renamed comment. See [`../REBRANDING.md`](../REBRANDING.md).

---

## full-restore-deploy.sh

```
sudo deploy/full-restore-deploy.sh [any install.sh flag]
sudo deploy/full-restore-deploy.sh --help
```

The interactive way to run `install.sh` (below) without already knowing which flags you need. It
asks about whichever of the following wasn't already given on the command line, then hands off to
`install.sh` with the resolved flags — every other `install.sh` flag or prompt (`--domain`,
`--app-dir`, `--admin-*`, `--company-*`, `--skip-*`, ...) is passed straight through and behaves
exactly as it does when you call `install.sh` directly:

1. **Restore vs. fresh** — restore an existing backup onto this box instead of setting up a
   brand-new company: either the app's own `itflow_<timestamp>_*.zip` (Settings > Backup — see
   `restore_admin_zip.sh` below) or an encrypted `backup.sh` archive (see `restore.sh`
   below), auto-detected by the file extension you give. Answering yes prompts for the backup file;
   a `.tar.gz.enc` always then asks for its passphrase file, a `.zip` only asks whether one was set
   for that specific backup — fills in `--restore-from`/`--restore-passphrase-file`.
2. **Database name** — pick one, or leave it blank to auto-generate one from the domain
   (`--db-name`).
3. **SSL certificate** — a real Let's Encrypt certificate now (prompts for `--email`), a self-signed
   one behind your own reverse proxy (`--proxy-mode`), or a self-signed placeholder to replace with
   real TLS later (`--skip-tls`).
4. **Extra hardening** — also run `harden.sh` (below) once `install.sh` finishes. This is this
   script's own `--harden`/`--skip-harden` flag, not an `install.sh` flag.

```bash
sudo deploy/full-restore-deploy.sh --domain=rivetit.example.com
# Prompts for restore-vs-fresh, database name, SSL certificate, and extra hardening;
# everything else (admin user, company details, ...) is prompted for by install.sh itself,
# exactly as it would be if you ran install.sh directly.
```

`--non-interactive` skips every question here too and passes your flags straight to `install.sh`,
exactly as if you'd run `install.sh` directly — `harden.sh` then only runs if you also pass
`--harden` explicitly (the safer default for scripted/automated runs).

---

## install.sh

```
sudo deploy/install.sh --domain=<fqdn> [options]
sudo deploy/install.sh --help
```

### What it does, in order

1. **Packages** — asks first ("Install dependencies? [Y/n]"; answer no, or pass `--skip-dependencies`,
   to skip this step entirely if you already have them set up the way you want), then installs nginx,
   MariaDB, Redis, cron, PHP 8.5 (added via the `ondrej/php` PPA if Ubuntu's default repos don't carry it),
   certbot, ufw, fail2ban, git, composer, and friends. Anything already installed (e.g. because another
   instance is already running on this box) is left alone.
2. **Application code** — if run from inside an existing checkout of this repo, that checkout is copied
   into the new instance's app directory (so a second company doesn't need its own GitHub network
   access); otherwise it's cloned fresh from GitHub. `composer install --no-dev` runs if `composer.json`
   is present.
3. **Uploads/backups directories + permissions** — creates every `uploads/*` subdirectory with its
   directory-listing-denial placeholder, then sets the whole app directory to `www-data:www-data`,
   `750` directories / `640` files, widening only `uploads/` and `backups/` back to writable.
4. **Database** — generates a random 32-character password (`gen_secret`), creates a MariaDB database
   and a same-named user bound to `localhost` only, with exactly the grants the app needs
   (`SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES, CREATE
   TEMPORARY TABLES` on that one database — nothing global, no `SUPER`/`FILE`/`GRANT OPTION`).
5. **Certificate + nginx vhost** — bootstraps a self-signed certificate (needed before `nginx -t` will
   accept a config referencing it), renders `templates/nginx-vhost.conf.template` for your domain, and
   either runs certbot (direct-TLS mode) or leaves the self-signed cert in place long-term (`--proxy-mode`
   or `--skip-tls`).
6. **PHP-FPM + MariaDB hardening** — applies `templates/php-hardening.ini` and
   `templates/mariadb-hardening.cnf` (see `harden.sh` below for what's in them), validating each with a
   config test before restarting the service, and rolling back automatically if the test fails.
7. **Firewall + fail2ban** — allows SSH (auto-detected port) and HTTP/HTTPS through `ufw` *before*
   enabling it, then installs the `sshd` and `itflow-auth` fail2ban jails.
8. **Cron** — installs and starts the cron service, creates writable per-instance logs, and installs
   `/etc/cron.d/rivetit-<domain>` with the main dispatcher plus standalone mail, backup,
   metrics, Outlook, domain/certificate, and training jobs. Each standalone script checks its
   feature setting; the backup runner defers to the main dispatcher when it is enabled. A prior
   `/etc/cron.d/itflow-<domain>` for the same app is backed up and removed to avoid duplicate runs.
   The main job is inert until an admin turns on **Enable Cron** in Settings. The installer also registers
   a root-owned schedule helper so administrators can edit this installation's job schedules in
   **Maintenance → Cron** without granting the web process general write access to `/etc/cron.d`.
   Re-running the installer preserves an existing schedule. For an existing installation with
   other cron jobs but no main job, it leaves the main job absent to avoid duplicate work.
9. **Application setup** — runs `scripts/setup_cli.php` as `www-data` to write `config.php`, import
   `db.sql`, and create the first admin user. Skipped automatically if `config.php` already exists
   (re-running `install.sh` against an already-set-up instance is safe).

### Key flags

Full reference: `sudo deploy/install.sh --help`. The ones worth knowing up front:

- `--domain=<fqdn>` — **required.** Public hostname for this instance.
- `--proxy-mode` — this box sits behind an *external* reverse proxy that already terminates public TLS
  (a common production pattern). Skips certbot, serves a self-signed
  backend cert on `:8443`, and keeps nginx's own redirects relative so the internal hostname/port never
  leaks to an end user.
- `--skip-tls` — no public DNS yet / TLS will be configured later by hand. Serves self-signed directly.
- `--local-proxies=<N>` / `--cloudflare=<yes|no>` — what sits in front of the app, so the real client
  address is recorded instead of a proxy's. `N` is the number of reverse proxies on your side (0-10,
  not counting Cloudflare); default 1 with `--proxy-mode`, else 0. Asked interactively when omitted,
  defaulted with `--non-interactive`. Saved to the app's settings (`scripts/set_network_cli.php`) and
  editable later under Admin > Security > Network path, which also shows a self-check of the address
  the app sees. After the app is set up the installer also runs `scripts/update_cli.php --update_db`
  until the schema is current (db.sql is an older snapshot).
- `--skip-dependencies` — don't install nginx, PHP, MariaDB, Redis or cron, and don't ask about it
  either. Use this when they're already provisioned the way you want (a different PHP build, a managed
  database, etc.) and you only want install.sh's other steps (app code, vhost, TLS, hardening, cron,
  first-run setup). Without this flag, an interactive run always asks first; `--non-interactive` installs
  them by default unless this flag is also given.
- `--non-interactive` — fail instead of prompting for anything missing (see `--help` for the full list of
  flags it then requires).
- `--admin-password=...` — **avoid this flag on an interactive terminal.** `install.sh` forwards it to
  `setup_cli.php` via the `RIVETIT_ADMIN_PASSWORD` environment variable (it also sets the deprecated
  `ITFLOW_ADMIN_PASSWORD`, which `setup_cli.php` still honours), never on `setup_cli.php`'s own
  argv, so it's not visible to other users on the box via `ps`. It IS visible on *this* script's own
  command line and shell history, though — leave it out and answer `setup_cli.php`'s interactive prompt
  instead whenever you have a terminal in front of you, which touches neither.
- `--restore-from=<path>` — restore onto this new box instead of a fresh company setup; every
  company/localization/admin-user flag above is ignored when this is given. Accepts either an
  in-app `itflow_<timestamp>_*.zip` (see `restore_admin_zip.sh` below — `--restore-passphrase-file`
  only needed if that specific backup had one set) or an encrypted `backup.sh` archive (see
  `restore.sh` below, which always needs `--restore-passphrase-file=<path>`) — picked by the file
  extension you give.

### Worked example 1 — fresh dedicated box

```bash
sudo deploy/install.sh \
  --domain=rivetit.example.com \
  --email=admin@example.com \
  --company-name="Example Co" --country="United States" \
  --locale=en_US --timezone=America/New_York --currency=USD
# (admin name/email/password prompted interactively)
```

This installs everything from scratch, gets a real Let's Encrypt certificate for
`rivetit.example.com`, and finishes with a working instance at `https://rivetit.example.com/`.

### Worked example 2 — adding a second company to a box that already runs one instance

```bash
sudo deploy/install.sh \
  --domain=rivetit2.example.com \
  --db-name=itflow2 \
  --email=admin@example.com \
  --company-name="Second Co" --country="United States" \
  --locale=en_US --timezone=America/New_York --currency=USD
```

Nginx, PHP-FPM, and MariaDB are already installed and running from the first instance — `install.sh`
detects that and skips reinstalling them. It provisions a brand-new app directory
(`/var/www/rivetit2.example.com` by default), a brand-new database/user, and a second nginx vhost,
side by side with the first. The shared `ufw`/`fail2ban` state and the shared nginx rate-limit zone
(`/etc/nginx/conf.d/itflow-rate-limit.conf`) are reused, not duplicated.

---

## harden.sh

```
sudo deploy/harden.sh                    # box-wide hardening only
sudo deploy/harden.sh --dry-run          # preview every action, change nothing
sudo deploy/harden.sh --domain rivetit.example.com --app-root /var/www/rivetit.example.com \
    --ssl-cert /etc/ssl/certs/rivetit.example.com.crt \
    --ssl-cert-key /etc/ssl/private/rivetit.example.com.key
                                          # also (re)render that vhost hardened
```

### What it applies

1. PHP-FPM hardening (`templates/php-hardening.ini`) — validated with a config test, service reloaded
   only on success, rolled back automatically otherwise.
2. MariaDB hardening (`templates/mariadb-hardening.cnf`) — same validate-then-restart-then-rollback
   pattern.
3. A **mysql_secure_installation-equivalent cleanup** — removes anonymous MySQL users and the `test`
   database via direct, idempotent SQL (skipped, with a warning, if MariaDB's root account isn't using
   the default `unix_socket` auth this expects).
4. fail2ban (`templates/jail-itflow.local` + its `filter.d` companion).
5. `ufw` — auto-detects every port `sshd` actually listens on (main config *and* any
   `sshd_config.d/*.conf` drop-in) and allows all of them, plus 80/443 (and 8443 in `--proxy-mode`),
   *before* enabling the firewall.
6. **unattended-upgrades**, restricted to `-security` origin packages only, with automatic reboot
   explicitly disabled (a live app rebooting itself unattended is its own outage).
7. nginx — the shared rate-limit zone unconditionally, plus (only if `--domain`/`--app-root`/`--ssl-cert`/
   `--ssl-cert-key` are given) a re-rendered vhost for that domain.

Every step is check-before-act and skips (not re-does) anything already in place, so this is safe to run
repeatedly — including against a box that already has one or more *other* RivetIT instances hardened by
an earlier run.

### Standalone vs. letting install.sh call it

**In this version, `install.sh` does not invoke `harden.sh`.** It applies its own inline copy of the
PHP-FPM/MariaDB hardening (steps 1–2 above, from the exact same template files), plus its own firewall
and fail2ban setup (steps 4–5) as part of a fresh install. What `install.sh` does **not** do on its own
is the mysql_secure_installation-equivalent cleanup (step 3) or unattended-upgrades (step 6).

Practical recommendation: **run `deploy/harden.sh` once, standalone, after `deploy/install.sh`
completes.** Because `harden.sh`'s file-deploy step compares content before writing anything, it will
find the PHP-FPM/MariaDB/fail2ban config `install.sh` already applied unchanged and skip re-writing it —
you'll only actually pick up the two extra steps `install.sh` doesn't do itself:

```bash
sudo deploy/install.sh --domain=rivetit.example.com ...
sudo deploy/harden.sh
```

`harden.sh` is also the right tool, entirely on its own, for a company that already has
RivetIT (or its predecessor, ITFlow Internal IT) running from a manual or older setup and just wants
to retrofit this hardening onto it — it installs the hardening tools themselves (fail2ban, ufw, unattended-upgrades) if they're missing,
since a retrofit target may well not have them yet. Always preview first with `--dry-run` on a box you
didn't just build with `install.sh`, so you know exactly what's about to change before it does.

Selective skips are available for every section (`--skip-php`, `--skip-mariadb`,
`--skip-mysql-secure`, `--skip-fail2ban`, `--skip-ufw`, `--skip-unattended-upgrades`, `--skip-nginx`) —
see `--help` for the full list.

---

## backup.sh + systemd timer

Scheduled, encrypted backups of the database and `uploads/`, run automatically via a companion systemd
timer rather than depending on someone remembering to run a script by hand.

### Setting up the passphrase file

Backups are encrypted with a passphrase that lives **only** in a root-only file on the server,
`/etc/itflow/backup-passphrase` — never on argv, never in the systemd unit itself:

```bash
sudo mkdir -p /etc/itflow
sudo bash -c 'openssl rand -base64 48 > /etc/itflow/backup-passphrase'
sudo chmod 600 /etc/itflow/backup-passphrase
sudo chown root:root /etc/itflow/backup-passphrase
```

**Immediately copy that passphrase somewhere other than this server** — a password manager, a printed
copy in a safe, a secrets vault, anything off-box. An encrypted backup whose only decryption passphrase
lives on the same disk as the backup provides no real protection if that disk is what's lost, stolen, or
ransomwared — you'd be encrypting a backup against a threat model where the key is guaranteed to be
right next to it.

### Enabling the scheduled timer

```bash
sudo systemctl enable --now itflow-backup.timer
sudo systemctl list-timers itflow-backup.timer     # confirm the next scheduled run
```

Run `deploy/backup.sh` once by hand first (`sudo deploy/backup.sh`) to confirm it succeeds and to see
where it writes output, before trusting the timer to run it unattended.

One app-specific wrinkle worth knowing: credential vault data in the database is encrypted with each
user's own zero-knowledge key, derived from their password — not a separate recoverable secret bundled
into the SQL dump. As long as the admin account's password is unchanged after a restore, vault data
decrypts normally; there is also an in-app "retrieve master key" option (Settings → Backup, admin only)
for the rare case a full DR situation needs it. A restore that changes the admin password will need that
step.

The application also has its own independent, manual, on-demand backup feature reachable from inside the
app (Settings → Backup → "Download Backup" / "Save to Server", `admin/backup.php`) that produces the same
kind of database-dump-plus-uploads-zip on demand — useful before a risky change, but it is **not**
encrypted and **not** scheduled, so it does not replace `deploy/backup.sh` + the systemd timer for actual
disaster-recovery purposes. It has its own restore paths too — from the browser, the `/setup` wizard's
"Restore from Backup" option (reachable from the Welcome screen, or the `?restore` Utilities link) accepts
a zip from that feature; from the command line, `restore_admin_zip.sh` (below) does the same thing with a
required safety backup, `--admin-user`/`--passphrase-file` master-key recovery, and unattended/cron
support the browser wizard doesn't have. Neither accepts a `deploy/backup.sh` archive — that's handled by
`restore.sh` below instead.

## restore.sh

The counterpart `backup.sh` didn't have until now: decrypts a `backup-*.tar.gz.enc` archive and restores
it into an already-installed instance (run `install.sh` first on a fresh box — or pass `install.sh`
`--restore-from`/`--restore-passphrase-file` to do both in one step, see below).

```bash
sudo deploy/restore.sh --app-dir=/var/www/rivetit.example.com \
    --backup=/path/to/backup-itflow-20260101T000000Z.tar.gz.enc \
    --passphrase-file=/etc/itflow/backup-passphrase \
    --confirm-restore
```

`--confirm-restore` is mandatory and has no interactive y/n prompt to click through — this REPLACES every
table in the target database and everything under `--app-dir/uploads`. By default it takes a fresh safety
backup of the target's *current* state first (via `backup.sh` itself) and aborts before touching anything
if that fails; pass `--no-pre-restore-backup-confirmed` only when the target has nothing worth keeping
(e.g. it was just created and never used).

If the archive has a `backup-manifest.json` (every backup taken since this feature shipped), `restore.sh`
also applies its `settings_enc_key` to the target's `config.php` after import — without this, SMTP/IMAP
passwords, RMM/UniFi API keys, webhook secrets, and the wrapped credential-vault master key would decrypt
to garbage under a *different* instance's own randomly-generated key. A pre-existing backup taken before
this shipped has no manifest; `restore.sh` warns loudly and leaves those secrets for you to re-enter by
hand instead of silently producing an instance with broken integrations.

**Standing up a brand-new server straight from a backup** (the disaster-recovery scenario this exists
for): `install.sh` accepts the same restore in one step instead of its normal fresh-company setup:

```bash
sudo deploy/install.sh --domain=rivetit.example.com \
    --restore-from=/path/to/backup-itflow-20260101T000000Z.tar.gz.enc \
    --restore-passphrase-file=/etc/itflow/backup-passphrase
```

This skips every company/localization/admin-user prompt (the restored data already has all of that) —
`install.sh` provisions the box and an empty database exactly as it normally would, then hands off to
`restore.sh` to fill it in.

**Testing a restore without touching a real instance:** point `--app-dir` at a disposable one instead —
either `install.sh --domain=restore-test.local --skip-tls --skip-firewall --skip-fail2ban` on a spare box
or VM, or just `scripts/setup_cli.php --config-only` against a scratch database on this one — then inspect
it and throw it away. `--app-dir`'s target must already have a `config.php` either way; `restore.sh` never
creates an instance from nothing, only restores data into one that already exists.

---

## restore_admin_zip.sh

The counterpart to `restore.sh` above, for the *other* backup format this app produces: the
`itflow_<timestamp>_<manual|auto>.zip` an admin gets from Settings → Backup's "Save to Server" /
"Download Backup" (`admin/post/backup.php`), and what the daily cron auto-backup writes to
`<app-dir>/backups/`. `restore.sh` cannot open this format — it only understands its own
`backup-*.tar.gz.enc`; this script is that zip's restore counterpart, following the same safety
conventions (`--confirm-restore` required with no y/n prompt to click through, a pre-restore safety
backup of the target's current state by default, root-required). It replaces the app's own browser-based
`/setup` wizard restore for command-line/scripted use, sharing the same hardened extraction (`setup/
setup_functions.php`'s `safeExtractZip()`) rather than reimplementing it.

```bash
sudo deploy/restore_admin_zip.sh --app-dir=/var/www/rivetit.example.com \
    --backup=/var/www/rivetit.example.com/backups/itflow_20260101000001_auto.zip \
    --passphrase-file=/etc/itflow/backup-passphrase \
    --confirm-restore
```

Master-key recovery needs exactly one of two flags — this is the fallback the browser wizard has no
equivalent for at all:

- **`--passphrase-file=<path>`** — required if the backup's manifest is encrypted (an admin had a backup
  passphrase set in Admin → Backup when it was taken). Works even against a brand-new, never-set-up
  `--app-dir` — true from-scratch disaster recovery.
- **`--admin-user=<email>` + `--admin-password-file=<path>`** — the fallback when the passphrase is lost:
  an existing, active **Administrator**-role (not Technician) account's login email and password, already
  on `--app-dir` *right now*, verified with the same `password_verify()` + `role_is_admin=1` check the web
  app's own admin session gate enforces. Proves real application-level authority over this specific box —
  not just root/SSH access to it — before anything is touched. Requires `--app-dir` to already be a
  reachable, already-set-up instance with its own live `users`/`user_roles` tables; refused with a clear
  message otherwise (use `--passphrase-file` instead for a truly from-scratch target). After the restore,
  this mode also recovers `site_encryption_master_key` by decrypting that same admin's *restored* account
  with the same password — reliable for rolling a box back onto an earlier backup of itself, not
  guaranteed across two independently-set-up boxes. It never recovers `config_settings_enc_key` itself
  (SMTP/IMAP passwords, RMM/webhook secrets) — use `--passphrase-file` for that.

Neither flag needed at all if that specific backup's manifest was never encrypted (no backup passphrase
was set when it was taken) — the script reads it plain and recovers what it can from `config_settings_enc_key`
alone. Pass `--no-pre-restore-backup-confirmed` to skip the automatic pre-restore safety backup, same as
`restore.sh`.

Full flag reference, exact steps performed and in what order, and what each master-key-recovery mode can
and cannot guarantee: `sudo deploy/restore_admin_zip.sh --help`, and the doc comments at the top of
`restore_admin_zip.sh` and `deploy/lib/admin_auth_check.php` themselves.

**Standing up a brand-new server straight from an in-app backup:** `install.sh --restore-from` accepts a
`.zip` here too — only add `--restore-passphrase-file` if that specific backup had one set (a fresh box has
no admin account of its own yet, so `--admin-user` mode never applies here):

```bash
sudo deploy/install.sh --domain=rivetit.example.com \
    --restore-from=/path/to/itflow_20260101120000_manual.zip
```

**Testing a restore without touching a real instance:** same approach as `restore.sh` above — point
`--app-dir` at a disposable instance (`scripts/setup_cli.php --config-only` against a scratch database is
the quickest) and inspect/throw it away afterward.

---

## update.sh

```bash
sudo deploy/update.sh
```

Wraps `scripts/update_cli.php`: pulls application code updates, then runs any pending database
migrations from `admin/database_updates.php` up to `includes/database_version.php`'s
`LATEST_DATABASE_VERSION`.

**Recommended cadence:** monthly, or immediately after `deploy/harden.sh`'s unattended-upgrades has kept
the OS current but a known application-level fix has shipped. Always take a fresh `deploy/backup.sh` run
(or confirm the scheduled timer ran recently) before updating a production instance — a schema migration
is exactly the kind of change you want a tested rollback path for.

**Known issue: avoid `scripts/update_cli.php --force_update`.** It hardcodes `git fetch --all && git
reset --hard origin/master` — but this repository's real default branch is `main`, not `master`. In
practice that means `--force_update` will not track this repo correctly. `deploy/update.sh` uses a plain
`git pull` instead, which is branch-correct and is the recommended update path until that mismatch is
fixed upstream in `scripts/update_cli.php` itself (not something this deployment tooling patches around
— see `docs/ISO27001-COMPLIANCE.md`'s "Known gaps").

### Upgrading an EXISTING instance to authenticated KB media serving (database 2.6.78)

This one change has an order, and getting it wrong is the difference between a clean rollout and a
Knowledge Base full of broken images. A fresh `install.sh` needs none of this — it renders the vhost
template and imports `db.sql`, so it starts in the finished state.

1. **Deploy the code.** `agent/kb_media.php`, `client/kb_media.php`, `agent/kb_article_attachment.php`
   and `src/KB/` are what serve KB media with authentication. Nothing changes for users at this point:
   stored article HTML still points at `/uploads/kb/...`, the web server still serves that, and
   `api/v1/kb.php` deliberately keeps emitting those same URLs while it cannot sign, so the Android app
   is unaffected.
2. **Run the database update** (`deploy/update.sh`, or `scripts/update_cli.php --update_db`). The
   2.6.77 → 2.6.78 block adds `settings.config_kb_media_key` and rewrites every stored KB media URL onto
   the authenticated endpoint. It **refuses to run and modifies nothing** if step 1 has not happened —
   it checks that `agent/kb_media.php` is on disk first — so the order cannot be inverted by accident.
   It prints how many rows it migrated, and warns about any it deliberately skipped (a path shape
   neither importer produces; those images need re-inserting through the editor).
3. **Add the `/uploads/kb/` deny to nginx and reload.** Until this lands the old unauthenticated path is
   still open, so the defect is not actually closed; after it lands, the authenticated endpoints are the
   only way to the bytes. The block is already in `templates/nginx-vhost.conf.template`, so instances
   built by `install.sh` from this version have it; an existing hand-maintained vhost (or a shared
   `snippets/` include) needs it added by hand:

   ```nginx
   location ^~ /uploads/kb/ {
       deny all;
   }
   ```

   It must sit beside the existing `location ^~ /uploads/`, not inside it — nginx picks the longest
   matching prefix, which is what makes `/uploads/kb/...` land here while everything else (avatars,
   company logos, ticket and document files) keeps working untouched. Verify with `nginx -t`, reload,
   then confirm a KB media path returns 403 while `/uploads/users/<file>` still returns 200. If a shared
   snippet serves several vhosts the rule applies to all of them, which is harmless for any instance
   with no `uploads/kb` directory — it turns a 404 into a 403.

Rolling back is step 3 in reverse (remove the block, `nginx -t`, reload); the migrated URLs keep working
either way, because the authenticated endpoints do not depend on the deny.

---

## Security model

The full, control-by-control picture of what this tooling does and doesn't cover is in
[`../docs/ISO27001-COMPLIANCE.md`](../docs/ISO27001-COMPLIANCE.md) — read that for the honest version of
"is this secure," including what's deliberately out of scope and why.

A handful of manual follow-ups matter enough that every company deploying this should do them,
regardless of anything else in that document:

1. **Rotate the generated database password if it was ever displayed on a screen someone else could
   see.** `install.sh` generates it and writes it straight into `config.php` without printing it to the
   terminal or the install log — but if you ever typed `--password=...` on a command line yourself, or
   looked at it over someone's shoulder, treat it as exposed and rotate it.
2. **Enable "Enable Cron" in the app's own Settings once the instance is fully configured.** The system
   cron entry `install.sh` installs fires every 5 minutes unconditionally, but `cron/cron.php` does
   nothing at all until that setting is turned on — it defaults to off.
3. **Set up the backup passphrase file and store a copy of the passphrase somewhere other than the
   server itself**, per the `backup.sh` section above — this is the single most common way an encrypted
   backup ends up providing zero real protection.
4. **Review and enable MFA for the admin account.** RivetIT genuinely supports this — TOTP
   (via `plugins/totp`) and WebAuthn/passkeys are both real, working authentication methods, and MFA can
   be force-required per user from the user administration screens (`user_config_force_mfa`). None of
   this is turned on by default for the account `scripts/setup_cli.php` creates; do it as one of the
   first things after logging in for the first time.
