<?php

namespace Waterline\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Waterline\Support\RequestTimeoutException;
use Waterline\Support\RequestTimeouts;

final class RequestTimeoutsTest extends TestCase
{
    private Container $original;

    protected function setUp(): void
    {
        $this->original = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->original);
    }

    public function testInheritedPolicyDoesNotRequireOrProbeAnOptionalSdk(): void
    {
        $this->assertSame([], RequestTimeouts::serviceClientOptions('MissingOptionalSdk'));
        $this->assertSame(65000, RequestTimeouts::browserMilliseconds());
    }

    public function testExplicitBudgetUsesThePublishedNamedConstructorCapability(): void
    {
        config()->set('waterline.service.request_timeout_seconds', '0.125');
        $this->assertSame(['requestTimeoutSeconds' => 0.125], RequestTimeouts::serviceClientOptions(TimeoutCapableSdk::class));
    }

    public function testOlderOptionalSdkReportsTheMissingCapabilityInsteadOfIgnoringTheBudget(): void
    {
        config()->set('waterline.service.request_timeout_seconds', 0.2);
        try {
            RequestTimeouts::serviceClientOptions(LegacyTimeoutSdk::class);
            $this->fail('Expected an explicit old-SDK capability diagnostic.');
        } catch (RequestTimeoutException $error) {
            $this->assertSame(501, $error->status);
            $this->assertSame('backend_capability_unavailable', $error->reason);
            $this->assertStringContainsString('3.4.0', $error->getMessage());
        }
    }

    public function testBrowserSupportsMillisecondBudgetsAndRejectsUnboundedOrOverflowingValues(): void
    {
        config()->set('waterline.browser.request_timeout_seconds', '0.001');
        $this->assertSame(1, RequestTimeouts::browserMilliseconds());
        config()->set('waterline.browser.request_timeout_seconds', 0.2001);
        $this->assertSame(201, RequestTimeouts::browserMilliseconds());
        foreach ([0, -1, true, false, null, '', [], 'forever', INF, NAN, 0.0001, 2147484] as $value) {
            config()->set('waterline.browser.request_timeout_seconds', $value);
            try {
                RequestTimeouts::browserMilliseconds();
                $this->fail('Expected invalid browser budget refusal.');
            } catch (RequestTimeoutException $error) {
                $this->assertStringContainsString('WATERLINE_BROWSER_REQUEST_TIMEOUT', $error->getMessage());
            }
        }
    }

    public function testServiceRejectsInvalidBudgetsBeforeSdkConstruction(): void
    {
        foreach ([0, -1, true, '', [], 'forever', INF, NAN, 0.0001, PHP_INT_MAX] as $value) {
            config()->set('waterline.service.request_timeout_seconds', $value);
            try {
                RequestTimeouts::serviceClientOptions(TimeoutCapableSdk::class);
                $this->fail('Expected invalid service budget refusal.');
            } catch (RequestTimeoutException $error) {
                $this->assertSame(500, $error->status);
                $this->assertSame('waterline_request_timeout_invalid', $error->reason);
            }
        }
    }
}

final class LegacyTimeoutSdk
{
    public function __construct() {}
}

final class TimeoutCapableSdk
{
    public function __construct(?float $requestTimeoutSeconds = null) {}
}
