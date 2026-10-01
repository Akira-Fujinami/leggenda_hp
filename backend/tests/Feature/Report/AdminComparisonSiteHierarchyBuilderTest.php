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
        // 依頼CF-1: 起点URLの解決・配下判定はCrawlOriginScopeResolverへ
        // 抜き出したため、コンストラクタ経由で注入する(app()経由でコンテナに
        // 解決させる ―― CrawlOriginScopeResolver自体に依存が無いため
        // 手動でnewしても差はないが、他の新しいBuilder系テストと同じ流儀に揃える)。
        return app(AdminComparisonSiteHierarchyBuilder::class);
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

    /**
     * 依頼CD-5(2026-09-28): 起点URL直下にディレクトリを介さずファイルが
     * 直接置かれているサイト(実例: .../recruit/qa.html、
     * .../recruit/flow.html)では、従来は1ファイルごとに1件の「枝」が
     * 乱立していた(枝名がファイル名そのもの)。この依頼で、そのような
     * ページは枝に積まず、1つの集計行(flat_pages_heading)にまとめること。
     */
    public function test_flat_files_directly_under_the_origin_are_grouped_into_a_single_summary_row_instead_of_one_branch_each(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/qa.html', 'よくある質問');
        $this->crawledPage($wa, 'https://example.com/recruit/flow.html', '選考の流れ');
        $this->crawledPage($wa, 'https://example.com/recruit/privacy.html', null);

        $result = $this->builder()->build($wa);

        $this->assertCount(1, $result['branches'], 'ファイル1件ごとに枝が乱立しないこと');
        $flatEntry = $result['branches'][0];
        $this->assertSame((string) config('admin_comparison_pptx.site_hierarchy_flat_pages_heading'), $flatEntry['name']);
        $this->assertSame(3, $flatEntry['page_count']);
        // タイトルが取れているページはタイトル、取れていないページは
        // ファイル名(捏造しない)。
        $this->assertContains('よくある質問', $flatEntry['sample_pages']);
        $this->assertContains('選考の流れ', $flatEntry['sample_pages']);
        $this->assertContains('privacy.html', $flatEntry['sample_pages']);
        // 見出し自体は特定のページ名を名乗るものではないため、既存の
        // 「(ページ名未取得)」印は付けない(壁を作らないための集約行)。
        $this->assertFalse($flatEntry['name_is_url_segment']);
    }

    /**
     * 依頼CD-5必須: ディレクトリ構成のサイト(依頼CB-3以来の既存挙動)は、
     * この変更の影響を受けないこと ―― 起点直下に単独ファイルが1つも
     * 無ければ、従来どおり枝の一覧だけが返ること。
     */
    public function test_directory_structured_sites_are_unaffected_by_the_flat_page_handling(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/careers/interview/01.html', 'インタビュー01');
        $this->crawledPage($wa, 'https://example.com/recruit/culture/about.html', 'カルチャー');

        $result = $this->builder()->build($wa);

        $byName = collect($result['branches'])->keyBy('name');
        $this->assertSame(1, $byName['careers']['page_count']);
        $this->assertSame(1, $byName['culture']['page_count']);
        $this->assertCount(2, $result['branches'], '起点直下の集約行(flat_pages_heading)が余計に増えないこと');
    }

    /**
     * ディレクトリ配下のページと、起点直下の単独ファイルが混在する場合、
     * 両方とも失わずに表示されること(ディレクトリの枝+単独ページの
     * 集約行の両方が返る)。
     */
    public function test_mixed_sites_show_both_directory_branches_and_the_flat_page_summary_row(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/careers/interview/01.html', 'インタビュー01');
        $this->crawledPage($wa, 'https://example.com/recruit/privacy.html', null);

        $result = $this->builder()->build($wa);

        $names = array_column($result['branches'], 'name');
        $this->assertContains('careers', $names);
        $this->assertContains((string) config('admin_comparison_pptx.site_hierarchy_flat_pages_heading'), $names);
        $this->assertCount(2, $result['branches']);
    }

    /**
     * 依頼CB-3の既存動作: 「.」を含まない単一セグメント(ディレクトリ形式の
     * URL、例 .../recruit/culture/)は、従来どおりその枝自身のインデックス
     * ページとして扱うこと(CD-5のファイル判定〈"."を含む〉に巻き込まれて
     * 集約行に混ざらないこと)。
     */
    public function test_extensionless_single_segment_pages_still_become_their_own_branch_index_page(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/culture', 'カルチャー・社風');

        $result = $this->builder()->build($wa);

        $this->assertCount(1, $result['branches']);
        $this->assertSame('カルチャー・社風', $result['branches'][0]['name']);
        $this->assertNotSame((string) config('admin_comparison_pptx.site_hierarchy_flat_pages_heading'), $result['branches'][0]['name']);
    }

    /**
     * 依頼CF-2(2026-09-29): プライバシーポリシー等、枝の中身の判断材料に
     * ならないページは、代表ページとして選ぶ優先順位を下げること(除外は
     * しない ―― 巡回対象からは外さない、crawl_excluded_path_patternsは
     * 変更しない)。
     */
    public function test_deprioritized_pages_are_pushed_to_the_end_of_the_sample_pages(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        // privacypolicyを先に発見(id順で先)、その後に本来の代表ページに
        // なるべき2件を発見する ―― サンプル上限(既定5)を超える件数にし、
        // 優先度を付けなければprivacypolicyが先着で選ばれてしまう状況にする。
        $this->crawledPage($wa, 'https://example.com/recruit/careers/privacypolicy.html', null);
        $this->crawledPage($wa, 'https://example.com/recruit/careers/a.html', 'インタビューA');
        $this->crawledPage($wa, 'https://example.com/recruit/careers/b.html', 'インタビューB');
        $this->crawledPage($wa, 'https://example.com/recruit/careers/c.html', 'インタビューC');
        $this->crawledPage($wa, 'https://example.com/recruit/careers/d.html', 'インタビューD');
        $this->crawledPage($wa, 'https://example.com/recruit/careers/e.html', 'インタビューE');

        $result = $this->builder()->build($wa);

        $samplePages = $result['branches'][0]['sample_pages'];
        $limit = (int) config('admin_comparison_pptx.site_hierarchy_sample_pages_per_branch');
        $this->assertCount($limit, $samplePages);
        $this->assertNotContains('privacypolicy.html', $samplePages, '優先度の高いページが十分にあるとき、privacypolicyは代表ページに出ないこと');
    }

    /**
     * 依頼CF-2必須: 除外した結果その枝の代表ページが0件になるなら、
     * 外さずに出すこと(デプライオリティは除外ではない)。
     */
    public function test_deprioritized_page_still_shows_when_it_is_the_only_candidate(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/recruit/careers/privacypolicy.html', null);

        $result = $this->builder()->build($wa);

        $this->assertSame(['privacypolicy.html'], $result['branches'][0]['sample_pages']);
    }

    /**
     * 依頼CF-2(2026-09-29): 起点URL配下の「外」にあったページを、パスの
     * 第1セグメントで集計すること(参考用、実データから出す、捏造しない)。
     */
    public function test_outside_origin_breakdown_summarizes_pages_outside_the_origin_by_top_segment(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $this->crawledPage($wa, 'https://example.com/ir/report.html', null);
        $this->crawledPage($wa, 'https://example.com/ir/notice.html', null);
        $this->crawledPage($wa, 'https://example.com/news/2026-topics.html', null);
        // 起点配下(参考の対象外)。
        $this->crawledPage($wa, 'https://example.com/recruit/careers/a.html', 'A');

        $result = $this->builder()->build($wa);

        $byName = collect($result['outside_origin_breakdown'])->keyBy('name');
        $this->assertSame(2, $byName['ir']['page_count']);
        $this->assertSame(1, $byName['news']['page_count']);
        $this->assertSame(0, $result['outside_origin_other_count']);
    }

    /**
     * 上限を超えた区分は件数を合算して「ほか」にまとめること。
     */
    public function test_outside_origin_breakdown_folds_the_tail_into_other_count(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $limit = (int) config('admin_comparison_pptx.site_hierarchy_outside_breakdown_limit');
        for ($i = 0; $i < $limit + 2; $i++) {
            $this->crawledPage($wa, "https://example.com/segment{$i}/page.html", null);
        }

        $result = $this->builder()->build($wa);

        $this->assertCount($limit, $result['outside_origin_breakdown']);
        $this->assertSame(2, $result['outside_origin_other_count']);
    }

    /**
     * 起点配下にしかページが無いサイトでは、「参考」の内訳は0件
     * (空配列)であること ―― セクションごと出ない(Generator側)ことの
     * 前提。
     */
    public function test_outside_origin_breakdown_is_empty_when_everything_is_within_origin(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);
        $this->crawledPage($wa, 'https://example.com/recruit/careers/a.html', 'A');

        $result = $this->builder()->build($wa);

        $this->assertSame([], $result['outside_origin_breakdown']);
        $this->assertSame(0, $result['outside_origin_other_count']);
    }

    /**
     * 依頼CH-4(2026-10-01): インデックスページが巡回できていない(title不明)
     * 枝の名前が、パーセントエンコーディングされたパスセグメントの場合、
     * デコードして表示すること。巡回・集計ロジック(枝の件数等)は
     * 変わらないこと。
     */
    public function test_branch_name_decodes_a_percent_encoded_segment_without_an_index_title(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $encodedSegment = rawurlencode('企画・営業');
        $this->crawledPage($wa, "https://example.com/recruit/{$encodedSegment}/detail.html", null);

        $result = $this->builder()->build($wa);

        $this->assertSame('企画・営業', $result['branches'][0]['name']);
        $this->assertTrue($result['branches'][0]['name_is_url_segment']);
    }

    /**
     * 依頼CH-4必須: デコード後に不正なUTF-8になる場合は、デコードせず
     * 元のまま出すこと(文字化けを資料に出さない)。
     */
    public function test_branch_name_keeps_the_raw_segment_when_decoding_produces_invalid_utf8(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        // %ff%feは有効なUTF-8には復号されない不正なバイト列。
        $this->crawledPage($wa, 'https://example.com/recruit/%ff%fe/detail.html', null);

        $result = $this->builder()->build($wa);

        $this->assertSame('%ff%fe', $result['branches'][0]['name']);
    }

    /**
     * 依頼CH-4: 代表ページラベル(インデックスタイトルが無いページの
     * フォールバック表示名)でもデコードが効くこと。
     */
    public function test_sample_page_label_decodes_a_percent_encoded_segment(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $encodedSegment = rawurlencode('社員紹介');
        $this->crawledPage($wa, "https://example.com/recruit/careers/{$encodedSegment}.html", null);

        $result = $this->builder()->build($wa);

        $this->assertSame(['社員紹介.html'], $result['branches'][0]['sample_pages']);
    }

    /**
     * 依頼CH-4: 「参考」内訳(起点URL配下の外)の表示名でもデコードが
     * 効くこと。
     */
    public function test_outside_origin_breakdown_name_decodes_a_percent_encoded_segment(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create(['website_analysis_id' => $wa->id, 'page_type' => PageType::Recruit, 'url' => 'https://example.com/recruit/']);

        $encodedSegment = rawurlencode('広報');
        $this->crawledPage($wa, "https://example.com/{$encodedSegment}/release.html", null);

        $result = $this->builder()->build($wa);

        $this->assertSame('広報', $result['outside_origin_breakdown'][0]['name']);
    }
}
