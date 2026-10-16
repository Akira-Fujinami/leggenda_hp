<?php

namespace Tests\Feature\TopMessageDraft;

use App\Models\WebsiteAnalysis;
use App\Services\TopMessageDraft\TopMessageDraftBuilder;
use App\Services\TopMessageDraft\TopMessageDraftExtractor;
use App\Services\TopMessagePanel\TopMessagePanelMaterialBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼DA-1: 既定で出る素材ページ(TopMessageDraftBuilder)にも、パネルの材料と同じ整理を効かせる。
 * 整理の処理・語は共通の1か所(TopMessageProgramFilter・config('top_message_draft'))。本文はすべて人工のもの。
 */
class TopMessageDraftCleanupTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    private const BASE = 'https://self.example.com/recruit';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('analysis');
    }

    private function messageHtml(): string
    {
        return '<html><head><title>代表メッセージ | 例社</title></head><body><main>'
            .'<h1>代表メッセージ</h1>'
            .'<p>代表取締役社長 山田太郎</p>'
            .'<p>私たちは、小さなビジネスから、世の中が面白くなっていくと信じています。</p>'
            .'<p><a href="/news/1">「小さなビジネスに着目する」代表の山田氏：他社メディアの電子版</a></p>'
            .'<p>代表メッセージ</p>'
            .'<h2>関連記事</h2>'
            .'<p>関連記事の下にある、普通の文章に見える長めの文ですが、素材ページにも出しません。</p>'
            .'<h2>最後に、皆さんへのメッセージです</h2>'
            .'<p>最後に、皆さんと一緒に働ける日を楽しみにしています。ぜひ、ご応募ください。</p>'
            .'</main></body></html>';
    }

    private function seedCompany(WebsiteAnalysis $wa): void
    {
        $this->addHomepage($wa, self::BASE.'/', $this->htmlPage('採用トップ', '採用情報です。', '<a href="'.self::BASE.'/ceo-message/">代表からのご挨拶</a>'));
        $this->addCrawledPage($wa, self::BASE.'/ceo-message/', '代表メッセージ | 例社', $this->messageHtml());
        $this->addCrawledPage($wa, self::BASE.'/training/', '研修制度 | 例社',
            '<html><head><title>研修制度 | 例社</title></head><body><main>'
            .'<h2>サポーター制度</h2><p>入社2年目から3年目の先輩が、新人社員のサポーターとなって悩みを聞きます。</p>'
            .'<h2>成長支援</h2>'                                                                        // 抜粋なし
            .'<h2>評価制度</h2><p>関連記事</p>'                                                         // 抜粋が見出しの言葉だけ
            .'<h2>新卒</h2><p>営業職や企画職など、新卒向けの職種を紹介するページです。</p>'               // 名前が採用区分の言葉と一致
            .'<h2>新卒研修</h2><p>入社前の1カ月に、基礎を学ぶ研修を受けます。</p>'                       // 含むだけは残す
            .'<h2>利用しているツール</h2><p>Slack</p>'                                                  // 抜粋が15字未満
            .'<h2>十四字の制度</h2><p>十四文字ぴったりの抜粋です。</p>'                                  // 14字
            .'<h2>十五字の制度</h2><p>十五文字ぴったりのの抜粋です。</p>'                                // 15字
            .'<h2>新卒｜営業</h2><p>営業職の新卒向けの募集のページへのリンクとして並ぶ行の説明です。</p>'  // 名前に「｜」
            .'</main></body></html>');
        $this->addCrawledPage($wa, self::BASE.'/welfare/requirements/', '福利厚生 募集要項 | 例社',                  // 募集要項のページ
            '<html><head><title>福利厚生 募集要項 | 例社</title></head><body><main>'
            .'<h2>初任給</h2><p>大学卒は月給25万円、大学院卒は月給27万円を支給します。</p>'
            .'</main></body></html>');
        $this->addCrawledPage($wa, self::BASE.'/welfare/', '福利厚生 | 例社',
            '<html><head><title>福利厚生 | 例社</title></head><body><main>'
            .'<h2>育児休業</h2><p>育児休業後の復職率は100%で、時短勤務も利用できます。</p>'
            .'</main></body></html>');
    }

    public function test_the_default_draft_page_drops_the_non_program_rows_with_the_reasons(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        $page = app(TopMessageDraftBuilder::class)->build($wa, '例社');

        $names = array_column(array_filter($page['programs']['rows'], fn (array $r) => $r['kind'] === 'program'), 'name');
        sort($names);
        $expected = ['サポーター制度', '十五字の制度', '新卒研修', '育児休業'];
        sort($expected);
        $this->assertSame($expected, $names, '制度でない行は除かれ、本物の制度(含むだけの名前・15字ちょうど)は残る');
        $this->assertSame(
            ['job_page' => 1, 'name_separator' => 1, 'no_excerpt' => 1, 'recruit_category_name' => 1, 'generic_excerpt' => 1, 'short_excerpt' => 2],
            $page['programs']['removed'],
        );
        $this->assertSame(4, $page['programs']['candidate_total'], '除いた行は候補の総数にも数えない');
    }

    public function test_the_default_draft_page_has_the_same_programs_as_the_panel_material(): void
    {
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        $draft = app(TopMessageDraftBuilder::class)->build($wa, '例社');
        $material = app(TopMessagePanelMaterialBuilder::class)->build($wa);

        $draftNames = array_column(array_filter($draft['programs']['rows'], fn (array $r) => $r['kind'] === 'program'), 'name');
        sort($draftNames);
        $panelNames = array_column($material['programs'], 'name');
        sort($panelNames);
        $this->assertSame($panelNames, $draftNames);
        foreach ($draft['programs']['removed'] as $reason => $count) {
            $this->assertSame($count, $material['dropped']['programs'][$reason], $reason.': 理由別の件数もパネルの材料と同じ');
        }
    }

    public function test_the_default_draft_page_message_drops_the_related_section_and_link_only_sentences(): void
    {
        $found = (new TopMessageDraftExtractor)->messageCandidates($this->messageHtml(), '代表メッセージ | 例社');

        $joined = implode(' ', $found['lines']);
        $this->assertStringContainsString('私たちは、小さなビジネスから、世の中が面白くなっていくと信じています。', $joined);
        $this->assertStringContainsString('最後に、皆さんと一緒に働ける日を楽しみにしています。', $joined, '関連記事の見出しは、次の同じ階層の見出しで終わる');
        $this->assertStringNotContainsString('関連記事の下にある', $joined, '関連記事の下は除く');
        $this->assertStringNotContainsString('他社メディアの電子版', $joined, 'リンクの文字だけの文は除く');
        $this->assertSame(1, $found['dropped_related']['link_text']);
        $this->assertSame(1, $found['dropped_related']['related_section']);
        $this->assertSame(1, $found['dropped_title'], 'ページ名と同じ文は除く(これまでどおり)');
    }

    public function test_the_default_draft_page_message_matches_the_panel_material_sentences(): void
    {
        $extractor = new TopMessageDraftExtractor;
        $draft = $extractor->messageCandidates($this->messageHtml(), '代表メッセージ | 例社');
        $panel = $extractor->panelMessage($this->messageHtml(), '代表メッセージ | 例社');

        $panelTexts = array_column($panel['sentences'], 'text');
        foreach ($draft['lines'] as $line) {
            if ($line === $panel['speaker']) {
                continue;
            }
            $this->assertContains($line, $panelTexts, '素材ページに出る文は、パネルの材料にもある(同じ整理)');
        }
        $this->assertSame($panel['dropped']['related_section'], $draft['dropped_related']['related_section']);
        $this->assertSame($panel['dropped']['link_text'], $draft['dropped_related']['link_text']);
    }

    public function test_the_shared_words_are_read_from_the_same_config_keys_by_the_draft_page_and_the_panel(): void
    {
        config(['top_message_draft.program_excerpt_min_chars' => 100]);
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedCompany($wa);

        $draft = app(TopMessageDraftBuilder::class)->build($wa, '例社');
        $material = app(TopMessagePanelMaterialBuilder::class)->build($wa);

        $this->assertNull($draft['programs']['rows'][0] ?? null, '長さの下限を上げると、素材ページも全件除かれる');
        $this->assertSame([], $material['programs'], 'パネルの材料も同じ設定で全件除かれる');
    }
}
