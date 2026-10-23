<?php

declare(strict_types=1);

final class NativeBudgetFixtureException extends RuntimeException {}

if (PHP_SAPI !== 'cli') {
    $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $mode = json_decode(file_get_contents(__DIR__.'/mode.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($mode['schedule_write_stall'] ?? false) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && $path === '/waterline/api/v2/schedules/budget-schedule/pause') {
        file_put_contents(__DIR__.'/schedule-writes.jsonl', json_encode([
            'method' => 'POST', 'path' => $path, 'schedule_id' => 'budget-schedule', 'status' => 'paused',
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        usleep(3_000_000);
        header('Content-Type: application/json');
        echo json_encode(['schedule_id' => 'budget-schedule', 'status' => 'paused'], JSON_THROW_ON_ERROR);
        return;
    }
    // View wiring uses controlled HTTP responses. Run-list cases continue to
    // the real package/image and its actual SDK/protocol backend.
    if (isset($mode['browser_path'], $mode['browser_response']) && $path === $mode['browser_path']) {
        if ($mode['mode'] === 'browser-stall') {
            usleep(3_000_000);
        }
        header('Content-Type: application/json');
        echo json_encode($mode['browser_response'], JSON_THROW_ON_ERROR);
        return;
    }
    if ($mode['mode'] === 'browser-stall' && $path === '/waterline/api/flows/running') {
        usleep((int) ($mode['browser_stall_microseconds'] ?? 3_000_000));
    }

    $nativePort = getenv('BUDGET_NATIVE_PORT');
    if ($nativePort !== false) {
        $port = filter_var($nativePort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false || !str_starts_with($_SERVER['REQUEST_URI'], '/')) {
            http_response_code(400);
            throw new NativeBudgetFixtureException('Expected a local native-image port and request path.');
        }
        $decodedPath = rawurldecode($path);
        $segments = explode('/', ltrim($decodedPath, '/'));
        $allowed = preg_match('#^(?:/up|/favicon\.ico|/waterline(?:/[A-Za-z0-9_-]+)*|/vendor/waterline/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+)$#D', $decodedPath);
        if ($allowed !== 1 || array_intersect(['.', '..'], $segments) !== []) {
            http_response_code(400);
            throw new NativeBudgetFixtureException('Unexpected native proxy request path.');
        }
        $targetPath = '/'.implode('/', array_map('rawurlencode', $segments));
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        $target = 'http://127.0.0.1:'.$port.$targetPath.($query === null ? '' : '?'.$query);
        $headers = ['Host: 127.0.0.1:8080'];
        foreach (getallheaders() as $name => $value) {
            if (!in_array(strtolower($name), ['host', 'connection', 'content-length', 'transfer-encoding'], true)) {
                $headers[] = $name.': '.$value;
            }
        }
        $responseHeaders = [];
        $connection = curl_init($target);
        curl_setopt_array($connection, [
            CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'], CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => file_get_contents('php://input'), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_TIMEOUT_MS => 7000,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $responseHeaders[] = trim($line);
                return strlen($line);
            },
        ]);
        $body = curl_exec($connection);
        if ($body === false) {
            http_response_code(502);
            throw new NativeBudgetFixtureException('Native image HTTP fixture proxy failed.');
        }
        http_response_code(curl_getinfo($connection, CURLINFO_RESPONSE_CODE));
        foreach ($responseHeaders as $line) {
            if ($line !== '' && !preg_match('/^(?:HTTP\/|connection:|transfer-encoding:|content-length:)/i', $line)) {
                header($line, false);
            }
        }
        echo $body;
        return;
    }
}

require __DIR__.'/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

putenv('WATERLINE_BACKEND=service');

final class UnexpectedRequestBudgetSdk extends RuntimeException {}

final class RequestBudgetFixtureProvider extends ServiceProvider
{
    public const SDK_PACKAGE = 'durable-workflow/sdk';
    public const WATERLINE_PACKAGE = 'durable-workflow/waterline';
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
            'waterline.browser.request_timeout_seconds' => $mode['browser_budget'],
        ]);
        if (array_key_exists('service_budget', $mode)) {
            config()->set('waterline.service.request_timeout_seconds', $mode['service_budget']);
        }
    }
}

$app = Application::configure(basePath: __DIR__)->withProviders([
    \Waterline\WaterlineServiceProvider::class, RequestBudgetFixtureProvider::class,
], withBootstrapProviders: false)->withExceptions()->create();

if (PHP_SAPI === 'cli') {
    $sdkVersion = getenv('EXPECTED_BUDGET_SDK_VERSION') ?: '3.4.1';
    $sdkSource = getenv('EXPECTED_BUDGET_SDK_SOURCE') ?: 'cb1d11e57767e20e1fdb8081024f67057ff66714';
    if (\Composer\InstalledVersions::getPrettyVersion(RequestBudgetFixtureProvider::SDK_PACKAGE) !== $sdkVersion
        || \Composer\InstalledVersions::getReference(RequestBudgetFixtureProvider::SDK_PACKAGE) !== $sdkSource) {
        throw new UnexpectedRequestBudgetSdk('Expected the exact normally published SDK identity.');
    }
    $published = getenv('EXPECTED_WATERLINE_VERSION');
    if ($published && (ltrim((string) \Composer\InstalledVersions::getPrettyVersion(RequestBudgetFixtureProvider::WATERLINE_PACKAGE), 'v') !== $published
        || \Composer\InstalledVersions::getReference(RequestBudgetFixtureProvider::WATERLINE_PACKAGE) !== getenv('EXPECTED_WATERLINE_SOURCE'))) {
        throw new UnexpectedRequestBudgetSdk('Expected the exact normally published Waterline identity.');
    }
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('vendor:publish', ['--tag' => 'waterline-assets', '--force' => true]);
    echo json_encode([
        'source' => getenv('EXPECTED_CANDIDATE_SOURCE'), 'kind' => $published ? 'normal published-package qualification' : 'candidate-path-source qualification',
        'waterline_version' => \Composer\InstalledVersions::getPrettyVersion(RequestBudgetFixtureProvider::WATERLINE_PACKAGE),
        'waterline_reference' => \Composer\InstalledVersions::getReference(RequestBudgetFixtureProvider::WATERLINE_PACKAGE),
        'php' => PHP_VERSION, 'loaded_backend' => (new ReflectionClass(\Waterline\Support\Remote\RemoteBackend::class))->getFileName(),
        'sdk' => \Composer\InstalledVersions::getPrettyVersion(RequestBudgetFixtureProvider::SDK_PACKAGE),
        'sdk_source' => \Composer\InstalledVersions::getReference(RequestBudgetFixtureProvider::SDK_PACKAGE),
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
$app->handleRequest(\Illuminate\Http\Request::capture());
