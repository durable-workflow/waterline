<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;

class V2HealthControllerTest extends TestCase
{
    public function testHealthEndpointReturnsV2HealthSnapshot(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array.driver', 'array');

        $this->get('/waterline/api/v2/health')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('checks.0.name', 'backend_capabilities')
            ->assertJsonPath('checks.0.status', 'ok')
            ->assertJsonPath('operator_metrics.backend.supported', true);
    }

    public function testHealthEndpointReturnsUnavailableForBlockingBackendIssues(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.sync.driver', 'sync');

        $this->get('/waterline/api/v2/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('healthy', false)
            ->assertJsonPath('checks.0.name', 'backend_capabilities')
            ->assertJsonPath('checks.0.status', 'error')
            ->assertJsonFragment(['code' => 'queue_sync_unsupported']);
    }
}
