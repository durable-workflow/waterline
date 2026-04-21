<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $path = public_path('vendor/waterline');

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($path.'/mix-manifest.json', json_encode([
            '/app.css' => '/app.css',
            '/app-dark.css' => '/app-dark.css',
            '/app.js' => '/app.js',
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

    public function testDashboardFallsBackWhenEnvironmentStripColorIsUnsafe(): void
    {
        config()->set('waterline.env_name', 'Staging');
        config()->set('waterline.env_color', 'red; background: url(https://example.test)');

        $this->get('/waterline')
            ->assertOk()
            ->assertSee('--waterline-env-color: #6c757d;', false)
            ->assertDontSee('red; background', false);
    }
}
