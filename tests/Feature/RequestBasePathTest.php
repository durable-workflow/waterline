<?php

namespace Waterline\Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Contracts\Http\Kernel;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

class RequestBasePathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waterline.engine_source', 'v2');
        $this->app->make(Kernel::class)->pushMiddleware(WaterlinePrefixTrustProxies::class);
    }

    public function testRootDeploymentKeepsItsObserverLinksAndDashboardPath(): void
    {
        $this->assertDeploymentPaths('', 'waterline');
    }

    public function testTrustedForwardedPrefixIsIncludedInEveryObserverLinkAndDashboardPath(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->withHeader('X-Forwarded-Prefix', '/admin');

        $this->assertDeploymentPaths('/admin', 'waterline');
    }

    public function testUntrustedForwardedPrefixIsIgnored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->withHeader('X-Forwarded-Prefix', '/untrusted');

        $this->assertDeploymentPaths('', 'waterline');
    }

    public function testTrustedPrefixCombinesWithACustomWaterlinePath(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->withHeader('X-Forwarded-Prefix', '/admin');
        config()->set('waterline.path', 'operations/workflows');
        $this->app->make('Waterline\WaterlineServiceProvider', ['app' => $this->app])->boot();

        $this->assertDeploymentPaths('/admin', 'operations/workflows');
    }

    public function testSubdirectoryDeploymentUsesTheResolvedScriptBasePath(): void
    {
        $this->withServerVariables([
            'SCRIPT_NAME' => '/admin/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
            'PHP_SELF' => '/admin/index.php',
        ]);

        $this->assertDeploymentPaths('/admin', 'waterline', '/admin');
    }

    private function assertDeploymentPaths(string $baseUrl, string $waterlinePath, string $requestPrefix = ''): void
    {
        $instance = WorkflowInstance::create([
            'id' => 'prefix-instance',
            'workflow_class' => 'PrefixWorkflow',
            'workflow_type' => 'prefix.workflow',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        $run = WorkflowRun::create([
            'id' => '01JPREFIX000000000000000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'PrefixWorkflow',
            'workflow_type' => 'prefix.workflow',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now(),
        ]);
        $instance->update(['current_run_id' => $run->id]);

        $apiPath = '/'.$waterlinePath.'/api';
        $instancePath = '/instances/'.$instance->id;
        $runPath = $instancePath.'/runs/'.$run->id;
        $this->getJson('http://localhost'.$requestPrefix.$apiPath.$runPath)
            ->assertOk()
            ->assertJsonPath('observer_state.paths', [
                'selected_run_detail' => $baseUrl.$apiPath.$runPath,
                'selected_run_history_export' => $baseUrl.$apiPath.$runPath.'/history-export',
                'selected_run_query_template' => $baseUrl.$apiPath.$runPath.'/queries/{query}',
                'selected_run_update_template' => $baseUrl.$apiPath.$runPath.'/updates/{update}',
                'selected_run_update_lookup_template' => $baseUrl.$apiPath.$runPath.'/updates/{updateId}',
                'instance_query_template' => $baseUrl.$apiPath.$instancePath.'/queries/{query}',
                'instance_update_template' => $baseUrl.$apiPath.$instancePath.'/updates/{update}',
                'instance_update_lookup_template' => $baseUrl.$apiPath.$instancePath.'/updates/{updateId}',
            ]);

        $this->get('http://localhost'.$requestPrefix.'/'.$waterlinePath)
            ->assertOk()
            ->assertViewHas('waterlineBootstrap', function (array $bootstrap) use ($baseUrl, $waterlinePath): bool {
                return $bootstrap['path'] === ltrim($baseUrl.'/'.$waterlinePath, '/');
            });
    }
}

class WaterlinePrefixTrustProxies extends TrustProxies
{
    protected $proxies = ['127.0.0.1'];

    protected $headers = Request::HEADER_X_FORWARDED_PREFIX;
}
