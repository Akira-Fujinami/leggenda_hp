<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\RelativeUrlResolver;
use Tests\TestCase;

class RelativeUrlResolverTest extends TestCase
{
    private function resolver(): RelativeUrlResolver
    {
        return new RelativeUrlResolver;
    }

    public function test_an_absolute_url_passes_through_unchanged(): void
    {
        $this->assertSame(
            'https://careers.example.com/list',
            $this->resolver()->resolve('https://example.com', 'https://careers.example.com/list'),
        );
    }

    public function test_a_root_relative_path_resolves_against_the_origin(): void
    {
        $this->assertSame('https://example.com/careers', $this->resolver()->resolve('https://example.com/about/team', '/careers'));
        $this->assertSame('https://example.com/careers', $this->resolver()->resolve('https://example.com', '/careers'));
    }

    public function test_a_path_relative_href_resolves_against_the_current_directory(): void
    {
        $this->assertSame('https://example.com/jp/careers', $this->resolver()->resolve('https://example.com/jp/about', 'careers'));
    }

    public function test_a_protocol_relative_href_inherits_the_base_scheme(): void
    {
        $this->assertSame('https://careers.example.com/list', $this->resolver()->resolve('https://example.com', '//careers.example.com/list'));
    }

    public function test_a_base_url_without_a_path_resolves_against_the_root(): void
    {
        $this->assertSame('https://example.com/careers', $this->resolver()->resolve('https://example.com', 'careers'));
    }
}
