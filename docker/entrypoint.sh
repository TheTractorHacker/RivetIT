#!/usr/bin/env bash
set -euo pipefail

# ITFlow-Internal-IT — Docker Compose entrypoint. Runs as root (the
# container's default user); its job is everything that needs root before
# handing off to supervisord, which then runs php-fpm/nginx as www-data.
#
# App code at /var/www/html is a BIND MOUNT of the git checkout you cloned
# (see ../docker-compose.yml) — not baked into the image — so config.php,
# uploads/, backups/, and vendor/ all live on the host and survive
# `docker compose down` / image rebuilds. That also means this container's
# www-data has to be able to write into a directory it doesn't own by
# default: see the DOCKER_UID/DOCKER_GID handling below.

APP_DIR="/var/www/html"
UPLOAD_SUBDIRS=(contracts clients custom documents document_templates expenses kb recurring_tickets settings users tmp tickets ticket_templates)

remap_www_data() {
    local target_uid="${DOCKER_UID:-33}"
    local target_gid="${DOCKER_GID:-33}"
    local current_uid current_gid
    current_uid="$(id -u www-data)"
    current_gid="$(id -g www-data)"

    if [[ "${current_uid}" != "${target_uid}" ]]; then
        usermod -u "${target_uid}" www-data
    fi
    if [[ "${current_gid}" != "${target_gid}" ]]; then
        groupmod -g "${target_gid}" www-data
    fi

    if [[ "${target_uid}" == "33" && "${target_gid}" == "33" ]]; then
        echo "entrypoint: www-data left at the image default (uid/gid 33). If ${APP_DIR} is owned by your own host user, set DOCKER_UID/DOCKER_GID in .env to 'id -u'/'id -g' so the setup wizard can write config.php." >&2
    fi
}

wait_for_db() {
    local host="${DB_HOST:-db}" port="${DB_PORT:-3306}" tries=30
    echo "entrypoint: waiting for database at ${host}:${port}..."
    until (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; do
        tries=$((tries - 1))
        if [[ "${tries}" -le 0 ]]; then
            echo "entrypoint: database still not reachable after 60s; starting anyway — the app will show a connection error until it's up." >&2
            return 0
        fi
        sleep 2
    done
    exec 3<&- 3>&- 2>/dev/null || true
    echo "entrypoint: database is reachable."
}

ensure_upload_dirs() {
    local d
    for d in "${UPLOAD_SUBDIRS[@]}"; do
        mkdir -p "${APP_DIR}/uploads/${d}"
        [[ -f "${APP_DIR}/uploads/${d}/index.php" ]] || : > "${APP_DIR}/uploads/${d}/index.php"
    done
    mkdir -p "${APP_DIR}/backups"
    chown -R www-data:www-data "${APP_DIR}/uploads" "${APP_DIR}/backups"
}

install_composer_deps() {
    if [[ -f "${APP_DIR}/composer.json" && ! -d "${APP_DIR}/vendor" ]]; then
        echo "entrypoint: vendor/ missing, running composer install (first boot against this checkout only — persists to the bind mount)..."
        ( cd "${APP_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction )
        chown -R www-data:www-data "${APP_DIR}/vendor"
    fi
}

# restore_from_backup(): the container-first-boot counterpart to
# deploy/install.sh's --restore-from. Only runs when setup isn't already
# fully complete AND RESTORE_FROM is set (see ../docker-compose.yml's
# optional ./restore:/var/www/restore:ro mount + .env.example) — otherwise a
# brand new instance just falls through to the browser /setup wizard as
# before, unchanged from the original design here.
#
# Checks config_enable_setup = 0, NOT just "does config.php exist": a
# container that dies between setup_cli.php --config-only succeeding and
# restore.sh completing leaves a config.php on the bind mount (so it
# persists across `docker compose down`/restarts) whose setup is NOT
# actually done — config-only mode deliberately never sets
# config_enable_setup. A file-existence-only check would treat that
# half-finished state as "already handled" forever and silently stop
# retrying on every subsequent boot. setup_cli.php's own "config.php already
# configured" guard makes re-running --config-only against it a safe no-op,
# so retrying the whole sequence here is safe either way.
restore_from_backup() {
    if [[ -f "${APP_DIR}/config.php" ]] && grep -q '^\$config_enable_setup = 0;' "${APP_DIR}/config.php"; then
        return 0
    fi
    if [[ -z "${RESTORE_FROM:-}" ]]; then
        return 0
    fi
    if [[ -z "${RESTORE_PASSPHRASE_FILE:-}" ]]; then
        echo "entrypoint: RESTORE_FROM is set but RESTORE_PASSPHRASE_FILE is not — cannot decrypt it. Falling through to the browser /setup wizard instead." >&2
        return 0
    fi

    echo "entrypoint: RESTORE_FROM=${RESTORE_FROM} — writing config.php (setup_cli.php --config-only) then restoring..."
    # No sudo -u www-data here (unlike deploy/install.sh's equivalent step):
    # this image doesn't install sudo, and this entrypoint already runs as
    # root — config.php is chown'd to www-data explicitly right below
    # instead, same end state.
    if ! ( cd "${APP_DIR}/scripts" && env ITFLOW_DB_PASSWORD="${DB_PASSWORD}" php setup_cli.php \
        --config-only --non-interactive \
        --host="${DB_HOST}" --username="${DB_USER}" --database="${DB_NAME}" \
        --base-url="${APP_BASE_URL:-localhost}" ); then
        echo "entrypoint: setup_cli.php --config-only failed; leaving config.php absent so the browser /setup wizard is still reachable as a fallback." >&2
        return 0
    fi
    chmod 640 "${APP_DIR}/config.php"
    chown www-data:www-data "${APP_DIR}/config.php"

    # --no-pre-restore-backup-confirmed: config.php was just written above
    # against a database this container's entrypoint has never touched
    # before now — nothing in it yet is worth a safety backup of.
    #
    # Deliberately NOT passing --unattended: that redirects restore.sh's
    # output into a logfile INSIDE the container instead of stdout, which
    # for a real server is the more cron-friendly choice — but in Docker
    # `docker compose logs` IS the operator's window into what's happening,
    # and that logfile isn't even on a persisted volume here. Plain stdout
    # keeps restore progress visible where an operator will actually look.
    if ! "${APP_DIR}/deploy/restore.sh" --app-dir="${APP_DIR}" --backup="${RESTORE_FROM}" \
        --passphrase-file="${RESTORE_PASSPHRASE_FILE}" --confirm-restore \
        --no-pre-restore-backup-confirmed; then
        echo "entrypoint: deploy/restore.sh failed (see /var/log/itflow-restore.log inside the container). config.php exists but config_enable_setup was left enabled, so /setup is still reachable to retry or fall back to a fresh install." >&2
        return 0
    fi

    if ! grep -q '^\$config_enable_setup = 0;' "${APP_DIR}/config.php"; then
        printf '$config_enable_setup = 0;\n\n' >> "${APP_DIR}/config.php"
        chown www-data:www-data "${APP_DIR}/config.php"
    fi
    echo "entrypoint: restore complete — ready to log in with the restored data."
    # The ./restore mount is read-only in here, so the container can't clean
    # it up itself. nginx denies /restore/, but the backup and its passphrase
    # still shouldn't outlive the restore on the host.
    echo "entrypoint: now DELETE the backup and passphrase from ./restore/ on the host, and remove RESTORE_FROM / RESTORE_PASSPHRASE_FILE from .env." >&2
}

remap_www_data
wait_for_db
ensure_upload_dirs
install_composer_deps
restore_from_backup

exec "$@"
