<?php

namespace Waterline\Tests\Feature;

use Waterline\Support\ObserverStateEnvelope;
use Waterline\Tests\TestCase;

class SignalsQueriesConformanceCommandTest extends TestCase
{
    public function testItEmitsObserverComparisonForPublicSignalQueryEvidence(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'waterline-sq-input-');
        $output = tempnam(sys_get_temp_dir(), 'waterline-sq-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-detail-');
        $queryCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-query-');
        $this->assertIsString($input);
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($queryCapture);

        $evidence = [
            'scenario_results' => [
                'python_worker_cli_and_sdk_baseline' => [
                    'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'cli_signal_and_query' => true,
                        'sdk_python_signal_and_query' => true,
                    ],
                ],
                'ordered_signal_delivery' => [
                    'scenario_id' => 'ordered_signal_delivery',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                        'queried_total' => 55,
                        'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                    ],
                ],
            ],
            'workflow_instance_id' => 'counter-instance',
            'workflow_run_id' => 'counter-run-001',
            'run_status' => 'waiting',
        ];

        file_put_contents($input, json_encode($evidence, JSON_THROW_ON_ERROR));
        file_put_contents(
            $detailCapture,
            json_encode($this->selectedRunDetailCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $queryCapture,
            json_encode($this->selectedRunQueryCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->artisan('waterline:signals-queries-conformance', [
                '--input' => $input,
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-query-capture' => $queryCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(0);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['waterline_operator_visibility'];
            $artifactScenario = $scenarios['published_artifact_install_only'];
            $observed = $scenario['observed_outputs'];

            $this->assertSame('durable-workflow.v2.signal-query-runtime.waterline-observer-result', $result['schema']);
            $this->assertSame('non_passing', $result['outcome']);
            $this->assertSame('pass', $artifactScenario['status']);
            $this->assertSame('pass', $scenario['status']);
            $this->assertSame('counter-run-001', $observed['observer_state']['selected_run']['run_id']);
            $this->assertSame('waiting', $observed['observer_state']['selected_run']['status']);
            $this->assertSame(10, $observed['observer_state']['signals']['accepted_count']);
            $this->assertSame(['increment'], $observed['observer_state']['signals']['names']);
            $this->assertSame('/waterline/api/instances/counter-instance/runs/counter-run-001/queries/{query}', $observed['observer_state']['paths']['selected_run_query_template']);
            $this->assertSame(55, $observed['comparison']['expected_counter']);
            $this->assertSame(55, $observed['comparison']['observer_derived_state']['counter']);
            $this->assertTrue($observed['comparison']['counter_state_matches_public_clients']);
            $this->assertSame(55, $observed['comparison']['waterline_query_action']['result']);
            $this->assertSame(
                ObserverStateEnvelope::QUERY_STATE_LIMITATION,
                $observed['typed_product_findings'][0]['reason'],
            );
            $this->assertSame(
                '/waterline/api/instances/counter-instance/runs/counter-run-001',
                $observed['api_captures']['selected_run_detail']['path'],
            );
            $this->assertSame(
                'provided_waterline_api_capture',
                $observed['api_captures']['selected_run_query_action']['capture_source'],
            );
            $this->assertSame('packagist_package', $result['artifact_sources']['waterline']);
        } finally {
            if (is_string($input) && file_exists($input)) {
                unlink($input);
            }
            if (is_string($output) && file_exists($output)) {
                unlink($output);
            }
            if (is_string($detailCapture) && file_exists($detailCapture)) {
                unlink($detailCapture);
            }
            if (is_string($queryCapture) && file_exists($queryCapture)) {
                unlink($queryCapture);
            }
        }
    }

    public function testItRequiresPublishedArtifactSourceProof(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'waterline-sq-input-');
        $output = tempnam(sys_get_temp_dir(), 'waterline-sq-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-detail-');
        $queryCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-query-');
        $this->assertIsString($input);
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($queryCapture);

        file_put_contents($input, json_encode($this->publicEvidence(), JSON_THROW_ON_ERROR));
        file_put_contents(
            $detailCapture,
            json_encode($this->selectedRunDetailCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $queryCapture,
            json_encode($this->selectedRunQueryCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        try {
            $this->artisan('waterline:signals-queries-conformance', [
                '--input' => $input,
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-query-capture' => $queryCapture,
                '--artifact-version' => $this->publishedArtifactVersions(),
            ])->assertExitCode(1);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $artifactScenario = $scenarios['published_artifact_install_only'];

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame([], $result['artifact_sources']);
            $this->assertSame('fail', $artifactScenario['status']);
            $this->assertSame([], $artifactScenario['observed_outputs']['missing_artifact_versions']);
            $this->assertSame(
                ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'],
                $artifactScenario['observed_outputs']['missing_artifact_sources'],
            );
            $this->assertSame('pass', $scenarios['waterline_operator_visibility']['status']);
        } finally {
            if (is_string($input) && file_exists($input)) {
                unlink($input);
            }
            if (is_string($output) && file_exists($output)) {
                unlink($output);
            }
            if (is_string($detailCapture) && file_exists($detailCapture)) {
                unlink($detailCapture);
            }
            if (is_string($queryCapture) && file_exists($queryCapture)) {
                unlink($queryCapture);
            }
        }
    }

    public function testItFailsClosedWhenSelectedRunDetailDoesNotExposeObserverState(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'waterline-sq-input-');
        $output = tempnam(sys_get_temp_dir(), 'waterline-sq-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-detail-');
        $queryCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-query-');
        $this->assertIsString($input);
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($queryCapture);

        file_put_contents($input, json_encode([
            'scenario_results' => [
                'ordered_signal_delivery' => [
                    'scenario_id' => 'ordered_signal_delivery',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                        'queried_total' => 55,
                        'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                    ],
                ],
            ],
            'workflow_instance_id' => 'counter-instance',
            'workflow_run_id' => 'counter-run-001',
            'run_status' => 'waiting',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($detailCapture, json_encode([
            'method' => 'GET',
            'path' => '/waterline/api/instances/counter-instance/runs/counter-run-001',
            'status' => 200,
            'json' => [
                'instance_id' => 'counter-instance',
                'run_id' => 'counter-run-001',
                'status' => 'waiting',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        file_put_contents($queryCapture, json_encode($this->selectedRunQueryCapture(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $this->artisan('waterline:signals-queries-conformance', [
                '--input' => $input,
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-query-capture' => $queryCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(1);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['waterline_operator_visibility'];

            $this->assertSame('fail', $result['outcome']);
            $this->assertSame('fail', $scenario['status']);
            $this->assertContains(
                'waterline_observer_state_missing',
                array_column($scenario['linked_findings'], 'type'),
            );
            $this->assertSame(
                '/waterline/api/instances/counter-instance/runs/counter-run-001',
                $scenario['observed_outputs']['api_captures']['selected_run_detail']['path'],
            );
        } finally {
            if (is_string($input) && file_exists($input)) {
                unlink($input);
            }
            if (is_string($output) && file_exists($output)) {
                unlink($output);
            }
            if (is_string($detailCapture) && file_exists($detailCapture)) {
                unlink($detailCapture);
            }
            if (is_string($queryCapture) && file_exists($queryCapture)) {
                unlink($queryCapture);
            }
        }
    }

    public function testItFailsClosedWhenProvidedCapturesUseTheWrongObserverEndpoints(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'waterline-sq-input-');
        $output = tempnam(sys_get_temp_dir(), 'waterline-sq-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-detail-');
        $queryCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-query-');
        $this->assertIsString($input);
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($queryCapture);

        file_put_contents($input, json_encode($this->publicEvidence(), JSON_THROW_ON_ERROR));

        $detail = $this->selectedRunDetailCapture();
        $detail['method'] = 'POST';
        $detail['path'] = '/waterline/api/instances/counter-instance/runs/other-run';
        $detail['request_path'] = '/waterline/api/instances/counter-instance/runs/other-run';
        file_put_contents($detailCapture, json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $query = $this->selectedRunQueryCapture();
        $query['method'] = 'GET';
        $query['path'] = '/waterline/api/instances/counter-instance/runs/other-run/queries/current';
        $query['request_path'] = '/waterline/api/instances/counter-instance/runs/other-run/queries/current';
        file_put_contents($queryCapture, json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $this->artisan('waterline:signals-queries-conformance', [
                '--input' => $input,
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-query-capture' => $queryCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(1);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['waterline_operator_visibility'];
            $findingIds = array_column($scenario['linked_findings'], 'id');

            $this->assertSame('fail', $scenario['status']);
            $this->assertContains('waterline_selected_run_detail_capture_request_mismatch', $findingIds);
            $this->assertContains('waterline_selected_run_query_capture_request_mismatch', $findingIds);
            $this->assertSame(
                '/waterline/api/instances/counter-instance/runs/other-run',
                $scenario['observed_outputs']['api_captures']['selected_run_detail']['path'],
            );
        } finally {
            if (is_string($input) && file_exists($input)) {
                unlink($input);
            }
            if (is_string($output) && file_exists($output)) {
                unlink($output);
            }
            if (is_string($detailCapture) && file_exists($detailCapture)) {
                unlink($detailCapture);
            }
            if (is_string($queryCapture) && file_exists($queryCapture)) {
                unlink($queryCapture);
            }
        }
    }

    public function testItFailsClosedWhenProvidedCapturesIdentifyADifferentRunOrQuery(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'waterline-sq-input-');
        $output = tempnam(sys_get_temp_dir(), 'waterline-sq-output-');
        $detailCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-detail-');
        $queryCapture = tempnam(sys_get_temp_dir(), 'waterline-sq-query-');
        $this->assertIsString($input);
        $this->assertIsString($output);
        $this->assertIsString($detailCapture);
        $this->assertIsString($queryCapture);

        file_put_contents($input, json_encode($this->publicEvidence(), JSON_THROW_ON_ERROR));

        $detail = $this->selectedRunDetailCapture();
        $detail['json']['observer_state']['selected_run']['instance_id'] = 'other-instance';
        $detail['json']['observer_state']['selected_run']['run_id'] = 'other-run';
        file_put_contents($detailCapture, json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $query = $this->selectedRunQueryCapture();
        $query['json']['workflow_id'] = 'other-instance';
        $query['json']['run_id'] = 'other-run';
        $query['json']['query_name'] = 'other-query';
        file_put_contents($queryCapture, json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $this->artisan('waterline:signals-queries-conformance', [
                '--input' => $input,
                '--output' => $output,
                '--selected-run-detail-capture' => $detailCapture,
                '--selected-run-query-capture' => $queryCapture,
            ] + $this->publishedArtifactOptions())->assertExitCode(1);

            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            $scenarios = array_column($result['scenario_results'], null, 'scenario_id');
            $scenario = $scenarios['waterline_operator_visibility'];
            $findingTypes = array_column($scenario['linked_findings'], 'type');

            $this->assertSame('fail', $scenario['status']);
            $this->assertContains('waterline_observer_state_identity_mismatch', $findingTypes);
            $this->assertContains('waterline_observer_query_identity_mismatch', $findingTypes);
            $this->assertTrue($scenario['observed_outputs']['comparison']['counter_state_matches_public_clients']);
            $this->assertSame(
                'other-query',
                $scenario['linked_findings'][1]['evidence']['observed_identity']['query'],
            );
        } finally {
            if (is_string($input) && file_exists($input)) {
                unlink($input);
            }
            if (is_string($output) && file_exists($output)) {
                unlink($output);
            }
            if (is_string($detailCapture) && file_exists($detailCapture)) {
                unlink($detailCapture);
            }
            if (is_string($queryCapture) && file_exists($queryCapture)) {
                unlink($queryCapture);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function publicEvidence(): array
    {
        return [
            'scenario_results' => [
                'python_worker_cli_and_sdk_baseline' => [
                    'scenario_id' => 'python_worker_cli_and_sdk_baseline',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'cli_signal_and_query' => true,
                        'sdk_python_signal_and_query' => true,
                    ],
                ],
                'ordered_signal_delivery' => [
                    'scenario_id' => 'ordered_signal_delivery',
                    'status' => 'pass',
                    'observed_outputs' => [
                        'rapid_increment_inputs' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                        'queried_total' => 55,
                        'history_signal_order' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
                    ],
                ],
            ],
            'workflow_instance_id' => 'counter-instance',
            'workflow_run_id' => 'counter-run-001',
            'run_status' => 'waiting',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedArtifactOptions(): array
    {
        return [
            '--artifact-version' => $this->publishedArtifactVersions(),
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
     * @return list<string>
     */
    private function publishedArtifactVersions(): array
    {
        return [
            'server=0.2.250',
            'cli=0.1.75',
            'sdk-python=0.4.84',
            'workflow=2.0.0-alpha.191',
            'waterline=2.0.0-alpha.77',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRunDetailCapture(): array
    {
        $signals = [];
        foreach (range(1, 10) as $value) {
            $signals[] = [
                'id' => 'signal-'.$value,
                'command_id' => 'command-'.$value,
                'command_sequence' => $value,
                'workflow_sequence' => $value,
                'name' => 'increment',
                'status' => 'received',
                'outcome' => 'signal_received',
                'arguments_available' => true,
                'arguments' => [$value],
                'received_at' => '2026-06-02T17:55:00Z',
                'applied_at' => '2026-06-02T17:55:00Z',
            ];
        }

        return [
            'method' => 'GET',
            'path' => '/waterline/api/instances/counter-instance/runs/counter-run-001',
            'status' => 200,
            'captured_at' => '2026-06-02T17:55:10Z',
            'json' => [
                'instance_id' => 'counter-instance',
                'run_id' => 'counter-run-001',
                'status' => 'waiting',
                'status_bucket' => 'running',
                'observer_state' => [
                    'schema' => 'waterline.observer-state',
                    'version' => 1,
                    'captured_at' => '2026-06-02T17:55:10Z',
                    'paths' => [
                        'selected_run_detail' => '/waterline/api/instances/counter-instance/runs/counter-run-001',
                        'selected_run_query_template' => '/waterline/api/instances/counter-instance/runs/counter-run-001/queries/{query}',
                        'instance_query_template' => '/waterline/api/instances/counter-instance/queries/{query}',
                    ],
                    'selected_run' => [
                        'instance_id' => 'counter-instance',
                        'run_id' => 'counter-run-001',
                        'status' => 'waiting',
                        'status_bucket' => 'running',
                        'is_terminal' => false,
                        'output_available' => false,
                        'output' => null,
                    ],
                    'signals' => [
                        'count' => 10,
                        'accepted_count' => 10,
                        'names' => ['increment'],
                        'items' => $signals,
                    ],
                    'queries' => [
                        'declared' => ['current'],
                        'targets' => [
                            [
                                'name' => 'current',
                                'has_contract' => true,
                                'parameters' => [],
                            ],
                        ],
                        'live_results_materialized' => false,
                        'limitation' => [
                            'type' => 'query_state_not_materialized',
                            'reason' => ObserverStateEnvelope::QUERY_STATE_LIMITATION,
                            'message' => 'Selected-run detail is a durable observer snapshot.',
                            'query_action_path_template' => '/waterline/api/instances/counter-instance/runs/counter-run-001/queries/{query}',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selectedRunQueryCapture(): array
    {
        return [
            'method' => 'POST',
            'path' => '/waterline/api/instances/counter-instance/runs/counter-run-001/queries/current',
            'status' => 200,
            'captured_at' => '2026-06-02T17:55:11Z',
            'request_json' => ['arguments' => []],
            'json' => [
                'query_name' => 'current',
                'workflow_id' => 'counter-instance',
                'run_id' => 'counter-run-001',
                'target_scope' => 'run',
                'result' => 55,
            ],
        ];
    }
}
