<?php

namespace Tests\Feature\TopMessagePanel;

use App\Enums\AnalysisKind;
use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Jobs\Analysis\FinalizeAnalysisJob;
use App\Jobs\GenerateTopMessagePanelJob;
use App\Models\Analysis;
use App\Models\LeadCompany;
use App\Models\WebsiteAnalysis;
use App\Services\Analysis\AnalysisPipeline;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\Report\AdminComparisonPptxDataBuilder;
use App\Services\Report\AdminComparisonPptxGenerator;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessagePanel\OpenAiTopMessagePanelProvider;
use App\Services\TopMessagePanel\TopMessagePanelDispatcher;
use App\Services\TopMessagePanel\TopMessagePanelMaterialBuilder;
use App\Services\TopMessagePanel\TopMessagePanelProvider;
use App\Services\TopMessagePanel\TopMessagePanelProviderFactory;
use App\Services\TopMessagePanel\TopMessagePanelService;
use App\Services\TopMessagePanel\TopMessagePanelStore;
use App\Services\TopMessagePanel\TopMessagePanelVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * 依頼CZ: トップメッセージと制度のパネル。AIは「選ぶ」だけ(文章は書かせない)。選んだものはサーバーが原文にあるか確かめ、
 * 通らなければ依頼CSの素材ページのまま。テストではAIを呼ばない(固定の出力を返す偽物を使う)。本文はすべて人工のもの。
 */
class TopMessagePanelTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const CORE = '私たちは、小さなビジネスから、世の中が面白くなっていくと信じています。';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
        config(['top_message_panel.enabled' => true, 'top_message_draft.enabled' => true]);
    }

    // ------------------------------------------------------------------ 部品

    /** @return array{sentences: list<array{id: string, text: string}>, programs: list<array{id: string, name: string, excerpt: string, source: string}>} */
    private function material(): array
    {
        return [
            'sentences' => [
                ['id' => 'm-1', 'text' => self::CORE],
                ['id' => 'm-2', 'text' => 'ものづくりは人づくりだと考え、社員の成長を支えています。'],
                ['id' => 'm-3', 'text' => '一社如一家の精神で、チーム力を高めていきます。'],
                ['id' => 'm-4', 'text' => '短い文です。'],
                ['id' => 'm-5', 'text' => '変化を恐れず挑戦する人を、会社は全力で応援する方針です。'],
            ],
            'programs' => [
                ['id' => 'p-1', 'name' => 'サポーター制度', 'excerpt' => '入社2年目から3年目の先輩が、新人社員のサポーターとなって悩みを聞きます。', 'source' => '研修制度'],
                ['id' => 'p-2', 'name' => '新人研修', 'excerpt' => '入社後1カ月は、全員が合宿形式の新人研修に参加します。', 'source' => '研修制度'],
                ['id' => 'p-3', 'name' => '家賃補助', 'excerpt' => '30歳までの独身者に、家賃の最大80%を住宅補助として支給します。', 'source' => '福利厚生'],
                ['id' => 'p-4', 'name' => '資格取得支援', 'excerpt' => '業務に関係する資格の取得費用を、会社が全額負担する制度です。', 'source' => '福利厚生'],
                ['id' => 'p-5', 'name' => '育児休業', 'excerpt' => '育児休業後の復職率は100%で、時短勤務も利用できます。', 'source' => '福利厚生'],
                ['id' => 'p-6', 'name' => '社内表彰', 'excerpt' => '年に一度、成果を上げたチームを全社員の前で表彰しています。', 'source' => '評価制度'],
            ],
        ];
    }

    /** @return array<string, mixed> 全部が確認を通るAIの出力 */
    private function goodDecoded(): array
    {
        return [
            'core_sentence_id' => 'm-1',
            'body_sentence_ids' => ['m-2', 'm-3'],
            'keywords' => [
                ['phrase' => 'ものづくりは人づくり', 'source_sentence_id' => 'm-2', 'programs' => [
                    ['program_id' => 'p-1', 'summary' => '2年目から3年目の先輩が、新人社員のサポーター'],
                    ['program_id' => 'p-2', 'summary' => '全員が合宿形式の新人研修に参加します'],
                ]],
                ['phrase' => '一社如一家', 'source_sentence_id' => 'm-3', 'programs' => [
                    ['program_id' => 'p-3', 'summary' => '家賃の最大80%を住宅補助として支給'],
                ]],
            ],
        ];
    }

    private function verify(array $decoded, ?array $material = null): array
    {
        return (new TopMessagePanelVerifier)->verify($decoded, $material ?? $this->material());
    }

    // ------------------------------------------------------------------ CZ-3 確認

    public function test_a_valid_selection_passes_and_everything_shown_is_the_original_text(): void
    {
        $result = $this->verify($this->goodDecoded());

        $this->assertSame([], $result['rejected']);
        $panel = $result['panel'];
        $this->assertSame(self::CORE, $panel['core']['text']);
        $this->assertSame(['m-2', 'm-3'], array_column($panel['body'], 'id'));
        $this->assertSame(['ものづくりは人づくり', '一社如一家'], array_column($panel['keywords'], 'phrase'));
        $this->assertSame('サポーター制度', $panel['keywords'][0]['programs'][0]['name'], '制度名は材料のもの');
        $this->assertSame('家賃の最大80%を住宅補助として支給', $panel['keywords'][1]['programs'][0]['summary']);
    }

    public function test_a_core_sentence_that_does_not_exist_or_is_out_of_range_drops_the_panel(): void
    {
        foreach ([['core_sentence_id' => 'm-99', 'code' => 'core_unknown_id'], ['core_sentence_id' => 'm-4', 'code' => 'core_length'], ['core_sentence_id' => null, 'code' => 'core_missing']] as $case) {
            $result = $this->verify(['core_sentence_id' => $case['core_sentence_id']] + $this->goodDecoded());

            $this->assertNull($result['panel'], $case['code']);
            $this->assertSame([$case['code'] => 1], $result['rejected']);
        }
    }

    public function test_a_body_sentence_that_does_not_exist_or_repeats_the_core_is_dropped_and_no_body_is_fine(): void
    {
        $decoded = ['body_sentence_ids' => ['m-99', 'm-1', 'm-2', 'm-2', 'm-3', 'm-5', 'm-4']] + $this->goodDecoded();

        $result = $this->verify($decoded);

        $this->assertSame(['m-2', 'm-3', 'm-5'], array_column($result['panel']['body'], 'id'), '上限は3文');
        $this->assertSame(['body_unknown_id' => 1, 'body_duplicate' => 2, 'body_over_limit' => 1], $result['rejected']);

        $none = $this->verify(['body_sentence_ids' => []] + $this->goodDecoded());
        $this->assertSame([], $none['panel']['body']);
    }

    public function test_a_keyword_must_be_in_its_source_sentence_within_the_length_and_without_forbidden_words(): void
    {
        $forbidden = (string) (config('comparison_commentary.forbidden_words')[0] ?? '');
        $this->assertNotSame('', $forbidden);
        $program = [['program_id' => 'p-1', 'summary' => '']];
        $decoded = $this->goodDecoded();
        $decoded['keywords'] = [
            ['phrase' => '人づくり', 'source_sentence_id' => 'm-3', 'programs' => $program],            // その文に無い
            ['phrase' => '人', 'source_sentence_id' => 'm-2', 'programs' => $program],                  // 短すぎる
            ['phrase' => str_repeat('あ', 21), 'source_sentence_id' => 'm-2', 'programs' => $program],   // 長すぎる
            ['phrase' => '社員', 'source_sentence_id' => 'm-99', 'programs' => $program],                // 文が無い
            ['phrase' => '社員の成長', 'source_sentence_id' => 'm-2', 'programs' => [['program_id' => 'p-1', 'summary' => '']]],
            ['phrase' => '社員の成長', 'source_sentence_id' => 'm-2', 'programs' => [['program_id' => 'p-2', 'summary' => '']]],   // 同じ語句
            ['phrase' => 'チーム力'.$forbidden, 'source_sentence_id' => 'm-3', 'programs' => [['program_id' => 'p-3', 'summary' => '']]],   // 本文に無い(かつ禁止語)
        ];

        $result = $this->verify($decoded);

        $this->assertNull($result['panel'], '残るキーワードが2個未満');
        $this->assertSame(1, $result['rejected']['keyword_not_in_sentence'] + 0 >= 1 ? 1 : 0);
        foreach (['keyword_length' => 2, 'keyword_source_unknown' => 1, 'keyword_duplicate' => 1, 'keywords_below_min' => 1] as $code => $count) {
            $this->assertSame($count, $result['rejected'][$code], $code);
        }
    }

    public function test_a_keyword_with_a_forbidden_word_is_dropped(): void
    {
        $forbidden = (string) (config('comparison_commentary.forbidden_words')[0] ?? '');
        $material = $this->material();
        $material['sentences'][2]['text'] = '一社如一家の精神で、'.$forbidden.'を高めていきます。';
        $decoded = $this->goodDecoded();
        $decoded['keywords'][1]['phrase'] = $forbidden;

        $result = $this->verify($decoded, $material);

        $this->assertSame(1, $result['rejected']['keyword_forbidden_word'] ?? 0);
    }

    public function test_a_keyword_matches_ignoring_spaces_and_width_only(): void
    {
        $material = $this->material();
        $material['sentences'][1]['text'] = 'ＩＴ を 活用し、ものづくり は 人づくり だと考えて、社員の成長を支えています。';
        $decoded = $this->goodDecoded();
        $decoded['keywords'][0]['phrase'] = 'ものづくりは人づくり';

        $this->assertCount(2, $this->verify($decoded, $material)['panel']['keywords']);

        $decoded['keywords'][0]['phrase'] = 'ものづくりを人づくり';
        $this->assertNull($this->verify($decoded, $material)['panel'], '字が違えば通らない(残り1個)');
    }

    public function test_a_program_that_does_not_exist_or_is_used_by_another_keyword_is_dropped_and_a_keyword_without_programs_is_dropped(): void
    {
        $decoded = $this->goodDecoded();
        $decoded['keywords'][0]['programs'] = [['program_id' => 'p-99', 'summary' => 'x'], ['program_id' => 'p-1', 'summary' => '']];
        $decoded['keywords'][1]['programs'] = [['program_id' => 'p-1', 'summary' => ''], ['program_id' => 'p-3', 'summary' => '']];
        $decoded['keywords'][] = ['phrase' => 'チーム力', 'source_sentence_id' => 'm-3', 'programs' => [['program_id' => 'p-3', 'summary' => '']]];

        $result = $this->verify($decoded);

        $this->assertSame(1, $result['rejected']['program_unknown_id']);
        $this->assertSame(2, $result['rejected']['program_duplicate'], 'p-1は最初のキーワードだけ、p-3は2番目だけ');
        $this->assertSame(1, $result['rejected']['keyword_no_program'], '制度が1件も付かないキーワードは捨てる');
        $this->assertSame(['ものづくりは人づくり', '一社如一家'], array_column($result['panel']['keywords'], 'phrase'));
    }

    public function test_a_failed_summary_leaves_a_card_with_the_program_name_only(): void
    {
        $cases = [
            'summary_not_in_excerpt' => '抜粋に書かれていない一節をここに置きます',
            'summary_length' => '短い説明',
            'summary_bad_ending' => '入社2年目から3年目の先輩が',
            'summary_missing' => '',
        ];
        foreach ($cases as $code => $summary) {
            $decoded = $this->goodDecoded();
            $decoded['keywords'][0]['programs'] = [['program_id' => 'p-1', 'summary' => $summary]];

            $result = $this->verify($decoded);

            $card = $result['panel']['keywords'][0]['programs'][0];
            $this->assertSame('サポーター制度', $card['name'], $code.': 落とさず、制度名だけのカード');
            $this->assertNull($card['summary'], $code);
            $this->assertSame(1, $result['rejected'][$code], $code);
        }
        $long = str_repeat('あ', 31);
        $material = $this->material();
        $material['programs'][0]['excerpt'] = $long;
        $decoded = $this->goodDecoded();
        $decoded['keywords'][0]['programs'] = [['program_id' => 'p-1', 'summary' => $long]];
        $this->assertSame(1, $this->verify($decoded, $material)['rejected']['summary_length'], '31字は長すぎる');
    }

    public function test_fewer_than_two_keywords_or_more_than_the_limit(): void
    {
        $one = $this->goodDecoded();
        array_pop($one['keywords']);
        $this->assertNull($this->verify($one)['panel']);
        $this->assertSame(1, $this->verify($one)['rejected']['keywords_below_min']);

        $many = $this->goodDecoded();
        $material = $this->material();
        foreach ([['p-4', '資格取得'], ['p-5', '復職率は100'], ['p-6', '成果を上げた']] as $i => [$program, $phrase]) {
            $material['sentences'][] = ['id' => 'm-'.(10 + $i), 'text' => "この文には、{$phrase}という語句が入っている、パネルのための文です。"];
            $many['keywords'][] = ['phrase' => $phrase, 'source_sentence_id' => 'm-'.(10 + $i), 'programs' => [['program_id' => $program, 'summary' => '']]];
        }
        $result = $this->verify($many, $material);

        $this->assertCount(4, $result['panel']['keywords']);
        $this->assertSame(1, $result['rejected']['keyword_over_limit']);
    }

    // ------------------------------------------------------------------ CZ-1 材料

    private function messageHtml(): string
    {
        return '<html><head><title>代表メッセージ | 例社</title></head><body><header><h1>HEADER-HEADING</h1></header><main>'
            .'<h1>代表メッセージ</h1>'
            .'<p>代表取締役社長 山田太郎</p>'
            .'<p>'.self::CORE.'</p>'
            .'<p>ものづくりは人づくりだと考え、社員の成長を支えています。</p>'
            .'<p><a href="/news/1">「小さなビジネスに着目する」代表の山田氏：他社メディアの電子版</a></p>'
            .'<h2>関連記事</h2>'
            .'<p>関連記事の下にある、普通の文章に見える長めの文ですが、これは材料から除かれます。</p>'
            .'<ul><li>関連記事の下のリストの項目として、同じく除かれる長めの文章がここにあります。</li></ul>'
            .'<h2>最後に、皆さんへのメッセージです</h2>'
            .'<p>最後に、皆さんと一緒に働ける日を楽しみにしています。ぜひ、ご応募ください。</p>'
            .'</main><footer><p>FOOTER-PARAGRAPH-LONG-ENOUGH-TO-BE-A-SENTENCE-HERE</p></footer></body></html>';
    }

    public function test_the_message_is_split_into_sentences_with_ids_and_related_sections_and_link_only_sentences_are_dropped(): void
    {
        $found = (new TopMessageDraftExtractor)->panelMessage($this->messageHtml(), '代表メッセージ | 例社');

        $texts = array_column($found['sentences'], 'text');
        $this->assertSame(['m-1', 'm-2', 'm-3', 'm-4', 'm-5'], array_column($found['sentences'], 'id'));
        $this->assertContains(self::CORE, $texts, '文は原文のまま');
        $this->assertContains('ものづくりは人づくりだと考え、社員の成長を支えています。', $texts);
        $this->assertContains('最後に、皆さんと一緒に働ける日を楽しみにしています。', $texts, '関連記事の見出しは、次の同じ階層の見出しで終わる');
        $this->assertContains('最後に、皆さんへのメッセージです', $texts, '見出しも文の候補');
        $this->assertSame('代表取締役社長 山田太郎', $found['speaker'], '話者は素材ページと同じ取り方');
        $joined = implode(' ', $texts);
        $this->assertStringNotContainsString('関連記事の下にある', $joined);
        $this->assertStringNotContainsString('関連記事の下のリスト', $joined);
        $this->assertStringNotContainsString('他社メディアの電子版', $joined, 'リンクの文字だけの行は、他社のメディア名の語の一覧なしで落ちる');
        $this->assertStringNotContainsString('FOOTER', $joined);
        $this->assertStringNotContainsString('HEADER', $joined);
        $this->assertSame(['related_section' => 2, 'link_text' => 1, 'title' => 0, 'short_or_long' => 1], $found['dropped'], '短い見出し(ページ名と同じ)は、長さで落ちる');
    }

    public function test_the_link_ratio_is_the_configured_threshold(): void
    {
        $html = '<html><body><main><p>これは説明の文章であり、ここだけがリンクではない部分です。<a href="/x">リンクの文字</a></p></main></body></html>';

        $this->assertCount(1, (new TopMessageDraftExtractor)->panelMessage($html)['sentences']);

        config(['top_message_draft.panel_link_text_ratio' => 0.1]);
        $this->assertCount(0, (new TopMessageDraftExtractor)->panelMessage($html)['sentences']);
    }

    private function seedCompany(WebsiteAnalysis $wa, string $base = 'https://self.example.com/recruit'): void
    {
        $this->addHomepage($wa, "{$base}/", $this->htmlPage('採用トップ', '採用情報です。', '<a href="'.$base.'/ceo-message/">代表からのご挨拶</a>'));
        $this->addCrawledPage($wa, "{$base}/ceo-message/", '代表メッセージ | 例社', $this->messageHtml());
        $this->addCrawledPage($wa, "{$base}/training/", '研修制度 | 例社',
            '<html><head><title>研修制度 | 例社</title></head><body><main>'
            .'<h2>サポーター制度</h2><p>入社2年目から3年目の先輩が、新人社員のサポーターとなって悩みを聞きます。</p>'
            .'<h2>成長支援</h2>'
            .'<h2>評価制度</h2><p>関連記事</p>'
            .'<h2>新人研修</h2><p>入社後1カ月は、全員が合宿形式の新人研修に参加します。</p>'
            .'</main></body></html>');
        $this->addCrawledPage($wa, "{$base}/welfare/requirements/", '福利厚生 募集要項 | 例社',
            '<html><head><title>福利厚生 募集要項 | 例社</title></head><body><main>'
            .'<h2>初任給</h2><p>大学卒は月給25万円、大学院卒は月給27万円を支給します。</p>'
            .'<h2>家賃補助</h2><p>30歳までの独身者に、家賃の最大80%を住宅補助として支給します。</p>'
            .'</main></body></html>');
        $this->addCrawledPage($wa, "{$base}/welfare/", '福利厚生 | 例社',
            '<html><head><title>福利厚生 | 例社</title></head><body><main>'
            .'<h2>育児休業</h2><p>育児休業後の復職率は100%で、時短勤務も利用できます。</p>'
            .'</main></body></html>');
    }

    public function test_the_material_gets_ids_full_excerpts_and_drops_programs_without_an_excerpt_with_a_generic_excerpt_or_from_job_pages(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        $material = app(TopMessagePanelMaterialBuilder::class)->build($wa);

        $this->assertSame('ok', $material['status']);
        $names = array_column($material['programs'], 'name');
        $this->assertContains('サポーター制度', $names);
        $this->assertContains('新人研修', $names);
        $this->assertContains('育児休業', $names);
        $this->assertNotContains('成長支援', $names, '抜粋が無い');
        $this->assertNotContains('評価制度', $names, '抜粋が「関連記事」だけ');
        $this->assertNotContains('初任給', $names, '募集要項のページの候補');
        $this->assertNotContains('家賃補助', $names, '募集要項のページの候補');
        $this->assertSame(['p-1', 'p-2', 'p-3'], array_column($material['programs'], 'id'));
        $this->assertSame('入社2年目から3年目の先輩が、新人社員のサポーターとなって悩みを聞きます。', $material['programs'][0]['excerpt'], '50字で切らず、全文(上限200字)');
        $this->assertSame(['no_excerpt' => 1, 'generic_excerpt' => 1, 'job_page' => 2], $material['dropped']['programs']);
        $this->assertSame('代表取締役社長 山田太郎', $material['speaker']);
        $this->assertSame('m-1', $material['sentences'][0]['id']);
    }

    public function test_a_company_without_a_message_page_or_without_programs_has_no_material_and_the_ai_is_not_called(): void
    {
        $fake = $this->fakeProvider($this->goodDecoded());
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->addHomepage($wa, 'https://self.example.com/recruit/', $this->htmlPage('採用トップ', '採用情報です。'));

        $result = app(TopMessagePanelService::class)->composeCompany($wa, '例社');

        $this->assertSame('fallback', $result['status']);
        $this->assertSame('no_message_page', $result['reason']);
        $this->assertSame(0, $fake->calls);
    }

    // ------------------------------------------------------------------ CZ-2 AI

    /**
     * AIの偽物。固定の出力を返し、渡された入力と呼ばれた回数を持つ。
     *
     * @param  array<string, mixed>|\Closure  $output
     */
    private function fakeProvider(array|\Closure $output): object
    {
        $provider = new class($output) implements TopMessagePanelProvider
        {
            public int $calls = 0;

            public array $inputs = [];

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

            public function select(array $material): array
            {
                $this->calls++;
                $this->inputs[] = $material;
                $decoded = $this->output instanceof \Closure ? ($this->output)($material) : $this->output;

                return ['decoded' => $decoded, 'usage_input_tokens' => 10, 'usage_output_tokens' => 5];
            }
        };

        app()->instance(TopMessagePanelProviderFactory::class, new class($provider) extends TopMessagePanelProviderFactory
        {
            public function __construct(private readonly TopMessagePanelProvider $fake) {}

            public function make(): TopMessagePanelProvider
            {
                return $this->fake;
            }
        });

        return $provider;
    }

    /** 偽物に、材料の文・制度から、確認を通る出力を作らせる(材料のIDを使う)。 */
    private function decodedFromMaterial(): \Closure
    {
        return function (array $material): array {
            $sentences = array_column($material['sentences'], 'text', 'id');
            $core = array_search(self::CORE, $sentences, true);
            $second = array_search('ものづくりは人づくりだと考え、社員の成長を支えています。', $sentences, true);
            $programs = array_column($material['programs'], 'name', 'id');

            return [
                'core_sentence_id' => $core,
                'body_sentence_ids' => [$second],
                'keywords' => [
                    ['phrase' => 'ものづくりは人づくり', 'source_sentence_id' => $second, 'programs' => [['program_id' => array_search('サポーター制度', $programs, true), 'summary' => '先輩が、新人社員のサポーターとなって悩みを聞きます']]],
                    ['phrase' => '社員の成長を支えて', 'source_sentence_id' => $second, 'programs' => [['program_id' => array_search('新人研修', $programs, true), 'summary' => '']]],
                ],
            ];
        };
    }

    public function test_the_prompt_asks_to_select_only_and_has_no_example_sentence_and_the_material_has_ids(): void
    {
        $provider = new OpenAiTopMessagePanelProvider;
        $prompt = $provider->buildPrompt(['company_name' => '例社'] + $this->material() + ['programs' => $this->material()['programs']]);

        foreach (['core_sentence_id', 'body_sentence_ids', 'keywords', 'phrase', 'source_sentence_id', 'program_id', 'summary', '選ぶだけ', '一字一句そのまま'] as $needle) {
            $this->assertStringContainsString($needle, $prompt, $needle);
        }
        $this->assertStringContainsString('m-1: '.self::CORE, $prompt);
        $this->assertStringContainsString('p-1 | サポーター制度 |', $prompt);
        $this->assertStringContainsString('1〜3', $prompt);
        $this->assertStringNotContainsString('例:', $prompt);
        $this->assertStringNotContainsString('良い例', $prompt);
        $this->assertSame('v1', config('top_message_panel.prompt_version'));
        $schema = $provider->buildResponseSchema();
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['core_sentence_id', 'body_sentence_ids', 'keywords'], $schema['required']);
    }

    public function test_the_ai_is_called_once_per_company_with_the_material_and_the_result_keeps_the_verified_panel_only(): void
    {
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        $result = app(TopMessagePanelService::class)->composeCompany($wa, '例社');

        $this->assertSame(1, $fake->calls);
        $this->assertSame('例社', $fake->inputs[0]['company_name']);
        $this->assertSame(['id', 'name', 'excerpt'], array_keys($fake->inputs[0]['programs'][0]), '制度はID・名前・抜粋だけを渡す');
        $this->assertSame('panel', $result['status']);
        $this->assertSame(self::CORE, $result['panel']['core']['text']);
        $this->assertSame(1, $result['ai_calls']);
        $this->assertSame(10, $result['usage_input_tokens']);
        $this->assertSame('サポーター制度', $result['panel']['keywords'][0]['programs'][0]['name']);
        $this->assertSame('先輩が、新人社員のサポーターとなって悩みを聞きます', $result['panel']['keywords'][0]['programs'][0]['summary']);
        $this->assertNull($result['panel']['keywords'][1]['programs'][0]['summary'], '説明が空なら名前だけ');
    }

    public function test_a_selection_that_fails_the_check_is_a_fallback_with_the_reason_and_the_ai_is_not_called_beyond_the_limit_callback(): void
    {
        $fake = $this->fakeProvider(['core_sentence_id' => 'm-999', 'body_sentence_ids' => [], 'keywords' => []]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        $result = app(TopMessagePanelService::class)->composeCompany($wa, '例社');

        $this->assertSame('fallback', $result['status']);
        $this->assertSame('core_unknown_id', $result['reason']);
        $this->assertNull($result['panel']);

        $blocked = app(TopMessagePanelService::class)->composeCompany($wa, '例社', fn () => false);
        $this->assertSame('ai_call_limit', $blocked['reason']);
        $this->assertSame(1, $fake->calls, '上限のコールバックがfalseなら、AIを呼ばない');
    }

    public function test_an_ai_error_makes_the_company_a_fallback(): void
    {
        $this->fakeProvider(fn () => throw new ComparisonCommentaryException('AI_RATE_LIMITED', 'レート制限'));
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        try {
            $result = app(TopMessagePanelService::class)->composeCompany($wa, '例社');
            $this->assertSame('AI_RATE_LIMITED', $result['reason']);
        } catch (\Throwable $e) {
            $this->fail('AIのエラーは握って、素材ページに戻す: '.$e::class);
        }
    }

    // ------------------------------------------------------------------ CZ-5 保存・ジョブ・待ち

    /** 実行中の比較(自社1 + 競合1社、全社が完了)。どちらも材料が作れる。 */
    private function runningComparison(): Analysis
    {
        $self = $this->makeComparisonWebsiteAnalysis(true, 0, 'self');
        $analysis = Analysis::query()->findOrFail($self->analysis_id);
        $analysis->update(['status' => AnalysisStatus::Running]);
        $self->update(['status' => WebsiteAnalysisStatus::Completed]);
        $this->seedCompany($self);
        $other = $this->makeComparisonWebsiteAnalysis(false, 1, 'rival', $analysis);
        $other->update(['status' => WebsiteAnalysisStatus::Completed]);
        $this->seedCompany($other, 'https://rival.example.com/recruit');

        return $analysis->fresh();
    }

    public function test_the_default_of_enabled_is_false(): void
    {
        $this->assertFalse((require base_path('config/top_message_panel.php'))['enabled']);
        $this->assertStringContainsString("env('TOP_MESSAGE_PANEL_ENABLED', false)", (string) file_get_contents(base_path('config/top_message_panel.php')));
    }

    public function test_the_results_are_stored_per_company_in_one_file_and_not_called_again(): void
    {
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $analysis = $this->runningComparison();

        $result = app(TopMessagePanelService::class)->generate($analysis);

        $this->assertSame(2, $fake->calls, '1社1回');
        $this->assertCount(2, $result['companies']);
        $path = "analyses/{$analysis->id}/top_message_panel.json";
        Storage::disk('analysis')->assertExists($path);
        $stored = json_decode((string) Storage::disk('analysis')->get($path), true);
        $this->assertArrayNotHasKey('candidates', array_values($stored['companies'])[0], 'AIの出力そのまま(確認前)は置かない');
        $this->assertSame('v1', $stored['prompt_version']);

        app(TopMessagePanelService::class)->generate($analysis);
        $this->assertSame(2, $fake->calls, '結果のファイルがあれば、AIを呼び直さない');

        $selfWa = $analysis->websiteAnalyses()->whereHas('website', fn ($q) => $q->where('is_primary', true))->first();
        $this->assertNotNull(app(TopMessagePanelStore::class)->panelFor($analysis->id, $selfWa->id));
    }

    public function test_when_disabled_nothing_is_dispatched_nothing_is_waited_for_and_no_ai_is_called(): void
    {
        config(['top_message_panel.enabled' => false]);
        Queue::fake();
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $analysis = $this->runningComparison();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateTopMessagePanelJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class);
        $this->assertSame(0, $fake->calls);
        $this->assertFalse(app(TopMessagePanelDispatcher::class)->holdFinalization($analysis));
    }

    public function test_when_the_draft_page_is_off_the_panel_is_not_started_either(): void
    {
        config(['top_message_draft.enabled' => false]);
        Queue::fake();
        $analysis = $this->runningComparison();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateTopMessagePanelJob::class);
    }

    public function test_when_enabled_the_comparison_waits_for_the_job_and_finishes_after_it(): void
    {
        Queue::fake();
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $analysis = $this->runningComparison();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertPushed(GenerateTopMessagePanelJob::class, 1);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateTopMessagePanelJob($analysis->id))->handle(app(TopMessagePanelService::class), app(TopMessagePanelDispatcher::class));

        $this->assertSame(2, $fake->calls);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_the_job_is_not_dispatched_before_every_company_has_finished(): void
    {
        Queue::fake();
        $analysis = $this->runningComparison();
        $analysis->websiteAnalyses()->first()->update(['status' => WebsiteAnalysisStatus::Running]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateTopMessagePanelJob::class);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);
    }

    public function test_a_failing_job_leaves_the_draft_pages_and_the_comparison_still_finishes(): void
    {
        Queue::fake();
        $this->fakeProvider(fn () => throw new \RuntimeException('想定外の失敗'));
        $analysis = $this->runningComparison();

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        (new GenerateTopMessagePanelJob($analysis->id))->handle(app(TopMessagePanelService::class), app(TopMessagePanelDispatcher::class));

        $stored = app(TopMessagePanelStore::class)->read($analysis->id);
        $this->assertSame('RuntimeException', $stored['job_failed']);
        $this->assertSame([], $stored['companies']);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_job_killed_by_the_worker_also_unblocks_the_comparison(): void
    {
        Queue::fake();
        $analysis = $this->runningComparison();
        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);
        Queue::assertNotPushed(FinalizeAnalysisJob::class);

        (new GenerateTopMessagePanelJob($analysis->id))->failed(new \RuntimeException('timeout'));

        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_the_wait_ends_after_the_configured_minutes(): void
    {
        Queue::fake();
        $analysis = $this->runningComparison();
        $analysis->websiteAnalyses()->toBase()->update(['updated_at' => now()->subMinutes(60)]);

        app(AnalysisPipeline::class)->maybeFinalizeAnalysis($analysis->id);

        Queue::assertNotPushed(GenerateTopMessagePanelJob::class);
        Queue::assertPushed(FinalizeAnalysisJob::class, 1);
    }

    public function test_a_regular_diagnosis_is_not_touched(): void
    {
        $analysis = $this->runningComparison();
        $analysis->update(['kind' => AnalysisKind::LeadDiagnosis]);

        $this->assertFalse(app(TopMessagePanelDispatcher::class)->holdFinalization($analysis->fresh()));
    }

    public function test_the_result_file_is_deleted_together_with_the_company_data(): void
    {
        $this->fakeProvider($this->decodedFromMaterial());
        $analysis = $this->runningComparison();
        app(TopMessagePanelService::class)->generate($analysis);
        $path = "analyses/{$analysis->id}/top_message_panel.json";
        Storage::disk('analysis')->assertExists($path);

        $analysis->update(['status' => AnalysisStatus::Completed]);
        $company = LeadCompany::query()->findOrFail($analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing($path);
    }

    // ------------------------------------------------------------------ CZ-4 スライド

    private function slideXml(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tp');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = html_entity_decode((string) $zip->getFromName('ppt/slides/slide1.xml'), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $hasRelations = $zip->getFromName('ppt/slides/_rels/slide1.xml.rels');
        $zip->close();
        @unlink($tmp);
        $this->assertStringNotContainsString('r:embed', $xml, '画像は入れない');

        return $xml;
    }

    /** @return array<string, mixed> */
    private function page(array $keywords, ?array $panel = null): array
    {
        return [
            'company_name' => '例社',
            'message' => ['title' => '代表メッセージ', 'url' => 'https://self.example.com/recruit/ceo-message/', 'lines' => ['素材ページの文です。'], 'hidden' => 0],
            'programs' => ['rows' => [], 'hidden' => 0, 'page_urls' => [], 'candidate_total' => 0, 'dropped' => ['colon' => 0, 'title' => 0, 'category' => 0]],
            'panel' => $panel ?? [
                'speaker' => '代表取締役社長 山田太郎',
                'source_title' => '代表メッセージ',
                'core' => self::CORE,
                'body' => ['ものづくりは人づくりだと考え、社員の成長を支えています。', '一社如一家の精神で、チーム力を高めていきます。'],
                'keywords' => $keywords,
            ],
        ];
    }

    /** @return list<array{phrase: string, programs: list<array{name: string, summary: ?string}>}> */
    private function keywords(int $count, int $programs = 3): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $cards = [];
            for ($j = 1; $j <= $programs; $j++) {
                $cards[] = ['name' => "制度{$i}-{$j}", 'summary' => "説明{$i}-{$j}は短い一節です"];
            }
            $rows[] = ['phrase' => "キーワード{$i}", 'programs' => $cards];
        }

        return $rows;
    }

    public function test_the_panel_slide_has_the_red_band_the_left_panel_the_right_bands_and_cards_and_the_note_and_no_image(): void
    {
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page($this->keywords(4))));

        foreach ([
            (string) config('admin_comparison_pptx.top_message_draft_band_text'),   // 赤い帯
            '例社：トップメッセージと制度',
            'トップメッセージ',
            '代表取締役社長 山田太郎', '代表メッセージ',
            '「'.self::CORE.'」', 'ものづくりは人づくりだと考え、社員の成長を支えています。', '一社如一家の精神で、チーム力を高めていきます。',
            'トップメッセージのキーワードと、関連していると思われる制度',
            '出典：例社 採用サイト「代表メッセージ」ほか。キーワードと制度の対応づけは AI が選んだもので、社内での確認が必要です。',
        ] as $text) {
            $this->assertStringContainsString($text, $xml, $text);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->assertStringContainsString("キーワード{$i}", $xml);
            for ($j = 1; $j <= 3; $j++) {
                $this->assertStringContainsString("制度{$i}-{$j}", $xml);
                $this->assertStringContainsString("説明{$i}-{$j}は短い一節です", $xml);
            }
        }
        $this->assertStringNotContainsString('素材ページの文です。', $xml, 'パネルを使うときは、素材ページの内容ではない');
        foreach ((array) config('admin_comparison_pptx.top_message_panel_band_colors') as $color) {
            $this->assertStringContainsString($color, $xml, '帯の2色');
        }
    }

    public function test_a_card_without_a_summary_shows_the_program_name_only(): void
    {
        $keywords = [['phrase' => 'キーワード1', 'programs' => [['name' => '名前だけの制度', 'summary' => null]]], ['phrase' => 'キーワード2', 'programs' => [['name' => '別の制度', 'summary' => '説明の一節です']]]];

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page($keywords)));

        $this->assertStringContainsString('名前だけの制度', $xml);
        $this->assertStringContainsString('説明の一節です', $xml);
    }

    public function test_when_the_text_does_not_fit_even_when_small_the_later_bands_are_dropped(): void
    {
        $long = str_repeat('とても長い制度の名前', 6);   // 60字
        $keywords = [];
        for ($i = 1; $i <= 4; $i++) {
            $keywords[] = ['phrase' => "キーワード{$i}", 'programs' => [
                ['name' => $long."{$i}a", 'summary' => str_repeat('説', 29)],
                ['name' => $long."{$i}b", 'summary' => str_repeat('説', 29)],
                ['name' => $long."{$i}c", 'summary' => str_repeat('説', 29)],
            ]];
        }

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page($keywords)));

        $this->assertStringContainsString('キーワード1', $xml);
        $this->assertStringNotContainsString('キーワード4', $xml, '後ろの帯から減らす');
    }

    public function test_without_a_panel_the_slide_is_the_draft_page_as_before(): void
    {
        $page = $this->page($this->keywords(2));
        unset($page['panel']);

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($page));

        $this->assertStringContainsString('例社：トップメッセージと制度の素材', $xml);
        $this->assertStringContainsString('素材ページの文です。', $xml);
        $this->assertStringNotContainsString('AI が選んだ', $xml);
    }

    public function test_the_data_builder_attaches_the_stored_panel_only_when_enabled_and_never_calls_the_ai_or_the_network(): void
    {
        Http::fake();
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $analysis = $this->runningComparison();
        app(TopMessagePanelService::class)->generate($analysis);
        $callsAfterGenerate = $fake->calls;

        $viewModel = app(\App\Services\Report\MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());
        $builder = app(AdminComparisonPptxDataBuilder::class);

        $pages = $builder->buildTopMessageDraftData($analysis->fresh(), $viewModel);
        $this->assertNotEmpty($pages);
        $this->assertSame(self::CORE, $pages[0]['panel']['core']);
        $this->assertSame('代表メッセージ', $pages[0]['panel']['source_title']);
        $this->assertCount(2, $pages[0]['panel']['keywords']);

        config(['top_message_panel.enabled' => false]);
        $this->assertArrayNotHasKey('panel', $builder->buildTopMessageDraftData($analysis->fresh(), $viewModel)[0]);

        $this->assertSame($callsAfterGenerate, $fake->calls, 'データを作るときにAIを呼ばない');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 測定コマンド

    public function test_the_measure_command_never_calls_the_ai_beyond_the_approved_number_of_calls(): void
    {
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $analysis = $this->runningComparison();
        $ids = $analysis->websiteAnalyses()->pluck('id')->all();
        $args = ['--wa' => "a:{$ids[0]},a2:{$ids[0]},b:{$ids[1]}"];

        $this->artisan('top-message-panel:measure', $args + ['--live' => true])->assertFailed();
        $this->assertSame(0, $fake->calls);

        $this->artisan('top-message-panel:measure', $args + ['--live' => true, '--max-ai-calls' => 2])->assertFailed();
        $this->assertSame(0, $fake->calls, '3回呼ぶ見込みなので、2回の承認では1回も呼ばない');

        $this->artisan('top-message-panel:measure', $args + ['--max-ai-calls' => 5])->assertSuccessful();
        $this->assertSame(0, $fake->calls, '--liveが無ければ呼ばない');

        $this->artisan('top-message-panel:measure', $args + ['--live' => true, '--max-ai-calls' => 3])->assertSuccessful();
        $this->assertSame(3, $fake->calls);
        Storage::disk('analysis')->assertMissing("analyses/{$analysis->id}/top_message_panel.json");
    }
}
