<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    private const PACKAGE_MANIFEST = __DIR__.'/../../public/mix-manifest.json';

    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishMatchingAssets();
    }

    private function publishedManifestPath(): string
    {
        return public_path('vendor/waterline/mix-manifest.json');
    }

    private function publishMatchingAssets(): void
    {
        $path = public_path('vendor/waterline');

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        copy(self::PACKAGE_MANIFEST, $this->publishedManifestPath());
    }

    private function publishStaleAssets(): void
    {
        $path = public_path('vendor/waterline');

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($this->publishedManifestPath(), json_encode([
            '/app.css' => '/app.css?id=stale',
            '/app-dark.css' => '/app-dark.css?id=stale',
            '/app.js' => '/app.js?id=stale',
        ], JSON_THROW_ON_ERROR));
    }

    public function testDashboardDoesNotRenderEnvironmentStripWithoutConfiguredName(): void
    {
        config()->set('waterline.env_name', null);

        $this->get('/waterline')
            ->assertOk()
            ->assertDontSee('environment-strip', false);
    }

    public function testDashboardRendersConfiguredEnvironmentStrip(): void
    {
        config()->set('waterline.env_name', 'Production');
        config()->set('waterline.env_color', '#dc3545');

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('environment-strip', false)
            ->assertSee('environment-strip--red', false)
            ->assertDontSee('style=', false)
            ->assertSee('Production');
    }

    public function testDashboardRendersManagedBlueEnvironmentStripWithABoundedClass(): void
    {
        config()->set('waterline.env_name', 'Managed Waterline');
        config()->set('waterline.env_color', '#2563eb');

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('environment-strip--blue', false)
            ->assertDontSee('style=', false)
            ->assertSee('Managed Waterline');
    }

    public function testDashboardRendersConfiguredOperatorNamespaceScope(): void
    {
        config()->set('waterline.namespace', 'billing');

        $content = $this->get('/waterline')
            ->assertOk()
            ->getContent();

        $this->assertSame('namespace', $this->bootstrapConfig($content)['operator_scope']['mode']);
        $this->assertSame('billing', $this->bootstrapConfig($content)['operator_scope']['namespace']);
    }

    public function testDashboardRendersClusterWideOperatorScopeWhenNamespaceIsUnset(): void
    {
        config()->set('waterline.namespace', null);

        $content = $this->get('/waterline')
            ->assertOk()
            ->getContent();

        $this->assertSame('cluster', $this->bootstrapConfig($content)['operator_scope']['mode']);
        $this->assertNull($this->bootstrapConfig($content)['operator_scope']['namespace']);
    }

    public function testDashboardFallsBackWhenEnvironmentStripColorIsUnsafe(): void
    {
        config()->set('waterline.env_name', 'Staging');
        config()->set('waterline.env_color', 'red; background: url(https://example.test)');

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('environment-strip--neutral', false)
            ->assertDontSee('style=', false)
            ->assertDontSee('red; background', false);
    }

    public function testDashboardRendersBootstrapAsEscapedNonExecutableData(): void
    {
        config()->set('waterline.path', 'waterline"></div><script>alert("unsafe")</script>');

        $content = $this->get('/waterline')
            ->assertOk()
            ->assertDontSee('window.Waterline', false)
            ->assertDontSee('fonts.bunny.net', false)
            ->assertDontSee('<script>alert("unsafe")</script>', false)
            ->getContent();

        $this->assertIsString($content);
        $bootstrap = $this->bootstrapConfig($content);

        $this->assertSame('waterline"></div><script>alert("unsafe")</script>', $bootstrap['path']);
        $this->assertIsArray($bootstrap['operator_scope']);
        $this->assertIsArray($bootstrap['backend']);
    }

    private function bootstrapConfig(string|false $content): array
    {
        $this->assertIsString($content);
        $this->assertMatchesRegularExpression('/data-waterline-config="[^"]+"/', $content);

        preg_match('/data-waterline-config="([^"]+)"/', $content, $matches);

        return json_decode(
            html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function testDashboardHidesStaleAssetWarningWhenPublishedAssetsMatchPackage(): void
    {
        // setUp() already published a manifest that matches the package's manifest.

        $content = $this->get('/waterline')
            ->assertOk()
            ->getContent();

        $this->assertTrue($this->bootstrapConfig($content)['assets_current']);
    }

    public function testDashboardLoadsEveryFrontendEntryFromThePublishedManifest(): void
    {
        $manifest = json_decode((string) file_get_contents(self::PACKAGE_MANIFEST), true, flags: JSON_THROW_ON_ERROR);

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('href="'.asset('vendor/waterline/'.ltrim($manifest['/app-dark.css'], '/')).'"', false)
            ->assertSee('href="'.asset('vendor/waterline/'.ltrim($manifest['/components.css'], '/')).'"', false)
            ->assertSee('type="module" src="'.asset('vendor/waterline/'.ltrim($manifest['/app.js'], '/')).'"', false);
    }

    public function testDashboardSurfacesStaleAssetWarningWhenPublishedAssetsDriftFromPackage(): void
    {
        $this->publishStaleAssets();

        $content = $this->get('/waterline')
            ->assertOk()
            ->getContent();

        $this->assertFalse($this->bootstrapConfig($content)['assets_current']);
    }

    public function testDashboardSurfacesStaleAssetWarningWhenPublishedManifestIsMissing(): void
    {
        @unlink($this->publishedManifestPath());

        $content = $this->get('/waterline')
            ->assertOk()
            ->assertSee('vendor/waterline/app-dark.css', false)
            ->assertSee('vendor/waterline/components.css', false)
            ->assertSee('vendor/waterline/app.js', false)
            ->getContent();

        $this->assertFalse($this->bootstrapConfig($content)['assets_current']);
    }
}
