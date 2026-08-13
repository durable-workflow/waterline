#!/usr/bin/env bash
set -euo pipefail

waterline_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
server_root="${INTEGRATION_SERVER_SOURCE_PATH:-$waterline_root/../server}"
expected_server_version=2.0.0-rc.32
auth_token=integration-test-token-123
server_pid=
state_dir=

cleanup() {
  status=$?
  trap - EXIT
  set +e

  if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
    kill "$server_pid" 2>/dev/null
    for _ in {1..20}; do
      kill -0 "$server_pid" 2>/dev/null || break
      sleep 0.1
    done
    if kill -0 "$server_pid" 2>/dev/null; then
      kill -KILL "$server_pid" 2>/dev/null
    fi
    wait "$server_pid" 2>/dev/null
  fi

  if [[ -n "$state_dir" ]]; then
    case "$state_dir" in
      /dev/shm/waterline-service-capacity.*|/tmp/waterline-service-capacity.*)
        rm -rf -- "$state_dir"
        ;;
    esac
  fi

  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for required in \
  "$waterline_root/vendor/bin/phpunit" \
  "$server_root/artisan" \
  "$server_root/composer.json" \
  "$server_root/vendor/autoload.php"; do
  if [[ ! -e "$required" ]]; then
    printf 'service-capacity-tuple: missing prerequisite %s\n' "$required" >&2
    exit 1
  fi
done

server_version="$(php -r '
  $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
  echo $manifest["extra"]["durable-workflow"]["product-train"] ?? "";
' "$server_root/composer.json")"
if [[ "$server_version" != "$expected_server_version" ]]; then
  printf 'service-capacity-tuple: expected Server %s, found %s\n' \
    "$expected_server_version" "${server_version:-<missing>}" >&2
  exit 1
fi

state_parent=/tmp
if [[ -d /dev/shm && -w /dev/shm ]]; then
  state_parent=/dev/shm
fi
state_dir="$(mktemp -d "$state_parent/waterline-service-capacity.XXXXXX")"
database="$state_dir/server.sqlite"
server_log="$state_dir/server.log"
touch "$database"

server_port="$(php -r '
  $socket = stream_socket_server("tcp://127.0.0.1:0", $errorCode, $errorMessage);
  if ($socket === false) {
      fwrite(STDERR, $errorMessage.PHP_EOL);
      exit(1);
  }
  $address = stream_socket_get_name($socket, false);
  fclose($socket);
  echo substr($address, strrpos($address, ":") + 1);
')"
server_url="http://127.0.0.1:$server_port"

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY=base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI=
export APP_URL="$server_url"
export LOG_CHANNEL=stderr
export DB_CONNECTION=sqlite
export DB_DATABASE="$database"
export CACHE_STORE=array
export CACHE_DRIVER=array
export QUEUE_CONNECTION=sync
export SESSION_DRIVER=array
export DW_AUTH_DRIVER=token
export DW_AUTH_TOKEN="$auth_token"
export DW_DEFAULT_NAMESPACE=default
unset APP_VERSION

(
  cd "$server_root"
  php artisan migrate --force --no-interaction
)

(
  cd "$server_root"
  exec php artisan serve --host=127.0.0.1 --port="$server_port" --no-reload
) >"$server_log" 2>&1 &
server_pid=$!

healthy=false
for _ in {1..30}; do
  if curl --fail --silent --max-time 2 "$server_url/api/health" >/dev/null; then
    healthy=true
    break
  fi
  if ! kill -0 "$server_pid" 2>/dev/null; then
    break
  fi
  sleep 1
done

if [[ "$healthy" != true ]]; then
  printf 'service-capacity-tuple: Server did not become healthy at %s\n' "$server_url" >&2
  tail -n 200 "$server_log" >&2 || true
  exit 1
fi

(
  cd "$waterline_root"
  INTEGRATION_SERVER_URL="$server_url" \
  INTEGRATION_SERVER_SOURCE_PATH="$server_root" \
  INTEGRATION_SERVER_REQUIRED=1 \
  INTEGRATION_DB_CONNECTION=sqlite \
  INTEGRATION_DB_DATABASE="$database" \
    vendor/bin/phpunit \
      --configuration=phpunit-sqlite.xml \
      --filter=test_service_capacity_evidence_flows_through_the_declared_release_tuple \
      tests/Feature/ServerIntegrationTest.php
)
