# Odoo Department Portal sign-in (experimental)

> **Experimental.** Off by default and not yet validated against a live Odoo. The no-addon route below, which uses a shared identity provider and RivetIT's OpenID Connect sign-in, is the recommended path. The addon route (the rest of this page) is for deployments that can install custom Odoo addons and accept an experimental feature.

This integration targets **Odoo 19 Enterprise** at `https://odoo.mwautomation.com` (the installed `/web/version` endpoint reports `19.0+e`). The `rivetit_sso` addon must be installed on that Odoo database before enabling sign-in in RivetIT. RivetIT's side is disabled by default. Directory sync, local passwords, Microsoft Entra and OpenID Connect keep their existing behavior.

## Odoo's built-in OAuth login is not a drop-in alternative

Odoo's own **OAuth Authentication** (`auth_oauth`) signs people in to Odoo using the OAuth *implicit* flow (`response_type=token`, no client secret, no PKCE; checked against Odoo 20.0). Authentik does not accept a plain `token` response type (it supports `code`, `id_token`, `id_token token` and the hybrid types), so Odoo's built-in login generally cannot use Authentik. Providers that still allow the implicit flow may work, but that is a less safe flow than the one RivetIT's OpenID Connect sign-in uses, so this page does not recommend it. If you cannot install the addon, keep using RivetIT's OpenID Connect sign-in directly and give employees a plain link to `https://YOUR_RIVETIT_HOST/client/login_oidc.php` (for example a URL action menu item in Odoo developer mode, Settings > Technical > Actions > URL Actions).

The `rivetit_sso` addon below needs access to the Odoo server's addons path (Apps > Import Module cannot install it: that tool extracts data files only, never Python code). Versions: `rivetit_sso-20.0.1.0.0.zip` for Odoo 20 and `rivetit_sso-19.0.1.0.0.zip` for Odoo 19. Both are built from the one `rivetit_sso` source folder by `odoo_addons/build_zips.sh` (the only difference is the access-rights file name, which Odoo 20 renamed); rebuild them after changing the source. The manifest version is series-agnostic, so Odoo shows 19.0.1.0.0 or 20.0.1.0.0 as appropriate.

After installing, the settings live in Odoo under **Settings > RivetIT SSO** (visible to administrators; create one integration there).

## Install and configure

1. A ready-to-upload copy of the addon is in this repository as `odoo_addons/rivetit_sso-19.0.1.0.0.zip` (unzip it into an addons folder; it is built from `odoo_addons/rivetit_sso` and must be rebuilt if the source changes). Add `odoo_addons` from this repository to the Odoo server's `addons_path`, update the Apps list, and install **RivetIT Department Portal SSO** in `midwest-production`. It requires the `hr` module and a deployment that permits custom addons. Check the addon in a staging Odoo database first.
2. In Odoo Settings, create one **RivetIT SSO integration** for the intended company. Set `Issuer URL` to the exact HTTPS Odoo base URL, `Integration ID` to a stable identifier such as `rivetit-department-portal`, and both RivetIT URLs to the exact callback `https://YOUR_RIVETIT_HOST/client/login_odoo.php`. Leave **Active** off until the secret is configured.
3. Generate a random secret with at least 32 printable characters. In an interactive Odoo shell for the correct database, store only its SHA-256 hash on the integration record:

   ```python
   import getpass
   import hashlib
   secret = getpass.getpass('RivetIT SSO secret: ')
   integration = env['rivetit.sso.integration'].search([('client_id', '=', 'rivetit-department-portal')], limit=1)
   integration.secret_hash = hashlib.sha256(secret.encode('utf-8')).hexdigest()
   env.cr.commit()
   del secret
   ```

   Enter the same raw secret in RivetIT Admin → Integrations → **Odoo Department Portal sign-in**. RivetIT encrypts it at rest; Odoo stores only the hash. Do not reuse the Odoo directory-sync API key. To rotate, first disable Odoo sign-in, replace the hash and encrypted secret, then enable it again.
4. In RivetIT, enter the same Integration ID and the numeric Odoo company ID, then enable Odoo sign-in. In Odoo, activate the integration. Assign the **RivetIT Department Portal** group only to employees allowed to launch it.
5. Run Odoo Directory Sync and confirm each person's stable `contact_odoo_links` employee mapping. Edit an existing RivetIT **Department Login** and choose **Odoo employee**. RivetIT will not map solely by email or create a new login during SSO.
6. From a browser signed in to Odoo as an allowed employee, click **Department Portal**. Confirm that the expected RivetIT account opens `/client/`. Test an unlinked employee, inactive account, wrong company, and sign-out/retry before broad rollout.

The addon uses `/rivetit/sso/launch`, `/rivetit/sso/authorize`, and `/rivetit/sso/token`. The launch and authorize routes require an Odoo user session and the assigned group. Token exchange requires a dedicated bearer secret even if an Odoo session cookie is present. The code expires in 60 seconds and is consumed in one conditional SQL update; state and PKCE bind it to the initiating RivetIT browser. RivetIT validates the exact issuer, database, company, and existing active account before creating a portal session.

## Reverse proxy and operations

Use HTTPS with certificate verification on both sites. Restrict callback URLs to the one configured on the Odoo integration. Redact query strings on Odoo's `/rivetit/sso/authorize` and RivetIT's `/client/login_odoo.php` access logs; authorization state and the one-time code appear in those requests. The Nginx template supplied with RivetIT suppresses its callback access log; configure the Odoo proxy separately, and inspect any CDN logs. Application audit events contain only reason categories and safe IDs.

Odoo removes expired or used code records after one day through an hourly scheduled job. A failed or canceled handoff does not sign in another RivetIT account. If a RivetIT session already existed, it remains on failure and is replaced only after a successful, verified handoff. Odoo sign-out does not end an existing RivetIT session. There is no automatic provisioning, single logout, or arbitrary deep-link support.

To stop sign-in immediately, turn off **Enable Odoo sign-in** in RivetIT; revoke the addon integration secret hash and deactivate its record in Odoo as well. Existing RivetIT sessions should be terminated separately if required. To roll back the code, disable the feature first; the added RivetIT database columns can remain harmlessly in place.
