<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waterline\Support\ServiceVisibilityFilters;

final class ServiceVisibilityFiltersTest extends TestCase
{
    public function testClassificationRemainsConjunctiveWithAQueryContainingOrAndEscapedTypes(): void
    {
        $plan = ServiceVisibilityFilters::plan([], 'running', 'WorkflowId = "one" OR WorkflowId = "two"', ['orders/quote"+']);
        $this->assertSame('(WorkflowId = "one" OR WorkflowId = "two") AND (WorkflowType IN ("orders/quote\\"+"))', $plan['query']);
        $this->assertSame('WorkflowType = ""', ServiceVisibilityFilters::plan([], 'running', null, [])['query']);
    }

    public function testTimeBoundsUseOneInjectedClockAndRemainConjunctiveWithoutBooleanParentheses(): void
    {
        $at = new \DateTimeImmutable('2026-01-10T12:00:00.123456Z');
        $plan = ServiceVisibilityFilters::plan(['started_time' => 'rolling', 'started_within_seconds' => 86400],
            'running', null, ['orders'], $at);
        $this->assertSame('StartTime >= "2026-01-09T12:00:00.123456Z" AND StartTime < "2026-01-10T12:00:00.123456Z" AND WorkflowType IN ("orders")', $plan['query']);
        $this->assertSame(86400, $plan['applied_filters']['started_within_seconds']);
        $this->assertSame([], $plan['unavailable_filters']);
        $this->assertSame('2026-01-09T12:00:00.123456Z', $plan['time_window']['from']);
    }

    public function testSavedAndRequestTimeDescriptorsMergeAtomicallyAcrossVersions(): void
    {
        $saved = ['workflow_type' => 'orders', 'started_time' => 'rolling', 'started_within_seconds' => 86400];
        $this->assertSame(['workflow_type' => 'orders', 'started_time' => 'any'],
            \Waterline\Support\ActionabilityVisibilityFilters::merge($saved, ['started_time' => 'any']));
        $fixed = ['started_time' => 'fixed', 'started_from' => '2026-01-01T00:00:00Z', 'started_before' => '2026-01-02T00:00:00Z'];
        $merged = \Waterline\Support\ActionabilityVisibilityFilters::merge($saved, $fixed);
        $this->assertArrayNotHasKey('started_within_seconds', $merged);
        $this->assertSame('fixed', $merged['started_time']);
        $this->assertSame(['workflow_type' => 'orders'], \Waterline\Support\ActionabilityVisibilityFilters::normalizeStored($saved, 6));
        $this->assertSame($saved, \Waterline\Support\ActionabilityVisibilityFilters::normalizeStored($saved, 7));
        $this->assertFalse(\Waterline\Support\ActionabilityVisibilityFilters::versionMetadata(99)['supported']);
    }

    public function testDefinitionClassifiesEverySavedViewFilterForServiceMode(): void
    {
        $definition = ServiceVisibilityFilters::definition();
        $supported = ServiceVisibilityFilters::supportedFields();

        foreach ($definition['fields'] as $name => $field) {
            $available = in_array($name, $supported, true);

            $this->assertSame($available, $field['service_mode_available']);
            $this->assertSame($available, $field['filterable']);
            $this->assertSame($available, $field['saved_view_compatible']);
        }

        $this->assertFalse($definition['labels']['service_mode_available']);
        $this->assertFalse($definition['labels']['filterable']);
        $this->assertFalse($definition['search_attributes']['service_mode_available']);
        $this->assertFalse($definition['search_attributes']['filterable']);
        $this->assertSame([], $definition['actionability']['filter_fields']);
        $this->assertSame(['repair_state'], $definition['actionability']['unavailable_filter_fields']);
        $this->assertSame($supported, $definition['service_mode']['supported_fields']);
    }

    public function testPlanTranslatesSupportedFieldsToTheAuthoritativeWorkflowListContract(): void
    {
        $plan = ServiceVisibilityFilters::plan([
            'instance_id' => 'order-1',
            'run_id' => 'run-1',
            'workflow_type' => 'orders.process',
            'compatibility' => 'orders-v2',
            'queue' => 'orders',
            'status' => 'waiting',
            'status_bucket' => 'running',
        ], 'running');

        $this->assertSame('orders.process', $plan['workflow_type']);
        $this->assertSame(
            'WorkflowId = "order-1" AND RunId = "run-1" AND BuildId = "orders-v2" AND TaskQueue = "orders" AND Status = "waiting"',
            $plan['query'],
        );
        $this->assertSame([], $plan['unavailable_filters']);
        $this->assertNull($plan['warning']);
        $this->assertTrue($plan['capability']['fully_applied']);
    }

    public function testPlanReportsEmbeddedOnlyFieldsWithoutSendingThemToTheService(): void
    {
        $plan = ServiceVisibilityFilters::plan([
            'workflow_type' => 'orders.process',
            'repair_state' => 'blocked',
            'labels' => ['tenant' => 'acme'],
            'search_attributes' => ['CustomerId' => '42'],
        ], 'running');

        $this->assertSame(['workflow_type' => 'orders.process'], $plan['applied_filters']);
        $this->assertEquals([
            'repair_state' => 'blocked',
            'labels' => ['tenant' => 'acme'],
            'search_attributes' => ['CustomerId' => '42'],
        ], $plan['unavailable_filters']);
        $this->assertSame([
            'labels.tenant',
            'repair_state',
            'search_attributes.CustomerId',
        ], $plan['capability']['unavailable_fields']);
        $this->assertFalse($plan['capability']['fully_applied']);
    }
}
