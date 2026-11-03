### Added
- **Move department** (issue #29 leftovers): People row menu. Moves a person to another active department (needs Departments at Modify and access to both). Their devices and licence seats in the old department are unassigned by default (a box keeps them), the location is kept only if the new department can use it, the primary-contact flag is cleared, the move is audited, and `employee.transferred` is raised through the existing lifecycle detection when Employee workflows auto-start is on. Web only, no API equivalent.
- Users export has an **Include archived users** box; archived agents are then listed with the status Archived.

### Changed
- **Company-wide vendors** (reverses the earlier decision that they stay Financial-only): a role with Tickets, assets & docs can list and open them (Read) and add, edit, archive and restore them (Modify). The **account number**, Delete and the vendor CSV stay Financial-only; without Financial the account number is hidden from the list, search, details and forms and is never changed by that role's edits.
- **Deleting an RMM check policy** now asks the RMM to remove the checks it already pushed to agents. If any cannot be removed the policy is kept, those deployments are marked `remove_failed`, and **Delete anyway** (which writes the device names and check ids left on agents to the audit log) is offered.
- The Waiting on Employee dashboard tile also counts open tickets in any status flagged "waiting on someone else" (pauses the SLA clock), not only the two names.

### Fixed
- Users CSV listed Department Portal logins; it is now agents only (what the Users list shows).
- Bulk department email mailed the contacts of an archived department when it was selected.
- Licence seat links: a forged asset or person id from another department was linked on add or edit; only the licence's own department can be linked now.
- Departments date range: a custom range with only a From (or only a To) date showed `... - Dec 31, 2099` (or 1970) after the page reloaded; it now reads `From Jan 15, 2026` / `Up to ...`.
- `deploy/restore.sh` refused a backup whose inner manifest is encrypted although it already requires `--passphrase-file`; it now decrypts the manifest with that file.

### Notes
- No database change (the DB range 2.6.237 to 2.6.241 is unused). New tests: `tests/i29_leftovers.php`, `tests/browser/i29_leftovers_seed.php`, `tests/browser/i29_leftovers_smoke.mjs`. Findings status tables are final in `docs/user-guide/tools/app-findings.md` and `docs/FINDINGS-STATUS.md`; decisions 13 and 14 in `docs/FINDINGS-DECISIONS.md` supersede 11 and 12.
