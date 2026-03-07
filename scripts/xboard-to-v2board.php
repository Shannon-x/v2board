#!/usr/bin/env php
<?php
/**
 * Xboard → V2Board 数据库迁移脚本
 *
 * 使用方式：
 *   php xboard-to-v2board.php [选项]
 *
 * 必填选项：
 *   --src-host=HOST      Xboard MySQL 主机
 *   --src-db=DB          Xboard 数据库名
 *   --src-user=USER      Xboard 数据库用户名
 *   --src-pass=PASS      Xboard 数据库密码
 *
 * 可选选项：
 *   --src-port=PORT      Xboard MySQL 端口（默认 3306）
 *   --dst-host=HOST      V2Board MySQL 主机（默认读 .env）
 *   --dst-db=DB          V2Board 数据库名（默认读 .env）
 *   --dst-user=USER      V2Board 数据库用户名（默认读 .env）
 *   --dst-pass=PASS      V2Board 数据库密码（默认读 .env）
 *   --dst-port=PORT      V2Board MySQL 端口（默认 3306）
 *   --dry-run            仅预览，不写入目标库
 *   --force              跳过二次确认
 *   --no-truncate        不清空目标表（跳过已存在记录）
 *   --tables=t1,t2       只迁移指定表（逗号分隔，默认全部）
 */

define('SCRIPT_VERSION', '1.2.0');

// 支持从容器内 /tmp 运行（migrate-xboard.sh 会 docker cp 到 /tmp）
// 也支持直接在项目目录运行
function find_env_file(): string {
    $candidates = [
        __DIR__ . '/../data/.env',                // 项目目录运行
        '/var/www/v2board/data/.env',              // 容器内
        '/var/www/v2board/.env',                   // 兜底
    ];
    foreach ($candidates as $f) {
        if (file_exists($f)) return $f;
    }
    return $candidates[0]; // 返回首选（可能不存在，后续容错处理）
}
define('ENV_FILE', find_env_file());

// ─── ANSI 颜色 ──────────────────────────────────────────────────────────────
function c(string $text, string $color): string {
    $colors = ['red'=>'31','green'=>'32','yellow'=>'33','blue'=>'34','cyan'=>'36','bold'=>'1','reset'=>'0'];
    return "\033[{$colors[$color]}m{$text}\033[0m";
}
function log_info(string $msg)    { echo c('  ✓ ', 'green')  . $msg . "\n"; }
function log_warn(string $msg)    { echo c('  ! ', 'yellow') . $msg . "\n"; }
function log_error(string $msg)   { echo c('  ✗ ', 'red')    . $msg . "\n"; }
function log_step(string $msg)    { echo c("\n▶ ", 'cyan')    . c($msg, 'bold') . "\n"; }
function log_skip(string $msg)    { echo c('  - ', 'blue')   . $msg . "\n"; }

// ─── 参数解析 ────────────────────────────────────────────────────────────────
function parse_args(array $argv): array {
    $opts = [
        'src-host' => '', 'src-port' => '3306', 'src-db' => '',
        'src-user' => '', 'src-pass' => '',
        'dst-host' => '', 'dst-port' => '3306', 'dst-db' => '',
        'dst-user' => '', 'dst-pass' => '',
        'dry-run'  => false, 'force' => false, 'no-truncate' => false,
        'tables'   => '',
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run')     { $opts['dry-run']     = true; continue; }
        if ($arg === '--force')       { $opts['force']       = true; continue; }
        if ($arg === '--no-truncate') { $opts['no-truncate'] = true; continue; }
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $opts[$m[1]] = $m[2];
        }
    }
    return $opts;
}

// ─── 读取 .env 文件 ──────────────────────────────────────────────────────────
function read_env(string $path): array {
    if (!file_exists($path)) return [];
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
    return $env;
}

// ─── PDO 连接 ────────────────────────────────────────────────────────────────
function pdo_connect(string $host, string $port, string $db, string $user, string $pass, string $label): PDO {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
        log_info("连接成功：{$label} ({$host}:{$port}/{$db})");
        return $pdo;
    } catch (PDOException $e) {
        log_error("连接失败：{$label} — " . $e->getMessage());
        exit(1);
    }
}

// ─── 辅助：检查列是否存在 ────────────────────────────────────────────────────
function has_column(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $stmt->rowCount() > 0;
}

function has_table(PDO $pdo, string $table): bool {
    $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
    return $stmt->rowCount() > 0;
}

// ─── 辅助：JSON 解析（容错）─────────────────────────────────────────────────
function safe_json(mixed $v, mixed $default = null): mixed {
    if ($v === null || $v === '') return $default;
    $decoded = json_decode($v, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $default;
}

// ─── 辅助：将 JSON 数组字符串转 CSV（V2Board group_id 格式）────────────────
// 实际上 V2Board per-type 表也接受 JSON 数组格式，直接原样保留即可
function normalize_ids(?string $v): string {
    if ($v === null || $v === '') return '[]';
    // 如果已经是 JSON 格式直接返回
    if (str_starts_with(ltrim($v), '[')) return $v;
    // 否则当作 CSV 转为 JSON 数组
    $ids = array_filter(array_map('intval', explode(',', $v)));
    return json_encode(array_values($ids));
}

// ─── 简单表直接复制（字段完全兼容）─────────────────────────────────────────
function migrate_simple(PDO $src, PDO $dst, string $table, array $opts,
                         array &$stats, array $skip_cols = [], array $defaults = []): void {
    log_step("迁移 {$table}");

    if (!has_table($src, $table)) { log_skip("源库无此表，跳过"); return; }
    if (!has_table($dst, $table)) { log_skip("目标库无此表，跳过"); return; }

    $rows = $src->query("SELECT * FROM `{$table}`")->fetchAll();
    $count = count($rows);
    log_info("源库记录数：{$count}");

    if ($count === 0) { log_skip("无数据，跳过"); return; }

    if (!$opts['dry-run'] && !$opts['no-truncate']) {
        $dst->exec("SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `{$table}`; SET FOREIGN_KEY_CHECKS=1;");
    }

    // 获取目标表字段列表
    $dst_cols = $dst->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);

    $inserted = 0;
    foreach ($rows as $row) {
        // 过滤不在目标表的列
        $data = [];
        foreach ($row as $k => $v) {
            if (in_array($k, $skip_cols)) continue;
            if (in_array($k, $dst_cols)) $data[$k] = $v;
        }
        // 补充默认值
        foreach ($defaults as $k => $v) {
            if (!isset($data[$k]) && in_array($k, $dst_cols)) $data[$k] = $v;
        }

        if ($opts['dry-run']) { $inserted++; continue; }

        $cols  = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs   = implode(',', array_fill(0, count($data), '?'));
        $mode  = $opts['no-truncate'] ? 'INSERT IGNORE' : 'INSERT';
        $stmt  = $dst->prepare("{$mode} INTO `{$table}` ({$cols}) VALUES ({$phs})");
        $stmt->execute(array_values($data));
        $inserted++;
    }

    $stats[$table] = $inserted;
    $tag = $opts['dry-run'] ? c('[dry-run]', 'yellow') : '';
    log_info("已处理 {$inserted} / {$count} 条记录 {$tag}");
}

// ─── v2_user ─────────────────────────────────────────────────────────────────
function migrate_users(PDO $src, PDO $dst, array $opts, array &$stats): void {
    log_step("迁移 v2_user（用户表）");

    $rows  = $src->query("SELECT * FROM `v2_user`")->fetchAll();
    $count = count($rows);
    log_info("源库用户数：{$count}");

    // Xboard 额外字段（V2Board 无）
    $xboard_only = ['online_count', 'last_online_at', 'next_reset_at', 'last_reset_at', 'reset_count'];

    if (!$opts['dry-run'] && !$opts['no-truncate']) {
        $dst->exec("SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `v2_user`; SET FOREIGN_KEY_CHECKS=1;");
    }

    $dst_cols = $dst->query("SHOW COLUMNS FROM `v2_user`")->fetchAll(PDO::FETCH_COLUMN);

    $inserted = $skipped = 0;
    foreach ($rows as $row) {
        $data = [];
        foreach ($row as $k => $v) {
            if (in_array($k, $xboard_only)) continue;
            if (in_array($k, $dst_cols)) $data[$k] = $v;
        }
        // V2Board 特有字段
        if (in_array('auto_renewal', $dst_cols) && !isset($data['auto_renewal'])) {
            $data['auto_renewal'] = 0;
        }

        if ($opts['dry-run']) { $inserted++; continue; }

        try {
            $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
            $phs  = implode(',', array_fill(0, count($data), '?'));
            $mode = $opts['no-truncate'] ? 'INSERT IGNORE' : 'INSERT';
            $stmt = $dst->prepare("{$mode} INTO `v2_user` ({$cols}) VALUES ({$phs})");
            $stmt->execute(array_values($data));
            $inserted++;
        } catch (PDOException $e) {
            log_warn("用户 {$row['email']} 跳过：" . $e->getMessage());
            $skipped++;
        }
    }

    $stats['v2_user'] = $inserted;
    log_info("已迁移 {$inserted} 用户，跳过 {$skipped}" . ($opts['dry-run'] ? c(' [dry-run]', 'yellow') : ''));
}

// ─── v2_plan ─────────────────────────────────────────────────────────────────
function migrate_plans(PDO $src, PDO $dst, array $opts, array &$stats): void {
    log_step("迁移 v2_plan（套餐表）");

    // 判断 Xboard 使用的是新格式（prices JSON）还是旧格式（独立列）
    $is_new_format = has_column($src, 'v2_plan', 'prices');
    log_info($is_new_format ? "检测到 Xboard 新格式（prices JSON）" : "检测到旧格式（独立价格列）");

    $rows  = $src->query("SELECT * FROM `v2_plan`")->fetchAll();
    $count = count($rows);
    log_info("源库套餐数：{$count}");

    if (!$opts['dry-run'] && !$opts['no-truncate']) {
        $dst->exec("SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `v2_plan`; SET FOREIGN_KEY_CHECKS=1;");
    }

    // 周期映射：Xboard prices JSON key → V2Board 列名
    $period_map = [
        'monthly'       => 'month_price',
        'quarterly'     => 'quarter_price',
        'half_yearly'   => 'half_year_price',
        'yearly'        => 'year_price',
        'two_yearly'    => 'two_year_price',
        'three_yearly'  => 'three_year_price',
        'onetime'       => 'onetime_price',
        'reset_traffic' => 'reset_price',
    ];

    $inserted = 0;
    foreach ($rows as $row) {
        if ($is_new_format) {
            $prices = safe_json($row['prices'], []);
            $data = [
                'id'                   => $row['id'],
                'group_id'             => $row['group_id'],
                'transfer_enable'      => $row['transfer_enable'],
                'device_limit'         => $row['device_limit'] ?? null,
                'name'                 => $row['name'],
                'speed_limit'          => $row['speed_limit'] ?? null,
                'show'                 => $row['sell'] ?? $row['show'] ?? 0,
                'sort'                 => $row['sort'] ?? null,
                'renew'                => $row['renew'] ?? 1,
                'content'              => $row['content'] ?? null,
                'reset_traffic_method' => $row['reset_traffic_method'] ?? null,
                'capacity_limit'       => $row['capacity_limit'] ?? null,
                'created_at'           => $row['created_at'],
                'updated_at'           => $row['updated_at'],
            ];
            // 将 prices JSON（元）转为独立列（分）
            foreach ($period_map as $json_key => $col) {
                $data[$col] = isset($prices[$json_key]) ? (int)round($prices[$json_key] * 100) : null;
            }
        } else {
            // 旧格式直接复制
            $data = array_intersect_key($row, array_flip([
                'id','group_id','transfer_enable','device_limit','name','speed_limit',
                'show','sort','renew','content','month_price','quarter_price',
                'half_year_price','year_price','two_year_price','three_year_price',
                'onetime_price','reset_price','reset_traffic_method','capacity_limit',
                'created_at','updated_at',
            ]));
        }

        if ($opts['dry-run']) {
            $inserted++;
            continue;
        }

        $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        $mode = $opts['no-truncate'] ? 'INSERT IGNORE' : 'INSERT';
        $stmt = $dst->prepare("{$mode} INTO `v2_plan` ({$cols}) VALUES ({$phs})");
        $stmt->execute(array_values($data));
        $inserted++;
    }

    $stats['v2_plan'] = $inserted;
    log_info("已迁移 {$inserted} / {$count} 套餐" . ($opts['dry-run'] ? c(' [dry-run]', 'yellow') : ''));
}

// ─── v2_order ────────────────────────────────────────────────────────────────
function migrate_orders(PDO $src, PDO $dst, array $opts, array &$stats): void {
    log_step("迁移 v2_order（订单表）");

    $rows  = $src->query("SELECT * FROM `v2_order`")->fetchAll();
    $count = count($rows);
    log_info("源库订单数：{$count}");

    // Xboard 新周期名 → V2Board 旧字段名
    $period_map = [
        'monthly'       => 'month_price',
        'quarterly'     => 'quarter_price',
        'half_yearly'   => 'half_year_price',
        'yearly'        => 'year_price',
        'two_yearly'    => 'two_year_price',
        'three_yearly'  => 'three_year_price',
        'onetime'       => 'onetime_price',
        'reset_traffic' => 'reset_price',
    ];

    if (!$opts['dry-run'] && !$opts['no-truncate']) {
        $dst->exec("SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `v2_order`; SET FOREIGN_KEY_CHECKS=1;");
    }

    $dst_cols = $dst->query("SHOW COLUMNS FROM `v2_order`")->fetchAll(PDO::FETCH_COLUMN);

    $inserted = 0;
    foreach ($rows as $row) {
        // 转换 period 字段
        $period = $period_map[$row['period']] ?? $row['period'];

        $data = [];
        foreach ($row as $k => $v) {
            if (in_array($k, $dst_cols)) $data[$k] = $v;
        }
        $data['period'] = $period;

        if ($opts['dry-run']) { $inserted++; continue; }

        $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        $mode = $opts['no-truncate'] ? 'INSERT IGNORE' : 'INSERT';
        $stmt = $dst->prepare("{$mode} INTO `v2_order` ({$cols}) VALUES ({$phs})");
        $stmt->execute(array_values($data));
        $inserted++;
    }

    $stats['v2_order'] = $inserted;
    log_info("已迁移 {$inserted} / {$count} 订单" . ($opts['dry-run'] ? c(' [dry-run]', 'yellow') : ''));
}

// ─── v2_server（核心：拆分到各类型表）──────────────────────────────────────
function migrate_servers(PDO $src, PDO $dst, array $opts, array &$stats): void {
    log_step("迁移服务器节点（v2_server → 各类型表）");

    // 判断源库是否为 Xboard 统一表格式
    $has_unified = has_table($src, 'v2_server');
    $has_legacy  = has_table($src, 'v2_server_vmess');

    if ($has_unified) {
        log_info("检测到 Xboard 统一 v2_server 表，开始拆分迁移");
        migrate_servers_from_unified($src, $dst, $opts, $stats);
    } elseif ($has_legacy) {
        log_info("检测到旧版分类型表，直接复制");
        migrate_servers_legacy($src, $dst, $opts, $stats);
    } else {
        log_warn("源库无服务器表，跳过");
    }
}

function migrate_servers_from_unified(PDO $src, PDO $dst, array $opts, array &$stats): void {
    $servers = $src->query("SELECT * FROM `v2_server` ORDER BY id")->fetchAll();
    log_info("总节点数：" . count($servers));

    // 类型 → 目标表
    $type_table_map = [
        'vmess'       => 'v2_server_vmess',
        'vless'       => 'v2_server_vless',
        'trojan'      => 'v2_server_trojan',
        'shadowsocks' => 'v2_server_shadowsocks',
        'hysteria'    => 'v2_server_hysteria',
        'tuic'        => 'v2_server_tuic',
        'anytls'      => 'v2_server_anytls',
    ];
    $unsupported = ['socks', 'naive', 'http', 'mieru'];

    // 清空目标表
    if (!$opts['dry-run'] && !$opts['no-truncate']) {
        foreach ($type_table_map as $table) {
            if (has_table($dst, $table)) {
                $dst->exec("SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `{$table}`; SET FOREIGN_KEY_CHECKS=1;");
            }
        }
    }

    // 第一轮：插入所有节点，建立 xboard_id → {type, new_id} 映射
    $id_map = []; // xboard_server.id => ['type' => ..., 'new_id' => ...]

    foreach ($servers as $s) {
        $type     = strtolower($s['type']);
        $settings = safe_json($s['protocol_settings'], []);

        if (in_array($type, $unsupported)) {
            log_skip("跳过不支持的协议类型 {$type}（ID={$s['id']} 名称={$s['name']}）");
            continue;
        }
        if (!isset($type_table_map[$type])) {
            log_skip("未知协议类型 {$type}（ID={$s['id']}），跳过");
            continue;
        }

        $table = $type_table_map[$type];
        if (!has_table($dst, $table)) {
            log_skip("目标表 {$table} 不存在，跳过 {$type} 节点");
            continue;
        }

        // 时间戳转换（Xboard 存 datetime，V2Board 存 unix ts）
        $created_at = is_numeric($s['created_at']) ? (int)$s['created_at'] : strtotime($s['created_at']);
        $updated_at = is_numeric($s['updated_at']) ? (int)$s['updated_at'] : strtotime($s['updated_at']);

        // 公共字段
        $common = [
            'group_id'   => normalize_ids($s['group_ids']),
            'route_id'   => normalize_ids($s['route_ids']),
            'name'       => $s['name'],
            'parent_id'  => null,  // 第一轮先置 NULL，第二轮再补
            'host'       => $s['host'],
            'port'       => $s['port'],
            'server_port'=> (int)$s['server_port'],
            'tags'       => $s['tags'] ?? null,
            'rate'       => (string)$s['rate'],
            'show'       => (int)$s['show'],
            'sort'       => $s['sort'] ?? null,
            'created_at' => $created_at,
            'updated_at' => $updated_at,
        ];

        // 类型特有字段
        $extra = build_server_extra($type, $settings);
        $data  = array_merge($common, $extra);

        // 过滤目标表不存在的列
        $dst_cols = $dst->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $data = array_intersect_key($data, array_flip($dst_cols));

        if ($opts['dry-run']) {
            $id_map[$s['id']] = ['type' => $type, 'new_id' => $s['id']];
            $stats[$table] = ($stats[$table] ?? 0) + 1;
            continue;
        }

        $cols = implode(',', array_map(fn($c) => "`{$c}`", array_keys($data)));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        $mode = $opts['no-truncate'] ? 'INSERT IGNORE' : 'INSERT';
        $stmt = $dst->prepare("{$mode} INTO `{$table}` ({$cols}) VALUES ({$phs})");
        $stmt->execute(array_values($data));
        $new_id = (int)$dst->lastInsertId();
        $id_map[$s['id']] = ['type' => $type, 'new_id' => $new_id];
        $stats[$table] = ($stats[$table] ?? 0) + 1;
    }

    log_info("第一轮插入完成，开始修正 parent_id 引用");

    // 第二轮：修正 parent_id
    if (!$opts['dry-run']) {
        foreach ($servers as $s) {
            if (!$s['parent_id']) continue;
            if (!isset($id_map[$s['id']]) || !isset($id_map[$s['parent_id']])) continue;

            $my   = $id_map[$s['id']];
            $pa   = $id_map[$s['parent_id']];
            $table = $type_table_map[$my['type']] ?? null;
            if (!$table) continue;

            $dst->prepare("UPDATE `{$table}` SET `parent_id`=? WHERE `id`=?")
                ->execute([$pa['new_id'], $my['new_id']]);
        }
        log_info("parent_id 修正完成");
    }

    // 统计
    foreach ($type_table_map as $type => $table) {
        $cnt = $stats[$table] ?? 0;
        if ($cnt > 0) log_info("{$type}: {$cnt} 条");
    }
}

function build_server_extra(string $type, array $s): array {
    return match($type) {
        'vmess' => [
            'tls'            => (int)($s['tls'] ?? 0),
            'network'        => $s['network'] ?? 'tcp',
            'networkSettings'=> isset($s['network_settings']) ? json_encode($s['network_settings']) : null,
            'tlsSettings'    => isset($s['tls_settings'])     ? json_encode($s['tls_settings'])     : null,
        ],
        'vless' => [
            'tls'             => (int)($s['tls'] ?? 0),
            'tls_settings'    => build_vless_tls_settings($s),
            'flow'            => $s['flow'] ?? null,
            'network'         => $s['network'] ?? 'tcp',
            'network_settings'=> isset($s['network_settings']) ? json_encode($s['network_settings']) : null,
            'encryption'      => $s['encryption'] ?? null,
        ],
        'trojan' => [
            'allow_insecure'  => (int)($s['allow_insecure'] ?? 0),
            'server_name'     => $s['server_name'] ?? null,
            'network'         => $s['network'] ?? null,
            'network_settings'=> isset($s['network_settings']) ? json_encode($s['network_settings']) : null,
        ],
        'shadowsocks' => [
            'cipher'        => $s['cipher'] ?? 'aes-256-gcm',
            'obfs'          => $s['obfs'] ?? null,
            'obfs_settings' => isset($s['obfs_settings']) ? json_encode($s['obfs_settings']) : null,
        ],
        'hysteria' => [
            'version'      => (int)($s['version'] ?? 2),
            'up_mbps'      => (int)($s['bandwidth']['up']   ?? 0),
            'down_mbps'    => (int)($s['bandwidth']['down'] ?? 0),
            'obfs'         => ($s['obfs']['open'] ?? false) ? ($s['obfs']['type'] ?? 'salamander') : null,
            'obfs_password'=> ($s['obfs']['open'] ?? false) ? ($s['obfs']['password'] ?? null)     : null,
            'server_name'  => $s['tls']['server_name']   ?? null,
            'insecure'     => (int)($s['tls']['allow_insecure'] ?? 0),
        ],
        'tuic' => [
            'server_name'       => $s['tls']['server_name'] ?? null,
            'insecure'          => (int)($s['tls']['allow_insecure'] ?? 0),
            'disable_sni'       => 0,
            'udp_relay_mode'    => $s['udp_relay_mode'] ?? 'native',
            'zero_rtt_handshake'=> 0,
            'congestion_control'=> $s['congestion_control'] ?? 'cubic',
        ],
        'anytls' => [
            'server_name'   => $s['tls']['server_name'] ?? null,
            'insecure'      => (int)($s['tls']['allow_insecure'] ?? 0),
            'padding_scheme'=> isset($s['padding_scheme']) ? json_encode($s['padding_scheme']) : null,
        ],
        default => [],
    };
}

function build_vless_tls_settings(array $s): ?string {
    $tls = is_array($s['tls_settings'] ?? null) ? $s['tls_settings'] : [];
    if (isset($s['reality_settings']) && is_array($s['reality_settings'])) {
        $r = $s['reality_settings'];
        $tls = array_merge($tls, array_filter([
            'public_key'  => $r['public_key']  ?? null,
            'private_key' => $r['private_key'] ?? null,
            'short_id'    => $r['short_id']    ?? null,
            'server_name' => $r['server_name'] ?? null,
            'server_port' => $r['server_port'] ?? null,
        ]));
    }
    return $tls ? json_encode($tls) : null;
}

// 旧版 Xboard（已有分类型表）直接复制
function migrate_servers_legacy(PDO $src, PDO $dst, array $opts, array &$stats): void {
    $tables = [
        'v2_server_vmess', 'v2_server_vless', 'v2_server_trojan',
        'v2_server_shadowsocks', 'v2_server_hysteria', 'v2_server_tuic', 'v2_server_anytls',
    ];
    foreach ($tables as $t) {
        if (has_table($src, $t) && has_table($dst, $t)) {
            migrate_simple($src, $dst, $t, $opts, $stats);
        }
    }
}

// ─── 主流程 ──────────────────────────────────────────────────────────────────
function main(array $argv): int {
    echo c("\n╔══════════════════════════════════════════════════╗\n", 'cyan');
    echo c("║    Xboard → V2Board 数据库迁移工具 v" . SCRIPT_VERSION . "      ║\n", 'cyan');
    echo c("╚══════════════════════════════════════════════════╝\n\n", 'cyan');

    $opts = parse_args($argv);

    if ($opts['dry-run']) {
        echo c("  ⚠  DRY-RUN 模式：只预览，不写入任何数据\n\n", 'yellow');
    }

    // ── 1. 目标库凭证（优先命令行，回退 .env）
    $env = read_env(ENV_FILE);
    $dst = [
        'host' => $opts['dst-host'] ?: ($env['DB_HOST'] ?? '127.0.0.1'),
        'port' => $opts['dst-port'] ?: ($env['DB_PORT'] ?? '3306'),
        'db'   => $opts['dst-db']   ?: ($env['DB_DATABASE'] ?? ''),
        'user' => $opts['dst-user'] ?: ($env['DB_USERNAME'] ?? ''),
        'pass' => $opts['dst-pass'] ?: ($env['DB_PASSWORD'] ?? ''),
    ];

    // ── 2. 源库凭证（必填）
    $src = [
        'host' => $opts['src-host'],
        'port' => $opts['src-port'],
        'db'   => $opts['src-db'],
        'user' => $opts['src-user'],
        'pass' => $opts['src-pass'],
    ];

    foreach (['host','db','user','pass'] as $k) {
        if (empty($src[$k])) {
            log_error("缺少必填参数：--src-{$k}");
            echo "\n  用法：php xboard-to-v2board.php --src-host=... --src-db=... --src-user=... --src-pass=...\n\n";
            return 1;
        }
    }

    // ── 3. 确认
    if (!$opts['force'] && !$opts['dry-run']) {
        echo c("  源库：", 'bold') . "{$src['user']}@{$src['host']}:{$src['port']}/{$src['db']}\n";
        echo c("  目标：", 'bold') . "{$dst['user']}@{$dst['host']}:{$dst['port']}/{$dst['db']}\n";
        if (!$opts['no-truncate']) {
            echo c("\n  ⚠  将清空目标库现有数据再导入！请确认已备份。\n", 'yellow');
        }
        echo "\n  输入 'yes' 确认继续：";
        $confirm = trim(fgets(STDIN));
        if ($confirm !== 'yes') { echo "已取消。\n"; return 0; }
    }

    // ── 4. 建立连接
    log_step("建立数据库连接");
    $src_pdo = pdo_connect($src['host'], $src['port'], $src['db'], $src['user'], $src['pass'], '源库(Xboard)');
    $dst_pdo = pdo_connect($dst['host'], $dst['port'], $dst['db'], $dst['user'], $dst['pass'], '目标(V2Board)');

    // ── 5. 确定要迁移的表
    $all_tables = [
        'v2_server_group', 'v2_server_route',
        'v2_plan', 'v2_user', 'v2_order',
        'servers',  // 特殊处理
        'v2_coupon', 'v2_invite_code', 'v2_knowledge',
        'v2_notice', 'v2_ticket', 'v2_ticket_message',
        'v2_commission_log', 'v2_mail_log',
        'v2_stat', 'v2_stat_server', 'v2_stat_user',
        'v2_payment',
    ];
    $filter = $opts['tables'] ? array_map('trim', explode(',', $opts['tables'])) : [];

    $stats = [];

    // ── 6. 执行迁移
    $dst_pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");

    if (empty($filter) || in_array('v2_server_group', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_server_group', $opts, $stats);

    if (empty($filter) || in_array('v2_server_route', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_server_route', $opts, $stats);

    if (empty($filter) || in_array('v2_plan', $filter))
        migrate_plans($src_pdo, $dst_pdo, $opts, $stats);

    if (empty($filter) || in_array('v2_user', $filter))
        migrate_users($src_pdo, $dst_pdo, $opts, $stats);

    if (empty($filter) || in_array('v2_order', $filter))
        migrate_orders($src_pdo, $dst_pdo, $opts, $stats);

    if (empty($filter) || in_array('servers', $filter))
        migrate_servers($src_pdo, $dst_pdo, $opts, $stats);

    if (empty($filter) || in_array('v2_coupon', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_coupon', $opts, $stats);

    if (empty($filter) || in_array('v2_invite_code', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_invite_code', $opts, $stats);

    if (empty($filter) || in_array('v2_knowledge', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_knowledge', $opts, $stats);

    if (empty($filter) || in_array('v2_notice', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_notice', $opts, $stats,
            ['sort'],  // V2Board v2_notice 无 sort 列则跳过
        );

    if (empty($filter) || in_array('v2_ticket', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_ticket', $opts, $stats);

    if (empty($filter) || in_array('v2_ticket_message', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_ticket_message', $opts, $stats);

    if (empty($filter) || in_array('v2_commission_log', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_commission_log', $opts, $stats);

    if (empty($filter) || in_array('v2_mail_log', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_mail_log', $opts, $stats);

    if (empty($filter) || in_array('v2_stat', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_stat', $opts, $stats);

    if (empty($filter) || in_array('v2_stat_server', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_stat_server', $opts, $stats);

    if (empty($filter) || in_array('v2_stat_user', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_stat_user', $opts, $stats);

    if (empty($filter) || in_array('v2_payment', $filter))
        migrate_simple($src_pdo, $dst_pdo, 'v2_payment', $opts, $stats);

    // ── 7. 迁移后清理缓存
    if (!$opts['dry-run']) {
        log_step("清理 V2Board 缓存");
        $cache_dir = __DIR__ . '/../bootstrap/cache';
        foreach (glob("{$cache_dir}/*.php") ?: [] as $f) { @unlink($f); }
        log_info("bootstrap/cache/*.php 已清理");

        $redis_info = ['host' => $env['REDIS_HOST'] ?? '127.0.0.1',
                        'port' => $env['REDIS_PORT'] ?? 6379,
                        'pass' => $env['REDIS_PASSWORD'] ?? ''];
        try {
            $redis = new Redis();
            $redis->connect($redis_info['host'], (int)$redis_info['port'], 3);
            if ($redis_info['pass'] && $redis_info['pass'] !== 'null') {
                $redis->auth($redis_info['pass']);
            }
            $redis->flushDB();
            log_info("Redis 缓存已清空（当前 DB）");
        } catch (Exception $e) {
            log_warn("Redis 清理失败（不影响迁移）：" . $e->getMessage());
        }
    }

    // ── 8. 统计报告
    log_step("迁移完成");
    echo c("\n  迁移汇总\n", 'bold');
    echo "  " . str_repeat('─', 40) . "\n";
    foreach ($stats as $table => $cnt) {
        printf("  %-30s %5d 条\n", $table, $cnt);
    }
    echo "  " . str_repeat('─', 40) . "\n";
    $total = array_sum($stats);
    printf("  %-30s %5d 条\n", c('总计', 'bold'), $total);

    // 提示不支持的 Xboard 专属功能
    echo c("\n  注意事项：\n", 'yellow');
    echo "  • v2_settings（系统配置）需手动在 V2Board 后台重新配置\n";
    echo "  • v2_plugins（插件）V2Board 暂不支持，已跳过\n";
    echo "  • v2_gift_card_*（礼品卡）V2Board 结构不同，需手动处理\n";
    echo "  • v2_traffic_reset_logs V2Board 无此表，已跳过\n";
    if ($opts['dry-run']) {
        echo c("\n  ✓ DRY-RUN 完成，未写入任何数据\n\n", 'yellow');
    } else {
        echo c("\n  ✓ 迁移成功！请重启 V2Board 容器使配置生效。\n\n", 'green');
    }

    return 0;
}

exit(main($argv));
