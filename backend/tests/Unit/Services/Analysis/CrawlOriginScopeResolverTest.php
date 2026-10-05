<?php

namespace Tests\Unit\Services\Analysis;

use App\Enums\PageType;
use App\Models\AnalysisPage;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
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

    // ------------------------------------------------------------------
    // 依頼CM-1(2026-10-06): 転送されたときの起点。
    // ------------------------------------------------------------------

    private function pages(WebsiteAnalysis $wa, string $homepageUrl, string $homepageFinal, ?string $recruitUrl = null, ?string $recruitFinal = null): void
    {
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Homepage, 'url' => $homepageUrl, 'final_url' => $homepageFinal]);
        if ($recruitUrl !== null) {
            AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => $recruitUrl, 'final_url' => $recruitFinal ?? $recruitUrl]);
        }
    }

    /** 実例: サイトの一番上を入力 → 別ホストの奥のページへ転送 → 自己参照で採用ページの行が複製された。 */
    public function test_a_top_page_input_redirected_to_a_deeper_page_on_another_host_uses_the_top_of_the_destination_host(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->pages($wa, 'https://hello-world.smarthr.co.jp/', 'https://recruit.smarthr.co.jp/engineer/', 'https://hello-world.smarthr.co.jp/', 'https://recruit.smarthr.co.jp/engineer/');

        $scope = $this->resolver()->resolveScope($wa);

        $this->assertSame('https://recruit.smarthr.co.jp/', $scope['origin_url']);
        $this->assertSame('recruit.smarthr.co.jp', $scope['host']);
        $this->assertSame('/', $scope['path']);
    }

    public function test_the_same_applies_on_the_same_host_and_when_there_is_no_recruit_row(): void
    {
        $sameHost = WebsiteAnalysis::factory()->create();
        $this->pages($sameHost, 'https://www.example.com/', 'https://www.example.com/ja/', 'https://www.example.com/', 'https://www.example.com/ja/');
        $this->assertSame('https://www.example.com/', $this->resolver()->resolveScope($sameHost)['origin_url']);

        $noRecruit = WebsiteAnalysis::factory()->create();
        $this->pages($noRecruit, 'https://example.org/', 'https://example.org/corp/top/');
        $this->assertSame('https://example.org/', $this->resolver()->resolveScope($noRecruit)['origin_url']);
    }

    public function test_a_top_page_input_without_a_redirect_is_unchanged(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->pages($wa, 'https://recruit.moneyforward.com/', 'https://recruit.moneyforward.com/', 'https://recruit.moneyforward.com/', 'https://recruit.moneyforward.com/');

        $this->assertSame('https://recruit.moneyforward.com/', $this->resolver()->resolveScope($wa)['origin_url']);
    }

    /** 奥のページを入力した場合は、転送の有無を問わず従来どおり最終URLのディレクトリ。 */
    public function test_a_deep_page_input_keeps_the_final_url_directory_with_or_without_a_redirect(): void
    {
        $noRedirect = WebsiteAnalysis::factory()->create();
        $this->pages($noRedirect, 'https://cybozu.co.jp/recruit/', 'https://cybozu.co.jp/recruit/', 'https://cybozu.co.jp/recruit/', 'https://cybozu.co.jp/recruit/');
        $this->assertSame('https://cybozu.co.jp/recruit/', $this->resolver()->resolveScope($noRedirect)['origin_url']);

        $redirected = WebsiteAnalysis::factory()->create();
        $this->pages($redirected, 'https://example.com/recruit/', 'https://example.com/recruit/saiyo/', 'https://example.com/recruit/', 'https://example.com/recruit/saiyo/');
        $scope = $this->resolver()->resolveScope($redirected);
        $this->assertSame('https://example.com/recruit/saiyo/', $scope['origin_url']);
        $this->assertSame('/recruit/saiyo/', $scope['path']);
    }

    /** システムがリンクをたどって見つけた採用ページ(トップページ行とurlが異なる)は変えない。 */
    public function test_a_recruit_page_the_system_found_by_following_a_link_is_unchanged(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->pages($wa, 'https://www.example.com/', 'https://www.example.com/', 'https://www.example.com/recruit/', 'https://www.example.com/recruit/');
        $this->assertSame('https://www.example.com/recruit/', $this->resolver()->resolveScope($wa)['origin_url']);

        // トップページが転送されていても、見つけた採用ページが別のページなら変えない。
        $redirectedHome = WebsiteAnalysis::factory()->create();
        $this->pages($redirectedHome, 'https://example.net/', 'https://example.net/ja/', 'https://example.net/ja/careers/', 'https://example.net/ja/careers/');
        $this->assertSame('https://example.net/ja/careers/', $this->resolver()->resolveScope($redirectedHome)['origin_url']);
    }

    public function test_the_widening_can_be_disabled_by_config_and_then_matches_the_previous_origin(): void
    {
        config(['brand_wheel.crawl_origin_widen_redirected_top' => false]);

        $wa = WebsiteAnalysis::factory()->create();
        $this->pages($wa, 'https://hello-world.smarthr.co.jp/', 'https://recruit.smarthr.co.jp/engineer/', 'https://hello-world.smarthr.co.jp/', 'https://recruit.smarthr.co.jp/engineer/');

        $scope = $this->resolver()->resolveScope($wa);
        $this->assertSame('https://recruit.smarthr.co.jp/engineer/', $scope['origin_url']);
        $this->assertSame('/engineer/', $scope['path']);
    }

    /** 3つの呼び出し元は、同じ共有クラスの同じ判定を使い、別々の起点判定を持たない。 */
    public function test_all_three_callers_share_this_resolver_and_none_defines_its_own_origin_logic(): void
    {
        foreach ([
            'app/Jobs/Analysis/CrawlWebsitePageJob.php',
            'app/Services/BrandWheel/BrandWheelAnalysisInputFactory.php',
            'app/Services/Report/AdminComparisonSiteHierarchyBuilder.php',
        ] as $relative) {
            $source = (string) file_get_contents(base_path($relative));
            $this->assertStringContainsString('->resolveScope(', $source, "{$relative}: 共有クラスのresolveScope()を使う");
            $this->assertStringNotContainsString('function resolveOriginUrl', $source, "{$relative}: 起点の判定を別に持たない");
        }
    }

    public function test_the_hierarchy_builder_reports_the_same_origin_as_the_resolver(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->pages($wa, 'https://hello-world.smarthr.co.jp/', 'https://recruit.smarthr.co.jp/engineer/', 'https://hello-world.smarthr.co.jp/', 'https://recruit.smarthr.co.jp/engineer/');

        $this->assertSame(
            $this->resolver()->resolveScope($wa)['origin_url'],
            app(AdminComparisonSiteHierarchyBuilder::class)->countWithinOrigin($wa)['origin_url'],
        );
    }
}
