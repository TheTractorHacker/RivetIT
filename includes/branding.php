<?php
/*
 * RivetIT product identity: the one place the product name, descriptions and project links live.
 *
 * Every page, e-mail, PDF, notification and script that shows the product name uses these constants
 * (APP_NAME, never a literal), so a later rename or a move of the repository is a one-file change.
 * The company's own name and logo (Settings > Company) still brand the install itself; APP_NAME is
 * the software's name ("Powered by RivetIT", page footers, setup, e-mails about the system).
 *
 * Loaded first by functions.php and by includes/app_version.php, so it exists wherever APP_VERSION does.
 *
 * Overrides, in order: a constant defined before this file (e.g. at the top of config.php), then an
 * environment variable RIVETIT_<NAME> (Docker: set it in .env / docker-compose.yml), then the default.
 * Only these names are read; nothing else in the application depends on the environment for branding.
 *
 * History: RivetIT started from ITFlow (https://github.com/itflow-org/itflow, GPL-3.0). Internal
 * identifiers that still say "itflow" (the PHP namespace ITFlow\, itflow_* functions and CSS files,
 * database and config names, API routes, backup file names) are kept on purpose for compatibility;
 * see REBRANDING.md.
 */

if (!function_exists('rivetit_brand_define')) {
    /** Defines $name from an earlier define, the RIVETIT_<NAME> environment variable, or $default. */
    function rivetit_brand_define(string $name, string $default): void
    {
        if (defined($name)) {
            return;
        }
        $env = getenv('RIVETIT_' . $name);
        define($name, is_string($env) && trim($env) !== '' ? trim($env) : $default);
    }
}

rivetit_brand_define('APP_NAME', 'RivetIT');
rivetit_brand_define('APP_SHORT_DESCRIPTION', 'Open-source internal IT operations platform.');
rivetit_brand_define('APP_DESCRIPTION', 'RivetIT is a free and open-source internal IT operations platform for managing service requests, users, devices, documentation, automation, integrations, and employee training from one centralized system.');
rivetit_brand_define('APP_TAGLINE', 'Everything your IT department needs. One platform.');

// Project links. The repository below is the one this install is built from today; change it here
// (or with RIVETIT_APP_REPO_URL) when the project moves, e.g. to a RivetIT organization.
rivetit_brand_define('APP_REPO_URL', 'https://github.com/TheTractorHacker/ITFlow-Internal-IT');
rivetit_brand_define('APP_WEBSITE_URL', APP_REPO_URL);
rivetit_brand_define('APP_DOCS_URL', APP_REPO_URL . '/tree/main/docs');
rivetit_brand_define('APP_SUPPORT_URL', APP_REPO_URL . '/issues');
rivetit_brand_define('APP_LICENSE', 'GPL-3.0');
// "Latest Release" on Admin > Update links here. The updater compares git tags, so this is the tag list.
rivetit_brand_define('APP_RELEASES_URL', APP_REPO_URL . '/tags');

// Updater source. The in-app updater (Admin > Update: fetchUpdates() and admin/update.php) fetches this
// git remote of the install's checkout and compares <remote>/<$repo_branch> with HEAD. It is the "fork"
// remote, which on existing installs points at the repository above (APP_REPO_URL); this constant only
// names it and changes nothing about where it points. To follow another repository later, add a remote
// to the checkout (git remote add <name> <url>) and set this, or RIVETIT_APP_UPDATE_REMOTE, to its name.
// Callers pass it through escapeshellarg(); only plain remote names ([A-Za-z0-9._-]) are meaningful.
rivetit_brand_define('APP_UPDATE_REMOTE', 'fork');

// Upstream attribution (GPL): shown on the About/debug page, the README and NOTICE.
rivetit_brand_define('APP_UPSTREAM_NAME', 'ITFlow');
rivetit_brand_define('APP_UPSTREAM_URL', 'https://github.com/itflow-org/itflow');

// Brand assets (served from the web root). The favicon and logos under /img/branding/ are the product's;
// a company logo or favicon uploaded in Settings still takes precedence where the page already shows it.
rivetit_brand_define('APP_LOGO_URL', '/img/branding/logo.svg');
rivetit_brand_define('APP_LOGO_DARK_URL', '/img/branding/logo-dark.svg');
rivetit_brand_define('APP_LOGO_MARK_URL', '/img/branding/logo-mark.svg');
rivetit_brand_define('APP_FAVICON_URL', '/img/branding/favicon.svg');
