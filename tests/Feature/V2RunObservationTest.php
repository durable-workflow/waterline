<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Waterline\Tests\TestCase;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;

final class V2RunObservationTest extends TestCase
{
    public function testInitialApiReadBoundsHistoryAndChildrenAndLinksToFailureOutsideTheWindow(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');
        $run = $this->createRun('coordinator', 'completed');
        for ($i = 1; $i <= 101; ++$i) {
            $child = $this->createRun(sprintf('child-%03d', $i), $i === 1 ? 'failed' : 'running');
            WorkflowLink::query()->create([
                'id' => sprintf('link-%03d', $i), 'link_type' => 'child_workflow', 'sequence' => $i,
                'parent_workflow_instance_id' => $run->workflow_instance_id,
                'parent_workflow_run_id' => $run->id,
                'child_workflow_instance_id' => $child->workflow_instance_id,
                'child_workflow_run_id' => $child->id,
                'is_primary_parent' => true,
            ]);
        }
        $history = [];
        for ($i = 1; $i <= 1001; ++$i) {
            foreach ([$run->id, 'child-001'] as $runId) {
                $history[] = [
                    'id' => $runId.'-event-'.$i, 'workflow_run_id' => $runId, 'sequence' => $i,
                    'event_type' => $i === 1 ? 'WorkflowStarted' : 'SignalReceived',
                    'payload' => '{}', 'recorded_at' => now(),
                ];
            }
        }
        foreach (array_chunk($history, 100) as $chunk) {
            DB::table('workflow_history_events')->insert($chunk);
        }
        WorkflowFailure::query()->create([
            'id' => 'failure', 'workflow_run_id' => $run->id, 'source_kind' => 'activity',
            'source_id' => 'activity', 'propagation_kind' => 'activity', 'failure_category' => 'application',
            'exception_class' => 'RuntimeException', 'message' => 'Activity failed',
            'file' => 'fixture.php', 'line' => 12, 'trace_preview' => '',
        ]);
        WorkflowHistoryEvent::query()->create([
            'id' => 'primary-failure', 'workflow_run_id' => $run->id, 'sequence' => 1002,
            'event_type' => 'ActivityFailed', 'payload' => ['failure_id' => 'failure'], 'recorded_at' => now(),
        ]);
        $retrieved = ['runs' => 0, 'history' => 0];
        WorkflowRun::retrieved(static function (WorkflowRun $model) use (&$retrieved): void {
            ++$retrieved['runs'];
            self::assertArrayNotHasKey('arguments', $model->getAttributes());
            self::assertArrayNotHasKey('output', $model->getAttributes());
        });
        WorkflowHistoryEvent::retrieved(static function () use (&$retrieved): void {
            ++$retrieved['history'];
        });
        $path = $this->path($run);
        $response = $this->getJson($path)->assertOk()
            ->assertJsonPath('read_mode', 'bounded')
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('current_run_audit', 'not_evaluated')
            ->assertJsonPath('timeline_returned_count', 200)
            ->assertJsonPath('timeline_truncated', true)
            ->assertJsonPath('relationships.children.returned_count', 50)
            ->assertJsonPath('relationships.children.has_more', true)
            ->assertJsonPath('relationships.children.relationships.0.status', 'failed')
            ->assertJsonPath('relationships.children.relationships.1.status', 'running')
            ->assertJsonPath('recent_failures.0.supporting_event.sequence', 1002)
            ->assertJsonPath('detail_sections.activities', 'not_loaded');
        $this->assertSame(['runs' => 52, 'history' => 203], $retrieved);
        $this->assertArrayNotHasKey('arguments', $response->json());
        $this->assertArrayNotHasKey('output', $response->json());
        $token = $response->json('recent_failures.0.supporting_event.next_page_token');
        $this->getJson($path.'&history_page_token='.rawurlencode($token))->assertOk()
            ->assertJsonPath('history_window_from_start', false)
            ->assertJsonPath('timeline_returned_count', 1)
            ->assertJsonPath('timeline.0.id', 'primary-failure')
            ->assertJsonPath('history_start_page_token', $token)
            ->assertJsonPath('history_next_page_token', null);
    }

    public function testHistoryPagesRetainTheirOriginalBoundaryAndRejectCrossRunCursors(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $run = $this->createRun('pages');
        $other = $this->createRun('other');
        foreach ([1, 2, 3] as $sequence) {
            $this->event($run, $sequence);
        }
        $first = $this->getJson($this->path($run).'&history_limit=2')->assertOk();
        $token = $first->json('history_next_page_token');
        $this->event($run, 4);
        $this->getJson($this->path($run).'&history_limit=2&history_page_token='.rawurlencode($token))
            ->assertOk()->assertJsonPath('timeline_returned_count', 1)
            ->assertJsonPath('timeline.0.sequence', 3)->assertJsonPath('history_next_page_token', null);
        $this->getJson($this->path($other).'&history_page_token='.rawurlencode($token))->assertStatus(422);
        $this->getJson($this->path($run).'&history_page_token=invalid')->assertStatus(422);
        $this->getJson($this->path($run).'&history_limit=1001')->assertStatus(422);
    }

    public function testStoredPointerSelectionAndExplicitRunAreNamespaceBounded(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');
        $run = $this->createRun('selected');
        $other = $this->createRun('other', 'running', 'other-namespace');
        $this->getJson('/waterline/api/instances/'.$run->workflow_instance_id.'?observation=bounded')
            ->assertOk()->assertJsonPath('run_id', $run->id);
        $this->getJson($this->path($other))->assertNotFound();
        WorkflowInstance::query()->whereKey($run->workflow_instance_id)->update(['current_run_id' => $other->id]);
        $this->getJson('/waterline/api/instances/'.$run->workflow_instance_id.'?observation=bounded')->assertNotFound();
        $this->getJson($this->path($run))->assertOk()
            ->assertJsonPath('current_run_state', 'unavailable')->assertJsonPath('is_current_run', null);
        $this->assertSame($other->id, DB::table('workflow_instances')->where('id', $run->workflow_instance_id)->value('current_run_id'));
    }

    public function testPrunedEmptyObservationDoesNotClaimCompleteAbsence(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $run = $this->createRun('pruned', 'completed');
        $run->forceFill(['details_pruned_at' => now()])->save();
        $this->getJson($this->path($run))->assertOk()
            ->assertJsonPath('history_state', 'pruned')->assertJsonPath('timeline_total_count', null)
            ->assertJsonPath('recent_failures_state', 'pruned')->assertJsonPath('recent_failures_total_count', null)
            ->assertJsonPath('history_audit', 'not_evaluated')->assertJsonPath('current_waits_state', 'pruned');
    }

    public function testApplicationContextReadsOnlyAllowedAttributesAndDoesNotReturnRawMetadata(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.observability.workflow_types', [
            'observation.proof' => [
                'classification' => 'business_operation',
                'fields' => ['order' => ['source' => 'search_attributes', 'key' => 'order']],
                'links' => ['order' => ['url' => 'https://example.com/orders/{order}']],
            ],
        ]);
        $run = $this->createRun('context');
        $run->forceFill(['visibility_labels' => ['private' => 'private-value']])->save();
        foreach (['order' => 'order/42', 'private' => 'secret'] as $key => $value) {
            WorkflowSearchAttribute::query()->create([
                'workflow_run_id' => $run->id, 'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key, 'type' => 'keyword', 'value_keyword' => $value, 'upserted_at_sequence' => 1,
            ]);
        }
        $attributes = 0;
        WorkflowSearchAttribute::retrieved(static function () use (&$attributes): void {
            ++$attributes;
        });
        $response = $this->getJson($this->path($run))->assertOk()
            ->assertJsonPath('application_context.fields.0.value', 'order/42')
            ->assertJsonPath('application_context.links.0.url', 'https://example.com/orders/order%2F42');
        $this->assertSame(1, $attributes);
        $this->assertArrayNotHasKey('visibility_labels', $response->json());
        $this->assertArrayNotHasKey('search_attributes', $response->json());
        $this->assertStringNotContainsString('private-value', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function testAnObserverWithoutTheOptionalCapabilityRefusesTheBoundedReadWithoutAFullFallback(): void
    {
        config()->set('waterline.engine_source', 'v2');
        $run = $this->createRun('unsupported');
        $this->mock(OperatorObservabilityRepository::class);
        $events = 0;
        WorkflowHistoryEvent::retrieved(static function () use (&$events): void {
            ++$events;
        });

        $this->getJson($this->path($run))->assertStatus(501);
        $this->assertSame(0, $events);
    }

    private function createRun(string $id, string $status = 'running', string $namespace = 'default'): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'instance-'.$id, 'namespace' => $namespace, 'workflow_class' => 'observation.proof',
            'workflow_type' => 'observation.proof', 'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => $id, 'workflow_instance_id' => $instance->id, 'namespace' => $namespace,
            'workflow_class' => 'observation.proof', 'workflow_type' => 'observation.proof', 'run_number' => 1,
            'status' => $status, 'connection' => 'database', 'queue' => 'default',
        ]);
        $instance->update(['current_run_id' => $run->id]);

        return $run;
    }

    private function path(WorkflowRun $run): string
    {
        return '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'?observation=bounded';
    }

    private function event(WorkflowRun $run, int $sequence): void
    {
        WorkflowHistoryEvent::query()->create([
            'id' => 'event-'.$sequence, 'workflow_run_id' => $run->id, 'sequence' => $sequence,
            'event_type' => 'SignalReceived', 'payload' => [], 'recorded_at' => now(),
        ]);
    }
}
