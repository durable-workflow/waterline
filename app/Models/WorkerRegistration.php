<?php

declare(strict_types=1);

namespace Waterline\Models;

use Illuminate\Database\Eloquent\Model;

class WorkerRegistration extends Model
{
    protected $table = 'workflow_worker_registrations';

    protected $fillable = [
        'worker_id',
        'namespace',
        'task_queue',
        'runtime',
        'sdk_version',
        'build_id',
        'supported_workflow_types',
        'workflow_definition_fingerprints',
        'supported_activity_types',
        'max_concurrent_workflow_tasks',
        'max_concurrent_activity_tasks',
        'max_concurrent_worker_sessions',
        'available_workflow_slots',
        'available_activity_slots',
        'available_session_slots',
        'process_metrics',
        'heartbeat_interval_seconds',
        'last_heartbeat_at',
        'status',
    ];

    protected $casts = [
        'supported_workflow_types' => 'array',
        'workflow_definition_fingerprints' => 'array',
        'supported_activity_types' => 'array',
        'process_metrics' => 'array',
        'last_heartbeat_at' => 'datetime',
    ];
}
