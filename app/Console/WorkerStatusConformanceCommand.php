<?php

declare(strict_types=1);

namespace Waterline\Console;

use Composer\InstalledVersions;
use DurableWorkflow\Client as SdkClient;
use DurableWorkflow\SdkIdentity;
use DurableWorkflow\Version as SdkVersion;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Waterline\Support\WorkerStatusObservationGate;

final class WorkerStatusConformanceCommand extends Command
{
    private const ABRUPT_PROCESS_LOSS_SIGNAL = 9;

    protected $signature = 'waterline:worker-status-conformance
        {--server-url= : Base URL of the exact published standalone server}
        {--waterline-url= : Base URL of the published Waterline package host}
        {--token= : Standalone server bearer token}
        {--namespace= : Namespace dedicated to this run}
        {--task-queue= : Task queue dedicated to this run}
        {--run-id= : Stable published-artifact run identity}
        {--cli-bin= : Path to the exact published dw executable}
        {--server-version= : Exact published standalone server version}
        {--cli-version= : Exact published CLI version}
        {--sdk-php-version= : Exact installed durable-workflow/sdk version}
        {--workflow-version= : Exact installed durable-workflow/workflow version}
        {--waterline-version= : Exact installed durable-workflow/waterline version}
        {--server-source= : Published server image provenance}
        {--cli-source= : Published CLI installer provenance}
        {--sdk-php-source= : Published PHP SDK package provenance}
        {--workflow-source= : Published Workflow PHP package provenance}
        {--waterline-source= : Published Waterline package provenance}
        {--heartbeat-interval=2 : Server-advertised worker heartbeat interval in seconds}
        {--stale-after=7 : Configured worker stale interval in seconds}
        {--output= : JSON evidence output path}';

    protected $description = 'Exercise published Waterline worker-status projections against live server and CLI authority';

    private HttpFactory $http;

    /** @var array<string, array<string, mixed>> */
    private array $observations = [];

    /** @var list<array<string, mixed>> */
    private array $workerProcesses = [];

    private bool $publishedExecutionStarted = false;

    /** @var array<string, mixed> */
    private array $report = [];

    public function handle(HttpFactory $http): int
    {
        $this->http = $http;
        $startedAt = $this->now();
        $runOption = $this->option('run-id');
        $runId = is_string($runOption) && trim($runOption) !== ''
            ? trim($runOption)
            : 'missing-run-id';
        $artifactVersions = $this->artifactOptionValues('version');
        $artifactSources = $this->artifactOptionValues('source');

        $this->report = [
            'schema' => 'durable-workflow.v2.waterline-worker-status-evidence',
            'version' => 1,
            'scenario_id' => 'waterline_worker_status_visibility',
            'conformance_run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => null,
            'outcome' => 'non_passing_runner_blocked',
            'runner_blocked' => true,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'php_sdk_contract' => $this->phpSdkContract(
                $artifactVersions['sdk-php'],
                $artifactSources['sdk-php'],
            ),
            'local_product_source_checkouts_used' => null,
            'topology' => [],
            'checks' => [],
            'observations' => [],
            'findings' => [],
        ];

        try {
            $context = $this->exercise();
            $checks = $this->checks($context);
            $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));

            $this->report['checks'] = $checks;
            $this->report['observations'] = $this->observations;
            $this->report['worker_execution'] = $context['worker_execution'];
            $this->report['stale_transition'] = $context['stale_transition'];
            $this->report['orderly_deregistration'] = $context['orderly_deregistration'];
            $this->report['routing_exclusion'] = $context['routing_exclusion'];
            $this->report['topology'] = $context['topology'];
            $this->report['worker_processes'] = $this->workerProcesses;
            $this->report['runner_blocked'] = false;

            if ($failed !== []) {
                $this->report['outcome'] = 'fail';
                $this->report['classification'] = 'published-waterline-worker-status-projection-mismatch';
                $this->report['findings'] = array_map(
                    fn (string $check): array => $this->findingForCheck($check, $context),
                    $failed,
                );
                $this->writeReport();

                return self::FAILURE;
            }

            $this->report['outcome'] = 'pass';
            $this->report['classification'] = 'published-waterline-worker-status-proven';
            $this->report['covered_scenarios'] = ['waterline_worker_status_visibility'];
            $this->report['findings'] = [];
            $this->writeReport();

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->report['outcome'] = $this->publishedExecutionStarted
                ? 'fail'
                : 'non_passing_runner_blocked';
            $this->report['runner_blocked'] = ! $this->publishedExecutionStarted;
            $this->report['classification'] = $this->publishedExecutionStarted
                ? 'published-worker-status-product-execution-failed'
                : 'waterline-worker-status-runner-blocked';
            $this->report['observations'] = $this->observations;
            $this->report['worker_processes'] = $this->workerProcesses;
            $this->report['findings'] = [[
                'finding_type' => $this->publishedExecutionStarted
                    ? 'product_behavior_failure'
                    : 'conformance_runner_blocked',
                'owning_surface' => $this->publishedExecutionStarted ? 'server-or-php-sdk' : 'runner',
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]];
            $this->writeReport();

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function exercise(): array
    {
        $serverUrl = rtrim($this->requiredUrlOption('server-url'), '/');
        $waterlineUrl = rtrim($this->requiredUrlOption('waterline-url'), '/');
        $token = $this->optionOrEnvironment('token', 'DURABLE_WORKFLOW_AUTH_TOKEN');
        $namespace = $this->requiredOption('namespace');
        $taskQueue = $this->requiredOption('task-queue');
        $runId = $this->requiredOption('run-id');
        $heartbeatInterval = $this->positiveIntegerOption('heartbeat-interval');
        $staleAfter = $this->positiveIntegerOption('stale-after');
        $cliBin = $this->requiredOption('cli-bin');
        $suffix = substr(preg_replace('/[^a-zA-Z0-9]/', '', $runId) ?: 'run', -16);
        $staleWorkerId = 'waterline-stale-'.strtolower($suffix);
        $freshWorkerId = 'waterline-fresh-'.strtolower($suffix);
        $buildId = 'waterline-compat-'.strtolower($suffix);
        $workflowType = 'conformance.waterline.worker-status';
        $activityType = 'conformance.waterline.worker-status.activity';

        $this->assertPublishedArtifacts($cliBin);
        $composerInstall = $this->composerPackageInstallEvidence();
        if (($composerInstall['passed'] ?? false) !== true) {
            throw new RuntimeException('The installed Composer project does not prove published dist packages without path repositories.');
        }
        $this->report['composer_package_install'] = $composerInstall;
        $this->report['local_product_source_checkouts_used'] = false;
        $this->ensureNamespace($serverUrl, $token, $namespace);
        $this->captureServer('server.readiness', $serverUrl, '/api/ready', $token, $namespace);
        $this->captureWaterline('waterline.readiness', $waterlineUrl);
        $this->publishedExecutionStarted = true;

        $control = new SdkClient($serverUrl, token: $token, namespace: $namespace);
        $staleProcess = null;
        $freshProcess = null;
        $staleStopped = false;
        $freshStopped = false;

        try {
            $staleProcess = $this->startSdkWorker(
                'stale',
                $serverUrl,
                $token,
                $namespace,
                $taskQueue,
                $staleWorkerId,
                $workflowType,
                $activityType,
                $buildId,
                $heartbeatInterval,
            );
            $staleHeartbeats = $this->successiveHeartbeats(
                $staleProcess,
                $staleWorkerId,
                $serverUrl,
                $token,
                $namespace,
                $heartbeatInterval,
                'stale',
            );
            $staleRegistration = data_get(
                $this->captureServer(
                    'server.stale.registration',
                    $serverUrl,
                    '/api/workers/'.rawurlencode($staleWorkerId),
                    $token,
                    $namespace,
                ),
                'body',
                [],
            );

            $initialWorkflowId = 'waterline-worker-status-initial-'.strtolower($suffix);
            $initialHandle = $control->startWorkflow($workflowType, $initialWorkflowId, $taskQueue);
            $initialStart = [
                'workflow_id' => $initialHandle->workflowId,
                'run_id' => $initialHandle->selectedRunId,
                'workflow_type' => $initialHandle->workflowType,
                'started_at' => $this->now(),
            ];
            $initialDetail = $this->waitForWorkflowCompletion(
                $control,
                $initialWorkflowId,
                $staleProcess,
                'stale',
            );

            $freshProcess = $this->startSdkWorker(
                'fresh',
                $serverUrl,
                $token,
                $namespace,
                $taskQueue,
                $freshWorkerId,
                $workflowType,
                $activityType,
                $buildId,
                $heartbeatInterval,
            );
            $freshHeartbeats = $this->successiveHeartbeats(
                $freshProcess,
                $freshWorkerId,
                $serverUrl,
                $token,
                $namespace,
                $heartbeatInterval,
                'fresh',
            );
            $freshRegistration = data_get(
                $this->captureServer(
                    'server.fresh.registration',
                    $serverUrl,
                    '/api/workers/'.rawurlencode($freshWorkerId),
                    $token,
                    $namespace,
                ),
                'body',
                [],
            );

            $before = $this->capturePhase(
                'before',
                $serverUrl,
                $waterlineUrl,
                $token,
                $namespace,
                $taskQueue,
                $staleWorkerId,
                $freshWorkerId,
                $cliBin,
            );

            $staleProcessLoss = $this->crashSdkWorker($staleProcess, 'stale');
            $staleStopped = true;
            $postCrashRegistration = $this->captureServer(
                'server.stale.post-crash-registration',
                $serverUrl,
                '/api/workers/'.rawurlencode($staleWorkerId),
                $token,
                $namespace,
            );
            $finalHeartbeatAt = data_get($postCrashRegistration, 'body.last_heartbeat_at');
            if (! is_string($finalHeartbeatAt) || ! $this->timestamp($finalHeartbeatAt)) {
                throw new RuntimeException('The crashed worker registration did not preserve its final accepted heartbeat timestamp.');
            }
            $staleTransition = $this->waitForStaleTransition(
                $serverUrl,
                $waterlineUrl,
                $token,
                $namespace,
                $taskQueue,
                $staleWorkerId,
                $heartbeatInterval,
                $staleAfter,
                $finalHeartbeatAt,
                $staleProcessLoss['observed_at'],
            );

            $afterWorkflowId = 'waterline-worker-status-after-stale-'.strtolower($suffix);
            $afterHandle = $control->startWorkflow($workflowType, $afterWorkflowId, $taskQueue);
            $afterStart = [
                'workflow_id' => $afterHandle->workflowId,
                'run_id' => $afterHandle->selectedRunId,
                'workflow_type' => $afterHandle->workflowType,
                'started_at' => $this->now(),
            ];
            $staleTask = $control->pollWorkflowTask($staleWorkerId, $taskQueue, 0);
            $stalePollCapture = $this->captureStaleWorkerPoll(
                $serverUrl,
                $token,
                $namespace,
                $taskQueue,
                $staleWorkerId,
            );
            $stalePoll = [
                'sdk_client' => SdkClient::class,
                'sdk_task_claimed' => $staleTask !== null,
                'poll_status' => data_get($stalePollCapture, 'body.poll_status'),
                'authority' => $stalePollCapture,
            ];
            $afterDetail = $this->waitForWorkflowCompletion(
                $control,
                $afterWorkflowId,
                $freshProcess,
                'fresh',
            );

            $after = $this->capturePhase(
                'after',
                $serverUrl,
                $waterlineUrl,
                $token,
                $namespace,
                $taskQueue,
                $staleWorkerId,
                $freshWorkerId,
                $cliBin,
            );
            $orderlyProcessStop = $this->stopSdkWorker($freshProcess, 'fresh', 'orderly-deregistration-proof');
            $freshStopped = true;
            $orderlyDeregistration = $this->waitForOrderlyDeregistration(
                $serverUrl,
                $waterlineUrl,
                $token,
                $namespace,
                $taskQueue,
                $freshWorkerId,
                $cliBin,
            );

            return [
                'topology' => [
                    'namespace' => $namespace,
                    'task_queue' => $taskQueue,
                    'stale_worker_id' => $staleWorkerId,
                    'fresh_worker_id' => $freshWorkerId,
                    'workflow_type' => $workflowType,
                    'compatibility' => $buildId,
                    'initial_workflow_id' => $initialWorkflowId,
                    'after_stale_workflow_id' => $afterWorkflowId,
                ],
                'heartbeat_interval' => $heartbeatInterval,
                'stale_after' => $staleAfter,
                'before' => $before,
                'after' => $after,
                'stale_transition' => $staleTransition,
                'orderly_deregistration' => $orderlyDeregistration,
                'routing_exclusion' => [
                    'stale_worker_poll' => $stalePoll,
                    'stale_worker_tasks_claimed' => $staleTask === null ? 0 : 1,
                    'fresh_worker_after_stale_workflow' => $afterDetail,
                ],
                'worker_execution' => [
                    'driver' => \DurableWorkflow\Worker::class,
                    'client' => SdkClient::class,
                    'heartbeat_loop_implementation_owner' => 'durable-workflow/sdk',
                    'stale_registration' => $staleRegistration,
                    'stale_post_crash_registration' => $postCrashRegistration,
                    'stale_process_loss' => $staleProcessLoss,
                    'fresh_registration' => $freshRegistration,
                    'orderly_process_stop' => $orderlyProcessStop,
                    'stale_heartbeat_timestamps' => $staleHeartbeats,
                    'fresh_heartbeat_timestamps' => $freshHeartbeats,
                    'initial_workflow' => [
                        'start' => $initialStart,
                        'detail' => $initialDetail,
                    ],
                    'after_stale_workflow' => [
                        'start' => $afterStart,
                        'detail' => $afterDetail,
                    ],
                ],
            ];
        } finally {
            if ($staleProcess instanceof Process && ! $staleStopped) {
                $this->stopSdkWorker($staleProcess, 'stale', 'cleanup');
            }
            if ($freshProcess instanceof Process && ! $freshStopped) {
                $this->stopSdkWorker($freshProcess, 'fresh', 'cleanup');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function successiveHeartbeats(
        Process $worker,
        string $workerId,
        string $serverUrl,
        string $token,
        string $namespace,
        int $interval,
        string $label,
    ): array {
        $deadline = microtime(true) + max(20, ($interval * 5) + 10);
        $registrationTimestamp = null;
        $timestamps = [];

        while (microtime(true) < $deadline) {
            $this->assertSdkWorkerRunning($worker, $label);
            $capture = $this->probeWorkerDetail(
                'server.'.$label.'.heartbeat-probe',
                $serverUrl,
                $workerId,
                $token,
                $namespace,
            );
            $timestamp = data_get($capture, 'body.last_heartbeat_at');
            if (is_string($timestamp) && $registrationTimestamp === null) {
                $registrationTimestamp = $timestamp;
            } elseif (is_string($timestamp)
                && $timestamp !== $registrationTimestamp
                && ! in_array($timestamp, $timestamps, true)) {
                $timestamps[] = $timestamp;
            }
            if (count($timestamps) >= 2) {
                return $timestamps;
            }
            usleep(250_000);
        }

        throw new RuntimeException(sprintf(
            'PHP SDK %s worker did not emit two observable heartbeats within the bounded interval.',
            $label,
        ));
    }

    private function startSdkWorker(
        string $label,
        string $serverUrl,
        string $token,
        string $namespace,
        string $taskQueue,
        string $workerId,
        string $workflowType,
        string $activityType,
        string $buildId,
        int $heartbeatInterval,
    ): Process
    {
        $command = [
            PHP_BINARY,
            base_path('artisan'),
            'waterline:worker-status-sdk-worker',
            '--server-url='.$serverUrl,
            '--worker-id='.$workerId,
            '--namespace='.$namespace,
            '--task-queue='.$taskQueue,
            '--workflow-type='.$workflowType,
            '--activity-type='.$activityType,
            '--build-id='.$buildId,
            '--heartbeat-interval='.$heartbeatInterval,
            '--poll-timeout=1',
        ];
        $process = new Process($command, base_path(), [
            'DURABLE_WORKFLOW_AUTH_TOKEN' => $token,
        ]);
        $process->setTimeout(null);
        $process->start();
        $this->workerProcesses[] = [
            'label' => $label,
            'event' => 'started',
            'worker_id' => $workerId,
            'process_id' => $process->getPid(),
            'observed_at' => $this->now(),
            'driver' => \DurableWorkflow\Worker::class,
        ];

        return $process;
    }

    /** @return array<string, mixed> */
    private function crashSdkWorker(Process $process, string $label): array
    {
        if (! $process->isRunning()) {
            throw new RuntimeException(sprintf('PHP SDK %s worker exited before abrupt process loss could be exercised.', $label));
        }

        $processId = $process->getPid();
        $process->signal(self::ABRUPT_PROCESS_LOSS_SIGNAL);
        $deadline = microtime(true) + 5;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $processGone = ! $process->isRunning();
        $event = [
            'label' => $label,
            'event' => 'crashed',
            'reason' => 'stale-transition',
            'cleanup_mode' => 'abrupt_process_loss',
            'signal' => self::ABRUPT_PROCESS_LOSS_SIGNAL,
            'process_id' => $processId,
            'process_gone' => $processGone,
            'exit_code' => $process->getExitCode(),
            'observed_at' => $this->now(),
            'stderr' => trim($process->getErrorOutput()),
        ];
        $this->workerProcesses[] = $event;

        if (! $processGone) {
            throw new RuntimeException(sprintf('PHP SDK %s worker remained alive after abrupt process loss.', $label));
        }

        return $event;
    }

    /** @return array<string, mixed> */
    private function stopSdkWorker(Process $process, string $label, string $reason): array
    {
        $wasRunning = $process->isRunning();
        $exitCode = $wasRunning ? $process->stop(5) : $process->getExitCode();
        $event = [
            'label' => $label,
            'event' => 'stopped',
            'reason' => $reason,
            'cleanup_mode' => 'graceful',
            'was_running' => $wasRunning,
            'process_gone' => ! $process->isRunning(),
            'exit_code' => $exitCode,
            'observed_at' => $this->now(),
            'stderr' => trim($process->getErrorOutput()),
        ];
        $this->workerProcesses[] = $event;

        return $event;
    }

    private function assertSdkWorkerRunning(Process $process, string $label): void
    {
        if ($process->isRunning()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'PHP SDK %s worker exited before conformance completed (exit %s): %s',
            $label,
            (string) $process->getExitCode(),
            trim($process->getErrorOutput() ?: $process->getOutput()),
        ));
    }

    /** @return array<string, mixed> */
    private function waitForWorkflowCompletion(
        SdkClient $client,
        string $workflowId,
        Process $worker,
        string $label,
    ): array {
        $deadline = microtime(true) + 30;
        $last = [];

        while (microtime(true) < $deadline) {
            $this->assertSdkWorkerRunning($worker, $label);
            $execution = $client->describeWorkflow($workflowId);
            $last = $execution->raw + [
                'workflow_id' => $execution->workflowId,
                'run_id' => $execution->runId,
                'workflow_type' => $execution->workflowType,
                'status' => $execution->status,
            ];
            if ($this->completed($last)) {
                return $last;
            }
            usleep(250_000);
        }

        throw new RuntimeException(sprintf(
            'PHP SDK %s worker did not complete workflow %s within 30 seconds (last status: %s).',
            $label,
            $workflowId,
            (string) ($last['status'] ?? 'unknown'),
        ));
    }

    /** @return array<string, mixed> */
    private function capturePhase(
        string $phase,
        string $serverUrl,
        string $waterlineUrl,
        string $token,
        string $namespace,
        string $taskQueue,
        string $staleWorkerId,
        string $freshWorkerId,
        string $cliBin,
    ): array {
        return [
            'server_list' => $this->captureServer(
                'server.'.$phase.'.list',
                $serverUrl,
                '/api/workers?'.http_build_query(['task_queue' => $taskQueue]),
                $token,
                $namespace,
            ),
            'server_stale_list' => $this->captureServer(
                'server.'.$phase.'.stale-list',
                $serverUrl,
                '/api/workers?'.http_build_query(['task_queue' => $taskQueue, 'status' => 'stale']),
                $token,
                $namespace,
            ),
            'server_stale_detail' => $this->captureServer(
                'server.'.$phase.'.stale-detail',
                $serverUrl,
                '/api/workers/'.rawurlencode($staleWorkerId),
                $token,
                $namespace,
            ),
            'server_fresh_detail' => $this->captureServer(
                'server.'.$phase.'.fresh-detail',
                $serverUrl,
                '/api/workers/'.rawurlencode($freshWorkerId),
                $token,
                $namespace,
            ),
            'waterline_health' => $this->captureWaterline('waterline.'.$phase.'.health', $waterlineUrl),
            'cli_list' => $this->captureCli(
                'cli.'.$phase.'.list',
                $cliBin,
                ['worker:list', '--task-queue='.$taskQueue],
                $serverUrl,
                $token,
                $namespace,
            ),
            'cli_stale_list' => $this->captureCli(
                'cli.'.$phase.'.stale-list',
                $cliBin,
                ['worker:list', '--task-queue='.$taskQueue, '--status=stale'],
                $serverUrl,
                $token,
                $namespace,
            ),
            'cli_stale_detail' => $this->captureCli(
                'cli.'.$phase.'.stale-detail',
                $cliBin,
                ['worker:describe', $staleWorkerId],
                $serverUrl,
                $token,
                $namespace,
            ),
            'cli_fresh_detail' => $this->captureCli(
                'cli.'.$phase.'.fresh-detail',
                $cliBin,
                ['worker:describe', $freshWorkerId],
                $serverUrl,
                $token,
                $namespace,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function waitForStaleTransition(
        string $serverUrl,
        string $waterlineUrl,
        string $token,
        string $namespace,
        string $taskQueue,
        string $staleWorkerId,
        int $heartbeatInterval,
        int $staleAfter,
        string $finalHeartbeatAt,
        string $processLostAt,
    ): array {
        $finalHeartbeatTimestamp = strtotime($finalHeartbeatAt);
        if ($finalHeartbeatTimestamp === false) {
            throw new RuntimeException('The stale transition requires a valid final heartbeat timestamp.');
        }

        $deadline = max(microtime(true), $finalHeartbeatTimestamp + $staleAfter) + 20;
        $lastServer = [];
        $lastWaterline = [];

        while (microtime(true) < $deadline) {
            $lastServer = $this->captureServer(
                'server.stale-transition-probe',
                $serverUrl,
                '/api/workers/'.rawurlencode($staleWorkerId),
                $token,
                $namespace,
            );
            $lastWaterline = $this->captureWaterline('waterline.stale-transition-probe', $waterlineUrl);
            $waterlineStale = $this->waterlineWorker($lastWaterline['body'], $taskQueue, $staleWorkerId);

            if (($lastServer['body']['status'] ?? null) === 'stale'
                && ($waterlineStale['list']['status'] ?? null) === 'stale'
                && ($waterlineStale['detail']['status'] ?? null) === 'stale') {
                $observedAt = $this->now();

                return $this->staleTransitionTiming($finalHeartbeatAt, $observedAt, $staleAfter) + [
                    'process_lost_at' => $processLostAt,
                    'observed_stale_at' => $observedAt,
                    'server' => $lastServer,
                    'waterline' => $lastWaterline,
                ];
            }

            sleep(max(1, min($heartbeatInterval, 2)));
        }

        $observedAt = $this->now();
        $waterlineWorker = $this->waterlineWorker(
            is_array($lastWaterline['body'] ?? null) ? $lastWaterline['body'] : [],
            $taskQueue,
            $staleWorkerId,
        );

        return $this->staleTransitionTiming($finalHeartbeatAt, $observedAt, $staleAfter) + [
            'process_lost_at' => $processLostAt,
            'observed_stale_at' => null,
            'last_checked_at' => $observedAt,
            'within_bounded_window' => false,
            'server' => $lastServer,
            'waterline' => $lastWaterline,
            'mismatch' => [
                'worker_id' => $staleWorkerId,
                'server_status' => data_get($lastServer, 'body.status'),
                'waterline_list_status' => data_get($waterlineWorker, 'list.status'),
                'waterline_detail_status' => data_get($waterlineWorker, 'detail.status'),
            ],
        ];
    }

    /** @return array<string, int|string|bool> */
    private function staleTransitionTiming(string $finalHeartbeatAt, string $observedAt, int $staleAfter): array
    {
        $finalHeartbeatTimestamp = strtotime($finalHeartbeatAt);
        $observedTimestamp = strtotime($observedAt);
        if ($finalHeartbeatTimestamp === false || $observedTimestamp === false) {
            throw new RuntimeException('Stale transition timing requires valid heartbeat and observation timestamps.');
        }

        $elapsed = $observedTimestamp - $finalHeartbeatTimestamp;

        return [
            'final_heartbeat_at' => $finalHeartbeatAt,
            'stale_deadline_at' => gmdate('Y-m-d\TH:i:s\Z', $finalHeartbeatTimestamp + $staleAfter),
            'configured_stale_after_seconds' => $staleAfter,
            'transition_elapsed_seconds' => $elapsed,
            'seconds_after_stale_deadline' => $elapsed - $staleAfter,
            'bounded_min_seconds' => $staleAfter,
            'bounded_max_seconds' => $staleAfter + 10,
            'within_bounded_window' => $elapsed >= $staleAfter && $elapsed <= $staleAfter + 10,
        ];
    }

    /** @return array<string, mixed> */
    private function waitForOrderlyDeregistration(
        string $serverUrl,
        string $waterlineUrl,
        string $token,
        string $namespace,
        string $taskQueue,
        string $workerId,
        string $cliBin,
    ): array {
        $deadline = microtime(true) + 10;
        $last = [];

        while (microtime(true) < $deadline) {
            $serverDetail = $this->captureWorkerDetailAllowMissing(
                'server.orderly-deregistration.detail',
                $serverUrl,
                $workerId,
                $token,
                $namespace,
            );
            $serverList = $this->captureServer(
                'server.orderly-deregistration.list',
                $serverUrl,
                '/api/workers?'.http_build_query(['task_queue' => $taskQueue]),
                $token,
                $namespace,
            );
            $serverStaleList = $this->captureServer(
                'server.orderly-deregistration.stale-list',
                $serverUrl,
                '/api/workers?'.http_build_query(['task_queue' => $taskQueue, 'status' => 'stale']),
                $token,
                $namespace,
            );
            $waterline = $this->captureWaterline('waterline.orderly-deregistration.health', $waterlineUrl);
            $cliList = $this->captureCli(
                'cli.orderly-deregistration.list',
                $cliBin,
                ['worker:list', '--task-queue='.$taskQueue],
                $serverUrl,
                $token,
                $namespace,
            );
            $cliStaleList = $this->captureCli(
                'cli.orderly-deregistration.stale-list',
                $cliBin,
                ['worker:list', '--task-queue='.$taskQueue, '--status=stale'],
                $serverUrl,
                $token,
                $namespace,
            );
            $waterlineWorker = $this->waterlineWorker($waterline['body'], $taskQueue, $workerId);
            $last = [
                'observed_at' => $this->now(),
                'server_detail' => $serverDetail,
                'server_list' => $serverList,
                'server_stale_list' => $serverStaleList,
                'waterline' => $waterline,
                'cli_list' => $cliList,
                'cli_stale_list' => $cliStaleList,
                'server_absent' => $serverDetail['status_code'] === 404
                    && $this->findWorker($this->authorityWorkers($serverList['body']), $workerId) === []
                    && $this->findWorker($this->authorityWorkers($serverStaleList['body']), $workerId) === [],
                'waterline_absent' => $waterlineWorker['list'] === [] && $waterlineWorker['detail'] === [],
                'cli_absent' => $this->findWorker($this->authorityWorkers($cliList['body']), $workerId) === []
                    && $this->findWorker($this->authorityWorkers($cliStaleList['body']), $workerId) === [],
            ];

            if ($last['server_absent'] && $last['waterline_absent'] && $last['cli_absent']) {
                return $last + ['deregistered_instead_of_stale' => true];
            }

            usleep(250_000);
        }

        return $last + ['deregistered_instead_of_stale' => false];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, bool>
     */
    private function checks(array $context): array
    {
        $topology = $context['topology'];
        $staleId = $topology['stale_worker_id'];
        $freshId = $topology['fresh_worker_id'];
        $taskQueue = $topology['task_queue'];
        $beforeWaterline = $this->waterlineWorker(
            $context['before']['waterline_health']['body'],
            $taskQueue,
            $staleId,
        );
        $afterStaleWaterline = $this->waterlineWorker(
            $context['after']['waterline_health']['body'],
            $taskQueue,
            $staleId,
        );
        $afterFreshWaterline = $this->waterlineWorker(
            $context['after']['waterline_health']['body'],
            $taskQueue,
            $freshId,
        );
        $beforeServer = $context['before']['server_stale_detail']['body'];
        $afterStaleServer = $context['after']['server_stale_detail']['body'];
        $afterFreshServer = $context['after']['server_fresh_detail']['body'];
        $afterStaleCli = $this->cliWorker($context['after']['cli_stale_detail']['body']);
        $afterFreshCli = $this->cliWorker($context['after']['cli_fresh_detail']['body']);
        $beforeServerListStale = $this->findWorker(
            $this->authorityWorkers($context['before']['server_list']['body']),
            $staleId,
        );
        $beforeCliListStale = $this->findWorker(
            $this->authorityWorkers($context['before']['cli_list']['body']),
            $staleId,
        );
        $afterServerStaleList = $this->findWorker(
            $this->authorityWorkers($context['after']['server_stale_list']['body']),
            $staleId,
        );
        $afterCliStaleList = $this->findWorker(
            $this->authorityWorkers($context['after']['cli_stale_list']['body']),
            $staleId,
        );
        $afterServerFreshList = $this->findWorker(
            $this->authorityWorkers($context['after']['server_list']['body']),
            $freshId,
        );
        $afterCliFreshList = $this->findWorker(
            $this->authorityWorkers($context['after']['cli_list']['body']),
            $freshId,
        );
        $stalePoll = $context['routing_exclusion']['stale_worker_poll'];
        $staleReason = $stalePoll['poll_status'] ?? $stalePoll['reason'] ?? null;
        $checks = WorkerStatusObservationGate::checks($this->observations);

        return array_merge($checks, [
            'exact_published_package_versions' => $this->installedPackageVersion('durable-workflow/sdk') === $this->requiredOption('sdk-php-version')
                && $this->installedPackageVersion('durable-workflow/workflow') === $this->requiredOption('workflow-version')
                && $this->installedPackageVersion('durable-workflow/waterline') === $this->requiredOption('waterline-version'),
            'no_local_product_source_checkout' => ($this->report['local_product_source_checkouts_used'] ?? null) === false,
            'stale_worker_emitted_two_heartbeats' => count($context['worker_execution']['stale_heartbeat_timestamps']) >= 2,
            'fresh_worker_emitted_two_heartbeats' => count($context['worker_execution']['fresh_heartbeat_timestamps']) >= 2,
            'stale_worker_terminated_by_abrupt_process_loss' => ($context['worker_execution']['stale_process_loss']['cleanup_mode'] ?? null) === 'abrupt_process_loss'
                && ($context['worker_execution']['stale_process_loss']['signal'] ?? null) === self::ABRUPT_PROCESS_LOSS_SIGNAL
                && ($context['worker_execution']['stale_process_loss']['process_gone'] ?? false) === true,
            'stale_worker_final_heartbeat_preserved' => $this->sameTimestamp(
                data_get($context, 'worker_execution.stale_post_crash_registration.body.last_heartbeat_at'),
                data_get($context, 'stale_transition.server.body.last_heartbeat_at'),
            ) && $this->sameTimestamp(
                data_get($context, 'worker_execution.stale_post_crash_registration.body.last_heartbeat_at'),
                data_get($context, 'after.server_stale_detail.body.last_heartbeat_at'),
            ) && $this->sameTimestamp(
                data_get($context, 'worker_execution.stale_post_crash_registration.body.last_heartbeat_at'),
                $afterStaleWaterline['detail']['last_heartbeat_at'] ?? null,
            ) && $this->sameTimestamp(
                data_get($context, 'worker_execution.stale_post_crash_registration.body.last_heartbeat_at'),
                $afterStaleCli['last_heartbeat_at'] ?? null,
            ),
            'orderly_worker_deregisters_instead_of_becoming_stale' => ($context['worker_execution']['orderly_process_stop']['cleanup_mode'] ?? null) === 'graceful'
                && ($context['worker_execution']['orderly_process_stop']['was_running'] ?? false) === true
                && ($context['worker_execution']['orderly_process_stop']['process_gone'] ?? false) === true
                && ($context['orderly_deregistration']['deregistered_instead_of_stale'] ?? false) === true,
            'dedicated_wave_topology_preserved' => $staleId !== $freshId
                && ($context['worker_execution']['stale_post_crash_registration']['body']['worker_id'] ?? null) === $staleId
                && ($context['worker_execution']['stale_post_crash_registration']['body']['namespace'] ?? null) === $topology['namespace']
                && ($context['worker_execution']['stale_post_crash_registration']['body']['task_queue'] ?? null) === $taskQueue
                && ($context['worker_execution']['fresh_registration']['worker_id'] ?? null) === $freshId
                && ($context['worker_execution']['fresh_registration']['namespace'] ?? null) === $topology['namespace']
                && ($context['worker_execution']['fresh_registration']['task_queue'] ?? null) === $taskQueue
                && $topology['initial_workflow_id'] !== $topology['after_stale_workflow_id']
                && ($context['worker_execution']['initial_workflow']['start']['workflow_id'] ?? null) === $topology['initial_workflow_id']
                && ($context['worker_execution']['after_stale_workflow']['start']['workflow_id'] ?? null) === $topology['after_stale_workflow_id'],
            'real_workflow_work_executed' => $this->completed($context['worker_execution']['initial_workflow']['detail'])
                && $this->completed($context['worker_execution']['after_stale_workflow']['detail']),
            'waterline_namespace_and_task_queue_visible' => ($beforeWaterline['list']['namespace'] ?? null) === $topology['namespace']
                && ($beforeWaterline['list']['task_queue'] ?? null) === $taskQueue,
            'waterline_freshness_visible' => ($beforeWaterline['list']['status'] ?? null) === 'active'
                && $this->timestamp($beforeWaterline['list']['last_heartbeat_at'] ?? null),
            'waterline_task_slots_visible' => is_array($beforeWaterline['list']['task_slots'] ?? null)
                && array_key_exists('workflow_available', $beforeWaterline['list']['task_slots']),
            'waterline_process_metrics_visible' => is_array($beforeWaterline['list']['process_metrics'] ?? null)
                && array_key_exists('process_id', $beforeWaterline['list']['process_metrics']),
            'waterline_protocol_or_compatibility_visible' => ($beforeWaterline['list']['sdk_version'] ?? null) === SdkIdentity::registration()
                && ($beforeWaterline['list']['build_id'] ?? null) === $topology['compatibility'],
            'waterline_list_detail_agree_before_stale' => $this->projectionsAgree($beforeWaterline['list'], $beforeWaterline['detail']),
            'waterline_list_agrees_with_server_before_stale' => $this->projectionsAgree($beforeWaterline['list'], $beforeServer),
            'waterline_list_agrees_with_server_and_cli_lists_before_stale' => $this->listProjectionsAgree($beforeWaterline['list'], $beforeServerListStale)
                && $this->listProjectionsAgree($beforeWaterline['list'], $beforeCliListStale),
            'waterline_stale_list_detail_agree' => $this->projectionsAgree($afterStaleWaterline['list'], $afterStaleWaterline['detail']),
            'waterline_fresh_list_detail_agree' => $this->projectionsAgree($afterFreshWaterline['list'], $afterFreshWaterline['detail']),
            'waterline_stale_agrees_with_server_and_cli' => $this->projectionsAgree($afterStaleWaterline['detail'], $afterStaleServer)
                && $this->projectionsAgree($afterStaleWaterline['detail'], $afterStaleCli),
            'waterline_fresh_agrees_with_server_and_cli' => $this->projectionsAgree($afterFreshWaterline['detail'], $afterFreshServer)
                && $this->projectionsAgree($afterFreshWaterline['detail'], $afterFreshCli),
            'waterline_stale_list_agrees_with_server_and_cli_lists' => $this->listProjectionsAgree($afterStaleWaterline['list'], $afterServerStaleList)
                && $this->listProjectionsAgree($afterStaleWaterline['list'], $afterCliStaleList),
            'waterline_fresh_list_agrees_with_server_and_cli_lists' => $this->listProjectionsAgree($afterFreshWaterline['list'], $afterServerFreshList)
                && $this->listProjectionsAgree($afterFreshWaterline['list'], $afterCliFreshList),
            'waterline_stale_transition_visible' => ($afterStaleWaterline['list']['status'] ?? null) === 'stale'
                && ($afterStaleWaterline['detail']['status'] ?? null) === 'stale',
            'stale_transition_bounded' => ($context['stale_transition']['within_bounded_window'] ?? false) === true,
            'waterline_uses_configured_stale_interval' => data_get(
                $context['after']['waterline_health']['body'],
                'operator_metrics.workers.stale_after_seconds',
            ) === $context['stale_after'],
            'server_advertises_configured_stale_interval' => ($beforeServer['stale_after_seconds'] ?? null) === $context['stale_after'],
            'stale_worker_cannot_claim_new_work' => $context['routing_exclusion']['stale_worker_tasks_claimed'] === 0
                && in_array($staleReason, ['stale_worker_registration', 'worker_heartbeat_stale'], true),
            'fresh_peer_remains_visible_and_eligible' => ($afterFreshWaterline['list']['status'] ?? null) === 'active'
                && ($afterFreshServer['status'] ?? null) === 'active'
                && $this->completed($context['routing_exclusion']['fresh_worker_after_stale_workflow']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $health
     * @return array{list: array<string, mixed>, detail: array<string, mixed>}
     */
    private function waterlineWorker(array $health, string $taskQueue, string $workerId): array
    {
        $workers = data_get($health, 'operator_metrics.workers', []);
        $listRows = array_merge(
            is_array($workers['registrations'] ?? null) ? $workers['registrations'] : [],
            is_array($workers['stale_registrations'] ?? null) ? $workers['stale_registrations'] : [],
        );
        $list = $this->findWorker($listRows, $workerId);
        $queues = data_get($health, 'queue_visibility.task_queues', []);
        $queue = collect(is_array($queues) ? $queues : [])->first(
            static fn (mixed $row): bool => is_array($row)
                && (($row['task_queue'] ?? $row['name'] ?? null) === $taskQueue),
        );
        $detail = $this->findWorker(is_array($queue['workers'] ?? null) ? $queue['workers'] : [], $workerId);

        return ['list' => $list, 'detail' => $detail];
    }

    /**
     * @param  list<array<string, mixed>>  $workers
     * @return array<string, mixed>
     */
    private function findWorker(array $workers, string $workerId): array
    {
        foreach ($workers as $worker) {
            if (($worker['worker_id'] ?? null) === $workerId) {
                return $worker;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $payload */
    private function cliWorker(array $payload): array
    {
        if (is_array($payload['worker'] ?? null)) {
            return $payload['worker'];
        }

        if (isset($payload['worker_id'])) {
            return $payload;
        }

        $workers = is_array($payload['workers'] ?? null) ? $payload['workers'] : [];

        return is_array($workers[0] ?? null) ? $workers[0] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function authorityWorkers(array $payload): array
    {
        $workers = $payload['workers'] ?? [];

        return is_array($workers) ? array_values(array_filter($workers, 'is_array')) : [];
    }

    /**
     * Compare the operator fields common to Waterline list/detail, server API,
     * and CLI projections. Extra authority fields do not affect agreement.
     *
     * @param  array<string, mixed>  $waterline
     * @param  array<string, mixed>  $authority
     */
    private function projectionsAgree(array $waterline, array $authority): bool
    {
        return $this->projectionFieldsAgree($waterline, $authority, [
            'worker_id',
            'namespace',
            'task_queue',
            'runtime',
            'status',
            'sdk_version',
            'build_id',
            'supported_workflow_types',
            'supported_activity_types',
            'max_concurrent_workflow_tasks',
            'max_concurrent_activity_tasks',
            'max_concurrent_worker_sessions',
            'heartbeat_interval_seconds',
            'task_slots',
            'process_metrics',
        ]);
    }

    /**
     * @param  array<string, mixed>  $waterline
     * @param  array<string, mixed>  $authority
     */
    private function listProjectionsAgree(array $waterline, array $authority): bool
    {
        return $this->projectionFieldsAgree($waterline, $authority, [
            'worker_id',
            'namespace',
            'task_queue',
            'runtime',
            'status',
            'sdk_version',
            'build_id',
            'max_concurrent_workflow_tasks',
            'max_concurrent_activity_tasks',
            'task_slots',
        ]);
    }

    /**
     * @param  array<string, mixed>  $waterline
     * @param  array<string, mixed>  $authority
     * @param  list<string>  $fields
     */
    private function projectionFieldsAgree(array $waterline, array $authority, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($waterline[$field] ?? null) !== ($authority[$field] ?? null)) {
                return false;
            }
        }

        if (! $this->timestamp($waterline['last_heartbeat_at'] ?? null)
            || ! $this->timestamp($authority['last_heartbeat_at'] ?? null)) {
            return false;
        }

        $delta = abs((strtotime($waterline['last_heartbeat_at']) ?: 0) - (strtotime($authority['last_heartbeat_at']) ?: 0));

        return $delta <= max(2, $this->positiveIntegerOption('heartbeat-interval') * 2);
    }

    /** @return array<string, mixed> */
    private function probeWorkerDetail(
        string $name,
        string $baseUrl,
        string $workerId,
        string $token,
        string $namespace,
    ): array {
        $url = $baseUrl.'/api/workers/'.rawurlencode($workerId);
        $response = $this->http->acceptJson()
            ->withToken($token)
            ->withHeaders([
                'X-Namespace' => $namespace,
                'X-Durable-Workflow-Control-Plane-Version' => '2',
            ])
            ->timeout(15)
            ->get($url);

        if ($response->status() === 404) {
            return [];
        }

        return $this->recordHttp($name, $url, $response);
    }

    /** @return array<string, mixed> */
    private function captureWorkerDetailAllowMissing(
        string $name,
        string $baseUrl,
        string $workerId,
        string $token,
        string $namespace,
    ): array {
        $url = $baseUrl.'/api/workers/'.rawurlencode($workerId);
        $response = $this->http->acceptJson()
            ->withToken($token)
            ->withHeaders([
                'X-Namespace' => $namespace,
                'X-Durable-Workflow-Control-Plane-Version' => '2',
            ])
            ->timeout(15)
            ->get($url);

        return $this->recordHttp($name, $url, $response, acceptedStatuses: [404]);
    }

    /** @return array<string, mixed> */
    private function captureStaleWorkerPoll(
        string $baseUrl,
        string $token,
        string $namespace,
        string $taskQueue,
        string $workerId,
    ): array {
        $url = $baseUrl.'/api/worker/workflow-tasks/poll';
        $response = $this->http->acceptJson()
            ->asJson()
            ->withToken($token)
            ->withHeaders([
                'X-Namespace' => $namespace,
                'X-Durable-Workflow-Protocol-Version' => SdkVersion::WORKER_PROTOCOL,
            ])
            ->timeout(15)
            ->post($url, [
                'worker_id' => $workerId,
                'task_queue' => $taskQueue,
                'poll_request_id' => 'waterline-stale-proof-'.bin2hex(random_bytes(8)),
                'timeout_seconds' => 0,
            ]);

        return $this->recordHttp('server.stale-worker-poll-refusal', $url, $response, 'POST');
    }

    /** @return array<string, mixed> */
    private function captureServer(
        string $name,
        string $baseUrl,
        string $path,
        string $token,
        string $namespace,
    ): array {
        $url = $baseUrl.$path;
        $response = $this->http->acceptJson()
            ->withToken($token)
            ->withHeaders([
                'X-Namespace' => $namespace,
                'X-Durable-Workflow-Protocol-Version' => '1.0',
                'X-Durable-Workflow-Control-Plane-Version' => '2',
            ])
            ->timeout(15)
            ->get($url);

        return $this->recordHttp($name, $url, $response);
    }

    /** @return array<string, mixed> */
    private function captureWaterline(string $name, string $baseUrl): array
    {
        $url = $baseUrl.'/waterline/api/v2/health';
        $response = $this->http->acceptJson()
            ->withHeader('X-Durable-Workflow-Control-Plane-Version', '2')
            ->timeout(30)
            ->get($url);

        return $this->recordHttp($name, $url, $response);
    }

    /** @return array<string, mixed> */
    private function recordHttp(
        string $name,
        string $url,
        Response $response,
        string $method = 'GET',
        array $acceptedStatuses = [],
    ): array
    {
        $body = $response->json();
        $capture = [
            'provenance' => [
                'kind' => 'live_http',
                'captured_by' => WorkerStatusObservationGate::CAPTURED_BY,
            ],
            'observed_at' => $this->now(),
            'method' => $method,
            'url' => $url,
            'status_code' => $response->status(),
            'body' => is_array($body) ? $body : ['raw' => $response->body()],
        ];
        $this->observations[$name] = $capture;

        if (! $response->successful() && ! in_array($response->status(), $acceptedStatuses, true)) {
            throw new RuntimeException(sprintf('%s %s returned HTTP %d', $method, $url, $response->status()));
        }

        return $capture;
    }

    /** @return array<string, mixed> */
    private function captureCli(
        string $name,
        string $cliBin,
        array $arguments,
        string $serverUrl,
        string $token,
        string $namespace,
    ): array {
        $command = [$cliBin, ...$arguments, '--output=json'];
        $process = new Process($command, null, [
            'DURABLE_WORKFLOW_SERVER_URL' => $serverUrl,
            'DURABLE_WORKFLOW_AUTH_TOKEN' => $token,
            'DURABLE_WORKFLOW_NAMESPACE' => $namespace,
            'DURABLE_WORKFLOW_TLS_VERIFY' => 'false',
        ]);
        $process->setTimeout(60);
        $process->run();
        $body = json_decode($process->getOutput(), true);
        $capture = [
            'provenance' => [
                'kind' => 'live_cli_process',
                'captured_by' => WorkerStatusObservationGate::CAPTURED_BY,
            ],
            'observed_at' => $this->now(),
            'command' => ['dw', ...$arguments, '--output=json'],
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'body' => is_array($body) ? $body : ['raw' => $process->getOutput()],
        ];
        $this->observations[$name] = $capture;

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Published CLI command [%s] failed with exit code %s: %s',
                implode(' ', $capture['command']),
                (string) $process->getExitCode(),
                trim($process->getErrorOutput()),
            ));
        }

        return $capture;
    }

    private function ensureNamespace(string $baseUrl, string $token, string $namespace): void
    {
        $url = $baseUrl.'/api/namespaces';
        $response = $this->http->acceptJson()
            ->asJson()
            ->withToken($token)
            ->withHeaders([
                'X-Namespace' => $namespace,
                'X-Durable-Workflow-Control-Plane-Version' => '2',
            ])
            ->timeout(30)
            ->post($url, [
                'name' => $namespace,
                'description' => 'Published Waterline worker-status conformance',
                'retention_days' => 1,
            ]);

        if (! in_array($response->status(), [201, 409], true)) {
            throw new RuntimeException(sprintf('Namespace setup returned HTTP %d: %s', $response->status(), $response->body()));
        }

        $this->observations['server.namespace-setup'] = [
            'provenance' => [
                'kind' => 'live_http',
                'captured_by' => WorkerStatusObservationGate::CAPTURED_BY,
            ],
            'observed_at' => $this->now(),
            'method' => 'POST',
            'url' => $url,
            'status_code' => $response->status(),
            'body' => is_array($response->json()) ? $response->json() : ['raw' => $response->body()],
        ];
    }

    private function assertPublishedArtifacts(string $cliBin): void
    {
        $versions = $this->artifactOptions('version');
        $sources = $this->artifactOptions('source');
        $failures = [];

        if (! self::isExactSemverRelease($versions['server'])) {
            $failures[] = 'server-version must be an exact SemVer release';
        }
        if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $versions['cli'])) {
            $failures[] = 'cli-version must be exact';
        }
        if (! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $versions['sdk-php'])) {
            $failures[] = 'sdk-php-version must be exact';
        }
        foreach (['workflow', 'waterline'] as $package) {
            if (! self::isExact2xPrerelease($versions[$package])) {
                $failures[] = $package.'-version must be an exact 2.0 prerelease';
            }
        }
        if ($this->installedPackageVersion('durable-workflow/sdk') !== $versions['sdk-php']) {
            $failures[] = 'installed PHP SDK package does not match sdk-php-version';
        }
        if (SdkIdentity::version() !== $versions['sdk-php']) {
            $failures[] = 'PHP SDK runtime identity does not match sdk-php-version';
        }
        if ($this->installedPackageVersion('durable-workflow/workflow') !== $versions['workflow']) {
            $failures[] = 'installed Workflow PHP package does not match workflow-version';
        }
        if ($this->installedPackageVersion('durable-workflow/waterline') !== $versions['waterline']) {
            $failures[] = 'installed Waterline package does not match waterline-version';
        }
        if (! is_file($cliBin) || ! is_executable($cliBin)) {
            $failures[] = 'cli-bin is not an executable published CLI';
        }
        foreach ($sources as $actor => $source) {
            if ($source === '' || preg_match('#(?:^|[/:])(path|file|workspace)(?:[/:]|$)#i', $source)) {
                $failures[] = $actor.'-source is not recognized published provenance';
            }
        }
        if (! str_starts_with($sources['server'], 'docker://durableworkflow/server@sha256:')) {
            $failures[] = 'server-source must identify the resolved public image digest';
        }
        if (! str_starts_with($sources['cli'], 'https://github.com/durable-workflow/cli/releases/download/')) {
            $failures[] = 'cli-source must identify the official release installer';
        }
        if ($sources['sdk-php'] !== 'packagist://durable-workflow/sdk@'.$versions['sdk-php']) {
            $failures[] = 'sdk-php-source must identify the exact Packagist package';
        }
        if ($sources['workflow'] !== 'packagist://durable-workflow/workflow@'.$versions['workflow']) {
            $failures[] = 'workflow-source must identify the exact Packagist package';
        }
        if ($sources['waterline'] !== 'packagist://durable-workflow/waterline@'.$versions['waterline']) {
            $failures[] = 'waterline-source must identify the exact Packagist package';
        }

        if ($failures !== []) {
            throw new RuntimeException(implode('; ', $failures));
        }
    }

    private static function isExact2xPrerelease(string $version): bool
    {
        return preg_match('/\A2\.0\.0-(?:alpha|beta|rc)\.(?:0|[1-9]\d*)\z/', $version) === 1;
    }

    private static function isExactSemverRelease(string $version): bool
    {
        $coreIdentifier = '(?:0|[1-9]\d*)';
        $prereleaseIdentifier = '(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)';
        $pattern = '/\A'.$coreIdentifier.'\.'.$coreIdentifier.'\.'.$coreIdentifier
            .'(?:-(?<prerelease>'.$prereleaseIdentifier.'(?:\.'.$prereleaseIdentifier.')*))?\z/';

        if (preg_match($pattern, $version, $matches) !== 1) {
            return false;
        }

        $rollingIdentifiers = [
            'latest', 'current', 'head', 'main', 'master', 'dev', 'snapshot', 'unresolved', 'placeholder',
        ];
        foreach (explode('.', $matches['prerelease'] ?? '') as $identifier) {
            if (in_array(strtolower($identifier), $rollingIdentifiers, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function composerPackageInstallEvidence(): array
    {
        $composerJsonPath = base_path('composer.json');
        $composerLockPath = base_path('composer.lock');
        $composerJson = is_file($composerJsonPath)
            ? json_decode((string) file_get_contents($composerJsonPath), true)
            : null;
        $composerLock = is_file($composerLockPath)
            ? json_decode((string) file_get_contents($composerLockPath), true)
            : null;

        if (! is_array($composerJson) || ! is_array($composerLock)) {
            return [
                'passed' => false,
                'reason' => 'composer_project_metadata_missing',
            ];
        }

        $repositories = $composerJson['repositories'] ?? [];
        $repositoryJson = json_encode($repositories, JSON_UNESCAPED_SLASHES) ?: '';
        $hasPathRepository = str_contains(strtolower($repositoryJson), '"type":"path"');
        $packages = array_merge(
            is_array($composerLock['packages'] ?? null) ? $composerLock['packages'] : [],
            is_array($composerLock['packages-dev'] ?? null) ? $composerLock['packages-dev'] : [],
        );
        $expected = [
            'durable-workflow/sdk' => $this->requiredOption('sdk-php-version'),
            'durable-workflow/workflow' => $this->requiredOption('workflow-version'),
            'durable-workflow/waterline' => $this->requiredOption('waterline-version'),
        ];
        $evidence = [];

        foreach ($expected as $package => $version) {
            $entry = collect($packages)->first(
                static fn (mixed $candidate): bool => is_array($candidate)
                    && ($candidate['name'] ?? null) === $package,
            );
            $lockedVersion = is_array($entry) && is_string($entry['version'] ?? null)
                ? ltrim($entry['version'], 'v')
                : null;
            $dist = is_array($entry['dist'] ?? null) ? $entry['dist'] : null;
            $evidence[$package] = [
                'expected_version' => $version,
                'locked_version' => $lockedVersion,
                'dist_type' => is_string($dist['type'] ?? null) ? $dist['type'] : null,
                'dist_url' => is_string($dist['url'] ?? null) ? $dist['url'] : null,
                'dist_reference' => is_string($dist['reference'] ?? null) ? $dist['reference'] : null,
                'passed' => $lockedVersion === $version
                    && is_string($dist['url'] ?? null)
                    && filter_var($dist['url'], FILTER_VALIDATE_URL) !== false,
            ];
        }

        return [
            'checked_at' => $this->now(),
            'preferred_install' => data_get($composerJson, 'config.preferred-install'),
            'path_repository_present' => $hasPathRepository,
            'packages' => $evidence,
            'local_product_source_checkouts_used' => false,
            'passed' => ! $hasPathRepository
                && ! in_array(false, array_column($evidence, 'passed'), true),
        ];
    }

    /** @return array<string, string> */
    private function artifactOptions(string $suffix): array
    {
        return [
            'server' => $this->requiredOption('server-'.$suffix),
            'cli' => $this->requiredOption('cli-'.$suffix),
            'sdk-php' => $this->requiredOption('sdk-php-'.$suffix),
            'workflow' => $this->requiredOption('workflow-'.$suffix),
            'waterline' => $this->requiredOption('waterline-'.$suffix),
        ];
    }

    /** @return array<string, string|null> */
    private function artifactOptionValues(string $suffix): array
    {
        $values = [];
        foreach (['server', 'cli', 'sdk-php', 'workflow', 'waterline'] as $artifact) {
            $value = $this->option($artifact.'-'.$suffix);
            $values[$artifact] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $values;
    }

    private function installedPackageVersion(string $package): string
    {
        $version = InstalledVersions::getPrettyVersion($package) ?? '';

        return str_starts_with($version, 'v') ? substr($version, 1) : $version;
    }

    /** @return array<string, string|null> */
    private function phpSdkContract(?string $artifactVersion, ?string $artifactSource): array
    {
        return [
            'package' => SdkIdentity::PACKAGE,
            'installed_version' => SdkIdentity::version(),
            'registration_identity' => SdkIdentity::registration(),
            'worker_protocol_version' => SdkVersion::WORKER_PROTOCOL,
            'artifact_version' => $artifactVersion,
            'artifact_source' => $artifactSource,
        ];
    }

    /** @param array<string, mixed> $detail */
    private function completed(array $detail): bool
    {
        return strtolower((string) ($detail['status'] ?? data_get($detail, 'run.status', ''))) === 'completed';
    }

    private function timestamp(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }

    private function sameTimestamp(mixed $left, mixed $right): bool
    {
        return $this->timestamp($left)
            && $this->timestamp($right)
            && strtotime($left) === strtotime($right);
    }

    private function requiredUrlOption(string $name): string
    {
        $value = $this->requiredOption($name);

        if (filter_var($value, FILTER_VALIDATE_URL) === false
            || ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException($name.' must be an HTTP(S) URL');
        }

        return $value;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('--'.$name.' is required');
        }

        return trim($value);
    }

    private function optionOrEnvironment(string $name, string $environment): string
    {
        $value = $this->option($name);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = getenv($environment);
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('--'.$name.' or '.$environment.' is required');
        }

        return trim($value);
    }

    private function positiveIntegerOption(string $name): int
    {
        $value = $this->option($name);

        if (! is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('--'.$name.' must be a positive integer');
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    private function findingForCheck(string $check, array $context): array
    {
        $owner = str_starts_with($check, 'waterline_') ? 'waterline'
            : (str_contains($check, '_cli') ? 'cli'
                : (str_contains($check, 'stale_worker') || str_contains($check, 'transition') ? 'server' : 'sdk-php'));

        return [
            'finding_type' => 'published_projection_mismatch',
            'owning_surface' => $owner,
            'failed_check' => $check,
            'worker_id' => data_get($context, 'topology.stale_worker_id'),
            'peer_worker_id' => data_get($context, 'topology.fresh_worker_id'),
            'task_queue' => data_get($context, 'topology.task_queue'),
            'observed_at' => $this->now(),
            'expected' => $this->expectedForCheck($check),
            'observed_projection' => [
                'server_stale_worker' => data_get($context, 'after.server_stale_detail.body'),
                'server_fresh_worker' => data_get($context, 'after.server_fresh_detail.body'),
                'cli_stale_worker' => $this->cliWorker(data_get($context, 'after.cli_stale_detail.body', [])),
                'cli_fresh_worker' => $this->cliWorker(data_get($context, 'after.cli_fresh_detail.body', [])),
                'waterline_stale_worker' => $this->waterlineWorker(
                    data_get($context, 'after.waterline_health.body', []),
                    (string) data_get($context, 'topology.task_queue', ''),
                    (string) data_get($context, 'topology.stale_worker_id', ''),
                ),
                'waterline_fresh_worker' => $this->waterlineWorker(
                    data_get($context, 'after.waterline_health.body', []),
                    (string) data_get($context, 'topology.task_queue', ''),
                    (string) data_get($context, 'topology.fresh_worker_id', ''),
                ),
                'stale_transition' => $context['stale_transition'] ?? null,
            ],
        ];
    }

    private function expectedForCheck(string $check): string
    {
        return match (true) {
            str_contains($check, 'authoritative_capture') => 'A live HTTP response or executed published CLI process envelope captured by this runner.',
            str_contains($check, 'stale_transition') => 'The crashed worker becomes stale on server and Waterline within the interval measured from its final heartbeat.',
            str_contains($check, 'orderly_worker') => 'The gracefully stopped worker is absent from server, CLI, and Waterline active and stale projections.',
            str_contains($check, 'cannot_claim') => 'A queued workflow task is refused to the stale worker with the typed stale-registration reason.',
            str_contains($check, 'fresh_peer') => 'The fresh peer remains active, visible, and completes work after its peer becomes stale.',
            str_contains($check, 'agree') => 'Waterline list and task-queue detail fields match server API and published CLI observations for the same worker.',
            default => 'The focused published Waterline worker-status contract check passes.',
        };
    }

    private function writeReport(): void
    {
        $this->report['finished_at'] = $this->now();
        $encoded = json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $output = $this->option('output');

        if (is_string($output) && trim($output) !== '') {
            $directory = dirname($output);
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create evidence output directory: '.$directory);
            }
            file_put_contents($output, $encoded);
        }

        $this->line($encoded);
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
