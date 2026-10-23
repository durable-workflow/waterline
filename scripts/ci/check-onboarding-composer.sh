#!/usr/bin/env sh

set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -r -- "$work"' EXIT HUP INT TERM

python3 "$root/scripts/ci/onboarding_composer.py" \
    --authority "$root/release/current-product-tuple.json" \
    --candidate-composer "$root/composer.json" --directory "$work" --write-manifests

for kind in approved onboarding; do
    for graph in embedded service; do
        directory="$work/$kind-$graph"
        composer --working-dir "$directory" install \
            --no-interaction --no-progress --no-scripts --quiet
        case "$graph" in
            embedded) peer_class='Workflow\V2\Workflow' ;;
            service) peer_class='DurableWorkflow\Client' ;;
            *) echo "Unknown onboarding graph: $graph" >&2; exit 1 ;;
        esac
        php -r '
            require $argv[1];
            foreach (["Waterline\\Waterline", $argv[2]] as $class) {
                if (!class_exists($class)) {
                    fwrite(STDERR, "onboarding class is not autoloadable: {$class}\n");
                    exit(1);
                }
            }
        ' "$directory/vendor/autoload.php" "$peer_class"
    done
done

python3 "$root/scripts/ci/onboarding_composer.py" \
    --authority "$root/release/current-product-tuple.json" \
    --candidate-composer "$root/composer.json" --directory "$work" "$@"
