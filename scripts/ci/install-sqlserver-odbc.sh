#!/usr/bin/env bash

set -euo pipefail

started_at=$(date +%s)

if [ ! -r /etc/os-release ]; then
  echo "::error::Cannot identify the Ubuntu release for the Microsoft package repository."
  exit 1
fi

. /etc/os-release

if [ "${ID:-}" != ubuntu ] || [ -z "${VERSION_ID:-}" ]; then
  echo "::error::SQL Server ODBC setup requires an identified Ubuntu runner."
  exit 1
fi

runtime_temp_dir=${RUNNER_TEMP:-${TMPDIR:-/tmp}}
repository_package=$(mktemp "${runtime_temp_dir%/}/packages-microsoft-prod.XXXXXX.deb")
trap 'rm -f "$repository_package"' EXIT

curl --fail --location --silent --show-error \
  "https://packages.microsoft.com/config/ubuntu/${VERSION_ID}/packages-microsoft-prod.deb" \
  --output "$repository_package"
sudo dpkg --install "$repository_package"
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install --yes --no-install-recommends msodbcsql18 unixodbc

odbcinst -q -d -n "ODBC Driver 18 for SQL Server"

for tooling_package in mssql-tools mssql-tools18; do
  if dpkg-query --show --showformat='${Status}\n' "$tooling_package" 2>/dev/null |
    grep --quiet --line-regexp 'install ok installed'; then
    echo "::error::SQL Server command-line tooling was installed unexpectedly: ${tooling_package}."
    exit 1
  fi
done

if command -v sqlcmd >/dev/null 2>&1; then
  echo "::error::sqlcmd was installed unexpectedly."
  exit 1
fi

elapsed_seconds=$(($(date +%s) - started_at))
echo "sqlserver-odbc-setup-seconds=${elapsed_seconds}"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### SQL Server ODBC runtime setup"
    echo
    echo "- Elapsed time: ${elapsed_seconds}s"
    echo '- Runtime packages: `msodbcsql18`, `unixodbc`'
    echo '- `mssql-tools`/`sqlcmd` download: not requested'
  } >> "$GITHUB_STEP_SUMMARY"
fi
