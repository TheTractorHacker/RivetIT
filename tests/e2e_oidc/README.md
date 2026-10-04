# End-to-end OpenID Connect test (scratch installs only)

Drives the real login screens in a browser against a mock OpenID Connect provider. It covers Department Portal and
agent company SSO: configuring the provider, linking agents, local two-factor after SSO, refusals (unlinked,
disabled, administrator, wrong account type, replayed callback) and the Department Portal regression.

**Run it only against a throwaway RivetIT install and database.** It creates and deletes users and changes the
identity-provider settings. Never point it at a real installation.

Needs: Python 3 with `playwright` (and its Chromium), `PyJWT`, `cryptography`; `openssl`; the `mysql` client.

1. Make a scratch install with its own database (for example with `scripts/setup_cli.php`) and set its
   `$config_base_url` to `localhost:9444`.
2. Create a throwaway CA and a `localhost` certificate (`ca.pem`, `srv.pem`, `srv.key`) in this folder with `openssl`.
3. Serve the scratch install with PHP's built-in server on `127.0.0.1:9412`, adding
   `-d curl.cainfo=ca.pem -d openssl.cafile=ca.pem` so RivetIT trusts the throwaway CA.
4. From this folder: `python3 mock_idp.py` (provider on `https://localhost:9443`) and `python3 tls_proxy.py`
   (HTTPS front for the app on `https://localhost:9444`).
5. `E2E_DB_PASSWORD=... python3 e2e.py` (optional: `E2E_DB_USER`, `E2E_DB_NAME`, `E2E_ADMIN_EMAIL`,
   `E2E_ADMIN_PASSWORD`). It prints PASS/FAIL per check and exits non-zero if any fail.
