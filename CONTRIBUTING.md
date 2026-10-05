# Contributing to RivetIT

Thanks for helping improve RivetIT. Bug reports, questions and feature ideas go in the repository's
issues (templates are provided); security problems never do — see [SECURITY.md](SECURITY.md).

## Getting a development instance

The quickest way is Docker Compose (see [README.md](README.md#self-hosting) and
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)): the app code is bind-mounted from your checkout, so edits show
up on the next page load. [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) explains the portals, the login and
permission model, the data model, module toggles, migrations and integrations. [docs/API.md](docs/API.md)
covers the REST API.

## Ground rules

- **Plain PHP, no framework.** Follow the style of the file you are editing: `mysqli` with escaped or
  integer-cast values, output escaped with `nullable_htmlentities()` / `htmlspecialchars()`, CSRF tokens on
  every form (`validateCSRFToken()` in POST handlers), permissions checked with `enforceUserPermission()`.
- **The product name comes from `includes/branding.php`.** Use `APP_NAME` and the other `APP_*` constants in
  PHP output; never type the product name or the repository URL into a page, e-mail or PDF.
- **Keep the legacy identifiers.** The `ITFlow\` namespace (PSR-4, `src/`), `itflow_*` functions,
  `css/itflow*.css` files, database/config names and the other names listed in [REBRANDING.md](REBRANDING.md)
  stay as they are; new classes go under `src/` in the existing `ITFlow\` namespace.
- **"Department" in the UI, `client` in the code.** Screens say Department; tables, variables and the API keep
  `client` / `client_id` (see the naming note in ARCHITECTURE.md). The API contract does not change: add
  endpoints or optional fields, never rename or remove existing ones.
- **Schema changes** go in two places: a new version step at the end of `admin/database_updates.php` (bumping
  `LATEST_DATABASE_VERSION` in `includes/database_version.php`) for existing installs, and the same change in
  `db.sql` for fresh installs. Never rename tables or columns.
- **Never edit `vendor/` or `plugins/`.** They are third-party code with their own licenses.

## Before you open a pull request

- `php -l` every PHP file you changed (the PHPLint workflow runs on every pull request; the db.sql workflow
  imports `db.sql` when it changes).
- If you touched the API, update `api/v1/openapi.yaml` and run `scripts/check_openapi_drift.sh`.
- Load the pages you changed on a test instance with the browser console open, and check the PHP error log.
- Branch from and open pull requests against `beta`; see [docs/RELEASING.md](docs/RELEASING.md).
- Branch from and open pull requests against `beta`; production (`main`) only receives releases. See
  [docs/RELEASING.md](docs/RELEASING.md).
- Add an entry under `[Unreleased]` at the top of [CHANGELOG.md](CHANGELOG.md) for anything users or
  administrators will notice, including any upgrade step.

## License

RivetIT is licensed under the GNU General Public License v3.0 ([LICENSE](LICENSE)); it is built on ITFlow,
which uses the same license (see [NOTICE](NOTICE)). By contributing, you agree that your contribution is
licensed under the same terms.
