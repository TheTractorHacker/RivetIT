# Security Policy

## **Please do NOT report security concerns/vulnerabilities publicly (issues, pull requests or discussions)**

**We take security seriously**

- Whilst we are confident in the safety of the code, no system is risk-free. Nearly all software has bugs. Use your best judgement before storing highly confidential information in RivetIT.
- RivetIT is built on [ITFlow](https://github.com/itflow-org/itflow). Code it shares with ITFlow is also covered by the upstream project's own [security policy](https://github.com/itflow-org/itflow/security/policy) and code scanning.
- For what the deployment tooling in `deploy/` does and does not cover, see [`docs/ISO27001-COMPLIANCE.md`](docs/ISO27001-COMPLIANCE.md).

## Supported Versions
We operate a rolling release model on the `main` branch. Any bug fixes will be released into the latest version of RivetIT, so you must stay up-to-date.

| Version | Supported          |
|---------| ------------------ |
| 26.09   | :white_check_mark: |
| older   | :x:                |

## Reporting a Vulnerability via GitHub Security Advisories

**Security contact: [GitHub Security Advisories](https://github.com/TheTractorHacker/ITFlow-Internal-IT/security/advisories/new)** (on the repository page: **Security > Report a vulnerability**)

If you have discovered a security issue, please **[report it](https://github.com/TheTractorHacker/ITFlow-Internal-IT/security/advisories/new)** to us in as much detail as possible, so we can fix it. Include the RivetIT version (page footer, or Admin > Update) and the database version.

If the issue is in code RivetIT shares with ITFlow and you can reproduce it on an unmodified ITFlow install, please also report it to the ITFlow maintainers through [their advisories](https://github.com/itflow-org/itflow/security/advisories/new), or tell us and we will pass it on.

You should expect to receive an initial acknowledgement within 72 hours. If you don't receive any feedback, we may have missed the notification from GitHub (we're human!). Please add a comment to the advisory you opened, quoting ONLY the assigned GHSA ref.
