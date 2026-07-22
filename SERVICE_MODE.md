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
  durableworkflow/waterline:2.0.0-beta.5
```

Open `http://localhost:8080/waterline`. Bind the port to a private interface or
put an authenticating reverse proxy in front of it when
`WATERLINE_ALLOW_UNAUTHENTICATED=true` is used. The banner always identifies
the backend, namespace, server-auth configuration, and read-only/operator mode.

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

Waterline persistence stores saved views, display preferences, and Laravel
runtime state only. It is never configured with, and never reads, the
standalone server database. Mount `/data` when using the default SQLite
configuration. For MySQL or PostgreSQL, set `DATABASE_URL` or the ordinary
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` inputs.

The server token controls the backend role. Worker, queue, workflow, and
schedule observation require the server's operator role. Server health and
operator metrics can require its admin role. A 401, 403, unavailable SDK
capability, namespace error, or transport failure is returned as typed JSON and
shown as an explicit unavailable state by the shared UI.

## Docker Compose

[`deploy/docker-compose.service.yml`](deploy/docker-compose.service.yml) is a
ready-to-edit service deployment. It keeps Waterline state in its own volume
and accepts the standalone endpoint and token from the deployment environment.

## Embedded mode

Embedded Laravel applications install the same Waterline package and UI plus
the optional Workflow integration:

```bash
composer require \
  durable-workflow/waterline:2.0.0-beta.5@beta \
  durable-workflow/workflow:2.0.0-beta.5@beta \
  durable-workflow/sdk:2.0.0-beta.5@beta

php artisan waterline:install
```

The service adapter remains part of the package. Selecting it later only
requires the service deployment inputs; no alternate UI or service-only fork
is installed.
