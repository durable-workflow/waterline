<?php

namespace Waterline\Tests\Feature;

use Waterline\Http\Middleware\ControlPlaneVersion;
use Waterline\Tests\TestCase;

class ControlPlaneVersionTest extends TestCase
{
    public function testWaterlineApiAllowsCompatibleControlPlaneHeader(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $this->withHeader(ControlPlaneVersion::HEADER, ControlPlaneVersion::VERSION)
            ->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertHeader(ControlPlaneVersion::HEADER, ControlPlaneVersion::VERSION);
    }

    public function testWaterlineApiRefusesExplicitUnsupportedControlPlaneHeader(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $this->withHeader(ControlPlaneVersion::HEADER, '999')
            ->getJson('/waterline/api/flows/running')
            ->assertStatus(400)
            ->assertHeader(ControlPlaneVersion::HEADER, ControlPlaneVersion::VERSION)
            ->assertJsonPath('reason', 'unsupported_control_plane_version')
            ->assertJsonPath('supported_version', ControlPlaneVersion::VERSION)
            ->assertJsonPath('requested_version', '999')
            ->assertJson(fn ($json) => $json
                ->whereType('remediation', 'string')
                ->etc());
    }
}
