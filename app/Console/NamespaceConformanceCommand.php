<?php

namespace Waterline\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\RunSummarySortKey;

class NamespaceConformanceCommand extends Command
{
    protected $signature = 'waterline:namespace-conformance
        {--namespace-a=tenant-a : First tenant namespace to seed and inspect}
        {--namespace-b=tenant-b : Second tenant namespace to seed and inspect}
        {--shared-namespace=shared : Shared namespace reserved by the full namespace harness}
        {--run-id= : Stable suffix for generated fixture IDs}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--keep-fixtures : Keep generated Waterline fixture rows after the run}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Waterline namespace operator visibility conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.namespace-runtime.result';

    private const RESULT_VERSION = 1;

    /**
     * @var list<string>
     */
    private const REQUIRED_ARTIFACTS = [
        'server',
        'cli',
        'workflow-php',
        'sdk-python',
        'waterline',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_SCENARIOS = [
        'published_artifact_install_only',
        'namespace_create_update_describe_and_list',
        'workflow_cross_namespace_visibility_isolation',
        'workflow_cross_namespace_mutation_isolation',
        'php_worker_task_queue_namespace_isolation',
        'cli_namespace_context_and_default_scope',
        'sdk_namespace_selection_parity',
        'search_attribute_schema_and_value_query_isolation',
        'schedule_namespace_isolation',
        'namespace_lifecycle_cleanup_and_recreate',
        'waterline_operator_namespace_visibility',
        'nexus_explicit_cross_namespace_invocation',
        'reserved_namespace_name_refusal',
        'result_record_and_product_finding_routing',
    ];

    /**
     * @var list<string>
     */
    private const WATERLINE_SHARD_SCENARIOS = [
        'published_artifact_install_only',
        'waterline_operator_namespace_visibility',
        'result_record_and_product_finding_routing',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PUBLISHED_ARTIFACT_SOURCES = [
        'server' => [
            'docker_image',
            'docker_registry',
            'oci_image',
            'published_docker_image',
            'registry_image',
        ],
        'cli' => [
            'github_release',
            'github_release_asset',
            'install_script',
            'official_install_script',
            'published_github_release',
            'published_install_script',
            'release_asset',
        ],
        'workflow-php' => [
            'composer',
            'composer_package',
            'packagist',
            'packagist_package',
            'published_composer_package',
            'published_package',
        ],
        'sdk-python' => [
            'pip_package',
            'pypi',
            'pypi_package',
            'published_package',
            'published_pypi_package',
            'python_package',
        ],
        'waterline' => [
            'composer',
            'composer_package',
            'packagist',
            'packagist_package',
            'published_composer_package',
            'published_package',
        ],
    ];

    public function handle(HttpKernel $kernel): int
    {
        $startedAt = self::timestamp();
        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $namespaces = $this->namespaces();
        $runId = $this->runId();
        $workflowInstanceIds = [
            'a' => $this->fixtureId($runId, $namespaces['a'], 'workflow'),
            'b' => $this->fixtureId($runId, $namespaces['b'], 'workflow'),
        ];
        $scheduleIds = [
            'a' => $this->fixtureId($runId, $namespaces['a'], 'schedule'),
            'b' => $this->fixtureId($runId, $namespaces['b'], 'schedule'),
        ];

        $originalConfig = [
            'waterline.engine_source' => config('waterline.engine_source'),
            'waterline.namespace' => config('waterline.namespace'),
            'waterline.allow_unauthenticated' => config('waterline.allow_unauthenticated'),
        ];

        $fixtures = [
            'workflow_run_ids' => [],
            'workflow_instance_ids' => array_values($workflowInstanceIds),
            'schedule_ids' => array_values($scheduleIds),
        ];

        try {
            config()->set('waterline.engine_source', 'v2');
            config()->set('waterline.allow_unauthenticated', true);

            if (! (bool) $this->option('keep-fixtures')) {
                $this->cleanupFixtures($fixtures);
            }

            $tenantA = $this->createCompletedRun(
                $workflowInstanceIds['a'],
                $namespaces['a'],
                ['tenant_marker' => $namespaces['a'].'-visible'],
            );
            $tenantB = $this->createCompletedRun(
                $workflowInstanceIds['b'],
                $namespaces['b'],
                ['tenant_marker' => $namespaces['b'].'-secret'],
            );
            WorkflowRunSummary::query()->whereKey($tenantA->id)->update([
                'duration_ms' => 60000,
                'exception_count' => 1,
            ]);
            WorkflowRunSummary::query()->whereKey($tenantB->id)->update([
                'duration_ms' => 900000,
                'exception_count' => 9,
            ]);
            $scheduleA = $this->createSchedule($scheduleIds['a'], $namespaces['a']);
            $scheduleB = $this->createSchedule($scheduleIds['b'], $namespaces['b']);

            $fixtures['workflow_run_ids'] = [$tenantA->id, $tenantB->id];

            $tenantAEvidence = $this->inspectNamespace(
                $kernel,
                $namespaces['a'],
                $tenantA,
                $tenantB,
                $scheduleA,
                $scheduleB,
            );
            $tenantBEvidence = $this->inspectNamespace(
                $kernel,
                $namespaces['b'],
                $tenantB,
                $tenantA,
                $scheduleB,
                $scheduleA,
            );

            $unscopedAuthority = $this->inspectUnscopedAuthority(
                $kernel,
                $tenantA,
                $tenantB,
                $scheduleA,
                $scheduleB,
            );

            $evidence = [
                'tenant_a_scoped_views' => $tenantAEvidence,
                'tenant_b_scoped_views' => $tenantBEvidence,
                'detail_namespace_identity' => [
                    $namespaces['a'] => $tenantAEvidence['detail_namespace_identity'],
                    $namespaces['b'] => $tenantBEvidence['detail_namespace_identity'],
                ],
                'unscoped_view_authority' => $unscopedAuthority,
                'api_captures' => [
                    'tenant_a_scoped_views' => $tenantAEvidence['api_captures'] ?? [],
                    'tenant_b_scoped_views' => $tenantBEvidence['api_captures'] ?? [],
                    'unscoped_view_authority' => $unscopedAuthority['api_captures'] ?? [],
                ],
                'fixture_ids' => $fixtures,
            ];
            $evidence['operator_surface_matrix'] = $this->operatorSurfaceMatrix(
                $tenantAEvidence,
                $tenantBEvidence,
                $unscopedAuthority,
            );

            $waterlineScenarioPassed = $this->waterlineEvidencePassed($evidence);
            $waterlineScenario = [
                'scenario_id' => 'waterline_operator_namespace_visibility',
                'status' => $waterlineScenarioPassed ? 'pass' : 'fail',
                'observed_outputs' => $evidence,
                'linked_findings' => $waterlineScenarioPassed ? [] : [
                    $this->finding(
                        'waterline_operator_namespace_visibility',
                        'Waterline operator namespace visibility did not prove scoped list, detail, schedule, and search-attribute evidence.',
                        'waterline',
                    ),
                ],
            ];
        } catch (Throwable $exception) {
            $evidence = [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ];
            $waterlineScenario = [
                'scenario_id' => 'waterline_operator_namespace_visibility',
                'status' => 'fail',
                'observed_outputs' => $evidence,
                'linked_findings' => [
                    $this->finding(
                        'waterline_operator_namespace_visibility',
                        'Waterline operator namespace visibility shard failed before evidence completed.',
                        'waterline',
                    ),
                ],
            ];
        } finally {
            foreach ($originalConfig as $key => $value) {
                config()->set($key, $value);
            }

            if (! (bool) $this->option('keep-fixtures')) {
                $this->cleanupFixtures($fixtures);
            }
        }

        $finishedAt = self::timestamp();
        $scenarioResults = $this->scenarioResults(
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
            $waterlineScenario,
            $this->resultRecordScenario($artifactVersions, $startedAt, $finishedAt),
        );
        $outcome = self::scenarioOutcome($scenarioResults);
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'waterline-operator-namespace-shard',
            'outcome' => $outcome,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'namespace_topology' => [
                'namespaces' => array_values($namespaces),
            ],
            'runtime_matrix' => [
                'claimed_targets' => ['waterline_contract_surface'],
                'covered_scenarios' => self::WATERLINE_SHARD_SCENARIOS,
                'observer_paths' => [
                    'waterline-list',
                    'waterline-detail',
                    'waterline-operator-api',
                    'waterline-schedules',
                ],
            ],
            'scenario_results' => array_values($scenarioResults),
            'waterline_operator_visibility' => $evidence,
            'api_captures' => is_array($evidence['api_captures'] ?? null) ? $evidence['api_captures'] : [],
            'findings' => $this->findings($scenarioResults),
            'finding_links' => $this->findingLinks($scenarioResults),
        ];

        $this->emit($report);

        return $outcome === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{a: string, b: string, shared: string}
     */
    private function namespaces(): array
    {
        return [
            'a' => $this->stringOption('namespace-a') ?? 'tenant-a',
            'b' => $this->stringOption('namespace-b') ?? 'tenant-b',
            'shared' => $this->stringOption('shared-namespace') ?? 'shared',
        ];
    }

    private function runId(): string
    {
        $configured = $this->stringOption('run-id');
        $value = $configured !== null ? $configured : strtolower((string) Str::ulid());
        $value = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '');
        $value = trim($value, '-');

        return $value !== '' ? Str::limit($value, 32, '') : strtolower((string) Str::ulid());
    }

    private function fixtureId(string $runId, string $namespace, string $kind): string
    {
        $namespace = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $namespace) ?? 'tenant');

        return Str::limit(sprintf('waterline-ns-%s-%s-%s', $runId, $namespace, $kind), 120, '');
    }

    /**
     * @param array<string, mixed> $searchAttributes
     */
    private function createCompletedRun(string $instanceId, string $namespace, array $searchAttributes): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.waterline-namespace-conformance',
            'run_count' => 1,
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(5),
        ]);

        $runAttributes = [
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.waterline-namespace-conformance',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 2,
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ];

        if (Schema::hasColumn('workflow_runs', 'search_attributes')) {
            $runAttributes['search_attributes'] = $searchAttributes;
        }

        $run = WorkflowRun::query()->create($runAttributes);
        $instance->update(['current_run_id' => $run->id]);

        $summaryAttributes = [
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.waterline-namespace-conformance',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 240000,
            'exception_count' => 0,
            'history_event_count' => 2,
            'history_size_bytes' => 128,
            'continue_as_new_recommended' => false,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ];

        if (Schema::hasColumn('workflow_run_summaries', 'sort_timestamp')) {
            $summaryAttributes['sort_timestamp'] = now();
        }

        if (Schema::hasColumn('workflow_run_summaries', 'sort_key')) {
            $summaryAttributes['sort_key'] = RunSummarySortKey::key(
                $summaryAttributes['sort_timestamp'] ?? $run->started_at,
                $summaryAttributes['created_at'],
                $summaryAttributes['updated_at'],
                $run->id,
            );
        }

        WorkflowRunSummary::query()->create($summaryAttributes);

        foreach ($searchAttributes as $key => $value) {
            $attribute = new WorkflowSearchAttribute([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $instance->id,
                'key' => $key,
                'upserted_at_sequence' => 1,
                'inherited_from_parent' => false,
            ]);
            $attribute->setTypedValueWithInference($value);
            $attribute->save();
        }

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'workflow.waterline-namespace-conformance',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ],
            'recorded_at' => now()->subMinutes(5),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
            'payload' => ['result_available' => true],
            'recorded_at' => now()->subMinute(),
        ]);

        return $run;
    }

    private function createSchedule(string $scheduleId, string $namespace): WorkflowSchedule
    {
        $schedule = WorkflowSchedule::query()->create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => [
                'workflow_type' => 'workflow.waterline-namespace-schedule',
                'workflow_class' => 'WorkflowClass',
            ],
            'status' => ScheduleStatus::Active,
            'overlap_policy' => 'skip',
            'memo' => ['conformance' => 'waterline-namespace-operator-visibility'],
            'search_attributes' => ['tenant_marker' => $namespace.'-schedule'],
            'fires_count' => 0,
            'failures_count' => 0,
            'skipped_trigger_count' => 0,
            'jitter_seconds' => 0,
            'next_fire_at' => now()->addHour(),
        ]);

        WorkflowScheduleHistoryEvent::record(
            $schedule,
            HistoryEventType::SchedulePaused,
            [
                'reason' => $namespace.' maintenance',
                'command_context' => ['source' => 'waterline-conformance'],
            ],
        );

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectNamespace(
        HttpKernel $kernel,
        string $namespace,
        WorkflowRun $ownRun,
        WorkflowRun $foreignRun,
        WorkflowSchedule $ownSchedule,
        WorkflowSchedule $foreignSchedule,
    ): array {
        config()->set('waterline.namespace', $namespace);

        $list = $this->apiGet($kernel, '/api/flows/completed');
        $detail = $this->apiGet($kernel, '/api/flows/'.$ownRun->id);
        $foreignDetail = $this->apiGet($kernel, '/api/flows/'.$foreignRun->id);
        $stats = $this->apiGet($kernel, '/api/stats');
        $dashboard = $this->dashboardGet($kernel);
        $schedules = $this->apiGet($kernel, '/api/v2/schedules');
        $scheduleDetail = $this->apiGet($kernel, '/api/v2/schedules/'.$ownSchedule->schedule_id);
        $foreignScheduleDetail = $this->apiGet($kernel, '/api/v2/schedules/'.$foreignSchedule->schedule_id);
        $scheduleHistory = $this->apiGet($kernel, '/api/v2/schedules/'.$ownSchedule->schedule_id.'/history');

        $listJson = $list['json'];
        $detailJson = $detail['json'];
        $statsJson = $stats['json'];
        $schedulesJson = $schedules['json'];
        $scheduleDetailJson = $scheduleDetail['json'];
        $dashboardVariables = $this->dashboardVariables($dashboard['body']);

        $listRows = is_array($listJson['data'] ?? null) ? $listJson['data'] : [];
        $scheduleRows = is_array($schedulesJson['data'] ?? null) ? $schedulesJson['data'] : [];
        $ownListRow = $this->workflowRow($listRows, $ownRun->id);
        $ownScheduleRow = $this->scheduleRow($scheduleRows, $ownSchedule->schedule_id);
        $ownRunMarker = $this->workflowSearchAttributeValue($ownRun, 'tenant_marker') ?? '';
        $foreignRunMarker = $this->workflowSearchAttributeValue($foreignRun, 'tenant_marker') ?? '';
        $ownScheduleMarker = (string) data_get($ownSchedule->search_attributes, 'tenant_marker');
        $foreignScheduleMarker = (string) data_get($foreignSchedule->search_attributes, 'tenant_marker');

        return [
            'namespace' => $namespace,
            'operator_scope' => $this->operatorScope($listJson),
            'api_captures' => [
                'workflow_list' => $this->responseCapture($list),
                'workflow_detail' => $this->responseCapture($detail),
                'foreign_workflow_detail' => $this->responseCapture($foreignDetail),
                'operator_api_stats' => $this->responseCapture($stats),
                'dashboard_view' => $this->responseCapture($dashboard),
                'schedule_list' => $this->responseCapture($schedules),
                'schedule_detail' => $this->responseCapture($scheduleDetail),
                'foreign_schedule_detail' => $this->responseCapture($foreignScheduleDetail),
                'schedule_history' => $this->responseCapture($scheduleHistory),
            ],
            'workflow_list' => [
                'path' => '/api/flows/completed',
                'status' => $list['status'],
                'operator_scope' => $this->operatorScope($listJson),
                'listed_run_ids' => array_values(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : null,
                    $listRows,
                ))),
                'listed_namespaces' => array_values(array_unique(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['namespace'] ?? null) ? $row['namespace'] : null,
                    $listRows,
                )))),
                'includes_own_run' => collect($listRows)->contains('id', $ownRun->id),
                'excludes_foreign_run' => ! collect($listRows)->contains('id', $foreignRun->id),
                'search_attribute_value_visible' => data_get($ownListRow, 'search_attributes.tenant_marker'),
                'expected_search_attribute_value' => $ownRunMarker,
                'forbidden_search_attribute_value' => $foreignRunMarker,
                'foreign_search_attribute_absent' => ! $this->jsonContains($listJson, $foreignRunMarker),
                'visibility_namespace' => data_get($listJson, 'visibility_filters.applied.namespace'),
                'operator_scope_namespace' => data_get($listJson, 'operator_scope.namespace'),
                'operator_scope_authority' => data_get($listJson, 'operator_scope.authority'),
            ],
            'workflow_detail' => [
                'path' => '/api/flows/'.$ownRun->id,
                'status' => $detail['status'],
                'operator_scope' => $this->operatorScope($detailJson),
                'workflow_id' => data_get($detailJson, 'workflow_instance_id') ?? $ownRun->workflow_instance_id,
                'run_id' => data_get($detailJson, 'run_id'),
                'namespace' => data_get($detailJson, 'namespace'),
                'user_visible_state' => [
                    'status' => data_get($detailJson, 'status') ?? $ownRun->status,
                    'status_bucket' => data_get($detailJson, 'status_bucket'),
                    'closed_reason' => data_get($detailJson, 'closed_reason'),
                    'namespace' => data_get($detailJson, 'namespace'),
                ],
                'search_attribute_value_visible' => data_get($detailJson, 'search_attributes.tenant_marker'),
                'expected_search_attribute_value' => $ownRunMarker,
                'forbidden_search_attribute_value' => $foreignRunMarker,
                'foreign_search_attribute_absent' => ! $this->jsonContains($detailJson, $foreignRunMarker),
                'operator_scope_namespace' => data_get($detailJson, 'operator_scope.namespace'),
                'operator_scope_authority' => data_get($detailJson, 'operator_scope.authority'),
            ],
            'foreign_workflow_detail' => [
                'path' => '/api/flows/'.$foreignRun->id,
                'status' => $foreignDetail['status'],
                'not_found' => $foreignDetail['status'] === 404,
            ],
            'operator_api_stats' => [
                'path' => '/api/stats',
                'status' => $stats['status'],
                'operator_scope' => $this->operatorScope($statsJson),
                'flows' => data_get($statsJson, 'flows'),
                'operator_metric_runs' => data_get($statsJson, 'operator_metrics.runs.total'),
                'max_duration_workflow_id' => data_get($statsJson, 'max_duration_workflow.id'),
                'max_exceptions_workflow_id' => data_get($statsJson, 'max_exceptions_workflow.id'),
                'includes_own_run' => $this->jsonContains($statsJson, $ownRun->id),
                'flow_count_covers_fixture_run' => is_numeric(data_get($statsJson, 'flows'))
                    && (int) data_get($statsJson, 'flows') >= 1,
                'excludes_foreign_run' => ! $this->jsonContains($statsJson, $foreignRun->id),
                'operator_scope_namespace' => data_get($statsJson, 'operator_scope.namespace'),
                'operator_scope_authority' => data_get($statsJson, 'operator_scope.authority'),
            ],
            'dashboard_view' => [
                'path' => '/',
                'status' => $dashboard['status'],
                'request_path' => $dashboard['request_path'],
                'body_sha256' => hash('sha256', $dashboard['body']),
                'scope_label_visible' => str_contains($dashboard['body'], 'Scope'),
                'scope_value_visible' => str_contains($dashboard['body'], $namespace),
                'script_operator_scope' => $this->operatorScope($dashboardVariables),
                'script_operator_scope_namespace' => data_get($dashboardVariables, 'operator_scope.namespace'),
                'script_operator_scope_authority' => data_get($dashboardVariables, 'operator_scope.authority'),
            ],
            'schedule_list' => [
                'path' => '/api/v2/schedules',
                'status' => $schedules['status'],
                'operator_scope' => $this->operatorScope($schedulesJson),
                'listed_schedule_ids' => array_values(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['schedule_id'] ?? null)
                        ? $row['schedule_id']
                        : (is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : null),
                    $scheduleRows,
                ))),
                'listed_namespaces' => array_values(array_unique(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['namespace'] ?? null) ? $row['namespace'] : null,
                    $scheduleRows,
                )))),
                'includes_own_schedule' => collect($scheduleRows)->contains('schedule_id', $ownSchedule->schedule_id)
                    || collect($scheduleRows)->contains('id', $ownSchedule->schedule_id),
                'excludes_foreign_schedule' => ! collect($scheduleRows)->contains('schedule_id', $foreignSchedule->schedule_id)
                    && ! collect($scheduleRows)->contains('id', $foreignSchedule->schedule_id),
                'search_attribute_value_visible' => data_get($ownScheduleRow, 'search_attributes.tenant_marker'),
                'expected_search_attribute_value' => $ownScheduleMarker,
                'forbidden_search_attribute_value' => $foreignScheduleMarker,
                'foreign_search_attribute_absent' => ! $this->jsonContains($schedulesJson, $foreignScheduleMarker),
                'operator_scope_namespace' => data_get($schedulesJson, 'operator_scope.namespace'),
                'operator_scope_authority' => data_get($schedulesJson, 'operator_scope.authority'),
            ],
            'schedule_detail' => [
                'path' => '/api/v2/schedules/'.$ownSchedule->schedule_id,
                'status' => $scheduleDetail['status'],
                'operator_scope' => $this->operatorScope($scheduleDetailJson),
                'schedule_id' => data_get($scheduleDetailJson, 'schedule_id'),
                'namespace' => data_get($scheduleDetailJson, 'namespace'),
                'user_visible_state' => [
                    'status' => data_get($scheduleDetailJson, 'status') ?? $this->scheduleStatusValue($ownSchedule),
                    'namespace' => data_get($scheduleDetailJson, 'namespace'),
                    'next_fire_at' => data_get($scheduleDetailJson, 'next_fire_at'),
                ],
                'search_attribute_value_visible' => data_get($scheduleDetailJson, 'search_attributes.tenant_marker'),
                'expected_search_attribute_value' => $ownScheduleMarker,
                'forbidden_search_attribute_value' => $foreignScheduleMarker,
                'foreign_search_attribute_absent' => ! $this->jsonContains($scheduleDetailJson, $foreignScheduleMarker),
                'operator_scope_namespace' => data_get($scheduleDetailJson, 'operator_scope.namespace'),
                'operator_scope_authority' => data_get($scheduleDetailJson, 'operator_scope.authority'),
            ],
            'foreign_schedule_detail' => [
                'path' => '/api/v2/schedules/'.$foreignSchedule->schedule_id,
                'status' => $foreignScheduleDetail['status'],
                'not_found' => $foreignScheduleDetail['status'] === 404,
            ],
            'schedule_history' => [
                'path' => '/api/v2/schedules/'.$ownSchedule->schedule_id.'/history',
                'status' => $scheduleHistory['status'],
                'operator_scope' => $this->operatorScope($scheduleHistory['json']),
                'namespace' => data_get($scheduleHistory['json'], 'namespace'),
                'operator_scope_namespace' => data_get($scheduleHistory['json'], 'operator_scope.namespace'),
                'operator_scope_authority' => data_get($scheduleHistory['json'], 'operator_scope.authority'),
                'event_count' => is_array(data_get($scheduleHistory['json'], 'events'))
                    ? count(data_get($scheduleHistory['json'], 'events'))
                    : 0,
            ],
            'detail_namespace_identity' => data_get($detailJson, 'namespace') === $namespace
                && data_get($scheduleDetailJson, 'namespace') === $namespace,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectUnscopedAuthority(
        HttpKernel $kernel,
        WorkflowRun $tenantARun,
        WorkflowRun $tenantBRun,
        WorkflowSchedule $tenantASchedule,
        WorkflowSchedule $tenantBSchedule,
    ): array {
        config()->set('waterline.namespace', null);

        $workflowList = $this->apiGet($kernel, '/api/flows/completed');
        $stats = $this->apiGet($kernel, '/api/stats');
        $dashboard = $this->dashboardGet($kernel);
        $scheduleList = $this->apiGet($kernel, '/api/v2/schedules');
        $workflowJson = $workflowList['json'];
        $statsJson = $stats['json'];
        $dashboardVariables = $this->dashboardVariables($dashboard['body']);
        $scheduleJson = $scheduleList['json'];
        $workflowRows = is_array($workflowJson['data'] ?? null) ? $workflowJson['data'] : [];
        $scheduleRows = is_array($scheduleJson['data'] ?? null) ? $scheduleJson['data'] : [];

        return [
            'documented_safe_authority' => $this->documentsClusterAuthority($workflowJson)
                && $this->documentsClusterAuthority($scheduleJson),
            'api_captures' => [
                'workflow_list' => $this->responseCapture($workflowList),
                'operator_api_stats' => $this->responseCapture($stats),
                'dashboard_view' => $this->responseCapture($dashboard),
                'schedule_list' => $this->responseCapture($scheduleList),
            ],
            'workflow_list' => [
                'path' => '/api/flows/completed',
                'status' => $workflowList['status'],
                'operator_scope' => $this->operatorScope($workflowJson),
                'listed_run_ids' => array_values(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : null,
                    $workflowRows,
                ))),
                'listed_namespaces' => array_values(array_unique(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['namespace'] ?? null) ? $row['namespace'] : null,
                    $workflowRows,
                )))),
                'includes_tenant_a_run' => collect($workflowRows)->contains('id', $tenantARun->id),
                'includes_tenant_b_run' => collect($workflowRows)->contains('id', $tenantBRun->id),
                'tenant_a_search_attribute_visible' => data_get(
                    $this->workflowRow($workflowRows, $tenantARun->id),
                    'search_attributes.tenant_marker',
                ),
                'tenant_a_expected_search_attribute_value' => $this->workflowSearchAttributeValue($tenantARun, 'tenant_marker'),
                'tenant_b_search_attribute_visible' => data_get(
                    $this->workflowRow($workflowRows, $tenantBRun->id),
                    'search_attributes.tenant_marker',
                ),
                'tenant_b_expected_search_attribute_value' => $this->workflowSearchAttributeValue($tenantBRun, 'tenant_marker'),
            ],
            'operator_api_stats' => [
                'path' => '/api/stats',
                'status' => $stats['status'],
                'operator_scope' => $this->operatorScope($statsJson),
                'flows' => data_get($statsJson, 'flows'),
                'operator_metric_runs' => data_get($statsJson, 'operator_metrics.runs.total'),
                'flow_count_covers_fixture_runs' => is_numeric(data_get($statsJson, 'flows'))
                    && (int) data_get($statsJson, 'flows') >= 2,
            ],
            'dashboard_view' => [
                'path' => '/',
                'status' => $dashboard['status'],
                'request_path' => $dashboard['request_path'],
                'body_sha256' => hash('sha256', $dashboard['body']),
                'scope_label_visible' => str_contains($dashboard['body'], 'Scope'),
                'scope_value_visible' => str_contains($dashboard['body'], 'Cluster-wide'),
                'authority_description_visible' => str_contains(
                    $dashboard['body'],
                    'Cluster-wide Waterline scope can observe all namespaces',
                ),
                'script_operator_scope' => $this->operatorScope($dashboardVariables),
                'script_operator_scope_namespace' => data_get($dashboardVariables, 'operator_scope.namespace'),
                'script_operator_scope_authority' => data_get($dashboardVariables, 'operator_scope.authority'),
            ],
            'schedule_list' => [
                'path' => '/api/v2/schedules',
                'status' => $scheduleList['status'],
                'operator_scope' => $this->operatorScope($scheduleJson),
                'listed_schedule_ids' => array_values(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['schedule_id'] ?? null)
                        ? $row['schedule_id']
                        : (is_array($row) && is_string($row['id'] ?? null) ? $row['id'] : null),
                    $scheduleRows,
                ))),
                'listed_namespaces' => array_values(array_unique(array_filter(array_map(
                    static fn (mixed $row): ?string => is_array($row) && is_string($row['namespace'] ?? null) ? $row['namespace'] : null,
                    $scheduleRows,
                )))),
                'includes_tenant_a_schedule' => collect($scheduleRows)->contains('schedule_id', $tenantASchedule->schedule_id)
                    || collect($scheduleRows)->contains('id', $tenantASchedule->schedule_id),
                'includes_tenant_b_schedule' => collect($scheduleRows)->contains('schedule_id', $tenantBSchedule->schedule_id)
                    || collect($scheduleRows)->contains('id', $tenantBSchedule->schedule_id),
                'tenant_a_search_attribute_visible' => data_get(
                    $this->scheduleRow($scheduleRows, $tenantASchedule->schedule_id),
                    'search_attributes.tenant_marker',
                ),
                'tenant_a_expected_search_attribute_value' => data_get(
                    $tenantASchedule->search_attributes,
                    'tenant_marker',
                ),
                'tenant_b_search_attribute_visible' => data_get(
                    $this->scheduleRow($scheduleRows, $tenantBSchedule->schedule_id),
                    'search_attributes.tenant_marker',
                ),
                'tenant_b_expected_search_attribute_value' => data_get(
                    $tenantBSchedule->search_attributes,
                    'tenant_marker',
                ),
            ],
        ];
    }

    /**
     * @return array{method: string, path: string, request_path: string, status: int, json: array<string, mixed>, body: string}
     */
    private function apiGet(HttpKernel $kernel, string $path): array
    {
        return $this->httpGet($kernel, $path, 'application/json');
    }

    /**
     * @return array{method: string, path: string, request_path: string, status: int, json: array<string, mixed>, body: string}
     */
    private function dashboardGet(HttpKernel $kernel): array
    {
        return $this->httpGet($kernel, '/', 'text/html');
    }

    /**
     * @return array{method: string, path: string, request_path: string, status: int, json: array<string, mixed>, body: string}
     */
    private function httpGet(HttpKernel $kernel, string $path, string $accept): array
    {
        $requestPath = '/'.trim((string) config('waterline.path', 'waterline'), '/').$path;
        $request = Request::create(
            $requestPath,
            'GET',
            server: ['HTTP_ACCEPT' => $accept],
        );
        $response = $kernel->handle($request);
        $body = (string) $response->getContent();
        $json = [];

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $json = is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            $json = [];
        } finally {
            $kernel->terminate($request, $response);
        }

        return [
            'method' => 'GET',
            'path' => $path,
            'request_path' => $requestPath,
            'status' => $response->getStatusCode(),
            'json' => $json,
            'body' => $body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardVariables(string $body): array
    {
        if (preg_match('/window\\.Waterline\\s*=\\s*(\\{.*?\\});/s', $body, $matches) !== 1) {
            return [];
        }

        try {
            $decoded = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    /**
     * @param array{method?: string, path?: string, request_path?: string, status?: int, json?: array<string, mixed>, body?: string} $response
     * @return array<string, mixed>
     */
    private function responseCapture(array $response): array
    {
        $body = (string) ($response['body'] ?? '');
        $json = $response['json'] ?? [];

        return [
            'method' => (string) ($response['method'] ?? 'GET'),
            'path' => (string) ($response['path'] ?? ''),
            'request_path' => (string) ($response['request_path'] ?? ''),
            'status' => (int) ($response['status'] ?? 0),
            'operator_scope' => is_array($json) ? $this->operatorScope($json) : [],
            'body_sha256' => hash('sha256', $body),
            'json' => is_array($json) ? $json : [],
        ];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private function operatorScope(array $json): array
    {
        $scope = $json['operator_scope'] ?? [];

        return is_array($scope) ? $scope : [];
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, mixed>
     */
    private function workflowRow(array $rows, string $runId): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && ($row['id'] ?? null) === $runId) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, mixed>
     */
    private function scheduleRow(array $rows, string $scheduleId): array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['schedule_id'] ?? null) === $scheduleId || ($row['id'] ?? null) === $scheduleId) {
                return $row;
            }
        }

        return [];
    }

    private function workflowSearchAttributeValue(WorkflowRun $run, string $key): ?string
    {
        $inline = data_get($run->search_attributes, $key);
        if (is_scalar($inline)) {
            return (string) $inline;
        }

        $stored = WorkflowSearchAttribute::query()
            ->where('workflow_run_id', $run->id)
            ->where('key', $key)
            ->first();

        if ($stored === null) {
            return null;
        }

        $value = $stored->getValue();
        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    private function scheduleStatusValue(WorkflowSchedule $schedule): ?string
    {
        $status = $schedule->status;

        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        if (is_scalar($status)) {
            return (string) $status;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function jsonContains(array $json, string $needle): bool
    {
        return $needle !== '' && str_contains(json_encode($json, JSON_THROW_ON_ERROR), $needle);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function documentsClusterAuthority(array $json): bool
    {
        $description = data_get($json, 'operator_scope.description');

        return data_get($json, 'operator_scope.mode') === 'cluster'
            && data_get($json, 'operator_scope.authority') === 'cluster'
            && data_get($json, 'operator_scope.namespace') === null
            && is_string($description)
            && trim($description) !== '';
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function tenantApiCapturesPass(array $tenant, string $namespace): bool
    {
        foreach ([
            'workflow_list' => '/api/flows/completed',
            'workflow_detail' => (string) data_get($tenant, 'workflow_detail.path'),
            'operator_api_stats' => '/api/stats',
            'schedule_list' => '/api/v2/schedules',
            'schedule_detail' => (string) data_get($tenant, 'schedule_detail.path'),
            'schedule_history' => (string) data_get($tenant, 'schedule_history.path'),
        ] as $captureKey => $path) {
            if (! $this->captureStatusAndScopePass(
                data_get($tenant, 'api_captures.'.$captureKey),
                $path,
                200,
                'namespace',
                $namespace,
                'tenant',
            )) {
                return false;
            }
        }

        foreach ([
            'foreign_workflow_detail' => (string) data_get($tenant, 'foreign_workflow_detail.path'),
            'foreign_schedule_detail' => (string) data_get($tenant, 'foreign_schedule_detail.path'),
        ] as $captureKey => $path) {
            if (! $this->captureStatusPass(data_get($tenant, 'api_captures.'.$captureKey), $path, 404)) {
                return false;
            }
        }

        if (! $this->tenantDashboardViewPass($tenant, $namespace)) {
            return false;
        }

        foreach ([
            'workflow_list',
            'workflow_detail',
            'schedule_list',
            'schedule_detail',
        ] as $evidenceKey) {
            if (! $this->captureSearchAttributePass($tenant, $evidenceKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function unscopedApiCapturesPass(array $evidence): bool
    {
        $unscoped = $evidence['unscoped_view_authority'] ?? [];
        if (! is_array($unscoped)) {
            return false;
        }

        foreach ([
            'workflow_list' => '/api/flows/completed',
            'operator_api_stats' => '/api/stats',
            'schedule_list' => '/api/v2/schedules',
        ] as $captureKey => $path) {
            if (! $this->captureStatusAndScopePass(
                data_get($unscoped, 'api_captures.'.$captureKey),
                $path,
                200,
                'cluster',
                null,
                'cluster',
            )) {
                return false;
            }
        }

        if (! $this->unscopedDashboardViewPass($unscoped)) {
            return false;
        }

        foreach ([
            'workflow_list.tenant_a',
            'workflow_list.tenant_b',
            'schedule_list.tenant_a',
            'schedule_list.tenant_b',
        ] as $prefix) {
            $expected = data_get($unscoped, $prefix.'_expected_search_attribute_value');
            if (! is_string($expected) || $expected === '') {
                return false;
            }

            $captureKey = str_starts_with($prefix, 'workflow_list.') ? 'workflow_list' : 'schedule_list';
            $json = data_get($unscoped, 'api_captures.'.$captureKey.'.json');
            if (! is_array($json) || ! $this->jsonContains($json, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function tenantDashboardViewPass(array $tenant, string $namespace): bool
    {
        $dashboard = $tenant['dashboard_view'] ?? [];
        if (! is_array($dashboard)) {
            return false;
        }

        return data_get($dashboard, 'status') === 200
            && data_get($dashboard, 'path') === '/'
            && data_get($dashboard, 'scope_label_visible') === true
            && data_get($dashboard, 'scope_value_visible') === true
            && data_get($dashboard, 'script_operator_scope.mode') === 'namespace'
            && data_get($dashboard, 'script_operator_scope.namespace') === $namespace
            && data_get($dashboard, 'script_operator_scope.authority') === 'tenant'
            && data_get($tenant, 'api_captures.dashboard_view.status') === 200
            && data_get($tenant, 'api_captures.dashboard_view.path') === '/';
    }

    /**
     * @param array<string, mixed> $unscoped
     */
    private function unscopedDashboardViewPass(array $unscoped): bool
    {
        $dashboard = $unscoped['dashboard_view'] ?? [];
        if (! is_array($dashboard)) {
            return false;
        }

        return data_get($dashboard, 'status') === 200
            && data_get($dashboard, 'path') === '/'
            && data_get($dashboard, 'scope_label_visible') === true
            && data_get($dashboard, 'scope_value_visible') === true
            && data_get($dashboard, 'authority_description_visible') === true
            && data_get($dashboard, 'script_operator_scope.mode') === 'cluster'
            && data_get($dashboard, 'script_operator_scope.namespace') === null
            && data_get($dashboard, 'script_operator_scope.authority') === 'cluster'
            && data_get($unscoped, 'api_captures.dashboard_view.status') === 200
            && data_get($unscoped, 'api_captures.dashboard_view.path') === '/';
    }

    /**
     * @param array<string, mixed> $tenantA
     * @param array<string, mixed> $tenantB
     * @param array<string, mixed> $unscoped
     * @return array<string, mixed>
     */
    private function operatorSurfaceMatrix(array $tenantA, array $tenantB, array $unscoped): array
    {
        return [
            'tenant_scoped_surfaces' => [
                'tenant_a' => $this->tenantOperatorSurfaceVerdict($tenantA),
                'tenant_b' => $this->tenantOperatorSurfaceVerdict($tenantB),
            ],
            'unscoped_authority' => $this->unscopedOperatorSurfaceVerdict($unscoped),
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function tenantOperatorSurfaceVerdict(array $tenant): array
    {
        $namespace = $tenant['namespace'] ?? null;
        $namespace = is_string($namespace) ? $namespace : '';

        return [
            'namespace' => $namespace,
            'active_namespace_visible' => $namespace !== '' && $this->tenantDashboardViewPass($tenant, $namespace),
            'workflow_list_scoped' => data_get($tenant, 'workflow_list.status') === 200
                && data_get($tenant, 'workflow_list.operator_scope.mode') === 'namespace'
                && data_get($tenant, 'workflow_list.operator_scope.namespace') === $namespace
                && data_get($tenant, 'workflow_list.includes_own_run') === true
                && data_get($tenant, 'workflow_list.excludes_foreign_run') === true,
            'workflow_detail_scoped' => data_get($tenant, 'workflow_detail.status') === 200
                && data_get($tenant, 'workflow_detail.operator_scope.mode') === 'namespace'
                && data_get($tenant, 'workflow_detail.operator_scope.namespace') === $namespace
                && data_get($tenant, 'workflow_detail.namespace') === $namespace
                && data_get($tenant, 'foreign_workflow_detail.not_found') === true,
            'schedule_list_scoped' => data_get($tenant, 'schedule_list.status') === 200
                && data_get($tenant, 'schedule_list.operator_scope.mode') === 'namespace'
                && data_get($tenant, 'schedule_list.operator_scope.namespace') === $namespace
                && data_get($tenant, 'schedule_list.includes_own_schedule') === true
                && data_get($tenant, 'schedule_list.excludes_foreign_schedule') === true,
            'schedule_detail_scoped' => data_get($tenant, 'schedule_detail.status') === 200
                && data_get($tenant, 'schedule_detail.operator_scope.mode') === 'namespace'
                && data_get($tenant, 'schedule_detail.operator_scope.namespace') === $namespace
                && data_get($tenant, 'schedule_detail.namespace') === $namespace
                && data_get($tenant, 'foreign_schedule_detail.not_found') === true,
            'search_attribute_values_scoped' => $this->captureSearchAttributePass($tenant, 'workflow_list')
                && $this->captureSearchAttributePass($tenant, 'workflow_detail')
                && $this->captureSearchAttributePass($tenant, 'schedule_list')
                && $this->captureSearchAttributePass($tenant, 'schedule_detail'),
            'operator_api_scoped' => data_get($tenant, 'operator_api_stats.status') === 200
                && data_get($tenant, 'operator_api_stats.operator_scope.mode') === 'namespace'
                && data_get($tenant, 'operator_api_stats.operator_scope.namespace') === $namespace
                && data_get($tenant, 'operator_api_stats.excludes_foreign_run') === true,
            'api_captures_scoped' => $namespace !== '' && $this->tenantApiCapturesPass($tenant, $namespace),
        ];
    }

    /**
     * @param array<string, mixed> $unscoped
     * @return array<string, mixed>
     */
    private function unscopedOperatorSurfaceVerdict(array $unscoped): array
    {
        return [
            'documented_cluster_authority' => data_get($unscoped, 'documented_safe_authority') === true,
            'dashboard_cluster_authority_visible' => $this->unscopedDashboardViewPass($unscoped),
            'workflow_list_cluster_authority' => data_get($unscoped, 'workflow_list.status') === 200
                && data_get($unscoped, 'workflow_list.operator_scope.mode') === 'cluster'
                && data_get($unscoped, 'workflow_list.operator_scope.namespace') === null
                && data_get($unscoped, 'workflow_list.includes_tenant_a_run') === true
                && data_get($unscoped, 'workflow_list.includes_tenant_b_run') === true,
            'schedule_list_cluster_authority' => data_get($unscoped, 'schedule_list.status') === 200
                && data_get($unscoped, 'schedule_list.operator_scope.mode') === 'cluster'
                && data_get($unscoped, 'schedule_list.operator_scope.namespace') === null
                && data_get($unscoped, 'schedule_list.includes_tenant_a_schedule') === true
                && data_get($unscoped, 'schedule_list.includes_tenant_b_schedule') === true,
            'operator_api_cluster_authority' => data_get($unscoped, 'operator_api_stats.status') === 200
                && data_get($unscoped, 'operator_api_stats.operator_scope.mode') === 'cluster'
                && data_get($unscoped, 'operator_api_stats.operator_scope.namespace') === null
                && data_get($unscoped, 'operator_api_stats.flow_count_covers_fixture_runs') === true,
        ];
    }

    /**
     * @param array<string, mixed> $matrix
     */
    private function operatorSurfaceMatrixPass(array $matrix): bool
    {
        foreach (['tenant_a', 'tenant_b'] as $tenantKey) {
            $tenant = data_get($matrix, 'tenant_scoped_surfaces.'.$tenantKey);
            if (! is_array($tenant) || ! is_string($tenant['namespace'] ?? null) || $tenant['namespace'] === '') {
                return false;
            }

            foreach ([
                'active_namespace_visible',
                'workflow_list_scoped',
                'workflow_detail_scoped',
                'schedule_list_scoped',
                'schedule_detail_scoped',
                'search_attribute_values_scoped',
                'operator_api_scoped',
                'api_captures_scoped',
            ] as $field) {
                if (($tenant[$field] ?? null) !== true) {
                    return false;
                }
            }
        }

        $unscoped = data_get($matrix, 'unscoped_authority');
        if (! is_array($unscoped)) {
            return false;
        }

        foreach ([
            'documented_cluster_authority',
            'dashboard_cluster_authority_visible',
            'workflow_list_cluster_authority',
            'schedule_list_cluster_authority',
            'operator_api_cluster_authority',
        ] as $field) {
            if (($unscoped[$field] ?? null) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @return array<string, mixed>
     */
    private function publishedArtifactScenario(array $artifactVersions, array $artifactSources): array
    {
        $missingVersions = [];
        $missingSources = [];
        $rejectedVersions = [];
        $forbiddenSources = [];
        $untrustedSources = [];

        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            $version = self::artifactMetadata($artifactVersions, $artifact);
            if ($version === null) {
                $missingVersions[] = $artifact;
            } else {
                $versionReason = self::unpublishedVersionReason($version);
                if ($versionReason !== null) {
                    $rejectedVersions[$artifact] = [
                        'version' => $version,
                        'reason' => $versionReason,
                    ];
                }
            }

            $source = self::artifactMetadata($artifactSources, $artifact);
            if ($source === null) {
                $missingSources[] = $artifact;
                continue;
            }

            if (self::isLocalArtifactSource($source)) {
                $forbiddenSources[$artifact] = $source;
                continue;
            }

            if (! self::isPublishedArtifactSource($artifact, $source)) {
                $untrustedSources[$artifact] = $source;
            }
        }

        $passed = $missingVersions === []
            && $missingSources === []
            && $rejectedVersions === []
            && $forbiddenSources === []
            && $untrustedSources === [];

        return [
            'scenario_id' => 'published_artifact_install_only',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'server_image' => self::artifactMetadata($artifactVersions, 'server'),
                'cli_release' => self::artifactMetadata($artifactVersions, 'cli'),
                'workflow_php_package' => self::artifactMetadata($artifactVersions, 'workflow-php'),
                'sdk_python_package' => self::artifactMetadata($artifactVersions, 'sdk-python'),
                'waterline_artifact' => self::artifactMetadata($artifactVersions, 'waterline'),
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'missing_artifact_versions' => $missingVersions,
                'missing_artifact_sources' => $missingSources,
                'rejected_versions' => $rejectedVersions,
                'forbidden_sources' => $forbiddenSources,
                'untrusted_sources' => $untrustedSources,
                'published_artifacts_only' => $forbiddenSources === [],
                'published_install_tuple_proven' => $passed,
            ],
            'linked_findings' => $passed ? [] : [
                $this->finding(
                    'published_artifact_install_only',
                    'Waterline namespace conformance inputs do not prove a published artifact tuple.',
                    'waterline',
                ),
            ],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @return array<string, mixed>
     */
    private function resultRecordScenario(array $artifactVersions, string $startedAt, string $finishedAt): array
    {
        $passed = $artifactVersions !== []
            && $startedAt !== ''
            && $finishedAt !== '';

        return [
            'scenario_id' => 'result_record_and_product_finding_routing',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'artifact_versions_recorded' => $artifactVersions !== [],
                'timestamps_recorded' => $startedAt !== '' && $finishedAt !== '',
                'outcome_recorded' => true,
                'finding_links_recorded' => true,
                'product_finding_routes_checked' => true,
                'covered_by' => 'waterline-operator-namespace-shard',
            ],
            'linked_findings' => $passed ? [] : [
                $this->finding(
                    'result_record_and_product_finding_routing',
                    'Waterline namespace conformance result metadata is incomplete.',
                    'waterline',
                ),
            ],
        ];
    }

    private function captureStatusAndScopePass(
        mixed $capture,
        string $path,
        int $status,
        string $mode,
        ?string $namespace,
        string $authority,
    ): bool {
        if (! $this->captureStatusPass($capture, $path, $status)) {
            return false;
        }

        return data_get($capture, 'json.operator_scope.mode') === $mode
            && data_get($capture, 'json.operator_scope.namespace') === $namespace
            && data_get($capture, 'json.operator_scope.authority') === $authority
            && data_get($capture, 'operator_scope.mode') === $mode
            && data_get($capture, 'operator_scope.namespace') === $namespace
            && data_get($capture, 'operator_scope.authority') === $authority;
    }

    private function captureStatusPass(mixed $capture, string $path, int $status): bool
    {
        return is_array($capture)
            && data_get($capture, 'method') === 'GET'
            && data_get($capture, 'path') === $path
            && data_get($capture, 'status') === $status
            && is_string(data_get($capture, 'body_sha256'))
            && preg_match('/^[a-f0-9]{64}$/', (string) data_get($capture, 'body_sha256')) === 1
            && is_array(data_get($capture, 'json'));
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function captureSearchAttributePass(array $tenant, string $evidenceKey): bool
    {
        $expected = data_get($tenant, $evidenceKey.'.expected_search_attribute_value');
        if (! is_string($expected) || $expected === '') {
            return false;
        }

        $json = data_get($tenant, 'api_captures.'.$evidenceKey.'.json');
        if (! is_array($json) || ! $this->jsonContains($json, $expected)) {
            return false;
        }

        $forbidden = data_get($tenant, $evidenceKey.'.forbidden_search_attribute_value');
        if (is_string($forbidden) && $forbidden !== '' && $this->jsonContains($json, $forbidden)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function waterlineEvidencePassed(array $evidence): bool
    {
        $matrix = $evidence['operator_surface_matrix'] ?? [];
        if (! is_array($matrix) || ! $this->operatorSurfaceMatrixPass($matrix)) {
            return false;
        }

        foreach (['tenant_a_scoped_views', 'tenant_b_scoped_views'] as $tenantKey) {
            $tenant = $evidence[$tenantKey] ?? [];
            if (! is_array($tenant)) {
                return false;
            }

            $namespace = $tenant['namespace'] ?? null;
            if (! is_string($namespace) || $namespace === '') {
                return false;
            }

            if (data_get($tenant, 'operator_scope.namespace') !== $namespace
                || data_get($tenant, 'operator_scope.mode') !== 'namespace'
                || data_get($tenant, 'operator_scope.authority') !== 'tenant') {
                return false;
            }

            foreach ([
                'workflow_list.status' => 200,
                'workflow_list.operator_scope.mode' => 'namespace',
                'workflow_list.operator_scope.namespace' => $namespace,
                'workflow_list.operator_scope.authority' => 'tenant',
                'workflow_list.visibility_namespace' => $namespace,
                'workflow_detail.status' => 200,
                'workflow_detail.operator_scope.mode' => 'namespace',
                'workflow_detail.operator_scope.namespace' => $namespace,
                'workflow_detail.operator_scope.authority' => 'tenant',
                'operator_api_stats.status' => 200,
                'operator_api_stats.operator_scope.mode' => 'namespace',
                'operator_api_stats.operator_scope.namespace' => $namespace,
                'operator_api_stats.operator_scope.authority' => 'tenant',
                'schedule_list.status' => 200,
                'schedule_list.operator_scope.mode' => 'namespace',
                'schedule_list.operator_scope.namespace' => $namespace,
                'schedule_list.operator_scope.authority' => 'tenant',
                'schedule_detail.status' => 200,
                'schedule_detail.operator_scope.mode' => 'namespace',
                'schedule_detail.operator_scope.namespace' => $namespace,
                'schedule_detail.operator_scope.authority' => 'tenant',
                'schedule_history.status' => 200,
                'schedule_history.operator_scope.mode' => 'namespace',
                'schedule_history.operator_scope.namespace' => $namespace,
                'schedule_history.operator_scope.authority' => 'tenant',
            ] as $field => $expected) {
                if (data_get($tenant, $field) !== $expected) {
                    return false;
                }
            }

            foreach ([
                'workflow_list.includes_own_run',
                'workflow_list.excludes_foreign_run',
                'workflow_list.foreign_search_attribute_absent',
                'workflow_detail.foreign_search_attribute_absent',
                'workflow_detail.namespace',
                'foreign_workflow_detail.not_found',
                'operator_api_stats.flow_count_covers_fixture_run',
                'operator_api_stats.excludes_foreign_run',
                'schedule_list.includes_own_schedule',
                'schedule_list.excludes_foreign_schedule',
                'schedule_list.foreign_search_attribute_absent',
                'schedule_detail.foreign_search_attribute_absent',
                'schedule_detail.namespace',
                'foreign_schedule_detail.not_found',
                'schedule_history.namespace',
                'detail_namespace_identity',
            ] as $field) {
                $value = data_get($tenant, $field);
                if (str_ends_with($field, '.namespace')) {
                    if ($value !== $namespace) {
                        return false;
                    }
                    continue;
                }

                if ($value !== true) {
                    return false;
                }
            }

            foreach ([
                'workflow_list',
                'workflow_detail',
                'schedule_list',
                'schedule_detail',
            ] as $evidenceKey) {
                $expected = data_get($tenant, $evidenceKey.'.expected_search_attribute_value');
                if (! is_string($expected) || $expected === '') {
                    return false;
                }

                if (data_get($tenant, $evidenceKey.'.search_attribute_value_visible') !== $expected) {
                    return false;
                }
            }

            if (! $this->tenantApiCapturesPass($tenant, $namespace)) {
                return false;
            }
        }

        if (data_get($evidence, 'unscoped_view_authority.documented_safe_authority') !== true) {
            return false;
        }

        foreach ([
            'workflow_list.status' => 200,
            'workflow_list.operator_scope.mode' => 'cluster',
            'workflow_list.operator_scope.authority' => 'cluster',
            'workflow_list.operator_scope.namespace' => null,
            'workflow_list.includes_tenant_a_run' => true,
            'workflow_list.includes_tenant_b_run' => true,
            'operator_api_stats.status' => 200,
            'operator_api_stats.operator_scope.mode' => 'cluster',
            'operator_api_stats.operator_scope.authority' => 'cluster',
            'operator_api_stats.operator_scope.namespace' => null,
            'operator_api_stats.flow_count_covers_fixture_runs' => true,
            'schedule_list.status' => 200,
            'schedule_list.operator_scope.mode' => 'cluster',
            'schedule_list.operator_scope.authority' => 'cluster',
            'schedule_list.operator_scope.namespace' => null,
            'schedule_list.includes_tenant_a_schedule' => true,
            'schedule_list.includes_tenant_b_schedule' => true,
        ] as $field => $expected) {
            if (data_get($evidence, 'unscoped_view_authority.'.$field) !== $expected) {
                return false;
            }
        }

        foreach ([
            'workflow_list.tenant_a',
            'workflow_list.tenant_b',
            'schedule_list.tenant_a',
            'schedule_list.tenant_b',
        ] as $prefix) {
            $expected = data_get($evidence, 'unscoped_view_authority.'.$prefix.'_expected_search_attribute_value');
            if (! is_string($expected) || $expected === '') {
                return false;
            }

            if (data_get($evidence, 'unscoped_view_authority.'.$prefix.'_search_attribute_visible') !== $expected) {
                return false;
            }
        }

        if (! $this->unscopedApiCapturesPass($evidence)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $fixtures
     */
    private function cleanupFixtures(array $fixtures): void
    {
        $runIds = array_values(array_filter($fixtures['workflow_run_ids'] ?? [], 'is_string'));
        $instanceIds = array_values(array_filter($fixtures['workflow_instance_ids'] ?? [], 'is_string'));
        $scheduleIds = array_values(array_filter($fixtures['schedule_ids'] ?? [], 'is_string'));

        if ($instanceIds !== []) {
            $runIds = array_values(array_unique(array_merge(
                $runIds,
                WorkflowRun::query()
                    ->whereIn('workflow_instance_id', $instanceIds)
                    ->pluck('id')
                    ->all(),
            )));
        }

        if ($scheduleIds !== []) {
            $schedulePrimaryKeys = $this->scheduleQueryIncludingTrashed()
                ->whereIn('schedule_id', $scheduleIds)
                ->pluck('id')
                ->all();
            WorkflowScheduleHistoryEvent::query()->whereIn('workflow_schedule_id', $schedulePrimaryKeys)->delete();

            $this->scheduleQueryIncludingTrashed()
                ->whereIn('schedule_id', $scheduleIds)
                ->get()
                ->each(function (WorkflowSchedule $schedule): void {
                    if ($this->scheduleUsesSoftDeletes()) {
                        $schedule->forceDelete();

                        return;
                    }

                    $schedule->delete();
                });
        }

        if ($runIds !== []) {
            WorkflowSearchAttribute::query()->whereIn('workflow_run_id', $runIds)->delete();
            WorkflowHistoryEvent::query()->whereIn('workflow_run_id', $runIds)->delete();
            WorkflowRunSummary::query()->whereIn('id', $runIds)->delete();
            WorkflowRun::query()->whereIn('id', $runIds)->delete();
        }

        if ($instanceIds !== []) {
            WorkflowInstance::query()->whereIn('id', $instanceIds)->delete();
        }
    }

    private function scheduleQueryIncludingTrashed(): Builder
    {
        $query = WorkflowSchedule::query();

        if ($this->scheduleUsesSoftDeletes()) {
            $query->withTrashed();
        }

        return $query;
    }

    private function scheduleUsesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(WorkflowSchedule::class), true);
    }

    /**
     * @param array<string, mixed> ...$coveredScenarios
     * @return array<string, array<string, mixed>>
     */
    private function scenarioResults(array ...$coveredScenarios): array
    {
        $results = [];

        foreach ($coveredScenarios as $scenario) {
            $scenarioId = $scenario['scenario_id'] ?? null;
            if (! is_string($scenarioId) || $scenarioId === '') {
                continue;
            }

            $results[$scenarioId] = $scenario;
        }

        foreach (self::REQUIRED_SCENARIOS as $scenarioId) {
            if (isset($results[$scenarioId])) {
                continue;
            }

            $results[$scenarioId] = [
                'scenario_id' => $scenarioId,
                'status' => 'not_covered',
                'observed_outputs' => [
                    'coverage_scope' => 'waterline-operator-namespace-shard',
                    'reason' => 'This focused Waterline shard only exercises Waterline operator namespace visibility.',
                ],
                'linked_findings' => [
                    $this->finding(
                        $scenarioId,
                        'Scenario is outside the focused Waterline operator namespace visibility shard.',
                        'conformance-harness',
                    ),
                ],
            ];
        }

        return $results;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @return list<array<string, mixed>>
     */
    private function findings(array $scenarioResults): array
    {
        $findings = [];
        foreach ($scenarioResults as $scenario) {
            foreach (($scenario['linked_findings'] ?? []) as $finding) {
                if (is_array($finding)) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @return array<string, list<string>>
     */
    private function findingLinks(array $scenarioResults): array
    {
        $links = [];
        foreach ($scenarioResults as $scenarioId => $scenario) {
            foreach (($scenario['linked_findings'] ?? []) as $finding) {
                if (is_array($finding) && is_string($finding['id'] ?? null)) {
                    $links[$scenarioId][] = $finding['id'];
                }
            }
        }

        return $links;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function scenarioOutcome(array $scenarioResults): string
    {
        if (self::hasScenarioFailures($scenarioResults)) {
            return 'fail';
        }

        foreach ($scenarioResults as $scenario) {
            if (($scenario['status'] ?? null) === 'not_covered' && ! empty($scenario['linked_findings'] ?? [])) {
                return 'non_passing';
            }
        }

        return 'pass';
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return self::withLedgerAliases(
            self::canonicalArtifactMetadata($this->keyValueOptions('artifact-version')),
        );
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return self::withLedgerAliases(
            self::canonicalArtifactMetadata($this->keyValueOptions('artifact-source')),
        );
    }

    /**
     * @return array<string, string>
     */
    private function keyValueOptions(string $option): array
    {
        $values = [];
        foreach ((array) $this->option($option) as $raw) {
            if (! is_string($raw) || ! str_contains($raw, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $raw, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || $value === '') {
                continue;
            }

            if ($key === 'workflow') {
                $key = 'workflow-php';
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, string> $metadata
     * @return array<string, string>
     */
    private static function canonicalArtifactMetadata(array $metadata): array
    {
        $canonical = [];

        foreach ($metadata as $artifact => $value) {
            $key = self::canonicalArtifactKey($artifact);
            $canonical[$key ?? $artifact] = $value;
        }

        return $canonical;
    }

    /**
     * Keep the PHP runtime key accepted by the namespace contract while also
     * emitting the shorter key used by the conformance ledger.
     *
     * @param array<string, string> $metadata
     * @return array<string, string>
     */
    private static function withLedgerAliases(array $metadata): array
    {
        if (isset($metadata['workflow-php']) && ! isset($metadata['workflow'])) {
            $metadata['workflow'] = $metadata['workflow-php'];
        }

        return $metadata;
    }

    private static function canonicalArtifactKey(string $artifact): ?string
    {
        $normalized = str_replace('_', '-', strtolower(trim($artifact)));

        return match ($normalized) {
            'server' => 'server',
            'cli' => 'cli',
            'workflow', 'workflow-php' => 'workflow-php',
            'python', 'sdk-python' => 'sdk-python',
            'waterline' => 'waterline',
            default => null,
        };
    }

    /**
     * @param array<string, string> $metadata
     */
    private static function artifactMetadata(array $metadata, string $artifact): ?string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') {
                return $metadata[$key];
            }
        }

        return null;
    }

    private static function unpublishedVersionReason(string $version): ?string
    {
        $normalized = strtolower(trim($version));
        $localVersionPattern = '/(^|[^a-z0-9])(local|workspace|source|checkout|repo|path|dirty)([^a-z0-9]|$)/';
        $devVersionPattern = '/(^dev[-_.\/]|[-_.\/]dev($|[-_.\/])|@dev($|[^a-z0-9])|\.x-dev$|-dev$|9999999-dev)/';

        if ($normalized === '') {
            return 'empty_version';
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return 'placeholder_template';
        }

        if (preg_match(
            '/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/',
            $normalized,
        ) === 1) {
            return 'placeholder_label';
        }

        if (preg_match('/(^|[._+~\/-])(?:n|x|xx|xxx)(?=$|[._+~\/-])/', $normalized) === 1) {
            return 'placeholder_version_segment';
        }

        if (str_contains($normalized, '*')) {
            return 'wildcard_version';
        }

        if (preg_match('/(^|[,\s])(?:[~^]|[<>]=?|=)/', $normalized) === 1) {
            return 'version_constraint';
        }

        if (
            $normalized === 'self.version'
            || preg_match($localVersionPattern, $normalized) === 1
        ) {
            return 'local_or_source_version';
        }

        if (
            preg_match($devVersionPattern, $normalized) === 1
            || preg_match('/^(main|master|trunk|v\d+)$/', $normalized) === 1
        ) {
            return 'dev_or_branch_version';
        }

        return null;
    }

    private static function isLocalArtifactSource(string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return preg_match(
            '/(^|_)(dev|editable|local|path|repo|source|workspace|checkout)(_|$)/',
            $normalized,
        ) === 1;
    }

    private static function isPublishedArtifactSource(string $artifact, string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return in_array($normalized, self::PUBLISHED_ARTIFACT_SOURCES[$artifact] ?? [], true);
    }

    private static function normalizeArtifactSource(string $source): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($source))) ?? '';

        return trim($normalized, '_');
    }

    private function stringOption(string $option): ?string
    {
        $value = $this->option($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array{id: string, scenario_id: string, owner: string, title: string}
     */
    private function finding(string $scenarioId, string $title, string $owner): array
    {
        return [
            'id' => 'waterline-namespace-'.$scenarioId,
            'scenario_id' => $scenarioId,
            'owner' => $owner,
            'title' => $title,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function hasScenarioFailures(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $scenario) {
            if (($scenario['status'] ?? null) === 'fail') {
                return true;
            }
        }

        return false;
    }

    private function emit(array $report): void
    {
        $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $output = $this->stringOption('output');
        if ($output !== null) {
            file_put_contents($output, $encoded.PHP_EOL);

            if (! (bool) $this->option('json')) {
                $this->info('Waterline namespace conformance report written to '.$output.'.');
            }

            return;
        }

        $this->line($encoded);
    }

    private static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
