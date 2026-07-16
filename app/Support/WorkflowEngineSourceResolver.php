<?php

declare(strict_types=1);

namespace Waterline\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\ReadinessContract;
use Workflow\V2\Support\WaterlineEngineSource;

final class WorkflowEngineSourceResolver
{
    private const ENGINE_AUTO = 'auto';

    private const ENGINE_V1 = 'v1';

    private const ENGINE_V2 = 'v2';

    // The operator bridge can still serve health, running-list, and selected-run
    // detail from durable instance/run/history state when these projections lag
    // or are absent in a package-installed Waterline host.
    private const OPTIONAL_V2_CONFIG_KEYS = [
        'activity_attempt_model',
        'activity_execution_model',
        'command_model',
        'failure_model',
        'link_model',
        'run_summary_model',
        'run_lineage_entry_model',
        'run_timer_entry_model',
        'run_timeline_entry_model',
        'run_wait_model',
        'timer_model',
    ];

    private const OPTIONAL_V2_TABLES = [
        'activity_attempts',
        'activity_executions',
        'workflow_commands',
        'workflow_failures',
        'workflow_links',
        'workflow_run_summaries',
        'workflow_run_lineage_entries',
        'workflow_run_timer_entries',
        'workflow_run_timeline_entries',
        'workflow_run_waits',
        'workflow_signal_records',
        'workflow_run_timers',
        'workflow_timers',
        'workflow_updates',
    ];

    private const CORE_V2_MODELS = [
        'instance_model' => WorkflowInstance::class,
        'run_model' => WorkflowRun::class,
        'history_event_model' => WorkflowHistoryEvent::class,
        'task_model' => WorkflowTask::class,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function status(string|null $configured = null): array
    {
        RuntimeConfiguration::hydrate();

        $configured ??= config('waterline.engine_source');
        $normalized = self::normalize($configured);

        if (class_exists(WaterlineEngineSource::class)) {
            $status = WaterlineEngineSource::status(is_string($configured) ? $configured : null);
            $repair = self::repairWorkflowStorageConnection($status, $normalized);

            if (($repair['applied'] ?? false) === true) {
                $status = WaterlineEngineSource::status(is_string($configured) ? $configured : null);
            }

            $status['storage_connection'] = self::storageConnectionDiagnostics($repair);

            return self::withReadinessIssueDiagnostics(self::allowDegradedV2OperatorSurface(
                $status,
                $normalized,
            ));
        }

        $resolved = $normalized === self::ENGINE_V2
            ? self::ENGINE_V2
            : self::ENGINE_V1;

        return self::withReadinessIssueDiagnostics([
            'configured' => $normalized,
            'resolved' => $resolved,
            'uses_v2' => $resolved === self::ENGINE_V2,
            'v2_operator_surface_available' => $resolved === self::ENGINE_V2,
            'status' => $resolved === self::ENGINE_V2 ? 'v2_pinned' : 'v1_pinned',
            'severity' => 'ok',
            'message' => $resolved === self::ENGINE_V2
                ? 'Waterline is pinned to the v2 operator bridge.'
                : 'Waterline is pinned to the legacy v1 workflow tables.',
            'issues' => [],
            'required_tables' => [],
        ]);
    }

    public static function resolve(string|null $configured = null): string
    {
        /** @var string $resolved */
        $resolved = self::status($configured)['resolved'];

        return $resolved;
    }

    public static function usesV2(string|null $configured = null): bool
    {
        return self::status($configured)['uses_v2'] === true;
    }

    private static function normalize(string|null $configured): string
    {
        $normalized = strtolower(trim((string) $configured));

        return $normalized === '' ? self::ENGINE_AUTO : $normalized;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function allowDegradedV2OperatorSurface(array $status, string $configured): array
    {
        if (($status['uses_v2'] ?? false) === true) {
            return $status;
        }

        if (! in_array($configured, [self::ENGINE_AUTO, self::ENGINE_V2], true)) {
            return $status;
        }

        $issues = is_array($status['issues'] ?? null) ? $status['issues'] : [];

        if ($issues === []) {
            return $status;
        }

        if (! self::onlyOptionalProjectionIssues($issues)
            && ! self::durableV2OperatorCoreAvailable()) {
            return $status;
        }

        return self::degradedV2Status($status, $configured);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function withReadinessIssueDiagnostics(array $status): array
    {
        $rawIssues = is_array($status['issues'] ?? null) ? array_values($status['issues']) : [];
        $readinessIssues = [];

        foreach ($rawIssues as $index => $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $readinessIssues[] = self::readinessIssueFromWorkflowIssue($issue, $index);
        }

        if (($status['v2_operator_surface_available'] ?? true) !== true) {
            $readinessIssues = array_merge(
                $readinessIssues,
                self::readinessIssuesFromStorageConnection($status['storage_connection'] ?? []),
                self::readinessIssuesFromBackendCapabilities(),
            );
        }

        $readinessIssues = self::deduplicateReadinessIssues($readinessIssues);

        $status['issues'] = self::redactedWorkflowIssues($rawIssues);
        $status['required_tables'] = self::redactedRequiredTables($status['required_tables'] ?? []);
        $status['readiness_issues'] = $readinessIssues;
        $status['readiness_issue_codes'] = array_values(array_unique(array_map(
            static fn (array $issue): string => $issue['code'],
            $readinessIssues,
        )));
        $status['readiness_issue_count'] = count($readinessIssues);

        return $status;
    }

    /**
     * @param array<string, mixed> $issue
     * @return array<string, mixed>
     */
    private static function readinessIssueFromWorkflowIssue(array $issue, int $index): array
    {
        $classification = self::classifyWorkflowIssue($issue);
        $configKey = self::safeIdentifier($issue['config_key'] ?? null);
        $table = self::safeIdentifier($issue['table'] ?? null);
        $condition = $configKey !== null
            ? 'model:'.$configKey
            : ($table !== null ? 'table:'.$table : 'operator_surface_requirement:'.($index + 1));

        return [
            'code' => $classification['code'],
            'category' => $classification['category'],
            'severity' => $classification['severity'],
            'condition' => $condition,
            'reason' => $classification['reason'],
            'summary' => $classification['summary'],
            'remediation' => $classification['remediation'],
            'config_key' => $configKey,
            'table' => $table,
        ];
    }

    /**
     * @param array<string, mixed> $issue
     * @return array{code: string, category: string, severity: string, reason: string, summary: string, remediation: string}
     */
    private static function classifyWorkflowIssue(array $issue): array
    {
        $reason = self::safeReason($issue['reason'] ?? null);

        if (in_array($reason, ['invalid_model', 'invalid_model_class', 'missing_table_name'], true)) {
            return [
                'code' => 'v2_operator_model_missing',
                'category' => 'operator_model',
                'severity' => 'error',
                'reason' => $reason,
                'summary' => 'A configured v2 operator model is missing or does not resolve to a readable table.',
                'remediation' => 'Restore the workflow package model binding or configure workflows.v2.*_model to an Eloquent model backed by the workflow v2 schema.',
            ];
        }

        if ($reason === 'schema_inspection_failed') {
            return [
                'code' => 'v2_workflow_table_unreadable',
                'category' => 'workflow_schema',
                'severity' => 'error',
                'reason' => $reason,
                'summary' => 'A required v2 workflow table could not be inspected through the effective workflow storage connection.',
                'remediation' => 'Verify that the workflow storage connection is reachable and that the application can read the workflow v2 tables.',
            ];
        }

        if ($reason === 'missing_table') {
            return [
                'code' => 'v2_workflow_table_missing',
                'category' => 'workflow_schema',
                'severity' => 'error',
                'reason' => $reason,
                'summary' => 'A required v2 workflow table is missing from the effective workflow storage connection.',
                'remediation' => 'Run the workflow v2 migrations on the shared workflow storage connection or point workflows.storage.connection at the migrated store.',
            ];
        }

        if (str_contains($reason, 'unsupported') || str_starts_with($reason, 'codec_')) {
            return [
                'code' => 'v2_backend_capability_unsupported',
                'category' => 'backend_capability',
                'severity' => 'error',
                'reason' => $reason,
                'summary' => 'A configured backend capability does not satisfy the workflow v2 operator contract.',
                'remediation' => 'Switch the affected database, queue, cache, or serializer setting to a workflow v2 supported backend before enabling the v2 operator surface.',
            ];
        }

        return [
            'code' => 'v2_operator_surface_unavailable',
            'category' => 'operator_surface',
            'severity' => 'error',
            'reason' => $reason,
            'summary' => 'The workflow package reported an incomplete v2 operator surface.',
            'remediation' => 'Check workflow v2 package readiness and storage configuration, then repair the failed readiness condition before enabling Waterline v2.',
        ];
    }

    /**
     * @param array<string, mixed>|mixed $storageConnection
     * @return list<array<string, mixed>>
     */
    private static function readinessIssuesFromStorageConnection(mixed $storageConnection): array
    {
        if (! is_array($storageConnection)) {
            return [];
        }

        $issues = [];
        $coreStatus = self::safeReason($storageConnection['core_table_status'] ?? null);
        $candidateCount = self::availableCoreTableConnectionCount($storageConnection);

        if (($storageConnection['core_tables_available'] ?? false) !== true && $candidateCount > 0) {
            $issues[] = [
                'code' => 'v2_workflow_storage_connection_mismatch',
                'category' => 'storage_connection',
                'severity' => 'error',
                'condition' => 'workflow_storage_connection',
                'reason' => 'core_tables_on_non_effective_connection',
                'summary' => 'Waterline found v2 core tables outside the effective workflow storage connection.',
                'remediation' => 'Set workflows.storage.connection to the migrated workflow store; if more than one configured connection has core tables, '
                    .'choose the intended shared storage explicitly.',
                'candidate_connection_count' => $candidateCount,
            ];
        }

        if ($coreStatus === 'connection_unavailable') {
            $issues[] = [
                'code' => 'v2_workflow_storage_unreadable',
                'category' => 'storage_connection',
                'severity' => 'error',
                'condition' => 'workflow_storage_connection',
                'reason' => $coreStatus,
                'summary' => 'The effective workflow storage connection could not be inspected for v2 core tables.',
                'remediation' => 'Verify that the configured workflow storage connection is reachable and readable from the Waterline host.',
            ];
        } elseif ($coreStatus === 'no_v2_core_tables') {
            $issues[] = [
                'code' => 'v2_workflow_storage_core_tables_missing',
                'category' => 'storage_connection',
                'severity' => 'error',
                'condition' => 'workflow_storage_connection',
                'reason' => $coreStatus,
                'summary' => 'The effective workflow storage connection has none of the v2 core workflow tables.',
                'remediation' => 'Run the workflow v2 migrations against the shared workflow storage connection or configure Waterline to use the connection '
                    .'that already contains those tables.',
            ];
        } elseif ($coreStatus === 'partial_v2_core_tables') {
            $issues[] = [
                'code' => 'v2_workflow_storage_core_tables_partial',
                'category' => 'storage_connection',
                'severity' => 'error',
                'condition' => 'workflow_storage_connection',
                'reason' => $coreStatus,
                'summary' => 'The effective workflow storage connection has only part of the v2 core workflow schema.',
                'remediation' => 'Apply the remaining workflow v2 migrations before enabling the Waterline v2 operator surface.',
            ];
        } elseif ($coreStatus === 'invalid_core_models') {
            $issues[] = [
                'code' => 'v2_operator_model_missing',
                'category' => 'operator_model',
                'severity' => 'error',
                'condition' => 'workflow_storage_connection',
                'reason' => $coreStatus,
                'summary' => 'One or more configured v2 core operator models are missing or invalid.',
                'remediation' => 'Restore the workflow package model bindings or configure workflows.v2 core models to valid Eloquent model classes.',
            ];
        }

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readinessIssuesFromBackendCapabilities(): array
    {
        if (! class_exists(BackendCapabilities::class)) {
            return [];
        }

        try {
            $snapshot = BackendCapabilities::snapshot();
        } catch (Throwable) {
            return [[
                'code' => 'v2_backend_capability_inspection_failed',
                'category' => 'backend_capability',
                'severity' => 'warning',
                'condition' => 'backend_capabilities',
                'reason' => 'inspection_failed',
                'summary' => 'Waterline could not inspect backend capability readiness.',
                'remediation' => 'Check database, queue, cache, and serializer configuration before enabling the v2 operator surface.',
            ]];
        }

        $issues = [];
        foreach (is_array($snapshot['issues'] ?? null) ? $snapshot['issues'] : [] as $issue) {
            if (! is_array($issue) || ($issue['severity'] ?? null) !== 'error') {
                continue;
            }

            $component = self::safeIdentifier($issue['component'] ?? null) ?? 'backend';
            $code = self::safeReason($issue['code'] ?? null);
            $issues[] = [
                'code' => 'v2_backend_capability_unsupported',
                'category' => 'backend_capability',
                'severity' => 'error',
                'condition' => $component.':'.$code,
                'reason' => $code,
                'summary' => 'A configured backend capability does not satisfy the workflow v2 operator contract.',
                'remediation' => 'Switch the affected database, queue, cache, or serializer setting to a workflow v2 supported backend before enabling the v2 operator surface.',
                'backend_component' => $component,
                'backend_issue_code' => $code,
            ];
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $storageConnection
     */
    private static function availableCoreTableConnectionCount(array $storageConnection): int
    {
        $count = 0;

        foreach (is_array($storageConnection['connections'] ?? null) ? $storageConnection['connections'] : [] as $connection) {
            if (is_array($connection) && ($connection['core_tables_available'] ?? false) === true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<mixed> $issues
     * @return list<array<string, mixed>>
     */
    private static function redactedWorkflowIssues(array $issues): array
    {
        $redacted = [];

        foreach ($issues as $index => $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $readinessIssue = self::readinessIssueFromWorkflowIssue($issue, $index);
            $redacted[] = [
                'code' => $readinessIssue['code'],
                'category' => $readinessIssue['category'],
                'config_key' => $readinessIssue['config_key'],
                'table' => $readinessIssue['table'],
                'reason' => $readinessIssue['reason'],
                'message' => $readinessIssue['summary'],
                'remediation' => $readinessIssue['remediation'],
            ];
        }

        return $redacted;
    }

    /**
     * @param mixed $requiredTables
     * @return list<array<string, mixed>>
     */
    private static function redactedRequiredTables(mixed $requiredTables): array
    {
        if (! is_array($requiredTables)) {
            return [];
        }

        $redacted = [];
        foreach ($requiredTables as $table) {
            if (! is_array($table)) {
                continue;
            }

            $redacted[] = [
                'config_key' => self::safeIdentifier($table['config_key'] ?? null),
                'table' => self::safeIdentifier($table['table'] ?? null),
                'available' => ($table['available'] ?? false) === true,
            ];
        }

        return $redacted;
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private static function deduplicateReadinessIssues(array $issues): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($issues as $issue) {
            $key = implode('|', [
                $issue['code'] ?? '',
                $issue['category'] ?? '',
                $issue['condition'] ?? '',
                $issue['reason'] ?? '',
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $issue;
        }

        return $deduplicated;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function degradedV2Status(array $status, string $configured): array
    {
        $status['resolved'] = self::ENGINE_V2;
        $status['uses_v2'] = true;
        $status['v2_operator_surface_available'] = true;
        $status['status'] = $configured === self::ENGINE_V2 ? 'v2_pinned_degraded' : 'v2_auto_degraded';
        $status['severity'] = 'warning';
        $status['message'] = 'Waterline is using the v2 operator bridge with degraded selected-run projections; durable run and history state remain available.';
        $status['degraded_operator_surface'] = true;
        $status['durable_operator_core_available'] = true;
        $status['readiness_contract'] = ReadinessContract::forEngineSourceStatus(
            configured: $configured,
            resolved: self::ENGINE_V2,
            usesV2: true,
            v2OperatorSurfaceAvailable: true,
        );

        return $status;
    }

    /**
     * @param list<mixed> $issues
     */
    private static function onlyOptionalProjectionIssues(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                return false;
            }

            $configKey = $issue['config_key'] ?? null;
            $table = $issue['table'] ?? null;

            if (is_string($configKey) && in_array($configKey, self::OPTIONAL_V2_CONFIG_KEYS, true)) {
                continue;
            }

            if (is_string($table) && in_array($table, self::OPTIONAL_V2_TABLES, true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private static function durableV2OperatorCoreAvailable(): bool
    {
        return self::configuredModelTableExists('workflows.v2.instance_model', WorkflowInstance::class)
            && self::configuredModelTableExists('workflows.v2.run_model', WorkflowRun::class)
            && self::configuredModelTableExists('workflows.v2.history_event_model', WorkflowHistoryEvent::class)
            && self::configuredModelTableExists('workflows.v2.task_model', WorkflowTask::class);
    }

    /**
     * @param class-string<Model> $fallback
     */
    private static function configuredModelTableExists(string $configKey, string $fallback): bool
    {
        $modelClass = config($configKey, $fallback);

        if (! is_string($modelClass)) {
            return false;
        }

        return self::modelTableExists($modelClass);
    }

    private static function modelTableExists(string $modelClass): bool
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        try {
            /** @var Model $model */
            $model = new $modelClass();

            return DB::connection($model->getConnectionName())
                ->getSchemaBuilder()
                ->hasTable($model->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private static function repairWorkflowStorageConnection(array $status, string $configured): array
    {
        if (($status['uses_v2'] ?? false) === true
            || ! in_array($configured, [self::ENGINE_AUTO, self::ENGINE_V2], true)) {
            return [
                'applied' => false,
                'reason' => 'not_needed',
            ];
        }

        $current = self::currentStorageInspection();
        if (($current['core_tables_available'] ?? false) === true) {
            return [
                'applied' => false,
                'reason' => 'current_connection_has_core_tables',
            ];
        }

        $matches = [];
        foreach (self::connectionNamesToInspect() as $connection) {
            $inspection = self::inspectConnectionForCoreTables($connection);
            if (($inspection['core_tables_available'] ?? false) === true) {
                $matches[] = $connection;
            }
        }

        $matches = array_values(array_unique($matches));
        if (count($matches) !== 1) {
            return [
                'applied' => false,
                'reason' => count($matches) === 0
                    ? 'no_configured_connection_has_core_tables'
                    : 'multiple_configured_connections_have_core_tables',
                'candidate_connections' => $matches,
            ];
        }

        config()->set('workflows.storage.connection', $matches[0]);

        return [
            'applied' => true,
            'reason' => 'selected_only_connection_with_core_tables',
            'selected_connection' => $matches[0],
            'previous_connection' => self::stringOrNull($current['effective_connection'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $repair
     * @return array<string, mixed>
     */
    private static function storageConnectionDiagnostics(array $repair = []): array
    {
        $connections = [];
        foreach (self::connectionNamesToInspect() as $connection) {
            $connections[] = self::inspectConnectionForCoreTables($connection);
        }
        $current = self::currentStorageInspection();

        return [
            'configured' => self::stringOrNull(config('workflows.storage.connection')),
            'default_connection' => self::stringOrNull(config('database.default')),
            'effective_connection' => self::effectiveStorageConnectionName(),
            'core_tables_available' => $current['core_tables_available'] ?? false,
            'core_table_status' => self::coreTableStatus($current),
            'missing_core_tables' => is_array($current['missing_tables'] ?? null)
                ? $current['missing_tables']
                : [],
            'connections' => $connections,
            'repair' => [
                'applied' => ($repair['applied'] ?? false) === true,
                'reason' => self::stringOrNull($repair['reason'] ?? null),
                'selected_connection' => self::stringOrNull($repair['selected_connection'] ?? null),
                'previous_connection' => self::stringOrNull($repair['previous_connection'] ?? null),
                'candidate_connections' => is_array($repair['candidate_connections'] ?? null)
                    ? array_values(array_filter($repair['candidate_connections'], 'is_string'))
                    : [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function currentStorageInspection(): array
    {
        return self::inspectConnectionForCoreTables(self::effectiveStorageConnectionName());
    }

    private static function effectiveStorageConnectionName(): ?string
    {
        $configured = self::stringOrNull(config('workflows.storage.connection'));

        return $configured ?? self::stringOrNull(config('database.default'));
    }

    /**
     * @return list<string|null>
     */
    private static function connectionNamesToInspect(): array
    {
        $connections = [];

        $effective = self::effectiveStorageConnectionName();
        if ($effective !== null) {
            $connections[] = $effective;
        }

        $configured = self::stringOrNull(config('workflows.storage.connection'));
        if ($configured !== null) {
            $connections[] = $configured;
        }

        $default = self::stringOrNull(config('database.default'));
        if ($default !== null) {
            $connections[] = $default;
        }

        return array_values(array_unique($connections));
    }

    /**
     * @return array<string, mixed>
     */
    private static function inspectConnectionForCoreTables(?string $connection): array
    {
        $tables = [];
        $missing = [];
        $availableCount = 0;
        $inspectionFailureCount = 0;
        $allAvailable = true;

        foreach (self::CORE_V2_MODELS as $configKey => $fallback) {
            $table = self::tableForConfiguredModel('workflows.v2.'.$configKey, $fallback);
            $available = false;
            $reason = 'missing_table';
            $message = null;

            if ($table === null) {
                $reason = 'invalid_model';
            } else {
                try {
                    $available = DB::connection($connection)
                        ->getSchemaBuilder()
                        ->hasTable($table);
                    $reason = $available ? 'available' : 'missing_table';
                    if ($available) {
                        $availableCount++;
                    }
                } catch (Throwable) {
                    $reason = 'schema_inspection_failed';
                    $message = 'Schema inspection failed while checking workflow storage table availability.';
                    $inspectionFailureCount++;
                }
            }

            $tables[] = [
                'config_key' => $configKey,
                'table' => $table,
                'available' => $available,
                'reason' => $reason,
                'message' => $message,
            ];

            if (! $available) {
                $allAvailable = false;

                if (is_string($table)) {
                    $missing[] = $table;
                }
            }
        }

        return [
            'name' => $connection,
            'driver' => self::connectionDriver($connection),
            'core_tables_available' => $allAvailable && $tables !== [],
            'core_table_status' => self::coreTableStatusFromCounts(
                count($tables),
                $availableCount,
                $inspectionFailureCount,
            ),
            'missing_tables' => array_values(array_unique($missing)),
            'tables' => $tables,
        ];
    }

    /**
     * @param class-string<Model> $fallback
     */
    private static function tableForConfiguredModel(string $configKey, string $fallback): ?string
    {
        $modelClass = config($configKey, $fallback);

        if (! is_string($modelClass)
            || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        try {
            /** @var Model $model */
            $model = new $modelClass();
            $table = $model->getTable();

            return is_string($table) && trim($table) !== '' ? trim($table) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function connectionDriver(?string $connection): ?string
    {
        try {
            return DB::connection($connection)->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $inspection
     */
    private static function coreTableStatus(array $inspection): string
    {
        if (($inspection['core_tables_available'] ?? false) === true) {
            return 'available';
        }

        $tables = is_array($inspection['tables'] ?? null) ? $inspection['tables'] : [];
        $availableCount = 0;
        $inspectionFailureCount = 0;
        foreach ($tables as $table) {
            if (! is_array($table)) {
                continue;
            }

            if (($table['available'] ?? false) === true) {
                $availableCount++;
            }

            if (($table['reason'] ?? null) === 'schema_inspection_failed') {
                $inspectionFailureCount++;
            }
        }

        return self::coreTableStatusFromCounts(count($tables), $availableCount, $inspectionFailureCount);
    }

    private static function coreTableStatusFromCounts(int $tableCount, int $availableCount, int $inspectionFailureCount): string
    {
        if ($tableCount === 0) {
            return 'invalid_core_models';
        }

        if ($inspectionFailureCount === $tableCount) {
            return 'connection_unavailable';
        }

        if ($availableCount === $tableCount) {
            return 'available';
        }

        if ($availableCount === 0) {
            return 'no_v2_core_tables';
        }

        return 'partial_v2_core_tables';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function safeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1 ? $value : null;
    }

    private static function safeReason(mixed $value): string
    {
        if (! is_string($value)) {
            return 'unknown';
        }

        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9_:-]+$/', $value) === 1 ? $value : 'unknown';
    }
}
