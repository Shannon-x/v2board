# V2Board Webman 频繁重启问题分析与优化

## 一、问题现象

容器日志显示 webman 进程频繁停止并重启，主要有两种模式：

1. **`INFO waiting for webman to stop`** → `stopped` → `spawned`：由外部触发 supervisord 重启
2. **`INFO exited: webman (exit status 0; expected)`** → `spawned`：webman 主动退出后由 supervisord 自动拉起

## 二、根因分析

### 根因 1：MAX_REQUEST 请求计数限制（主要原因）

**位置**：`webman.php` 第 10、34-36 行

```php
define('MAX_REQUEST', 6600);
// ...
if (++$request_count > MAX_REQUEST) {
    Worker::stopAll();
}
```

- 每个 worker 处理满 **6600 个请求**后，会调用 `Worker::stopAll()` 主动退出
- 任一 worker 达到限制即触发**整个 webman 进程组**退出
- Worker 数量为 `$ncpu * 2`，请求在 worker 间分配
- **估算**：4 核约 8 个 worker，平均每 worker 6600 请求，总请求约 2.6 万；若 100 req/s，约 **4 分钟** 触发一次重启，与日志间隔吻合

### 根因 2：后台保存配置触发进程重启

**位置**：`app/Http/Controllers/V1/Admin/ConfigController.php` 第 220-225 行

```php
if(Cache::has('WEBMANPID')) {
    $pid = Cache::get('WEBMANPID');
    Cache::forget('WEBMANPID');
    return response(['data' => posix_kill($pid, 15)]);
}
```

- 管理员在后台**保存系统配置**时，会向 webman 主进程发送 `SIGTERM(15)`
- 进程退出后由 supervisord 的 `autorestart=true` 自动重启
- 若频繁修改配置或存在自动保存逻辑，会加剧重启频率

### 根因 3："waiting for webman to stop" 的来源

该日志表示 **supervisord 主动执行 stop/restart**，可能来源包括：

- 外部执行 `supervisorctl restart webman`
- 容器编排（如 K8s、Docker）对进程的探测或重启策略
- 其他脚本或定时任务调用 `supervisorctl`

## 三、优化方案

### 方案 1：提高 MAX_REQUEST（推荐）

将 `MAX_REQUEST` 从 6600 提高到 50000～100000，减少因请求计数导致的重启频率，同时保留定期回收以控制内存。

### 方案 2：支持环境变量配置

通过环境变量 `WEBMAN_MAX_REQUEST` 控制，便于不同环境（开发/生产）灵活调整。

### 方案 3：优化 supervisord 配置

- 适当增加 `stopwaitsecs`，避免强制 kill
- 视情况调整 `startretries`，应对短暂异常

## 四、实施后的预期效果

- 重启间隔从约 2～5 分钟延长到 30 分钟～2 小时（视流量而定）
- 配置保存仍会触发重启，但仅在实际保存时发生
- 通过环境变量可针对不同部署场景做细粒度控制
