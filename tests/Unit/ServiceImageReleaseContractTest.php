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
        $this->assertStringContainsString('platforms: linux/amd64,linux/arm64', $workflow);
        $this->assertStringContainsString("SERVICE_IMAGE_SKIP_BUILD: '1'", $workflow);
        $this->assertStringContainsString('WATERLINE_IMAGE: durableworkflow/waterline:${{ github.ref_name }}', $workflow);
    }
}
