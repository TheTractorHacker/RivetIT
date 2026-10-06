# Entra ID and Intune sync: what a real tenant needs

**Live-tenant behaviour is unverified.** The Microsoft Graph code (`src/Integrations/Microsoft/`) has been tested only against a
local mock (`tests/mock/graph_mock.php`), because no real tenant was available. Treat the first live sync as a test, using the
checklist at the end.

## What to set up in Microsoft

1. **App registration.** Entra admin center > Identity > Applications > **App registrations** > New registration. Single tenant is fine.
   No redirect URI is needed (this is an unattended, app-only sync).
2. **Tenant ID and Client ID.** On the app's Overview page copy **Directory (tenant) ID** and **Application (client) ID**.
3. **Client secret.** Certificates and secrets > New client secret. Copy the **Value** immediately (it is shown once). Secrets expire
   (6, 12 or 24 months): put the expiry in your calendar. An expired secret shows up as "Sign-in failed" in RivetIT.
4. **API permissions** > Add a permission > Microsoft Graph > **Application permissions** (not Delegated), then add:
   | Permission | Needed for |
   |---|---|
   | `User.Read.All` | Directory Sync (people from Entra ID) |
   | `DeviceManagementManagedDevices.Read.All` | Intune Device Sync |
   | `Organization.Read.All` | optional: lets Test Connection show the organisation name |
5. **Grant admin consent** for the tenant (the button on the API permissions page; a Global Administrator or Privileged Role
   Administrator is needed). Every row must show a green check "Granted for ...". Without this every call fails with 403.
6. Intune sync also needs the tenant to have **Intune** (a licence and the Intune service set up), or Graph answers that the request
   does not apply to the tenant.
7. In RivetIT: Administration > Integrations > Directory Sync, enter the tenant ID, client ID and secret (the secret is stored
   encrypted and never shown again), tick **Enable**, Save, then **Test Connection**. On the Device Sync tab, tick **Sync devices from Intune**.

RivetIT only reads. It never writes to Entra or Intune.

## What RivetIT does

- Tokens: one app-only token (client credentials), cached encrypted in the database and shared by cron, Test Connection and Sync Now.
  It is replaced 5 minutes before it expires, and dropped if a call returns 401.
- Throttling: HTTP 429/503/504 are retried up to 4 times, waiting the `Retry-After` time (capped at 60 s) or 1, 2, 4, 8 s. Requests
  time out after 30 s. A sync run has a 2 minute limit and will not wait past it.
- Paging: follows `@odata.nextLink` up to 500 pages, stops on a repeated link, and refuses a link to any other host (the token is
  never sent elsewhere).
- Errors are classified and shown on the Device Sync tab, in Recent Intune Syncs and in `intune_sync_log.error_code`:
  *Sign-in failed* (wrong tenant, client ID or secret; expired secret), *Admin consent missing* (names the permission, for example
  `DeviceManagementManagedDevices.Read.All`), *Throttled by Microsoft*, *Could not reach Microsoft*, *Not available for this tenant*
  (no Intune), *Unexpected response*, *Time limit reached*.
- **Test Connection** checks sign-in, lists the application permissions Microsoft put in the token, and makes a one-row call for each
  feature that is switched on, so a missing consent is named.
- **Sync Now** (Device Sync tab) and the cron job use the same service: one run at a time, same log, same errors.

Advanced: `config.php` may define `RIVETIT_GRAPH_BASE_URL` and `RIVETIT_GRAPH_AUTHORITY_URL` (for example for Microsoft's US Government
cloud, or a mock server). The web UI cannot change them. Leave them unset for normal tenants.

## First live sync: checklist

1. Back up the database first (Administration > Backup). The first sync creates assets.
2. Save the connection with **Sync devices from Intune** switched off, then press **Test Connection**. Expect "Connection successful".
   If not, read the message: it says which of tenant, secret or consent is wrong.
3. Switch Intune sync on, Save, press **Sync Now**. Expect "Intune sync complete: N created ..." and a green row in Recent Intune Syncs.
4. Spot-check 5 devices against the Intune portal: name, serial number, OS version, compliance state, primary user.
5. Check no duplicate assets were made for devices you already track (matching is by serial number, then by unique hostname). Devices
   with no serial and a hostname used twice will be created as new assets.
6. Press **Sync Now** again. Expect 0 created and every device updated, with the same asset count.
7. Open the Device Sync tab after a day: the cron run should show in Recent Intune Syncs with no errors.
8. On a large tenant, note how long the first run takes. If it hits the 2 minute limit it is logged as "Time limit reached".
9. Repeat steps 2-3 for Directory Sync if you use it.
10. Tell the maintainer what you saw: the real behaviour of paging, throttling and the exact permission error text is the part that
    has not been verified.

## Tests

`tests/graph_client.php` (token reuse and refresh, 429/503 retry, paging safety, error classification, sync idempotence) and
`tests/e2e/chat_webhooks.py` (Sync Now and Test Connection through the real pages). Both use mocks and a scratch database.
