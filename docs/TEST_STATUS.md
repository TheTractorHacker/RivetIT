# Test status (release candidate 2, branch rc2-tests)

How the numbers below were produced, what they mean and what is still open. Every top-level `tests/*.php` (209 files) was run once on 2026-11-02 against a **throwaway private MariaDB 11.8** (its own data directory and socket, never the shared server), each test on a freshly reset database (`golden.sql`: `db.sql` imported, `scripts/update_cli.php --update_db` run up to DB 2.6.231, then the `settings` rows removed so the schema is current and the tables empty), with a private Redis on a loopback port, a 60 second limit per test, and `EA_ALLOW_INSECURE_HTTP` in the scratch `config.php`. A second pass re-ran every failure with a `settings` row (company 1, version 2.6.231) and a `companies` row.

The earlier statement "about 168 tests fail identically on origin/beta" was **not reproduced and not checked against origin/beta**: with a per-test fresh database 141 of 209 files pass, 52 fail, and 16 are fragments that cannot run alone. Most of the earlier failures were the harness (one shared database that tests wipe and drop tables from, so later tests inherit a broken schema), not the code. No claim is made about origin/beta itself.

## Summary

| Bucket | Files | Meaning |
|---|---|---|
| Pass on an empty current schema | 119 | nothing to do |
| Pass once a `settings` row exists | 22 | needs a fixture only: the test assumes an install with a settings row |
| Fragments | 16 | `rmm_ui_*.php` are included by `rmm_ui.php` and share its variables; they cannot run alone |
| Fail: needs a server or environment | see below | the test says what it needs (env variable, nginx, a second database) |
| Fail: needs a fixture or not root-caused | see below | HTTP or crypto suites that fail on this baseline; cause not established |
| Fail: genuinely broken | 2 | stale against the code, see below |

## Pass on an empty current schema

`api_auth_hardening`, `api_key_security`, `api_router_legacy_throttle`, `audit_chain`, `automation_webhook_handler`, `backup_cron_settings`, `backup_rmm_postgres`, `build_host_setup_cli`, `chat_delivery_pin`, `chat_delivery_pinned`, `company_logo_paths`, `company_terms_url`, `compliance_retention`, `compliance_snapshot_cron`, `cron_jobs`, `cron_runner`, `crypto_multikey_source`, `csp_inline_handlers`, `download_hardening`, `endpoint_agent_authz`, `endpoint_agent_build`, `endpoint_agent_checkin`, `endpoint_agent_deploy_unit`, `endpoint_agent_enroll`, `endpoint_agent_feed`, `endpoint_agent_jobs`, `endpoint_agent_lib`, `endpoint_agent_migration`, `endpoint_agent_module`, `entity_links`, `entra_actions`, `entra_sync`, `entra_target_binding`, `fa_icons`, `findings_round`, `graph_client`, `i34_bootstrap`, `i34_mail_attachment`, `i34_reports_core`, `i34_reports_data`, `i34_reports_helper_filters`, `i34_reports_scheduler`, `kiosk_fleet_links`, `lms_router`, `mail_intake_parser`, `mail_provider_independence`, `manageengine_mdm_client`, `manageengine_mdm_code_exchange`, `manageengine_mdm_is_removed`, `manageengine_mdm_lib`, `mcp_admin`, `mcp_identity`, `mcp_jwt`, `mcp_oauth_cimd_pin`, `mcp_oauth_lib`, `mcp_oauth_router`, `mcp_oauth_service`, `mcp_oauth_utc`, `metrics_tab_groups_unit`, `mobile_api`, `mobile_api_router`, `nginx_private_paths`, `no_hardcoded_product_name`, `no_native_dialogs`, `no_operator_branding`, `no_upstream_branding`, `notification_categories`, `odoo_badge_outbox`, `odoo_badge_push`, `odoo_course_certify`, `odoo_portal`, `odoo_portal_db`, `odoo_skill_name_fallback`, `oidc_portal`, `org_chart_filter`, `platform_migration`, `platform_search`, `platform_sync`, `price_quotes`, `recovery_integration`, `recovery_offsite`, `release_channel`, `report_schedule_policy`, `reports_bootstrap`, `reports_department_scope`, `reports_saved_views`, `reports_scheduler`, `reports_widgets`, `rivet_time`, `rivet_time_lint`, `rivet_time_pages`, `rivetcore_rc7_mcp_collation`, `rmm_alert_routes_unit`, `rmm_asset_moved`, `rmm_pg_credential_no_plaintext_test`, `rmm_rc25_metrics`, `rmm_rc25_ui`, `rmm_script_write_policy`, `security_backup`, `security_findings_i29`, `security_mfa`, `security_passwords`, `server_status`, `service_catalog`, `service_catalog_i30`, `shared_item_fixes`, `shim_deprecations`, `sla_pause`, `ssl_target_guard`, `theme_accent_contrast`, `ticket_modal_scoping`, `ticket_resolution_time`, `ticket_tab_helpers_loaded`, `totp_replay`, `training_cover_gallery`, `trust_page`, `update_handler_includes`, `web_routes_convert`, `web_routes_lib`

## Pass once a settings row exists (fixture)

`automation_engine`, `automation_i33`, `compliance_framework`, `crypto_settings`, `employee_portal_i32`, `endpoint_agent_deploy_http`, `endpoint_agent_installer_ui`, `mail_templates`, `manageengine_mdm_mapping`, `manageengine_mdm_sync`, `oidc_identity_collation`, `quality_ssrf`, `recovery_drill`, `recovery_syncwatch`, `reporting_views`, `reports_migration`, `rmm_check_schedule`, `rmm_rc25_os_cleanup`, `security_sessions`, `security_vault_reveal`, `workflow_lifecycle`, `employee_portal`

## Fragments (run `tests/rmm_ui.php`)

`rmm_ui_addevice`, `rmm_ui_meshwizard`, `rmm_ui_p23_0_foundation`, `rmm_ui_p23_a_policies`, `rmm_ui_p23_b_scripts`, `rmm_ui_p23_c_alerting`, `rmm_ui_p23_d_runscript`, `rmm_ui_p68_admin`, `rmm_ui_p68_logs`, `rmm_ui_p68_remote`, `rmm_ui_rc13_mesh`, `rmm_ui_rc13_starter`, `rmm_ui_rc16_a_push`, `rmm_ui_rc16_b_msi`, `rmm_ui_rc20_patching`, `rmm_ui_rc21_software`

`rmm_ui` itself needs more than 60 seconds; in the run it had 1130 PASS and 39 FAIL lines before the limit and was not investigated.

## Fail: needs a server or environment (the message names the requirement)

* `endpoint_agent_build_host`, `endpoint_agent_linux_hosting`: scratch `config.php` must define `RIVET_BUILD_HOST_DIR`.
* `endpoint_agent_golden`: `RIVET_CORE_DIR` (a rivet-core checkout).
* `findings_http_walk`, `reports_http_walk`: `RIVETIT_WALK_BASE`, `RIVETIT_WALK_SESSION_DIR`, `RIVETIT_WALK_DOCROOT` and a running instance.
* `automation_api_i33` (`RIVETIT_TEST_BASE`), `rmm_alert_ticket_category` (`WEB_BASE`): a running loopback instance.
* `i34_reports_migration` (`RIVETIT_TEST_DB_OLD`: a second database loaded from the previous db.sql), `service_catalog_i30_migration` (`RIVETIT_TEST_UPG_DB`, `RIVETIT_TEST_FRESH_DB`).
* `oidc_provider` (`OIDC_TEST_SESSIONS`), `quality_module` (`QMS_STATE`, `QUALITY_TEST_SESSIONS`, two servers), `rmm_rc25_linux` (`EA_TEST_NO_FORCE=1`).
* `web_routes_apply`, `web_routes_nginx`, `web_routes_probe`, `web_routes_registry`, `web_routes_security`: need nginx, a root-owned copy of the apply tool and the live-route probes; not runnable as an unprivileged user on a scratch box.
* `api_auth_unify_test`, `lifecycle_automation_test`, `project_tasks_phase1_test`, `project_tasks_phase2_3_test`: replay old migrations from an older base version ("schema reset to 2.6.20x"); need a database at that base version, which this baseline does not provide.
* `reporting_page`: needs `RIVETIT_TEST_RPT_PORT` / `RIVETIT_TEST_RPT_ROOT_PASSWORD` / `RIVETIT_TEST_SCHEMA_DUMP` (given here, but the schema dump is the stale `db.sql`, see below).
* `mcp_oauth_cimd_mock`, `service_catalog_browser_seed`: helpers started by another test, not tests.

## Fail: needs a fixture, or not root-caused

These fail on both baselines. They drive pages or APIs over HTTP or exercise crypto with a key file, and most pass hundreds of checks before a handful fail. **The cause was not established**; do not read "fixture" as proven.

`ticket_views` (HTTP 500 on the tickets page for the test agent), `security_login_http`, `mcp_oauth_admin`, `mcp_oauth_cimd`, `mcp_oauth_flow`, `mcp_oauth_hardening`, `mcp_oauth_walkthrough`, `chat_teams`, `slack_interactive`, `chat_integration`, `chat_webhooks`, `crypto_panel`, `crypto_rewrap`, `crypto_rmm_after_rewrap`, `crypto_vault`, `security_crypto`, `mail_intake_db`, `manageengine_mdm_mapping_web`, `manageengine_mdm_onprem`, `manageengine_mdm_web`, `endpoint_agent_build_host_ui`, `directory_api_test` (token request 500), `rule_summary` (expects a status named Open that an empty database lacks), `mcp_tools` (inserts ticket status id 1; `db.sql` already seeds it), `redis_settings`.

## Fail: genuinely broken

* `crypto_registry_scan`, `service_catalog_migration`: `db.sql` is stamped `RIVETIT_SCHEMA_VERSION: 2.6.100` while the application is 2.6.231, so a fresh `db.sql` install does not equal the migrated schema (for example `backup_runs.run_encrypted`, a flag column that matches the `*encrypted*` pattern, is missing from `db.sql` and unlisted in the scan's exclusion list). Fix at release by regenerating `db.sql` (see docs/RELEASING.md), then add `backup_runs.run_encrypted` ("a flag, not a secret") to the exclusion list in `tests/crypto_registry_scan.php`; doing the second step before the first would make the "exclusion names only existing columns" check fail.

## Fixed in this branch

Code: `csp_inline_handlers`, `no_native_dialogs` (Quality and SSO pages used inline handlers and `confirm()`; `agent/js/project_task_panel.js` fell back to `window.confirm`), `no_hardcoded_product_name` (20 literal "RivetIT" strings), `rivet_time_lint` (8 hard-coded "UTC" labels; the compliance restore-test form now takes and shows the company time zone and the handler converts to UTC).

Tests that were wrong: `api_router_legacy_throttle` (the 15-failures throttle left with the retired X-Api-Key keys; it now checks the refusal and its rate-limited audit), `employee_portal` (default home sections now include announcements and kb), `reports_department_scope` (did not load `reportClientIn` / `reportClientClause`), `reporting_views` (assumed a UTC company; now sets it, and without Docker loads time zone tables into a private throwaway server or skips cleanly), `compliance_framework` (posts company-zone times, sets a known zone), the three browser suites (take the scratch DB name and user from the environment; the employee-portal seed no longer trips over foreign keys on an install that has demo data).

## Browser suites (run on a scratch install with fictional demo data)

`tests/service_catalog_browser.js` ALL PASS; `tests/i34_browser_smoke.js` light and dark ALL PASSED; `tests/browser/employee_portal_i32_smoke.mjs` 16 of 16. No product bug was found by them; the failures were in the test setup. Accessibility probe: `tests/a11y_new_pages.js` (labels, accessible names, duplicate ids, heading order, focus visibility, WCAG AA text contrast where the background is a flat colour; text over gradients is not measured).

## Core PHPUnit conformance (`tests/core`)

221 tests, 1581 assertions, 0 failures, 4 skipped by design ("This edition does not offer createAssetWithType()", "This bus does not keep events"). Run with PHPUnit from a RivetCore checkout that has `composer install --dev` (`RIVETCORE_PHPUNIT_AUTOLOAD`), a scratch database (`RIVETCORE_TEST_DB_*`) and a throwaway Redis (`RIVETCORE_TEST_REDIS_PORT`); without Redis 8 more are skipped.

## Reproducing

Private server: `mariadb-install-db --no-defaults --datadir=D`, start `mariadbd --no-defaults --datadir=D --port=P --socket=D/sock`, load `db.sql`, insert a `settings` row at version 2.6.100, run `php scripts/update_cli.php --update_db`, delete the settings row, dump to `golden.sql`. Point PHP at the private socket with an extra ini in `PHP_INI_SCAN_DIR` (`mysqli.default_socket=D/sock`) so `config.php` and the test servers a test starts use it too. Reload `golden.sql` before each test.
