<?php

namespace Waterline\Tests\Unit;

use Waterline\Tests\TestCase;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLite;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\WaterlineServiceProvider;

class WaterlineServiceProviderTest extends TestCase
{
    public function testProviderMergesEngineSourceIntoLegacyPublishedConfig(): void
    {
        config()->set('waterline', [
            'domain' => null,
            'path' => 'legacy-waterline',
            'middleware' => ['web'],
        ]);

        (new WaterlineServiceProvider($this->app))->register();

        $this->assertSame('legacy-waterline', config('waterline.path'));
        $this->assertSame(['web'], config('waterline.middleware'));
        $this->assertSame('auto', config('waterline.engine_source'));
    }

    public function testAutoEngineSourceUsesV2RepositoryWhenWorkflowOperatorSurfaceIsAvailable(): void
    {
        config()->set('waterline.engine_source', 'auto');

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf(V2WorkflowRepository::class, $repository);
    }

    public function testAutoEngineSourceFallsBackToLegacyRepositoryWhenWorkflowOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf(WorkflowRepositorySQLite::class, $repository);
    }

    public function testExplicitV1EngineSourceStaysOnLegacyRepository(): void
    {
        config()->set('waterline.engine_source', 'v1');

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf(WorkflowRepositorySQLite::class, $repository);
    }
}

final class MissingWorkflowRunSummary extends \Workflow\V2\Models\WorkflowRunSummary
{
    protected $table = 'missing_workflow_run_summaries';
}
