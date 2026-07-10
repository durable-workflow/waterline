<?php

declare(strict_types=1);

namespace Waterline\Repositories\Workflow\Infrastructure;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\HybridMigrationView;
use Workflow\Models\StoredWorkflow;

final class HybridWorkflowRepository implements WorkflowRepositoryInterface
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly V2WorkflowRepository $v2,
        private readonly WorkflowRepositoryBaseSQL $legacy,
    ) {}

    public function engineSource(): string
    {
        return 'v2';
    }

    public function completedFlows()
    {
        return $this->bucketFlows('completed');
    }

    public function failedFlows()
    {
        return $this->bucketFlows('failed');
    }

    public function cancelledFlows()
    {
        return $this->bucketFlows('cancelled');
    }

    public function terminatedFlows()
    {
        return $this->bucketFlows('terminated');
    }

    public function runningFlows()
    {
        return $this->bucketFlows('running');
    }

    public function bucketFlows(string $bucket, int $perPage = self::PAGE_SIZE, ?int $page = null)
    {
        $perPage = max(1, $perPage);
        $page ??= LengthAwarePaginator::resolveCurrentPage();

        if (! HybridMigrationView::requestIncludesLegacyRows()) {
            return $this->v2->bucketFlows($bucket, $perPage, $page);
        }

        $fetchLimit = $page * $perPage;
        $legacyPage = $this->legacy->bucketFlows($bucket, $fetchLimit, 1, true);

        if ($legacyPage->total() === 0) {
            return $this->v2->bucketFlows($bucket, $perPage, $page);
        }

        $v2Page = $this->v2->bucketFlows($bucket, $fetchLimit, 1);
        $items = array_merge($v2Page->items(), $legacyPage->items());

        usort($items, fn (mixed $left, mixed $right): int => $this->compareItems($left, $right));

        $items = array_slice($items, ($page - 1) * $perPage, $perPage);
        $items = array_map(
            fn (mixed $item): mixed => $this->isLegacyWorkflow($item)
                ? $this->legacyListItem($item)
                : $item,
            $items,
        );

        return new LengthAwarePaginator(
            $items,
            $v2Page->total() + $legacyPage->total(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    public function findFlow(string $id)
    {
        $legacyId = $this->qualifiedLegacyId($id);

        if ($legacyId !== null) {
            return $this->findLegacyFlow($legacyId);
        }

        if (str_starts_with($id, 'v1:')) {
            throw $this->notFound($id);
        }

        try {
            return $this->v2->findFlow($id);
        } catch (ModelNotFoundException $v2Exception) {
            if (! preg_match('/^(?:0|[1-9][0-9]*)$/', $id)) {
                throw $v2Exception;
            }

            try {
                return $this->findLegacyFlow($id);
            } catch (ModelNotFoundException) {
                throw $v2Exception;
            }
        }
    }

    public function findFlowSelection(string $instanceId, ?string $runId = null)
    {
        return $this->v2->findFlowSelection($instanceId, $runId);
    }

    public function dashboardStats(): array
    {
        return $this->v2->dashboardStats();
    }

    public function flowsPastHour(): int
    {
        return $this->v2->flowsPastHour();
    }

    public function exceptionsPastHour(): int
    {
        return $this->v2->exceptionsPastHour();
    }

    public function failedFlowsPastWeek(): int
    {
        return $this->v2->failedFlowsPastWeek();
    }

    public function maxWaitTimeWorkflow()
    {
        return $this->v2->maxWaitTimeWorkflow();
    }

    public function maxDurationWorkflow()
    {
        return $this->v2->maxDurationWorkflow();
    }

    public function maxExceptionsWorkflow()
    {
        return $this->v2->maxExceptionsWorkflow();
    }

    public function totalFlows(): int
    {
        return $this->v2->totalFlows();
    }

    public function operatorMetrics()
    {
        return $this->v2->operatorMetrics();
    }

    private function findLegacyFlow(string $id)
    {
        $flow = $this->legacy->findFlow($id);

        if ($flow instanceof Model) {
            $flow->loadMissing('signals');
        }

        return $flow;
    }

    private function qualifiedLegacyId(string $id): ?string
    {
        if (! str_starts_with($id, 'v1:')) {
            return null;
        }

        $legacyId = substr($id, strlen('v1:'));

        return $legacyId !== '' ? $legacyId : null;
    }

    private function notFound(string $id): ModelNotFoundException
    {
        $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);

        return (new ModelNotFoundException())->setModel($modelClass, [$id]);
    }

    private function isLegacyWorkflow(mixed $item): bool
    {
        $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);

        return is_object($item) && $item instanceof $modelClass;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyListItem(Model $flow): array
    {
        $legacyKey = $flow->getKeyType() === 'string'
            ? (string) $flow->getKey()
            : (int) $flow->getKey();
        $legacyId = (string) $legacyKey;
        $status = $this->statusValue($flow->getAttribute('status'));
        $statusBucket = $this->statusBucket($status);
        $createdAt = $flow->getAttribute('created_at');
        $updatedAt = $flow->getAttribute('updated_at');

        return [
            'id' => 'v1:'.$legacyId,
            'legacy_id' => $legacyKey,
            'operator_id' => 'v1:'.$legacyId,
            'engine_source' => 'v1',
            'engine_version' => '1.x',
            'execution_engine' => 'finish-on-v1',
            'class' => $flow->getAttribute('class'),
            'status' => $status,
            'status_bucket' => $statusBucket,
            'is_terminal' => $statusBucket !== 'running',
            'created_at' => $this->timestamp($createdAt),
            'updated_at' => $this->timestamp($updatedAt),
            'started_at' => $this->timestamp($createdAt),
            'closed_at' => $statusBucket === 'running' ? null : $this->timestamp($updatedAt),
            'sort_timestamp' => $this->timestamp($updatedAt ?? $createdAt),
            'detail_path' => '/api/flows/v1:'.$legacyId,
            'operator_visibility_degraded' => null,
        ];
    }

    private function compareItems(mixed $left, mixed $right): int
    {
        $comparison = $this->sortValue($left) <=> $this->sortValue($right);

        if ($comparison === 0) {
            $comparison = $this->identity($left) <=> $this->identity($right);
        }

        return $this->sortDirection() === 'asc' ? $comparison : -$comparison;
    }

    private function sortValue(mixed $item): float
    {
        $value = $this->itemValue($item, 'sort_timestamp')
            ?? $this->itemValue($item, 'last_progress_at')
            ?? $this->itemValue($item, 'updated_at')
            ?? $this->itemValue($item, 'created_at');

        if ($value instanceof CarbonInterface) {
            return (float) $value->format('U.u');
        }

        if (is_string($value)) {
            return (float) (strtotime($value) ?: 0);
        }

        return 0.0;
    }

    private function identity(mixed $item): string
    {
        $prefix = $this->isLegacyWorkflow($item) ? 'v1:' : 'v2:';

        return $prefix.(string) ($this->itemValue($item, 'id') ?? '');
    }

    private function itemValue(mixed $item, string $key): mixed
    {
        if ($item instanceof Model) {
            return $item->getAttribute($key);
        }

        return is_array($item) ? ($item[$key] ?? null) : null;
    }

    private function sortDirection(): string
    {
        $direction = request()->query('sort_direction', request()->query('sort'));

        return is_string($direction) && strtolower(trim($direction)) === 'asc' ? 'asc' : 'desc';
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof BackedEnum) {
            return is_string($status->value) ? $status->value : null;
        }

        if (is_object($status) && method_exists($status, '__toString')) {
            return (string) $status;
        }

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function statusBucket(?string $status): ?string
    {
        return match ($status) {
            'completed', 'continued' => 'completed',
            'failed' => 'failed',
            null => null,
            default => 'running',
        };
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof CarbonInterface
            ? $value->toIso8601String()
            : (is_string($value) ? $value : null);
    }
}
