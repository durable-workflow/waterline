<?php

declare(strict_types=1);

namespace Waterline\Tests\Source;

use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestOperatorCommandWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Support\CancellationCascadeView;
use Workflow\V2\WorkflowStub;

/** Source overlay qualification. Never skip a missing candidate runtime. */
final class CancellationCascadeVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(class_exists(CancellationCascadeView::class), 'The exact Native inspection source is required.');
        config(['waterline.engine_source' => 'v2', 'queue.default' => 'database']);
        Queue::fake();
    }

    public function testEmbeddedDetailUsesTheCanonicalSharedViewAndOriginalBudget(): void
    {
        [$workflow, $context] = $this->start();
        $view = CancellationCascadeView::forRun($workflow->run()->fresh());
        $before = WorkflowHistoryEvent::query()->count();
        $this->detail($workflow)->assertOk()->assertJsonPath('cancellation_cascade_supported', true)
            ->assertJsonPath('cancellation_cascade', $view)
            ->assertJsonPath('cancellation_cascade.root.root_request_id', $context->rootRequestId)
            ->assertJsonPath('cancellation_cascade.root.cleanup_deadline_at', $context->deadline()->toISOString());
        $this->assertSame($before, WorkflowHistoryEvent::query()->count());
        $this->travel(10)->seconds();
        $workflow->requestCancellation('duplicate', 300);
        $this->detail($workflow)->assertOk()->assertJsonPath('cancellation_cascade', $view);
    }

    public function testHistoricalSelectionDoesNotFollowTheCurrentRunPointer(): void
    {
        [$workflow, $context] = $this->start();
        $selected = $workflow->run()->fresh();
        $replacement = $selected->replicate(['id']);
        $replacement->run_number = $selected->run_number + 1;
        $replacement->cancellation_request_command_id = null;
        $replacement->cancellation_requested_at = null;
        $replacement->cancellation_deadline_at = null;
        $replacement->save();
        $selected->instance->forceFill(['current_run_id' => $replacement->id])->save();
        $view = $this->detail($workflow)->assertOk()
            ->assertJsonPath('selected_run_id', $selected->id)
            ->assertJsonPath('cancellation_cascade.selected_run_id', $selected->id)
            ->assertJsonPath('cancellation_cascade.root.root_request_id', $context->rootRequestId)
            ->json('cancellation_cascade');
        $this->assertStringNotContainsString($replacement->id, json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testMissingCanonicalRequestEvidenceIsVisibleAsIncomplete(): void
    {
        [$workflow] = $this->start();
        WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->delete();
        $view = $this->detail($workflow)->assertOk()->assertJsonPath('cancellation_cascade.root', null)
            ->assertJsonPath('cancellation_cascade.inspection_complete', false)->json('cancellation_cascade');
        $this->assertContains('request_history_unavailable', array_column($view['findings'], 'code'));
    }

    public function testRelatedForeignRunsAreRedactedBeforeDetailIsReturned(): void
    {
        [$workflow] = $this->start();
        [$foreign] = $this->start();
        $foreign->run()->instance->forceFill(['namespace' => 'foreign'])->save();
        $foreign->run()->forceFill(['namespace' => 'foreign'])->save();
        WorkflowLink::query()->create([
            'parent_workflow_instance_id' => $workflow->id(), 'parent_workflow_run_id' => $workflow->runId(),
            'child_workflow_instance_id' => $foreign->id(), 'child_workflow_run_id' => $foreign->runId(),
            'link_type' => 'child_workflow', 'sequence' => 99, 'is_primary_parent' => false,
        ]);
        $view = $this->detail($workflow)->assertOk()->assertJsonPath('cancellation_cascade.inspection_complete', false)
            ->assertJsonPath('cancellation_cascade.edges.0.child_run_id', null)->json('cancellation_cascade');
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($foreign->runId(), $encoded);
        $this->assertStringNotContainsString($foreign->id(), $encoded);
    }

    private function start(): array
    {
        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class);
        $workflow->start();
        $context = $workflow->requestCancellation('UI inspection', 30)->cancellationContext();
        $this->assertNotNull($context);

        return [$workflow, $context];
    }

    private function detail(WorkflowStub $workflow): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/waterline/api/instances/'.$workflow->id().'/runs/'.$workflow->runId());
    }
}
