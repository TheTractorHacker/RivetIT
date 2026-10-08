# Installer and updater end-to-end harness

Proves `deploy/install.sh` and `deploy/update.sh` on a clean Ubuntu 24.04, for real (apt, PPA, composer from
GitHub, MariaDB, nginx, Redis, cron), in a throwaway systemd container. Same files in the RivetIT and RivetMSP
repositories; `run.sh` detects the edition.

```
tests/installer/run.sh                         # fresh + upgrade
tests/installer/run.sh --scenario=fresh        # or upgrade
tests/installer/run.sh --previous-tag=v26.10.5 # upgrade from another release (defaults: RivetIT v26.10.24, RivetMSP v26.10.6)
tests/installer/run.sh --keep                  # keep the image and work dir for debugging
```

Prints one `PASS:` / `FAIL:` line per check and exits non-zero if anything failed. Logs of the installer and
updater runs are left in `/tmp/rivet-inst-last-logs` after a failure.

## Requirements

- Docker that can run `--privileged --cgroupns=host` containers with systemd as PID 1 (cgroup v2 host), and
  your user able to run `docker` (or `sudo -n docker`).
- Outbound network from containers: archive.ubuntu.com, ppa.launchpadcontent.net (ondrej/php), github.com
  (RivetCore is installed by composer from GitHub), packagist.org.
- The previous release tag present in the checkout (`git fetch --tags`).
- Base image `jrei/systemd-ubuntu:24.04` (pulled automatically; plain Ubuntu 24.04 plus systemd).
- Run it as a normal user, from the repo you want to test. It snapshots the working tree (committed,
  uncommitted and untracked-but-not-ignored files), so you can test before committing. Nothing outside the
  container is touched; the repo is mounted read-only.

Runtime: about 8 to 15 minutes per scenario on an idle host with a warm download cache (`~/.cache/rivet-installer-harness`,
.deb and composer files only, safe to delete), 30 minutes or more on a loaded host with a cold cache. Two scenarios
per edition, so plan for roughly 20 to 60 minutes.

Not suited to CI: GitHub-hosted runners cannot reliably run privileged systemd containers. Run it by hand
(or on a self-hosted runner) before a release.

## What it does

A fresh container per scenario. The tested tree is committed as a snapshot into a bare "origin" inside the
container, on the edition's production branch, so `update.sh`'s `git pull` has a real remote and no GitHub push
is needed. Installs use `--non-interactive --skip-tls --skip-firewall` (self-signed cert; ufw cannot run in a
container) and talk to the app with `curl --resolve <domain>:443:127.0.0.1 -k`.

**fresh**: runs `deploy/install.sh`, then checks: nginx, php-fpm, MariaDB active; nginx config valid;
`https://<domain>/` serves the login page; schema version equals `includes/database_version.php`;
every RivetCore migration recorded in `rivet_core_migrations`; Redis answers on 6380 and is loopback-only;
`health/ready.php` ready and `health/live.php` 200; scripted admin login with the password given to the
installer; cron.d entry present and every job in it runs once as `www-data`; RivetCore in `vendor/` is the
version pinned in `composer.lock`; `config.php` not world-readable, `uploads/` writable by `www-data` only, no
world-writable files, tree owned by `www-data`. Then a second `install.sh` run (must be idempotent or refuse
cleanly: config.php untouched, no duplicate users, login still works) and a no-op `update.sh` run.

**upgrade**: runs the previous tag's own `deploy/install.sh`, inserts a client, ticket and webhook through SQL,
runs the NEW checkout's `deploy/update.sh --no-backup-confirmed`, then repeats the health checks and verifies:
code at the new commit, schema at the latest version, seeded rows and users intact, composer dependencies
refreshed, a second `update.sh` run changes nothing.

## Known limits

- `--skip-firewall` is used (no ufw in containers), so firewall setup is not exercised. fail2ban is installed
  but its jails are not exercised either. Let's Encrypt (certbot) is not exercised (`--skip-tls`).
- Mail, SSO, integrations and the web UI beyond login are out of scope (see `tests/browser` for the UI).
- The container image carries a shim (`shims/mariadb-install-db`) that is only active when the Docker host's
  AppArmor policy blocks MariaDB's first-run setup inside the container (hosts that themselves run MariaDB
  load a profile that leaks into containers). On other hosts it is a pass-through.
- The installer starts Redis on 127.0.0.1:6380 without a password (loopback only) and so does not write
  `/etc/rivetit/redis.env` / `/etc/rivetmsp/redis.env`; those files are for operators pointing the app at a
  different, authenticated or TLS Redis. The harness checks the loopback-only binding.
