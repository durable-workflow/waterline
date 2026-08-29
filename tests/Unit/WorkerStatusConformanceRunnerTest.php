<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waterline\Support\WorkerStatusObservationGate;

final class WorkerStatusConformanceRunnerTest extends TestCase
{
    public function testExerciseCrashesTheWorkerDesignatedForTheStaleTransition(): void
    {
        $exercise = new \ReflectionMethod(
            \Waterline\Console\WorkerStatusConformanceCommand::class,
            'exercise',
        );
        $source = file($exercise->getFileName());
        $this->assertIsArray($source);
        $exerciseSource = implode('', array_slice(
            $source,
            $exercise->getStartLine() - 1,
            $exercise->getEndLine() - $exercise->getStartLine() + 1,
        ));

        $matched = preg_match(
            <<<'REGEX'
                ~\$staleProcessLoss\s*=\s*\$this->(?<method>\w+)\(\s*\$staleProcess\s*,\s*'stale'[^;]*\);~
                REGEX,
            $exerciseSource,
            $terminationCall,
            PREG_OFFSET_CAPTURE,
        );

        $this->assertSame(1, $matched, 'The stale-transition termination call was not found in exercise().');
        $this->assertSame(
            'crashSdkWorker',
            $terminationCall['method'][0],
            'The exercise() stale-transition path must not invoke graceful Worker cleanup.',
        );

        $waitForStale = strpos($exerciseSource, '$this->waitForStaleTransition(');
        $this->assertNotFalse($waitForStale);
        $this->assertLessThan($waitForStale, $terminationCall[0][1]);
        $this->assertDoesNotMatchRegularExpression(
            '~\$this->stopSdkWorker\(\s*\$staleProcess\b~',
            substr($exerciseSource, 0, $waitForStale),
            'The stale worker must not be gracefully stopped before its stale transition is observed.',
        );
    }

    public function testStaleTransitionUsesAbruptProcessLossWithoutRunningGracefulCleanup(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('PCNTL is required to distinguish SIGKILL from managed SIGTERM cleanup.');
        }

        $cleanupMarker = sys_get_temp_dir().'/waterline-graceful-cleanup-'.bin2hex(random_bytes(8));
        $worker = <<<'PHP'
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use ($argv): void {
                file_put_contents($argv[1], 'managed cleanup ran');
                exit(0);
            });
            fwrite(STDOUT, "ready\n");
            while (true) {
                usleep(100_000);
            }
            PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, $cleanupMarker]);
        $process->setTimeout(null);

        try {
            $process->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($process->getOutput(), 'ready') && microtime(true) < $deadline) {
                $this->assertTrue($process->isRunning(), $process->getErrorOutput());
                usleep(25_000);
            }
            $this->assertStringContainsString('ready', $process->getOutput());

            $crash = new \ReflectionMethod(
                \Waterline\Console\WorkerStatusConformanceCommand::class,
                'crashSdkWorker',
            );
            $event = $crash->invoke(
                new \Waterline\Console\WorkerStatusConformanceCommand(),
                $process,
                'stale',
            );

            $this->assertFalse($process->isRunning());
            $this->assertFileDoesNotExist($cleanupMarker);
            $this->assertSame('abrupt_process_loss', $event['cleanup_mode']);
            $this->assertSame(9, $event['signal']);
            $this->assertTrue($event['process_gone']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            if (is_file($cleanupMarker)) {
                unlink($cleanupMarker);
            }
        }
    }

    public function testStaleDeadlineIsMeasuredFromTheFinalAcceptedHeartbeat(): void
    {
        $timing = new \ReflectionMethod(
            \Waterline\Console\WorkerStatusConformanceCommand::class,
            'staleTransitionTiming',
        );

        $this->assertSame([
            'final_heartbeat_at' => '2026-08-09T02:00:00Z',
            'stale_deadline_at' => '2026-08-09T02:00:07Z',
            'configured_stale_after_seconds' => 7,
            'transition_elapsed_seconds' => 9,
            'seconds_after_stale_deadline' => 2,
            'bounded_min_seconds' => 7,
            'bounded_max_seconds' => 17,
            'within_bounded_window' => true,
        ], $timing->invoke(
            new \Waterline\Console\WorkerStatusConformanceCommand(),
            '2026-08-09T02:00:00Z',
            '2026-08-09T02:00:09Z',
            7,
        ));
    }

    public function testFixtureAndPlanSuppliedProjectionsCannotManufactureAPass(): void
    {
        $matchingBody = [
            'worker_id' => 'worker-a',
            'namespace' => 'default',
            'task_queue' => 'orders',
            'status' => 'active',
        ];

        foreach (['fixture', 'host_plan'] as $kind) {
            $checks = WorkerStatusObservationGate::checks([
                'waterline.after.health' => [
                    'provenance' => [
                        'kind' => $kind,
                        'captured_by' => WorkerStatusObservationGate::CAPTURED_BY,
                    ],
                    'observed_at' => '2026-07-10T12:00:00Z',
                    'url' => 'http://waterline.test/waterline/api/v2/health',
                    'status_code' => 200,
                    'body' => $matchingBody,
                ],
            ]);

            $this->assertSame(
                ['authoritative_capture.waterline.after.health' => false],
                $checks,
                'Matching projection bodies are not authority when supplied by '.$kind.'.',
            );
        }
    }

    public function testOnlyExecutedHttpAndCliCaptureEnvelopesAreAuthoritative(): void
    {
        $checks = WorkerStatusObservationGate::checks([
            'waterline.after.health' => [
                'provenance' => [
                    'kind' => 'live_http',
                    'captured_by' => WorkerStatusObservationGate::CAPTURED_BY,
                ],
                'observed_at' => '2026-07-10T12:00:00Z',
                'url' => 'http://waterline.test/waterline/api/v2/health',
                'status_code' => 200,
                'body' => ['status' => 'ok'],
            ],
            'cli.after.list' => [
                'provenance' => [
                    'kind' => 'live_cli_process',
                    'captured_by' => WorkerStatusObservationGate::CAPTURED_BY,
                ],
                'observed_at' => '2026-07-10T12:00:01Z',
                'command' => ['dw', 'worker:list', '--output=json'],
                'exit_code' => 0,
                'stdout' => '{"workers":[]}',
                'body' => ['workers' => []],
            ],
        ]);

        $this->assertNotContains(false, $checks);
    }

    public function testReleaseRunnerInstallsOnlyExactPublishedProductArtifacts(): void
    {
        $root = dirname(__DIR__, 2);
        $node = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');
        $shell = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.sh');

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_PHP_SDK_VERSION',
            'DW_WORKFLOW_PHP_VERSION',
            'DW_WATERLINE_VERSION',
            'durableworkflow/server:${SERVER_VERSION}',
            'durable-workflow/sdk:${SDK_PHP_VERSION}',
            'durable-workflow/workflow:${WORKFLOW_VERSION}',
            'durable-workflow/waterline:${WATERLINE_VERSION}',
            "'--prefer-dist'",
            'local_product_source_checkouts_used: false',
            'waterline:worker-status-conformance',
            "'down', '-v', '--remove-orphans'",
            "'network', 'ls'",
            'waitForHttpReadiness',
            'createInterruptionMonitor',
        ] as $needle) {
            $this->assertStringContainsString($needle, $node.$shell);
        }

        $this->assertStringNotContainsString('DW_WATERLINE_WORKER_STATUS_PLAN', $node);
        $this->assertStringNotContainsString('DW_SDK_PHP_VERSION', $node.$shell);
        $this->assertStringNotContainsString('REPO_ROOT', $node);
        $this->assertStringNotContainsString("'type': 'path'", $node);
        $this->assertStringNotContainsString('fixture_response', $node);
    }

    public function testPinnedCliInstallerPrefersThePrivateExactBinaryOverAnAmbientOlderDw(): void
    {
        $root = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');
        $testDirectory = sys_get_temp_dir().'/waterline-cli-install-path-'.bin2hex(random_bytes(6));
        $ambientBin = $testDirectory.'/ambient-bin';
        $privateBin = $testDirectory.'/private-bin';
        mkdir($ambientBin, 0777, true);
        mkdir($privateBin, 0777, true);

        $ambientDw = $ambientBin.'/dw';
        $exactDw = $testDirectory.'/exact-dw';
        $installedDw = $privateBin.'/dw';
        $installer = $testDirectory.'/install.sh';
        $beforePath = $testDirectory.'/before-path';
        $afterPath = $testDirectory.'/after-path';
        file_put_contents($ambientDw, "#!/bin/sh\nprintf '%s\\n' 'dw 2.0.0-rc.35'\n");
        file_put_contents($exactDw, "#!/bin/sh\nprintf '%s\\n' 'dw 2.0.0-rc.36'\n");
        file_put_contents($installer, <<<'SH'
#!/bin/sh
set -eu
command -v dw > "$BEFORE_PATH"
cp "$EXACT_DW" "$DURABLE_WORKFLOW_INSTALL_DIR/dw"
chmod +x "$DURABLE_WORKFLOW_INSTALL_DIR/dw"
hash -r 2>/dev/null || :
command -v dw > "$AFTER_PATH"
test "$(cat "$AFTER_PATH")" = "$DURABLE_WORKFLOW_INSTALL_DIR/dw"
SH);
        chmod($ambientDw, 0755);
        chmod($exactDw, 0755);
        chmod($installer, 0755);

        try {
            $this->assertMatchesRegularExpression(
                <<<'REGEX'
~const binDir = path\.join\(CLI_DIR, 'bin'\);.*?env: \{.*?PATH: \[binDir, process\.env\.PATH \?\? ''\]\.filter\(Boolean\)\.join\(path\.delimiter\),.*?DURABLE_WORKFLOW_INSTALL_DIR: binDir,.*?const binary = path\.join\(binDir,~s
REGEX,
                $runner,
                'The runner must give its private install directory precedence for the installer subprocess.',
            );
            $this->assertStringContainsString("run(binary, ['--version']", $runner);
            $this->assertStringContainsString('if (installedVersion !== CLI_VERSION)', $runner);

            $process = new Process(['/bin/sh', $installer], $root, [
                'AFTER_PATH' => $afterPath,
                'BEFORE_PATH' => $beforePath,
                'DURABLE_WORKFLOW_INSTALL_DIR' => $privateBin,
                'EXACT_DW' => $exactDw,
                'PATH' => implode(PATH_SEPARATOR, [
                    $privateBin,
                    $ambientBin,
                    '/usr/local/bin',
                    '/usr/bin',
                    '/bin',
                ]),
            ]);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($ambientDw, trim((string) file_get_contents($beforePath)));
            $this->assertSame($installedDw, trim((string) file_get_contents($afterPath)));

            $ambientVersion = new Process([$ambientDw, '--version']);
            $ambientVersion->mustRun();
            $installedVersion = new Process([$installedDw, '--version']);
            $installedVersion->mustRun();
            $this->assertSame('dw 2.0.0-rc.35', trim($ambientVersion->getOutput()));
            $this->assertSame('dw 2.0.0-rc.36', trim($installedVersion->getOutput()));
        } finally {
            $this->removeTestDirectory($testDirectory);
        }
    }

    public function testReleaseRunnerCanJoinTheVerifiedSharedHeartbeatWave(): void
    {
        $root = dirname(__DIR__, 2);
        $node = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');
        $sharedTopology = (string) file_get_contents(
            $root.'/scripts/conformance/worker-status-shared-topology.mjs',
        );
        $command = (string) file_get_contents($root.'/app/Console/WorkerStatusConformanceCommand.php');
        $shell = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.sh');
        $nodeBinary = trim((string) shell_exec('command -v node 2>/dev/null'));
        $this->assertNotSame('', $nodeBinary, 'Node is required to execute the shared-topology regression.');
        exec(sprintf(
            '%s --test %s 2>&1',
            escapeshellarg($nodeBinary),
            escapeshellarg($root.'/tests/Unit/WorkerStatusSharedTopologyTest.mjs'),
        ), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString(
            "const SHARED_SERVER_STATE_FILE = env('DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE');",
            $node,
        );
        $this->assertStringContainsString('if (USE_SHARED_SERVER) return attachSharedServer();', $node);
        $this->assertStringContainsString(
            "state?.clean_bootstrap?.migrations_completed !== true",
            $sharedTopology,
        );
        $this->assertStringContainsString(
            "state?.lifecycle?.cleanup_status !== 'pending'",
            $sharedTopology,
        );
        $this->assertStringContainsString('running shared server no longer matches', $node);
        $this->assertStringContainsString(
            "command === 'docker'",
            $node,
        );
        $this->assertStringContainsString(
            "args[0] === 'run'",
            $node,
        );
        $this->assertStringContainsString(
            "'--network', sharedServerNetwork",
            $node,
        );
        $this->assertStringContainsString(
            'sharedServerNetwork = state.compose.network;',
            $node,
        );
        $this->assertStringContainsString("mode: 'shared_wave_clean_bootstrap'", $node);
        $this->assertStringContainsString("mode: 'focused_cell_clean_bootstrap'", $node);
        $this->assertStringContainsString("status: 'retained_for_wave_cleanup'", $node);
        $this->assertStringContainsString('actual_stale_worker_id:', $sharedTopology);
        $this->assertStringContainsString('actual_fresh_worker_id:', $sharedTopology);
        $this->assertStringContainsString('worker_id_prefix_binding_proven:', $sharedTopology);
        $this->assertStringNotContainsString('--worker-id-prefix', $node.$command);
        $this->assertStringContainsString("'waterline-stale-'.strtolower(\$suffix)", $command);
        $this->assertStringContainsString("'waterline-fresh-'.strtolower(\$suffix)", $command);
        $this->assertStringContainsString(
            'DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE',
            $shell,
        );
    }

    public function testCommandAndPublishedRunnerEnforceServerAndPackageVersionContracts(): void
    {
        $serverValidator = new \ReflectionMethod(
            \Waterline\Console\WorkerStatusConformanceCommand::class,
            'isExactSemverRelease',
        );
        $packageValidator = new \ReflectionMethod(
            \Waterline\Console\WorkerStatusConformanceCommand::class,
            'isExact2xPrerelease',
        );

        foreach ([
            '2.0.0-alpha.1',
            '2.0.0-beta.1',
            '2.0.0-rc.5',
            '2.0.0',
            '1.13.4',
        ] as $version) {
            $this->assertTrue($serverValidator->invoke(null, $version), $version);
        }

        foreach ([
            '',
            '*',
            '^2.0.0-beta.1',
            '2.0.x-dev',
            'dev-v2',
            'latest',
            '2.0.0-latest',
            '2.0.0-snapshot.4',
            '2.0',
            '2.0.x',
            '2.0.0-beta.01',
            '2.0.0-beta..1',
            'v2.0.0-beta.1',
            '2.0.0-beta.1 || 2.0.0',
        ] as $version) {
            $this->assertFalse($serverValidator->invoke(null, $version), $version);
        }

        foreach ([
            '2.0.0-alpha.1',
            '2.0.0-beta.1',
            '2.0.0-rc.5',
        ] as $version) {
            $this->assertTrue($packageValidator->invoke(null, $version), $version);
        }

        foreach ([
            '',
            '*',
            '^2.0.0-beta.1',
            '2.0.x-dev',
            'dev-v2',
            '2.0.0',
            '2.0.1-beta.1',
            '1.0.0-rc.1',
            '2.0.0-preview.1',
            '2.0.0-beta',
            '2.0.0-beta.01',
            '2.0.0-beta.1 || 2.0.0',
        ] as $version) {
            $this->assertFalse($packageValidator->invoke(null, $version), $version);
        }

        $root = dirname(__DIR__, 2);
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        $this->assertNotSame('', $node, 'Node is required to validate published runner version pins.');
        exec(sprintf(
            '%s --test %s 2>&1',
            escapeshellarg($node),
            escapeshellarg($root.'/tests/Unit/WorkerStatusVersionTest.mjs'),
        ), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));

        $runtimeSources = (string) file_get_contents($root.'/app/Console/WorkerStatusConformanceCommand.php')
            .(string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');
        $this->assertStringContainsString('must be an exact SemVer release', $runtimeSources);
        $this->assertStringContainsString('must be an exact 2.0 prerelease', $runtimeSources);
        $this->assertStringNotContainsString('must be an exact 2.0 alpha release', $runtimeSources);
    }

    public function testPublishedWorkerExecutionUsesThePhpSdkPackageBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $runner = (string) file_get_contents($root.'/app/Console/WorkerStatusConformanceCommand.php');
        $worker = (string) file_get_contents($root.'/app/Console/WorkerStatusSdkWorkerCommand.php');

        $this->assertSame('2.0.0-rc.33', $manifest['extra']['durable-workflow']['product-train'] ?? null);
        $this->assertSame('2.0.0-rc.52', $manifest['require-dev']['durable-workflow/workflow'] ?? null);
        $this->assertSame('2.0.0-rc.53', $manifest['require-dev']['durable-workflow/sdk'] ?? null);
        $this->assertStringContainsString('use DurableWorkflow\\Client as SdkClient;', $runner);
        $this->assertStringContainsString('use DurableWorkflow\\SdkIdentity;', $runner);
        $this->assertStringContainsString('SdkIdentity::registration()', $runner);
        $this->assertStringContainsString("'php_sdk_contract' =>", $runner);
        $this->assertStringNotContainsString('SdkVersion::SDK', $runner);
        $this->assertStringContainsString('use DurableWorkflow\\Worker;', $worker);
        $this->assertStringContainsString('$worker->run(', $worker);
        $this->assertStringContainsString("'heartbeat_loop_implementation_owner' => 'durable-workflow/sdk'", $runner);
        $this->assertStringNotContainsString('heartbeatWorker(', $runner.$worker);
        $this->assertStringNotContainsString('registerWorker(', $runner.$worker);
        $this->assertStringNotContainsString('Workflow\\V2\\Worker\\', $runner.$worker);
        $this->assertStringNotContainsString('class_alias', $runner.$worker);

        $sdkVersion = \DurableWorkflow\SdkIdentity::version();
        $sdkSource = 'packagist://durable-workflow/sdk@'.$sdkVersion;
        $contract = new \ReflectionMethod(
            \Waterline\Console\WorkerStatusConformanceCommand::class,
            'phpSdkContract',
        );

        $this->assertSame([
            'package' => 'durable-workflow/sdk',
            'installed_version' => $sdkVersion,
            'registration_identity' => 'durable-workflow-php/'.$sdkVersion,
            'worker_protocol_version' => \DurableWorkflow\Version::WORKER_PROTOCOL,
            'artifact_version' => $sdkVersion,
            'artifact_source' => $sdkSource,
        ], $contract->invoke(new \Waterline\Console\WorkerStatusConformanceCommand(), $sdkVersion, $sdkSource));
    }

    public function testComposerImagePhpCannotSilentlySelectDependenciesForAnotherServerRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $node = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');

        $serverDetection = strpos($node, "detectPhpRuntime(SERVER_IMAGE, 'the exact published server image')");
        $globalPlatformPin = strpos($node, "composer(['config', '--global', 'platform.php', serverPhpVersion]");
        $skeletonDownload = strpos($node, "composer(['create-project', '--no-install', '--no-scripts'");
        $platformPin = strpos($node, "composer(['config', 'platform.php', serverPhpVersion]");
        $productRequirement = strpos($node, "'require', '--no-update', '--no-interaction'");
        $dependencyResolution = strpos($node, "'update', '--no-interaction', '--no-progress', '--prefer-dist'");
        $serverBoot = strpos($node, 'verifyInstalledAppBoot(server.phpVersion)');
        $publishedCommand = strpos($node, 'runPublishedCommand(server, waterline);');

        foreach (compact(
            'serverDetection',
            'globalPlatformPin',
            'skeletonDownload',
            'platformPin',
            'productRequirement',
            'dependencyResolution',
            'serverBoot',
            'publishedCommand',
        ) as $step => $position) {
            $this->assertNotFalse($position, sprintf('Missing runtime compatibility step: %s.', $step));
        }

        $this->assertLessThan($globalPlatformPin, $serverDetection);
        $this->assertLessThan($skeletonDownload, $globalPlatformPin);
        $this->assertLessThan($platformPin, $skeletonDownload);
        $this->assertLessThan($productRequirement, $platformPin);
        $this->assertLessThan($dependencyResolution, $productRequirement);
        $this->assertLessThan($publishedCommand, $serverBoot);
        $this->assertStringContainsString('configuredGlobalPlatformPhp !== serverPhpVersion', $node);
        $this->assertStringContainsString('configuredPlatformPhp !== serverPhpVersion', $node);
        $this->assertStringNotContainsString(
            "composer(['create-project', '--no-install', '--no-interaction'",
            $node,
            'Laravel project hooks must not run before vendor dependencies have been installed.',
        );
        $this->assertStringContainsString("'artisan', '--version'", $node);
        $this->assertStringContainsString('composer_detected_php', $node);
        $this->assertStringContainsString('composer_platform_configured_php', $node);
    }

    public function testPublishedWaterlineHostUsesTheValidatedExternalGatewayWithoutLeakingItIntoContainerCommands(): void
    {
        $root = dirname(__DIR__, 2);
        $runner = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');

        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        $this->assertNotSame('', $node, 'Node is required to execute the published-artifact runner regression.');
        $command = sprintf(
            '%s --test %s 2>&1',
            escapeshellarg($node),
            escapeshellarg($root.'/tests/Unit/WorkerStatusNetworkTest.mjs'),
        );
        exec($command, $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));

        $this->assertStringContainsString('waterlineEnvironment(topology.appUrl)', $runner);
        $this->assertStringContainsString('url: `${topology.externalHostUrl}/waterline/api/v2/health`', $runner);
        $this->assertStringContainsString('networkUrl: topology.containerNetworkUrl', $runner);
        $this->assertStringContainsString('waterlineEnvironment(waterline.networkUrl)', $runner);
        $this->assertStringContainsString('`--waterline-url=${waterline.networkUrl}`', $runner);
    }

    public function testReleaseArchiveDoesNotExcludeTheFocusedRunner(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            'scripts/conformance/worker-status-published-artifacts.sh',
            'scripts/conformance/worker-status-published-artifacts.mjs',
            'scripts/conformance/worker-status-network.mjs',
            'scripts/conformance/worker-status-runner-lifecycle.mjs',
            'scripts/conformance/worker-status-shared-isolation.mjs',
            'scripts/conformance/worker-status-shared-topology.mjs',
            'scripts/conformance/worker-status-version.mjs',
            'app/Console/WorkerStatusConformanceCommand.php',
            'app/Console/WorkerStatusSdkWorkerCommand.php',
        ];

        foreach ($paths as $path) {
            $this->assertFileExists($root.'/'.$path);
            $output = shell_exec(sprintf(
                'cd %s && git check-attr export-ignore -- %s 2>/dev/null',
                escapeshellarg($root),
                escapeshellarg($path),
            ));
            $this->assertStringContainsString('export-ignore: unspecified', (string) $output);
        }
    }

    public function testReadinessTimeoutsRetryAndReachDeterministicCleanup(): void
    {
        $root = dirname(__DIR__, 2);
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        $this->assertNotSame('', $node, 'Node is required to execute the runner lifecycle regression.');
        $command = sprintf(
            '%s --test %s 2>&1',
            escapeshellarg($node),
            escapeshellarg($root.'/tests/Unit/WorkerStatusRunnerLifecycleTest.mjs'),
        );
        exec($command, $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testShellFallbackWritesFreshEvidenceForArgumentFailures(): void
    {
        $root = dirname(__DIR__, 2);
        $resultDirectory = sys_get_temp_dir().'/waterline-worker-status-arguments-'.bin2hex(random_bytes(6));
        mkdir($resultDirectory, 0777, true);
        $resultPath = $resultDirectory.'/waterline-worker-status-result.json';
        $runnerLogPath = $resultDirectory.'/waterline-worker-status-runner.log';
        $staleEvidencePath = $resultDirectory.'/waterline-worker-status-evidence.json';
        $staleHygienePath = $resultDirectory.'/source-hygiene.json';
        $staleDiagnostic = 'stale diagnostic from a previous invocation';
        file_put_contents($resultPath, '{"artifact_versions":{"server":"stale"}}');
        file_put_contents($runnerLogPath, $staleDiagnostic);
        file_put_contents($staleEvidencePath, '{"outcome":"pass","runner_blocked":false}');
        file_put_contents($staleHygienePath, '{"passed":true}');

        try {
            [$status, $output] = $this->runShellRunner(
                $root,
                $resultDirectory,
                ['--unknown-argument'],
            );

            $this->assertSame(2, $status, implode("\n", $output));
            $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('durable-workflow.v2.waterline-worker-status-run-result', $result['schema']);
            $this->assertSame('0.2.626', $result['artifact_versions']['server']);
            $this->assertSame('0.1.86', $result['artifact_versions']['cli']);
            $this->assertSame('0.1.2', $result['artifact_versions']['sdk-php']);
            $this->assertSame('2.0.0-alpha.259', $result['artifact_versions']['workflow']);
            $this->assertSame('2.0.0-alpha.128', $result['artifact_versions']['waterline']);
            $this->assertSame(
                'packagist://durable-workflow/sdk@0.1.2',
                $result['artifact_sources']['sdk-php'],
            );
            $this->assertSame(2, $result['runner_error']['exit_code']);
            $this->assertSame('shell_exit_fallback', $result['runner_error']['evidence_origin']);
            $this->assertStringNotContainsString($staleDiagnostic, $result['runner_error']['message']);
            $this->assertFileDoesNotExist($runnerLogPath);
            $this->assertFileDoesNotExist($staleEvidencePath);
            $this->assertFileDoesNotExist($staleHygienePath);
            $this->assertTrue($result['runner_blocked']);
        } finally {
            $this->removeTestDirectory($resultDirectory);
        }
    }

    public function testShellFallbackEscapesAndBoundsDiagnosticsWhileReplacingInvalidEvidence(): void
    {
        $root = dirname(__DIR__, 2);
        $realNode = trim((string) shell_exec('command -v node 2>/dev/null'));
        $this->assertNotSame('', $realNode, 'Node is required to validate current runner evidence.');

        $testDirectory = sys_get_temp_dir().'/waterline-worker-status-fallback-'.bin2hex(random_bytes(6));
        $resultDirectory = $testDirectory.'/results';
        $fakeBin = $testDirectory.'/bin';
        mkdir($resultDirectory, 0777, true);
        mkdir($fakeBin, 0777, true);

        $diagnosticPath = $testDirectory.'/diagnostic.log';
        $diagnostic = str_repeat('x', 2100)
            ."\nquoted \"diagnostic\" at C:\\runner\\work"
            ."\nsecond line\n";
        file_put_contents($diagnosticPath, $diagnostic);

        $fakeNode = $fakeBin.'/node';
        $this->writeFakeNode($fakeNode);

        try {
            foreach ([
                '{"artifact_versions":{"server":"stale"}}',
                '{malformed pre-existing evidence',
            ] as $preExistingResult) {
                $resultPath = $resultDirectory.'/waterline-worker-status-result.json';
                file_put_contents($resultPath, $preExistingResult);

                [$status, $output] = $this->runShellRunner(
                    $root,
                    $resultDirectory,
                    [],
                    [
                        'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
                        'REAL_NODE' => $realNode,
                        'FAKE_DIAGNOSTIC' => $diagnosticPath,
                    ],
                );

                $this->assertSame(13, $status, implode("\n", $output));
                $rawResult = (string) file_get_contents($resultPath);
                $result = json_decode($rawResult, true, 512, JSON_THROW_ON_ERROR);
                $decodedDiagnostic = $result['runner_error']['message'];

                $this->assertSame('0.2.626', $result['artifact_versions']['server']);
                $this->assertSame('0.1.86', $result['artifact_versions']['cli']);
                $this->assertSame('0.1.2', $result['artifact_versions']['sdk-php']);
                $this->assertSame('2.0.0-alpha.259', $result['artifact_versions']['workflow']);
                $this->assertSame('2.0.0-alpha.128', $result['artifact_versions']['waterline']);
                $this->assertSame(
                    'packagist://durable-workflow/sdk@0.1.2',
                    $result['artifact_sources']['sdk-php'],
                );
                $this->assertSame(13, $result['runner_error']['exit_code']);
                $this->assertStringContainsString(
                    "quoted \"diagnostic\" at C:\\runner\\work\nsecond line",
                    $decodedDiagnostic,
                );
                $this->assertLessThanOrEqual(2000, strlen($decodedDiagnostic));
            }

            [$status, $output] = $this->runShellRunner(
                $root,
                $resultDirectory,
                [],
                [
                    'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
                    'REAL_NODE' => $realNode,
                    'FAKE_DIAGNOSTIC' => $diagnosticPath,
                    'FAKE_VALID_RESULT' => '1',
                ],
            );
            $this->assertSame(17, $status, implode("\n", $output));
            $currentResult = json_decode(
                (string) file_get_contents($resultDirectory.'/waterline-worker-status-result.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            $this->assertSame('current-node-result', $currentResult['sentinel']);
            $this->assertArrayNotHasKey('runner_error', $currentResult);
        } finally {
            $this->removeTestDirectory($testDirectory);
        }
    }

    public function testShellFallbackTruncatesUnicodeDiagnosticsOnAValidUtf8Boundary(): void
    {
        $root = dirname(__DIR__, 2);
        $realNode = trim((string) shell_exec('command -v node 2>/dev/null'));
        $this->assertNotSame('', $realNode, 'Node is required to validate current runner evidence.');

        $testDirectory = sys_get_temp_dir().'/waterline-worker-status-unicode-'.bin2hex(random_bytes(6));
        $resultDirectory = $testDirectory.'/results';
        $fakeBin = $testDirectory.'/bin';
        mkdir($resultDirectory, 0777, true);
        mkdir($fakeBin, 0777, true);

        $diagnosticPath = $testDirectory.'/diagnostic.log';
        file_put_contents($diagnosticPath, str_repeat('🙂', 600).'x');
        $this->writeFakeNode($fakeBin.'/node');

        try {
            [$status, $output] = $this->runShellRunner(
                $root,
                $resultDirectory,
                [],
                [
                    'PATH' => $fakeBin.PATH_SEPARATOR.getenv('PATH'),
                    'REAL_NODE' => $realNode,
                    'FAKE_DIAGNOSTIC' => $diagnosticPath,
                ],
            );

            $this->assertSame(13, $status, implode("\n", $output));
            $rawResult = (string) file_get_contents(
                $resultDirectory.'/waterline-worker-status-result.json',
            );
            $result = json_decode($rawResult, true, 512, JSON_THROW_ON_ERROR);
            $decodedDiagnostic = $result['runner_error']['message'];

            $this->assertSame(13, $result['runner_error']['exit_code']);
            $this->assertStringContainsString('🙂', $decodedDiagnostic);
            $this->assertStringEndsWith('x', $decodedDiagnostic);
            $this->assertLessThanOrEqual(2000, strlen($decodedDiagnostic));
        } finally {
            $this->removeTestDirectory($testDirectory);
        }
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @return array{int, list<string>}
     */
    private function runShellRunner(
        string $root,
        string $resultDirectory,
        array $arguments,
        array $environment = [],
    ): array {
        $environment += [
            'DW_SERVER_VERSION' => '0.2.626',
            'DW_CLI_VERSION' => '0.1.86',
            'DW_PHP_SDK_VERSION' => '0.1.2',
            'DW_WORKFLOW_PHP_VERSION' => '2.0.0-alpha.259',
            'DW_WATERLINE_VERSION' => '2.0.0-alpha.128',
        ];
        $assignments = [];
        foreach ($environment as $name => $value) {
            $assignments[] = $name.'='.escapeshellarg($value);
        }

        $command = implode(' ', $assignments)
            .' bash '.escapeshellarg($root.'/scripts/conformance/worker-status-published-artifacts.sh')
            .' --result-dir '.escapeshellarg($resultDirectory);
        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg($argument);
        }

        $output = [];
        exec($command.' 2>&1', $output, $status);

        return [$status, $output];
    }

    private function writeFakeNode(string $path): void
    {
        file_put_contents($path, <<<'BASH'
#!/usr/bin/env bash
if [[ "${1:-}" == "-e" ]]; then
  exec "$REAL_NODE" "$@"
fi
if [[ "${FAKE_VALID_RESULT:-0}" == "1" ]]; then
  printf '%s' \
    '{"schema":"durable-workflow.v2.waterline-worker-status-run-result","sentinel":"current-node-result"}' \
    >"$RESULT_DIR/waterline-worker-status-result.json"
  exit 17
fi
cp -- "$FAKE_DIAGNOSTIC" "$RESULT_DIR/waterline-worker-status-runner.log"
printf '%s' '{"schema":' >"$RESULT_DIR/waterline-worker-status-result.json"
exit 13
BASH);
        chmod($path, 0755);
    }

    private function removeTestDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
