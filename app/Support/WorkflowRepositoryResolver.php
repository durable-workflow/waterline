<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Support\Facades\DB;
use Waterline\Repositories\Workflow\Infrastructure\HybridWorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\UnavailableV2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryMySQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryPostgreSQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositoryBaseSQL;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLite;
use Waterline\Repositories\Workflow\Infrastructure\WorkflowRepositorySQLServer;
use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Workflow\Models\StoredWorkflow;

final class WorkflowRepositoryResolver
{
    /**
     * @param array<string, mixed> $engineSource
     */
    public static function resolve(array $engineSource): WorkflowRepositoryInterface
    {
        if (($engineSource['uses_v2'] ?? false) === true) {
            $v2 = app(V2WorkflowRepository::class);

            if ((HybridMigrationView::status($engineSource)['available'] ?? false) === true) {
                return new HybridWorkflowRepository($v2, self::legacy());
            }

            return $v2;
        }

        if (($engineSource['resolved'] ?? null) === 'v2') {
            return new UnavailableV2WorkflowRepository($engineSource);
        }

        return self::legacy();
    }

    private static function legacy(): WorkflowRepositoryBaseSQL
    {
        $drivers = [
            'mysql' => WorkflowRepositoryMySQL::class,
            'pgsql' => WorkflowRepositoryPostgreSQL::class,
            'sqlite' => WorkflowRepositorySQLite::class,
            'sqlsrv' => WorkflowRepositorySQLServer::class,
        ];
        $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);
        $driver = DB::connection((new $modelClass())->getConnectionName())->getDriverName();

        return app($drivers[$driver] ?? WorkflowRepositoryMySQL::class);
    }
}
