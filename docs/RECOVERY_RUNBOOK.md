# RivetIT recovery runbook

What to do, in what order, when the server is gone or the data is wrong, and how the backups that make that possible are monitored,
tested and copied off the box. Written for the person on call, so the recovery order (section 6) stands on its own.

Related: [DEPLOYMENT.md](DEPLOYMENT.md) (installing a host), `deploy/README.md` (the scripts), [REDIS.md](REDIS.md),
[ENDPOINT_AGENT.md](ENDPOINT_AGENT.md).

## 1. RTO and RPO statement (template)

Fill this in once, review it each quarter, and keep the filled copy with the key escrow (section 8). Numbers in *italics* are
measured by the system; do not guess them.

| | Target | Basis |
|---|---|---|
| **RPO** (data we can lose) | `__ hours` | Backup schedule: in-app `daily` (Admin > Backup) and `deploy/backup.sh` at 02:30. Worst case is one schedule interval plus the stale limit (26 h by default). If the off-site copy is the only survivor, add the off-site interval. |
| **RTO** (time to be working again) | `__ hours` | *Measured restore time* (Admin > Backup > Restore drill, "Last restore drill") **plus** host provisioning (`deploy/install.sh`, about `__` min), DNS/TLS, verification (section 6, step 10) and the human decision time. The drill measures the database import only; it is the part that grows with the data. |
| **Scope** | | Ticketing, assets, documentation, vault, billing, training, integrations (see section 3 for what is and is not in a backup). |
| **Owner / decision maker** | `__` | Who declares a recovery and who is the second pair of eyes. |
| **Last proven restore** | *date from the drill* and `__` (manual quarterly test, section 7) | |

Statement to give a customer or auditor, once the blanks are filled: "RivetIT is backed up daily with copies kept off the server. A
restore of the newest backup is proven every night into an isolated scratch database and the result (including restore time) is
recorded. Our recovery point objective is `__` hours and our recovery time objective is `__` hours; both are tested every quarter
by a full restore onto a clean server."

## 2. How backups are made, watched and tested

| Piece | Where | What it does |
|---|---|---|
| In-app backup | Admin > Backup; `cron/cron.php` or `cron/backup_cron.php` when scheduled | Zip with `db.sql`, `uploads.zip`, `version.txt` (SHA-256 of both, DB version, training-ledger head), the key manifest, and `table-snapshot.json` (table names and counts, no data). Readable by the web user. |
| Encrypted backup | `deploy/backup.sh` (systemd timer, root) | `backup-<db>-<UTC>.tar.gz.enc` (AES-256, passphrase file), a `.sha256` checksum file beside it, `root:root 600`. Optional off-site copy (section 5). |
| Status record | table `backup_runs` | Every run, either tool: started, finished, kind, file, size, SHA-256, ok, error, off-site result. A run that dies half way is closed as failed. |
| Failure alert | in-app notification, email, event | A failed run or off-site copy raises `backup.failed`. De-duplicated: one alert per problem per 6 hours. Email goes to the **Alert email** (Admin > Backup > Restore drill) or, if blank, every active administrator. |
| Stale alert | `cron/cron.php` (or `backup_cron.php` when the main cron is off) | The newest successful backup (record or file) older than **26 hours** (setting) raises `backup.stale`. |
| Sync alerts | same watcher | RMM, Intune and UniFi syncs: no run in 3x the interval, or the last 3 runs all errored, raise `integration.sync_stale` / `integration.sync_failed`. Intervals and the error count are settings; UniFi defaults to `auto` (inferred from its own scheduled runs). |
| Restore drill | `cron/restore_drill.php` nightly (in-app zip); `deploy/restore_drill.sh` (encrypted archive, root) | Section 4. Result in `restore_drill_log`, shown on Admin > Backup, and read by the Compliance check "A restore was proven in the last 35 days". |

Events `backup.failed`, `backup.stale`, `restore_drill.failed`, `integration.sync_failed`, `integration.sync_stale` can be sent to any
webhook or event rule (Admin > Webhooks / Event rules, group "Recovery").

## 3. What is, and is not, in a backup

| Item | In the in-app zip | In `backup.sh` archive | Notes |
|---|---|---|---|
| Database | yes (`db.sql`) | yes | One consistent snapshot. |
| `uploads/` | yes (`uploads.zip`) | yes (`uploads/`) | Includes training media. |
| `config.php` | **no** | **no** | Holds DB credentials and the settings key (KEK). Keep a copy in escrow (section 8). |
| Settings key (`$config_settings_enc_key`) | only inside the passphrase-encrypted `backup-manifest.json.enc` | **no**: a separate `backup-<db>-<timestamp>.settings-key` file next to the archive (0600; move it off the server); the archive manifest holds a fingerprint only | Without it, every stored secret (SMTP/IMAP, RMM, webhooks, vault master key, TOTP) is unreadable. For `backup.sh` archives the `.settings-key` file or your escrow copy is the only copy. The restore drill checks it against the archive (*Settings key for this backup*). |
| Backup passphrase | no | no | Never inside what it protects. Escrow. |
| Endpoint-agent binaries | no (`uploads/` only has what was uploaded there) | no | Re-publish (section 6, step 7). |
| `backups/rmm-state/` | no (excluded) | no | A cache of the RMM switch; regenerated (step 8). |
| Redis | no | no | Not needed: cache, locks and rate limits only. A restore starts with it empty. |
| nginx / PHP / MariaDB configuration, certificates | no | no | `deploy/install.sh` recreates them; custom vhost changes live in your config management or this repo's `deploy/templates`. |

## 4. The restore drill

### What it proves
Every night the newest backup is restored into a scratch database `drill_<yyyymmdd>` and checked:

1. archive opens; `db.sql` and `uploads.zip` SHA-256 equal `version.txt`;
2. restored schema version equals what `version.txt` recorded;
3. table list equals the live one (a missing table is a failure on the same schema, a warning if the backup is from an older schema);
4. row counts of 24 core tables (clients, contacts, users, tickets, assets, invoices, payments, documents, credentials, ...) within **5%**
   (setting) of the exact counts stored in the backup's `table-snapshot.json` (small tables get a 2-row slack; an emptied table always fails);
5. the training-ledger hash chain verifies end to end and its head equals the head recorded outside the database;
6. one stored secret decrypts with the current key;
7. `uploads.zip` opens, and a deterministic sample of files passes its CRC check.

It **always** drops the scratch database and deletes its temp files, records the measured restore seconds (the RTO figure), and raises
`restore_drill.failed` on a failure. It **never** starts the application against the scratch database and never runs integration
syncs, cron jobs, mail or webhooks against it: it uses plain SQL reads and the `mysql` client.

### Setup (once)
The drill uses its own database account, which can create and fill only databases named `drill_*`. It has no rights on the live
database, so a mistake cannot touch live data. As the database administrator:

```sql
CREATE USER 'rivet_drill'@'localhost' IDENTIFIED BY '<a long random password>';
GRANT ALL PRIVILEGES ON `drill\_%`.* TO 'rivet_drill'@'localhost';
FLUSH PRIVILEGES;
```

Then Admin > Backup > Restore drill: enter the user and password (the password is stored encrypted), tick **Enable**, Save, and press
**Run drill now**. Until the account exists the page shows "Not set up" with these steps, and the drill reports `not configured`; it
never fails silently. Needs the `mysql` or `mariadb` command-line client on the server and enough free disk in the temp directory for
`db.sql` (and for `uploads.zip`, which is only opened for the sample check when there is room).

The account name `drill_*` is a dedicated namespace: leftovers from an interrupted run are dropped at the next drill. No `CREATE USER`
privilege is needed or wanted; the scoped account itself is the throwaway identity.

Schedule: `deploy/install.sh` adds `restore_drill.php` to the instance's cron file (03:45; it does nothing while the drill is off). To
add it by hand: `45 3 * * * www-data /usr/bin/php <app>/cron/restore_drill.php >> /var/log/rivetit-backup-<name>.log 2>&1`.

### The encrypted archives (root)
`deploy/restore_drill.sh --app-dir=<app> --passphrase-file=/etc/itflow/backup-passphrase` drills the newest `backup-*.tar.gz.enc`: checks
its `.sha256`, decrypts into a private `0700` directory, hands it to `cron/restore_drill.php --extracted-dir=...`, and shreds the
decrypted copy. A checksum mismatch or a wrong passphrase is recorded as a failed drill. Timer: `deploy/templates/itflow-restore-drill.{service,timer}`
(03:30, an hour after the backup timer); `deploy/install.sh --install-backup-timers` renders both pairs.

## 5. Off-site copies of the encrypted backup

The local disk shares the server's fate. `deploy/backup.sh` copies each archive and its checksum off the machine when
`/etc/itflow/offsite.conf` exists (or `--offsite=<file>`; `--no-offsite` skips it for one run):

```
install -d -m 700 /etc/itflow
install -m 600 -o root -g root deploy/etc/offsite.conf.example /etc/itflow/offsite.conf   # edit
```

| Method | Needs | Credentials |
|---|---|---|
| `rclone` | `rclone` | rclone's own config (`rclone config`, run as root; optionally `OFFSITE_RCLONE_CONFIG`) |
| `s3` | `aws` CLI | AWS-format credentials file (`OFFSITE_S3_CREDENTIALS_FILE`); `OFFSITE_S3_ENDPOINT` for non-AWS S3 |
| `sftp` | `sftp` | a dedicated key file; the host key pinned in a known_hosts file |
| `local` | a mounted directory | none (make sure it is really mounted) |

After each local backup it uploads the archive and `.sha256` (an upload is renamed into place only when complete), verifies the size at
the destination, then deletes off-site archives older than `OFFSITE_RETENTION_DAYS` (default: `--retention-days`). Retention is judged from
the timestamp in the file name, touches only `backup-*.tar.gz.enc[.sha256]`, and never removes the last remaining archive. A failed copy
leaves the local archive intact, is recorded in `backup_runs.run_offsite_result`, raises `backup.failed`, and makes the script exit 3 so
the systemd unit shows as failed. The in-app backup has its own S3 upload (Admin > Backup > Remote Storage).

Prove the off-site copy works by restoring **from it**, not from the server (section 7).

### Installing the timers
`deploy/install.sh --domain=... --install-backup-timers` creates `/etc/itflow/backup-passphrase` if missing (**copy it offline
immediately**), and installs and enables `rivetit-backup-<domain>.timer` (02:30) and `rivetit-restore-drill-<domain>.timer` (03:30). It
skips a box that already has a backup unit for the same app directory. By hand: render `deploy/templates/itflow-backup.*` and
`itflow-restore-drill.*` with `sed 's|${APP_DIR}|/var/www/<site>|g'` into `/etc/systemd/system/`, `systemctl daemon-reload`, then
`systemctl enable --now <timer>`.

## 6. Full recovery order

Use this for a lost server, a ransomware rebuild, or a restore onto a new host. The order matters: keys before data, schema before
services, verification before anything talks to the outside world.

0. **Declare and freeze.** Decide who is running the recovery. If the old server is still reachable, stop its cron and web service so
   nothing writes while you copy. Note the time of the last good backup: that is your actual RPO.
1. **Get the material together.** Newest good archive (in-app zip, or `backup-*.tar.gz.enc` plus its `.sha256` from the off-site copy), the
   backup passphrase, the settings key and `config.php` from escrow (section 8). Verify the archive first: `sha256sum -c <file>.sha256`.
2. **Provision a clean host** of the same or newer PHP/MariaDB generation: `sudo deploy/install.sh --domain=<name> ...` (see
   [DEPLOYMENT.md](DEPLOYMENT.md)). With the encrypted archive you can do steps 3 to 5 in one go with `--restore-from=<archive>
   --restore-passphrase-file=<file>`.
3. **Restore the database.** Encrypted archive: `sudo deploy/restore.sh --app-dir=<app> --backup=<file.enc> --passphrase-file=<pp>
   --confirm-restore`. In-app zip: `sudo deploy/restore_admin_zip.sh --app-dir=<app> --backup=<zip> --confirm-restore`.
4. **Restore `uploads/`** (the same scripts do it; check that `uploads/` has files and is owned by the web user).
5. **Config and keys.** Put `config.php` (or at least `$config_settings_enc_key` and `$installation_id`) from escrow in place **before the
   application serves a request**. If the key is missing the restore "works" but every stored secret reads as empty.
6. **Bring the schema forward.** `sudo -u www-data php scripts/update_cli.php --update_db` (run from `scripts/`) if the archive is from an older
   version. Then `sudo systemctl reload php*-fpm`.
7. **Endpoint-agent binaries.** They are not in the database backup. Re-publish the current agent build for each architecture:
   `sudo -u www-data php scripts/endpoint_agent_publish.php dist/rivetit-agent-windows-amd64.exe --version <x.y.z> --arch amd64 --activate
   [--release stable]`, from the release artefacts ([ENDPOINT_AGENT_BUILD.md](ENDPOINT_AGENT_BUILD.md)). Installers already deployed keep working;
   new installers and self-updates need the binary published again.
8. **RMM state file.** `backups/rmm-state/rmm_state.json` is a cache and is not restored. It regenerates by itself: open Admin > Endpoint
   agent, or wait one cron tick. If `RMM_STATE_DIR` is customised, set it in `config.php` and the web server environment again (see
   [ENDPOINT_AGENT.md](ENDPOINT_AGENT.md)).
9. **Redis is not needed.** It holds only cache, locks and rate limits; start it empty (`deploy/install.sh` already provisions it).
10. **Verify before reconnecting anything.**
    - `/health/ready.php` is healthy; log in as an administrator; open a client, a ticket, an asset and a document with an attachment.
    - Admin > Backup > Run drill now passes against the newest backup on the new host.
    - Credentials vault: open a stored credential (proves the key is right). Training ledger: Admin > Training > verify.
    - Admin > Server Status shows no red.
11. **Only then re-enable the outside world, one piece at a time.**

> **DO NOT run Odoo or any other integration sync after a restore until step 10 has passed.** A restored database still carries
> the production integration settings. Syncs (and the queued email and webhooks that came with the backup) would talk to live systems from a host that
> may be a test copy, or replay work that was already done. On any restore that is not the one-and-only production server, first
> quarantine, in this order:
>
> ```sql
> UPDATE settings SET config_enable_cron = 0;
> UPDATE odoo_integrations SET enabled = 0;
> UPDATE rmm_integrations SET enabled = 0;
> UPDATE microsoft_integrations SET enabled = 0, intune_sync_enabled = 0, directory_sync_enabled = 0;
> UPDATE unifi_integrations SET enabled = 0;
> UPDATE webhooks SET webhook_enabled = 0;
> DELETE FROM email_queue;      -- mail that was queued at backup time must not be sent again
> ```
>
> Remove the standalone cron lines for the integration jobs as well, and re-enable each integration only after you have decided it is the
> source of truth again. Odoo write-back stays off until you have checked the target.

12. **Record it.** Write down the actual time taken per step and the real RPO in the quarterly record (section 7); update the RTO row in section 1.

## 7. Quarterly drill checklist

The nightly drill proves the data restores. This proves the **people, keys and off-site copy** work. Do it on a clean throwaway host (a
VM), using only what you would have in a disaster: the off-site copy and the escrow, not the production server.

- [ ] Date, person doing it, second person observing: `__________`
- [ ] Fetched the newest archive from the **off-site** destination (not from the server); `sha256sum -c` passes.
- [ ] Got the backup passphrase and the settings key from escrow without asking the usual administrator.
- [ ] Provisioned a clean host with `deploy/install.sh` (time taken: `__` min).
- [ ] Restored database and uploads (section 6, steps 3 to 6; time taken: `__` min). Quarantined integrations **before** first start (section 6, step 11).
- [ ] Re-published the endpoint-agent binary and confirmed an installer can be generated.
- [ ] Verification list (section 6, step 10) all green; credentials vault opens; training ledger verifies.
- [ ] Total time from "declared" to "verified": `__` h. Recorded as the measured RTO. Data age of the archive: `__` h (the measured RPO).
- [ ] Anything that was missing, unclear or slow is written down and fixed in this runbook or the tooling.
- [ ] Destroyed the throwaway host (it holds a copy of production data).
- [ ] Reviewed: escrow contents are current and two people can reach them; backup passphrase rotated if staff left; off-site credentials still valid; the 35-day
      Compliance check "A restore was proven" is green.

## 8. Key escrow

Backups are only as recoverable as the keys that open them, and the keys must **not** be recoverable from the same place as the backups.

| Secret | Why it is needed | Where it lives | Escrow |
|---|---|---|---|
| Settings key (`$config_settings_enc_key`, in `config.php`) | Opens every stored secret: SMTP/IMAP, RMM, webhooks, TOTP, and the vault master key wrap | `config.php` | Offline copy; `deploy/backup.sh` also writes it to a separate `.settings-key` file, never into the archive |
| `config.php` | DB credentials, `installation_id`, the settings key | server only | Offline copy of the file or its non-default values |
| Backup passphrase (`/etc/itflow/backup-passphrase`, and the one set on Admin > Backup) | Opens `backup-*.enc` and the manifest | root-only file; saved encrypted in settings | Offline; rotate when staff change |
| Drill DB account | Runs the restore drill | `recovery_settings` (encrypted) | Not critical: recreate with the GRANT in section 4 |
| Off-site credentials (rclone / aws / SFTP key) | Reading the off-site copy on a new host | `/etc/itflow/` | In the password manager; test access during the quarterly drill |
| TLS certificates / DNS access | Serving the new host | certbot / registrar | Registrar account in escrow; certificates are re-issued |

Guidance:

1. **Two copies, two places, two people.** A sealed printout in a safe plus an entry in the team password manager vault, readable by at
   least two people. Neither the password manager nor the safe is on the server being protected.
2. **Never store a key next to the backup it opens.** An off-site bucket that holds both the archives and the passphrase protects
   nothing. Keep the passphrase and settings key out of that bucket and out of the same account.
3. **Test the escrow, not just the backup.** The quarterly checklist makes someone other than the usual administrator fetch the keys.
4. **Rotate on departure.** When someone who knew the passphrase leaves, change it (and re-encrypt or retire old archives by your retention window).
5. **Write down the fingerprint** (first 8 characters of the SHA-256 of the settings key) beside the escrowed key so a wrong copy is noticed
   immediately, not during a disaster.
