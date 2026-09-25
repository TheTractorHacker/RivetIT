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
  - If the asset is later unassigned or re-assigned, the device stops working until it gets a **New start URL**. That new URL takes a fresh snapshot of the owner (plan A19).
- A device stops working at once when it is **revoked**, its temporary time is up, its asset is archived or retyped, or its personal assignment changes. It then shows "This device is not set up for training".
- No ITFlow agent session is ever kept on a training device:
  - Enrollment ends with a mandatory sign-out.
  - The kiosk expires any stray agent cookies it sees.

## Temporary or unlisted devices

- **Not in Assets.** On **Set up this device**, choose **This device isn't in Assets** and give the device a name (for example "Trainer's laptop" or "Borrowed iPad"). Nothing else is needed.
  - It is always a shared device: people find their name, then enter their PIN. Personal-device mode needs an asset assigned to someone, so an unlisted device never opens straight to one person.
  - It isn't tied to an asset, so archiving or retyping assets never affects it, and the "one device per asset" replacement rule doesn't apply: each unlisted device stands on its own.
  - The Devices tab and the kiosk evidence on a training record show its name and "Not in Assets".
- **Temporary.** Under **How long?** choose **Temporary** and one of: until the end of today (11:59 PM), 4 hours, 8 hours, 24 hours, or a date and time up to 30 days away. Times are in the app's time zone (America/Chicago here). Asset devices start on **Keep until I remove it**; unlisted devices start on **Temporary · until the end of today**.
  - The success panel shows when the device stops working.
  - When the time is up, the device's next request is treated exactly like a revoke: the device is revoked with the reason "Temporary device expired", anyone signed in is signed out, the screen shows "This device is not set up for training", and the start URL stops working. Devices nobody touches are revoked the same way by `cron/training_kiosk_cron.php` within 10 minutes. The ledger and audit log record it like a manual revoke, with the system as the actor.
  - The Devices tab shows **Temporary · expires …** (or **Expired**). **Extend** sets a new end time counted from now with the same choices, or **Keep until I remove it**; **End now** switches the device off at once.
  - A listed temporary device still gets personal-device mode when its asset is assigned to someone.

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

## iPad

1. Update to **iPadOS 16.4 or later**. Older iPads run everything except YouTube and Vimeo lessons, which ask for an update.
2. In Safari, open `https://<site>/kiosk/`. Tap **Share › Add to Home Screen**, then open the new **Training** icon.
3. Inside that app, tap **Set up this device (admin)** and sign in to ITFlow. Search for the iPad's asset, name it (for example "Fab Shop iPad 2"), pick a default department, and tap **Use this device for training**.
   - Enroll **inside the Home Screen app**. Safari and the Home Screen app keep separate cookies.
4. On the success panel, tap **Open training on this device**. You are signed out of ITFlow, and the iPad shows the name search (or the owner's PIN on a personal iPad).
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
