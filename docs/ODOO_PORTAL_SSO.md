# Odoo Department Portal sign-in (preview)

This integration targets **Odoo 19 Enterprise** at `https://odoo.mwautomation.com` (the installed `/web/version` endpoint reports `19.0+e`). The `rivetit_sso` addon must be installed on that Odoo database before enabling sign-in in RivetIT. RivetIT's side is disabled by default. Directory sync, local passwords, Microsoft Entra and OpenID Connect keep their existing behavior.

## No custom Odoo addon allowed? Use your identity provider (recommended)

If you cannot install custom modules in Odoo, you do not need the addon. Make Odoo and RivetIT trust the same identity provider (Authentik, Keycloak, and so on) and employees get one sign-in across both:

1. Set up **OpenID Connect for Department Portal** in RivetIT (see `OPENID_CONNECT_PORTAL.md`) and link each Department login to its provider account (subject, or the optional first sign-in email linking).
2. In Odoo, use its built-in **OAuth authentication** (Settings > General Settings > Integrations > OAuth Authentication) to add the same provider as an Odoo sign-in option, then link each employee's Odoo user to their provider account (Odoo's user form has an OAuth tab for the provider user ID). Check your Odoo version's provider form for the flow it supports (authorization code with PKCE is preferred over the implicit flow) and the exact endpoint fields; Odoo's own documentation for custom OAuth providers is authoritative here.
3. Give employees a **Department Portal** link inside Odoo that needs no code: in developer mode create a URL action (Settings > Technical > Actions > URL Actions) pointing at `https://YOUR_RIVETIT_HOST/client/login_oidc.php`, and attach a menu item to it. Starting at that address begins the RivetIT sign-in directly.
4. When an employee who signed in to Odoo through the provider clicks the link, the provider already has their session, so RivetIT signs them in without another password prompt and applies their normal Department Portal permissions. Unlinked, disabled, or unknown people are refused exactly as for any OpenID Connect sign-in.

This keeps RivetIT's single, well-tested OpenID Connect sign-in path, needs nothing installed in Odoo, and works for any provider that supports OpenID Connect. The `rivetit_sso` addon below remains an option only for deployments that can install custom addons and want Odoo itself to vouch for the employee.

## Install and configure

1. Add `odoo_addons` from this repository to the Odoo server's `addons_path`, update the Apps list, and install **RivetIT Department Portal SSO** in `midwest-production`. It requires the `hr` module and a deployment that permits custom addons. Check the addon in a staging Odoo database first.
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
