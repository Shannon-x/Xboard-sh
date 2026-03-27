# Stage 1: Build XBoard-admin frontend
FROM node:20-alpine AS admin-builder

WORKDIR /build

ARG ADMIN_REPO_URL=https://github.com/Shannon-x/XBoard-admin.git
ARG ADMIN_BRANCH=main
ARG CACHEBUST

# Copy patch script first
COPY scripts/patch-admin.sh /tmp/patch-admin.sh

RUN apk --no-cache add git && \
    echo "Cache bust: ${CACHEBUST}" && \
    git clone --depth 1 --branch ${ADMIN_BRANCH} ${ADMIN_REPO_URL} . && \
    sh /tmp/patch-admin.sh && \
    npm install && \
    npm run build

# Stage 2: PHP application
FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

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

# Add build arguments
ARG CACHEBUST
ARG REPO_URL
ARG BRANCH_NAME

RUN echo "Attempting to clone branch: ${BRANCH_NAME} from ${REPO_URL} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git clone --depth 1 --branch ${BRANCH_NAME} ${REPO_URL} .

# Copy XBoard-admin built assets to replace default admin
COPY --from=admin-builder /build/dist/ /www/public/assets/admin/

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN composer install --no-cache --no-dev \
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
