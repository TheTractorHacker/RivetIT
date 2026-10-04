# Screenshots without call-outs

The same pictures as [`../images/`](../images/), with the same folder and file names, but **without the numbered
red call-outs and frames** the user guide draws on top of them. They are meant for reuse outside the guide, for
example on a web site or in slides.

- **One folder per page group** (`service-desk/`, `portal/`, `admin-config/`, ...), files named `NN-slug.png` exactly
  as in `../images/`. A picture in the guide at `images/portal/09-ticket.png` is `images-clean/portal/09-ticket.png`
  here. 344 of the 346 guide pictures have a counterpart with the identical name and the identical pixel size.
- **Same capture, one step skipped.** They come from the same demo company and the same capture scripts as the
  guide's pictures; the only difference is that the call-out step is skipped. Crops, window sizes and data match.
- **Lossless.** The PNGs are recompressed without changing a pixel (no palette reduction), so they are larger than
  the guide's pictures (about 25 MB in all, against about 13 MB).
- **All data is invented.** The company is the made-up Summit Ridge Manufacturing; names, addresses, phone numbers,
  serials and keys are fictional.

## What differs from the guide's folder

| Guide picture | Here |
|---|---|
| `getting-started/03-three-scopes.png` (three sidebars side by side, with captions) | the three real sidebars on their own: `03-three-scopes-app-level-sidebar.png`, `03-three-scopes-department-workspace.png`, `03-three-scopes-company-wide.png` |
| `getting-started/10-admin-vs-technician.png` (two sidebars side by side, with captions) | `10-admin-vs-technician-administrator.png` and `10-admin-vs-technician-technician.png` |

Everything else is the same picture minus the call-outs. A few things in both folders are deliberate and are not
call-outs, so they are still present here:

- Billing, accounting and CRM entries are hidden in the few places they would show (those features are off by
  design), for example the Sales and Financial rows of the role editor and the Invoice and Quote cards on the
  Notifications page.
- Real-looking placeholder values are replaced with invented ones, the installation ID on the Telemetry page is
  blanked, and a few forms are filled with illustrative text (nothing is saved).
- `admin-config/36-scheduled-jobs-edit.png` shows the application's own schedule dialog on a demo that has no
  scheduled job to edit, so the dialog is reconstructed from the application's markup.
- Some pictures are crops of one card or pop-up rather than the whole page, as in the guide.
- Times and dates ("2 days ago", early October 2026) come from the demo clock when the picture was taken, so they can
  differ slightly from the guide's pictures.

## Regenerating

```bash
docs/user-guide/tools/build-demo.sh                                    # a fresh demo instance
NODE_PATH=$(npm root -g) node docs/user-guide/tools/run-all.cjs --clean # writes images-clean/ (and copies the installer shots)
docs/user-guide/tools/optimize-images.sh --lossless docs/user-guide/images-clean
```

See [`../tools/README.md`](../tools/README.md) for the requirements.
