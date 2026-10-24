<?php

namespace Tests\Unit\Services\Analysis;

use App\Services\Analysis\CrawlFrontierPolicy;
use App\Services\Analysis\CrawlLinkExtractor;
use Tests\TestCase;

/**
 * 依頼DF(2026-10-22): 巡回の優先(DF-1)・同じ親の数え方(DF-2)・回らないリンク(DF-3)の判定。
 */
class CrawlFrontierPolicyTest extends TestCase
{
    private function policy(): CrawlFrontierPolicy
    {
        return new CrawlFrontierPolicy;
    }

    public function test_priority_matches_the_url_path_or_the_link_text(): void
    {
        $this->assertTrue($this->policy()->isPriority('https://example.co.jp/recruit/benefits/'));
        $this->assertTrue($this->policy()->isPriority('https://example.co.jp/recruit/%E5%88%B6%E5%BA%A6/'), 'デコードしたパス(制度)にも当てる');
        $this->assertTrue($this->policy()->isPriority('https://example.co.jp/recruit/page-12/', '給与と評価制度'));
        $this->assertFalse($this->policy()->isPriority('https://example.co.jp/recruit/staff/akai.html', '赤井 太郎-社員紹介'));
    }

    public function test_priority_can_be_disabled(): void
    {
        config(['brand_wheel.crawl_priority_enabled' => false]);

        $this->assertFalse($this->policy()->isPriority('https://example.co.jp/recruit/benefits/', '福利厚生'));
    }

    public function test_skip_matches_the_url_path_or_the_link_text(): void
    {
        $this->assertTrue($this->policy()->shouldSkip('https://example.co.jp/recruit/privacy-policy/'));
        $this->assertTrue($this->policy()->shouldSkip('https://example.co.jp/recruit/official-rules/sea.html', 'Recruitment Privacy Notice | Kintone SEA'));
        $this->assertTrue($this->policy()->shouldSkip('https://example.co.jp/terms.html'));
        $this->assertTrue($this->policy()->shouldSkip('https://example.co.jp/a.html', '個人情報の取り扱い'));
        $this->assertFalse($this->policy()->shouldSkip('https://example.co.jp/recruit/about/', '基本を知る'));
    }

    public function test_skip_can_be_disabled(): void
    {
        config(['brand_wheel.crawl_skip_enabled' => false]);

        $this->assertFalse($this->policy()->shouldSkip('https://example.co.jp/recruit/privacy-policy/', '個人情報'));
    }

    public function test_parent_key_groups_pages_under_the_same_directory_and_ignores_the_query(): void
    {
        $p = $this->policy();

        $this->assertSame('example.co.jp/recruit/staff/', $p->parentKey('https://example.co.jp/recruit/staff/akai.html'));
        $this->assertSame('example.co.jp/recruit/staff/', $p->parentKey('https://example.co.jp/recruit/staff/'));
        $this->assertSame('example.co.jp/recruit/career/', $p->parentKey('https://example.co.jp/recruit/career/?page=2'));
        $this->assertSame('example.co.jp/recruit/career/', $p->parentKey('https://example.co.jp/recruit/career/'));
        $this->assertSame('example.co.jp/', $p->parentKey('https://example.co.jp'));
        $this->assertNotSame($p->parentKey('https://example.co.jp/recruit/about/'), $p->parentKey('https://example.co.jp/recruit/job/'));
    }

    public function test_order_links_drops_skipped_links_and_moves_priority_links_first_keeping_the_original_order(): void
    {
        $result = $this->policy()->orderLinks([
            'https://example.co.jp/recruit/staff/a.html' => '社員A',
            'https://example.co.jp/recruit/privacy/' => '採用における個人情報の取り扱い',
            'https://example.co.jp/recruit/x/' => '給与と評価制度',
            'https://example.co.jp/recruit/staff/b.html' => '社員B',
            'https://example.co.jp/recruit/benefit/' => '',
        ]);

        $this->assertSame([
            'https://example.co.jp/recruit/x/',
            'https://example.co.jp/recruit/benefit/',
            'https://example.co.jp/recruit/staff/a.html',
            'https://example.co.jp/recruit/staff/b.html',
        ], $result['links']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, $result['prioritized']);
    }

    public function test_link_extractor_returns_the_link_text_with_image_alt_fallback_and_merges_duplicates(): void
    {
        $html = '<html><body>'
            .'<a href="/recruit/system/">社内<b>制度</b></a>'
            .'<a href="/recruit/staff/a.html"><img src="a.png" alt="社員紹介 A"></a>'
            .'<a href="/recruit/system/">働き方</a>'
            .'<a href="mailto:x@example.co.jp">mail</a>'
            .'</body></html>';

        $links = (new CrawlLinkExtractor)->extractAbsoluteLinksWithText($html, 'https://example.co.jp/recruit/');

        $this->assertSame([
            'https://example.co.jp/recruit/system/' => '社内制度 働き方',
            'https://example.co.jp/recruit/staff/a.html' => '社員紹介 A',
        ], $links);
    }
}
