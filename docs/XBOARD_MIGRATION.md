# Xboard → V2Board 完整迁移指南

本文档详细说明 Xboard 与 V2Board 数据库之间的所有差异，以及逐步手动迁移方法。  
脚本迁移请参考 [`scripts/migrate-xboard.sh`](../scripts/migrate-xboard.sh)，手动迁移请阅读本文。

---

## 目录

1. [迁移前准备](#1-迁移前准备)
2. [数据库总体差异](#2-数据库总体差异)
3. [各表差异详解与手动迁移](#3-各表差异详解与手动迁移)
   - [v2_user（用户表）](#31-v2_user-用户表)
   - [v2_plan（套餐表）](#32-v2_plan-套餐表)
   - [v2_order（订单表）](#33-v2_order-订单表)
   - [服务器节点表](#34-服务器节点表)
   - [v2_server_group / v2_server_route](#35-v2_server_group--v2_server_route)
   - [其余兼容表](#36-其余兼容表直接复制)
4. [系统配置迁移（v2_settings）](#4-系统配置迁移-v2_settings)
5. [不可自动迁移的内容](#5-不可自动迁移的内容)
6. [迁移后收尾操作](#6-迁移后收尾操作)
7. [回滚方法](#7-回滚方法)

---

## 1. 迁移前准备

### 1.1 备份两侧数据库

```bash
# 备份 Xboard 数据库
mysqldump -h <xboard_host> -u <user> -p <xboard_db> > xboard_backup_$(date +%Y%m%d).sql

# 备份 V2Board 数据库（重要！）
mysqldump -h <v2board_host> -u <user> -p <v2board_db> > v2board_backup_$(date +%Y%m%d).sql
```

### 1.2 查询 Xboard 当前数据量

```sql
SELECT 'v2_user'    AS tbl, COUNT(*) AS cnt FROM v2_user    UNION ALL
SELECT 'v2_plan'    AS tbl, COUNT(*) AS cnt FROM v2_plan    UNION ALL
SELECT 'v2_order'   AS tbl, COUNT(*) AS cnt FROM v2_order   UNION ALL
SELECT 'v2_server'  AS tbl, COUNT(*) AS cnt FROM v2_server  UNION ALL
SELECT 'v2_payment' AS tbl, COUNT(*) AS cnt FROM v2_payment UNION ALL
SELECT 'v2_coupon'  AS tbl, COUNT(*) AS cnt FROM v2_coupon;
```

### 1.3 确认 Xboard 版本（新旧架构判断）

```sql
-- 有这张表 → Xboard 新版（2025-01 之后，统一 v2_server 表架构）
SHOW TABLES LIKE 'v2_server';

-- 有这张表 → Xboard 旧版（分类型服务器表架构）
SHOW TABLES LIKE 'v2_server_vmess';

-- 套餐是否已迁移到 prices JSON
SHOW COLUMNS FROM v2_plan LIKE 'prices';
```

---

## 2. 数据库总体差异

| 分类 | Xboard | V2Board | 处理方式 |
|------|--------|---------|----------|
| 服务器存储 | 统一 `v2_server` 表 + `protocol_settings` JSON | 每个协议独立表（`v2_server_vmess` 等） | 拆分转换 |
| 套餐价格存储 | `prices` JSON 列（元，如 `{"monthly":30.00}`） | 独立价格列（分，如 `month_price=3000`） | × 100 转换 |
| 订单周期字段 | 新命名（`monthly`、`quarterly` 等） | 旧命名（`month_price`、`quarter_price` 等） | 字段重命名 |
| 系统配置存储 | `v2_settings` 数据库表 | `config/v2board.php` 文件 | 读出后写入文件 |
| 用户附加字段 | `device_limit`、`online_count`、`next_reset_at` 等 | `device_limit`（有）、其余无 | 过滤多余字段 |
| V2Board 独有字段 | 无 `auto_renewal` | `auto_renewal` | 默认补 0 |
| 礼品卡系统 | 3 张独立表（模板/兑换码/使用记录） | 1 张简单 `v2_giftcard` 表 | 结构不兼容，需手动处理 |
| 插件系统 | `v2_plugins` 表 | 无 | 跳过 |
| 流量重置日志 | `v2_traffic_reset_logs` | 无 | 跳过 |
| 订阅模板配置 | 存在 `v2_settings` 中 | 存在 `config/v2board.php` 中 | 手动迁移 |

---

## 3. 各表差异详解与手动迁移

### 3.1 v2_user（用户表）

#### 字段差异

| 字段 | Xboard | V2Board | 说明 |
|------|--------|---------|------|
| `auto_renewal` | ❌ 无 | ✅ 有 | 迁移时默认填 `0` |
| `device_limit` | ✅ 有（`int`） | ✅ 有（`int`） | 直接复制 |
| `online_count` | ✅ 有 | ❌ 无 | 迁移时跳过 |
| `last_online_at` | ✅ 有 | ❌ 无 | 迁移时跳过 |
| `next_reset_at` | ✅ 有 | ❌ 无 | 迁移时跳过 |
| `last_reset_at` | ✅ 有 | ❌ 无 | 迁移时跳过 |
| `reset_count` | ✅ 有 | ❌ 无 | 迁移时跳过 |
| 其余字段 | 相同 | 相同 | 直接复制 |

#### 手动迁移 SQL

```sql
-- 在 V2Board 目标库执行
-- 第一步：清空目标表（保留结构）
TRUNCATE TABLE v2_user;

-- 第二步：从 Xboard 库导入（替换 xboard_db 为实际库名）
INSERT INTO v2board_db.v2_user
  (id, invite_user_id, telegram_id, email, password, password_algo,
   password_salt, balance, discount, commission_type, commission_rate,
   commission_balance, t, u, d, transfer_enable, device_limit, banned,
   is_admin, last_login_at, is_staff, last_login_ip, uuid, group_id,
   plan_id, speed_limit, auto_renewal, remind_expire, remind_traffic,
   token, expired_at, remarks, created_at, updated_at)
SELECT
  id, invite_user_id, telegram_id, email, password, password_algo,
  password_salt, balance, discount, commission_type, commission_rate,
  commission_balance, t, u, d, transfer_enable,
  device_limit,           -- Xboard 有此字段，直接复制
  banned, is_admin, last_login_at, is_staff, last_login_ip,
  uuid, group_id, plan_id, speed_limit,
  0 AS auto_renewal,       -- V2Board 独有字段，补默认值
  remind_expire, remind_traffic, token, expired_at, remarks,
  created_at, updated_at
FROM xboard_db.v2_user;
```

---

### 3.2 v2_plan（套餐表）

#### 字段差异

Xboard 在 2025-01-04 的迁移中将价格从独立列改为 JSON，同时字段做了多项变动：

| 字段 | Xboard | V2Board | 说明 |
|------|--------|---------|------|
| `prices` | ✅ JSON（**元**）| ❌ 无 | 需展开到各价格列，并 × 100 |
| `sell` | ✅ 有 | ❌ 无 | 对应 V2Board 的 `show` |
| `month_price` 等8个独立列 | ❌ 已删除 | ✅ 有 | 从 `prices` JSON 还原 |
| `tags` | ✅ JSON | ❌ 无 | 跳过 |
| `device_limit` | ✅ 有 | ✅ 有 | 直接复制 |

#### `prices` JSON 键名与 V2Board 列名对应

| Xboard `prices` 键 | V2Board 列名 | 转换规则 |
|-------------------|-------------|---------|
| `monthly` | `month_price` | × 100（元→分） |
| `quarterly` | `quarter_price` | × 100 |
| `half_yearly` | `half_year_price` | × 100 |
| `yearly` | `year_price` | × 100 |
| `two_yearly` | `two_year_price` | × 100 |
| `three_yearly` | `three_year_price` | × 100 |
| `onetime` | `onetime_price` | × 100 |
| `reset_traffic` | `reset_price` | × 100 |

#### 手动迁移 SQL

```sql
-- Xboard 新版（prices JSON 格式）→ V2Board
TRUNCATE TABLE v2board_db.v2_plan;

INSERT INTO v2board_db.v2_plan
  (id, group_id, transfer_enable, device_limit, name, speed_limit,
   show, sort, renew, content, reset_traffic_method, capacity_limit,
   month_price, quarter_price, half_year_price, year_price,
   two_year_price, three_year_price, onetime_price, reset_price,
   created_at, updated_at)
SELECT
  id,
  group_id,
  transfer_enable,
  device_limit,
  name,
  speed_limit,
  COALESCE(sell, `show`, 0) AS `show`,    -- Xboard 新版用 sell，旧版用 show
  sort,
  renew,
  content,
  reset_traffic_method,
  capacity_limit,
  -- 从 JSON 提取价格并转换单位（元 → 分）
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.monthly'))     * 100) AS month_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.quarterly'))   * 100) AS quarter_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.half_yearly')) * 100) AS half_year_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.yearly'))      * 100) AS year_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.two_yearly'))  * 100) AS two_year_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.three_yearly'))* 100) AS three_year_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.onetime'))     * 100) AS onetime_price,
  ROUND(JSON_UNQUOTE(JSON_EXTRACT(prices, '$.reset_traffic'))* 100) AS reset_price,
  created_at,
  updated_at
FROM xboard_db.v2_plan;

-- NULL 处理（JSON 中没有的键会返回 NULL，直接保留 NULL 即可）
```

> **如果 Xboard 数据库是旧版（仍有独立价格列）**，直接 `INSERT INTO ... SELECT` 复制即可，无需 JSON 转换。

---

### 3.3 v2_order（订单表）

#### 字段差异

只有 `period` 字段的值需要重命名，其余字段完全兼容。

| Xboard `period` 值 | V2Board `period` 值 |
|--------------------|---------------------|
| `monthly` | `month_price` |
| `quarterly` | `quarter_price` |
| `half_yearly` | `half_year_price` |
| `yearly` | `year_price` |
| `two_yearly` | `two_year_price` |
| `three_yearly` | `three_year_price` |
| `onetime` | `onetime_price` |
| `reset_traffic` | `reset_price` |

#### 手动迁移 SQL

```sql
-- 第一步：复制所有订单
TRUNCATE TABLE v2board_db.v2_order;

INSERT INTO v2board_db.v2_order SELECT * FROM xboard_db.v2_order;

-- 第二步：在目标库中批量重命名 period 字段
UPDATE v2board_db.v2_order SET period = 'month_price'     WHERE period = 'monthly';
UPDATE v2board_db.v2_order SET period = 'quarter_price'   WHERE period = 'quarterly';
UPDATE v2board_db.v2_order SET period = 'half_year_price' WHERE period = 'half_yearly';
UPDATE v2board_db.v2_order SET period = 'year_price'      WHERE period = 'yearly';
UPDATE v2board_db.v2_order SET period = 'two_year_price'  WHERE period = 'two_yearly';
UPDATE v2board_db.v2_order SET period = 'three_year_price'WHERE period = 'three_yearly';
UPDATE v2board_db.v2_order SET period = 'onetime_price'   WHERE period = 'onetime';
UPDATE v2board_db.v2_order SET period = 'reset_price'     WHERE period = 'reset_traffic';
```

---

### 3.4 服务器节点表

这是两者之间**差异最大**的部分。

#### 架构对比

| | Xboard（新版） | V2Board |
|--|----------------|---------|
| 存储方式 | 单张 `v2_server` 表 | 按协议分表 |
| 协议识别 | `type` 字段（字符串） | 表名区分 |
| 协议参数 | `protocol_settings` JSON | 展开为各个独立列 |
| 支持协议 | vmess/vless/trojan/ss/hysteria/tuic/anytls + socks/naive/http/mieru | vmess/vless/trojan/ss/hysteria/tuic/anytls（+ v2node） |
| 时间戳格式 | `datetime`（`2025-01-01 12:00:00`） | `int`（Unix 时间戳） |
| group_id 格式 | `group_ids` JSON 数组（`[1,2,3]`） | `group_id` JSON 字符串（`[1,2,3]`） |

#### 各协议的 `protocol_settings` 字段映射

**VMess → v2_server_vmess**

| `protocol_settings` 键 | V2Board 列名 |
|------------------------|-------------|
| `tls` | `tls` |
| `network` | `network` |
| `network_settings` | `networkSettings`（JSON字符串） |
| `tls_settings` | `tlsSettings`（JSON字符串） |

**VLESS → v2_server_vless**

| `protocol_settings` 键 | V2Board 列名 |
|------------------------|-------------|
| `tls` | `tls` |
| `tls_settings` + `reality_settings` | `tls_settings`（合并后 JSON） |
| `flow` | `flow` |
| `network` | `network` |
| `network_settings` | `network_settings`（JSON字符串） |

> **Reality 特殊处理**：Xboard 将 Reality 参数拆成独立的 `reality_settings` 子对象，V2Board 将 Reality 的 `public_key`、`private_key`、`short_id`、`server_name`、`server_port` 合并进 `tls_settings` 中存储。

**Trojan → v2_server_trojan**

| `protocol_settings` 键 | V2Board 列名 |
|------------------------|-------------|
| `allow_insecure` | `allow_insecure` |
| `server_name` | `server_name` |
| `network` | `network` |
| `network_settings` | `network_settings`（JSON字符串） |

**Shadowsocks → v2_server_shadowsocks**

| `protocol_settings` 键 | V2Board 列名 |
|------------------------|-------------|
| `cipher` | `cipher` |
| `obfs` | `obfs` |
| `obfs_settings` | `obfs_settings`（JSON字符串） |

**Hysteria → v2_server_hysteria**

| `protocol_settings` 键路径 | V2Board 列名 |
|---------------------------|-------------|
| `version` | `version` |
| `bandwidth.up` | `up_mbps` |
| `bandwidth.down` | `down_mbps` |
| `obfs.type`（当 `obfs.open=true`） | `obfs` |
| `obfs.password`（当 `obfs.open=true`） | `obfs_password` |
| `tls.server_name` | `server_name` |
| `tls.allow_insecure` | `insecure` |

**TUIC → v2_server_tuic**

| `protocol_settings` 键 | V2Board 列名 |
|------------------------|-------------|
| `tls.server_name` | `server_name` |
| `tls.allow_insecure` | `insecure` |
| `udp_relay_mode` | `udp_relay_mode` |
| `congestion_control` | `congestion_control` |
| —（无此字段） | `disable_sni`（默认 0） |
| —（无此字段） | `zero_rtt_handshake`（默认 0） |

**AnyTLS → v2_server_anytls**

| `protocol_settings` 键 | V2Board 列名 |
|------------------------|-------------|
| `tls.server_name` | `server_name` |
| `tls.allow_insecure` | `insecure` |
| `padding_scheme` | `padding_scheme`（JSON字符串） |

#### 手动迁移 SQL（以 VMess 为例）

```sql
-- 清空目标表
TRUNCATE TABLE v2board_db.v2_server_vmess;

-- 从 Xboard 统一表拆分迁移 VMess 节点
INSERT INTO v2board_db.v2_server_vmess
  (group_id, route_id, name, parent_id, host, port, server_port,
   tls, tags, rate, network, networkSettings, tlsSettings,
   show, sort, created_at, updated_at)
SELECT
  group_ids                                                   AS group_id,
  route_ids                                                   AS route_id,
  name,
  NULL                                                        AS parent_id,  -- 第一轮先置 NULL
  host,
  port,
  server_port,
  CAST(JSON_UNQUOTE(JSON_EXTRACT(protocol_settings, '$.tls'))          AS UNSIGNED),
  tags,
  rate,
  JSON_UNQUOTE(JSON_EXTRACT(protocol_settings, '$.network'))           AS network,
  JSON_EXTRACT(protocol_settings, '$.network_settings')                AS networkSettings,
  JSON_EXTRACT(protocol_settings, '$.tls_settings')                    AS tlsSettings,
  `show`,
  sort,
  UNIX_TIMESTAMP(created_at),   -- datetime → Unix 时间戳
  UNIX_TIMESTAMP(updated_at)
FROM xboard_db.v2_server
WHERE type = 'vmess';

-- 第二轮：修正 parent_id（用于中转/父节点引用）
-- 注意：Xboard 的 parent_id 引用的是 v2_server.id，需要映射到新表的 id
-- 如果你的节点没有父子关系，可跳过此步
```

> 其余协议（VLESS、Trojan、Shadowsocks、Hysteria、TUIC、AnyTLS）按同样思路编写，  
> 使用自动化脚本可通过 `bash scripts/migrate-xboard.sh` 一键完成。

---

### 3.5 v2_server_group / v2_server_route

两者结构完全相同，直接复制：

```sql
TRUNCATE TABLE v2board_db.v2_server_group;
INSERT INTO v2board_db.v2_server_group SELECT * FROM xboard_db.v2_server_group;

TRUNCATE TABLE v2board_db.v2_server_route;
INSERT INTO v2board_db.v2_server_route SELECT * FROM xboard_db.v2_server_route;
```

---

### 3.6 其余兼容表（直接复制）

下列表在两个系统中结构完全兼容，直接 `INSERT INTO ... SELECT *` 即可：

| 表名 | 说明 | 注意事项 |
|------|------|----------|
| `v2_coupon` | 优惠券 | 无差异 |
| `v2_invite_code` | 邀请码 | 无差异 |
| `v2_knowledge` | 知识库文章 | 无差异 |
| `v2_notice` | 公告 | Xboard 有 `sort` 列，V2Board 无；迁移时跳过该列 |
| `v2_ticket` | 工单 | 无差异 |
| `v2_ticket_message` | 工单消息 | 无差异 |
| `v2_commission_log` | 佣金日志 | 无差异 |
| `v2_mail_log` | 邮件日志 | 无差异 |
| `v2_payment` | 支付方式 | 无差异（含加密的 config JSON） |
| `v2_stat` | 统计汇总 | 无差异 |
| `v2_stat_server` | 节点流量统计 | 无差异 |
| `v2_stat_user` | 用户流量统计 | 无差异 |

```sql
-- 以 v2_coupon 为例
TRUNCATE TABLE v2board_db.v2_coupon;
INSERT INTO v2board_db.v2_coupon SELECT * FROM xboard_db.v2_coupon;

-- v2_notice 需跳过 sort 列
TRUNCATE TABLE v2board_db.v2_notice;
INSERT INTO v2board_db.v2_notice
  (id, title, content, `show`, img_url, tags, created_at, updated_at)
SELECT
  id, title, content, `show`, img_url, tags, created_at, updated_at
FROM xboard_db.v2_notice;
```

---

## 4. 系统配置迁移（v2_settings）

### 4.1 存储机制差异

| | Xboard | V2Board |
|--|--------|---------|
| 存储位置 | MySQL `v2_settings` 表（KV格式） | `config/v2board.php` 文件（PHP数组） |
| 读取方式 | `admin_setting('key')` 函数查表 | `config('v2board.key')` 读PHP配置 |
| 更新方式 | 后台保存 → 写入数据库 | 后台保存 → 重写PHP文件 + `config:cache` |

### 4.2 查询 Xboard 当前所有配置

```sql
-- 在 Xboard 库执行，查看所有当前配置项
SELECT name, value, `group` FROM v2_settings ORDER BY `group`, name;
```

### 4.3 配置键映射表

Xboard 与 V2Board 的配置键名 **绝大多数完全相同**，以下列出所有有效配置及其说明：

#### 站点基础（site 分组）

| 配置键 | 说明 | V2Board 默认值 |
|--------|------|----------------|
| `app_name` | 站点名称 | `V2Board` |
| `app_description` | 站点描述 | `V2Board is best!` |
| `app_url` | 站点地址（含协议，如 `https://example.com`） | 空 |
| `subscribe_url` | 订阅域名（为空则使用 app_url） | 空 |
| `subscribe_path` | 订阅路径（以 `/` 开头） | 空 |
| `logo` | Logo URL | 空 |
| `force_https` | 强制 HTTPS（0/1） | `0` |
| `stop_register` | 关闭注册（0/1） | `0` |
| `try_out_plan_id` | 试用套餐 ID | `0` |
| `try_out_hour` | 试用时长（小时） | `1` |
| `tos_url` | 服务条款 URL | 空 |
| `currency` | 货币代码 | `CNY` |
| `currency_symbol` | 货币符号 | `¥` |

#### 订阅设置（subscribe 分组）

| 配置键 | 说明 | V2Board 默认值 |
|--------|------|----------------|
| `plan_change_enable` | 允许升降级（0/1） | `1` |
| `reset_traffic_method` | 流量重置方式（0-4） | `0` |
| `surplus_enable` | 升级折抵剩余（0/1） | `1` |
| `new_order_event_id` | 新购通知事件 | `0` |
| `renew_order_event_id` | 续费通知事件 | `0` |
| `change_order_event_id` | 变更通知事件 | `0` |
| `show_info_to_server_enable` | 节点展示用户信息（0/1） | `0` |
| `default_remind_expire` | 默认开启到期提醒（0/1） | `1` |
| `default_remind_traffic` | 默认开启流量提醒（0/1） | `1` |

> ⚠️ **注意**：Xboard 有 `show_protocol_to_server_enable`，V2Board 中对应 `show_subscribe_method`。

#### 节点设置（server 分组）

| 配置键 | 说明 | V2Board 默认值 |
|--------|------|----------------|
| `server_token` | 节点通信密钥（>16位） | 空 |
| `server_pull_interval` | 节点拉取间隔（秒） | `60` |
| `server_push_interval` | 节点上报间隔（秒） | `60` |
| `device_limit_mode` | 设备限制模式（0/1） | `0` |

> V2Board 独有：`server_api_url`、`server_node_report_min_traffic`、`server_device_online_min_traffic`

#### 邮件设置（email 分组）

| 配置键 | 说明 |
|--------|------|
| `email_host` | SMTP 服务器地址 |
| `email_port` | SMTP 端口 |
| `email_username` | SMTP 用户名 |
| `email_password` | SMTP 密码 |
| `email_encryption` | 加密方式（ssl/tls/空） |
| `email_from_address` | 发件人地址 |
| `email_template` | 邮件模板 |

#### 安全设置（safe 分组）

| 配置键 | 说明 | V2Board 默认值 |
|--------|------|----------------|
| `secure_path` | 后台访问路径（8位以上，仅字母数字） | 随机值 |
| `email_verify` | 邮箱验证码（0/1） | `0` |
| `safe_mode_enable` | 安全模式（0/1） | `0` |
| `email_whitelist_enable` | 邮箱白名单（0/1） | `0` |
| `email_whitelist_suffix` | 邮箱后缀白名单（JSON数组） | 默认列表 |
| `email_gmail_limit_enable` | 限制 Gmail 别名（0/1） | `0` |
| `recaptcha_enable` | 开启验证码（0/1） | `0` |
| `recaptcha_key` | reCAPTCHA 服务端密钥 | 空 |
| `recaptcha_site_key` | reCAPTCHA 站点密钥 | 空 |
| `register_limit_by_ip_enable` | IP 注册限制（0/1） | `0` |
| `register_limit_count` | IP 注册限制次数 | `3` |
| `register_limit_expire` | IP 限制时长（分钟） | `60` |
| `password_limit_enable` | 密码重试限制（0/1） | `1` |
| `password_limit_count` | 密码重试次数 | `5` |
| `password_limit_expire` | 密码限制时长（分钟） | `60` |

> ⚠️ **注意**：Xboard 中 `captcha_enable`/`captcha_type` 对应 V2Board 的 `recaptcha_enable`（仅支持 reCAPTCHA）。Xboard 支持 Turnstile 等验证码，V2Board 暂不支持，迁移后需选择 reCAPTCHA 重新配置。

#### 邀请/佣金（invite 分组）

| 配置键 | 说明 | V2Board 默认值 |
|--------|------|----------------|
| `invite_force` | 强制邀请码注册（0/1） | `0` |
| `invite_commission` | 邀请佣金比例（%） | `10` |
| `invite_gen_limit` | 每人生成邀请码上限 | `5` |
| `invite_never_expire` | 邀请码永不过期（0/1） | `0` |
| `commission_first_time_enable` | 仅首次付款结算佣金（0/1） | `1` |
| `commission_auto_check_enable` | 自动审核佣金（0/1） | `1` |
| `commission_withdraw_limit` | 最低提现金额（分） | `100` |
| `commission_withdraw_method` | 可提现渠道（JSON数组） | 默认列表 |
| `withdraw_close_enable` | 关闭提现（0/1） | `0` |
| `commission_distribution_enable` | 多级分销（0/1） | `0` |
| `commission_distribution_l1` | 一级佣金比例（%） | 空 |
| `commission_distribution_l2` | 二级佣金比例（%） | 空 |
| `commission_distribution_l3` | 三级佣金比例（%） | 空 |

#### Telegram（telegram 分组）

| 配置键 | 说明 |
|--------|------|
| `telegram_bot_enable` | 启用 Telegram 机器人（0/1） |
| `telegram_bot_token` | Bot Token |
| `telegram_discuss_link` | 群组链接 |

#### 客户端下载（app 分组）

| 配置键 | 说明 |
|--------|------|
| `windows_version` | Windows 客户端版本号 |
| `windows_download_url` | Windows 下载链接 |
| `macos_version` | macOS 客户端版本号 |
| `macos_download_url` | macOS 下载链接 |
| `android_version` | Android 客户端版本号 |
| `android_download_url` | Android 下载链接 |

### 4.4 从 Xboard 导出配置

```sql
-- 导出所有配置（在 Xboard 数据库执行）
SELECT name, COALESCE(value, '') AS value FROM v2_settings ORDER BY name;
```

### 4.5 写入 V2Board 配置的方法

**方法一：通过后台界面手动逐项填写（推荐）**

登录 V2Board 后台，在「系统设置」中逐一填写对应配置项。适合配置项不多的情况。

**方法二：通过 artisan 命令批量写入**

```bash
# 进入 V2Board 容器
docker exec -it v2board bash

# 使用 artisan tinker 批量写入
php artisan tinker
```

在 tinker 中执行（将 `xxx` 替换为实际值）：

```php
// 读取当前配置
$config = config('v2board');

// 填入从 Xboard 取出的值
$config['app_name']           = 'YourSiteName';
$config['app_url']            = 'https://your-domain.com';
$config['server_token']       = 'your_server_token';
$config['email_host']         = 'smtp.example.com';
$config['email_port']         = '465';
$config['email_username']     = 'noreply@example.com';
$config['email_password']     = 'your_smtp_password';
$config['email_encryption']   = 'ssl';
$config['email_from_address'] = 'noreply@example.com';
$config['telegram_bot_token'] = 'your_bot_token';
$config['invite_commission']  = 10;
// ... 其他配置项

// 写入配置文件
$path = config_path('v2board.php');
file_put_contents($path, '<?php return ' . var_export($config, true) . ';');
echo "配置已写入\n";
exit;
```

然后重建缓存：

```bash
php artisan config:cache
php artisan route:cache
```

**方法三：直接编辑配置文件（最快）**

```bash
# 进入容器
docker exec -it v2board bash
# 编辑配置（V2Board 配置在此文件，运行时生成于 bootstrap/cache）
cat /var/www/v2board/config/v2board.php
# 用编辑器直接修改 key => value
```

### 4.6 Xboard 独有配置（V2Board 无对应项）

| Xboard 配置键 | 说明 | V2Board 处理方式 |
|--------------|------|-----------------|
| `captcha_type` | 验证码类型（recaptcha/turnstile） | 仅支持 recaptcha，turnstile 不可用 |
| `turnstile_secret_key` | Cloudflare Turnstile 密钥 | 无效，可忽略 |
| `turnstile_site_key` | Cloudflare Turnstile 站点密钥 | 无效，可忽略 |
| `recaptcha_v3_secret_key` | reCAPTCHA v3 密钥 | 无对应配置 |
| `recaptcha_v3_site_key` | reCAPTCHA v3 站点密钥 | 无对应配置 |
| `recaptcha_v3_score_threshold` | v3 分数阈值 | 无对应配置 |
| `remind_mail_enable` | 提醒邮件总开关 | 无此总开关，由用户设置控制 |
| `subscribe_path` | 订阅路径 | V2Board 有相同配置，可直接迁移 |
| `show_protocol_to_server_enable` | 节点展示协议 | V2Board 对应 `show_subscribe_method` |
| `subscribe_template_*` | 订阅模板（Clash/SingBox等） | V2Board 暂无订阅模板自定义，忽略 |

---

## 5. 不可自动迁移的内容

### 5.1 礼品卡系统

Xboard 礼品卡系统有 3 张表，结构比 V2Board 复杂得多：

| Xboard 表 | 说明 | V2Board 对应 |
|-----------|------|-------------|
| `v2_gift_card_template` | 礼品卡模板（支持余额/有效期/流量/套餐/组合/盲盒等类型） | 无直接对应 |
| `v2_gift_card_code` | 礼品卡兑换码（含状态/过期时间/使用次数） | `v2_giftcard`（简单版） |
| `v2_gift_card_usage` | 使用记录 | 无 |

**迁移建议**：
- 未使用的兑换码可手动在 V2Board 后台礼品卡功能中重新创建等价的礼品卡
- 历史使用记录建议保留在 Xboard 数据库归档，无需迁移

### 5.2 插件系统（v2_plugins）

Xboard 支持插件，V2Board 无此功能，直接忽略。

### 5.3 流量重置日志（v2_traffic_reset_logs）

V2Board 无此表，不影响运营，直接忽略。

### 5.4 用户在线状态字段

以下字段 V2Board 无对应，迁移后这些功能将不可用：

- `device_limit`：V2Board 有此字段，可以迁移
- `online_count`：实时在线设备计数，V2Board 无
- `last_online_at`：最后在线时间，V2Board 无

### 5.5 v2node 节点（V2Board 独有）

V2Board 有 `v2_server_v2node` 表，Xboard 不支持 v2node 协议。  
如果你在 Xboard 使用的是 V2bX 节点端，则迁移到 V2Board 后节点端也需换用 V2bX。

---

## 6. 迁移后收尾操作

### 6.1 重建应用缓存

```bash
docker exec v2board bash -c "
  cd /var/www/v2board
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
"
```

### 6.2 清空 Redis 缓存

```bash
docker exec v2board php artisan cache:clear
```

### 6.3 验证检查清单

```bash
# 检查用户数是否一致
docker exec v2board php artisan tinker --execute="echo \App\Models\User::count();"

# 检查节点是否正常加载
docker exec v2board php artisan tinker --execute="
  \$counts = [];
  foreach (['Vmess','Vless','Trojan','Shadowsocks','Hysteria','Tuic','Anytls'] as \$t) {
    \$model = 'App\\\Models\\\Server'.\$t;
    if (class_exists(\$model)) \$counts[\$t] = \$model::count();
  }
  print_r(\$counts);
"

# 检查套餐是否有价格
docker exec v2board php artisan tinker --execute="
  \App\Models\Plan::select('id','name','month_price','year_price')->get()->each(fn(\$p)=>print_r(\$p->toArray()));
"
```

### 6.4 登录验证

1. 访问 V2Board 后台（`https://your-domain.com/<secure_path>`）
2. 使用 Xboard 的管理员账号密码登录（密码哈希格式相同，可直接使用）
3. 进入「系统设置」补全 Xboard 独有配置项
4. 检查节点列表，确认协议类型和参数正确
5. 检查套餐列表，确认价格显示正确
6. 测试订阅链接是否正常生成

---

## 7. 回滚方法

如果迁移出现问题，使用备份恢复：

```bash
# 恢复 V2Board 数据库
zcat v2board_backup_YYYYMMDD.sql.gz | \
  docker exec -i 1Panel-mysql-nRCz \
  mysql -u sktv2board -p4BFZSc2EXnRtcRrT sktv2board

# 或使用明文备份
mysql -h <host> -u <user> -p <v2board_db> < v2board_backup_YYYYMMDD.sql

# 重建缓存
docker exec v2board php artisan config:cache
docker exec v2board php artisan route:cache
```

---

## 附录：快捷参考

### 一键自动迁移

```bash
bash /opt/v2board/scripts/migrate-xboard.sh \
  --src-host=<Xboard数据库IP> \
  --src-db=<Xboard库名>       \
  --src-user=<用户名>          \
  --src-pass=<密码>
```

### 常见问题

**Q：迁移后用户无法登录？**  
A：两个系统使用相同的密码哈希（bcrypt），无需重置密码。如仍无法登录，检查 `APP_KEY` 是否与 Xboard 保持一致（JWT 令牌由 APP_KEY 签名）。

**Q：节点端无法上报流量？**  
A：检查 `server_token` 是否已正确迁移到 V2Board 配置，并确认节点端使用的 API 地址已更新。

**Q：订阅链接变化导致用户客户端失效？**  
A：如果域名相同且 `subscribe_path` 配置一致，订阅链接不会变化。如果更换了域名，需要通知用户更新订阅。

**Q：套餐价格显示异常（价格是实际的100倍）？**  
A：说明 Xboard `prices` JSON 已是元，但迁移时误当分处理。检查迁移脚本是否执行了 `× 100` 转换，或手动执行：
```sql
UPDATE v2_plan SET month_price = month_price / 100 WHERE month_price > 100000;
```
