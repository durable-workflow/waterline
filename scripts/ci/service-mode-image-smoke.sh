#!/usr/bin/env sh
set -eu

image="${WATERLINE_IMAGE:-waterline-service-smoke:local}"
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
    docker pull "$image"
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
[$list, $savedViews, $selectedSavedView, $filteredList, $detail, $query, $signal, $workflowStreamsRequired] = array_slice($argv, 1);
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
    || ($savedViews["filter_version"] ?? null) !== 6
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
' "$list" "$saved_views" "$selected_saved_view" "$filtered_list" "$detail" "$query" "$signal" "$workflow_streams_required"

server_requests="$(docker logs "$server_container" 2>&1)"
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
