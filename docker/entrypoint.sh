#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/v2board"
cd "$APP_DIR"

log() { echo "[entrypoint $(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ── .env ────────────────────────────────────────────────────
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        cp .env.example .env
        log "已从 .env.example 创建 .env — 请按需修改后重启容器"
    else
        log "ERROR: 未找到 .env 或 .env.example"
        exit 1
    fi
fi

# ── 目录 & 权限 ────────────────────────────────────────────
mkdir -p storage/logs \
         storage/framework/{cache,sessions,views} \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# ── 等待外部服务就绪 ───────────────────────────────────────
wait_for_tcp() {
    local host="$1" port="$2" label="$3" max=30 i=0
    log "等待 ${label} (${host}:${port}) ..."
    while ! php -r "if(!@fsockopen('${host}',${port},\$e,\$m,2))exit(1);" 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge "$max" ]; then
            log "ERROR: ${label} 连接超时 (${max}s)"
            exit 1
        fi
        sleep 1
    done
    log "${label} 已就绪"
}

DB_HOST=$(php -r "echo parse_url(env('DB_HOST') ?? getenv('DB_HOST'))['host'] ?? getenv('DB_HOST') ?? 'localhost';" 2>/dev/null || echo "localhost")
DB_PORT=$(php -r "echo getenv('DB_PORT') ?: '3306';" 2>/dev/null || echo "3306")
REDIS_HOST_VAL=$(php -r "echo getenv('REDIS_HOST') ?: '127.0.0.1';" 2>/dev/null || echo "127.0.0.1")
REDIS_PORT_VAL=$(php -r "echo getenv('REDIS_PORT') ?: '6379';" 2>/dev/null || echo "6379")

# 从 .env 文件直接读取（更可靠）
if [ -f ".env" ]; then
    _db_host=$(grep -E '^DB_HOST=' .env | cut -d'=' -f2- | tr -d '[:space:]"'"'" || true)
    _db_port=$(grep -E '^DB_PORT=' .env | cut -d'=' -f2- | tr -d '[:space:]"'"'" || true)
    _redis_host=$(grep -E '^REDIS_HOST=' .env | cut -d'=' -f2- | tr -d '[:space:]"'"'" || true)
    _redis_port=$(grep -E '^REDIS_PORT=' .env | cut -d'=' -f2- | tr -d '[:space:]"'"'" || true)
    [ -n "$_db_host" ]    && DB_HOST="$_db_host"
    [ -n "$_db_port" ]    && DB_PORT="$_db_port"
    [ -n "$_redis_host" ] && REDIS_HOST_VAL="$_redis_host"
    [ -n "$_redis_port" ] && REDIS_PORT_VAL="$_redis_port"
fi

wait_for_tcp "$DB_HOST"       "$DB_PORT"       "MySQL"
wait_for_tcp "$REDIS_HOST_VAL" "$REDIS_PORT_VAL" "Redis"

# ── Laravel 初始化 ─────────────────────────────────────────
log "执行 Laravel 初始化 ..."

php artisan migrate --force 2>&1 | while IFS= read -r line; do log "migrate: $line"; done || true

php artisan config:cache  2>/dev/null || true
php artisan route:cache   2>/dev/null || true
php artisan view:cache    2>/dev/null || true

log "初始化完成，启动服务 ..."

exec "$@"
