# ManageEngine Mobile Device Manager Plus: read-only device sync (on-premises and cloud)

**Live-server behaviour is mostly unverified.** This integration was written from ManageEngine's published API documentation and tested against a
local mock (`tests/mock/manageengine_mdm_mock.php`). One first live run of the cloud edition happened (it showed that `is_removed` arrives as the string `"false"`, now handled); the **on-premises edition, MSP mode, asset creation and stale-device retirement have never run against a real server**. Treat the first live sync as a test, using the
checklist at the end. The section "What the docs did not say" lists every behaviour that was taken from the documentation only or assumed.

Two editions are supported: **on-premises** (your own server, an API key) and **cloud** (Zoho sign-in). Either can be the **MSP edition** (one
server, many customers). Infrastructure > Assets stays the single inventory: a device either links to an existing asset or creates one; there is no second
device list.

## What it does

- Lists your managed devices (iPads, iPhones, Android phones and tablets, anything else MDM Plus manages) and links each one to a RivetIT asset, in this order:
  an existing link, **serial number**, **IMEI**, **UDID**, then a **unique asset with the same name in the device's department**.
- Creates an asset when nothing matches (switch **Create and maintain assets**, on for a new connection; an upgraded connection keeps it off, and with it off a sync writes nothing on an asset, as before): type **Phone**
  for iPhones and Android phones, **Tablet** for iPads and Android tablets, **Laptop** for Windows / macOS / ChromeOS, **Display** for TV platforms,
  **Other** otherwise. Name, serial (the IMEI when there is no serial), make, model and OS come from the device.
- **Never overwrites what a person typed.** With **Create and maintain assets** on, make, model and OS go through the same rule the RMM and Intune syncs use (`asset_sync_state`): a field is written when
  it is blank or still holds the value the last sync wrote; a human edit is left alone for good. The device's user becomes the asset's contact only when exactly
  one non-archived contact of the asset's department has that e-mail address, and never replaces a contact a person chose (the Intune rule).
- Shows the result on the asset page (an MDM card: status, compliance, last contact, OS, owner type, enrolled date, user, groups, battery, storage, link to the
  console), on the assets list (a small MDM badge), and on **Mobile devices** (menu group "Managed devices"): counts, filters (department, platform, state:
  compliant / not compliant / lost / not seen, last contact older than N days, search by name, serial, IMEI, UDID, user, model), and the review list.
- **Read only.** It never locks, wipes, locates or changes a device and never writes to ManageEngine. The code has no such call. Lock, lost mode and wipe are
  planned for later as approved jobs; they would need write permissions that this version never requests.
- **Optional mapping features** (see "Mapping options"): a user-to-contact table, a device-to-asset mapping screen and field mapping that can write (or overwrite) more asset fields. Every one is **off until an administrator turns it on**. They sit on top of the always-on, never-overwrite behaviour above.
- Off by default. Nothing is requested from ManageEngine until an administrator saves a connection and switches it on.

## Permissions (decision)

The existing `module_client` permission is reused instead of adding a module: it already governs the Intune devices page and the departments and assets these
rows point at, and a new module would have to be added to every role matrix (Admin and Technician must not change). Level 1 sees the list and the asset card,
level 2 links, creates, ignores and unlinks, level 3 and administrators configure the connection (settings, Test connection, Sync now). A user limited to some
departments only sees those departments' devices and never the review list.

## Set it up

### On-premises edition (API key)

1. On the MDM server: **Admin** tab > **Integrations** > **API Key Generation** > **Generate Key**; give it an application and service name and the permissions it
   needs (read access to devices: list, details and summary; for MSP also customers). The key is valid **6 months**.
2. In RivetIT, Administration > Integrations > Device Sync > ManageEngine: Edition **On-premises**, **Server address** `https://mdm.example.com:8383` (scheme, host
   and optional port only; RivetIT adds `/api/v1/mdm`), **API key**, **API key expires on** (the date 6 months from generation: RivetIT warns 30 days before and
   when it has expired, as a notification once a day, and on this page), then Save, **Test connection** (shows the device count) and **Sync now**.
3. The key goes in the `Authorization` header only (the docs: "API key can be passed only in header"), is stored encrypted, and is never shown, logged or audited.
4. **Private networks.** The address must be https. An MDM server on a private LAN (10.x, 172.16-31.x, 192.168.x) is refused until that network is listed under
   **Administration > Webhooks > Allowed internal networks**: the same list and the same RivetCore URL policy webhooks use (public addresses always pass; loopback,
   link-local and cloud-metadata addresses can never be allowed). The connection is pinned to the vetted address, so DNS cannot be changed between the check and
   the request. The check runs when saving and on every sync.
5. **Self-signed certificates.** On-premises servers often have one. Turn **Verify the server's TLS certificate** off only for a certificate you trust; the default is on.
6. A new key: generate it, paste it, change the expiry date, Save. The old one stops working when it expires or is revoked (HTTP 401: "rejected the API key").

### MSP edition (either edition of the connection)

Tick **MSP edition**. A sync then reads the customer list and fetches each customer's devices with that customer's id in the `X-CUSTOMER` request header. Each
customer name goes through the shared **Client mapping** queue (Administration > Integrations > RMM > Client mapping): an exact department-name match is used
as before, a saved mapping wins, **Ignore** skips the customer on purpose, and a name that matches nothing waits there (its devices wait in the review list as
"needs_mapping") until you map it. The **Default department** catches customers that have no mapping. Without MSP, the default department is used for every new
asset; without a default there is one queue entry for the whole account. The queue entries of this integration are keyed by the negative of its own id, so they
can never collide with an RMM integration's.

### Cloud edition: 1. Create a Zoho self client (once)

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

Also stored: the MSP customer id and name (MSP only) and `raw_json`, a small allow-listed subset of the device record (id, platform, OS, model, product, ownership,
removed / lost / supervised flags, battery, registered / unregistered / last-contact times, summary counts). Not stored, deliberately: the raw responses (the details
response contains an `apn_password` field), tokens, location, installed apps, profiles.

### Field mapping (what the code relies on, and where the docs show it)

| RivetIT field | ManageEngine field | Docs page (section) |
|---|---|---|
| link id | `device_id` (string, 16 digits: never a PHP int) | Devices > Get all devices (response) |
| asset name | `device_name` | same |
| asset serial / match key | `serial_number` (then `imei`, `udid`) | same; `imei` also inside `sims[]` of Get device details |
| asset type | `model`, `product_name`, `platform_type` (iOS / Android / Windows) | same |
| asset make | derived: Apple for iOS / iPad / iPhone / macOS, else the leading vendor word of `product_name` | (derived) |
| asset model | `model`, else `product_name` | same |
| asset OS | `platform_type` + `os_version` | same |
| contact | `user.user_email` (matched to a contact of the department) | same (`user` object) |
| ownership | `owned_by` (1 corporate, 2 personal) | same (filter description) |
| removed | `is_removed` (marked deprecated in the attribute table; example shows a boolean) | same |
| lost mode | `is_lost_mode_enabled` | same |
| supervised, battery, storage, registered / unregistered | `is_supervised`, `battery_level`, `device_capacity`, `available_device_capacity`, `registered_time`, `unregistered_time` | Get device details |
| passcode / root | `security.passcode_complaint` (sic), `security.device_rooted` | Get device details |
| last contact, groups | `last_contact_time`, `group[].group_name` | Get device summary (scope MDMDeviceMgmt) |
| paging | `limit`, `offset`; `metadata.total_record_count`; `paging.next` (never followed) | Pagination page (default 50, no stated maximum) |
| MSP customers | `customers[].customer_id`, `customer_name` | MSP API > Customers > Get Customer list |
| MSP scope | request header `X-CUSTOMER: <customer_id>` | MSP API examples (every device example sends it) |
| on-prem auth | `Authorization: {api_key}`, key valid 6 months | API Key page |
| cloud auth | `Authorization: Zoho-oauthtoken <token>` | OAuth page |

How the status badge is decided (`MdmDeviceStatus::classify`, one place for every screen): *Not enrolled* if removed or unregistered;
*Lost mode* if the lost-mode flag is on; *Not compliant* if the passcode is reported non-compliant or the device is rooted; *Not seen N+
days* if the last contact is older than the setting; *OK* otherwise. "Compliance" here is only what the API reports about the passcode
and root state: ManageEngine's own compliance policies (geofence, fence policy) are not read.

### Matching rules

1. A device already linked (same ManageEngine device id) just has its row refreshed (and its asset's blank fields filled, never a human edit overwritten).
2. Otherwise the device's **serial number** is compared with `assets.asset_serial` (case-insensitive, non-archived assets only).
3. With no serial, or no asset with that serial: the **IMEI**, then the **UDID**, are compared with `assets.asset_serial` too (people record a
   phone's IMEI as its serial). Wi-Fi MAC is not used: the documented endpoints do not return it.
4. Still nothing, and the device's department is known: exactly one asset with the **same name** (case-insensitive) in that department that is not already linked to
   another live device. Two assets with that name is ambiguous. Without a department the name is never used (names repeat across departments).
5. Blank or placeholder values ("unknown", "0", anything under four characters) are never match keys.
6. **Ambiguous means unmatched.** Two assets with the same serial, one serial on two live devices, or an asset that is already linked to
   a different live device: nothing is linked and the device waits in the review list with the reason (and the candidate asset ids).
7. A device that was re-enrolled (new ManageEngine id, old one gone from the list) takes over the existing link in place.
8. A device marked removed in ManageEngine keeps its link, shown as *Not enrolled*; a removed device with no link is skipped.

Nothing matched, so: with **Create assets** on and a department resolved (MSP customer mapping, the default department, or a name match to a department) the asset is
created; with no department yet the device waits as `needs_mapping`; with Create assets off it waits as `no_match`.

The review list is Managed devices > Mobile devices > Needs review. **Link to existing asset** lists unlinked tablet and phone assets (candidates
preselected); **Create asset** makes one asset (type Tablet for an iPad or tablet model, otherwise Phone; name, serial, model and
OS from the device, in the department you choose); **Ignore** hides a device until you restore it. Linking needs the Departments module
at level 2 and access to the asset's department. **Unlink** (asset page) removes a link; the next sync links it again if the serial still
matches.

## Mapping options

Three optional features sit on top of the sync. They never talk to ManageEngine (they only use data a sync already stored), they are all
**off or non-destructive by default**, and every change they make to an asset goes through the same history and audit trail as a manual edit.

| Safety default | Where | What it means |
|---|---|---|
| Assign contact from MDM user: **off** | Integrations > Device Sync > Mapping options | No asset's Assigned To is ever set from a device user. Mapping users to contacts only records the mapping. |
| ...overwrite: **off** | same | Even when on, only an **empty** Assigned To is filled. |
| Allow MDM to write asset fields (master switch): **off** | same, administrators only | No asset column is written, whatever the field table says. Switched off again when the integration is disabled. |
| Every field: **Show in the MDM panel only** | field table | The current behaviour: shown in the MDM panel, no asset write. |
| "Write to asset" mode: **Fill only when empty** | field table | Never replaces something a person typed. |
| Mapping screen and user mapping | Endpoints > MDM Devices | Closed to a department-scoped non-admin on every action, single or bulk. |

With no database row (a fresh install, or before the 2.6.169 database update) every option reads as off and a sync behaves exactly as before.

### A. MDM user to RivetIT contact

ManageEngine reports each device's user in the device list as `user.user_name` and `user.user_email` (these are the only user fields the sync
reads and stores; the documented shape also has a user id, but it is not stored, so it cannot be a key). RivetIT keys an MDM user by the
lower-cased **e-mail**; a device user with no e-mail is keyed by `name:` + the lower-cased name, and a device with no user adds nothing.

- After every sync (and on **Rescan users**) each user seen on a linked or unmatched device is recorded in `mdm_user_contact_map`.
  A user is **auto-mapped** when exactly one live (non-archived) contact has that e-mail, compared exactly and ignoring case. When several
  contacts share the address, the departments of the user's linked assets narrow it down, and it is mapped only if that leaves exactly one.
  Otherwise the user waits in **Needs mapping**. An auto-mapping whose contact is later archived goes back to Needs mapping.
- **Endpoints > MDM Devices > Users to contacts** lists the users (Needs mapping by default, Mapped, Ignored, All, with search). For a user in
  Needs mapping pick a contact with the search-as-you-type picker (it is narrowed to the department of the user's device when the device's
  asset has one, and the server enforces the same rule) and press **Map**, or press **Ignore**. A choice made by a person is never changed by
  a later rescan or sync; **Clear mapping** puts the user back in Needs mapping.
- **Assign contact from MDM user** (administrators switch it on under Mapping options): for a linked device whose user is mapped, the asset's
  Assigned To is set to the mapped contact only when it is **empty** (unless "Overwrite" is also on), only when the contact belongs to the
  asset's department (an asset with no department takes any contact), and never for an ignored, unmapped or archived contact. The change
  is recorded through `AssetAssignmentService::recordChangeIfNeeded` (assignment history) plus an asset history line and an audit event.
  It runs after each sync, from **Assign contacts now** (with a **Preview** that changes nothing) on the Users page, and from the
  **Set contact from MDM user** button on the asset's MDM panel (shown only when it would really change something).

### B. Mapping screen (device to asset, by hand and in bulk)

**Endpoints > MDM Devices > Mapping** lists every MDM device: linked, unmatched and ignored, with the asset it is linked to now, a search box
(name, serial, IMEI, UDID, user, asset), filters (state, platform, department) and a per-row asset picker (search-as-you-type, flags assets
that already have a device).

- Per row: **Link** (unmatched), **Re-link** (move a linked device to another asset), **Unlink**, **Ignore** / **Restore**.
- Bulk: tick rows and press **Link** (each ticked row uses the asset chosen on that row), **Unlink**, **Ignore** or **Restore**. A request is
  capped at 500 rows.
- **Propose matches** suggests assets for unmatched devices by loose similarity of serial (or IMEI / UDID) and name (typo-tolerant, spaces and
  punctuation ignored, at least 60 percent). A suggestion only fills that row's asset box; it is **never** applied automatically.
- **One asset, one MDM link.** Linking a device to an asset that already has another device is refused with the device's name and an
  explanation. Tick **Replace an existing link** to move the asset to the chosen device; the device that held it goes back to the review list
  (reason *Unlinked by a person*, its data kept). Two rows in one bulk request choosing the same asset conflict the same way.
- **Unlink** moves the device to the review list (so it stays visible) instead of dropping it; as before, the next sync links it again if its
  serial still matches an asset. **Ignore** applies to unmatched devices only (a linked device must be unlinked first).
- **Access.** Needs the Clients module at level 2 and is closed to a department-scoped non-admin on every action (the page falls back to their
  own department list, the POST handler refuses, the asset search returns nothing). Each action also checks access to the department of every
  asset it touches (the asset a device is linked to now and the one it is being linked to), CSRF on every form, and every change is written to
  the action log (names and ids only, never a secret).

### C. Field mapping

Under Integrations > Device Sync > **Mapping options**, a table lists the MDM fields (device name, model, OS and version, serial, IMEI, UDID,
battery, storage, last contact, passcode compliant, supervised, group) against a target. Every field starts at **Show in the MDM panel only**.
Only the fields that have a matching asset column can be switched to **Write to asset**:

| MDM field | Asset column | Modes |
|---|---|---|
| Device name | `asset_name` | fill only when empty |
| Model (falls back to the product name) | `asset_model` | fill only when empty, or always overwrite |
| OS and version (e.g. `iOS 17.5.1`) | `asset_os` | fill only when empty, or always overwrite |
| Serial number | `asset_serial` | fill only when empty (skipped when another live asset already has that serial) |

The others (IMEI, UDID, battery, storage, last contact, passcode, supervised, group) have no asset column and stay in the MDM panel.

- **Nothing writes** unless the master switch **Allow MDM to write asset fields** is on (administrators only; the checkbox is greyed out and
  stored off while the integration is disabled, and disabling the integration switches it off again). With the switch off the table is only a
  stored preference.
- When on, each sync writes the marked fields onto the asset of every linked device that is still enrolled. A blank ManageEngine value never
  blanks a field. "Fill only when empty" never replaces a typed value (and the update itself re-checks, so a value typed during the run is
  kept). "Always overwrite" replaces the asset's value whenever ManageEngine reports a different one, so it undoes a manual edit at the next
  sync. Intune and RMM syncs can write the same columns; do not map the same column from two sources in overwrite mode.
- **Preview changes (no writes)** uses the values in the form (saved or not), lists every asset that would change with the current and the new
  value, and writes nothing (not the settings either); the result is shown once.
- **What is recorded.** Each written field adds a row to `mdm_field_write_log` (asset, field, column, mode, old value, new value, the sync run,
  who), an asset history line ("ManageEngine MDM sync set model from 'x' to 'y'") and an audit event. The sync log line for the run counts
  them ("asset field writes: N field(s) on M asset(s)"), and another counts contacts set; both lines appear only while their option is on.
  Only device attributes ever appear in these records, never a token or secret. A failure in the mapping step becomes a warning; it never
  fails the sync.

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
- **Audit.** Saving settings (including the mapping options), Test connection, Sync now, linking, creating an asset from a device, ignoring, unlinking and every mapping action are written to the
  action log (and the structured audit trail for the settings ones). Secrets, tokens and the Authorization header are never logged; the
  audit text names only *which* secret was replaced.
- **Recovery sync watch.** The sync is registered with the watcher that alerts on failing or stale integration syncs (Administration > Backup > Integration sync
  watch, source `mdm`): its own expected interval is the connection's *Sync every* setting (stale after three times that; failing after the configured number of
  consecutive real errors; Mute, interval override and Forget history work as for the others). Every failed run, including a refused server address, writes a
  `mdm_sync_log` row so the watcher sees it.
- **Stale devices (off by default).** *Retire assets whose MDM device is gone or removed for N days* (7 to 3650, default 30) archives an asset only when every MDM
  link of it has left the MDM list for N days, or the MDM reports it removed / unenrolled and it was last heard from N days ago, and the asset has no RMM link. A
  device that is merely quiet but still enrolled is never retired. Retired assets use the same review queue as the RMM rule (Integrations > RMM > Stale assets: Restore
  / Confirm), are never deleted, and come back by themselves when the device is enrolled again. Retirement runs only after a successful complete run that listed devices.
- **API key expiry** is a date the administrator enters (the MDM server does not report it); see the on-premises section.
- **Response size.** A response over 8 MB is refused (`Unexpected response`) instead of being buffered.

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
| *rejected the API key (HTTP 401)* | On-premises: key wrong, revoked or past its 6 months. Generate a new one and paste it. |
| *That MDM server address is not allowed* | The server is on a private network that is not in Webhooks > Allowed internal networks, or it is loopback / link-local, or not https. |
| *TLS / could not reach* with a self-signed certificate | Turn off *Verify the server's TLS certificate* (only for a certificate you trust). |
| MSP: *no customers* or *403* on the customer list | The key / token lacks the customer permission (cloud scope `MDMOnDemand.MDMAdmin.READ`), or the server is not the MSP edition. |
| Devices wait as *needs_mapping* | The customer (or the whole account) has no department: Client mapping queue, or set a default department. |
| A field is not being written to the asset | Check, in order: the master switch (and that the integration is enabled), that the field is set to *Write to asset*, the mode (fill-only skips a non-empty value), and that the device is still enrolled. **Preview** shows what a run would do. |
| A user stays in Needs mapping | No contact has that exact e-mail, more than one does and the device's department does not pick one, or the device user has no e-mail. Map it by hand on the Users page. |
| Linking is refused ("only one MDM link") | The asset already has another device. Tick **Replace an existing link** to move it. |
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
- **User id.** The documented device list shape shows the user as `user_name`, `user_id` and `user_email`; only name and e-mail are read, and
  no user id is stored, so a user with no e-mail is identified by the name. Whether real tenants fill `user_email` for every enrolled
  device (for example on shared or kiosk iPads) is unconfirmed.
- **Response wrapping.** The docs show `devices` as a top-level array in the list and a bare object for details and summary; the client also
  accepts a `device` / `summary` wrapper in case an edition wraps them.

### Added with on-premises / MSP (docs only, nothing verified live)

- **On-premises API key**: the header is `Authorization: {api_key}` exactly as generated (API Key page); the 6-month validity is from the docs. Whether an expired key
  answers 401 (assumed) or another code is unknown. RivetIT cannot read the expiry from the server, hence the date you enter. The key's permission names are not
  documented on the pages read; "read access to devices" is the intent.
- **On-premises base path**: `{ServerURL}/api/v1/mdm` (introduction page: "On-Premises {ServerURL}/", and the device endpoint path). The device-endpoint examples on
  the MSP introduction show `/api/v1/devices` once; the standard pages show `/api/v1/mdm/devices`, which is what is used.
- **MSP scoping**: the `X-CUSTOMER` header appears in the examples of the Customers and Devices pages, but the docs say nothing about when it is required or what
  happens without it. The customer list is documented as `GET /customers` (scope `MDMOnDemand.MDMAdmin.READ`); this client calls `/api/v1/mdm/customers` (the
  prefix is an assumption). Whether the on-premises MSP server uses the same header and path is not documented. A server answering the customer list with 403
  fails the run with a clear message.
- **`device_type` and other filters** exist in the docs but are not used; Phone / Tablet is decided from `model`, `product_name` and `platform_type` (heuristics:
  `iPad`, "tablet", "tab", `SM-T*` / `SM-X*` are tablets).
- **API version**: no version field or header is documented, so Test connection shows the device count only.
- **Device console deep link**: no per-device URL is documented; the asset card links to the console's address (on-premises server, or the data center host), not the device.
- **Customer-scoped paging, `Retry-After` and 429 on on-premises servers** are assumed to behave as the cloud docs describe.
- A real server may order, cap or wrap responses differently; the mock was written from the same pages and therefore shares their gaps.

## Checklist for the first live sync

0. On-premises: the server address is allowed (see above) and the expiry date is entered. MSP: the customer list shows up in Client mapping after the first sync.
1. Settings saved, **Test connection** green with "inventory ok, device management ok" (a red message names the missing scope) and a device count.
2. **Sync now.** Open the settings page: the sync log shows the device count. It should equal the number of devices in the ManageEngine console
   (excluding removed ones). If it is exactly 50, the paging assumption above is wrong: stop and report it.
3. Managed devices > Mobile devices: pick one linked iPad, compare the battery, last contact, groups and passcode status with the ManageEngine
   console. Check an unmatched device's serial against the console.
4. Check a device you know is in lost mode, and one that is not compliant, shows the badge.
5. Look at the application log (Admin > Logs) after the first scheduled run: *ManageEngine MDM sync complete* with no warnings.
6. On a training iPad that is linked, open Training > Devices: the MDM chip should appear under the device.

## Files

`src/Integrations/ManageEngine/` (`MdmClient` interface, `ManageEngineMdmClient` for both editions, `MdmClientFactory` with the server-address policy, `DataCenters`, token store,
`MdmDeviceMapper`, `MdmSyncService`, `MdmStale`, `MdmFleet`, `MdmDeviceStatus`), `src/Integrations/ClientMap.php` (shared queue), `src/Assets/AssetInventorySync.php` (no-overwrite writes),
`src/Recovery/SyncWatch.php` (watcher), `admin/includes/mdm_settings_panel.php` and `admin/post/settings_manageengine_mdm.php`, `agent/mdm_devices.php` and
`agent/post/mdm_device.php`, the card in `agent/asset_details.php`, the badge in `agent/assets.php`, the cron block in `cron/cron.php`, DB 2.6.168 (four tables) and 2.6.170
(columns for edition, key, MSP, mapping, retirement; `customer_id`, `customer_name`, `raw_json`; `devices_created`) in `admin/database_updates.php` and `db.sql`. The secret
columns (`client_secret_enc`, `refresh_token_enc`, `token_cache_enc`, `api_key_enc`) are registered in `src/Crypto/ColumnRegistry.php`; `tests/crypto_registry_scan.php` fails when any
`*_enc` column of the schema is neither registered nor explicitly excluded.

`src/Integrations/ManageEngine/` (data centers, client, token store, mapper, sync service, status helper), `admin/includes/mdm_settings_panel.php` (the form, on Integrations > Device Sync)
and `admin/post/settings_manageengine_mdm.php`, `agent/mdm_devices.php` and `agent/post/mdm_device.php`, the mapping screens (`agent/includes/mdm_mapping_view.php`, `agent/includes/mdm_users_view.php`,
`agent/js/mdm_mapping.js`, `agent/post/mdm_mapping.php`, the `mdm_search_assets` branch of `agent/ajax.php`) with `MdmMappingSettings`, `MdmUserContactMapper`,
`MdmDeviceMapping`, `MdmMatchSuggester` and `MdmFieldWriter` in `src/Integrations/ManageEngine/`, the panel in
`agent/asset_details.php`, the badge in `agent/assets.php`, the status chip in `agent/js/training_devices.js` (data from
`KioskAdminActions::kioskList`), the cron block in `cron/cron.php`, DB 2.6.168 in `admin/database_updates.php` and `db.sql` (four new
tables: `manageengine_mdm_integrations`, `asset_mdm_links`, `mdm_unmatched_devices`, `mdm_sync_log`; the secret columns are registered in
`src/Crypto/ColumnRegistry.php` for key rotation) and DB 2.6.169 (three more new tables, nothing existing altered: `mdm_mapping_settings`,
`mdm_user_contact_map`, `mdm_field_write_log`). Tests: `tests/manageengine_mdm_client.php`, `tests/manageengine_mdm_sync.php`,
`tests/manageengine_mdm_web.php`, `tests/manageengine_mdm_mapping.php` (services) and `tests/manageengine_mdm_mapping_web.php` (HTTP: every new action
for every kind of person), with the mock in `tests/mock/manageengine_mdm_mock.php`.

Tests: `tests/manageengine_mdm_client.php`, `_sync.php`, `_onprem.php`, `_web.php`, `_code_exchange.php`, `tests/crypto_registry_scan.php`, the mock in
`tests/mock/manageengine_mdm_mock.php` (cloud, on-premises, MSP customers, pagination caps, 401, 429, malformed and oversized bodies), and the browser smoke
`tests/browser/mdm_smoke.mjs` with `mdm_seed.php`.

Config-only overrides for testing against a mock (never in the web UI): `RIVETIT_MDM_BASE_URL` and `RIVETIT_MDM_ACCOUNTS_URL` (cloud hosts) and
`RIVETIT_MDM_ALLOW_HTTP_LOOPBACK` (allows plain http to a loopback host; set only in a test rig's `config.php`, never in production).
