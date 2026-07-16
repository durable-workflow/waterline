<?php

namespace Waterline\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Concerns\WithWorkbench;
use function Orchestra\Testbench\artisan;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PDO;
use Waterline\Waterline;
use Workflow\Providers\WorkflowServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function setUp(): void
    {
        if ($this->shouldSkipSqlServerDatabaseCoverage()) {
            $this->markTestSkipped(
                'SQL Server qualification is scoped to legacy repository coverage until workflow v2 supports SQL Server execution paths.'
            );
        }

        parent::setUp();

        Carbon::setTestNow('2022-01-01');

        Waterline::auth(function () {
            return true;
        });

        Waterline::$principalUsing = null;
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
    }

    protected function defineDatabaseMigrations()
    {
        if (! $this->requiresDatabaseMigrations()) {
            return;
        }

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

        if ($this->isSqlServerQualification()) {
            artisan($this, 'migrate:fresh', [
                '--path' => $this->sqlServerMigrationPaths(),
                '--realpath' => true,
            ]);
        } else {
            artisan($this, 'migrate:fresh');
        }

        // The next database-backed test owns isolation with migrate:fresh.
        // Rolling every migration back during teardown duplicates destructive
        // DDL and transaction-log work without changing the next test's state.
    }

    protected function requiresDatabaseMigrations(): bool
    {
        return true;
    }

    protected function supportsSqlServerDatabaseQualification(): bool
    {
        return false;
    }

    protected function getPackageProviders($app)
    {
        if (! class_exists('\Workflow\Models\Model')) {
            class_alias(\Illuminate\Database\Eloquent\Model::class, '\Workflow\Models\Model');
        }

        return array_values(array_unique(array_merge(parent::getPackageProviders($app), [
            WorkflowServiceProvider::class,
            'Waterline\WaterlineServiceProvider',
            ...$this->waterlineHostApplicationProviders(),
        ])));
    }

    /**
     * @return list<class-string|string>
     */
    protected function waterlineHostApplicationProviders(): array
    {
        return [
            'Waterline\WaterlineApplicationServiceProvider',
        ];
    }

    private function shouldSkipSqlServerDatabaseCoverage(): bool
    {
        return $this->isSqlServerQualification()
            && $this->requiresDatabaseMigrations()
            && ! $this->supportsSqlServerDatabaseQualification();
    }

    private function isSqlServerQualification(): bool
    {
        return ($_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION')) === 'sqlsrv';
    }

    /**
     * @return list<string>
     */
    private function sqlServerMigrationPaths(): array
    {
        $workflowMigrations = glob(
            dirname(__DIR__).'/vendor/durable-workflow/workflow/src/migrations/2022_*.php'
        );
        $waterlineMigrations = glob(dirname(__DIR__).'/database/migrations/*.php');

        return array_values(array_merge(
            $workflowMigrations === false ? [] : $workflowMigrations,
            $waterlineMigrations === false ? [] : $waterlineMigrations,
        ));
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
