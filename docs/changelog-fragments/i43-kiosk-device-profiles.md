### Training kiosk: pick tablets, one Web Clip profile per iPad (issue #43)

- Fleet links > **Per-device URLs and profiles** now lists the department's tablets with a usable serial. Tick the ones you want, then download a **CSV** (device name, serial, type, start URL) or a **ZIP with one `.mobileconfig` Web Clip per iPad** (its own serial in the URL, Full Screen, not removable, kiosk icon embedded, stable PayloadUUIDs per device). Limit 300 tablets per ZIP; foreign-department, archived, non-device and unusable-serial assets are ignored. Needs the Training kiosk permission at level 3 plus Assets view, and the once-shown link token.
- New API action `fleet_device_profiles` (Training kiosk admin API); each download is audited (`training.kiosk_fleet_profiles`).
- No database change (the fleet-link schema is DB 2.6.151). Docs: `docs/training-kiosk-setup.md`. Tests: `tests/kiosk_fleet_links.php`.
