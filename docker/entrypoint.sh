#!/bin/bash
set -uo pipefail

APP_DIR="/var/www/v2board"
DATA_DIR="${APP_DIR}/data"
cd "$APP_DIR"

log() { echo "[entrypoint $(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ── .env 处理 ──────────────────────────────────────────────
# 优先级：data/.env（持久卷） > 已有 .env > .env.example 模板
mkdir -p "$DATA_DIR"

# Docker 对不存在的文件做 bind mount 会创建空目录，清理掉
if [ -d "${APP_DIR}/.env" ]; then
    rm -rf "${APP_DIR}/.env"
fi

if [ -f "${DATA_DIR}/.env" ]; then
    ln -sf "${DATA_DIR}/.env" "${APP_DIR}/.env"
    log ".env 已从 data/.env 加载（持久化）"
elif [ -f "${APP_DIR}/.env" ] && [ -s "${APP_DIR}/.env" ]; then
    cp "${APP_DIR}/.env" "${DATA_DIR}/.env"
    ln -sf "${DATA_DIR}/.env" "${APP_DIR}/.env"
    log ".env 已复制到 data/ 并建立链接"
else
    if [ -f "${APP_DIR}/.env.example" ]; then
        cp "${APP_DIR}/.env.example" "${DATA_DIR}/.env"
        log "已从 .env.example 创建 .env"
    else
        log "ERROR: 未找到 .env.example 模板"
        exit 1
    fi

    # 用 docker-compose / docker run -e 传入的环境变量覆盖 .env 中的值
    override_env() {
        local key="$1"
        local val="${!key:-}"
        if [ -n "$val" ]; then
            if grep -qE "^${key}=" "${DATA_DIR}/.env"; then
                sed -i "s|^${key}=.*|${key}=${val}|" "${DATA_DIR}/.env"
            else
                echo "${key}=${val}" >> "${DATA_DIR}/.env"
            fi
            log "  覆盖: ${key}"
        fi
    }
    for key in APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL \
               DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
               REDIS_HOST REDIS_PASSWORD REDIS_PORT \
               CACHE_DRIVER QUEUE_CONNECTION SESSION_DRIVER; do
        override_env "$key"
    done

    ln -sf "${DATA_DIR}/.env" "${APP_DIR}/.env"
    log ".env 已生成到 data/.env — 如需修改请编辑 data/.env 后重启容器"
fi

# ── 目录 & 权限 ────────────────────────────────────────────
mkdir -p storage/logs \
         storage/framework/{cache,sessions,views} \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache data
chmod -R 775 storage bootstrap/cache

# 首次运行若无 APP_KEY 则自动生成
if ! grep -qE '^APP_KEY=base64:.+' "${DATA_DIR}/.env"; then
    log "APP_KEY 为空，正在生成 ..."
    if php artisan key:generate --force 2>&1; then
        log "APP_KEY 已生成"
    else
        log "WARN: key:generate 失败，请手动执行"
    fi
fi

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

# 从 .env 文件读取连接信息
_read_env() { grep -E "^${1}=" "${DATA_DIR}/.env" 2>/dev/null | head -1 | cut -d'=' -f2- | tr -d '[:space:]"'"'" || echo "$2"; }

DB_HOST=$(_read_env DB_HOST "localhost")
DB_PORT=$(_read_env DB_PORT "3306")
REDIS_HOST_VAL=$(_read_env REDIS_HOST "127.0.0.1")
REDIS_PORT_VAL=$(_read_env REDIS_PORT "6379")

wait_for_tcp "$DB_HOST"        "$DB_PORT"        "MySQL"
wait_for_tcp "$REDIS_HOST_VAL" "$REDIS_PORT_VAL" "Redis"

# ── Laravel 初始化 ─────────────────────────────────────────
log "执行数据库迁移 ..."
php artisan migrate --force 2>&1 | while IFS= read -r line; do log "  migrate: $line"; done || true

log "缓存配置 ..."
php artisan config:cache  2>&1 || true
php artisan route:cache   2>&1 || true
php artisan view:cache    2>&1 || true

log "初始化完成，启动服务 ..."
exec "$@"
