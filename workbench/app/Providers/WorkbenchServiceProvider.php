<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Workbench\App\Console\CreateTestWorkflow::class,
                \Workbench\App\Console\CreateTestParentWorkflow::class,
                \Workbench\App\Console\CreateTestContinueAsNewWorkflow::class,
            ]);
        }
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
