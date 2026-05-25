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
use Workflow\V2\Support\PlatformConformanceSuite;

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
                'cli=published_install_script',
                'workflow=published_composer_package',
                'sdk-python=published_pypi_package',
                'waterline=published_package',
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
        $this->assertSame(PlatformConformanceSuite::VERSION, $report['suite_version']);
        $this->assertSame('waterline-operator-namespace-shard', $report['coverage_scope']);
        $this->assertSame('non_passing', $report['outcome']);
        $this->assertSame('2.0.0-alpha.61', $report['artifact_versions']['waterline']);
        $this->assertSame('2.0.0-alpha.177', $report['artifact_versions']['workflow']);
        $this->assertSame('2.0.0-alpha.177', $report['artifact_versions']['workflow-php']);
        $this->assertSame('published_package', $report['artifact_sources']['waterline']);
        $this->assertSame('published_composer_package', $report['artifact_sources']['workflow']);
        $this->assertSame('published_composer_package', $report['artifact_sources']['workflow-php']);
        $this->assertSame('waterline_contract_surface', $report['runtime_matrix']['claimed_targets'][0]);
        $this->assertContains('waterline_operator_namespace_visibility', $report['runtime_matrix']['covered_scenarios']);

        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('pass', $waterline['status']);
        $this->assertSame('pass', $scenarios['result_record_and_product_finding_routing']['status']);
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
        $this->assertSame('/api/stats', $visibility['tenant_a_scoped_views']['operator_api_stats']['path']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['operator_api_stats']['operator_scope']['namespace']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['operator_api_stats']['flow_count_covers_fixture_run']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['operator_api_stats']['excludes_foreign_run']);
        $this->assertSame('/', $visibility['tenant_a_scoped_views']['dashboard_view']['path']);
        $this->assertSame('/waterline/', $visibility['tenant_a_scoped_views']['dashboard_view']['request_path']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['dashboard_view']['scope_label_visible']);
        $this->assertTrue($visibility['tenant_a_scoped_views']['dashboard_view']['scope_value_visible']);
        $this->assertSame('billing', $visibility['tenant_a_scoped_views']['dashboard_view']['script_operator_scope']['namespace']);
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
        $this->assertSame('cluster', $visibility['unscoped_view_authority']['operator_api_stats']['operator_scope']['authority']);
        $this->assertNull($visibility['unscoped_view_authority']['operator_api_stats']['operator_scope']['namespace']);
        $this->assertTrue($visibility['unscoped_view_authority']['operator_api_stats']['flow_count_covers_fixture_runs']);
        $this->assertSame('/', $visibility['unscoped_view_authority']['dashboard_view']['path']);
        $this->assertTrue($visibility['unscoped_view_authority']['dashboard_view']['scope_value_visible']);
        $this->assertTrue($visibility['unscoped_view_authority']['dashboard_view']['authority_description_visible']);
        $this->assertSame('cluster', $visibility['unscoped_view_authority']['dashboard_view']['script_operator_scope']['authority']);
        $this->assertSame('cluster', $visibility['unscoped_view_authority']['schedule_list']['operator_scope']['authority']);
        $this->assertNull($visibility['unscoped_view_authority']['schedule_list']['operator_scope']['namespace']);
        $this->assertTrue($visibility['unscoped_view_authority']['schedule_list']['includes_tenant_a_schedule']);
        $this->assertTrue($visibility['unscoped_view_authority']['schedule_list']['includes_tenant_b_schedule']);
        $this->assertSame('billing-schedule', $visibility['unscoped_view_authority']['schedule_list']['tenant_a_search_attribute_visible']);
        $this->assertSame('shipping-schedule', $visibility['unscoped_view_authority']['schedule_list']['tenant_b_search_attribute_visible']);
        $this->assertSame('billing', $visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['namespace']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['active_namespace_visible']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['workflow_list_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['workflow_detail_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['schedule_list_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['schedule_detail_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['search_attribute_values_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_a']['operator_api_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['tenant_scoped_surfaces']['tenant_b']['workflow_list_scoped']);
        $this->assertTrue($visibility['operator_surface_matrix']['unscoped_authority']['documented_cluster_authority']);
        $this->assertTrue($visibility['operator_surface_matrix']['unscoped_authority']['dashboard_cluster_authority_visible']);
        $this->assertTrue($visibility['operator_surface_matrix']['unscoped_authority']['workflow_list_cluster_authority']);
        $this->assertTrue($visibility['operator_surface_matrix']['unscoped_authority']['schedule_list_cluster_authority']);
        $this->assertTrue($visibility['operator_surface_matrix']['unscoped_authority']['operator_api_cluster_authority']);
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

    public function testCommandFailsWhenPublishedArtifactTupleIsNotProven(): void
    {
        $reportPath = $this->ephemeralPath('waterline-namespace-conformance');

        $this->artisan('waterline:namespace-conformance', [
            '--namespace-a' => 'billing',
            '--namespace-b' => 'shipping',
            '--run-id' => 'waterline-ns-local-artifact-test',
            '--artifact-version' => [
                'waterline=dev-main',
            ],
            '--artifact-source' => [
                'waterline=local_checkout',
            ],
            '--output' => $reportPath,
        ])->assertExitCode(1);

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame(
            ['server', 'cli', 'workflow-php', 'sdk-python'],
            $scenarios['published_artifact_install_only']['observed_outputs']['missing_artifact_versions'],
        );
        $this->assertSame(
            'local_checkout',
            $scenarios['published_artifact_install_only']['observed_outputs']['forbidden_sources']['waterline'],
        );
        $this->assertSame(
            'dev_or_branch_version',
            $scenarios['published_artifact_install_only']['observed_outputs']['rejected_versions']['waterline']['reason'],
        );
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

        $missingDashboardScope = $visibility;
        data_set($missingDashboardScope, 'tenant_a_scoped_views.dashboard_view.script_operator_scope.namespace', null);
        $this->assertFalse($passes($missingDashboardScope));

        $wrongStatsScope = $visibility;
        data_set($wrongStatsScope, 'tenant_a_scoped_views.operator_api_stats.operator_scope.namespace', 'shipping');
        $this->assertFalse($passes($wrongStatsScope));

        $missingVerdictMatrix = $visibility;
        unset($missingVerdictMatrix['operator_surface_matrix']);
        $this->assertFalse($passes($missingVerdictMatrix));

        $failedVerdictMatrix = $visibility;
        data_set($failedVerdictMatrix, 'operator_surface_matrix.tenant_scoped_surfaces.tenant_a.workflow_list_scoped', false);
        $this->assertFalse($passes($failedVerdictMatrix));

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
