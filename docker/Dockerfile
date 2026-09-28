# RivetIT — Docker Compose runtime image.
#
# This image is deliberately NOT a self-contained distributable — it ships
# nginx + PHP-FPM 8.5 + supervisord only. The application code always comes
# from a bind mount (see ../docker-compose.yml), the same git checkout you
# cloned from GitHub, so config.php/uploads/backups written at runtime live
# on the host and survive image rebuilds, and `git pull` + a container
# restart is the update path — no separate "bake a new image with new code"
# step to keep in sync with deploy/update.sh's git-pull model.
#
# Extension list mirrors deploy/install.sh's REQUIRED_PHP_PACKAGES exactly
# (php-mysql -> mysqli+pdo_mysql, php-curl, php-gd, php-mbstring, php-intl,
# php-xml, php-zip, php-bcmath; opcache is compiled in below too, bundled by
# default as of the PHP version install.sh now targets) so this image and a
# bare-metal install.sh box behave the same way at the PHP level. Keep the
# two lists — and the FROM tag's PHP version below, and install.sh's
# PHP_VERSION constant — in sync if either changes.
#
# redis-server is also included (run by supervisord on 127.0.0.1:6380,
# matching includes/redis_functions.php's hardcoded expectation) so live
# ticket/chat push works the same as it does bare-metal, instead of silently
# degrading in every container deployment. openssl is included for
# deploy/restore.sh's decrypt step (RESTORE_FROM below).

FROM composer:2 AS composer

FROM php:8.5-fpm-bookworm

COPY --from=composer /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor rsync unzip git default-mysql-client openssl redis-server \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        mysqli pdo_mysql gd intl zip bcmath opcache mbstring curl \
    # The compiled gd/zip/intl/curl .so files need these RUNTIME shared
    # libraries at every request, not just at build time - but apt only
    # knows them as dependencies of the *-dev packages being purged below,
    # not of the extensions that now dlopen() them, so --auto-remove drops
    # them right along with the -dev packages unless marked manual first.
    # Confirmed live: without this line, gd/zip fail to load at startup
    # ("cannot open shared object file: libpng16.so.16" / "libzip.so.4")
    # despite docker-php-ext-install having reported success.
    && apt-mark manual libicu72 libzip4 libpng16-16 libjpeg62-turbo libfreetype6 libonig5 libcurl4 \
    && apt-get purge -y --auto-remove \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# upload_max_filesize/post_max_size match client_max_body_size 500M in
# deploy/templates/nginx-vhost.conf.template — a large document/attachment
# upload should not succeed at nginx and then fail at PHP.
# opcache.validate_timestamps=1 (upstream php.ini-production default is 0)
# for the same reason deploy/update.sh reloads php-fpm after a git pull: a
# `git pull` here is not accompanied by any reload, so opcache must notice
# changed files on its own.
#
# The in-container file names below still say "itflow"; they are internal to
# the image and renaming them would change nothing a user sees.
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-itflow-overrides.ini
COPY docker/nginx.conf /etc/nginx/sites-available/itflow.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/itflow.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/itflow.conf /etc/nginx/sites-enabled/itflow.conf \
    && chmod +x /entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
