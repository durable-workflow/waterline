<?php

namespace Waterline\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;
use Throwable;
use Waterline\Models\SavedWorkflowView;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\RunSummarySortKey;

class SearchAttributesConformanceCommand extends Command
{
    protected $signature = 'waterline:search-attributes-conformance
        {--namespace-a=sa-test : First namespace to inspect through scoped Waterline views}
        {--namespace-b=sa-test-b : Second namespace used to prove scoped isolation}
        {--run-id= : Conformance run id recorded in evidence and used to derive fixture IDs}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--keep-fixtures : Keep generated Waterline fixture rows after the run}
        {--json : Emit only machine-readable output when --output is used}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Waterline search-attribute operator visibility conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result';

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
    private const WATERLINE_SHARD_SCENARIOS = [
        'published_artifact_install_only',
        'waterline_operator_visibility',
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
        $fixtureIds = $this->fixtureIds($this->fixtureRunId($runId), $namespaces);

        $originalConfig = [
            'waterline.engine_source' => config('waterline.engine_source'),
            'waterline.namespace' => config('waterline.namespace'),
            'waterline.allow_unauthenticated' => config('waterline.allow_unauthenticated'),
            'waterline.saved_views.enabled' => config('waterline.saved_views.enabled'),
        ];

        try {
            config()->set('waterline.engine_source', 'v2');
            config()->set('waterline.allow_unauthenticated', true);
            config()->set('waterline.saved_views.enabled', true);
            config()->set('waterline.namespace', $namespaces['a']);

            if (! (bool) $this->option('keep-fixtures')) {
                $this->cleanupFixtures($fixtureIds);
            }

            $fixtures = $this->createFixtures($fixtureIds, $namespaces);
            $evidence = $this->inspectSearchAttributeVisibility($kernel, $fixtures, $namespaces);
            $evidence['conformance_run_id'] = $runId;
            $evidence['fixture_ids'] = $fixtureIds;
            $evidence['operator_surface_matrix'] = $this->operatorSurfaceMatrix($evidence);

            $passed = $this->waterlineEvidencePassed($evidence);
            $waterlineScenario = [
                'scenario_id' => 'waterline_operator_visibility',
                'status' => $passed ? 'pass' : 'fail',
                'observed_outputs' => $evidence,
                'linked_findings' => $passed ? [] : [
                    $this->finding(
                        'waterline_operator_visibility',
                        'Waterline search-attribute operator visibility did not prove list filtering, detail values, saved filters, and namespace scope.',
                        'waterline',
                    ),
                ],
            ];
        } catch (Throwable $exception) {
            $evidence = [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                'fixture_ids' => $fixtureIds,
            ];
            $waterlineScenario = [
                'scenario_id' => 'waterline_operator_visibility',
                'status' => 'fail',
                'observed_outputs' => $evidence,
                'linked_findings' => [
                    $this->finding(
                        'waterline_operator_visibility',
                        'Waterline search-attribute operator visibility shard failed before evidence completed.',
                        'waterline',
                    ),
                ],
            ];
        } finally {
            foreach ($originalConfig as $key => $value) {
                config()->set($key, $value);
            }

            if (! (bool) $this->option('keep-fixtures')) {
                $this->cleanupFixtures($fixtureIds);
            }
        }

        $finishedAt = self::timestamp();
        $scenarioResults = $this->scenarioResults(
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
            $waterlineScenario,
            $this->resultRecordScenario($artifactVersions, $runId, $startedAt, $finishedAt),
        );
        $hasFailures = self::hasScenarioFailures($scenarioResults);
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'waterline-search-attribute-operator-shard',
            'outcome' => $hasFailures ? 'fail' : 'pass',
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'local_product_source_checkouts_used' => false,
            'namespace_topology' => [
                'namespaces' => array_values($namespaces),
            ],
            'runtime_matrix' => [
                'claimed_targets' => ['waterline_contract_surface'],
                'covered_scenarios' => self::WATERLINE_SHARD_SCENARIOS,
                'observer_paths' => [
                    'waterline-list',
                    'waterline-detail',
                    'waterline-saved-views',
                ],
            ],
            'scenario_results' => array_values($scenarioResults),
            'waterline_search_attribute_visibility' => $evidence,
            'api_captures' => is_array($evidence['api_captures'] ?? null) ? $evidence['api_captures'] : [],
            'findings' => $this->findings($scenarioResults),
            'finding_links' => $this->findingLinks($scenarioResults),
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{a: string, b: string}
     */
    private function namespaces(): array
    {
        return [
            'a' => $this->stringOption('namespace-a') ?? 'sa-test',
            'b' => $this->stringOption('namespace-b') ?? 'sa-test-b',
        ];
    }

    private function runId(): string
    {
        return $this->stringOption('run-id') ?? strtolower((string) Str::ulid());
    }

    private function fixtureRunId(string $runId): string
    {
        $value = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $runId) ?? '');
        $value = trim($value, '-');

        return $value !== '' ? Str::limit($value, 32, '') : strtolower((string) Str::ulid());
    }

    /**
     * @param array{a: string, b: string} $namespaces
     * @return array{workflow_instance_ids: list<string>, workflow_run_ids: list<string>, saved_view_ids: list<string>}
     */
    private function fixtureIds(string $runId, array $namespaces): array
    {
        $ids = [
            'workflow_instance_ids' => [],
            'workflow_run_ids' => [],
            'saved_view_ids' => [
                $this->savedViewFixtureId($runId, $namespaces['a'], 'saved-view'),
            ],
        ];

        foreach ([
            [$namespaces['a'], 'cust7-primary'],
            [$namespaces['a'], 'cust7-secondary'],
            [$namespaces['a'], 'cust8-control'],
            [$namespaces['b'], 'cust7-foreign'],
        ] as [$namespace, $kind]) {
            $ids['workflow_instance_ids'][] = $this->fixtureId($runId, $namespace, 'instance-'.$kind);
            $ids['workflow_run_ids'][] = $this->workflowRunFixtureId($runId, $namespace, 'run-'.$kind);
        }

        return $ids;
    }

    private function fixtureId(string $runId, string $namespace, string $kind): string
    {
        $namespace = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $namespace) ?? 'namespace');

        return Str::limit(sprintf('waterline-sa-%s-%s-%s', $runId, $namespace, $kind), 120, '');
    }

    private function workflowRunFixtureId(string $runId, string $namespace, string $kind): string
    {
        return $this->boundedFixtureId('wl-run', 'waterline-sa-workflow-run', $runId, $namespace, $kind);
    }

    private function savedViewFixtureId(string $runId, string $namespace, string $kind): string
    {
        return $this->boundedFixtureId('wl-sa', 'waterline-sa', $runId, $namespace, $kind);
    }

    private function boundedFixtureId(string $prefix, string $domain, string $runId, string $namespace, string $kind): string
    {
        $namespace = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $namespace) ?? 'namespace');
        $prefix = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $prefix) ?? 'wl');
        $prefix = trim($prefix, '-');
        $prefix = $prefix !== '' ? Str::limit($prefix, 6, '') : 'wl';
        $fingerprint = hash('sha256', sprintf('%s|%s|%s|%s', $domain, $runId, $namespace, $kind));

        return $prefix.'-'.substr($fingerprint, 0, 25 - strlen($prefix));
    }

    /**
     * @param array{workflow_instance_ids: list<string>, workflow_run_ids: list<string>, saved_view_ids: list<string>} $fixtureIds
     * @param array{a: string, b: string} $namespaces
     * @return array<string, mixed>
     */
    private function createFixtures(array $fixtureIds, array $namespaces): array
    {
        $createdAt = CarbonImmutable::parse('2026-01-01 12:00:00', 'UTC');
        $customerFilter = ['customer_id' => 'cust-7'];
        $tagFilter = ['tags' => 'urgent'];

        $primaryAttributes = [
            'customer_id' => 'cust-7',
            'order_total_cents' => 7500,
            'priority_tier' => 'gold',
            'is_vip' => true,
            'created_at' => $createdAt,
            'tags' => ['urgent', 'oversized'],
        ];
        $secondaryAttributes = [
            'customer_id' => 'cust-7',
            'order_total_cents' => 2500,
            'priority_tier' => 'silver',
            'is_vip' => false,
            'created_at' => $createdAt->addMinute(),
            'tags' => ['standard'],
        ];
        $controlAttributes = [
            'customer_id' => 'cust-8',
            'order_total_cents' => 9100,
            'priority_tier' => 'bronze',
            'is_vip' => false,
            'created_at' => $createdAt->addMinutes(2),
            'tags' => ['standard'],
        ];
        $foreignAttributes = [
            'customer_id' => 'cust-7',
            'order_total_cents' => 8800,
            'priority_tier' => 'platinum',
            'is_vip' => true,
            'created_at' => $createdAt->addMinutes(3),
            'tags' => ['urgent'],
        ];

        $runs = [
            'tenant_a_primary' => $this->createRunningRun(
                $fixtureIds['workflow_instance_ids'][0],
                $fixtureIds['workflow_run_ids'][0],
                $namespaces['a'],
                $primaryAttributes,
            ),
            'tenant_a_secondary' => $this->createRunningRun(
                $fixtureIds['workflow_instance_ids'][1],
                $fixtureIds['workflow_run_ids'][1],
                $namespaces['a'],
                $secondaryAttributes,
            ),
            'tenant_a_control' => $this->createRunningRun(
                $fixtureIds['workflow_instance_ids'][2],
                $fixtureIds['workflow_run_ids'][2],
                $namespaces['a'],
                $controlAttributes,
            ),
            'tenant_b_foreign' => $this->createRunningRun(
                $fixtureIds['workflow_instance_ids'][3],
                $fixtureIds['workflow_run_ids'][3],
                $namespaces['b'],
                $foreignAttributes,
            ),
        ];

        $savedView = SavedWorkflowView::query()->create([
            'id' => $fixtureIds['saved_view_ids'][0],
            'name' => 'Customer cust-7',
            'scope' => SavedWorkflowView::configuredScope(),
            'bucket' => 'running',
            'filters' => [
                'search_attributes' => $customerFilter,
            ],
            'filter_version' => \Waterline\Support\ActionabilityVisibilityFilters::VERSION,
            'shared' => true,
            'owner_type' => 'scope',
            'owner_id' => SavedWorkflowView::configuredScope(),
        ]);

        return [
            'runs' => $runs,
            'saved_view' => $savedView,
            'expected_search_attributes' => [
                'customer_id' => 'cust-7',
                'created_at' => $createdAt->toJSON(),
                'is_vip' => true,
                'order_total_cents' => 7500,
                'priority_tier' => 'gold',
                'tags' => ['urgent', 'oversized'],
            ],
            'filters' => [
                'customer' => $customerFilter,
                'tag' => $tagFilter,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $searchAttributes
     */
    private function createRunningRun(
        string $instanceId,
        string $runId,
        string $namespace,
        array $searchAttributes,
    ): WorkflowRun {
        $startedAt = now()->subMinutes(5);
        $createdAt = now()->subMinutes(6);

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.waterline-search-attribute-conformance',
            'business_key' => $instanceId,
            'run_count' => 1,
            'namespace' => $namespace,
            'started_at' => $startedAt,
        ]);

        $runAttributes = [
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.waterline-search-attribute-conformance',
            'business_key' => $instanceId,
            'status' => 'waiting',
            'namespace' => $namespace,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 1,
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
            'created_at' => $createdAt,
            'updated_at' => $startedAt,
        ];

        if (Schema::hasColumn('workflow_runs', 'search_attributes')) {
            $runAttributes['search_attributes'] = $this->jsonReadySearchAttributes($searchAttributes);
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
            'workflow_type' => 'workflow.waterline-search-attribute-conformance',
            'business_key' => $instanceId,
            'status' => 'waiting',
            'status_bucket' => 'running',
            'namespace' => $namespace,
            'started_at' => $startedAt,
            'history_event_count' => 1,
            'history_size_bytes' => 128,
            'continue_as_new_recommended' => false,
            'created_at' => $createdAt,
            'updated_at' => $startedAt,
        ];

        if (Schema::hasColumn('workflow_run_summaries', 'sort_timestamp')) {
            $summaryAttributes['sort_timestamp'] = $startedAt;
        }

        if (Schema::hasColumn('workflow_run_summaries', 'sort_key')) {
            $summaryAttributes['sort_key'] = RunSummarySortKey::key(
                $summaryAttributes['sort_timestamp'] ?? $startedAt,
                $createdAt,
                $startedAt,
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
                'workflow_type' => 'workflow.waterline-search-attribute-conformance',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ],
            'recorded_at' => $startedAt,
        ]);

        return $run;
    }

    /**
     * @param array<string, mixed> $searchAttributes
     * @return array<string, mixed>
     */
    private function jsonReadySearchAttributes(array $searchAttributes): array
    {
        return array_map(static function (mixed $value): mixed {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            return $value;
        }, $searchAttributes);
    }

    /**
     * @param array<string, mixed> $fixtures
     * @param array{a: string, b: string} $namespaces
     * @return array<string, mixed>
     */
    private function inspectSearchAttributeVisibility(HttpKernel $kernel, array $fixtures, array $namespaces): array
    {
        $runs = $fixtures['runs'];
        $filters = $fixtures['filters'];
        $expectedAttributes = $fixtures['expected_search_attributes'];
        $savedView = $fixtures['saved_view'];

        config()->set('waterline.namespace', $namespaces['a']);

        $customerList = $this->apiGet(
            $kernel,
            '/api/flows/running?search_attributes[customer_id]=cust-7',
        );
        $tagList = $this->apiGet(
            $kernel,
            '/api/flows/running?search_attributes[tags]=urgent',
        );
        $detail = $this->apiGet($kernel, '/api/flows/'.$runs['tenant_a_primary']->id);
        $savedViewShow = $this->apiGet($kernel, '/api/saved-views/'.$savedView->id);
        $savedViewList = $this->apiGet($kernel, '/api/saved-views?bucket=running');
        $savedViewApplied = $this->apiGet($kernel, '/api/flows/running?view='.$savedView->id);

        config()->set('waterline.namespace', $namespaces['b']);

        $foreignNamespaceList = $this->apiGet(
            $kernel,
            '/api/flows/running?search_attributes[customer_id]=cust-7',
        );

        $expectedCustomerRunIds = [
            $runs['tenant_a_primary']->id,
            $runs['tenant_a_secondary']->id,
        ];
        $expectedTagRunIds = [
            $runs['tenant_a_primary']->id,
        ];
        $expectedForeignRunIds = [
            $runs['tenant_b_foreign']->id,
        ];

        $customerRows = $this->rows($customerList['json']);
        $tagRows = $this->rows($tagList['json']);
        $foreignRows = $this->rows($foreignNamespaceList['json']);
        $savedRows = $this->rows($savedViewApplied['json']);
        $customerRunIds = $this->rowIds($customerRows);
        $tagRunIds = $this->rowIds($tagRows);
        $foreignRunIds = $this->rowIds($foreignRows);
        $savedRunIds = $this->rowIds($savedRows);
        $detailAttributes = data_get($detail['json'], 'search_attributes');
        $detailAttributes = is_array($detailAttributes) ? $detailAttributes : [];
        $savedViewFilters = data_get($savedViewShow['json'], 'filters');
        $savedViewFilters = is_array($savedViewFilters) ? $savedViewFilters : [];
        $savedListRows = $this->rows($savedViewList['json']);
        $savedListRow = $this->savedViewRow($savedListRows, $savedView->id);

        return [
            'namespaces' => $namespaces,
            'schema_keys' => array_keys($expectedAttributes),
            'api_captures' => [
                'workflow_list_customer_filter' => $this->responseCapture($customerList),
                'workflow_list_keyword_list_filter' => $this->responseCapture($tagList),
                'selected_run_detail' => $this->responseCapture($detail),
                'saved_view_show' => $this->responseCapture($savedViewShow),
                'saved_view_list' => $this->responseCapture($savedViewList),
                'saved_view_applied_workflow_list' => $this->responseCapture($savedViewApplied),
                'foreign_namespace_workflow_list' => $this->responseCapture($foreignNamespaceList),
            ],
            'workflow_list_filter' => [
                'path' => '/api/flows/running?search_attributes[customer_id]=cust-7',
                'status' => $customerList['status'],
                'filter' => $filters['customer'],
                'expected_count' => count($expectedCustomerRunIds),
                'actual_count' => count($customerRunIds),
                'expected_run_ids' => $this->sortedStrings($expectedCustomerRunIds),
                'actual_run_ids' => $this->sortedStrings($customerRunIds),
                'matched' => $this->sameStringSet($expectedCustomerRunIds, $customerRunIds),
                'visibility_filter_echo' => data_get($customerList['json'], 'visibility_filters.applied.search_attributes'),
                'operator_scope' => $this->operatorScope($customerList['json']),
                'foreign_run_absent' => ! in_array($runs['tenant_b_foreign']->id, $customerRunIds, true),
            ],
            'keyword_list_filter' => [
                'path' => '/api/flows/running?search_attributes[tags]=urgent',
                'status' => $tagList['status'],
                'filter' => $filters['tag'],
                'expected_count' => count($expectedTagRunIds),
                'actual_count' => count($tagRunIds),
                'expected_run_ids' => $this->sortedStrings($expectedTagRunIds),
                'actual_run_ids' => $this->sortedStrings($tagRunIds),
                'matched' => $this->sameStringSet($expectedTagRunIds, $tagRunIds),
                'visibility_filter_echo' => data_get($tagList['json'], 'visibility_filters.applied.search_attributes'),
                'operator_scope' => $this->operatorScope($tagList['json']),
            ],
            'selected_run_detail' => [
                'path' => '/api/flows/'.$runs['tenant_a_primary']->id,
                'status' => $detail['status'],
                'expected_workflow_instance_id' => $runs['tenant_a_primary']->workflow_instance_id,
                'actual_workflow_instance_id' => data_get($detail['json'], 'workflow_instance_id'),
                'expected_run_id' => $runs['tenant_a_primary']->id,
                'run_id' => data_get($detail['json'], 'run_id'),
                'identity_matched' => data_get($detail['json'], 'workflow_instance_id')
                    === $runs['tenant_a_primary']->workflow_instance_id
                    && data_get($detail['json'], 'run_id') === $runs['tenant_a_primary']->id,
                'namespace' => data_get($detail['json'], 'namespace'),
                'expected_search_attributes' => $expectedAttributes,
                'actual_search_attributes' => $detailAttributes,
                'expected_attributes_visible' => $this->expectedAttributesVisible($expectedAttributes, $detailAttributes),
                'operator_scope' => $this->operatorScope($detail['json']),
            ],
            'saved_filter_state' => [
                'saved_view_id' => $savedView->id,
                'stored_scope' => $savedView->scope,
                'retrieved_scope' => data_get($savedViewShow['json'], 'scope'),
                'listed_scope' => is_array($savedListRow) ? ($savedListRow['scope'] ?? null) : null,
                'stored_filters' => $savedView->filters,
                'retrieved_filters' => $savedViewFilters,
                'listed_filters' => is_array($savedListRow) ? ($savedListRow['filters'] ?? null) : null,
                'saved_view_show_status' => $savedViewShow['status'],
                'saved_view_list_status' => $savedViewList['status'],
                'applied_list_status' => $savedViewApplied['status'],
                'filter_preserved_on_retrieval' => $savedViewFilters === $savedView->filters,
                'filter_preserved_on_list_retrieval' => is_array($savedListRow)
                    && ($savedListRow['filters'] ?? null) === $savedView->filters,
                'applied_expected_count' => count($expectedCustomerRunIds),
                'applied_actual_count' => count($savedRunIds),
                'applied_expected_run_ids' => $this->sortedStrings($expectedCustomerRunIds),
                'applied_actual_run_ids' => $this->sortedStrings($savedRunIds),
                'applied_filter_matched' => $this->sameStringSet($expectedCustomerRunIds, $savedRunIds),
                'applied_filter_echo' => data_get($savedViewApplied['json'], 'visibility_filters.applied.search_attributes'),
                'operator_scope' => $this->operatorScope($savedViewApplied['json']),
            ],
            'namespace_isolation' => [
                'tenant_a_namespace' => $namespaces['a'],
                'tenant_b_namespace' => $namespaces['b'],
                'tenant_a_filter_expected_run_ids' => $this->sortedStrings($expectedCustomerRunIds),
                'tenant_a_filter_actual_run_ids' => $this->sortedStrings($customerRunIds),
                'tenant_b_filter_expected_run_ids' => $this->sortedStrings($expectedForeignRunIds),
                'tenant_b_filter_actual_run_ids' => $this->sortedStrings($foreignRunIds),
                'tenant_a_excludes_tenant_b' => ! in_array($runs['tenant_b_foreign']->id, $customerRunIds, true),
                'tenant_b_excludes_tenant_a' => ! in_array($runs['tenant_a_primary']->id, $foreignRunIds, true)
                    && ! in_array($runs['tenant_a_secondary']->id, $foreignRunIds, true),
                'tenant_b_filter_matched' => $this->sameStringSet($expectedForeignRunIds, $foreignRunIds),
                'tenant_a_operator_scope' => $this->operatorScope($customerList['json']),
                'tenant_b_operator_scope' => $this->operatorScope($foreignNamespaceList['json']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function operatorSurfaceMatrix(array $evidence): array
    {
        return [
            'workflow_list_search_attribute_filter' => data_get($evidence, 'workflow_list_filter.matched') === true
                && data_get($evidence, 'workflow_list_filter.foreign_run_absent') === true,
            'keyword_list_search_attribute_filter' => data_get($evidence, 'keyword_list_filter.matched') === true,
            'selected_run_search_attributes' => data_get($evidence, 'selected_run_detail.identity_matched') === true
                && data_get($evidence, 'selected_run_detail.expected_attributes_visible') === true,
            'saved_filter_round_trip' => data_get($evidence, 'saved_filter_state.filter_preserved_on_retrieval') === true
                && data_get($evidence, 'saved_filter_state.filter_preserved_on_list_retrieval') === true
                && data_get($evidence, 'saved_filter_state.applied_filter_matched') === true,
            'namespace_scoped_visibility' => data_get($evidence, 'namespace_isolation.tenant_a_excludes_tenant_b') === true
                && data_get($evidence, 'namespace_isolation.tenant_b_excludes_tenant_a') === true
                && data_get($evidence, 'namespace_isolation.tenant_b_filter_matched') === true,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function waterlineEvidencePassed(array $evidence): bool
    {
        if (! is_string($evidence['conformance_run_id'] ?? null)
            || trim((string) $evidence['conformance_run_id']) === '') {
            return false;
        }

        $matrix = $evidence['operator_surface_matrix'] ?? [];
        if (! is_array($matrix) || in_array(false, $matrix, true)) {
            return false;
        }

        foreach ([
            'workflow_list_filter.status',
            'keyword_list_filter.status',
            'selected_run_detail.status',
            'saved_filter_state.saved_view_show_status',
            'saved_filter_state.saved_view_list_status',
            'saved_filter_state.applied_list_status',
        ] as $field) {
            if (data_get($evidence, $field) !== 200) {
                return false;
            }
        }

        foreach ([
            'workflow_list_filter.operator_scope.namespace' => data_get($evidence, 'namespaces.a'),
            'keyword_list_filter.operator_scope.namespace' => data_get($evidence, 'namespaces.a'),
            'selected_run_detail.operator_scope.namespace' => data_get($evidence, 'namespaces.a'),
            'saved_filter_state.operator_scope.namespace' => data_get($evidence, 'namespaces.a'),
            'namespace_isolation.tenant_a_operator_scope.namespace' => data_get($evidence, 'namespaces.a'),
            'namespace_isolation.tenant_b_operator_scope.namespace' => data_get($evidence, 'namespaces.b'),
        ] as $field => $namespace) {
            if (! is_string($namespace) || $namespace === '' || data_get($evidence, $field) !== $namespace) {
                return false;
            }
        }

        foreach ([
            'workflow_list_customer_filter',
            'workflow_list_keyword_list_filter',
            'selected_run_detail',
            'saved_view_show',
            'saved_view_list',
            'saved_view_applied_workflow_list',
            'foreign_namespace_workflow_list',
        ] as $capture) {
            if (! $this->captureStatusPass(data_get($evidence, 'api_captures.'.$capture), 200)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function expectedAttributesVisible(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if ($key === 'created_at') {
                if (! is_string($actual[$key] ?? null) || $actual[$key] === '') {
                    return false;
                }

                continue;
            }

            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private function rows(array $json): array
    {
        $rows = $json['data'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function rowIds(array $rows): array
    {
        return array_values(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['id'] ?? null) ? $row['id'] : null,
            $rows,
        )));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function savedViewRow(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameStringSet(array $left, array $right): bool
    {
        return $this->sortedStrings($left) === $this->sortedStrings($right);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_filter($values, 'is_string'));
        sort($values);

        return $values;
    }

    /**
     * @return array{method: string, path: string, request_path: string, status: int, json: array<string, mixed>, body: string}
     */
    private function apiGet(HttpKernel $kernel, string $path): array
    {
        $requestPath = '/'.trim((string) config('waterline.path', 'waterline'), '/').$path;
        $request = Request::create(
            $requestPath,
            'GET',
            server: ['HTTP_ACCEPT' => 'application/json'],
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

    private function captureStatusPass(mixed $capture, int $status): bool
    {
        return is_array($capture)
            && data_get($capture, 'method') === 'GET'
            && data_get($capture, 'status') === $status
            && is_string(data_get($capture, 'body_sha256'))
            && preg_match('/^[a-f0-9]{64}$/', (string) data_get($capture, 'body_sha256')) === 1
            && is_array(data_get($capture, 'json'));
    }

    /**
     * @param array{workflow_instance_ids: list<string>, workflow_run_ids: list<string>, saved_view_ids: list<string>} $fixtures
     */
    private function cleanupFixtures(array $fixtures): void
    {
        $runIds = array_values(array_filter($fixtures['workflow_run_ids'] ?? [], 'is_string'));
        $instanceIds = array_values(array_filter($fixtures['workflow_instance_ids'] ?? [], 'is_string'));
        $savedViewIds = array_values(array_filter($fixtures['saved_view_ids'] ?? [], 'is_string'));

        if ($instanceIds !== []) {
            $runIds = array_values(array_unique(array_merge(
                $runIds,
                WorkflowRun::query()
                    ->whereIn('workflow_instance_id', $instanceIds)
                    ->pluck('id')
                    ->all(),
            )));
        }

        if ($savedViewIds !== []) {
            SavedWorkflowView::query()->whereIn('id', $savedViewIds)->delete();
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

        return $results;
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
                    'Waterline search-attribute conformance inputs do not prove a published artifact tuple.',
                    'waterline',
                ),
            ],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @return array<string, mixed>
     */
    private function resultRecordScenario(
        array $artifactVersions,
        string $runId,
        string $startedAt,
        string $finishedAt,
    ): array
    {
        $passed = $artifactVersions !== []
            && $runId !== ''
            && $startedAt !== ''
            && $finishedAt !== '';

        return [
            'scenario_id' => 'result_record_and_product_finding_routing',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'artifact_versions_recorded' => $artifactVersions !== [],
                'run_id_recorded' => $runId !== '',
                'run_id' => $runId,
                'timestamps_recorded' => $startedAt !== '' && $finishedAt !== '',
                'outcome_recorded' => true,
                'finding_links_recorded' => true,
                'product_finding_routes_checked' => true,
                'covered_by' => 'waterline-search-attribute-operator-shard',
            ],
            'linked_findings' => $passed ? [] : [
                $this->finding(
                    'result_record_and_product_finding_routing',
                    'Waterline search-attribute conformance result metadata is incomplete.',
                    'waterline',
                ),
            ],
        ];
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
     * @return array{id: string, scenario_id: string, owner: string, title: string, owning_surface: string, artifact_versions: array<string, string>, ui_api_surface: string, observed_behavior: string, expected_behavior: string, reproduction_steps: list<string>, next_acceptance_criterion: string}
     */
    private function finding(string $scenarioId, string $title, string $owner): array
    {
        return [
            'id' => 'waterline-search-attributes-'.$scenarioId,
            'scenario_id' => $scenarioId,
            'owner' => $owner,
            'title' => $title,
            'owning_surface' => $owner,
            'artifact_versions' => $this->artifactVersions(),
            'ui_api_surface' => 'Waterline workflow list filters, selected-run detail API, saved views API, and namespace-scoped operator scope.',
            'observed_behavior' => $title,
            'expected_behavior' => 'Waterline records workflow-list search-attribute filter counts, selected-run typed search attributes, saved-filter round trip state, and namespace-scoped operator visibility through published package HTTP routes.',
            'reproduction_steps' => [
                'Install Waterline from the published package version recorded in artifact_versions.',
                'Run php artisan waterline:search-attributes-conformance with the recorded published artifact versions and sources.',
                'Inspect scenario_results.waterline_operator_visibility, waterline_search_attribute_visibility.operator_surface_matrix, and api_captures.',
            ],
            'next_acceptance_criterion' => 'Publish a Waterline artifact whose search-attribute shard records waterline_operator_visibility=pass and links into the full search-attribute runtime ledger.',
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
                $this->info('Waterline search-attribute conformance report written to '.$output.'.');
            }

            return;
        }

        $this->line($encoded);
    }

    private static function timestamp(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
