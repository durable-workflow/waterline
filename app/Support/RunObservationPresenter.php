<?php

namespace Waterline\Support;

final class RunObservationPresenter
{
    public static function present(array $detail, array $history, string $engine, ?string $token): array
    {
        $waits = $detail['current_waits'];
        $failures = $detail['recent_failures'];
        $parents = $detail['parents'];
        $children = $detail['children'];
        $contract = $detail['command_contract'];
        unset($detail['command_contract'], $detail['parents'], $detail['children'], $detail['history']);
        $detail['engine_source'] = $engine;
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
        $detail['recent_failures'] = $failures['failures'];
        $detail['recent_failures_state'] = $failures['state'];
        $detail['recent_failures_truncated'] = $failures['has_more'];
        $detail['recent_failures_total_count'] = null;
        $detail['history_state'] = $history['details_state'];
        $detail['history_window_from_start'] = $history['history_window_from_start'];
        $detail['history_start_page_token'] = $token;
        $detail['history_next_page_token'] = $history['next_page_token'];
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

        return $detail;
    }
}
