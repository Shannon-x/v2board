# V2Board

多协议代理服务管理面板，基于 Workerman (AdapterMan) 高性能架构，提供开箱即用的 Docker 部署方案。

## 特性

- **高性能**：Workerman 常驻内存 HTTP 服务器，替代传统 PHP-FPM
- **单容器部署**：内置 Nginx + Workerman + Horizon (队列)，一条命令启动
- **自动初始化**：首次启动自动完成数据库导入、管理员创建、配置缓存
- **多架构支持**：`linux/amd64` 和 `linux/arm64`
- **日志管理**：内置 logrotate，日志自动轮转，不会无限增长

## 环境要求

| 依赖 | 说明 |
|------|------|
| Docker & Docker Compose | 容器运行环境 |
| MySQL 5.7+ | 外部数据库服务 |
| Redis 6+ | 外部缓存/队列服务 |
| Nginx / Caddy（可选） | 外部反向代理，提供 HTTPS |

> 容器内部已包含 Nginx 做 Workerman 的反代，外部 Nginx 用于 HTTPS 终止和域名绑定。

## 快速开始

### 1. 获取项目

```bash
git clone https://github.com/Shannon-x/v2board.git
cd v2board
```

### 2. 配置环境变量

```bash
cp .env.example .env
```

编辑 `.env`，至少修改以下配置：

```ini
# 站点地址（改为你的实际域名）
APP_URL=https://your-domain.com

# 数据库
DB_HOST=your-mysql-host
DB_PORT=3306
DB_DATABASE=v2board
DB_USERNAME=v2board
DB_PASSWORD=your_db_password

# Redis
REDIS_HOST=your-redis-host
REDIS_PORT=6379
REDIS_PASSWORD=your_redis_password

# 管理员账号（仅首次启动生效）
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=your_secure_password

# 后台访问路径：https://your-domain.com/你设置的路径
ADMIN_SECURE_PATH=my-admin-panel
```

### 3. 启动容器

```bash
docker compose up -d
```

首次启动会自动完成：
1. 从 `.env.example` 生成配置（如未提供 `.env`）
2. 生成 `APP_KEY`
3. 等待 MySQL / Redis 就绪
4. 导入数据库表结构
5. 创建管理员账号
6. 缓存配置/路由/视图

### 4. 查看初始化日志

```bash
docker logs v2board
```

输出中会显示管理员账号信息：

```
[entrypoint] ======================================================
[entrypoint]   管理员邮箱:  admin@example.com
[entrypoint]   管理员密码:  your_secure_password
[entrypoint]   后台路径:    /my-admin-panel
[entrypoint] ======================================================
```

### 5. 配置外部 Nginx 反向代理

容器暴露端口默认为 `7680`（可通过 `V2BOARD_PORT` 环境变量修改）。

Nginx 参考配置：

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate     /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://127.0.0.1:7680;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        "upgrade";
    }
}

server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

## 使用预构建镜像

如果不想本地构建，可以直接使用 GitHub Container Registry 上的镜像：

```bash
# docker-compose.yml 中已默认配置
image: ghcr.io/shannon-x/v2board:latest
```

或者手动拉取：

```bash
docker pull ghcr.io/shannon-x/v2board:latest
```

## Docker Compose 完整示例

以下示例展示如何连接同一 Docker 网络中已有的 MySQL 和 Redis：

```yaml
version: "3.8"

services:
  v2board:
    image: ghcr.io/shannon-x/v2board:latest
    container_name: v2board
    restart: unless-stopped
    ports:
      - "7680:80"
    volumes:
      - ./data:/var/www/v2board/data          # 持久化 .env 和安装锁
      - v2board-logs:/var/www/v2board/storage/logs  # 日志
    env_file:
      - path: .env
        required: false
    environment:
      - TZ=Asia/Shanghai
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "5"
    networks:
      - your-network    # 替换为 MySQL/Redis 所在的 Docker 网络

volumes:
  v2board-logs:
    driver: local

networks:
  your-network:
    external: true       # 使用已存在的外部网络
```

## 数据持久化

| 路径 | 说明 |
|------|------|
| `./data/.env` | 环境变量配置，容器重启后保留 |
| `./data/.installed` | 安装锁文件，存在时跳过初始化 |
| `v2board-logs` | 应用日志（Workerman / Horizon / Nginx） |

> **重新初始化**：删除 `./data/.installed` 文件后重启容器，会重新执行数据库导入和管理员创建流程。

## 环境变量参考

### 基础配置

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `APP_URL` | `http://localhost` | 站点访问地址 |
| `APP_ENV` | `production` | 运行环境 |
| `APP_DEBUG` | `false` | 调试模式 |
| `V2BOARD_PORT` | `7680` | 容器映射到宿主机的端口 |

### 数据库

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `DB_HOST` | `127.0.0.1` | MySQL 地址 |
| `DB_PORT` | `3306` | MySQL 端口 |
| `DB_DATABASE` | `v2board` | 数据库名 |
| `DB_USERNAME` | `v2board` | 数据库用户 |
| `DB_PASSWORD` | — | 数据库密码 |

### Redis

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `REDIS_HOST` | `127.0.0.1` | Redis 地址 |
| `REDIS_PORT` | `6379` | Redis 端口 |
| `REDIS_PASSWORD` | `null` | Redis 密码 |

### 管理员（仅首次安装生效）

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `ADMIN_EMAIL` | `admin@v2board.com` | 管理员邮箱 |
| `ADMIN_PASSWORD` | 自动生成 | 管理员密码（至少 8 位） |
| `ADMIN_SECURE_PATH` | `admin-dashboard` | 后台访问路径 |

### 邮件

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `MAIL_DRIVER` | `smtp` | 邮件驱动 |
| `MAIL_HOST` | — | SMTP 服务器 |
| `MAIL_PORT` | `2525` | SMTP 端口 |
| `MAIL_USERNAME` | — | SMTP 用户名 |
| `MAIL_PASSWORD` | — | SMTP 密码 |
| `MAIL_ENCRYPTION` | — | 加密方式 (`tls`/`ssl`) |
| `MAIL_FROM_ADDRESS` | — | 发件人地址 |

## 容器架构

```
┌─────────────────────────────────────────────┐
│              Docker Container               │
│                                             │
│  ┌─────────┐    proxy     ┌──────────────┐  │
│  │  Nginx  │ ──────────── │  Workerman   │  │
│  │  :80    │              │  :6600       │  │
│  └─────────┘              └──────────────┘  │
│                                             │
│  ┌──────────────┐   ┌──────────────────┐    │
│  │   Horizon    │   │    Logrotate     │    │
│  │  (队列消费)   │   │  (日志轮转)       │    │
│  └──────────────┘   └──────────────────┘    │
│                                             │
│            Supervisor 进程管理                │
└──────────────────┬──────────────────────────┘
                   │ :7680 (宿主机)
         ┌─────────┴─────────┐
         │   外部 Nginx      │
         │   (HTTPS 终止)    │
         └─────────┬─────────┘
                   │ :443
               用户访问
```

## 常见问题

### 如何修改后台路径？

编辑 `.env` 中的 `ADMIN_SECURE_PATH`，仅在**首次安装**时生效。安装后如需修改，直接编辑容器内 `config/v2board.php` 中的 `secure_path` 字段，然后重启容器。

### 如何更新版本？

```bash
docker compose pull
docker compose up -d
```

### 如何查看运行日志？

```bash
# 容器启动日志
docker logs v2board

# 应用日志（进入容器）
docker exec v2board tail -f /var/www/v2board/storage/logs/webman.log
docker exec v2board tail -f /var/www/v2board/storage/logs/horizon.log
docker exec v2board tail -f /var/www/v2board/storage/logs/nginx-error.log
```

### 容器内进程状态

```bash
docker exec v2board supervisorctl status
```

正常输出：

```
horizon    RUNNING   pid 123, uptime 0:05:00
logrotate  RUNNING   pid 124, uptime 0:05:00
nginx      RUNNING   pid 125, uptime 0:05:00
webman     RUNNING   pid 126, uptime 0:05:00
```

### MySQL / Redis 连接失败？

1. 确认 MySQL 和 Redis 服务已启动
2. 如果它们运行在 Docker 中，确保 V2Board 容器和它们在同一个网络下（`docker-compose.yml` 中配置 `networks`）
3. `DB_HOST` / `REDIS_HOST` 应使用容器名而非 `127.0.0.1`

## 支持的后端

- [V2bX](https://github.com/wyx2685/V2bX)
- [v2node](https://github.com/wyx2685/v2node)

## 许可证

[MIT](LICENSE)
