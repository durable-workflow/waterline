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
            ],
            [
                'selected_run_detail' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001',
                'selected_run_query_template' => '/waterline/api/instances/counter-workflow/runs/01JCOUNTER000000000000000001/queries/{query}',
                'instance_query_template' => '/waterline/api/instances/counter-workflow/queries/{query}',
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
        $this->assertSame(['current'], $detail['observer_state']['queries']['declared']);
        $this->assertFalse($detail['observer_state']['queries']['live_results_materialized']);
        $this->assertSame(
            ObserverStateEnvelope::QUERY_STATE_LIMITATION,
            $detail['observer_state']['queries']['limitation']['reason'],
        );
    }
}
