<?php

namespace Tests\Feature\Health;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
    }
}
