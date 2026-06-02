<?php

namespace Waterline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
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
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Assert the v2 API floor only when the resolved engine source is
        // v2. v1 installs and auto-mode installs that fall back to v1 must
        // continue to boot even when the v2 surface is absent or stale.
        WorkflowPackageApiFloor::assertIfActive();

        $this->registerRoutes();
        $this->registerResources();
        $this->defineAssetPublishing();
        $this->offerPublishing();
        $this->registerCommands();
    }

    /**
     * Register the Waterline routes.
     *
     * @return void
     */
    protected function registerRoutes()
    {
        Route::group([
            'domain' => config('waterline.domain', null),
            'prefix' => config('waterline.path', 'waterline'),
            'namespace' => 'Waterline\Http\Controllers',
            'middleware' => config('waterline.middleware', 'web'),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
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
}
