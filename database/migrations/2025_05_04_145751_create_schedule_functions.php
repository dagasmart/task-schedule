<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class CreateScheduleFunctions extends Migration
{
    protected $connection = null;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // ============================================================
        // 1. 原子化领取待执行任务（不依赖 dispatch 表）
        // ============================================================
        DB::statement('
            CREATE OR REPLACE FUNCTION claim_due_tasks(
                p_worker_id TEXT,
                p_server_id TEXT,
                p_precision INTEGER DEFAULT NULL,
                p_limit INTEGER DEFAULT 100
            )
            RETURNS TABLE(
                task_id BIGINT,
                task_name TEXT,
                task_type TEXT,
                command TEXT,
                parameters JSONB,
                options JSONB,
                timezone TEXT,
                max_runtime INTEGER,
                retry_times INTEGER,
                retry_interval INTEGER
            ) AS $$
            DECLARE
                v_lock_key BIGINT;
            BEGIN
                v_lock_key := x\'5441534B\'::bigint;

                IF pg_try_advisory_xact_lock(v_lock_key) THEN
                    RETURN QUERY
                    UPDATE task_schedule ts
                    SET last_run_at = NOW(),
                        version = version + 1
                    FROM (
                        SELECT id
                        FROM task_schedule
                        WHERE active = true
                          AND (p_precision IS NULL OR precision = p_precision)
                          AND (next_run_at IS NULL OR next_run_at <= NOW())
                        ORDER BY priority DESC, next_run_at ASC, id ASC
                        LIMIT p_limit
                        FOR UPDATE SKIP LOCKED
                    ) claimable
                    WHERE ts.id = claimable.id
                    RETURNING ts.id AS task_id,
                              ts.task_name,
                              ts.task_type,
                              ts.command,
                              ts.parameters,
                              ts.options,
                              ts.timezone,
                              ts.max_runtime,
                              ts.retry_times,
                              ts.retry_interval;
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // ============================================================
        // 2. 尝试获取任务执行锁
        // ============================================================
        DB::statement('
            CREATE OR REPLACE FUNCTION try_acquire_task_lock(
                p_task_id BIGINT,
                p_dispatch_id TEXT,
                p_worker_id TEXT,
                p_server_id TEXT,
                p_lock_timeout INTEGER DEFAULT 300
            )
            RETURNS BOOLEAN AS $$
            DECLARE
                v_lock_key BIGINT;
                v_acquired BOOLEAN := FALSE;
            BEGIN
                v_lock_key := p_task_id;

                SELECT pg_try_advisory_xact_lock(v_lock_key) INTO v_acquired;

                RETURN v_acquired;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // ============================================================
        // 3. 释放任务执行锁
        // ============================================================
        DB::statement('
            CREATE OR REPLACE FUNCTION release_task_lock(
                p_dispatch_id TEXT,
                p_state INTEGER DEFAULT 2
            )
            RETURNS VOID AS $$
            BEGIN
                RETURN;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // ============================================================
        // 4. 清理过期日志
        // ============================================================
        DB::statement('
            CREATE OR REPLACE FUNCTION cleanup_dispatch_records(
                p_retention_days INTEGER DEFAULT 7
            )
            RETURNS INTEGER AS $$
            DECLARE
                v_deleted INTEGER;
            BEGIN
                DELETE FROM task_schedule_log
                WHERE created_at < NOW() - make_interval(days := p_retention_days);

                GET DIAGNOSTICS v_deleted = ROW_COUNT;
                RETURN v_deleted;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // ============================================================
        // 5. 批量更新下次执行时间
        // ============================================================
        DB::statement('
            CREATE OR REPLACE FUNCTION update_next_run_batch(
                p_task_ids BIGINT[]
            )
            RETURNS VOID AS $$
            BEGIN
                UPDATE task_schedule
                SET next_run_at = CASE
                    WHEN precision = 1 THEN NOW() + make_interval(secs := interval_seconds)
                    WHEN precision = 2 THEN NOW() + make_interval(mins := 1)
                    WHEN precision = 3 THEN NOW() + make_interval(hours := 1)
                    WHEN precision = 4 THEN NOW() + make_interval(days := 1)
                    WHEN precision = 5 THEN NOW() + make_interval(days := 7)
                    WHEN precision = 6 THEN NOW() + make_interval(months := 1)
                    ELSE NOW() + make_interval(mins := 1)
                END
                WHERE id = ANY(p_task_ids)
                  AND active = true;
            END;
            $$ LANGUAGE plpgsql;
        ');

        // ============================================================
        // 6. 统计函数（使用 state 字段）
        // ============================================================
        DB::statement('
            CREATE OR REPLACE FUNCTION get_task_stats(
                p_start_date TIMESTAMP DEFAULT NULL,
                p_end_date TIMESTAMP DEFAULT NULL
            )
            RETURNS TABLE(
                total_tasks BIGINT,
                active_tasks BIGINT,
                total_executions BIGINT,
                success_count BIGINT,
                failed_count BIGINT,
                avg_duration NUMERIC,
                max_duration NUMERIC,
                p99_duration NUMERIC
            ) AS $$
            BEGIN
                RETURN QUERY
                SELECT
                    (SELECT COUNT(*) FROM task_schedule)::BIGINT,
                    (SELECT COUNT(*) FROM task_schedule WHERE active = true)::BIGINT,
                    (SELECT COUNT(*) FROM task_schedule_log
                     WHERE (p_start_date IS NULL OR created_at >= p_start_date)
                       AND (p_end_date IS NULL OR created_at <= p_end_date))::BIGINT,
                    (SELECT COUNT(*) FROM task_schedule_log
                     WHERE state = true
                       AND (p_start_date IS NULL OR created_at >= p_start_date)
                       AND (p_end_date IS NULL OR created_at <= p_end_date))::BIGINT,
                    (SELECT COUNT(*) FROM task_schedule_log
                     WHERE state = false
                       AND (p_start_date IS NULL OR created_at >= p_start_date)
                       AND (p_end_date IS NULL OR created_at <= p_end_date))::BIGINT,
                    (SELECT COALESCE(AVG(duration), 0) FROM task_schedule_log
                     WHERE (p_start_date IS NULL OR created_at >= p_start_date)
                       AND (p_end_date IS NULL OR created_at <= p_end_date)),
                    (SELECT COALESCE(MAX(duration), 0) FROM task_schedule_log
                     WHERE (p_start_date IS NULL OR created_at >= p_start_date)
                       AND (p_end_date IS NULL OR created_at <= p_end_date)),
                    (SELECT COALESCE(
                        percentile_cont(0.99) WITHIN GROUP (ORDER BY duration),
                        0
                    ) FROM task_schedule_log
                     WHERE (p_start_date IS NULL OR created_at >= p_start_date)
                       AND (p_end_date IS NULL OR created_at <= p_end_date));
            END;
            $$ LANGUAGE plpgsql;
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP FUNCTION IF EXISTS claim_due_tasks(TEXT, TEXT, INTEGER, INTEGER) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS try_acquire_task_lock(BIGINT, TEXT, TEXT, TEXT, INTEGER) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS release_task_lock(TEXT, INTEGER) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS cleanup_dispatch_records(INTEGER) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS update_next_run_batch(BIGINT[]) CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS get_task_stats(TIMESTAMP, TIMESTAMP) CASCADE');
    }
}
