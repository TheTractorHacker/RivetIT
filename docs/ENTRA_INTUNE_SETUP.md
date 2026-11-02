# Entra ID and Intune sync: what a real tenant needs

**Live-tenant behaviour is unverified.** The Microsoft Graph code (`src/Integrations/Microsoft/`) has been tested only against a
local mock (`tests/mock/graph_mock.php`), because no real tenant was available. Treat the first live sync as a test, using the
checklist at the end. This applies to everything below, including the newer pieces (incremental sync, groups, licenses, Intune
compliance and apps, secret-expiry lookup): they are built and mock-tested, **UNVERIFIED LIVE**.

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
   | `Organization.Read.All` | License sync (SKU counts, `/subscribedSkus`); also lets Test Connection show the organisation name. Without license sync it stays optional |
   | `Group.Read.All` | only for **Sync Entra groups and their members** |
   | `Application.Read.All` | optional: only for **Look up expiry in Entra** (reads this app registration's own secret expiry). Without it, type the date in by hand |

   Group sync, license sync and the expiry lookup are separate switches and all **off by default**: grant only what you switch on.
   Intune compliance policy states and detected apps use the `DeviceManagementManagedDevices.Read.All` permission you already granted for device sync.
   (Whether Microsoft wants `DeviceManagementConfiguration.Read.All` for the policy states of a device is **not verified**: if a live run answers 403, the message names the permission it wants. Likewise `LicenseAssignment.Read.All` is the narrower alternative to `Organization.Read.All` for `/subscribedSkus`.)
5. **Grant admin consent** for the tenant (the button on the API permissions page; a Global Administrator or Privileged Role
   Administrator is needed). Every row must show a green check "Granted for ...". Without this every call fails with 403.
6. Intune sync also needs the tenant to have **Intune** (a licence and the Intune service set up), or Graph answers that the request
   does not apply to the tenant.
7. In RivetIT: Administration > Integrations > Directory Sync, enter the tenant ID, client ID and secret (the secret is stored
   encrypted and never shown again), tick **Enable**, Save, then **Test Connection**. On the Device Sync tab, tick **Sync devices from Intune**.

By default RivetIT only reads. It never writes to Entra or Intune unless you switch on the optional account actions below.

## Optional: letting lifecycle workflows change Entra accounts

> **Warning.** This is a different kind of access. `User.ReadWrite.All` and `Group.ReadWrite.All` let this app registration create,
> change and **disable any user** and change **any group's membership** in your tenant, so anyone who obtains its client secret
> can too. Add them only if you want the lifecycle workflow actions (create account, disable account and revoke sessions, add to
> groups). If you do not, leave them out: everything else in RivetIT keeps working with the read permissions above.

1. Add two more **Application permissions** (Microsoft Graph) to the same app registration and grant admin consent:
   | Permission | Needed for |
   |---|---|
   | `User.ReadWrite.All` | create a user, disable sign-in, revoke sign-in sessions |
   | `Group.ReadWrite.All` | add a user to a group |
2. In RivetIT: Administration > Integrations > Directory Sync, tick **Allow RivetIT to change Entra accounts** and Save (administrators
   only; it is **off by default** and logged when changed). While it is off every write is refused inside the Graph client itself,
   before any request is made, so nothing can leave even if a template contains an Entra action.
3. Use the actions in Employee Workflow Templates, see docs/EMPLOYEE-LIFECYCLE-WORKFLOWS.md. Always **Preview** first: it shows
   exactly what would be sent and sends nothing.

Notes: Microsoft refuses to change some privileged accounts (for example a Global Administrator) with these permissions alone; that
shows as a failed task, not as a silent skip. RivetIT never deletes an account. A missing consent is reported as "Admin consent
missing for User.ReadWrite.All" (or `Group.ReadWrite.All`). The write code is tested only against `tests/mock/graph_mock.php`.

## Scheduled and incremental sync (all opt-in, all off by default)

Administration > Integrations > Directory Sync > **Entra scheduled and incremental sync**. Nothing runs from cron until you switch it on and save.
The Intune device sync keeps running on every cron tick as before.

| Switch | What it does | Needs |
|---|---|---|
| Run the directory sync automatically | cron runs the Entra directory sync every 1 h to once a day (never more often than every 15 minutes) | "Sync users from Entra ID" on; `User.Read.All` |
| Incremental sync (Graph delta query) | The first run reads every user and saves a **delta token**; later runs read only users that changed. A user deleted in Entra is archived and marked terminated (never deleted), the same as a disabled one. If Microsoft answers that the token is invalid (HTTP 410 `syncStateNotFound`), the run starts over with a full read by itself and says so in the log. **Full resync** (sync status page) drops the token on purpose. Off: every run reads every user, exactly as before. | `User.Read.All` |
| Sync Entra groups and their members | Groups (security, Microsoft 365, distribution) and their **user** members, into `entra_groups` / `entra_group_members`. Nested groups and devices are not followed. Member lists are read for up to 300 groups per run, oldest first. | `Group.Read.All` |
| Sync license SKUs and who holds them | `/subscribedSkus` purchased/consumed counts and each user's assigned SKUs, into `entra_licenses` / `entra_user_licenses`. SKU part numbers are shown in words for about 25 common SKUs, others by part number. | `Organization.Read.All`, `User.Read.All` |
| Read compliance policy states and detected apps (Device Sync tab) | Per Intune device: each compliance policy and its state, and the detected apps (name, version, publisher). Up to 200 devices per run, never-read first. Shown on the asset page and on the sync status page. | `DeviceManagementManagedDevices.Read.All` |

Groups, licenses and Intune details share one interval (6 hours to a week, default daily). Every one of these writes only to RivetIT's own tables
(plus, for the directory sync, departments and contacts exactly as before). Nothing is ever written to Microsoft.

Email changes: from DB 2.6.236 the directory sync remembers which Entra user each contact came from (`microsoft_directory_users`). A mailbox rename
therefore updates the contact it belongs to instead of creating a second contact. Contacts that existed before are linked the first time they match by email.

## Sync status, previews (dry run) and Full resync

Administration > **Entra / Intune sync status** (`/admin/entra_status.php`, linked from both tabs) shows for every sync: whether it is on, its schedule,
the last real run (when, cron or manual, mode, counts), the classified error with the last success beside it, and when the next scheduled run is due.
Below it are the license table (used / bought / free), the Intune compliance and app totals, and the group list.

**Preview** (on the status page and next to each Sync Now button) runs the whole sync inside a database transaction that is rolled back: the counts
are the real ones, and nothing is changed (no contacts, assets, links, departments, delta token or last-sync time). Previews are logged
(`dry_run = 1`), listed on the status page separately, and ignored by the stalled-sync watch, so a preview can never make a dead sync look alive.

## Client-secret expiry, alerts and health checks

- Enter the secret's expiry date next to the secret (Directory Sync tab), or press **Look up expiry in Entra** (needs `Application.Read.All`; the app finds its
  own registration by client ID and matches the secret by its first 3 characters). Saving a new secret clears the old date. A date typed in by hand is
  never overwritten by a lookup, and cron only refreshes a date that was looked up before.
- The recovery watch (cron) alerts the administrators (in-app notification, email, event `integration.secret_expiring`) **30 days before**
  (repeated weekly), **7 days before** and **daily once expired**, and clears the alert when the secret is renewed.
- A **stalled or failing sync** raises the usual `integration.sync_stale` / `integration.sync_failed` alerts: the scheduled directory sync (only while its
  switch is on), the extras (groups/licenses/Intune details, only while one is on) and Intune are watched from their sync logs
  (Admin > Backup > Integration sync watch, with Mute / expected interval / Forget history as for the other sources).
- **Admin > Update > Checks** and **Admin > Server status** show two lines while the connection is enabled: the secret expiry (OK / due soon / problem / no date recorded)
  and whether any Entra/Intune sync is failing or stopped.

## Per-client (multi-tenant) credentials for MSPs: not built, plan

The model here is one Microsoft connection for the whole install (`microsoft_integrations`, newest row wins, read in about fifteen places). An MSP that
wants one tenant per client cannot get that from a small additive column, because the directory sync turns Entra departments into clients and assets from
Intune have no client of their own. The plan, kept in docs/ROADMAP.md: add `microsoft_integrations.client_id` (nullable, NULL = the global connection),
make every reader take an integration id (the services here already do), run the schedulers per row, map every created contact/asset to that client
instead of to departments, and show a per-client row on the status page. It touches the MSP edition's client scoping, so it is scheduled with the RivetMSP port, not built here.

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
  (no Intune), *Unexpected response*, *Time limit reached*, *Delta token reset* (handled by a full read; only shown if the full read also failed).
- **Test Connection** checks sign-in, lists the application permissions Microsoft put in the token, and makes a one-row call for each
  feature that is switched on (users, devices, groups, licenses), so a missing consent is named.
- **Sync Now** (Device Sync tab) and the cron job use the same service: one run at a time, same log, same errors.

Advanced: `config.php` may define `RIVETIT_GRAPH_BASE_URL` and `RIVETIT_GRAPH_AUTHORITY_URL` (for example for Microsoft's US Government
cloud, or a mock server). The web UI cannot change them. Leave them unset for normal tenants.

## First live sync: checklist

Everything here is **UNVERIFIED LIVE**. Do it in this order, on a real tenant, and note what differs from the "expect" column. Back up the database first
(Administration > Backup): the first sync creates departments, contacts and assets.

### A. Connection (Entra admin center)

1. App registration, tenant ID, client ID, client secret (value copied once). Write the secret's expiry date down.
2. API permissions > Microsoft Graph > **Application permissions**, add exactly these, then **Grant admin consent** (every row shows a green check):
   `User.Read.All`, `DeviceManagementManagedDevices.Read.All`. Add `Group.Read.All` and `Organization.Read.All` only if you will try group / license sync,
   and `Application.Read.All` only for the expiry lookup.
3. RivetIT: Administration > Integrations > Directory Sync: tenant ID, client ID, secret, **the expiry date**, Enabled. Save, then **Test Connection**.
   Expect "Connection successful". Wrong tenant/ID/secret = "Sign-in failed"; a missing consent names the permission.

### B. Directory sync, full, then incremental

4. Tick "Sync users from Entra ID", Save. Press **Preview**. Expect "Preview (nothing was changed): mode full, N users read ...". Check the sync status page shows it as a preview, and that Contacts did not change.
5. Press **Sync Now**. Expect the same counts as the preview, contacts and departments created. Spot-check 5 people (name, email, department, job title, phone) against the Entra portal.
6. Press **Sync Now** again. Expect 0 created, N matched.
7. Tick **Incremental sync**, Save, **Sync Now** (baseline): log shows mode full and a delta token is saved (the Directory Sync tab shows "saved <time>").
8. In Entra change one person's department and phone, rename one mailbox, disable one account. **Sync Now**: expect "mode delta, 3 users read", the department/phone updated, the renamed
   mailbox **updating the same contact** (no second contact), the disabled one archived. Then delete a test user in Entra and sync again: expect "1 archived because deleted in Entra".
   (Microsoft may take a few minutes to show changes in a delta round; `@removed` for a deleted user is the part most worth watching.)
9. **Full resync** on the status page: expect mode full and a new token. To test the fallback you cannot easily make Microsoft expire a token on demand: note that the 410 path is only mock-tested.

### C. Scheduled directory sync

10. Tick "Run the directory sync automatically" every hour, Save. After the next cron tick(s) the status page shows a run with trigger `cron`; Admin > Logs (App log, category Cron) shows "Entra directory sync complete".
11. Untick it and confirm no new cron run appears. Leave it on only if you want it.

### D. Groups and licenses

12. Add `Group.Read.All`, admin consent, tick "Sync Entra groups", Save, **Preview groups** then **Sync groups**. Compare the group list and 3 member counts with the portal. Expect member counts of **users only**
    (nested groups and devices are not counted; that is a documented limit).
13. Add `Organization.Read.All`, tick license sync, **Preview licenses** then **Sync licenses**. Compare Used / Bought for 3 SKUs and the "people hold it" number with Microsoft 365 admin center > Billing > Licenses.
    If Microsoft answers 403 even with `Organization.Read.All`, the error names the permission it wants (probably `LicenseAssignment.Read.All`): tell the maintainer.

### E. Intune: device sync preview, compliance policies and apps

14. Device Sync tab: **Preview** first (expect counts, no new assets), then **Sync Now**; spot-check 5 devices (name, serial, OS version, compliance, primary user), and that no duplicate assets were made.
15. Tick "Also read compliance policy states and detected apps", Save, **Preview** then **Read details now**. Expect up to 200 devices read, and on a device's asset page its policies (coloured by state) and a "N detected apps" list.
    Compare 3 devices' policies and apps with Intune. 403 here names the permission; 404 per device is skipped quietly (device left Intune).
    Large fleets: devices are read oldest first, so several daily runs are normal; the status page shows "N of M devices have had their policy states and apps read".

### F. Secret expiry, alerts, health

16. Enter an expiry date 20 days ahead, Save. Run the cron job (or wait): you should get **one** notification (and email) "expires in 20 days" and nothing more for a week. Admin > Update > Checks and Admin > Server status show it as "due soon".
    Set it 5 days ahead: a second, more urgent alert. Set it far ahead: the alerts clear. (Do not set the real secret's date wrong for long; put it back.)
17. Press **Look up expiry in Entra** with `Application.Read.All` granted: expect the date to match the portal; without the permission expect the plain "Entra did not let the app read its own registration" message and your typed date untouched.
18. To see the stalled-sync alert for real: leave the scheduled directory sync on, stop the cron job for 3 x the interval, and expect "Entra directory sync has stopped" under Admin > Backup > Integration sync watch (and the notification). Restart cron.

### G. What to send back

19. The exact text of any error you saw, which permission it named, how long the first full directory sync and the first details run took, and anything on the status page that looked wrong. Paging, throttling, delta
    behaviour (especially removed users and the 410 token reset), and the licence/policy-state permissions are the parts nothing here could verify.

## Tests

`tests/graph_client.php` (token reuse and refresh, 429/503 retry, paging safety, error classification, sync idempotence), `tests/entra_actions.php` (account writes: setting gate, dry run, idempotent create, disable + revoke, consent errors, password handling),
`tests/entra_sync.php` (delta sync with fallback, previews, groups, licenses, Intune compliance and apps, secret expiry and its alerts, the stalled-sync watch, health checks, the scheduler) and
`tests/e2e/chat_webhooks.py` (Sync Now and Test Connection through the real pages). All use `tests/mock/graph_mock.php` and a scratch database; none contacts Microsoft or sends mail.
