<?php

namespace Waterline\Tests\Unit\Support;

use Waterline\Support\RunApplicationContext;
use Waterline\Tests\TestCase;

final class RunApplicationContextTest extends TestCase
{
    public function testUnconfiguredApplicationDoesNotDeriveContextFromInputsOrIdentifiers(): void
    {
        $detail = RunApplicationContext::annotate([
            'workflow_type' => 'Import', 'instance_id' => 'order-42',
            'arguments' => ['token' => 'private'], 'search_attributes' => ['order_id' => '42'],
        ]);

        self::assertNull($detail['workflow_classification']);
        self::assertSame(['state' => 'not_configured', 'fields' => [], 'links' => []], $detail['application_context']);
    }

    public function testOnlyConfiguredScalarMetadataIsDisplayedAndEntitySegmentsAreEncoded(): void
    {
        config()->set('waterline.observability.workflow_types.Import', [
            'classification' => 'business_operation',
            'fields' => [
                'order' => ['source' => 'search_attributes', 'key' => 'order_id', 'label' => 'Order'],
                'tenant' => ['source' => 'visibility_labels', 'key' => 'tenant'],
                'token' => ['source' => 'arguments', 'key' => 'token'],
                'object' => ['source' => 'search_attributes', 'key' => 'object'],
            ],
            'links' => ['order' => ['label' => 'Open order', 'url' => 'https://app.example/orders/{order}?tenant={tenant}']],
        ]);
        $detail = RunApplicationContext::annotate([
            'workflow_type' => 'Import', 'arguments' => ['token' => 'private'],
            'visibility_labels' => ['tenant' => 'a&b', 'secret' => 'private'],
            'search_attributes' => ['order_id' => '42/../admin#x', 'object' => ['private' => 'value']],
        ]);

        self::assertSame('business_operation', $detail['workflow_classification']);
        self::assertSame(['order', 'tenant', 'object'], array_column($detail['application_context']['fields'], 'name'));
        self::assertSame('unavailable', $detail['application_context']['fields'][2]['state']);
        self::assertSame('https://app.example/orders/42%2F..%2Fadmin%23x?tenant=a%26b', $detail['application_context']['links'][0]['url']);
        self::assertStringNotContainsString('private', json_encode($detail['application_context']));
    }

    public function testUnsafeOrUnavailableLinksCannotBecomeDashboardNavigation(): void
    {
        config()->set('waterline.observability.workflow_types.Import', [
            'fields' => ['id' => ['source' => 'search_attributes', 'key' => 'id']],
            'links' => [
                'script' => ['url' => 'javascript:alert(1)'],
                'relative' => ['url' => '//app.example/orders/1'],
                'host' => ['url' => 'https://{id}/orders/1'],
                'credentials' => ['url' => 'https://user:password@app.example/orders/1'],
                'missing' => ['url' => 'https://app.example/orders/{missing}'],
                'newline' => ['url' => "https://app.example/\norders/{id}"],
                'backslash' => ['url' => 'https://app.example\\evil.example/orders/{id}'],
            ],
        ]);
        $detail = RunApplicationContext::annotate(['workflow_type' => 'Import', 'search_attributes' => ['id' => '42']]);

        self::assertSame([], $detail['application_context']['links']);
    }

    public function testContextBoundsValuesAndDoesNotLinkToShortenedEntityIdentifiers(): void
    {
        config()->set('waterline.observability.workflow_types.Import', [
            'fields' => ['id' => ['source' => 'search_attributes', 'key' => 'id']],
            'links' => ['entity' => ['url' => 'https://app.example/orders/{id}']],
        ]);
        $detail = RunApplicationContext::annotate(['workflow_type' => 'Import', 'search_attributes' => ['id' => str_repeat('x', 600)]]);

        self::assertSame(512, mb_strlen($detail['application_context']['fields'][0]['value']));
        self::assertTrue($detail['application_context']['fields'][0]['truncated']);
        self::assertSame([], $detail['application_context']['links']);
    }

    public function testCompletedCoordinatorKeepsItsDeclaredChildOutcome(): void
    {
        config()->set('waterline.observability.workflow_types.Discover', ['classification' => 'coordinator']);
        $detail = RunApplicationContext::annotate([
            'workflow_type' => 'Discover', 'status' => 'completed',
            'continuedWorkflows' => [['id' => 'child', 'status' => 'running']],
        ]);

        self::assertSame('coordinator', $detail['workflow_classification']);
        self::assertSame('completed', $detail['status']);
        self::assertSame('running', $detail['continuedWorkflows'][0]['status']);
    }

    public function testMissingMetadataIsExplicitAndConfiguredOutputCountsAreBounded(): void
    {
        $fields = [];
        $links = [];
        for ($i = 0; $i < 25; $i++) {
            $fields['field'.$i] = ['source' => 'visibility_labels', 'key' => 'field'.$i];
            $links['link'.$i] = ['url' => 'https://app.example/help/'.$i];
        }
        config()->set('waterline.observability.workflow_types.Import', ['fields' => $fields, 'links' => $links]);
        $detail = RunApplicationContext::annotate(['workflow_type' => 'Import']);

        self::assertCount(20, $detail['application_context']['fields']);
        self::assertSame('unavailable', $detail['application_context']['fields'][0]['state']);
        self::assertNull($detail['application_context']['fields'][0]['value']);
        self::assertCount(10, $detail['application_context']['links']);
    }
}
