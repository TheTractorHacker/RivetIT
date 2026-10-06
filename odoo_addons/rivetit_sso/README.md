# RivetIT Department Portal SSO for Odoo 19 and 20

This installable Odoo addon supplies the Odoo half of the browser-bound Department Portal sign-in flow. See [`docs/ODOO_PORTAL_SSO.md`](../../docs/ODOO_PORTAL_SSO.md) for installation, configuration, secret rotation and rollback.

Build the per-series zips with `odoo_addons/build_zips.sh`.

Verified on a throwaway stock `odoo:19.0` + PostgreSQL 16 stack (2026-10-06): clean install, upgrade, uninstall, and the full authorize / token / health flow, including wrong verifier, wrong secret, replay and mismatched redirect. It has not been run against the production Odoo, and the Odoo 20 build has not been installed.
