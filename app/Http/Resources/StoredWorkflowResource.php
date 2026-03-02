<?php
declare(strict_types=1);

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Waterline\Transformer\WorkflowToChartDataTransformer;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;

/**
 * @mixin StoredWorkflow
 */
class StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        $arguments = $this->normalizeArguments();

        return [
            "id" => $this->id,
            "class" => $this->class,
            "arguments" => serialize($arguments['arguments']),
            "connection" => $arguments['connection'],
            "queue" => $arguments['queue'],
            "output" => $this->output === null ? serialize(null) : serialize(Serializer::unserialize($this->output)),
            "status" => $this->status,
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
            "logs" => StoredWorkflowLogResource::collection($this->logs),
            "exceptions" => StoredWorkflowExceptionResource::collection($this->exceptions),
            "parents" => StoredWorkflowRelationshipResource::collection($this->parents),
            "continuedWorkflows" => StoredWorkflowRelationshipResource::collection($this->continuedWorkflows),
            "chartData" => app(WorkflowToChartDataTransformer::class)->transform($this->resource),
        ];
    }

    protected function normalizeArguments(): array
    {
        $arguments = $this->arguments === null ? null : Serializer::unserialize($this->arguments);

        if (! $this->isWrappedWorkflowArguments($arguments)) {
            return [
                'arguments' => $arguments,
                'connection' => null,
                'queue' => null,
            ];
        }

        $options = isset($arguments['options']) && is_array($arguments['options'])
            ? $arguments['options']
            : [];

        return [
            'arguments' => array_key_exists('arguments', $arguments) ? $arguments['arguments'] : [],
            'connection' => $this->normalizeOptionValue($options['connection'] ?? null),
            'queue' => $this->normalizeOptionValue($options['queue'] ?? null),
        ];
    }

    protected function isWrappedWorkflowArguments($arguments): bool
    {
        if (! is_array($arguments) || ! array_key_exists('arguments', $arguments)) {
            return false;
        }

        if (! array_key_exists('options', $arguments) && ! array_key_exists('__constructor', $arguments)) {
            return false;
        }

        if (array_key_exists('__constructor', $arguments) && $arguments['__constructor'] !== 'arguments') {
            return false;
        }

        if (array_key_exists('options', $arguments) && ! is_array($arguments['options'])) {
            return false;
        }

        return array_diff(array_keys($arguments), ['arguments', 'options', '__constructor']) === [];
    }

    protected function normalizeOptionValue($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
