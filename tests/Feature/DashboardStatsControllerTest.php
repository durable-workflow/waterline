<?php

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Workflow\Models\StoredWorkflow;

class DashboardStatsControllerTest extends TestCase
{
    protected function supportsSqlServerDatabaseQualification(): bool
    {
        return true;
    }

    public function testIndexNone()
    {
        $response = $this
            ->get('/waterline/api/stats');

        $response
            ->assertStatus(200)
            ->assertJson([
                'flows' => 0,
                'flows_per_minute' => 0,
                'flows_past_hour' => 0,
                'exceptions_past_hour' => 0,
                'failed_flows_past_week' => 0,
                'max_wait_time_workflow' => null,
                'max_duration_workflow' => null,
                'max_exceptions_workflow' => null,
            ]);
    }

    public function testIndexOne()
    {
        $workflow = StoredWorkflow::create([
            'class' => 'class',
            'arguments' => null,
            'output' => null,
            'status' => 'created',
        ]);

        $response = $this
            ->get('/waterline/api/stats');

        $response
            ->assertStatus(200)
            ->assertJson([
                'flows' => 1,
                'flows_per_minute' => 0.016666666666666666,
                'flows_past_hour' => 1,
                'exceptions_past_hour' => 0,
                'failed_flows_past_week' => 0,
                'max_wait_time_workflow' => null,
                'max_duration_workflow' => $workflow->toArray(),
                'max_exceptions_workflow' => null,
            ]);
    }

    public function testIndexTwo()
    {
        $workflows = [StoredWorkflow::create([
            'class' => 'class',
            'arguments' => null,
            'output' => null,
            'status' => 'created',
        ]), StoredWorkflow::create([
            'class' => 'class',
            'arguments' => null,
            'output' => null,
            'status' => 'created',
        ])];

        $response = $this
            ->get('/waterline/api/stats');

        $response
            ->assertStatus(200)
            ->assertJson([
                'flows' => 2,
                'flows_per_minute' => 0.03333333333333333,
                'flows_past_hour' => 2,
                'exceptions_past_hour' => 0,
                'failed_flows_past_week' => 0,
                'max_wait_time_workflow' => null,
                'max_duration_workflow' => $workflows[0]->toArray(),
                'max_exceptions_workflow' => null,
            ]);
    }
}
