#!/usr/bin/env sh

set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT HUP INT TERM

authority="$work/qualified-prerelease.json"
python3 "$root/scripts/resolve-current-prerelease.py" json > "$authority"

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

python3 - "$authority" "$work/embedded/composer.lock" "$work/service/composer.lock" <<'PY'
import json
import re
import sys
from pathlib import Path

authority_path, embedded_path, service_path = map(Path, sys.argv[1:])
authority = json.loads(authority_path.read_text(encoding="utf-8"))
qualified_waterline = authority["packages"]["waterline"]
pattern = re.compile(r"^2\.0\.0-rc\.[1-9][0-9]*$")
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
    if any(pattern.fullmatch(version) is None for version in packages.values()):
        raise SystemExit(f"{graph} onboarding graph escaped the 2.0 RC channel")
    if packages["durable-workflow/waterline"] != qualified_waterline:
        raise SystemExit(
            f"{graph} onboarding selected Waterline "
            f"{packages['durable-workflow/waterline']}, not qualified "
            f"{qualified_waterline}"
        )
    resolved[graph] = packages

print(
    json.dumps(
        {
            "schema": "durable-workflow.waterline.onboarding-composer-qualification/v1",
            "channel": "^2.0@RC",
            "qualified_waterline": qualified_waterline,
            "graphs": resolved,
            "outcome": "pass",
        },
        sort_keys=True,
    )
)
PY
