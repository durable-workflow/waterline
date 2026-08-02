#!/usr/bin/env sh

set -eu

manifest="${EXACT_CURRENT_COMPOSER_MANIFEST:-composer.json}"
evidence="${EXACT_CURRENT_COMPOSER_EVIDENCE:-exact-current-composer-evidence.json}"

if [ ! -f "$manifest" ]; then
    printf 'Waterline Composer manifest is absent: %s\n' "$manifest" >&2
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

solver_root="$(mktemp -d)"
trap 'rm -rf "$solver_root"' EXIT HUP INT TERM
composer --working-dir "$solver_root" init \
    --name durable-workflow/exact-current-qualification \
    --no-interaction >/dev/null
composer --working-dir "$solver_root" config minimum-stability RC
composer --working-dir "$solver_root" config prefer-stable true
composer --working-dir "$solver_root" require \
    --dry-run \
    --no-install \
    --no-interaction \
    --no-progress \
    "durable-workflow/waterline:${waterline}@RC" \
    "durable-workflow/workflow:${workflow}@RC" \
    "durable-workflow/sdk:${sdk}@RC"

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
