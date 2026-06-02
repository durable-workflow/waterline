<?php

namespace Waterline\Tests\Feature;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Waterline\Console\NamespaceConformanceCommand;
use Waterline\Console\SearchAttributesConformanceCommand;
use Waterline\Models\SavedWorkflowView;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\PlatformConformanceSuite;

class NamespaceConformanceCommandTest extends TestCase
{
    public function testSearchAttributesCommandEmitsWaterlineOperatorVisibilityShard(): void
    {
        $commandOptions = [
            '--namespace-a' => 'billing',
            '--namespace-b' => 'shipping',
            '--run-id' => 'waterline-sa-test',
            '--artifact-version' => [
                'server=0.2.235',
                'cli=0.1.75',
                'workflow=2.0.0-alpha.189',
                'sdk-python=0.4.84',
                'waterline=2.0.0-alpha.73',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=published_install_script',
                'workflow=published_composer_package',
                'sdk-python=published_pypi_package',
                'waterline=published_package',
            ],
        ];

        $reportPath = $this->ephemeralPath('waterline-search-attributes-conformance');
        $this->artisan('waterline:search-attributes-conformance', $commandOptions + [
            '--output' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $visibility = $report['waterline_search_attribute_visibility'];
        $matrix = $visibility['operator_surface_matrix'];

        $this->assertSame('durable-workflow.v2.search-attribute-runtime.result', $report['schema']);
        $this->assertSame(PlatformConformanceSuite::VERSION, $report['suite_version']);
        $this->assertSame('waterline-search-attribute-operator-shard', $report['coverage_scope']);
        $this->assertSame('non_passing', $report['outcome']);
        $this->assertSame('2.0.0-alpha.73', $report['artifact_versions']['waterline']);
        $this->assertSame('2.0.0-alpha.189', $report['artifact_versions']['workflow']);
        $this->assertSame('2.0.0-alpha.189', $report['artifact_versions']['workflow-php']);
        $this->assertSame('waterline_contract_surface', $report['runtime_matrix']['claimed_targets'][0]);
        $this->assertContains(
            'waterline_operator_search_attribute_visibility',
            $report['runtime_matrix']['covered_scenarios'],
        );
        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('pass', $scenarios['waterline_operator_search_attribute_visibility']['status']);
        $this->assertSame('pass', $scenarios['result_record_and_product_finding_routing']['status']);
        $this->assertTrue($matrix['workflow_list_search_attribute_filter']);
        $this->assertTrue($matrix['keyword_list_search_attribute_filter']);
        $this->assertTrue($matrix['selected_run_search_attributes']);
        $this->assertTrue($matrix['saved_filter_round_trip']);
        $this->assertTrue($matrix['namespace_scoped_visibility']);
        $this->assertSame(2, $visibility['workflow_list_filter']['expected_count']);
        $this->assertSame(2, $visibility['workflow_list_filter']['actual_count']);
        $this->assertTrue($visibility['workflow_list_filter']['matched']);
        $this->assertSame('cust-7', $visibility['workflow_list_filter']['visibility_filter_echo']['customer_id']);
        $this->assertTrue($visibility['workflow_list_filter']['foreign_run_absent']);
        $this->assertSame(1, $visibility['keyword_list_filter']['expected_count']);
        $this->assertSame(1, $visibility['keyword_list_filter']['actual_count']);
        $this->assertTrue($visibility['keyword_list_filter']['matched']);
        $this->assertSame('urgent', $visibility['keyword_list_filter']['visibility_filter_echo']['tags']);
        $this->assertTrue($visibility['selected_run_detail']['expected_attributes_visible']);
        $this->assertSame('cust-7', $visibility['selected_run_detail']['actual_search_attributes']['customer_id']);
        $this->assertSame(7500, $visibility['selected_run_detail']['actual_search_attributes']['order_total_cents']);
        $this->assertSame('gold', $visibility['selected_run_detail']['actual_search_attributes']['priority_tier']);
        $this->assertTrue($visibility['selected_run_detail']['actual_search_attributes']['is_vip']);
        $this->assertNotEmpty($visibility['selected_run_detail']['actual_search_attributes']['created_at']);
        $this->assertSame(['urgent', 'oversized'], $visibility['selected_run_detail']['actual_search_attributes']['tags']);
        $this->assertTrue($visibility['saved_filter_state']['filter_preserved_on_retrieval']);
        $this->assertTrue($visibility['saved_filter_state']['filter_preserved_on_list_retrieval']);
        $this->assertTrue($visibility['saved_filter_state']['applied_filter_matched']);
        $this->assertSame(2, $visibility['saved_filter_state']['applied_actual_count']);
        $this->assertSame('cust-7', $visibility['saved_filter_state']['applied_filter_echo']['customer_id']);
        foreach ($visibility['fixture_ids']['saved_view_ids'] as $savedViewId) {
            $this->assertSame(26, strlen($savedViewId));
        }
        foreach ($visibility['fixture_ids']['workflow_run_ids'] as $workflowRunId) {
            $this->assertSame(26, strlen($workflowRunId));
        }
        $this->assertTrue($visibility['namespace_isolation']['tenant_a_excludes_tenant_b']);
        $this->assertTrue($visibility['namespace_isolation']['tenant_b_excludes_tenant_a']);
        $this->assertTrue($visibility['namespace_isolation']['tenant_b_filter_matched']);
        $this->assertSame('billing', $visibility['namespace_isolation']['tenant_a_operator_scope']['namespace']);
        $this->assertSame('shipping', $visibility['namespace_isolation']['tenant_b_operator_scope']['namespace']);
        $this->assertSame($visibility['api_captures'], $report['api_captures']);
        $this->assertSame(
            '/api/flows/running?search_attributes[customer_id]=cust-7',
            $visibility['api_captures']['workflow_list_customer_filter']['path'],
        );
        $this->assertStringContainsString(
            'cust-7',
            json_encode($visibility['api_captures']['workflow_list_customer_filter']['json'], JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            $visibility['fixture_ids']['workflow_run_ids'][3],
            json_encode($visibility['api_captures']['workflow_list_customer_filter']['json'], JSON_THROW_ON_ERROR),
        );
        $this->assertSearchAttributeEvidencePassCriteriaRequireHttpCaptures($visibility);

        $this->assertSame(
            0,
            WorkflowRun::query()->whereIn('id', $visibility['fixture_ids']['workflow_run_ids'])->count(),
            'The command should clean up workflow fixture rows by default.',
        );
        $this->assertSame(
            0,
            SavedWorkflowView::query()->whereIn('id', $visibility['fixture_ids']['saved_view_ids'])->count(),
            'The command should clean up saved view fixture rows by default.',
        );
    }

    public function testCommandEmitsWaterlineOperatorNamespaceVisibilityShard(): void
    {
        $commandOptions = [
            '--namespace-a' => 'billing',
            '--namespace-b' => 'shipping',
            '--shared-namespace' => 'shared',
            '--run-id' => 'waterline-ns-test',
            '--artifact-version' => [
                'server=0.2.235',
                'cli=0.1.75',
                'workflow=2.0.0-alpha.189',
                'sdk-python=0.4.84',
                'waterline=2.0.0-alpha.73',
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
        $this->assertSame('2.0.0-alpha.73', $report['artifact_versions']['waterline']);
        $this->assertSame('2.0.0-alpha.189', $report['artifact_versions']['workflow']);
        $this->assertSame('2.0.0-alpha.189', $report['artifact_versions']['workflow-php']);
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

    public function testCommandRejectsPlaceholderPublishedArtifactVersions(): void
    {
        $reportPath = $this->ephemeralPath('waterline-namespace-conformance');

        $this->artisan('waterline:namespace-conformance', [
            '--namespace-a' => 'billing',
            '--namespace-b' => 'shipping',
            '--run-id' => 'waterline-ns-placeholder-artifact-test',
            '--artifact-version' => [
                'server=0.2.N',
                'cli=0.1.N',
                'workflow=2.0.0-alpha.N',
                'sdk-python=0.4.N',
                'waterline=2.0.0-alpha.N',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=published_install_script',
                'workflow=published_composer_package',
                'sdk-python=published_pypi_package',
                'waterline=published_package',
            ],
            '--output' => $reportPath,
        ])->assertExitCode(1);

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');
        $rejectedVersions = $scenarios['published_artifact_install_only']['observed_outputs']['rejected_versions'];

        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame([], $scenarios['published_artifact_install_only']['observed_outputs']['missing_artifact_versions']);
        $this->assertSame('placeholder_version_segment', $rejectedVersions['server']['reason']);
        $this->assertSame('placeholder_version_segment', $rejectedVersions['cli']['reason']);
        $this->assertSame('placeholder_version_segment', $rejectedVersions['workflow-php']['reason']);
        $this->assertSame('placeholder_version_segment', $rejectedVersions['sdk-python']['reason']);
        $this->assertSame('placeholder_version_segment', $rejectedVersions['waterline']['reason']);
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

    /**
     * @param array<string, mixed> $visibility
     */
    private function assertSearchAttributeEvidencePassCriteriaRequireHttpCaptures(array $visibility): void
    {
        $command = app(SearchAttributesConformanceCommand::class);
        $passes = Closure::bind(
            function (array $evidence): bool {
                return $this->waterlineEvidencePassed($evidence);
            },
            $command,
            SearchAttributesConformanceCommand::class,
        );

        $this->assertNotNull($passes);
        $this->assertTrue($passes($visibility));

        $missingCount = $visibility;
        data_set($missingCount, 'workflow_list_filter.actual_count', 0);
        data_set($missingCount, 'workflow_list_filter.matched', false);
        data_set($missingCount, 'operator_surface_matrix.workflow_list_search_attribute_filter', false);
        $this->assertFalse($passes($missingCount));

        $missingDetailAttributes = $visibility;
        data_set($missingDetailAttributes, 'selected_run_detail.expected_attributes_visible', false);
        data_set($missingDetailAttributes, 'operator_surface_matrix.selected_run_search_attributes', false);
        $this->assertFalse($passes($missingDetailAttributes));

        $missingSavedViewRoundTrip = $visibility;
        data_set($missingSavedViewRoundTrip, 'saved_filter_state.filter_preserved_on_retrieval', false);
        data_set($missingSavedViewRoundTrip, 'operator_surface_matrix.saved_filter_round_trip', false);
        $this->assertFalse($passes($missingSavedViewRoundTrip));

        $wrongNamespaceScope = $visibility;
        data_set($wrongNamespaceScope, 'namespace_isolation.tenant_b_operator_scope.namespace', 'billing');
        $this->assertFalse($passes($wrongNamespaceScope));

        $missingHttpCapture = $visibility;
        unset($missingHttpCapture['api_captures']['workflow_list_customer_filter']);
        $this->assertFalse($passes($missingHttpCapture));
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
