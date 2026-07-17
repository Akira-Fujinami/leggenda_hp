<?php

namespace Tests\Unit\Analysis;

use App\Services\Analysis\RobotsTxtParser;
use Tests\TestCase;

class RobotsTxtParserTest extends TestCase
{
    private RobotsTxtParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new RobotsTxtParser;
    }

    public function test_it_parses_disallow_and_allow_for_wildcard_user_agent(): void
    {
        $content = <<<'TXT'
        User-agent: *
        Disallow: /admin/
        Allow: /admin/public/
        Sitemap: https://example.com/sitemap.xml
        TXT;

        $result = $this->parser->parse($content);

        $this->assertSame(['/admin/'], $result['disallow']);
        $this->assertSame(['/admin/public/'], $result['allow']);
        $this->assertSame(['https://example.com/sitemap.xml'], $result['sitemaps']);
        $this->assertFalse($result['parse_error']);
    }

    public function test_it_ignores_rules_for_other_user_agents(): void
    {
        $content = <<<'TXT'
        User-agent: Googlebot
        Disallow: /private/

        User-agent: *
        Disallow: /admin/
        TXT;

        $result = $this->parser->parse($content);

        $this->assertSame(['/admin/'], $result['disallow']);
    }

    public function test_top_level_is_allowed_when_not_disallowed(): void
    {
        $parsed = $this->parser->parse("User-agent: *\nDisallow: /admin/");

        $this->assertTrue($this->parser->isPathAllowed($parsed, '/'));
    }

    public function test_disallowed_path_is_blocked(): void
    {
        $parsed = $this->parser->parse("User-agent: *\nDisallow: /");

        $this->assertFalse($this->parser->isPathAllowed($parsed, '/'));
    }

    public function test_more_specific_allow_overrides_disallow(): void
    {
        $parsed = $this->parser->parse("User-agent: *\nDisallow: /admin/\nAllow: /admin/public/");

        $this->assertFalse($this->parser->isPathAllowed($parsed, '/admin/secret'));
        $this->assertTrue($this->parser->isPathAllowed($parsed, '/admin/public/page'));
    }

    public function test_empty_disallow_value_means_everything_allowed(): void
    {
        $parsed = $this->parser->parse("User-agent: *\nDisallow:");

        $this->assertTrue($this->parser->isPathAllowed($parsed, '/anything'));
    }
}
