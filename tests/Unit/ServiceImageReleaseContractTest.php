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
        $this->assertMatchesRegularExpression('/apk add --no-cache --virtual \.build-deps [^\n]*\bsqlite-dev\b/', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-install mbstring pdo_mysql pdo_pgsql pdo_sqlite zip', $dockerfile);
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
