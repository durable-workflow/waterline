<?php

namespace Waterline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Waterline\Http\Middleware\ControlPlaneVersion;
use Waterline\Http\Middleware\UseEphemeralApiSessionWhenDatabaseTableMissing;
use Waterline\Repositories\Workflow\Infrastructure\UnavailableV2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryMySQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryPostgreSQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLite;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLServer;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Support\WorkflowPackageApiFloor;
use Workflow\Models\StoredWorkflow;

class WaterlineServiceProvider extends ServiceProvider
{
    private const WORKFLOW_PACKAGE_API_FLOOR_CONFIG = 'waterline.workflow_package_api_floor';

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->recordWorkflowPackageApiFloor();

        $this->registerRoutes();
        $this->registerResources();
        $this->defineAssetPublishing();
        $this->offerPublishing();
        $this->registerCommands();
    }

    private function recordWorkflowPackageApiFloor(): void
    {
        if (! WorkflowEngineSourceResolver::usesV2()) {
            config()->set(self::WORKFLOW_PACKAGE_API_FLOOR_CONFIG, [
                'active' => false,
                'available' => true,
                'missing' => [],
                'message' => null,
            ]);

            return;
        }

        try {
            WorkflowPackageApiFloor::assert();

            config()->set(self::WORKFLOW_PACKAGE_API_FLOOR_CONFIG, [
                'active' => true,
                'available' => true,
                'missing' => [],
                'message' => null,
            ]);
        } catch (Throwable $exception) {
            config()->set(self::WORKFLOW_PACKAGE_API_FLOOR_CONFIG, [
                'active' => true,
                'available' => false,
                'missing' => WorkflowPackageApiFloor::findMissing(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Register the Waterline routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        $routeOptions = [
            'domain' => config('waterline.domain', null),
            'prefix' => config('waterline.path', 'waterline'),
        ];

        Route::group(array_merge($routeOptions, [
            'middleware' => $this->apiRouteMiddleware(),
        ]), function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        Route::group(array_merge($routeOptions, [
            'middleware' => $this->webRouteMiddleware(),
        ]), function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * @return list<class-string|string>
     */
    private function webRouteMiddleware(): array
    {
        return $this->withControlPlaneVersion(
            $this->normalizeMiddleware(config('waterline.middleware', ['web']))
        );
    }

    /**
     * @return list<class-string|string>
     */
    private function apiRouteMiddleware(): array
    {
        $configured = config('waterline.api_middleware')
            ?? config('waterline.middleware', ['web']);

        return $this->withControlPlaneVersion(
            $this->withEphemeralSessionFallback($this->normalizeMiddleware($configured))
        );
    }

    /**
     * @param  list<class-string|string>  $middleware
     * @return list<class-string|string>
     */
    private function withEphemeralSessionFallback(array $middleware): array
    {
        array_unshift($middleware, UseEphemeralApiSessionWhenDatabaseTableMissing::class);

        return $middleware;
    }

    /**
     * @param  mixed  $configured
     * @return list<class-string|string>
     */
    private function normalizeMiddleware($configured): array
    {
        if ($configured === null || $configured === false || $configured === '') {
            return [];
        }

        return is_array($configured) ? array_values($configured) : [$configured];
    }

    /**
     * @param  list<class-string|string>  $middleware
     * @return list<class-string|string>
     */
    private function withControlPlaneVersion(array $middleware): array
    {
        array_unshift($middleware, ControlPlaneVersion::class);

        return $middleware;
    }

    /**
     * Register the Waterline resources.
     *
     * @return void
     */
    protected function registerResources()
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'waterline');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Define the asset publishing configuration.
     *
     * @return void
     */
    public function defineAssetPublishing()
    {
        $this->publishes([
            WATERLINE_PATH.'/public' => public_path('vendor/waterline'),
        ], ['waterline-assets', 'laravel-assets']);
    }

    /**
     * Setup the resource publishing groups for Waterline.
     *
     * @return void
     */
    protected function offerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/Providers/WaterlineServiceProvider.stub' => app_path('Providers/WaterlineServiceProvider.php'),
            ], 'waterline-provider');

			$this->publishes([
                __DIR__.'/../config/waterline.php' => config_path('waterline.php'),
            ], 'waterline-config');
        }
    }

    /**
     * Register the Waterline Artisan commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\NamespaceConformanceCommand::class,
                Console\PrincipalAttributionConformanceCommand::class,
                Console\PublishCommand::class,
                Console\SearchAttributesConformanceCommand::class,
                Console\SignalsQueriesConformanceCommand::class,
            ]);
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/waterline.php', 'waterline');
        $this->hydrateRuntimeConfigurationFromEnvironment();

        if (! defined('WATERLINE_PATH')) {
            define('WATERLINE_PATH', realpath(__DIR__.'/../'));
        }

        if (! class_exists('Workflow\Models\Model')) {
            class_alias(config('workflows.base_model', Model::class), 'Workflow\Models\Model');
        }

        // Bind the default WorkflowRepositoryInterface resolver so Waterline
        // routes work on a fresh composer install without requiring the host
        // app to publish and register WaterlineApplicationServiceProvider
        // first. Published provider subclasses that bind their own
        // implementation in register() still take precedence because bindIf()
        // defers to any pre-existing binding.
        $this->app->bindIf(WorkflowRepositoryInterface::class, static function () {
            $engineSource = WorkflowEngineSourceResolver::status();

            if (($engineSource['uses_v2'] ?? false) === true) {
                return app(V2WorkflowRepository::class);
            }

            if (($engineSource['resolved'] ?? null) === 'v2') {
                return new UnavailableV2WorkflowRepository($engineSource);
            }

            $drivers = [
                'mysql' => WorkflowRepositoryMySQL::class,
                'pgsql' => WorkflowRepositoryPostgreSQL::class,
                'sqlite' => WorkflowRepositorySQLite::class,
                'sqlsrv' => WorkflowRepositorySQLServer::class,
            ];

            $driver = DB::connection(
                (new (config('workflows.stored_workflow_model', StoredWorkflow::class)))->getConnectionName()
            )->getDriverName();

            return app($drivers[$driver] ?? WorkflowRepositoryMySQL::class);
        });
    }

    private function hydrateRuntimeConfigurationFromEnvironment(): void
    {
        $this->setStringConfigFromEnvironment('WATERLINE_DOMAIN', 'waterline.domain');
        $this->setStringConfigFromEnvironment('WATERLINE_PATH', 'waterline.path');
        $this->setStringConfigFromEnvironment('WATERLINE_ENGINE_SOURCE', 'waterline.engine_source');
        $this->setStringConfigFromEnvironment('WATERLINE_NAMESPACE', 'waterline.namespace');
        $this->setStringConfigFromEnvironment('WATERLINE_HEALTH_TASK_DISPATCH_MODE', 'waterline.health.task_dispatch_mode');
        $this->setBooleanConfigFromEnvironment('WATERLINE_ALLOW_UNAUTHENTICATED', 'waterline.allow_unauthenticated');
    }

    private function setStringConfigFromEnvironment(string $environmentKey, string $configKey): void
    {
        $value = $this->environmentValue($environmentKey);

        if ($value !== null) {
            config()->set($configKey, $value);
        }
    }

    private function setBooleanConfigFromEnvironment(string $environmentKey, string $configKey): void
    {
        $value = $this->environmentValue($environmentKey);

        if ($value === null) {
            return;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed !== null) {
            config()->set($configKey, $parsed);
        }
    }

    private function environmentValue(string $environmentKey): ?string
    {
        $values = [
            $_SERVER[$environmentKey] ?? null,
            $_ENV[$environmentKey] ?? null,
            getenv($environmentKey),
        ];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
