<?php

namespace Tests\Feature\Admin;

use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageInsight\TopMessageInsightService;
use App\Services\TopMessageInsight\TopMessageInsightStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestPptxDecks;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CQ-4: 営業資料へ差し込む「トップメッセージと人事制度」のページ(1社1ページ、文字だけ)。
 */
class AdminComparisonPptxTopMessageTest extends TestCase
{
    use MakesTestPptxDecks;
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    /**
     * 自社(is_primary)と、競合(display_order順)の比較を作る。
     *
     * @param  list<string>  $competitorNames  display_order順
     * @return array{analysis: Analysis, self: WebsiteAnalysis, competitors: list<WebsiteAnalysis>}
     */
    private function makeComparison(array $competitorNames = ['競合A', '競合B']): array
    {
        $self = $this->makeComparisonWebsiteAnalysis(true, 0, 'self');
        $analysis = Analysis::query()->findOrFail($self->analysis_id);
        $competitors = [];
        foreach ($competitorNames as $i => $name) {
            $competitors[] = $this->makeComparisonWebsiteAnalysis(false, $i + 1, $name, $analysis);
        }
        foreach ([$self, ...$competitors] as $wa) {
            BrandWheelAnalysisResult::factory()->create(['analysis_id' => $analysis->id, 'website_analysis_id' => $wa->id, 'status' => 'success', 'axes' => []]);
        }

        return ['analysis' => $analysis, 'self' => $self, 'competitors' => $competitors];
    }

    private function attachDeck(Analysis $analysis): void
    {
        $path = "attachments/{$analysis->id}/test.pptx";
        Storage::disk('analysis')->put($path, $this->makeMinimalPptxBytes(['内容1', '参照元']));
        AnalysisAttachment::factory()->create([
            'analysis_id' => $analysis->id,
            'original_filename' => '営業資料.pptx',
            'storage_path' => $path,
            'extension' => 'pptx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createdResult(string $quote = self::MSG_QUOTE): array
    {
        return [
            'status' => 'created',
            'reason' => null,
            'quote' => $quote,
            'keywords' => [
                ['keyword' => 'ものづくりは人づくり', 'programs' => [
                    ['name' => '1カ月の新人研修', 'detail' => '入社後に実施', 'source_url' => 'https://e.com/t', 'source_title' => '研修制度'],
                    ['name' => 'サポーター制度', 'detail' => '先輩がつく', 'source_url' => 'https://e.com/t', 'source_title' => '研修制度'],
                ]],
                ['keyword' => '一社如一家', 'programs' => [
                    ['name' => '家賃補助', 'detail' => '最大80%', 'source_url' => 'https://e.com/w', 'source_title' => '福利厚生'],
                ]],
            ],
            'sources' => [['url' => 'https://e.com/m', 'title' => '代表メッセージ'], ['url' => 'https://e.com/t', 'title' => '研修制度'], ['url' => 'https://e.com/w', 'title' => '福利厚生']],
            'discarded' => [],
        ];
    }

    /**
     * @return list<string> 差し込み後のデッキの、表示順の各スライドのテキスト
     */
    private function download(Analysis $analysis): array
    {
        $response = $this->withSession(['admin_authenticated' => true])
            ->get(route('admin.analyses.comparison-report.pptx-insert', $analysis->id, false));
        $response->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);

        $presentation = (string) $zip->getFromName('ppt/presentation.xml');
        $rels = (string) $zip->getFromName('ppt/_rels/presentation.xml.rels');
        preg_match_all('/<p:sldId\s+id="\d+"\s+r:id="(rId\d+)"/', $presentation, $ids);
        $targets = [];
        preg_match_all('/<Relationship\s+Id="(rId\d+)"[^>]*Target="([^"]+)"/', $rels, $relMatches, PREG_SET_ORDER);
        foreach ($relMatches as $m) {
            $targets[$m[1]] = $m[2];
        }

        $texts = [];
        foreach ($ids[1] as $rId) {
            $xml = (string) $zip->getFromName('ppt/'.$targets[$rId]);
            preg_match_all('/<a:t>(.*?)<\/a:t>/s', $xml, $t);
            $texts[] = html_entity_decode(implode(' ', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $this->assertDoesNotMatchRegularExpression('/\br:(embed|link)="/', $xml, 'スライドが画像などの外部パーツを参照していない');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $this->assertSame([], array_values(array_filter($names, fn (string $n) => str_starts_with($n, 'ppt/media/'))), '画像のパーツが無い');

        $zip->close();
        @unlink($tmp);

        return $texts;
    }

    public function test_pages_follow_missing_items_in_the_order_self_then_competitors_and_skip_companies_without_a_page(): void
    {
        $c = $this->makeComparison(['競合A', '競合B', '競合C']);
        $this->attachDeck($c['analysis']);
        $store = app(TopMessageInsightStore::class);
        // 競合Bは作られなかった。競合Cの結果を先に書いても、並びは入力順(A→C)のまま。
        $store->write($c['competitors'][2]->analysis_id, $c['competitors'][2]->id, $this->createdResult('競合Cのメッセージです。'));
        $store->write($c['competitors'][1]->analysis_id, $c['competitors'][1]->id, ['status' => 'not_created', 'reason' => 'no_message_page']);
        $store->write($c['competitors'][0]->analysis_id, $c['competitors'][0]->id, $this->createdResult('競合Aのメッセージです。'));
        $store->write($c['self']->analysis_id, $c['self']->id, $this->createdResult('自社のメッセージです。'));

        $slides = $this->download($c['analysis']);

        // 説明, 比較, 足りないもの, [自社, 競合A, 競合C], 求職者が知りたい情報, 階層図 + 元の内容1, 参照元
        $this->assertCount(2 + 5 + 3 + 0, $slides);
        $titleSlides = array_values(array_filter($slides, fn (string $t) => str_contains($t, 'トップメッセージと人事制度')));
        $this->assertCount(3, $titleSlides);
        $this->assertStringContainsString('：トップメッセージと人事制度', $titleSlides[0]);
        $this->assertStringContainsString('自社のメッセージです。', $titleSlides[0]);
        $this->assertStringContainsString('競合Aのメッセージです。', $titleSlides[1]);
        $this->assertStringContainsString('競合Cのメッセージです。', $titleSlides[2]);

        $missingIndex = array_key_first(array_filter($slides, fn (string $t) => str_contains($t, '足りないもの')));
        $surveyIndex = array_key_first(array_filter($slides, fn (string $t) => str_contains($t, '求職者が知りたい情報と、自社サイト')));
        $titleIndexes = array_keys(array_filter($slides, fn (string $t) => str_contains($t, 'トップメッセージと人事制度')));
        $this->assertSame([$missingIndex + 1, $missingIndex + 2, $missingIndex + 3], $titleIndexes, '「足りないもの」の次に連続して並ぶ');
        $this->assertSame($missingIndex + 4, $surveyIndex);
    }

    public function test_a_company_without_a_page_is_named_in_one_note_line_on_the_comparison_page(): void
    {
        $c = $this->makeComparison(['競合A', '競合B']);
        $this->attachDeck($c['analysis']);
        $store = app(TopMessageInsightStore::class);
        $store->write($c['self']->analysis_id, $c['self']->id, $this->createdResult());
        $store->write($c['competitors'][0]->analysis_id, $c['competitors'][0]->id, ['status' => 'not_created', 'reason' => 'no_message_page']);
        $store->write($c['competitors'][1]->analysis_id, $c['competitors'][1]->id, ['status' => 'not_created', 'reason' => 'too_few_programs']);

        $slides = $this->download($c['analysis']);

        $comparison = current(array_filter($slides, fn (string $t) => str_contains($t, 'ブランド・ホイール比較')));
        $this->assertStringContainsString('トップメッセージのページを確認できなかったため、競合A、競合Bの分は作成していません。', $comparison);
        $this->assertCount(1, array_filter($slides, fn (string $t) => str_contains($t, 'トップメッセージと人事制度')), '自社の1ページだけ');
    }

    public function test_no_note_and_no_page_when_no_result_exists_yet(): void
    {
        $c = $this->makeComparison();
        $this->attachDeck($c['analysis']);

        $slides = $this->download($c['analysis']);

        $this->assertCount(7, $slides, '元の2枚 + 従来の差し込み5枚(説明・比較・足りないもの・求職者が知りたい情報・階層図)のまま');
        $this->assertStringNotContainsString('作成していません', implode(' ', $slides));
    }

    public function test_downloading_the_pptx_twice_never_calls_the_ai_again(): void
    {
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedStandardCompany($c['self']);
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());

        // 比較の流れの中でジョブが1回だけAIを呼ぶ。
        app(TopMessageInsightService::class)->generate($c['self']);
        $this->assertSame(1, $fake->calls);

        $first = $this->download($c['analysis']);
        $second = $this->download($c['analysis']);

        $this->assertSame(1, $fake->calls, 'PPTXを2回作っても、AIの呼び出しは1回のまま');
        $this->assertSame($first, $second);
        $this->assertCount(1, array_filter($first, fn (string $t) => str_contains($t, 'トップメッセージと人事制度')));
    }

    public function test_the_slide_has_no_pictures_and_shows_only_verified_text(): void
    {
        $bytes = app(AdminComparisonPptxGenerator::class)->generateTopMessageSlide([
            'company_name' => 'テスト株式会社',
            'quote' => self::MSG_QUOTE,
            'keywords' => [
                ['keyword' => 'ものづくりは人づくり', 'programs' => [['name' => '1カ月の新人研修', 'detail' => '入社後に実施'], ['name' => 'サポーター制度', 'detail' => '先輩がつく']]],
                ['keyword' => '一社如一家', 'programs' => [['name' => '家賃補助', 'detail' => '最大80%']]],
            ],
            'sources' => ['代表メッセージ', '研修制度', '福利厚生'],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'tm');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        $this->assertSame([], array_filter($names, fn (string $n) => str_starts_with($n, 'ppt/media/')));
        $this->assertStringNotContainsString('<p:pic', $xml);
        $this->assertDoesNotMatchRegularExpression('/\br:(id|embed|link)="/', $xml);
        foreach (['テスト株式会社：トップメッセージと人事制度', 'TOP MESSAGE', self::MSG_QUOTE, '1カ月の新人研修', '最大80%', '出典：代表メッセージ、研修制度、福利厚生。', 'キーワードと制度の対応づけは、サイトの記述をもとに自動で整理したものです。', 'トップメッセージのキーワードと、関連すると思われる制度を、サイトの記述から整理しています。'] as $expected) {
            $this->assertStringContainsString(htmlspecialchars($expected, ENT_XML1), $xml, $expected);
        }
    }

    public function test_text_that_does_not_fit_is_shrunk_and_then_a_program_is_dropped(): void
    {
        $long = fn (int $n) => ['name' => str_repeat('長い名前', 9).'その'.$n, 'detail' => str_repeat('説明', 15)];
        $page = [
            'company_name' => 'テスト株式会社',
            'quote' => self::MSG_QUOTE,
            'keywords' => array_map(
                fn (int $k) => ['keyword' => "キーワード{$k}", 'programs' => [$long(1), $long(2), $long(3)]],
                [1, 2, 3, 4],
            ),
            'sources' => ['A'],
        ];
        $short = $page;
        $short['keywords'] = array_map(
            fn (int $k) => ['keyword' => "キーワード{$k}", 'programs' => [['name' => "短い{$k}の1", 'detail' => '短い説明'], ['name' => "短い{$k}の2", 'detail' => '短い説明'], ['name' => "短い{$k}の3", 'detail' => '短い説明']]],
            [1, 2, 3, 4],
        );

        $longXml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageSlide($page));
        $shortXml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageSlide($short));

        // 収まる短い文章は、4段とも3つの制度が残る。
        $this->assertSame(4, substr_count($shortXml, 'の3'));
        // 小さくしても収まらない長い文章は、制度を減らす(減らしたあとの文字は省略しない)。
        $this->assertSame(4, substr_count($longXml, 'その1'));
        $this->assertSame(0, substr_count($longXml, 'その3'));
    }

    private function slideXml(string $pptxBytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tm');
        file_put_contents($tmp, $pptxBytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }
}
