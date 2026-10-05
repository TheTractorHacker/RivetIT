# Third-party notices

RivetIT is free software under the [GNU General Public License v3.0](LICENSE) (see [NOTICE](NOTICE) for where it
comes from). It bundles the open-source components listed here. Each keeps its own license and copyright, which
applies to that component only. All of them allow use inside a GPL-3.0 program.

The **Evidence** column says how each license was confirmed, so nothing here rests on memory:

- **header**: the license is printed in the component's own shipped file.
- **LICENSE file**: a license file ships with the component in this repository.
- **upstream**: the component's published license, as stated by its own project. The shipped files do not repeat it.
  Please check these against the upstream project when you update the component.

## Front-end libraries (`plugins/`)

| Component | Version | License | Evidence | Folder |
|---|---|---|---|---|
| AdminLTE | 3.2.0 | MIT | header | `plugins/adminlte` |
| AdminLTE | 4.0.0-beta3 | MIT | header | `plugins/adminlte4` |
| Bootstrap | 4.6.2 | MIT | header | `plugins/bootstrap` |
| Bootstrap | 5.3.3 | MIT | header | `plugins/bootstrap5` |
| Tabler | see files | MIT | header | `plugins/tabler` |
| Font Awesome Free | 5.15.4 | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT | header | `plugins/fontawesome-free` |
| jQuery | 3.7.1 | MIT | header (points to jquery.org/license) | `plugins/jquery` |
| jQuery UI | 1.13.0 | MIT | header (points to jquery.org/license) | `plugins/jquery-ui` |
| Popper | see files | MIT | header | `plugins/popper` |
| Chart.js | see files | MIT | header | `plugins/chart.js` |
| DataTables (combined build) | see files | MIT | upstream | `plugins/DataTables` |
| Simple-DataTables | see files | MIT | upstream | `plugins/simple-datatables` |
| Select2 | 4.0.13 | MIT | header (points to its LICENSE.md) | `plugins/select2` |
| Select2 Bootstrap 4 theme | see files | MIT | upstream | `plugins/select2-bootstrap4-theme` |
| Tom Select | 2.6.2 | Apache-2.0 | header | `plugins/tom-select` |
| SortableJS | 1.15.6 | MIT | header | `plugins/SortableJS` |
| clipboard.js | 2.0.11 | MIT | header | `plugins/clipboardjs` |
| Moment.js | see files | MIT | upstream | `plugins/moment` |
| Date Range Picker | 3.1 | MIT | upstream | `plugins/daterangepicker` |
| Tempus Dominus | 6.10.4 | MIT | header | `plugins/tempus-dominus` |
| Tempus Dominus (Bootstrap 4) | see files | MIT | header | `plugins/tempusdominus-bootstrap-4` |
| Litepicker | 2.0.12 | MIT | upstream | `plugins/litepicker` |
| Leaflet | 1.9.4 | BSD-2-Clause | upstream | `plugins/leaflet` |
| FullCalendar | 6.1.20 | MIT | LICENSE file | `plugins/fullcalendar` |
| TinyMCE | see files | GPL-2.0-or-later (Tiny Technologies) | LICENSE file (`license.md`) | `plugins/tinymce` |
| DOMPurify (bundled inside TinyMCE) | 3.2.6 | Apache-2.0 or MPL-2.0 | header | `plugins/tinymce` |
| marked | 15.0.12 | MIT | header | `plugins/marked` |
| Turndown | see files | MIT | upstream | `plugins/turndown` |
| pdfmake | 0.2.18 | MIT | header | `plugins/pdfmake` |
| Inputmask | see files | MIT | header | `plugins/inputmask`, `plugins/inputmask5` |
| intl-tel-input | 25.3.0 | MIT | header | `plugins/intl-tel-input` |
| Dropzone | see files | MIT | upstream | `plugins/dropzone` |
| Toastr | see files | MIT | upstream | `plugins/toastr` |
| Show/Hide Passwords (Bootstrap 4) | see files | MIT | upstream | `plugins/Show-Hide-Passwords-Bootstrap-4` |
| Barcode generator | see files | MIT | header | `plugins/barcode` |
| Redoc (RivetIT-modified build "2.5.4-rivetit") | 2.5.4 | MIT | LICENSE file | `plugins/redoc` |
| Scalar API Reference | 1.72.4 | MIT | LICENSE file | `plugins/scalar` |
| zapcallib (iCalendar) | see files | GPL-3.0 | header | `plugins/zapcal` |

## Fonts (`fonts/`)

| Component | Version | License | Evidence | Folder |
|---|---|---|---|---|
| IBM Plex Sans, IBM Plex Serif, IBM Plex Mono | see files | SIL Open Font License 1.1 | LICENSE file (`fonts/plex-serif-OFL.txt`; the license text is the same for the whole Plex family) | `fonts/` |

The OFL requires the license text to travel with the fonts, so keep `fonts/plex-serif-OFL.txt` with them. The fonts are
not sold on their own and are not renamed.

## PHP libraries (`plugins/`)

| Component | Version | License | Evidence | Folder |
|---|---|---|---|---|
| PHPMailer | 7.0.2 | LGPL-2.1 (as declared in its `composer.json`) | LICENSE file | `plugins/PHPMailer` |
| TCPDF | 6.11.3 | LGPL-3.0-or-later | LICENSE file | `plugins/TCPDF` |
| HTMLPurifier | 4.15.0 | LGPL-2.1-or-later | upstream (no license file ships; add the upstream license text if you redistribute this folder on its own) | `plugins/htmlpurifier` |
| stripe-php | 19.4.1 | MIT | LICENSE file | `plugins/stripe-php` |
| Composer packages used by the plugins | see each package | each package's own, e.g. MIT | LICENSE file in each package | `plugins/vendor` |

PHPMailer, TCPDF and HTMLPurifier are libraries under the LGPL, used unmodified. Their license texts and source stay
available in their folders, and anyone may replace them with their own copy of the same library.

## Composer dependencies (`vendor/`)

Installed by `composer install` from `composer.lock`; each package carries its own `LICENSE` file under `vendor/`.
At the time of writing the direct and indirect packages are:

| License | Examples |
|---|---|
| MIT (31 packages) | `predis/predis`, `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `nyholm/psr7`, `doctrine/deprecations`, `mtdowling/jmespath.php` |
| Apache-2.0 (6 packages) | `mcp/sdk`, `aws/aws-sdk-php`, `aws/aws-crt-php`, `opis/json-schema`, `opis/string`, `opis/uri` |
| BSD-3-Clause | `firebase/php-jwt` |
| GPL-3.0-only | `rivet/rivet-core` (the shared RivetIT core package) |

Run `composer licenses` for the live list.

## Not covered here

- **ITFlow** and the MSP-focused fork RivetIT descends from are credited in [NOTICE](NOTICE). Their code is covered
  by the GPL-3.0 license of this repository, not by this file.
- **`plugins/totp`** (a small TOTP implementation) ships with no separate license notice. It is treated as part of the
  ITFlow-derived code base under GPL-3.0. If you know it came from somewhere else, record its source and license here.
- **Images** under `img/` (the RivetIT logos in `img/branding/` and the training cover images in `img/training/`) were
  not audited for this file. If any of them came from a third party, add its source and license here.

To keep this file accurate, update the matching row whenever a bundled component is added, removed or upgraded.
