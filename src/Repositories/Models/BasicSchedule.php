<?php
namespace Jiannei\Schedule\Laravel\Repositories\Models;

use Illuminate\Database\Eloquent\Model;

class BasicSchedule extends Model
{
    protected $fillable = [
        'description', 'command', 'parameters', 'expression', 'active', 'timezone',
        'environments', 'without_overlapping', 'on_one_server', 'in_background', 'in_maintenance_mode',
        'output_file_path', 'output_append', 'output_email', 'output_email_on_failure',
    ];

    protected $casts = [
        'active' => 'boolean',
        'on_one_server' => 'boolean',
        'in_background' => 'boolean',
        'in_maintenance_mode' => 'boolean',
        'output_append' => 'boolean',
        'output_email_on_failure' => 'boolean',
    ];

    /**
     * Scope a query to only include active schedule.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }
}
