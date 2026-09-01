#!/usr/bin/env sh

set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT HUP INT TERM

release_authority="$work/current-public-release.txt"
verify_public_release_authority="${VERIFY_PUBLIC_RELEASE_AUTHORITY:-false}"
case "$verify_public_release_authority" in
    true)
        target_commit="$(git -C "$root" rev-parse HEAD)"
        python3 "$root/scripts/ci/resolve-current-waterline-release.py" \
            --target-commit "$target_commit" > "$release_authority"
        ;;
    false)
        : > "$release_authority"
        ;;
    *)
        echo "VERIFY_PUBLIC_RELEASE_AUTHORITY must be true or false" >&2
        exit 1
        ;;
esac

create_graph() {
    graph="$1"
    peer="$2"
    directory="$work/$graph"

    mkdir "$directory"
    composer --working-dir "$directory" init \
        --name "durable-workflow/waterline-$graph-onboarding-probe" \
        --no-interaction \
        --quiet
    composer --working-dir "$directory" config prefer-stable true
    composer --working-dir "$directory" require \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --quiet \
        'durable-workflow/waterline:^2.0@RC' \
        "$peer:^2.0@RC"

    case "$graph" in
        embedded)
            peer_class='Workflow\V2\Workflow'
            ;;
        service)
            peer_class='DurableWorkflow\Client'
            ;;
        *)
            echo "unknown onboarding graph: $graph" >&2
            exit 1
            ;;
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
}

create_graph embedded durable-workflow/workflow
create_graph service durable-workflow/sdk

python3 - "$release_authority" "$work/embedded/composer.lock" "$work/service/composer.lock" <<'PY'
import json
import re
import sys
from pathlib import Path

authority_path, embedded_path, service_path = map(Path, sys.argv[1:])
release_authority = authority_path.read_text(encoding="utf-8").split()
version_pattern = re.compile(
    r"^2\.0\.(?:0|[1-9][0-9]*)(?:-rc\.[1-9][0-9]*)?$"
)
commit_pattern = re.compile(r"^[0-9a-f]{40}$")
if release_authority and (
    len(release_authority) != 2
    or version_pattern.fullmatch(release_authority[0]) is None
    or commit_pattern.fullmatch(release_authority[1]) is None
):
    raise SystemExit("current public Waterline release authority is malformed")
qualified_waterline = release_authority[0] if release_authority else None
qualified_source_commit = release_authority[1] if release_authority else None
expected = {
    "embedded": {
        "durable-workflow/waterline",
        "durable-workflow/workflow",
    },
    "service": {
        "durable-workflow/sdk",
        "durable-workflow/waterline",
    },
}
resolved = {}

for graph, path in (("embedded", embedded_path), ("service", service_path)):
    lock = json.loads(path.read_text(encoding="utf-8"))
    packages = {
        package["name"]: package["version"]
        for package in lock["packages"]
        if package.get("name") in expected[graph]
    }
    if set(packages) != expected[graph]:
        raise SystemExit(f"{graph} onboarding graph did not resolve both roots")
    if any(version_pattern.fullmatch(version) is None for version in packages.values()):
        raise SystemExit(f"{graph} onboarding graph escaped the supported 2.0 line")
    if (
        qualified_waterline is not None
        and packages["durable-workflow/waterline"] != qualified_waterline
    ):
        raise SystemExit(
            f"{graph} onboarding selected Waterline "
            f"{packages['durable-workflow/waterline']}, not qualified "
            f"{qualified_waterline}"
        )
    resolved[graph] = packages

if qualified_waterline is None:
    qualified_waterline = resolved["embedded"]["durable-workflow/waterline"]
    if resolved["service"]["durable-workflow/waterline"] != qualified_waterline:
        raise SystemExit("public onboarding graphs selected different Waterline releases")

evidence = {
    "schema": "durable-workflow.waterline.onboarding-composer-qualification/v1",
    "authority": (
        "github-public-release"
        if qualified_source_commit is not None
        else "registry-graph-consensus"
    ),
    "channel": "2.0",
    "composer_constraint": "^2.0@RC",
    "qualified_waterline": qualified_waterline,
    "graphs": resolved,
    "outcome": "pass",
}
if qualified_source_commit is not None:
    evidence["qualified_source_commit"] = qualified_source_commit
print(json.dumps(evidence, sort_keys=True))
PY
