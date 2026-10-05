<?php

namespace Tests\Feature\Report;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\CrawlOriginScopeResolver;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 依頼CL-3(2026-10-05): 階層図を「TOP → 第1階層(TOPのメニュー) → 第2階層(その先の
 * ページ)」の木にする。AIは使わず、保存済みHTMLと巡回済みページ(status=fetched)から
 * 決まった手順で組み立てる(同じ入力から同じ結果)。スキーマは変更しない。
 */
class AdminComparisonSiteHierarchyTreeTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://example.com/recruit/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        // 依頼CM-2の「広げて描く」は専用のテストで確かめる(ここでは基準を0にして無効にする)。
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 0]);
    }

    private function builder(): AdminComparisonSiteHierarchyBuilder
    {
        return app(AdminComparisonSiteHierarchyBuilder::class);
    }

    private function html(string $menu, string $main = '', string $footer = ''): string
    {
        return '<html><head><title>採用情報</title></head><body>'
            .'<header><nav>'.$menu.'</nav></header>'
            .'<main>'.$main.'</main>'
            .'<footer>'.$footer.'</footer>'
            .'</body></html>';
    }

    private function recruitPage(WebsiteAnalysis $wa, ?string $rawHtml, ?string $renderedHtml = null, string $url = self::ORIGIN, ?string $title = '採用情報トップ'): AnalysisPage
    {
        $raw = null;
        $rendered = null;
        if ($rawHtml !== null) {
            $raw = "pages/{$wa->id}/recruit-raw.html";
            Storage::disk('analysis')->put($raw, $rawHtml);
        }
        if ($renderedHtml !== null) {
            $rendered = "pages/{$wa->id}/recruit-rendered.html";
            Storage::disk('analysis')->put($rendered, $renderedHtml);
        }

        return AnalysisPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'page_type' => PageType::Recruit,
            'url' => $url,
            'final_url' => $url,
            'title' => $title,
            'raw_html_path' => $raw,
            'rendered_html_path' => $rendered,
        ]);
    }

    private function crawled(WebsiteAnalysis $wa, string $url, ?string $title = null, ?string $html = null, string $status = AnalysisCrawledPage::STATUS_FETCHED): AnalysisCrawledPage
    {
        $path = null;
        if ($html !== null) {
            $path = 'pages/'.$wa->id.'/'.md5($url).'.html';
            Storage::disk('analysis')->put($path, $html);
        }

        return AnalysisCrawledPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'url' => $url,
            'final_url' => $url,
            'title' => $title,
            'status' => $status,
            'raw_html_path' => $path,
        ]);
    }

    /** @return array<string, mixed> */
    private function standardSite(): array
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html(
            '<a href="/recruit/culture/">カルチャー</a><a href="/recruit/jobs/">募集職種</a>',
            '<h1>私たちの採用</h1><h2>仕事</h2><h2>文化</h2>',
            '<a href="/recruit/privacy/">プライバシーポリシー</a>',
        ));

        // カルチャー: インデックスのHTMLが本文で2ページをリンクしている(ヘッダー/フッターのリンクは除かれる)。
        $this->crawled($wa, 'https://example.com/recruit/culture/', 'カルチャー | 採用', $this->html(
            '<a href="/recruit/jobs/">募集職種</a>',
            '<a href="/recruit/culture/a">Aの記事</a><a href="/recruit/culture/b">Bの記事</a><a href="/recruit/jobs/">募集職種(本文内)</a>',
            '<a href="/recruit/privacy/">プライバシーポリシー</a>',
        ));
        $this->crawled($wa, 'https://example.com/recruit/culture/a', 'Aの記事');
        $this->crawled($wa, 'https://example.com/recruit/culture/b', 'Bの記事');
        // 募集職種: インデックスのHTMLが無い(読み直せない) → URLの配下で代用。
        $this->crawled($wa, 'https://example.com/recruit/jobs/', '募集職種 | 採用');
        $this->crawled($wa, 'https://example.com/recruit/jobs/engineer', 'エンジニア');
        // どの項目にも属さないページ。
        $this->crawled($wa, 'https://example.com/recruit/privacy/', 'プライバシーポリシー');

        return ['wa' => $wa];
    }

    public function test_menu_items_become_first_level_and_the_pages_linked_from_each_item_become_second_level(): void
    {
        $wa = $this->standardSite()['wa'];

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('menu', $tree['mode']);
        $this->assertSame(self::ORIGIN, $tree['origin_url']);
        $this->assertSame(['カルチャー', '募集職種'], array_column($tree['branches'], 'name'), 'メニューに書かれている文字が名前になる');

        [$culture, $jobs] = $tree['branches'];
        $this->assertSame('https://example.com/recruit/culture/', $culture['url']);
        $this->assertSame(3, $culture['page_count'], 'その項目のページ＋リンクされたページ');
        $this->assertSame(['Aの記事', 'Bの記事'], $culture['pages'], '実際のリンクの出現順。共通のメニュー・フッター・他の項目へのリンクは除く');
        $this->assertSame(0, $culture['other_page_count']);

        $this->assertSame(2, $jobs['page_count']);
        $this->assertSame(['エンジニア'], $jobs['pages'], 'HTMLが無い項目はURLの配下で代用');

        // 依頼CN-A2: 置き方の内訳(ページ数)。どちらのページもURLが枝の配下にある。
        $this->assertSame(['links' => 0, 'url' => 3], $tree['second_level_source']);
    }

    public function test_top_has_the_url_page_title_main_headings_and_menu_item_count(): void
    {
        $tree = $this->builder()->buildTree($this->standardSite()['wa']);

        $this->assertSame([
            'url' => self::ORIGIN,
            'title' => '採用情報トップ',
            'headings' => ['私たちの採用', '仕事', '文化'],
            'menu_item_count' => 2,
        ], $tree['top']);
    }

    public function test_footer_links_are_not_first_level_and_unlinked_pages_are_not_forced_into_a_branch(): void
    {
        $tree = $this->builder()->buildTree($this->standardSite()['wa']);

        $names = array_column($tree['branches'], 'name');
        $this->assertNotContains('プライバシーポリシー', $names);
        foreach ($tree['branches'] as $branch) {
            $this->assertNotContains('プライバシーポリシー', $branch['pages']);
        }
    }

    public function test_scope_counts_total_fetched_and_pages_within_the_origin(): void
    {
        $wa = $this->standardSite()['wa'];
        $this->crawled($wa, 'https://example.com/ir/news', 'IRニュース');
        $this->crawled($wa, 'https://other.example.org/recruit/', '別ホスト');
        $this->crawled($wa, 'https://example.com/recruit/failed', '失敗', null, AnalysisCrawledPage::STATUS_FAILED);

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(8, $tree['total_fetched_pages'], '取得できたページだけ(失敗は数えない)');
        $this->assertSame(6, $tree['pages_within_origin']);
        $this->assertSame(2, $tree['outside_origin_count']);
    }

    public function test_pages_that_were_not_fetched_never_appear_in_the_tree(): void
    {
        $wa = $this->standardSite()['wa'];
        $this->crawled($wa, 'https://example.com/recruit/culture/ghost', '取得失敗のページ', null, AnalysisCrawledPage::STATUS_FAILED);
        $this->crawled($wa, 'https://example.com/recruit/culture/pending', '未取得のページ', null, AnalysisCrawledPage::STATUS_PENDING);

        $tree = $this->builder()->buildTree($wa);

        $all = collect($tree['branches'])->flatMap(fn (array $b) => $b['pages'])->all();
        $this->assertNotContains('取得失敗のページ', $all);
        $this->assertNotContains('未取得のページ', $all);
        $this->assertSame(3, $tree['branches'][0]['page_count']);
    }

    public function test_rendered_html_is_preferred_when_the_menu_can_be_read_from_it(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage(
            $wa,
            $this->html('<a href="/recruit/static-only/">静的のメニュー1</a><a href="/recruit/static-only2/">静的のメニュー2</a>'),
            $this->html('<a href="/recruit/culture/">描画後のメニュー1</a><a href="/recruit/jobs/">描画後のメニュー2</a>'),
        );

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('rendered', $tree['menu_source']);
        $this->assertSame(['描画後のメニュー1', '描画後のメニュー2'], array_column($tree['branches'], 'name'));
    }

    public function test_static_html_is_used_when_the_rendered_html_has_fewer_than_the_minimum_menu_items(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage(
            $wa,
            $this->html('<a href="/recruit/culture/">静的のメニュー1</a><a href="/recruit/jobs/">静的のメニュー2</a><a href="/recruit/faq/">静的のメニュー3</a>'),
            $this->html('<a href="/recruit/culture/">描画後のメニュー1</a>'),
        );

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('raw', $tree['menu_source']);
        $this->assertSame('menu', $tree['mode']);
        $this->assertSame(3, $tree['top']['menu_item_count']);
    }

    public function test_falls_back_to_the_url_hierarchy_in_the_same_tree_shape_when_fewer_than_two_menu_items_are_found(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/culture/">カルチャー</a>'));
        $this->crawled($wa, 'https://example.com/recruit/culture/', '社風を知る');
        $this->crawled($wa, 'https://example.com/recruit/culture/vision', '私たちのビジョン');
        $this->crawled($wa, 'https://example.com/recruit/culture/value', null);
        $this->crawled($wa, 'https://example.com/recruit/jobs/engineer', 'エンジニア');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('url', $tree['mode']);
        $this->assertSame(1, $tree['top']['menu_item_count'], '読み取れた件数は報告する');
        $byName = collect($tree['branches'])->keyBy('name');
        $this->assertTrue($byName->has('社風を知る'), '枝の名前にはページのtitleを優先して使う');
        $this->assertSame(3, $byName['社風を知る']['page_count']);
        $this->assertSame(['私たちのビジョン', 'value'], $byName['社風を知る']['pages'], 'titleが無いページはURLをデコードしたもの');
        $this->assertTrue($byName->has('jobs'), 'インデックスが無い枝はURLのセグメント(デコード済み)');
        foreach ($tree['branches'] as $branch) {
            $this->assertNull($branch['url']);
            $this->assertStringNotContainsString('ページ名未取得', $branch['name']);
        }
    }

    public function test_falls_back_when_the_top_html_is_missing_or_unreadable_without_throwing(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        AnalysisPage::factory()->create([
            'website_analysis_id' => $wa->id,
            'page_type' => PageType::Recruit,
            'url' => self::ORIGIN,
            'final_url' => self::ORIGIN,
            'raw_html_path' => 'pages/does-not-exist.html',
            'rendered_html_path' => 'pages/also-missing.html',
        ]);
        $this->crawled($wa, 'https://example.com/recruit/culture/a', 'Aの記事');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('url', $tree['mode']);
        $this->assertNull($tree['menu_source']);
        $this->assertSame(0, $tree['top']['menu_item_count']);
        $this->assertSame([], $tree['top']['headings']);
        $this->assertCount(1, $tree['branches']);
    }

    public function test_an_empty_tree_is_returned_when_there_is_nothing_to_draw(): void
    {
        $wa = WebsiteAnalysis::factory()->create();

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame([], $tree['branches']);
        $this->assertSame(0, $tree['other_branch_count']);
        $this->assertSame($this->builder()->emptyTree()['branches'], $tree['branches']);
        $this->assertSame('url', $this->builder()->emptyTree()['mode']);
    }

    public function test_menu_items_outside_the_origin_and_links_to_the_origin_itself_are_not_first_level(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html(
            '<a href="/recruit/">ホーム</a>'                     // 起点自身
            .'<a href="/recruit/index.html">採用トップ</a>'      // 起点自身(index.html)
            .'<a href="https://www.other.example.org/x/">外部サイト</a>'
            .'<a href="/ir/">IR情報</a>'                         // 同じホストだが起点URLの配下ではない
            .'<a href="/recruit/culture/">カルチャー</a>'
            .'<a href="/recruit/jobs/">募集職種</a>',
        ));

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['カルチャー', '募集職種'], array_column($tree['branches'], 'name'));
        $this->assertSame(2, $tree['top']['menu_item_count']);
    }

    public function test_a_menu_item_below_another_menu_item_is_not_first_level(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html(
            '<a href="/recruit/environment/">職場環境</a>'
            .'<a href="/recruit/environment/benefit/">福利厚生</a>'   // ドロップダウンの子(職場環境の配下)
            .'<a href="/recruit/jobs/">募集職種</a>',
        ));
        $this->crawled($wa, 'https://example.com/recruit/environment/', '職場環境');
        $this->crawled($wa, 'https://example.com/recruit/environment/benefit/', '福利厚生のページ');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['職場環境', '募集職種'], array_column($tree['branches'], 'name'));
        $this->assertSame(['福利厚生のページ'], $tree['branches'][0]['pages'], '子の項目のページは親の項目の枝に含まれる');
    }

    public function test_icon_font_text_in_menu_labels_is_removed_and_duplicate_urls_are_collapsed(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html(
            '<a href="/recruit/mvvc">keyboard_arrow_rightカルチャー</a>'
            .'<a href="/recruit/mvvc/">ミッション</a>'                  // 同じURL → 先に出たものだけ
            .'<a href="/recruit/service">サービス</a>',
        ));

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['カルチャー', 'サービス'], array_column($tree['branches'], 'name'));
    }

    public function test_first_level_limit_keeps_the_pages_richest_items_in_menu_order_and_counts_the_rest(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_first_level_limit' => 3]);

        $wa = WebsiteAnalysis::factory()->create();
        $menu = '';
        foreach (['a', 'b', 'c', 'd', 'e'] as $dir) {
            $menu .= "<a href=\"/recruit/{$dir}/\">項目{$dir}</a>";
        }
        $this->recruitPage($wa, $this->html($menu));
        // ページ数: a=1, b=4, c=2, d=4, e=1  → 上位3(同数はメニュー順): b, d, c → 表示はメニュー順 b, c, d
        $counts = ['a' => 1, 'b' => 4, 'c' => 2, 'd' => 4, 'e' => 1];
        foreach ($counts as $dir => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $this->crawled($wa, "https://example.com/recruit/{$dir}/p{$i}", "{$dir}-{$i}");
            }
        }

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['項目b', '項目c', '項目d'], array_column($tree['branches'], 'name'));
        $this->assertSame(2, $tree['other_branch_count']);
    }

    public function test_second_level_limit_folds_the_rest_into_other_page_count(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_second_level_limit' => 2]);

        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/culture/">カルチャー</a><a href="/recruit/jobs/">募集職種</a>'));
        for ($i = 1; $i <= 5; $i++) {
            $this->crawled($wa, "https://example.com/recruit/culture/p{$i}", "記事{$i}");
        }

        $tree = $this->builder()->buildTree($wa);

        $culture = $tree['branches'][0];
        $this->assertSame(['記事1', '記事2'], $culture['pages']);
        $this->assertSame(3, $culture['other_page_count']);
        $this->assertSame(5, $culture['page_count']);
        $this->assertSame(0, $tree['branches'][1]['page_count'], '第2階層が0件の枝も出る(0ページ)');
        $this->assertSame([], $tree['branches'][1]['pages']);
    }

    /** 依頼CN-A2: 2つの枝からリンクされたページはどの枝にも置かない(TOP・項目のページ自身も置かない)。 */
    public function test_a_page_linked_from_two_branches_is_not_placed_and_the_top_and_menu_items_are_never_placed(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/a/">項目A</a><a href="/recruit/b/">項目B</a>'));
        $shared = '<a href="/recruit/shared">共有ページ</a><a href="/recruit/b/">項目B(本文内)</a><a href="/recruit/">TOPへ戻る</a>';
        $this->crawled($wa, 'https://example.com/recruit/a/', '項目Aのページ', $this->html('', $shared));
        $this->crawled($wa, 'https://example.com/recruit/b/', '項目Bのページ', $this->html('', $shared));
        $this->crawled($wa, 'https://example.com/recruit/shared', '共有ページ');
        $this->crawled($wa, 'https://example.com/recruit/', 'TOP');

        $tree = $this->builder()->buildTree($wa);

        [$a, $b] = $tree['branches'];
        $this->assertSame([], $a['pages']);
        $this->assertSame([], $b['pages']);
        $this->assertSame(1, $b['page_count']);
        $this->assertSame(1, $tree['unplaced_page_count']);
    }

    public function test_pages_without_a_title_show_the_decoded_url_path(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/culture/">カルチャー</a><a href="/recruit/jobs/">募集職種</a>'));
        $this->crawled($wa, 'https://example.com/recruit/culture/'.rawurlencode('社風'), null);

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['/recruit/culture/社風'], $tree['branches'][0]['pages']);
    }

    public function test_the_same_input_always_gives_the_same_tree(): void
    {
        $wa = $this->standardSite()['wa'];

        $this->assertSame($this->builder()->buildTree($wa), $this->builder()->buildTree($wa));
    }

    public function test_the_origin_scope_is_decided_by_the_shared_resolver_so_a_file_style_origin_scopes_to_its_directory(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/ssc/recruit/information.html">募集要項</a><a href="/ssc/recruit/flow.html">採用までの流れ</a><a href="/ssc/guide/contact.html">お問い合わせ</a>'), null, 'https://example.com/ssc/recruit/index.html');
        $this->crawled($wa, 'https://example.com/ssc/recruit/information.html', '募集要項のページ');
        $this->crawled($wa, 'https://example.com/ssc/recruit/flow.html', '採用までの流れのページ');
        $this->crawled($wa, 'https://example.com/ssc/guide/contact.html', 'お問い合わせのページ');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('menu', $tree['mode']);
        $this->assertSame(['募集要項', '採用までの流れ'], array_column($tree['branches'], 'name'), '起点(.../ssc/recruit/)の配下だけ');
        $this->assertSame(2, $tree['pages_within_origin']);
        $this->assertSame(1, $tree['outside_origin_count']);
    }

    // ------------------------------------------------------------------
    // 依頼CM-2(2026-10-06): 起点の配下にページが少ないときは、階層図の表示だけ
    // 起点をそのホストの一番上まで広げて描く(巡回・判定の起点は変えない)。
    // ------------------------------------------------------------------

    private function shallowOriginSite(): WebsiteAnalysis
    {
        $wa = WebsiteAnalysis::factory()->create();
        // 起点は /recruit/engineer/(配下は自分自身の1件だけ)。サイトの一番上のメニューは別にある。
        $this->recruitPage(
            $wa,
            $this->html('<a href="/about/">会社を知る</a><a href="/jobs/">募集職種</a>', '<h1>エンジニア採用</h1>'),
            null,
            'https://example.com/recruit/engineer/',
            'エンジニア採用トップ',
        );
        $this->crawled($wa, 'https://example.com/recruit/engineer/', 'エンジニア採用トップ');
        $this->crawled($wa, 'https://example.com/about/', '会社を知る | 採用');
        $this->crawled($wa, 'https://example.com/about/philosophy', '私たちの考え方');
        $this->crawled($wa, 'https://example.com/jobs/', '募集職種 | 採用');
        $this->crawled($wa, 'https://example.com/jobs/sales', '営業');

        return $wa;
    }

    public function test_the_tree_is_widened_to_the_host_top_when_few_pages_are_under_the_origin(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 3]);
        $wa = $this->shallowOriginSite();

        $tree = $this->builder()->buildTree($wa);

        $this->assertTrue($tree['origin_widened']);
        $this->assertSame('https://example.com/', $tree['origin_url']);
        $this->assertSame('https://example.com/', $tree['top']['url']);
        $this->assertSame('menu', $tree['mode'], '広げた範囲でTOPのメニューが描ける');
        $this->assertSame(['会社を知る', '募集職種'], array_column($tree['branches'], 'name'));
        $this->assertSame(5, $tree['pages_within_origin'], '広げた範囲で数え直す');
        $this->assertNull($tree['top']['title'], '広げた先の一番上のものではないページ名は出さない');
        $this->assertSame([], $tree['top']['headings']);
    }

    public function test_the_tree_is_not_widened_when_enough_pages_are_under_the_origin(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 1]);
        $wa = $this->shallowOriginSite();

        $tree = $this->builder()->buildTree($wa);

        $this->assertFalse($tree['origin_widened']);
        $this->assertSame('https://example.com/recruit/engineer/', $tree['origin_url']);
    }

    public function test_widening_never_changes_the_origin_used_for_crawling_and_scoring(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 3]);
        $wa = $this->shallowOriginSite();

        $this->builder()->buildTree($wa);

        $this->assertSame(
            'https://example.com/recruit/engineer/',
            app(CrawlOriginScopeResolver::class)->resolveScope($wa)['origin_url'],
            '巡回・判定に使う起点(リゾルバ)は階層図の広げ方に影響されない',
        );
        $this->assertSame('https://example.com/recruit/engineer/', $this->builder()->countWithinOrigin($wa)['origin_url']);
    }

    public function test_a_site_that_is_already_at_the_top_is_not_reported_as_widened(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 10]);
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/a/">A</a><a href="/b/">B</a>'), null, 'https://example.com/');

        $tree = $this->builder()->buildTree($wa);

        $this->assertFalse($tree['origin_widened']);
    }

    public function test_widening_still_returns_an_empty_branch_list_when_nothing_can_be_drawn(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 3]);
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, null, null, 'https://example.com/recruit/engineer/');

        $tree = $this->builder()->buildTree($wa);

        $this->assertTrue($tree['origin_widened']);
        $this->assertSame([], $tree['branches']);
    }

    /**
     * 依頼CM-1の実例(SmartHR): 入力=サイトの一番上(hello-world.smarthr.co.jp)、
     * 転送先=recruit.smarthr.co.jp/engineer/、自己参照で採用ページの行が複製された。
     * 起点は転送先ホストの一番上になり、保存済みのページのメニューから木が描ける。
     */
    public function test_a_redirected_top_page_input_draws_the_menu_tree_from_the_stored_page(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $html = $this->html(
            '<a href="/about/">SmartHRを知る</a><a href="/business/">事業領域を知る</a><a href="/environment/">働く環境を知る</a>',
            '<h1>エンジニア採用</h1>',
        );
        Storage::disk('analysis')->put("pages/{$wa->id}/smarthr.html", $html);
        foreach ([PageType::Homepage, PageType::Recruit] as $type) {
            AnalysisPage::factory()->create([
                'website_analysis_id' => $wa->id,
                'page_type' => $type,
                'url' => 'https://hello-world.smarthr.co.jp/',
                'final_url' => 'https://recruit.smarthr.co.jp/engineer/',
                'title' => 'エンジニア採用',
                'raw_html_path' => "pages/{$wa->id}/smarthr.html",
            ]);
        }
        $this->crawled($wa, 'https://recruit.smarthr.co.jp/engineer/', 'エンジニア採用');
        $this->crawled($wa, 'https://recruit.smarthr.co.jp/about/', 'SmartHRを知る');
        $this->crawled($wa, 'https://recruit.smarthr.co.jp/about/mission', 'ミッション');
        $this->crawled($wa, 'https://recruit.smarthr.co.jp/business/', '事業領域を知る');
        $this->crawled($wa, 'https://recruit.smarthr.co.jp/environment/', '働く環境を知る');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('https://recruit.smarthr.co.jp/', $tree['origin_url']);
        $this->assertSame('menu', $tree['mode']);
        $this->assertFalse($tree['origin_widened']);
        $this->assertSame(['SmartHRを知る', '事業領域を知る', '働く環境を知る'], array_column($tree['branches'], 'name'));
        $this->assertSame(5, $tree['pages_within_origin']);
        $this->assertNull($tree['top']['title'], '起点そのもののページではないページ名は出さない');
        $this->assertSame([], $tree['top']['headings']);
    }

    // ------------------------------------------------------------------
    // 依頼CN-A1(2026-10-06): 第2階層のページ名。
    // ------------------------------------------------------------------

    private function titledHtml(?string $title, ?string $h1 = null): string
    {
        return '<html><head>'.($title !== null ? "<title>{$title}</title>" : '').'</head><body><main>'.($h1 !== null ? "<h1>{$h1}</h1>" : '').'<p>本文</p></main></body></html>';
    }

    private function siteWithTwoItems(): WebsiteAnalysis
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/culture/">カルチャー</a><a href="/recruit/jobs/">募集職種</a>'));

        return $wa;
    }

    public function test_a_page_without_a_saved_title_gets_its_name_from_the_stored_html_title_then_h1_then_the_url_path(): void
    {
        $wa = $this->siteWithTwoItems();
        $this->crawled($wa, 'https://example.com/recruit/culture/a', null, $this->titledHtml('Aのタイトル'));
        $this->crawled($wa, 'https://example.com/recruit/culture/b', null, $this->titledHtml(null, 'Bの見出し'));
        $this->crawled($wa, 'https://example.com/recruit/culture/c', null, $this->titledHtml(null));
        $this->crawled($wa, 'https://example.com/recruit/culture/d', null);

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['Aのタイトル', 'Bの見出し', '/recruit/culture/c'], $tree['branches'][0]['pages']);
        $this->assertSame(1, $tree['branches'][0]['other_page_count']);
    }

    public function test_the_rendered_html_is_read_before_the_static_html_for_the_page_name(): void
    {
        $wa = $this->siteWithTwoItems();
        $page = $this->crawled($wa, 'https://example.com/recruit/culture/a', null, $this->titledHtml('静的のタイトル'));
        Storage::disk('analysis')->put("pages/{$wa->id}/rendered-a.html", $this->titledHtml('描画後のタイトル'));
        $page->update(['rendered_html_path' => "pages/{$wa->id}/rendered-a.html"]);

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['描画後のタイトル'], $tree['branches'][0]['pages']);
    }

    public function test_the_page_names_are_not_written_back_to_the_database(): void
    {
        $wa = $this->siteWithTwoItems();
        $page = $this->crawled($wa, 'https://example.com/recruit/culture/a', null, $this->titledHtml('Aのタイトル'));

        $this->builder()->buildTree($wa);

        $this->assertNull($page->fresh()->title);
    }

    public function test_a_common_site_name_suffix_is_removed_from_the_page_names(): void
    {
        $wa = $this->siteWithTwoItems();
        $this->crawled($wa, 'https://example.com/recruit/culture/a', 'Aの記事 | Example採用サイト');
        $this->crawled($wa, 'https://example.com/recruit/culture/b', 'Bの記事 | Example採用サイト');
        $this->crawled($wa, 'https://example.com/recruit/culture/c', 'Cの記事｜Example採用サイト');
        $this->crawled($wa, 'https://example.com/recruit/jobs/x', '別の書き方のページ');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['Aの記事', 'Bの記事', 'Cの記事'], $tree['branches'][0]['pages']);
        $this->assertSame(['別の書き方のページ'], $tree['branches'][1]['pages'], '区切りの無いtitleは変えない');
    }

    public function test_a_title_that_would_become_empty_keeps_its_site_name_and_a_minority_suffix_is_kept(): void
    {
        $wa = $this->siteWithTwoItems();
        $this->crawled($wa, 'https://example.com/recruit/culture/a', 'Aの記事 | Example採用サイト');
        $this->crawled($wa, 'https://example.com/recruit/culture/b', 'Bの記事 | Example採用サイト');
        $this->crawled($wa, 'https://example.com/recruit/culture/c', 'Example採用サイト');
        $this->crawled($wa, 'https://example.com/recruit/jobs/x', 'Xの記事 | 別のサイト名');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['Aの記事', 'Bの記事', 'Example採用サイト'], $tree['branches'][0]['pages'], '落とすと空になるページは落とさない');
        $this->assertSame(['Xの記事 | 別のサイト名'], $tree['branches'][1]['pages'], '過半に共通しない末尾は落とさない');
    }

    public function test_long_page_names_are_cut_with_the_existing_truncator(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_page_label_max_chars' => 12]);
        $wa = $this->siteWithTwoItems();
        $this->crawled($wa, 'https://example.com/recruit/culture/a', 'とても長いページ名がここに入ります。続きの文章です');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('とても長いページ名がここ…', $tree['branches'][0]['pages'][0], '句点が上限内に無いときは上限で切って…を付ける');
    }

    // ------------------------------------------------------------------
    // 依頼CN-A2(2026-10-06): 第2階層の置き場所。
    // ------------------------------------------------------------------

    public function test_a_page_below_a_branch_url_goes_to_that_branch_even_if_another_branch_links_to_it_first(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/service/">サービス</a><a href="/recruit/culture/">カルチャー</a>'));
        // サービスのページが、カルチャー配下のページを本文でリンクしている(先にあるサービスの枝に吸われていた)。
        $this->crawled($wa, 'https://example.com/recruit/service/', 'サービス', $this->html('', '<a href="/recruit/culture/mvvc">ミッション</a>'));
        $this->crawled($wa, 'https://example.com/recruit/culture/', 'カルチャー');
        $this->crawled($wa, 'https://example.com/recruit/culture/mvvc', 'ミッション');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame([], $tree['branches'][0]['pages']);
        $this->assertSame(['ミッション'], $tree['branches'][1]['pages']);
        $this->assertSame(1, $tree['branches'][0]['page_count']);
        $this->assertSame(2, $tree['branches'][1]['page_count']);
    }

    public function test_a_page_outside_every_branch_goes_to_the_only_branch_that_links_to_it(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/culture/">カルチャー</a><a href="/recruit/jobs/">募集職種</a>'));
        $this->crawled($wa, 'https://example.com/recruit/culture/', 'カルチャー', $this->html('', '<a href="/recruit/en/mvvc">English</a>'));
        $this->crawled($wa, 'https://example.com/recruit/jobs/', '募集職種', $this->html('', '<a href="/recruit/news/1">お知らせ</a>'));
        $this->crawled($wa, 'https://example.com/recruit/en/mvvc', 'MVVC');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['MVVC'], $tree['branches'][0]['pages']);
        $this->assertSame(2, $tree['branches'][0]['page_count']);
        $this->assertSame(0, $tree['unplaced_page_count']);
        $this->assertSame(['links' => 1, 'url' => 0], $tree['second_level_source']);
    }

    public function test_a_page_linked_from_two_branches_belongs_to_neither_and_is_counted_as_unplaced(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/a/">項目A</a><a href="/recruit/b/">項目B</a>'));
        $shared = '<a href="/recruit/harassment-policy">ハラスメントポリシー</a><a href="/recruit/b/">項目B(本文内)</a><a href="/recruit/">TOPへ戻る</a>';
        $this->crawled($wa, 'https://example.com/recruit/a/', '項目Aのページ', $this->html('', $shared));
        $this->crawled($wa, 'https://example.com/recruit/b/', '項目Bのページ', $this->html('', $shared));
        $this->crawled($wa, 'https://example.com/recruit/harassment-policy', 'ハラスメントポリシー');
        $this->crawled($wa, 'https://example.com/recruit/orphan', 'どこからもリンクされないページ');
        $this->crawled($wa, 'https://example.com/recruit/', 'TOP');

        $tree = $this->builder()->buildTree($wa);

        [$a, $b] = $tree['branches'];
        $this->assertSame([], $a['pages']);
        $this->assertSame([], $b['pages']);
        $this->assertSame(1, $a['page_count'], '枝のページ数は置いた数で数え直す(項目のページ自身のみ)');
        $this->assertSame(2, $tree['unplaced_page_count'], '置かなかったページは捨てずに件数を出す');
    }

    public function test_the_deepest_branch_wins_when_a_page_is_below_two_branch_urls(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/job/">仕事</a><a href="/recruit/job-interview.html">インタビュー</a>'));
        $this->crawled($wa, 'https://example.com/recruit/job/x', 'Xのページ');
        $this->crawled($wa, 'https://example.com/recruit/job-interview/y', 'Yのインタビュー');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(['Xのページ'], $tree['branches'][0]['pages']);
        $this->assertSame(['Yのインタビュー'], $tree['branches'][1]['pages']);
    }

    public function test_first_level_labels_include_the_branches_folded_into_the_other_count(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_tree_first_level_limit' => 2]);
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, $this->html('<a href="/recruit/a/">項目A</a><a href="/recruit/b/">項目B</a><a href="/recruit/culture/">カルチャー</a>'));
        $this->crawled($wa, 'https://example.com/recruit/a/p1', 'a1');
        $this->crawled($wa, 'https://example.com/recruit/a/p2', 'a2');
        $this->crawled($wa, 'https://example.com/recruit/b/p1', 'b1');
        $this->crawled($wa, 'https://example.com/recruit/b/p2', 'b2');
        $this->crawled($wa, 'https://example.com/recruit/culture/p1', 'c1');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame(1, $tree['other_branch_count']);
        $this->assertSame(['項目A', '項目B'], array_column($tree['branches'], 'name'));
        $this->assertSame(['項目A', '項目B', 'カルチャー'], $tree['first_level_labels'], '画面に出ない(畳まれた)枝も含む');
    }

    public function test_the_url_hierarchy_fallback_is_unchanged_by_the_new_placement_rules(): void
    {
        $wa = WebsiteAnalysis::factory()->create();
        $this->recruitPage($wa, null);
        $this->crawled($wa, 'https://example.com/recruit/culture/', 'カルチャー');
        $this->crawled($wa, 'https://example.com/recruit/culture/vision', '私たちのビジョン');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('url', $tree['mode']);
        $this->assertSame(['カルチャー'], array_column($tree['branches'], 'name'));
        $this->assertSame(['私たちのビジョン'], $tree['branches'][0]['pages']);
        $this->assertSame(0, $tree['unplaced_page_count']);
        $this->assertSame(['カルチャー'], $tree['first_level_labels']);
    }
}
