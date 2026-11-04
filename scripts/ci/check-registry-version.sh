#!/usr/bin/env bash
# Decide whether the fixed 2.15.0 registry tag may be written.
# Exit 0 when the tag is absent (an explicit manifest-unknown answer) or already
# names the expected index. Any other answer, including an unreadable registry,
# refuses publication. Uses only tools present on hosted runners (no ripgrep).
set -euo pipefail

work="$1"
expected="$2"
[[ "$expected" =~ ^sha256:[0-9a-f]{64}$ ]]

if skopeo inspect --raw --authfile "$work/anonymous-auth.json" \
    docker://docker.io/durableworkflow/waterline:2.15.0 > "$work/existing-index.json" 2> "$work/existing-index-error.txt"; then
    actual="sha256:$(sha256sum "$work/existing-index.json" | cut -d ' ' -f 1)"
    if [[ "$actual" != "$expected" ]]; then
        echo "Registry tag 2.15.0 already names $actual, not $expected; publication refused." >&2
        exit 1
    fi
elif ! grep -Eiq 'manifest unknown|manifest_unknown' "$work/existing-index-error.txt"; then
    echo 'Cannot establish fixed registry tag absence; publication refused.' >&2
    exit 1
fi
