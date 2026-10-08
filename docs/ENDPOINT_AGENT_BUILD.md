# Building the Windows endpoint agent

The agent source, its build, CI and release process moved to the rivet-core repository together with the server-side module (RivetCore 1.0.0-rc.4).

* Source: `endpoint-agent/` in rivet-core (`README.md` there: architecture, security model, install/uninstall policy, what is unverified on Windows).
* Build, test counts, footprint and signing: `docs/rmm/AGENT_BUILD.md` in rivet-core. `make dist VERSION=X.Y.Z` builds the Windows executables; the
  `endpoint-agent` workflow of rivet-core builds them on `agent-v*` tags and publishes the GitHub Release.
* Getting a build into RivetIT: [ENDPOINT_AGENT.md](ENDPOINT_AGENT.md) section 4 (upload under Administration > Endpoint agent > Agent binaries, or `scripts/endpoint_agent_publish.php`).

The agent protocol and wire format are unchanged by the move; already enrolled agents keep working and update through the usual release ring.
