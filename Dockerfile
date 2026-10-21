# Stage 1: PHP application
# Ship the source and admin assets from the tested build context, never a moving branch.
#
# Base image is the OFFICIAL php image, PINNED BY DIGEST, with Swoole compiled here from the
# swoole-src release tarball using the exact recipe of the upstream phpswoole/swoole image
# (dockerfiles/<swoole>/php8.2/alpine/Dockerfile in swoole/docker-swoole).
#
# Why we no longer start FROM phpswoole/swoole (2026-10-21, CVE-2026-109618 / CVE-2026-109619):
# that image freezes the PHP patch release at Swoole release time (6.2.2 == PHP 8.2.32) and is
# not rebuilt for PHP security releases — neither 8.2.33 nor 8.2.34 ever reached it. The PHP
# security team ships fixes on php.net and the official php image follows within hours, so
# PHP now comes from there and only Swoole is built in this file.
#
#   To take a PHP security release: bump the FROM tag + digest and PHP_MIN_VERSION together.
#   Look the digest up with `docker buildx imagetools inspect php:<ver>-cli-alpine3.23`.
#
# Digest pinning stays mandatory. On 2026-08-02 the floating `phpswoole/swoole:php8.2-alpine`
# tag was re-pushed with a Swoole rebuild whose embedded swoole_library defines
# CURLOPT_PREREQFUNCTION. On PHP 8.2 native curl_setopt() rejects that option, so Guzzle's
# defined()-guarded teardown threw on EVERY outbound request and took down payments, Telegram
# and Chatwoot. Both builds self-reported "Swoole 6.2.1", so a version tag is not enough.
# See scripts/assert-curl-contract.php for the build-time guard; it still gates this build.
FROM php:8.2.34-cli-alpine3.23@sha256:5778176077aa729410f231c82f0c06259316c584ecea13ee9205772f7d6bb2e2

# Security floor: the build fails if the base image is older than this, so a stale digest can
# never ship silently. Bump it together with the FROM line above.
ARG PHP_MIN_VERSION=8.2.34
# Swoole release tag built below. 6.2.2 is what production has run since 2026-08-02 and is
# verified free of the CURLOPT_PREREQFUNCTION define (see above).
ARG SWOOLE_VERSION=6.2.2
# phpredis release tag, same version upstream installs from PECL.
ARG PHPREDIS_VERSION=6.3.0

COPY --from=composer:2.10.2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer:latest@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 /usr/bin/install-php-extensions /usr/local/bin/

# Fail fast if the base image does not meet the security floor.
RUN php -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MIN_VERSION"), ">=") ? 0 : 1);' \
    || { echo "FATAL: PHP $(php -r 'echo PHP_VERSION;') is below the required ${PHP_MIN_VERSION} — update the FROM digest"; exit 1; }

# Swoole, pdo_mysql, sockets and redis as phpswoole/swoole builds them (same versions and
# configure options; comments kept from upstream). Keep only the runtime shared libraries in the final image; the matching "-dev"
# packages are installed as the temporary ".build-deps" virtual package and removed afterwards.
# Package "libucontext" provides the ucontext functions that musl does not implement; Swoole
# needs them on architectures it has no context-switching assembly for.
RUN set -ex && \
    apk add --no-cache libstdc++ libpq libssh2 libucontext unixodbc zstd-libs && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev linux-headers brotli-dev postgresql-dev openssl-dev pcre-dev pcre2-dev sqlite-dev zlib-dev zstd-dev libssh2-dev libucontext-dev unixodbc-dev && \
    docker-php-ext-install pdo_mysql && \
    docker-php-ext-install sockets && \
    docker-php-source extract && \
# phpredis from the GitHub release tarball with upstream's options (lzf + zstd, no igbinary /
# msgpack / lz4). Upstream pulls it from pecl.php.net, which returned 504s on 2026-10-21 and
# would have blocked the emergency rebuild; GitHub is the more dependable source.
    mkdir /usr/src/php/ext/redis && \
    curl -sfL "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" -o phpredis.tar.gz && \
    sha256sum phpredis.tar.gz && \
    tar xfz phpredis.tar.gz --strip-components=1 -C /usr/src/php/ext/redis && \
    grep -rq "PHP_REDIS_VERSION \"${PHPREDIS_VERSION}\"" /usr/src/php/ext/redis/ && \
    docker-php-ext-configure redis \
        --enable-redis-lzf \
        --enable-redis-zstd \
        --disable-redis-igbinary \
        --disable-redis-msgpack \
        --disable-redis-lz4 && \
    docker-php-ext-install -j$(nproc) redis && \
    rm -f phpredis.tar.gz && \
    mkdir /usr/src/php/ext/swoole && \
    curl -sfL "https://github.com/swoole/swoole-src/archive/v${SWOOLE_VERSION}.tar.gz" -o swoole.tar.gz && \
    sha256sum swoole.tar.gz && \
    tar xfz swoole.tar.gz --strip-components=1 -C /usr/src/php/ext/swoole && \
    grep -q "define SWOOLE_VERSION \"${SWOOLE_VERSION}\"" /usr/src/php/ext/swoole/include/swoole_version.h && \
# Tripwire for the 2026-08-02 landmine: refuse a Swoole tree whose embedded PHP library
# defines CURLOPT_PREREQFUNCTION itself (scripts/assert-curl-contract.php stays the real gate).
    ! grep -rq "define('CURLOPT_PREREQFUNCTION'" /usr/src/php/ext/swoole/ && \
    export LDFLAGS="${LDFLAGS} -lucontext" && \
    docker-php-ext-configure swoole \
        --enable-brotli \
        --enable-mysqlnd \
        --enable-sockets \
        --enable-swoole-curl \
        --enable-swoole-ftp \
        --enable-swoole-pgsql \
        --enable-swoole-sqlite \
        --enable-swoole-stdext \
        --enable-zstd \
        --with-swoole-odbc=unixODBC,/usr \
        --with-swoole-ssh2 && \
    docker-php-ext-install -j$(nproc) swoole && \
    rm -f swoole.tar.gz && \
    docker-php-source delete && \
    apk del .build-deps && \
# Fail the build if the extension does not actually load (PHP only warns and exits 0).
    php -r 'exit(extension_loaded("swoole") ? 0 : 1);' && \
    php --ri swoole | head -n 12

# Install the remaining PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions gd && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

ARG SOURCE_REVISION=local
LABEL org.opencontainers.image.revision=$SOURCE_REVISION
COPY . /www/

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# composer.lock is committed and MUST be honoured. Without it every build re-resolved
# every dependency to whatever was newest on Packagist at that instant,
# so an unrelated commit could silently ship a different
# Laravel/Guzzle. Fail loudly rather than drift.
RUN test -f composer.lock || { echo "FATAL: composer.lock is missing — dependencies must be locked"; exit 1; } \
    && composer validate --no-check-publish --no-check-all \
    && composer install --no-cache --no-dev --no-interaction --prefer-dist \
    && php scripts/assert-curl-contract.php \
    && php scripts/assert-runtime-extensions.php \
    && php artisan storage:link \
    && cp -r plugins/ /opt/default-plugins/ \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=false \
    ENABLE_WS_SERVER=false

EXPOSE 7001
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
