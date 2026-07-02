<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;

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

    /**
     * @return array<string, mixed>
     */
    private function publishedArtifactOptions(): array
    {
        return [
            '--artifact-version' => [
                'server=0.2.543',
                'cli=0.1.84',
                'sdk-python=0.4.93',
                'workflow=2.0.0-alpha.242',
                'waterline=2.0.0-alpha.112',
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
