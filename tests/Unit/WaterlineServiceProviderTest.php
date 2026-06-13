<?php

namespace Waterline\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Waterline\Console\SignalsQueriesConformanceCommand;
use Waterline\Http\Middleware\ControlPlaneVersion;
use Waterline\Http\Middleware\UseEphemeralApiSessionWhenDatabaseTableMissing;
use Waterline\Repositories\Workflow\Infrastructure\UnavailableV2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryMySQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryPostgreSQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLite;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLServer;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Tests\TestCase;
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
        $this->assertFalse(config('waterline.allow_unauthenticated'));
        $this->assertSame('v1', config('waterline.engine_source'));
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
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf($this->expectedLegacyRepositoryClass(), $repository);
    }

    public function testExplicitV1EngineSourceStaysOnLegacyRepository(): void
    {
        config()->set('waterline.engine_source', 'v1');

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf($this->expectedLegacyRepositoryClass(), $repository);
    }

    public function testExplicitV2EngineSourceBindsUnavailableRepositoryWhenOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf(UnavailableV2WorkflowRepository::class, $repository);
    }

    public function testPackageRegisterBindsDefaultRepositoryWithoutApplicationProvider(): void
    {
        // Simulates a fresh composer install: the package service provider
        // is loaded via auto-discovery, but the host app has not published
        // and registered WaterlineApplicationServiceProvider yet. The
        // Waterline /api/stats and /api/flows/* routes must still resolve.
        $this->app->offsetUnset(WorkflowRepositoryInterface::class);

        (new WaterlineServiceProvider($this->app))->register();

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf(WorkflowRepositoryInterface::class, $repository);
    }

    public function testPackageRegisterLeavesHostAppBindingInPlace(): void
    {
        // When the host app (or a published WaterlineApplicationServiceProvider
        // subclass) has already bound WorkflowRepositoryInterface, the
        // package default must not overwrite it.
        $custom = $this->createMock(WorkflowRepositoryInterface::class);

        $this->app->offsetUnset(WorkflowRepositoryInterface::class);
        $this->app->bind(WorkflowRepositoryInterface::class, static fn () => $custom);

        (new WaterlineServiceProvider($this->app))->register();

        $this->assertSame($custom, $this->app->make(WorkflowRepositoryInterface::class));
    }

    public function testPackageApiRoutesGuardMissingDatabaseSessionTablesBeforeWebMiddleware(): void
    {
        $routes = $this->app['router']->getRoutes();
        $healthRoute = $routes->getByName('waterline.v2.health');
        $dashboardRoute = $routes->getByName('waterline.index');

        $this->assertNotNull($healthRoute);
        $this->assertNotNull($dashboardRoute);
        $healthMiddleware = $healthRoute->gatherMiddleware();
        $fallbackIndex = array_search(UseEphemeralApiSessionWhenDatabaseTableMissing::class, $healthMiddleware, true);
        $webIndex = array_search('web', $healthMiddleware, true);

        $this->assertContains(ControlPlaneVersion::class, $healthMiddleware);
        $this->assertContains(UseEphemeralApiSessionWhenDatabaseTableMissing::class, $healthMiddleware);
        $this->assertContains('web', $healthMiddleware);
        $this->assertIsInt($fallbackIndex);
        $this->assertIsInt($webIndex);
        $this->assertLessThan($webIndex, $fallbackIndex);
        $this->assertContains('web', $dashboardRoute->gatherMiddleware());
    }

    public function testBootPassesFloorGuardWhenPinnedToV1EvenIfV2SurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v1');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        (new WaterlineServiceProvider($this->app))->boot();

        $this->expectNotToPerformAssertions();
    }

    public function testBootPassesFloorGuardWhenAutoFallsBackToV1(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        (new WaterlineServiceProvider($this->app))->boot();

        $this->expectNotToPerformAssertions();
    }

    public function testBootPassesFloorGuardWhenResolvedToV2OnCurrentWorkflowPackage(): void
    {
        config()->set('waterline.engine_source', 'v2');

        (new WaterlineServiceProvider($this->app))->boot();

        $this->expectNotToPerformAssertions();
    }

    public function testSignalsQueriesConformanceCommandDoesNotParseRoutePlaceholdersAsArguments(): void
    {
        $definition = (new SignalsQueriesConformanceCommand())->getDefinition();

        $this->assertFalse($definition->hasArgument('instance'));
        $this->assertFalse($definition->hasArgument('run'));
        $this->assertFalse($definition->hasArgument('query'));
        $this->assertTrue($definition->hasOption('selected-run-detail-capture'));
        $this->assertTrue($definition->hasOption('selected-run-query-capture'));
        $this->assertTrue($definition->hasOption('run-id'));
        $this->assertTrue($definition->hasOption('workflow-run-id'));
    }

    private function expectedLegacyRepositoryClass(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => WorkflowRepositoryPostgreSQL::class,
            'sqlite' => WorkflowRepositorySQLite::class,
            'sqlsrv' => WorkflowRepositorySQLServer::class,
            default => WorkflowRepositoryMySQL::class,
        };
    }
}

final class MissingWorkflowRun extends \Workflow\V2\Models\WorkflowRun
{
    protected $table = 'missing_workflow_runs';
}
