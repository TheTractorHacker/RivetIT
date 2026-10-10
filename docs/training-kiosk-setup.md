# Training kiosk: device setup (iPad and Windows)

This guide covers how to turn an iPad or a Windows PC into a **training device**. On a training device, employees find their name, enter their PIN, and take their training. It matches the in-app guide at **Training › Devices & PINs › Setup guide**. That page is the one to send people to, because nginx denies `docs/`.

## How a training device works

- A device is usually an **asset** of type Tablet, Phone, Mobile Phone, Laptop or Desktop. It is enrolled once by an admin who has the *Training kiosk* permission at level 3. A device that isn't in Assets can be enrolled too, and any device can be temporary (see [Temporary or unlisted devices](#temporary-or-unlisted-devices)).
- Enrolling gives the device a **start URL**, `https://<site>/kiosk/#d=<token>`:
  - It is shown only once. Re-issuing it (**New start URL**) rotates it.
  - The token is a URL *fragment*, so it never reaches a server or an access log.
  - The same URL written as `https://<site>/kiosk/?d=<token>` also works (for a kiosk shortcut or policy that drops
    the `#` part). Prefer the `#d=` form: the `?d=` form reaches the web server, so the token lands in its access log.
  - The page adopts the token with a POST and stores it as a device cookie (`Path=/kiosk/`, `Secure`, `HttpOnly`, `SameSite=Strict`).
  - If the device is already set up as another device, it asks before switching.
- **Shared device**: the asset is not assigned to anyone. People type their name, tap it, then enter their PIN.
- **Personal device**: the asset is assigned to one eligible person at enrollment. The device opens straight to "Hi {first}" and the PIN, and "Not {first}?" goes to the name search.
  - If the asset is later unassigned or re-assigned, the device stops working until it gets a **New start URL**. That new URL takes a fresh snapshot of the owner.
- A device stops working at once when it is **revoked**, its temporary time is up, its asset is archived or retyped, or its personal assignment changes. It then shows "This device is not set up for training".
- No RivetIT agent session is ever kept on a training device:
  - Enrollment ends with a mandatory sign-out.
  - The kiosk expires any stray agent cookies it sees.

## Temporary or unlisted devices

- **Not in Assets.** On **Set up this device**, choose **This device isn't in Assets** and give the device a name (for example "Trainer's laptop" or "Borrowed iPad"). Nothing else is needed.
  - It is always a shared device: people find their name, then enter their PIN. Personal-device mode needs an asset assigned to someone, so an unlisted device never opens straight to one person.
  - It isn't tied to an asset, so archiving or retyping assets never affects it, and the "one device per asset" replacement rule doesn't apply: each unlisted device stands on its own.
  - The Devices tab and the kiosk evidence on a training record show its name and "Not in Assets" with its device number.
- **Temporary.** Under **How long?** choose **Temporary** and one of: until the end of today (11:59 PM), 4 hours, 8 hours, 24 hours, or a date and time up to 30 days away. Times are in the app's time zone (America/Chicago here). Asset devices start on **Keep until I remove it**; unlisted devices start on **Temporary · until the end of today**.
  - "Until the end of today" needs at least 5 minutes left today; late at night pick 4 hours or a date and time. A device set up with a **setup code** must stay set up for at least 15 minutes (the code lasts that long).
  - The success panel shows when the device stops working, with the time zone in force at that time.
  - On the device, the sign-in screen and trainer mode show *This device: … · until 3:13 PM*. From 15 minutes before the end the top bar shows *Ends 3:13 PM*, with a reminder at 15 and at 5 minutes.
  - When the time is up, the device's next request is treated exactly like a revoke: the device is revoked with the reason "Temporary device expired", anyone signed in is signed out, the screen shows "This device's training time is over" and when it ended, and the start URL stops working. Devices nobody touches are revoked the same way by `cron/training_kiosk_cron.php` within 10 minutes. The ledger and audit log record it like a manual revoke, with the system as the actor. To use the device again, set it up again on the device.
  - The Devices tab shows a **Temporary** badge and **Temporary · expires …** (amber with the minutes left in the last hour), or **Expired**. **Change end time** shows the current end and a preview of the new one (counted from now with the same choices, or **Keep until I remove it**); a new time earlier than the current one is flagged and the button reads **Shorten it**. **End now** switches the device off at once. An expired device nobody has touched yet offers **Remove now** (no reason needed).
  - A permanent device can be made temporary later with **Set end time** (the same choices without *Keep*).
  - A listed temporary device still gets personal-device mode when its asset is assigned to someone.
  - Training records show "temporary device" when the device was temporary **at the moment the person signed** (read from the ledger), so changing a device's end time later never changes older records. An unlisted device is shown as "Not in Assets (device #<id>)", because two unlisted devices may share a name.

## PINs

- **Odoo-PIN sign-in is off by default.** While it is off, everyone uses a **training PIN**. Only switch it on after the Odoo production switch, a clean *Check employee links* run, and **Refresh PIN sources**, in Admin › Settings › Training kiosk.
- To give people a training PIN, go to **Devices & PINs › People & PINs**, tick the people, and choose **Issue setup slips**. Then print the slips: each one has an 8-digit code that works once and expires after the configured number of days.
- On the device the person taps their name, then **I have a setup code**, types the code, and picks a new 6-digit PIN twice. Easy PINs are refused (123456, 111111, 121212, the previous PIN, and similar).
- After printing, choose **Done — clear these slips**. Without that, the encrypted batch expires after 10 minutes anyway.
- Lockouts:
  - 5 wrong PINs lock a PIN for 15 minutes (the time doubles on each repeat), and 10 lock it until an admin unlocks it.
  - A device that sees too many wrong PINs pauses sign-in for 10 minutes.
  - Admins can **Unlock** a person, **Clear cooldown** on a device and **Clear pause** for everyone. Each of these needs a reason.
- Trainers always use a training PIN. Issuing a slip for a trainer, or unlocking or unblocking one, needs the Training permission at level Full (or admin) and alerts the admins.

## Choosing how to deploy

Different customers deploy different ways. All of these work side by side.

| Option | How it works | Best for |
|---|---|---|
| Set up on the device | An admin signs in on the tablet (**Set up this device**). | One or two devices |
| Setup code | **Get setup codes** prints a 10-character code per device; someone types it on the device. | A handful of devices, no MDM |
| Per-device start URL | `https://<site>/kiosk/?d=<token>`, one per device, pushed or typed. | Windows kiosk-mode PCs, one at a time |
| Per-device URL export | **Training › Devices & PINs › Fleet links**: after creating a link, **Per-device URLs (CSV)** lists one URL per tablet in the department with its serial already filled in. | An MDM that cannot fill in a serial macro |
| **Fleet link with serial** (recommended for MDM) | One shared URL, `https://<site>/kiosk/?e=<token>&sn=<serial macro>`; the MDM fills in each tablet's serial. | Many iPads or Android tablets |
| Fleet link, plain | `https://<site>/kiosk/?e=<token>` with no serial. Each tablet enrolls as an unlisted device and waits for approval. | Fallback when the MDM has no serial macro |

## Fleet links (MDM mass deployment)

A **fleet link** is one enrollment link for a whole department's tablets. It works like the endpoint agent's enrollment tokens:

- **Scoped** to one department (a client). Tablets that use it are enrolled for that department, and serials are matched only to that department's Assets.
- **Expires** (7 to 90 days) and has a **most devices** cap. Each new tablet counts as one use.
- **Revocable and rotatable.** Revoking stops new tablets only; tablets that already enrolled keep working (revoke those on the Devices tab). **Rotate** revokes the link and gives you a new one with the same settings.
- Only a hash of the token is stored. The link is shown **once**, when you create or rotate it (Training › Devices & PINs › **Fleet links**; needs the Training kiosk permission at level 3).
- Every enrollment is audited, and the training ledger records the device.

**What happens on the tablet.** The MDM opens `/kiosk/?e=<token>&sn=<serial>`. The server answers with a bare redirect to `/kiosk/#e=<token>&sn=<serial>` (nginx does this itself where the rule from `docker/nginx.conf` is deployed, so the token and serial stay out of the access log), the page enrolls the tablet and stores the device cookie, and the tablet ends up on the sign-in screen with nothing typed. A Home Screen icon that opens the same URL on every tap is fine: a tablet that is already set up is left alone and uses nothing.

**Approval.** Pick one when you create the link:

- **Require approval** (default). Every tablet lands as *pending* and shows "Waiting for approval". It gets no training data and nobody can sign in until an admin opens **Fleet links › Waiting for approval** and chooses **Approve** (optionally binding an Asset of that department) or **Reject**. If the serial already matched an Asset, the approval screen binds it and names the device after it.
- **Auto-enroll when the serial matches.** A tablet whose serial matches exactly one active, non-archived device Asset (Tablet, Phone, Mobile Phone, Laptop or Desktop) **of the link's department** that has no training device yet is enrolled at once, named after the asset, and (if the asset is assigned to an eligible person) personal. Anything else (no serial, no match, a match in another department, two assets with the same serial, an asset that already has a device) still waits for approval, with the reason shown.

A serial must be 3 to 64 letters, digits, `.`, `_` or `-`. If the MDM did not expand its macro (the tablet shows "did not fill in this device's serial number"), nothing is created and no use is spent. A serial that already has a pending or active fleet device is refused until an admin revokes that device, so knowing a serial never takes over a working tablet.

### Apple (iPad): MDM Web Clip

1. **Fleet links › New fleet link.** Pick the department, approval mode, how long it works and the most devices.
2. In the panel that appears (shown once), choose your MDM so the **serial-number macro** is filled in, or type your own. Check the macro name in your MDM's documentation before a large push; common forms are `$SERIALNUMBER` (Jamf Pro), `{{serialnumber}}` (Intune) and `%serialnumber%` (ManageEngine).
3. Click **Download .mobileconfig** (set the icon name first). The profile is an unsigned managed Web Clip with: the URL `https://<site>/kiosk/?e=<token>&sn=<macro>`, **Full Screen**, **not removable**, and the kiosk icon embedded as base64 (managed Web Clips do not reliably fetch the site's icon). Its PayloadUUIDs are fixed for the link, so pushing it again updates the profile instead of adding a second one.
4. Upload the profile to your MDM and assign it to the iPads (Jamf: Computers › Configuration Profiles › upload, or build a Web Clip payload and paste the URL template; Intune: Devices › iOS/iPadOS › Configuration profiles › Templates › Custom › upload the file, or a Web clips profile with the template URL; ManageEngine: Profiles › iOS › Web Clip with the template URL).
5. For no-touch setup, enroll the iPads through **Apple Business Manager / School Manager** (automated device enrollment) so the profile arrives during Setup Assistant.
6. On first tap of the icon the iPad enrolls (or waits for approval); after that the icon always opens the kiosk. Optional: **Guided Access** or Single App Mode as in the iPad section above.

If the MDM cannot expand a macro inside a Web Clip, use **Per-device URLs (CSV)** and push each URL to its iPad (or paste each into a per-device Web Clip), or use the plain link and approve the tablets.

### Android: Android Enterprise

1. Create the fleet link and copy the **MDM URL template** (choose the macro your MDM uses for the serial number).
2. Enroll the tablets as **fully managed / dedicated devices** (zero-touch enrollment or a QR code).
3. Push the URL as a **managed web app / web link** (Managed Google Play web apps) or as a **Chrome managed bookmark / homepage** (Chrome policy `HomepageLocation` and `RestoreOnStartupURLs`), and in dedicated-device (kiosk) mode allow only that app or Chrome.
4. If your MDM has no serial macro for web links, use the plain link (tablets wait for approval) or **Per-device URLs (CSV)**.

Once a tablet is enrolled it keeps its own device cookie; clearing Chrome's data returns it to the link, which is refused for that serial until an admin revokes the old device entry (a deliberate guard against take-over).

## iPad

1. Update to **iPadOS 16.4 or later**. Older iPads run everything except YouTube and Vimeo lessons, which ask for an update.
2. In Safari, open `https://<site>/kiosk/`. Tap **Share › Add to Home Screen**, then open the new **Training** icon.
3. Inside that app, tap **Set up this device (admin)** and sign in to RivetIT. Search for the iPad's asset, name it (for example "Fab Shop iPad 2"), pick a default department, and tap **Use this device for training**.
   - Enroll **inside the Home Screen app**. Safari and the Home Screen app keep separate cookies.
4. On the success panel, tap **Open training on this device**. You are signed out of RivetIT, and the iPad shows the name search (or the owner's PIN on a personal iPad).
5. Go to Settings › Display & Brightness and set **Auto-Lock** to **Never** while the iPad stays on its charger.
6. Optional: go to Settings › Accessibility › **Guided Access** and turn it on. Then triple-click the top button inside the Training app to lock the iPad to it.
7. If the cookies get cleared or the iPad was enrolled in Safari by mistake, open the start URL again. If nobody has it, use **New start URL** on the Devices tab.

## Windows PC (Edge kiosk mode)

1. On the PC, in Edge, open `https://<site>/kiosk/` and choose **Set up this device (admin)**. Sign in and pick the PC's asset (type Desktop or Laptop).
2. On the success panel, click **Copy** to copy the **start URL** *before* you click **Open training on this device**. The URL is shown only once.
3. Run Edge in public-browsing kiosk mode on the start URL:

   ```
   msedge --kiosk "https://<site>/kiosk/#d=<token>" --edge-kiosk-type=public-browsing
   ```

   You can also use Settings › Accounts › Other users › **Set up a kiosk** (assigned access), with Microsoft Edge, "As a public browser", and the start URL.
4. Public browsing clears cookies between sessions. The start URL re-adopts the device every time, so the PC keeps working.
5. Under Power & sleep, set **Screen** and **Sleep** to **Never** while plugged in, and keep Windows Update restarts out of shift hours.
6. On a PC with a keyboard, people can type their PIN on the number keys and press Enter.

## Keep the start URL safe

Anyone with a start URL can set up a copy of that device. They still need a person's PIN to sign in. If a URL leaks:

- use **New start URL** (the old URL stops working at once), or
- use **Revoke** with a reason.

The Devices tab shows each device's last-seen time and browser, so a copy that shouldn't exist stands out.

## Ops notes (live server)

- nginx must deny `/kiosk/includes/` on the live vhost (`location ^~ /kiosk/includes/ { deny all; }`). The repo mirrors are `docker/nginx.conf` and `deploy/templates/nginx-vhost.conf.template`.
- `config.php` must define `$config_settings_enc_key`. Every PIN, setup code, slip and kiosk CSRF key is derived from it. Rotating it invalidates every training PIN and code, and everyone then needs a new slip.
