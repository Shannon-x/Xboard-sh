# Stage 1: PHP application
# Ship the source and admin assets from the tested build context, never a moving branch.
# Base image is PINNED BY DIGEST on purpose — do not switch back to a floating tag.
# On 2026-08-02 the floating `phpswoole/swoole:php8.2-alpine` tag was re-pushed with a
# Swoole rebuild whose embedded swoole_library defines CURLOPT_PREREQFUNCTION. On PHP 8.2
# native curl_setopt() rejects that option, so Guzzle's defined()-guarded teardown threw on
# EVERY outbound request and took down payments, Telegram and Chatwoot. Both builds
# self-reported "Swoole 6.2.1", so a version tag is not enough — only a digest is.
# See scripts/assert-curl-contract.php for the build-time guard.
# 6.2.2-php8.2-alpine == PHP 8.2.32, verified free of the bad define.
FROM phpswoole/swoole:6.2.2-php8.2-alpine@sha256:eb587392e8ef6218626082e87cf8f58d9d24c41d46510e0b528573a251323534

COPY --from=mlocati/php-extension-installer:latest@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
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
