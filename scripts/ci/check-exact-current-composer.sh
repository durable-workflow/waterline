#!/usr/bin/env sh

set -eu

manifest="${EXACT_CURRENT_COMPOSER_MANIFEST:-composer.json}"
evidence="${EXACT_CURRENT_COMPOSER_EVIDENCE:-exact-current-composer-evidence.json}"
attempts="${EXACT_CURRENT_COMPOSER_ATTEMPTS:-32}"
sleep_seconds="${EXACT_CURRENT_COMPOSER_RETRY_SLEEP:-30}"

fail() {
    title="$1"
    message="$2"

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            printf '## %s\n\n' "$title"
            printf '%s\n' "$message"
        } >> "$GITHUB_STEP_SUMMARY"
    fi

    php -r '
$result = [
    "schema" => "durable-workflow.exact-current-composer-qualification/v1",
    "outcome" => "incomplete",
    "packages" => [
        "waterline" => $argv[2],
        "workflow" => $argv[3],
        "sdk-php" => $argv[4],
    ],
    "reason" => $argv[5],
];
file_put_contents($argv[1], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
' "$evidence" "$waterline" "$workflow" "$sdk" "$message"
    printf '::error title=%s::%s\n' "$title" "$message" >&2
    printf '%s\n' "$message" >&2
    exit 1
}

if [ ! -f "$manifest" ]; then
    printf '::error title=Waterline Composer manifest required::Waterline Composer manifest is absent: %s\n' "$manifest" >&2
    exit 1
fi

waterline="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $m["extra"]["durable-workflow"]["product-train"];' "$manifest")"
workflow="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $m["require-dev"]["durable-workflow/workflow"];' "$manifest")"
sdk="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR); echo $m["require"]["durable-workflow/sdk"];' "$manifest")"

case "$waterline:$workflow:$sdk" in
    *[!0-9A-Za-z.:-]*)
        printf 'Waterline exact-current Composer tuple contains an invalid version\n' >&2
        exit 1
        ;;
esac

case "$attempts" in
    ''|*[!0-9]*) fail "Invalid Composer convergence retry count" "EXACT_CURRENT_COMPOSER_ATTEMPTS must be a positive integer." ;;
esac
case "$sleep_seconds" in
    ''|*[!0-9]*) fail "Invalid Composer convergence retry delay" "EXACT_CURRENT_COMPOSER_RETRY_SLEEP must be a non-negative integer." ;;
esac
if [ "$attempts" -lt 1 ]; then
    fail "Invalid Composer convergence retry count" "EXACT_CURRENT_COMPOSER_ATTEMPTS must be at least 1."
fi

solver_root="$(mktemp -d)"
trap 'rm -rf "$solver_root"' EXIT HUP INT TERM
composer --working-dir "$solver_root" init \
    --name durable-workflow/exact-current-qualification \
    --no-interaction >/dev/null
composer --working-dir "$solver_root" config minimum-stability RC
composer --working-dir "$solver_root" config prefer-stable true
attempt=1
while ! composer --working-dir "$solver_root" require \
    --dry-run \
    --no-install \
    --no-interaction \
    --no-progress \
    "durable-workflow/waterline:${waterline}@RC" \
    "durable-workflow/workflow:${workflow}@RC" \
    "durable-workflow/sdk:${sdk}@RC"
do
    if [ "$attempt" -ge "$attempts" ]; then
        fail "Composer metadata did not converge" \
            "Composer could not install the exact Waterline ${waterline}, Workflow ${workflow}, and PHP SDK ${sdk} tuple after ${attempts} attempt(s)."
    fi
    printf 'Waiting for Composer metadata convergence (%s/%s): Waterline %s\n' \
        "$attempt" "$attempts" "$waterline" >&2
    sleep "$sleep_seconds"
    attempt=$((attempt + 1))
done

php -r '
$result = [
    "schema" => "durable-workflow.exact-current-composer-qualification/v1",
    "outcome" => "pass",
    "packages" => [
        "waterline" => $argv[2],
        "workflow" => $argv[3],
        "sdk-php" => $argv[4],
    ],
];
file_put_contents($argv[1], json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
' "$evidence" "$waterline" "$workflow" "$sdk"

printf 'Composer resolved Waterline %s, Workflow %s, and PHP SDK %s together.\n' \
    "$waterline" "$workflow" "$sdk"
