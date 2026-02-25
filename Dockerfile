# ============================================================
# V2Board — Workerman (AdapterMan) 高性能单容器镜像
# 内部进程：Workerman(:6600) + Nginx(:80 反代) + Horizon(队列)
# 外部依赖：MySQL · Redis · (可选)外部 Nginx/CDN
# ============================================================

# ---------- Stage 1: composer 依赖 ----------
FROM composer:2 AS vendor

WORKDIR /build
COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

# ---------- Stage 2: 运行时镜像 ----------
FROM php:8.1-cli-bookworm

LABEL maintainer="Shannon-x" \
      org.opencontainers.image.source="https://github.com/Shannon-x/v2board"

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        logrotate \
        curl \
        ca-certificates \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libsodium-dev \
        libigbinary-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        mbstring \
        zip \
        gd \
        bcmath \
        sodium \
        pcntl \
        posix \
        opcache \
        fileinfo \
        xml \
    && pecl install igbinary redis \
    && docker-php-ext-enable igbinary redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# 复制项目文件
WORKDIR /var/www/v2board
COPY --from=vendor /build/vendor ./vendor
COPY . .

# 生成优化的自动加载
RUN php vendor/bin/composer dump-autoload --optimize --no-dev 2>/dev/null || true \
    && php artisan package:discover --ansi 2>/dev/null || true

# 目录权限
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chown -R www-data:www-data /var/www/v2board \
    && chmod -R 775 storage bootstrap/cache

# 配置文件
COPY docker/nginx.conf        /etc/nginx/sites-available/default
COPY docker/supervisord.conf   /etc/supervisor/conf.d/v2board.conf
COPY docker/php.ini            /usr/local/etc/php/conf.d/99-v2board.ini
COPY docker/logrotate.conf     /etc/logrotate.d/v2board
COPY docker/entrypoint.sh      /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Nginx: 移除默认站点，避免冲突
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -sf http://127.0.0.1/api/v1/guest/comm/config || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
