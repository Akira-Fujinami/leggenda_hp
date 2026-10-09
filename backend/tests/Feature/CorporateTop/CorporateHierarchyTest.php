<?php

namespace Tests\Feature\CorporateTop;

use App\Enums\PageType;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\CorporateTop\CorporateTopStore;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\Report\AdminComparisonSiteHierarchyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestPptxDecks;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CR-2/CR-3: 階層図の第2階層の枝名(リンクの文字)、コーポレートTOPの4列、いまの3列への切り替え、見出し・パス。
 * 通信は行わない(保存済みのファイルだけを読む)。
 */
class CorporateHierarchyTest extends TestCase
{
    use MakesTestPptxDecks;
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const ORIGIN = 'https://self.example.com/recruit/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['admin_comparison_pptx.site_hierarchy_tree_widen_min_pages' => 0]);
    }

    private function builder(): AdminComparisonSiteHierarchyBuilder
    {
        return app(AdminComparisonSiteHierarchyBuilder::class);
    }

    private function link(string $href, string $text): string
    {
        return '<a href="'.$href.'">'.$text.'</a>';
    }

    /**
     * 起点 /recruit/ の下に、新卒(/fresh/)・中途(/career/)の2つの枝があるサイト。
     * メニュー(header/nav)は無く、URLの階層で描かれる。リンクは$linksを本文に置く。
     */
    private function siteWithLinks(string $links = ''): WebsiteAnalysis
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, self::ORIGIN, $this->htmlPage('採用', '採用です。', $links), PageType::Recruit);
        $this->addCrawledPage($wa, self::ORIGIN, '採用', $this->htmlPage('採用', '採用です。', $links));
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/', '学歴や卒業時期は不問', $this->htmlPage('x', '新卒です。'));
        $this->addCrawledPage($wa, self::ORIGIN.'fresh/a', '社員インタビュー', $this->htmlPage('x', '新卒の話です。'));
        $this->addCrawledPage($wa, self::ORIGIN.'career/', '求人情報の一覧です', $this->htmlPage('x', '中途です。'));
        $this->addCrawledPage($wa, self::ORIGIN.'career/b', '営業職の求人', $this->htmlPage('x', '中途の話です。'));

        return $wa;
    }

    /**
     * @param  list<string>  $menu
     */
    private function storeCorporateTop(WebsiteAnalysis $wa, array $menu = ['会社情報', 'サービス'], string $recruitHref = '/recruit/', string $recruitText = '採用情報', string $url = 'https://www.example.com/'): void
    {
        $items = implode('', array_map(fn (string $name) => $this->link('/x/'.md5($name), $name), $menu));
        $html = '<html><body><header><nav>'.$items.$this->link($recruitHref, $recruitText).'</nav></header></body></html>';
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, [
            'status' => 'found', 'url' => $url, 'recruit_label' => $recruitText, 'recruit_url' => 'https://www.example.com/recruit/',
        ], $html);
    }

    // ---------------------------------------------------------------- CR-2 枝名

    public function test_the_branch_name_is_the_most_used_link_text_and_otherwise_the_shorter_one(): void
    {
        $wa = $this->siteWithLinks(
            $this->link('/recruit/fresh/', '新卒採用').$this->link('/recruit/fresh/', '新卒採用').$this->link('/recruit/fresh/', '学生の方')
            .$this->link('/recruit/career/', 'キャリア採用').$this->link('/recruit/career/', '中途'),
        );

        $names = array_column($this->builder()->buildTree($wa)['branches'], 'name', 'path');

        $this->assertSame('新卒採用', $names['/recruit/fresh'], '多い文字(2回)が勝つ');
        $this->assertSame('中途', $names['/recruit/career'], '同数(1回ずつ)なら短いほう');
    }

    public function test_generic_too_long_and_empty_link_texts_are_not_candidates_and_the_title_is_the_fallback(): void
    {
        $wa = $this->siteWithLinks(
            $this->link('/recruit/fresh/', '詳しく見る').$this->link('/recruit/fresh/', 'MORE').$this->link('/recruit/fresh/', 'こちら')
            .$this->link('/recruit/career/', '十二文字をこえる長いリンクの文字です').$this->link('/recruit/career/', '   '),
        );

        $tree = $this->builder()->buildTree($wa);
        $names = array_column($tree['branches'], 'name', 'path');

        $this->assertSame('学歴や卒業時期は不問', $names['/recruit/fresh'], '汎用の言葉しか無いので、従来どおりページのtitle');
        $this->assertSame('求人情報の一覧です', $names['/recruit/career'], '長すぎる・空の文字も候補にならない');
    }

    public function test_a_link_to_the_branch_with_a_trailing_slash_difference_counts_as_the_same_url(): void
    {
        $wa = $this->siteWithLinks($this->link('/recruit/fresh', '新卒').$this->link('https://self.example.com/recruit/fresh/', '新卒').$this->link('/recruit/fresh/#top', '新卒'));

        $names = array_column($this->builder()->buildTree($wa)['branches'], 'name', 'path');

        $this->assertSame('新卒', $names['/recruit/fresh']);
    }

    public function test_link_texts_are_also_collected_from_the_corporate_top(): void
    {
        $wa = $this->siteWithLinks();
        $this->storeCorporateTop($wa, ['新卒の方はこちら']);
        // 採用のリンクの文字とは別に、コーポレートTOPにある枝へのリンクの文字も集める。
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, [
            'status' => 'found', 'url' => 'https://self.example.com/', 'recruit_label' => '採用情報', 'recruit_url' => self::ORIGIN,
        ], '<header><nav><a href="/recruit/">採用情報</a></nav></header><main><a href="/recruit/fresh/">新卒</a></main>');

        $names = array_column($this->builder()->buildTree($wa)['branches'], 'name', 'path');

        $this->assertSame('新卒', $names['/recruit/fresh']);
    }

    public function test_the_link_names_can_be_switched_off(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_link_names_enabled' => false]);
        $wa = $this->siteWithLinks($this->link('/recruit/fresh/', '新卒採用'));

        $names = array_column($this->builder()->buildTree($wa)['branches'], 'name', 'path');

        $this->assertSame('学歴や卒業時期は不問', $names['/recruit/fresh']);
    }

    // ---------------------------------------------------------------- CR-1 / CR-3 データ

    public function test_a_stored_corporate_top_gives_the_corporate_info_the_recruit_label_and_the_other_menu_names_only(): void
    {
        $wa = $this->siteWithLinks();
        $this->storeCorporateTop($wa, ['会社情報', 'サービス', 'ニュース', 'IR情報', '企業理念', 'お問い合わせ'], '/recruit/', 'RECRUIT');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('www.example.com', $tree['corporate']['host']);
        $this->assertSame('RECRUIT', $tree['corporate']['recruit_label']);
        $this->assertSame(['会社情報', 'サービス', 'ニュース', 'IR情報'], $tree['corporate']['other_menu'], '上限(4件)まで、採用の項目は含まない');
        $this->assertSame(2, $tree['corporate']['other_menu_more']);
        $this->assertFalse($tree['corporate_missing']);
        $this->assertSame('self.example.com/recruit', $tree['recruit_path'], 'コーポレートTOPのホストと違うので、ホストから書く');
    }

    public function test_the_default_label_is_used_when_the_link_text_is_empty(): void
    {
        $wa = $this->siteWithLinks();
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'found', 'url' => 'https://self.example.com/', 'recruit_label' => null, 'recruit_url' => self::ORIGIN], '<header><nav><a href="/x">会社情報</a></nav></header>');

        $this->assertSame('採用情報', $this->builder()->buildTree($wa)['corporate']['recruit_label']);
    }

    public function test_paths_omit_the_host_on_the_same_host_and_write_it_otherwise(): void
    {
        $wa = $this->siteWithLinks();
        $this->storeCorporateTop($wa, ['会社情報'], url: 'https://self.example.com/');

        $tree = $this->builder()->buildTree($wa);

        $this->assertSame('/recruit', $tree['recruit_path']);
        $this->assertEqualsCanonicalizing(['/recruit/fresh', '/recruit/career'], array_column($tree['branches'], 'path'));
    }

    public function test_long_paths_are_shortened_in_the_middle(): void
    {
        config(['admin_comparison_pptx.site_hierarchy_path_max_chars' => 12]);
        $wa = $this->siteWithLinks();
        $this->addCrawledPage($wa, self::ORIGIN.'a-very-long-section-name/', '長い', $this->htmlPage('x', '本文です。'));

        $paths = array_column($this->builder()->buildTree($wa)['branches'], 'path');

        foreach ($paths as $path) {
            $this->assertLessThanOrEqual(12, mb_strlen((string) $path));
        }
        $shortened = array_values(array_filter($paths, fn ($p) => str_contains((string) $p, '…')));
        $this->assertNotSame([], $shortened);
        foreach ($shortened as $path) {
            $this->assertSame(12, mb_strlen($path), '途中を「…」で省いて、上限ちょうどの長さにする');
        }
    }

    public function test_without_a_decided_corporate_top_the_tree_is_the_current_three_columns_and_the_note_appears_only_after_a_failed_check(): void
    {
        $never = $this->siteWithLinks();
        $treeNever = $this->builder()->buildTree($never);
        $this->assertNull($treeNever['corporate']);
        $this->assertFalse($treeNever['corporate_missing'], 'まだ確かめていない(ファイルが無い)ときは注記を出さない');

        $failed = $this->siteWithLinks();
        app(CorporateTopStore::class)->write($failed->analysis_id, $failed->id, ['status' => 'not_found'], null);
        $treeFailed = $this->builder()->buildTree($failed);
        $this->assertNull($treeFailed['corporate']);
        $this->assertTrue($treeFailed['corporate_missing']);
    }

    // ---------------------------------------------------------------- CR-3 スライド

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tree');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    private function slideFor(WebsiteAnalysis $wa): string
    {
        $tree = $this->builder()->buildTree($wa);

        return $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));
    }

    public function test_four_columns_with_headings_descriptions_the_recruit_box_and_the_paths(): void
    {
        $wa = $this->siteWithLinks($this->link('/recruit/fresh/', '新卒採用'));
        $this->storeCorporateTop($wa, ['会社情報', 'サービス'], url: 'https://www.example.com/');

        $xml = $this->slideFor($wa);

        foreach (['コーポレートTOP', 'サイトの入口', '第1階層', 'コーポレートのメニュー', '第2階層', '採用サイトの区分', '第3階層', '各ページ', 'www.example.com', '採用情報', '会社情報', 'サービス', '新卒採用', '/recruit/fresh'] as $text) {
            $this->assertStringContainsString($text, $xml, $text);
        }
        $this->assertStringContainsString('self.example.com/recruit', $xml, 'ホストが違うので、採用の箱のパスはホストから');
        $this->assertStringNotContainsString('コーポレートサイトのTOPから採用サイトへのリンクを確認できなかった', $xml);
    }

    public function test_three_columns_with_three_headings_and_the_note_when_the_corporate_top_was_not_decided(): void
    {
        $wa = $this->siteWithLinks();
        app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'not_found'], null);

        $xml = $this->slideFor($wa);

        foreach (['採用サイトTOP', '第1階層', '採用サイトの区分', '第2階層', '各ページ', 'コーポレートサイトのTOPから採用サイトへのリンクを確認できなかったため、採用サイトを起点に描いています。', '/recruit/fresh'] as $text) {
            $this->assertStringContainsString($text, $xml, $text);
        }
        $this->assertStringNotContainsString('第3階層', $xml);
        $this->assertStringNotContainsString('コーポレートのメニュー', $xml);
    }

    public function test_the_redirect_line_sits_inside_the_recruit_box_in_the_four_column_tree(): void
    {
        $wa = $this->siteWithLinks();
        $this->storeCorporateTop($wa, ['会社情報'], url: 'https://www.example.com/');
        $tree = $this->builder()->buildTree($wa);
        $tree['top']['input_redirected'] = true;

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => []], $tree));

        $note = (string) config('admin_comparison_pptx.site_hierarchy_tree_top_redirected_note');
        $this->assertSame(1, substr_count($xml, $note));
        // 採用の箱の同じテキストボックス(<p:sp>)の中にある。
        preg_match_all('#<p:sp>.*?</p:sp>#s', $xml, $shapes);
        $box = array_values(array_filter($shapes[0], fn (string $sp) => str_contains($sp, $note)));
        $this->assertCount(1, $box);
        $this->assertStringContainsString('採用情報', $box[0]);
    }

    public function test_the_slide_has_only_integer_geometry_and_no_external_parts(): void
    {
        $wa = $this->siteWithLinks();
        $this->storeCorporateTop($wa);
        $tree = $this->builder()->buildTree($wa);
        $raw = $this->rawSlideXml(app(AdminComparisonPptxGenerator::class)->generateSiteHierarchySlide(['recommended_site_flow_names' => ['福利厚生']], $tree));

        // 小数のEMU・インセットはPowerPointが「破損」として開けない(実機で確認済み)。
        $this->assertDoesNotMatchRegularExpression('/\b(?:lIns|rIns|tIns|bIns|x|y|cx|cy)="[0-9]*\.[0-9]+"/', $raw);
        $this->assertDoesNotMatchRegularExpression('/\br:(id|embed|link)="/', $raw);
    }

    private function rawSlideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tree');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    public function test_the_ppt_download_never_talks_to_the_outside(): void
    {
        Http::fake();
        $self = $this->makeComparisonWebsiteAnalysis();
        $analysis = Analysis::query()->findOrFail($self->analysis_id);
        BrandWheelAnalysisResult::factory()->create(['analysis_id' => $analysis->id, 'website_analysis_id' => $self->id, 'status' => 'success', 'axes' => []]);
        $this->addHomepage($self, self::ORIGIN, $this->htmlPage('採用', '採用です。'), PageType::Recruit);
        $this->storeCorporateTop($self);
        $path = "attachments/{$analysis->id}/test.pptx";
        Storage::disk('analysis')->put($path, $this->makeMinimalPptxBytes(['内容1', '参照元']));
        AnalysisAttachment::factory()->create([
            'analysis_id' => $analysis->id, 'original_filename' => '営業資料.pptx', 'storage_path' => $path,
            'extension' => 'pptx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ]);

        $response = $this->withSession(['admin_authenticated' => true])->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));

        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_more_other_menu_names_than_fit_are_folded_into_a_count(): void
    {
        $wa = $this->siteWithLinks();
        $this->storeCorporateTop($wa, ['A社情報', 'Bサービス', 'Cニュース', 'D IR', 'E理念', 'F窓口'], url: 'https://www.example.com/');

        $xml = $this->slideFor($wa);

        $this->assertStringContainsString('ほか2', $xml);
        $this->assertStringNotContainsString('E理念', $xml);
    }
}
