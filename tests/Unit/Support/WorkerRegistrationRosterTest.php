<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Waterline\Support\WorkerRegistrationRoster;

final class WorkerRegistrationRosterTest extends TestCase
{
    public function testItBuildsDisjointActiveAndStaleRostersForEveryFleetShape(): void
    {
        $active = [
            ['worker_id' => 'worker-active', 'namespace' => 'orders', 'task_queue' => 'orders', 'status' => 'active'],
        ];
        $stale = [
            ['worker_id' => 'worker-stale', 'namespace' => 'orders', 'task_queue' => 'orders', 'status' => 'stale'],
        ];

        $scenarios = [
            'active-only' => [$active, [], 1, 0],
            'stale-only' => [[], $stale, 0, 1],
            'mixed' => [$active, $stale, 1, 1],
        ];

        foreach ($scenarios as $name => [$activeInput, $staleInput, $activeCount, $staleCount]) {
            $roster = WorkerRegistrationRoster::from($activeInput, $staleInput);

            $this->assertCount($activeCount, $roster['registrations'], $name);
            $this->assertCount($staleCount, $roster['stale_registrations'], $name);
            $this->assertSame($activeCount, $roster['active_registration_count'], $name);
            $this->assertSame($staleCount, $roster['stale_registration_count'], $name);
            $this->assertSame($activeCount + $staleCount, $roster['registration_count'], $name);
        }
    }

    public function testAStaleObservationWinsWithoutDuplicatingTheRegistration(): void
    {
        $active = [
            [
                'worker_id' => 'worker-1',
                'namespace' => 'orders',
                'task_queue' => 'orders',
                'status' => 'stale',
                'current_leases' => 2,
            ],
        ];
        $stale = [
            [
                'worker_id' => 'worker-1',
                'namespace' => 'orders',
                'task_queue' => 'orders-v2',
                'current_leases' => 0,
            ],
        ];

        $roster = WorkerRegistrationRoster::from($active, $stale);

        $this->assertSame([], $roster['registrations']);
        $this->assertSame(1, $roster['registration_count']);
        $this->assertSame(0, $roster['active_registration_count']);
        $this->assertSame(1, $roster['stale_registration_count']);
        $this->assertSame('stale', $roster['stale_registrations'][0]['status']);
        $this->assertSame(0, $roster['stale_registrations'][0]['current_leases']);
    }
}
