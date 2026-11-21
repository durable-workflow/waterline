<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Support\WorkflowTaskLeaseLoss;

final class TaskAttention
{
    public const FIELD = 'task_attention';

    public static function supported(bool $fresh = false): bool
    {
        return class_exists(WorkflowTaskLeaseLoss::class) && WorkflowTaskLeaseLoss::summaryTracked($fresh);
    }

    public static function fromSummary(?Model $summary): ?array
    {
        $value = $summary?->getAttributes()[self::FIELD] ?? null;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }
}
