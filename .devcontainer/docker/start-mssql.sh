#!/usr/bin/env bash

set -euo pipefail

: "${MSSQL_SA_PASSWORD:=P@ssword}"
: "${MSSQL_PID:=Developer}"

mkdir -p /var/opt/mssql
chown -R mssql:mssql /var/opt/mssql

run_sqlcmd() {
    /opt/mssql-tools18/bin/sqlcmd -S localhost -U sa -P "$MSSQL_SA_PASSWORD" -Q "$1" -C \
        || /opt/mssql-tools/bin/sqlcmd -S localhost -U sa -P "$MSSQL_SA_PASSWORD" -Q "$1"
}

su -s /bin/bash -c '/opt/mssql/bin/sqlservr' mssql &
sqlservr_pid=$!

cleanup() {
    if kill -0 "$sqlservr_pid" 2>/dev/null; then
        kill "$sqlservr_pid"
        wait "$sqlservr_pid"
    fi
}

trap cleanup EXIT INT TERM

attempts=60
until run_sqlcmd "SELECT 1" >/dev/null 2>&1; do
    attempts=$((attempts - 1))
    if [ "$attempts" -le 0 ]; then
        echo "SQL Server did not become ready in time" >&2
        exit 1
    fi
    sleep 2
done

run_sqlcmd "IF DB_ID('testing') IS NULL CREATE DATABASE testing"

trap - EXIT INT TERM
wait "$sqlservr_pid"
