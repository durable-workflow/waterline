<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Waterline\Tests\TestCase;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\WorkerCompatibilityFleet;

class V2DashboardObservationTest extends TestCase
{
    public function testEmbeddedHttpDashboardCountsUtcProjectionsInALocalApplication(): void
    {
        $originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Kyiv', 'waterline.engine_source' => 'v2', 'waterline.namespace' => 'billing']);
        date_default_timezone_set('Europe/Kyiv');
        $now = Carbon::parse('2026-10-07T07:42:00Z')->setTimezone('Europe/Kyiv');
        Carbon::setTestNow($now);

        try {
            foreach ([-1, 0, 1] as $seconds) {
                $startedAt = $now->copy()->utc()->subHour()->addSeconds($seconds)->setTimezone('Europe/Kyiv');
                $this->seedSummary('sorted-'.$seconds, $startedAt);
                $this->seedSummary('legacy-'.$seconds, $startedAt, projected: false);
            }
            $this->seedSummary('outside', $now, namespace: 'outside');
            $this->getJson('/waterline/api/stats')
                ->assertOk()
                ->assertJsonPath('flows_past_hour', 4)
                ->assertJsonPath('flows', 6)
                ->assertJsonPath('workflow_scope.namespace', 'billing');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    /**
     * @param list<int> $expiryOffsets
     * @dataProvider heartbeatCases
     */
    #[DataProvider('heartbeatCases')]
    public function testEmbeddedHttpDashboardWorksWithoutDatabaseWrites(array $expiryOffsets): void
    {
        config(['waterline.engine_source' => 'v2', 'waterline.namespace' => 'billing']);
        WorkerCompatibilityFleet::clear();
        foreach ($expiryOffsets as $index => $offset) {
            $this->seedHeartbeat('worker-'.$index, now()->addSeconds($offset));
        }
        $this->seedHeartbeat('outside', now()->addMinute(), 'outside');
        $db = DB::connection();
        $db->beginTransaction();
        $db->enableQueryLog();
        $db->flushQueryLog();

        try {
            if ($db->getDriverName() === 'pgsql') {
                $db->statement('SET TRANSACTION READ ONLY');
            } elseif ($db->getDriverName() === 'sqlite') {
                $db->statement('PRAGMA query_only = ON');
            }
            $this->getJson('/waterline/api/stats')->assertOk()->assertJsonPath('flows', 0);
            $this->assertSame(count($expiryOffsets) + 1, DB::table('workflow_worker_compatibility_heartbeats')->count());
            $writes = array_filter($db->getQueryLog(), static fn (array $query): bool =>
                preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query']) === 1);
            $this->assertSame([], $writes);
        } finally {
            $db->rollBack();
            if ($db->getDriverName() === 'sqlite') {
                $db->statement('PRAGMA query_only = OFF');
            }
            $db->disableQueryLog();
        }
    }

    /**
     * @return array<string, array{list<int>}>
     */
    public static function heartbeatCases(): array
    {
        return [
            'empty scope' => [[]],
            'expired only' => [[-1, -60]],
            'active only' => [[0, 60]],
            'mixed' => [[-1, 0, 60]],
        ];
    }

    private function seedSummary(string $id, Carbon $startedAt, string $namespace = 'billing', bool $projected = true): void
    {
        $identity = [
            'id' => $id, 'namespace' => $namespace, 'workflow_type' => 'dashboard.test',
            'created_at' => $startedAt->format('Y-m-d H:i:s.u'), 'updated_at' => $startedAt->format('Y-m-d H:i:s.u'),
        ];
        DB::table('workflow_instances')->insert([...$identity, 'workflow_class' => 'Dashboard', 'run_count' => 1]);
        DB::table('workflow_runs')->insert([
            ...$identity, 'workflow_instance_id' => $id, 'workflow_class' => 'Dashboard', 'run_number' => 1,
            'status' => 'completed', 'started_at' => $startedAt, 'closed_at' => $startedAt,
        ]);
        DB::table('workflow_run_summaries')->insert([
            ...$identity, 'workflow_instance_id' => $id, 'class' => 'Dashboard', 'run_number' => 1,
            'status' => 'completed', 'status_bucket' => 'completed', 'started_at' => $startedAt, 'closed_at' => $startedAt,
            'sort_timestamp' => $projected ? RunSummarySortKey::timestamp($startedAt) : null,
        ]);
    }

    private function seedHeartbeat(string $workerId, Carbon $expiresAt, string $namespace = 'billing'): void
    {
        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => $workerId, 'scope_key' => $workerId, 'namespace' => $namespace,
            'supported' => '["build"]', 'queue' => 'default', 'recorded_at' => now(), 'expires_at' => $expiresAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
