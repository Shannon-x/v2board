#!/bin/bash
set -uo pipefail

APP_DIR="/var/www/v2board"
DATA_DIR="${APP_DIR}/data"
INSTALL_LOCK="${DATA_DIR}/.installed"
cd "$APP_DIR"

log() { echo "[entrypoint $(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ── .env 处理 ──────────────────────────────────────────────
mkdir -p "$DATA_DIR"

if [ -d "${APP_DIR}/.env" ]; then
    rm -rf "${APP_DIR}/.env"
fi

if [ -f "${DATA_DIR}/.env" ]; then
    ln -sf "${DATA_DIR}/.env" "${APP_DIR}/.env"
    log ".env 已从 data/.env 加载"
elif [ -f "${APP_DIR}/.env" ] && [ -s "${APP_DIR}/.env" ]; then
    cp "${APP_DIR}/.env" "${DATA_DIR}/.env"
    ln -sf "${DATA_DIR}/.env" "${APP_DIR}/.env"
    log ".env 已复制到 data/"
else
    if [ -f "${APP_DIR}/.env.example" ]; then
        cp "${APP_DIR}/.env.example" "${DATA_DIR}/.env"
        log "已从 .env.example 创建 .env"
    else
        log "ERROR: 未找到 .env.example"
        exit 1
    fi
    ln -sf "${DATA_DIR}/.env" "${APP_DIR}/.env"
fi

# 用环境变量覆盖 .env（仅首次生成或每次启动都可刷新）
override_env() {
    local key="$1" val="${!key:-}"
    if [ -n "$val" ]; then
        if grep -qE "^${key}=" "${DATA_DIR}/.env"; then
            sed -i "s|^${key}=.*|${key}=${val}|" "${DATA_DIR}/.env"
        else
            echo "${key}=${val}" >> "${DATA_DIR}/.env"
        fi
    fi
}
for key in APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL \
           DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
           REDIS_HOST REDIS_PASSWORD REDIS_PORT \
           CACHE_DRIVER QUEUE_CONNECTION SESSION_DRIVER \
           ADMIN_EMAIL ADMIN_PASSWORD; do
    override_env "$key"
done

# ── 目录 & 权限 ────────────────────────────────────────────
mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache data
chmod -R 775 storage bootstrap/cache

# ── APP_KEY ────────────────────────────────────────────────
if ! grep -qE '^APP_KEY=base64:.+' "${DATA_DIR}/.env"; then
    log "生成 APP_KEY ..."
    php artisan key:generate --force 2>&1 || log "WARN: key:generate 失败"
fi

# ── 等待外部服务 ───────────────────────────────────────────
wait_for_tcp() {
    local host="$1" port="$2" label="$3" max=30 i=0
    log "等待 ${label} (${host}:${port}) ..."
    while ! php -r "if(!@fsockopen('${host}',${port},\$e,\$m,2))exit(1);" 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge "$max" ]; then
            log "ERROR: ${label} 连接超时"
            exit 1
        fi
        sleep 1
    done
    log "${label} 已就绪"
}

_read_env() {
    grep -E "^${1}=" "${DATA_DIR}/.env" 2>/dev/null | head -1 | cut -d'=' -f2- | tr -d '[:space:]"'"'" || echo "$2"
}

wait_for_tcp "$(_read_env DB_HOST localhost)"    "$(_read_env DB_PORT 3306)"  "MySQL"
wait_for_tcp "$(_read_env REDIS_HOST 127.0.0.1)" "$(_read_env REDIS_PORT 6379)" "Redis"

# ── 自动安装（替代 php artisan v2board:install）──────────────
if [ ! -f "$INSTALL_LOCK" ]; then
    log "========== 首次安装 =========="

    # 1) 导入数据库结构
    log "导入数据库表结构 ..."
    if php artisan v2board:db-import 2>&1; then
        log "数据库导入完成"
    else
        log "ERROR: 数据库导入失败"
        exit 1
    fi

    # 2) 创建管理员
    ADMIN_EMAIL=$(_read_env ADMIN_EMAIL "admin@v2board.com")
    ADMIN_PASSWORD=$(_read_env ADMIN_PASSWORD "")

    if [ -z "$ADMIN_PASSWORD" ] || [ ${#ADMIN_PASSWORD} -lt 8 ]; then
        ADMIN_PASSWORD=$(head -c 16 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9' | head -c 16)
        log "ADMIN_PASSWORD 未设置或少于8位，已自动生成"
    fi

    log "创建管理员账号 ..."
    if php artisan v2board:admin-create --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" 2>&1; then
        SECURE_PATH=$(php artisan v2board:admin-path 2>/dev/null || echo "unknown")
        log "=================================="
        log "  管理员邮箱: ${ADMIN_EMAIL}"
        log "  管理员密码: ${ADMIN_PASSWORD}"
        log "  后台路径:   /${SECURE_PATH}"
        log "=================================="
    else
        log "ERROR: 管理员创建失败"
        exit 1
    fi

    echo "installed at $(date)" > "$INSTALL_LOCK"
    log "========== 安装完成 =========="
else
    log "已安装，跳过初始化"
    # 非首次启动仍执行迁移（可能有表结构更新）
    php artisan migrate --force 2>&1 | while IFS= read -r line; do log "  migrate: $line"; done || true
fi

# ── 缓存 ──────────────────────────────────────────────────
log "缓存配置 ..."
php artisan config:cache  2>&1 || true
php artisan route:cache   2>&1 || true
php artisan view:cache    2>&1 || true

log "初始化完成，启动服务 ..."
exec "$@"
