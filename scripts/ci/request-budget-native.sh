#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
work="${NATIVE_BUDGET_ROOT:?Expected isolated browser tool directory}"
image="${WATERLINE_IMAGE:?Expected exact published image}"
prefix="waterline-budget-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-$$"
backend="$prefix-backend"
application="$prefix-app"
proxy="$prefix-proxy"
network="$prefix-network"
evidence="$work/request-budget-evidence"
mkdir -p "$evidence"

cleanup() {
    result=$?
    trap - EXIT
    for container in "$proxy" "$application" "$backend"; do
        docker logs "$container" > "$evidence/$container.log" 2>&1 || true
        docker rm -f "$container" >/dev/null 2>&1 || true
    done
    docker network rm "$network" >/dev/null 2>&1 || true
    exit "$result"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

cp "$root/scripts/ci/request-budget-host.php" "$work/host.php"
cp "$root/scripts/ci/request-budget-server.php" "$work/server.php"
cp "$root/tests/Fixtures/service-mode-server.php" "$work/protocol-server.php"
printf '%s\n' '{"mode":"healthy","service_budget":0.2,"browser_budget":0.8}' > "$work/mode.json"
docker pull --platform "$EXPECTED_PLATFORM" "$image"
docker image inspect --format '{{json .Config.Labels}}' "$image" > "$evidence/native-labels.json"
docker run --rm --network none --user 1000:1000 --cpus 1 --memory 128m --tmpfs /data:rw,size=1m --entrypoint php "$image" -r '
    require "/app/vendor/autoload.php";
    echo json_encode([
        "sdk" => Composer\InstalledVersions::getPrettyVersion("durable-workflow/sdk"),
        "sdk_source" => Composer\InstalledVersions::getReference("durable-workflow/sdk"),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
' > "$evidence/native-sdk.json"
python3 - "$evidence" "$WATERLINE_SOURCE_DATA" <<'PY'
import json, os, platform, sys
from pathlib import Path
evidence, source = map(Path, sys.argv[1:])
labels = json.loads((evidence / 'native-labels.json').read_text())
sdk = json.loads((evidence / 'native-sdk.json').read_text())
locked = json.loads((source / 'standalone/composer.lock').read_text())
expected = [package for package in locked['packages'] if package['name'] == 'durable-workflow/sdk']
if len(expected) != 1 or sdk != {'sdk': expected[0]['version'], 'sdk_source': expected[0]['source']['reference']}:
    raise SystemExit('Published native SDK differs from its exact release lock.')
if labels.get('org.opencontainers.image.revision') != os.environ['EXPECTED_SOURCE_COMMIT'] or labels.get('org.opencontainers.image.version') != os.environ['EXPECTED_WATERLINE_VERSION']:
    raise SystemExit('Published native image source/version identity mismatch.')
if platform.machine() != os.environ['EXPECTED_MACHINE']:
    raise SystemExit('Browser qualification must run on the actual native architecture.')
(evidence / 'native-identity.json').write_text(json.dumps({
    'kind': 'published-native-image-browser', 'image': os.environ['WATERLINE_IMAGE'],
    'source': os.environ['EXPECTED_SOURCE_COMMIT'], 'version': os.environ['EXPECTED_WATERLINE_VERSION'],
    'platform': os.environ['EXPECTED_PLATFORM'], 'machine': platform.machine(), **sdk,
}, indent=2) + '\n')
PY

docker network create "$network" >/dev/null
docker run -d --name "$backend" --network "$network" --network-alias budget-backend \
    --user "$(id -u):$(id -g)" --cpus 1 --memory 128m \
    --tmpfs /data:rw,size=1m \
    --mount "type=bind,src=$work,dst=/budget" --workdir /budget --entrypoint php \
    "$image" -S 0.0.0.0:18081 server.php >/dev/null
docker run -d --name "$application" --network "$network" \
    --cpus 2 --memory 768m --tmpfs /data:rw,size=64m -p 127.0.0.1::8080 \
    -e WATERLINE_SERVER_ENDPOINT=http://budget-backend:18081 \
    -e WATERLINE_SERVER_TOKEN=smoke-token -e WATERLINE_NAMESPACE=smoke \
    -e WATERLINE_ACCESS_MODE=operator -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
    -e WATERLINE_SERVER_REQUEST_TIMEOUT=0.2 -e WATERLINE_BROWSER_REQUEST_TIMEOUT=0.8 \
    "$image" >/dev/null
port="$(docker inspect --format '{{(index (index .NetworkSettings.Ports "8080/tcp") 0).HostPort}}' "$application")"
ready=0
for attempt in $(seq 1 30); do
    if curl --fail --silent --connect-timeout 1 --max-time 1 "http://127.0.0.1:$port/up" >/dev/null; then ready=1; break; fi
    sleep 1
done
test "$ready" = 1

# Proxy only the controlled faults; HTML/assets and run APIs come from the actual
# immutable application image. The protocol backend is a separate real HTTP peer.
docker run -d --name "$proxy" --network host --user "$(id -u):$(id -g)" \
    --cpus 1 --memory 128m --tmpfs /data:rw,size=1m --mount "type=bind,src=$work,dst=/budget" --workdir /budget \
    -e "BUDGET_NATIVE_PORT=$port" --entrypoint php "$image" \
    -S 127.0.0.1:8080 host.php >/dev/null
export EXPECTED_CANDIDATE_SOURCE="$EXPECTED_SOURCE_COMMIT"
export FIXED_BROWSER_BUDGET_SECONDS=0.8
cd "$work"
proxy_ready=0
for attempt in $(seq 1 15); do
    if curl --fail --silent --max-time 1 http://127.0.0.1:8080/up >/dev/null; then proxy_ready=1; break; fi
    sleep 1
done
test "$proxy_ready" = 1
node "$root/scripts/ci/request-budget-browser.mjs"
