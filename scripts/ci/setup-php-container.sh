#!/usr/bin/env bash
set -euo pipefail
umask 077

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
minor="${1:?Usage: setup-php-container.sh 8.2}"
image="$(python3 -I -S - "$root/scripts/ci/php-container-images.json" "$minor" <<'PY'
import json,re,sys
images=json.load(open(sys.argv[1]))
image=images[sys.argv[2]]
assert re.fullmatch(r'php:8\.2\.\d+-cli-bookworm@sha256:[0-9a-f]{64}', image)
print(image)
PY
)"
[[ -f "$root/.central-apt-source/docker/apt/require-fixed-bookworm-apt.sh" ]]
[[ "$(git -C "$root/.central-apt-source" rev-parse HEAD)" = 18783be6bef737cc778e4c7282a8797e39daa61a ]]
echo '2a36a2417e8947357ea6f6cbc2f6c53a834f3c069f8013a34d6d861b7123d576  '"$root/.central-apt-source/docker/apt/require-fixed-bookworm-apt.sh" | sha256sum --check --strict

runtime="waterline-php-ci:${minor}-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}"
docker build --file "$root/docker/php-ci/Dockerfile" --build-arg "PHP_IMAGE=$image" --tag "$runtime" "$root"
runtime_id="$(docker image inspect "$runtime" --format '{{.Id}}')"
docker run --rm --init --name "waterline-php-ci-probe-${minor}-${GITHUB_RUN_ID:-local}" \
    --cpus 1 --memory 512m --pids-limit 64 --network none "$runtime_id" \
    php -r '
        if (PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION !== $argv[1]) { throw new RuntimeException("The PHP matrix minor does not match the image."); }
        foreach (["mbstring", "intl", "bcmath", "sockets", "zip", "curl", "pcntl", "posix", "dom", "xml", "xmlwriter", "pdo_sqlite", "sqlite3"] as $extension) {
            if (!extension_loaded($extension)) { throw new RuntimeException("Missing extension: ".$extension); }
        }
        foreach (["pcntl_fork", "pcntl_waitpid", "pcntl_signal", "pcntl_async_signals", "posix_kill"] as $function) {
            if (!function_exists($function)) { throw new RuntimeException("Missing function: ".$function); }
        }
        if (extension_loaded("xdebug") || extension_loaded("pcov")) { throw new RuntimeException("Coverage must remain none."); }
        if (php_ini_loaded_file() !== "/usr/local/etc/php/php.ini") { throw new RuntimeException("Production INI must be loaded."); }
        echo json_encode(["php" => PHP_VERSION, "extensions" => get_loaded_extensions(), "ini" => php_ini_loaded_file(), "coverage" => "none"]), PHP_EOL;
    ' "$minor"
printf 'php_ci_image=%s\n' "$runtime_id"

state="$(mktemp -d "${RUNNER_TEMP:?RUNNER_TEMP is required}/waterline-php-ci.XXXXXX")"
# Keep host-executed wrappers and source outside all writable container mounts.
# The build caller writes dependencies/skeleton output and its generated lock only.
[[ ! -L "$root/vendor" && ! -L "$root/composer.lock" ]]
mkdir -p "$root/vendor" "$state/bin" "$state/data/tmp" "$state/data/composer-home/cache"
if [[ ! -e "$root/composer.lock" ]]; then
    printf '{}\n' > "$root/composer.lock"
fi
[[ -d "$root/vendor" && -f "$root/composer.lock" ]]
ln -sf "$root/scripts/ci/run-php-container.sh" "$state/bin/php"
ln -sf "$root/scripts/ci/run-php-container.sh" "$state/bin/composer"
WATERLINE_PHP_CI_TEST_IMAGE="$runtime_id" \
    python3 -I -S -B "$root/tests/ci/test_php_container_isolation.py" -v
WATERLINE_PHP_CI_TEST_IMAGE="$runtime_id" WATERLINE_PHP_CI_ROOT="$root" WATERLINE_PHP_CI_STATE="$state" \
    python3 -I -S -B "$root/tests/ci/test_php_container_tls.py" -v
{
    echo "WATERLINE_PHP_CI_IMAGE=$runtime_id"
    echo "WATERLINE_PHP_CI_ROOT=$root"
    echo "WATERLINE_PHP_CI_STATE=$state"
    echo "COMPOSER_HOME=$state/data/composer-home"
    echo "COMPOSER_CACHE_DIR=$state/data/composer-home/cache"
    echo "TMPDIR=$state/data/tmp"
} >> "${GITHUB_ENV:?GITHUB_ENV is required}"
echo "$state/bin" >> "${GITHUB_PATH:?GITHUB_PATH is required}"
