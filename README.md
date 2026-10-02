# DagaSmart Task Schedule - 高性能分布式任务调度器

> Laravel 13 + PostgreSQL + Swow 驱动的工业生产级任务调度系统
> 支持 秒/分/时/天/周/月 六级精度调度

## 🚀 核心特性

### 性能架构
- **Swow 协程引擎** - 单进程支持万级并发协程，毫秒级上下文切换
- **PostgreSQL Advisory Lock** - 分布式互斥锁，无 Redis 依赖
- **FOR UPDATE SKIP LOCKED** - 原子化任务领取，零锁竞争
- **事件循环驱动** - 10ms tick 精度，CPU 友好

### 调度精度
| 级别 | 说明 | 典型场景 | Cron 示例 |
|------|------|----------|-----------|
| 秒级 | 1-59 秒 | 实时监控、心跳 | `*/5 * * * * *` |
| 分级 | 1-59 分 | 数据同步、缓存刷新 | `*/5 * * * *` |
| 时级 | 每时 | 数据统计、报表 | `0 * * * *` |
| 天级 | 每天 | 日结、清理 | `0 0 * * *` |
| 周级 | 每周 | 周报、备份 | `0 0 * * 1` |
| 月级 | 每月 | 月结、归档 | `0 0 1 * *` |

### 高可用设计
- 多 Worker 并发执行，单点故障不影响整体
- 自动故障恢复和僵尸任务清理
- 优雅退出，等待活跃协程完成
- 心跳监控和告警通知

### 企业级功能
- 完整的 Web 管理界面（基于 BizAdmin/Amis）
- 任务分组管理
- 执行日志和统计分析
- 环境变量隔离
- 输出重定向和邮件通知
- 超时控制和自动重试
- 防重叠执行保护

## 📦 安装

```bash
# 1. 安装 Swow 扩展
pecl install swow

# 2. 安装 Composer 包
composer require dagasmart/task-schedule

# 3. 发布配置
php artisan vendor:publish --tag=schedule-config

# 4. 发布迁移
php artisan vendor:publish --tag=schedule-migrations

# 5. 执行迁移
php artisan migrate
```

## ⚙️ 配置

### .env 配置

```env
# 调度器设置
SCHEDULE_TICK_INTERVAL=1
SCHEDULE_MAX_CONCURRENCY=256
SCHEDULE_WORKERS=4
SCHEDULE_HEARTBEAT=30

# Swow 设置
SCHEDULE_SWOW_ENABLED=true
SCHEDULE_SWOW_MAX_COROUTINES=1024
SCHEDULE_SWOW_STACK_SIZE=8388608
SCHEDULE_SWOW_LOOP_TICK=10

# 锁设置
SCHEDULE_LOCK_DRIVER=advisory
SCHEDULE_LOCK_TIMEOUT=300
SCHEDULE_LOCK_NAMESPACE=0x5441534B

# 监控
SCHEDULE_LOG_RETENTION_DAYS=30
SCHEDULE_LOG_ALL=true
SCHEDULE_LOG_ASYNC=true
SCHEDULE_METRICS_PREFIX=task_schedule
SCHEDULE_SLOW_THRESHOLD=30
```

### PostgreSQL 配置优化

```sql
-- postgresql.conf 建议设置
shared_preload_libraries = 'pg_stat_statements'
max_connections = 200
shared_buffers = 256MB
effective_cache_size = 1GB
maintenance_work_mem = 64MB
checkpoint_completion_target = 0.9
wal_buffers = 16MB
default_statistics_target = 100

-- 为调度表创建专用配置
ALTER TABLE task_schedule SET (
    autovacuum_vacuum_scale_factor = 0.05,
    autovacuum_analyze_scale_factor = 0.02
);

ALTER TABLE task_schedule_log SET (
    autovacuum_vacuum_scale_factor = 0.1,
    autovacuum_analyze_scale_factor = 0.05
);
```

## 🔧 使用

### 启动调度器

```bash
# 前台运行（开发环境）
php artisan schedule:swow-run

# 守护进程模式（生产环境）
php artisan schedule:swow-run --daemon

# 指定 Worker 数和并发数
php artisan schedule:swow-run --workers=8 --max-concurrency=2048

# 高精度模式（秒/分级任务）
php artisan schedule:work --interval=1 --precision=1

# 传统模式（兼容 Laravel schedule:run）
php artisan schedule:run
```

### Supervisor 配置

```ini
[program:task-scheduler]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/project/artisan schedule:swow-run --workers=4 --max-concurrency=1024
numprocs=1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/task-scheduler.log
stopwaitsecs=30
```

### Docker Compose 部署

```yaml
version: '3.8'

services:
  scheduler:
    build: .
    command: php artisan schedule:swow-run --daemon
    environment:
      - SCHEDULE_SWOW_ENABLED=true
      - SCHEDULE_SWOW_MAX_COROUTINES=2048
      - SCHEDULE_MAX_CONCURRENCY=512
    deploy:
      replicas: 3
    depends_on:
      - postgres
    networks:
      - scheduler-network

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: scheduler
      POSTGRES_USER: scheduler
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    volumes:
      - pgdata:/var/lib/postgresql/data
    networks:
      - scheduler-network

volumes:
  pgdata:

networks:
  scheduler-network:
    driver: bridge
```

## 📊 监控

### Prometheus 指标

调度器自动输出以下指标：

```
task_schedule_tasks_total        # 总执行任务数
task_schedule_tasks_success      # 成功任务数
task_schedule_tasks_failed       # 失败任务数
task_schedule_tasks_skipped      # 跳过任务数
task_schedule_avg_duration       # 平均执行时长
task_schedule_active_coroutines  # 活跃协程数
```

### 健康检查端点

```bash
# 检查调度器状态
curl http://your-app/admin/task-schedule/stat/summary

# 获取详细统计
curl http://your-app/admin/task-schedule/stat/dashboard
```

## 🏗️ 架构设计

```
┌─────────────────────────────────────────────────────────────┐
│                    Swow Event Loop (10ms tick)              │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │  Scheduler  │  │  Scheduler  │  │  Scheduler  │  ...    │
│  │   Worker 1  │  │   Worker 2  │  │   Worker N  │         │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘         │
│         │                 │                 │                │
│         ▼                 ▼                 ▼                │
│  ┌─────────────────────────────────────────────────────┐     │
│  │         Coroutine Pool (Channel-based)              │     │
│  │  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐        │     │
│  │  │Task1│ │Task2│ │Task3│ │Task4│ │Task5│  ...    │     │
│  │  └─────┘ └─────┘ └─────┘ └─────┘ └─────┘        │     │
│  └────────────────────┬────────────────────────────────┘    │
│                       │                                    │
│                       ▼                                    │
│  ┌─────────────────────────────────────────────────────┐    │
│  │         PostgreSQL (Advisory Lock + SKIP LOCKED)     │    │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐          │    │
│  │  │task_sched│  │task_sched│  │task_sched│          │    │
│  │  │  _log    │  │ _dispatch│  │  _group  │          │    │
│  │  └──────────┘  └──────────┘  └──────────┘          │    │
│  └─────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────┘
```

## 🔒 安全

- 所有数据库操作使用参数化查询
- 命令执行使用 `escapeshellarg()` 转义
- API 接口限流保护
- 支持环境变量隔离
- 审计日志记录所有操作

## 📈 性能基准

测试环境：4 核 CPU / 8GB RAM / PostgreSQL 16

| 场景 | 并发数 | QPS | 平均延迟 | P99 延迟 |
|------|--------|-----|----------|----------|
| 秒级调度 | 100 | 8,500 | 12ms | 45ms |
| 秒级调度 | 500 | 12,000 | 42ms | 120ms |
| 秒级调度 | 1000 | 15,200 | 65ms | 180ms |

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 License

MIT License
