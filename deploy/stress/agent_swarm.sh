#!/usr/bin/env bash
# RivetIT agent swarm: run many REAL Linux endpoint agents as tiny Docker containers on one host, to load-test a RivetIT server
# (enrollment, check-ins, metrics, jobs) for hours or days. Every container is a separate device: own hostname, own /etc/machine-id,
# own state directory, enrolled with a fleet token.
#
#   deploy/stress/agent_swarm.sh plan                                  # how many containers this host can carry
#   deploy/stress/agent_swarm.sh up   --server https://mw-itflow.foleyit.com --token-file ./fleet.token --agent ./rivetit-agent-linux-amd64 --count 500
#   deploy/stress/agent_swarm.sh status
#   deploy/stress/agent_swarm.sh sample --out swarm.csv --interval 60 [--probe https://mw-itflow.foleyit.com/api/v1/agent_checkin]   # run under tmux/nohup for 72 h
#   deploy/stress/agent_swarm.sh logs  [N]                             # tail of one container (default the first)
#   deploy/stress/agent_swarm.sh down                                  # remove every swarm container (the devices stay on the server until you delete them there)
#
# Server side, once: Endpoints > Agent Fleet > Add device > Advanced: department, lifetime longer than the ramp-up, PC count >= --count (the server allows up to 5000
# per token), then copy the enrollment token into --token-file (the "Linux" tab shows it in the install command). The module must be on and the agent binary
# must be the Linux amd64 build (Administration > Endpoint agent shows the hosted Linux files). Use a department made for the test so the devices are easy to remove.
#
# Run it on a SEPARATE small VM, not on the RivetIT server, so the load generator does not compete with what is being measured.
# Environment: SWARM_PREFIX (container name prefix, default rivet-swarm), SWARM_IMAGE (default alpine:3.20, anything with /bin/sh).
set -euo pipefail

PREFIX="${SWARM_PREFIX:-rivet-swarm}"
IMAGE="${SWARM_IMAGE:-alpine:3.20}"
LABEL="rivetit.swarm=$PREFIX"
MEM_MB="${SWARM_MEM_MB:-40}"      # per container hard limit; a steady Go agent sits around 15-25 MB
CPUS="${SWARM_CPUS:-0.10}"        # per container CPU cap
PIDS="${SWARM_PIDS:-64}"

die() { echo "error: $*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "$1 is required"; }
need docker

# CPU% and MiB summed over the running swarm containers (docker stats has no --filter)
usage() {
  local r; r=$(docker ps --filter "label=$LABEL" --filter status=running --format '{{.Names}}')
  [ -n "$r" ] || { echo "0.0 0"; return; }
  # shellcheck disable=SC2086
  docker stats --no-stream --format '{{.CPUPerc}} {{.MemUsage}}' $r 2>/dev/null | awk '
    { c += $1; v = $2; u = v; gsub(/[0-9.]/, "", u); sub(/[A-Za-z]+/, "", v); if (u == "KiB") v /= 1024; else if (u == "GiB") v *= 1024; else if (u == "B") v /= 1048576; m += v }
    END { printf "%.1f %.0f\n", c, m }'
}

names() { docker ps -a --filter "label=$LABEL" --format '{{.Names}}' | sort; }

cmd_plan() {
  local ram_mb cpus by_ram by_cpu by_pid
  ram_mb=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
  cpus=$(nproc)
  by_ram=$(( (ram_mb * 80 / 100) / 25 ))          # leave 20% for the OS and Docker, ~25 MB resident each
  by_cpu=$(( cpus * 100 / 3 ))                     # a quiet agent averages ~3% of a core at a 60 s check-in
  by_pid=$(( $(cat /proc/sys/kernel/pid_max) / 40 ))
  echo "host: ${cpus} CPUs, ${ram_mb} MB available RAM"
  echo "limit by RAM : ~${by_ram} containers (25 MB each, 80% of free RAM)"
  echo "limit by CPU : ~${by_cpu} containers (3% of a core each)"
  echo "limit by PIDs: ~${by_pid} containers"
  local m=$by_ram; [ "$by_cpu" -lt "$m" ] && m=$by_cpu; [ "$by_pid" -lt "$m" ] && m=$by_pid
  echo "suggested ceiling: ${m}. Start at 10% of it, check 'sample' output and the server, then add with 'up --count' again."
  echo "(a small VM: 4 vCPU / 8 GB carries roughly 250 devices; 8 vCPU / 16 GB roughly 500; check-in interval is set on the server)"
}

cmd_up() {
  local server="" tokfile="" agent="" ca="" count=0 batch=20 delay=3 update=0 interval=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --server) server="$2"; shift 2;;
      --token-file) tokfile="$2"; shift 2;;
      --agent) agent="$2"; shift 2;;
      --ca) ca="$2"; shift 2;;
      --count) count="$2"; shift 2;;
      --batch) batch="$2"; shift 2;;
      --delay) delay="$2"; shift 2;;
      --allow-self-update) update=1; shift;;
      *) die "unknown option $1";;
    esac
  done
  [ -n "$server" ] && [ -n "$tokfile" ] && [ -n "$agent" ] && [ "$count" -gt 0 ] 2>/dev/null || die "usage: up --server URL --token-file F --agent BIN --count N [--ca ca.pem] [--batch 20] [--delay 3] [--allow-self-update]"
  case "$server" in https://*) ;; *) die "--server must be an https:// URL";; esac
  [ -s "$tokfile" ] || die "token file $tokfile is empty or missing"
  [ -x "$agent" ] || die "agent binary $agent is not executable"
  "$agent" version >/dev/null 2>&1 || die "$agent does not run on this host (needs the Linux amd64 build)"
  [ -z "$ca" ] || [ -f "$ca" ] || die "CA file $ca not found"

  local dir; dir="$(mktemp -d "${TMPDIR:-/tmp}/${PREFIX}.XXXXXX")"
  # one copy of the files shared read-only by every container; the directory is kept for the life of the swarm
  local keep="/var/lib/${PREFIX}"; if ! mkdir -p "$keep" 2>/dev/null; then keep="$HOME/.${PREFIX}"; mkdir -p "$keep"; fi
  chmod 700 "$keep"
  cp "$agent" "$keep/rivetit-agent"; chmod 755 "$keep/rivetit-agent"
  # the enrollment token is a credential: owner-only (containers run as root and can still read it)
  install -m 600 "$tokfile" "$keep/enroll.token"
  [ -z "$ca" ] || cp "$ca" "$keep/ca.pem"
  rm -rf "$dir"
  cat >"$keep/entrypoint.sh" <<'EOS'
#!/bin/sh
# container entrypoint: unique identity, enroll once (retry with jitter), then run the agent in the foreground
set -u
[ -s /etc/machine-id ] || cat /proc/sys/kernel/random/uuid | tr -d '-' > /etc/machine-id
CA=""; [ -f /swarm/ca.pem ] && CA="-ca /swarm/ca.pem"
if [ ! -f /state/.swarm-enrolled ]; then
  until /swarm/rivetit-agent enroll -server "$SWARM_SERVER" -token-file /swarm/enroll.token -state-dir /state $CA -department swarm; do
    sleep $((20 + $(od -An -N1 -tu1 /dev/urandom) % 40))
  done
  : > /state/.swarm-enrolled
fi
exec /swarm/rivetit-agent run -state-dir /state ${SWARM_NOUPDATE:+-no-update}
EOS
  chmod 755 "$keep/entrypoint.sh"

  local noupd=(-e SWARM_NOUPDATE=1); [ "$update" = 1 ] && noupd=()
  local existing; existing=$(names | wc -l)
  local start=$((existing + 1)) end=$((existing + count)) i n=0
  echo "starting containers $start..$end against $server (batches of $batch, ${delay}s apart)"
  for i in $(seq "$start" "$end"); do
    local name; name=$(printf '%s-%04d' "$PREFIX" "$i")
    docker run -d --name "$name" --hostname "$name" --label "$LABEL" \
      --restart unless-stopped --memory "${MEM_MB}m" --memory-swap "${MEM_MB}m" --cpus "$CPUS" --pids-limit "$PIDS" \
      --log-opt max-size=2m --log-opt max-file=2 \
      -e SWARM_SERVER="$server" "${noupd[@]}" \
      -v "$keep:/swarm:ro" "$IMAGE" /swarm/entrypoint.sh >/dev/null
    n=$((n + 1))
    if [ $((n % batch)) -eq 0 ]; then echo "  $n started"; sleep "$delay"; fi
  done
  echo "done: $n started, $((existing + n)) total. Watch the server's Agent Fleet page, then run: $0 status"
}

cmd_status() {
  local total running restarting
  total=$(names | wc -l)
  running=$(docker ps --filter "label=$LABEL" --filter status=running -q | wc -l)
  restarting=$(docker ps --filter "label=$LABEL" --filter status=restarting -q | wc -l)
  echo "swarm '$PREFIX': $total containers, $running running, $restarting restarting, $((total - running - restarting)) stopped"
  local enrolled=0 waiting=0
  for c in $(docker ps --filter "label=$LABEL" --filter status=running --format '{{.Names}}' | head -200); do
    if docker exec "$c" sh -c 'test -f /state/.swarm-enrolled' 2>/dev/null; then enrolled=$((enrolled + 1)); else waiting=$((waiting + 1)); fi
  done
  echo "first 200 running: $enrolled have agent state (enrolled), $waiting not enrolled yet (retrying)"
  local cpu mem; read -r cpu mem < <(usage)
  echo "agents combined: ${cpu}% of a core, ${mem} MiB resident"
}

cmd_logs() {
  local n="${1:-1}" name
  name=$(names | sed -n "${n}p"); [ -n "$name" ] || die "no container #$n"
  docker logs --tail 60 "$name"
}

cmd_sample() {
  local out="swarm.csv" interval=60 probe=""
  while [ $# -gt 0 ]; do
    case "$1" in --out) out="$2"; shift 2;; --interval) interval="$2"; shift 2;; --probe) probe="$2"; shift 2;; *) die "unknown option $1";; esac
  done
  [ -s "$out" ] || echo "time_utc,containers,running,restarting,agent_cpu_pct,agent_mem_mib,host_load1,host_mem_avail_mib,host_swap_used_mib,probe_http,probe_ms" >"$out"
  echo "sampling every ${interval}s into $out (Ctrl-C to stop; safe to restart, it appends)"
  while true; do
    local total running restarting cpu mem load avail swap code ms
    total=$(names | wc -l)
    running=$(docker ps --filter "label=$LABEL" --filter status=running -q | wc -l)
    restarting=$(docker ps --filter "label=$LABEL" --filter status=restarting -q | wc -l)
    read -r cpu mem < <(usage)
    load=$(cut -d' ' -f1 /proc/loadavg)
    avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
    swap=$(awk '/SwapTotal/ {t=$2} /SwapFree/ {f=$2} END {print int((t-f)/1024)}' /proc/meminfo)
    code=""; ms=""
    if [ -n "$probe" ]; then
      read -r code ms < <(curl -sk -o /dev/null -m 10 -w '%{http_code} %{time_total}\n' "$probe" | awk '{printf "%s %d\n", $1, $2*1000}') || { code=000; ms=10000; }
    fi
    echo "$(date -u +%FT%TZ),$total,$running,$restarting,${cpu:-0},${mem:-0},$load,$avail,$swap,$code,$ms" >>"$out"
    sleep "$interval"
  done
}

cmd_down() {
  local list; list=$(names)
  [ -n "$list" ] || { echo "no swarm containers"; return 0; }
  echo "removing $(echo "$list" | wc -l) containers"
  echo "$list" | xargs -r -n 50 docker rm -f >/dev/null
  rm -f "/var/lib/${PREFIX}/enroll.token" "$HOME/.${PREFIX}/enroll.token"
  echo "done. The devices remain on the server: delete them from the test department there (Agent Fleet > select > delete), and revoke the fleet token."
}

case "${1:-}" in
  plan) shift; cmd_plan "$@";;
  up) shift; cmd_up "$@";;
  status) shift; cmd_status "$@";;
  logs) shift; cmd_logs "$@";;
  sample) shift; cmd_sample "$@";;
  down) shift; cmd_down "$@";;
  *) sed -n 2,19p "$0" | sed 's/^# \{0,1\}//'; exit 1;;
esac
