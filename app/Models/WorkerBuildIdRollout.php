<?php

declare(strict_types=1);

namespace Waterline\Models;

use Illuminate\Database\Eloquent\Model;
use Waterline\Traits\ResolvesStorageConnection;

class WorkerBuildIdRollout extends Model
{
    use ResolvesStorageConnection;

    public const DRAIN_INTENT_ACTIVE = 'active';

    public const DRAIN_INTENT_DRAINING = 'draining';

    public const UNVERSIONED_KEY = '';

    protected $table = 'workflow_worker_build_id_rollouts';

    protected $fillable = [
        'namespace',
        'task_queue',
        'build_id',
        'drain_intent',
        'drained_at',
        'promoted_at',
        'rolled_back_at',
        'required_compatibility',
        'recorded_fingerprint',
        'compatibility_policy',
        'workflow_types',
    ];

    protected $casts = [
        'drained_at' => 'datetime',
        'promoted_at' => 'datetime',
        'rolled_back_at' => 'datetime',
        'workflow_types' => 'array',
    ];

    public static function buildIdKey(?string $buildId): string
    {
        if (! is_string($buildId)) {
            return self::UNVERSIONED_KEY;
        }

        $trimmed = trim($buildId);

        return $trimmed === '' ? self::UNVERSIONED_KEY : $trimmed;
    }

    public function publicBuildId(): ?string
    {
        $buildId = (string) $this->build_id;

        return $buildId === self::UNVERSIONED_KEY ? null : $buildId;
    }
}
