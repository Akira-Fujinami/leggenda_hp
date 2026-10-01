<?php

namespace Tests\Unit\BrandWheel;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisCrawledPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\BrandWheel\BrandWheelAnalysisInputFactory;
use Database\Seeders\CategoryDefinitionSeeder;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CH-3(2026-10-01): 1ページ・1段落が予算を独占しないよう、クラスタ内の
 * 選定順序をページ横断のラウンドロビンへ変更したことの検証。
 *
 * シナリオ: ページ1に短い段落3つ(10字・9字・8字)、ページ2に1つの巨大な
 * 段落(100字)。小さい予算(21字、maxChars=tokens*3=21)のもとで、
 * 従来のフラット長さ降順だとページ2の巨大段落が予算の大半を先取りし、
 * ページ1の2つ目以降の段落(9字・8字)が丸ごと切り捨てられる
 * (まだ採用されていないため「捨てられた」扱いになる、部分採用にすら
 * ならない)。ラウンドロビンではページ1・2を交互に取るため、ページ1の
 * 複数段落がより多く生き残る。
 */
class BrandWheelAnalysisInputFactoryParagraphPoolOrderTest extends TestCase
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
        // seed(トップページ・採用ページ)を両方とも用意しない ―― 予算計算の
        // fixedChars/シード文字数をゼロにし、クロール分の予算(maxChars)を
        // そのまま検証できるようにするため。
        config(['services.ai.max_input_tokens' => 7]); // maxChars = 7*3 = 21字。
    }

    private function makeWebsiteAnalysis(): WebsiteAnalysis
    {
        $analysis = Analysis::factory()->create(['crawl_site' => true]);

        return WebsiteAnalysis::factory()->create(['analysis_id' => $analysis->id]);
    }

    private function putCrawledPage(WebsiteAnalysis $websiteAnalysis, string $url, string $html, int $depth): AnalysisCrawledPage
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

    private function seedTwoPages(WebsiteAnalysis $wa): void
    {
        $this->putCrawledPage(
            $wa,
            'https://example.com/page-one',
            '<html><body><p>'.str_repeat('A', 10).'</p><p>'.str_repeat('B', 9).'</p><p>'.str_repeat('C', 8).'</p></body></html>',
            depth: 1,
        );
        $this->putCrawledPage(
            $wa,
            'https://example.com/page-two',
            '<html><body><p>'.str_repeat('Z', 100).'</p></body></html>',
            depth: 2,
        );
    }

    public function test_round_robin_lets_the_first_page_second_paragraph_survive(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->seedTwoPages($wa);

        $input = $this->factory->build($wa->fresh());

        $this->assertStringContainsString(str_repeat('A', 10), $input->homepageBodyText);
        $this->assertStringContainsString(str_repeat('B', 9), $input->homepageBodyText);
    }

    public function test_disabling_round_robin_reproduces_the_legacy_single_page_domination(): void
    {
        config(['brand_wheel.crawl_paragraph_pool_round_robin_enabled' => false]);

        $wa = $this->makeWebsiteAnalysis();
        $this->seedTwoPages($wa);

        $input = $this->factory->build($wa->fresh());

        $this->assertStringContainsString(str_repeat('A', 10), $input->homepageBodyText);
        // フラット長さ降順(旧実装)では、ページ2の巨大段落が予算の大半を
        // 先取りするため、ページ1の2つ目の段落は丸ごと切り捨てられる。
        $this->assertStringNotContainsString(str_repeat('B', 9), $input->homepageBodyText);
    }
}
