<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use Waterline\Tests\TestCase;

final class ServiceImageReleaseContractTest extends TestCase
{
    public function testImageProvidesSqliteBuildAndRuntimeDependencies(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertMatchesRegularExpression('/apk add --no-cache [^\n]*\bsqlite-libs\b/', $dockerfile);
        $this->assertMatchesRegularExpression('/apk add --no-cache [^\n]*\bsu-exec\b/', $dockerfile);
        $this->assertMatchesRegularExpression('/apk add --no-cache --virtual \.build-deps [^\n]*\bsqlite-dev\b/', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install mbstring pdo_mysql pdo_pgsql pdo_sqlite zip', $dockerfile);
    }

    public function testEntrypointUsesBoundedOfflineInitializationAndDropsPrivileges(): void
    {
        $entrypoint = (string) file_get_contents(dirname(__DIR__, 2).'/standalone/entrypoint.sh');

        $this->assertStringContainsString('WATERLINE_MIGRATION_TIMEOUT_SECONDS', $entrypoint);
        $this->assertStringContainsString('timeout -s TERM -k 5', $entrypoint);
        $this->assertStringContainsString('su-exec www-data php artisan migrate', $entrypoint);
        $this->assertStringContainsString('exec su-exec www-data php -d variables_order=EGPCS -S', $entrypoint);
        $this->assertStringNotContainsString('composer ', strtolower($entrypoint));
        $this->assertStringNotContainsString('apk ', strtolower($entrypoint));
        $this->assertStringNotContainsString('curl ', strtolower($entrypoint));
        $this->assertStringNotContainsString('wget ', strtolower($entrypoint));
    }

    public function testEntrypointRejectsProcessLocalSqliteMemoryDatabase(): void
    {
        $entrypoint = dirname(__DIR__, 2).'/standalone/entrypoint.sh';
        $process = proc_open(
            [$entrypoint],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname($entrypoint),
            [
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'LC_ALL' => 'C',
                'PATH' => (string) getenv('PATH'),
                'WATERLINE_BACKEND' => 'service',
                'WATERLINE_SERVER_ENDPOINT' => 'http://workflow.example.test',
            ],
        );

        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(1, proc_close($process));
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('waterline-service: startup failed:', $stderr);
        $this->assertStringContainsString('DB_DATABASE=:memory:', $stderr);
        $this->assertStringContainsString('file-backed SQLite', $stderr);
    }

    public function testSmokeCoversSelectedRunQueryAndSignalThroughAnIsolatedNetwork(): void
    {
        $smoke = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/ci/service-mode-image-smoke.sh');

        $this->assertStringContainsString('docker network create', $smoke);
        $this->assertStringContainsString('DB_DATABASE=:memory:', $smoke);
        $this->assertStringContainsString('/queries/current', $smoke);
        $this->assertStringContainsString('/signals/approve', $smoke);
        $this->assertStringContainsString('/api/saved-views?bucket=terminated', $smoke);
        $this->assertStringContainsString('WATERLINE_ACCESS_MODE=operator', $smoke);
    }

    public function testPublishedImageBindsReleaseVersionAndSourceCommitLabels(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = (string) file_get_contents($root.'/Dockerfile');
        $workflow = (string) file_get_contents($root.'/.github/workflows/service-image.yml');

        $this->assertStringContainsString('ARG WATERLINE_VERSION=', $dockerfile);
        $this->assertStringContainsString('ARG SOURCE_COMMIT=', $dockerfile);
        $this->assertStringContainsString('org.opencontainers.image.revision="$SOURCE_COMMIT"', $dockerfile);
        $this->assertStringContainsString('dev.durable-workflow.release.tag="$WATERLINE_VERSION"', $dockerfile);
        $this->assertStringContainsString('WATERLINE_VERSION=${{ github.ref_name }}', $workflow);
        $this->assertStringContainsString('SOURCE_COMMIT=${{ steps.source.outputs.commit }}', $workflow);
        $this->assertGreaterThan(
            strpos($dockerfile, 'docker-php-ext-install'),
            strpos($dockerfile, 'ARG WATERLINE_VERSION='),
            'Release-only metadata must not invalidate the expensive dependency layer.',
        );
    }

    public function testPublisherIsOnlyReachableFromAnImmutableVersionTagPush(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/service-image.yml');

        $this->assertStringContainsString("tags: ['2.*']", $workflow);
        $this->assertStringNotContainsString('pull_request:', $workflow);
        $this->assertStringNotContainsString('workflow_dispatch:', $workflow);
        $this->assertStringNotContainsString('branches: [v2]', $workflow);
        $this->assertStringContainsString("if: startsWith(github.ref, 'refs/tags/2.')", $workflow);
        $this->assertStringContainsString('environment: release-plan-publication', $workflow);
        $this->assertStringContainsString('SERVICE_IMAGE_PLATFORMS: linux/amd64,linux/arm64', $workflow);
        $this->assertStringContainsString('platforms: ${{ env.SERVICE_IMAGE_PLATFORMS }}', $workflow);
        $this->assertStringContainsString("SERVICE_IMAGE_SKIP_BUILD: '1'", $workflow);
        $this->assertStringContainsString('WATERLINE_IMAGE: durableworkflow/waterline:${{ github.ref_name }}', $workflow);
    }

    public function testProtectedPublisherSharesRegistryCacheAndRecordsItsBuildBudget(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/service-image.yml');

        $this->assertSame(1, substr_count($workflow, 'SERVICE_IMAGE_CACHE: durableworkflow/waterline:buildcache-v1'));
        $this->assertStringContainsString('docker buildx imagetools inspect "$CACHE_REF"', $workflow);
        $this->assertStringContainsString('cache-from: ${{ steps.cache.outputs.cache_from }}', $workflow);
        $this->assertStringContainsString('cache-to: ${{ steps.cache.outputs.cache_to }}', $workflow);
        $this->assertStringContainsString('mode=max,ignore-error=true', $workflow);
        $this->assertStringContainsString('pull: true', $workflow);
        $this->assertStringContainsString('node scripts/ci/record-service-image-build-timing.mjs', $workflow);
        $this->assertStringContainsString('WARM_CACHE_TARGET_SECONDS: 600', $workflow);
        $this->assertStringContainsString('run: test "$MEETS_REPEAT_BUDGET" = true', $workflow);
        $this->assertStringContainsString('path: service-image-build-evidence.json', $workflow);

        $environment = strpos($workflow, 'environment: release-plan-publication');
        $login = strpos($workflow, 'docker/login-action@');
        $cache = strpos($workflow, 'name: Resolve the protected publication cache');
        $publish = strpos($workflow, 'name: Build and publish immutable version tag');
        $this->assertIsInt($environment);
        $this->assertIsInt($login);
        $this->assertIsInt($cache);
        $this->assertIsInt($publish);
        $this->assertLessThan($login, $environment);
        $this->assertLessThan($cache, $login);
        $this->assertLessThan($publish, $cache);
    }

    public function testBuildTimingEvidenceMeasuresWarmCacheImprovement(): void
    {
        $root = dirname(__DIR__, 2);
        $temporary = tempnam(sys_get_temp_dir(), 'waterline-image-build-');
        $output = tempnam(sys_get_temp_dir(), 'waterline-image-output-');
        $summary = tempnam(sys_get_temp_dir(), 'waterline-image-summary-');

        $this->assertNotFalse($temporary);
        $this->assertNotFalse($output);
        $this->assertNotFalse($summary);

        try {
            $command = sprintf(
                'BUILD_STARTED_AT=100 BUILD_FINISHED_AT=220 BUILD_OUTCOME=success '.
                'CACHE_REF=durableworkflow/waterline:buildcache-v1 CACHE_STATE=warm '.
                'RELEASE_TAG=2.0.0-beta.6 SOURCE_COMMIT=%s '.
                'IMAGE_DIGEST=sha256:%s SERVICE_IMAGE_PLATFORMS=linux/amd64,linux/arm64 '.
                'UNCACHED_BASELINE_FLOOR_SECONDS=600 WARM_CACHE_TARGET_SECONDS=600 '.
                'BUILD_TIMING_EVIDENCE_FILE=%s GITHUB_OUTPUT=%s GITHUB_STEP_SUMMARY=%s '.
                'node %s',
                str_repeat('a', 40),
                str_repeat('b', 64),
                escapeshellarg($temporary),
                escapeshellarg($output),
                escapeshellarg($summary),
                escapeshellarg($root.'/scripts/ci/record-service-image-build-timing.mjs'),
            );

            exec($command, $processOutput, $status);
            $this->assertSame(0, $status, implode("\n", $processOutput));

            $evidence = json_decode(
                (string) file_get_contents($temporary),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame(
                ['linux/amd64', 'linux/arm64'],
                $evidence['platforms'],
            );
            $this->assertSame('protected-tag-publication', $evidence['cache']['write_scope']);
            $this->assertSame(120, $evidence['timing']['build_seconds']);
            $this->assertSame(480, $evidence['timing']['minimum_improvement_seconds']);
            $this->assertSame(80, $evidence['timing']['minimum_improvement_percent']);
            $this->assertTrue($evidence['timing']['meets_repeat_budget']);
            $this->assertStringContainsString('meets_repeat_budget=true', (string) file_get_contents($output));
            $this->assertStringContainsString(
                'Minimum measured improvement: at least 480s (80%)',
                (string) file_get_contents($summary),
            );

            $slowCommand = str_replace('BUILD_FINISHED_AT=220', 'BUILD_FINISHED_AT=701', $command);
            exec($slowCommand, $slowProcessOutput, $slowStatus);
            $this->assertSame(0, $slowStatus, implode("\n", $slowProcessOutput));

            $slowEvidence = json_decode(
                (string) file_get_contents($temporary),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame(601, $slowEvidence['timing']['build_seconds']);
            $this->assertFalse($slowEvidence['timing']['meets_repeat_budget']);
            $this->assertNull($slowEvidence['timing']['minimum_improvement_seconds']);
        } finally {
            @unlink($temporary);
            @unlink($output);
            @unlink($summary);
        }
    }
}
