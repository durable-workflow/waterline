#!/usr/bin/env bash
# Give the protected publisher its own ~/.docker/config.json for the copy and
# attest steps, and put the hosted runner's original file back afterwards.
#
#   isolate  Move any existing config aside (mode 600) so login writes a fresh
#            file that holds only the docker.io entry.
#   restore  Remove the publication config and restore the runner's original.
#            Does nothing unless isolate ran, so it is safe in an always() step.
#
# attest-build-provenance reads registry credentials only from
# ~/.docker/config.json, so the publication login must live at that path.
# Contents are never printed.
set -euo pipefail

mode="${1:-}"
config="$HOME/.docker/config.json"
saved="${RUNNER_TEMP:?RUNNER_TEMP is required}/waterline-runner-docker-config.json"
marker="$RUNNER_TEMP/waterline-docker-config-isolated"

fail() {
    echo "::error::$1" >&2
    exit 1
}

case "$mode" in
    isolate)
        [[ ! -e "$marker" ]] || fail "The publication Docker config is already isolated."
        [[ ! -e "$saved" ]] || fail "A saved runner Docker config already exists at $saved; refusing to overwrite it."
        if [[ -e "$config" || -L "$config" ]]; then
            [[ -f "$config" && ! -L "$config" ]] || fail "$config is not a regular file; refusing to move it."
            mv -- "$config" "$saved"
            chmod 600 "$saved"
            echo "Moved the runner's existing Docker config aside for the publication window."
        fi
        [[ ! -e "$config" ]] || fail "$config still exists after isolation."
        mkdir -p -m 700 "$HOME/.docker"
        : > "$marker"
        ;;
    restore)
        # Before isolation the runner's own file is untouched, so leave it.
        [[ -e "$marker" ]] || exit 0
        rm -f -- "$config"
        if [[ -e "$saved" ]]; then
            mv -- "$saved" "$config"
            echo "Restored the runner's original Docker config."
        fi
        rm -f -- "$marker"
        ;;
    *)
        fail "Usage: publication-docker-config.sh isolate|restore"
        ;;
esac
