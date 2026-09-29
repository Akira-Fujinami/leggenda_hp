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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CG-1(2026-09-30): 「34,570文字入った」だけでは中身が一切分からな
 * かった(依頼者指摘、本番ログanalysis_id=145の実例)ことへの対応。
 * 選定ロジック(長さ順・予算配分・トークン見積もり)は一切変更せず、その
 * 結果(ページ別の寄与・起点URL配下/外の比率・クラスタごとの予算消化・
 * 採用段落の長さ分布・捨てられた段落・部分的に切られた段落)をログへ
 * 出すだけの変更を検証する。
 */
class BrandWheelAnalysisInputFactoryCrawlSelectionLoggingTest extends TestCase
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

    private function putSeedPage(WebsiteAnalysis $websiteAnalysis, PageType $pageType, string $url, string $html): AnalysisPage
    {
        $filename = $pageType === PageType::Recruit ? 'recruit.html' : 'homepage.html';
        $path = app(AnalysisStoragePaths::class)->rawHtmlPath($websiteAnalysis->analysis_id, $websiteAnalysis->id, $filename);
        Storage::disk('analysis')->put($path, $html);

        return AnalysisPage::query()->create([
            'website_analysis_id' => $websiteAnalysis->id,
            'url' => $url,
            'final_url' => $url,
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

    /**
     * Log::spy()をセットし、'Brand wheel analysis input: crawl selection
     * breakdown'・'Brand wheel analysis input truncated due to
     * AI_MAX_INPUT_TOKENS'の2つのcontextを、呼び出し後に読めるように
     * 参照渡しの配列へ集める。
     *
     * @param  array<string, array>  $captured
     */
    private function captureLogsInto(array &$captured): void
    {
        Log::shouldReceive('info')->withArgs(function (string $message, array $context) use (&$captured) {
            $captured[$message] = $context;

            return true;
        })->zeroOrMoreTimes();
        Log::shouldReceive('warning')->withArgs(function (string $message, array $context) use (&$captured) {
            $captured[$message] = $context;

            return true;
        })->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
    }

    /**
     * 基本ケース: 予算を使い切らない(全段落が採用される)とき、ログが
     * 出て、件数・文字数が食い違わないこと。
     */
    public function test_logs_the_breakdown_when_all_crawled_paragraphs_fit_within_budget(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>採用ページ本文。</p></body></html>');
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>トップページ本文。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/recruit/member', '<html><body><p>社員インタビューの本文です。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/service', '<html><body><p>事業紹介の本文です。</p></body></html>');

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown, 'ログが出ていること');
        $this->assertArrayHasKey('top_pages_by_chars', $breakdown);
        $this->assertArrayHasKey('origin_chars', $breakdown);
        $this->assertArrayHasKey('outside_chars', $breakdown);
        $this->assertArrayHasKey('clusters', $breakdown);
        $this->assertArrayHasKey('selected_paragraph_length', $breakdown);
        $this->assertArrayHasKey('discarded_paragraph_count', $breakdown);
        $this->assertArrayHasKey('discarded_chars', $breakdown);
        $this->assertArrayHasKey('partially_cut_paragraph_count', $breakdown);
        $this->assertArrayHasKey('paragraph_separator_chars', $breakdown);

        // 全て採用されているため、捨てられた段落は0件。
        $this->assertSame(0, $breakdown['discarded_paragraph_count']);
        $this->assertSame(0, $breakdown['discarded_chars']);
        $this->assertSame(0, $breakdown['partially_cut_paragraph_count']);
    }

    /**
     * 依頼CG-1必須: 「ページ別の寄与の合計」=「crawl_chars_added」
     * (既存のtruncatedログが持つ値)であること。
     */
    public function test_top_pages_total_matches_crawl_chars_added(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>採用ページ本文。</p></body></html>');
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>トップページ本文。</p></body></html>');
        for ($i = 0; $i < 5; $i++) {
            $this->putCrawledPage($wa, "https://example.com/recruit/page-{$i}", '<html><body><p>'.str_repeat("候補段落{$i}", 30).'</p></body></html>');
        }
        config(['services.ai.max_input_tokens' => 100]); // maxChars=300、seedを引いた残りだけクロール分に回る小さい予算。

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown);

        $truncatedLog = $captured['Brand wheel analysis input truncated due to AI_MAX_INPUT_TOKENS'] ?? null;
        $this->assertNotNull($truncatedLog, '予算が小さいため切り詰めログも出ているはず(前提の確認)');

        // joinInOriginalOrder()が段落を"\n"で連結するため、crawl_chars_added
        // (連結後の文字列長)には区切り文字ぶんが上乗せされる。
        // paragraph_separator_charsで明示的にその差分を埋め合わせられること
        // (依頼CG-1必須の「合計が合うこと」)。
        $topPagesTotal = array_sum(array_column($breakdown['top_pages_by_chars'], 'chars'));
        $this->assertSame($truncatedLog['crawl_chars_added'], $topPagesTotal + $breakdown['paragraph_separator_chars']);

        $this->assertGreaterThan(0, $breakdown['discarded_paragraph_count'], '予算が小さいため捨てられた段落があるはず(前提の確認)');
    }

    /**
     * 起点URL配下/外の比率が、CrawlOriginScopeResolverと同じ判定基準で
     * 出ること(判定を再実装していないことの確認)。
     */
    public function test_origin_ratio_reflects_pages_within_and_outside_the_origin(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>採用ページ本文。</p></body></html>');
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>トップページ本文。</p></body></html>');
        // 起点(/recruit/)配下。
        $this->putCrawledPage($wa, 'https://example.com/recruit/member', '<html><body><p>'.str_repeat('起点配下の段落です', 5).'</p></body></html>');
        // 起点の外(/ir/)。
        $this->putCrawledPage($wa, 'https://example.com/ir/report', '<html><body><p>'.str_repeat('起点の外の段落です', 5).'</p></body></html>');

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown);
        $this->assertGreaterThan(0, $breakdown['origin_chars'], '起点配下のページ由来の文字数が計上されること');
        $this->assertGreaterThan(0, $breakdown['outside_chars'], '起点の外のページ由来の文字数が計上されること');
        $this->assertIsFloat($breakdown['origin_ratio']);
    }

    /**
     * 予算が0(seed本文だけで予算を使い切っている等)で、1段落も採用され
     * ないときも、ログが壊れず件数が食い違わないこと(0件のケース)。
     */
    public function test_does_not_break_when_zero_paragraphs_are_selected(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>'.str_repeat('採', 100).'</p></body></html>');
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>'.str_repeat('会', 100).'</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/recruit/member', '<html><body><p>採用できないはずの段落です。</p></body></html>');
        // maxChars = 60*3=180。seedだけで200文字あり、予算超過でクロール分の
        // 残り予算は0になる。
        config(['services.ai.max_input_tokens' => 60]);

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown, '予算0件でもログ自体は出ること');
        $this->assertSame([], $breakdown['top_pages_by_chars']);
        $this->assertSame(0, $breakdown['origin_chars']);
        $this->assertSame(0, $breakdown['outside_chars']);
        $this->assertNull($breakdown['origin_ratio']);
        $this->assertSame(0, $breakdown['selected_paragraph_length']['count']);
        $this->assertNull($breakdown['selected_paragraph_length']['max']);
        $this->assertNull($breakdown['selected_paragraph_length']['min']);
        $this->assertNull($breakdown['selected_paragraph_length']['median']);
        $this->assertGreaterThan(0, $breakdown['discarded_paragraph_count'], '候補はあったが予算0で全て捨てられること');
    }

    /**
     * 段落が1件だけ採用されるとき、長さ分布(max=median=min)が矛盾なく
     * 出ること。
     */
    public function test_length_distribution_is_consistent_when_exactly_one_paragraph_is_selected(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>seed。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/service', '<html><body><p>唯一採用される段落です。</p></body></html>');

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown);
        $this->assertSame(1, $breakdown['selected_paragraph_length']['count']);
        $this->assertSame($breakdown['selected_paragraph_length']['max'], $breakdown['selected_paragraph_length']['min']);
        $this->assertSame($breakdown['selected_paragraph_length']['max'], $breakdown['selected_paragraph_length']['median']);
    }

    /**
     * 部分的に切られた段落(mb_substrで途中まで採用)が発生したとき、
     * partially_cut_paragraph_countに反映されること。
     */
    public function test_partially_cut_paragraph_is_counted(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>seed。</p></body></html>');
        // 予算(下記)より明らかに長い1段落だけを候補にする ―― 必ず部分採用になる。
        $this->putCrawledPage($wa, 'https://example.com/service', '<html><body><p>'.str_repeat('とても長い段落です', 50).'</p></body></html>');
        config(['services.ai.max_input_tokens' => 20]); // maxChars=60、seed(3文字)を引いた残りがクロール分の予算。

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown);
        $this->assertGreaterThanOrEqual(1, $breakdown['partially_cut_paragraph_count']);
    }

    /**
     * 依頼CG-1必須: 「採用＋捨てられた」＝「プール全体」であること。
     * プール全体の件数は既存のcrawled_pages_integratedログ
     * (recruit_cluster_pool_count+homepage_cluster_pool_count、依頼E-7由来、
     * このログ自体は変更していない)から独立に取り、循環参照にならない
     * ようにする。
     */
    public function test_selected_plus_discarded_equals_the_whole_pool(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>採用ページ本文。</p></body></html>');
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>トップページ本文。</p></body></html>');
        for ($i = 0; $i < 5; $i++) {
            $this->putCrawledPage($wa, "https://example.com/recruit/page-{$i}", '<html><body><p>'.str_repeat("候補段落{$i}", 30).'</p></body></html>');
        }
        config(['services.ai.max_input_tokens' => 100]);

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $integratedLog = $captured['Brand wheel analysis input: crawled pages integrated'] ?? null;
        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($integratedLog);
        $this->assertNotNull($breakdown);

        $poolCount = $integratedLog['recruit_cluster_pool_count'] + $integratedLog['homepage_cluster_pool_count'];
        $selectedCount = $breakdown['selected_paragraph_length']['count'];

        $this->assertSame($poolCount, $selectedCount + $breakdown['discarded_paragraph_count']);
    }

    /**
     * 依頼CG-1必須: リードの個人情報・段落/本文のテキストそのものが
     * ログに出ないこと。
     */
    public function test_log_never_contains_paragraph_text_or_lead_pii(): void
    {
        $wa = $this->makeWebsiteAnalysis();
        $this->putSeedPage($wa, PageType::Recruit, 'https://example.com/recruit/', '<html><body><p>採用ページ本文の秘密の文言です。</p></body></html>');
        $this->putSeedPage($wa, PageType::Homepage, 'https://example.com/', '<html><body><p>トップページ本文の秘密の文言です。</p></body></html>');
        $this->putCrawledPage($wa, 'https://example.com/recruit/member', '<html><body><p>社員インタビューの独自表現テキストです。</p></body></html>');

        $captured = [];
        $this->captureLogsInto($captured);
        $this->factory->build($wa->fresh());

        $breakdown = $captured['Brand wheel analysis input: crawl selection breakdown'] ?? null;
        $this->assertNotNull($breakdown);

        $encoded = (string) json_encode($breakdown, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('秘密の文言', $encoded);
        $this->assertStringNotContainsString('独自表現テキスト', $encoded);
        $this->assertStringNotContainsString('@', $encoded, 'メールアドレスらしき文字列を含まないこと');
    }
}
