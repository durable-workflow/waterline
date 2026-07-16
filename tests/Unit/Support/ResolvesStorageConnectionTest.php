<?php

namespace Waterline\Tests\Unit\Support;

use Waterline\Models\WorkerBuildIdRollout;
use Waterline\Models\WorkerRegistration;
use Waterline\Tests\TestCase;
use Waterline\Traits\ResolvesStorageConnection;

class ResolvesStorageConnectionTest extends TestCase
{
    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    public function testObserverModelsUseWaterlineLocalStorageConnectionResolver(): void
    {
        foreach ([WorkerRegistration::class, WorkerBuildIdRollout::class] as $model) {
            $traits = class_uses_recursive($model);

            $this->assertContains(ResolvesStorageConnection::class, $traits);
            $this->assertNotContains('Workflow\\Traits\\ResolvesStorageConnection', $traits);
        }
    }

    public function testResolverUsesSharedWorkflowStorageConnection(): void
    {
        config()->set('workflows.storage.connection', 'server_storage');

        $this->assertSame('server_storage', (new WorkerRegistration())->getConnectionName());
        $this->assertSame('server_storage', (new WorkerBuildIdRollout())->getConnectionName());
    }

    public function testResolverFallsBackToModelConnectionWhenSharedWorkflowStorageIsUnset(): void
    {
        config()->set('workflows.storage.connection', null);

        $registration = (new WorkerRegistration())->setConnection('waterline_host');
        $rollout = (new WorkerBuildIdRollout())->setConnection('waterline_host');

        $this->assertSame('waterline_host', $registration->getConnectionName());
        $this->assertSame('waterline_host', $rollout->getConnectionName());
    }
}
