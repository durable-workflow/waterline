<?php

namespace Waterline\Tests\Unit\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Waterline\Support\WorkflowClassification;
use Waterline\Tests\TestCase;

final class WorkflowClassificationTest extends TestCase
{
    public function testUnconfiguredApplicationKeepsAllTypesVisible(): void
    {
        $selection = WorkflowClassification::selection(Request::create('/waterline/api/stats'));

        self::assertNull($selection['classification']);
        self::assertNull($selection['workflow_types']);
        self::assertSame([], $selection['options']);
        self::assertNull(WorkflowClassification::forType('Import'));
    }

    public function testConfiguredGroupsResolveExactTypesAndIgnoreInvalidProfiles(): void
    {
        config()->set('waterline.observability.workflow_types', [
            'orders.import' => ['classification' => 'business_operation'],
            'invoices.send' => ['classification' => 'business_operation'],
            'maintenance.scan' => ['classification' => 'maintenance'],
            'invalid' => ['classification' => 'bad class'],
            'unconfigured' => [],
            'malformed' => 'maintenance',
        ]);

        $selection = WorkflowClassification::selection(Request::create('/waterline/api/stats', 'GET', [
            'classification' => 'business_operation',
        ]));

        self::assertSame(['invoices.send', 'orders.import'], $selection['workflow_types']);
        self::assertSame('Business Operation', $selection['label']);
        self::assertSame(['business_operation', 'maintenance'], array_column($selection['options'], 'value'));
        self::assertSame('maintenance', WorkflowClassification::forType('maintenance.scan'));
        self::assertNull(WorkflowClassification::forType('invalid'));
        self::assertNull(WorkflowClassification::forType('unconfigured'));
        self::assertNull(WorkflowClassification::forType(['maintenance.scan']));
    }

    public function testUnknownGroupCannotSilentlyBecomeAnUnfilteredDashboard(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowClassification::selection(Request::create('/waterline/api/stats', 'GET', [
            'classification' => 'business_operation',
        ]));
    }

    public function testArrayQueryCannotChangeClassificationScope(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowClassification::selection(Request::create('/waterline/api/stats', 'GET', [
            'classification' => ['maintenance', 'business_operation'],
        ]));
    }
}
