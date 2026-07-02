<?php

namespace Waterline\Tests\Unit\Support;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Waterline\Support\ObserverStateEnvelope;

class ObserverStateEnvelopeTest extends TestCase
{
    public function testItBuildsAStableObserverStateEnvelopeFromSelectedRunDetail(): void
    {
        $detail = ObserverStateEnvelope::annotateRun(
            [
                'instance_id' => 'counter-workflow',
                'run_id' => '01JCOUNTER000000000000000001',
                'status' => 'waiting',
                'status_bucket' => 'running',
                'is_terminal' => false,
                'output' => ['counter' => 8],
                'declared_queries' => ['current'],
                'declared_query_targets' => [
                    [
                        'name' => 'current',
                        'has_contract' => true,
                        'parameters' => [],
                    ],
                ],
                'signals' => [
                    [
                        'id' => 'signal-1',
                        'command_id' => 'command-1',
                        'command_sequence' => 1,
                        'workflow_sequence' => 2,
                        'name' => 'increment',
                        'status' => 'received',
                        'outcome' => 'signal_received',
                        'arguments_available' => true,
                        'arguments' => [3],
                        'received_at' => '2026-05-19T01:00:00+00:00',
                        'applied_at' => '2026-05-19T01:00:01+00:00',
                    ],
                    [
                        'id' => 'signal-2',
                        'command_id' => 'command-2',
                        'command_sequence' => 2,
                        'workflow_sequence' => 3,
                        'name' => 'increment',
                        'status' => 'received',
                        'outcome' => 'signal_received',
                        'arguments_available' => true,
                        'arguments' => [5],
                        'received_at' => '2026-05-19T01:00:02+00:00',
                        'applied_at' => '2026-05-19T01:00:03+00:00',
                    ],
                ],
                'updates' => [
                    [
                        'id' => 'update-1',
                        'command_id' => 'command-3',
                        'request_id' => 'req-update-1',
                        'correlation_id' => 'corr-update-1',
                        'request_fingerprint' => 'sha256:update-1',
                        'command_sequence' => 3,
                        'workflow_sequence' => 4,
                        'name' => 'approve',
                        'status' => 'completed',
                        'state_label' => 'completed',
                        'outcome' => 'update_completed',
                        'reason' => 'update_completed',
                        'arguments_available' => true,
                        'arguments' => ['order-1'],
                        'payload_available' => true,
                        'payload' => ['name' => 'approve', 'arguments' => ['order-1']],
                        'result_available' => true,
                        'result' => ['approved' => true],
                        'error_available' => false,
                        'history_event_ids' => ['history-1', 'history-2'],
                        'history_event_sequences' => [4, 5],
                        'history_event_types' => ['UpdateAccepted', 'UpdateCompleted'],
                    ],
                ],
            ],
            [
                'selected_run_detail' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001',
                'selected_run_history_export' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001/history-export',
                'selected_run_query_template' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001/queries/{query}',
                'selected_run_update_template' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001/updates/{update}',
                'selected_run_update_lookup_template' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001/updates/{updateId}',
                'instance_query_template' => '/waterline/api/instances/counter-workflow/queries/{query}',
                'instance_update_template' => '/waterline/api/instances/counter-workflow/updates/{update}',
                'instance_update_lookup_template' => '/waterline/api/instances/counter-workflow/updates/{updateId}',
            ],
            CarbonImmutable::parse('2026-05-19T01:01:00Z'),
        );

        $this->assertSame('waterline.observer-state', $detail['observer_state']['schema']);
        $this->assertSame(1, $detail['observer_state']['version']);
        $this->assertSame('2026-05-19T01:01:00+00:00', $detail['observer_state']['captured_at']);
        $this->assertSame('waiting', $detail['observer_state']['selected_run']['status']);
        $this->assertSame('running', $detail['observer_state']['selected_run']['status_bucket']);
        $this->assertSame(['counter' => 8], $detail['observer_state']['selected_run']['output']);
        $this->assertSame(2, $detail['observer_state']['signals']['count']);
        $this->assertSame(2, $detail['observer_state']['signals']['accepted_count']);
        $this->assertSame(['increment'], $detail['observer_state']['signals']['names']);
        $this->assertSame([3], $detail['observer_state']['signals']['items'][0]['arguments']);
        $this->assertSame([5], $detail['observer_state']['signals']['items'][1]['arguments']);
        $this->assertSame(1, $detail['observer_state']['updates']['count']);
        $this->assertSame(1, $detail['observer_state']['updates']['state_counts']['completed']);
        $this->assertSame(['approve'], $detail['observer_state']['updates']['names']);
        $this->assertSame('req-update-1', $detail['observer_state']['updates']['items'][0]['request_id']);
        $this->assertSame(['approved' => true], $detail['observer_state']['updates']['items'][0]['result']);
        $this->assertSame(['UpdateAccepted', 'UpdateCompleted'], $detail['observer_state']['updates']['items'][0]['history_event_types']);
        $this->assertSame(
            '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001/updates/{update}',
            $detail['observer_state']['updates']['update_action_path_template'],
        );
        $this->assertSame(['current'], $detail['observer_state']['queries']['declared']);
        $this->assertFalse($detail['observer_state']['queries']['live_results_materialized']);
        $this->assertSame(
            ObserverStateEnvelope::QUERY_STATE_LIMITATION,
            $detail['observer_state']['queries']['limitation']['reason'],
        );
    }
}
