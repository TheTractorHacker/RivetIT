# Deployment

This document is the narrative overview of how to get a RivetIT instance running, how to
choose between the two supported paths, and how backup/restore actually works end to end. It complements
two more detailed references rather than replacing them:

- [`deploy/README.md`](../deploy/README.md) — the full flag-by-flag reference for every script under
  `deploy/` (`install.sh`, `harden.sh`, `backup.sh`, `restore.sh`, `update.sh`).
- [`.env.example`](../.env.example) — every Docker Compose environment variable, with inline comments.

See [`ARCHITECTURE.md`](ARCHITECTURE.md) for how the codebase itself is organized, and
[`ISO27001-COMPLIANCE.md`](ISO27001-COMPLIANCE.md) for a control-by-control mapping of what this
deployment tooling covers from a security standpoint.

## 1. Two paths — which one do you want?

| | Docker Compose | Bare-metal (`deploy/install.sh`) |
|---|---|---|
| Best for | Trying it out, a lightweight self-hosted deployment, a homelab | A production instance you're running long-term |
| What it sets up | nginx + PHP-FPM + MariaDB + Redis in containers | nginx, PHP 8.4, MariaDB, TLS, and OS-level hardening directly on the box |
| TLS | None — put a reverse proxy in front | Built in (Let's Encrypt via certbot, or your own reverse proxy with `--proxy-mode`) |
| Hardening | None (a container has no fail2ban/ufw/unattended-upgrades equivalent) | `fail2ban`, `ufw`, PHP-FPM/MariaDB hardening drop-ins, unattended security upgrades |
| Update path | `git pull` + `docker compose up -d --build` | `deploy/update.sh` (pulls code, runs pending DB migrations) |
| Multi-company on one box | Not designed for it | Yes — re-run `install.sh` with a different `--domain`/`--db-name` |

If you're not sure, start with Docker Compose to evaluate the app, then move to `deploy/install.sh` for
anything you intend to keep running and trust with real data.

## 2. Docker Compose

```bash
git clone https://github.com/TheTractorHacker/ITFlow-Internal-IT.git
cd ITFlow-Internal-IT
cp .env.example .env
# edit DB_PASSWORD / DB_ROOT_PASSWORD, and DOCKER_UID / DOCKER_GID (run `id -u` / `id -g`)
docker compose up -d --build
```

(The clone directory is still called `ITFlow-Internal-IT` because that is the repository's name today; see
[`REBRANDING.md`](../REBRANDING.md#repository-and-links).)

Visit `http://localhost:8080/` (or whatever `APP_PORT` you set) — it redirects to the browser-based
`/setup/` wizard, the same one a manual/bare-metal install would use. Enter database host `db` and the
credentials from your `.env`.

**Architecture, briefly:** the `app` container runs nginx + PHP-FPM + a Redis instance (for live
ticket/chat updates) + a `cron/cron.php` loop, all under `supervisord`. Application code is **bind-mounted**
from your checkout (`.:/var/www/html`), not baked into the image — this keeps `config.php`, `uploads/`,
`backups/`, and `vendor/` on the host, persisting across `docker compose down` and image rebuilds, and
makes `git pull` the update path (matching `deploy/update.sh`'s model on bare metal, just without the
wrapper script). `DOCKER_UID`/`DOCKER_GID` in `.env` remap the container's `www-data` to your host user so
it can actually write into that bind-mounted checkout.

**Names.** The containers are `rivetit-web` and `rivetit-db` and the built image is tagged
`rivetit-web:local`. To run a second stack on the same host, set `RIVETIT_CONTAINER_PREFIX` in that stack's
`.env`; it prefixes both container names and the image tag, so the stacks neither collide nor overwrite each
other's image. The compose service keys (`app`, `db`), the `DB_NAME`/`DB_USER` defaults (`itflow`) and the
`itflow_db_data` volume keep the names they had before the RivetIT rename, so an existing stack comes back
up on its existing database. Upgrading such a stack: `git pull` then `docker compose up -d --build`; Compose
recreates both containers under the new names and reattaches the same volume. **A host that already runs
two or more stacks** must give every stack but one its own `RIVETIT_CONTAINER_PREFIX` before that
`docker compose up`: before the rename Compose named containers per project, and now the second stack
would stop with "container name /rivetit-db is already in use". Use `docker compose exec app …` /
`docker compose exec db …` (service names) in your own scripts rather than container names.

**No TLS termination and no hardening** are built into the container — this is intentional, to keep the
image simple and portable. Put a real reverse proxy (Traefik, Caddy, nginx-proxy, a cloud load balancer)
in front for anything beyond local evaluation.

### Restoring a backup instead of a fresh install

If you already have a `deploy/backup.sh` backup (see [§4](#4-backup--disaster-recovery) below) and want
this container to come up with that data instead of an empty instance:

1. Drop the backup file and its passphrase file under `./restore/` (already bind-mounted read-only into
   the container at `/var/www/restore`).
2. Set `RESTORE_FROM` and `RESTORE_PASSPHRASE_FILE` in `.env` to their in-container paths.
3. `docker compose up -d --build`.

The entrypoint writes `config.php` and hands off to `deploy/restore.sh` automatically on first boot; a
container that already finished setup (fresh or restored) skips this and starts normally. If a restore
attempt fails partway (e.g. a permissions problem with the passphrase file), it's safe to fix the issue
and just restart the container — it retries rather than getting stuck, because it checks whether setup
actually *completed*, not merely whether `config.php` exists.

Once the restore succeeds, **delete the backup and passphrase from `./restore/`** and remove
`RESTORE_FROM`/`RESTORE_PASSPHRASE_FILE` from `.env`. `./restore/` sits inside the same checkout nginx
serves; `docker/nginx.conf` denies `/restore/` and every dotfile (including `.env`), but a decryption
passphrase shouldn't outlive the one restore it was needed for.

## 3. Bare-metal (`deploy/install.sh`)

```bash
git clone https://github.com/TheTractorHacker/ITFlow-Internal-IT.git
cd ITFlow-Internal-IT
sudo deploy/install.sh --domain=rivetit.example.com
```

This provisions the whole box — packages, database, TLS/vhost, PHP-FPM/MariaDB hardening, `ufw`,
`fail2ban`, a cron entry — then runs the app's own first-run setup. See
[`deploy/README.md`](../deploy/README.md) for the full flag reference, worked examples (including adding
a second company's instance to a box that already runs one), and the hardening/backup/update tooling that
ships alongside it.

**Restoring onto a brand-new box** instead of a fresh company setup is a single extra pair of flags:

```bash
sudo deploy/install.sh --domain=rivetit.example.com \
    --restore-from=/path/to/backup-itflow-<timestamp>.tar.gz.enc \
    --restore-passphrase-file=/etc/itflow/backup-passphrase
```

This is the disaster-recovery path: box died, stand up a new one, get your data back. It skips every
company/localization/admin-user prompt — the restored data already has all of that — and provisions the
box exactly as a fresh install would otherwise.

## 4. Backup & disaster recovery

**Two independent backup systems exist. Knowing which one you're restoring matters — they use different
formats and different restore tools.**

### 4.1 In-app backup (quick snapshots)

Reachable from inside the app at **Settings → Backup**, this produces a plain (unencrypted) zip
containing `db.sql` + `uploads.zip` + `version.txt`. It runs two ways:

- On demand ("Download Backup" / "Save to Server").
- Automatically, once an hour, if enabled — and optionally pushed to S3-compatible remote storage (also
  configured under Settings → Backup).

**Restore it from the browser**: the `/setup` wizard has a "Restore from Backup" step (reachable from the
Welcome screen, or the `?restore` Utilities link) that accepts exactly this zip format. Useful for
"undo my last change" or moving a quick snapshot between instances — not encrypted, so it's not the tool
for genuine disaster recovery.

### 4.2 `deploy/backup.sh` (the actual DR mechanism)

Scheduled via a systemd timer, root-only, and **encrypted** — this is what the deployment tooling
considers the real disaster-recovery backup. It produces one file,
`backup-<database>-<timestamp>.tar.gz.enc`, containing the SQL dump, the full `uploads/` tree, and a
small `backup-manifest.json` (see below), all encrypted together with `openssl`. See
[`deploy/README.md`](../deploy/README.md#backupsh--systemd-timer) for setting up the passphrase file and
the scheduled timer.

**Restoring it** is `deploy/restore.sh` (a command-line tool, not the browser wizard — the `/setup`
`?restore` form does not accept this format):

```bash
sudo deploy/restore.sh --app-dir=/var/www/rivetit.example.com \
    --backup=/path/to/backup-itflow-<timestamp>.tar.gz.enc \
    --passphrase-file=/etc/itflow/backup-passphrase \
    --confirm-restore
```

`--confirm-restore` is mandatory — there is no interactive prompt to click through, since this replaces
every table in the target database and everything under `uploads/`. By default it takes a fresh safety
backup of the target's *current* state first and aborts before touching anything if that fails.

**Why the manifest matters:** `config_settings_enc_key` — the key that decrypts every stored SMTP/IMAP
password, RMM/UniFi API key, webhook secret, and the wrapped credential-vault master key — lives only in
`config.php`, which is deliberately never included in the backup itself (it also has instance-specific
DB credentials that shouldn't travel with the data). `backup-manifest.json` carries that key separately,
inside the same encrypted archive, so `restore.sh` can apply it to the new instance's `config.php` after
import. Without it, a restored instance's SMTP/RMM/webhook integrations would silently stop working — a
backup taken before this existed has no manifest, and `restore.sh` warns you to re-enter those secrets by
hand instead of guessing.

**Standing up a brand-new server from a backup** in one step: see [§3](#3-bare-metal-deployinstallsh)
above (`install.sh --restore-from`) or [§2](#restoring-a-backup-instead-of-a-fresh-install) (Docker's
`RESTORE_FROM`) — both call `restore.sh` internally after provisioning.

**Testing a restore without touching a real instance**: point `--app-dir` at a disposable one — either a
throwaway `install.sh` run with `--skip-tls --skip-firewall --skip-fail2ban`, or just
`scripts/setup_cli.php --config-only` against a scratch database — then inspect it and throw it away.
`restore.sh` never creates an instance from nothing; the target must already have a `config.php`.

## 5. Updating

- **Docker Compose:** `git pull` then `docker compose up -d --build`.
- **Bare-metal:** `sudo deploy/update.sh` — pulls code and runs any pending database migrations. Always
  take a fresh backup (or confirm the scheduled timer ran recently) before updating a production
  instance.
