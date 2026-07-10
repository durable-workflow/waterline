#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: worker-status-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Runs the focused published-artifact Waterline worker-status cell and writes:
  pins.json
  run-metadata.json
  source-hygiene.json
  waterline-worker-status-evidence.json
  waterline-worker-status-result.json

Required exact artifact pins:
  DW_SERVER_VERSION
  DW_CLI_VERSION
  DW_WORKFLOW_PHP_VERSION
  DW_WATERLINE_VERSION

Optional overrides:
  DW_SERVER_IMAGE                          Exact public tag or digest.
  DW_WATERLINE_HOST                        Docker host address for published-port probes; defaults to 127.0.0.1.
  DW_WATERLINE_WORKER_STATUS_AUTH_TOKEN   Defaults to dev-token.
  DW_WATERLINE_WORKER_STATUS_NAMESPACE    Defaults to waterline-worker-status.
  DW_WATERLINE_WORKER_STATUS_HEARTBEAT_SECONDS  Defaults to 2.
  DW_WATERLINE_WORKER_STATUS_STALE_SECONDS      Defaults to 7.
  DW_WATERLINE_WORKER_STATUS_COMPOSER_IMAGE     Defaults to composer:2.
  DW_WATERLINE_WORKER_STATUS_KEEP_RUN_ROOT      Set to 1 to retain scratch files.
USAGE
}

result_dir="${DW_WATERLINE_WORKER_STATUS_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      [[ -n "$result_dir" ]] || { printf '%s\n' '--result-dir requires a value' >&2; exit 2; }
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-waterline-worker-status.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

RESULT_DIR="$result_dir" node "$script_dir/worker-status-published-artifacts.mjs"
