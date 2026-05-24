<?php

namespace Waterline\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Concerns\WithWorkbench;
use function Orchestra\Testbench\artisan;
use function Orchestra\Testbench\default_skeleton_path;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PDO;
use Waterline\Waterline;
use Workflow\Providers\WorkflowServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2022-01-01');

        Waterline::auth(function () {
            return true;
        });

        Waterline::$principalUsing = null;

        if ($this->shouldSkipSqlServerV2FeatureCoverage()) {
            $this->markTestSkipped(
                'Waterline v2 feature coverage runs on MySQL, PostgreSQL, and SQLite; SQL Server is scoped to legacy repository coverage until workflow v2 supports SQL Server execution paths.'
            );
        }
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
    }

    protected function defineDatabaseMigrations()
    {
        $this->app->bind('db.connector.sqlsrv', function () {
            return new class extends \Illuminate\Database\Connectors\SqlServerConnector
            {
                protected $options = [
                    PDO::ATTR_CASE => PDO::CASE_NATURAL,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
                ];
            };
        });

        $this->loadMigrationsFrom(default_skeleton_path('migrations'));
        artisan($this, 'migrate:fresh');

        $this->beforeApplicationDestroyed(function (): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                return;
            }

            artisan($this, 'migrate:rollback');
        });
    }

    protected function getPackageProviders($app)
    {
        if (! class_exists('\Workflow\Models\Model')) {
            class_alias(\Illuminate\Database\Eloquent\Model::class, '\Workflow\Models\Model');
        }

        return array_values(array_unique(array_merge(parent::getPackageProviders($app), [
            WorkflowServiceProvider::class,
            'Waterline\WaterlineServiceProvider',
            'Waterline\WaterlineApplicationServiceProvider',
        ])));
    }

    private function shouldSkipSqlServerV2FeatureCoverage(): bool
    {
        if (! str_starts_with(static::class, 'Waterline\\Tests\\Feature\\V2')) {
            return false;
        }

        return DB::connection()->getDriverName() === 'sqlsrv';
    }

    protected function ensureLegacyVisibilityColumnsPresent(): void
    {
        if (Schema::hasTable('workflow_runs')) {
            $missing = [];
            if (! Schema::hasColumn('workflow_runs', 'memo')) {
                $missing[] = 'memo';
            }
            if (! Schema::hasColumn('workflow_runs', 'search_attributes')) {
                $missing[] = 'search_attributes';
            }

            if ($missing !== []) {
                Schema::table('workflow_runs', static function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->json($column)->nullable();
                    }
                });
            }
        }

        if (Schema::hasTable('workflow_instances') && ! Schema::hasColumn('workflow_instances', 'memo')) {
            Schema::table('workflow_instances', static function (Blueprint $table): void {
                $table->json('memo')->nullable();
            });
        }
    }
}
