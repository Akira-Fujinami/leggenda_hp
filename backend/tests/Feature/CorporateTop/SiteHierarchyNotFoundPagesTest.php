<?php

namespace Tests\Feature\CorporateTop;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼DA-3(AIなし・通信しない): 存在しないページ(404・410、ページ名・最初の見出しが「見つかりません」系)は、階層図に出さない。
 * 巡回した件数・判定の入力(保存済みのページ)は変えない。本文はすべて人工のもの。
 */
class SiteHierarchyNotFoundPagesTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const ORIGIN = 'https://self.example.com/recruit/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 0]);
    }

    private function company(): \App\Models\WebsiteAnalysis
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '新卒採用', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/a', '社員紹介', $this->htmlPage('x', '本文'));

        return $wa;
    }

    /** @return list<string> */
    private function paths(\App\Models\WebsiteAnalysis $wa): array
    {
        return array_column(app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa)['branches'], 'path');
    }

    public function test_pages_with_status_404_or_410_are_left_out_and_an_emptied_branch_is_left_out_with_it(): void
    {
        $wa = $this->company();
        $gone = $this->addCrawledPage($wa, self::ORIGIN.'gone/', '通常のページ名', $this->htmlPage('x', '本文'));
        $missing = $this->addCrawledPage($wa, self::ORIGIN.'missing/', '別のページ名', $this->htmlPage('x', '本文'));
        $this->assertContains('/recruit/gone', $this->paths($wa), '状態が正常なうちは枝がある');

        $gone->update(['http_status' => 410]);
        $missing->update(['http_status' => 404]);

        $paths = $this->paths($wa);
        $this->assertNotContains('/recruit/gone', $paths, '410のページだけの枝は、枝ごと除く');
        $this->assertNotContains('/recruit/missing', $paths, '404のページだけの枝は、枝ごと除く');
        $this->assertContains('/recruit/fresh', $paths, '他の枝は残る');
    }

    public function test_a_page_whose_name_or_first_heading_says_not_found_is_left_out(): void
    {
        $wa = $this->company();
        $this->addCrawledPage($wa, self::ORIGIN.'a/', '申し訳ございませんお探しのページが見つかりませんでした', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'b/', 'Page Not Found | 例社', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'c/', '404', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'d/', '採用のご案内', '<html><head><title>採用のご案内</title></head><body><main><h1>お探しのページが見つかりません</h1></main></body></html>');
        $this->addCrawledPage($wa, self::ORIGIN.'e/', '採用のご案内', '<html><head><title>採用のご案内</title></head><body><main><h2>見つかりません</h2><h1>正常な見出し</h1></main></body></html>');

        $paths = $this->paths($wa);

        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->assertNotContains('/recruit/'.$slug, $paths, $slug);
        }
        $this->assertContains('/recruit/e', $paths, '最初の見出し(h1)だけを見る。h2は見ない');
        $this->assertContains('/recruit/fresh', $paths);
    }

    public function test_a_not_found_page_inside_a_branch_leaves_the_other_pages_of_the_branch(): void
    {
        $wa = $this->company();
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/oops', 'ページが見つかりません', $this->htmlPage('x', '本文'));

        $branch = array_column(app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa)['branches'], null, 'path')['/recruit/fresh'];
        config(['admin_comparison_pptx.site_hierarchy_tree_not_found_words' => []]);
        $before = array_column(app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa)['branches'], null, 'path')['/recruit/fresh'];

        $this->assertNotContains('ページが見つかりません', $branch['pages']);
        $this->assertSame($before['page_count'] - 1, $branch['page_count'], '同じ枝の他のページは残り、除いた1ページだけ減る');
        $this->assertGreaterThan(0, $branch['page_count']);
    }

    public function test_the_headline_count_and_the_stored_pages_are_unchanged(): void
    {
        $wa = $this->company();
        $this->addCrawledPage($wa, self::ORIGIN.'oops/', 'ページが見つかりません', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'gone/', '通常のページ名', $this->htmlPage('x', '本文'))->update(['http_status' => 404]);
        $rowsBefore = AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->count();
        $titlesBefore = AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->orderBy('id')->pluck('title')->all();

        $withFilter = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);
        config(['admin_comparison_pptx.site_hierarchy_tree_not_found_words' => [], 'admin_comparison_pptx.site_hierarchy_tree_not_found_statuses' => []]);
        $without = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);

        $this->assertSame($without['total_fetched_pages'], $withFilter['total_fetched_pages'], '冒頭の「巡回したN件」は変えない');
        $this->assertSame($without['pages_within_origin'], $withFilter['pages_within_origin'], '「うちN件」も変えない');
        $this->assertSame($without['outside_origin_count'], $withFilter['outside_origin_count']);
        $this->assertNotSame(count($without['branches']), count($withFilter['branches']), '図の枝だけが減る');
        $this->assertSame($rowsBefore, AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->count(), '保存済みのページ(判定の入力)は消さない');
        $this->assertSame($titlesBefore, AnalysisCrawledPage::query()->where('website_analysis_id', $wa->id)->orderBy('id')->pluck('title')->all(), '書き換えない');
    }

    public function test_the_removed_count_is_logged_without_any_page_text(): void
    {
        $wa = $this->company();
        $this->addCrawledPage($wa, self::ORIGIN.'oops/', 'ページが見つかりません', $this->htmlPage('x', '本文'));
        $logged = [];
        \Illuminate\Support\Facades\Log::shouldReceive('info')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [$message, $context];
        });

        app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);

        $notFound = array_values(array_filter($logged, fn (array $row) => str_contains((string) $row[0], 'not found')));
        $this->assertCount(1, $notFound);
        $this->assertSame(['count' => 1], $notFound[0][1], '件数だけ。ページ名・本文は出さない');
    }
}
