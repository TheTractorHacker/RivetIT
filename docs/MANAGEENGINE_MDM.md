# ManageEngine Mobile Device Manager Plus (cloud): read-only device sync

**Live-tenant behaviour is unverified.** This integration was written from ManageEngine's published API documentation and tested
only against a local mock (`tests/mock/manageengine_mdm_mock.php`). No real ManageEngine or Zoho account was used. Treat the first
live sync as a test, using the checklist at the end. Section "What the docs did not say" lists every fact that could not be confirmed.

It covers the **cloud** edition (Zoho sign-in). The on-premises edition (API key, your own server URL) and the MSP edition are not supported.

## What it does

- Lists your managed devices (iPads, Android tablets and phones, and anything else MDM Plus manages) and links each one to a RivetIT asset
  **by serial number** (IMEI and UDID are tried when a device has no usable serial).
- Shows the result on the asset page (a ManageEngine MDM panel), on the assets list (a small MDM badge), on a fleet page
  (Endpoints > MDM Devices: counts by platform, not compliant or lost, not seen in N days, unmatched) and on the training kiosk
  Devices list (an MDM status chip for a device whose asset is linked).
- **Read only.** It never locks, wipes, or changes a device, and never writes to ManageEngine. The code has no such call. Remote
  actions (lock, lost mode, wipe) are a possible later version; they would need write scopes that this version never requests.
- **Never writes an asset's own fields.** A sync only reads `assets` and writes its own link tables. A device that matches no asset
  (or more than one) goes to a review list; a person chooses **Create asset** or **Link to existing asset** (or Ignore).
- Off by default. Nothing is requested from ManageEngine until an administrator saves a connection and switches it on.

## Set it up

### 1. Create a Zoho self client (once)

ManageEngine's cloud MDM signs API calls with a Zoho OAuth token. A *self client* is the Zoho feature for "my own script calls my own
account": no redirect URL, no user in the loop.

1. Sign in to the **Zoho API console** for your data center with the same Zoho account that administers MDM
   (`https://api-console.zoho.com`; `.eu`, `.in`, `.com.au`, `.jp`, `.ca`, `.sa`, `.uk` for the other data centers).
2. **Add Client** > **Self Client** > Create. Open the new client and copy the **Client ID** and **Client Secret**.
3. Open the **Generate Code** tab and fill in:
   - **Scope** (exactly these two, comma separated, read only):
     `MDMOnDemand.MDMInventory.READ,MDMOnDemand.MDMDeviceMgmt.READ`
   - **Time duration**: the longest offered (the code is single use and short lived; you exchange it in the next step).
   - **Scope description**: anything, for example "RivetIT read-only device sync".
   - Click **Create** and copy the generated **code** (do not close the page until step 4 is done).
4. **Easiest:** skip this step. Paste the code from step 3 into the **One-time code from Zoho** box on the RivetIT form (with the client ID and secret) and click Save: RivetIT exchanges it for the refresh token itself and stores only the token. Do this within a few minutes; the code works once. (The code is not the refresh token: pasting it into the Refresh token box gives `invalid_code`.)

   Or exchange it yourself: Run this once from any machine (use your data center's accounts host, see the table below),
   replacing the three placeholders. The refresh token is in the answer; the access token in it is not needed.

   ```
   curl -s -X POST "https://accounts.zoho.com/oauth/v2/token" \
        -d "grant_type=authorization_code" \
        -d "client_id=YOUR_CLIENT_ID" -d "client_secret=YOUR_CLIENT_SECRET" \
        -d "code=THE_CODE_FROM_STEP_3"
   ```

   Copy the `refresh_token` value. It does not expire until you revoke it. Zoho keeps at most 20 refresh tokens per user: creating a
   21st deletes the oldest, so do not generate them casually.
5. In MDM Plus itself, make sure the Zoho user you used has permission to see the devices you want synced (an MDM administrator does).

### 2. Enter it in RivetIT

Admin > **Integrations** > **Device Sync** tab, ManageEngine MDM section (`/admin/settings_integrations.php?tab=devicesync`; administrators only).

| Field | What to enter |
|---|---|
| Data center | The region of your ManageEngine MDM account (see below). |
| Client ID / Client secret | From step 2 above. The secret is stored encrypted and never shown again. |
| Refresh token | From step 4. Stored encrypted, never shown again. Leave a field blank on later saves to keep the stored value. |
| Sync every (minutes) | 5 to 1440, default 60. Cron runs the sync when this much time has passed. |
| "Not seen" after (days) | 1 to 365, default 7. Drives the "not seen" card, filter and badge. |
| Fetch battery, storage, passcode and last contact | On by default: two extra read calls per device per sync. Turn it off for very large fleets or if ManageEngine throttles you. |
| Enabled | Off by default. |

Then **Save**, **Test connection** (proves the sign-in, the inventory scope with a one-row device list, and the device-management scope
with the first device's summary), and **Sync now**. After that cron keeps it current (it runs inside `cron/cron.php`, so there is
nothing extra to schedule).

### Which data center?

Use the region you sign in to ManageEngine MDM on. The key is a fixed list; free text is never accepted (this is also what stops the
integration being pointed at an internal address).

| Choice | MDM API host | Zoho accounts host (tokens) |
|---|---|---|
| United States (.com) | `mdm.manageengine.com` | `accounts.zoho.com` |
| Europe (.eu) | `mdm.manageengine.eu` | `accounts.zoho.eu` |
| India (.in) | `mdm.manageengine.in` | `accounts.zoho.in` |
| Australia (.com.au) | `mdm.manageengine.com.au` | `accounts.zoho.com.au` |
| Japan (.jp) | `mdm.manageengine.jp` | `accounts.zoho.jp` |
| Canada (.ca) | `mdm.manageengine.ca` | `accounts.zohocloud.ca` |
| Saudi Arabia (.sa) | `mdm.manageengine.sa` | `accounts.zoho.sa` |
| United Kingdom (.uk) | `mdm.manageengine.uk` | `accounts.zoho.uk` |

China (`.cn`) is not offered: ManageEngine lists an MDM host for it, but no Zoho accounts host for China could be confirmed.

## What is synced

For each device, stored in `asset_mdm_links` (matched) or `mdm_unmatched_devices` (review list):

| Stored | From | Endpoint |
|---|---|---|
| Device name, platform, OS version, model, product name, serial, UDID, IMEI, user name and email, owned by (corporate or personal), lost mode flag, removed flag | device list | `GET /api/v1/mdm/devices` |
| Supervised, passcode compliant, rooted, battery level, storage total and available, registered time, unregistered time | device details | `GET /api/v1/mdm/devices/{id}` |
| Last contact time, group names | device summary | `GET /api/v1/mdm/devices/{id}/summary` |

Not stored, deliberately: the raw responses (the details response contains an `apn_password` field), tokens, location, installed apps,
profiles. Nothing here is a secret, but only the fields above are ever kept.

How the status badge is decided (`MdmDeviceStatus::classify`, one place for every screen): *Not enrolled* if removed or unregistered;
*Lost mode* if the lost-mode flag is on; *Not compliant* if the passcode is reported non-compliant or the device is rooted; *Not seen N+
days* if the last contact is older than the setting; *OK* otherwise. "Compliance" here is only what the API reports about the passcode
and root state: ManageEngine's own compliance policies (geofence, fence policy) are not read.

### Matching rules

1. A device already linked (same ManageEngine device id) just has its row refreshed.
2. Otherwise the device's **serial number** is compared with `assets.asset_serial` (case-insensitive, non-archived assets only).
3. With no serial, or no asset with that serial: the **IMEI**, then the **UDID**, are compared with `assets.asset_serial` too (people record a
   phone's IMEI as its serial). Wi-Fi MAC is not used: the documented endpoints do not return it.
4. Blank or placeholder values ("unknown", "0", anything under four characters) are never match keys.
5. **Ambiguous means unmatched.** Two assets with the same serial, one serial on two live devices, or an asset that is already linked to
   a different live device: nothing is linked and the device waits in the review list with the reason (and the candidate asset ids).
6. A device that was re-enrolled (new ManageEngine id, old one gone from the list) takes over the existing link in place.
7. A device marked removed in ManageEngine keeps its link, shown as *Not enrolled*; a removed device with no link is skipped.

The review list is Endpoints > MDM Devices > Needs review. **Link to existing asset** lists unlinked tablet and phone assets (candidates
preselected); **Create asset** makes one asset (type Tablet for an iPad or tablet model, otherwise Mobile Phone; name, serial, model and
OS from the device, in the department you choose); **Ignore** hides a device until you restore it. Linking needs the Departments module
at level 2 and access to the asset's department. **Unlink** (asset page) removes a link; the next sync links it again if the serial still
matches.

## Limits and behaviour you should know

- **Rate limits.** The docs publish only the error (`COM0002`, HTTP 429, "API limit exceeded"), no number. The client waits out
  `Retry-After` (or backs off 1, 2, 4 s, capped at 60 s) up to four retries, then fails the run as *Throttled*; the next scheduled run tries
  again. A single 5xx (`COM0004`) is retried once. The detail and summary calls stop for the rest of a run after a throttle or a
  missing scope; the list data still syncs.
- **Zoho token limits** (documented): at most 10 access tokens per refresh token in any 10 minutes. RivetIT therefore keeps the one-hour
  access token (encrypted) and shares it between cron, Test connection and Sync now, and refreshes it five minutes before expiry.
- **Paging.** `limit`/`offset` on our own host: it asks for 100 devices per page and advances the offset by what it actually received (so a server that caps pages at its default of 50 is still read completely), at most 200 pages. The `paging.next` URL in a response is never
  followed (the access token only ever goes to the data center host). A server that ignores `offset` cannot loop the sync.
- **Time limit.** A run stops after 120 seconds (cron and Sync now). With very large fleets turn off the per-device extras.
- **One run at a time.** A second run is refused while one is in progress (a crashed run stops blocking after five minutes).
- **Failures keep old data.** A failed run changes no device rows; the last good data stays and the sync log (settings page, last eight
  runs) says what failed: *Sign-in failed*, *Scope missing*, *Throttled*, *Could not reach ManageEngine*, *ManageEngine error*,
  *Unexpected response*, *Time limit reached*.
- **Audit.** Saving settings, Test connection, Sync now, linking, creating an asset from a device, ignoring and unlinking are written to the
  action log (and the structured audit trail for the settings ones). Secrets, tokens and the Authorization header are never logged; the
  audit text names only *which* secret was replaced.
- **Not wired into the recovery "sync watch"** (the stale/failing alert for RMM, Intune, UniFi): a stalled MDM sync shows in the sync log
  on the settings page and in the application log, but sends no alert. A possible follow-up.

## Troubleshooting

| What you see | Meaning and fix |
|---|---|
| *Sign-in failed (invalid_code)* | Refresh token, client id or secret wrong, or the token was revoked. Check the data center matches the one you created the client in; make a new refresh token (step 3 and 4). |
| *Sign-in failed (invalid_client)* | Client id or secret wrong, or the self client was deleted. |
| *Zoho is limiting token requests* | More than 10 access tokens in 10 minutes. Wait ten minutes. It should not happen in normal use because the token is reused. |
| *Scope missing* / HTTP 403 | The code was generated without both scopes, or the Zoho user cannot see the devices. Generate a new code with both scopes and a new refresh token. |
| Test connection works, but there are no battery, passcode or last-contact values | The `MDMOnDemand.MDMDeviceMgmt.READ` scope is missing (summary calls fail), or *Fetch battery...* is off. The sync log shows the warning. |
| *Throttled* | ManageEngine returned 429 or 503 and did not recover. Raise the interval, or turn off the per-device extras. |
| *Could not reach ManageEngine* | The server cannot reach the data center host over HTTPS (firewall, DNS). |
| Every device is in the review list | The serials in Assets do not match the serials ManageEngine reports. Check one device side by side (iPad serials are the 10 to 12 character Apple serial, not the UDID). |
| A device is ambiguous | Two assets share the serial, or two live ManageEngine records do. Fix the duplicate, or use Link to existing asset. |
| Device was retired in ManageEngine but still shows | It shows *Not enrolled* and keeps its link by design; Unlink it on the asset page. |

## What the docs did not say (assumptions, all in code and testable)

Confirmed from ManageEngine's and Zoho's published pages: the OAuth flow (refresh token exchange, `Authorization: Zoho-oauthtoken <token>`,
one-hour access tokens, the limits above), the scope names, the base URL of each data center, `GET /api/v1/mdm/devices` (with its list of
filters and the fields in each row), `GET /api/v1/mdm/devices/{id}` and `/summary` with their fields, the `limit`/`offset` paging parameters
and the `metadata`/`paging` response objects (Pagination page), the error codes and the `error_code`/`error_description` error shape.

Could **not** be confirmed, and how the code copes:

- **Device list paging.** `limit`/`offset` are documented on the Pagination page, but the Devices page does not list them as parameters of
  the device list, and no maximum page size is stated (the docs' own example uses `limit=200`). The client sends `limit=100&offset=N`,
  stops on a short page, on a page with no new device id, or when `paging` carries no `next`. If ManageEngine ignores the parameters it
  returns one page and the sync stops after it; a fleet larger than one default page (50) would then be partial. Test with more devices than a
  default page.
- **`exclude_removed` / `include_all`.** Their accepted values are not documented, so they are not sent. Removed devices are recognised from
  `is_removed` (marked deprecated in the docs) and, in the details, `unregistered_time` > 0. If the list hides removed devices by default,
  they simply never show up; links of vanished devices keep their last data.
- **`managed_status`, `platform_type_id`.** Values are not defined in the docs; not used. `platform_type` (a string) is shown as is.
- **Where compliance and last contact live.** There is no single "compliant" field. Passcode compliance is `security.passcode_complaint`
  (spelled that way in the docs; the corrected spelling is accepted too) and root state is `security.device_rooted`, both only in the
  details response. `last_contact_time` is only in the summary response (a different scope). Lost mode is `is_lost_mode_enabled`
  (list and details). Group membership comes from the summary's `group` array. There are no compliance, lost-mode or groups endpoints
  beyond these.
- **Storage units.** `device_capacity` and `available_device_capacity` have no stated unit. They are stored raw and only the free
  percentage is shown.
- **IMEI.** Top-level in the list; inside `sims[]` in the details. Both are read. Wi-Fi MAC and Bluetooth MAC are not returned by any
  documented device endpoint, so they cannot be used for matching.
- **Accounts hosts.** The MDM page names the data centers' API hosts and uses `accounts.zoho.com` / `.in` in its examples. The Zoho
  accounts host of each other data center comes from Zoho's multi-data-center page (Canada is `accounts.zohocloud.ca`, which differs from the
  pattern). If your region's token request fails with a connection error, this table is the first suspect.
- **Request headers.** The docs' examples send no `Accept` header, so none is sent. `redirect_uri` is documented as not needed for self
  clients and is not sent on refresh.
- **Zoho token errors.** Zoho is known to answer token errors with HTTP 200 and `{"error": "..."}`; the client treats any answer without an
  `access_token` as a sign-in failure, and Zoho's "too many requests" text as throttling.
- **Rate limits.** No numeric limit is published for the MDM API (only the 429 / `COM0002` error).
- **Response wrapping.** The docs show `devices` as a top-level array in the list and a bare object for details and summary; the client also
  accepts a `device` / `summary` wrapper in case an edition wraps them.

## Checklist for the first live sync

1. Settings saved, **Test connection** green with "inventory ok, device management ok" (a red message names the missing scope).
2. **Sync now.** Open the settings page: the sync log shows the device count. It should equal the number of devices in the ManageEngine console
   (excluding removed ones). If it is exactly 50, the paging assumption above is wrong: stop and report it.
3. Endpoints > MDM Devices: pick one linked iPad, compare the battery, last contact, groups and passcode status with the ManageEngine
   console. Check an unmatched device's serial against the console.
4. Check a device you know is in lost mode, and one that is not compliant, shows the badge.
5. Look at the application log (Admin > Logs) after the first scheduled run: *ManageEngine MDM sync complete* with no warnings.
6. On a training iPad that is linked, open Training > Devices: the MDM chip should appear under the device.

## Files

`src/Integrations/ManageEngine/` (data centers, client, token store, mapper, sync service, status helper), `admin/includes/mdm_settings_panel.php` (the form, on Integrations > Device Sync)
and `admin/post/settings_manageengine_mdm.php`, `agent/mdm_devices.php` and `agent/post/mdm_device.php`, the panel in
`agent/asset_details.php`, the badge in `agent/assets.php`, the status chip in `agent/js/training_devices.js` (data from
`KioskAdminActions::kioskList`), the cron block in `cron/cron.php`, DB 2.6.168 in `admin/database_updates.php` and `db.sql` (four new
tables: `manageengine_mdm_integrations`, `asset_mdm_links`, `mdm_unmatched_devices`, `mdm_sync_log`; the secret columns are registered in
`src/Crypto/ColumnRegistry.php` for key rotation). Tests: `tests/manageengine_mdm_client.php`, `tests/manageengine_mdm_sync.php`,
`tests/manageengine_mdm_web.php`, with the mock in `tests/mock/manageengine_mdm_mock.php`.

Config-only overrides for testing against a mock (never in the web UI): `RIVETIT_MDM_BASE_URL` and `RIVETIT_MDM_ACCOUNTS_URL` in `config.php`.
