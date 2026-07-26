<?php

namespace Waterline\Tests\Unit;

use Waterline\Repositories\Workflow\Interfaces\WorkflowRepositoryInterface;
use Waterline\Tests\TestCase;
use Waterline\WaterlineApplicationServiceProvider;
use Waterline\WaterlineServiceProvider;

class WaterlineServiceProviderTest extends TestCase
{
    public function testPackageDiscoveryBindsDefaultRepositoryWithoutApplicationProvider(): void
    {
        $this->assertNull($this->app->getProvider(WaterlineApplicationServiceProvider::class));
        $this->assertTrue($this->app->bound(WorkflowRepositoryInterface::class));
        $this->assertInstanceOf(
            WorkflowRepositoryInterface::class,
            $this->app->make(WorkflowRepositoryInterface::class)
        );
    }

    public function testPackageRegistrationPreservesApplicationRepositoryBinding(): void
    {
        $repository = $this->createMock(WorkflowRepositoryInterface::class);
        $this->app->bind(WorkflowRepositoryInterface::class, static fn () => $repository);

        (new WaterlineServiceProvider($this->app))->register();

        $this->assertSame(
            $repository,
            $this->app->make(WorkflowRepositoryInterface::class)
        );
    }
}
