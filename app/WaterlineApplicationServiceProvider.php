<?php

namespace Waterline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryMySQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryPostgreSQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLite;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLServer;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Workflow\Models\StoredWorkflow;

class WaterlineApplicationServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->authorization();
    }

    protected function authorization()
    {
        $this->gate();

        Waterline::auth(function ($request) {
            return Gate::check('viewWaterline', [$request->user()]) || app()->environment('local');
        });
    }

    protected function gate()
    {
        Gate::define('viewWaterline', function ($user) {
            return in_array($user->email, [
                //
            ]);
        });
    }

    public function register()
    {
        if (! class_exists('Workflow\Models\Model')) {
            class_alias(config('workflows.base_model', Model::class), 'Workflow\Models\Model');
        }

        $drivers = [
            'mysql' => WorkflowRepositoryMySQL::class,
            'pgsql' => WorkflowRepositoryPostgreSQL::class,
            'sqlite' => WorkflowRepositorySQLite::class,
            'sqlsrv' => WorkflowRepositorySQLServer::class,
        ];

        $driver = DB::connection((new (config('workflows.stored_workflow_model', StoredWorkflow::class)))->getConnectionName())->getDriverName();

        $this->app->bind(WorkflowRepositoryInterface::class, $drivers[$driver] ?? WorkflowRepositoryMySQL::class);
    }
}
