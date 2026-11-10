<?php

namespace Tests\Unit\Jobs\Analysis;

use App\Jobs\Analysis\FetchRobotsJob;
use PHPUnit\Framework\TestCase;

/**
 * 依頼DP: robots.txt は入力URLのパスに関係なく、ホストの一番上から読む。
 */
class RobotsUrlTest extends TestCase
{
    public function test_the_robots_url_is_always_at_the_root_of_the_host(): void
    {
        $this->assertSame('https://www.omron.com/robots.txt', FetchRobotsJob::robotsUrlFor('https://www.omron.com/jp/ja/recruit/newgrad/'));
        $this->assertSame('https://example.com/robots.txt', FetchRobotsJob::robotsUrlFor('https://example.com'));
        $this->assertSame('https://example.com/robots.txt', FetchRobotsJob::robotsUrlFor('https://Example.com/recruit/index.html?x=1#top'));
        $this->assertSame('http://example.com:8080/robots.txt', FetchRobotsJob::robotsUrlFor('http://example.com:8080/a/b/'));
    }
}
