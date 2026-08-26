<?php

declare(strict_types=1);

namespace Waterline\Support;

use BackedEnum;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use PDOException;
use Throwable;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

/** Normalizes embedded MessageStream and service Workflow Stream diagnostics. */
final class WorkflowStreamPresenter
{
    public const STATE_AVAILABLE = 'available';

    public const STATE_DEGRADED = 'degraded';

    public const REASON_COLLECTION_FAILED = 'workflow_streams_collection_failed';

    public const REASON_SCHEMA_UNAVAILABLE = 'workflow_streams_schema_unavailable';

    /**
     * @return array{
     *     state: string,
     *     available: bool,
     *     reason: string|null,
     *     streams: list<array<string, mixed>>
     * }
     */
    public function embedded(WorkflowRun $run): array
    {
        try {
            $summaries = $this->embeddedSummaries($run);
        } catch (Throwable $exception) {
            return [
                'state' => self::STATE_DEGRADED,
                'available' => false,
                'reason' => $this->embeddedFailureReason($exception),
                'streams' => [],
            ];
        }

        $streams = $summaries->map(function (object $summary) use ($run): array {
            $direction = $this->enumValue($summary->direction);
            $error = $this->nullableString($summary->error_reason);

            return [
                'mode' => 'embedded',
                'stream_name' => (string) $summary->stream_key,
                'status' => $error === null && (int) $summary->failed_items === 0
                    ? 'open'
                    : 'errored',
                'last_offset' => (int) $summary->last_offset,
                'run_cursor_offset' => $direction === MessageDirection::Inbound->value
                    ? (int) $run->message_cursor_position
                    : null,
                'total_items' => (int) $summary->total_items,
                'pending_items' => (int) $summary->pending_items,
                'error_reason' => $error,
                'offset_origin' => 1,
                'delivery' => 'at-least-once',
                'direction' => $direction,
                'supports_inbound_workflow_messaging' => true,
                'continue_as_new_cursor_transfer' => true,
            ];
        })->values()->all();

        return [
            'state' => self::STATE_AVAILABLE,
            'available' => true,
            'reason' => null,
            'streams' => $streams,
        ];
    }

    /**
     * @param  iterable<object|array<string, mixed>>  $streams
     * @return list<array<string, mixed>>
     */
    public function service(iterable $streams): array
    {
        $rows = [];

        foreach ($streams as $stream) {
            $rows[] = [
                'mode' => 'service',
                'stream_name' => (string) $this->value($stream, 'streamName', 'stream_name', ''),
                'status' => (string) $this->value($stream, 'status', 'status', 'open'),
                'last_offset' => (int) $this->value($stream, 'lastOffset', 'last_offset', -1),
                'run_cursor_offset' => null,
                'total_items' => (int) $this->value($stream, 'totalItems', 'total_items', 0),
                'pending_items' => (int) $this->value($stream, 'pendingItems', 'pending_items', 0),
                'error_reason' => $this->nullableString(
                    $this->value($stream, 'errorReason', 'error_reason'),
                ),
                'offset_origin' => 0,
                'delivery' => 'at-least-once',
                'direction' => 'workflow_output',
                'supports_inbound_workflow_messaging' => false,
                'continue_as_new_cursor_transfer' => false,
            ];
        }

        return $rows;
    }

    private function embeddedSummaries(WorkflowRun $run): Collection
    {
        $table = (new WorkflowMessage)->getTable();
        $errorTable = 'workflow_stream_latest_error';
        $latestError = WorkflowMessage::query()
            ->from($table.' as '.$errorTable)
            ->select($errorTable.'.last_delivery_error')
            ->where($errorTable.'.workflow_run_id', $run->id)
            ->whereColumn($errorTable.'.stream_key', $table.'.stream_key')
            ->whereColumn($errorTable.'.direction', $table.'.direction')
            ->whereNotNull($errorTable.'.last_delivery_error')
            ->whereRaw('TRIM('.$errorTable.'.last_delivery_error) <> ?', [''])
            ->orderByDesc($errorTable.'.sequence')
            ->limit(1);

        return WorkflowMessage::query()
            ->where($table.'.workflow_run_id', $run->id)
            ->select($table.'.stream_key', $table.'.direction')
            ->selectSub($latestError, 'error_reason')
            ->selectRaw('MAX(sequence) AS last_offset')
            ->selectRaw('COUNT(*) AS total_items')
            ->selectRaw(
                'SUM(CASE WHEN consume_state = ? THEN 1 ELSE 0 END) AS failed_items',
                [MessageConsumeState::Failed->value],
            )
            ->selectRaw(
                'SUM(CASE WHEN direction = ? AND consume_state = ? THEN 1 ELSE 0 END) AS pending_items',
                [MessageDirection::Inbound->value, MessageConsumeState::Pending->value],
            )
            ->groupBy($table.'.stream_key', $table.'.direction')
            ->orderBy($table.'.stream_key')
            ->orderBy($table.'.direction')
            ->get();
    }

    private function embeddedFailureReason(Throwable $exception): string
    {
        if (! $exception instanceof QueryException) {
            return self::REASON_COLLECTION_FAILED;
        }

        $previous = $exception->getPrevious();
        $sqlState = strtoupper((string) (
            $previous instanceof PDOException
                ? ($previous->errorInfo[0] ?? $previous->getCode())
                : $exception->getCode()
        ));
        $message = strtolower($exception->getMessage());

        if (in_array($sqlState, ['42P01', '42703', '42S02', '42S22'], true)) {
            return self::REASON_SCHEMA_UNAVAILABLE;
        }

        foreach ([
            'base table or view not found',
            'does not exist',
            'invalid column name',
            'invalid object name',
            'no such column',
            'no such table',
            'undefined column',
            'undefined table',
        ] as $schemaFailure) {
            if (str_contains($message, $schemaFailure)) {
                return self::REASON_SCHEMA_UNAVAILABLE;
            }
        }

        return self::REASON_COLLECTION_FAILED;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    private function value(
        object|array $stream,
        string $objectKey,
        string $arrayKey,
        mixed $default = null,
    ): mixed {
        if (is_array($stream)) {
            return $stream[$arrayKey] ?? $default;
        }

        return $stream->{$objectKey} ?? $default;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
