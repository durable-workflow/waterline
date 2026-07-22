<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waterline\Support\WorkerStatusObservationGate;

final class WorkerStatusConformanceRunnerTest extends TestCase
{
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

    public function testCommandAndPublishedRunnerAcceptOnlyExactCurrent2xPrereleases(): void
    {
        $validator = new \ReflectionMethod(
            \Waterline\Console\WorkerStatusConformanceCommand::class,
            'isExact2xPrerelease',
        );

        foreach ([
            '2.0.0-alpha.1',
            '2.0.0-beta.1',
            '2.0.0-rc.1',
        ] as $version) {
            $this->assertTrue($validator->invoke(null, $version), $version);
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
            $this->assertFalse($validator->invoke(null, $version), $version);
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
        $this->assertStringContainsString('must be an exact 2.0 prerelease', $runtimeSources);
        $this->assertStringNotContainsString('must be an exact 2.0 alpha release', $runtimeSources);
    }

    public function testPublishedWorkerExecutionUsesThePhpSdkPackageBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $runner = (string) file_get_contents($root.'/app/Console/WorkerStatusConformanceCommand.php');
        $worker = (string) file_get_contents($root.'/app/Console/WorkerStatusSdkWorkerCommand.php');

        $this->assertSame('2.0.0-beta.3', $manifest['extra']['durable-workflow']['product-train'] ?? null);
        $this->assertSame('>=2.0.0-beta.3 <2.0.0-beta.4', $manifest['require']['durable-workflow/workflow'] ?? null);
        $this->assertSame('>=2.0.0-beta.3 <2.0.0-beta.4', $manifest['require']['durable-workflow/sdk'] ?? null);
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
