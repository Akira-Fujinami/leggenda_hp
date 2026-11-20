<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\CrawlFrontierPolicy;
use Tests\TestCase;

/**
 * 依頼DS-2: 外国語のページを回らない(2a)・ニュース/記事/製品のページを後回し(2b)・配下の外の上限(2c)の判定。
 */
class CrawlFocusPolicyTest extends TestCase
{
    private function policy(): CrawlFrontierPolicy
    {
        return new CrawlFrontierPolicy;
    }

    public function test_foreign_language_pages_are_detected_by_a_whole_path_segment(): void
    {
        $p = $this->policy();

        $this->assertTrue($p->isForeignLanguage('https://www.optex.co.jp/e/company/'));
        $this->assertTrue($p->isForeignLanguage('https://www.omron.com/global/en/about/'));
        $this->assertTrue($p->isForeignLanguage('https://example.co.jp/recruit/en_US/'));
        $this->assertTrue($p->isForeignLanguage('https://example.co.jp/zh-cn/'));
        $this->assertFalse($p->isForeignLanguage('https://www.omron.com/jp/ja/recruit/'), '日本語の区切りは回る');
        $this->assertFalse($p->isForeignLanguage('https://example.co.jp/recruit/engineer/'), '区切りの一部(en…)には当てない');
        $this->assertFalse($p->isForeignLanguage('https://example.co.jp/recruit/entry.html'));
    }

    public function test_a_language_segment_in_the_origin_path_is_not_a_reason_to_skip(): void
    {
        $p = $this->policy();

        $this->assertFalse($p->isForeignLanguage('https://example.com/en/careers/jobs/', 'https://example.com/en/careers/'));
        $this->assertTrue($p->isForeignLanguage('https://example.com/zh/careers/', 'https://example.com/en/careers/'));
    }

    public function test_it_can_be_turned_off(): void
    {
        config(['brand_wheel.crawl_foreign_language_skip_enabled' => false, 'brand_wheel.crawl_deprioritize_enabled' => false]);

        $this->assertFalse($this->policy()->isForeignLanguage('https://example.co.jp/en/'));
        $this->assertFalse($this->policy()->isDeprioritized('https://example.co.jp/products/a/'));
    }

    public function test_news_article_and_product_pages_are_deprioritized_by_a_whole_path_segment(): void
    {
        $p = $this->policy();

        $this->assertTrue($p->isDeprioritized('https://example.co.jp/products/sensor/'));
        $this->assertTrue($p->isDeprioritized('https://example.co.jp/topics/2026/1.html'));
        $this->assertTrue($p->isDeprioritized('https://example.co.jp/'.rawurlencode('お知らせ').'/1/'));
        $this->assertFalse($p->isDeprioritized('https://example.co.jp/company/philosophy/'));
        $this->assertFalse($p->isDeprioritized('https://example.co.jp/productivity/'), '区切りの一部には当てない');
    }

    public function test_order_links_drops_foreign_language_links(): void
    {
        $links = ['https://example.co.jp/en/recruit/' => 'English', 'https://example.co.jp/recruit/a/' => 'A'];

        $this->assertSame(['https://example.co.jp/recruit/a/'], $this->policy()->orderLinks($links, 'https://example.co.jp/recruit/')['links']);
        $this->assertSame(1, $this->policy()->orderLinks($links, 'https://example.co.jp/recruit/')['skipped']);
    }

    public function test_the_outside_origin_cap_comes_from_config(): void
    {
        $this->assertSame(20, $this->policy()->outsideOriginMaxPages());
        config(['brand_wheel.crawl_outside_origin_max_pages' => 0]);
        $this->assertSame(0, $this->policy()->outsideOriginMaxPages());
    }
}
