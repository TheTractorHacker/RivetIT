### Security and permissions (issue #29)
- New installs now give the built-in Technician role Knowledge Base Modify access (browser installer and `setup_cli.php`). Reporting stays an explicit administrator grant. Existing installs are unchanged; no database change.
- Re-verified the 22 security/permission items from the user-guide findings against current code; most were already fixed. `docs/user-guide/tools/app-findings.md` now carries a status table and `tests/security_findings_i29.php` pins the guards.
