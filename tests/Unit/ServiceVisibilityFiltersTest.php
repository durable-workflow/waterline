<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waterline\Support\ServiceVisibilityFilters;

final class ServiceVisibilityFiltersTest extends TestCase
{
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
