# Redis in RivetIT

Redis is an acceleration and coordination layer. **It never holds the only copy of anything**: losing it
(or leaving it off) must only cost speed and live updates. Every Redis feature below fails open.

## Installing it

`deploy/install.sh` installs Redis and starts a **dedicated instance** as the systemd unit `rivetit-redis` on `127.0.0.1:6380`, the address
RivetIT expects. It is separate from the distribution's own `redis-server` (port 6379), so nothing else on the box shares its memory limit,
keys or settings. `deploy/update.sh` does the same on existing installs, so a server installed before this existed gets it on its next update.
The Docker image runs its own copy under supervisord on the same port.

- Config: `/etc/redis-rivetit/redis.conf`, written once and never overwritten (your edits, and memory limits saved from Administration → Redis, survive re-runs).
- Defaults: loopback only, no persistence (`save ""`, no append-only file), `maxmemory 256mb`, `maxmemory-policy volatile-lru` (every key RivetIT writes has an expiry).
- Check it: `systemctl status rivetit-redis`, `redis-cli -p 6380 ping`, or `/health/ready.php` (`"redis":"ok"`).
- Skipped, with a warning rather than an error, if Redis is not installed, systemd is not running, or something else already listens on 6380.
- To use a different Redis instead, point RivetIT at it in Administration → Redis (or the `RIVETIT_REDIS_*` variables); the installer leaves a listener it did not create alone.

## Connection and settings (Administration → Redis)

Open **Administration → Maintenance → Redis**. From there you can:

- see whether Redis is connected, its version, uptime, memory use, clients, cache hit rate and whether it saves to disk;
- change the **host, port, database number and password** (the password is stored encrypted). Saving runs a connection test first and is refused if it fails, unless you tick "Save even if it cannot connect right now";
- set the **memory limit and eviction policy** (`allkeys-lru` is the safe choice). It applies immediately; if Redis cannot save it to its own config file you are shown the two lines to add to `redis.conf`;
- **clear** rate-limit counters, the MCP discovery cache, or job locks. Only those allowlisted key groups can be cleared, never the whole database.

Where the connection comes from, in order: the `RIVETIT_REDIS_HOST`, `_PORT`, `_PASSWORD` and `_DB` environment variables (PHP-FPM pool `env[...]`, or the shell for cron), then the values saved on that page, then the built-in default `127.0.0.1:6380`. A field set by the environment is shown read-only on the page. Needs database update 2.6.123. Keep Redis on loopback or a private network and never expose it publicly.

## What uses it

| Feature | Where | Behaviour when Redis is down |
| --- | --- | --- |
| Live ticket, chat and notification push (pub/sub) | `includes/redis_functions.php`, SSE streams | Saves still succeed; the live push is skipped |
| API rate limiting | `api/v1/includes/api_ratelimit.php` | Allowed |
| MCP OAuth discovery/JWKS cache | `mcp_server/RedisMetadataCache.php` | Cache miss |
| MCP per-agent rate limit (60/min) | `src/Redis/RateLimit.php` | Allowed |
| Job mutex for CLI scripts | `src/Redis/Lock.php`, `CronGuard.php` (used by `cron/integration_worker.php`) | Runs unguarded, as before |
| Readiness report | `health/ready.php` | Reported as `unavailable`; does not fail readiness |

## Building blocks (`src/Redis/`)

- `Lock::acquire($name, $ttl)` returns a lock; check `held()` (proceed) and `degraded()` (Redis was unreachable, so
  no real exclusion). Release is compare-and-delete, so a slow job can never free someone else's lock.
  `Lock::run($name, $ttl, $fn)` wraps it.
- `CronGuard::acquireOrExit($job, $ttl)` is the one-line guard for a CLI script.
- `RateLimit::hit($bucket, $limit, $window)` is a fixed-window limiter that sets the counter and its TTL atomically.

## Deliberately not done

- **Sessions stay on files.** The app sets 30-90 day sessions, concurrent requests (SSE streams) share them, and Redis
  has no locking or persistence guarantee here. Moving them would risk lost logins and lost CSRF tokens for no gain on a
  single web node. Revisit when running more than one web node.
- **The job queue stays DB-backed.** `integration_jobs` is durable and already has retry and dead-letter handling.
- **No general cache layer.** Nothing is cached without a clear invalidation point; add one per hot path when
  profiling shows a need.

## Server status page

**Administration → Maintenance → Server status & tasks** shows disk space, backup freshness (in-app zips and the encrypted disaster-recovery archives), scheduled jobs, the job queue, PHP and Redis in one place, and lists the few tasks that genuinely need root (encrypted backup, safe update, hardening preview, restore) with the exact command for this install ready to copy. It changes nothing itself.

## Health endpoints

`/health/live.php` answers if PHP is serving. `/health/ready.php` returns 200 only when the database is reachable and
its schema version is at least this code's version; it reports Redis status without failing on it, and returns no
hostnames, versions or error text.
