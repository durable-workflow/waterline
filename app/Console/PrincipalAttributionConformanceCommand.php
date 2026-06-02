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
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\RunSummarySortKey;

class PrincipalAttributionConformanceCommand extends Command
{
    protected $signature = 'waterline:principal-attribution-conformance
        {--run-id= : Stable suffix for generated fixture IDs}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--keep-fixtures : Keep generated Waterline fixture rows after the run}
        {--json : Emit only machine-readable output when --output is used}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Waterline principal-attribution operator visibility conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.principal-attribution.waterline-operator-shard';

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
        $runId = $this->runId();
        $fixtureIds = $this->fixtureIds($runId);

        $originalConfig = [
            'waterline.engine_source' => config('waterline.engine_source'),
            'waterline.namespace' => config('waterline.namespace'),
            'waterline.allow_unauthenticated' => config('waterline.allow_unauthenticated'),
        ];

        try {
            config()->set('waterline.engine_source', 'v2');
            config()->set('waterline.allow_unauthenticated', true);
            config()->set('waterline.namespace', 'principal-attribution');

            if (! (bool) $this->option('keep-fixtures')) {
                $this->cleanupFixtures($fixtureIds);
            }

            $fixtures = $this->createFixtures($fixtureIds);
            $evidence = $this->inspectPrincipalVisibility($kernel, $fixtures);
            $evidence['fixture_ids'] = $fixtureIds;
            $evidence['operator_surface_matrix'] = $this->operatorSurfaceMatrix($evidence);

            $passed = $this->waterlineEvidencePassed($evidence);
            $waterlineScenario = [
                'scenario_id' => 'waterline_operator_visibility',
                'status' => $passed ? 'pass' : 'fail',
                'surface' => 'selected-run detail API commands and timeline',
                'output_sample' => substr(json_encode(
                    $evidence['api_captures']['selected_run_detail']['json'] ?? [],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ), 0, 4000),
                'principal_visible' => $passed,
                'observed_outputs' => $evidence,
                'linked_findings' => $passed ? [] : [
                    $this->finding(
                        'waterline_operator_visibility',
                        'Waterline selected-run principal visibility did not prove command and timeline principal fields.',
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
                'surface' => 'selected-run detail API commands and timeline',
                'output_sample' => null,
                'principal_visible' => false,
                'observed_outputs' => $evidence,
                'linked_findings' => [
                    $this->finding(
                        'waterline_operator_visibility',
                        'Waterline principal-attribution operator visibility shard failed before evidence completed.',
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
        );
        $hasFailures = self::hasScenarioFailures($scenarioResults);
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'waterline-principal-attribution-operator-shard',
            'outcome' => $hasFailures ? 'fail' : 'non_passing',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'runtime_matrix' => [
                'claimed_targets' => ['waterline_contract_surface'],
                'covered_scenarios' => self::WATERLINE_SHARD_SCENARIOS,
                'observer_paths' => [
                    'waterline-selected-run-detail',
                    'waterline-selected-run-timeline',
                    'waterline-command-intake',
                ],
            ],
            'scenario_results' => array_values($scenarioResults),
            'waterline_principal_visibility' => $evidence,
            'api_captures' => is_array($evidence['api_captures'] ?? null) ? $evidence['api_captures'] : [],
            'findings' => $this->findings($scenarioResults),
            'finding_links' => $this->findingLinks($scenarioResults),
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    private function runId(): string
    {
        $configured = $this->stringOption('run-id');
        $value = $configured !== null ? $configured : strtolower((string) Str::ulid());
        $value = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '');
        $value = trim($value, '-');

        return $value !== '' ? Str::limit($value, 32, '') : strtolower((string) Str::ulid());
    }

    /**
     * @return array{workflow_instance_ids: list<string>, workflow_run_ids: list<string>, workflow_command_ids: list<string>}
     */
    private function fixtureIds(string $runId): array
    {
        $suffix = strtolower(preg_replace('/[^a-z0-9]+/', '', $runId) ?? '');
        $suffix = $suffix !== '' ? substr($suffix, 0, 10) : 'principal';

        return [
            'workflow_instance_ids' => ['waterline-principal-'.$suffix],
            'workflow_run_ids' => [$this->boundedFixtureId('wl-pr', 'principal-attribution-workflow-run', $runId)],
            'workflow_command_ids' => [$this->boundedFixtureId('wl-pc', 'principal-attribution-workflow-command', $runId)],
        ];
    }

    private function boundedFixtureId(string $prefix, string $domain, string $runId): string
    {
        $prefix = strtolower(preg_replace('/[^a-z0-9-]+/', '-', $prefix) ?? 'wl');
        $prefix = trim($prefix, '-');
        $prefix = $prefix !== '' ? Str::limit($prefix, 6, '') : 'wl';
        $fingerprint = hash('sha256', sprintf('%s|%s', $domain, $runId));

        return $prefix.'-'.substr($fingerprint, 0, 25 - strlen($prefix));
    }

    /**
     * @param array{workflow_instance_ids: list<string>, workflow_run_ids: list<string>, workflow_command_ids: list<string>} $fixtureIds
     * @return array<string, mixed>
     */
    private function createFixtures(array $fixtureIds): array
    {
        $startedAt = CarbonImmutable::parse('2026-01-01 12:00:00', 'UTC');
        $principal = [
            'type' => 'user',
            'id' => 'waterline-user:42',
            'label' => 'Taylor Operator',
        ];
        $namespace = 'principal-attribution';
        $instanceId = $fixtureIds['workflow_instance_ids'][0];
        $runId = $fixtureIds['workflow_run_ids'][0];
        $commandId = $fixtureIds['workflow_command_ids'][0];

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.waterline-principal-attribution-conformance',
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
            'workflow_type' => 'workflow.waterline-principal-attribution-conformance',
            'business_key' => $instanceId,
            'status' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 0,
            'started_at' => $startedAt,
            'closed_at' => $startedAt->addMinute(),
            'last_progress_at' => $startedAt->addMinute(),
            'created_at' => $startedAt,
            'updated_at' => $startedAt->addMinute(),
        ];

        if (Schema::hasColumn('workflow_runs', 'search_attributes')) {
            $runAttributes['search_attributes'] = ['principal_conformance' => true];
        }

        $run = WorkflowRun::query()->create($runAttributes);
        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::query()->create($this->summaryAttributes($run, $startedAt));

        $command = WorkflowCommand::record($instance, $run, [
            'id' => $commandId,
            'command_type' => 'archive',
            'target_scope' => 'instance',
            'source' => 'waterline',
            'status' => 'accepted',
            'outcome' => 'archived',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize(['reason' => 'principal attribution visibility']),
            'context' => [
                'caller' => [
                    'type' => 'waterline',
                    'label' => 'Waterline UI',
                ],
                'principal' => $principal,
                'auth' => [
                    'status' => 'authorized',
                    'method' => 'waterline',
                ],
                'request' => [
                    'method' => 'POST',
                    'path' => '/waterline/api/instances/'.$instance->id.'/archive',
                    'route_name' => 'waterline.instances.archive',
                ],
            ],
            'accepted_at' => $startedAt->addSeconds(10),
            'applied_at' => $startedAt->addSeconds(10),
            'created_at' => $startedAt->addSeconds(10),
            'updated_at' => $startedAt->addSeconds(10),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ArchiveRequested, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'archive',
            'outcome' => 'archived',
            'reason' => 'principal attribution visibility',
        ], null, $command);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowArchived, [
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'archive_command_id' => $command->id,
            'reason' => 'principal attribution visibility',
        ], null, $command);

        return [
            'instance' => $instance,
            'run' => $run,
            'command' => $command,
            'expected_principal' => $principal,
            'namespace' => $namespace,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryAttributes(WorkflowRun $run, CarbonImmutable $startedAt): array
    {
        $createdAt = $startedAt->subMinute();
        $attributes = [
            'id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'business_key' => $run->business_key,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $run->namespace,
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'history_event_count' => 3,
            'history_size_bytes' => 256,
            'continue_as_new_recommended' => false,
            'created_at' => $createdAt,
            'updated_at' => $run->closed_at,
        ];

        if (Schema::hasColumn('workflow_run_summaries', 'sort_timestamp')) {
            $attributes['sort_timestamp'] = $run->closed_at;
        }

        if (Schema::hasColumn('workflow_run_summaries', 'sort_key')) {
            $attributes['sort_key'] = RunSummarySortKey::key(
                $attributes['sort_timestamp'] ?? $run->closed_at,
                $createdAt,
                $run->started_at,
                $run->id,
            );
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $fixtures
     * @return array<string, mixed>
     */
    private function inspectPrincipalVisibility(HttpKernel $kernel, array $fixtures): array
    {
        $run = $fixtures['run'];
        $expectedPrincipal = $fixtures['expected_principal'];
        $detail = $this->apiGet(
            $kernel,
            '/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id,
        );
        $commands = $this->rows($detail['json']['commands'] ?? []);
        $timeline = $this->rows($detail['json']['timeline'] ?? []);
        $command = $this->firstMatching($commands, 'id', $fixtures['command']->id);
        $timelineEvent = $this->firstTimelineCommand($timeline, $fixtures['command']->id);

        return [
            'expected_principal' => $expectedPrincipal,
            'api_captures' => [
                'selected_run_detail' => $this->responseCapture($detail),
            ],
            'selected_run_detail' => [
                'path' => '/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id,
                'status' => $detail['status'],
                'run_id' => $detail['json']['run_id'] ?? null,
                'namespace' => $detail['json']['namespace'] ?? null,
                'operator_scope' => $this->operatorScope($detail['json']),
                'commands_count' => count($commands),
                'timeline_count' => count($timeline),
            ],
            'command_principal' => [
                'principal_type' => $command['principal_type'] ?? null,
                'principal_id' => $command['principal_id'] ?? null,
                'principal_label' => $command['principal_label'] ?? null,
                'context_principal' => data_get($command, 'context.principal'),
                'auth_status' => $command['auth_status'] ?? null,
                'auth_method' => $command['auth_method'] ?? null,
                'caller_label' => $command['caller_label'] ?? null,
                'request_route_name' => $command['request_route_name'] ?? null,
            ],
            'timeline_principal' => [
                'event_type' => $timelineEvent['type'] ?? null,
                'principal_type' => data_get($timelineEvent, 'command.principal_type'),
                'principal_id' => data_get($timelineEvent, 'command.principal_id'),
                'principal_label' => data_get($timelineEvent, 'command.principal_label'),
                'auth_status' => data_get($timelineEvent, 'command.auth_status'),
                'auth_method' => data_get($timelineEvent, 'command.auth_method'),
                'request_route_name' => data_get($timelineEvent, 'command.request_route_name'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, bool>
     */
    private function operatorSurfaceMatrix(array $evidence): array
    {
        $expected = $evidence['expected_principal'] ?? [];

        return [
            'selected_run_detail_status' => data_get($evidence, 'selected_run_detail.status') === 200,
            'command_principal_fields' => $this->principalMatches($evidence['command_principal'] ?? [], $expected),
            'command_context_principal' => data_get($evidence, 'command_principal.context_principal') === $expected,
            'timeline_command_principal_fields' => $this->principalMatches($evidence['timeline_principal'] ?? [], $expected),
            'waterline_auth_fields_visible' => data_get($evidence, 'command_principal.auth_status') === 'authorized'
                && data_get($evidence, 'command_principal.auth_method') === 'waterline'
                && data_get($evidence, 'timeline_principal.auth_status') === 'authorized'
                && data_get($evidence, 'timeline_principal.auth_method') === 'waterline',
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function waterlineEvidencePassed(array $evidence): bool
    {
        $matrix = $evidence['operator_surface_matrix'] ?? [];

        return is_array($matrix)
            && $matrix !== []
            && ! in_array(false, $matrix, true)
            && $this->captureStatusPass(data_get($evidence, 'api_captures.selected_run_detail'), 200);
    }

    /**
     * @param array<string, mixed> $observed
     * @param array<string, mixed> $expected
     */
    private function principalMatches(array $observed, array $expected): bool
    {
        return ($observed['principal_type'] ?? null) === ($expected['type'] ?? null)
            && ($observed['principal_id'] ?? null) === ($expected['id'] ?? null)
            && ($observed['principal_label'] ?? null) === ($expected['label'] ?? null);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function firstMatching(array $rows, string $field, string $value): ?array
    {
        foreach ($rows as $row) {
            if (($row[$field] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $timeline
     * @return array<string, mixed>|null
     */
    private function firstTimelineCommand(array $timeline, string $commandId): ?array
    {
        foreach ($timeline as $event) {
            if (data_get($event, 'command.id') === $commandId) {
                return $event;
            }
        }

        return null;
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
     * @param array{workflow_instance_ids: list<string>, workflow_run_ids: list<string>, workflow_command_ids: list<string>} $fixtures
     */
    private function cleanupFixtures(array $fixtures): void
    {
        $runIds = array_values(array_filter($fixtures['workflow_run_ids'] ?? [], 'is_string'));
        $instanceIds = array_values(array_filter($fixtures['workflow_instance_ids'] ?? [], 'is_string'));
        $commandIds = array_values(array_filter($fixtures['workflow_command_ids'] ?? [], 'is_string'));

        if ($instanceIds !== []) {
            $runIds = array_values(array_unique(array_merge(
                $runIds,
                WorkflowRun::query()
                    ->whereIn('workflow_instance_id', $instanceIds)
                    ->pluck('id')
                    ->all(),
            )));
        }

        if ($runIds !== []) {
            $commandIds = array_values(array_unique(array_merge(
                $commandIds,
                WorkflowCommand::query()
                    ->whereIn('workflow_run_id', $runIds)
                    ->pluck('id')
                    ->all(),
            )));
        }

        if ($instanceIds !== []) {
            $commandIds = array_values(array_unique(array_merge(
                $commandIds,
                WorkflowCommand::query()
                    ->whereIn('workflow_instance_id', $instanceIds)
                    ->pluck('id')
                    ->all(),
            )));
        }

        if ($runIds !== []) {
            WorkflowHistoryEvent::query()->whereIn('workflow_run_id', $runIds)->delete();
        }

        if ($commandIds !== []) {
            WorkflowCommand::query()->whereIn('id', $commandIds)->delete();
        }

        if ($runIds !== []) {
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
                    'Waterline principal-attribution conformance inputs do not prove a published artifact tuple.',
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
     * @return array{id: string, scenario_id: string, owner: string, title: string, owning_surface: string, artifact_versions: array<string, string>, observed_behavior: string, expected_behavior: string, next_acceptance_criterion: string}
     */
    private function finding(string $scenarioId, string $title, string $owner): array
    {
        return [
            'id' => 'waterline-principal-'.$scenarioId,
            'scenario_id' => $scenarioId,
            'owner' => $owner,
            'title' => $title,
            'owning_surface' => $owner,
            'artifact_versions' => $this->artifactVersions(),
            'observed_behavior' => $title,
            'expected_behavior' => 'Waterline selected-run detail and timeline surfaces expose command principal fields from the workflow history command context.',
            'next_acceptance_criterion' => 'Publish a Waterline artifact whose principal-attribution shard records principal_visible=true for selected-run command and timeline output.',
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
                $this->info('Waterline principal-attribution conformance report written to '.$output.'.');
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
