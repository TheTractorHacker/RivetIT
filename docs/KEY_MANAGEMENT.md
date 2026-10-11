# Key management (RivetCore\Crypto, ADR-011)

RivetIT seals stored secrets (mail and API passwords, webhook secrets, OAuth tokens, two-factor seeds, the vault key) with one envelope from RivetCore:
`v3:<kid>:base64(nonce || tag || ciphertext)`, AES-256-GCM, the record's context bound in as authenticated data. Older values (`ENC2:`, `ENC:`, cleartext) stay
readable forever and move to v3 lazily and with a tool. Nothing changes on an existing install until an administrator creates the key file.

## The pieces

| Piece | Where | Notes |
|---|---|---|
| Key file | `/etc/rivetit/keys.json` (`$config_keyfile` in `config.php` overrides; `''` disables) | `root:www-data`, mode `0640`, outside the web root. Holds every key by id (`kid`). |
| Legacy key | `$config_settings_enc_key` in `config.php` | Stays. It becomes kid `k1`, opens `ENC2:`/`ENC:` values, and still derives other keys (training PINs, media tokens, audit chain). The backup fingerprint is unchanged. |
| Stage flags | `$config_crypto_v3_settings`, `$config_crypto_v3_totp` (optional) | Default: on when a key file exists. `false` switches a stage off again: new writes use the older form, v3 values stay readable. |
| Vault flag | Setting `config_vault_v3_enabled` | Default **off**. Never changed by an update. |
| Scripts | `scripts/keys_cli.php`, `scripts/rewrap_cli.php`, `scripts/vault_v3_cli.php` | Nothing prints key material. |
| Panel | Administration > Security > Encryption keys | Kids, fingerprints, age, values per key, rewrap status; rewrap, add key, make active, retire (admin only, CSRF, audited). The web server cannot write the key file, so add/activate/retire there need the CLI unless you give it write access to the directory. |

Contexts are frozen (changing one makes data unreadable): settings family `settings|generic`, vault key `settings|settings.vault_canonical_key`,
TOTP seed `totp|user:<id>`, instance data-key wrap `vault`, credential fields `credential:<id>:<username|password|otp>`, version snapshots
`credential:version-<version_id>:<previous_username|previous_password>`. The settings family shares one context because its ~90 call sites do not pass
the column; binding each column is a follow-up that needs them to.

## Upgrade an existing install (the manual step)

1. Deploy the release and run the database update (`php scripts/update_cli.php --update_db`). This widens the encrypted columns and adds the vault columns; it does not change any value. **Dump the database first.**
2. Create the key file (as root): `sudo php scripts/keys_cli.php generate` then `sudo chown root:www-data /etc/rivetit/keys.json && sudo chmod 0640 /etc/rivetit/keys.json`. The legacy key becomes `k1`; nothing is lost. From now on new writes are v3 and settings are moved lazily as they are read.
3. **Back the key file up offline now**, apart from the database dump and the backup passphrase. Without it, v3 secrets in a restored dump do not open.
4. Move everything at once (optional, it is also lazy): `php scripts/rewrap_cli.php --dry-run`, then `php scripts/rewrap_cli.php` (repeats until a pass changes nothing; resumable; one audit row per column). `php scripts/keys_cli.php status --inventory` shows the result.

Rolling back after step 2: set `$config_crypto_v3_settings = false;` in `config.php` (new writes are `ENC2:` again). v3 values already written are only readable by this release or later, so a downgrade of the code needs them moved back first or a restore from the pre-upgrade dump.

## Rotation

`keys_cli.php add-key` (new active key) -> `rewrap_cli.php --dry-run` -> `rewrap_cli.php` until 0 rewrapped -> take a fresh offline copy -> keep the old key for one backup cycle -> `keys_cli.php retire <kid>` (refused while any value still uses it). The panel warns when the active key is 12 months old. The key admin keeps `keys.json.bak-N` copies for undo; they hold key material, delete them after confirming.

## Credential vault v3 (flag off by default)

Order: key file -> `rewrap_cli.php` -> `vault_v3_cli.php prepare` (data key, wrapped by the key file; needs the canonical vault key) -> `rewrap_cli.php --vault --dry-run` -> `rewrap_cli.php --vault` -> `vault_v3_cli.php enable`.
With the flag on: credential fields are v3; each user's wrap becomes `vw3:` (Argon2id) at their next password login (only when their legacy key equals the canonical key); a password change rewraps the data key; a session holds both the data key and the legacy key; writes are converted to v3 right after they are saved; the mobile token wraps the data key (`api_tokens.token_enc_dek`); share links keep their own per-link key in the URL fragment. API keys keep their legacy wrap and take the data key from the instance wrap. Users.user_outlook tokens and the unifi sync still use the legacy key. Retiring the legacy key is a later step. Tokens issued before the flag was switched on have no data key and must sign in again to read v3 fields.

## Backup and recovery

The in-app zip manifest keeps `settings_enc_key_fingerprint` (formula `rivetit-settings-key-fingerprint|v1|`) and adds `keyring` (kid => fingerprint) and `keyring_active`; the key file is never in an archive. Escrow `keys.json` separately. Restore: `deploy/restore.sh --key-file=<offline copy>` or `deploy/restore_admin_zip.sh --key-file=<offline copy>` installs it (0640) after the import; `--key-file-dest` changes the target. The nightly restore drill checks that the key file here holds every key the backup names and opens a v3 sample secret.
