<?php

namespace Tests\Unit\BrandWheel;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CH-2(2026-10-01): 定型文ページ(プライバシーポリシー等)を段落
 * プールから除外する。巡回自体は変更しない(このテストでは
 * crawl_excluded_path_patternsに触れない)。
 */
class BrandWheelAnalysisInputFactoryParagraphPoolExclusionTest extends TestCase
{
    use RefreshDatabase;

    private BrandWheelAnalysisInputFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CategoryDefinitionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Storage::fake('analysis');
        $this->factory = app(BrandWheelAnalysisInputFactory::class);
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $analysis = Analysis::factory()->create(['crawl_site' => true]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id]);
    }

    private function putSeedPage(WebsiteAnalysis $websiteAnalysis, PageType $pageType, string $html): AnalysisPage
    {
        $filename = $pageType === PageType::Recruit ? 'recruit.html' : 'homepage.html';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, $filename);
        Storage::disk('analysis')->put($path, $html);

        return AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => 'https://example.com',
            'final_url' => 'https://example.com',
            'page_type' => $pageType,
            'http_status' => 200,
            'raw_html_path' => $path,
            'fetched_at' => now(),
        ]);
    }

    private function putCrawledPage(WebsiteAnalysis $websiteAnalysis, string $url, string $html, int $depth = 1): AnalysisCrawledPage
    {
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath(
            $websiteAnalysis->analysis_id,
            $websiteAnalysis->id,
            'crawl/'.hash('sha256', $url).'.html',
        );
        Storage::disk('analysis')->put($path, $html);

        $page = new AnalysisCrawledPage;
        $page->website_analysis_id = $websiteAnalysis->id;
        $page->url = $url;
        $page->final_url = $url;
        $page->depth = $depth;
        $page->discovered_via = 'link';
        $page->status = AnalysisCrawledPage::STATUS_FETCHED;
        $page->raw_html_path = $path;
        $page->content_length = strlen($html);
        $page->fetched_at = now();
        $page->save();

        return $page;
    }

    public function test_excludes_pages_matching_configured_keywords_from_the_pool(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/member', '<html><body><p>社員インタビューの内容です。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/privacy-policy', '<html><body><p>個人情報の取り扱いについて定めます。</p></body></html>');

        $input = $this->factory->build($wa->fresh());

        $this->assertStringContainsString('社員インタビューの内容です', $input->homepageBodyText);
        $this->assertStringNotContainsString('個人情報の取り扱いについて定めます', $input->homepageBodyText);
    }

    public function test_exclusion_can_be_disabled_via_config(): void
    {
        config(['brand_wheel.crawl_paragraph_pool_exclude_enabled' => false]);

        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/member', '<html><body><p>社員インタビューの内容です。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/privacy-policy', '<html><body><p>個人情報の取り扱いについて定めます。</p></body></html>');

        $input = $this->factory->build($wa->fresh());

        $this->assertStringContainsString('社員インタビューの内容です', $input->homepageBodyText);
        $this->assertStringContainsString('個人情報の取り扱いについて定めます', $input->homepageBodyText);
    }

    /**
     * 安全弁(依頼者指定): 除外の結果、このサイトの段落プールが0件になる
     * 場合は除外を適用しない(材料をゼロにしない)。
     */
    public function test_does_not_exclude_when_doing_so_would_empty_the_pool(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Homepage, '<html><body><p>トップページの本文です。</p></body></html>');
        // 除外語に一致するページしか候補が無い。
        $this->putCrawledPage($wa, 'https://example.com/privacy-policy', '<html><body><p>個人情報の取り扱いについて定めます。</p></body></html>');

        $input = $this->factory->build($wa->fresh());

        $this->assertStringContainsString('個人情報の取り扱いについて定めます', $input->homepageBodyText);
    }
}
