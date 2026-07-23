<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestIdTest extends TestCase
{
    public function test_api_responses_include_a_request_id_header(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertHeader('X-Request-Id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $response->headers->get('X-Request-Id')
        );
    }

    public function test_each_request_gets_a_distinct_request_id(): void
    {
        $first = $this->getJson('/api/health')->headers->get('X-Request-Id');
        $second = $this->getJson('/api/health')->headers->get('X-Request-Id');

        $this->assertNotSame($first, $second);
    }
}
