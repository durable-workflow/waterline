<?php

namespace Waterline\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Waterline\Http\Resources\V2StoredWorkflowResource;
use Waterline\Tests\TestCase;
use Waterline\Waterline;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class V2ExceptionSourceContextTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('waterline.engine_source', 'v2');
        $this->source = app_path('source-context-'.bin2hex(random_bytes(8)).'.php');
        file_put_contents($this->source, "<?php\n\n// source-context-marker\nthrow new Exception('failure');\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->source) && is_file($this->source)) {
            unlink($this->source);
        }
        parent::tearDown();
    }

    public function testOnlyAuthorizedFullDetailsIncludeEphemeralSourceWithNoStoreHeaders(): void
    {
        [$run, $failure] = $this->failure();
        $before = $failure->fresh()->getRawOriginal();
        $path = '/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id;
        foreach (['/waterline/api/flows/'.$run->id, $path.'?observation=complete'] as $detailPath) {
            $this->getJson($detailPath)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('exceptions.0.source_context.status', 'available')
                ->assertJsonPath('exceptions.0.source_context.start_line', 1)
                ->assertJsonPath('exceptions.0.source_context.throw_line', 4)
                ->assertJsonPath('exceptions.0.trace_preview', 'trace only')
                ->assertJsonPath('exceptions.0.code', 'trace only');
        }
        self::assertSame($before, $failure->fresh()->getRawOriginal());
        self::assertSame(0, WorkflowHistoryEvent::query()->count());
        self::assertStringNotContainsString('source-context-marker', json_encode(DB::table('workflow_failures')->get()));
        self::assertArrayNotHasKey('source_context', V2StoredWorkflowResource::make($run)->resolve(Request::create('/'))['exceptions'][0]);
        foreach ([$path.'?observation=bounded', $path.'/history-export'] as $excludedPath) {
            $this->getJson($excludedPath)->assertOk()->assertDontSee('source-context-marker');
        }
        config()->set('waterline.namespace', 'other');
        $this->getJson($path.'?observation=complete')->assertNotFound()->assertDontSee('source-context-marker');
    }

    public function testTypedHistoryAndFailureFallbackBothShowSourceWithoutWritingItToHistory(): void
    {
        [$run, $failure] = $this->failure();
        WorkflowHistoryEvent::query()->create([
            'id' => 'source-failure-event', 'workflow_run_id' => $run->id, 'sequence' => 1,
            'event_type' => 'WorkflowFailed', 'recorded_at' => now(),
            'payload' => ['failure_id' => $failure->id],
        ]);
        $history = WorkflowHistoryEvent::query()->first()->getRawOriginal();
        $this->getJson('/waterline/api/flows/'.$run->id)->assertOk()
            ->assertJsonPath('exceptions.0.history_authority', 'typed_history')
            ->assertJsonPath('exceptions.0.source_context.status', 'available');
        self::assertSame($history, WorkflowHistoryEvent::query()->first()->getRawOriginal());
    }

    public function testUnavailableSourceKeepsTraceAndUnauthenticatedAccessNeverExposesSource(): void
    {
        [$run] = $this->failure();
        $path = '/waterline/api/flows/'.$run->id;
        config()->set('waterline.source_context.enabled', false);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('exceptions.0.source_context.reason', 'disabled')
            ->assertJsonPath('exceptions.0.trace_preview', 'trace only')
            ->assertDontSee('source-context-marker');
        config()->set('waterline.source_context.enabled', true);
        config()->set('waterline.allow_unauthenticated', true);
        $this->getJson($path)->assertOk()
            ->assertJsonPath('exceptions.0.source_context.reason', 'unauthenticated')
            ->assertDontSee('source-context-marker');
        config()->set('waterline.allow_unauthenticated', false);
        Waterline::auth(static fn () => false);
        $this->getJson($path)->assertForbidden()->assertDontSee('source-context-marker');
    }

    private function failure(): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'source-instance', 'namespace' => 'default', 'workflow_class' => 'SourceWorkflow',
            'workflow_type' => 'source.workflow', 'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => 'source-run', 'workflow_instance_id' => $instance->id, 'namespace' => 'default',
            'workflow_class' => 'SourceWorkflow', 'workflow_type' => 'source.workflow', 'run_number' => 1,
            'status' => 'failed', 'connection' => 'database', 'queue' => 'default',
        ]);
        $instance->update(['current_run_id' => $run->id]);
        $failure = WorkflowFailure::query()->create([
            'id' => 'source-failure', 'workflow_run_id' => $run->id, 'source_kind' => 'workflow_task',
            'source_id' => 'task', 'propagation_kind' => 'workflow', 'handled' => false,
            'exception_class' => \RuntimeException::class, 'message' => 'failure',
            'file' => $this->source, 'line' => 4, 'trace_preview' => 'trace only',
        ]);

        return [$run, $failure];
    }
}
