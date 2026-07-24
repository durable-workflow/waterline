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
            ->assertSee('--waterline-env-color: #dc3545;', false)
            ->assertSee('Production');
    }

    public function testDashboardRendersConfiguredOperatorNamespaceScope(): void
    {
        config()->set('waterline.namespace', 'billing');

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('wl-topbar__scope', false)
            ->assertSee('Scope')
            ->assertSee('billing')
            ->assertSee('"operator_scope":{"mode":"namespace","namespace":"billing"', false);
    }

    public function testDashboardRendersClusterWideOperatorScopeWhenNamespaceIsUnset(): void
    {
        config()->set('waterline.namespace', null);

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('wl-topbar__scope', false)
            ->assertSee('Cluster-wide')
            ->assertSee('Cluster-wide Waterline scope can observe all namespaces', false)
            ->assertSee('"operator_scope":{"mode":"cluster","namespace":null', false);
    }

    public function testDashboardFallsBackWhenEnvironmentStripColorIsUnsafe(): void
    {
        config()->set('waterline.env_name', 'Staging');
        config()->set('waterline.env_color', 'red; background: url(https://example.test)');

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('--waterline-env-color: #6c757d;', false)
            ->assertDontSee('red; background', false);
    }

    public function testDashboardHidesStaleAssetWarningWhenPublishedAssetsMatchPackage(): void
    {
        // setUp() already published a manifest that matches the package's manifest.

        $this->get('/waterline')
            ->assertOk()
            ->assertDontSee('php artisan waterline:publish', false)
            ->assertDontSee('not up-to-date');
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

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('alert-warning', false)
            ->assertSee('not up-to-date')
            ->assertSee('php artisan waterline:publish', false);
    }

    public function testDashboardSurfacesStaleAssetWarningWhenPublishedManifestIsMissing(): void
    {
        @unlink($this->publishedManifestPath());

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('alert-warning', false)
            ->assertSee('php artisan waterline:publish', false)
            ->assertSee('vendor/waterline/app-dark.css', false)
            ->assertSee('vendor/waterline/components.css', false)
            ->assertSee('vendor/waterline/app.js', false)
            ->assertSee('wl-topbar__scope', false)
            ->assertSee('Scope');
    }
}
