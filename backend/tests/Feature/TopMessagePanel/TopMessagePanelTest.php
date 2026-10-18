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
use App\Services\TopMessagePanel\TopMessagePanelMachineSelector;
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
            'programs' => [
                ['program_id' => 'p-1', 'summary' => '2年目から3年目の先輩が、新人社員のサポーター'],
                ['program_id' => 'p-2', 'summary' => '全員が合宿形式の新人研修に参加します'],
                ['program_id' => 'p-3', 'summary' => '家賃の最大80%を住宅補助として支給'],
                ['program_id' => 'p-4', 'summary' => '資格の取得費用を、会社が全額負担する'],
            ],
            'keywords' => [
                ['phrase' => 'ものづくりは人づくり', 'source_sentence_id' => 'm-2', 'program_ids' => ['p-1', 'p-2']],
                ['phrase' => '一社如一家の精神で、チーム力を高めていきます', 'source_sentence_id' => 'm-3', 'program_ids' => ['p-3', 'p-4']],
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
        $this->assertSame('keywords', $panel['mode']);
        $this->assertSame(self::CORE, $panel['core']['text']);
        $this->assertSame(['m-2', 'm-3'], array_column($panel['body'], 'id'));
        $this->assertSame(['ものづくりは人づくり', '一社如一家の精神で、チーム力を高めていきます'], array_column($panel['keywords'], 'phrase'));
        $this->assertSame('サポーター制度', $panel['keywords'][0]['programs'][0]['name'], '制度名は材料のもの');
        $this->assertSame('家賃の最大80%を住宅補助として支給', $panel['keywords'][1]['programs'][0]['summary']);
        $this->assertSame(['p-1', 'p-2', 'p-3', 'p-4'], array_column($panel['programs'], 'id'));
        $this->assertSame('研修制度', $panel['programs'][0]['source']);
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

    /**
     * @return array<string, mixed> 1つのキーワードだけを差し替えた出力(もう1つは通るキーワードのまま)
     */
    private function withKeyword(array $keyword): array
    {
        $decoded = $this->goodDecoded();
        $decoded['keywords'][0] = $keyword + ['source_sentence_id' => 'm-2', 'program_ids' => ['p-1', 'p-2']];

        return $decoded;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function badKeywords(): array
    {
        return [
            '6字未満' => ['人づくり', 'm-2', 'keyword_length'],
            '30字超' => ['ものづくりは人づくりだと考え、社員の成長を支えています。この文は長すぎる', 'm-2', 'keyword_length'],
            'ひらがなを含まない(漢字)' => ['社員成長支援体制', 'm-2', 'keyword_no_hiragana'],
            'ひらがなを含まない(カタカナ)' => ['スモールビジネス', 'm-2', 'keyword_no_hiragana'],
            '原文に無い' => ['ものづくりを人づくり', 'm-2', 'keyword_not_in_sentence'],
            '別の文の句' => ['ものづくりは人づくり', 'm-3', 'keyword_not_in_sentence'],
            '文が無い' => ['ものづくりは人づくり', 'm-99', 'keyword_source_unknown'],
            '末尾が助詞' => ['ものづくりは人づくりだと考え、社員の', 'm-2', 'keyword_bad_ending'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badKeywords')]
    public function test_a_bad_keyword_is_dropped_and_with_fewer_than_two_keywords_the_right_side_is_by_source(string $phrase, string $sourceId, string $code): void
    {
        $result = $this->verify($this->withKeyword(['phrase' => $phrase, 'source_sentence_id' => $sourceId]));

        $this->assertSame(1, $result['rejected'][$code] ?? 0, $code);
        $this->assertSame('sources', $result['panel']['mode'], '残るキーワードが2個未満');
        $this->assertSame([], $result['panel']['keywords']);
        $this->assertCount(4, $result['panel']['programs']);
    }

    public function test_a_keyword_with_a_forbidden_word_is_dropped(): void
    {
        $forbidden = (string) (config('comparison_commentary.forbidden_words')[0] ?? '');
        $this->assertNotSame('', $forbidden);
        $material = $this->material();
        $material['sentences'][1]['text'] = '人づくりを大切にし、'.$forbidden.'ことを大事にしている考え方です。';

        $result = $this->verify($this->withKeyword(['phrase' => '人づくりを大切にし、'.$forbidden.'ことを大事にしている']), $material);

        $this->assertSame(1, $result['rejected']['keyword_forbidden_word'] ?? 0);
    }

    public function test_a_keyword_matches_ignoring_spaces_width_and_the_brackets_on_both_sides(): void
    {
        $material = $this->material();
        $material['sentences'][1]['text'] = '「ものづくり」は「人づくり」だと私たちは強く信じています。';

        // 句に「」が無くても、原文の「」を取り除けば一致する。
        $this->assertSame(['ものづくりは人づくりだと私たちは強く信じています', '一社如一家の精神で、チーム力を高めていきます'], array_column(
            $this->verify($this->withKeyword(['phrase' => 'ものづくりは人づくりだと私たちは強く信じています']), $material)['panel']['keywords'], 'phrase'));
        // 句に「」が付いていても、取り除いて比べる。
        $this->assertCount(2, $this->verify($this->withKeyword(['phrase' => '「ものづくり」は「人づくり」だと私たちは強く信じています']), $material)['panel']['keywords']);

        // 空白・全角半角の違いだけ無視する。
        $material['sentences'][1]['text'] = 'ものづくり は 人づくり だと考え、ＩＴ で社員の成長を支えています。';
        $this->assertCount(2, $this->verify($this->withKeyword(['phrase' => 'ものづくりは人づくりだと考え']), $material)['panel']['keywords']);

        // 字が違えば通らない。
        $this->assertSame('sources', $this->verify($this->withKeyword(['phrase' => 'ものづくりを人づくりだと考え']), $material)['panel']['mode']);
    }

    public function test_a_keyword_with_fewer_than_two_programs_is_dropped_and_a_program_not_selected_cannot_be_attached(): void
    {
        $one = $this->verify($this->withKeyword(['phrase' => 'ものづくりは人づくり', 'program_ids' => ['p-1']]));
        $this->assertSame(1, $one['rejected']['keyword_programs_below_min']);
        $this->assertSame('sources', $one['panel']['mode']);

        $decoded = $this->goodDecoded();
        $decoded['programs'] = array_slice($decoded['programs'], 0, 3);   // p-4 は選ばれていない
        $notSelected = $this->verify($decoded);
        $this->assertSame(1, $notSelected['rejected']['keyword_program_not_selected']);
        $this->assertSame(1, $notSelected['rejected']['keyword_programs_below_min'], '付けられるのがp-3だけになり、2件未満');

        $dup = $this->goodDecoded();
        $dup['keywords'][1]['program_ids'] = ['p-1', 'p-3'];   // p-1は最初のキーワードが使った
        $result = $this->verify($dup);
        $this->assertSame(1, $result['rejected']['program_duplicate']);
        $this->assertSame(1, $result['rejected']['keyword_programs_below_min']);
    }

    public function test_programs_that_do_not_exist_or_repeat_or_exceed_the_limit_are_dropped(): void
    {
        $decoded = $this->goodDecoded();
        array_unshift($decoded['programs'], ['program_id' => 'p-99', 'summary' => '']);
        $decoded['programs'][] = ['program_id' => 'p-1', 'summary' => ''];
        config(['top_message_panel.programs_max' => 4]);
        $decoded['programs'][] = ['program_id' => 'p-5', 'summary' => ''];

        $result = $this->verify($decoded);

        $this->assertSame(1, $result['rejected']['program_unknown_id']);
        $this->assertSame(1, $result['rejected']['program_duplicate']);
        $this->assertSame(1, $result['rejected']['program_over_limit']);
        $this->assertCount(4, $result['panel']['programs']);
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
            $decoded['programs'][0]['summary'] = $summary;

            $result = $this->verify($decoded);

            $card = $result['panel']['keywords'][0]['programs'][0];
            $this->assertSame('サポーター制度', $card['name'], $code.': 落とさず、制度名だけのカード');
            $this->assertNull($card['summary'], $code);
            $this->assertSame(1, $result['rejected'][$code], $code);
            $this->assertSame('keywords', $result['panel']['mode']);
        }
        $long = str_repeat('あ', 31);
        $material = $this->material();
        $material['programs'][0]['excerpt'] = $long;
        $decoded = $this->goodDecoded();
        $decoded['programs'][0]['summary'] = $long;
        $this->assertSame(1, $this->verify($decoded, $material)['rejected']['summary_length'], '31字は長すぎる');
    }

    public function test_a_summary_with_unclosed_brackets_leaves_a_name_only_card_and_a_balanced_one_passes(): void
    {
        $excerpt = '1週目 ：各配属先(会社の概要、会社規則)について学びます';
        $material = $this->material();
        $material['programs'][0]['excerpt'] = $excerpt;

        $decoded = $this->goodDecoded();
        $decoded['programs'][0]['summary'] = '1週目 ：各配属先(会社の概要、会社規則';
        $result = $this->verify($decoded, $material);
        $this->assertNull($result['panel']['keywords'][0]['programs'][0]['summary'], '閉じていない括弧は名前だけのカード');
        $this->assertSame('サポーター制度', $result['panel']['keywords'][0]['programs'][0]['name']);
        $this->assertSame(1, $result['rejected']['summary_unbalanced_brackets']);

        $decoded['programs'][0]['summary'] = '1週目 ：各配属先(会社の概要、会社規則)について学びます';
        $result = $this->verify($decoded, $material);
        $this->assertSame('1週目 ：各配属先(会社の概要、会社規則)について学びます',$result['panel']['keywords'][0]['programs'][0]['summary']);
        $this->assertArrayNotHasKey('summary_unbalanced_brackets', $result['rejected']);
    }

    public function test_a_keyword_with_unclosed_brackets_is_dropped(): void
    {
        $material = $this->material();
        $material['sentences'][0]['text'] = '私たちは「社員の成長を支援する体制を整えています。';
        $decoded = $this->goodDecoded();
        $decoded['keywords'][0]['phrase'] = '「社員の成長を支援する体制';
        $decoded['keywords'][0]['source_sentence_id'] = $material['sentences'][0]['id'];

        $result = $this->verify($decoded, $material);

        $this->assertSame(1, $result['rejected']['keyword_unbalanced_brackets']);
        foreach ($result['panel']['keywords'] ?? [] as $keyword) {
            $this->assertNotSame('「社員の成長を支援する体制', $keyword['phrase']);
        }
    }

    public function test_fewer_than_two_keywords_with_programs_is_the_source_mode_and_without_programs_there_is_no_panel(): void
    {
        $one = $this->goodDecoded();
        array_pop($one['keywords']);
        $result = $this->verify($one);
        $this->assertSame('sources', $result['panel']['mode']);
        $this->assertSame(1, $result['rejected']['keywords_below_min']);
        $this->assertSame(self::CORE, $result['panel']['core']['text'], '核の一文は使える');

        $none = $this->goodDecoded();
        $none['programs'] = [];
        $none['keywords'] = [];
        $this->assertNull($this->verify($none)['panel'], 'programsも無ければ素材ページ');
    }

    public function test_more_keywords_than_the_limit_are_dropped(): void
    {
        $many = $this->goodDecoded();
        $material = $this->material();
        for ($i = 0; $i < 3; $i++) {
            $material['sentences'][] = ['id' => 'm-'.(10 + $i), 'text' => "社員一人ひとりの成長を大切にして、{$i}番目の挑戦を応援する考えです。"];
            $material['programs'][] = ['id' => 'p-'.(10 + 2 * $i), 'name' => "制度{$i}a", 'excerpt' => "制度{$i}aの説明を、十分な長さで書いた抜粋の文章です。", 'source' => '評価制度'];
            $material['programs'][] = ['id' => 'p-'.(11 + 2 * $i), 'name' => "制度{$i}b", 'excerpt' => "制度{$i}bの説明を、十分な長さで書いた抜粋の文章です。", 'source' => '評価制度'];
            $many['programs'][] = ['program_id' => 'p-'.(10 + 2 * $i), 'summary' => ''];
            $many['programs'][] = ['program_id' => 'p-'.(11 + 2 * $i), 'summary' => ''];
            $many['keywords'][] = ['phrase' => "社員一人ひとりの成長を大切にして、{$i}番目の挑戦を応援する", 'source_sentence_id' => 'm-'.(10 + $i), 'program_ids' => ['p-'.(10 + 2 * $i), 'p-'.(11 + 2 * $i)]];
        }
        config(['top_message_panel.programs_max' => 20]);

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

        config(['top_message_draft.link_text_ratio' => 0.1]);
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
            .'<h2>資格取得支援</h2><p>業務に関係する資格の取得費用を、会社が全額負担する制度です。</p>'
            .'<h2>新卒｜営業</h2><p>営業職の新卒向けの募集のページへのリンクとして並ぶ行の説明です。</p>'
            .'<h2>新卒</h2><p>営業職や企画職など、新卒向けの職種を紹介するページです。</p>'
            .'<h2>キャリア採用</h2><p>経験者向けの職種を紹介するページへのリンクです。</p>'
            .'<h2>新卒研修</h2><p>入社前の1カ月に、基礎を学ぶ研修を受けます。</p>'
            .'<h2>利用しているツール</h2><p>Slack</p>'
            .'<h2>十四字の制度</h2><p>十四文字ぴったりの抜粋です。</p>'
            .'<h2>十五字の制度</h2><p>十五文字ぴったりのの抜粋です。</p>'
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
        $this->assertContains('資格取得支援', $names);
        $this->assertNotContains('新卒｜営業', $names, '名前に「｜」を含む候補');
        // 依頼CZ追補2: 抜粋が15字未満は除く(14字は除く・15字は残す)/名前が採用区分の言葉と一致は除く(含むだけの「新卒研修」は残す)
        $this->assertNotContains('利用しているツール', $names, '抜粋が短い');
        $this->assertNotContains('十四字の制度', $names, '抜粋が14字');
        $this->assertContains('十五字の制度', $names, '抜粋が15字');
        $this->assertNotContains('新卒', $names, '名前が採用区分の言葉と一致');
        $this->assertNotContains('キャリア採用', $names, '名前が採用区分の言葉と一致');
        $this->assertContains('新卒研修', $names, '採用区分の言葉を含むだけ');
        $this->assertSame(['p-1', 'p-2', 'p-3', 'p-4', 'p-5', 'p-6'], array_column($material['programs'], 'id'));
        $this->assertSame('入社2年目から3年目の先輩が、新人社員のサポーターとなって悩みを聞きます。', $material['programs'][0]['excerpt'], '50字で切らず、全文(上限200字)');
        $this->assertSame(['no_excerpt' => 1, 'generic_excerpt' => 1, 'job_page' => 2, 'name_separator' => 1, 'recruit_category_name' => 2, 'short_excerpt' => 2], $material['dropped']['programs']);
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
                return 'v2';
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
            $id = fn (string $name) => array_search($name, $programs, true);

            return [
                'core_sentence_id' => $core,
                'body_sentence_ids' => [$second],
                'programs' => [
                    ['program_id' => $id('サポーター制度'), 'summary' => '先輩が、新人社員のサポーターとなって悩みを聞きます'],
                    ['program_id' => $id('新人研修'), 'summary' => ''],
                    ['program_id' => $id('育児休業'), 'summary' => '育児休業後の復職率は100%'],
                    ['program_id' => $id('資格取得支援'), 'summary' => ''],
                ],
                'keywords' => [
                    ['phrase' => 'ものづくりは人づくり', 'source_sentence_id' => $second, 'program_ids' => [$id('サポーター制度'), $id('新人研修')]],
                    ['phrase' => '社員の成長を支えています', 'source_sentence_id' => $second, 'program_ids' => [$id('育児休業'), $id('資格取得支援')]],
                ],
            ];
        };
    }

    public function test_the_prompt_asks_to_select_only_and_has_no_example_sentence_and_the_material_has_ids(): void
    {
        $provider = new OpenAiTopMessagePanelProvider;
        $prompt = $provider->buildPrompt(['company_name' => '例社'] + $this->material() + ['programs' => $this->material()['programs']]);

        foreach (['core_sentence_id', 'body_sentence_ids', 'programs', 'keywords', 'phrase', 'source_sentence_id', 'program_ids', 'program_id', 'summary', '選ぶだけ', '一字一句そのまま', '考えを表す句', '事業の対象・製品・市場', '社員にとっての仕組み', '1つの考えを2つに分けない'] as $needle) {
            $this->assertStringContainsString($needle, $prompt, $needle);
        }
        $this->assertStringContainsString('m-1: '.self::CORE, $prompt);
        $this->assertStringContainsString('p-1 | サポーター制度 |', $prompt);
        $this->assertStringContainsString('1〜3', $prompt);
        $this->assertStringNotContainsString('例:', $prompt);
        $this->assertStringNotContainsString('良い例', $prompt);
        $this->assertStringContainsString('6〜30字', $prompt);
        $this->assertSame('v2', config('top_message_panel.prompt_version'));
        $schema = $provider->buildResponseSchema();
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['core_sentence_id', 'body_sentence_ids', 'programs', 'keywords'], $schema['required']);
        $this->assertSame(['phrase', 'source_sentence_id', 'program_ids'], $schema['properties']['keywords']['items']['required']);
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
        $this->assertNull($result['panel']['keywords'][0]['programs'][1]['summary'], '説明が空なら名前だけ');
        $this->assertCount(4, $result['panel']['programs']);
    }

    public function test_a_selection_that_fails_the_check_is_a_fallback_with_the_reason_and_the_ai_is_not_called_beyond_the_limit_callback(): void
    {
        $fake = $this->fakeProvider(['core_sentence_id' => 'm-999', 'body_sentence_ids' => [], 'programs' => [], 'keywords' => []]);
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
        $this->assertSame('v2', $stored['prompt_version']);

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

    public function test_when_the_keywords_are_not_usable_the_left_panel_stays_and_the_right_side_is_bands_by_source_page_with_other_heading_and_note(): void
    {
        $panel = [
            'mode' => 'sources',
            'speaker' => '代表取締役社長 山田太郎',
            'source_title' => '代表メッセージ',
            'core' => self::CORE,
            'body' => ['ものづくりは人づくりだと考え、社員の成長を支えています。'],
            'keywords' => [],
            'programs' => [
                ['name' => '制度A', 'summary' => '説明Aの一節です', 'source' => '働き方'],
                ['name' => '制度B', 'summary' => null, 'source' => '働き方'],
                ['name' => '制度C', 'summary' => '説明Cの一節です', 'source' => '評価制度'],
            ],
        ];

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page([], $panel)));

        foreach (['「'.self::CORE.'」', '代表取締役社長 山田太郎', '主な制度', '働き方', '評価制度', '制度A', '説明Aの一節です', '制度B', '制度C',
            '出典：例社 採用サイト「代表メッセージ」ほか。制度の選択は AI が行ったもので、社内での確認が必要です。'] as $text) {
            $this->assertStringContainsString($text, $xml, $text);
        }
        $this->assertStringNotContainsString('トップメッセージのキーワードと、関連していると思われる制度', $xml);
        $this->assertStringNotContainsString('キーワードと制度の対応づけ', $xml, '注記から対応づけの文言を外す');
        $this->assertStringNotContainsString('素材ページの文です。', $xml);
    }

    public function test_a_core_sentence_with_brackets_gets_no_outer_brackets_and_one_without_gets_them(): void
    {
        $with = '「ものづくり」は「人づくり」だと私は強く信じています。';
        $panel = ['speaker' => null, 'source_title' => 'M', 'core' => $with, 'body' => [], 'keywords' => $this->keywords(2)];

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page([], $panel)));

        $this->assertStringContainsString($with, $xml, '外側に「」を付けない');
        $this->assertStringNotContainsString('「'.$with, $xml);

        $this->assertStringContainsString('「'.self::CORE.'」', $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page($this->keywords(2)))));
    }

    /** 核の一文の1行に入る全角の文字数(20ptのとき)と、見積もりの関数。 */
    private function coreLine(int $pt): array
    {
        $generator = app(AdminComparisonPptxGenerator::class);
        $class = new \ReflectionClass($generator);
        $width = $class->getReflectionConstant('TM_DRAFT_LEFT_WIDTH_IN')->getValue() - 0.6;
        $units = $class->getMethod('topMessageWrapUnits');
        $orphan = $class->getMethod('topMessageLastLineIsOrphan');

        return [(int) floor($units->invoke($generator, $width, $pt) / 2),fn (string $text, int $size) => $orphan->invoke($generator, $text, $width, $size, true)];
    }

    public function test_the_last_line_of_the_core_sentence_is_judged_as_symbols_only_only_when_it_wraps(): void
    {
        [$perLine, $isOrphan] = $this->coreLine(20);

        $this->assertTrue($isOrphan(str_repeat('あ', $perLine).'。', 20), '最後の行が「。」だけ');
        $this->assertTrue($isOrphan(str_repeat('あ', $perLine).'」。', 20), '閉じ括弧と句点だけでも同じ');
        $this->assertFalse($isOrphan(str_repeat('あ', $perLine - 1).'。', 20), '1行に収まる');
        $this->assertFalse($isOrphan(str_repeat('あ', $perLine).'あ。', 20), '最後の行に文字がある');

        // 1行の文字数は、枠の幅から左右の余白(config)を引いた幅に、全角の文字が何文字入るか
        $this->assertSame((int) floor((3.9 - 0.2) * 72 / 20), $perLine);
    }

    public function test_a_core_sentence_whose_last_line_would_be_a_mark_only_is_set_one_step_smaller_and_otherwise_kept(): void
    {
        [$perLine] = $this->coreLine(20);
        $max = (int) config('admin_comparison_pptx.top_message_panel_core_max_pt');
        $core = '「'.str_repeat('あ', $perLine - 1).'」。';
        $panel = fn (string $text) => ['speaker' => null, 'source_title' => 'M', 'core' => $text, 'body' => [], 'keywords' => $this->keywords(2)];

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page([], $panel($core))));
        $this->assertStringNotContainsString('sz="'.($max * 100).'"', $xml, '最大の大きさのままだと「。」が落ちるので小さくする');
        [, $isOrphan] = $this->coreLine($max);
        $expected = $max - 1;
        while ($isOrphan($core, $expected)) {
            $expected--;
        }
        preg_match_all('/sz="(\d+)"/', $xml, $sizes);
        $this->assertContains((string) ($expected * 100), $sizes[1], '1段階ずつ下げて、最後の行が記号だけでなくなる最初の大きさ');

        $plain = '「'.str_repeat('あ', $perLine - 3).'」。';
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page([], $panel($plain))));
        $this->assertStringContainsString('sz="'.($max * 100).'"', $xml, '落ちないときは最大のまま');

        // 下限まで下げても直らない(下限を最大と同じにする)ときは、そのまま
        config(['admin_comparison_pptx.top_message_min_font_ratio' => 1.0]);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($this->page([], $panel($core))));
        $this->assertStringContainsString('sz="'.($max * 100).'"', $xml);
    }

    public function test_the_speaker_is_taken_from_the_company_and_title_form_and_the_title_and_name_form(): void
    {
        $extractor = new TopMessageDraftExtractor;
        $body = '<p>'.self::CORE.'</p>';

        foreach (['freee株式会社CEOの佐々木大輔', 'CEOの佐々木大輔', '代表取締役社長の山田太郎', '例社 代表取締役社長 山田太郎'] as $line) {
            $found = $extractor->panelMessage('<html><body><main>'.$body.'<p>'.$line.'</p></main></body></html>');

            $this->assertSame($line, $found['speaker'], $line);
        }
    }

    public function test_without_a_panel_the_slide_is_still_the_panel_type_with_the_missing_texts(): void
    {
        $page = $this->page($this->keywords(2));
        unset($page['panel']);

        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($page));

        $this->assertStringContainsString('例社：トップメッセージと制度', $xml);
        $this->assertStringNotContainsString('トップメッセージと制度の素材', $xml, '表の形(素材ページ)は使わない');
        $this->assertStringContainsString((string) config('admin_comparison_pptx.top_message_panel_no_message'), $xml);
        $this->assertStringContainsString((string) config('admin_comparison_pptx.top_message_panel_no_programs'), $xml);
        $this->assertStringContainsString((string) config('admin_comparison_pptx.top_message_panel_note_machine'), $xml);
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
        $machine = $builder->buildTopMessageDraftData($analysis->fresh(), $viewModel)[0];
        $this->assertSame('machine', $machine['panel']['selection'], '無効のときも、ページの型はパネル(中身は機械の選択)');
        $this->assertSame('ai', $pages[0]['panel']['selection']);

        $this->assertSame($callsAfterGenerate, $fake->calls, 'データを作るときにAIを呼ばない');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ 依頼DD: ページの型の固定(機械の選択・AIの選択・provider)

    /**
     * @param  list<string>  $texts
     * @param  list<array{name: string, excerpt: string, source: string}>  $programs
     * @return array<string, mixed>
     */
    private function machineMaterial(array $texts, array $programs = []): array
    {
        $sentences = [];
        foreach ($texts as $i => $text) {
            $sentences[] = ['id' => 'm-'.($i + 1), 'text' => $text];
        }

        return ['status' => 'ok', 'reason' => null, 'speaker' => '代表 太郎', 'message_title' => '代表メッセージ', 'message_url' => 'https://e.example.com/m', 'sentences' => $sentences, 'programs' => $programs, 'dropped' => []];
    }

    public function test_the_core_is_the_first_sentence_with_a_word_for_thought_or_will_else_the_first_sentence(): void
    {
        $selector = app(TopMessagePanelMachineSelector::class);

        $with = $selector->select($this->machineMaterial(['最初の文です、特別な言葉はありません。', '二つ目の文は、私たちが大切にしていることを書きます。', '三つ目の文は、未来を信じています。']));
        $this->assertSame('二つ目の文は、私たちが大切にしていることを書きます。', $with['core'], '考え・意志を表す言葉(config)を含む最初の文');

        $without = $selector->select($this->machineMaterial(['最初の文です、特別な言葉はありません。', '二つ目の文も、事実を述べるだけです。']));
        $this->assertSame('最初の文です、特別な言葉はありません。', $without['core'], '無ければ最初の文');

        config(['top_message_panel.machine_core_words' => ['特別']]);
        $this->assertSame('最初の文です、特別な言葉はありません。', $selector->select($this->machineMaterial(['最初の文です、特別な言葉はありません。', '二つ目の文は、信じています。']))['core'], '語はconfigから');
    }

    public function test_the_body_is_two_sentences_around_the_core_in_page_order(): void
    {
        $selector = app(TopMessagePanelMachineSelector::class);
        $texts = ['文1は事実を述べます、長さはあります。', '文2は事実を述べます、長さはあります。', '文3は私たちが信じていることを書きます。', '文4は事実を述べます、長さはあります。', '文5は事実を述べます、長さはあります。'];

        $middle = $selector->select($this->machineMaterial($texts));
        $this->assertSame([$texts[3], $texts[1]], array_reverse($middle['body']), '核の前後から1文ずつ、ページ上の順番のまま');
        $this->assertSame([$texts[1], $texts[3]], $middle['body']);

        $first = $selector->select($this->machineMaterial([$texts[2], $texts[3], $texts[4]]));
        $this->assertSame([$texts[3], $texts[4]], $first['body'], '核が先頭なら後ろの2文');

        $last = $selector->select($this->machineMaterial([$texts[0], $texts[1], $texts[2]]));
        $this->assertSame([$texts[0], $texts[1]], $last['body'], '核が末尾なら前の2文');

        config(['top_message_panel.machine_body_count' => 1]);
        $this->assertCount(1, $selector->select($this->machineMaterial($texts))['body'], '文の数はconfigから');
    }

    public function test_the_card_summary_is_the_first_sentence_up_to_the_limit_else_the_name_only(): void
    {
        $selector = app(TopMessagePanelMachineSelector::class);
        $long = str_repeat('あ', 40).'。';   // 41字
        $programs = [
            ['name' => '短い', 'excerpt' => '最初の文はここまでです。二つ目の文は使いません。', 'source' => 'ページA'],
            ['name' => 'ちょうど', 'excerpt' => str_repeat('い', 39).'。続きの文。', 'source' => 'ページA'],
            ['name' => '長い', 'excerpt' => $long.'続き。', 'source' => 'ページA'],
            ['name' => '切れた', 'excerpt' => '句点がなく途中で切れた説明…', 'source' => 'ページB'],
            ['name' => '括弧', 'excerpt' => '制度の説明(詳細は次の。', 'source' => 'ページB'],
            ['name' => '句点なし', 'excerpt' => '句点が無いが最後まで書かれた説明', 'source' => 'ページB'],
        ];

        $rows = $selector->select($this->machineMaterial(['文です、信じています。'], $programs))['programs'];
        $byName = array_column($rows, 'summary', 'name');

        $this->assertSame('最初の文はここまでです。', $byName['短い']);
        $this->assertSame(str_repeat('い', 39).'。', $byName['ちょうど'], '40字ちょうどは通る');
        $this->assertNull($byName['長い'], '上限を超える最初の文は、名前だけ(途中で切らない)');
        $this->assertNull($byName['切れた'], '途中で切れた抜粋は、名前だけ');
        $this->assertNull($byName['括弧'], '括弧が閉じていない説明は、名前だけ');
        $this->assertSame('句点が無いが最後まで書かれた説明', $byName['句点なし'], '句点が無くても、最後まで書かれた短い抜粋はそのまま');

        config(['top_message_panel.machine_summary_max_chars' => 60]);
        $this->assertSame($long, array_column($selector->select($this->machineMaterial(['文です。'], $programs))['programs'], 'summary', 'name')['長い'], '上限はconfigから');
    }

    public function test_the_bands_are_by_source_page_in_order_of_program_count_with_the_configured_limits(): void
    {
        $programs = [];
        foreach (['少' => 1, '中' => 2, '多' => 5, 'ほか1' => 1, 'ほか2' => 1, 'ほか3' => 1] as $source => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $programs[] = ['name' => "{$source}の制度{$i}", 'excerpt' => '説明です。', 'source' => $source];
            }
        }

        $rows = app(TopMessagePanelMachineSelector::class)->select($this->machineMaterial(['文です。'], $programs))['programs'];

        $this->assertSame(['多', '中', '少', 'ほか1'], array_values(array_unique(array_column($rows, 'source'))), '制度の数が多い順(同数はページの順)・最大4本');
        $this->assertCount(3, array_filter($rows, fn (array $r) => $r['source'] === '多'), '1つの帯は最大3カード');
        $this->assertSame(['多の制度1', '多の制度2', '多の制度3'], array_column(array_filter($rows, fn (array $r) => $r['source'] === '多'), 'name'));
    }

    public function test_the_machine_selection_is_empty_when_there_is_nothing_to_choose(): void
    {
        $empty = app(TopMessagePanelMachineSelector::class)->select($this->machineMaterial([], []));

        $this->assertNull($empty['core']);
        $this->assertSame([], $empty['body']);
        $this->assertSame([], $empty['programs']);
        $this->assertSame('machine', $empty['selection']);
    }

    public function test_with_the_ai_switch_off_the_page_is_the_panel_type_filled_by_the_machine_selection(): void
    {
        config(['top_message_panel.enabled' => false]);
        Http::fake();
        $analysis = $this->runningComparison();
        $viewModel = app(\App\Services\Report\MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());

        $pages = app(AdminComparisonPptxDataBuilder::class)->buildTopMessageDraftData($analysis->fresh(), $viewModel);

        $this->assertNotEmpty($pages);
        $this->assertSame('machine', $pages[0]['panel']['selection']);
        $this->assertNotNull($pages[0]['panel']['core']);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($pages[0]));
        $this->assertStringContainsString('：トップメッセージと制度', $xml);
        $this->assertStringNotContainsString('トップメッセージと制度の素材', $xml, '表の形は出ない');
        $this->assertStringContainsString((string) config('admin_comparison_pptx.top_message_panel_note_machine'), $xml, '機械の選択の注記');
        $this->assertStringNotContainsString('AI が選んだ', $xml);
        Http::assertNothingSent();
    }

    public function test_when_the_ai_passes_the_ai_panel_and_when_it_fails_the_machine_selection_with_the_same_type(): void
    {
        $analysis = $this->runningComparison();
        $viewModel = app(\App\Services\Report\MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());
        $builder = app(AdminComparisonPptxDataBuilder::class);
        $generator = app(AdminComparisonPptxGenerator::class);

        $this->fakeProvider($this->decodedFromMaterial());
        app(TopMessagePanelService::class)->generate($analysis);
        $ai = $builder->buildTopMessageDraftData($analysis->fresh(), $viewModel)[0];
        $aiXml = $this->slideXml($generator->generateTopMessageDraftSlide($ai));
        $this->assertSame('ai', $ai['panel']['selection']);
        $this->assertStringContainsString('AI が選んだ', $aiXml);

        $this->fakeProvider(fn (array $material) => ['core_sentence_id' => 'm-999', 'body_sentence_ids' => [], 'programs' => [], 'keywords' => []]);
        app(TopMessagePanelService::class)->generate($analysis, true);
        $machine = $builder->buildTopMessageDraftData($analysis->fresh(), $viewModel)[0];
        $machineXml = $this->slideXml($generator->generateTopMessageDraftSlide($machine));
        $this->assertSame('machine', $machine['panel']['selection'], 'AIが落ちたら機械の選択に戻る');
        $this->assertStringContainsString((string) config('admin_comparison_pptx.top_message_panel_note_machine'), $machineXml);
        foreach (['：トップメッセージと制度', (string) config('admin_comparison_pptx.top_message_draft_band_text'), 'トップメッセージ'] as $common) {
            $this->assertStringContainsString($common, $aiXml);
            $this->assertStringContainsString($common, $machineXml, '型は同じ');
        }
    }

    public function test_a_company_without_a_message_page_or_without_programs_still_gets_the_page_with_the_fixed_text(): void
    {
        $generator = app(AdminComparisonPptxGenerator::class);
        $selector = app(TopMessagePanelMachineSelector::class);

        $noMessage = $this->slideXml($generator->generateTopMessageDraftSlide(['company_name' => '例社', 'panel' => $selector->select($this->machineMaterial([], [['name' => '制度A', 'excerpt' => '説明です。', 'source' => 'ページ']]))]));
        $this->assertStringContainsString('トップメッセージのページを確認できませんでした。', $noMessage);
        $this->assertStringContainsString('制度A', $noMessage, '右側は出す');

        $noPrograms = $this->slideXml($generator->generateTopMessageDraftSlide(['company_name' => '例社', 'panel' => $selector->select($this->machineMaterial(['私たちは信じています、という文です。']))]));
        $this->assertStringContainsString('制度のページを確認できませんでした。', $noPrograms);
        $this->assertStringContainsString('私たちは信じています', $noPrograms, '左側は出す');
    }

    public function test_the_material_keeps_the_programs_even_without_a_message_page_but_the_ai_is_still_not_called(): void
    {
        Http::fake();
        $fake = $this->fakeProvider($this->decodedFromMaterial());
        $self = $this->makeComparisonWebsiteAnalysis(true, 0, 'self');
        $this->addHomepage($self, 'https://nomsg.example.com/recruit/', $this->htmlPage('採用トップ', '採用情報です。'));
        $this->addCrawledPage($self, 'https://nomsg.example.com/recruit/training/', '研修制度 | 例社', '<html><head><title>研修制度 | 例社</title></head><body><main><h2>研修制度の名前</h2><p>入社後に受けられる研修の内容を、丁寧に説明するための文章です。</p></main></body></html>');

        $material = app(TopMessagePanelMaterialBuilder::class)->build($self);

        $this->assertSame('skipped', $material['status']);
        $this->assertSame('no_message_page', $material['reason']);
        $this->assertNotEmpty($material['programs'], 'メッセージのページが無くても、制度の候補は取る');
        app(TopMessagePanelService::class)->composeCompany($self, 'self');
        $this->assertSame(0, $fake->calls, 'AIは呼ばない');
        Http::assertNothingSent();
    }

    public function test_the_red_band_is_not_drawn_when_its_text_is_empty(): void
    {
        $page = ['company_name' => '例社', 'panel' => app(TopMessagePanelMachineSelector::class)->select($this->machineMaterial(['私たちは信じています、という文です。']))];
        $band = strtoupper((string) config('admin_comparison_pptx.top_message_draft_band_fill'));
        $this->assertStringContainsString($band, strtoupper($this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($page))));

        config(['admin_comparison_pptx.top_message_draft_band_text' => '']);
        $xml = $this->slideXml(app(AdminComparisonPptxGenerator::class)->generateTopMessageDraftSlide($page));

        $this->assertStringNotContainsString($band, strtoupper($xml), '文言が空なら帯を出さない');
        $this->assertStringContainsString('：トップメッセージと制度', $xml);
    }

    public function test_provider_anthropic_uses_the_anthropic_caller_and_without_a_model_or_key_the_ai_is_not_called(): void
    {
        config(['top_message_panel.provider' => 'anthropic', 'services.anthropic.api_key' => 'sk-ant-test-key-not-real', 'top_message_panel.model' => 'test-model']);
        $this->assertInstanceOf(\App\Services\TopMessagePanel\AnthropicTopMessagePanelProvider::class, app(TopMessagePanelProviderFactory::class)->make());

        config(['top_message_panel.provider' => 'openai']);
        $this->assertNotInstanceOf(\App\Services\TopMessagePanel\AnthropicTopMessagePanelProvider::class, (function () {
            config(['services.brand_wheel_ai.provider' => 'openai', 'services.openai.api_key' => 'sk-test']);

            return app(TopMessagePanelProviderFactory::class)->make();
        })(), 'openai(既定)のときは従来の呼び出し口');

        config(['top_message_panel.provider' => 'anthropic', 'top_message_panel.model' => null]);
        try {
            app(TopMessagePanelProviderFactory::class)->make();
            $this->fail('モデル未設定は例外');
        } catch (ComparisonCommentaryException $e) {
            $this->assertSame('MODEL_NOT_CONFIGURED', $e->errorCode);
        }

        Http::fake();
        $analysis = $this->runningComparison();
        $result = app(TopMessagePanelService::class)->generate($analysis);
        $this->assertSame('MODEL_NOT_CONFIGURED', array_values($result['companies'])[0]['reason']);
        $this->assertSame(0, array_sum(array_column($result['companies'], 'ai_calls')), 'AIを呼ばない');
        Http::assertNothingSent();

        $viewModel = app(\App\Services\Report\MultiSiteReportViewModelBuilder::class)->build($analysis->fresh());
        $this->assertSame('machine', app(AdminComparisonPptxDataBuilder::class)->buildTopMessageDraftData($analysis->fresh(), $viewModel)[0]['panel']['selection'], '機械の選択になる');
    }

    public function test_the_anthropic_request_has_the_key_header_the_model_no_temperature_and_the_same_prompt_and_reads_only_text_blocks(): void
    {
        config(['top_message_panel.provider' => 'anthropic', 'services.anthropic.api_key' => 'sk-ant-test-key-not-real', 'top_message_panel.model' => 'test-model', 'top_message_panel.anthropic_max_output_tokens' => 1234]);
        $material = ['company_name' => '例社', 'sentences' => $this->material()['sentences'], 'programs' => array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name'], 'excerpt' => $p['excerpt']], $this->material()['programs'])];
        $decoded = ['core_sentence_id' => 'm-1', 'body_sentence_ids' => [], 'programs' => [], 'keywords' => []];
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'thinking', 'thinking' => '…'], ['type' => 'text', 'text' => json_encode($decoded)]],
            'usage' => ['input_tokens' => 11, 'output_tokens' => 7],
        ])]);

        $outcome = app(TopMessagePanelProviderFactory::class)->make()->select($material);

        $this->assertSame($decoded, $outcome['decoded']);
        $this->assertSame([11, 7], [$outcome['usage_input_tokens'], $outcome['usage_output_tokens']]);
        $expectedPrompt = (new OpenAiTopMessagePanelProvider)->buildPrompt($material);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($expectedPrompt): bool {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key') === ['sk-ant-test-key-not-real']
                && $body['model'] === 'test-model'
                && $body['max_tokens'] === 1234
                && ! array_key_exists('temperature', $body)
                && $body['messages'][0]['content'] === $expectedPrompt;
        });
    }

    public function test_a_failed_anthropic_call_is_a_fallback_and_logs_only_the_status_and_error_type(): void
    {
        config(['top_message_panel.provider' => 'anthropic', 'services.anthropic.api_key' => 'sk-ant-test-key-not-real', 'top_message_panel.model' => 'test-model']);
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'SECRET-ERROR-MESSAGE-BODY']], 400)]);
        $warnings = [];
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$warnings) {
            $warnings[] = [$message, $context];
        });
        \Illuminate\Support\Facades\Log::shouldReceive('info')->andReturnNull();

        $result = app(TopMessagePanelService::class)->generate($this->runningComparison());

        $this->assertSame('AI_REQUEST_FAILED', array_values($result['companies'])[0]['reason']);
        $this->assertSame(['http_status' => 400, 'error_type' => 'invalid_request_error'], $warnings[0][1]);
        $text = json_encode($warnings, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SECRET-ERROR-MESSAGE-BODY', $text);
        $this->assertStringNotContainsString('sk-ant-test-key-not-real', $text);
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
