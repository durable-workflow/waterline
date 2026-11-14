#!/usr/bin/env sh
set -eu

# Source-only preparation handoff. This launcher refuses before imports or I/O.
if [ "${WATERLINE_SOURCE_PREPARATION_PROPOSAL:-0}" != 0 ]; then
    exec python3 -I scripts/ci/source-smoke-preparation/bootstrap.py "$@"
fi

# Held source-only proposal. The runner has a literal, non-overridable hold and
# returns before Docker/collection; normal CI leaves this unset.
if [ "${WATERLINE_SOURCE_SMOKE_EVIDENCE_PROPOSAL:-0}" != 0 ]; then
    exec python3 -I scripts/ci/source-smoke-evidence/bootstrap.py "$@"
fi

image="${WATERLINE_IMAGE:-waterline-service-smoke:local}"
. scripts/ci/service-image-features.sh

# Source for the current-target acceptance packet. No ordinary path calls this
# function; execution wiring needs the complete independent source/effect review.
current_runtime_probe_source() {
    cat <<'WATERLINE_CURRENT_RUNTIME_PHP'
<?php
declare(strict_types=1);

const CURRENT_LOCK_SHA256 = 'c2181f466bcbb799e3ce183d58531574d32de0cf9eba2f78dd9c18b211c60466';
const CURRENT_CACHE_PATH = '/app/bootstrap/cache/routes-v7.php';
const CURRENT_RUNTIME_CLASSES = [
    'Waterline\\Http\\Controllers\\Remote\\RemoteWorkflowsController' => '/app/waterline/app/Http/Controllers/Remote/RemoteWorkflowsController.php',
    'Waterline\\Support\\WorkflowStreamPresenter' => '/app/waterline/app/Support/WorkflowStreamPresenter.php',
    'Illuminate\\Foundation\\Application' => '/app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
];

function currentFileIdentity(array $state): array
{
    return array_intersect_key($state, array_flip(['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime']));
}

function currentRealParents(string $path): void
{
    $parent = '';
    foreach (explode('/', trim(dirname($path), '/')) as $part) {
        $parent .= '/'.$part;
        clearstatcache(true, $parent);
        $state = lstat($parent);
        if (($state['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('Observation parent is not a real directory.');
        }
    }
}

function currentRegularBytes(string $path, int $limit): string
{
    $allowed = ['/app/composer.lock', '/app/vendor/composer/installed.json', CURRENT_CACHE_PATH, ...array_values(CURRENT_RUNTIME_CLASSES)];
    if (!in_array($path, $allowed, true)) {
        throw new RuntimeException('Unselected observation path.');
    }
    currentRealParents($path);
    clearstatcache(true, $path);
    $before = lstat($path);
    if (($before['mode'] & 0170000) !== 0100000 || $before['size'] > $limit) {
        throw new RuntimeException('Observation input is not a bounded regular file.');
    }
    $stream = fopen($path, 'rb');
    try {
        if (currentFileIdentity($before) !== currentFileIdentity(fstat($stream))) {
            throw new RuntimeException('Observation input changed before opening.');
        }
        $bytes = stream_get_contents($stream, $limit + 1);
        if (strlen($bytes) > $limit || currentFileIdentity($before) !== currentFileIdentity(fstat($stream))) {
            throw new RuntimeException('Observation input changed while reading.');
        }
        return $bytes;
    } finally {
        fclose($stream);
    }
}

function currentCacheState(): array
{
    currentRealParents(CURRENT_CACHE_PATH);
    $count = 0;
    foreach (new DirectoryIterator(dirname(CURRENT_CACHE_PATH)) as $entry) {
        if (++$count > 128) {
            throw new RuntimeException('Cache directory entry bound exceeded.');
        }
        if ($entry->getFilename() === basename(CURRENT_CACHE_PATH)) {
            $bytes = currentRegularBytes(CURRENT_CACHE_PATH, 2 * 1024 * 1024);
            return ['path' => CURRENT_CACHE_PATH, 'present' => true, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
    }
    return ['path' => CURRENT_CACHE_PATH, 'present' => false, 'bytes' => 0, 'sha256' => null];
}

function currentClasses(): array
{
    $classes = [];
    foreach (CURRENT_RUNTIME_CLASSES as $name => $expectedPath) {
        $actual = (new ReflectionClass($name))->getFileName();
        if ($actual !== $expectedPath || realpath($actual) !== $expectedPath) {
            throw new RuntimeException('Loaded class escaped its canonical path.');
        }
        $classes[$name] = ['path' => $actual, 'sha256' => hash('sha256', currentRegularBytes($actual, 1024 * 1024))];
    }
    return $classes;
}

function currentIniValues(): array
{
    $values = [];
    foreach (['memory_limit', 'date.timezone', 'auto_prepend_file', 'auto_append_file', 'include_path',
              'opcache.enable_cli', 'opcache.file_cache', 'allow_url_include'] as $key) {
        $values[$key] = ini_get($key);
    }
    return $values;
}

function currentRoutes(object $app): array
{
    $routes = [];
    foreach ($app['router']->getRoutes() as $route) {
        if (count($routes) >= 2048) {
            throw new RuntimeException('Route count bound exceeded.');
        }
        $routes[] = [
            'methods' => $route->methods(), 'uri' => $route->uri(), 'domain' => $route->getDomain(),
            'name' => $route->getName(), 'action' => $route->getActionName(),
            'middleware' => $route->gatherMiddleware(), 'wheres' => (object) $route->wheres,
            'defaults' => (object) $route->defaults, 'binding_fields' => (object) $route->bindingFields(),
            'scoped_bindings' => $route->enforcesScopedBindings(),
        ];
    }
    return $routes;
}

$mode = $argv[1] ?? '';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    if (!in_array($mode, ['metadata', 'inventory', 'clear-before', 'cache-build', 'clear-after'], true)) {
        throw new RuntimeException('Unselected observation mode.');
    }
    if (PHP_VERSION_ID !== 80336 || PHP_INT_SIZE !== 8 || realpath(PHP_BINARY) !== '/usr/local/bin/php') {
        throw new RuntimeException('Unexpected PHP runtime.');
    }
    if (getenv('APP_ROUTES_CACHE') !== false) {
        throw new RuntimeException('Route-cache path override is unselected.');
    }
    $lock = currentRegularBytes('/app/composer.lock', 1024 * 1024);
    if (!hash_equals(CURRENT_LOCK_SHA256, hash('sha256', $lock))) {
        throw new RuntimeException('Lock differs from the current-target source.');
    }
    $installed = currentRegularBytes('/app/vendor/composer/installed.json', 1024 * 1024);
    $report = [
        'schema' => 'waterline.current-standalone-runtime-observation/v1', 'mode' => $mode,
        'php_version' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE, 'php_binary' => realpath(PHP_BINARY),
        'effective_uid' => posix_geteuid(),
        'ini_loaded_file' => php_ini_loaded_file() ?: null, 'ini_scanned_files' => php_ini_scanned_files() ?: null,
        'ini_before' => currentIniValues(),
        'lock_sha256' => hash('sha256', $lock), 'installed_sha256' => hash('sha256', $installed),
    ];
    if ($mode === 'metadata') {
        if (php_ini_loaded_file() !== false || php_ini_scanned_files() !== false) {
            throw new RuntimeException('Metadata observation requires the no-INI PHP launch.');
        }
        if (count($argv) !== 2 || currentCacheState()['present']) {
            throw new RuntimeException('Initial image has a route cache or extra arguments.');
        }
        $entries = 0;
        foreach (new DirectoryIterator(dirname(CURRENT_CACHE_PATH)) as $entry) {
            if (++$entries > 128) {
                throw new RuntimeException('Fresh cache directory entry bound exceeded.');
            }
            if (!in_array($entry->getFilename(), ['.', '..', '.gitignore'], true)) {
                throw new RuntimeException('Fresh image contains bootstrap cache output.');
            }
        }
        $report += ['lock_base64' => base64_encode($lock), 'installed_base64' => base64_encode($installed),
                    'cache' => currentCacheState()];
    } else {
        $expectedInstalled = $argv[2] ?? '';
        if (count($argv) !== 3 || preg_match('/\A[a-f\d]{64}\z/D', $expectedInstalled) !== 1
            || !hash_equals($expectedInstalled, $report['installed_sha256']) || posix_geteuid() !== 82) {
            throw new RuntimeException('Missing metadata seal or wrong application UID.');
        }
        ob_start();
        require '/app/vendor/autoload.php';
        $app = require '/app/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        currentRealParents(CURRENT_CACHE_PATH);
        if ($app->getCachedRoutesPath() !== CURRENT_CACHE_PATH) {
            throw new RuntimeException('Application selected a different cache path.');
        }
        $kernel->bootstrap();
        if ($app->getCachedRoutesPath() !== CURRENT_CACHE_PATH) {
            throw new RuntimeException('Bootstrap changed the selected cache path.');
        }
        if ($mode !== 'inventory') {
            $command = $mode === 'cache-build' ? 'route:cache' : 'route:clear';
            if ($kernel->call($command) !== 0 || strlen($kernel->output()) > 65536) {
                throw new RuntimeException('Route-cache command failed or exceeded output bounds.');
            }
            $report += ['command' => $command, 'exit_status' => 0];
        } else {
            $report['routes'] = currentRoutes($app);
        }
        $report += ['classes' => currentClasses(), 'cache' => currentCacheState()];
        if (ob_get_clean() !== '') {
            throw new RuntimeException('Unexpected application output.');
        }
        if (!hash_equals($report['lock_sha256'], hash('sha256', currentRegularBytes('/app/composer.lock', 1024 * 1024)))
            || !hash_equals($expectedInstalled, hash('sha256', currentRegularBytes('/app/vendor/composer/installed.json', 1024 * 1024)))) {
            throw new RuntimeException('Application changed the observed package metadata.');
        }
    }
    $report['ini_after'] = currentIniValues();
    $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (strlen($encoded) + 1 > 1024 * 1024) {
        throw new RuntimeException('Observation output bound exceeded.');
    }
    echo $encoded, "\n";
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $safeMode = in_array($mode, ['metadata', 'inventory', 'clear-before', 'cache-build', 'clear-after'], true) ? $mode : 'invalid';
    $file = $error->getFile();
    $line = $error->getLine();
    $severity = $error instanceof ErrorException ? $error->getSeverity() : null;
    $origin = [
        'file_sha256' => $file !== '' && strlen($file) <= 4096 ? hash('sha256', $file) : null,
        'line' => $line >= 0 && $line <= 1000000 ? $line : null,
        'severity' => in_array($severity, [1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384], true) ? $severity : null,
    ];
    fwrite(STDERR, json_encode(['mode' => $safeMode, 'error_class' => substr(get_class($error), 0, 256),
                               'origin' => $origin], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}
WATERLINE_CURRENT_RUNTIME_PHP
}

container="waterline-service-smoke-${GITHUB_RUN_ID:-local}-$$"
memory_database_container="${container}-memory-database"
server_container="${container}-server"
network="${container}-network"
data_volume="${container}-data"
memory_database_log="$(mktemp)"
response_body="$(mktemp)"
waterline_url=''
last_request=''
expected_response_status=''
response_status=''
response_content=''
runner_attached=0
service_ready_timeout_seconds=30

dump_container_state() {
    target="$1"

    echo "$target container state:" >&2
    docker inspect --format '{{json .State}}' "$target" >&2 || true
    echo "$target container log:" >&2
    docker logs "$target" >&2 || true
}

dump_diagnostics() {
    echo "Service image smoke failed." >&2

    if [ -n "$last_request" ]; then
        echo "Last request: $last_request" >&2
        echo "Expected response status: ${expected_response_status:-not set}" >&2
        echo "Response status: ${response_status:-unavailable}" >&2

        if [ -s "$response_body" ]; then
            echo "Response body:" >&2
            cat "$response_body" >&2
            echo >&2
        fi
    fi

    dump_container_state "$container"
    dump_container_state "$memory_database_container"
    dump_container_state "$server_container"
}

cleanup() {
    status=$?
    trap - EXIT INT TERM

    if [ "$status" -ne 0 ]; then
        dump_diagnostics
    fi

    docker rm -f "$container" "$memory_database_container" "$server_container" >/dev/null 2>&1 || true
    docker volume rm -f "$data_volume" >/dev/null 2>&1 || true
    if [ "$runner_attached" -eq 1 ]; then
        docker network disconnect "$network" "$HOSTNAME" >/dev/null 2>&1 || true
    fi
    docker network rm "$network" >/dev/null 2>&1 || true
    rm -f "$memory_database_log" "$response_body"

    exit "$status"
}

request() {
    expected_response_status="$1"
    last_request="$2"
    shift 2
    : >"$response_body"

    if ! response_status="$(
        curl --silent --show-error \
            --connect-timeout 2 \
            --max-time 5 \
            --output "$response_body" \
            --write-out '%{http_code}' \
            "$@"
    )"; then
        return 1
    fi

    if [ "$response_status" != "$expected_response_status" ]; then
        return 1
    fi

    response_content="$(cat "$response_body")"
}

image_label() {
    docker image inspect --format "{{ index .Config.Labels \"$1\" }}" "$image"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "${SERVICE_IMAGE_SKIP_BUILD:-0}" != "1" ]; then
    source_commit="$(git rev-parse HEAD)"
    smoke_version='0.0.0-service-smoke'
    docker build --pull \
        --build-arg "SOURCE_COMMIT=$source_commit" \
        --build-arg "WATERLINE_VERSION=$smoke_version" \
        -t "$image" .
    expected_source_commit="$source_commit"
    expected_waterline_version="$smoke_version"
else
    if [ "${SERVICE_IMAGE_LOCAL_ONLY:-0}" = "1" ]; then
        # OCI qualification must exercise the imported image without a registry fallback.
        docker image inspect "$image" >/dev/null
    else
        docker pull "$image"
    fi
    expected_source_commit="${EXPECTED_SOURCE_COMMIT:-}"
    expected_waterline_version="${EXPECTED_WATERLINE_VERSION:-}"
fi

image_revision="$(image_label org.opencontainers.image.revision)"
image_release="$(image_label dev.durable-workflow.release.tag)"
workflow_streams_required=0

case "$image_release" in
    0.0.0-service-smoke|2.0.0)
        workflow_streams_required=1
        ;;
    2.0.0-rc.*)
        rc_number="${image_release##*.}"
        case "$rc_number" in
            ''|*[!0-9]*) ;;
            *)
                if [ "$rc_number" -ge 26 ]; then
                    workflow_streams_required=1
                fi
                ;;
        esac
        ;;
esac

if [ -z "$image_revision" ] || [ -z "$image_release" ]; then
    echo "Service image is missing its immutable revision or release label." >&2
    exit 1
fi
if [ -n "$expected_source_commit" ] && [ "$image_revision" != "$expected_source_commit" ]; then
    echo "Service image revision label [$image_revision] does not match [$expected_source_commit]." >&2
    exit 1
fi
if [ -n "$expected_waterline_version" ] && [ "$image_release" != "$expected_waterline_version" ]; then
    echo "Service image release label [$image_release] does not match [$expected_waterline_version]." >&2
    exit 1
fi

if image_requires_brotli_guard "$image_release"; then
    docker run --rm --entrypoint php "$image" /usr/local/share/waterline/verify-ca-trust.php
    docker run --rm --entrypoint php "$image" /usr/local/share/waterline/verify-brotli.php
else
    brotli_feature_status=$?
    if [ "$brotli_feature_status" -ne 1 ]; then
        echo 'Cannot determine the decoder contract for this image version.' >&2
        exit 1
    fi
    echo 'Historical application smoke only: this image predates decoder qualification.' >&2
fi

if image_requires_curl_guard "$image_release"; then
    docker run --rm --init --user 1000:1000 --network none --entrypoint php \
        "$image" /usr/local/share/waterline/verify-curl.php
else
    curl_feature_status=$?
    if [ "$curl_feature_status" -ne 1 ]; then
        echo 'Cannot determine the curl contract for this image version.' >&2
        exit 1
    fi
    echo 'Historical application smoke only: this image predates curl qualification.' >&2
fi

if docker run --name "$memory_database_container" \
    -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=:memory: \
    -e WATERLINE_SERVER_ENDPOINT=http://workflow.example.test \
    "$image" >"$memory_database_log" 2>&1; then
    echo "Service image accepted process-local SQLite memory configuration." >&2
    exit 1
fi

memory_database_status="$(docker inspect --format '{{.State.Status}}' "$memory_database_container")"
memory_database_exit_code="$(docker inspect --format '{{.State.ExitCode}}' "$memory_database_container")"
if [ "$memory_database_status" != 'exited' ] || [ "$memory_database_exit_code" -eq 0 ]; then
    echo "Service image did not exit with a deterministic startup failure for DB_DATABASE=:memory:." >&2
    exit 1
fi
if ! grep -F 'waterline-service: startup failed:' "$memory_database_log" >/dev/null \
    || ! grep -F 'DB_DATABASE=:memory:' "$memory_database_log" >/dev/null \
    || ! grep -F 'file-backed SQLite' "$memory_database_log" >/dev/null; then
    echo "Service image did not explain the supported SQLite persistence model." >&2
    exit 1
fi

docker network create "$network" >/dev/null

# Run the protocol fixture as a separate container on the same network. Reuse
# the service image's PHP binary so this test adds no runtime package bootstrap.
docker create \
    --name "$server_container" \
    --network "$network" \
    --entrypoint php \
    "$image" \
    -S 0.0.0.0:18081 /tmp/service-mode-server.php >/dev/null
docker cp tests/Fixtures/service-mode-server.php "$server_container:/tmp/service-mode-server.php"
docker start "$server_container" >/dev/null

attempt=0
until docker exec "$server_container" php -r \
    '$c=@file_get_contents("http://127.0.0.1:18081/api/system/health", false, stream_context_create(["http"=>["header"=>"Authorization: Bearer smoke-token\r\nX-Namespace: smoke\r\nX-Durable-Workflow-Control-Plane-Version: 2\r\n"]])); exit($c===false?1:0);'; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 10 ]; then
        exit 1
    fi
    sleep 1
done

docker volume create "$data_volume" >/dev/null
# Reproduce storage provisioned by a root-owned host mount. The service must
# repair only this initialization boundary and then run the application as
# www-data.
docker run --rm \
    --user root \
    -v "$data_volume:/data" \
    --entrypoint sh \
    "$image" \
    -c 'chown root:root /data && chmod 0755 /data'

if [ -n "${HOSTNAME:-}" ] && docker inspect "$HOSTNAME" >/dev/null 2>&1; then
    docker network connect "$network" "$HOSTNAME"
    runner_attached=1
    waterline_url="http://${container}:8080"
    publish_port=''
else
    publish_port='-p 127.0.0.1::8080'
fi

startup_started="$(date +%s)"
# shellcheck disable=SC2086
docker run -d --name "$container" \
    --network "$network" \
    $publish_port \
    -v "$data_volume:/data" \
    -e "WATERLINE_SERVER_ENDPOINT=http://${server_container}:18081" \
    -e WATERLINE_SERVER_TOKEN=smoke-token \
    -e WATERLINE_NAMESPACE=smoke \
    -e WATERLINE_ACCESS_MODE=operator \
    -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
    "$image" >/dev/null

if [ -z "$waterline_url" ]; then
    port="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' "$container")"
    waterline_url="http://127.0.0.1:${port}"
fi

readiness_deadline=$((startup_started + service_ready_timeout_seconds))
last_request="GET ${waterline_url}/up"
expected_response_status='200'
while :; do
    container_status="$(docker inspect --format '{{.State.Status}}' "$container")"
    if [ "$container_status" != 'running' ]; then
        echo "Service container stopped before binding its HTTP port." >&2
        exit 1
    fi

    : >"$response_body"
    if response_status="$(
        curl --silent \
            --connect-timeout 1 \
            --max-time 2 \
            --output "$response_body" \
            --write-out '%{http_code}' \
            "${waterline_url}/up"
    )" && [ "$response_status" = "$expected_response_status" ]; then
        break
    fi

    if [ "$(date +%s)" -ge "$readiness_deadline" ]; then
        echo "Service did not reach /up within ${service_ready_timeout_seconds}s." >&2
        exit 1
    fi
    sleep 1
done

startup_elapsed=$(($(date +%s) - startup_started))
if [ "$startup_elapsed" -gt "$service_ready_timeout_seconds" ]; then
    echo "Service exceeded the ${service_ready_timeout_seconds}s cold-start budget." >&2
    exit 1
fi

service_uid="$(docker exec --user root "$container" awk '/^Uid:/{print $2; exit}' /proc/1/status)"
www_data_uid="$(docker exec --user root "$container" id -u www-data)"
if [ "$service_uid" != "$www_data_uid" ]; then
    echo "Service process did not drop privileges to www-data (uid ${www_data_uid})." >&2
    exit 1
fi

packaged_sdk_version="$(docker exec "$container" php -r '
require "/app/vendor/autoload.php";
$manifest = json_decode(file_get_contents("/app/composer.json"), true, flags: JSON_THROW_ON_ERROR);
$expected = $manifest["require"]["durable-workflow/sdk"] ?? null;
$actual = Composer\InstalledVersions::getPrettyVersion("durable-workflow/sdk");
$controller = new ReflectionClass(Waterline\Http\Controllers\Remote\RemoteWorkflowsController::class);
$controllerFile = $controller->getFileName();
if ($actual !== $expected
    || !is_string($controllerFile)
    || !str_starts_with($controllerFile, "/app/waterline/app/")) {
    fwrite(STDERR, "Packaged service runtime identity mismatch.\n");
    exit(1);
}
if ($argv[1] === "1"
    && (!method_exists(DurableWorkflow\Client::class, "listWorkflowStreams")
        || !class_exists(Waterline\Support\WorkflowStreamPresenter::class))) {
    fwrite(STDERR, "Packaged service is missing the Workflow Streams runtime contract.\n");
    exit(1);
}
echo $actual;
' "$workflow_streams_required")"

# The candidate extends Waterline's filter contract independently of Core.
# Published images retain their own supported contract; bind the HTTP response
# to the code actually packaged in the already identified image.
packaged_filter_contract="$(docker exec "$container" php -r '
require "/app/vendor/autoload.php";
$version = Waterline\Support\ActionabilityVisibilityFilters::VERSION;
$supported = Waterline\Support\ActionabilityVisibilityFilters::supportedVersions();
if (!in_array($version, [6, 7], true) || !in_array($version, $supported, true)) {
    fwrite(STDERR, "Unsupported packaged visibility filter contract.\n");
    exit(1);
}
echo json_encode(["version" => $version, "supported_versions" => $supported], JSON_THROW_ON_ERROR);
')"

request 200 \
    "GET ${waterline_url}/waterline/api/flows/running" \
    "${waterline_url}/waterline/api/flows/running"
list="$response_content"

request 200 \
    "GET ${waterline_url}/waterline/api/saved-views?bucket=running" \
    "${waterline_url}/waterline/api/saved-views?bucket=running"
saved_views="$response_content"

request 201 \
    "POST ${waterline_url}/waterline/api/saved-views" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"name":"Smoke orders","bucket":"running","filters":{"workflow_type":"smoke.order"},"shared":true}' \
    "${waterline_url}/waterline/api/saved-views"
selected_saved_view="$response_content"
selected_saved_view_id="$(php -r '
$view = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (!is_string($view["id"] ?? null) || $view["id"] === "") {
    exit(1);
}
echo rawurlencode($view["id"]);
' "$selected_saved_view")"

request 200 \
    "GET ${waterline_url}/waterline/api/flows/running?view=${selected_saved_view_id}" \
    "${waterline_url}/waterline/api/flows/running?view=${selected_saved_view_id}"
filtered_list="$response_content"

request 200 \
    "GET ${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run" \
    "${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run"
detail="$response_content"

request 200 \
    "POST ${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run/queries/current" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"arguments":[]}' \
    "${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run/queries/current"
query="$response_content"

request 200 \
    "POST ${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run/signals/approve" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"arguments":["manager"]}' \
    "${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run/signals/approve"
signal="$response_content"

php -r '
[$list, $savedViews, $selectedSavedView, $filteredList, $detail, $query, $signal, $workflowStreamsRequired, $imageRelease, $filterContract] = array_slice($argv, 1);
$filterContract = json_decode($filterContract, true, flags: JSON_THROW_ON_ERROR);
$list = json_decode($list, true, flags: JSON_THROW_ON_ERROR);
$savedViews = json_decode($savedViews, true, flags: JSON_THROW_ON_ERROR);
$selectedSavedView = json_decode($selectedSavedView, true, flags: JSON_THROW_ON_ERROR);
$filteredList = json_decode($filteredList, true, flags: JSON_THROW_ON_ERROR);
$detail = json_decode($detail, true, flags: JSON_THROW_ON_ERROR);
$query = json_decode($query, true, flags: JSON_THROW_ON_ERROR);
$signal = json_decode($signal, true, flags: JSON_THROW_ON_ERROR);

$detailExpectations = [
    "selected_run_id" => "smoke-run",
    "timeline.0.event_type" => "WorkflowStarted",
];
// The previous stable image predates the history presentation fix (#111).
if ($imageRelease === "0.0.0-service-smoke" || version_compare($imageRelease, "2.0.3", ">=")) {
    $detailExpectations += [
        "timeline.0.type" => "WorkflowStarted",
        "timeline.0.recorded_at" => "2026-07-22T12:00:00Z",
        "timeline.0.id" => "smoke-run:history:1",
    ];
}
if ($workflowStreamsRequired === "1") {
    $detailExpectations += [
        "workflow_streams_mode" => "service",
        "workflow_streams.0.stream_name" => "receipts",
        "workflow_streams.0.status" => "errored",
        "workflow_streams.0.last_offset" => 4,
        "workflow_streams.0.pending_items" => 2,
        "workflow_streams.0.error_reason" => "producer_failed",
        "workflow_streams.0.supports_inbound_workflow_messaging" => false,
    ];
}
foreach ($detailExpectations as $path => $expected) {
    $actual = $detail;
    foreach (explode(".", $path) as $segment) {
        $actual = is_array($actual) && array_key_exists($segment, $actual)
            ? $actual[$segment]
            : null;
    }
    if ($actual !== $expected) {
        fwrite(
            STDERR,
            "Service image detail field [{$path}] expected "
                .json_encode($expected, JSON_THROW_ON_ERROR)
                .", received ".json_encode($actual, JSON_THROW_ON_ERROR).".\n"
        );
        exit(1);
    }
}

if (($list["data"][0]["instance_id"] ?? null) !== "smoke-order"
    || count($list["data"] ?? []) !== 2
    || ($savedViews["filter_version"] ?? null) !== $filterContract["version"]
    || ($savedViews["supported_filter_versions"] ?? null) !== $filterContract["supported_versions"]
    || ($savedViews["filter_definition"]["version"] ?? null) !== $filterContract["version"]
    || ($selectedSavedView["filter_version"] ?? null) !== $filterContract["version"]
    || ($savedViews["filter_definition"]["fields"]["repair_state"]["label"] ?? null) !== "Repair State"
    || ($savedViews["filter_definition"]["fields"]["repair_state"]["filterable"] ?? null) !== false
    || ($selectedSavedView["filters"]["workflow_type"] ?? null) !== "smoke.order"
    || ($selectedSavedView["service_mode_available"] ?? null) !== true
    || count($filteredList["data"] ?? []) !== 1
    || ($filteredList["data"][0]["instance_id"] ?? null) !== "smoke-order"
    || ($filteredList["visibility_filters"]["saved_view"]["id"] ?? null) !== ($selectedSavedView["id"] ?? null)
    || ($filteredList["visibility_filters"]["saved_view_applied"] ?? null) !== true
    || ($filteredList["visibility_filters"]["applied"]["workflow_type"] ?? null) !== "smoke.order"
    || ($filteredList["visibility_filters"]["capability"]["fully_applied"] ?? null) !== true
    || ($query["query"] ?? null) !== "current"
    || ($query["result"]["state"] ?? null) !== "awaiting_approval"
    || ($query["result"]["selected_run_id"] ?? null) !== "smoke-run"
    || ($signal["command_status"] ?? null) !== "accepted"
    || ($signal["signal_name"] ?? null) !== "approve"
    || ($signal["selected_run_id"] ?? null) !== "smoke-run"
    || ($signal["input_received"] ?? null) !== true) {
    fwrite(STDERR, "Service image list, saved-view, query, or signal contract mismatch.\n");
    exit(1);
}
' "$list" "$saved_views" "$selected_saved_view" "$filtered_list" "$detail" "$query" "$signal" "$workflow_streams_required" "$image_release" "$packaged_filter_contract"

server_requests="$(docker logs "$server_container" 2>&1)"
case "$packaged_sdk_version" in
    2.0.*) ;;
    2.*) printf '%s\n' "$server_requests" | grep -F 'GET /api/cluster/info' >/dev/null ;;
esac
if [ "$workflow_streams_required" -eq 1 ]; then
    printf '%s\n' "$server_requests" | grep -F 'GET /api/workflows/smoke-order/runs/smoke-run/streams' >/dev/null
fi
printf '%s\n' "$server_requests" | grep -F 'POST /api/workflows/smoke-order/runs/smoke-run/query/current' >/dev/null
printf '%s\n' "$server_requests" | grep -F 'POST /api/workflows/smoke-order/runs/smoke-run/signal/approve' >/dev/null

streams_summary='preserved the pre-Streams release contract'
if [ "$workflow_streams_required" -eq 1 ]; then
    streams_summary='passed the Workflow Streams route and lifecycle contract'
fi
summary="Service image $image_release ($image_revision) rejected DB_DATABASE=:memory:,"\
" uses PHP SDK ${packaged_sdk_version}, reached /up in ${startup_elapsed}s,"\
" passed saved-view narrowing, selected-run, query, and signal checks, and ${streams_summary}."
printf '%s\n' "$summary"
