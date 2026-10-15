<?php

namespace Tests\Feature\SiteFirstImpression;

use App\Enums\AnalysisJobStatus;
use App\Enums\AnalysisStatus;
use App\Enums\JobType;
use App\Enums\PageType;
use App\Jobs\Analysis\FinalizeWebsiteAnalysisJob;
use App\Jobs\FetchCorporateTopJob;
use App\Jobs\GenerateSiteFirstImpressionJob;
use App\Models\AnalysisJob;
use App\Models\LeadCompany;
use App\Models\WebsiteAnalysis;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\Analysis\AnalyzerClient;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use App\Services\CorporateTop\CorporateTopDispatcher;
use App\Services\CorporateTop\CorporateTopService;
use App\Services\CorporateTop\CorporateTopStore;
use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\SiteFirstImpression\OpenAiSiteFirstImpressionProvider;
use App\Services\SiteFirstImpression\PageStructureExtractor;
use App\Services\SiteFirstImpression\SiteFirstImpressionDispatcher;
use App\Services\SiteFirstImpression\SiteFirstImpressionMaterialBuilder;
use App\Services\SiteFirstImpression\SiteFirstImpressionProvider;
use App\Services\SiteFirstImpression\SiteFirstImpressionProviderFactory;
use App\Services\SiteFirstImpression\SiteFirstImpressionService;
use App\Services\SiteFirstImpression\SiteFirstImpressionStore;
use App\Services\SiteFirstImpression\SiteFirstImpressionVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼CX: コーポレートTOPと採用TOPの「第一印象」(求職者目線の良い点・悪い点。社内用・下書き)。構造の取り出し・画面の画像・
 * サーバー側の確認・流れ・スライド・測定コマンド。ページの文字・企業名はすべて人工のもの。AIは呼ばず、固定の出力を返す偽物を使う。
 */
class SiteFirstImpressionTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const CORPORATE_URL = 'https://corp.example.com/';

    private const RECRUIT_URL = 'https://self.example.com/recruit/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['site_first_impression.enabled' => true, 'site_first_impression.images_enabled' => false]);
    }

    // ------------------------------------------------------------------ 部品

    private function corporateHtml(string $recruitHref = 'https://self.example.com/recruit/', string $recruitPlace = 'section'): string
    {
        $recruitNav = $recruitPlace === 'nav' ? '<a href="'.$recruitHref.'">採用情報</a>' : '';
        $recruitItem = $recruitPlace === 'section' ? '<a href="'.$recruitHref.'">採用情報</a>' : '';
        $recruitFooter = $recruitPlace === 'footer' ? '<a href="'.$recruitHref.'">採用情報</a>' : '';

        return <<<HTML
<html><head><title>コーポレート</title><script>var x = "スクリプトの文字";</script></head><body>
<header><nav><a href="/news">ニュース一覧</a><a href="/products">製品</a>{$recruitNav}<a href="/about">会社案内</a></nav></header>
<div class="hero-slider"><img alt="新製品キャンペーン実施中" src="a.jpg"><h2>チームで働くことが楽しくなる</h2></div>
<main>
<h2>PickUp</h2>
<a href="/hr">人的資本経営サイト</a><a href="/csr">サステナビリティ</a>{$recruitItem}<a href="/event">イベント情報</a>
<h2>サポート</h2>
<a href="/support/phone">電話サポート</a>
<h3>よくある質問</h3>
<a href="/faq">FAQ一覧</a>
</main>
<footer><a href="/privacy">プライバシー</a><a href="/sitemap">サイトマップ</a>{$recruitFooter}</footer>
</body></html>
HTML;
    }

    private function recruitHtml(): string
    {
        return <<<'HTML'
<html><body>
<header><nav><a href="/recruit/fresh">新卒採用</a><a href="/recruit/career">中途採用</a></nav></header>
<div id="main-visual"><img alt="私たちと働きませんか" src="b.jpg"></div>
<h2>社員インタビュー</h2>
<a href="/recruit/interview/1">営業部 入社5年目</a>
<footer><a href="/entry">エントリー</a></footer>
</body></html>
HTML;
    }

    /** 自社(比較の自社サイト)に、コーポレートTOPと採用TOPを持たせる。 */
    private function makeSite(bool $withCorporate = true, string $recruitPlace = 'section'): WebsiteAnalysis
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        // 採用TOP(起点)のURLは、自社サイトの正規化したURL。
        $wa->website->forceFill(['url' => self::RECRUIT_URL, 'normalized_url' => self::RECRUIT_URL])->save();
        $this->addHomepage($wa, self::RECRUIT_URL, $this->recruitHtml(), PageType::Recruit);
        if ($withCorporate) {
            app(CorporateTopStore::class)->write($wa->analysis_id, $wa->id, ['status' => 'found', 'url' => self::CORPORATE_URL, 'recruit_url' => self::RECRUIT_URL], $this->corporateHtml(self::RECRUIT_URL, $recruitPlace));
        }

        return $wa;
    }

    /** 確認をすべて通る点(corporate の PickUp の最初の項目を根拠にする)。 */
    private function point(array $override = []): array
    {
        return array_replace([
            'title' => '人的資本の施策が前面にある',
            'body' => '人的資本経営サイトが、PickUpの1番目にあります。働き方の施策を表に出す姿勢が見えます。',
            'evidence' => [['id' => 'corp-sec-1-item-1', 'text' => '人的資本経営サイト']],
        ], $override);
    }

    /** @return array{good: list<array<string, mixed>>, bad: list<array<string, mixed>>} */
    private function goodOutput(): array
    {
        return [
            'good' => [$this->point(), $this->point(['title' => 'ビジュアルがチームの話', 'body' => 'メインビジュアルは「チームで働くことが楽しくなる」と伝えていて、一貫して見えます。', 'evidence' => [['id' => 'corp-hero-2', 'text' => 'チームで働くことが楽しくなる']]])],
            'bad' => [$this->point(['title' => '採用への導線が目立たない', 'body' => '採用情報はセクションの3番目の項目で、グローバルナビには見当たりません(確認できる範囲では)。', 'evidence' => [['id' => 'corp-sec-1-item-3', 'text' => '採用情報']]])],
        ];
    }

    /**
     * @param  array<string, mixed>|\Closure  $output
     */
    private function fakeProvider(array|\Closure $output): object
    {
        $provider = new class($output) implements SiteFirstImpressionProvider
        {
            public int $calls = 0;

            /** @var list<array<string, mixed>> */
            public array $materials = [];

            public function __construct(public readonly array|\Closure $output) {}

            public function name(): string
            {
                return 'fake';
            }

            public function model(): ?string
            {
                return 'fake-model';
            }

            public function promptVersion(): string
            {
                return 'v1';
            }

            public function write(array $material): array
            {
                $this->calls++;
                $this->materials[] = $material;
                $decoded = $this->output instanceof \Closure ? ($this->output)($material) : $this->output;

                return ['decoded' => $decoded, 'usage_input_tokens' => 1000, 'usage_output_tokens' => 200];
            }
        };

        app()->instance(SiteFirstImpressionProviderFactory::class, new class($provider) extends SiteFirstImpressionProviderFactory
        {
            public function __construct(private readonly SiteFirstImpressionProvider $fake) {}

            public function make(): SiteFirstImpressionProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }

    /** 画面の撮影の偽物。撮れた(JPEGを共有ストレージに置く)か、失敗する。 */
    private function fakeAnalyzer(bool $works): object
    {
        $client = new class($works) extends AnalyzerClient
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $urls = [];

            public function __construct(private readonly bool $works) {}

            public function screenshot(int $analysisId, int $websiteAnalysisId, string $url, string $device, bool $fullPage = true): array
            {
                $this->calls++;
                $this->urls[] = $url;
                if (! $this->works) {
                    throw new \RuntimeException('撮影に失敗');
                }
                $image = imagecreatetruecolor(1440, 2000);
                ob_start();
                imagejpeg($image);
                $bytes = (string) ob_get_clean();
                $path = "analyses/{$analysisId}/websites/{$websiteAnalysisId}/screenshots/shot-{$this->calls}.jpg";
                Storage::disk('analysis')->put($path, $bytes);

                return ['storage_path' => $path, 'width' => 1440, 'height' => 2000, 'truncated' => false];
            }
        };
        app()->instance(AnalyzerClient::class, $client);

        return $client;
    }

    private function material(WebsiteAnalysis $wa): array
    {
        return app(SiteFirstImpressionMaterialBuilder::class)->build($wa);
    }

    private function verify(array $point, WebsiteAnalysis $wa): array
    {
        return app(SiteFirstImpressionVerifier::class)->verify($point, $this->material($wa));
    }

    // ------------------------------------------------------------------ CX-1 構造

    public function test_the_structure_is_taken_from_the_top_in_order_with_ids(): void
    {
        $s = app(PageStructureExtractor::class)->extract($this->corporateHtml(), self::CORPORATE_URL, 'corp', self::RECRUIT_URL);

        // グローバルナビ(header / nav のリンク、順番つき)。scriptの文字は含まない。
        $this->assertSame([['id' => 'corp-nav-1', 'text' => 'ニュース一覧'], ['id' => 'corp-nav-2', 'text' => '製品'], ['id' => 'corp-nav-3', 'text' => '会社案内']], $s['nav']);
        // メインビジュアル(class に hero を含む要素の中の、画像のaltと見出し)。
        $this->assertSame([['id' => 'corp-hero-1', 'kind' => 'alt', 'text' => '新製品キャンペーン実施中'], ['id' => 'corp-hero-2', 'kind' => 'heading', 'text' => 'チームで働くことが楽しくなる']], $s['hero']);
        // セクション: 見出し(h1〜h3)ごとに、その下の項目と項目数。メインビジュアルの見出しはセクションにしない。
        $this->assertSame(['corp-sec-1', 'corp-sec-2', 'corp-sec-3'], array_column($s['sections'], 'id'));
        $this->assertSame(['PickUp', 'サポート', 'よくある質問'], array_column($s['sections'], 'heading'));
        $this->assertSame([4, 1, 1], array_column($s['sections'], 'item_count'));
        $this->assertSame([['id' => 'corp-sec-1-item-1', 'text' => '人的資本経営サイト'], ['id' => 'corp-sec-1-item-2', 'text' => 'サステナビリティ'], ['id' => 'corp-sec-1-item-3', 'text' => '採用情報'], ['id' => 'corp-sec-1-item-4', 'text' => 'イベント情報']], $s['sections'][0]['items']);
        // フッター(リンクの文字をまとめて1つ)。
        $this->assertSame(['id' => 'corp-footer', 'text' => 'プライバシー / サイトマップ'], $s['footer']);
        // 上から何%の位置かは、描画しないと分からないので出さない。
        $this->assertNull($s['sections'][0]['position_percent']);
        // 要素の一覧(確認用)と数字。
        $this->assertSame('人的資本経営サイト', $s['elements']['corp-sec-1-item-1']);
        $this->assertContains(3, $s['numbers']);
        $this->assertSame(['nav' => 3, 'hero' => 2, 'sections' => 3, 'items' => 6], $s['counts']);
    }

    public function test_the_link_to_the_recruit_page_is_found_in_the_nav_a_section_the_footer_or_not_at_all(): void
    {
        $extract = fn (string $place, string $href = 'https://self.example.com/recruit/') => app(PageStructureExtractor::class)->extract($this->corporateHtml($href, $place), self::CORPORATE_URL, 'corp', self::RECRUIT_URL)['recruit_link'];

        $this->assertSame(['where' => 'section', 'id' => 'corp-sec-1-item-3', 'ordinal' => 3], $extract('section'));
        $this->assertSame(['where' => 'nav', 'id' => 'corp-nav-3', 'ordinal' => 3], $extract('nav'));
        $this->assertSame(['where' => 'footer', 'id' => 'corp-footer', 'ordinal' => null], $extract('footer'));
        // 起点の配下へのリンクも導線とみなす。別のホストへのリンクは導線ではない。
        $this->assertSame('section', $extract('section', 'https://self.example.com/recruit/fresh')['where']);
        $this->assertSame('none', $extract('section', 'https://other.example.com/recruit/')['where']);
        // 採用TOPでは、導線は探さない。
        $this->assertNull(app(PageStructureExtractor::class)->extract($this->recruitHtml(), self::RECRUIT_URL, 'rec')['recruit_link']);
    }

    public function test_the_material_has_both_pages_and_only_the_recruit_top_when_the_corporate_top_was_not_decided(): void
    {
        $both = $this->material($this->makeSite());
        $this->assertSame(['corporate', 'recruit'], array_keys($both['pages']));
        $this->assertSame(self::CORPORATE_URL, $both['pages']['corporate']['url']);
        $this->assertSame(self::RECRUIT_URL, $both['pages']['recruit']['url']);
        $this->assertSame('rec-nav-1', $both['pages']['recruit']['structure']['nav'][0]['id']);
        $this->assertArrayHasKey('corp-sec-1-item-1', $both['elements']);
        $this->assertArrayHasKey('rec-hero-1', $both['elements'], 'id="main-visual" もメインビジュアルとみなす');

        $recruitOnly = $this->material($this->makeSite(false));
        $this->assertSame(['recruit'], array_keys($recruitOnly['pages']));
    }

    public function test_when_no_page_can_be_read_the_material_is_not_built_and_the_ai_is_not_called(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $fake = $this->fakeProvider($this->goodOutput());

        $this->assertSame('no_page', $this->material($wa)['reason']);
        $result = app(SiteFirstImpressionService::class)->generate($wa);

        $this->assertSame('no_page', $result['skipped']);
        $this->assertNull($result['page']);
        $this->assertSame(0, $fake->calls);
    }

    // ------------------------------------------------------------------ CX-1 画面の画像

    public function test_the_screens_are_captured_and_handed_to_the_ai_but_when_it_fails_the_text_material_goes_on_without_images(): void
    {
        config(['site_first_impression.images_enabled' => true]);
        $wa = $this->makeSite();
        $fake = $this->fakeProvider($this->goodOutput());

        // 撮れたとき: 各ページの「最初に見える範囲(1280×800)」と「ページ全体」を保存し、AIに渡す(精細さはconfig)。
        $analyzer = $this->fakeAnalyzer(true);
        $result = app(SiteFirstImpressionService::class)->generate($wa);

        $this->assertSame(2, $analyzer->calls);
        $this->assertSame([self::CORPORATE_URL, self::RECRUIT_URL], $analyzer->urls);
        $first = Storage::disk('analysis')->get("analyses/{$wa->analysis_id}/site_first_impression/corporate-first.jpg");
        $this->assertSame([1280, 800], array_slice(getimagesizefromstring($first), 0, 2));
        $full = Storage::disk('analysis')->get("analyses/{$wa->analysis_id}/site_first_impression/corporate-full.jpg");
        $this->assertSame([1440, 2000], array_slice(getimagesizefromstring($full), 0, 2));
        $this->assertCount(4, $fake->materials[0]['images']);
        $this->assertSame(['high', 'low', 'high', 'low'], array_column($fake->materials[0]['images'], 'detail'));
        $this->assertSame(4, $result['image_count']);
        $this->assertTrue($result['images']['corporate']['first_view']);

        // 撮れなかったとき: 画像なしで、テキストの材料だけで進める。
        app(SiteFirstImpressionStore::class)->delete($wa->analysis_id);
        $this->fakeAnalyzer(false);
        $result = app(SiteFirstImpressionService::class)->generate($wa);

        $this->assertSame([], $fake->materials[1]['images']);
        $this->assertSame(0, $result['image_count']);
        $this->assertFalse($result['images']['recruit']['first_view']);
        $this->assertNotNull($result['page'], '画像が無くても、構造の根拠があればページは作れる');
    }

    public function test_images_are_not_taken_when_disabled_and_the_screens_are_never_put_into_the_slide(): void
    {
        $wa = $this->makeSite();
        $analyzer = $this->fakeAnalyzer(true);
        $this->fakeProvider($this->goodOutput());

        app(SiteFirstImpressionService::class)->generate($wa);

        $this->assertSame(0, $analyzer->calls, 'images_enabled=false のときは、撮らない');

        $xml = $this->slideBytes(app(AdminComparisonPptxGenerator::class)->generateSiteFirstImpressionSlide($this->pageData()));
        $this->assertStringNotContainsString('<p:pic', $xml);
    }

    // ------------------------------------------------------------------ CX-3 サーバー側の確認

    public function test_a_point_that_meets_every_check_is_used(): void
    {
        $wa = $this->makeSite();

        $this->assertSame([], $this->verify($this->point(), $wa)['reasons']);
        // 数字: 構造の情報(順番・項目数)の数字は使える。「」の言葉は evidence.text と同じなら使える。
        $this->assertTrue($this->verify($this->point(['body' => '採用情報は、セクションの3番目の項目で、項目数は4つです。「採用情報」の文字は小さく見えます。', 'evidence' => [['id' => 'corp-sec-1-item-3', 'text' => '採用情報']]]), $wa)['ok']);
        // 自社の名前は出してよい(法人格を除いた言い換えも)。
        $this->assertTrue($this->verify($this->point(['body' => '自社テストは、人的資本経営サイトを前面に出しています。']), $wa)['ok']);
    }

    /**
     * @return array<string, array{0: callable(array): array, 1: string}>
     */
    public static function brokenPoints(): array
    {
        return [
            'evidenceが無い' => [fn (array $p) => array_replace($p, ['evidence' => []]), 'evidence_missing'],
            '存在しないID' => [fn (array $p) => array_replace($p, ['evidence' => [['id' => 'corp-sec-9-item-9', 'text' => '人的資本経営サイト']]]), 'evidence_id_unknown'],
            'textがその要素の文字に無い' => [fn (array $p) => array_replace($p, ['evidence' => [['id' => 'corp-sec-1-item-1', 'text' => 'サステナビリティ']]]), 'evidence_text_not_in_element'],
            'textが空' => [fn (array $p) => array_replace($p, ['evidence' => [['id' => 'corp-sec-1-item-1', 'text' => '']]]), 'evidence_text_not_in_element'],
            '「」の言葉がevidenceに無い' => [fn (array $p) => array_replace($p, ['body' => '「幻の制度」を紹介しています。']), 'bracketed_word_not_evidence'],
            '『』の言葉がevidenceに無い' => [fn (array $p) => array_replace($p, ['body' => '『幻の制度』を紹介しています。']), 'bracketed_word_not_evidence'],
            '構造に無い数字' => [fn (array $p) => array_replace($p, ['body' => '人的資本経営サイトは、PickUpの77番目にあります。']), 'number_not_in_structure'],
            '構造に無い数字(全角)' => [fn (array $p) => array_replace($p, ['body' => '人的資本経営サイトは、９９件のうちにあります。']), 'number_not_in_structure'],
            '他社の名前' => [fn (array $p) => array_replace($p, ['body' => 'ライバルと違い、人的資本経営サイトを前面に出しています。']), 'company_not_allowed'],
            '禁止の言葉(CUの一覧)' => [fn (array $p) => array_replace($p, ['body' => '人的資本経営サイトを前面に出しており、懸念はありません。']), 'forbidden_word'],
            '禁止の言葉(強すぎる評価)' => [fn (array $p) => array_replace($p, ['body' => '人的資本経営サイトの扱いが最悪です。']), 'forbidden_word'],
            'titleが長い' => [fn (array $p) => array_replace($p, ['title' => str_repeat('あ', 26)]), 'too_long'],
            'bodyが長い' => [fn (array $p) => array_replace($p, ['body' => str_repeat('あ', 121)]), 'too_long'],
            'titleが空' => [fn (array $p) => array_replace($p, ['title' => '']), 'empty_title_or_body'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenPoints')]
    public function test_a_point_that_fails_any_check_is_discarded(callable $break, string $reason): void
    {
        $wa = $this->makeSite();
        $this->makeComparisonWebsiteAnalysis(false, 1, 'ライバル', $wa->analysis);

        $checked = $this->verify($break($this->point()), $wa);

        $this->assertFalse($checked['ok']);
        $this->assertContains($reason, $checked['reasons']);
        $this->assertNull($checked['value']);
    }

    public function test_the_discarded_points_are_dropped_counted_by_reason_and_at_most_four_are_kept(): void
    {
        $wa = $this->makeSite();
        $output = ['good' => array_fill(0, 6, $this->point()), 'bad' => [$this->point(), $this->point(['evidence' => []]), $this->point(['body' => '最悪の見え方です。'])]];

        $checked = app(SiteFirstImpressionVerifier::class)->verifyAll($output, $this->material($wa));

        $this->assertCount(4, $checked['good']);
        $this->assertCount(1, $checked['bad']);
        $this->assertSame(['evidence_missing' => 1, 'forbidden_word' => 1], $checked['discarded']);
    }

    public function test_when_good_or_bad_is_left_with_no_point_the_page_is_not_made(): void
    {
        $wa = $this->makeSite();
        $fake = $this->fakeProvider(['good' => $this->goodOutput()['good'], 'bad' => [$this->point(['evidence' => []])]]);

        $result = app(SiteFirstImpressionService::class)->generate($wa);

        $this->assertSame(1, $fake->calls);
        $this->assertNull($result['page']);
        $this->assertSame('not_enough_points', $result['skipped']);
        $this->assertSame(['good' => 2, 'bad' => 0], $result['kept']);
        $this->assertSame(['evidence_missing' => 1], $result['discarded']);
    }

    public function test_the_saved_page_has_only_the_verified_points_and_the_sources(): void
    {
        $wa = $this->makeSite();
        $fake = $this->fakeProvider($this->goodOutput());

        $result = app(SiteFirstImpressionService::class)->generate($wa);

        $this->assertSame(1, $fake->calls);
        $this->assertSame(2, $result['kept']['good']);
        $this->assertSame('人的資本の施策が前面にある', $result['page']['good'][0]['title']);
        $this->assertSame('corp-sec-1-item-1', $result['page']['good'][0]['evidence'][0]['id']);
        $this->assertSame([['label' => 'コーポレートTOP', 'url' => self::CORPORATE_URL], ['label' => '採用TOP', 'url' => self::RECRUIT_URL]], $result['page']['sources']);
        $this->assertSame(1, $result['ai_calls']);
        $this->assertSame(1000, $result['usage_input_tokens']);
        Storage::disk('analysis')->assertExists("analyses/{$wa->analysis_id}/site_first_impression.json");

        // 結果のファイルがあれば、AIは呼び直さない。
        app(SiteFirstImpressionService::class)->generate($wa);
        $this->assertSame(1, $fake->calls);
    }

    public function test_the_prompt_has_the_viewpoints_and_the_structure_but_no_example_sentence(): void
    {
        $builder = app(SiteFirstImpressionMaterialBuilder::class);
        $provider = new OpenAiSiteFirstImpressionProvider;
        $prompt = $provider->buildPrompt($builder->visible($this->material($this->makeSite())) + ['images' => []]);

        // 構造: ID・文字・項目数・採用への導線。
        $this->assertStringContainsString('corp-nav-1: ニュース一覧', $prompt);
        $this->assertStringContainsString('corp-hero-1 [alt]: 新製品キャンペーン実施中', $prompt);
        $this->assertStringContainsString('項目数: 4', $prompt);
        $this->assertStringContainsString('セクション内にある(corp-sec-1-item-3、項目3番目)', $prompt);
        $this->assertStringContainsString('rec-nav-1: 新卒採用', $prompt);
        // 見る観点・規則。
        foreach (['何を大事にしている会社か', '採用への導線', '働く人の顔', '制度・働き方', 'メインビジュアル', '求職者に関係ない情報'] as $viewpoint) {
            $this->assertStringContainsString($viewpoint, $prompt);
        }
        $this->assertStringContainsString('確認できる範囲では', $prompt);
        $this->assertStringContainsString('画像はありません', $prompt);
        foreach ((array) config('site_first_impression.extra_forbidden_words') as $word) {
            $this->assertStringContainsString((string) $word, $prompt);
        }
        // 文の例は書かない(依頼CWの反省)。依頼書の所見の言葉も、プロンプトに入れない。
        foreach (['チームワーク', '4つ中3番目', 'グローバルナビにない', '買ってください', 'カスタマーサポート'] as $example) {
            $this->assertStringNotContainsString($example, $prompt);
        }
        // 画像があるときは、画像を先に渡す。
        $content = $provider->buildContent($builder->visible($this->material($this->makeSite())) + ['images' => [['label' => 'コーポレートTOPの最初に見える範囲', 'mime' => 'image/jpeg', 'base64' => 'AAAA', 'detail' => 'high']]]);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertSame('high', $content[1]['image_url']['detail']);
        $this->assertSame('text', end($content)['type']);
        $this->assertSame(['good', 'bad'], $provider->buildResponseSchema()['required']);
    }

    public function test_the_log_has_only_counts_and_reason_codes(): void
    {
        $wa = $this->makeSite();
        $this->fakeProvider(['good' => $this->goodOutput()['good'], 'bad' => [$this->point(['body' => '最悪の見え方です。'])]]);
        Log::spy();

        app(SiteFirstImpressionService::class)->generate($wa);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            if ($message !== 'Site first impression saved') {
                return false;
            }
            $json = json_encode($context, JSON_UNESCAPED_UNICODE);

            return str_contains($json, 'forbidden_word') && ! str_contains($json, '最悪') && ! str_contains($json, '人的資本') && ! str_contains($json, 'example.com');
        })->once();
    }

    // ------------------------------------------------------------------ CX-4 スライド

    /** @return array{company_name: string, good: list<array{title: string, body: string}>, bad: list<array{title: string, body: string}>, note: string} */
    private function pageData(int $good = 2, int $bad = 2): array
    {
        $points = fn (string $prefix, int $n) => array_map(fn (int $i) => ['title' => "{$prefix}の見出し{$i}", 'body' => "{$prefix}の本文{$i}です。".str_repeat('文字がたくさん入ります。', 8)], range(1, $n));

        return ['company_name' => '自社テスト', 'good' => $points('良い', $good), 'bad' => $points('悪い', $bad), 'note' => 'コーポレートTOP（https://corp.example.com/）と採用TOP（https://self.example.com/recruit/）を、2026年10月15日時点で AI が見て作成した所見です。'];
    }

    private function slideBytes(string $pptx): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sfi');
        file_put_contents($tmp, $pptx);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    public function test_the_slide_has_the_red_band_the_title_the_two_columns_and_the_note(): void
    {
        $xml = $this->slideBytes(app(AdminComparisonPptxGenerator::class)->generateSiteFirstImpressionSlide($this->pageData()));

        $this->assertStringContainsString('自社テスト：サイトの第一印象（求職者目線）', $xml);
        $this->assertStringContainsString((string) config('admin_comparison_pptx.site_first_impression_band_text'), $xml);
        $this->assertStringContainsString('C62828', $xml, '素材ページと同じ赤い帯');
        $this->assertStringContainsString('良いイメージ', $xml);
        $this->assertStringContainsString('悪いイメージ', $xml);
        $this->assertStringContainsString('良いの見出し1', $xml);
        $this->assertStringContainsString('悪いの本文2です。', $xml);
        $this->assertStringContainsString('コーポレートTOP（https://corp.example.com/）と採用TOP', $xml);
        $this->assertStringNotContainsString('ほか', $xml);
    }

    public function test_when_the_points_do_not_fit_the_last_ones_are_dropped_with_the_count_of_the_rest(): void
    {
        $xml = $this->slideBytes(app(AdminComparisonPptxGenerator::class)->generateSiteFirstImpressionSlide($this->pageData(12, 12)));

        $this->assertMatchesRegularExpression('/ほか\d+件/', $xml);
        $this->assertStringContainsString('良いの見出し1', $xml, '前の点は残る');
        $this->assertStringNotContainsString('良いの見出し12', $xml);
    }

    public function test_the_data_for_the_slide_comes_only_from_the_saved_file_with_the_urls_and_the_date_in_the_note(): void
    {
        $wa = $this->makeSite();
        $this->fakeProvider($this->goodOutput());
        app(SiteFirstImpressionService::class)->generate($wa);
        Http::preventStrayRequests();
        Http::fake();
        $this->fakeProvider(fn () => throw new \RuntimeException('呼ばれてはいけない'));

        $page = app(AdminComparisonPptxDataBuilder::class)->buildSiteFirstImpressionPage($wa->analysis);

        $this->assertSame(2, count($page['good']));
        $this->assertSame('コーポレートTOP（'.self::CORPORATE_URL.'）と採用TOP（'.self::RECRUIT_URL.'）を、'.now()->format('Y年n月j日').'時点で AI が見て作成した所見です。', $page['note']);
        Http::assertNothingSent();

        // コーポレートTOPが無いときは、採用TOPだけの注記。
        $single = $this->makeSite(false);
        $this->fakeProvider($this->goodOutput());
        app(SiteFirstImpressionStore::class)->write($single->analysis_id, ['status' => 'done', 'generated_at' => now()->toIso8601String(), 'page' => ['company_name' => 'x', 'good' => [['title' => 'a', 'body' => 'b']], 'bad' => [['title' => 'c', 'body' => 'd']], 'sources' => [['label' => '採用TOP', 'url' => self::RECRUIT_URL]]]]);
        $this->assertStringStartsWith('採用TOP（'.self::RECRUIT_URL.'）を、', app(AdminComparisonPptxDataBuilder::class)->buildSiteFirstImpressionPage($single->analysis)['note']);
    }

    // ------------------------------------------------------------------ CX-5 いつ実行するか

    public function test_when_disabled_no_job_no_wait_no_ai_and_no_page(): void
    {
        Queue::fake();
        config(['site_first_impression.enabled' => false, 'admin_comparison_pptx.corporate_top_enabled' => false]);
        $wa = $this->makeSite();
        $fake = $this->fakeProvider($this->goodOutput());

        app(CorporateTopDispatcher::class)->dispatchFor($wa->analysis_id, $wa->id);
        Queue::assertNotPushed(GenerateSiteFirstImpressionJob::class);

        $this->finishAllJobs($wa);
        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class);

        $this->assertSame(0, $fake->calls);
        $this->assertNull(app(AdminComparisonPptxDataBuilder::class)->buildSiteFirstImpressionPage($wa->analysis));
    }

    public function test_the_default_of_enabled_is_false(): void
    {
        $this->assertFalse((require base_path('config/site_first_impression.php'))['enabled']);
        $this->assertStringContainsString("env('SITE_FIRST_IMPRESSION_ENABLED', false)", (string) file_get_contents(base_path('config/site_first_impression.php')));
    }

    public function test_the_job_is_started_after_the_corporate_top_fetch_finishes_only_for_the_primary_site(): void
    {
        Queue::fake();
        $wa = $this->makeSite(false);   // コーポレートTOPが未取得(取得ジョブを出す場合)

        // 取得ジョブを出す時点では、第一印象のジョブはまだ出さない。
        app(CorporateTopDispatcher::class)->dispatchFor($wa->analysis_id, $wa->id);
        Queue::assertPushed(FetchCorporateTopJob::class, 1);
        Queue::assertNotPushed(GenerateSiteFirstImpressionJob::class);

        // 取得が終わった(失敗でも)あとに出す。
        app()->instance(CorporateTopService::class, new class extends CorporateTopService
        {
            public function __construct() {}

            public function run(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('取得に失敗');
            }
        });
        (new FetchCorporateTopJob($wa->id))->handle(app(CorporateTopService::class));
        Queue::assertPushed(GenerateSiteFirstImpressionJob::class, 1);
        Queue::assertPushedOn('ai', GenerateSiteFirstImpressionJob::class);

        // 競合(自社でないサイト)には出さない。
        $competitor = $this->makeComparisonWebsiteAnalysis(false, 1, 'rival', $wa->analysis);
        app(SiteFirstImpressionDispatcher::class)->dispatchFor($wa->analysis_id, $competitor->id);
        Queue::assertPushed(GenerateSiteFirstImpressionJob::class, 1);
    }

    public function test_when_the_corporate_top_is_disabled_the_job_is_started_directly(): void
    {
        Queue::fake();
        config(['admin_comparison_pptx.corporate_top_enabled' => false]);
        $wa = $this->makeSite();

        app(CorporateTopDispatcher::class)->dispatchFor($wa->analysis_id, $wa->id);

        Queue::assertNotPushed(FetchCorporateTopJob::class);
        Queue::assertPushed(GenerateSiteFirstImpressionJob::class, 1);
    }

    private function finishAllJobs(WebsiteAnalysis $wa, ?int $brandWheelMinutesAgo = null): void
    {
        app(AnalysisPipeline::class)->registerWebsiteJobPlaceholders($wa->fresh());
        AnalysisJob::query()->where('website_analysis_id', $wa->id)->update(['status' => AnalysisJobStatus::Completed->value, 'completed_at' => now()]);
        if ($brandWheelMinutesAgo !== null) {
            AnalysisJob::query()->where('website_analysis_id', $wa->id)->where('job_type', JobType::GenerateBrandWheelAnalysis)->update(['completed_at' => now()->subMinutes($brandWheelMinutesAgo)]);
        }
    }

    public function test_the_comparison_waits_for_the_job_and_finishes_after_it_even_when_the_job_fails(): void
    {
        Queue::fake();
        $wa = $this->makeSite();
        app()->instance(SiteFirstImpressionService::class, new class extends SiteFirstImpressionService
        {
            public function __construct() {}

            public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
            {
                throw new \RuntimeException('想定外');
            }

            public function recordFailure(WebsiteAnalysis $websiteAnalysis, string $reason): void
            {
                app(SiteFirstImpressionStore::class)->write((int) $websiteAnalysis->analysis_id, ['status' => 'done', 'page' => null, 'job_failed' => $reason]);
            }
        });
        $this->finishAllJobs($wa);

        // 待っている間は完了させない。
        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertNotPushed(FinalizeWebsiteAnalysisJob::class);

        (new GenerateSiteFirstImpressionJob($wa->id))->handle(app(SiteFirstImpressionService::class), app(SiteFirstImpressionDispatcher::class));

        $this->assertTrue(app(SiteFirstImpressionStore::class)->exists($wa->analysis_id));
        $this->assertNull(app(SiteFirstImpressionStore::class)->read($wa->analysis_id)['page']);
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class);
        // ページが無いだけ(スライドに入らない)。
        $this->assertNull(app(AdminComparisonPptxDataBuilder::class)->buildSiteFirstImpressionPage($wa->analysis));
    }

    public function test_an_ai_error_is_recorded_without_a_page_and_a_job_killed_by_the_worker_also_unblocks_the_comparison(): void
    {
        Queue::fake();
        $wa = $this->makeSite();
        $this->fakeProvider(fn () => throw new ComparisonCommentaryException('AI_RATE_LIMITED', 'レート制限'));
        $this->finishAllJobs($wa);

        (new GenerateSiteFirstImpressionJob($wa->id))->handle(app(SiteFirstImpressionService::class), app(SiteFirstImpressionDispatcher::class));

        $stored = app(SiteFirstImpressionStore::class)->read($wa->analysis_id);
        $this->assertSame('AI_RATE_LIMITED', $stored['skipped']);
        $this->assertNull($stored['page']);
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class);

        Queue::fake();
        app(SiteFirstImpressionStore::class)->delete($wa->analysis_id);
        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);
        Queue::assertNotPushed(FinalizeWebsiteAnalysisJob::class);
        (new GenerateSiteFirstImpressionJob($wa->id))->failed(new \RuntimeException('timeout'));
        $this->assertSame('RuntimeException', app(SiteFirstImpressionStore::class)->read($wa->analysis_id)['job_failed']);
        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class);
    }

    public function test_the_wait_ends_after_the_configured_minutes_even_if_the_job_never_comes(): void
    {
        Queue::fake();
        config(['site_first_impression.wait_max_minutes' => 10]);
        $wa = $this->makeSite();
        $this->finishAllJobs($wa, 11);

        app(AnalysisPipeline::class)->maybeFinalizeWebsiteAnalysis($wa->id);

        Queue::assertPushed(FinalizeWebsiteAnalysisJob::class);
    }

    public function test_the_result_file_and_the_images_are_deleted_together_with_the_company_data(): void
    {
        config(['site_first_impression.images_enabled' => true]);
        $wa = $this->makeSite();
        $this->fakeAnalyzer(true);
        $this->fakeProvider($this->goodOutput());
        app(SiteFirstImpressionService::class)->generate($wa);
        $file = "analyses/{$wa->analysis_id}/site_first_impression.json";
        $image = "analyses/{$wa->analysis_id}/site_first_impression/corporate-first.jpg";
        Storage::disk('analysis')->assertExists($file);
        Storage::disk('analysis')->assertExists($image);

        $wa->analysis->update(['status' => AnalysisStatus::Completed]);
        $company = LeadCompany::query()->findOrFail($wa->analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing($file);
        Storage::disk('analysis')->assertMissing($image);
    }

    // ------------------------------------------------------------------ 測定コマンド

    public function test_the_measure_command_never_calls_the_ai_beyond_the_approved_number_of_calls(): void
    {
        $wa = $this->makeSite();
        $fake = $this->fakeProvider($this->goodOutput());
        $args = ['--wa' => "自社:{$wa->id}"];

        // 既定(0)は何も呼ばない。--live を付けても、承認が無ければ呼ばずに止まる。
        $this->artisan('site-first-impression:measure', $args + ['--live' => true])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --repeat は同じ材料でもう1回呼ぶので、1回の承認では足りない。
        $this->artisan('site-first-impression:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 1])->assertFailed();
        $this->assertSame(0, $fake->calls);

        // --live を付けなければ、承認があってもAIは呼ばない(構造と見込み回数だけ)。
        $this->artisan('site-first-impression:measure', $args + ['--max-ai-calls' => 5])->assertSuccessful();
        $this->assertSame(0, $fake->calls);

        // 承認された回数ぶんだけは呼ぶ。測定は結果のファイルを作らない。
        $this->artisan('site-first-impression:measure', $args + ['--live' => true, '--repeat' => true, '--max-ai-calls' => 2])->assertSuccessful();
        $this->assertSame(2, $fake->calls);
        Storage::disk('analysis')->assertMissing("analyses/{$wa->analysis_id}/site_first_impression.json");
    }
}
