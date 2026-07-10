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

        $this->assertNotContains(false, $checks, true);
    }

    public function testReleaseRunnerInstallsOnlyExactPublishedProductArtifacts(): void
    {
        $root = dirname(__DIR__, 2);
        $node = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.mjs');
        $shell = (string) file_get_contents($root.'/scripts/conformance/worker-status-published-artifacts.sh');

        foreach ([
            'DW_SERVER_VERSION',
            'DW_CLI_VERSION',
            'DW_WORKFLOW_PHP_VERSION',
            'DW_WATERLINE_VERSION',
            'durableworkflow/server:${SERVER_VERSION}',
            'durable-workflow/workflow:${WORKFLOW_VERSION}',
            'durable-workflow/waterline:${WATERLINE_VERSION}',
            "'--prefer-dist'",
            'local_product_source_checkouts_used: false',
            'waterline:worker-status-conformance',
            "'down', '-v', '--remove-orphans'",
        ] as $needle) {
            $this->assertStringContainsString($needle, $node.$shell);
        }

        $this->assertStringNotContainsString('DW_WATERLINE_WORKER_STATUS_PLAN', $node);
        $this->assertStringNotContainsString('REPO_ROOT', $node);
        $this->assertStringNotContainsString("'type': 'path'", $node);
        $this->assertStringNotContainsString('fixture_response', $node);
    }

    public function testReleaseArchiveDoesNotExcludeTheFocusedRunner(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [
            'scripts/conformance/worker-status-published-artifacts.sh',
            'scripts/conformance/worker-status-published-artifacts.mjs',
            'app/Console/WorkerStatusConformanceCommand.php',
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
}
