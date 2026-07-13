# ============================================================
# V2Board — Workerman (AdapterMan) 高性能单容器镜像
# 内部进程：Workerman(:6600) + Nginx(:80 反代) + Horizon(队列)
# 外部依赖：MySQL · Redis · (可选)外部 Nginx/CDN
# 支持架构：linux/amd64, linux/arm64
# ============================================================

# ---------- Stage 1: composer 依赖 ----------
FROM composer:2 AS vendor

WORKDIR /build

# 只先拷贝依赖清单：这一层的缓存键仅取决于 composer.json/composer.lock，
# 因此改动业务代码不会再让依赖重装。（务必不要在 composer install 之前 COPY 源码）
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --ignore-platform-reqs

# 依赖装完后再拷贝源码，仅重建 autoload 映射（秒级）
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# ---------- Stage 2: 运行时镜像 ----------
FROM php:8.1-cli-bookworm

LABEL maintainer="Shannon-x" \
      org.opencontainers.image.source="https://github.com/Shannon-x/v2board"

ARG DEBIAN_FRONTEND=noninteractive

# 系统依赖 —— 分层安装便于缓存
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
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
    && rm -rf /var/lib/apt/lists/*

# PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
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
        xml

# PECL 扩展（igbinary 必须在 redis 之前，redis 编译时依赖它）
RUN pecl install igbinary \
    && docker-php-ext-enable igbinary \
    && pecl install --configureoptions 'enable-redis-igbinary="yes"' redis \
    && docker-php-ext-enable redis

# 清理构建缓存
RUN apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# ---------- 应用代码 ----------
WORKDIR /var/www/v2board

COPY . .
COPY --from=vendor /build/vendor ./vendor

RUN php artisan package:discover --ansi 2>/dev/null || true

# 目录权限
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache config/theme \
    && chown -R www-data:www-data /var/www/v2board \
    && chmod -R 775 storage bootstrap/cache config

# 配置文件
COPY docker/nginx.conf         /etc/nginx/sites-available/default
COPY docker/supervisord.conf   /etc/supervisor/conf.d/v2board.conf
COPY docker/php.ini            /usr/local/etc/php/conf.d/99-v2board.ini
COPY docker/logrotate.conf     /etc/logrotate.d/v2board
COPY docker/entrypoint.sh      /entrypoint.sh
COPY docker/log-tail.sh        /usr/local/bin/log-tail.sh

RUN chmod +x /entrypoint.sh \
    && chmod +x /usr/local/bin/log-tail.sh \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1/healthz -o /dev/null 2>&1 || curl -fsS http://127.0.0.1/monitor/api -o /dev/null 2>&1 || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
