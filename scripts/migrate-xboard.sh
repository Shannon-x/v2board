#!/bin/bash
# ============================================================
# Xboard → V2Board 一键迁移脚本
# 使用方式：bash migrate-xboard.sh [选项]
#
# 示例：
#   # 交互模式（推荐首次使用）
#   bash migrate-xboard.sh
#
#   # 非交互，直接指定源库
#   bash migrate-xboard.sh \
#     --src-host=1.2.3.4 --src-db=xboard \
#     --src-user=xboard  --src-pass=your_pass
#
#   # 预览模式（不写入数据）
#   bash migrate-xboard.sh --dry-run \
#     --src-host=... --src-db=... --src-user=... --src-pass=...
#
#   # 只迁移用户和订单
#   bash migrate-xboard.sh --tables=v2_user,v2_order \
#     --src-host=... --src-db=... --src-user=... --src-pass=...
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
V2BOARD_DIR="$(dirname "${SCRIPT_DIR}")"
PHP_SCRIPT="${SCRIPT_DIR}/xboard-to-v2board.php"
CONTAINER_NAME="${V2BOARD_CONTAINER:-v2board}"
ENV_FILE="${V2BOARD_DIR}/data/.env"
BACKUP_DIR="${V2BOARD_DIR}/data/backups"

# ── 颜色 ─────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

log_info()  { echo -e "${GREEN}  ✓${RESET} $*"; }
log_warn()  { echo -e "${YELLOW}  !${RESET} $*"; }
log_error() { echo -e "${RED}  ✗${RESET} $*"; }
log_step()  { echo -e "\n${CYAN}▶${RESET} ${BOLD}$*${RESET}"; }

# ── 参数解析 ──────────────────────────────────────────────────────────────────
SRC_HOST=""; SRC_PORT="3306"; SRC_DB=""; SRC_USER=""; SRC_PASS=""
DST_HOST=""; DST_PORT=""; DST_DB=""; DST_USER=""; DST_PASS=""
DRY_RUN=""; FORCE=""; NO_TRUNCATE=""; TABLES=""; SKIP_BACKUP=""

for arg in "$@"; do
    case "$arg" in
        --src-host=*)    SRC_HOST="${arg#*=}" ;;
        --src-port=*)    SRC_PORT="${arg#*=}" ;;
        --src-db=*)      SRC_DB="${arg#*=}"   ;;
        --src-user=*)    SRC_USER="${arg#*=}" ;;
        --src-pass=*)    SRC_PASS="${arg#*=}" ;;
        --dst-host=*)    DST_HOST="${arg#*=}" ;;
        --dst-db=*)      DST_DB="${arg#*=}"   ;;
        --dst-user=*)    DST_USER="${arg#*=}" ;;
        --dst-pass=*)    DST_PASS="${arg#*=}" ;;
        --tables=*)      TABLES="${arg#*=}"   ;;
        --dry-run)       DRY_RUN="--dry-run"  ;;
        --force)         FORCE="--force"      ;;
        --no-truncate)   NO_TRUNCATE="--no-truncate" ;;
        --skip-backup)   SKIP_BACKUP=1        ;;
        --help|-h)
            sed -n '/^# ===/,/^# ===/p' "$0" | head -25 | sed 's/^# \{0,2\}//'
            exit 0 ;;
    esac
done

# ── 欢迎横幅 ─────────────────────────────────────────────────────────────────
echo -e "${CYAN}"
cat <<'BANNER'
╔══════════════════════════════════════════════════════╗
║         Xboard → V2Board  一键数据迁移工具           ║
╚══════════════════════════════════════════════════════╝
BANNER
echo -e "${RESET}"

# ── 前置检查 ─────────────────────────────────────────────────────────────────
log_step "前置检查"

# 检查 V2Board 容器
if ! docker inspect "${CONTAINER_NAME}" &>/dev/null; then
    log_error "未找到容器 ${CONTAINER_NAME}，请确认 V2Board 已启动"
    exit 1
fi
log_info "V2Board 容器：${CONTAINER_NAME}"

# 检查 PHP 脚本
if [ ! -f "${PHP_SCRIPT}" ]; then
    log_error "迁移脚本不存在：${PHP_SCRIPT}"
    exit 1
fi
log_info "迁移脚本：${PHP_SCRIPT}"

# 读取目标库凭证（从 .env）
if [ -f "${ENV_FILE}" ]; then
    _read_env() { grep -E "^${1}=" "${ENV_FILE}" 2>/dev/null | head -1 | cut -d'=' -f2- | tr -d '"'"'" || echo "$2"; }
    DB_HOST_ENV=$(_read_env DB_HOST 127.0.0.1)
    DB_PORT_ENV=$(_read_env DB_PORT 3306)
    DB_DB_ENV=$(_read_env DB_DATABASE "")
    DB_USER_ENV=$(_read_env DB_USERNAME "")
    DB_PASS_ENV=$(_read_env DB_PASSWORD "")
    log_info "目标库配置已从 data/.env 读取：${DB_USER_ENV}@${DB_HOST_ENV}:${DB_PORT_ENV}/${DB_DB_ENV}"
else
    log_warn "未找到 data/.env，需要手动指定 --dst-* 参数"
    DB_HOST_ENV=""; DB_PORT_ENV="3306"; DB_DB_ENV=""; DB_USER_ENV=""; DB_PASS_ENV=""
fi

# ── 交互式输入源库信息 ────────────────────────────────────────────────────────
if [ -z "${SRC_HOST}" ]; then
    log_step "请输入 Xboard 数据库连接信息"
    read -rp "  MySQL 主机（如 1.2.3.4 或容器名）：" SRC_HOST
    read -rp "  MySQL 端口 [3306]：" _port
    SRC_PORT="${_port:-3306}"
    read -rp "  数据库名：" SRC_DB
    read -rp "  用户名：" SRC_USER
    read -rsp "  密码（输入不可见）：" SRC_PASS; echo
fi

# ── 测试源库连通性 ─────────────────────────────────────────────────────────
log_step "测试源库（Xboard）连接"
if ! docker exec "${CONTAINER_NAME}" php -r "
try {
    new PDO('mysql:host=${SRC_HOST};port=${SRC_PORT};dbname=${SRC_DB}',
            '${SRC_USER}', '${SRC_PASS}', [PDO::ATTR_TIMEOUT => 5]);
    echo 'OK';
} catch (Exception \$e) { echo 'FAIL:'.\$e->getMessage(); }
" 2>/dev/null | grep -q "^OK"; then
    log_error "无法连接到 Xboard 数据库 ${SRC_USER}@${SRC_HOST}:${SRC_PORT}/${SRC_DB}"
    log_warn "请检查：1) 主机/端口是否正确 2) 用户名密码是否正确 3) 防火墙是否开放"
    exit 1
fi
log_info "源库连接成功 ✓"

# ── 查询源库版本信息 ──────────────────────────────────────────────────────────
log_step "检测 Xboard 数据库版本"
HAS_UNIFIED=$(docker exec "${CONTAINER_NAME}" php -r "
try {
    \$pdo = new PDO('mysql:host=${SRC_HOST};port=${SRC_PORT};dbname=${SRC_DB}',
                   '${SRC_USER}', '${SRC_PASS}');
    \$r = \$pdo->query(\"SHOW TABLES LIKE 'v2_server'\")->rowCount();
    echo \$r > 0 ? 'unified' : 'legacy';
} catch (Exception \$e) { echo 'error'; }
" 2>/dev/null)

USER_COUNT=$(docker exec "${CONTAINER_NAME}" php -r "
try {
    \$pdo = new PDO('mysql:host=${SRC_HOST};port=${SRC_PORT};dbname=${SRC_DB}',
                   '${SRC_USER}', '${SRC_PASS}');
    echo \$pdo->query('SELECT COUNT(*) FROM v2_user')->fetchColumn();
} catch (Exception \$e) { echo '?'; }
" 2>/dev/null)

SERVER_COUNT=$(docker exec "${CONTAINER_NAME}" php -r "
try {
    \$pdo = new PDO('mysql:host=${SRC_HOST};port=${SRC_PORT};dbname=${SRC_DB}',
                   '${SRC_USER}', '${SRC_PASS}');
    \$t = '${HAS_UNIFIED}' === 'unified' ? 'v2_server' : 'v2_server_vmess';
    echo \$pdo->query(\"SELECT COUNT(*) FROM \${t}\")->fetchColumn();
} catch (Exception \$e) { echo '?'; }
" 2>/dev/null)

if [ "${HAS_UNIFIED}" = "unified" ]; then
    log_info "检测到 Xboard 新版（统一 v2_server 表）"
else
    log_info "检测到 Xboard 旧版（分类型服务器表）"
fi
log_info "用户数：${USER_COUNT}，节点数：${SERVER_COUNT}（仅主类型表）"

# ── 备份目标库 ────────────────────────────────────────────────────────────────
if [ -z "${SKIP_BACKUP}" ] && [ -z "${DRY_RUN}" ]; then
    log_step "备份当前 V2Board 数据库"
    mkdir -p "${BACKUP_DIR}"
    BACKUP_FILE="${BACKUP_DIR}/v2board_before_xboard_migration_$(date +%Y%m%d_%H%M%S).sql.gz"

    _dst_host="${DST_HOST:-${DB_HOST_ENV}}"
    _dst_port="${DST_PORT:-${DB_PORT_ENV}}"
    _dst_db="${DST_DB:-${DB_DB_ENV}}"
    _dst_user="${DST_USER:-${DB_USER_ENV}}"
    _dst_pass="${DST_PASS:-${DB_PASS_ENV}}"

    if docker exec "${CONTAINER_NAME}" sh -c \
        "mysqldump -h'${_dst_host}' -P'${_dst_port}' -u'${_dst_user}' -p'${_dst_pass}' '${_dst_db}' 2>/dev/null | gzip" \
        > "${BACKUP_FILE}" 2>/dev/null; then
        SIZE=$(du -sh "${BACKUP_FILE}" 2>/dev/null | cut -f1)
        log_info "备份完成：${BACKUP_FILE}（${SIZE}）"
    else
        log_warn "备份失败（mysqldump 未安装或权限不足），继续迁移前请手动备份数据库"
        read -rp "  是否继续？(yes/no)：" _confirm
        [ "${_confirm}" = "yes" ] || { echo "已取消。"; exit 0; }
    fi
fi

# ── 执行迁移 ──────────────────────────────────────────────────────────────────
log_step "开始迁移数据"

# 构建 PHP 脚本参数
PHP_ARGS=(
    "--src-host=${SRC_HOST}"
    "--src-port=${SRC_PORT}"
    "--src-db=${SRC_DB}"
    "--src-user=${SRC_USER}"
    "--src-pass=${SRC_PASS}"
)
[ -n "${TABLES}" ]      && PHP_ARGS+=("--tables=${TABLES}")
[ -n "${DRY_RUN}" ]     && PHP_ARGS+=("--dry-run")
[ -n "${FORCE}" ]       && PHP_ARGS+=("--force")
[ -n "${NO_TRUNCATE}" ] && PHP_ARGS+=("--no-truncate")
[ -n "${DST_HOST}" ]    && PHP_ARGS+=("--dst-host=${DST_HOST}")
[ -n "${DST_DB}" ]      && PHP_ARGS+=("--dst-db=${DST_DB}")
[ -n "${DST_USER}" ]    && PHP_ARGS+=("--dst-user=${DST_USER}")
[ -n "${DST_PASS}" ]    && PHP_ARGS+=("--dst-pass=${DST_PASS}")
[ -n "${DST_PORT}" ]    && PHP_ARGS+=("--dst-port=${DST_PORT}")

# 把 PHP 脚本复制到容器内执行
docker cp "${PHP_SCRIPT}" "${CONTAINER_NAME}:/tmp/xboard-to-v2board.php"

docker exec -i "${CONTAINER_NAME}" php /tmp/xboard-to-v2board.php "${PHP_ARGS[@]}"
EXIT_CODE=$?

docker exec "${CONTAINER_NAME}" rm -f /tmp/xboard-to-v2board.php

if [ "${EXIT_CODE}" -ne 0 ]; then
    log_error "迁移失败（退出码 ${EXIT_CODE}）"
    if [ -n "${BACKUP_FILE:-}" ] && [ -f "${BACKUP_FILE}" ]; then
        echo ""
        log_warn "如需回滚，使用以下命令恢复备份："
        echo "    zcat ${BACKUP_FILE} | docker exec -i ${CONTAINER_NAME} \\"
        echo "      mysql -h'\${DB_HOST}' -u'\${DB_USER}' -p'\${DB_PASS}' '\${DB_NAME}'"
    fi
    exit "${EXIT_CODE}"
fi

# ── 迁移后重建缓存 ────────────────────────────────────────────────────────────
if [ -z "${DRY_RUN}" ]; then
    log_step "重建 V2Board 缓存"
    docker exec "${CONTAINER_NAME}" bash -c "
        cd /var/www/v2board
        php artisan config:cache 2>&1 && echo '  config 缓存已重建' || true
        php artisan route:cache  2>&1 && echo '  route  缓存已重建' || true
        php artisan view:cache   2>&1 && echo '  view   缓存已重建' || true
    "

    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════════════╗${RESET}"
    echo -e "${GREEN}║              迁移成功！后续操作建议                  ║${RESET}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════╝${RESET}"
    echo ""
    echo "  1. 在 V2Board 后台重新配置系统设置（支付、邮件、站点信息等）"
    echo "  2. 检查节点列表是否完整，核对协议类型是否正确"
    echo "  3. 用 Xboard 的管理员账号登录（密码相同）"
    echo "  4. 如有礼品卡数据，需手动在后台重新录入"
    echo ""
    if [ -n "${BACKUP_FILE:-}" ] && [ -f "${BACKUP_FILE}" ]; then
        echo -e "${YELLOW}  备份文件：${BACKUP_FILE}${RESET}"
        echo -e "${YELLOW}  请妥善保管，以便出现问题时回滚${RESET}"
    fi
    echo ""
fi
