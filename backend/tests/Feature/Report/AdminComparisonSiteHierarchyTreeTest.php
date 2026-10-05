<?php

namespace Tests\Feature\Report;

use App\Enums\PageType;
use App\Models\AnalysisCrawledPage;
use App\Models\AnalysisPage;
use App\Models\WebsiteAnalysis;
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

        $this->assertSame(['links' => 1, 'url' => 1], $tree['second_level_source'], 'どちらで作ったかを件数で返す');
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

    public function test_a_page_is_assigned_to_only_one_branch_and_never_to_the_top_or_a_menu_item(): void
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
        $this->assertSame(['共有ページ'], $a['pages'], '先に出た項目Aの枝に入る');
        $this->assertSame([], $b['pages'], '同じページを2つの枝に重ねて出さない');
        $this->assertSame(1, $b['page_count']);
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
}
