<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Waterline\Support\RunApplicationContext;
use Waterline\Support\RunObservationHistoryToken;
use Waterline\Support\RunObservationPresenter;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\ConfiguredV2Models;

class V2RunObservationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        $validated = $request->validate([
            'history_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'history_page_token' => ['nullable', 'string', 'max:4096'],
        ]);
        $observer = app(OperatorObservabilityRepository::class);
        abort_unless(method_exists($observer, 'runObservation') && method_exists($observer, 'runHistoryPage'), 501,
            'The selected Workflow observer does not support bounded run observations.');
        $token = $validated['history_page_token'] ?? null;
        $cursor = RunObservationHistoryToken::decode($token, $this->resource->id);
        $history = $observer->runHistoryPage(
            $this->resource, (int) ($validated['history_limit'] ?? 200),
            $cursor['after'] ?? 0, $cursor['through'] ?? null,
        );
        $detail = $observer->runObservation($this->resource);
        foreach ($detail['recent_failures']['failures'] as &$failure) {
            if ($failure['supporting_event']['state'] === 'retained') {
                $reference = $failure['supporting_event'];
                $failure['supporting_event']['next_page_token'] = RunObservationHistoryToken::encode(
                    $detail['run_id'], $reference['after_sequence'], $reference['sequence'],
                );
            }
        }
        unset($failure);
        $history['next_page_token'] = $history['has_more'] ? RunObservationHistoryToken::encode(
            $detail['run_id'], $history['next_sequence'], $history['through_sequence'],
        ) : null;
        $detail = RunObservationPresenter::present($detail, $history, 'v2', $token);

        $detail['search_attributes'] = $this->allowedSearchAttributes($detail['workflow_type']);
        $detail = RunApplicationContext::annotate($detail);
        unset($detail['visibility_labels'], $detail['search_attributes']);

        return $detail;
    }

    private function allowedSearchAttributes(string $type): array
    {
        $keys = RunApplicationContext::searchAttributeKeys($type);
        if ($keys === []) {
            return [];
        }
        $model = ConfiguredV2Models::resolve('search_attribute_model', WorkflowSearchAttribute::class);

        return (new $model())->setConnection($this->resource->getConnectionName())
            ->newQuery()->setEagerLoads([])->where('workflow_run_id', $this->resource->id)
            ->where('workflow_instance_id', $this->resource->workflow_instance_id)
            ->whereIn('key', $keys)->orderBy('key')->limit(20)->get()
            ->mapWithKeys(static fn (WorkflowSearchAttribute $attribute): array => [$attribute->key => $attribute->getValue()])
            ->all();
    }
}
