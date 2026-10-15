<?php

namespace Tests\Feature\CorporateTop;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalyzerClient;
use App\Services\CorporateTop\CorporateTopAnalyzer;
use App\Services\CorporateTop\CorporateTopStore;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use App\Services\Report\PageKindClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CY(AIなし・通信しない):
 *  CY-1 コーポレートの他メニュー(静的 → 描画済み → class/idの語 の順・除外・上限)
 *  CY-2 第3階層を「情報の種類」で示す(ページ名の言葉 / 判定の根拠の文)
 *  CY-3 求職者向けでない枝(プライバシー等)を図から外す
 */
class CorporateMenuAndPageKindsTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const ORIGIN = 'https://self.example.com/recruit/';

    private const SCOPE = ['origin_url' => 'https://self.example.com/recruit/', 'host' => 'self.example.com', 'path' => '/recruit/'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 0]);
    }

    private function analyzer(): CorporateTopAnalyzer
    {
        return app(CorporateTopAnalyzer::class);
    }

    private function a(string $href, string $text): string
    {
        return '<a href="'.$href.'">'.$text.'</a>';
    }

    private function menu(?string $static, ?string $rendered = null): array
    {
        return $this->analyzer()->otherMenu((string) $static, $rendered, 'https://www.example.com/', self::SCOPE, '採用情報');
    }

    // ---------------------------------------------------------------- CY-1

    public function test_the_static_header_nav_is_used_first_and_the_later_steps_are_not_tried(): void
    {
        $static = '<html><body><header><nav>'.$this->a('/about/', '会社情報').$this->a('/service/', 'サービス').'</nav></header></body></html>';
        $rendered = '<html><body><header><nav>'.$this->a('/other/', '描画後だけ').'</nav></header></body></html>';

        $result = $this->menu($static, $rendered);

        $this->assertSame('static_nav', $result['source']);
        $this->assertSame(['会社情報', 'サービス'], $result['labels']);
    }

    public function test_the_rendered_html_is_used_when_the_static_html_has_no_menu(): void
    {
        $static = '<html><body><main>本文</main></body></html>';
        $rendered = '<html><body><header><nav>'.$this->a('/about/', '会社情報').$this->a('/ir/', 'IR情報').'</nav></header></body></html>';

        $result = $this->menu($static, $rendered);

        $this->assertSame('rendered_nav', $result['source']);
        $this->assertSame(['会社情報', 'IR情報'], $result['labels']);
    }

    public function test_the_class_words_are_used_last_and_links_inside_main_and_footer_are_not_taken(): void
    {
        $html = '<html><body><div class="gnav"><ul><li>'.$this->a('/about/', '会社情報').'</li><li>'.$this->a('/news/', 'ニュース').'</li></ul></div>'
            .'<main><div class="menu">'.$this->a('/in-main/', '本文の中').'</div></main>'
            .'<footer><div class="menu">'.$this->a('/in-footer/', 'フッター').'</div></footer></body></html>';

        $result = $this->menu($html);

        $this->assertSame('class_words', $result['source']);
        $this->assertSame(['会社情報', 'ニュース'], $result['labels']);
    }

    public function test_sub_menu_items_nested_in_a_list_are_not_taken_as_first_level(): void
    {
        $html = '<html><body><div class="gnav"><ul><li>'.$this->a('/about/', '会社情報').'<ul><li>'.$this->a('/about/history/', '沿革').'</li></ul></li></ul></div></body></html>';

        $this->assertSame(['会社情報'], $this->menu($html)['labels']);
    }

    public function test_recruit_links_the_recruit_label_the_logo_other_hosts_and_non_section_items_are_excluded_with_reasons(): void
    {
        $html = '<html><body><header><nav>'
            .$this->a('https://self.example.com/recruit/fresh/', '新卒')                       // 採用サイトの配下
            .$this->a('/jobs/', '採用情報')                            // 採用の箱と同じ名前
            .$this->a('/', 'ホーム')                                   // サイトのTOP自身(ロゴ)
            .$this->a('https://other.example.net/x/', '外部')          // 他のホスト
            .$this->a('/contact/', 'お問い合わせ')                      // 情報の区分ではない
            .$this->a('/about/', '会社情報')
            .'</nav></header></body></html>';

        $result = $this->menu($html);

        $this->assertSame(['会社情報'], $result['labels']);
        $reasons = $result['excluded']['static_nav'];
        foreach (['recruit_scope', 'recruit_label', 'site_top', 'other_host', 'not_a_section'] as $reason) {
            $this->assertSame(1, $reasons[$reason] ?? 0, $reason);
        }
    }

    public function test_when_no_step_gives_a_menu_the_source_is_null(): void
    {
        $result = $this->menu('<html><body><main>メニューなし</main></body></html>');

        $this->assertNull($result['source']);
        $this->assertSame([], $result['labels']);
    }

    public function test_more_menu_items_than_the_limit_fold_into_the_count_and_recruit_comes_first(): void
    {
        config(['admin_comparison_pptx.corporate_top_other_menu_limit' => 3]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '新卒', $this->htmlPage('x', '新卒です。'));
        $items = implode('', array_map(fn (string $n) => $this->a('/m/'.md5($n).'/', $n), ['A社情報', 'Bサービス', 'Cニュース', 'D理念', 'E沿革']));
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, [
            'status' => 'found', 'url' => 'https://www.example.com/', 'recruit_label' => '採用情報', 'recruit_url' => 'https://www.example.com/recruit/',
        ], '<html><body><header><nav>'.$items.$this->a('/recruit/', '採用情報').'</nav></header></body></html>');

        $tree = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));

        $this->assertSame(['A社情報', 'Bサービス', 'Cニュース'], $tree['corporate']['other_menu'], 'メニューの順');
        $this->assertSame(2, $tree['corporate']['other_menu_more']);
        $this->assertStringContainsString('ほか2', $xml);
        $this->assertLessThan(strpos($xml, 'A社情報'), strpos($xml, '採用情報'), '採用情報の箱が先');
    }

    public function test_nothing_obtained_draws_the_recruit_box_only_without_a_note(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '新卒', $this->htmlPage('x', '新卒です。'));
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, [
            'status' => 'found', 'url' => 'https://www.example.com/', 'recruit_label' => '採用情報', 'recruit_url' => 'https://www.example.com/recruit/',
        ], '<html><body><main>メニューなし</main></body></html>');

        $tree = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);

        $this->assertSame([], $tree['corporate']['other_menu']);
        $this->assertSame(0, $tree['corporate']['other_menu_more']);
        $this->assertFalse($tree['corporate_missing']);
    }

    public function test_the_corporate_top_fetch_also_stores_the_rendered_html_and_a_render_failure_is_swallowed(): void
    {
        $paths = app(\App\Services\Analysis\AnalysisStoragePaths::class);
        $this->assertStringEndsWith('corporate_top.rendered.html', $paths->corporateTopRenderedHtmlPath(1, 2));

        $wa = $this->makeComparisonWebsiteAnalysis();
        $store = app(CorporateTopStore::class);
        $store->write($wa->analysis_id, $wa->id, ['status' => 'found', 'url' => 'https://www.example.com/'], '<html>static</html>', '<html>rendered</html>');

        $this->assertSame('<html>rendered</html>', $store->readRenderedHtml($wa->analysis_id, $wa->id));
        $this->assertSame('<html>static</html>', $store->readHtml($wa->analysis_id, $wa->id));

        $store->write($wa->analysis_id, $wa->id, ['status' => 'found', 'url' => 'https://www.example.com/'], '<html>static</html>');
        $this->assertNull($store->readRenderedHtml($wa->analysis_id, $wa->id), '描画済みHTMLが無ければnull(静的HTMLだけで続ける)');
    }

    // ---------------------------------------------------------------- CY-2

    private function classifier(): PageKindClassifier
    {
        return app(PageKindClassifier::class);
    }

    private function record(array $subElements): BrandWheelAnalysisResult
    {
        return (new BrandWheelAnalysisResult)->forceFill([
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => $subElements]],
        ]);
    }

    public function test_a_page_name_word_gives_the_kind_and_a_page_without_any_word_has_no_kind(): void
    {
        $this->assertSame(['vision_philosophy' => 'title'], $this->classifier()->classify('私たちの企業理念', null, []));
        $this->assertSame(['employee_interview' => 'title'], $this->classifier()->classify('社員インタビュー', null, []));
        $this->assertSame([], $this->classifier()->classify('3分でわかる会社', null, []));
    }

    public function test_the_first_h1_is_also_read_as_the_page_name(): void
    {
        $kinds = $this->classifier()->classify('特集', '<html><body><h1>ビジョンについて</h1></body></html>', []);

        $this->assertSame(['vision_philosophy' => 'title'], $kinds);
    }

    public function test_top_message_pages_are_executive_not_employee_interview(): void
    {
        $kinds = $this->classifier()->classify('エンジニアトップインタビュー', null, []);

        $this->assertArrayNotHasKey('employee_interview', $kinds);
        $this->assertSame(['executive_interview' => 'title'], $this->classifier()->classify('エンジニアトップメッセージ', null, []));
    }

    public function test_an_evidence_text_found_verbatim_in_the_body_gives_the_survey_kind_ignoring_whitespace_and_width(): void
    {
        $evidence = $this->classifier()->evidenceFor($this->record([['key' => 'purpose', 'evidence' => 'チームワークあふれる社会を創るという理念を実現する']]));
        $html = '<html><body><p>チーム ワークあふれる社会を創るという理念を実現する。</p></body></html>';

        $this->assertSame(['vision_philosophy' => 'evidence'], $this->classifier()->classify('特集', $html, $evidence));
        $this->assertSame([], $this->classifier()->classify('特集', '<html><body>無関係な本文です</body></html>', $evidence));
    }

    public function test_title_and_evidence_for_the_same_kind_are_one_kind_marked_both(): void
    {
        $evidence = $this->classifier()->evidenceFor($this->record([['key' => 'purpose', 'evidence' => 'チームワークあふれる社会を創るという理念を実現する']]));
        $html = '<html><body><p>チームワークあふれる社会を創るという理念を実現する</p></body></html>';

        $this->assertSame(['vision_philosophy' => 'both'], $this->classifier()->classify('企業理念', $html, $evidence));
    }

    public function test_a_short_evidence_text_is_not_used(): void
    {
        $this->assertSame([], $this->classifier()->evidenceFor($this->record([['key' => 'purpose', 'evidence' => '短い文']])));
    }

    public function test_lines_are_ordered_by_the_survey_percentage_with_counts_and_the_rest_is_folded(): void
    {
        config(['admin_comparison_pptx.page_kind_line_template' => '%s（%d）']);
        $options = app(\App\Services\Report\CandidateSurveyCatalog::class)->options();
        [$first, $second, $third] = [$options[0]['key'], $options[1]['key'], $options[2]['key']];

        $result = $this->classifier()->lines([
            ['kinds' => [$third => 'title']],
            ['kinds' => [$second => 'title', $third => 'evidence']],
            ['kinds' => [$first => 'title']],
            ['kinds' => []],
            ['kinds' => []],
        ], 2);

        $this->assertTrue($result['classified']);
        $this->assertSame([
            $this->classifier()->shortName($first).'（1）',
            $this->classifier()->shortName($second).'（1）',
        ], $result['lines'], '調査の割合が高い順、行数の上限まで');
        $this->assertSame(3, $result['rest'], '出さなかった種類だけのページ + その他のページ');
    }

    public function test_a_branch_where_nothing_is_classified_keeps_showing_the_page_names(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'misc/', 'いろいろ', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'misc/a', '3分でわかる会社', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '新卒', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/a', '社員インタビュー', $this->htmlPage('x', '本文'));

        $tree = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);
        $branches = array_column($tree['branches'], null, 'path');

        $this->assertContains('3分でわかる会社', $branches['/recruit/misc']['pages'], 'すべて「その他」の枝はページ名を出す');
        $this->assertSame(['社員インタビュー（1）'], array_slice($branches['/recruit/fresh']['pages'], 0, 1));
    }

    public function test_the_third_column_heading_is_the_configured_text(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '新卒', $this->htmlPage('x', '本文'));
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, [
            'status' => 'found', 'url' => 'https://www.example.com/', 'recruit_label' => '採用情報', 'recruit_url' => 'https://www.example.com/recruit/',
        ], '<html><body><header><nav>'.$this->a('/about/', '会社情報').$this->a('/recruit/', '採用情報').'</nav></header></body></html>');

        $tree = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));

        $this->assertStringContainsString((string) config('admin_comparison_pptx.site_hierarchy_columns_corporate')[3]['description'], $xml);
    }

    // ---------------------------------------------------------------- CY-3

    public function test_branches_for_privacy_cookie_and_terms_are_dropped_and_the_top_count_is_unchanged(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '新卒', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/a', '社員紹介', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'privacy/', 'プライバシーポリシー', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'cookie-policy/', 'Cookieについて', $this->htmlPage('x', '本文'));
        $this->addCrawledPage($wa, self::ORIGIN.'terms/', '利用規約', $this->htmlPage('x', '本文'));

        $withFilter = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);
        $paths = array_column($withFilter['branches'], 'path');

        $this->assertContains('/recruit/fresh', $paths);
        foreach (['/recruit/privacy', '/recruit/cookie-policy', '/recruit/terms'] as $excluded) {
            $this->assertNotContains($excluded, $paths);
        }
        $this->assertSame(0, $withFilter['other_branch_count'] ?? 0, '除いた枝は「ほかN」に数えない');

        config(['admin_comparison_pptx.site_hierarchy_excluded_branch_words' => []]);
        $without = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);

        $this->assertSame($without['total_fetched_pages'], $withFilter['total_fetched_pages'], '巡回した件数は変わらない');
        $this->assertContains('/recruit/privacy', array_column($without['branches'], 'path'));
    }

    // ---------------------------------------------------------------- 通信しない

    public function test_the_slide_is_built_without_any_network_or_ai_call(): void
    {
        Http::fake();
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/a', '社員インタビュー', $this->htmlPage('x', '本文'));
        $this->mock(AnalyzerClient::class)->shouldNotReceive('render');

        $tree = app(AdminComparisonSiteHierarchyBuilder::class)->buildTree($wa);
        app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree);

        Http::assertNothingSent();
        $this->assertInstanceOf(Analysis::class, Analysis::query()->find($wa->analysis_id));
    }

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cy');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }
}
