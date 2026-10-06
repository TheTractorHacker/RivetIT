# Odoo Department Portal sign-in

Employees who are signed in to Odoo click **Department Portal** in Odoo and enter RivetIT without another password. Odoo 19 and 20 are supported, Community or Enterprise, through the `rivetit_sso` addon. Sign-in is off by default on the RivetIT side, and directory sync, local passwords, Microsoft Entra and OpenID Connect keep their existing behavior. The addon has been installed, upgraded and exercised against real Odoo 19.0 and 20.0 servers, including a full sign-in from Odoo into RivetIT.

If you cannot install the addon, employees can still sign in through RivetIT's OpenID Connect sign-in (see `OPENID_CONNECT_PORTAL.md`) with a plain link to `/client/login_oidc.php`.

## Odoo's built-in OAuth login is not a drop-in alternative

Odoo's own **OAuth Authentication** (`auth_oauth`) signs people in to Odoo using the OAuth *implicit* flow (`response_type=token`, no client secret, no PKCE; checked against Odoo 20.0). Authentik does not accept a plain `token` response type (it supports `code`, `id_token`, `id_token token` and the hybrid types), so Odoo's built-in login generally cannot use Authentik. Providers that still allow the implicit flow may work, but that is a less safe flow than the one RivetIT's OpenID Connect sign-in uses, so this page does not recommend it. If you cannot install the addon, keep using RivetIT's OpenID Connect sign-in directly and give employees a plain link to `https://YOUR_RIVETIT_HOST/client/login_oidc.php` (for example a URL action menu item in Odoo developer mode, Settings > Technical > Actions > URL Actions).

The `rivetit_sso` addon below needs access to the Odoo server's addons path (Apps > Import Module cannot install it: that tool extracts data files only, never Python code). Versions: `rivetit_sso-20.0.1.1.0.zip` for Odoo 20 and `rivetit_sso-19.0.1.1.0.zip` for Odoo 19. Both are built from the one `rivetit_sso` source folder by `odoo_addons/build_zips.sh` (the only difference is the access-rights file name, which Odoo 20 renamed); rebuild them after changing the source. The manifest version is series-agnostic, so Odoo shows 19.0.1.1.0 or 20.0.1.1.0 as appropriate.

After installing, the settings live in Odoo under **Settings > RivetIT SSO** (visible to administrators; create one integration there).

## Install and configure

1. Install the addon on the Odoo server (Odoo 19 or 20). Ready-made zips are in this repository: `odoo_addons/rivetit_sso-19.0.1.1.0.zip` and `odoo_addons/rivetit_sso-20.0.1.1.0.zip`. Unzip the right one into a folder on Odoo's `addons_path`, restart Odoo, update the Apps list, and install **RivetIT Department Portal SSO**. It requires the `hr` module. Try it on a staging Odoo database first. To upgrade an existing install, replace the folder and use Apps > Upgrade; existing integrations are kept.
2. In Odoo go to **Settings > RivetIT SSO** and create one integration. Enter your **RivetIT address** (for example `https://helpdesk.example.com`) and confirm the company. The Integration ID, this Odoo's address and both RivetIT endpoint URLs are filled in for you.
3. Click **Generate a secret** (or paste your own of 32 or more characters). Odoo shows a generated secret once and stores only its SHA-256 hash; copy it into RivetIT Admin > Settings > Integrations > **Odoo Department Portal sign-in** as the dedicated integration secret. Do not reuse the Odoo directory-sync API key. Rotating means generating a new secret and pasting it into RivetIT.
4. In RivetIT enter the same Integration ID and the numeric Odoo company ID, save, run **Test connection**, and enable Odoo sign-in. In Odoo tick **Active**.
5. Decide who may launch it. Either add people to the **RivetIT Department Portal** group, or tick **All employees may use it** on the integration (every internal user linked to an employee in that company; RivetIT still only signs in people who have a Department Login set to Odoo employee). People who are not allowed see a plain explanation page that says what is missing.
6. Click **Check setup** on the integration. It reports whether the secret and Active are set, how many employees can open the portal, and whether RivetIT answers with Odoo sign-in enabled.
7. Run Odoo Directory Sync in RivetIT and confirm each person's employee link. Edit the person's RivetIT **Department Login** and choose **Odoo employee**. RivetIT will not map solely by email or create a new login during SSO. If the sync left two contacts for one person, put the login on the contact that carries the Odoo link.
8. From a browser signed in to Odoo as an allowed employee, click **Department Portal** in the Odoo menu. Confirm that the expected RivetIT account opens `/client/`. Test an unlinked employee, inactive account, wrong company, and sign-out/retry before broad rollout.

The addon uses `/rivetit/sso/launch`, `/rivetit/sso/authorize`, and `/rivetit/sso/token`. The launch and authorize routes require an Odoo user session and the assigned group. Token exchange requires a dedicated bearer secret even if an Odoo session cookie is present. The code expires in 60 seconds and is consumed in one conditional SQL update; state and PKCE bind it to the initiating RivetIT browser. RivetIT validates the exact issuer, database, company, and existing active account before creating a portal session.

## Reverse proxy and operations

Use HTTPS with certificate verification on both sites. Restrict callback URLs to the one configured on the Odoo integration. Redact query strings on Odoo's `/rivetit/sso/authorize` and RivetIT's `/client/login_odoo.php` access logs; authorization state and the one-time code appear in those requests. The Nginx template supplied with RivetIT suppresses its callback access log; configure the Odoo proxy separately, and inspect any CDN logs. Application audit events contain only reason categories and safe IDs.

Odoo removes expired or used code records after one day through an hourly scheduled job. A failed or canceled handoff does not sign in another RivetIT account. If a RivetIT session already existed, it remains on failure and is replaced only after a successful, verified handoff. Odoo sign-out does not end an existing RivetIT session. There is no automatic provisioning, single logout, or arbitrary deep-link support.

To stop sign-in immediately, turn off **Enable Odoo sign-in** in RivetIT; revoke the addon integration secret hash and deactivate its record in Odoo as well. Existing RivetIT sessions should be terminated separately if required. To roll back the code, disable the feature first; the added RivetIT database columns can remain harmlessly in place.
