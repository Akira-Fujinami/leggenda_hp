<?php

namespace Tests\Feature\TopMessageDraft;

use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\BrandWheelAnalysisResult;
use App\Models\WebsiteAnalysis;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessageInsight\TopMessagePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTestPptxDecks;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CS: トップメッセージと制度の「素材ページ」(社内用・下書き)。AIは使わず、保存済みのHTMLから
 * 機械的に抜き出すだけ。本文はすべて人工のもの(実在のサイトの文章ではない)。
 */
class TopMessageDraftTest extends TestCase
{
    use MakesTestPptxDecks;
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    // ---- 抜き出し(サービス) ----

    private function messageHtml(string $main, string $outside = ''): string
    {
        return '<html><head><title>代表メッセージ | 例社</title></head><body><header><h1>HEADER-HEADING</h1></header><nav><h2>NAV-HEADING</h2><ul><li>NAV-ITEM-LONG-ENOUGH-TO-BE-A-SENTENCE-IN-A-LIST</li></ul></nav>'
            .'<main>'.$main.'</main>'.$outside.'<footer><h2>FOOTER-HEADING</h2><p>FOOTER-PARAGRAPH-LONG-ENOUGH-TO-BE-A-SENTENCE-HERE</p></footer></body></html>';
    }

    public function test_message_candidates_are_the_text_in_the_html_unchanged(): void
    {
        $sentence = '私たちは、お客様と社員の成長を第一に考え、ものづくりを通じて社会に貢献します。';
        $quoted = '「ものづくりは人づくり」だと私たちは強く信じています、これからも変わりません。';
        $found = (new TopMessageDraftExtractor)->messageCandidates($this->messageHtml(
            '<h1>代表メッセージ</h1><p>代表取締役社長 山田　太郎</p><p>'.$sentence.'</p><p>'.$quoted.'</p>',
        ));

        $this->assertContains($sentence, $found['lines'], '文は言い換えず、そのまま');
        $this->assertContains($quoted, $found['lines']);
        $this->assertContains('代表取締役社長 山田 太郎', $found['lines'], '話し手の行(空白の畳み込みだけ)');
        $this->assertContains('代表メッセージ', $found['lines'], '見出し');
        foreach ($found['lines'] as $line) {
            $this->assertStringContainsString($line, preg_replace('/\s+/u', ' ', strip_tags($this->messageHtml('<h1>代表メッセージ</h1><p>代表取締役社長 山田　太郎</p><p>'.$sentence.'</p><p>'.$quoted.'</p>'))), '候補はすべてHTMLの文字そのまま');
        }
    }

    public function test_a_sentence_over_the_limit_is_cut_with_an_ellipsis_at_the_end_and_nothing_is_skipped_in_the_middle(): void
    {
        $max = (int) config('top_message_draft.message_sentence_max_chars');
        $long = str_repeat('長い文章です、', 20).'終わり。';
        $found = (new TopMessageDraftExtractor)->messageCandidates($this->messageHtml('<p>'.$long.'</p>'));

        $this->assertCount(1, $found['lines']);
        $this->assertStringEndsWith('…', $found['lines'][0]);
        $this->assertSame($max + 1, mb_strlen($found['lines'][0]));
        $this->assertStringStartsWith(rtrim(mb_substr($found['lines'][0], 0, -1)), $long, '先頭から連続した部分(途中を省かない)');
    }

    public function test_headings_and_text_in_the_header_footer_and_nav_are_not_candidates(): void
    {
        $found = (new TopMessageDraftExtractor)->messageCandidates($this->messageHtml(
            '<h1>本文の見出し</h1><p>私たちは、お客様と社員の成長を第一に考え、ものづくりを通じて社会に貢献します。</p>',
        ));
        $joined = implode(' ', $found['lines']);

        $this->assertStringContainsString('本文の見出し', $joined);
        foreach (['HEADER-HEADING', 'NAV-HEADING', 'NAV-ITEM', 'FOOTER-HEADING', 'FOOTER-PARAGRAPH'] as $outside) {
            $this->assertStringNotContainsString($outside, $joined);
        }

        $programs = (new TopMessageDraftExtractor)->programCandidates([new TopMessagePage('https://e.example.com/t', '研修制度', '本文', true, 2, $this->messageHtml('<h2>本文の制度</h2><p>説明です。</p>'))]);
        $this->assertSame(['本文の制度'], array_column($programs, 'name'), 'ヘッダー・フッター・ナビの見出しは制度の候補にならない');
    }

    public function test_the_message_list_is_capped_and_the_total_is_returned(): void
    {
        config(['top_message_draft.message_total_max' => 3]);
        $paragraphs = '';
        for ($i = 1; $i <= 8; $i++) {
            $paragraphs .= "<p>これは{$i}番目の、メッセージの本文として十分な長さのある文章です。</p>";
        }
        $found = (new TopMessageDraftExtractor)->messageCandidates($this->messageHtml($paragraphs));

        $this->assertCount(3, $found['lines']);
        $this->assertSame(8, $found['total']);
    }

    public function test_generic_headings_are_not_program_candidates_and_a_name_appears_once_across_pages(): void
    {
        $page = fn (string $url, string $body) => new TopMessagePage($url, 'ページ', '本文', true, 2, '<html><body><main>'.$body.'</main></body></html>');
        $rows = (new TopMessageDraftExtractor)->programCandidates([
            $page('https://e.example.com/a', '<h2>お問い合わせ</h2><h2>ENTRY</h2><h3>MENU</h3><h3>関連リンク</h3><h3>よくある質問</h3><h2>新人研修</h2><p>説明A</p><h2>家賃補助</h2><p>説明B</p>'),
            $page('https://e.example.com/b', '<h2>新人研修</h2><p>別の説明</p><h2>資格取得支援</h2><p>説明C</p>'),
        ]);

        $this->assertSame(['新人研修', '家賃補助', '資格取得支援'], array_column($rows, 'name'));
        $this->assertSame('説明A', $rows[0]['excerpt'], '同じ名前は最初のものだけ');
        $this->assertSame('https://e.example.com/a', $rows[0]['source_url']);
    }

    public function test_the_excerpt_keeps_numbers_and_comes_from_the_text_right_after_the_candidate(): void
    {
        $rows = (new TopMessageDraftExtractor)->programCandidates([new TopMessagePage('https://e.example.com/a', '福利厚生', '本文', true, 2,
            '<html><body><main><h2>家賃補助</h2><p>最大80%を、入社から36カ月まで支給します。</p><dl><dt>年間休日</dt><dd>120日(2024年度)</dd></dl><table><tr><th>資格取得</th><td>月額15,000円</td></tr></table></main></body></html>',
        )]);

        $byName = array_column($rows, 'excerpt', 'name');
        $this->assertSame('最大80%を、入社から36カ月まで支給します。', $byName['家賃補助']);
        $this->assertSame('120日(2024年度)', $byName['年間休日']);
        $this->assertSame('月額15,000円', $byName['資格取得']);
    }

    public function test_an_excerpt_over_the_limit_is_cut_with_an_ellipsis(): void
    {
        $rows = (new TopMessageDraftExtractor)->programCandidates([new TopMessagePage('https://e.example.com/a', '福利厚生', '本文', true, 2,
            '<html><body><main><h2>家賃補助</h2><p>'.str_repeat('あ', 80).'</p></main></body></html>',
        )]);

        $this->assertSame((int) config('top_message_draft.program_excerpt_max_chars') + 1, mb_strlen($rows[0]['excerpt']));
        $this->assertStringEndsWith('…', $rows[0]['excerpt']);
    }

    // ---- 差し込み(PPTX) ----

    /**
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
     * メッセージのページ1つ(任意)と、制度のページ1つ。識別用の語($tag)を本文に入れる。
     *
     * @param  list<string>  $programNames
     */
    private function seedDraftCompany(WebsiteAnalysis $wa, string $tag, bool $withMessage = true, array $programNames = []): void
    {
        $base = "https://{$tag}.example.com/recruit";
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用トップ', '採用情報です。', '<a href="'.$base.'/ceo-message/">代表からのご挨拶</a>'));
        if ($withMessage) {
            $this->addCrawledPage($wa, "{$base}/ceo-message/", '代表メッセージ | 例社', '<html><head><title>代表メッセージ | 例社</title></head><body><main><h1>代表メッセージ</h1><p>代表取締役社長 '.$tag.'太郎</p><p>私たちは、お客様と社員の成長を第一に考え、ものづくりを通じて'.$tag.'に貢献します。</p></main></body></html>');
        }
        if ($programNames !== []) {
            $items = implode('', array_map(fn (string $name) => "<h2>{$name}</h2><p>{$name}の説明です。</p>", $programNames));
            $this->addCrawledPage($wa, "{$base}/training/", '研修制度 | 例社', '<html><head><title>研修制度 | 例社</title></head><body><main>'.$items.'</main></body></html>');
        }
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
            $this->assertStringNotContainsString('<p:pic', $xml, 'スライドに画像が入っていない');
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

    /**
     * @param  list<string>  $slides
     * @return list<int> 素材ページの位置
     */
    private function draftIndexes(array $slides): array
    {
        return array_keys(array_filter($slides, fn (string $t) => str_contains($t, 'トップメッセージと制度の素材')));
    }

    public function test_draft_pages_follow_missing_items_in_the_order_self_then_competitors_and_skip_companies_without_candidates(): void
    {
        $c = $this->makeComparison(['競合A', '競合B', '競合C']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', true, ['自社の研修']);
        // 競合Bは巡回したページが無く、候補が1件も無い。
        $this->seedDraftCompany($c['competitors'][2], 'cccsha', true, ['競合Cの研修']);
        $this->seedDraftCompany($c['competitors'][0], 'aaasha', false, ['競合Aの研修']);

        $slides = $this->download($c['analysis']);
        $indexes = $this->draftIndexes($slides);

        $this->assertCount(3, $indexes, '候補が1件も無い会社(競合B)はページを作らない');
        $this->assertStringContainsString('自社の研修', $slides[$indexes[0]]);
        $this->assertStringContainsString('競合Aの研修', $slides[$indexes[1]]);
        $this->assertStringContainsString('競合Cの研修', $slides[$indexes[2]]);

        $missingIndex = array_key_first(array_filter($slides, fn (string $t) => str_contains($t, '足りないもの')));
        $surveyIndex = array_key_first(array_filter($slides, fn (string $t) => str_contains($t, '求職者が知りたい情報と、自社サイト')));
        $this->assertSame([$missingIndex + 1, $missingIndex + 2, $missingIndex + 3], $indexes, '「足りないもの」の次に連続して並ぶ');
        $this->assertSame($missingIndex + 4, $surveyIndex);
    }

    public function test_the_slide_has_a_red_band_with_the_internal_draft_text_and_the_fixed_footer(): void
    {
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', true, ['自社の研修']);

        $slides = $this->download($c['analysis']);
        $text = $slides[$this->draftIndexes($slides)[0]];

        $this->assertStringContainsString('社内用・下書き', $text);
        $this->assertStringContainsString('お客様にお見せする前に、仕上げたページと差し替えてください。', $text);
        $this->assertStringContainsString('サイトの文章を機械的に抜き出したものです。キーワードの整理と、メッセージと制度の対応づけは行っていません。', $text);
        foreach (['メッセージの候補', '制度の候補', '抜粋', '出典'] as $label) {
            $this->assertStringContainsString($label, $text);
        }

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->pageData()));
        $band = strtoupper((string) config('admin_comparison_pptx.top_message_draft_band_fill'));
        $this->assertStringContainsString($band, strtoupper($xml), '赤い帯(config)の色');
        $this->assertSame('C62828', $band, '帯は、アクセント色ではなくはっきりした赤');
    }

    public function test_a_company_without_a_message_page_shows_the_fixed_text_and_still_shows_the_programs(): void
    {
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', false, ['自社の研修']);

        $slides = $this->download($c['analysis']);
        $text = $slides[$this->draftIndexes($slides)[0]];

        $this->assertStringContainsString('トップメッセージのページを確認できませんでした。', $text);
        $this->assertStringContainsString('自社の研修', $text);
    }

    public function test_a_company_with_only_a_message_still_gets_a_page(): void
    {
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', true, []);

        $slides = $this->download($c['analysis']);

        $this->assertCount(1, $this->draftIndexes($slides));
        $this->assertStringContainsString('制度の候補を確認できませんでした。', $slides[$this->draftIndexes($slides)[0]]);
    }

    public function test_programs_over_the_limit_are_reported_as_the_rest(): void
    {
        config(['top_message_draft.program_total_max' => 3]);
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', true, array_map(fn (int $i) => sprintf('制度の名前X%02d', $i), range(1, 30)));

        $slides = $this->download($c['analysis']);
        $text = $slides[$this->draftIndexes($slides)[0]];

        $this->assertStringContainsString('制度の名前X01', $text);
        $this->assertStringContainsString('ほか27件', $text);
        $this->assertStringNotContainsString('制度の名前X04', $text);
    }

    public function test_the_draft_pages_are_not_made_when_disabled(): void
    {
        config(['top_message_draft.enabled' => false]);
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', true, ['自社の研修']);

        $slides = $this->download($c['analysis']);

        $this->assertSame([], $this->draftIndexes($slides));
        $this->assertCount(7, $slides, '元の2枚 + 従来の差し込み5枚のまま');
    }

    public function test_when_the_ai_switch_is_also_on_only_the_draft_pages_are_made_and_the_ai_is_never_called(): void
    {
        config(['top_message_insight.enabled' => true]);
        $fake = $this->fakeTopMessageProvider($this->validAiOutput());
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedStandardCompany($c['self']);

        $slides = $this->download($c['analysis']);

        $this->assertSame(0, $fake->calls, 'AIは呼ばない');
        $this->assertCount(1, $this->draftIndexes($slides));
        $this->assertSame([], array_keys(array_filter($slides, fn (string $t) => str_contains($t, 'トップメッセージと人事制度'))), 'AIのページは出さない');
    }

    public function test_building_the_pptx_makes_no_external_request(): void
    {
        Http::fake();
        $c = $this->makeComparison(['競合A']);
        $this->attachDeck($c['analysis']);
        $this->seedDraftCompany($c['self'], 'selfsha', true, ['自社の研修']);
        $this->seedDraftCompany($c['competitors'][0], 'aaasha', true, ['競合Aの研修']);

        $this->download($c['analysis']);

        Http::assertNothingSent();
    }

    public function test_text_that_does_not_fit_is_shrunk_and_then_candidates_are_dropped_from_the_end_with_the_rest_count(): void
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['name' => str_repeat('長い名前', 5).$i, 'excerpt' => str_repeat('抜粋です', 12), 'source' => '研修制度'];
        }
        $page = $this->pageData(rows: $rows, hidden: 0);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($page));

        $this->assertMatchesRegularExpression('/ほか(\d+)件/', $xml, '収まらない分は「ほか N件」');
        preg_match('/ほか(\d+)件/', $xml, $m);
        $shown = substr_count($xml, '研修制度');
        $this->assertSame(12, $shown + (int) $m[1], '見えている件数 + ほかの件数 = 全候補');
        $this->assertStringContainsString(str_repeat('長い名前', 5).'1]]>', $xml, '先頭は残る');
        $this->assertStringNotContainsString(str_repeat('長い名前', 5).'12]]>', $xml, '減らすのは後ろから');
    }

    public function test_the_slide_xml_has_no_pictures_or_external_parts(): void
    {
        $bytes = app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->pageData());
        $tmp = tempnam(sys_get_temp_dir(), 'dr');
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
        $this->assertDoesNotMatchRegularExpression('/(?:x|y|cx|cy|l|t|r|b)="\d+\.\d+"/', $xml, '座標・寸法は整数(小数だとPowerPointが壊れたファイルとして扱う)');
    }

    /**
     * @param  list<array{name: string, excerpt: string, source: string}>|null  $rows
     * @return array<string, mixed>
     */
    private function pageData(?array $rows = null, int $hidden = 0): array
    {
        return [
            'company_name' => 'テスト株式会社',
            'message' => ['title' => '代表メッセージ', 'url' => 'https://e.example.com/message/', 'lines' => ['私たちは、お客様と社員の成長を第一に考えます。'], 'hidden' => 0],
            'programs' => [
                'rows' => $rows ?? [['name' => '新人研修', 'excerpt' => '入社後1カ月', 'source' => '研修制度']],
                'hidden' => $hidden,
                'page_urls' => ['https://e.example.com/training/'],
            ],
        ];
    }

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }
}
