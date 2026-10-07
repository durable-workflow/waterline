<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Orchestra\Testbench\TestCase;
use function Orchestra\Testbench\artisan;
use Waterline\Support\Remote\RemoteBackend;
use Waterline\Support\RuntimeConfiguration;
use Waterline\Tests\Fixtures\FakeRemoteClient;
use Waterline\Waterline;
use Waterline\WaterlineServiceProvider;

final class ServiceUiLocaleTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WaterlineServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=');
        $app['config']->set('waterline.backend', 'service');
        $app['config']->set('waterline.service.endpoint', 'https://server.example');
        $app['config']->set('waterline.service.token', 'fixture-token');
        $app['config']->set('waterline.service.namespace', 'orders');
        $app['config']->set('waterline.service.access_mode', 'read_only');
        $app['config']->set('waterline.middleware', []);
        $app['config']->set('waterline.api_middleware', []);
    }

    public function testUkrainianBootstrapKeepsTheHostLocaleAndApiValues(): void
    {
        Waterline::auth(static fn (): bool => true);
        $this->app->instance(RemoteBackend::class, new RemoteBackend(new FakeRemoteClient()));
        $previous = getenv('WATERLINE_LOCALE');
        $previousEnv = $_ENV['WATERLINE_LOCALE'] ?? null;
        $previousServer = $_SERVER['WATERLINE_LOCALE'] ?? null;
        putenv('WATERLINE_LOCALE=uk');
        $this->app->setLocale('fr');

        try {
            RuntimeConfiguration::hydrate();
            artisan($this, 'waterline:publish');
            self::assertTrue(Waterline::assetsAreCurrent());
            $catalogs = glob(WATERLINE_PATH.'/public/chunks/ui-messages-*.js');
            self::assertCount(1, $catalogs);
            self::assertSame(
                hash_file('sha256', $catalogs[0]),
                hash_file('sha256', public_path('vendor/waterline/chunks/'.basename($catalogs[0]))),
            );
            $this->get('/waterline')->assertOk()
                ->assertSee('<html lang="uk">', false)
                ->assertSee('Перейти до основного вмісту')
                ->assertViewHas('waterlineBootstrap', fn (array $value): bool =>
                    $value['locale'] === 'uk' && $value['backend']['mode'] === 'service');
            $this->getJson('/waterline/api/instances/order-1/runs/run-1')->assertOk()
                ->assertJsonPath('instance_id', 'order-1')
                ->assertJsonPath('selected_run_id', 'run-1')
                ->assertJsonPath('timeline.0.event_type', 'WorkflowStarted');
            self::assertSame('fr', $this->app->getLocale());
        } finally {
            putenv($previous === false ? 'WATERLINE_LOCALE' : 'WATERLINE_LOCALE='.$previous);
            unset($_ENV['WATERLINE_LOCALE'], $_SERVER['WATERLINE_LOCALE']);
            if ($previousEnv !== null) {
                $_ENV['WATERLINE_LOCALE'] = $previousEnv;
            }
            if ($previousServer !== null) {
                $_SERVER['WATERLINE_LOCALE'] = $previousServer;
            }
        }
    }
}
