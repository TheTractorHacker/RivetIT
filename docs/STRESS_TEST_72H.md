# 72-hour agent stress test

Goal: see how a RivetIT server behaves with hundreds of real endpoint agents checking in for three days.

Load generator: `deploy/stress/agent_swarm.sh` (real Linux agent binary, one small Docker container per device, unique hostname and machine-id each). Run it on a separate small VM, not on the server under test.

1. **Server (beta, mw-itflow):** Endpoints module on; make a department for the test; Agent Fleet > Add device > Advanced: PC count above the planned device count (up to 5000), lifetime longer than the ramp-up. Save the token to a file on the VM.
2. **VM:** install Docker; copy the Linux amd64 agent (`rivetit-agent-linux-amd64`) and the script. `agent_swarm.sh plan` prints the ceiling for that VM.
3. **Ramp:** `up --count 25`, check Agent Fleet shows 25 online, then `up --count 100`, then in steps. Note where check-in latency, PHP-FPM workers or MariaDB threads start to hurt.
4. **Soak:** `nohup agent_swarm.sh sample --out swarm.csv --interval 60 --probe https://<server>/ &` for 72 hours. On the server also watch PHP-FPM status, MariaDB `Threads_running` and slow log, disk growth of `rmm_*` tables (metric history), and the herd-control/capacity page.
5. **Chaos (optional):** `docker restart` a batch, stop the server for 5 minutes and watch the reconnect storm, publish an agent update to a pilot ring.
6. **Finish:** `down`, then delete the test department's devices on the server and revoke the token.

Not yet verified: the swarm script has run containers against a dead port only (identity, retry, stats, sampling); enrollment and check-ins through it have not been run against a live server. The agent itself is proven under systemd by `tests/real_agent_proof/run.sh`.
