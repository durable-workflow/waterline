<?php

namespace Waterline;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;
use WeakMap;
use Waterline\Http\Middleware\ControlPlaneVersion;
use Waterline\Http\Middleware\RenderApiExceptionsAsJson;
use Waterline\Http\Middleware\UseEphemeralApiSessionWhenDatabaseTableMissing;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\RuntimeConfiguration;
use Waterline\Support\BackendConfiguration;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\ServiceModeRequirements;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Support\WorkflowPackageApiFloor;
use Waterline\Support\WorkflowRepositoryResolver;

class WaterlineServiceProvider extends ServiceProvider
{
    private const WORKFLOW_PACKAGE_API_FLOOR_CONFIG = 'waterline.workflow_package_api_floor';

    /**
     * @var WeakMap<object, true>|null
     */
    private static ?WeakMap $exceptionHandlersWithApiRenderer = null;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        RuntimeConfiguration::hydrate();
        if (! BackendConfiguration::serviceMode()) {
            $this->recordWorkflowPackageApiFloor();
        }

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
            'excluded_middleware' => $this->apiRouteExcludedMiddleware(),
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

        return $this->withApiExceptionRenderer(
            $this->withControlPlaneVersion(
                $this->withEphemeralSessionFallback($this->normalizeMiddleware($configured))
            )
        );
    }

    /**
     * @return list<class-string|string>
     */
    private function apiRouteExcludedMiddleware(): array
    {
        return [
            VerifyCsrfToken::class,
            PreventRequestForgery::class,
        ];
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
     * @param  list<class-string|string>  $middleware
     * @return list<class-string|string>
     */
    private function withApiExceptionRenderer(array $middleware): array
    {
        array_unshift($middleware, RenderApiExceptionsAsJson::class);

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
        if ($this->app->runningInConsole() && ! BackendConfiguration::serviceMode()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\NamespaceConformanceCommand::class,
                Console\PrincipalAttributionConformanceCommand::class,
                Console\PublishCommand::class,
                Console\SearchAttributesConformanceCommand::class,
                Console\SignalsQueriesConformanceCommand::class,
                Console\WorkflowUpdatesConformanceCommand::class,
                Console\WorkerStatusConformanceCommand::class,
                Console\WorkerStatusSdkWorkerCommand::class,
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
        RuntimeConfiguration::hydrate();
        $this->registerApiExceptionRenderer();

        if (! defined('WATERLINE_PATH')) {
            define('WATERLINE_PATH', realpath(__DIR__.'/../'));
        }

        if (! BackendConfiguration::serviceMode() && ! class_exists('Workflow\Models\Model')) {
            class_alias(config('workflows.base_model', Model::class), 'Workflow\Models\Model');
        }

        if (BackendConfiguration::serviceMode()) {
            ServiceModeRequirements::assertSdkInstalled();
            $this->app->singleton(RemoteBackend::class, static fn (): RemoteBackend => RemoteBackend::fromConfig());

            return;
        }

        // Bind the default WorkflowRepositoryInterface resolver so Waterline
        // routes work on a fresh composer install without requiring the host
        // app to publish and register WaterlineApplicationServiceProvider
        // first. Published provider subclasses that bind their own
        // implementation in register() still take precedence because bindIf()
        // defers to any pre-existing binding.
        $this->app->bindIf(WorkflowRepositoryInterface::class, static function () {
            return WorkflowRepositoryResolver::resolve(WorkflowEngineSourceResolver::status());
        });
    }

    private function registerApiExceptionRenderer(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
            if (! is_object($handler) || ! method_exists($handler, 'renderable')) {
                return;
            }

            self::$exceptionHandlersWithApiRenderer ??= new WeakMap();

            if (isset(self::$exceptionHandlersWithApiRenderer[$handler])) {
                return;
            }

            self::$exceptionHandlersWithApiRenderer[$handler] = true;

            $handler->renderable(function (Throwable $exception, Request $request) {
                if (! $this->isWaterlineApiRequest($request)) {
                    return null;
                }

                return $this->app
                    ->make(RenderApiExceptionsAsJson::class)
                    ->renderException($request, $exception);
            });
        });
    }

    private function isWaterlineApiRequest(Request $request): bool
    {
        $waterlinePath = trim((string) config('waterline.path', 'waterline'), '/');
        $apiPath = $waterlinePath === '' ? 'api' : $waterlinePath.'/api';
        $requestPath = trim($request->path(), '/');

        return $requestPath === $apiPath || str_starts_with($requestPath, $apiPath.'/');
    }

}
