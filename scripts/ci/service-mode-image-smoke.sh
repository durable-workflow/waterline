#!/usr/bin/env sh
set -eu

image="${WATERLINE_IMAGE:-waterline-service-smoke:local}"
container="waterline-service-smoke-${GITHUB_RUN_ID:-local}-$$"
data_volume="${container}-data"
mock_log="$(mktemp)"
response_body="$(mktemp)"
mock_pid=''
waterline_url=''
last_request=''
expected_response_status=''
response_status=''
response_content=''

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

    echo "Waterline container log:" >&2
    docker logs "$container" >&2 || true
    echo "Mock server log:" >&2
    cat "$mock_log" >&2 || true
}

cleanup() {
    status=$?
    trap - EXIT INT TERM

    if [ "$status" -ne 0 ]; then
        dump_diagnostics
    fi

    docker rm -f "$container" >/dev/null 2>&1 || true
    docker volume rm -f "$data_volume" >/dev/null 2>&1 || true
    if [ -n "$mock_pid" ]; then
        kill "$mock_pid" >/dev/null 2>&1 || true
    fi
    rm -f "$mock_log" "$response_body"

    exit "$status"
}

request() {
    expected_response_status="$1"
    last_request="$2"
    shift 2
    : >"$response_body"

    if ! response_status="$(
        curl --silent --show-error \
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
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if [ "${SERVICE_IMAGE_SKIP_BUILD:-0}" != "1" ]; then
    docker build --pull -t "$image" .
else
    docker pull "$image"
fi

php -S 0.0.0.0:18081 tests/Fixtures/service-mode-server.php >"$mock_log" 2>&1 &
mock_pid=$!

attempt=0
until request 200 \
    'GET http://127.0.0.1:18081/api/system/health' \
    --header 'Authorization: Bearer smoke-token' \
    --header 'X-Namespace: smoke' \
    --header 'X-Durable-Workflow-Control-Plane-Version: 2' \
    http://127.0.0.1:18081/api/system/health; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        exit 1
    fi
    sleep 1
done

docker volume create "$data_volume" >/dev/null

if [ -n "${HOSTNAME:-}" ] && docker inspect "$HOSTNAME" >/dev/null 2>&1; then
    runner_network_details="$(
        docker inspect \
            --format '{{range $name, $settings := .NetworkSettings.Networks}}{{println $name $settings.IPAddress}}{{end}}' \
            "$HOSTNAME" |
            awk '$1 != "bridge" && $1 != "host" && $1 != "none" && $2 != "" { print $1, $2; exit }'
    )"
    runner_network="${runner_network_details%% *}"
    runner_ip="${runner_network_details#* }"

    if [ -z "$runner_network_details" ] || [ -z "$runner_network" ] || [ -z "$runner_ip" ]; then
        echo "Unable to discover the containerized runner network and IPv4 address." >&2
        exit 1
    fi

    docker run -d --name "$container" \
        --network "$runner_network" \
        -v "$data_volume:/data" \
        -e "WATERLINE_SERVER_ENDPOINT=http://${runner_ip}:18081" \
        -e WATERLINE_SERVER_TOKEN=smoke-token \
        -e WATERLINE_NAMESPACE=smoke \
        -e WATERLINE_ACCESS_MODE=read_only \
        -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
        "$image" >/dev/null

    waterline_url="http://${container}:8080"
else
    docker run -d --name "$container" \
        --add-host host.docker.internal:host-gateway \
        -p 127.0.0.1::8080 \
        -v "$data_volume:/data" \
        -e WATERLINE_SERVER_ENDPOINT=http://host.docker.internal:18081 \
        -e WATERLINE_SERVER_TOKEN=smoke-token \
        -e WATERLINE_NAMESPACE=smoke \
        -e WATERLINE_ACCESS_MODE=read_only \
        -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
        "$image" >/dev/null

    port="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' "$container")"
    waterline_url="http://127.0.0.1:${port}"
fi

attempt=0
last_request="GET ${waterline_url}/up"
expected_response_status='200'
until response_status="$(
    curl --silent --show-error \
        --output "$response_body" \
        --write-out '%{http_code}' \
        "${waterline_url}/up"
)" && [ "$response_status" = "$expected_response_status" ]; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        exit 1
    fi
    sleep 1
done

request 200 \
    "GET ${waterline_url}/waterline/api/flows/running" \
    "${waterline_url}/waterline/api/flows/running"
list="$response_content"

request 200 \
    "GET ${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run" \
    "${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run"
detail="$response_content"

request 403 \
    "POST ${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run/signals/approve" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"arguments":["approved"]}' \
    "${waterline_url}/waterline/api/instances/smoke-order/runs/smoke-run/signals/approve"
status="$response_status"
read_only="$response_content"

php -r '
[$list, $detail, $status, $readOnly] = array_slice($argv, 1);
$list = json_decode($list, true, flags: JSON_THROW_ON_ERROR);
$detail = json_decode($detail, true, flags: JSON_THROW_ON_ERROR);
$readOnly = json_decode($readOnly, true, flags: JSON_THROW_ON_ERROR);
if (($list["data"][0]["instance_id"] ?? null) !== "smoke-order"
    || ($detail["timeline"][0]["event_type"] ?? null) !== "WorkflowStarted"
    || $status !== "403"
    || ($readOnly["reason"] ?? null) !== "waterline_read_only") {
    fwrite(STDERR, "Service image smoke contract mismatch.\n");
    exit(1);
}
' "$list" "$detail" "$status" "$read_only"
