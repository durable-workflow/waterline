<?php

declare(strict_types=1);

namespace Waterline\Support;

use BackedEnum;
use Throwable;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

/** Normalizes embedded MessageStream and service Workflow Stream diagnostics. */
final class WorkflowStreamPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function embedded(WorkflowRun $run): array
    {
        try {
            $groups = WorkflowMessage::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('stream_key')
                ->orderBy('sequence')
                ->get()
                ->groupBy('stream_key');
        } catch (Throwable) {
            return [];
        }

        return $groups->map(function ($messages, string|int $streamName) use ($run): array {
            $pending = $messages->filter(
                fn (WorkflowMessage $message): bool => $this->enumValue($message->consume_state) === 'pending',
            )->count();
            $error = $messages
                ->pluck('last_delivery_error')
                ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
                ->last();
            $directions = $messages
                ->map(fn (WorkflowMessage $message): string => $this->enumValue($message->direction))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return [
                'mode' => 'embedded',
                'stream_name' => (string) $streamName,
                'status' => $error === null ? 'open' : 'errored',
                'last_offset' => (int) ($messages->max('sequence') ?? 0),
                'run_cursor_offset' => (int) $run->message_cursor_position,
                'total_items' => $messages->count(),
                'pending_items' => $pending,
                'error_reason' => $error,
                'offset_origin' => 1,
                'delivery' => 'at-least-once',
                'direction' => implode('+', $directions),
                'supports_inbound_workflow_messaging' => true,
                'continue_as_new_cursor_transfer' => true,
            ];
        })->values()->all();
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
