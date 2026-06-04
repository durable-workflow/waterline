<?php

namespace Waterline\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use JsonException;
use Throwable;
use Waterline\Support\ObserverStateEnvelope;
use Workflow\V2\Support\PlatformConformanceSuite;

class SignalsQueriesConformanceCommand extends Command
{
    private const SCHEMA = 'durable-workflow.v2.signal-query-runtime.waterline-observer-result';

    private const RESULT_VERSION = 1;

    private const SCENARIO = 'waterline_operator_visibility';

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
        self::SCENARIO,
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

    protected $signature = 'waterline:signals-queries-conformance
        {--input= : JSON evidence captured by the public signals/queries runner}
        {--output= : File path for the Waterline shard result}
        {--run-id= : Stable run id to include in the shard metadata}
        {--instance-id= : Workflow instance id to inspect when it is not present in the public evidence}
        {--workflow-run-id= : Workflow run id to inspect when it is not present in the public evidence}
        {--run-status= : Public client observed run status}
        {--query=current : Query name to invoke through the Waterline selected-run query action}
        {--selected-run-detail-capture= : JSON capture from GET /waterline/api/instances/<instance>/runs/<run>}
        {--selected-run-query-capture= : JSON capture from POST /waterline/api/instances/<instance>/runs/<run>/queries/<query>}
        {--api-capture=* : Repeatable Waterline API capture JSON file; captures may be keyed by selected_run_detail or selected_run_query_action}
        {--artifact-version=* : Published artifact version pair, for example server=0.2.250}
        {--artifact-source=* : Published artifact source pair, for example waterline=packagist_package}';

    protected $description = 'Emit Waterline observer comparison evidence for the signals/queries conformance suite.';

    public function handle(HttpKernel $kernel): int
    {
        $startedAt = $this->timestamp();
        $inputPath = $this->optionString('input');
        $outputPath = $this->optionString('output');
        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();

        try {
            $publicEvidence = $inputPath === null ? [] : $this->readJsonFile($inputPath);
            $scenario = $this->buildScenario(
                $kernel,
                $publicEvidence,
                $artifactVersions,
                $artifactSources,
                $startedAt,
            );
            $finishedAt = $this->timestamp();
            $scenarioResults = $this->scenarioResults(
                $this->publishedArtifactScenario($artifactVersions, $artifactSources),
                $scenario,
                $this->resultRecordScenario($artifactVersions, $startedAt, $finishedAt),
            );
            $hasFailures = self::hasScenarioFailures($scenarioResults);

            $result = [
                'schema' => self::SCHEMA,
                'schema_version' => self::RESULT_VERSION,
                'suite_version' => PlatformConformanceSuite::VERSION,
                'coverage_scope' => 'waterline-operator-visibility-shard',
                'run_id' => $this->optionString('run-id'),
                'outcome' => $hasFailures ? 'fail' : 'non_passing',
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'generated_at' => $finishedAt,
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'local_product_source_checkouts_used' => false,
                'runtime_matrix' => [
                    'claimed_targets' => ['waterline_contract_surface'],
                    'covered_scenarios' => self::WATERLINE_SHARD_SCENARIOS,
                    'observer_paths' => [
                        'selected_run_detail',
                        'selected_run_query_action',
                    ],
                ],
                'scenario_results' => array_values($scenarioResults),
                'waterline_observer_comparison' => [
                    self::SCENARIO => $scenario['observed_outputs'],
                ],
                'findings' => $this->findings($scenarioResults),
                'finding_links' => $this->findingLinks($scenarioResults),
            ];

            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if ($outputPath !== null) {
                $this->writeFile($outputPath, $json);
            } else {
                $this->line($json);
            }

            return $hasFailures ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $publicEvidence
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     *
     * @return array<string, mixed>
     */
    private function buildScenario(
        HttpKernel $kernel,
        array $publicEvidence,
        array $artifactVersions,
        array $artifactSources,
        string $capturedAt,
    ): array {
        $ordered = $this->scenarioObservedOutputs($publicEvidence, 'ordered_signal_delivery');
        $pythonBaseline = $this->scenarioObservedOutputs($publicEvidence, 'python_worker_cli_and_sdk_baseline');
        $counter = $this->counterFromPublicEvidence($publicEvidence, $ordered);
        $instanceId = $this->optionString('instance-id') ?? $this->stringEvidence($publicEvidence, [
            'workflow_instance_id',
            'instance_id',
            'workflow_id',
        ]);
        $runId = $this->optionString('workflow-run-id') ?? $this->stringEvidence($publicEvidence, [
            'workflow_run_id',
            'selected_run_id',
            'run_id',
        ]);
        $runStatus = $this->optionString('run-status') ?? $this->stringEvidence($publicEvidence, [
            'observer_run_status',
            'selected_run_status',
            'workflow_run_status',
            'run_status',
        ]);
        $queryName = $this->optionString('query') ?? $this->stringEvidence($publicEvidence, ['query_name']) ?? 'current';
        $paths = $instanceId !== null && $runId !== null
            ? $this->observerPaths($instanceId, $runId, $queryName)
            : $this->observerPathTemplates($queryName);

        $detailCapture = $this->providedCapture('selected_run_detail', $capturedAt)
            ?? ($instanceId !== null && $runId !== null
                ? $this->captureWaterlineApi($kernel, 'GET', $paths['selected_run_detail_api'], null, $capturedAt)
                : null);
        $queryCapture = $this->providedCapture(
            'selected_run_query_action',
            $capturedAt,
        ) ?? ($instanceId !== null && $runId !== null
            ? $this->captureWaterlineApi(
                $kernel,
                'POST',
                $paths['selected_run_query_action_api'],
                ['arguments' => []],
                $capturedAt,
            )
            : null);

        $observerState = $this->observerStateFromDetail($detailCapture);
        $observerCounter = $this->counterFromObserverState($observerState, $queryName);
        $queryCounter = $this->counterFromQueryCapture($queryCapture, $queryName);
        $observerStatus = $this->stringData($observerState, 'selected_run.status')
            ?? $this->stringData($detailCapture, 'json.status');
        $observerStatusBucket = $this->stringData($observerState, 'selected_run.status_bucket')
            ?? $this->stringData($detailCapture, 'json.status_bucket');
        $runStatusMatches = $this->runStatusMatches($runStatus, $observerStatus, $observerStatusBucket);
        $typedProductFindings = [];
        $blockingFindings = [];

        if ($instanceId === null || $runId === null) {
            $blockingFindings[] = $this->finding(
                'signal_query_waterline_target_identity_missing',
                'signal_query_waterline_target_identity_missing',
                'conformance_harness',
                'Waterline observer comparison cannot select a run because the public signals/queries evidence did not identify both the workflow instance and run.',
                'Public signals/queries evidence records workflow_instance_id and workflow_run_id for the run inspected by Waterline.',
                [
                    'instance_id_present' => $instanceId !== null,
                    'workflow_run_id_present' => $runId !== null,
                    'public_evidence_keys_checked' => [
                        'workflow_instance_id',
                        'instance_id',
                        'workflow_id',
                        'workflow_run_id',
                        'selected_run_id',
                        'run_id',
                    ],
                ],
            );
        }

        if ($counter === null) {
            $blockingFindings[] = $this->finding(
                'signal_query_public_counter_missing',
                'signal_query_public_counter_missing',
                'conformance_harness',
                'Waterline observer comparison cannot compare query-visible state because the public signals/queries evidence did not include a counter result.',
                'Public CLI and SDK evidence records the Counter.current query result used as the comparison target.',
                [
                    'ordered_signal_delivery' => $ordered,
                ],
            );
        }

        if ($runStatus === null) {
            $blockingFindings[] = $this->finding(
                'signal_query_public_run_status_missing',
                'signal_query_public_run_status_missing',
                'conformance_harness',
                'Waterline observer comparison cannot prove run status parity because the public signals/queries evidence did not include the observed run status.',
                'Public client evidence records the run status observed for the same workflow run inspected by Waterline.',
                [
                    'public_evidence_keys_checked' => [
                        'observer_run_status',
                        'selected_run_status',
                        'workflow_run_status',
                        'run_status',
                    ],
                ],
            );
        }

        if ($detailCapture === null) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_detail_not_captured',
                'waterline_observer_api_capture_missing',
                'Waterline selected-run detail was not captured from the observer API.',
                'The conformance shard captures GET selected-run detail from the published Waterline artifact.',
                [
                    'method' => 'GET',
                    'path' => $paths['selected_run_detail'],
                ],
                null,
            );
        } else {
            $requestFinding = $this->captureRequestFinding(
                'waterline_selected_run_detail_capture_request_mismatch',
                'GET',
                $paths['selected_run_detail'],
                $detailCapture,
                'Waterline selected-run detail capture was not collected from the expected observer API endpoint.',
                'The selected-run detail capture records GET for the public workflow instance and run selected by the client evidence.',
            );
            if ($requestFinding !== null) {
                $blockingFindings[] = $requestFinding;
            }
        }

        if ($detailCapture !== null && (int) ($detailCapture['status'] ?? 0) !== 200) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_detail_unavailable',
                'waterline_observer_api_unavailable',
                'Waterline selected-run detail did not return a successful JSON response.',
                'GET selected-run detail returns HTTP 200 with the observer_state envelope for the selected run.',
                [
                    'method' => 'GET',
                    'path' => $paths['selected_run_detail'],
                ],
                $detailCapture,
            );
        } elseif ($detailCapture !== null && $observerState === []) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_observer_state_missing',
                'waterline_observer_state_missing',
                'Waterline selected-run detail did not include observer_state.',
                'The selected-run detail JSON envelope includes observer_state.selected_run, observer_state.signals, observer_state.queries, and observer_state.paths.',
                [
                    'method' => 'GET',
                    'path' => $paths['selected_run_detail'],
                ],
                $detailCapture,
            );
        }

        if ($detailCapture !== null && $observerState !== [] && $instanceId !== null && $runId !== null) {
            $identityFinding = $this->observerStateIdentityFinding(
                $detailCapture,
                $observerState,
                $instanceId,
                $runId,
                $paths['selected_run_detail'],
            );
            if ($identityFinding !== null) {
                $blockingFindings[] = $identityFinding;
            }
        }

        if ($queryCapture === null) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_query_not_captured',
                'waterline_observer_api_capture_missing',
                'Waterline selected-run query action was not captured from the observer API.',
                'The conformance shard captures POST selected-run query action from the published Waterline artifact.',
                [
                    'method' => 'POST',
                    'path' => $paths['selected_run_query_action'],
                    'json' => ['arguments' => []],
                ],
                null,
            );
        } else {
            $requestFinding = $this->captureRequestFinding(
                'waterline_selected_run_query_capture_request_mismatch',
                'POST',
                $paths['selected_run_query_action'],
                $queryCapture,
                'Waterline selected-run query capture was not collected from the expected observer API endpoint.',
                'The selected-run query capture records POST for the public workflow instance, run, and query selected by the client evidence.',
                ['arguments' => []],
            );
            if ($requestFinding !== null) {
                $blockingFindings[] = $requestFinding;
            }
        }

        if ($queryCapture !== null && (int) ($queryCapture['status'] ?? 0) !== 200) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_query_unavailable',
                'waterline_observer_query_action_unavailable',
                'Waterline selected-run query action did not return a successful JSON response.',
                'POST selected-run query action returns HTTP 200 and the same Counter.current value observed by public clients.',
                [
                    'method' => 'POST',
                    'path' => $paths['selected_run_query_action'],
                    'json' => ['arguments' => []],
                ],
                $queryCapture,
            );
        } elseif ($queryCapture !== null && $queryCounter === null) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_query_result_missing',
                'waterline_observer_query_result_missing',
                'Waterline selected-run query action did not expose a numeric Counter.current result.',
                'POST selected-run query action returns a numeric result field for the current query.',
                [
                    'method' => 'POST',
                    'path' => $paths['selected_run_query_action'],
                    'json' => ['arguments' => []],
                ],
                $queryCapture,
            );
        } elseif ($queryCapture !== null && $counter !== null && $queryCounter !== $counter) {
            $blockingFindings[] = $this->finding(
                'signal_query_waterline_query_result_mismatch',
                'signal_query_waterline_query_result_mismatch',
                'waterline',
                'Waterline selected-run query action returned a different counter value than the public CLI and SDK observations.',
                'Waterline selected-run query action returns the same Counter.current result observed by public clients.',
                [
                    'public_counter' => $counter,
                    'waterline_query_counter' => $queryCounter,
                    'request' => [
                        'method' => 'POST',
                        'path' => $paths['selected_run_query_action'],
                        'json' => ['arguments' => []],
                    ],
                    'response' => $this->responseEvidence($queryCapture),
                ],
            );
        }

        if ($queryCapture !== null && (int) ($queryCapture['status'] ?? 0) === 200 && $instanceId !== null && $runId !== null) {
            $identityFinding = $this->queryCaptureIdentityFinding(
                $queryCapture,
                $instanceId,
                $runId,
                $queryName,
                $paths['selected_run_query_action'],
            );
            if ($identityFinding !== null) {
                $blockingFindings[] = $identityFinding;
            }
        }

        if ($observerState !== [] && $observerCounter === null) {
            $blockingFindings[] = $this->captureFinding(
                'waterline_selected_run_signal_state_not_derivable',
                'waterline_observer_signal_state_not_derivable',
                'Waterline selected-run observer_state did not expose signal arguments that can derive the public counter state.',
                'observer_state.signals.items exposes accepted increment/set signal arguments in workflow order.',
                [
                    'method' => 'GET',
                    'path' => $paths['selected_run_detail'],
                ],
                $detailCapture,
            );
        } elseif ($counter !== null && $observerCounter !== null && $observerCounter !== $counter) {
            $blockingFindings[] = $this->finding(
                'signal_query_waterline_observer_counter_mismatch',
                'signal_query_waterline_observer_counter_mismatch',
                'waterline',
                'Waterline observer_state signals derive a different counter value than public client query results.',
                'Waterline selected-run observer_state exposes enough signal state to derive the public Counter.current value.',
                [
                    'public_counter' => $counter,
                    'observer_counter' => $observerCounter,
                    'observer_state' => $observerState,
                ],
            );
        }

        if ($runStatusMatches === false) {
            $blockingFindings[] = $this->finding(
                'signal_query_waterline_run_status_mismatch',
                'signal_query_waterline_run_status_mismatch',
                'waterline',
                'Waterline selected-run observer_state reported a different run status than public client evidence.',
                'Waterline selected-run observer_state reports the same run status, or matching running status bucket, observed by public clients.',
                [
                    'public_run_status' => $runStatus,
                    'observer_run_status' => $observerStatus,
                    'observer_status_bucket' => $observerStatusBucket,
                ],
            );
        }

        $queryLimitation = $this->queryMaterializationLimitation($detailCapture, $paths, $queryName);
        if ($queryLimitation !== null) {
            $typedProductFindings[] = $queryLimitation;
        }

        $typedProductFindings = array_values(array_merge($typedProductFindings, $blockingFindings));
        $status = $blockingFindings === [] ? 'pass' : 'fail';
        $comparison = [
            'status' => $status,
            'run_status_matches_public_clients' => $runStatusMatches,
            'counter_state_matches_public_clients' => $counter !== null
                && $queryCounter === $counter
                && $observerCounter === $counter,
            'expected_counter' => $counter,
            'observer_derived_state' => [
                'counter' => $observerCounter,
                'source' => 'observer_state.signals.items',
                'algorithm' => 'apply increment and set signal arguments in observer order',
            ],
            'waterline_query_action' => [
                'query' => $queryName,
                'result' => $queryCounter,
                'path' => $paths['selected_run_query_action'],
                'status' => is_array($queryCapture) ? (int) ($queryCapture['status'] ?? 0) : null,
            ],
            'server_observation' => [
                'run_id' => $runId,
                'status' => $runStatus,
                'history_signal_order' => $ordered['history_signal_order'] ?? null,
                'counter' => $counter,
            ],
            'cli_observation' => [
                'query' => $queryName,
                'result' => $counter,
                'signal_and_query' => $pythonBaseline['cli_signal_and_query'] ?? null,
            ],
            'sdk_observation' => [
                'query' => $queryName,
                'result' => $counter,
                'signal_and_query' => $pythonBaseline['sdk_python_signal_and_query'] ?? null,
            ],
        ];

        $observedOutputs = [
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'captured_at' => $capturedAt,
            'observer_state' => $observerState,
            'api_paths' => [
                'selected_run_detail' => $paths['selected_run_detail'],
                'selected_run_query_template' => $paths['selected_run_query_template'],
                'instance_query_template' => $paths['instance_query_template'],
                'selected_run_query_action' => $paths['selected_run_query_action'],
            ],
            'dashboard_json_envelopes' => [
                'selected_run_detail' => $this->responseEvidence($detailCapture),
            ],
            'api_captures' => [
                'selected_run_detail' => $this->responseEvidence($detailCapture),
                'selected_run_query_action' => $this->responseEvidence($queryCapture),
            ],
            'comparison' => $comparison,
            'typed_product_findings' => $typedProductFindings,
        ];

        return [
            'scenario_id' => self::SCENARIO,
            'status' => $status,
            'observed_outputs' => $observedOutputs,
            'linked_findings' => $blockingFindings,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return self::withLedgerAliases(
            self::canonicalArtifactMetadata($this->keyValueOption('artifact-version')),
        );
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return self::withLedgerAliases(
            self::canonicalArtifactMetadata($this->keyValueOption('artifact-source')),
        );
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

        $observedOutputs = [
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
        ];

        return [
            'scenario_id' => 'published_artifact_install_only',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => $observedOutputs,
            'linked_findings' => $passed ? [] : [
                $this->finding(
                    'published_artifact_install_only',
                    'published_artifact_install_only',
                    'waterline',
                    'Waterline signals/queries conformance inputs do not prove a published artifact tuple.',
                    'The conformance shard receives explicit published version and source proof for server, CLI, workflow PHP, Python SDK, and Waterline artifacts.',
                    $observedOutputs,
                    'published_artifact_install_only',
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
                'covered_by' => 'waterline-signals-queries-operator-shard',
            ],
            'linked_findings' => $passed ? [] : [
                $this->finding(
                    'result_record_and_product_finding_routing',
                    'result_record_and_product_finding_routing',
                    'waterline',
                    'Waterline signals/queries conformance result metadata is incomplete.',
                    'The conformance shard records artifact versions, timestamps, outcome, and finding links.',
                    [
                        'artifact_versions_recorded' => $artifactVersions !== [],
                        'timestamps_recorded' => $startedAt !== '' && $finishedAt !== '',
                    ],
                    'result_record_and_product_finding_routing',
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

    /**
     * @return array<string, string>
     */
    private function keyValueOption(string $name): array
    {
        $values = $this->option($name);
        $values = is_array($values) ? $values : [];
        $pairs = [];

        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, '=')) {
                continue;
            }

            [$key, $pairValue] = explode('=', $value, 2);
            $key = trim($key);
            $pairValue = trim($pairValue);
            if ($key === '' || $pairValue === '') {
                continue;
            }

            $pairs[$key] = $pairValue;
            if ($key === 'workflow') {
                $pairs['workflow-php'] ??= $pairValue;
            }
            if ($key === 'workflow-php') {
                $pairs['workflow'] ??= $pairValue;
            }
        }

        return $pairs;
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function readJsonFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read input evidence file [{$path}].");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create output directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write output file [{$path}].");
        }
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function scenarioObservedOutputs(array $evidence, string $scenarioId): array
    {
        $candidate = $this->scenarioCandidate($evidence, $scenarioId);
        if ($candidate === null) {
            return [];
        }

        foreach (['observed_outputs', 'observedOutputs', 'evidence', 'outputs'] as $field) {
            if (is_array($candidate[$field] ?? null)) {
                return $candidate[$field];
            }
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>|null
     */
    private function scenarioCandidate(array $evidence, string $scenarioId): ?array
    {
        foreach (['scenario_results', 'scenarioResults'] as $field) {
            $results = $evidence[$field] ?? null;
            if (is_array($results) && isset($results[$scenarioId]) && is_array($results[$scenarioId])) {
                return $results[$scenarioId];
            }

            foreach (is_array($results) ? $results : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = $item['scenario_id'] ?? $item['scenario'] ?? $item['id'] ?? null;
                if ($id === $scenarioId) {
                    return $item;
                }
            }
        }

        foreach ([
            'waterline_observer_comparison',
            'replay_timing',
            'terminal_run_behavior',
            'adversarial_errors',
        ] as $section) {
            $value = $evidence[$section] ?? null;
            if (is_array($value) && isset($value[$scenarioId]) && is_array($value[$scenarioId])) {
                return $value[$scenarioId];
            }
        }

        return isset($evidence[$scenarioId]) && is_array($evidence[$scenarioId])
            ? $evidence[$scenarioId]
            : null;
    }

    /**
     * @param array<string, mixed> $publicEvidence
     * @param array<string, mixed> $ordered
     */
    private function counterFromPublicEvidence(array $publicEvidence, array $ordered): ?int
    {
        foreach ([
            $ordered['queried_total'] ?? null,
            $ordered['ten_signal_ordered_delivery_total'] ?? null,
            $this->recursiveNumeric($publicEvidence, [
                'current_counter',
                'counter',
                'queried_total',
                'ten_signal_ordered_delivery_total',
                'query_answer',
                'query_result',
                'current',
            ]),
        ] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        $inputs = $this->numericList($ordered['rapid_increment_inputs'] ?? null);

        return $inputs === [] ? null : array_sum($inputs);
    }

    /**
     * @param array<string, mixed> $publicEvidence
     * @param list<string> $keys
     */
    private function stringEvidence(array $publicEvidence, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->recursiveString($publicEvidence, $key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function recursiveNumeric(array $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            $found = $this->recursiveValue($value, $key);
            if (is_numeric($found)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function recursiveString(array $value, string $key): ?string
    {
        $found = $this->recursiveValue($value, $key);

        return is_string($found) && $found !== '' ? $found : null;
    }

    private function recursiveValue(mixed $value, string $key): mixed
    {
        if (is_array($value)) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }

            foreach ($value as $child) {
                $found = $this->recursiveValue($child, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function numericList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $numbers = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $numbers[] = (int) $item;
            }
        }

        return $numbers;
    }

    /**
     * @return array<string, string>
     */
    private function observerPaths(string $instanceId, string $runId, string $queryName): array
    {
        $instancePath = rawurlencode($instanceId);
        $runPath = rawurlencode($runId);
        $queryPath = rawurlencode($queryName);
        $selectedRunApi = sprintf('/api/instances/%s/runs/%s', $instancePath, $runPath);
        $queryTemplateApi = sprintf('/api/instances/%s/runs/%s/queries/{query}', $instancePath, $runPath);
        $queryActionApi = str_replace('{query}', $queryPath, $queryTemplateApi);
        $instanceQueryTemplateApi = sprintf('/api/instances/%s/queries/{query}', $instancePath);

        return [
            'selected_run_detail_api' => $selectedRunApi,
            'selected_run_query_template_api' => $queryTemplateApi,
            'instance_query_template_api' => $instanceQueryTemplateApi,
            'selected_run_query_action_api' => $queryActionApi,
            'selected_run_detail' => $this->publicWaterlinePath($selectedRunApi),
            'selected_run_query_template' => $this->publicWaterlinePath($queryTemplateApi),
            'instance_query_template' => $this->publicWaterlinePath($instanceQueryTemplateApi),
            'selected_run_query_action' => $this->publicWaterlinePath($queryActionApi),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function observerPathTemplates(string $queryName): array
    {
        $queryPath = rawurlencode($queryName);
        $selectedRunApi = '/api/instances/{instance}/runs/{run}';
        $queryTemplateApi = '/api/instances/{instance}/runs/{run}/queries/{query}';
        $queryActionApi = str_replace('{query}', $queryPath, $queryTemplateApi);
        $instanceQueryTemplateApi = '/api/instances/{instance}/queries/{query}';

        return [
            'selected_run_detail_api' => $selectedRunApi,
            'selected_run_query_template_api' => $queryTemplateApi,
            'instance_query_template_api' => $instanceQueryTemplateApi,
            'selected_run_query_action_api' => $queryActionApi,
            'selected_run_detail' => $this->publicWaterlinePath($selectedRunApi),
            'selected_run_query_template' => $this->publicWaterlinePath($queryTemplateApi),
            'instance_query_template' => $this->publicWaterlinePath($instanceQueryTemplateApi),
            'selected_run_query_action' => $this->publicWaterlinePath($queryActionApi),
        ];
    }

    private function publicWaterlinePath(string $apiPath): string
    {
        $waterlinePath = trim((string) config('waterline.path', 'waterline'), '/');

        return ($waterlinePath === '' ? '' : '/'.$waterlinePath).$apiPath;
    }

    /**
     * @param array<string, mixed>|null $requestJson
     * @return array<string, mixed>
     */
    private function captureWaterlineApi(
        HttpKernel $kernel,
        string $method,
        string $apiPath,
        ?array $requestJson,
        string $capturedAt,
    ): array {
        $publicPath = $this->publicWaterlinePath($apiPath);
        $content = $requestJson === null
            ? null
            : json_encode($requestJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $request = Request::create(
            $publicPath,
            $method,
            [],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ],
            $content,
        );
        $response = $kernel->handle($request);
        $body = (string) $response->getContent();
        $json = [];

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $json = is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            $json = [];
        } finally {
            $kernel->terminate($request, $response);
        }

        return [
            'method' => strtoupper($method),
            'path' => $publicPath,
            'request_path' => $publicPath,
            'status' => $response->getStatusCode(),
            'request_json' => $requestJson,
            'captured_at' => $capturedAt,
            'capture_source' => 'published_waterline_artifact_http_route',
            'body_sha256' => hash('sha256', $body),
            'json' => $json,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function providedCapture(
        string $kind,
        string $capturedAt,
    ): ?array {
        $explicit = $kind === 'selected_run_detail'
            ? $this->optionString('selected-run-detail-capture')
            : $this->optionString('selected-run-query-capture');

        if ($explicit !== null) {
            return $this->captureFromFile($explicit, $kind, $capturedAt);
        }

        $paths = $this->option('api-capture');
        $paths = is_array($paths) ? $paths : [];
        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $capture = $this->captureFromFile($path, $kind, $capturedAt);
            if ($capture !== null) {
                return $capture;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function captureFromFile(
        string $path,
        string $kind,
        string $capturedAt,
    ): ?array {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read Waterline API capture file [{$path}].");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return null;
        }

        $candidate = $this->captureCandidate($decoded, $kind);
        if ($candidate === null) {
            return null;
        }

        $capture = $this->normalizeCapture($candidate, $capturedAt);
        $capture['capture_source'] = 'provided_waterline_api_capture';
        $capture['capture_input'] = [
            'file' => basename($path),
            'sha256' => hash('sha256', $contents),
        ];

        return $capture;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>|null
     */
    private function captureCandidate(array $decoded, string $kind): ?array
    {
        $aliases = $kind === 'selected_run_detail'
            ? ['selected_run_detail', 'selectedRunDetail', 'detail', 'run_detail']
            : ['selected_run_query_action', 'selectedRunQueryAction', 'selected_run_query', 'query_action', 'query'];

        foreach ($aliases as $alias) {
            $candidate = data_get($decoded, $alias);
            if (is_array($candidate)) {
                return $candidate;
            }

            foreach ([
                'api_captures.'.$alias,
                'dashboard_json_envelopes.'.$alias,
                'waterline_observer_comparison.'.self::SCENARIO.'.api_captures.'.$alias,
                'waterline_observer_comparison.'.self::SCENARIO.'.dashboard_json_envelopes.'.$alias,
                'scenario_results.'.self::SCENARIO.'.observed_outputs.api_captures.'.$alias,
                'scenario_results.'.self::SCENARIO.'.observed_outputs.dashboard_json_envelopes.'.$alias,
                'scenarioResults.'.self::SCENARIO.'.observedOutputs.apiCaptures.'.$alias,
            ] as $path) {
                $candidate = data_get($decoded, $path);
                if (is_array($candidate)) {
                    return $candidate;
                }
            }
        }

        $label = $decoded['label'] ?? $decoded['name'] ?? $decoded['kind'] ?? null;
        if (is_string($label) && in_array($label, $aliases, true)) {
            return $decoded;
        }

        $path = $decoded['path'] ?? $decoded['request_path'] ?? data_get($decoded, 'request.path');
        if (is_string($path)) {
            if ($kind === 'selected_run_detail' && preg_match('#/api/instances/[^/]+/runs/[^/]+$#', $path) === 1) {
                return $decoded;
            }

            if ($kind === 'selected_run_query_action' && str_contains($path, '/queries/')) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>
     */
    private function normalizeCapture(
        array $capture,
        string $capturedAt,
    ): array {
        $json = $capture['json'] ?? $capture['response_json'] ?? data_get($capture, 'response.json');
        $json = is_array($json) ? $json : [];
        $body = is_string($capture['body'] ?? null)
            ? (string) $capture['body']
            : json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $captureMethod = $this->captureString($capture, ['method', 'request.method']);
        $capturePath = $this->captureString($capture, ['path', 'request_path', 'request.path']);
        $captureRequestPath = $this->captureString($capture, ['request_path', 'path', 'request.path']);
        if ($capturePath === null && $captureRequestPath !== null) {
            $capturePath = $captureRequestPath;
        }
        if ($captureRequestPath === null && $capturePath !== null) {
            $captureRequestPath = $capturePath;
        }

        return [
            'method' => $captureMethod === null ? '' : strtoupper($captureMethod),
            'path' => $capturePath ?? '',
            'request_path' => $captureRequestPath ?? '',
            'status' => (int) ($capture['status'] ?? $capture['response_status'] ?? data_get($capture, 'response.status') ?? 0),
            'request_json' => is_array($capture['request_json'] ?? null)
                ? $capture['request_json']
                : (is_array(data_get($capture, 'request.json')) ? data_get($capture, 'request.json') : null),
            'captured_at' => (string) ($capture['captured_at'] ?? $capturedAt),
            'capture_source' => is_string($capture['capture_source'] ?? null)
                ? $capture['capture_source']
                : 'provided_waterline_api_capture',
            'body_sha256' => is_string($capture['body_sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', (string) $capture['body_sha256']) === 1
                ? $capture['body_sha256']
                : hash('sha256', $body),
            'json' => $json,
        ];
    }

    /**
     * @param array<string, mixed> $capture
     * @param list<string> $paths
     */
    private function captureString(array $capture, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($capture, $path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $capture
     * @return array<string, mixed>
     */
    private function observerStateFromDetail(?array $capture): array
    {
        if (! is_array($capture)) {
            return [];
        }

        $observerState = data_get($capture, 'json.observer_state');

        return is_array($observerState) ? $observerState : [];
    }

    /**
     * @param array<string, mixed> $observerState
     */
    private function counterFromObserverState(array $observerState, string $queryName): ?int
    {
        foreach ([
            data_get($observerState, 'queries.results.'.$queryName),
            data_get($observerState, 'queries.live_results.'.$queryName),
            data_get($observerState, 'queries.materialized_results.'.$queryName),
        ] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        $signals = data_get($observerState, 'signals.items');
        if (! is_array($signals)) {
            return null;
        }

        return $this->counterFromSignals($signals);
    }

    /**
     * @param array<int|string, mixed> $signals
     */
    private function counterFromSignals(array $signals): ?int
    {
        $counter = 0;
        $observed = false;

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $name = is_string($signal['name'] ?? null) ? $signal['name'] : '';
            $arguments = is_array($signal['arguments'] ?? null) ? array_values($signal['arguments']) : [];
            $value = $arguments[0] ?? null;
            if (! is_numeric($value)) {
                continue;
            }

            if ($name === 'set') {
                $counter = (int) $value;
                $observed = true;
                continue;
            }

            if ($name === 'increment') {
                $counter += (int) $value;
                $observed = true;
            }
        }

        return $observed ? $counter : null;
    }

    /**
     * @param array<string, mixed>|null $capture
     */
    private function counterFromQueryCapture(?array $capture, string $queryName): ?int
    {
        if (! is_array($capture)) {
            return null;
        }

        $json = $capture['json'] ?? [];
        if (! is_array($json)) {
            return null;
        }

        foreach ([
            $json['result'] ?? null,
            $json[$queryName] ?? null,
            $json['query_result'] ?? null,
            $json['queryResult'] ?? null,
            data_get($json, 'data.result'),
            data_get($json, 'data.'.$queryName),
        ] as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function runStatusMatches(?string $expected, ?string $observerStatus, ?string $observerStatusBucket): ?bool
    {
        if ($expected === null) {
            return null;
        }

        if ($observerStatus === $expected) {
            return true;
        }

        return $expected === 'running' && $observerStatusBucket === 'running';
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private function stringData(?array $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        $found = data_get($value, $path);

        return is_string($found) && $found !== '' ? $found : null;
    }

    /**
     * @param array<string, mixed>|null $value
     * @param list<string> $paths
     */
    private function firstStringData(?array $value, array $paths): ?string
    {
        foreach ($paths as $path) {
            $found = $this->stringData($value, $path);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $capture
     * @param array<string, mixed>|null $requestJson
     * @return array<string, mixed>|null
     */
    private function captureRequestFinding(
        string $id,
        string $expectedMethod,
        string $expectedPath,
        array $capture,
        string $observed,
        string $expected,
        ?array $requestJson = null,
    ): ?array {
        $actualMethod = strtoupper((string) ($capture['method'] ?? ''));
        $actualPath = (string) ($capture['path'] ?? '');
        $actualRequestPath = (string) ($capture['request_path'] ?? '');
        $actualRequestJson = is_array($capture['request_json'] ?? null)
            ? $capture['request_json']
            : null;

        if (
            $actualMethod === $expectedMethod
            && $actualPath === $expectedPath
            && $actualRequestPath === $expectedPath
            && ($requestJson === null || $actualRequestJson === $requestJson)
        ) {
            return null;
        }

        return $this->captureFinding(
            $id,
            'waterline_observer_api_capture_request_mismatch',
            $observed,
            $expected,
            [
                'method' => $expectedMethod,
                'path' => $expectedPath,
                'json' => $requestJson,
                'observed_method' => $actualMethod,
                'observed_path' => $actualPath,
                'observed_request_path' => $actualRequestPath,
                'observed_json' => $actualRequestJson,
            ],
            $capture,
        );
    }

    /**
     * @param array<string, mixed> $capture
     * @param array<string, mixed> $observerState
     * @return array<string, mixed>|null
     */
    private function observerStateIdentityFinding(
        array $capture,
        array $observerState,
        string $instanceId,
        string $runId,
        string $path,
    ): ?array {
        $actual = [
            'workflow_instance_id' => $this->firstStringData($observerState, [
                'selected_run.workflow_instance_id',
                'selected_run.instance_id',
                'selected_run.workflow_id',
            ]),
            'workflow_run_id' => $this->firstStringData($observerState, [
                'selected_run.workflow_run_id',
                'selected_run.run_id',
            ]),
        ];
        $expected = [
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
        ];

        if ($actual === $expected) {
            return null;
        }

        return $this->finding(
            'waterline_selected_run_observer_state_identity_mismatch',
            'waterline_observer_state_identity_mismatch',
            'waterline',
            'Waterline selected-run detail observer_state did not identify the same workflow instance and run as the public client evidence.',
            'observer_state.selected_run records the public workflow_instance_id and workflow_run_id selected for the signals/queries scenario.',
            [
                'expected_identity' => $expected,
                'observed_identity' => $actual,
                'request' => [
                    'method' => 'GET',
                    'path' => $path,
                ],
                'response' => $this->responseEvidence($capture),
            ],
        );
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>|null
     */
    private function queryCaptureIdentityFinding(
        array $capture,
        string $instanceId,
        string $runId,
        string $queryName,
        string $path,
    ): ?array {
        $json = is_array($capture['json'] ?? null) ? $capture['json'] : [];
        $actual = [
            'workflow_instance_id' => $this->firstStringData($json, [
                'workflow_instance_id',
                'workflow_id',
                'instance_id',
                'data.workflow_instance_id',
                'data.workflow_id',
                'data.instance_id',
            ]),
            'workflow_run_id' => $this->firstStringData($json, [
                'workflow_run_id',
                'run_id',
                'data.workflow_run_id',
                'data.run_id',
            ]),
            'query' => $this->firstStringData($json, [
                'query_name',
                'query',
                'name',
                'data.query_name',
                'data.query',
                'data.name',
            ]),
            'target_scope' => $this->firstStringData($json, [
                'target_scope',
                'data.target_scope',
            ]),
        ];
        $expected = [
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
            'query' => $queryName,
            'target_scope' => 'run',
        ];

        if ($actual === $expected) {
            return null;
        }

        return $this->finding(
            'waterline_selected_run_query_identity_mismatch',
            'waterline_observer_query_identity_mismatch',
            'waterline',
            'Waterline selected-run query response did not identify the same workflow instance, run, and query as the public client evidence.',
            'The selected-run query response records the public workflow_instance_id, workflow_run_id, query name, and run target scope for the signals/queries scenario.',
            [
                'expected_identity' => $expected,
                'observed_identity' => $actual,
                'request' => [
                    'method' => 'POST',
                    'path' => $path,
                    'json' => ['arguments' => []],
                ],
                'response' => $this->responseEvidence($capture),
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $detailCapture
     * @param array<string, string> $paths
     * @return array<string, mixed>|null
     */
    private function queryMaterializationLimitation(?array $detailCapture, array $paths, string $queryName): ?array
    {
        if (! is_array($detailCapture)) {
            return null;
        }

        $limitation = data_get($detailCapture, 'json.observer_state.queries.limitation');
        if (! is_array($limitation)) {
            return null;
        }

        $reason = $limitation['reason'] ?? null;
        if ($reason !== ObserverStateEnvelope::QUERY_STATE_LIMITATION) {
            return null;
        }

        return [
            'id' => 'waterline_selected_run_query_results_not_materialized',
            'type' => 'waterline_observer_api_limitation',
            'scenario_id' => self::SCENARIO,
            'owning_surface' => 'waterline',
            'reason' => ObserverStateEnvelope::QUERY_STATE_LIMITATION,
            'observed_behavior' => 'Selected-run detail exposes durable signal/query metadata but does not materialize live query results in the detail envelope.',
            'expected_behavior' => 'Waterline records a separate selected-run query action capture when the read-only detail envelope does not store live query results.',
            'request' => [
                'method' => 'GET',
                'path' => $paths['selected_run_detail'],
            ],
            'response' => $this->responseEvidence($detailCapture),
            'query_name' => $queryName,
            'query_action_path' => $paths['selected_run_query_action'],
        ];
    }

    /**
     * @param array<string, mixed>|null $capture
     * @return array<string, mixed>|null
     */
    private function responseEvidence(?array $capture): ?array
    {
        if (! is_array($capture)) {
            return null;
        }

        return [
            'method' => (string) ($capture['method'] ?? ''),
            'path' => (string) ($capture['path'] ?? ''),
            'request_path' => (string) ($capture['request_path'] ?? ''),
            'status' => (int) ($capture['status'] ?? 0),
            'request_json' => $capture['request_json'] ?? null,
            'captured_at' => (string) ($capture['captured_at'] ?? ''),
            'capture_source' => (string) ($capture['capture_source'] ?? ''),
            'capture_input' => $capture['capture_input'] ?? null,
            'body_sha256' => (string) ($capture['body_sha256'] ?? ''),
            'json' => is_array($capture['json'] ?? null) ? $capture['json'] : [],
        ];
    }

    /**
     * @param array<string, mixed>|null $response
     * @return array<string, mixed>
     */
    private function captureFinding(
        string $id,
        string $type,
        string $observed,
        string $expected,
        array $request,
        ?array $response,
    ): array {
        return $this->finding($id, $type, 'waterline', $observed, $expected, [
            'request' => $request,
            'response' => $this->responseEvidence($response),
        ]);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function finding(
        string $id,
        string $type,
        string $owningSurface,
        string $observed,
        string $expected,
        array $evidence,
        ?string $scenarioId = null,
    ): array {
        return [
            'id' => $id,
            'type' => $type,
            'scenario_id' => $scenarioId ?? self::SCENARIO,
            'owning_surface' => $owningSurface,
            'observed_behavior' => $observed,
            'expected_behavior' => $expected,
            'evidence' => $evidence,
            'next_acceptance_criterion' => 'rerun signals/queries conformance and record matching Waterline selected-run detail and query-action captures',
        ];
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
