<?php

namespace Tests\Feature\Report;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼CB-3(2026-09-24): 「自社サイトの階層図」データの組み立て。
 * analysis_crawled_pages.discovered_viaが親子関係を持たない
 * (依頼者指定、スキーマ変更禁止)ため、URLのパス階層(起点URLより下の
 * パスセグメント)から1階層目の枝だけを組み立てることを確認する。
 */
class AdminComparisonSiteHierarchyBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): AdminComparisonSiteHierarchyBuilder
    {
        return new AdminComparisonSiteHierarchyBuilder;
    }

    private function crawledPage(WebsiteAnalysis $wa, string $url, ?string $title, string $status = AnalysisCrawledPage::STATUS_FETCHED): AnalysisCrawledPage
    {
        return AnalysisCrawledPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'url' => $url,
            'title' => $title,
            'status' => $status,
        ]);
    }

    /**
     * 依頼CB-3の例そのもの: 起点 .../recruit/、対象
     * .../recruit/careers/interview/01.html → 1階層目はcareers。
     */
    public function test_branches_are_grouped_by_the_first_path_segment_below_the_origin(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/careers/interview/01.html', 'インタビュー01');
        $this->crawledPage($wa, 'https://example.com/recruit/careers/interview/02.html', 'インタビュー02');
        // このページは枝(culture)のインデックスページ自身(セグメント1個)
        // なので、枝の名前はtitle('カルチャー・社風')になる
        // (test_branch_name_uses_the_index_page_title_when_crawledで別途
        // 検証済み)。ここではグルーピング自体(枝の件数)だけを見るため、
        // titleを持たせずセグメント名のまま集計されるようにする。
        $this->crawledPage($wa, 'https://example.com/recruit/culture/about.html', null);

        $result = $this->builder()->build($wa);

        $this->assertSame('https://example.com/recruit/', $result['origin_url']);
        $byName = collect($result['branches'])->keyBy('name');
        $this->assertSame(2, $byName['careers']['page_count']);
        $this->assertSame(1, $byName['culture']['page_count']);
    }

    /**
     * 依頼CB-3: 枝の名前は、その階層のインデックスページ(例 /careers/)が
     * 巡回できていればそのtitleを使うこと。
     */
    public function test_branch_name_uses_the_index_page_title_when_crawled(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/careers/', '仕事を知る');
        $this->crawledPage($wa, 'https://example.com/recruit/careers/detail.html', '職種詳細');

        $result = $this->builder()->build($wa);

        $this->assertSame('仕事を知る', $result['branches'][0]['name']);
    }

    /**
     * 依頼CB-3: インデックスページが巡回できていない(title不明)ときは
     * パスセグメントをそのまま使うこと。
     */
    public function test_branch_name_falls_back_to_the_path_segment_without_an_index_title(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/benefits/detail.html', '福利厚生の詳細');

        $result = $this->builder()->build($wa);

        $this->assertSame('benefits', $result['branches'][0]['name']);
    }

    /**
     * 依頼CB-3必須: depth列(seedからのBFSホップ数)は使わないこと ――
     * 深いdepthのページでもURLのパスから正しく1階層目に集計されること
     * (depthを直接使うと壊れるケースを、あえてdepthと矛盾させて確認する)。
     */
    public function test_depth_column_is_not_used_for_grouping(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        AnalysisCrawledPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'url' => 'https://example.com/recruit/careers/interview/deep/page.html',
            'title' => 'ページ',
            'status' => AnalysisCrawledPage::STATUS_FETCHED,
            'depth' => 1,
        ]);

        $result = $this->builder()->build($wa);

        $this->assertSame('careers', $result['branches'][0]['name']);
    }

    /**
     * 巡回に失敗した(status!==fetched)ページは集計に含めないこと。
     */
    public function test_pages_that_failed_to_be_fetched_are_excluded(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/careers/a.html', 'A', AnalysisCrawledPage::STATUS_FAILED);
        $this->crawledPage($wa, 'https://example.com/recruit/careers/b.html', 'B', AnalysisCrawledPage::STATUS_PENDING);

        $result = $this->builder()->build($wa);

        $this->assertSame([], $result['branches']);
    }

    /**
     * 起点URLと異なるホストのページ(許可外のドメイン等)は集計に含めない
     * こと。
     */
    public function test_pages_on_a_different_host_are_excluded(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://other.example.com/recruit/careers/a.html', 'A');

        $result = $this->builder()->build($wa);

        $this->assertSame([], $result['branches']);
    }

    /**
     * 依頼CB-3: 枝が多いサイトで、ページ数の多い順に絞られ、あふれた分は
     * other_branch_countに畳まれること。
     */
    public function test_branches_are_limited_and_sorted_by_page_count_descending(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_branch_limit');
        $branchCount = $limit + 3;
        for ($i = 0; $i < $branchCount; $i++) {
            // 枝ごとのページ数を変え(0番目が最多)、降順ソートを検証できるようにする。
            $pagesInBranch = $branchCount - $i;
            for ($p = 0; $p < $pagesInBranch; $p++) {
                $this->crawledPage($wa, "https://example.com/recruit/branch{$i}/page{$p}.html", "ページ{$p}");
            }
        }

        $result = $this->builder()->build($wa);

        $this->assertCount($limit, $result['branches']);
        $this->assertSame(3, $result['other_branch_count']);
        $this->assertSame('branch0', $result['branches'][0]['name'], '最もページ数が多い枝が先頭に来ること');
        $counts = array_column($result['branches'], 'page_count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, 'ページ数の多い順に並んでいること');
    }

    /**
     * 依頼CB-3: 起点URLは採用ページ(Recruit)のfinal_url/urlを優先すること。
     */
    public function test_origin_url_prefers_the_recruit_page(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Homepage, 'url' => 'https://example.com/']);
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/', 'final_url' => 'https://example.com/recruit/top']);

        $result = $this->builder()->build($wa);

        $this->assertSame('https://example.com/recruit/top', $result['origin_url']);
    }

    /**
     * 採用ページが無い場合はトップページへ、それも無ければWebsite.urlへ
     * fallbackすること(クラッシュせず、常に何らかの起点を返すこと)。
     */
    public function test_origin_url_falls_back_to_homepage_then_website_url(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Homepage, 'url' => 'https://example.com/']);

        $result = $this->builder()->build($wa);
        $this->assertSame('https://example.com/', $result['origin_url']);

        $waNoPages = WebsiteAnalysis::factory()->create();
        $result2 = $this->builder()->build($waNoPages);
        $this->assertSame($waNoPages->website?->url, $result2['origin_url']);
    }
}
