<?php

namespace Waterline\Tests\Unit;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Waterline\Console\SignalsQueriesConformanceCommand;
use Waterline\Console\WorkerStatusConformanceCommand;
use Waterline\Console\WorkflowUpdatesConformanceCommand;
use Waterline\Http\Middleware\ControlPlaneVersion;
use Waterline\Http\Middleware\RenderApiExceptionsAsJson;
use Waterline\Http\Middleware\UseEphemeralApiSessionWhenDatabaseTableMissing;
use Waterline\Repositories\Workflow\Infrastructure\UnavailableV2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\HybridWorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryMySQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryPostgreSQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLite;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLServer;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\RuntimeConfiguration;
use Waterline\Tests\TestCase;
use Waterline\WaterlineServiceProvider;

class WaterlineServiceProviderTest extends TestCase
{
    public function testApiRoutesExcludeCsrfMiddlewareFromDefaultWebStack(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('waterline.instances.runs.query');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());

        foreach ($this->csrfMiddlewareClasses() as $csrfMiddleware) {
            $this->assertContains($csrfMiddleware, $route->excludedMiddleware());
        }

        $middleware = $this->app['router']->gatherRouteMiddleware($route);

        foreach ($middleware as $resolved) {
            $name = explode(':', (string) $resolved, 2)[0];

            foreach ($this->csrfMiddlewareClasses() as $csrfMiddleware) {
                $this->assertFalse(
                    $name === $csrfMiddleware
                    || (class_exists($name) && class_exists($csrfMiddleware) && is_subclass_of($name, $csrfMiddleware)),
                    sprintf('Waterline API route should not include CSRF middleware [%s].', $resolved)
                );
            }
        }
    }

    /**
     * @return list<class-string|string>
     */
    private function csrfMiddlewareClasses(): array
    {
        return [
            VerifyCsrfToken::class,
            PreventRequestForgery::class,
        ];
    }

    public function testProviderMergesEngineSourceIntoLegacyPublishedConfig(): void
    {
        $this->withEnvironment([
            'WATERLINE_PATH' => null,
            'WATERLINE_ENGINE_SOURCE' => null,
            'WATERLINE_NAMESPACE' => null,
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => null,
            'WATERLINE_ALLOW_UNAUTHENTICATED' => null,
        ], function (): void {
            config()->set('waterline', [
                'domain' => null,
                'path' => 'legacy-waterline',
                'middleware' => ['web'],
            ]);

            (new WaterlineServiceProvider($this->app))->register();

            $this->assertSame('legacy-waterline', config('waterline.path'));
            $this->assertSame(['web'], config('waterline.middleware'));
            $this->assertFalse(config('waterline.allow_unauthenticated'));
            $this->assertSame('auto', config('waterline.engine_source'));
        });
    }

    public function testPackageRegisterHydratesRuntimeConfigurationFromEnvironment(): void
    {
        $this->withEnvironment([
            'WATERLINE_PATH' => 'waterline',
            'WATERLINE_ENGINE_SOURCE' => 'v2',
            'WATERLINE_NAMESPACE' => 'worker-versioning-conformance',
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => 'poll',
            'WATERLINE_ALLOW_UNAUTHENTICATED' => 'true',
        ], function (): void {
            config()->set('waterline.path', 'stale-waterline');
            config()->set('waterline.engine_source', 'auto');
            config()->set('waterline.namespace', null);
            config()->set('waterline.health.task_dispatch_mode', 'queue');
            config()->set('waterline.allow_unauthenticated', false);

            (new WaterlineServiceProvider($this->app))->register();

            $this->assertSame('waterline', config('waterline.path'));
            $this->assertSame('v2', config('waterline.engine_source'));
            $this->assertSame('worker-versioning-conformance', config('waterline.namespace'));
            $this->assertSame('poll', config('waterline.health.task_dispatch_mode'));
            $this->assertTrue(config('waterline.allow_unauthenticated'));
        });
    }

    public function testPackageBootHydratesRuntimeConfigurationFromEnvironment(): void
    {
        $this->withEnvironment([
            'WATERLINE_PATH' => 'waterline',
            'WATERLINE_ENGINE_SOURCE' => 'v2',
            'WATERLINE_NAMESPACE' => 'worker-versioning-conformance',
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => 'poll',
            'WATERLINE_ALLOW_UNAUTHENTICATED' => 'true',
        ], function (): void {
            config()->set('waterline.path', 'stale-waterline');
            config()->set('waterline.engine_source', 'auto');
            config()->set('waterline.namespace', null);
            config()->set('waterline.health.task_dispatch_mode', 'queue');
            config()->set('waterline.allow_unauthenticated', false);

            (new WaterlineServiceProvider($this->app))->boot();

            $this->assertSame('waterline', config('waterline.path'));
            $this->assertSame('v2', config('waterline.engine_source'));
            $this->assertSame('worker-versioning-conformance', config('waterline.namespace'));
            $this->assertSame('poll', config('waterline.health.task_dispatch_mode'));
            $this->assertTrue(config('waterline.allow_unauthenticated'));
        });
    }

    public function testRuntimeConfigurationPromotesProcessEnvironmentForArtisanServeChild(): void
    {
        $this->withProcessEnvironmentOnly([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'durable_workflow',
            'DB_USERNAME' => 'workflow',
            'DB_PASSWORD' => 'workflow',
            'DW_STORAGE_CONNECTION' => 'mysql',
            'WATERLINE_ENGINE_SOURCE' => 'v2',
            'WATERLINE_NAMESPACE' => 'worker-versioning-conformance',
            'WATERLINE_HEALTH_TASK_DISPATCH_MODE' => 'poll',
            'WATERLINE_ALLOW_UNAUTHENTICATED' => 'true',
            'DW_V2_TASK_DISPATCH_MODE' => 'poll',
        ], function (): void {
            config()->set('waterline.engine_source', 'auto');
            config()->set('waterline.namespace', null);
            config()->set('waterline.health.task_dispatch_mode', 'queue');
            config()->set('waterline.allow_unauthenticated', false);
            config()->set('database.default', 'sqlite');
            config()->set('database.connections.mysql.host', 'stale-host');
            config()->set('database.connections.mysql.port', '3307');
            config()->set('database.connections.mysql.database', 'stale_database');
            config()->set('database.connections.mysql.username', 'stale_user');
            config()->set('database.connections.mysql.password', 'stale_password');
            config()->set('workflows.storage.connection', null);
            config()->set('workflows.v2.task_dispatch_mode', 'queue');
            $_ENV['DB_CONNECTION'] = 'sqlite';
            $_SERVER['DB_CONNECTION'] = 'sqlite';
            $_ENV['WATERLINE_ENGINE_SOURCE'] = 'auto';
            $_SERVER['WATERLINE_ENGINE_SOURCE'] = 'auto';
            $_ENV['WATERLINE_NAMESPACE'] = 'stale-namespace';
            $_SERVER['WATERLINE_NAMESPACE'] = 'stale-namespace';

            RuntimeConfiguration::hydrate();

            $this->assertSame('v2', config('waterline.engine_source'));
            $this->assertSame('worker-versioning-conformance', config('waterline.namespace'));
            $this->assertSame('poll', config('waterline.health.task_dispatch_mode'));
            $this->assertTrue(config('waterline.allow_unauthenticated'));
            $this->assertSame('mysql', config('database.default'));
            $this->assertSame('mysql', config('database.connections.mysql.host'));
            $this->assertSame('3306', config('database.connections.mysql.port'));
            $this->assertSame('durable_workflow', config('database.connections.mysql.database'));
            $this->assertSame('workflow', config('database.connections.mysql.username'));
            $this->assertSame('workflow', config('database.connections.mysql.password'));
            $this->assertSame('mysql', config('workflows.storage.connection'));
            $this->assertSame('poll', config('workflows.v2.task_dispatch_mode'));

            $this->assertSame('mysql', $_ENV['DB_CONNECTION'] ?? null);
            $this->assertSame('workflow', $_ENV['DB_PASSWORD'] ?? null);
            $this->assertSame('mysql', $_ENV['DW_STORAGE_CONNECTION'] ?? null);
            $this->assertSame('v2', $_ENV['WATERLINE_ENGINE_SOURCE'] ?? null);
            $this->assertSame('worker-versioning-conformance', $_ENV['WATERLINE_NAMESPACE'] ?? null);
            $this->assertSame('poll', $_ENV['DW_V2_TASK_DISPATCH_MODE'] ?? null);
        });
    }

    public function testRuntimeConfigurationAllowsWaterlineEnvironmentThroughLaravelServe(): void
    {
        $serveCommand = 'Illuminate\\Foundation\\Console\\ServeCommand';

        if (! class_exists($serveCommand) || ! property_exists($serveCommand, 'passthroughVariables')) {
            $this->markTestSkipped('Laravel ServeCommand passthrough variables are not available.');
        }

        $previous = $serveCommand::$passthroughVariables;

        try {
            $serveCommand::$passthroughVariables = ['APP_ENV', 'PATH'];

            RuntimeConfiguration::hydrate();

            $this->assertContains('WATERLINE_ENGINE_SOURCE', $serveCommand::$passthroughVariables);
            $this->assertContains('WATERLINE_NAMESPACE', $serveCommand::$passthroughVariables);
            $this->assertContains('WATERLINE_HEALTH_TASK_DISPATCH_MODE', $serveCommand::$passthroughVariables);
            $this->assertContains('WATERLINE_HYBRID_MIGRATION_VIEW', $serveCommand::$passthroughVariables);
            $this->assertContains('WATERLINE_WORKER_STALE_AFTER_SECONDS', $serveCommand::$passthroughVariables);
            $this->assertContains('DW_V2_TASK_DISPATCH_MODE', $serveCommand::$passthroughVariables);
            $this->assertContains('DW_WV_WATERLINE_DB_DATABASE', $serveCommand::$passthroughVariables);
            $this->assertContains('WORKFLOW_STORAGE_CONNECTION', $serveCommand::$passthroughVariables);
        } finally {
            $serveCommand::$passthroughVariables = $previous;
        }
    }

    public function testRuntimeConfigurationHydratesPublishedWorkerStatusStaleWindow(): void
    {
        $this->withEnvironment([
            'WATERLINE_WORKER_STALE_AFTER_SECONDS' => '9',
        ], function (): void {
            config()->set('waterline.worker_stale_after_seconds', null);

            RuntimeConfiguration::hydrate();

            $this->assertSame(9, config('waterline.worker_stale_after_seconds'));
        });
    }

    public function testWorkerStatusConformanceCommandDoesNotAcceptProjectionOrFixtureInputs(): void
    {
        $definition = (new WorkerStatusConformanceCommand())->getDefinition();

        foreach (['plan', 'fixture', 'projection', 'server-json', 'waterline-json', 'cli-json'] as $option) {
            $this->assertFalse($definition->hasOption($option));
        }

        foreach (['server-url', 'waterline-url', 'cli-bin', 'workflow-version', 'waterline-version'] as $option) {
            $this->assertTrue($definition->hasOption($option));
        }
    }

    public function testRuntimeConfigurationKeepsCachedDatabasePasswordWhenRuntimePasswordIsAbsent(): void
    {
        $this->withEnvironment([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'runtime-host',
            'DB_PASSWORD' => null,
        ], function (): void {
            config()->set('database.default', 'sqlite');
            config()->set('database.connections.mysql.host', 'cached-host');
            config()->set('database.connections.mysql.password', 'cached-password');

            RuntimeConfiguration::hydrate();

            $this->assertSame('mysql', config('database.default'));
            $this->assertSame('runtime-host', config('database.connections.mysql.host'));
            $this->assertSame('cached-password', config('database.connections.mysql.password'));
        });
    }

    public function testRuntimeConfigurationAcceptsExplicitEmptyRuntimeDatabasePassword(): void
    {
        $this->withProcessEnvironmentOnly([
            'DB_CONNECTION' => 'mysql',
            'DB_PASSWORD' => '',
        ], function (): void {
            config()->set('database.default', 'sqlite');
            config()->set('database.connections.mysql.password', 'cached-password');

            RuntimeConfiguration::hydrate();

            $this->assertSame('mysql', config('database.default'));
            $this->assertSame('', config('database.connections.mysql.password'));
        });
    }

    public function testRuntimeConfigurationBuildsWorkflowStorageConnectionFromWaterlineDatabaseEnvironment(): void
    {
        $this->withProcessEnvironmentOnly([
            'DW_WV_WATERLINE_DB_HOST' => 'workflow-mysql',
            'DW_WV_WATERLINE_DB_PORT' => '3307',
            'DW_WV_WATERLINE_DB_DATABASE' => 'published_worker_versioning',
            'DW_WV_WATERLINE_DB_USERNAME' => 'workflow_user',
            'DW_WV_WATERLINE_DB_PASSWORD' => '',
        ], function (): void {
            config()->set('database.default', 'sqlite');
            config()->set('database.connections.mysql.host', 'stale-host');
            config()->set('database.connections.mysql.port', '3306');
            config()->set('database.connections.mysql.database', 'stale_database');
            config()->set('database.connections.mysql.username', 'stale_user');
            config()->set('database.connections.mysql.password', 'stale_password');
            config()->set('workflows.storage.connection', null);

            RuntimeConfiguration::hydrate();

            $this->assertSame('sqlite', config('database.default'));
            $this->assertSame('waterline_workflow', config('workflows.storage.connection'));
            $this->assertSame('mysql', config('database.connections.waterline_workflow.driver'));
            $this->assertSame('workflow-mysql', config('database.connections.waterline_workflow.host'));
            $this->assertSame('3307', config('database.connections.waterline_workflow.port'));
            $this->assertSame('published_worker_versioning', config('database.connections.waterline_workflow.database'));
            $this->assertSame('workflow_user', config('database.connections.waterline_workflow.username'));
            $this->assertSame('', config('database.connections.waterline_workflow.password'));

            $this->assertSame('workflow-mysql', $_ENV['DW_WV_WATERLINE_DB_HOST'] ?? null);
        });
    }

    public function testAutoEngineSourceUsesHybridRepositoryWhenBothOperatorSchemasAreAvailable(): void
    {
        config()->set('waterline.engine_source', 'auto');

        $repository = $this->app->make(WorkflowRepositoryInterface::class);

        $this->assertInstanceOf(HybridWorkflowRepository::class, $repository);
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

        $this->assertContains(RenderApiExceptionsAsJson::class, $healthMiddleware);
        $this->assertContains(ControlPlaneVersion::class, $healthMiddleware);
        $this->assertContains(UseEphemeralApiSessionWhenDatabaseTableMissing::class, $healthMiddleware);
        $this->assertContains('web', $healthMiddleware);
        $this->assertLessThan(array_search(ControlPlaneVersion::class, $healthMiddleware, true), array_search(RenderApiExceptionsAsJson::class, $healthMiddleware, true));
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

        $this->assertFalse(config('waterline.workflow_package_api_floor.active'));
        $this->assertTrue(config('waterline.workflow_package_api_floor.available'));
        $this->assertSame([], config('waterline.workflow_package_api_floor.missing'));
    }

    public function testBootPassesFloorGuardWhenAutoFallsBackToV1(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        (new WaterlineServiceProvider($this->app))->boot();

        $this->assertFalse(config('waterline.workflow_package_api_floor.active'));
        $this->assertTrue(config('waterline.workflow_package_api_floor.available'));
        $this->assertSame([], config('waterline.workflow_package_api_floor.missing'));
    }

    public function testBootPassesFloorGuardWhenResolvedToV2OnCurrentWorkflowPackage(): void
    {
        config()->set('waterline.engine_source', 'v2');

        (new WaterlineServiceProvider($this->app))->boot();

        $this->assertTrue(config('waterline.workflow_package_api_floor.active'));
        $this->assertTrue(config('waterline.workflow_package_api_floor.available'));
        $this->assertSame([], config('waterline.workflow_package_api_floor.missing'));
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

    public function testWorkflowUpdatesConformanceCommandDoesNotParseRoutePlaceholdersAsArguments(): void
    {
        $definition = (new WorkflowUpdatesConformanceCommand())->getDefinition();

        $this->assertFalse($definition->hasArgument('instance'));
        $this->assertFalse($definition->hasArgument('run'));
        $this->assertFalse($definition->hasArgument('update'));
        $this->assertTrue($definition->hasOption('selected-run-detail-capture'));
        $this->assertTrue($definition->hasOption('selected-run-history-capture'));
        $this->assertTrue($definition->hasOption('selected-run-history-export-capture'));
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

    private function withEnvironment(array $values, callable $callback): void
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $state) {
                if ($state['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['getenv']);
                }

                if ($state['env_exists']) {
                    $_ENV[$key] = $state['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$key] = $state['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }

    private function withProcessEnvironmentOnly(array $values, callable $callback): void
    {
        $previous = [];

        foreach ($values as $key => $value) {
            $previous[$key] = [
                'getenv' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];

            putenv($key.'='.$value);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $state) {
                if ($state['getenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['getenv']);
                }

                if ($state['env_exists']) {
                    $_ENV[$key] = $state['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$key] = $state['server'];
                } else {
                    unset($_SERVER[$key]);
                }
            }
        }
    }
}

final class MissingWorkflowRun extends \Workflow\V2\Models\WorkflowRun
{
    protected $table = 'missing_workflow_runs';
}
