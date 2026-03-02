<?php

namespace Waterline\Tests\Feature;

use Exception;
use Illuminate\Testing\Fluent\AssertableJson;
use Waterline\Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;

class DashboardWorkflowTest extends TestCase
{
    public function testIndexNone()
    {
        $storedWorkflow = StoredWorkflow::create([
            'class' => 'WorkflowClass',
            'arguments' => 'N;',
            'output' => 'N;',
            'status' => 'created',
        ]);

        $storedLog = $storedWorkflow->logs()->create([
            'index' => 0,
            'now' => now()->toDateTimeString(),
            'class' => 'Activity1Class',
            'result' => 'N;',
        ]);

        $storedWorkflow->exceptions()->create([
            'class' => 'Activity2Class',
            'exception' => Serializer::serialize(new Exception('ExceptionMessage')),
        ]);


        $response = $this
            ->get('/waterline/api/flows/'.$storedWorkflow->id);

        $response
            ->assertStatus(200)
            ->assertJson(
                fn (AssertableJson $json) => $json
                    ->where('id', $storedWorkflow->id)
                    ->where('class', 'WorkflowClass')
                    ->where('arguments', 'N;')
                    ->where('connection', null)
                    ->where('queue', null)
                    ->where('output', 'N;')
                    ->where('status', 'created')
                    ->whereType('created_at', 'string')
                    ->whereType('updated_at', 'string')
                    ->has('parents', 0)
                    ->has('continuedWorkflows', 0)
                    ->has(
                        'logs',
                        1,
                        fn (AssertableJson $log) => $log
                            ->where('id', $storedLog->id)
                            ->where('index', 0)
                            ->whereType('now', 'string')
                            ->where('class', 'Activity1Class')
                            ->where('result', 'N;')
                            ->whereType('created_at', 'string')
                    )
                    ->has(
                        'exceptions',
                        1,
                        fn (AssertableJson $exception) => $exception
                            ->where(
                                'id',
                                fn ($value) => is_string($value) || is_int($value)
                            )
                            ->whereType('code', 'string')
                            ->whereType('exception', 'string')
                            ->where('class', 'Activity2Class')
                            ->whereType('created_at', 'string')
                    )
                    ->has('chartData', 2)
                    ->where('chartData.0.x', 'WorkflowClass')
                    ->where('chartData.0.type', 'Workflow')
                    ->where('chartData.1.x', 'Activity1Class')
                    ->where('chartData.1.type', 'Activity')
                    ->whereAllType([
                        'chartData.0.y.0' => 'integer',
                        'chartData.0.y.1' => 'integer',
                        'chartData.1.y.0' => 'integer',
                        'chartData.1.y.1' => 'integer',
                    ])

            );
    }

    public function testShowExtractsWorkflowOptionsFromArguments()
    {
        $storedWorkflow = StoredWorkflow::create([
            'class' => 'WorkflowClass',
            'arguments' => Serializer::serialize([
                'arguments' => [],
                'options' => [
                    'connection' => 'redis',
                    'queue' => 'other',
                ],
                '__constructor' => 'arguments',
            ]),
            'output' => 'N;',
            'status' => 'completed',
        ]);

        $response = $this
            ->get('/waterline/api/flows/'.$storedWorkflow->id);

        $response
            ->assertStatus(200)
            ->assertJson(
                fn (AssertableJson $json) => $json
                    ->where('id', $storedWorkflow->id)
                    ->where('arguments', serialize([]))
                    ->where('connection', 'redis')
                    ->where('queue', 'other')
                    ->etc()
            );
    }
}
