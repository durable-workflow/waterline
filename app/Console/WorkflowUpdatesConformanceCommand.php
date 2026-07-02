<?php

namespace Waterline\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use JsonException;
use RuntimeException;
use Throwable;
use Workflow\V2\Support\PlatformConformanceSuite;

class WorkflowUpdatesConformanceCommand extends Command
{
    private const RESULT_SCHEMA = 'durable-workflow.v2.workflow-updates.waterline-operator-shard';

    private const RESULT_VERSION = 1;

    private const SCENARIO = 'operator_diagnostics_surfaces';

    /**
     * @var list<string>
     */
    private const REQUIRED_STATES = [
        'accepted',
        'completed',
        'failed',
        'refused',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const REQUIRED_HISTORY_TYPES = [
        'accepted' => ['UpdateAccepted'],
        'completed' => ['UpdateAccepted', 'UpdateCompleted'],
        'failed' => ['UpdateAccepted', 'UpdateCompleted'],
        'refused' => ['UpdateRejected'],
    ];

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

    protected $signature = 'waterline:workflow-updates-conformance
        {--input= : JSON evidence captured by the public workflow-updates runner}
        {--output= : File path for the Waterline workflow-updates shard result}
        {--run-id= : Stable run id to include in the shard metadata}
        {--instance-id= : Workflow instance id to inspect when it is not present in the public evidence or capture}
        {--workflow-run-id= : Workflow run id to inspect when it is not present in the public evidence or capture}
        {--selected-run-detail-capture= : JSON capture from GET /waterline/api/instances/<instance>/runs/<run>}
        {--selected-run-history-capture= : JSON capture from GET /waterline/api/instances/<instance>/runs/<run>/history-export}
        {--selected-run-history-export-capture= : Alias for --selected-run-history-capture}
        {--api-capture=* : Repeatable Waterline API capture JSON file; captures may be keyed by selected_run_detail or selected_run_history_export}
        {--artifact-version=* : Published artifact version pair, for example server=0.2.250}
        {--artifact-source=* : Published artifact source pair, for example waterline=packagist_package}';

    protected $description = 'Emit Waterline selected-run workflow update diagnostics evidence for the workflow-updates conformance suite.';

    public function handle(HttpKernel $kernel): int
    {
        $startedAt = $this->timestamp();
        $inputPath = $this->optionString('input');
        $outputPath = $this->optionString('output');
        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();
        $originalConfig = [
            'waterline.engine_source' => config('waterline.engine_source'),
            'waterline.allow_unauthenticated' => config('waterline.allow_unauthenticated'),
        ];

        try {
            config()->set('waterline.engine_source', 'v2');
            config()->set('waterline.allow_unauthenticated', true);

            $publicEvidence = $inputPath === null ? [] : $this->readJsonFile($inputPath);
            $detailCapture = $this->providedCapture('selected_run_detail', $startedAt);
            $historyCapture = $this->providedCapture('selected_run_history_export', $startedAt);
            $instanceId = $this->optionString('instance-id')
                ?? $this->stringEvidence($publicEvidence, ['workflow_instance_id', 'instance_id', 'workflow_id'])
                ?? $this->instanceIdFromCapture($detailCapture)
                ?? $this->instanceIdFromCapture($historyCapture);
            $runId = $this->optionString('workflow-run-id')
                ?? $this->stringEvidence($publicEvidence, ['workflow_run_id', 'selected_run_id', 'run_id'])
                ?? $this->runIdFromCapture($detailCapture)
                ?? $this->runIdFromCapture($historyCapture);
            $paths = $instanceId !== null && $runId !== null
                ? $this->observerPaths($instanceId, $runId)
                : $this->observerPathTemplates();

            if ($detailCapture === null && $instanceId !== null && $runId !== null) {
                $detailCapture = $this->captureWaterlineApi(
                    $kernel,
                    'GET',
                    $paths['selected_run_detail_api'],
                    $startedAt,
                );
            }

            if ($historyCapture === null && $instanceId !== null && $runId !== null) {
                $historyCapture = $this->captureWaterlineApi(
                    $kernel,
                    'GET',
                    $paths['selected_run_history_export_api'],
                    $startedAt,
                );
            }

            $operatorScenario = $this->operatorDiagnosticsScenario(
                $detailCapture,
                $historyCapture,
                $paths,
                $instanceId,
                $runId,
            );
            $finishedAt = $this->timestamp();
            $scenarioResults = $this->scenarioResults(
                $this->publishedArtifactScenario($artifactVersions, $artifactSources),
                $operatorScenario,
            );
            $hasFailures = self::hasScenarioFailures($scenarioResults);
            $result = [
                'schema' => self::RESULT_SCHEMA,
                'schema_version' => self::RESULT_VERSION,
                'suite_version' => $this->suiteVersion(),
                'coverage_scope' => 'waterline-workflow-updates-operator-shard',
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
                        'selected_run_history_export',
                        'selected_run_update_action',
                        'selected_run_update_lookup',
                    ],
                ],
                'scenario_results' => array_values($scenarioResults),
                'waterline_update_diagnostics' => $operatorScenario['observed_outputs'],
                'api_captures' => $operatorScenario['observed_outputs']['api_captures'],
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
            try {
                $this->writeEmergencyFailureReport(
                    $exception,
                    $startedAt,
                    $artifactVersions,
                    $artifactSources,
                    $outputPath,
                );
            } catch (Throwable) {
                // Preserve the command failure; the original exception is reported below.
            }

            $this->error('Waterline workflow update diagnostics command failed before the normal report completed.');

            return self::FAILURE;
        } finally {
            foreach ($originalConfig as $key => $value) {
                config()->set($key, $value);
            }
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     */
    private function writeEmergencyFailureReport(
        Throwable $exception,
        string $startedAt,
        array $artifactVersions,
        array $artifactSources,
        ?string $outputPath,
    ): void {
        $finishedAt = $this->timestamp();
        $finding = $this->finding(
            'waterline_workflow_updates_report_generation_failed',
            'waterline_conformance_report_generation_failed',
            'waterline',
            'Waterline workflow update diagnostics command failed before the normal report completed.',
            'The command emits a durable workflow update diagnostics report for every published-artifact run, including typed failures.',
            [
                'exception_class' => $exception::class,
                'report_written_by' => 'emergency_failure_report',
            ],
        );
        $operatorScenario = [
            'scenario_id' => self::SCENARIO,
            'status' => 'fail',
            'surface' => 'selected-run update detail and history export',
            'output_sample' => json_encode([
                'report_generation_failed' => true,
                'finding_id' => $finding['id'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'observed_outputs' => [
                'report_generation_failed' => true,
                'api_captures' => [],
                'typed_product_findings' => [$finding],
            ],
            'linked_findings' => [$finding],
        ];
        $scenarioResults = $this->scenarioResults(
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
            $operatorScenario,
        );
        $result = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => $this->suiteVersion(),
            'coverage_scope' => 'waterline-workflow-updates-operator-shard',
            'run_id' => $this->optionString('run-id'),
            'outcome' => 'fail',
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
                    'selected_run_history_export',
                    'selected_run_update_action',
                    'selected_run_update_lookup',
                ],
            ],
            'scenario_results' => array_values($scenarioResults),
            'waterline_update_diagnostics' => $operatorScenario['observed_outputs'],
            'api_captures' => [],
            'findings' => $this->findings($scenarioResults),
            'finding_links' => $this->findingLinks($scenarioResults),
        ];
        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if ($outputPath !== null) {
            $this->writeFile($outputPath, $json);

            return;
        }

        $this->line($json);
    }

    /**
     * @param array<string, mixed>|null $detailCapture
     * @param array<string, mixed>|null $historyCapture
     * @param array<string, string> $paths
     * @return array<string, mixed>
     */
    private function operatorDiagnosticsScenario(
        ?array $detailCapture,
        ?array $historyCapture,
        array $paths,
        ?string $instanceId,
        ?string $runId,
    ): array {
        $findings = [];
        $detailJson = $this->captureJson($detailCapture);
        $historyJson = $this->captureJson($historyCapture);

        if ($instanceId === null || $runId === null) {
            $findings[] = $this->finding(
                'workflow_updates_waterline_target_identity_missing',
                'workflow_updates_waterline_target_identity_missing',
                'conformance_harness',
                'Waterline workflow update diagnostics cannot select a run because the evidence did not identify both workflow instance and run.',
                'Workflow update conformance evidence or Waterline captures identify workflow_instance_id and workflow_run_id for the selected run.',
                [
                    'instance_id_present' => $instanceId !== null,
                    'workflow_run_id_present' => $runId !== null,
                ],
            );
        }

        if ($detailCapture === null) {
            $findings[] = $this->captureFinding(
                'waterline_selected_run_detail_not_captured',
                'waterline_observer_api_capture_missing',
                'Waterline selected-run detail was not captured from the observer API.',
                'The workflow-updates Waterline shard captures GET selected-run detail from the published Waterline artifact.',
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
                'The selected-run detail capture records GET for the public workflow instance and run selected by the workflow-updates evidence.',
            );
            if ($requestFinding !== null) {
                $findings[] = $requestFinding;
            }

            if ((int) ($detailCapture['status'] ?? 0) !== 200) {
                $findings[] = $this->captureFinding(
                    'waterline_selected_run_detail_unavailable',
                    'waterline_observer_api_unavailable',
                    'Waterline selected-run detail did not return a successful JSON response.',
                    'GET selected-run detail returns HTTP 200 with selected-run workflow update diagnostics.',
                    [
                        'method' => 'GET',
                        'path' => $paths['selected_run_detail'],
                    ],
                    $detailCapture,
                );
            }
        }

        if ($historyCapture === null) {
            $findings[] = $this->captureFinding(
                'waterline_selected_run_history_export_not_captured',
                'waterline_observer_api_capture_missing',
                'Waterline selected-run history export was not captured from the observer API.',
                'The workflow-updates Waterline shard captures GET selected-run history export from the published Waterline artifact.',
                [
                    'method' => 'GET',
                    'path' => $paths['selected_run_history_export'],
                ],
                null,
            );
        } else {
            $requestFinding = $this->captureRequestFinding(
                'waterline_selected_run_history_export_capture_request_mismatch',
                'GET',
                $paths['selected_run_history_export'],
                $historyCapture,
                'Waterline selected-run history export capture was not collected from the expected observer API endpoint.',
                'The selected-run history export capture records GET for the public workflow instance and run selected by the workflow-updates evidence.',
            );
            if ($requestFinding !== null) {
                $findings[] = $requestFinding;
            }

            if ((int) ($historyCapture['status'] ?? 0) !== 200) {
                $findings[] = $this->captureFinding(
                    'waterline_selected_run_history_export_unavailable',
                    'waterline_observer_api_unavailable',
                    'Waterline selected-run history export did not return a successful JSON response.',
                    'GET selected-run history export returns HTTP 200 with update history events for the selected run.',
                    [
                        'method' => 'GET',
                        'path' => $paths['selected_run_history_export'],
                    ],
                    $historyCapture,
                );
            }
        }

        if ($detailJson !== [] && $instanceId !== null && $runId !== null) {
            $identityFinding = $this->selectedRunIdentityFinding($detailCapture, $detailJson, $instanceId, $runId, $paths);
            if ($identityFinding !== null) {
                $findings[] = $identityFinding;
            }
        }

        $updates = $this->mergeUpdateRows(
            $this->updateRowsFromDetail($detailJson),
            $this->updateRowsFromHistoryExport($historyJson),
        );
        $historyEvents = $this->historyEventsFromExport($historyJson);
        $matrix = $this->operatorSurfaceMatrix($updates, $historyEvents);

        foreach (self::REQUIRED_STATES as $state) {
            $stateEvidence = $matrix['states'][$state] ?? ['present' => false];

            if (($stateEvidence['present'] ?? false) !== true) {
                $findings[] = $this->finding(
                    sprintf('waterline_selected_run_update_%s_missing', $state),
                    'waterline_update_state_surface_missing',
                    'waterline',
                    sprintf('Waterline selected-run detail did not expose a %s workflow update row.', $state),
                    'Selected-run update diagnostics expose accepted, completed, failed, and refused update rows.',
                    [
                        'required_state' => $state,
                        'available_state_counts' => $matrix['state_counts'],
                        'request' => [
                            'method' => 'GET',
                            'path' => $paths['selected_run_detail'],
                        ],
                        'response' => $this->responseEvidence($detailCapture),
                    ],
                );

                continue;
            }

            $missing = [];
            foreach ([
                'request_identifiers_visible',
                'payload_visible',
                'outcome_or_reason_visible',
                'history_references_visible',
                'history_export_references_visible',
            ] as $field) {
                if (($stateEvidence[$field] ?? false) === false) {
                    $missing[] = $field;
                }
            }

            if (($stateEvidence['result_visible'] ?? null) === false) {
                $missing[] = 'result_visible';
            }

            if (($stateEvidence['error_visible'] ?? null) === false) {
                $missing[] = 'error_visible';
            }

            if ($missing !== []) {
                $findings[] = $this->finding(
                    sprintf('waterline_selected_run_update_%s_diagnostics_incomplete', $state),
                    'waterline_update_diagnostics_incomplete',
                    'waterline',
                    sprintf('Waterline selected-run update diagnostics for the %s path were incomplete.', $state),
                    'Each selected-run update path exposes request identifiers, outcome or reason, payload/result/error details, and matching history references.',
                    [
                        'required_state' => $state,
                        'missing_fields' => $missing,
                        'state_evidence' => $stateEvidence,
                        'request' => [
                            'method' => 'GET',
                            'path' => $paths['selected_run_detail'],
                        ],
                        'history_request' => [
                            'method' => 'GET',
                            'path' => $paths['selected_run_history_export'],
                        ],
                    ],
                );
            }
        }

        $status = $findings === [] ? 'pass' : 'fail';
        $observedOutputs = [
            'api_paths' => [
                'selected_run_detail' => $paths['selected_run_detail'],
                'selected_run_history_export' => $paths['selected_run_history_export'],
                'selected_run_update_template' => $paths['selected_run_update_template'],
                'selected_run_update_lookup_template' => $paths['selected_run_update_lookup_template'],
            ],
            'api_captures' => [
                'selected_run_detail' => $this->responseEvidence($detailCapture),
                'selected_run_history_export' => $this->responseEvidence($historyCapture),
            ],
            'operator_surface_matrix' => $matrix,
            'selected_run_updates' => $this->compactUpdateEvidence($updates),
            'history_update_events' => $this->compactHistoryEvidence($historyEvents),
            'typed_product_findings' => $findings,
        ];

        return [
            'scenario_id' => self::SCENARIO,
            'status' => $status,
            'surface' => 'selected-run update detail and history export',
            'output_sample' => substr(json_encode($observedOutputs, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0, 4000),
            'observed_outputs' => $observedOutputs,
            'linked_findings' => $findings,
        ];
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @param list<array<string, mixed>> $historyEvents
     * @return array<string, mixed>
     */
    private function operatorSurfaceMatrix(array $updates, array $historyEvents): array
    {
        $states = [];
        $stateCounts = [
            'accepted' => 0,
            'completed' => 0,
            'failed' => 0,
            'refused' => 0,
        ];

        foreach ($updates as $update) {
            $state = $this->updateState($update);
            if ($state === null || ! array_key_exists($state, $stateCounts)) {
                continue;
            }

            $stateCounts[$state]++;
            $states[$state] ??= $this->stateEvidence($state, $update, $historyEvents);
        }

        foreach (self::REQUIRED_STATES as $state) {
            $states[$state] ??= [
                'present' => false,
                'required_history_event_types' => self::REQUIRED_HISTORY_TYPES[$state],
            ];
        }

        return [
            'surface' => 'selected_run_update_history',
            'scope' => 'selected_run',
            'required_states' => self::REQUIRED_STATES,
            'state_counts' => $stateCounts,
            'states' => $states,
            'update_count' => count($updates),
            'history_update_event_count' => count($historyEvents),
        ];
    }

    /**
     * @param array<string, mixed> $update
     * @param list<array<string, mixed>> $historyEvents
     * @return array<string, mixed>
     */
    private function stateEvidence(string $state, array $update, array $historyEvents): array
    {
        $detailEventTypes = $this->updateHistoryEventTypes($update);
        $relatedHistoryEvents = $this->relatedHistoryEvents($update, $historyEvents);
        $historyExportTypes = $this->historyEventTypes($relatedHistoryEvents);
        $requiredTypes = self::REQUIRED_HISTORY_TYPES[$state];
        $resultVisible = $state === 'completed' ? $this->hasResultDetails($update) : null;
        $errorVisible = in_array($state, ['failed', 'refused'], true) ? $this->hasErrorDetails($update) : null;

        return [
            'present' => true,
            'update_id' => $this->stringValue($update['id'] ?? $update['update_id'] ?? null),
            'command_id' => $this->stringValue($update['command_id'] ?? $update['workflow_command_id'] ?? null),
            'name' => $this->stringValue($update['name'] ?? $update['update_name'] ?? null),
            'status' => $this->stringValue($update['status'] ?? null),
            'state_label' => $this->stringValue($update['state_label'] ?? null) ?? $state,
            'request_id' => $this->stringValue($update['request_id'] ?? null),
            'correlation_id' => $this->stringValue($update['correlation_id'] ?? null),
            'request_identifiers_visible' => $this->hasRequestIdentifiers($update),
            'payload_visible' => $this->hasPayloadDetails($update),
            'outcome_or_reason_visible' => $this->hasOutcomeOrReason($state, $update),
            'result_visible' => $resultVisible,
            'error_visible' => $errorVisible,
            'history_references_visible' => $this->containsAllTypes($detailEventTypes, $requiredTypes),
            'history_export_references_visible' => $this->containsAllTypes($historyExportTypes, $requiredTypes),
            'required_history_event_types' => $requiredTypes,
            'history_event_types' => $detailEventTypes,
            'history_export_event_types' => $historyExportTypes,
            'history_event_ids' => $this->stringList($update['history_event_ids'] ?? null),
            'history_event_sequences' => $this->intList($update['history_event_sequences'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private function updateRowsFromDetail(array $json): array
    {
        foreach ([
            $json['updates'] ?? null,
            data_get($json, 'observer_state.updates.items'),
            data_get($json, 'data.updates'),
            data_get($json, 'data.observer_state.updates.items'),
        ] as $candidate) {
            $rows = $this->listOfMaps($candidate);
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private function updateRowsFromHistoryExport(array $json): array
    {
        $commandsById = $this->rowsById($json['commands'] ?? data_get($json, 'data.commands'));

        foreach ([
            data_get($json, 'update_diagnostics.items'),
            data_get($json, 'data.update_diagnostics.items'),
            $json['updates'] ?? null,
            data_get($json, 'data.updates'),
        ] as $candidate) {
            $rows = array_map(
                fn (array $row): array => $this->withCommandUpdateFields($row, $commandsById),
                $this->listOfMaps($candidate),
            );

            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $primary
     * @param list<array<string, mixed>> $fallback
     * @return list<array<string, mixed>>
     */
    private function mergeUpdateRows(array $primary, array $fallback): array
    {
        $merged = [];

        foreach ([$fallback, $primary] as $rows) {
            foreach ($rows as $index => $row) {
                $key = $this->updateRowKey($row) ?? 'row:'.$index.':'.count($merged);
                $merged[$key] = array_merge($merged[$key] ?? [], $row);
            }
        }

        return array_values($merged);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function updateRowKey(array $row): ?string
    {
        foreach (['id', 'update_id'] as $field) {
            $value = $this->stringValue($row[$field] ?? null);

            if ($value !== null) {
                return 'update:'.$value;
            }
        }

        foreach (['command_id', 'workflow_command_id'] as $field) {
            $value = $this->stringValue($row[$field] ?? null);

            if ($value !== null) {
                return 'command:'.$value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $update
     * @param array<string, array<string, mixed>> $commandsById
     * @return array<string, mixed>
     */
    private function withCommandUpdateFields(array $update, array $commandsById): array
    {
        $commandId = $this->stringValue($update['command_id'] ?? $update['workflow_command_id'] ?? null);
        $command = $commandId === null ? [] : ($commandsById[$commandId] ?? []);

        foreach ([
            'request_id',
            'correlation_id',
            'request_method',
            'request_path',
            'request_route_name',
            'request_fingerprint',
            'principal_type',
            'principal_id',
            'principal_label',
            'caller_label',
            'auth_status',
            'auth_method',
        ] as $field) {
            if (! $this->hasDetailValue($update[$field] ?? null)) {
                $update[$field] = $this->stringValue($command[$field] ?? null);
            }
        }

        if (! $this->hasDetailValue($update['payload'] ?? null)
            && (($update['arguments_available'] ?? false) === true || array_key_exists('arguments', $update))) {
            $update['payload_available'] = true;
            $update['payload'] = $this->compactDetails([
                'name' => $update['name'] ?? $update['update_name'] ?? null,
                'arguments' => $update['arguments'] ?? null,
            ]);
        }

        if (! $this->hasDetailValue($update['reason'] ?? null)) {
            $update['reason'] = $this->stringValue($update['rejection_reason'] ?? null)
                ?? $this->stringValue($update['failure_message'] ?? null)
                ?? $this->stringValue($update['outcome'] ?? null);
        }

        if (! $this->hasDetailValue($update['error'] ?? null)) {
            $error = $this->compactDetails([
                'failure_id' => $update['failure_id'] ?? null,
                'message' => $update['failure_message'] ?? null,
                'rejection_reason' => $update['rejection_reason'] ?? null,
                'validation_errors' => $update['validation_errors'] ?? null,
                'exception_type' => $update['exception_type'] ?? null,
                'exception_class' => $update['exception_class'] ?? null,
            ]);

            if ($error !== []) {
                $update['error'] = $error;
                $update['error_available'] = true;
            }
        }

        return $update;
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private function historyEventsFromExport(array $json): array
    {
        $events = [];

        foreach ([
            $json['history_events'] ?? null,
            $json['update_history_references'] ?? null,
            $json['timeline'] ?? null,
            data_get($json, 'data.history_events'),
            data_get($json, 'data.update_history_references'),
            data_get($json, 'data.timeline'),
        ] as $candidate) {
            foreach ($this->listOfMaps($candidate) as $event) {
                $type = $this->historyEventType($event);
                if ($type !== null && $this->isUpdateEventType($type)) {
                    $events[$this->historyEventKey($event)] = $event;
                }
            }
        }

        return array_values($events);
    }

    /**
     * @param list<array<string, mixed>> $updates
     * @return list<array<string, mixed>>
     */
    private function compactUpdateEvidence(array $updates): array
    {
        return array_values(array_map(function (array $update): array {
            return $this->compactDetails([
                'id' => $this->stringValue($update['id'] ?? $update['update_id'] ?? null),
                'command_id' => $this->stringValue($update['command_id'] ?? $update['workflow_command_id'] ?? null),
                'request_id' => $this->stringValue($update['request_id'] ?? null),
                'correlation_id' => $this->stringValue($update['correlation_id'] ?? null),
                'name' => $this->stringValue($update['name'] ?? $update['update_name'] ?? null),
                'status' => $this->stringValue($update['status'] ?? null),
                'state' => $this->updateState($update),
                'outcome' => $this->stringValue($update['outcome'] ?? null),
                'reason' => $this->stringValue($update['reason'] ?? null)
                    ?? $this->stringValue($update['rejection_reason'] ?? null)
                    ?? $this->stringValue($update['failure_message'] ?? null),
                'history_event_types' => $this->updateHistoryEventTypes($update),
                'payload_available' => $this->hasPayloadDetails($update),
                'result_available' => $this->hasResultDetails($update),
                'error_available' => $this->hasErrorDetails($update),
            ]);
        }, $updates));
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return list<array<string, mixed>>
     */
    private function compactHistoryEvidence(array $historyEvents): array
    {
        return array_values(array_map(function (array $event): array {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            return $this->compactDetails([
                'id' => $this->stringValue($event['id'] ?? null),
                'sequence' => is_numeric($event['sequence'] ?? null) ? (int) $event['sequence'] : null,
                'type' => $this->historyEventType($event),
                'update_id' => $this->stringValue($event['update_id'] ?? null)
                    ?? $this->stringValue($event['workflow_update_id'] ?? null)
                    ?? $this->stringValue($payload['update_id'] ?? null)
                    ?? $this->stringValue($payload['workflow_update_id'] ?? null),
                'workflow_command_id' => $this->stringValue($event['workflow_command_id'] ?? null)
                    ?? $this->stringValue($event['command_id'] ?? null)
                    ?? $this->stringValue($payload['workflow_command_id'] ?? null),
                'update_name' => $this->stringValue($event['update_name'] ?? null)
                    ?? $this->stringValue($payload['update_name'] ?? null),
            ]);
        }, $historyEvents));
    }

    /**
     * @param array<string, mixed> $update
     * @param list<array<string, mixed>> $historyEvents
     * @return list<array<string, mixed>>
     */
    private function relatedHistoryEvents(array $update, array $historyEvents): array
    {
        $related = [];
        foreach ($historyEvents as $event) {
            if ($this->historyEventMatchesUpdate($event, $update)) {
                $related[] = $event;
            }
        }

        return $related;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $update
     */
    private function historyEventMatchesUpdate(array $event, array $update): bool
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $eventId = $this->stringValue($event['id'] ?? null);
        $updateId = $this->stringValue($update['id'] ?? $update['update_id'] ?? null);
        $commandId = $this->stringValue($update['command_id'] ?? $update['workflow_command_id'] ?? null);
        $updateName = $this->stringValue($update['name'] ?? $update['update_name'] ?? null);

        if ($eventId !== null && in_array($eventId, $this->stringList($update['history_event_ids'] ?? null), true)) {
            return true;
        }

        foreach ([
            $event['update_id'] ?? null,
            $event['workflow_update_id'] ?? null,
            $payload['update_id'] ?? null,
            $payload['workflow_update_id'] ?? null,
        ] as $candidate) {
            if ($updateId !== null && $this->stringValue($candidate) === $updateId) {
                return true;
            }
        }

        foreach ([
            $event['workflow_command_id'] ?? null,
            $event['command_id'] ?? null,
            $payload['workflow_command_id'] ?? null,
            $payload['command_id'] ?? null,
            data_get($payload, 'command.id'),
            data_get($event, 'command.id'),
        ] as $candidate) {
            if ($commandId !== null && $this->stringValue($candidate) === $commandId) {
                return true;
            }
        }

        foreach ([
            $event['update_name'] ?? null,
            $event['name'] ?? null,
            $payload['update_name'] ?? null,
            $payload['name'] ?? null,
            data_get($payload, 'command.target_name'),
        ] as $candidate) {
            if ($updateName !== null && $this->stringValue($candidate) === $updateName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return list<string>
     */
    private function historyEventTypes(array $historyEvents): array
    {
        $types = [];
        foreach ($historyEvents as $event) {
            $type = $this->historyEventType($event);
            if ($type !== null) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * @param array<string, mixed> $update
     * @return list<string>
     */
    private function updateHistoryEventTypes(array $update): array
    {
        $types = $this->stringList($update['history_event_types'] ?? null);
        foreach ($this->listOfMaps($update['history_events'] ?? null) as $event) {
            $type = $this->historyEventType($event);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param array<string, mixed> $event
     */
    private function historyEventType(array $event): ?string
    {
        foreach ([$event['type'] ?? null, $event['event_type'] ?? null] as $value) {
            $type = $this->stringValue($value);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }

    private function isUpdateEventType(string $type): bool
    {
        return in_array($type, ['UpdateAccepted', 'UpdateRejected', 'UpdateApplied', 'UpdateCompleted'], true)
            || str_starts_with($type, 'WorkflowUpdate');
    }

    /**
     * @param list<string> $actual
     * @param list<string> $required
     */
    private function containsAllTypes(array $actual, array $required): bool
    {
        $normalizedActual = array_map([$this, 'normalizeHistoryEventType'], $actual);

        foreach ($required as $type) {
            if (! in_array($this->normalizeHistoryEventType($type), $normalizedActual, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHistoryEventType(string $type): string
    {
        return str_starts_with($type, 'WorkflowUpdate')
            ? 'Update'.substr($type, strlen('WorkflowUpdate'))
            : $type;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function updateState(array $update): ?string
    {
        $state = $this->stringValue($update['state_label'] ?? null)
            ?? $this->stringValue($update['state'] ?? null)
            ?? $this->stringValue($update['status'] ?? null);

        if ($state === 'rejected') {
            return 'refused';
        }

        return in_array($state, self::REQUIRED_STATES, true) ? $state : null;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function hasRequestIdentifiers(array $update): bool
    {
        $identifiers = is_array($update['request_identifiers'] ?? null) ? $update['request_identifiers'] : [];
        $requestId = $this->stringValue($update['request_id'] ?? null)
            ?? $this->stringValue($identifiers['request_id'] ?? null);
        $updateId = $this->stringValue($update['id'] ?? $update['update_id'] ?? null)
            ?? $this->stringValue($identifiers['update_id'] ?? null);
        $commandId = $this->stringValue($update['command_id'] ?? $update['workflow_command_id'] ?? null)
            ?? $this->stringValue($identifiers['command_id'] ?? null);

        return $requestId !== null
            && (
                $updateId !== null
                || $commandId !== null
            );
    }

    /**
     * @param array<string, mixed> $update
     */
    private function hasPayloadDetails(array $update): bool
    {
        if (($update['payload_available'] ?? false) === true
            && $this->hasVisiblePayloadValue($update['payload'] ?? null)) {
            return true;
        }

        if (($update['arguments_available'] ?? false) === true && array_key_exists('arguments', $update)) {
            return $this->hasVisiblePayloadValue($update['arguments']);
        }

        return $this->hasVisiblePayloadValue($update['payload'] ?? null)
            || $this->hasVisiblePayloadValue($update['arguments'] ?? null);
    }

    /**
     * @param array<string, mixed> $update
     */
    private function hasResultDetails(array $update): bool
    {
        if (($update['result_available'] ?? false) === true && array_key_exists('result', $update)) {
            return $this->hasVisiblePayloadValue($update['result']);
        }

        return $this->hasVisiblePayloadValue($update['result'] ?? null);
    }

    /**
     * @param array<string, mixed> $update
     */
    private function hasErrorDetails(array $update): bool
    {
        if (($update['error_available'] ?? false) === true && $this->hasDetailValue($update['error'] ?? null)) {
            return true;
        }

        return $this->hasDetailValue($update['error'] ?? null)
            || $this->stringValue($update['reason'] ?? null) !== null
            || $this->stringValue($update['failure_message'] ?? null) !== null
            || $this->stringValue($update['failure_id'] ?? null) !== null
            || $this->stringValue($update['rejection_reason'] ?? null) !== null
            || $this->stringValue($update['exception_type'] ?? null) !== null
            || $this->stringValue($update['exception_class'] ?? null) !== null
            || $this->hasDetailValue($update['validation_errors'] ?? null);
    }

    /**
     * @param array<string, mixed> $update
     */
    private function hasOutcomeOrReason(string $state, array $update): bool
    {
        if ($state === 'accepted') {
            return $this->stringValue($update['status'] ?? null) !== null;
        }

        return $this->stringValue($update['outcome'] ?? null) !== null
            || $this->stringValue($update['reason'] ?? null) !== null
            || $this->stringValue($update['failure_message'] ?? null) !== null
            || $this->stringValue($update['rejection_reason'] ?? null) !== null;
    }

    private function hasVisiblePayloadValue(mixed $value): bool
    {
        if (! $this->hasDetailValue($value)) {
            return false;
        }

        if (is_string($value)) {
            return ! $this->isLikelyEncodedPayloadBlob($value);
        }

        if (is_array($value)
            && ($value['decode_status'] ?? null) === 'unavailable'
            && isset($value['sha256'])) {
            return false;
        }

        return true;
    }

    private function isLikelyEncodedPayloadBlob(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(?:a|O|s|i|d|b|C):\d+[:;]/', $trimmed) === 1 || $trimmed === 'N;') {
            return true;
        }

        if (in_array($trimmed[0], ['{', '[', '"'], true)) {
            json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return true;
            }
        }

        if (str_starts_with($trimmed, 'base64:')) {
            return true;
        }

        if (strlen($trimmed) >= 16 && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $trimmed) === 1) {
            $decoded = base64_decode($trimmed, true);

            return is_string($decoded) && $decoded !== '' && ($decoded[0] === "\x00" || $decoded[0] === "\x01");
        }

        return false;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function selectedRunIdentityFinding(
        ?array $capture,
        array $json,
        string $instanceId,
        string $runId,
        array $paths,
    ): ?array {
        $actual = [
            'workflow_instance_id' => $this->firstStringData($json, [
                'observer_state.selected_run.workflow_instance_id',
                'observer_state.selected_run.instance_id',
                'workflow_instance_id',
                'instance_id',
                'workflow_id',
            ]),
            'workflow_run_id' => $this->firstStringData($json, [
                'observer_state.selected_run.workflow_run_id',
                'observer_state.selected_run.run_id',
                'workflow_run_id',
                'run_id',
                'selected_run_id',
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
            'waterline_selected_run_update_identity_mismatch',
            'waterline_observer_state_identity_mismatch',
            'waterline',
            'Waterline selected-run detail did not identify the same workflow instance and run as the workflow-updates evidence.',
            'Selected-run detail records the public workflow_instance_id and workflow_run_id inspected by the workflow-updates runner.',
            [
                'expected_identity' => $expected,
                'observed_identity' => $actual,
                'request' => [
                    'method' => 'GET',
                    'path' => $paths['selected_run_detail'],
                ],
                'response' => $this->responseEvidence($capture),
            ],
        );
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
                    'Waterline workflow-updates conformance inputs do not prove a published artifact tuple.',
                    'The conformance shard receives explicit published version and source proof for server, CLI, workflow PHP, Python SDK, and Waterline artifacts.',
                    $observedOutputs,
                    'published_artifact_install_only',
                ),
            ],
        ];
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
            if (is_string($scenarioId) && $scenarioId !== '') {
                $results[$scenarioId] = $scenario;
            }
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

        if (preg_match('/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/', $normalized) === 1) {
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

        if ($normalized === 'self.version' || preg_match($localVersionPattern, $normalized) === 1) {
            return 'local_or_source_version';
        }

        if (preg_match($devVersionPattern, $normalized) === 1
            || preg_match('/^(main|master|trunk|v\d+)$/', $normalized) === 1) {
            return 'dev_or_branch_version';
        }

        return null;
    }

    private static function isLocalArtifactSource(string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return preg_match('/(^|_)(dev|editable|local|path|repo|source|workspace|checkout)(_|$)/', $normalized) === 1;
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

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function suiteVersion(): ?int
    {
        return defined(PlatformConformanceSuite::class.'::VERSION')
            ? PlatformConformanceSuite::VERSION
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read JSON file [{$path}].");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create output directory [{$directory}].");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to write output file [{$path}].");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function providedCapture(string $kind, string $capturedAt): ?array
    {
        $explicit = match ($kind) {
            'selected_run_detail' => $this->optionString('selected-run-detail-capture'),
            'selected_run_history_export' => $this->optionString('selected-run-history-capture')
                ?? $this->optionString('selected-run-history-export-capture'),
            default => null,
        };

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
    private function captureFromFile(string $path, string $kind, string $capturedAt): ?array
    {
        $decoded = $this->readJsonFile($path);
        $candidate = $this->captureCandidate($decoded, $kind);
        if ($candidate === null) {
            return null;
        }

        $capture = $this->normalizeCapture($candidate, $kind, $capturedAt);
        $capture['capture_source'] = 'provided_waterline_api_capture';
        $capture['capture_input'] = $path;

        return $capture;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>|null
     */
    private function captureCandidate(array $decoded, string $kind): ?array
    {
        foreach ($this->captureAliases($kind) as $alias) {
            if (is_array($decoded[$alias] ?? null)) {
                return $decoded[$alias];
            }
        }

        if ($this->isList($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item) && $this->captureLooksLikeKind($item, $kind)) {
                    return $item;
                }
            }

            return null;
        }

        return $this->captureLooksLikeKind($decoded, $kind) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function captureAliases(string $kind): array
    {
        return $kind === 'selected_run_detail'
            ? ['selected_run_detail', 'selectedRunDetail', 'detail', 'run_detail']
            : ['selected_run_history_export', 'selectedRunHistoryExport', 'selected_run_history', 'history_export', 'history'];
    }

    /**
     * @param array<string, mixed> $capture
     */
    private function captureLooksLikeKind(array $capture, string $kind): bool
    {
        $path = $this->stringValue($capture['path'] ?? $capture['request_path'] ?? data_get($capture, 'request.path'));
        if ($path !== null) {
            if ($kind === 'selected_run_detail' && preg_match('#/api/instances/[^/]+/runs/[^/]+$#', $path) === 1) {
                return true;
            }

            if ($kind === 'selected_run_history_export' && str_contains($path, '/history-export')) {
                return true;
            }
        }

        $json = is_array($capture['json'] ?? null) ? $capture['json'] : $capture;

        return $kind === 'selected_run_detail'
            ? is_array($json) && (isset($json['updates']) || data_get($json, 'observer_state.updates.items') !== null)
            : is_array($json) && (isset($json['history_events']) || isset($json['timeline']));
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>
     */
    private function normalizeCapture(array $capture, string $kind, string $capturedAt): array
    {
        $json = $capture['json'] ?? $capture['body_json'] ?? $capture['response_json'] ?? data_get($capture, 'response.json');
        $wrapped = array_key_exists('status', $capture)
            || array_key_exists('method', $capture)
            || array_key_exists('json', $capture)
            || array_key_exists('body_json', $capture)
            || array_key_exists('response_json', $capture);

        if (! is_array($json)) {
            $json = $wrapped ? [] : $capture;
        }

        $method = strtoupper($this->stringValue($capture['method'] ?? data_get($capture, 'request.method')) ?? 'GET');
        $path = $this->stringValue($capture['path'] ?? data_get($capture, 'request.path') ?? $capture['request_path'] ?? null)
            ?? $this->pathFromJson($json, $kind)
            ?? '';
        $requestPath = $this->stringValue($capture['request_path'] ?? data_get($capture, 'request.path') ?? null)
            ?? $path;
        $status = is_numeric($capture['status'] ?? null) ? (int) $capture['status'] : 200;
        $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'method' => $method,
            'path' => $path,
            'request_path' => $requestPath,
            'status' => $status,
            'request_json' => is_array($capture['request_json'] ?? null) ? $capture['request_json'] : null,
            'captured_at' => $this->stringValue($capture['captured_at'] ?? null) ?? $capturedAt,
            'body_sha256' => is_string($capture['body_sha256'] ?? null)
                ? $capture['body_sha256']
                : hash('sha256', $body),
            'json' => $json,
        ];
    }

    /**
     * @param array<string, mixed> $json
     */
    private function pathFromJson(array $json, string $kind): ?string
    {
        if ($kind === 'selected_run_history_export') {
            $detailPath = $this->pathFromJson($json, 'selected_run_detail');

            return $detailPath === null ? null : $detailPath.'/history-export';
        }

        $path = $this->firstStringData($json, [
            'observer_state.paths.selected_run_detail',
            'paths.selected_run_detail',
        ]);

        if ($path !== null) {
            return $path;
        }

        $instanceId = $this->instanceIdFromJson($json);
        $runId = $this->runIdFromJson($json);

        return $instanceId !== null && $runId !== null
            ? $this->observerPaths($instanceId, $runId)['selected_run_detail']
            : null;
    }

    private function captureWaterlineApi(HttpKernel $kernel, string $method, string $apiPath, string $capturedAt): array
    {
        $publicPath = $this->publicWaterlinePath($apiPath);
        $request = Request::create($publicPath, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ]);
        $response = null;
        $body = '';
        $json = [];

        try {
            $response = $kernel->handle($request);
            $body = (string) $response->getContent();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $json = is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            $json = [];
        } catch (Throwable $exception) {
            $json = [
                'message' => 'Waterline API request failed during workflow update diagnostics capture.',
                'error' => 'waterline_api_capture_exception',
                'exception_class' => $exception::class,
            ];
            $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } finally {
            if ($response !== null) {
                try {
                    $kernel->terminate($request, $response);
                } catch (Throwable) {
                    // Termination hooks are not part of the observer payload; keep the capture reportable.
                }
            }
        }

        return [
            'method' => strtoupper($method),
            'path' => $publicPath,
            'request_path' => $publicPath,
            'status' => $response === null ? 500 : $response->getStatusCode(),
            'request_json' => null,
            'captured_at' => $capturedAt,
            'capture_source' => 'published_waterline_artifact_http_route',
            'body_sha256' => hash('sha256', $body),
            'json' => $json,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function observerPaths(string $instanceId, string $runId): array
    {
        $instancePath = rawurlencode($instanceId);
        $runPath = rawurlencode($runId);
        $selectedRunApi = sprintf('/api/instances/%s/runs/%s', $instancePath, $runPath);
        $historyExportApi = $selectedRunApi.'/history-export';
        $updateTemplateApi = $selectedRunApi.'/updates/{update}';
        $updateLookupTemplateApi = $selectedRunApi.'/updates/{updateId}';

        return [
            'selected_run_detail_api' => $selectedRunApi,
            'selected_run_history_export_api' => $historyExportApi,
            'selected_run_update_template_api' => $updateTemplateApi,
            'selected_run_update_lookup_template_api' => $updateLookupTemplateApi,
            'selected_run_detail' => $this->publicWaterlinePath($selectedRunApi),
            'selected_run_history_export' => $this->publicWaterlinePath($historyExportApi),
            'selected_run_update_template' => $this->publicWaterlinePath($updateTemplateApi),
            'selected_run_update_lookup_template' => $this->publicWaterlinePath($updateLookupTemplateApi),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function observerPathTemplates(): array
    {
        $selectedRunApi = '/api/instances/{instance}/runs/{run}';
        $historyExportApi = $selectedRunApi.'/history-export';
        $updateTemplateApi = $selectedRunApi.'/updates/{update}';
        $updateLookupTemplateApi = $selectedRunApi.'/updates/{updateId}';

        return [
            'selected_run_detail_api' => $selectedRunApi,
            'selected_run_history_export_api' => $historyExportApi,
            'selected_run_update_template_api' => $updateTemplateApi,
            'selected_run_update_lookup_template_api' => $updateLookupTemplateApi,
            'selected_run_detail' => $this->publicWaterlinePath($selectedRunApi),
            'selected_run_history_export' => $this->publicWaterlinePath($historyExportApi),
            'selected_run_update_template' => $this->publicWaterlinePath($updateTemplateApi),
            'selected_run_update_lookup_template' => $this->publicWaterlinePath($updateLookupTemplateApi),
        ];
    }

    private function publicWaterlinePath(string $apiPath): string
    {
        $waterlinePath = trim((string) config('waterline.path', 'waterline'), '/');

        return ($waterlinePath === '' ? '' : '/'.$waterlinePath).$apiPath;
    }

    /**
     * @param array<string, mixed>|null $capture
     */
    private function instanceIdFromCapture(?array $capture): ?string
    {
        return is_array($capture) ? $this->instanceIdFromJson($this->captureJson($capture)) : null;
    }

    /**
     * @param array<string, mixed>|null $capture
     */
    private function runIdFromCapture(?array $capture): ?string
    {
        return is_array($capture) ? $this->runIdFromJson($this->captureJson($capture)) : null;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function instanceIdFromJson(array $json): ?string
    {
        return $this->firstStringData($json, [
            'observer_state.selected_run.workflow_instance_id',
            'observer_state.selected_run.instance_id',
            'workflow.instance_id',
            'workflow.workflow_instance_id',
            'workflow_instance_id',
            'instance_id',
            'workflow_id',
        ]);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function runIdFromJson(array $json): ?string
    {
        return $this->firstStringData($json, [
            'observer_state.selected_run.workflow_run_id',
            'observer_state.selected_run.run_id',
            'workflow.run_id',
            'workflow.workflow_run_id',
            'workflow_run_id',
            'run_id',
            'selected_run_id',
        ]);
    }

    /**
     * @param array<string, mixed>|null $capture
     * @return array<string, mixed>
     */
    private function captureJson(?array $capture): array
    {
        return is_array($capture) && is_array($capture['json'] ?? null) ? $capture['json'] : [];
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     */
    private function stringEvidence(array $value, array $keys): ?string
    {
        foreach ($keys as $key) {
            $found = $this->recursiveString($value, $key);
            if ($found !== null) {
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
     * @param array<string, mixed>|null $value
     * @param list<string> $paths
     */
    private function firstStringData(?array $value, array $paths): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach ($paths as $path) {
            $found = data_get($value, $path);
            if (is_string($found) && $found !== '') {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param mixed $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsById(mixed $rows): array
    {
        $indexed = [];

        foreach ($this->listOfMaps($rows) as $row) {
            $id = $this->stringValue($row['id'] ?? null);

            if ($id !== null) {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOfMaps(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $item): int => (int) $item,
            array_filter($value, static fn (mixed $item): bool => is_int($item) || is_numeric($item)),
        ));
    }

    private function historyEventKey(array $event): string
    {
        return $this->stringValue($event['id'] ?? null)
            ?? implode(':', array_filter([
                $this->historyEventType($event) ?? 'event',
                (string) ($event['sequence'] ?? ''),
                $this->stringValue($event['update_id'] ?? null)
                    ?? $this->stringValue($event['workflow_update_id'] ?? null)
                    ?? $this->stringValue(data_get($event, 'payload.update_id') ?? null)
                    ?? $this->stringValue(data_get($event, 'payload.workflow_update_id') ?? null)
                    ?? '',
            ]));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function hasDetailValue(mixed $value): bool
    {
        return ! ($value === null || $value === '' || $value === []);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function compactDetails(array $values): array
    {
        return array_filter($values, static function (mixed $value): bool {
            return ! ($value === null || $value === '' || $value === []);
        });
    }

    /**
     * @param array<string, mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>|null
     */
    private function captureRequestFinding(
        string $id,
        string $expectedMethod,
        string $expectedPath,
        array $capture,
        string $observed,
        string $expected,
    ): ?array {
        $actualMethod = strtoupper((string) ($capture['method'] ?? ''));
        $actualPath = (string) ($capture['path'] ?? '');
        $actualRequestPath = (string) ($capture['request_path'] ?? '');

        if ($actualMethod === $expectedMethod
            && $actualPath === $expectedPath
            && $actualRequestPath === $expectedPath) {
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
                'observed_method' => $actualMethod,
                'observed_path' => $actualPath,
                'observed_request_path' => $actualRequestPath,
            ],
            $capture,
        );
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
            'next_acceptance_criterion' => 'rerun workflow-updates conformance and record matching Waterline selected-run detail and history-export captures',
        ];
    }

    private function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
