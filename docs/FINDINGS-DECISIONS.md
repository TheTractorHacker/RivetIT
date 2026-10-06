# Findings decisions (issue #29)

Product decisions taken while closing out the application findings from the user-guide review. In every case the
owner's chosen option was "apply the recommended option". Each entry gives the finding, the options considered, what
was chosen, why, and how to reverse it. The per-item outcome for the whole list is in `FINDINGS-STATUS.md`.

## 1. Automatic system notes no longer log time

- **Finding.** Every automatic ticket note (closed, re-assigned, priority changed, merged, invoice/quote created,
  Outlook sync and so on) inserted `ticket_reply_time_worked = '00:01:00'`, so each one added a billable minute.
- **Options.** (a) Leave it. (b) Write `00:00:00` for automatic notes. (c) Write `00:00:00` and rewrite history.
- **Chosen.** (b). All automatic notes now write `00:00:00`: `agent/post/ticket.php` (22 inserts, including the
  automatic "Ticket re-assigned/unassigned" notes), `api/v1/tickets.php` ("Ticket closed."),
  `cron/outlook_schedule_sync.php`. Time a person types (reply forms, bulk reply/resolve, Log Time, task time) is
  untouched, and the bulk reply/resolve dialogs keep their 00:01:00 suggestion because a person confirms it.
- **Why.** The reports (`functions.php` billable totals, Time by Technician, Department ticket time, client time
  detail) all `SUM(TIME_TO_SEC(ticket_reply_time_worked))`, so a note that did no work must be zero. Zero is also
  already what the RMM, automation-engine and task-undo notes write.
- **Existing data is not rewritten** (no migration). Notes created before this change keep their minute; only new
  notes are zero. An admin who wants history cleaned can, after a backup, run
  `UPDATE ticket_replies SET ticket_reply_time_worked = '00:00:00' WHERE ticket_reply_type = 'System' AND ticket_reply_time_worked = '00:01:00'`.
- **Reverse.** Put `'00:01:00'` back in those inserts.

## 2. Edit schedule is reachable again (`ticket_onsite`)

- **Finding.** `agent/modals/ticket/ticket_edit_schedule.php` (the only writer of `tickets.ticket_onsite`, appointment
  start/end and notes) had no link.
- **Options.** (a) Delete the modal and the column. (b) Restore a link.
- **Chosen.** (b). The ticket page now shows a schedule line (the formatted appointment plus Remote/Onsite, or "Edit
  schedule") and a menu item **Edit schedule**, both opening the modal. Both need Tickets, assets & docs at Modify and
  an open ticket, matching every other ticket edit. The modal itself now also enforces Modify plus department access
  (it only trusted the pop-up map before) and `includes/modal_permissions.php` lists it at level 2.
  `agent/calendar_feed.php` already selects `ticket_onsite` (it labels the event Onsite/Remote when the department
  has no address), so nothing changed there.
- **Why.** The Onsite filter and counter on the ticket list, the quote/invoice dialogs and the delivery-method default
  all read the column; without a writer they were permanently empty.
- **Reverse.** Remove the two links in `agent/ticket.php`.

## 3. RMM vendor warning is shown

- **Finding.** Acknowledging or resolving an alert for a vendor that cannot do it (Sophos, Level) returns
  `vendor_warning`; the UI did not show it properly.
- **Chosen.** A single alert shows a toast ("Updated here, but not synced to the RMM: ..."). A bulk action shows
  ONE summary toast ("... 3 of 12 alert(s) could not be synced ...") and delays the page reload until it has been
  readable. Text is set with `textContent`. Local state is still updated either way (unchanged).
- **Reverse.** Drop `rmmToast`/`rmmVendorSummary` in `agent/rmm_alerts.php`.

## 4. Comet "Auto-create tickets" is now honoured

- **Finding.** `config_comet_auto_ticket` was saved and never read. `includes/comet.php` created a ticket for every
  failed or missed backup regardless.
- **Options.** (a) Remove the checkbox. (b) Make the flag decide.
- **Chosen.** (b). Flag **on**: a failed or missed backup opens ONE ticket (High priority, status by name, same
  assignee rule as every other path). The existing open `comet_backup_alerts` row per device and username is the
  dedupe key, so repeats and the 48-hour missed check never open a second one, and the recovery path (a success job)
  adds the note and closes that ticket as it already did. Flag **off** (the default): the alert row and the in-app
  notification are still created (Backups page, Alerts) with `alert_ticket_id = 0`, and recovery just resolves the
  alert.
- **Behaviour change to be aware of.** Installs that never ticked the box were getting tickets anyway; they now get
  alerts only until an admin ticks **Auto-create tickets** under Settings > Integrations > Comet (the label now says
  so). This is the one place "off" is not identical to the old behaviour, because the old behaviour made the checkbox
  meaningless. Cron now also reads `config_comet_auto_ticket` (it builds its own settings variables).
- **Reverse.** In `comet_process_job()` and `comet_check_missed_backups()`, call `comet_create_alert_ticket()`
  unconditionally.

## 5. `/api/v1/metrics-ingest` stays unreleased

- **Finding.** `api/v1/metrics_ingest.php` exists with no route, no token table and no UI.
- **Chosen.** Keep it unreleased and unwired. The handler header and `docs/API.md` ("Not available yet") say so. No
  metric tables, routes or settings were touched; the endpoint agent being built separately decides how to report.
- **Reverse.** Wire the route in `api/v1/index.php` above the pre-auth body read (see the handler header), add the
  `device_metric_tokens` table through a migration, and move the doc entry into the endpoint tables.

## 6. Custom fields are labelled "Stored only"

- **Finding.** `admin/settings_custom_fields.php` stores fields that no record page displays.
- **Chosen.** No new behaviour. The page opens with a warning "Stored only: not shown on records yet", the Settings
  hub card says the same, and the page is in the Settings side-nav open-list (it already was, so the group stays open
  and highlighted). It stays reachable from the hub and settings search.
- **Reverse.** Remove the alert and the hub text once records render the fields.

## 7. A kiosk-only role can use Devices & PINs

- **Finding.** `itflow_limited_home_for()` sends a role with only `module_training_kiosk` to Devices & PINs, whose
  guard (`Access::pageGuardKiosk`) also required Training Read, and the Training JSON router required it for every
  action.
- **Chosen.** `pageGuardKiosk($min)` now needs Training switched on and `module_training_kiosk >= $min`, nothing else
  (pure rule: `Access::kioskDecision`). The router lets the `kiosk_admin.php` actions through on
  `module_training_kiosk >= 1` (`Access::apiKioskRoute`); every handler still applies its own `apiKiosk()` level, and
  `trainerPinAdminSet` is still Administrator-only. Every other route and every other page guard is unchanged
  (Training >= 1 as before). Covered by `tests/findings_round.php`.
- **Reverse.** Restore `pageGuard(1)` in `pageGuardKiosk` and the single `Access::api(1)` in the router.

## 8. Session length labels state the effective values

- **Finding.** The code enforces a 30-day minimum (`includes/session_init.php`, `includes/load_global_settings.php`);
  the stored defaults are 480 minutes and 3 days.
- **Chosen.** Enforcement untouched. The Security settings page shows the value in effect, adds the unit, and the
  help text says that lower values (including the old stored defaults 480 and 3) are raised to 30 days, with a 90-day
  ceiling on the session. The column defaults in `db.sql` are left alone (changing them needs a migration for no
  behavioural gain).
- **Reverse.** Remove the two help lines in `admin/settings_security.php`.

## 9. Calendar "Repeat" removed

- **Finding.** The Repeat select was disabled, posted nothing useful and `calendar.php` never expanded repeats.
- **Chosen.** The control is gone from the add and edit dialogs, and the handlers no longer write `event_repeat`.
  The column and existing values are untouched (previously every save wrote `0` over it).
- **Reverse.** Restore the select, `$repeat` in `event_model.php`, and the `event_repeat = '$repeat'` writes in
  `agent/post/event.php`; implementing repeats would also need expansion in the calendar feed.

## 10. Permanent deletes can be switched on

- **Finding.** `config_destructive_deletes_enable` gates the Delete buttons on people, locations, printers, network
  drives, software and products, but nothing wrote it.
- **Chosen.** A switch under Administration > Settings > Security > "Permanent deletes", with a red warning. Saved
  through the existing security handler, only when the form carries its marker (a partial post cannot switch it off),
  default off, and each change is written to the audit log. The Delete buttons already test the setting on page load.
- **Reverse.** Remove the switch and the handler block; the column stays.

## 11. Company-wide vendors stay Financial-only

- **Finding.** Vendors that belong to no department need `module_financial` (Modify) to add or edit
  (`agent/post/vendor.php`), while department vendors follow the department and Tickets, assets & docs permissions.
- **Chosen.** Keep as is. Only the guide wording changed (`docs/user-guide/01-getting-started.md`,
  `12-administration-users-and-security.md`).
- **Permissions note.** A role that should manage company-wide vendors needs Financial at Modify; deleting needs
  Financial at Full. Department vendors need Departments at Modify and department access. Reverse: change the
  `enforceUserPermission('module_financial', ...)` branches in `agent/post/vendor.php`.

## 12. Decided "no change"

| Finding | Decision | Reason |
|---|---|---|
| No UI moves a person to another department | No change | People move between departments only through People Import, deliberately: a move changes who can see the person, their assets and tickets, so it is an audited bulk operation, not a field on the edit form. |
| Technician role has no Reporting or Knowledge Base rows | No change | The built-in Administrator and Technician roles are protected and migrations must not change their permissions (module-only logins stay contained). Admins grant those modules by creating or copying a role. |
| `admin/category.php` opens on the Expense tab | No change | The default tab is kept. |

## Verified and not a bug

- **"Portal-created documents store a contact id in `document_created_by`."** In the portal `$session_user_id` is the
  signed-in portal account's own `users.user_id` (`client/includes/check_login.php` loads `users` by it; portal
  tickets use it for `ticket_created_by` the same way), never a contact id. The agent views join `users` on it and so
  show the portal account's name. Guarded by a test; no code change.
