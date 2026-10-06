# Systemd-capable Ubuntu 24.04 base for the installer harness (tests/installer/run.sh).
# jrei/systemd-ubuntu:24.04 is plain ubuntu:24.04 plus systemd as PID 1 (/sbin/init), nothing else.
# Only what a bare cloud image already has is added here: the installer itself must pull everything
# else (nginx, PHP, MariaDB, Redis, certbot, composer ...), that is the thing under test.
ARG BASE=jrei/systemd-ubuntu:24.04
FROM ${BASE}
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates curl git sudo iproute2 procps \
 && rm -rf /var/lib/apt/lists/*
# A real server has no policy-rc.d; the base image's one makes every package postinst skip starting services
# (mariadb, php-fpm ...), which is not what a fresh VM looks like. docker-clean would also throw away the
# .deb files the harness keeps on a host-side cache mount (run.sh) so repeat runs are not network-bound.
RUN rm -f /usr/sbin/policy-rc.d /etc/apt/apt.conf.d/docker-clean \
 && echo 'Binary::apt::APT::Keep-Downloaded-Packages "true"; APT::Keep-Downloaded-Packages "true";' > /etc/apt/apt.conf.d/99keep
# See shims/mariadb-install-db for why this exists (host AppArmor profile leaking into the container).
COPY shims/mariadb-install-db /usr/local/lib/rivet-inst/mariadb-install-db
RUN dpkg-divert --local --rename --divert /usr/bin/mariadb-install-db.real --add /usr/bin/mariadb-install-db \
 && install -m 0755 /usr/local/lib/rivet-inst/mariadb-install-db /usr/bin/mariadb-install-db
CMD ["/sbin/init"]
