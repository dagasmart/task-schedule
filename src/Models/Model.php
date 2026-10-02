<?php
declare(strict_types=1);

namespace DagaSmart\TaskSchedule\Models;

use DagaSmart\BizAdmin\Models\BaseModel;

/**
 * 基座模型
 * 支持 PostgreSQL 多 schema 连接
 */
class Model extends BaseModel
{
    const ?string SCHEMA = null; // 空值默认数据库

    public function __construct()
    {
        if (!empty(static::SCHEMA)) {
            $this->setConnection(static::SCHEMA);
        }
        parent::__construct();
    }

    protected static function booted(): void
    {
        parent::booted();

        // 自动设置 PostgreSQL schema search path
        static::creating(function ($model) {
            if (!empty(static::SCHEMA)) {
                $connection = $model->getConnection();
                $schema = static::SCHEMA;
                $connection->statement("SET search_path TO {$schema}, public");
            }
        });
    }
}
