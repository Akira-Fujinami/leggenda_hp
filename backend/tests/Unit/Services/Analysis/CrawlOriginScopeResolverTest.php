<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlOriginScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼CF-1(2026-09-29): AdminComparisonSiteHierarchyBuilder(依頼CB-3)が
 * 私有していたresolveScope()/isWithinScope()をこのクラスへ抜き出した
 * ―― CrawlWebsitePageJobの取得順序も同じ判定を必要とするため(同じ判定を
 * 2箇所に持たない、依頼者指定)。既存の判定内容自体は変更していない。
 */
class CrawlOriginScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CrawlOriginScopeResolver
    {
        return app(CrawlOriginScopeResolver::class);
    }

    public function test_scope_prefers_the_recruit_page_over_the_homepage(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Homepage, 'url' => 'https://example.com/']);
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/', 'final_url' => 'https://example.com/recruit/top']);

        $scope = $this->resolver()->resolveScope($wa);

        $this->assertSame('https://example.com/recruit/top', $scope['origin_url']);
        $this->assertSame('example.com', $scope['host']);
    }

    public function test_scope_falls_back_to_homepage_then_website_url(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Homepage, 'url' => 'https://example.com/']);

        $this->assertSame('https://example.com/', $this->resolver()->resolveScope($wa)['origin_url']);

        $waNoPages = WebsiteAnalysis::factory()->create();
        $this->assertSame($waNoPages->website?->url, $this->resolver()->resolveScope($waNoPages)['origin_url']);
    }

    public function test_scope_is_null_when_no_origin_url_can_be_resolved(): void
    {
        $wa = WebsiteAnalysis::factory()->create(['website_id' => Website::factory()->create(['url' => ''])]);

        $this->assertNull($this->resolver()->resolveScope($wa));
    }

    public function test_is_within_scope_matches_host_and_path_prefix(): void
    {
        $scope = ['origin_url' => 'https://example.com/recruit/', 'host' => 'example.com', 'path' => '/recruit/'];
        $resolver = $this->resolver();

        $this->assertTrue($resolver->isWithinScope('https://example.com/recruit/careers.html', null, $scope));
        $this->assertFalse($resolver->isWithinScope('https://example.com/about/company.html', null, $scope));
        $this->assertFalse($resolver->isWithinScope('https://other.com/recruit/careers.html', null, $scope));
    }

    /**
     * 依頼CF-1: pending(final_urlがまだ無い)ページでも、urlへfallbackして
     * 判定できること ―― 巡回中の取得順序判定に使えるための必須要件。
     */
    public function test_is_within_scope_falls_back_to_url_when_final_url_is_not_yet_known(): void
    {
        $scope = ['origin_url' => 'https://example.com/recruit/', 'host' => 'example.com', 'path' => '/recruit/'];

        $this->assertTrue($this->resolver()->isWithinScope('https://example.com/recruit/careers.html', null, $scope));
    }

    /**
     * final_urlがある場合はfinal_url(リダイレクト後の実際の到達先)を
     * 優先すること(既存の挙動、変更しない)。
     */
    public function test_is_within_scope_prefers_final_url_over_url_when_both_are_present(): void
    {
        $scope = ['origin_url' => 'https://example.com/recruit/', 'host' => 'example.com', 'path' => '/recruit/'];

        $this->assertFalse($this->resolver()->isWithinScope(
            'https://example.com/recruit/redirected.html',
            'https://example.com/about/company.html',
            $scope,
        ));
    }
}
