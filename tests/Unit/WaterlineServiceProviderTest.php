<?php

namespace Waterline\Tests\Unit;

use Waterline\Tests\TestCase;
use Waterline\WaterlineServiceProvider;

class WaterlineServiceProviderTest extends TestCase
{
    public function testProviderMergesEngineSourceIntoLegacyPublishedConfig(): void
    {
        config()->set('waterline', [
            'domain' => null,
            'path' => 'legacy-waterline',
            'middleware' => ['web'],
        ]);

        (new WaterlineServiceProvider($this->app))->register();

        $this->assertSame('legacy-waterline', config('waterline.path'));
        $this->assertSame(['web'], config('waterline.middleware'));
        $this->assertSame('v1', config('waterline.engine_source'));
    }
}
