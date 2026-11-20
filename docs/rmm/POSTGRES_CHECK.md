# PostgreSQL health check for the Linux fleet page

The **Linux fleet** page (RMM > Patching & software > Linux fleet) shows a PostgreSQL column for any device check whose key contains
`postgres` or `pg_` (case-insensitive). A check status other than ok or unknown makes the node fail, and the check's detail text (first
120 characters, control characters removed) is shown beside it. This page gives a read-only script you can use as that check.

## How to add it

1. Open **Policies** and create a new check policy (or edit an existing one) with platform **Linux**.
2. Add a check of type **script** (shown as "Commands to run"). Paste the script below. At most 8 KiB, and give-up time at most 60 seconds
   (the default is 30; 30 is fine here).
3. Give the check a **name that contains `postgres`**, for example `postgres_health`. The page matches on the check key, so a name without
   `postgres` or `pg_` is not shown in the PostgreSQL column.
4. Make sure the policy has **Script checks** on (policy feature `script_checks`); without it Core removes script checks from the policy.
5. Assign the policy to the PostgreSQL servers only (a group or device policy). A server without the check shows no PostgreSQL status,
   which is not counted against it.

Signing: script checks execute code on the device, so Core signs every delivered check definition per device with the instance key and the
agent refuses a check whose signature does not verify. You do nothing extra; if you edit the script, the new text is signed when it is saved.
Unlike a library script run, a check does not go through the two-person approval flow. The script runs with the agent's rights (root on
Linux), so keep it read-only; this one is.

Exit codes (the existing convention, from `ChecksValidator`, the policy form text and the starter pack docs, which agree): **0 is ok, 1 is
a warning, anything else is critical.** The first line the script prints becomes the detail. This script exits 0 when healthy and 2 when
anything is wrong (critical). The Linux fleet page treats warning and critical the same (the node fails), so use 1 instead of 2 in the
`fail()` function if you prefer the check to show as a warning on the device page. The design notes for this task said "exit 1"; Core
defines 1 as warning, so 2 is used here.

## What it checks

1. `pg_isready` reports the server is accepting connections.
2. Client connections in `pg_stat_activity` against `max_connections`: fails at 90% or more.
3. On a replica (`pg_is_in_recovery()`), fails when replay lag is over 60 seconds. Lag is 0 when the replica has replayed all the WAL it
   has received (`pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn()`), so a caught-up replica of an idle primary does not alarm. Otherwise
   it is `now() - pg_last_xact_replay_timestamp()`. Remaining limit: lag is not measured while no WAL is being received; a replica whose
   connection to the primary is broken but which has replayed everything it got reports 0 here (watch replication state separately). With
   no walreceiver the receive LSN is NULL and the lag is computed from the replay timestamp.
4. Fails when any client transaction has been open for more than 15 minutes (`state <> 'idle'` and `xact_start` older than 15 minutes).
   This script's own session is excluded.

It uses local peer authentication as the `postgres` OS user (no password, no connection string, nothing secret). If the agent runs as
`postgres` the commands run directly; otherwise they run through `runuser -u postgres` or `sudo -n -u postgres`. If your server listens on
a non-default port, set `PGPORT_USE` near the top.

## The script

```bash
#!/bin/bash
# PostgreSQL health check (read only). Exit 0 = ok, 2 = critical (1 would be a warning).
# Local peer authentication as the postgres OS user. No password is used or needed.
set -u
PGPORT_USE="${PGPORT_USE:-5432}"
CONN_FAIL_PCT=90
LAG_MAX_S=60
XACT_MAX_MIN=15

fail() { echo "PostgreSQL FAIL: $1"; exit 2; }

# Run a command as the postgres OS user (directly when we already are that user).
as_pg() {
  if [ "$(id -un)" = "postgres" ] || [ "${PGCHECK_AS_SELF:-0}" = "1" ]; then
    "$@"
  elif command -v runuser >/dev/null 2>&1; then
    runuser -u postgres -- "$@"
  elif command -v sudo >/dev/null 2>&1; then
    sudo -n -u postgres "$@"
  else
    return 127
  fi
}
q() { as_pg psql -X -Atq -p "$PGPORT_USE" -d postgres -c "$1" 2>/dev/null; }

command -v pg_isready >/dev/null 2>&1 || fail "pg_isready not found"
command -v psql >/dev/null 2>&1 || fail "psql not found"

# 1. Accepting connections
as_pg pg_isready -q -p "$PGPORT_USE" || fail "not accepting connections"

# 2. Connections vs max_connections
row=$(q "select count(*) filter (where backend_type = 'client backend'), current_setting('max_connections')::int from pg_stat_activity") \
  || fail "cannot query pg_stat_activity (is peer auth for postgres available?)"
used=${row%%|*}
max=${row##*|}
case "$used$max" in ''|*[!0-9]*) fail "unexpected connection count result" ;; esac
[ "$max" -gt 0 ] || fail "max_connections is 0"
if [ $(( used * 100 )) -ge $(( max * CONN_FAIL_PCT )) ]; then
  fail "$used/$max connections (>= ${CONN_FAIL_PCT}%)"
fi

# 3. Replica replay lag
rec=$(q "select pg_is_in_recovery()") || fail "cannot read recovery state"
if [ "$rec" = "t" ]; then
  lag=$(q "select case when pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() then 0 else coalesce(extract(epoch from now() - pg_last_xact_replay_timestamp())::bigint, 0) end") \
    || fail "cannot read replay lag"
  case "$lag" in ''|*[!0-9]*) lag=0 ;; esac
  [ "$lag" -le "$LAG_MAX_S" ] || fail "replica replay lag ${lag}s (> ${LAG_MAX_S}s)"
fi

# 4. Long-running transactions
old=$(q "select count(*) from pg_stat_activity where backend_type = 'client backend' and pid <> pg_backend_pid() and state <> 'idle' and xact_start < now() - interval '${XACT_MAX_MIN} minutes'") \
  || fail "cannot check transaction age"
case "$old" in ''|*[!0-9]*) fail "unexpected transaction age result" ;; esac
[ "$old" -eq 0 ] || fail "$old transaction(s) open longer than ${XACT_MAX_MIN} min"

echo "PostgreSQL OK: ${used}/${max} connections"
exit 0
```

`PGCHECK_AS_SELF=1` (run as the current user, no sudo) exists for testing the script by hand as the user that owns the server; leave it unset
in a policy.

## Test status

Run on 2026-11-20 against a throwaway PostgreSQL 18.6 started in a private temp directory as an ordinary user (own `initdb`, port 55432,
socket in a private directory, `max_connections=20`, peer authentication, nothing else touched), with `PGCHECK_AS_SELF=1`:

| Case | Result |
|---|---|
| `bash -n`, `shellcheck` | clean |
| Healthy primary | `PostgreSQL OK: 1/20 connections`, exit 0 |
| Healthy with a young transaction held open (real 15 min limit) | `PostgreSQL OK: 2/20 connections`, exit 0 |
| Transaction limit lowered to 0 min with a transaction held open | `PostgreSQL FAIL: 1 transaction(s) open longer than 0 min`, exit 2 |
| Connection limit lowered to 1% | `PostgreSQL FAIL: 2/20 connections (>= 1%)`, exit 2 |
| Nothing listening on the port | `PostgreSQL FAIL: not accepting connections`, exit 2 |

**Not run on a real server:** the replica branch (check 3) was never run on an actual replica; only the lag SQL expression (the `case when
pg_last_wal_receive_lsn() = pg_last_wal_replay_lsn() ...` form) was evaluated directly on a primary to prove it parses and returns a value, and
there the replica branch is skipped. The `runuser` / `sudo -n -u postgres` paths and the agent's check runner (how the
agent invokes the text, as which user, with which shell) were not exercised, and nothing was run through RivetIT. The 90% and 15 minute
limits were only proven by lowering them. Try it by hand as root on a real server (`bash script.sh; echo $?`) before putting it in a policy.
