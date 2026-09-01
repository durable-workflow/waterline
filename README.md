# Waterline

<p align="center">
  <a href="https://github.com/durable-workflow/waterline/actions/workflows/php.yml?query=branch%3Av2"><img src="https://github.com/durable-workflow/waterline/actions/workflows/php.yml/badge.svg?branch=v2" alt="Build status"></a>
  <a href="https://packagist.org/packages/durable-workflow/waterline"><img src="https://img.shields.io/packagist/v/durable-workflow/waterline" alt="Latest Packagist version"></a>
  <a href="https://hub.docker.com/r/durableworkflow/waterline"><img src="https://img.shields.io/docker/pulls/durableworkflow/waterline" alt="Docker pulls"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/durable-workflow/waterline" alt="MIT license"></a>
</p>

Waterline is the operator UI for the technical runtime state of
[Durable Workflow](https://github.com/durable-workflow/workflow).

Waterline is for fleet health, queues, waits, retries, failures, repair,
history, and runtime diagnostics. Business dashboards should read
application-owned read models projected at domain milestones, with
`workflow_id` and `run_id` stored only as correlation references.

## Installation

Waterline uses one UI and operator behavior contract with two backend modes:

- Embedded mode is the Composer package inside a Laravel application and adds
  the optional `durable-workflow/workflow` integration.
- Service mode is the self-contained `durableworkflow/waterline` image. It
  needs no host PHP installation and connects to a standalone server through
  the published PHP SDK, never through the server database.

See [Waterline service mode](SERVICE_MODE.md) for the image, deployment inputs,
authorization modes, persistence boundary, and Docker Compose example.

### Embedded Laravel

This UI is installable via [Composer](https://getcomposer.org).

```bash
composer require \
    "durable-workflow/waterline:^2.0" \
    "durable-workflow/workflow:^2.0"

php artisan waterline:install
```

The standalone PHP SDK is not part of the embedded dependency graph.

## Authorization

Waterline exposes a dashboard at the `/waterline` URL. By default, you will only be able to access this dashboard in the local environment. However, within your `app/Providers/WaterlineServiceProvider.php` file, there is an authorization gate definition. This authorization gate controls access to Waterline in non-local environments.

```php
Gate::define('viewWaterline', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

This will allow only the single admin user to access the Waterline UI.

Isolated observer stacks that have no application users can opt in to
unauthenticated Waterline access:

```dotenv
WATERLINE_ALLOW_UNAUTHENTICATED=true
```

## Configuration

Waterline can display a thin environment strip above the dashboard so production and non-production tabs are visibly distinct before an operator acts:

```dotenv
WATERLINE_ENV_NAME=production
WATERLINE_ENV_COLOR=#dc3545
```

`WATERLINE_ENV_COLOR` accepts hex colors. Invalid values fall back to a neutral gray.

If your workflow IDs are strings (for example UUIDs) and do not sort in a useful order, publish the config and set `workflow_sort_column` to a timestamp column such as `created_at`:

```php
'workflow_sort_column' => 'created_at',
```

### Operator Preferences

Waterline persists small operator view preferences through
`GET /waterline/api/preferences/{surface}` and
`PUT /waterline/api/preferences/{surface}`. Supported surfaces are
`workflow-list`, `run-detail`, `schedules-list`, and `workers-list`; supported
keys are `tab`, `sort_direction`, `row_density`, `saved_view_id`, and
`columns`. Preferences are scoped to the authenticated Laravel user when one is
available, otherwise to `WATERLINE_PREFERENCES_SCOPE` for local installs.

URL query parameters still win for shared links. For example,
`?tab=timeline&sort=asc&density=dense&columns=workflow_id,status` returns those
values in `effective_preferences` without mutating the stored preferences.

## Upgrading Waterline

When upgrading to 2.0, let Composer resolve the supported package graph together
and publish the latest assets.

```bash
composer require --with-all-dependencies \
    "durable-workflow/waterline:^2.0" \
    "durable-workflow/workflow:^2.0"

php artisan waterline:publish
```

## Screenshots

These screenshots show the stable 2.0 operator surface.

### Dashboard

![Waterline dashboard](docs/screenshots/dashboard.png)

### Workflow Detail

![Waterline workflow detail](docs/screenshots/workflow-detail.png)

## Development

### Quick Start

Get a working Waterline dashboard in under 5 minutes:

```bash
# Clone and install
git clone https://github.com/durable-workflow/waterline.git
cd waterline
make install

# Start development environment (asset watch + server)
make dev
```

Open http://localhost:18280/waterline

The `make dev` command automatically:
- Sets up the SQLite database with migrations
- Builds and watches assets for changes
- Starts the workbench server
- Publishes assets to the correct location

### Available Commands

Run `make help` to see all available commands:

- `make dev` - Start development environment (recommended)
- `make install` - Install dependencies
- `make test` - Run PHPUnit test suite
- `make test-sqlite` / `make test-mysql` / `make test-pgsql` / `make test-mssql` - Run tests on specific database
- `make clean` - Clean build artifacts

### Manual Setup

If you prefer to run commands manually:

1. Install dependencies:
   ```bash
   composer install
   npm ci
   ```
2. Build assets:
   ```bash
   npm run production
   ```
3. Publish assets to testbench:
   ```bash
   ./vendor/bin/testbench waterline:publish
   ```
4. Run migrations:
   ```bash
   ./vendor/bin/testbench workbench:create-sqlite-db
   ./vendor/bin/testbench migrate:fresh --database=sqlite
   ```
5. Start server:
   ```bash
   composer run serve
   ```
6. Access dashboard at http://localhost:18280/waterline
