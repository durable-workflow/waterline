<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waterline\Support\NamespaceScopeRefusalVisibility;

final class NamespaceScopeRefusalVisibilityTest extends TestCase
{
    public function testRedactionKeepsCountsAndDropsEveryNamespaceName(): void
    {
        $block = [
            'total' => 3,
            'by_path' => ['job' => 3, 'repair' => 0, 'timeout' => 0, 'schedule_tick' => 0],
            'namespaces' => [['namespace' => 'billing', 'path' => 'job', 'count' => 3, 'last_seen_at' => null]],
            'scope' => ['all' => false, 'namespaces' => ['orders']],
            'scope_error' => 'Namespace [tenant-secret] is invalid.',
            'truncated' => false,
        ];
        $payload = [
            'checks' => [
                ['name' => 'engine_source', 'status' => 'ok', 'data' => ['namespaces' => ['kept']]],
                ['name' => 'namespace_scope_refusals', 'status' => 'warning', 'data' => $block],
                'not a check',
            ],
            'operator_metrics' => ['runs' => ['total' => 1], 'namespace_scope_refusals' => $block],
        ];

        $redacted = NamespaceScopeRefusalVisibility::redact($payload);

        foreach ([$redacted['checks'][1]['data'], $redacted['operator_metrics']['namespace_scope_refusals']] as $result) {
            $this->assertSame(3, $result['total']);
            $this->assertSame(3, $result['by_path']['job']);
            $this->assertSame([], $result['namespaces']);
            $this->assertSame(['all' => false, 'namespaces' => null], $result['scope']);
            $this->assertSame('The configured namespace allowlist is invalid.', $result['scope_error']);
            $this->assertTrue($result['namespaces_redacted']);
        }

        $this->assertSame($payload['checks'][0], $redacted['checks'][0]);
        $this->assertSame('not a check', $redacted['checks'][2]);
        $this->assertSame(['total' => 1], $redacted['operator_metrics']['runs']);
        $this->assertStringNotContainsString('billing', json_encode($redacted, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('orders', json_encode($redacted, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('tenant-secret', json_encode($redacted, JSON_THROW_ON_ERROR));
    }

    public function testPayloadsWithoutTheCheckAreUnchanged(): void
    {
        $payload = ['checks' => [['name' => 'worker_compatibility', 'status' => 'warning']], 'operator_metrics' => ['runs' => []]];

        $this->assertSame($payload, NamespaceScopeRefusalVisibility::redact($payload));
        $this->assertSame(['status' => 'ok'], NamespaceScopeRefusalVisibility::redact(['status' => 'ok']));
    }
}
