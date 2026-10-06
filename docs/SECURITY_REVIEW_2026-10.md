# Security review follow-ups (2026-10)

Status of items from the 2026-10-05 review that are documented rather than code-fixed.

## F-04 Vault key design (server-trust model)
The credential vault uses one shared master key. A canonical copy is stored in `settings.config_vault_canonical_key`, wrapped with
`$config_settings_enc_key` (AES-256-GCM, key in `config.php`); passkey logins re-derive the vault from it. Per-user password
wrapping therefore does not protect against an actor holding DB read access plus `config.php` (or a backup containing both).
Operators should treat the server as trusted for vault confidentiality: keep `config.php` mode 0640, keep it out of backups
that sit next to DB dumps, and encrypt backups. Possible hardening (not done, needs a data migration and a versioned prefix such
as the existing "V2:" scheme): AES-GCM for credential blobs, PBKDF2 at 600k+ iterations, opt-in canonical key.

## Tracked `vendor/`
`vendor/` is tracked in git and can drift from `composer.lock` (HEAD once carried rivet-core v0.8.0 while source needed 0.17.x).
Not rewritten here. Recommended: either commit regenerated `vendor/` together with the lock in the same commit, or stop
tracking it and run `composer install --no-dev --optimize-autoloader` in deploy; add a CI check that `composer.lock` and
`vendor/composer/installed.json` agree.

## Deferred
- `client/login_reset.php` (F-05: reset token expiry, password validation, mail throttle): intentionally untouched.
- Remember-me skipping 2FA (F-08): needs an admin toggle/product decision. `session.use_strict_mode=1` is a php.ini change.
- GitHub Action SHA pinning (F-15): needs network to resolve SHAs; Dependabot config added to keep tags current.
- F-09, F-10, F-11, F-12, F-13, F-16 not addressed in this branch.
