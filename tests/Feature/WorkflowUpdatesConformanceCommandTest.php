<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Waterline;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowUpdate;

class WorkflowUpdatesConformanceCommandTest extends TestCase
{
    public function testItEmitsSelectedRunUpdateDiagnosticsForProvidedCaptures(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'waterline-wu-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-detail-');
        $historyCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-history-');
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($historyCapture);

        file_put_contents(
            $detailCapture,
            json_encode($this->selectedRunDetailCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $historyCapture,
            json_encode($this->selectedRunHistoryCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->artisan('waterline:workflow-updates-conformance', [
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-history-capture' => $historyCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(0);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $artifactScenario = $scenarios['published_artifact_install_only'];
            $scenario = $scenarios['operator_diagnostics_surfaces'];
            $matrix = $scenario['observed_outputs']['operator_surface_matrix'];

            $this->assertSame('durable-workflow.v2.workflow-updates.waterline-operator-shard', $result['schema']);
            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('pass', $artifactScenario['status']);
            $this->assertSame('pass', $scenario['status']);
            $this->assertSame(1, $matrix['state_counts']['accepted']);
            $this->assertSame(1, $matrix['state_counts']['completed']);
            $this->assertSame(1, $matrix['state_counts']['failed']);
            $this->assertSame(1, $matrix['state_counts']['refused']);
            $this->assertTrue($matrix['states']['accepted']['request_identifiers_visible']);
            $this->assertTrue($matrix['states']['completed']['result_visible']);
            $this->assertTrue($matrix['states']['failed']['error_visible']);
            $this->assertTrue($matrix['states']['refused']['error_visible']);
            $this->assertTrue($matrix['states']['refused']['history_export_references_visible']);
            $this->assertSame(
                '/waterline/api/instances/update-instance/runs/update-run-001/history-export',
                $scenario['observed_outputs']['api_paths']['selected_run_history_export'],
            );
            $this->assertSame(
                'provided_waterline_api_capture',
                $scenario['observed_outputs']['api_captures']['selected_run_detail']['capture_source'],
            );
            $this->assertSame('packagist_package', $result['artifact_sources']['waterline']);
            $this->assertSame([], $result['findings']);
        } finally {
            $this->unlinkTemp($output);
            $this->unlinkTemp($detailCapture);
            $this->unlinkTemp($historyCapture);
        }
    }

    public function testItSelfCapturesSelectedRunUpdateDiagnosticsInPublishedHostMode(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'waterline-wu-output-');
        $this->assertIsString($output);

        config()->set('waterline.engine_source', 'auto');
        config()->set('waterline.allow_unauthenticated', false);
        Waterline::auth(fn (): bool => false);
        $fixture = $this->seedSelectedRunUpdateDiagnostics();

        $this->app->bind(OperatorObservabilityRepository::class, fn (): OperatorObservabilityRepository => new class implements OperatorObservabilityRepository {
            public function runDetail(WorkflowRun $run, ?int $timelineLimit = null): array
            {
                throw new \RuntimeException('selected-run projection unavailable');
            }

            public function listItem(WorkflowRunSummary $summary): array
            {
                return [];
            }

            public function runHistoryExport(
                WorkflowRun $run,
                ?\Carbon\CarbonInterface $exportedAt = null,
                HistoryExportRedactor|callable|null $redactor = null,
            ): array {
                throw new \RuntimeException('history export projection unavailable');
            }

            public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }

            public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }
        });

        try {
            $this->artisan('waterline:workflow-updates-conformance', [
                '--output' => $output,
                '--run-id' => 'operator-diagnostics-'.$fixture['run']->id,
                '--instance-id' => $fixture['instance']->id,
                '--workflow-run-id' => $fixture['run']->id,
            ] + $this->publishedArtifactOptions())->assertExitCode(0);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['operator_diagnostics_surfaces'];
            $captures = $scenario['observed_outputs']['api_captures'];
            $matrix = $scenario['observed_outputs']['operator_surface_matrix'];

            $this->assertSame('pass', $scenario['status']);
            $this->assertSame(200, $captures['selected_run_detail']['status']);
            $this->assertSame(200, $captures['selected_run_history_export']['status']);
            $this->assertSame('published_waterline_artifact_http_route', $captures['selected_run_detail']['capture_source']);
            $this->assertSame(
                '/waterline/api/instances/'.$fixture['instance']->id.'/runs/'.$fixture['run']->id,
                $captures['selected_run_detail']['path'],
            );
            $this->assertSame(1, $matrix['state_counts']['accepted']);
            $this->assertSame(1, $matrix['state_counts']['completed']);
            $this->assertSame(1, $matrix['state_counts']['failed']);
            $this->assertSame(1, $matrix['state_counts']['refused']);
            $this->assertTrue($matrix['states']['accepted']['request_identifiers_visible']);
            $this->assertTrue($matrix['states']['completed']['result_visible']);
            $this->assertTrue($matrix['states']['failed']['error_visible']);
            $this->assertTrue($matrix['states']['refused']['history_export_references_visible']);
            $this->assertSame('auto', config('waterline.engine_source'));
            $this->assertFalse(config('waterline.allow_unauthenticated'));
        } finally {
            Waterline::auth(fn (): bool => true);
            $this->unlinkTemp($output);
        }
    }

    public function testItFailsClosedWhenARequiredUpdatePathLacksErrorDiagnostics(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'waterline-wu-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-detail-');
        $historyCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-history-');
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($historyCapture);

        $detail = $this->selectedRunDetailCapture();
        unset($detail['json']['updates'][3]['error'], $detail['json']['updates'][3]['rejection_reason'], $detail['json']['updates'][3]['reason']);
        $detail['json']['updates'][3]['error_available'] = false;

        file_put_contents(
            $detailCapture,
            json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $historyCapture,
            json_encode($this->selectedRunHistoryCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->artisan('waterline:workflow-updates-conformance', [
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-history-capture' => $historyCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(1);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['operator_diagnostics_surfaces'];
            $findings = array_column($scenario['linked_findings'], null, 'id');

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('fail', $scenario['status']);
            $this->assertArrayHasKey('waterline_selected_run_update_refused_diagnostics_incomplete', $findings);
            $this->assertContains(
                'error_visible',
                $findings['waterline_selected_run_update_refused_diagnostics_incomplete']['evidence']['missing_fields'],
            );
        } finally {
            $this->unlinkTemp($output);
            $this->unlinkTemp($detailCapture);
            $this->unlinkTemp($historyCapture);
        }
    }

    public function testItDoesNotCountRawSerializedPayloadBlobsAsVisibleDiagnostics(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'waterline-wu-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-detail-');
        $historyCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-history-');
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($historyCapture);

        $detail = $this->selectedRunDetailCapture();
        $detail['json']['updates'][0]['payload'] = json_encode(
            ['name' => 'queue-approval', 'arguments' => ['order-1']],
            JSON_THROW_ON_ERROR,
        );
        unset($detail['json']['updates'][0]['arguments']);

        file_put_contents(
            $detailCapture,
            json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $historyCapture,
            json_encode($this->selectedRunHistoryCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->artisan('waterline:workflow-updates-conformance', [
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-history-capture' => $historyCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(1);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['operator_diagnostics_surfaces'];
            $findings = array_column($scenario['linked_findings'], null, 'id');

            $this->assertSame('fail', $scenario['status']);
            $this->assertArrayHasKey('waterline_selected_run_update_accepted_diagnostics_incomplete', $findings);
            $this->assertContains(
                'payload_visible',
                $findings['waterline_selected_run_update_accepted_diagnostics_incomplete']['evidence']['missing_fields'],
            );
        } finally {
            $this->unlinkTemp($output);
            $this->unlinkTemp($detailCapture);
            $this->unlinkTemp($historyCapture);
        }
    }

    public function testItAcceptsPublishedCompatibleHistoryExportUpdateRows(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'waterline-wu-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-detail-');
        $historyCapture = tempnam(sys_get_temp_dir(), 'waterline-wu-history-');
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($historyCapture);

        $detail = $this->selectedRunDetailCapture();
        foreach ($detail['json']['updates'] as &$update) {
            unset(
                $update['payload_available'],
                $update['payload'],
                $update['result_available'],
                $update['result'],
                $update['error_available'],
                $update['error'],
                $update['request_id'],
                $update['correlation_id'],
            );
        }
        unset($update);

        $history = $this->selectedRunHistoryCapture();
        $history['json']['commands'] = $this->historyExportCommands();
        $history['json']['updates'] = $this->historyExportUpdates();
        $history['json']['update_diagnostics'] = [
            'surface' => 'selected_run_history_export',
            'scope' => 'selected_run',
            'items' => $this->historyExportUpdateDiagnosticItems(),
        ];

        file_put_contents(
            $detailCapture,
            json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $historyCapture,
            json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->artisan('waterline:workflow-updates-conformance', [
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-history-capture' => $historyCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(0);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $matrix = $scenarios['operator_diagnostics_surfaces']['observed_outputs']['operator_surface_matrix'];

            $this->assertSame('pass', $scenarios['operator_diagnostics_surfaces']['status']);
            $this->assertTrue($matrix['states']['completed']['request_identifiers_visible']);
            $this->assertTrue($matrix['states']['completed']['payload_visible']);
            $this->assertTrue($matrix['states']['completed']['result_visible']);
            $this->assertTrue($matrix['states']['failed']['error_visible']);
            $this->assertTrue($matrix['states']['refused']['error_visible']);
            $this->assertSame(['UpdateAccepted', 'UpdateCompleted'], $matrix['states']['completed']['history_export_event_types']);
        } finally {
            $this->unlinkTemp($output);
            $this->unlinkTemp($detailCapture);
            $this->unlinkTemp($historyCapture);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedArtifactOptions(): array
    {
        return [
            '--artifact-version' => [
                'server=0.2.544',
                'cli=0.1.84',
                'sdk-python=0.4.93',
                'workflow=2.0.0-alpha.242',
                'waterline=2.0.0-alpha.113',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
        ];
    }

    /**
     * @return array{instance: WorkflowInstance, run: WorkflowRun}
     */
    private function seedSelectedRunUpdateDiagnostics(): array
    {
        $instance = WorkflowInstance::create([
            'id' => 'update-conformance-instance',
            'workflow_class' => 'App\\Workflows\\WorkflowUpdatesConformanceWorkflow',
            'workflow_type' => 'workflow-updates.probe',
            'run_count' => 1,
            'started_at' => now()->subMinutes(5),
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\WorkflowUpdatesConformanceWorkflow',
            'workflow_type' => 'workflow-updates.probe',
            'status' => 'waiting',
            'arguments' => Serializer::serialize(['operator-diagnostics']),
            'connection' => 'redis',
            'queue' => 'workflow-updates-operator-queue',
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $accepted = $this->seedWorkflowUpdate($instance, $run, 1, 'accepted', 'approve', [true, 'cli-accepted']);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $accepted['command']->id,
            'update_id' => $accepted['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'arguments' => Serializer::serialize([true, 'cli-accepted']),
        ], null, $accepted['command']);

        $completed = $this->seedWorkflowUpdate($instance, $run, 2, 'completed', 'approve', [true, 'cli-completed'], [
            'result' => ['approved' => true, 'source' => 'operator-diagnostics-cli-waterline'],
            'outcome' => 'update_completed',
            'closed_at' => now()->subSeconds(30),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $completed['command']->id,
            'update_id' => $completed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'arguments' => Serializer::serialize([true, 'cli-completed']),
        ], null, $completed['command']);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $completed['command']->id,
            'update_id' => $completed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'result' => Serializer::serialize(['approved' => true, 'source' => 'operator-diagnostics-cli-waterline']),
        ], null, $completed['command']);

        $failed = $this->seedWorkflowUpdate($instance, $run, 3, 'failed', 'fail_update', ['cli failure'], [
            'outcome' => 'update_failed',
            'closed_at' => now()->subSeconds(20),
        ]);
        $failure = WorkflowFailure::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_command',
            'source_id' => $failed['command']->id,
            'propagation_kind' => 'update',
            'handled' => false,
            'exception_class' => 'DurableWorkflow\\Conformance\\WorkflowUpdateOperatorDiagnosticsFailure',
            'message' => 'workflow update operator diagnostics failure',
            'file' => __FILE__,
            'line' => 1,
            'trace_preview' => '',
        ]);
        $failed['update']->update(['failure_id' => $failure->id]);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $failed['command']->id,
            'update_id' => $failed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'fail_update',
            'arguments' => Serializer::serialize(['cli failure']),
        ], null, $failed['command']);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $failed['command']->id,
            'update_id' => $failed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'fail_update',
            'failure_id' => $failure->id,
            'exception_class' => 'DurableWorkflow\\Conformance\\WorkflowUpdateOperatorDiagnosticsFailure',
            'message' => 'workflow update operator diagnostics failure',
        ], null, $failed['command']);

        $refused = $this->seedWorkflowUpdate($instance, $run, 4, 'rejected', 'missing_update', [], [
            'outcome' => 'rejected_unknown_update',
            'rejection_reason' => 'unknown_update',
            'validation_errors' => ['update' => ['The requested workflow update is not declared.']],
            'closed_at' => now()->subSeconds(10),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateRejected, [
            'workflow_command_id' => $refused['command']->id,
            'update_id' => $refused['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'missing_update',
            'arguments' => Serializer::serialize([]),
            'rejection_reason' => 'unknown_update',
            'validation_errors' => ['update' => ['The requested workflow update is not declared.']],
        ], null, $refused['command']);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\WorkflowUpdatesConformanceWorkflow',
            'workflow_type' => 'workflow-updates.probe',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'workflow-updates-operator-queue',
            'history_event_count' => 6,
            'history_size_bytes' => 2048,
            'started_at' => $run->started_at,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        return ['instance' => $instance, 'run' => $run];
    }

    /**
     * @return array{command: WorkflowCommand, update: WorkflowUpdate}
     */
    private function seedWorkflowUpdate(
        WorkflowInstance $instance,
        WorkflowRun $run,
        int $sequence,
        string $status,
        string $name,
        array $arguments,
        array $overrides = [],
    ): array {
        $outcome = $overrides['outcome'] ?? ($status === 'completed' ? 'update_completed' : null);
        $rejectionReason = $overrides['rejection_reason'] ?? null;
        $acceptedAt = now()->subSeconds(60 - $sequence);
        $closedAt = $overrides['closed_at'] ?? null;
        $commandStatus = $status === 'rejected' ? 'rejected' : 'accepted';

        $command = WorkflowCommand::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => $sequence,
            'command_type' => 'update',
            'target_scope' => 'instance',
            'source' => 'api',
            'context' => [
                'caller' => [
                    'type' => 'operator',
                    'label' => 'Operator API',
                ],
                'principal' => [
                    'type' => 'service-account',
                    'id' => 'workflow-updates-operator',
                    'label' => 'Workflow Updates Operator',
                ],
                'auth' => [
                    'status' => 'verified',
                    'method' => 'bearer',
                ],
                'request' => [
                    'method' => 'POST',
                    'path' => '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/updates/'.$name,
                    'route_name' => 'waterline.instances.runs.update',
                    'fingerprint' => 'sha256:update-'.$sequence,
                    'request_id' => 'cli-'.$status.'-'.$sequence,
                    'correlation_id' => 'corr-update-'.$sequence,
                ],
            ],
            'status' => $commandStatus,
            'outcome' => $outcome,
            'rejection_reason' => $rejectionReason,
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => $name,
                'arguments' => $arguments,
            ]),
            'accepted_at' => $commandStatus === 'accepted' ? $acceptedAt : null,
            'rejected_at' => $commandStatus === 'rejected' ? $closedAt : null,
            'applied_at' => $status === 'completed' || $status === 'failed' ? $closedAt : null,
            'created_at' => $acceptedAt,
            'updated_at' => $closedAt ?? $acceptedAt,
        ]);

        $update = WorkflowUpdate::create([
            'id' => (string) Str::ulid(),
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'requested_workflow_run_id' => null,
            'resolved_workflow_run_id' => $run->id,
            'update_name' => $name,
            'status' => $status,
            'outcome' => $outcome,
            'rejection_reason' => $rejectionReason,
            'validation_errors' => $overrides['validation_errors'] ?? [],
            'command_sequence' => $sequence,
            'workflow_sequence' => $status === 'accepted' || $status === 'rejected' ? null : $sequence - 1,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize($arguments),
            'result' => array_key_exists('result', $overrides)
                ? Serializer::serialize($overrides['result'])
                : null,
            'accepted_at' => $commandStatus === 'accepted' ? $acceptedAt : null,
            'applied_at' => $status === 'completed' || $status === 'failed' ? $closedAt : null,
            'rejected_at' => $commandStatus === 'rejected' ? $closedAt : null,
            'closed_at' => $closedAt,
            'created_at' => $acceptedAt,
            'updated_at' => $closedAt ?? $acceptedAt,
        ]);

        return ['command' => $command, 'update' => $update];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRunDetailCapture(): array
    {
        return [
            'method' => 'GET',
            'path' => '/waterline/api/instances/update-instance/runs/update-run-001',
            'request_path' => '/waterline/api/instances/update-instance/runs/update-run-001',
            'status' => 200,
            'captured_at' => '2026-07-02T13:40:00Z',
            'json' => [
                'instance_id' => 'update-instance',
                'run_id' => 'update-run-001',
                'status' => 'waiting',
                'status_bucket' => 'running',
                'update_diagnostics' => [
                    'surface' => 'selected_run_detail',
                    'scope' => 'selected_run',
                    'state_counts' => [
                        'accepted' => 1,
                        'completed' => 1,
                        'failed' => 1,
                        'refused' => 1,
                    ],
                ],
                'observer_state' => [
                    'schema' => 'waterline.observer-state',
                    'version' => 1,
                    'paths' => [
                        'selected_run_detail' => '/waterline/api/instances/update-instance/runs/update-run-001',
                        'selected_run_history_export' => '/waterline/api/instances/update-instance/runs/update-run-001/history-export',
                        'selected_run_update_template' => '/waterline/api/instances/update-instance/runs/update-run-001/updates/{update}',
                        'selected_run_update_lookup_template' => '/waterline/api/instances/update-instance/runs/update-run-001/updates/{updateId}',
                    ],
                    'selected_run' => [
                        'instance_id' => 'update-instance',
                        'run_id' => 'update-run-001',
                        'status' => 'waiting',
                        'status_bucket' => 'running',
                    ],
                ],
                'updates' => [
                    [
                        'id' => 'update-accepted',
                        'command_id' => 'command-accepted',
                        'request_id' => 'req-accepted',
                        'correlation_id' => 'corr-accepted',
                        'name' => 'queue-approval',
                        'status' => 'accepted',
                        'state_label' => 'accepted',
                        'payload_available' => true,
                        'payload' => ['name' => 'queue-approval', 'arguments' => ['order-1']],
                        'history_event_ids' => ['history-accepted'],
                        'history_event_sequences' => [10],
                        'history_event_types' => ['UpdateAccepted'],
                    ],
                    [
                        'id' => 'update-completed',
                        'command_id' => 'command-completed',
                        'request_id' => 'req-completed',
                        'correlation_id' => 'corr-completed',
                        'name' => 'approve-order',
                        'status' => 'completed',
                        'state_label' => 'completed',
                        'outcome' => 'update_completed',
                        'payload_available' => true,
                        'payload' => ['name' => 'approve-order', 'arguments' => ['order-2']],
                        'result_available' => true,
                        'result' => ['approved' => true],
                        'history_event_ids' => ['history-completed-accepted', 'history-completed'],
                        'history_event_sequences' => [20, 21],
                        'history_event_types' => ['UpdateAccepted', 'UpdateCompleted'],
                    ],
                    [
                        'id' => 'update-failed',
                        'command_id' => 'command-failed',
                        'request_id' => 'req-failed',
                        'correlation_id' => 'corr-failed',
                        'name' => 'ship-order',
                        'status' => 'failed',
                        'state_label' => 'failed',
                        'outcome' => 'update_failed',
                        'reason' => 'inventory unavailable',
                        'payload_available' => true,
                        'payload' => ['name' => 'ship-order', 'arguments' => ['order-3']],
                        'error_available' => true,
                        'error' => ['failure_id' => 'failure-update', 'message' => 'inventory unavailable'],
                        'history_event_ids' => ['history-failed-accepted', 'history-failed'],
                        'history_event_sequences' => [30, 31],
                        'history_event_types' => ['UpdateAccepted', 'UpdateCompleted'],
                    ],
                    [
                        'id' => 'update-refused',
                        'command_id' => 'command-refused',
                        'request_id' => 'req-refused',
                        'correlation_id' => 'corr-refused',
                        'name' => 'cancel-order',
                        'status' => 'rejected',
                        'state_label' => 'refused',
                        'outcome' => 'rejected_invalid_arguments',
                        'reason' => 'invalid_operator_payload',
                        'rejection_reason' => 'invalid_operator_payload',
                        'payload_available' => true,
                        'payload' => ['name' => 'cancel-order', 'arguments' => ['order-4']],
                        'error_available' => true,
                        'error' => [
                            'rejection_reason' => 'invalid_operator_payload',
                            'validation_errors' => ['reason' => ['The reason field is required.']],
                        ],
                        'history_event_ids' => ['history-refused'],
                        'history_event_sequences' => [40],
                        'history_event_types' => ['UpdateRejected'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRunHistoryCapture(): array
    {
        return [
            'method' => 'GET',
            'path' => '/waterline/api/instances/update-instance/runs/update-run-001/history-export',
            'request_path' => '/waterline/api/instances/update-instance/runs/update-run-001/history-export',
            'status' => 200,
            'captured_at' => '2026-07-02T13:40:01Z',
            'json' => [
                'workflow' => [
                    'instance_id' => 'update-instance',
                    'run_id' => 'update-run-001',
                    'status' => 'waiting',
                ],
                'history_events' => [
                    $this->historyEvent('history-accepted', 10, 'UpdateAccepted', 'update-accepted', 'command-accepted', 'queue-approval'),
                    $this->historyEvent('history-completed-accepted', 20, 'UpdateAccepted', 'update-completed', 'command-completed', 'approve-order'),
                    $this->historyEvent('history-completed', 21, 'UpdateCompleted', 'update-completed', 'command-completed', 'approve-order'),
                    $this->historyEvent('history-failed-accepted', 30, 'UpdateAccepted', 'update-failed', 'command-failed', 'ship-order'),
                    $this->historyEvent('history-failed', 31, 'UpdateCompleted', 'update-failed', 'command-failed', 'ship-order', [
                        'failure_id' => 'failure-update',
                        'message' => 'inventory unavailable',
                    ]),
                    $this->historyEvent('history-refused', 40, 'UpdateRejected', 'update-refused', 'command-refused', 'cancel-order', [
                        'rejection_reason' => 'invalid_operator_payload',
                    ]),
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyExportCommands(): array
    {
        return [
            $this->historyExportCommand('command-accepted', 'req-accepted', 'corr-accepted', 'queue-approval'),
            $this->historyExportCommand('command-completed', 'req-completed', 'corr-completed', 'approve-order'),
            $this->historyExportCommand('command-failed', 'req-failed', 'corr-failed', 'ship-order'),
            $this->historyExportCommand('command-refused', 'req-refused', 'corr-refused', 'cancel-order'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyExportCommand(string $id, string $requestId, string $correlationId, string $name): array
    {
        return [
            'id' => $id,
            'type' => 'update',
            'target_name' => $name,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'request_method' => 'POST',
            'request_path' => '/waterline/api/instances/update-instance/runs/update-run-001/updates/'.$name,
            'request_route_name' => 'waterline.instances.runs.update',
            'request_fingerprint' => 'sha256:'.$id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyExportUpdates(): array
    {
        return array_values(array_map(function (array $update): array {
            $raw = $update;
            unset(
                $raw['arguments_available'],
                $raw['error'],
                $raw['error_available'],
                $raw['failure_message'],
                $raw['history_event_ids'],
                $raw['history_event_sequences'],
                $raw['history_event_types'],
                $raw['payload'],
                $raw['payload_available'],
                $raw['reason'],
                $raw['request_identifiers'],
                $raw['result_available'],
                $raw['state_label']
            );

            $raw['payload_codec'] = config('workflows.serializer');

            if (array_key_exists('arguments', $raw)) {
                $raw['arguments'] = Serializer::serialize($raw['arguments']);
            }

            if (array_key_exists('result', $raw)) {
                $raw['result'] = Serializer::serialize($raw['result']);
            }

            return $raw;
        }, $this->historyExportUpdateDiagnosticItems()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyExportUpdateDiagnosticItems(): array
    {
        return [
            [
                'id' => 'update-accepted',
                'command_id' => 'command-accepted',
                'name' => 'queue-approval',
                'status' => 'accepted',
                'state_label' => 'accepted',
                'arguments_available' => true,
                'arguments' => ['order-1'],
                'payload_available' => true,
                'payload' => ['name' => 'queue-approval', 'arguments' => ['order-1']],
                'history_event_ids' => ['history-accepted'],
                'history_event_sequences' => [10],
                'history_event_types' => ['UpdateAccepted'],
            ],
            [
                'id' => 'update-completed',
                'command_id' => 'command-completed',
                'name' => 'approve-order',
                'status' => 'completed',
                'state_label' => 'completed',
                'outcome' => 'update_completed',
                'arguments_available' => true,
                'arguments' => ['order-2'],
                'payload_available' => true,
                'payload' => ['name' => 'approve-order', 'arguments' => ['order-2']],
                'result_available' => true,
                'result' => ['approved' => true],
                'history_event_ids' => ['history-completed-accepted', 'history-completed'],
                'history_event_sequences' => [20, 21],
                'history_event_types' => ['UpdateAccepted', 'UpdateCompleted'],
            ],
            [
                'id' => 'update-failed',
                'command_id' => 'command-failed',
                'name' => 'ship-order',
                'status' => 'failed',
                'state_label' => 'failed',
                'outcome' => 'update_failed',
                'reason' => 'inventory unavailable',
                'failure_id' => 'failure-update',
                'failure_message' => 'inventory unavailable',
                'arguments_available' => true,
                'arguments' => ['order-3'],
                'payload_available' => true,
                'payload' => ['name' => 'ship-order', 'arguments' => ['order-3']],
                'error_available' => true,
                'error' => ['failure_id' => 'failure-update', 'message' => 'inventory unavailable'],
                'history_event_ids' => ['history-failed-accepted', 'history-failed'],
                'history_event_sequences' => [30, 31],
                'history_event_types' => ['UpdateAccepted', 'UpdateCompleted'],
            ],
            [
                'id' => 'update-refused',
                'command_id' => 'command-refused',
                'name' => 'cancel-order',
                'status' => 'rejected',
                'state_label' => 'refused',
                'outcome' => 'rejected_invalid_arguments',
                'reason' => 'invalid_operator_payload',
                'rejection_reason' => 'invalid_operator_payload',
                'validation_errors' => ['reason' => ['The reason field is required.']],
                'arguments_available' => true,
                'arguments' => ['order-4'],
                'payload_available' => true,
                'payload' => ['name' => 'cancel-order', 'arguments' => ['order-4']],
                'error_available' => true,
                'error' => [
                    'rejection_reason' => 'invalid_operator_payload',
                    'validation_errors' => ['reason' => ['The reason field is required.']],
                ],
                'history_event_ids' => ['history-refused'],
                'history_event_sequences' => [40],
                'history_event_types' => ['UpdateRejected'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyEvent(
        string $id,
        int $sequence,
        string $type,
        string $updateId,
        string $commandId,
        string $name,
        array $extra = [],
    ): array {
        return [
            'id' => $id,
            'sequence' => $sequence,
            'type' => $type,
            'payload' => array_merge([
                'update_id' => $updateId,
                'workflow_command_id' => $commandId,
                'update_name' => $name,
            ], $extra),
            'recorded_at' => '2026-07-02T13:39:00Z',
        ];
    }

    private function unlinkTemp(mixed $path): void
    {
        if (is_string($path) && file_exists($path)) {
            unlink($path);
        }
    }
}
