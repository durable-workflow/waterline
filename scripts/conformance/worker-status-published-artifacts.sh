#!/usr/bin/env bash
set -euo pipefail

MAX_DIAGNOSTIC_BYTES=2000
result_dir="${DW_WATERLINE_WORKER_STATUS_RESULT_DIR:-}"
result_path=""
runner_log_path=""
started_at=""
current_result_valid=0
runner_log_fresh=0
runner_pid=""

utc_now() {
  date -u +'%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || printf '%s' 'unknown'
}

set_result_paths() {
  result_path="$result_dir/waterline-worker-status-result.json"
  runner_log_path="$result_dir/waterline-worker-status-runner.log"
}

initialize_result_files() {
  set_result_paths
  if ((runner_log_fresh == 1)); then
    return 0
  fi

  # A shared result directory may be reused across invocations. Clear every
  # canonical output before either Node or the shell fallback can publish data.
  rm -f -- \
    "$result_path" \
    "$runner_log_path" \
    "$result_dir/waterline-worker-status-evidence.json" \
    "$result_dir/source-hygiene.json" \
    "$result_dir/run-metadata.json" \
    "$result_dir/pins.json" \
    2>/dev/null || return 1
  runner_log_fresh=1
}

ensure_result_dir() {
  local requested_dir="$result_dir"

  if [[ -n "$result_dir" ]] \
    && mkdir -p -- "$result_dir" 2>/dev/null \
    && initialize_result_files; then
    return 0
  fi

  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-waterline-worker-status.XXXXXX")" || return 1
  runner_log_fresh=0
  initialize_result_files || return 1
  if [[ -n "$requested_dir" ]]; then
    printf 'could not write structured evidence to %s; using %s\n' "$requested_dir" "$result_dir" >&2
  else
    printf 'structured evidence directory: %s\n' "$result_dir" >&2
  fi
}

# Escape a Bash string as JSON without increasing the decoded value. Running
# byte-wise also handles control bytes that JSON does not permit literally.
json_escape() {
  local value="${1-}"
  local character code index
  local LC_ALL=C

  for ((index = 0; index < ${#value}; index++)); do
    character="${value:index:1}"
    case "$character" in
      '"') printf '\\"' ;;
      \\) printf '\\\\' ;;
      $'\b') printf '\\b' ;;
      $'\f') printf '\\f' ;;
      $'\n') printf '\\n' ;;
      $'\r') printf '\\r' ;;
      $'\t') printf '\\t' ;;
      *)
        printf -v code '%d' "'$character"
        if ((code < 32)); then
          printf '\\u%04x' "$code"
        else
          printf '%s' "$character"
        fi
        ;;
    esac
  done
}

valid_utf8() {
  local value="${1-}"
  local character first second third fourth index=0 length width
  local LC_ALL=C

  length=${#value}
  while ((index < length)); do
    character="${value:index:1}"
    printf -v first '%d' "'$character"
    width=0

    if ((first <= 127)); then
      width=1
    elif ((first >= 194 && first <= 223 && index + 1 < length)); then
      printf -v second '%d' "'${value:index+1:1}"
      if ((second >= 128 && second <= 191)); then
        width=2
      fi
    elif ((first >= 224 && first <= 239 && index + 2 < length)); then
      printf -v second '%d' "'${value:index+1:1}"
      printf -v third '%d' "'${value:index+2:1}"
      if ((third >= 128 && third <= 191)) \
        && { ((first == 224 && second >= 160 && second <= 191)) \
          || ((first == 237 && second >= 128 && second <= 159)) \
          || ((first != 224 && first != 237 && second >= 128 && second <= 191)); }; then
        width=3
      fi
    elif ((first >= 240 && first <= 244 && index + 3 < length)); then
      printf -v second '%d' "'${value:index+1:1}"
      printf -v third '%d' "'${value:index+2:1}"
      printf -v fourth '%d' "'${value:index+3:1}"
      if ((third >= 128 && third <= 191 && fourth >= 128 && fourth <= 191)) \
        && { ((first == 240 && second >= 144 && second <= 191)) \
          || ((first == 244 && second >= 128 && second <= 143)) \
          || ((first >= 241 && first <= 243 && second >= 128 && second <= 191)); }; then
        width=4
      fi
    fi

    if ((width > 0)); then
      printf '%s' "${value:index:width}"
      ((index += width))
    else
      # Drop an incomplete or malformed byte. In particular, tail -c may
      # begin in the middle of a multibyte code point.
      ((index += 1))
    fi
  done
}

bounded_diagnostic() {
  local status="$1"
  local prefix="published runner exited with status $status before writing valid structured evidence"
  local available log_tail=""
  local LC_ALL=C

  available=$((MAX_DIAGNOSTIC_BYTES - ${#prefix} - 1))
  if ((runner_log_fresh == 1)) \
    && [[ -n "$runner_log_path" && -r "$runner_log_path" && $available -gt 0 ]]; then
    log_tail="$(tail -c "$available" -- "$runner_log_path" 2>/dev/null || true)"
    log_tail="$(valid_utf8 "$log_tail")"
  fi

  if [[ -n "$log_tail" ]]; then
    printf '%s\n%s' "$prefix" "$log_tail"
  else
    printf '%s' "$prefix"
  fi
}

synthesize_result() {
  local status="$1"
  local finished_at diagnostic temporary_path
  local server_version cli_version sdk_php_version workflow_version waterline_version server_image

  ensure_result_dir || {
    printf '%s\n' 'unable to create a directory for structured worker-status evidence' >&2
    return 1
  }

  finished_at="$(utc_now)"
  diagnostic="$(bounded_diagnostic "$status")"
  server_version="${DW_SERVER_VERSION:-}"
  cli_version="${DW_CLI_VERSION:-}"
  sdk_php_version="${DW_PHP_SDK_VERSION:-}"
  workflow_version="${DW_WORKFLOW_PHP_VERSION:-}"
  waterline_version="${DW_WATERLINE_VERSION:-}"
  cli_version="${cli_version#v}"
  sdk_php_version="${sdk_php_version#v}"
  workflow_version="${workflow_version#v}"
  waterline_version="${waterline_version#v}"
  server_image="${DW_SERVER_IMAGE:-durableworkflow/server:${server_version}}"
  temporary_path="${result_path}.tmp.$$"

  cat >"$temporary_path" <<JSON
{
  "schema": "durable-workflow.v2.waterline-worker-status-run-result",
  "version": 1,
  "scenario_id": "waterline_worker_status_visibility",
  "conformance_run_id": "shell-fallback-${finished_at}-$$",
  "started_at": "$(json_escape "${started_at:-$finished_at}")",
  "finished_at": "$(json_escape "$finished_at")",
  "outcome": "non_passing_runner_blocked",
  "runner_blocked": true,
  "classification": "waterline-worker-status-runner-blocked",
  "artifact_versions": {
    "server": "$(json_escape "$server_version")",
    "cli": "$(json_escape "$cli_version")",
    "sdk-php": "$(json_escape "$sdk_php_version")",
    "workflow": "$(json_escape "$workflow_version")",
    "waterline": "$(json_escape "$waterline_version")"
  },
  "artifact_sources": {
    "server": "$(json_escape "docker://${server_image}")",
    "cli": "github_release",
    "sdk-php": "$(json_escape "packagist://durable-workflow/sdk@${sdk_php_version}")",
    "workflow": "$(json_escape "packagist://durable-workflow/workflow@${workflow_version}")",
    "waterline": "$(json_escape "packagist://durable-workflow/waterline@${waterline_version}")"
  },
  "source_hygiene": null,
  "local_product_source_checkouts_used": false,
  "product_evidence_file": null,
  "product_evidence": null,
  "cleanup": {"results": [], "failures": []},
  "runner_error": {
    "message": "$(json_escape "$diagnostic")",
    "observed_at": "$(json_escape "$finished_at")",
    "published_command_started": false,
    "exit_code": $status,
    "evidence_origin": "shell_exit_fallback"
  }
}
JSON
  mv -f -- "$temporary_path" "$result_path"
}

on_exit() {
  local status="$1"
  trap - EXIT

  if ((status != 0)) && ((current_result_valid == 0)); then
    set +e
    synthesize_result "$status"
  fi
  exit "$status"
}

forward_signal() {
  local signal="$1"
  if [[ -n "$runner_pid" ]] && kill -0 "$runner_pid" 2>/dev/null; then
    kill -s "$signal" "$runner_pid" 2>/dev/null || true
  fi
}

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
  DW_PHP_SDK_VERSION
  DW_WORKFLOW_PHP_VERSION
  DW_WATERLINE_VERSION

Optional overrides:
  DW_SERVER_IMAGE                          Exact public tag or digest.
  DW_WATERLINE_HOST                        Docker host address for published-port probes; defaults to 127.0.0.1.
  DW_WATERLINE_WORKER_STATUS_AUTH_TOKEN   Defaults to dev-token.
  DW_WATERLINE_WORKER_STATUS_NAMESPACE    Defaults to waterline-worker-status.
  DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE
                                               Reuse a verified heartbeat-wave
                                               bootstrap receipt and namespace.
  DW_WATERLINE_WORKER_STATUS_HEARTBEAT_SECONDS  Defaults to 2.
  DW_WATERLINE_WORKER_STATUS_STALE_SECONDS      Defaults to 7.
  DW_WATERLINE_WORKER_STATUS_READINESS_ATTEMPT_SECONDS  Defaults to 5.
  DW_WATERLINE_WORKER_STATUS_READINESS_DEADLINE_SECONDS Defaults to 120.
  DW_WATERLINE_WORKER_STATUS_COMPOSER_IMAGE     Defaults to composer:2.
  DW_WATERLINE_WORKER_STATUS_KEEP_RUN_ROOT      Set to 1 to retain scratch files.
USAGE
}

trap 'on_exit $?' EXIT
started_at="$(utc_now)"

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
initialize_result_files

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

runner_status=0
trap 'forward_signal INT' INT
trap 'forward_signal TERM' TERM
RESULT_DIR="$result_dir" node "$script_dir/worker-status-published-artifacts.mjs" &
runner_pid=$!
while true; do
  if wait "$runner_pid"; then
    runner_status=0
    break
  else
    runner_status=$?
    # A trapped signal interrupts Bash's wait before the Node process has
    # finished its structured result and cleanup path. Keep waiting after the
    # signal has been forwarded.
    if kill -0 "$runner_pid" 2>/dev/null; then
      continue
    fi
    break
  fi
done
runner_pid=""
trap - INT TERM

if [[ -s "$result_path" ]] && node -e '
  const fs = require("node:fs");
  const result = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
  if (result?.schema !== "durable-workflow.v2.waterline-worker-status-run-result") process.exit(1);
' "$result_path" >/dev/null 2>&1; then
  current_result_valid=1
elif ((runner_status == 0)); then
  printf '%s\n' 'published runner exited successfully without valid structured evidence' >&2
  runner_status=1
fi

exit "$runner_status"
