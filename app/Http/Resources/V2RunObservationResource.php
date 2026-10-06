<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Waterline\Support\OperatorScope;
use Waterline\Support\RunApplicationContext;
use Waterline\Support\RunObservationHistoryToken;
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
        $waits = $detail['current_waits'];
        $failures = $detail['recent_failures'];
        $parents = $detail['parents'];
        $children = $detail['children'];
        $contract = $detail['command_contract'];
        unset($detail['command_contract'], $detail['parents'], $detail['children']);
        $detail['engine_source'] = 'v2';
        $detail['read_mode'] = 'bounded';
        $detail['operator_scope'] = OperatorScope::payload();
        $detail['workflow_instance_id'] = $detail['instance_id'];
        $detail['workflow_run_id'] = $detail['run_id'];
        $detail['status_bucket'] = match ($detail['status']) {
            'completed' => 'completed',
            'failed', 'cancelled', 'terminated', 'timed_out' => 'failed',
            default => 'running',
        };
        $detail['current_waits'] = $waits['waits'];
        $detail['current_waits_state'] = $waits['state'];
        $detail['current_waits_count'] = $waits['total_count'];
        $detail['current_waits_returned_count'] = $waits['returned_count'];
        $detail['current_waits_limit'] = $waits['limit'];
        $detail['current_waits_truncated'] = $waits['has_more'];
        $detail['current_waits_source'] = $waits['source'];
        $detail['current_waits_unavailable_reason'] = $waits['unavailable_reason'];
        $detail['relationships'] = ['parents' => $parents, 'children' => $children];
        $detail['recent_failures'] = array_map(static function (array $failure) use ($detail): array {
            if ($failure['supporting_event']['state'] === 'retained') {
                $reference = $failure['supporting_event'];
                $failure['supporting_event']['next_page_token'] = RunObservationHistoryToken::encode(
                    $detail['run_id'], $reference['after_sequence'], $reference['sequence'],
                );
            }

            return $failure;
        }, $failures['failures']);
        $detail['recent_failures_state'] = $failures['state'];
        $detail['recent_failures_truncated'] = $failures['has_more'];
        $detail['recent_failures_total_count'] = null;
        $detail['history_state'] = $history['details_state'];
        $detail['history_window_from_start'] = $history['history_window_from_start'];
        $detail['history_start_page_token'] = $token;
        $detail['history_next_page_token'] = $history['has_more'] ? RunObservationHistoryToken::encode(
            $detail['run_id'], $history['next_sequence'], $history['through_sequence'],
        ) : null;
        $detail['timeline'] = array_map(static fn (array $event): array => array_merge($event, [
            'type' => $event['event_type'],
        ]), $history['events']);
        $detail['timeline_returned_count'] = $history['returned_count'];
        $detail['timeline_total_count'] = null;
        $detail['timeline_limit'] = $history['limit'];
        $detail['timeline_truncated'] = $history['has_more'];
        $detail['timeline_window_start_sequence'] = $history['first_sequence'];
        $detail['timeline_window_end_sequence'] = $history['last_sequence'];
        foreach (['queries', 'signals', 'updates', 'query_contracts', 'signal_contracts', 'update_contracts',
            'query_targets', 'signal_targets', 'update_targets', 'entry_method', 'entry_mode', 'entry_declaring_class'] as $key) {
            $detail['declared_'.$key] = $contract[$key];
        }
        $detail['declared_contract_source'] = $contract['source'];
        $detail['declared_contract_backfill_needed'] = $contract['backfill_needed'];
        $detail['declared_contract_backfill_available'] = $contract['backfill_available'];
        $detail['detail_sections'] = [
            'history' => 'partial', 'current_waits' => $waits['state'],
            'relationships' => 'partial', 'failures' => $failures['state'],
            'activities' => 'not_loaded', 'tasks' => 'not_loaded', 'signals' => 'not_loaded',
            'updates' => 'not_loaded', 'streams' => 'not_loaded', 'cancellation_cascade' => 'not_loaded',
        ];

        $detail['search_attributes'] = $this->allowedSearchAttributes($detail['workflow_type']);
        $detail = RunApplicationContext::annotate($detail);
        unset($detail['visibility_labels'], $detail['search_attributes']);

        return $detail;
    }

    private function allowedSearchAttributes(string $type): array
    {
        $profiles = config('waterline.observability.workflow_types', []);
        $profile = is_array($profiles) && is_array($profiles[$type] ?? null) ? $profiles[$type] : [];
        $keys = [];
        foreach (is_array($profile['fields'] ?? null) ? $profile['fields'] : [] as $field) {
            if (count($keys) >= 20) {
                break;
            }
            if (is_array($field) && ($field['source'] ?? null) === 'search_attributes'
                && is_string($field['key'] ?? null) && strlen($field['key']) <= 255) {
                $keys[$field['key']] = true;
            }
        }
        if ($keys === []) {
            return [];
        }
        $model = ConfiguredV2Models::resolve('search_attribute_model', WorkflowSearchAttribute::class);

        return (new $model())->setConnection($this->resource->getConnectionName())
            ->newQuery()->setEagerLoads([])->where('workflow_run_id', $this->resource->id)
            ->where('workflow_instance_id', $this->resource->workflow_instance_id)
            ->whereIn('key', array_keys($keys))->orderBy('key')->limit(20)->get()
            ->mapWithKeys(static fn (WorkflowSearchAttribute $attribute): array => [$attribute->key => $attribute->getValue()])
            ->all();
    }
}
