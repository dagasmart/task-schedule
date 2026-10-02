#!/bin/bash

# DagaSmart Task Schedule 部署脚本
# 支持：单节点 / 集群部署 / Docker / Kubernetes

set -e

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 配置
APP_NAME="task-scheduler"
APP_PATH="/var/www/html"
PHP_BIN=$(which php)
COMPOSER_BIN=$(which composer)
SCHEDULER_USER="www-data"

# 日志函数
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_section() {
    echo -e "\n${BLUE}=== $1 ===${NC}"
}

# 检查依赖
check_dependencies() {
    log_section "检查依赖"

    # 检查 PHP 版本
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    log_info "PHP 版本: $PHP_VERSION"

    if ! php -r "exit(version_compare(PHP_VERSION, '8.3.0', '<') ? 1 : 0);"; then
        log_error "需要 PHP 8.3 或更高版本"
        exit 1
    fi

    # 检查 Swow 扩展
    if ! php -m | grep -q swow; then
        log_warn "Swow 扩展未安装，将使用传统调度模式"
        log_info "安装 Swow: pecl install swow"
    else
        log_info "Swow 扩展: 已安装"
    fi

    # 检查 PostgreSQL
    if ! command -v psql &> /dev/null; then
        log_warn "psql 客户端未安装"
    else
        log_info "PostgreSQL 客户端: $(psql --version)"
    fi
}

# 安装依赖
install_dependencies() {
    log_section "安装依赖"

    cd "$APP_PATH"

    if [ ! -f "composer.lock" ]; then
        log_warn "composer.lock 不存在，将执行 composer install"
        $COMPOSER_BIN install --no-dev --optimize-autoloader
    else
        $COMPOSER_BIN install --no-dev --optimize-autoloader
    fi

    log_info "依赖安装完成"
}

# 数据库迁移
run_migrations() {
    log_section "数据库迁移"

    cd "$APP_PATH"

    # 检查数据库连接
    if ! $PHP_BIN artisan db:monitor 2>/dev/null; then
        log_error "数据库连接失败，请检查配置"
        exit 1
    fi

    # 执行迁移
    $PHP_BIN artisan migrate --force

    # 填充初始数据（可选）
    if [ "$1" = "fresh" ]; then
        $PHP_BIN artisan db:seed --class=TaskScheduleSeeder --force
    fi

    log_info "数据库迁移完成"
}

# 优化配置
optimize() {
    log_section "优化配置"

    cd "$APP_PATH"

    $PHP_BIN artisan config:cache
    $PHP_BIN artisan route:cache
    $PHP_BIN artisan view:cache

    log_info "配置优化完成"
}

# 启动调度器
start_scheduler() {
    log_section "启动调度器"

    cd "$APP_PATH"

    # 检查是否已有实例运行
    if [ -f "storage/logs/scheduler.pid" ]; then
        OLD_PID=$(cat storage/logs/scheduler.pid)
        if kill -0 "$OLD_PID" 2>/dev/null; then
            log_warn "调度器已在运行 (PID: $OLD_PID)"
            return
        fi
    fi

    # 创建日志目录
    mkdir -p storage/logs/tasks

    # 启动 Swow 调度器
    if php -m | grep -q swow; then
        log_info "使用 Swow 调度器模式"
        nohup $PHP_BIN artisan schedule:swow-run \
            --daemon \
            --workers=4 \
            --max-concurrency=1024 \
            --tick-ms=10 \
            >> storage/logs/scheduler.log 2>&1 &

        echo $! > storage/logs/scheduler.pid
        log_info "Swow 调度器已启动 (PID: $(cat storage/logs/scheduler.pid))"
    else
        # 传统模式：添加到 crontab
        log_info "使用传统调度模式（需要配置 crontab）"
        log_info "添加以下行到 crontab："
        echo "* * * * * cd $APP_PATH && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
    fi
}

# 停止调度器
stop_scheduler() {
    log_section "停止调度器"

    if [ -f "storage/logs/scheduler.pid" ]; then
        PID=$(cat storage/logs/scheduler.pid)
        if kill -0 "$PID" 2>/dev/null; then
            kill -TERM "$PID"
            sleep 5
            if kill -0 "$PID" 2>/dev/null; then
                kill -KILL "$PID"
            fi
            log_info "调度器已停止"
        fi
        rm -f storage/logs/scheduler.pid
    else
        log_warn "调度器未运行"
    fi
}

# 重启调度器
restart_scheduler() {
    stop_scheduler
    sleep 2
    start_scheduler
}

# 健康检查
health_check() {
    log_section "健康检查"

    cd "$APP_PATH"

    # 检查调度器进程
    if [ -f "storage/logs/scheduler.pid" ]; then
        PID=$(cat storage/logs/scheduler.pid)
        if kill -0 "$PID" 2>/dev/null; then
            log_info "调度器进程: 运行中 (PID: $PID)"

            # 检查进程运行时长
            ETIME=$(ps -o etime= -p "$PID" 2>/dev/null | tr -d ' ')
            log_info "运行时长: $ETIME"
        else
            log_error "调度器进程: 已停止"
        fi
    else
        log_warn "未找到 PID 文件"
    fi

    # 检查数据库连接
    if $PHP_BIN artisan db:monitor 2>/dev/null; then
        log_info "数据库连接: 正常"
    else
        log_error "数据库连接: 异常"
    fi

    # 检查最近的任务执行
    $PHP_BIN artisan tinker --execute="
        \$count = DB::table('task_schedule_log')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->count();
        echo \"最近5分钟执行任务数: {\$count}\n\";
    " 2>/dev/null || log_warn "无法获取执行统计"
}

# 清理
cleanup() {
    log_section "清理"

    cd "$APP_PATH"

    # 清理日志
    $PHP_BIN artisan schedule:cleanup --days=30 --optimize

    # 清理缓存
    $PHP_BIN artisan cache:clear

    log_info "清理完成"
}

# 主函数
main() {
    case "$1" in
        install)
            check_dependencies
            install_dependencies
            run_migrations
            optimize
            ;;
        migrate)
            run_migrations "$2"
            ;;
        start)
            start_scheduler
            ;;
        stop)
            stop_scheduler
            ;;
        restart)
            restart_scheduler
            ;;
        status)
            health_check
            ;;
        optimize)
            optimize
            ;;
        cleanup)
            cleanup
            ;;
        deploy)
            check_dependencies
            install_dependencies
            run_migrations "$2"
            optimize
            restart_scheduler
            health_check
            ;;
        *)
            echo "用法: $0 {install|migrate|start|stop|restart|status|optimize|cleanup|deploy}"
            echo ""
            echo "命令说明:"
            echo "  install  - 完整安装（依赖 + 迁移 + 优化）"
            echo "  migrate  - 执行数据库迁移 [fresh]"
            echo "  start    - 启动调度器"
            echo "  stop     - 停止调度器"
            echo "  restart  - 重启调度器"
            echo "  status   - 健康检查"
            echo "  optimize - 优化配置"
            echo "  cleanup  - 清理过期数据"
            echo "  deploy   - 完整部署流程"
            exit 1
            ;;
    esac
}

main "$@"
