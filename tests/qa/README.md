# QA flows (Playwright, Python)

Run against the demo instances only (never live). Needs the `playwright` Python package and its Chromium.
`QA_PYLIBS=<dir with greenlet/pyee> python3 ticket_flow.py <it|msp>` then
`python3 ticket_lifecycle.py <it|msp> "<ticket url path from ticket_flow output>"`.

Targets are in `common.py` (`EDITIONS`): RivetIT demo = http://127.0.0.1:8080, MSP demo = https://10.1.0.45:8445.
Note `:8444` may be proxied to a different scratch instance; do not use it.
