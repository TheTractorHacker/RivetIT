# ISO/IEC 27001:2022 Annex A — Control Mapping

**Scope:** this document maps every one of the 93 Annex A controls in ISO/IEC 27001:2022 against what
`deploy/install.sh`, `deploy/harden.sh`, `deploy/backup.sh` (+ its systemd timer), `deploy/update.sh`, and
the base ITFlow-Internal-IT application itself actually do — and, just as importantly, what they do
**not** do. It is a gap-analysis working document for a company deploying this software, not a
certification, an auditor's report, or a Statement of Applicability (SoA). See the summary at the end for
what this document is — and isn't — a substitute for.

**Written against:** `db.sql` / `includes/database_version.php` `LATEST_DATABASE_VERSION 2.6.67`,
and the `deploy/` tooling as of this writing.

Each control gets exactly one of three honest statuses:

| Status | Meaning |
|---|---|
| **Automated** | A named step in `deploy/install.sh`, `deploy/harden.sh`, `deploy/backup.sh`, or `deploy/update.sh` implements this control (or a concrete, verifiable part of it) with no manual work required. |
| **Supported by the application** | An existing ITFlow-Internal-IT feature satisfies this control (partly or fully) once the company uses it — no deploy script is involved. |
| **Organizational responsibility** | A genuine policy, process, people, legal, or facilities matter. No script or software feature can satisfy it; it requires a decision or document from the company itself. |

Where a control is satisfied partly by a script and partly by the application, or partly by a technical
control and partly by policy, the single status reflects the larger/primary contribution and the detail
column says so explicitly — nothing here is claimed as fully solved when it isn't.

---

## A.5 — Organizational controls (37)

| Control | Status | Detail |
|---|---|---|
| **5.1** Policies for information security | Organizational responsibility | Requires a written, management-approved information security policy document — no script can author or approve organizational policy. |
| **5.2** Information security roles and responsibilities | Organizational responsibility | Requires the company to define and assign security roles in its own org chart (e.g. who is the security officer). ITFlow's role/permission system (5.16–5.18, 8.2, 8.3) can enforce whatever the company decides once decided, but the decision itself is organizational. |
| **5.3** Segregation of duties | Supported by the application | The `user_roles` / `user_role_permissions` module-permission system (checked via `lookupUserPermission()`) lets a company define separate roles — e.g. ticketing tech vs. billing admin vs. full admin — so duties can technically be segregated. Whether a company actually configures separate roles, rather than granting everyone the same role, is its own decision. |
| **5.4** Management responsibilities | Organizational responsibility | Requires management to actively require staff to apply security in line with policy — a leadership/process matter, not a software one. |
| **5.5** Contact with authorities | Organizational responsibility | Requires the company to establish its own contact list/process with law enforcement, regulators, and CERTs. |
| **5.6** Contact with special interest groups | Organizational responsibility | Requires the company to join and maintain relationships with security forums, vendor advisories, and ISACs relevant to its sector. |
| **5.7** Threat intelligence | Organizational responsibility | Requires a process for collecting and acting on threat intelligence. `deploy/harden.sh`'s unattended-upgrades consumes upstream *vendor patch* advisories, which is patch management (8.8), not threat intelligence gathering. |
| **5.8** Information security in project management | Organizational responsibility | Requires security to be embedded in the company's own project methodology (e.g. requiring a security review before a new feature or integration ships). |
| **5.9** Inventory of information and other associated assets | Supported by the application | ITFlow-Internal-IT's core purpose is exactly this: it *is* the asset/information inventory once populated — assets, locations, contacts, credentials, and network documentation records all live in it. Populating and maintaining that inventory is ongoing use, not something a deploy script does for the company. |
| **5.10** Acceptable use of information and other associated assets | Organizational responsibility | Requires a written Acceptable Use Policy the company adopts for staff. |
| **5.11** Return of assets | Supported by the application | The Employee Lifecycle Workflows feature (`workflow_templates`/`workflow_runs`) lets a company build an offboarding checklist that includes an asset-return task, and `asset_assignments` (with `AssetAssignmentService`) tracks exactly who currently holds which asset so a return can be verified against that history. The workflow tracks and reminds — it does not itself repossess hardware. |
| **5.12** Classification of information | Supported by the application | Department records (`client_edit.php`) carry a security-classification field that can encode whatever classification scheme a company adopts. Defining that scheme (levels, handling rules per level) is still the company's own decision. |
| **5.13** Labelling of information | Organizational responsibility | Requires a labelling scheme/process; ITFlow-Internal-IT has no automated information-labelling feature. |
| **5.14** Information transfer | Automated — `deploy/install.sh` | Every request to the app is forced over TLS (certbot-issued certificate, or a self-signed backend cert in `--proxy-mode`) with `config_https_only = TRUE` written into `config.php`, so information in transit to/from the app is always encrypted. Formal data-transfer agreements with third parties (e.g. an integration partner) remain organizational. |
| **5.15** Access control | Supported by the application | `user_roles`/`user_role_permissions` (module-level) and `enforceClientAccess()`/`user_client_permissions` (department-level, e.g. walling off HR/Finance departments) implement access control at the application layer. `deploy/install.sh`/`deploy/harden.sh` reinforce it at the infrastructure layer (default-deny `ufw`, MariaDB bound to `127.0.0.1` only) — see 8.20. |
| **5.16** Identity management | Supported by the application | Every user is a distinct row with its own login, role assignment, and (de)activation state, managed through the existing user administration screens. |
| **5.17** Authentication information | Supported by the application | Passwords are bcrypt-hashed (`password_verify()`), TOTP secrets are encrypted at rest (`encryptOtpSecret()`/`decryptOtpSecret()`), and each user's own zero-knowledge vault key is derived from their password rather than stored in the clear (`setupFirstUserSpecificKey`/`setCanonicalVaultKey`, set up by `scripts/setup_cli.php` for the first admin). |
| **5.18** Access rights | Supported by the application | Granting, changing, or revoking a user's role (including the single full-admin role created by `scripts/setup_cli.php`) is managed entirely through the existing user administration screens (`admin/users.php`, `admin/post/users.php`). |
| **5.19** Information security in supplier relationships | Organizational responsibility | Requires the company's own vendor risk-assessment process for any supplier it uses (hosting provider, SMTP relay, RMM, M365/Odoo integrations, etc.). |
| **5.20** Addressing information security within supplier agreements | Organizational responsibility | A contract-language matter between the company and each of its suppliers. |
| **5.21** Managing information security in the ICT supply chain | Organizational responsibility | `composer.lock` pins exact dependency versions and third-party libraries under `plugins/` are vendored into the repo rather than fetched at deploy time, which narrows (but does not eliminate) supply-chain drift risk. The broader supplier ICT supply-chain risk process is still the company's own. |
| **5.22** Monitoring, review and change management of supplier services | Organizational responsibility | Requires an ongoing process to review supplier service changes and their security impact. |
| **5.23** Information security for use of cloud services | Organizational responsibility | This tooling's deployment model is explicitly self-hosted (the company's own server or VPS, own database) rather than a SaaS/cloud dependency — but any cloud services the company chooses to use alongside it (SMTP relay, DNS, backup offsite storage) still need their own risk decision. |
| **5.24** Information security incident management planning and preparation | Organizational responsibility | Requires a written incident response plan (roles, escalation path, communication plan) prepared before an incident happens. |
| **5.25** Assessment and decision on information security events | Organizational responsibility | Requires a defined process/criteria for triaging a security event once detected. |
| **5.26** Response to information security incidents | Organizational responsibility | Requires a defined incident response procedure to actually execute. |
| **5.27** Learning from information security incidents | Organizational responsibility | Requires a post-incident review process to feed lessons back into policy and controls. |
| **5.28** Collection of evidence | Supported by the application | The app's own audit trail (`logAction()`-based `logs` table, plus the `audit_events`/`AuditService` layer covering login outcomes and vault-credential reveals) provides a forensic evidence source. A formal chain-of-custody process for using that evidence in an investigation is still organizational. |
| **5.29** Information security during disruption | Organizational responsibility | Requires a business continuity plan covering more than data (alternate premises, staff continuity, communication). `deploy/backup.sh` (8.13) is the closest technical building block, but backups alone are not a disruption plan. |
| **5.30** ICT readiness for business continuity | Organizational responsibility | Requires defined RTO/RPO targets and a tested restore/DR plan. `deploy/backup.sh` and the restore procedure documented in `deploy/README.md` are the technical building blocks a company would exercise as part of such a plan, but planning and testing it is the company's own responsibility. |
| **5.31** Identification of legal, statutory, regulatory and contractual requirements | Organizational responsibility | Requires the company to identify which laws, regulations, and contracts actually apply to it (data protection law, industry regulation, customer contracts) — a legal/compliance analysis no script can perform. |
| **5.32** Intellectual property rights | Organizational responsibility | A legal/IP-compliance matter for the company's own content and any third-party licenses it relies on. (The application's own license is documented in `LICENSE` — that covers the software's IP, not the deploying company's.) |
| **5.33** Protection of records | Organizational responsibility | Requires a records-retention policy driven by legal/regulatory/business requirements. `deploy/backup.sh` (8.13) and the app's own edit-history features (`credential_versions`, `kb_article_versions`) provide the technical substrate for *not losing* records, but deciding what must be retained, for how long, and under what protection is a policy decision. |
| **5.34** Privacy and protection of PII | Organizational responsibility | Requires the company to identify which privacy law applies to it (GDPR, CCPA, etc.) and build its own compliance program around it. The app's zero-knowledge vault encryption (8.24) is a genuine technical control that helps protect PII stored in it, but the legal analysis and broader program are organizational. |
| **5.35** Independent review of information security | Organizational responsibility | Requires a periodic independent/internal audit review of the security program — this document itself would be one input to such a review, not a substitute for it. |
| **5.36** Compliance with policies and standards for information security | Organizational responsibility | Requires an ongoing process to check actual practice against the company's own written policies. |
| **5.37** Documented operating procedures | Automated — `deploy/install.sh`, `deploy/harden.sh`, `deploy/backup.sh`, `deploy/update.sh` | `deploy/README.md` and this document are themselves the documented, version-controlled, repeatable operating procedures for installing, hardening, backing up, and updating a production instance — replacing tribal knowledge with a script and a written procedure. |

## A.6 — People controls (8)

| Control | Status | Detail |
|---|---|---|
| **6.1** Screening | Organizational responsibility | Background/reference checks before hiring are an HR/legal process with no software role. |
| **6.2** Terms and conditions of employment | Organizational responsibility | An HR/legal contract matter. |
| **6.3** Information security awareness, education and training | Organizational responsibility | Requires an actual training program for staff. |
| **6.4** Disciplinary process | Organizational responsibility | An HR/legal process for responding to policy violations. |
| **6.5** Responsibilities after termination or change of employment | Supported by the application | The same Employee Lifecycle Workflows offboarding checklist noted under 5.11 can include an "ITFlow account deactivated" (or similar) task, and asset-assignment history shows exactly what that person had. The workflow tracks and reminds — it does not itself deactivate accounts in this or any other connected system (there is no live Microsoft 365/Entra automation wired up yet; see `PROGRESS.md`), so someone still has to perform the action. |
| **6.6** Confidentiality or non-disclosure agreements | Organizational responsibility | A legal/contract matter between the company and its staff or contractors. |
| **6.7** Remote working | Organizational responsibility | A policy matter for how staff secure their own remote/home working environment — distinct from the application simply being reachable over HTTPS from anywhere. |
| **6.8** Information security event reporting | Organizational responsibility | Requires a defined reporting channel/process communicated to staff. ITFlow-Internal-IT's own ticketing system could serve as that channel if the company designates it as such, but nothing configures or announces that by default. |

## A.7 — Physical controls (14)

Every control in this section requires physical controls at wherever the server actually lives — an
on-prem server room, a colocation facility, or a VPS provider's own datacenter. None of it is something
`deploy/install.sh` or `deploy/harden.sh` can reach, because neither script has any relationship to the
physical machine beyond an SSH session into an already-running OS.

| Control | Status | Detail |
|---|---|---|
| **7.1** Physical security perimeter | Organizational responsibility | Requires physical perimeter controls (fencing, walls, controlled building access) at the server's hosting location. |
| **7.2** Physical entry controls | Organizational responsibility | Requires badge/key/entry-log controls at the server's hosting location. |
| **7.3** Securing offices, rooms and facilities | Organizational responsibility | Requires securing the specific office, rack, or room housing the server. |
| **7.4** Physical security monitoring | Organizational responsibility | Requires CCTV/alarm monitoring at the hosting location. |
| **7.5** Protecting against physical and environmental threats | Organizational responsibility | Requires fire suppression and flood/climate protection at the hosting location. |
| **7.6** Working in secure areas | Organizational responsibility | Requires physical procedures for anyone who works in a secure server area. |
| **7.7** Clear desk and clear screen | Organizational responsibility | An employee workspace policy, unrelated to where the server itself sits. |
| **7.8** Equipment siting and protection | Organizational responsibility | Requires correctly siting and protecting the physical server hardware, or is delegated entirely to a VPS/colo provider's own facility. |
| **7.9** Security of assets off-premises | Organizational responsibility | Requires a policy covering laptops and devices staff take off-site — the same asset-assignment tracking noted under 5.11 records *what* is off-premises, not the physical-security policy governing it. |
| **7.10** Storage media | Organizational responsibility | Requires physical handling procedures for backup media/drives, including decommissioned disks. |
| **7.11** Supporting utilities | Organizational responsibility | Requires power/UPS/cooling at the hosting location, or is delegated to a VPS/colo provider's own SLA. |
| **7.12** Cabling security | Organizational responsibility | Requires protecting network cabling from interception or damage at the hosting location. |
| **7.13** Equipment maintenance | Organizational responsibility | Requires a maintenance schedule for the physical server hardware, or is delegated to a VPS/colo provider's own SLA. |
| **7.14** Secure disposal or re-use of equipment | Organizational responsibility | Requires a secure-wipe/destruction procedure when server hardware or drives are decommissioned. |

## A.8 — Technological controls (34)

| Control | Status | Detail |
|---|---|---|
| **8.1** User endpoint devices | Organizational responsibility | MDM/endpoint security policy for the devices staff use to *access* ITFlow-Internal-IT is outside a server deployment script's reach entirely. |
| **8.2** Privileged access rights | Supported by the application | `scripts/setup_cli.php` creates exactly one full-admin account (role_id 3) at setup time rather than defaulting every account to admin; the `user_roles`/`user_role_permissions` system keeps privileged rights a distinct, explicitly-granted role thereafter. |
| **8.3** Information access restriction | Supported by the application | `lookupUserPermission()` (module-level) and `enforceClientAccess()`/`user_client_permissions` (department-level) restrict what an authenticated user can see or do — including walling off sensitive departments like HR/Finance from users without access to them. |
| **8.4** Access to source code | Automated — `deploy/install.sh` | `set_file_permissions()` chowns the deployed application tree to `www-data:www-data` and sets `750`/`640` (directories/files) — no other-user read or write access on the box. Access to the canonical source repository itself (who can push to GitHub, branch protection) is a separate, organizational control. |
| **8.5** Secure authentication | Supported by the application | Bcrypt password hashing, a per-user zero-knowledge vault encryption key (so even a full database copy doesn't expose vault contents without a user's password), TOTP-based MFA (`plugins/totp`, enforceable per-user via `user_config_force_mfa`), and WebAuthn/passkey support (`passkey_auth_begin.php`/`passkey_auth_complete.php`) as an additional factor. |
| **8.6** Capacity management | Automated — `deploy/install.sh` (+ `deploy/harden.sh`) | `mariadb-hardening.cnf` sizes `max_connections = 150` with explicit headroom over PHP-FPM's `pm.max_children = 100`, so normal web traffic can't itself exhaust database connections. Broader capacity planning (disk growth, CPU sizing, monitoring trends over time) remains an operational responsibility. |
| **8.7** Protection against malware | Automated — `deploy/install.sh` | The rendered nginx vhost denies execution of `.php`/`.phtml`/`.phar`/`.phps`/`.cgi`/`.pl`/`.sh` anywhere under `/uploads/`, so a malicious file uploaded through the app can never run as server-side code. No antivirus/EDR agent is installed by this tooling — that remains organizational. |
| **8.8** Management of technical vulnerabilities | Automated — `deploy/harden.sh` | Unattended-upgrades is configured to install *only* `${distro_id}:${distro_codename}-security` origin packages automatically (with automatic reboot explicitly disabled, since an unattended reboot of a live app is its own outage) — OS, PHP, MariaDB, and nginx security patches land without manual intervention. Application-dependency vulnerability scanning (e.g. `composer audit`) is not run by this tooling. |
| **8.9** Configuration management | Automated — `deploy/install.sh` (+ `deploy/harden.sh`) | PHP-FPM, MariaDB, nginx, and fail2ban configuration are all deployed from version-controlled templates under `deploy/templates/`, applied idempotently, and any existing file is backed up to a timestamped `.bak-<timestamp>` copy before being overwritten — never a silent, untracked hand-edit. |
| **8.10** Information deletion | Organizational responsibility | Requires a data-retention/secure-deletion policy for when application records should actually be purged. `deploy/lib/common.sh`'s `register_tmpfile()`/`shred -u` mechanism only secures transient installer secrets (e.g. a generated DB password's temp file), not application data. |
| **8.11** Data masking | Organizational responsibility | No field-level masking or anonymization is implemented anywhere in the application; this would need both an app-level feature and a company decision on which fields require it. |
| **8.12** Data leakage prevention | Organizational responsibility | No DLP tooling (egress monitoring, content inspection) is deployed. The access-restriction controls above (8.3) reduce who can see sensitive data in the first place, but that is not the same thing as DLP. |
| **8.13** Information backup | Automated — `deploy/backup.sh` | Scheduled via a companion systemd timer, dumps the database and archives `uploads/`, and encrypts the result with a passphrase supplied out-of-band (see `deploy/README.md`). The application also has its own manual/on-demand backup feature (`admin/backup.php` → "Download Backup" / "Save to Server") independent of this scheduled path. |
| **8.14** Redundancy of information processing facilities | Organizational responsibility | This tooling is single-box-per-instance by design (see `deploy/README.md`'s "not a multi-tenant installer" note). High-availability architecture — a standby app server, a database replica, a load balancer — is an infrastructure investment and decision the company would need to make and build separately. |
| **8.15** Logging | Supported by the application | The application's own `logAction()`-based audit log (plus the newer `audit_events`/`AuditService` layer covering login outcomes and vault-credential reveals) records security-relevant activity inside the app. `deploy/harden.sh`'s MariaDB hardening additionally routes the slow-query and error logs to predictable, rotatable paths under `/var/log/mysql/`, and nginx/PHP-FPM produce their own standard logs. |
| **8.16** Monitoring activities | Organizational responsibility | No SIEM or centralized alerting is deployed by this tooling. `fail2ban` (8.20) reacts automatically to brute-force patterns, but that is a narrow, specific reaction — not general security monitoring, log review, or alerting, which remains an operational responsibility. |
| **8.17** Clock synchronisation | Organizational responsibility | Ubuntu's default `systemd-timesyncd` typically keeps the system clock synchronized out of the box, but neither `deploy/install.sh` nor `deploy/harden.sh` verifies, configures, or monitors NTP itself — confirming it stays correct is an operational duty. |
| **8.18** Use of privileged utility programs | Automated — `deploy/harden.sh` (+ `deploy/install.sh`) | `php-hardening.ini`'s `disable_functions` removes `phpinfo`, `show_source`, `highlight_file`, `posix_kill`, `posix_mkfifo`, `putenv`, `create_function`, `system`, `proc_open`, `passthru`, and `assert` — confirmed by grepping the entire codebase (including `plugins/`) for zero real call sites of each (the handful of textual hits for the latter four are string literals in `setup/setup_functions.php`'s own dangerous-function scanner, or an unrelated same-named method in vendored code, never an actual call). `exec`/`shell_exec`/`popen` are deliberately **not** disabled, because the app's own self-update, backup, and cron features genuinely call them (see "Known gaps" below). Restricting *who* has shell/root access to the box itself is a separate, organizational access-management decision. |
| **8.19** Installation of software on operational systems | Automated — `deploy/install.sh` | Every package this tooling installs comes from an explicit, version-controlled manifest (`REQUIRED_BASE_PACKAGES`/`REQUIRED_PHP_PACKAGES` arrays) installed through an idempotent check-before-install helper, rather than ad hoc manual installs. Governance over what *additional* software gets installed on the box later, by whom, remains organizational. |
| **8.20** Network security | Automated — `deploy/install.sh` and `deploy/harden.sh` | `ufw` is configured default-deny-incoming with only SSH (auto-detected port), HTTP, and HTTPS explicitly allowed; `fail2ban` bans repeated SSH and application-login brute-force attempts; MariaDB is bound to `127.0.0.1` only (`bind-address = 127.0.0.1`) so the database is never reachable over the network at all. |
| **8.21** Security of network services | Automated — `deploy/install.sh` and `deploy/harden.sh` | The rendered nginx vhost denies direct HTTP access to `.git`, `.github`, `config.php`, `cron/`, `scripts/`, `backups/`, `config/`, `plugins/TCPDF/tools/`, and dotfiles, and rate-limits `/login.php` and `/setup.php` to 5 requests/minute via a shared `limit_req_zone` — the app's actual HTTP-exposed surface is locked down to only what a normal user needs. |
| **8.22** Segregation of networks | Automated — `deploy/install.sh` and `deploy/harden.sh` | MariaDB's `bind-address = 127.0.0.1` keeps the database off the network entirely (not merely firewalled — it never listens beyond loopback), and `ufw`'s default-deny-incoming posture segments this box from everything except the explicitly allowed ports. Broader network segmentation (VLANs, a dedicated database subnet) is an infrastructure-architecture decision beyond what a single-box deploy script provides. |
| **8.23** Web filtering | Organizational responsibility | This control is about filtering *outbound* web access for the company's own users/endpoints (e.g. blocking known-malicious sites) — unrelated to hosting a web application, and nothing in this tooling touches client-side web filtering. |
| **8.24** Use of cryptography | Supported by the application | The application's own per-user zero-knowledge vault encryption key (`setupFirstUserSpecificKey`/`setCanonicalVaultKey`) and `encryptSetting()`/`decryptSetting()`/`encryptOtpSecret()` protect vault and integration-credential data at rest without a recoverable master password stored in plaintext. `deploy/install.sh` covers the transport-layer half (TLS 1.2/1.3 only, `HIGH:!aNULL:!MD5` cipher list). |
| **8.25** Secure development life cycle | Organizational responsibility | An SDLC process (secure design review, threat modeling before a release) is a development-process matter for whoever maintains or extends this codebase — a deployment script cannot retrofit it. |
| **8.26** Application security requirements | Organizational responsibility | Defining security requirements for new features (tracked in this fork's own `PROGRESS.md`) is a development-process matter, not a deployment-time one. |
| **8.27** Secure systems architecture and engineering principles | Organizational responsibility | Architectural principles such as defense-in-depth and least privilege by design are a development-time discipline. `deploy/harden.sh` applies least-privilege at the infrastructure layer (narrow MariaDB grants, restrictive file permissions) as one concrete, narrower instance of the principle — not the whole of it. |
| **8.28** Secure coding | Organizational responsibility | Secure coding standards and code review for the PHP codebase itself are a development-process matter outside deployment tooling's reach. |
| **8.29** Security testing in development and acceptance | Organizational responsibility | Requires an actual testing process (SAST/DAST/penetration testing) before a release ships — not something a deployment script performs. (Upstream ITFlow separately references SonarCloud scanning in its own `SECURITY.md` — that is upstream's development process, not this fork's deployment tooling.) |
| **8.30** Outsourced development | Organizational responsibility | Governance of any outsourced or contracted development work is a vendor-management matter. |
| **8.31** Separation of development, test and production environments | Automated — `deploy/install.sh` | Each run of `deploy/install.sh` provisions a fully independent app directory, database, database user, and vhost (that is the entire point of its "another company" design — see `deploy/README.md`) — so standing up a genuinely separate dev/test/prod instance, rather than sharing one database across environments, is directly supported. Actually using it that way (vs. re-using one instance for everything) is the company's own choice. |
| **8.32** Change management | Automated — `deploy/update.sh` | Wraps `scripts/update_cli.php`'s `git pull` plus the versioned `admin/database_updates.php` migration runner, so every code and schema change to a production instance goes through the same controlled, logged, idempotent procedure rather than an ad hoc file edit or manual `ALTER TABLE`. An actual change-approval workflow (who signs off before running it against production) is still organizational. |
| **8.33** Test information | Organizational responsibility | Governs the use of production-like data in test/dev environments (e.g. not copying real client/PII data into a dev copy without scrubbing it). No script here anonymizes or scrubs data for testing — that would need its own tooling and a company policy on what "safe to use in test" means. |
| **8.34** Protection of information systems during audit testing | Organizational responsibility | Governs safeguards during an actual audit or penetration-test engagement (scoping, environment protection, data handling) — a process matter for whenever the company undergoes such testing, not something a deployment script provides. |

---

## Known gaps

Things this deployment tooling deliberately does **not** do, and why — listed here instead of quietly
left unmentioned, because a credible gap analysis says what's missing as plainly as what's covered:

- **`open_basedir` is not set.** Doing so safely requires auditing every directory the app touches —
  `uploads/`, `backups/`, PHP's session save path, temp paths, log paths, and the git working tree itself
  (needed for self-update) — and that audit has not been done. Getting it wrong silently breaks a feature
  (e.g. self-update failing to write, or a report export failing) with a confusing error far from this
  setting. This is flagged as a follow-up requiring its own dedicated audit, not something to guess at.
- **`disable_functions` deliberately excludes `exec`, `shell_exec`, and `popen`.** The application
  genuinely calls these for real, load-bearing features: git-based self-update
  (`admin/post/update.php`, `admin/update.php`, `scripts/update_cli.php`), backup creation
  (`admin/post/backup.php`), cron auxiliary work (`cron/cron.php`, `admin/post/cron.php`), and
  PHPMailer's sendmail transport (`popen`). Disabling any of the three breaks that feature outright.
  `system`, `proc_open`, `passthru`, and `assert` have zero real call sites and ARE disabled.
- **`config.php`'s `$repo_branch` defaults to `'master'`, while this repository's actual default branch
  is `main`.** This is a pre-existing mismatch in the application layer itself (also present in
  `scripts/update_cli.php --force_update`, which hardcodes `master`), not something this deployment
  tooling silently patches around. See `deploy/README.md`'s notes on `deploy/update.sh` for the practical
  consequence and workaround.
- **MariaDB's `root@localhost` authentication method is left as whatever Debian/Ubuntu's `mariadb-server`
  package already defaults to (`unix_socket`).** That default is already reasonably secure (only the OS
  root user can use it — no password to leak), and reconfiguring it incorrectly risks locking an admin out
  of their own database with no easy recovery. Left alone on purpose.

---

## Summary

Running `deploy/install.sh` followed by `deploy/harden.sh` materially improves this deployment's
**Technological (A.8)** control coverage — network segmentation, malware-execution prevention, patch
management, configuration management, and cryptography in transit are all handled with no manual work.
It also picks up a meaningful slice of **Organizational (A.5)** coverage specifically where an
organizational control has a technical component that overlaps with what these scripts do — for example
5.15 (Access control) and 5.37 (Documented operating procedures, satisfied in part by this very document
and `deploy/README.md`).

What it does **not** do — and what no software deployment script *could* do — is get a company to actual
**ISO/IEC 27001 certification**. Certification requires a full Information Security Management System
(ISMS): a documented risk assessment, a Statement of Applicability explaining each control's chosen
treatment, management review, internal audit, and the large majority of the People (A.6) and Physical
(A.7) controls above, which are policy, training, HR, and facilities matters no script can touch. This
tooling is a strong, honest **technical foundation** for that program — not a substitute for building the
program itself.
