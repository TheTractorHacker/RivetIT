# Task 3 report
Status: DONE_WITH_CONCERNS
- Renderer rivetLinuxFleetPage in includes/rmm_linux_fleet.php (lazy-requires rmm_pat_ui.php), page agent/rmm_linux_fleet.php, nav entry + PATCH_VIEW ability in rmm_bootstrap.php, side_nav endpoint list.
- Carry-over a: new pure rivetLinuxFleetPickGroups($all,$visible,$only); unrestricted ($visible===null) keeps zero-device groups, restricted drops them. Tested via the pure seam.
- Carry-over b: new pure rivetLinuxFleetNotOtherOs() (/windows|macos|darwin/i, empty os kept), applied inside Build before any fetch; View now lists up to 500 per group (listDevices max) so Windows rows cannot crowd out Linux ones at SQL level. Test: 70 Win/mac + 5 Linux -> capped=false, 5 nodes.
- Carry-over c: everything escaped via rmmH; test with <script> hostname, os_version and postgres detail.
- View model gained a `groups` key (id,name list) for the filter select (not in the brief).
- Tests: linux_fleet_page/view/model all pass; php -l clean. No existing test enumerates nav/side_nav entries. The a/b view tests passed immediately (implementation preceded them in the same edit); page test was seen RED first.
Concerns: pg "Failing: <detail>" shows "Failing: " when detail empty; ungrouped fetch still capped at 500 listing; Ungrouped group has no device_count so unaffected by (a).
