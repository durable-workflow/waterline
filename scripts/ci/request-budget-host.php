<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

putenv('WATERLINE_BACKEND=service');

final class RequestBudgetFixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        $mode = json_decode(file_get_contents(__DIR__.'/mode.json'), true, flags: JSON_THROW_ON_ERROR);
        config()->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.env' => 'testing', 'app.debug' => true,
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/database.sqlite', 'foreign_key_constraints' => true],
            'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
            'waterline.backend' => 'service', 'waterline.path' => 'waterline', 'waterline.middleware' => [],
            'waterline.allow_unauthenticated' => true, 'waterline.locale' => 'en',
            'waterline.service.endpoint' => 'http://127.0.0.1:8081',
            'waterline.service.namespace' => 'smoke', 'waterline.service.token' => 'smoke-token',
            'waterline.service.access_mode' => 'operator',
            'waterline.service.request_timeout_seconds' => $mode['service_budget'],
            'waterline.browser.request_timeout_seconds' => $mode['browser_budget'],
        ]);
    }
}

$app = Application::configure(basePath: __DIR__)->withProviders([
    \Waterline\WaterlineServiceProvider::class, RequestBudgetFixtureProvider::class,
], withBootstrapProviders: false)->withExceptions()->create();

if (PHP_SAPI === 'cli') {
    if (\Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk') !== '3.4.0'
        || \Composer\InstalledVersions::getReference('durable-workflow/sdk') !== '1bf25b5848f2031e72a3da38794b51db1d533546') {
        throw new RuntimeException('Expected the exact normally published SDK 3.4.0.');
    }
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('vendor:publish', ['--tag' => 'waterline-assets', '--force' => true]);
    echo json_encode([
        'source' => getenv('EXPECTED_CANDIDATE_SOURCE'), 'kind' => 'candidate-path-source qualification',
        'php' => PHP_VERSION, 'loaded_backend' => (new ReflectionClass(\Waterline\Support\Remote\RemoteBackend::class))->getFileName(),
        'sdk' => \Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
        'sdk_source' => \Composer\InstalledVersions::getReference('durable-workflow/sdk'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/vendor/waterline/')) {
    $file = realpath(__DIR__.'/public'.$path);
    if ($file && str_starts_with($file, __DIR__.'/public/vendor/waterline/') && is_file($file)) {
        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($file);
        $response->headers->set('Content-Type', match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css' => 'text/css', 'png' => 'image/png', 'json' => 'application/json', default => 'application/javascript',
        });
        $response->send();
        return;
    }
}
$mode = json_decode(file_get_contents(__DIR__.'/mode.json'), true, flags: JSON_THROW_ON_ERROR);
if ($mode['mode'] === 'browser-stall' && $path === '/waterline/api/flows/running') usleep(3_000_000);
$app->handleRequest(\Illuminate\Http\Request::capture());
