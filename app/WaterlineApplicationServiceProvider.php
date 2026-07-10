<?php

namespace Waterline;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Waterline\Support\WorkflowEngineSourceResolver;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Support\WorkflowRepositoryResolver;

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

        $this->app->bind(WorkflowRepositoryInterface::class, function () {
            return WorkflowRepositoryResolver::resolve(WorkflowEngineSourceResolver::status());
        });
    }
}
