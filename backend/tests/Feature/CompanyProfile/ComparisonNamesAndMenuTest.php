<?php

namespace Tests\Feature\CompanyProfile;

use App\Models\Analysis;
use App\Models\Website;
use App\Services\CorporateTop\CorporateTopAnalyzer;
use App\Services\Report\CompanyProfileSlideGenerator;
use App\Support\Report\ComparisonCompanyName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼DL: 比較の自社名・出典の行・コーポレートの他のメニューの取り方。
 */
class ComparisonNamesAndMenuTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    public function test_the_own_company_name_is_the_name_entered_for_the_comparison_and_falls_back_to_the_company(): void
    {
        $self = $this->makeComparisonWebsiteAnalysis(true, 0, 'self');
        $analysis = Analysis::query()->findOrFail($self->analysis_id);
        $website = Website::query()->findOrFail($self->website_id);
        $leadName = (string) $analysis->project->leadCompany->company_name;

        $website->update(['name' => '株式会社乃村工藝社']);
        $this->assertSame('株式会社乃村工藝社', ComparisonCompanyName::self($analysis->fresh()));
        $this->assertSame('株式会社乃村工藝社', app(\App\Services\Report\MultiSiteReportViewModelBuilder::class)->build($analysis->fresh())->selfCompanyDisplayName);

        $website->update(['name' => '自社サイト']);
        $this->assertSame($leadName, ComparisonCompanyName::self($analysis->fresh()), 'プレースホルダのときは、紐づいた企業の名前');
    }

    public function test_the_source_line_groups_the_own_site_and_each_outside_site_with_short_page_names(): void
    {
        $profile = ['headline' => null, 'lead' => null, 'pillars' => [], 'sources' => [
            ['url' => 'https://www.example.co.jp/corporate/greeting/', 'title' => 'トップメッセージ | 会社情報 | 株式会社例社', 'own' => true],
            ['url' => 'https://www.example.co.jp/ir/', 'title' => '株主・投資家のみなさまへ｜株式会社例社', 'own' => true],
            ['url' => 'https://prtimes.jp/main/html/rd/p/1.html', 'title' => '例社が手掛けたプロジェクトが「2025年度グッドデザイン賞」を受賞 | 株式会社例社のプレスリリース', 'own' => false],
            ['url' => 'https://prtimes.jp/main/html/rd/p/2.html', 'title' => '万博から商業施設まで | 株式会社例社のプレスリリース', 'own' => false],
        ]];

        $bytes = app(CompanyProfileSlideGenerator::class)->generate(['company_name' => '例社', 'is_self' => true, 'profile' => $profile]);
        $tmp = tempnam(sys_get_temp_dir(), 'cp');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertStringContainsString('出典：例社のサイト（トップメッセージ／株主・投資家のみなさまへ）、株式会社例社のプレスリリース（例社が手掛けたプロジェクトが「2025年度グッ…／万博から商業施設まで）', html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    public function test_when_no_menu_container_is_found_the_links_to_first_level_directories_are_used(): void
    {
        $html = '<html><body><div class="hd"><a href="/"><img alt="例社"></a>'
            .'<a href="/solutions/">事業内容</a><a href="/achievements/">実績紹介</a><a href="/corporate/">会社情報</a>'
            .'<a href="/recruit/">採用情報</a><a href="/ir/">IR情報</a><a href="/news/2025/1.html">お知らせの記事</a>'
            .'<a href="https://other.example.org/x/">外部</a><a href="/corporate/">会社情報</a></div>'
            .'<footer><a href="/privacy/">個人情報</a></footer></body></html>';

        $menu = app(CorporateTopAnalyzer::class)->otherMenu($html, null, 'https://www.example.com/', ['origin_url' => 'https://www.example.com/recruit/', 'host' => 'www.example.com', 'path' => '/recruit/'], '採用情報');

        $this->assertSame('section_roots', $menu['source']);
        $this->assertSame(['事業内容', '実績紹介', '会社情報', 'IR情報'], $menu['labels']);
    }

    public function test_when_the_menu_has_too_many_items_only_the_links_to_first_level_directories_are_kept(): void
    {
        $items = '';
        foreach (['solutions' => '事業内容', 'achievements' => '実績紹介', 'corporate' => '会社情報', 'ir' => 'IR情報', 'sustainability' => 'サステナビリティ'] as $dir => $label) {
            $items .= '<a href="/'.$dir.'/">'.$label.'</a>';
            foreach ([1, 2, 3] as $n) {
                $items .= '<a href="/'.$dir.'/page'.$n.'/">'.$label.'の項目'.$n.'</a>';
            }
        }
        $html = '<html><body><header>'.$items.'<a href="/recruit/">採用情報</a><a href="/en/">English</a></header><main><p>本文</p></main></body></html>';

        $menu = app(CorporateTopAnalyzer::class)->otherMenu($html, null, 'https://www.example.com/', ['origin_url' => 'https://www.example.com/recruit/', 'host' => 'www.example.com', 'path' => '/recruit/'], '採用情報');

        $this->assertSame('static_nav_roots', $menu['source']);
        $this->assertSame(['事業内容', '実績紹介', '会社情報', 'IR情報', 'サステナビリティ'], $menu['labels']);
        $this->assertArrayHasKey('too_many', $menu['excluded']['static_nav']);
    }

    public function test_links_to_language_directories_are_not_taken_as_sections(): void
    {
        $html = '<html><body><div class="hd"><a href="/en/">NOMURA Co.,Ltd.</a><a href="/zh-cn/">中文</a><a href="/solutions/">事業内容</a></div></body></html>';

        $menu = app(CorporateTopAnalyzer::class)->otherMenu($html, null, 'https://www.example.com/', ['origin_url' => 'https://www.example.com/recruit/', 'host' => 'www.example.com', 'path' => '/recruit/'], '採用情報');

        $this->assertSame(['事業内容'], $menu['labels']);
    }
}
