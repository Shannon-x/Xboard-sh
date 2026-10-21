# ──────────────────────────────────────────────────────────────────────────────
# Stage 0: php-base — PHP compiled from the php.net source tarball.
#
# This is the official docker-library/php recipe (8.2/alpine3.23/cli/Dockerfile and its
# docker-php-* helper scripts, vendored under scripts/php-base/) with only the version, URL and
# checksum parameterised. It exists so a PHP security release can be built the minute php.net
# publishes the tarball, without waiting for the official php image to be rebuilt.
#
#   To take a PHP release: set PHP_BASE_VERSION and PHP_BASE_SHA256 (the tar.xz sha256 from
#   https://www.php.net/releases/index.php?json&version=<ver>) and PHP_MIN_VERSION below.
#   The tarball is additionally verified against the 8.2 release managers' GPG keys.
# ──────────────────────────────────────────────────────────────────────────────
ARG PHP_BASE_VERSION=8.2.34
ARG PHP_BASE_SHA256=5351330c54de240f54f7527c26ef292e239749e6fe11db0330acb5317e02bb39

FROM alpine:3.23@sha256:85fe1e81d6758c208f3e1eed4338a1997e19d4be002d4dd32d3100c9a8c010a0 AS php-base
ARG PHP_BASE_VERSION
ARG PHP_BASE_SHA256

# dependencies required for running "phpize"
# these get automatically installed and removed by "docker-php-ext-*" (unless they're already installed)
ENV PHPIZE_DEPS \
		autoconf \
		dpkg-dev dpkg \
		file \
		g++ \
		gcc \
		libc-dev \
		make \
		pkgconf \
		re2c

# persistent / runtime deps
RUN apk add --no-cache \
		ca-certificates \
		curl \
		openssl \
		tar \
		xz

# ensure www-data user exists
RUN set -eux; \
	adduser -u 82 -D -S -G www-data www-data
# 82 is the standard uid/gid for "www-data" in Alpine
# https://git.alpinelinux.org/aports/tree/main/apache2/apache2.pre-install?h=3.14-stable
# https://git.alpinelinux.org/aports/tree/main/lighttpd/lighttpd.pre-install?h=3.14-stable
# https://git.alpinelinux.org/aports/tree/main/nginx/nginx.pre-install?h=3.14-stable

ENV PHP_INI_DIR /usr/local/etc/php
RUN set -eux; \
	mkdir -p "$PHP_INI_DIR/conf.d"; \
# allow running as an arbitrary user (https://github.com/docker-library/php/issues/743)
	[ ! -d /var/www/html ]; \
	mkdir -p /var/www/html; \
	chown www-data:www-data /var/www/html; \
	chmod 1777 /var/www/html

# Apply stack smash protection to functions using local buffers and alloca()
# Make PHP's main executable position-independent (improves ASLR security mechanism, and has no performance impact on x86_64)
# Enable optimization (-O2)
# Enable linker optimization (this sorts the hash buckets to improve cache locality, and is non-default)
# https://github.com/docker-library/php/issues/272
# -D_LARGEFILE_SOURCE and -D_FILE_OFFSET_BITS=64 (https://www.php.net/manual/en/intro.filesystem.php)
ENV PHP_CFLAGS="-fstack-protector-strong -fpic -fpie -O2 -D_LARGEFILE_SOURCE -D_FILE_OFFSET_BITS=64"
ENV PHP_CPPFLAGS="$PHP_CFLAGS"
ENV PHP_LDFLAGS="-Wl,-O1 -pie"

ENV GPG_KEYS 39B641343D8C104B2B146DC3F9C39DC0B9698544 E60913E4DF209907D8E30D96659A97C9CF2A795A 1198C0117593497A5EC5C199286AF1F9897469DC

ENV PHP_VERSION=$PHP_BASE_VERSION
ENV PHP_URL="https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz" PHP_ASC_URL="https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz.asc"
ENV PHP_SHA256=$PHP_BASE_SHA256

RUN set -eux; \
	\
	apk add --no-cache --virtual .fetch-deps gnupg; \
	\
	mkdir -p /usr/src; \
	cd /usr/src; \
	\
	curl -fsSL -o php.tar.xz "$PHP_URL"; \
	\
	if [ -n "$PHP_SHA256" ]; then \
		echo "$PHP_SHA256 *php.tar.xz" | sha256sum -c -; \
	fi; \
	\
	curl -fsSL -o php.tar.xz.asc "$PHP_ASC_URL"; \
	export GNUPGHOME="$(mktemp -d)"; \
	for key in $GPG_KEYS; do \
		gpg --batch --keyserver keyserver.ubuntu.com --recv-keys "$key"; \
	done; \
	gpg --batch --verify php.tar.xz.asc php.tar.xz; \
	gpgconf --kill all; \
	rm -rf "$GNUPGHOME"; \
	\
	apk del --no-network .fetch-deps

COPY scripts/php-base/docker-php-source /usr/local/bin/

RUN set -eux; \
	apk add --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		argon2-dev \
		coreutils \
		curl-dev \
		gnu-libiconv-dev \
		libsodium-dev \
		libxml2-dev \
		linux-headers \
		oniguruma-dev \
		openssl-dev \
		readline-dev \
		sqlite-dev \
	; \
	\
# make sure musl's iconv doesn't get used (https://www.php.net/manual/en/intro.iconv.php)
	rm -vf /usr/include/iconv.h; \
	\
	export \
		CFLAGS="$PHP_CFLAGS" \
		CPPFLAGS="$PHP_CPPFLAGS" \
		LDFLAGS="$PHP_LDFLAGS" \
# https://github.com/php/php-src/blob/d6299206dd828382753453befd1b915491b741c6/configure.ac#L1496-L1511
		PHP_BUILD_PROVIDER='https://github.com/docker-library/php' \
		PHP_UNAME='Linux - Docker' \
	; \
	docker-php-source extract; \
	cd /usr/src/php; \
	gnuArch="$(dpkg-architecture --query DEB_BUILD_GNU_TYPE)"; \
	case "$gnuArch" in \
		'i686-linux-'*) \
			# php zend jit assumes SSE registers, xmm0-7, are available (and "Pentium4"/SSE2 is a reasonable baseline)
			# https://github.com/php/php-src/pull/22832
			# so, allow it and use SSE/SSE2 floating-point unit instead of the legacy stack
			export \
				CFLAGS="$CFLAGS -msse2 -mfpmath=sse" \
				CPPFLAGS="$CPPFLAGS -msse2 -mfpmath=sse" \
			; \
			;; \
	esac; \
	test "$PHP_INI_DIR" != "${PHP_INI_DIR%/php}"; \
	./configure \
		--build="$gnuArch" \
		--sysconfdir="${PHP_INI_DIR%/php}" \
		--with-config-file-path="$PHP_INI_DIR" \
		--with-config-file-scan-dir="$PHP_INI_DIR/conf.d" \
		\
# make sure invalid --configure-flags are fatal errors instead of just warnings
		--enable-option-checking=fatal \
		\
# https://github.com/docker-library/php/issues/439
		--with-mhash \
		\
# https://github.com/docker-library/php/issues/822
		--with-pic \
		\
# --enable-mbstring is included here because otherwise there's no way to get pecl to use it properly (see https://github.com/docker-library/php/issues/195)
		--enable-mbstring \
# --enable-mysqlnd is included here because it's harder to compile after the fact than extensions are (since it's a plugin for several extensions, not an extension in itself)
		--enable-mysqlnd \
# https://wiki.php.net/rfc/argon2_password_hash
		--with-password-argon2 \
# https://wiki.php.net/rfc/libsodium
		--with-sodium=shared \
# always build against system sqlite3 (https://github.com/php/php-src/commit/6083a387a81dbbd66d6316a3a12a63f06d5f7109)
		--with-pdo-sqlite=/usr \
		--with-sqlite3=/usr \
		\
		--with-curl \
		--with-iconv=/usr \
		--with-openssl \
		--with-readline \
		--with-zlib \
		\
# https://github.com/docker-library/php/pull/1259
		--enable-phpdbg \
		--enable-phpdbg-readline \
		\
# in PHP 7.4+, the pecl/pear installers are officially deprecated (requiring an explicit "--with-pear")
		--with-pear \
		\
# bundled pcre does not support JIT on riscv64 until 10.41 (php 8.3+)
# https://github.com/PCRE2Project/pcre2/commits/pcre2-10.41/src/sljit/sljitNativeRISCV_64.c
# https://github.com/php/php-src/tree/php-8.3.0/ext/pcre/pcre2lib
		$(test "$gnuArch" = 'riscv64-linux-musl' && echo '--without-pcre-jit') \
	; \
	make -j "$(nproc)"; \
	find -type f -name '*.a' -delete; \
	make install; \
	find \
		/usr/local \
		-type f \
		-perm '/0111' \
		-exec sh -euxc ' \
			strip --strip-all "$@" || : \
		' -- '{}' + \
	; \
	make clean; \
	\
# https://github.com/docker-library/php/issues/692 (copy default example "php.ini" files somewhere easily discoverable)
	cp -v php.ini-* "$PHP_INI_DIR/"; \
	\
	cd /; \
	docker-php-source delete; \
	\
	runDeps="$( \
		scanelf --needed --nobanner --format '%n#p' --recursive /usr/local \
			| tr ',' '\n' \
			| sort -u \
			| awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
	)"; \
	apk add --no-cache $runDeps; \
	\
	apk del --no-network .build-deps; \
	\
# update pecl channel definitions https://github.com/docker-library/php/issues/443
	pecl update-channels; \
	rm -rf /tmp/pear ~/.pearrc; \
	\
# smoke test
	php --version

COPY scripts/php-base/docker-php-ext-* scripts/php-base/docker-php-entrypoint /usr/local/bin/

# enable OPcache by default (https://wiki.php.net/rfc/make_opcache_required)
RUN docker-php-ext-enable opcache

# sodium was built as a shared module (so that it can be replaced later if so desired), so let's enable it too (https://github.com/docker-library/php/issues/598)
RUN docker-php-ext-enable sodium

ENTRYPOINT ["docker-php-entrypoint"]

# ──────────────────────────────────────────────────────────────────────────────
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
#   Normal path: FROM php:<ver>-cli-alpine3.23@sha256:<digest> (official image) and bump
#   PHP_MIN_VERSION with it. Emergency path (official image not yet rebuilt): FROM php-base,
#   the stage above, which compiles the same thing from the php.net tarball.
#
# Digest pinning stays mandatory. On 2026-08-02 the floating `phpswoole/swoole:php8.2-alpine`
# tag was re-pushed with a Swoole rebuild whose embedded swoole_library defines
# CURLOPT_PREREQFUNCTION. On PHP 8.2 native curl_setopt() rejects that option, so Guzzle's
# defined()-guarded teardown threw on EVERY outbound request and took down payments, Telegram
# and Chatwoot. Both builds self-reported "Swoole 6.2.1", so a version tag is not enough.
# See scripts/assert-curl-contract.php for the build-time guard; it still gates this build.
FROM php-base

# Security floor: the build fails if the base image is older than this, so a stale digest can
# never ship silently. Bump it together with the FROM line above.
ARG PHP_MIN_VERSION=8.2.34
# Swoole release tag built below. 6.2.2 is what production has run since 2026-08-02 and is
# verified free of the CURLOPT_PREREQFUNCTION define (see above).
ARG SWOOLE_VERSION=6.2.2

COPY --from=composer:2.10.2@sha256:d020706319701a44468968321dccd0fce6620190159a7a9ec195d78e6e971c71 /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer:latest@sha256:b6d3fa381b9ba5cf051117c1c601d6a523b590e534bf3d56eb4fbe352949c138 /usr/bin/install-php-extensions /usr/local/bin/

# Fail fast if the base image does not meet the security floor.
RUN php -r 'exit(version_compare(PHP_VERSION, getenv("PHP_MIN_VERSION"), ">=") ? 0 : 1);' \
    || { echo "FATAL: PHP $(php -r 'echo PHP_VERSION;') is below the required ${PHP_MIN_VERSION} — update the FROM digest"; exit 1; }

# Swoole, pdo_mysql, sockets and redis exactly as phpswoole/swoole builds them (comments kept
# from upstream). Keep only the runtime shared libraries in the final image; the matching "-dev"
# packages are installed as the temporary ".build-deps" virtual package and removed afterwards.
# Package "libucontext" provides the ucontext functions that musl does not implement; Swoole
# needs them on architectures it has no context-switching assembly for.
RUN set -ex && \
    apk add --no-cache libstdc++ libpq libssh2 libucontext unixodbc && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev linux-headers brotli-dev postgresql-dev openssl-dev pcre-dev pcre2-dev sqlite-dev zlib-dev libssh2-dev libucontext-dev unixodbc-dev && \
    docker-php-ext-install pdo_mysql && \
    pecl channel-update pecl.php.net && \
    pecl install --configureoptions 'enable-redis-igbinary="no" enable-redis-lzf="yes" enable-redis-zstd="yes" enable-redis-msgpack="no" enable-redis-lz4="no" with-liblz4="yes"' redis-6.3.0 && \
    docker-php-ext-enable redis && \
    docker-php-ext-install sockets && \
    docker-php-source extract && \
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
