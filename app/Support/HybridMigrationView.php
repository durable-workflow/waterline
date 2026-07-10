<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Workflow\Models\StoredWorkflow;
use Workflow\Models\StoredWorkflowException;
use Workflow\Models\StoredWorkflowLog;
use Workflow\Models\StoredWorkflowSignal;

final class HybridMigrationView
{
    private const IDENTIFIER_FORMAT = 'v1:<workflow-id>';

    /**
     * @param array<string, mixed>|null $engineSource
     * @return array<string, mixed>
     */
    public static function status(?array $engineSource = null): array
    {
        $enabled = filter_var(
            config('waterline.hybrid_migration_view', true),
            FILTER_VALIDATE_BOOL,
        );
        $namespace = self::namespace();
        $tables = self::legacyTables();
        $missingTables = [];
        $inspectionError = null;

        try {
            $model = self::legacyWorkflowModel();
            $schema = $model->getConnection()->getSchemaBuilder();

            foreach ($tables as $table) {
                if (! $schema->hasTable($table)) {
                    $missingTables[] = $table;
                }
            }
        } catch (Throwable) {
            $missingTables = $tables;
            $inspectionError = 'legacy_storage_unreachable';
        }

        $primaryTableAvailable = $tables !== [] && ! in_array($tables[0], $missingTables, true);
        $schemaComplete = $missingTables === [];
        $available = $enabled && $namespace === null && $schemaComplete;
        $reason = match (true) {
            ! $enabled => 'disabled',
            $namespace !== null => 'namespace_scoped_view',
            $inspectionError !== null => $inspectionError,
            ! $primaryTableAvailable => 'legacy_schema_absent',
            ! $schemaComplete => 'legacy_schema_incomplete',
            default => 'available',
        };
        $engineSource ??= WorkflowEngineSourceResolver::status();
        $usesV2 = ($engineSource['uses_v2'] ?? false) === true;
        [$legacyWorkflowsPresent, $legacyOpenWorkflowsPresent] = self::legacyPresence($primaryTableAvailable);

        return [
            'enabled' => $enabled,
            'available' => $available,
            'active' => $available && $usesV2,
            'reason' => $reason,
            'message' => self::message($reason),
            'primary_engine' => $usesV2 ? 'v2' : ($engineSource['resolved'] ?? null),
            'legacy_engine' => 'v1',
            'legacy_engine_version' => '1.x',
            'legacy_identifier_format' => self::IDENTIFIER_FORMAT,
            'legacy_schema_present' => $primaryTableAvailable,
            'legacy_schema_complete' => $schemaComplete,
            'required_legacy_tables' => $tables,
            'missing_legacy_tables' => array_values($missingTables),
            'legacy_workflows_present' => $legacyWorkflowsPresent,
            'legacy_open_workflows_present' => $legacyOpenWorkflowsPresent,
            'namespace' => $namespace,
        ];
    }

    public static function requestIncludesLegacyRows(): bool
    {
        foreach (array_keys(request()->query()) as $key) {
            if (! in_array($key, ['page', 'sort', 'sort_direction'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function legacyTables(): array
    {
        $modelClasses = [
            config('workflows.stored_workflow_model', StoredWorkflow::class),
            config('workflows.stored_workflow_log_model', StoredWorkflowLog::class),
            config('workflows.stored_workflow_signal_model', StoredWorkflowSignal::class),
            config('workflows.stored_workflow_exception_model', StoredWorkflowException::class),
        ];
        $tables = [];

        foreach ($modelClasses as $modelClass) {
            if (is_string($modelClass) && is_a($modelClass, Model::class, true)) {
                $tables[] = (new $modelClass())->getTable();
            }
        }

        $relationshipTable = config('workflows.workflow_relationships_table', 'workflow_relationships');

        if (is_string($relationshipTable) && trim($relationshipTable) !== '') {
            $tables[] = trim($relationshipTable);
        }

        return array_values(array_unique($tables));
    }

    private static function legacyWorkflowModel(): Model
    {
        $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);

        return new $modelClass();
    }

    /**
     * @return array{0: bool|null, 1: bool|null}
     */
    private static function legacyPresence(bool $primaryTableAvailable): array
    {
        if (! $primaryTableAvailable) {
            return [null, null];
        }

        try {
            $modelClass = config('workflows.stored_workflow_model', StoredWorkflow::class);

            return [
                $modelClass::query()->exists(),
                $modelClass::query()->whereIn('status', [
                    'created',
                    'pending',
                    'running',
                    'waiting',
                ])->exists(),
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private static function namespace(): ?string
    {
        $namespace = config('waterline.namespace');

        return is_string($namespace) && trim($namespace) !== '' ? trim($namespace) : null;
    }

    private static function message(string $reason): string
    {
        return match ($reason) {
            'available' => 'Waterline can show preserved finish-on-v1 workflows beside v2 runs.',
            'disabled' => 'The finish-on-v1 migration view is disabled by configuration.',
            'namespace_scoped_view' => 'The finish-on-v1 migration view is unavailable because legacy workflows do not carry v2 namespace identity.',
            'legacy_schema_incomplete' => 'The finish-on-v1 migration view is unavailable because part of the legacy operator schema is missing.',
            'legacy_storage_unreachable' => 'The finish-on-v1 migration view is unavailable because legacy workflow storage could not be inspected.',
            default => 'No preserved v1 workflow schema is available to include in the migration view.',
        };
    }
}
