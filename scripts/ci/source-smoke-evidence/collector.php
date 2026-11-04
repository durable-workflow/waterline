<?php

declare(strict_types=1);

// Qualification source proposal only. The host runner is deliberately disabled.
// Nothing in the product entrypoint or normal CI invokes this file.
const MAX_FILE = 16_777_216;
const MAX_TOTAL_FILES = 134_217_728;
const MAX_FILES = 4096;
const MAX_JSON = 2_097_152;
const MAX_ROUTES = 2048;
const MAX_OUTPUT = 4_194_304;

function refuse(): never
{
    throw new RuntimeException('Source-smoke collector refused.');
}

/** Read an object under an admitted immutable root, not a candidate-supplied path. */
function objectBytes(string $root, string $relative, int $limit): array
{
    if ($relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
        refuse();
    }
    $base = realpath($root);
    $path = $root . '/' . $relative;
    $canonical = realpath($path);
    $before = lstat($path);
    if ($base !== $root || $canonical !== $path || $before === false
        || ($before['mode'] & 0170000) !== 0100000 || $before['nlink'] !== 1
        || $before['size'] < 0 || $before['size'] > $limit) {
        refuse();
    }
    // Intermediate symlinks are refused as well as a symlink leaf.
    $cursor = $root;
    foreach (explode('/', $relative) as $part) {
        $cursor .= '/' . $part;
        if (is_link($cursor)) {
            refuse();
        }
    }
    $stream = fopen($path, 'rb');
    if ($stream === false) {
        refuse();
    }
    try {
        $opened = fstat($stream);
        $bytes = stream_get_contents($stream, $limit + 1);
        $closed = fstat($stream);
        clearstatcache(true, $path);
        $after = lstat($path);
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ($opened === false || $closed === false || $after === false
                || $before[$field] !== $opened[$field] || $before[$field] !== $closed[$field]
                || $before[$field] !== $after[$field]) {
                refuse();
            }
        }
        if (!is_string($bytes) || strlen($bytes) !== $before['size'] || strlen($bytes) > $limit) {
            refuse();
        }
        return [$bytes, ['mode' => $before['mode'] & 0777, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]];
    } finally {
        fclose($stream);
    }
}

function emit(array $value): void
{
    $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($json) > MAX_OUTPUT) {
        refuse();
    }
    echo $json, "\n";
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['identity', 'routes', 'health', 'api', 'config'], true)) {
        refuse();
    }
    [$raw] = objectBytes('/app', 'vendor/composer/installed.json', MAX_JSON);
    $installed = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($installed) || ($installed['dev'] ?? null) !== false
        || !is_array($installed['packages'] ?? null) || !array_is_list($installed['packages'])
        || count($installed['packages']) !== 79 || ($installed['dev-package-names'] ?? []) !== []) {
        refuse();
    }
    $graph = [];
    foreach ($installed['packages'] as $package) {
        $name = $package['name'] ?? null;
        $version = $package['version'] ?? null;
        $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? null;
        if (!is_string($name) || strlen($name) > 255 || isset($graph[$name])
            || $name === 'durable-workflow/workflow' || !is_string($version) || strlen($version) > 128
            || !is_string($reference) || strlen($reference) > 128) {
            refuse();
        }
        $graph[$name] = ['version' => $version, 'reference' => $reference];
    }
    ksort($graph);
    require '/app/vendor/autoload.php';
    if ($mode === 'identity') {
        $files = [];
        $total = 0;
        // The host supplies no path list to trust. Enumerate all immutable app
        // leaves; compare their exact set to the independent Git/COPY mapping.
        $directory = new RecursiveDirectoryIterator('/app', FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, static function ($entry): bool {
            return !in_array($entry->getPathname(), ['/app/vendor', '/app/storage', '/app/bootstrap/cache'], true);
        });
        foreach (new RecursiveIteratorIterator($filter) as $leaf) {
            $relative = substr($leaf->getPathname(), strlen('/app/'));
            [$bytes, $fileIdentity] = objectBytes('/app', $relative, MAX_FILE);
            $total += strlen($bytes);
            if (count($files) >= MAX_FILES || $total > MAX_TOTAL_FILES || isset($files[$relative])) {
                refuse();
            }
            $files[$relative] = $fileIdentity;
        }
        ksort($files);
        $classes = [];
        foreach ([Waterline\WaterlineServiceProvider::class,
            Waterline\Http\Controllers\Remote\RemoteWorkflowsController::class,
            Waterline\Support\WorkflowStreamPresenter::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if (!is_string($file) || !str_starts_with($file, '/app/waterline/app/')) {
                refuse();
            }
            $relative = substr($file, 5);
            objectBytes('/app', $relative, MAX_FILE);
            $classes[$class] = $relative;
        }
        emit(['php' => PHP_VERSION, 'int_size' => PHP_INT_SIZE,
            'graph' => $graph, 'installed_json_sha256' => hash('sha256', $raw),
            'core_installed' => Composer\InstalledVersions::isInstalled('durable-workflow/workflow'),
            'files' => $files, 'classes' => $classes]);
        exit(0);
    }

    $app = require '/app/bootstrap/app.php';
    $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $http->bootstrap(); // routesAreCached is meaningful only after normal boot.
    if ($mode === 'config') {
        emit(['backend' => config('waterline.backend'), 'path' => config('waterline.path'),
            'namespace' => config('waterline.service.namespace'), 'access_mode' => config('waterline.service.access_mode'),
            'allow_unauthenticated' => config('waterline.allow_unauthenticated'),
            'service_budget' => config('waterline.service.request_timeout_seconds')]);
        exit(0);
    }
    if ($mode === 'routes') {
        $output = new class() extends Symfony\Component\Console\Output\BufferedOutput {
            private int $bytes = 0;
            protected function doWrite(string $message, bool $newline): void
            {
                $this->bytes += strlen($message) + (int) $newline;
                if ($this->bytes > MAX_JSON) {
                    refuse();
                }
                parent::doWrite($message, $newline);
            }
        };
        if ($app->make(Illuminate\Contracts\Console\Kernel::class)->call('route:list', ['--json' => true], $output) !== 0) {
            refuse();
        }
        $routes = json_decode($output->fetch(), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($routes) || !array_is_list($routes) || $routes === [] || count($routes) > MAX_ROUTES) {
            refuse();
        }
        $cache = $app->getCachedRoutesPath();
        if (!str_starts_with($cache, '/app/bootstrap/cache/')) {
            refuse();
        }
        $cached = $app->routesAreCached();
        $digest = null;
        if ($cached) {
            [, $identity] = objectBytes('/app/bootstrap/cache', basename($cache), MAX_FILE);
            $digest = $identity['sha256'];
        }
        emit(['cached' => $cached, 'cache_sha256' => $digest, 'routes' => $routes]);
        exit(0);
    }
    if ($mode === 'api' && strlen($argv[2] ?? '') > 32768) {
        refuse();
    }
    $request = $mode === 'health' ? ['path' => '/up', 'method' => 'GET', 'body' => []]
        : json_decode($argv[2] ?? '', true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_string($request['path'] ?? null) || strlen($request['path']) > 4096
        || !str_starts_with($request['path'], '/') || str_contains($request['path'], '://')
        || !in_array($request['method'] ?? null, ['GET', 'POST'], true) || !is_array($request['body'] ?? null)
        || strlen(json_encode($request['body'], JSON_THROW_ON_ERROR)) > 16384) {
        refuse();
    }
    $input = Illuminate\Http\Request::create($request['path'], $request['method'], [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($request['body'], JSON_THROW_ON_ERROR));
    $response = $http->handle($input);
    $body = $response->getContent();
    if (!is_string($body) || strlen($body) > MAX_JSON) {
        refuse();
    }
    emit(['path' => $request['path'], 'status' => $response->getStatusCode(),
        'body' => $mode === 'health' ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR),
        'body_sha256' => hash('sha256', $body)]);
    $http->terminate($input, $response);
} catch (Throwable $error) {
    // Candidate exception messages/raw data are not retained as diagnostics.
    fwrite(STDERR, "Source-smoke collector refused.\n");
    exit(1);
}
