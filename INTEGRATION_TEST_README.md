# Waterline Phase 0 Integration Test

## Overview

Phase 0 integration test validates that Waterline can successfully query and render workflow data from a running `durableworkflow/server` container.

This is the **foundational baseline test** for Waterline → Server integration.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  PHPUnit Test Process                                       │
│  ┌────────────────────────────────────────────────────────┐ │
│  │  ServerIntegrationTest                                 │ │
│  │  1. HTTP → Server API (create workflows)              │ │
│  │  2. MySQL → Server Database (query runs/history)      │ │
│  │  3. HTTP → Waterline Controllers (render views)       │ │
│  └────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
                           │
                           ↓
┌─────────────────────────────────────────────────────────────┐
│  Docker Compose Stack (docker-compose.integration.yml)      │
│                                                              │
│  ┌────────────────┐   ┌──────────┐   ┌──────────┐         │
│  │  server:8081   │──→│  mysql   │←──│  redis   │         │
│  │  (API + DB)    │   │  :33066  │   │  :63799  │         │
│  └────────────────┘   └──────────┘   └──────────┘         │
│           │                                                  │
│           ↓                                                  │
│  ┌────────────────┐                                         │
│  │  worker        │                                         │
│  │  (queue)       │                                         │
│  └────────────────┘                                         │
└─────────────────────────────────────────────────────────────┘
```

## Prerequisites

- Docker and Docker Compose installed
- Server repository at `../server` (relative to waterline repo)
- PHP 8.2+ with MySQL extension
- Composer dependencies installed

## Quick Start

### 1. Start Integration Stack

```bash
cd /path/to/waterline
docker-compose -f docker-compose.integration.yml up -d
```

Wait for services to become healthy (~30-60 seconds):

```bash
docker-compose -f docker-compose.integration.yml ps
```

All services should show "healthy" or "Up" status.

### 2. Run Integration Test

```bash
vendor/bin/phpunit tests/Feature/ServerIntegrationTest.php
```

Or run specific test:

```bash
vendor/bin/phpunit --filter it_can_query_workflow_runs_from_server_database
```

### 3. Cleanup

```bash
docker-compose -f docker-compose.integration.yml down -v
```

The `-v` flag removes volumes (cleans up test data).

## Configuration

### Environment Variables

Override defaults via `.env` or export:

```bash
# Database connection (defaults work with docker-compose.integration.yml)
INTEGRATION_DB_HOST=localhost
INTEGRATION_DB_PORT=33066
INTEGRATION_DB_DATABASE=durable_workflow
INTEGRATION_DB_USERNAME=workflow
INTEGRATION_DB_PASSWORD=workflow
```

### Docker Compose

`docker-compose.integration.yml` uses non-standard ports to avoid conflicts:

- Server API: `8081` (instead of 8080)
- MySQL: `33066` (instead of 3306)
- Redis: `63799` (instead of 6379)

## Test Coverage

`ServerIntegrationTest.php` validates:

1. **Query Workflow Runs** - Waterline can SELECT from `workflow_runs` table
2. **Query Workflow History** - Waterline can SELECT from `workflow_history_events` table
3. **Render Run Detail View** - Waterline controller can render `/waterline/v2/flow/{runId}`
4. **List Workflow Runs** - Waterline can list multiple runs via `/waterline/v2`
5. **Query Workflow Tasks** - Waterline can access `workflow_tasks` table schema

## Troubleshooting

### Server Not Healthy

If test is skipped with "Server container is not healthy":

```bash
# Check container status
docker-compose -f docker-compose.integration.yml ps

# View server logs
docker-compose -f docker-compose.integration.yml logs server

# View bootstrap logs (migrations)
docker-compose -f docker-compose.integration.yml logs server-bootstrap

# Restart stack
docker-compose -f docker-compose.integration.yml down -v
docker-compose -f docker-compose.integration.yml up -d
```

### Connection Refused

If test fails with "Connection refused" on port 33066:

1. Verify MySQL container is healthy:
   ```bash
   docker-compose -f docker-compose.integration.yml ps mysql
   ```

2. Test connection directly:
   ```bash
   mysql -h 127.0.0.1 -P 33066 -u workflow -pworkflow durable_workflow -e "SELECT 1"
   ```

3. Check if port is already in use:
   ```bash
   lsof -i :33066
   ```

### Server Build Fails

If server image build fails:

```bash
# Build manually to see detailed errors
cd ../server
docker build -t durableworkflow-server .

# Or use docker-compose build
cd ../waterline
docker-compose -f docker-compose.integration.yml build server
```

### Test Times Out

If test hangs during `ensureServerIsHealthy()`:

- Increase timeout in test (default: 30 seconds)
- Check server container logs for startup errors
- Verify healthcheck is passing: `docker inspect <container> | jq '.[0].State.Health'`

## Phase 0 Exit Criteria

To mark Phase 0 complete and merge to master, this integration test must:

- ✅ Start durableworkflow/server container successfully
- ✅ Create workflow data via server API
- ✅ Query workflow runs from server database
- ✅ Query workflow history from server database
- ✅ Render workflow run detail view
- ✅ List workflow runs via Waterline controllers
- ✅ Pass in CI/CD pipeline

## CI Integration

Add to `.github/workflows/test.yml`:

```yaml
jobs:
  integration:
    name: Integration Test (Waterline ↔ Server)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v2

      - name: Start integration stack
        run: |
          docker-compose -f docker-compose.integration.yml up -d
          docker-compose -f docker-compose.integration.yml ps

      - name: Wait for server health
        run: |
          timeout 60 sh -c 'until curl -f http://localhost:8081/api/health; do sleep 2; done'

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mysql, redis

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run integration test
        run: vendor/bin/phpunit tests/Feature/ServerIntegrationTest.php

      - name: Show logs on failure
        if: failure()
        run: docker-compose -f docker-compose.integration.yml logs

      - name: Cleanup
        if: always()
        run: docker-compose -f docker-compose.integration.yml down -v
```

## Related Issues

- Issue #246: Waterline Phase 0 - v2 Foundation
- Issue #247: Waterline Phase 1 - Operator-Grade Timeline

## Next Steps

Once Phase 0 integration test passes:

1. Add database seeder for realistic test data
2. Add `make dev` command for local development
3. Assess Phase 0 → master readiness
4. Begin Phase 1 enhancements (typed event renderers, drill-down UX)
