<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_database_and_redis_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertJsonStructure([
            'data' => ['status', 'checks' => ['database', 'redis', 'analyzer']],
            'meta',
            'message',
        ]);
    }
}
