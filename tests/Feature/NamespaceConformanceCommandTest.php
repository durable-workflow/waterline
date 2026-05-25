<?php

namespace Waterline\Tests\Feature;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Waterline\Console\NamespaceConformanceCommand;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;

class NamespaceConformanceCommandTest extends TestCase
{
    public function testCommandEmitsWaterlineOperatorNamespaceVisibilityShard(): void
    {
        $commandOptions = [
            '--namespace-a' => 'billing',
            '--namespace-b' => 'shipping',
            '--shared-namespace' => 'shared',
            '--run-id' => 'waterline-ns-test',
            '--artifact-version' => [
                'server=0.2.186',
                'cli=0.1.67',
                'workflow=2.0.0-alpha.177',
                'sdk-python=0.4.78',
                'waterline=2.0.0-alpha.61',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
        ];

        $firstReportPath = $this->ephemeralPath('waterline-namespace-conformance');
        $this->artisan('waterline:namespace-conformance', $commandOptions + [
            '--output' => $firstReportPath,
        ])->assertSuccessful();

        $firstReport = $this->readJson($firstReportPath);
        $this->assertScheduleFixturesDeletedIncludingTrashed($firstReport['waterline_operator_visibility']['fixture_ids']['schedule_ids']);

        $reportPath = $this->ephemeralPath('waterline-namespace-conformance');
        $this->artisan('waterline:namespace-conformance', $commandOptions + [
            '--output' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $waterline = $scenarios['waterline_operator_namespace_visibility'];
        $visibility = $report['waterline_operator_visibility'];

        $this->assertSame('durable-workflow.v2.namespace-runtime.result', $report['schema']);
        $this->assertSame('waterline-operator-namespace-shard', $report['coverage_scope']);
        $this->assertSame('non_passing', $report['outcome']);
        $this->assertSame('2.0.0-alpha.61', $report['artifact_versions']['waterline']);
        $this->assertSame('packagist_package', $report['artifact_sources']['waterline']);

        $this->assertSame('pass', $waterline['status']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['operator_scope']['namespace']);
        $this->assertSame('tenant', $visibility['tenant_a_scoped_views']['operator_scope']['authority']);
        $this->assertSame('shipping', $visibility['tenant_b_scoped_views']['operator_scope']['namespace']);
        $this->assertSame('tenant', $visibility['tenant_b_scoped_views']['operator_scope']['authority']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['workflow_list']['includes_own_run']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['workflow_list']['excludes_foreign_run']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['workflow_list']['foreign_search_attribute_absent']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['workflow_list']['operator_scope']['namespace']);
        $this->assertSame('billing-visible', $visibility['tenant_a_scoped_views']['workflow_list']['search_attribute_value_visible']);
        $this->assertSame($visibility['api_captures'], $report['api_captures']);
        $this->assertSame('/api/flows/completed', $visibility['tenant_a_scoped_views']['api_captures']['workflow_list']['path']);
        $this->assertSame('/waterline/api/flows/completed', $visibility['tenant_a_scoped_views']['api_captures']['workflow_list']['request_path']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['api_captures']['workflow_list']['json']['operator_scope']['namespace']);
        $this->assertStringContainsString(
            'billing-visible',
            json_encode($visibility['tenant_a_scoped_views']['api_captures']['workflow_list']['json'], JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'shipping-secret',
            json_encode($visibility['tenant_a_scoped_views']['api_captures']['workflow_list']['json'], JSON_THROW_ON_ERROR),
        );
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['workflow_detail']['namespace']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['workflow_detail']['operator_scope']['namespace']);
        $this->assertSame('billing-visible', $visibility['tenant_a_scoped_views']['workflow_detail']['search_attribute_value_visible']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['foreign_workflow_detail']['not_found']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['schedule_list']['includes_own_schedule']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['schedule_list']['excludes_foreign_schedule']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['schedule_list']['operator_scope']['namespace']);
        $this->assertSame('billing-schedule', $visibility['tenant_a_scoped_views']['schedule_list']['search_attribute_value_visible']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['schedule_detail']['namespace']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['schedule_detail']['operator_scope']['namespace']);
        $this->assertSame('billing-schedule', $visibility['tenant_a_scoped_views']['schedule_detail']['search_attribute_value_visible']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['foreign_schedule_detail']['not_found']);
        $this->assertTrue($visibility['unscoped_view_authority']['documented_safe_authority']);
        $this->assertSame('cluster', $visibility['unscoped_view_authority']['workflow_list']['operator_scope']['authority']);
        $this->assertNull($visibility['unscoped_view_authority']['workflow_list']['operator_scope']['namespace']);
        $this->assertTrue($visibility['unscoped_view_authority']['workflow_list']['includes_tenant_a_run']);
        $this->assertTrue($visibility['unscoped_view_authority']['workflow_list']['includes_tenant_b_run']);
        $this->assertSame('billing-visible', $visibility['unscoped_view_authority']['workflow_list']['tenant_a_search_attribute_visible']);
        $this->assertSame('shipping-secret', $visibility['unscoped_view_authority']['workflow_list']['tenant_b_search_attribute_visible']);
        $this->assertSame('cluster', $visibility['unscoped_view_authority']['schedule_list']['operator_scope']['authority']);
        $this->assertNull($visibility['unscoped_view_authority']['schedule_list']['operator_scope']['namespace']);
        $this->assertTrue($visibility['unscoped_view_authority']['schedule_list']['includes_tenant_a_schedule']);
        $this->assertTrue($visibility['unscoped_view_authority']['schedule_list']['includes_tenant_b_schedule']);
        $this->assertSame('billing-schedule', $visibility['unscoped_view_authority']['schedule_list']['tenant_a_search_attribute_visible']);
        $this->assertSame('shipping-schedule', $visibility['unscoped_view_authority']['schedule_list']['tenant_b_search_attribute_visible']);
        $this->assertWaterlineEvidencePassCriteriaRequireHttpCaptures($visibility);

        $this->assertSame('not_covered', $scenarios['nexus_explicit_cross_namespace_invocation']['status']);
        $this->assertNotEmpty($scenarios['nexus_explicit_cross_namespace_invocation']['linked_findings']);

        $this->assertSame(
            0,
            WorkflowRun::query()->whereIn('id', $visibility['fixture_ids']['workflow_run_ids'])->count(),
            'The command should clean up workflow fixture rows by default.',
        );
        $this->assertSame(
            0,
            WorkflowSchedule::query()->whereIn('schedule_id', $visibility['fixture_ids']['schedule_ids'])->count(),
            'The command should clean up schedule fixture rows by default.',
        );
        $this->assertScheduleFixturesDeletedIncludingTrashed($visibility['fixture_ids']['schedule_ids']);
    }

    /**
     * @param array<string, mixed> $visibility
     */
    private function assertWaterlineEvidencePassCriteriaRequireHttpCaptures(array $visibility): void
    {
        $command = app(NamespaceConformanceCommand::class);
        $passes = Closure::bind(
            function (array $evidence): bool {
                return $this->waterlineEvidencePassed($evidence);
            },
            $command,
            NamespaceConformanceCommand::class,
        );

        $this->assertNotNull($passes);
        $this->assertTrue($passes($visibility));

        $missingResponseScope = $visibility;
        data_set($missingResponseScope, 'tenant_a_scoped_views.workflow_list.operator_scope.namespace', null);
        $this->assertFalse($passes($missingResponseScope));

        $missingOwnSearchAttribute = $visibility;
        data_set($missingOwnSearchAttribute, 'tenant_a_scoped_views.workflow_list.search_attribute_value_visible', null);
        $this->assertFalse($passes($missingOwnSearchAttribute));

        $missingHttpCapture = $visibility;
        unset($missingHttpCapture['tenant_a_scoped_views']['api_captures']['workflow_list']);
        $this->assertFalse($passes($missingHttpCapture));

        $wrongCapturedScope = $visibility;
        data_set($wrongCapturedScope, 'tenant_a_scoped_views.api_captures.workflow_list.json.operator_scope.namespace', 'shipping');
        $this->assertFalse($passes($wrongCapturedScope));

        $missingCapturedOwnSearchAttribute = $visibility;
        data_set($missingCapturedOwnSearchAttribute, 'tenant_a_scoped_views.api_captures.workflow_list.json.data.0.search_attributes.tenant_marker', null);
        $this->assertFalse($passes($missingCapturedOwnSearchAttribute));

        $missingUnscopedRequest = $visibility;
        unset($missingUnscopedRequest['unscoped_view_authority']['workflow_list']);
        $this->assertFalse($passes($missingUnscopedRequest));
    }

    private function ephemeralPath(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.Str::ulid().'.json';
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $scheduleIds
     */
    private function assertScheduleFixturesDeletedIncludingTrashed(array $scheduleIds): void
    {
        $this->assertSame(
            0,
            $this->scheduleFixtureQueryIncludingTrashed($scheduleIds)->count(),
            'The command should force-delete schedule fixture rows, including trashed rows, by default.',
        );
    }

    /**
     * @param list<string> $scheduleIds
     */
    private function scheduleFixtureQueryIncludingTrashed(array $scheduleIds): Builder
    {
        $query = WorkflowSchedule::query();

        if ($this->scheduleUsesSoftDeletes()) {
            $query->withTrashed();
        }

        return $query->whereIn('schedule_id', $scheduleIds);
    }

    private function scheduleUsesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(WorkflowSchedule::class), true);
    }
}
