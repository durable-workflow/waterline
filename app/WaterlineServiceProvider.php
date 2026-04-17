<?php

namespace Waterline;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Waterline\Support\WorkflowPackageApiFloor;

class WaterlineServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Assert the installed workflow package meets the API floor
        // Waterline depends on (CommandContext-accepting schedule
        // mutations, etc.). Older v2 snapshots fail schedule routes with
        // unknown-named-parameter errors — catch that at boot instead.
        WorkflowPackageApiFloor::assert();

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
                Console\PublishCommand::class,
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
    }
}
