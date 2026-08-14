# Waterline service mode

Waterline is one operator product with two backend adapters. The Composer
package remains the embedded Laravel distribution. The versioned
`durableworkflow/waterline` image is the service distribution: it contains its
own PHP and Laravel runtime and reaches a standalone Durable Workflow server
only through `durable-workflow/sdk` and public HTTP contracts.

## Run the image

The host needs Docker, but does not need PHP, Laravel, Composer, or
`durable-workflow/workflow`.

```bash
docker run --rm -p 8080:8080 \
  -v waterline-data:/data \
  -e WATERLINE_SERVER_ENDPOINT=https://workflow.example.com \
  -e WATERLINE_SERVER_TOKEN=replace-with-a-server-token \
  -e WATERLINE_NAMESPACE=orders \
  -e WATERLINE_ACCESS_MODE=read_only \
  -e WATERLINE_ALLOW_UNAUTHENTICATED=true \
  durableworkflow/waterline:2.0.0-rc.21
```

Open `http://localhost:8080/waterline`. Bind the port to a private interface or
put an authenticating reverse proxy in front of it when
`WATERLINE_ALLOW_UNAUTHENTICATED=true` is used. The banner always identifies
the backend, namespace, server-auth configuration, and read-only/operator mode.

### Bounded startup and health

With the image already pulled, a clean container using the default SQLite
volume must reach `GET /up` within 30 seconds. The entrypoint prepares volume
ownership, then drops to the unprivileged `www-data` user before applying the
migrations already packaged in the image and binding the HTTP port. It also
generates an in-memory application key when one was not supplied. It does not
invoke Composer, install packages, or contact a package registry at runtime.

Migration execution defaults to a 20-second limit. Set
`WATERLINE_MIGRATION_TIMEOUT_SECONDS` to an integer from 1 through 60 when an
external database needs a different bounded allowance. Invalid configuration,
volume permissions, migration errors, and migration timeouts stop the
container with a non-zero status and a `waterline-service: startup failed`
message. Docker reports health as `starting` until `/up` succeeds; a bounded
initialization failure instead leaves an `exited` container and its exit code,
while a running service that later stops answering `/up` becomes `unhealthy`
within 30 seconds.

## Deployment inputs

| Input | Purpose | Default |
| --- | --- | --- |
| `WATERLINE_SERVER_ENDPOINT` | Standalone server base URL; required | none |
| `WATERLINE_SERVER_TOKEN` | Server control-plane bearer token; required for authenticated servers | none |
| `WATERLINE_NAMESPACE` | Namespace sent by the PHP SDK on every request | `default` |
| `WATERLINE_ACCESS_MODE` | `read_only` blocks mutations locally; `operator` enables server-authorized commands | `read_only` |
| `WATERLINE_ALLOW_UNAUTHENTICATED` | Allows access to the Waterline UI without host-Laravel users | `false` |
| `WATERLINE_PATH` | UI route prefix | `waterline` |
| `APP_URL` / `APP_KEY` | Public URL and optional persistent Laravel application key | generated at container start |
| `DATABASE_URL` | Waterline-owned MySQL or PostgreSQL persistence URL | none |
| `DB_CONNECTION` / `DB_*` | Waterline-owned persistence connection settings | SQLite at `/data/waterline.sqlite` |
| `WATERLINE_MIGRATION_TIMEOUT_SECONDS` | Bounded startup migration allowance (1-60 seconds) | `20` |

Waterline persistence stores saved views, display preferences, and Laravel
runtime state only. It is never configured with, and never reads, the
standalone server database. Mount `/data` when using the default SQLite
configuration. Service-mode SQLite databases must be file-backed so the
entrypoint migration process and the HTTP service process access the same
schema. The process-local `DB_DATABASE=:memory:` setting is rejected during
startup; use a mounted SQLite file such as `/data/waterline.sqlite` instead.
For MySQL or PostgreSQL, set `DATABASE_URL` or the ordinary `DB_HOST`,
`DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` inputs.

The server token controls the backend role. Worker, queue, workflow, and
schedule observation require the server's operator role. Server health and
operator metrics can require its admin role. A 401, 403, unavailable SDK
capability, namespace error, or transport failure is returned as typed JSON and
shown as an explicit unavailable state by the shared UI.

### Capacity-evidence compatibility

Service-mode capacity evidence is supported starting with Server
`2.0.0-rc.32`, PHP SDK `2.0.0-rc.14`, and Waterline `2.0.0-rc.19`. The PHP SDK
carries the Server's additive, versioned
`durable-workflow.v2.namespace-capacity-evidence` block through its operator
metrics method.

Waterline accepts only a Server-declared exact window for the configured
namespace. A Server that omits the contract, publishes an incomplete dimension
set, or does not publish the requested window receives the typed
`capacity_evidence_contract_unavailable` response. Waterline does not relabel a
fixed upstream window. It evaluates each snapshot against the request time and
allows at most one second of clock skew on either freshness boundary. Evidence
outside that bounded interval fails closed, while accepted output preserves the
Server's `generated_at`, `freshness.max_age_seconds`, and
`freshness.valid_until` values. Server `2.0.0-rc.32` snapshots the declared
windows for at most 30 seconds. Capacity recommendations remain diagnostic and
advisory; they cannot change plans, billing, or infrastructure.

### Worker health roster contract

`GET /waterline/api/v2/health` uses the same worker roster in embedded and
service mode. `operator_metrics.workers.registrations` contains active
registrations, while `stale_registrations` contains the disjoint stale roster.
`registration_count` is the total of both rosters, and
`active_registration_count` and `stale_registration_count` match their
respective roster lengths. The workers table and active-lease summary are
derived from the active roster; stale registrations remain visible in the
registration summary without contributing rows or leases.

## Docker Compose

[`deploy/docker-compose.service.yml`](deploy/docker-compose.service.yml) is a
ready-to-edit service deployment. It keeps Waterline state in its own volume
and accepts the standalone endpoint and token from the deployment environment.

## Laravel-hosted service adapter

Applications that host Waterline in Laravel while observing a standalone
server install the service graph explicitly. It contains the Waterline UI and
the PHP SDK, but not the embedded Workflow runtime:

```bash
composer require \
  durable-workflow/waterline:2.0.0-rc.21@RC \
  durable-workflow/sdk:2.0.0-rc.14@RC
```

Set `WATERLINE_BACKEND=service` together with the endpoint, token, namespace,
and access-mode inputs above. If service mode is selected without the SDK,
Waterline stops during package boot and reports the exact Composer command
needed for the current release tuple.

## Embedded mode

Embedded Laravel applications install the same Waterline package and UI plus
the optional Workflow integration:

```bash
composer require \
  durable-workflow/waterline:2.0.0-rc.21@RC \
  durable-workflow/workflow:2.0.0-rc.14@RC

php artisan waterline:install
```

The service adapter remains part of the package. Selecting it later requires
adding the PHP SDK and the service deployment inputs; no alternate UI or
service-only fork is installed.
