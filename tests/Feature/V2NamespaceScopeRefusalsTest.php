<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Waterline\Waterline;
use Workflow\V2\Support\NamespaceScopeRefusals;

/**
 * Workflow 2.27.0's namespace_scope_refusals check names the namespaces an
 * app refused. Namespace names here are unique so that rows other tests
 * leave in a shared database cannot satisfy or break the assertions. Waterline shows them on its authenticated health page and
 * hides the names from an observer stack opened without sign-in.
 */
final class V2NamespaceScopeRefusalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(NamespaceScopeRefusals::class)) {
            $this->markTestSkipped('Namespace-scope refusals need Workflow 2.27.0 or later.');
        }

        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'array');
        config()->set('workflows.v2.namespaces', ['orders']);

        NamespaceScopeRefusals::record(NamespaceScopeRefusals::PATH_JOB, 'nsr-billing', 3);
        NamespaceScopeRefusals::record(NamespaceScopeRefusals::PATH_REPAIR, 'nsr-reports', 2);
    }

    public function testAnAuthenticatedOperatorSeesRefusalsByNamespaceAndPath(): void
    {
        $payload = $this->getJson('/waterline/api/v2/health')->assertOk()->json();
        $check = collect($payload['checks'])->firstWhere('name', 'namespace_scope_refusals');

        $this->assertIsArray($check);
        $this->assertSame('warning', $check['status']);
        $this->assertSame('correctness', $check['category']);
        $this->assertSame(5, $check['data']['total']);
        $this->assertSame(3, $check['data']['by_path']['job']);
        $this->assertSame(2, $check['data']['by_path']['repair']);
        $this->assertSame(
            [['nsr-billing', 'job', 3], ['nsr-reports', 'repair', 2]],
            array_map(
                static fn (array $row): array => [$row['namespace'], $row['path'], $row['count']],
                $check['data']['namespaces'],
            ),
        );
        $this->assertSame(['all' => false, 'namespaces' => ['orders']], $check['data']['scope']);
        $this->assertArrayNotHasKey('namespaces_redacted', $check['data']);
        $this->assertSame('nsr-billing', $payload['operator_metrics']['namespace_scope_refusals']['namespaces'][0]['namespace']);
    }

    public function testAnUnauthenticatedObserverStackSeesCountsWithoutNamespaceNames(): void
    {
        Waterline::auth(static fn (): bool => false);
        config()->set('waterline.allow_unauthenticated', true);

        $response = $this->getJson('/waterline/api/v2/health')->assertOk();
        $check = collect($response->json('checks'))->firstWhere('name', 'namespace_scope_refusals');

        $this->assertSame('warning', $check['status']);
        $this->assertSame(5, $check['data']['total']);
        $this->assertSame(3, $check['data']['by_path']['job']);
        $this->assertSame([], $check['data']['namespaces']);
        $this->assertSame(['all' => false, 'namespaces' => null], $check['data']['scope']);
        $this->assertTrue($check['data']['namespaces_redacted']);
        $this->assertSame([], $response->json('operator_metrics.namespace_scope_refusals.namespaces'));
        $this->assertTrue($response->json('operator_metrics.namespace_scope_refusals.namespaces_redacted'));
        $this->assertStringNotContainsString('nsr-billing', $response->getContent());
        $this->assertStringNotContainsString('nsr-reports', $response->getContent());

        $stats = $this->getJson('/waterline/api/stats')->assertOk();
        $this->assertTrue($stats->json('operator_metrics.namespace_scope_refusals.namespaces_redacted'));
        $this->assertStringNotContainsString('nsr-billing', $stats->getContent());
    }

    public function testOpeningWaterlineWithoutSignInHidesTheNamesEvenForAnAdmittedViewer(): void
    {
        // As with embedded exception source, the opt-in applies to everyone.
        config()->set('waterline.allow_unauthenticated', true);

        $response = $this->getJson('/waterline/api/v2/health')->assertOk();

        $this->assertStringNotContainsString('nsr-billing', $response->getContent());
        $this->assertTrue(collect($response->json('checks'))->firstWhere('name', 'namespace_scope_refusals')['data']['namespaces_redacted']);
    }

    public function testTheAuthenticatedDashboardKeepsTheNames(): void
    {
        $stats = $this->getJson('/waterline/api/stats')->assertOk();

        $this->assertSame('nsr-billing', $stats->json('operator_metrics.namespace_scope_refusals.namespaces.0.namespace'));
    }

    public function testAnUnauthorizedViewerGetsNothing(): void
    {
        Waterline::auth(static fn (): bool => false);
        config()->set('waterline.allow_unauthenticated', false);

        $response = $this->get('/waterline/api/v2/health')->assertForbidden();
        $this->assertStringNotContainsString('nsr-billing', (string) $response->getContent());
    }

    public function testANamespaceScopedWaterlineDoesNotListOtherNamespacesRefusals(): void
    {
        config()->set('waterline.namespace', 'orders');

        $response = $this->getJson('/waterline/api/v2/health')->assertOk();
        $check = collect($response->json('checks'))->firstWhere('name', 'namespace_scope_refusals');

        $this->assertSame('ok', $check['status']);
        $this->assertSame([], $check['data']['namespaces']);
        $this->assertStringNotContainsString('nsr-billing', $response->getContent());
    }
}
