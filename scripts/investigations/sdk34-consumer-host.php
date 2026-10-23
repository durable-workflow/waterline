<?php

declare(strict_types=1);

// Synthetic service-only host. Both libraries come from ordinary Composer dist.
require __DIR__.'/vendor/autoload.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Waterline\Support\Remote\RemoteBackend;

$phase = $argv[1] ?? 'healthy';
if (!in_array($phase, ['healthy', 'default-stall', 'injected-stall'], true)) throw new RuntimeException('Unknown probe phase.');
putenv('WATERLINE_BACKEND=service');
putenv('WATERLINE_PROBE_PHASE='.$phase);

final class SdkConsumerProbeProvider extends ServiceProvider
{
    public function register(): void
    {
        $phase = getenv('WATERLINE_PROBE_PHASE');
        $port = match ($phase) { 'healthy' => 8081, 'default-stall' => 8082, default => 8083 };
        config()->set([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.env' => 'testing', 'app.debug' => false,
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/database.sqlite', 'foreign_key_constraints' => true],
            'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
            'waterline.backend' => 'service', 'waterline.path' => 'waterline',
            'waterline.middleware' => [], 'waterline.allow_unauthenticated' => true,
            'waterline.service.endpoint' => 'http://127.0.0.1:'.$port,
            'waterline.service.namespace' => 'smoke', 'waterline.service.token' => 'smoke-token',
            'waterline.service.access_mode' => 'read_only',
        ]);
        if ($phase === 'injected-stall') {
            $this->app->scoped(RemoteBackend::class, static fn () => new RemoteBackend(new Client(
                config('waterline.service.endpoint'), namespace: 'smoke', controlToken: 'smoke-token', requestTimeoutSeconds: 0.2,
            )));
        }
    }
}

$app = Application::configure(basePath: __DIR__)->withProviders([
    \Waterline\WaterlineServiceProvider::class, SdkConsumerProbeProvider::class,
], withBootstrapProviders: false)->withExceptions()->create();
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = Request::create('/waterline/api/flows/running', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
$started = hrtime(true);
$response = $kernel->handle($request);
$elapsed = (hrtime(true) - $started) / 1e9;
$body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
$packages = [];
foreach (['durable-workflow/waterline' => ['2.13.0', '30ec59eac270dafd3025afd65175670c6a0f7ab6'], 'durable-workflow/sdk' => ['3.4.0', '1bf25b5848f2031e72a3da38794b51db1d533546']] as $name => [$version, $source]) {
    $packages[$name] = ['version' => InstalledVersions::getPrettyVersion($name), 'source' => InstalledVersions::getReference($name)];
    if (ltrim((string) $packages[$name]['version'], 'v') !== $version || $packages[$name]['source'] !== $source) throw new RuntimeException('Unexpected installed package identity.');
}
if (class_exists('Workflow\\V2\\Workflow')) throw new RuntimeException('Service consumer unexpectedly installed embedded Core.');
$report = [
    'generated_at' => gmdate(DATE_ATOM), 'phase' => $phase, 'php' => PHP_VERSION, 'packages' => $packages,
    'guzzle' => InstalledVersions::getPrettyVersion('guzzlehttp/guzzle'), 'psr_http_message' => InstalledVersions::getPrettyVersion('psr/http-message'),
    'selection' => $phase === 'injected-stall' ? 'application-injected SDK client, 0.2 seconds' : 'published Waterline RemoteBackend::fromConfig, unmodified SDK default',
    'waterline_service_config_keys' => array_keys(config('waterline.service')), 'http_status' => $response->getStatusCode(),
    'elapsed_seconds' => $elapsed, 'body' => $body,
];
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
if ($phase === 'healthy') {
    if ($response->getStatusCode() !== 200 || count($body['data'] ?? []) !== 2) throw new RuntimeException('Healthy service-mode consumer failed.');
} elseif ($response->getStatusCode() !== 502 || ($body['error'] ?? null) !== 'remote_transport_failure' || ($body['reason'] ?? null) !== 'request_timeout') {
    throw new RuntimeException('Installed Waterline did not preserve the typed timeout.');
} elseif ($elapsed < ($phase === 'default-stall' ? 58 : 0.15) || $elapsed > ($phase === 'default-stall' ? 65 : 3)) {
    throw new RuntimeException('Service request did not respect the selected bounded timeout.');
}
$kernel->terminate($request, $response);
