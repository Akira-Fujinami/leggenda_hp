<?php

namespace Tests\Unit\Models;

use App\Models\Website;
use Tests\TestCase;

/**
 * 依頼DW: ページを取りに行くURLは、入力が「/」で終わっていれば「/」を付ける(normalized_url は落としている)。
 */
class WebsiteFetchUrlTest extends TestCase
{
    private function site(string $url, string $normalized): Website
    {
        return new Website(['url' => $url, 'normalized_url' => $normalized]);
    }

    public function test_a_trailing_slash_in_the_input_is_kept_for_fetching(): void
    {
        $this->assertSame('https://www.sankei.jp/saiyo/', $this->site('https://www.sankei.jp/saiyo/', 'https://www.sankei.jp/saiyo')->fetchUrl());
    }

    public function test_other_urls_are_fetched_as_normalized(): void
    {
        $this->assertSame('https://example.com/recruit', $this->site('https://example.com/recruit', 'https://example.com/recruit')->fetchUrl());
        $this->assertSame('https://example.com', $this->site('https://example.com/', 'https://example.com')->fetchUrl(), '一番上はそのまま');
        $this->assertSame('https://example.com/recruit/index.html', $this->site('https://example.com/recruit/index.html', 'https://example.com/recruit/index.html')->fetchUrl());
        $this->assertSame('https://example.com/r?x=1', $this->site('https://example.com/r/?x=1', 'https://example.com/r?x=1')->fetchUrl(), 'クエリ付きは変えない');
    }
}
