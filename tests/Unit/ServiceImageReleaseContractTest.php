<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;

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

    public function testImageChecksEffectiveTrustBeforeDownloadingPackages(): void
    {
        $stages = explode("\nFROM ", (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile'));
        $verifier = 'php /usr/local/share/waterline/verify-ca-trust.php';
        $builderCheck = strpos($stages[0], $verifier);
        $builderDownload = strpos($stages[0], 'RUN composer install');
        $runtimeCheck = strpos($stages[1], $verifier);
        $runtimeDownload = strpos($stages[1], 'apk add');
        $runtimeRecheck = strrpos($stages[1], $verifier);

        foreach ([$builderCheck, $builderDownload, $runtimeCheck, $runtimeDownload, $runtimeRecheck] as $position) {
            $this->assertIsInt($position);
        }
        $this->assertLessThan($builderDownload, $builderCheck);
        $this->assertLessThan($runtimeDownload, $runtimeCheck);
        $this->assertGreaterThan($runtimeDownload, $runtimeRecheck);
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
        $this->assertStringContainsString('/api/saved-views?bucket=running', $smoke);
        $this->assertStringContainsString('"workflow_type":"smoke.order"', $smoke);
        $this->assertStringContainsString('/api/flows/running?view=', $smoke);
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
        $this->assertStringContainsString('WATERLINE_VERSION=${{ env.RELEASE_VERSION }}', $workflow);
        $this->assertStringContainsString('SOURCE_COMMIT=${{ steps.source.outputs.commit }}', $workflow);
        $this->assertGreaterThan(
            strpos($dockerfile, 'docker-php-ext-install'),
            strpos($dockerfile, 'ARG WATERLINE_VERSION='),
            'Release-only metadata must not invalidate the expensive dependency layer.',
        );
    }

    public function testCandidateBuildsCannotReachRegistryCredentialsOrCaches(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/service-image.yml');

        $this->assertStringContainsString("tags: ['2.*']", $workflow);
        $this->assertStringNotContainsString('pull_request:', $workflow);
        $this->assertStringNotContainsString('environment:', $workflow);
        $this->assertStringNotContainsString('secrets.', $workflow);
        $this->assertStringNotContainsString('login-action', $workflow);
        $this->assertStringNotContainsString('type=registry', $workflow);
        $this->assertStringContainsString('contents: read', $workflow);
        $this->assertStringContainsString('runner: ubuntu-24.04-arm', $workflow);
        $this->assertStringContainsString('outputs: type=oci,', $workflow);
        $this->assertStringContainsString('push: false', $workflow);
        $this->assertStringContainsString('no-cache: true', $workflow);
        $this->assertStringContainsString('scripts/ci/service-mode-image-smoke.sh', $workflow);
        $this->assertStringContainsString('scripts/ci/oci_release.py native', $workflow);
        $this->assertStringContainsString('compression-level: 0', $workflow);
    }

    public function testProtectedPublisherOnlyPromotesInertArtifactsFromReviewedMain(): void
    {
        $workflow = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/service-image-publish.yml');

        $this->assertStringContainsString('workflow_dispatch:', $workflow);
        $this->assertStringContainsString("if: github.ref == 'refs/heads/main'", $workflow);
        $this->assertStringContainsString('environment: release-publication', $workflow);
        $this->assertStringContainsString('ref: ${{ github.sha }}', $workflow);
        $this->assertStringContainsString('skopeo copy --all --preserve-digests', $workflow);
        $this->assertStringContainsString('docker://docker.io/durableworkflow/waterline:2.13.2', $workflow);
        $this->assertStringNotContainsString('docker/build-push-action', $workflow);
        $this->assertStringNotContainsString('docker run', $workflow);
        $this->assertStringNotContainsString('service-mode-image-smoke.sh', $workflow);
        $this->assertStringNotContainsString('ref: ${{ inputs.source_commit }}', $workflow);
        $this->assertStringContainsString('uses: ./.github/workflows/published-image-qualification.yml', $workflow);
        $verification = strpos($workflow, 'scripts/ci/prepare-oci-publication.sh');
        $login = strpos($workflow, 'name: Log in only after inert evidence verification');
        $this->assertIsInt($verification);
        $this->assertIsInt($login);
        $this->assertLessThan($login, $verification);
        $this->assertStringContainsString('skopeo logout', $workflow);
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
                'BUILD_STARTED_AT=100 BUILD_FINISHED_AT=220 NATIVE_ARM_PREWARM_SECONDS=30 BUILD_OUTCOME=success '.
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
            $this->assertSame(30, $evidence['timing']['native_arm64_prewarm_seconds']);
            $this->assertSame(120, $evidence['timing']['multiarch_publish_seconds']);
            $this->assertSame(150, $evidence['timing']['build_seconds']);
            $this->assertSame(450, $evidence['timing']['minimum_improvement_seconds']);
            $this->assertSame(75, $evidence['timing']['minimum_improvement_percent']);
            $this->assertTrue($evidence['timing']['meets_repeat_budget']);
            $this->assertStringContainsString('meets_repeat_budget=true', (string) file_get_contents($output));
            $this->assertStringContainsString(
                'Minimum measured improvement: at least 450s (75%)',
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
            $this->assertSame(631, $slowEvidence['timing']['build_seconds']);
            $this->assertFalse($slowEvidence['timing']['meets_repeat_budget']);
            $this->assertNull($slowEvidence['timing']['minimum_improvement_seconds']);

            $coldCommand = str_replace('CACHE_STATE=warm', 'CACHE_STATE=cold', $slowCommand);
            exec($coldCommand, $coldProcessOutput, $coldStatus);
            $this->assertSame(0, $coldStatus, implode("\n", $coldProcessOutput));

            $coldEvidence = json_decode(
                (string) file_get_contents($temporary),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $this->assertSame('cold', $coldEvidence['cache']['state']);
            $this->assertSame(631, $coldEvidence['timing']['build_seconds']);
            $this->assertNull($coldEvidence['timing']['meets_repeat_budget']);
            $this->assertNull($coldEvidence['timing']['minimum_improvement_seconds']);
            $this->assertNull($coldEvidence['timing']['minimum_improvement_percent']);
            $this->assertStringContainsString('meets_repeat_budget=not-applicable', (string) file_get_contents($output));

            $missingArmTime = str_replace('NATIVE_ARM_PREWARM_SECONDS=30 ', '', $command);
            exec($missingArmTime.' 2>/dev/null', $missingOutput, $missingStatus);
            $this->assertNotSame(0, $missingStatus, 'Missing arm64 preparation time must not undercount a release build.');
        } finally {
            @unlink($temporary);
            @unlink($output);
            @unlink($summary);
        }
    }
}
