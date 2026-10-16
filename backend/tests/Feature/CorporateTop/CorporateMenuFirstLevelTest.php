<?php

namespace Tests\Feature\CorporateTop;

use App\Services\CorporateTop\CorporateTopAnalyzer;
use Tests\TestCase;

/**
 * 依頼DA-2(AIなし・通信しない): コーポレートTOPのメニューは「1段目の項目」だけを取る。
 * サブメニュー(ドロップダウン・メガメニュー)の中は取らない。HTMLはすべて、同じ構造を持つ最小の人工のもの。
 */
class CorporateMenuFirstLevelTest extends TestCase
{
    private const SCOPE = ['origin_url' => 'https://self.example.com/recruit/', 'host' => 'self.example.com', 'path' => '/recruit/'];

    private function a(string $href, string $text): string
    {
        return '<a href="'.$href.'">'.$text.'</a>';
    }

    private function menu(?string $static, ?string $rendered = null): array
    {
        return app(CorporateTopAnalyzer::class)->otherMenu((string) $static, $rendered, 'https://www.example.com/', self::SCOPE, '採用情報');
    }

    /** 1段目 + メガメニュー(入れ子の div・ul の中にリンク)を持つヘッダー。 */
    private function megaMenuHeader(): string
    {
        return '<html><body><header><nav><ul class="gnav">'
            .'<li>'.$this->a('/products/', '製品情報').'<div class="mega"><ul><li>'.$this->a('/products/top/', '製品詳細 トップページへ').'</li><li>'.$this->a('/products/a/', '製品A').'</li><li>'.$this->a('/products/b/', '製品B').'</li></ul></div></li>'
            .'<li>'.$this->a('/support/', 'サポート').'<ul><li>'.$this->a('/support/faq/', 'よくある質問').'</li></ul></li>'
            .'<li>'.$this->a('/contact-us/', '導入のご相談').'</li>'
            .'</ul></nav></header></body></html>';
    }

    public function test_only_the_first_level_items_are_taken_and_links_inside_a_sub_menu_are_not(): void
    {
        $result = $this->menu($this->megaMenuHeader());

        $this->assertSame('static_nav', $result['source']);
        $this->assertSame(['製品情報', 'サポート', '導入のご相談'], $result['labels']);
    }

    public function test_the_same_holds_for_the_rendered_html_and_for_the_class_words_step(): void
    {
        $rendered = $this->menu('<html><body><main>本文</main></body></html>', $this->megaMenuHeader());
        $this->assertSame('rendered_nav', $rendered['source']);
        $this->assertSame(['製品情報', 'サポート', '導入のご相談'], $rendered['labels']);

        $classOnly = '<html><body><div class="gnav"><ul>'
            .'<li>'.$this->a('/products/', '製品情報').'<div class="mega">'.$this->a('/products/a/', '製品A').'</div></li>'
            .'<li>'.$this->a('/about/', '会社情報').'</li></ul></div></body></html>';
        $viaClass = $this->menu($classOnly);
        $this->assertSame('class_words', $viaClass['source']);
        $this->assertSame(['製品情報', '会社情報'], $viaClass['labels']);
    }

    public function test_a_button_text_can_be_the_item_name_even_without_a_link(): void
    {
        $html = '<html><body><header><nav><ul>'
            .'<li><button type="button">製品情報</button><div class="mega"><ul><li>'.$this->a('/products/a/', '製品A').'</li></ul></div></li>'
            .'<li>'.$this->a('#', '会社情報').'<ul><li>'.$this->a('/about/history/', '沿革').'</li></ul></li>'
            .'<li>'.$this->a('/news/', 'ニュース').'</li>'
            .'</ul></nav></header></body></html>';

        $result = $this->menu($html);

        $this->assertSame(['製品情報', '会社情報', 'ニュース'], $result['labels'], 'ボタン・「#」のリンクの文字が項目名。中のリンクは取らない');
    }

    public function test_the_first_trigger_inside_an_item_is_the_name_even_when_a_button_comes_first(): void
    {
        $html = '<html><body><header><nav><ul>'
            .'<li><button>ソリューション</button>'.$this->a('/solutions/all/', 'すべてのソリューション').'</li>'
            .'<li>'.$this->a('/news/', 'ニュース').'</li>'
            .'</ul></nav></header></body></html>';

        $this->assertSame(['ソリューション', 'ニュース'], $this->menu($html)['labels']);
    }

    public function test_link_texts_that_are_not_a_section_are_excluded(): void
    {
        $html = '<html><body><header><nav>'
            .$this->a('/products/', '製品詳細 トップページへ')
            .$this->a('/cases/', '導入事例 一覧へ')
            .$this->a('/blog/', '詳しくはこちら')
            .$this->a('/news/', 'もっと見る')
            .$this->a('/about/', '会社情報')
            .'</nav></header></body></html>';

        $result = $this->menu($html);

        $this->assertSame(['会社情報'], $result['labels']);
        $this->assertSame(4, $result['excluded']['static_nav']['not_a_section']);
    }

    public function test_a_skip_link_is_not_a_section(): void
    {
        $html = '<html><body><header>'.$this->a('#main', '本文へ').$this->a('/main/', 'Skip to main content').'<nav><ul><li>'.$this->a('/about/', '会社情報').'</li></ul></nav></header></body></html>';

        $this->assertSame(['会社情報'], $this->menu($html)['labels']);
    }

    public function test_more_first_level_items_than_the_limit_means_the_menu_was_not_read_and_the_next_way_is_tried(): void
    {
        config(['admin_comparison_pptx.corporate_top_menu_first_level_max' => 3]);
        $many = '';
        foreach (['A社情報', 'Bサービス', 'Cニュース', 'D理念'] as $i => $name) {
            $many .= '<li>'.$this->a('/m/'.$i.'/', $name).'</li>';
        }
        $static = '<html><body><header><nav><ul>'.$many.'</ul></nav></header></body></html>';
        $rendered = '<html><body><header><nav><ul><li>'.$this->a('/about/', '会社情報').'</li><li>'.$this->a('/ir/', 'IR情報').'</li></ul></nav></header></body></html>';

        $result = $this->menu($static, $rendered);

        $this->assertSame('rendered_nav', $result['source'], '静的なHTMLは4項目で上限(3)超え → 使わず、次の取り方へ');
        $this->assertSame(['会社情報', 'IR情報'], $result['labels']);
        $this->assertSame(4, $result['excluded']['static_nav']['too_many']);

        $none = $this->menu($static);
        $this->assertNull($none['source'], '最後まで取れなければ、他メニューは無し(採用情報だけ)');
        $this->assertSame([], $none['labels']);
    }

    public function test_the_default_limit_is_twelve_and_exactly_the_limit_is_still_used(): void
    {
        $this->assertSame(12, config('admin_comparison_pptx.corporate_top_menu_first_level_max'));
        config(['admin_comparison_pptx.corporate_top_menu_first_level_max' => 3]);
        $items = '<li>'.$this->a('/a/', 'A社情報').'</li><li>'.$this->a('/b/', 'Bサービス').'</li><li>'.$this->a('/c/', 'Cニュース').'</li>';

        $result = $this->menu('<html><body><header><nav><ul>'.$items.'</ul></nav></header></body></html>');

        $this->assertSame(['A社情報', 'Bサービス', 'Cニュース'], $result['labels']);
    }

    public function test_the_rest_count_is_only_the_remaining_first_level_items(): void
    {
        config(['admin_comparison_pptx.corporate_top_other_menu_limit' => 2]);
        $html = '<html><body><header><nav><ul>'
            .'<li>'.$this->a('/a/', 'A社情報').'<ul><li>'.$this->a('/a/1/', '子1').'</li><li>'.$this->a('/a/2/', '子2').'</li><li>'.$this->a('/a/3/', '子3').'</li></ul></li>'
            .'<li>'.$this->a('/b/', 'Bサービス').'</li>'
            .'<li>'.$this->a('/c/', 'Cニュース').'</li>'
            .'<li>'.$this->a('/d/', 'D理念').'</li>'
            .'</ul></nav></header></body></html>';

        $labels = $this->menu($html)['labels'];

        $this->assertSame(['A社情報', 'Bサービス', 'Cニュース', 'D理念'], $labels, 'サブメニューの子(子1〜3)は数えない');
        $this->assertSame(2, count($labels) - (int) config('admin_comparison_pptx.corporate_top_other_menu_limit'), '「ほかN」は1段目の残りだけ');
    }
}
